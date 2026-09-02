<?php

namespace App\Console\Commands;

use App\Models\Transaction;
use App\Services\PurchaseJournal;
use App\Services\SellJournal;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class SyncDocumentJournals extends Command
{
    /**
     * @var string
     */
    protected $signature = 'journals:sync-documents {--type=all : purchase, sell, or all}';

    /**
     * @var string
     */
    protected $description = 'Post or refresh balanced purchase and sell ledger journals';

    public function handle(PurchaseJournal $purchases, SellJournal $sells): int
    {
        $type = (string) $this->option('type');
        $types = match ($type) {
            'purchase' => [Transaction::TYPE_PURCHASE],
            'sell' => [Transaction::TYPE_SELL],
            'all' => [Transaction::TYPE_PURCHASE, Transaction::TYPE_SELL],
            default => null,
        };

        if ($types === null) {
            $this->error('Type must be purchase, sell, or all.');

            return self::FAILURE;
        }

        $posted = 0;
        $skipped = 0;

        Transaction::query()
            ->with('contact')
            ->whereIn('type', $types)
            ->whereNotIn('status', ['draft', 'quotation'])
            ->orderBy('id')
            ->each(function (Transaction $document) use ($purchases, $sells, &$posted, &$skipped): void {
                try {
                    DB::transaction(function () use ($document, $purchases, $sells): void {
                        if ($document->type === Transaction::TYPE_PURCHASE) {
                            $purchases->sync($document);
                        }

                        if ($document->type === Transaction::TYPE_SELL) {
                            $sells->sync($document);
                        }
                    });

                    $posted++;
                } catch (ValidationException $exception) {
                    $skipped++;
                    $this->warn($this->documentLabel($document).': '.collect($exception->errors())->flatten()->implode(' '));
                } catch (Throwable $exception) {
                    $skipped++;
                    $this->warn($this->documentLabel($document).': '.$exception->getMessage());
                }
            });

        $this->info("Posted {$posted} journal(s). Skipped {$skipped}.");

        return self::SUCCESS;
    }

    private function documentLabel(Transaction $document): string
    {
        return trim($document->type.' '.$document->invoice_no.' #'.$document->id);
    }
}
