<?php

namespace App\Services;

use App\Models\PurchaseLine;
use App\Models\SellLine;
use App\Models\Transaction;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Serial / IMEI and batch / lot tracking, kept beside stock and never inside it (`StockMovements` is not read or changed here).
 *
 * A serial or a batch is an identity (`stock_serials`, `stock_batches`); what happens to it is a list of movements, each tied to
 * the document - and the document line - that caused it. Where a serial is, or how much of a batch is left, is worked out from
 * the movements of documents that are still live (not deleted; a sale is not a draft or quotation; a receipt still has its
 * received quantity; a transfer's arrival has been completed), the way stock itself is derived. So deleting, restoring,
 * finalising or completing a document needs no clean-up here, and saving a document just replaces its own movements.
 *
 * Only products whose `tracking_type` is `serial` or `batch` are looked at; for every other product nothing here runs.
 * Three documents write movements: the receiving note (inward), the sale (outward) and the stock transfer (internal movement).
 * Everything else (a sale return, a purchase return, an adjustment, opening stock) is entered on the Serials & Batches page
 * with a note, as a manual movement.
 */
class StockTracking
{
    public const KIND_RECEIVE = 'receive';

    public const KIND_SALE = 'sale';

    public const KIND_TRANSFER_OUT = 'transfer_out';

    public const KIND_TRANSFER_IN = 'transfer_in';

    public const KIND_REGISTER = 'register';

    public const KIND_WRITE_OFF = 'write_off';

    /** A serial is in stock when its latest movement is one of these. */
    public const IN_STOCK_KINDS = [self::KIND_RECEIVE, self::KIND_REGISTER, self::KIND_TRANSFER_IN];

    private static ?bool $enabled = null;

    /**
     * False until the tracking tables exist, so a deploy that has not run its migration yet behaves as before.
     */
    public static function enabled(): bool
    {
        return self::$enabled ??= Schema::hasTable('stock_serials') && Schema::hasColumn('products', 'tracking_type');
    }

    public static function forgetSchemaMemo(): void
    {
        self::$enabled = null;
    }

    /**
     * @param  array<int, int|string|null>  $productIds
     * @return array<int, string> product id => 'serial' | 'batch', for the tracked products only
     */
    public static function trackedTypes(array $productIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $productIds))));

        if (! self::enabled() || $ids === []) {
            return [];
        }

        return DB::table('products')->whereIn('id', $ids)->whereIn('tracking_type', ['serial', 'batch'])->pluck('tracking_type', 'id')->map(fn ($type): string => (string) $type)->all();
    }

    public static function hasEntries(int $productId): bool
    {
        return self::enabled() && (DB::table('stock_serials')->where('product_id', $productId)->exists() || DB::table('stock_batches')->where('product_id', $productId)->exists());
    }

    // -------------------------------------------------------------------------------------------------- derived state

    /**
     * Limits a movement query (alias `m`) to the movements that count: manual ones, and those of live documents.
     */
    public static function counted(Builder $query): Builder
    {
        return $query
            ->leftJoin('transactions as t', 't.id', '=', 'm.transaction_id')
            ->leftJoin('purchase_lines as pl', function ($join): void {
                $join->on('pl.id', '=', 'm.line_id')->where('m.line_table', '=', 'purchase_lines');
            })
            ->where(function (Builder $outer): void {
                $outer->whereNull('m.transaction_id')->orWhere(function (Builder $document): void {
                    $document->whereNull('t.deleted_at')->where(function (Builder $kind): void {
                        $kind->where(fn (Builder $r) => $r->where('m.kind', self::KIND_RECEIVE)->where('pl.quantity_received', '>', 0))
                            ->orWhere(fn (Builder $r) => $r->where('m.kind', self::KIND_SALE)->whereNotIn('t.status', Transaction::UNPOSTED_SELL_STATUSES))
                            ->orWhere('m.kind', self::KIND_TRANSFER_OUT)
                            ->orWhere(fn (Builder $r) => $r->where('m.kind', self::KIND_TRANSFER_IN)->where('t.status', 'completed'));
                    });
                });
            });
    }

    /**
     * One row per serial: where it stands now (`last_kind`, `branch_id`) and `in_stock`.
     */
    public static function serialStates(): Builder
    {
        $moves = self::counted(DB::table('stock_serial_movements as m'))
            ->select('m.serial_id', DB::raw('sum(m.direction) as net'), DB::raw('max(m.id) as last_id'))
            ->groupBy('m.serial_id');

        return DB::table('stock_serials as s')
            ->leftJoinSub($moves, 'st', 'st.serial_id', '=', 's.id')
            ->leftJoin('stock_serial_movements as lm', 'lm.id', '=', 'st.last_id')
            ->select(['s.id', 's.company_id', 's.product_id', 's.variation_id', 's.serial_no', DB::raw('coalesce(st.net, 0) as net'), 'lm.kind as last_kind', 'lm.branch_id', 'lm.transaction_id as last_transaction_id', 'lm.moved_at as last_moved_at']);
    }

    public static function serialInStock(object $state): bool
    {
        return (int) $state->net >= 1 && in_array($state->last_kind, self::IN_STOCK_KINDS, true);
    }

    public static function serialStatus(object $state): string
    {
        return match (true) {
            self::serialInStock($state) => 'in_stock',
            $state->last_kind === self::KIND_SALE => 'sold',
            $state->last_kind === self::KIND_TRANSFER_OUT => 'in_transit',
            $state->last_kind === self::KIND_WRITE_OFF => 'written_off',
            default => 'none',
        };
    }

    /**
     * What is left of every batch in every branch (a row per batch and branch).
     */
    public static function batchStock(): Builder
    {
        return self::counted(DB::table('stock_batch_movements as m'))
            ->join('stock_batches as b', 'b.id', '=', 'm.batch_id')
            ->select(['b.id as batch_id', 'b.company_id', 'b.product_id', 'b.variation_id', 'b.batch_no', 'b.expiry_date', 'm.branch_id', DB::raw('sum(m.qty) as qty')])
            ->groupBy('b.id', 'b.company_id', 'b.product_id', 'b.variation_id', 'b.batch_no', 'b.expiry_date', 'm.branch_id');
    }

    /**
     * What a document's lines carry, for showing them again on an edit form: per line id, its serial numbers and its batches
     * (the quantity in the unit of the line).
     *
     * @param  list<int>  $lineIds
     * @return array<int, array{serials: list<string>, batches: list<array{batch_id: int, batch_no: string, expiry_date: string|null, qty: float}>}>
     */
    public static function linesInfo(string $lineTable, array $lineIds, string $kind): array
    {
        if (! self::enabled() || $lineIds === [] || ! in_array($lineTable, ['sell_lines', 'purchase_lines'], true)) {
            return [];
        }

        $info = [];

        foreach (DB::table('stock_serial_movements as m')->join('stock_serials as s', 's.id', '=', 'm.serial_id')->where('m.line_table', $lineTable)->whereIn('m.line_id', $lineIds)->where('m.kind', $kind)->orderBy('m.id')->get(['m.line_id', 's.serial_no']) as $row) {
            $info[(int) $row->line_id]['serials'][] = (string) $row->serial_no;
        }

        $batches = DB::table('stock_batch_movements as m')
            ->join('stock_batches as b', 'b.id', '=', 'm.batch_id')
            ->join($lineTable.' as l', 'l.id', '=', 'm.line_id')
            ->where('m.line_table', $lineTable)->whereIn('m.line_id', $lineIds)->where('m.kind', $kind)->orderBy('m.id')
            ->get(['m.line_id', 'b.id as batch_id', 'b.batch_no', 'b.expiry_date', 'm.qty', 'l.packing_qty']);

        foreach ($batches as $row) {
            $info[(int) $row->line_id]['batches'][] = [
                'batch_id' => (int) $row->batch_id, 'batch_no' => (string) $row->batch_no,
                'expiry_date' => $row->expiry_date === null ? null : substr((string) $row->expiry_date, 0, 10),
                'qty' => round(abs((float) $row->qty) / max((float) $row->packing_qty, 1.0), 4),
            ];
        }

        return array_map(fn (array $line): array => ['serials' => $line['serials'] ?? [], 'batches' => $line['batches'] ?? []], $info);
    }

    // ------------------------------------------------------------------------------------------------------ documents

    /**
     * Inward: the received quantity of a purchase (a receiving note) names its serials, or its batches with their expiry dates.
     *
     * @param  array<int, mixed>  $rows  the request's purchase lines (id, serials | batches)
     *
     * @throws ValidationException
     */
    public function receive(Transaction $purchase, array $rows): void
    {
        if (! self::enabled()) {
            return;
        }

        $purchase->unsetRelation('purchaselines');
        $lines = $purchase->purchaselines()->get();
        $this->clear((int) $purchase->id, [self::KIND_RECEIVE]);
        $tracked = self::trackedTypes($lines->pluck('product_id')->all());

        foreach ($lines as $line) {
            $type = $tracked[(int) $line->product_id] ?? null;
            $need = $this->baseQuantity($line->quantity_received, $line->packing_qty);

            if ($type === null || $need <= 0) {
                continue;
            }

            [$row, $index] = $this->rowFor($rows, (int) $line->id);
            $field = "purchaselines.{$index}";

            if ($type === 'serial') {
                foreach ($this->serialNumbers($row, $need, $field) as $serialNo) {
                    $serial = $this->serialOf($purchase, (int) $line->product_id, $line->variation_id, $serialNo);

                    if ($this->isHeld($serial)) {
                        throw ValidationException::withMessages(["{$field}.serials" => ["Serial {$serialNo} is already in stock."]]);
                    }

                    $this->serialMove($serial, $purchase, 'purchase_lines', (int) $line->id, self::KIND_RECEIVE, 1, (int) $purchase->branch_id);
                }

                continue;
            }

            $received = 0.0;

            foreach ($this->receivedBatches($row, $field) as $entry) {
                $batch = $this->batchOf($purchase, (int) $line->product_id, $line->variation_id, $entry['batch_no'], $entry['expiry_date'], $field);
                $qty = $entry['qty'] * $this->pack($line->packing_qty);
                $received += $qty;
                $this->batchMove($batch, $purchase, 'purchase_lines', (int) $line->id, self::KIND_RECEIVE, $qty, (int) $purchase->branch_id);
            }

            if (abs($received - $need) > 0.0001) {
                throw ValidationException::withMessages(["{$field}.batches" => ["The batches add up to {$received} but {$need} were received."]]);
            }
        }
    }

    /**
     * Outward: a sale names the serials it sells, or the batches it draws from (the earliest expiry first when it names none).
     *
     * @param  list<SellLine>  $lines  the saved lines, in the order of the valid rows
     * @param  array<int, mixed>  $rows  the request's sell lines
     *
     * @throws ValidationException
     */
    public function sell(Transaction $sell, array $lines, array $rows): void
    {
        if (! self::enabled()) {
            return;
        }

        $this->clear((int) $sell->id, [self::KIND_SALE]);

        if (in_array($sell->status, Transaction::UNPOSTED_SELL_STATUSES, true)) {
            return;
        }

        $tracked = self::trackedTypes(array_map(fn (SellLine $line): ?int => $line->product_id, $lines));
        $valid = array_keys(array_filter($rows, 'is_array'));
        $used = [];
        $drawn = [];

        foreach ($lines as $at => $line) {
            $type = $tracked[(int) $line->product_id] ?? null;
            $need = $this->baseQuantity($line->quantity, $line->packing_qty);

            if ($type === null || $need <= 0) {
                continue;
            }

            $row = (array) ($rows[$valid[$at] ?? -1] ?? []);
            $field = 'selllines.'.($valid[$at] ?? $at);

            if ($type === 'serial') {
                foreach ($this->serialNumbers($row, $need, $field) as $serialNo) {
                    $serial = $this->existingSerial($sell, (int) $line->product_id, $serialNo, "{$field}.serials");
                    $this->assertSerialAvailable($serial, (int) $sell->branch_id, $serialNo, "{$field}.serials", $used);
                    $this->serialMove($serial, $sell, 'sell_lines', (int) $line->id, self::KIND_SALE, -1, (int) $sell->branch_id);
                }

                continue;
            }

            foreach ($this->drawBatches($sell, (int) $line->product_id, (int) $sell->branch_id, $row, $need, $line->packing_qty, $field, $sell->transaction_date?->toDateString(), $drawn) as [$batchId, $qty]) {
                $this->batchMove((object) ['id' => $batchId], $sell, 'sell_lines', (int) $line->id, self::KIND_SALE, -$qty, (int) $sell->branch_id);
            }
        }
    }

    /**
     * Internal movement: a stock transfer carries the same serials, or batches, from one branch to the other. The source side
     * is reserved as soon as the transfer exists; the destination side counts once it is completed.
     *
     * @param  list<PurchaseLine>  $lines  the saved lines, in the order of the valid rows
     * @param  array<int, mixed>  $rows  the request's lines
     *
     * @throws ValidationException
     */
    public function transfer(Transaction $transfer, array $lines, array $rows): void
    {
        if (! self::enabled()) {
            return;
        }

        $this->clear((int) $transfer->id, [self::KIND_TRANSFER_OUT, self::KIND_TRANSFER_IN]);
        $tracked = self::trackedTypes(array_map(fn (PurchaseLine $line): ?int => $line->product_id, $lines));
        $valid = array_keys(array_filter($rows, 'is_array'));
        $used = [];
        $drawn = [];
        $from = (int) $transfer->branch_id;
        $to = (int) $transfer->tobranch_id;

        foreach ($lines as $at => $line) {
            $type = $tracked[(int) $line->product_id] ?? null;
            $need = $this->baseQuantity($line->quantity, $line->packing_qty);

            if ($type === null || $need <= 0) {
                continue;
            }

            $row = (array) ($rows[$valid[$at] ?? -1] ?? []);
            $field = 'purchaselines.'.($valid[$at] ?? $at);

            if ($type === 'serial') {
                foreach ($this->serialNumbers($row, $need, $field) as $serialNo) {
                    $serial = $this->existingSerial($transfer, (int) $line->product_id, $serialNo, "{$field}.serials");
                    $this->assertSerialAvailable($serial, $from, $serialNo, "{$field}.serials", $used);
                    $this->serialMove($serial, $transfer, 'purchase_lines', (int) $line->id, self::KIND_TRANSFER_OUT, -1, $from);
                    $this->serialMove($serial, $transfer, 'purchase_lines', (int) $line->id, self::KIND_TRANSFER_IN, 1, $to);
                }

                continue;
            }

            foreach ($this->drawBatches($transfer, (int) $line->product_id, $from, $row, $need, $line->packing_qty, $field, $transfer->transaction_date?->toDateString(), $drawn) as [$batchId, $qty]) {
                $this->batchMove((object) ['id' => $batchId], $transfer, 'purchase_lines', (int) $line->id, self::KIND_TRANSFER_OUT, -$qty, $from);
                $this->batchMove((object) ['id' => $batchId], $transfer, 'purchase_lines', (int) $line->id, self::KIND_TRANSFER_IN, $qty, $to);
            }
        }
    }

    // --------------------------------------------------------------------------------------------------- manual entries

    /**
     * Puts serials into stock without a document (opening stock, or a sale or purchase return, an adjustment) and only says so
     * in a note. It changes no stock quantity: it only records the serials.
     *
     * @param  list<string>  $serialNumbers
     *
     * @throws ValidationException
     */
    public function registerSerials(int $companyId, int $productId, ?int $variationId, int $branchId, array $serialNumbers, ?string $note): int
    {
        $this->assertTracked($productId, 'serial');
        $made = 0;

        foreach (array_values(array_unique(array_filter(array_map('trim', $serialNumbers), fn (string $value): bool => $value !== ''))) as $serialNo) {
            $serial = DB::table('stock_serials')->where('company_id', $companyId)->where('product_id', $productId)->where('serial_no', $serialNo)->first();
            $serialId = $serial?->id ?? DB::table('stock_serials')->insertGetId(['company_id' => $companyId, 'product_id' => $productId, 'variation_id' => $variationId, 'serial_no' => $serialNo, 'created_at' => now(), 'updated_at' => now()]);

            $state = DB::query()->fromSub(self::serialStates(), 'x')->where('x.id', $serialId)->first();

            if ($state !== null && (self::serialInStock($state) || $state->last_kind === self::KIND_TRANSFER_OUT)) {
                throw ValidationException::withMessages(['serials' => ["Serial {$serialNo} is already in stock."]]);
            }

            $this->insertSerialMovement($serialId, null, null, null, self::KIND_REGISTER, 1, $branchId, $note);
            $made++;
        }

        return $made;
    }

    /**
     * Takes a serial that is in stock out of it (lost, damaged, written off).
     *
     * @throws ValidationException
     */
    public function writeOffSerial(int $serialId, ?string $note): void
    {
        $state = DB::query()->fromSub(self::serialStates(), 'x')->where('x.id', $serialId)->first();

        if ($state === null || ! self::serialInStock($state)) {
            throw ValidationException::withMessages(['serial' => ['Only a serial that is in stock can be written off.']]);
        }

        $this->insertSerialMovement($serialId, null, null, null, self::KIND_WRITE_OFF, -1, (int) $state->branch_id, $note);
    }

    /**
     * Puts a quantity of a batch into a branch without a document (opening stock, a return).
     *
     * @throws ValidationException
     */
    public function registerBatch(int $companyId, int $productId, ?int $variationId, int $branchId, string $batchNo, ?string $expiryDate, float $qty, ?string $note): void
    {
        $this->assertTracked($productId, 'batch');
        $batch = $this->batchOf((object) ['company_id' => $companyId], $productId, $variationId, trim($batchNo), $expiryDate, 'batch_no');
        $this->insertBatchMovement((int) $batch->id, null, null, null, self::KIND_REGISTER, round($qty, 4), $branchId, $note);
    }

    /**
     * Takes a quantity of a batch out of a branch (expired, damaged, written off).
     *
     * @throws ValidationException
     */
    public function writeOffBatch(int $batchId, int $branchId, float $qty, ?string $note): void
    {
        $available = (float) DB::query()->fromSub(self::batchStock(), 'x')->where('x.batch_id', $batchId)->where('x.branch_id', $branchId)->value('x.qty');

        if ($qty <= 0 || $qty > $available + 0.0001) {
            throw ValidationException::withMessages(['qty' => ["Only {$available} of this batch is in that branch."]]);
        }

        $this->insertBatchMovement($batchId, null, null, null, self::KIND_WRITE_OFF, -round($qty, 4), $branchId, $note);
    }

    // ------------------------------------------------------------------------------------------------------- internals

    /**
     * Drops what a document wrote before, so saving it again replaces its movements (its lines may have been re-created).
     *
     * @param  list<string>  $kinds
     */
    private function clear(int $transactionId, array $kinds): void
    {
        foreach (['stock_serial_movements', 'stock_batch_movements'] as $table) {
            DB::table($table)->where('transaction_id', $transactionId)->whereIn('kind', $kinds)->delete();
        }
    }

    private function pack(mixed $packing): float
    {
        return max((float) $packing, 1.0);
    }

    private function baseQuantity(mixed $quantity, mixed $packing): float
    {
        return round((float) $quantity * $this->pack($packing), 4);
    }

    /**
     * @param  array<int, mixed>  $rows
     * @return array{0: array<string, mixed>, 1: int}
     */
    private function rowFor(array $rows, int $lineId): array
    {
        foreach ($rows as $index => $row) {
            if (is_array($row) && (int) ($row['id'] ?? 0) === $lineId) {
                return [$row, (int) $index];
            }
        }

        return [[], 0];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return list<string>
     *
     * @throws ValidationException
     */
    private function serialNumbers(array $row, float $need, string $field): array
    {
        $raw = $row['serials'] ?? [];
        $list = is_array($raw) ? $raw : preg_split('/[\r\n,;]+/', (string) $raw);
        $serials = array_values(array_filter(array_map(fn ($value): string => trim((string) $value), (array) $list), fn (string $value): bool => $value !== ''));

        if (abs($need - round($need)) > 0.0001 || count($serials) !== (int) round($need)) {
            throw ValidationException::withMessages(["{$field}.serials" => ['Enter exactly '.rtrim(rtrim(number_format($need, 4, '.', ''), '0'), '.').' serial number(s), one for each unit; '.count($serials).' given.']]);
        }

        if (count(array_unique($serials)) !== count($serials)) {
            throw ValidationException::withMessages(["{$field}.serials" => ['A serial number is listed twice.']]);
        }

        return $serials;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return list<array{batch_no: string, expiry_date: string|null, qty: float}>
     *
     * @throws ValidationException
     */
    private function receivedBatches(array $row, string $field): array
    {
        $merged = [];

        foreach ((array) ($row['batches'] ?? []) as $entry) {
            $batchNo = trim((string) ($entry['batch_no'] ?? ''));
            $qty = round((float) ($entry['qty'] ?? 0), 4);
            $expiry = filled($entry['expiry_date'] ?? null) ? substr((string) $entry['expiry_date'], 0, 10) : null;

            if ($batchNo === '' || $qty <= 0) {
                throw ValidationException::withMessages(["{$field}.batches" => ['Every batch needs a batch number and a quantity.']]);
            }

            if (isset($merged[$batchNo]) && $merged[$batchNo]['expiry_date'] !== $expiry) {
                throw ValidationException::withMessages(["{$field}.batches" => ["Batch {$batchNo} is listed with two different expiry dates."]]);
            }

            $merged[$batchNo] = ['batch_no' => $batchNo, 'expiry_date' => $expiry, 'qty' => ($merged[$batchNo]['qty'] ?? 0) + $qty];
        }

        if ($merged === []) {
            throw ValidationException::withMessages(["{$field}.batches" => ['Enter the batch number, expiry date and quantity this line was received in.']]);
        }

        return array_values($merged);
    }

    private function serialOf(object $document, int $productId, mixed $variationId, string $serialNo): object
    {
        $companyId = (int) $document->company_id;
        $existing = DB::table('stock_serials')->where('company_id', $companyId)->where('product_id', $productId)->where('serial_no', $serialNo)->first();

        if ($existing !== null) {
            return $existing;
        }

        $id = DB::table('stock_serials')->insertGetId(['company_id' => $companyId, 'product_id' => $productId, 'variation_id' => $variationId, 'serial_no' => $serialNo, 'created_at' => now(), 'updated_at' => now()]);

        return (object) ['id' => $id, 'company_id' => $companyId, 'product_id' => $productId, 'serial_no' => $serialNo];
    }

    private function existingSerial(object $document, int $productId, string $serialNo, string $field): object
    {
        $serial = DB::table('stock_serials')->where('company_id', (int) $document->company_id)->where('product_id', $productId)->where('serial_no', $serialNo)->first();

        if ($serial === null) {
            throw ValidationException::withMessages([$field => ["Serial {$serialNo} is not known for this product."]]);
        }

        return $serial;
    }

    private function stateOf(object $serial): ?object
    {
        return DB::query()->fromSub(self::serialStates(), 'x')->where('x.id', $serial->id)->first();
    }

    private function isHeld(object $serial): bool
    {
        $state = $this->stateOf($serial);

        return $state !== null && (self::serialInStock($state) || $state->last_kind === self::KIND_TRANSFER_OUT);
    }

    /**
     * @param  array<int, true>  $used
     *
     * @throws ValidationException
     */
    private function assertSerialAvailable(object $serial, int $branchId, string $serialNo, string $field, array &$used): void
    {
        $state = $this->stateOf($serial);

        if (isset($used[$serial->id]) || $state === null || ! self::serialInStock($state) || (int) $state->branch_id !== $branchId) {
            $where = $state === null ? 'unknown' : str_replace('_', ' ', self::serialStatus($state));

            throw ValidationException::withMessages([$field => ["Serial {$serialNo} is not available in this branch ({$where})."]]);
        }

        $used[$serial->id] = true;
    }

    private function serialMove(object $serial, Transaction $document, string $lineTable, int $lineId, string $kind, int $direction, int $branchId): void
    {
        $this->insertSerialMovement((int) $serial->id, (int) $document->id, $lineTable, $lineId, $kind, $direction, $branchId, null);
    }

    private function insertSerialMovement(int $serialId, ?int $transactionId, ?string $lineTable, ?int $lineId, string $kind, int $direction, int $branchId, ?string $note): void
    {
        DB::table('stock_serial_movements')->insert([
            'serial_id' => $serialId, 'transaction_id' => $transactionId, 'line_table' => $lineTable, 'line_id' => $lineId, 'kind' => $kind,
            'direction' => $direction, 'branch_id' => $branchId, 'created_by' => Auth::id(), 'note' => $note, 'moved_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function batchMove(object $batch, Transaction $document, string $lineTable, int $lineId, string $kind, float $qty, int $branchId): void
    {
        $this->insertBatchMovement((int) $batch->id, (int) $document->id, $lineTable, $lineId, $kind, $qty, $branchId, null);
    }

    private function insertBatchMovement(int $batchId, ?int $transactionId, ?string $lineTable, ?int $lineId, string $kind, float $qty, int $branchId, ?string $note): void
    {
        DB::table('stock_batch_movements')->insert([
            'batch_id' => $batchId, 'transaction_id' => $transactionId, 'line_table' => $lineTable, 'line_id' => $lineId, 'kind' => $kind,
            'qty' => $qty, 'branch_id' => $branchId, 'created_by' => Auth::id(), 'note' => $note, 'moved_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /**
     * @throws ValidationException
     */
    private function batchOf(object $document, int $productId, mixed $variationId, string $batchNo, ?string $expiryDate, string $field): object
    {
        $companyId = (int) $document->company_id;
        $existing = DB::table('stock_batches')->where('company_id', $companyId)->where('product_id', $productId)->where('batch_no', $batchNo)->first();

        if ($existing !== null) {
            $current = $existing->expiry_date === null ? null : substr((string) $existing->expiry_date, 0, 10);

            if ($expiryDate !== null && $current !== null && $current !== $expiryDate) {
                throw ValidationException::withMessages(["{$field}.batches" => ["Batch {$batchNo} already has the expiry date {$current}."]]);
            }

            if ($current === null && $expiryDate !== null) {
                DB::table('stock_batches')->where('id', $existing->id)->update(['expiry_date' => $expiryDate, 'updated_at' => now()]);
            }

            return $existing;
        }

        $id = DB::table('stock_batches')->insertGetId(['company_id' => $companyId, 'product_id' => $productId, 'variation_id' => $variationId, 'batch_no' => $batchNo, 'expiry_date' => $expiryDate, 'created_at' => now(), 'updated_at' => now()]);

        return (object) ['id' => $id];
    }

    /**
     * Which batches a line draws from: the ones the line names, or - when it names none - the earliest expiry first (never an
     * expired batch). `$drawn` keeps what earlier lines of the same document already took.
     *
     * @param  array<string, mixed>  $row
     * @param  array<string, float>  $drawn
     * @return list<array{0: int, 1: float}> [batch id, base quantity]
     *
     * @throws ValidationException
     */
    private function drawBatches(Transaction $document, int $productId, int $branchId, array $row, float $need, mixed $packing, string $field, ?string $onDate, array &$drawn): array
    {
        $available = DB::query()->fromSub(self::batchStock(), 'x')
            ->where('x.company_id', (int) $document->company_id)->where('x.product_id', $productId)->where('x.branch_id', $branchId)->where('x.qty', '>', 0)
            ->get()->keyBy('batch_id');
        $left = fn (int $batchId): float => (float) ($available->get($batchId)->qty ?? 0) - ($drawn[$batchId] ?? 0);
        $expired = fn (object $batch): bool => $batch->expiry_date !== null && $onDate !== null && substr((string) $batch->expiry_date, 0, 10) < $onDate;
        $picked = [];
        $named = (array) ($row['batches'] ?? []);

        if ($named !== []) {
            $total = 0.0;

            foreach ($named as $entry) {
                $batchId = (int) ($entry['batch_id'] ?? 0);
                $qty = round((float) ($entry['qty'] ?? 0) * $this->pack($packing), 4);
                $batch = $available->get($batchId);

                if ($batch === null || $qty <= 0 || $qty > $left($batchId) + 0.0001) {
                    throw ValidationException::withMessages(["{$field}.batches" => ['A chosen batch has no such quantity in this branch.']]);
                }

                if ($expired($batch)) {
                    throw ValidationException::withMessages(["{$field}.batches" => ["Batch {$batch->batch_no} has expired."]]);
                }

                $drawn[$batchId] = ($drawn[$batchId] ?? 0) + $qty;
                $picked[] = [$batchId, $qty];
                $total += $qty;
            }

            if (abs($total - $need) > 0.0001) {
                throw ValidationException::withMessages(["{$field}.batches" => ["The batches add up to {$total} but the line needs {$need}."]]);
            }

            return $picked;
        }

        $order = $available->filter(fn (object $batch): bool => ! $expired($batch))->sortBy(fn (object $batch): string => ($batch->expiry_date === null ? '9999-12-31' : substr((string) $batch->expiry_date, 0, 10)).'#'.str_pad((string) $batch->batch_id, 12, '0', STR_PAD_LEFT));
        $remaining = $need;

        foreach ($order as $batch) {
            if ($remaining <= 0.0001) {
                break;
            }

            $take = min($remaining, $left((int) $batch->batch_id));

            if ($take > 0) {
                $drawn[(int) $batch->batch_id] = ($drawn[(int) $batch->batch_id] ?? 0) + $take;
                $picked[] = [(int) $batch->batch_id, round($take, 4)];
                $remaining -= $take;
            }
        }

        if ($remaining > 0.0001) {
            throw ValidationException::withMessages(["{$field}.batches" => ['Only '.round($need - $remaining, 4).' of '.$need.' is available in this branch from batches that have not expired.']]);
        }

        return $picked;
    }

    /**
     * @throws ValidationException
     */
    private function assertTracked(int $productId, string $type): void
    {
        if ((self::trackedTypes([$productId])[$productId] ?? null) !== $type) {
            throw ValidationException::withMessages(['product_id' => ["This product is not tracked by {$type}. Set its tracking on the product first."]]);
        }
    }
}
