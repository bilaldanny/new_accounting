<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ScopesToCompany;
use App\Services\Documents\CustomFields;
use App\Services\System\DataMigration;
use App\Services\System\DataTransfer;
use App\Services\System\ImportHistory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The import / export center: exports of customers, suppliers, products and the chart of accounts (CSV or Excel), the column
 * templates of the importers, the history of imports with the undo of an import that only created records, and the migration of
 * contacts from a template, a Tally ledger export or a QuickBooks contact list through the one contact importer. Everything
 * is company scoped and needs `/businesssettings/import`. The custom field values of contacts and products live here too.
 */
class DataCenterController extends Controller
{
    use ScopesToCompany;

    public function export(Request $request, string $entity, DataTransfer $transfer): StreamedResponse
    {
        $this->authorizeMenuPermission('/businesssettings/import');
        abort_unless(in_array($entity, DataTransfer::EXPORTS, true), 404);
        $request->validate(['format' => ['required', Rule::in(['csv', 'xlsx'])], 'company_id' => 'nullable|integer']);

        $rows = $transfer->export($entity, $this->scopeCompanyId($request), $this->branchId());

        return $this->download($transfer, $rows, $entity, $request->input('format'));
    }

    public function template(Request $request, string $entity, DataTransfer $transfer): StreamedResponse
    {
        $this->authorizeMenuPermission('/businesssettings/import');
        abort_unless(isset(DataTransfer::TEMPLATES[$entity]), 404);
        $request->validate(['format' => ['required', Rule::in(['csv', 'xlsx'])]]);

        return $this->download($transfer, [DataTransfer::TEMPLATES[$entity]], $entity.'-template', $request->input('format'));
    }

    public function history(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/businesssettings/import');

        return response()->json(['data' => DB::table('import_runs')->where('company_id', $this->scopeCompanyId($request))->orderByDesc('id')->limit(100)->get()
            ->map(fn (object $row): array => [
                'id' => (int) $row->id, 'entity' => $row->entity, 'source' => $row->source, 'file_name' => $row->file_name, 'status' => $row->status,
                'created' => count((array) json_decode((string) $row->created_ids, true)), 'summary' => json_decode((string) $row->summary, true), 'created_at' => $row->created_at, 'undone_at' => $row->undone_at,
            ])->all()]);
    }

    public function undo(Request $request, int $id, ImportHistory $history): JsonResponse
    {
        $this->authorizeMenuPermission('/businesssettings/import');
        $result = $history->undo($this->scopeCompanyId($request), $id, Auth::id());

        return response()->json(['message' => "Removed {$result['undone']} records".($result['kept'] === [] ? '.' : ', kept '.count($result['kept']).' that are in use.'), 'data' => $result]);
    }

    public function migrate(Request $request, DataMigration $migration, ImportHistory $history): JsonResponse
    {
        $this->authorizeMenuPermission('/businesssettings/import');
        $data = $request->validate([
            'source' => ['required', Rule::in(DataMigration::SOURCES)], 'kind' => ['nullable', Rule::in(['customer', 'supplier'])], 'csv' => 'required|string|max:1500000',
            'commit' => 'nullable|boolean', 'file_name' => 'nullable|string|max:190', 'company_id' => 'nullable|integer', 'branch_id' => 'nullable|integer',
        ]);
        $companyId = $this->scopeCompanyId($request);
        $branchId = $this->targetBranch($request, $companyId);

        $parsed = $migration->parse($data['csv']);

        if ($parsed['rows'] === []) {
            throw ValidationException::withMessages(['csv' => ['The file has no rows to import.']]);
        }

        $mapped = $migration->map($data['source'], $parsed, $data['kind'] ?? 'customer');
        $commit = (bool) ($data['commit'] ?? false);
        $results = $migration->run($mapped, $companyId, $branchId, ! $commit);
        $ok = collect($results)->filter()->every(fn (array $result): bool => $result['ok']);

        if ($commit && $ok) {
            foreach (['customers', 'suppliers'] as $entity) {
                if ($results[$entity] !== null) {
                    $history->record(
                        $companyId, Auth::id(), $entity,
                        array_values(array_filter(array_map(fn (array $row): ?int => $row['contact_id'] ?? null, $results[$entity]['rows']))), $results[$entity]['summary'], $data['source'], $data['file_name'] ?? null,
                    );
                }
            }
        }

        return response()->json([
            'message' => $commit ? ($ok ? 'Imported.' : 'Nothing was saved: fix the rows with errors.') : 'Checked: nothing was saved.',
            'dry_run' => ! $commit, 'ok' => $ok, 'mapped' => ['customers' => count($mapped['customers']), 'suppliers' => count($mapped['suppliers']), 'skipped' => $mapped['skipped'], 'unmatched_columns' => $mapped['unmatched']],
            'results' => $results,
        ], $ok || ! $commit ? 200 : 422);
    }

    // ---------------------------------------------------------------- custom field values of contacts and products

    public function customValues(Request $request, string $entity, int $id, CustomFields $fields): JsonResponse
    {
        [$companyId, $permission] = $this->customRecord($request, $entity, $id, 'view');
        $this->authorizeMenuPermission($permission);

        return response()->json(['data' => $fields->valuesFor($companyId, $entity, $id)]);
    }

    public function setCustomValues(Request $request, string $entity, int $id, CustomFields $fields): JsonResponse
    {
        [$companyId, $permission] = $this->customRecord($request, $entity, $id, 'edit');
        $this->authorizeMenuPermission($permission);
        $data = $request->validate(['values' => 'required|array|max:100']);

        $fields->setValues($companyId, $entity, $id, $data['values']);

        return response()->json(['message' => 'Successfully Saved', 'data' => $fields->valuesFor($companyId, $entity, $id)]);
    }

    /**
     * @return array{0: int, 1: string} the company of the record and the menu permission for the action
     */
    private function customRecord(Request $request, string $entity, int $id, string $action): array
    {
        abort_unless(in_array($entity, ['contact', 'product'], true), 404);
        $companyId = $this->scopeCompanyId($request);

        if ($entity === 'product') {
            abort_unless(DB::table('products')->where('company_id', $companyId)->whereNull('deleted_at')->where('id', $id)->exists(), 404);

            return [$companyId, $action === 'view' ? '/product' : '/product/:id/edit'];
        }

        $contact = DB::table('contacts')->where('company_id', $companyId)->whereNull('deleted_at')->where('id', $id)->first(['user_type', 'branch_id']);
        abort_if($contact === null, 404);

        $branch = $this->branchId();
        abort_if($branch !== null && (int) $contact->branch_id !== $branch, 404);

        $base = $contact->user_type === 'supplier' ? '/supplier' : '/customer';

        return [$companyId, $action === 'view' ? $base.'/:id/view' : $base.'/:id/edit'];
    }

    private function branchId(): ?int
    {
        $user = Auth::user();

        return $user->branch_id && ! $user->hasRole('superadmin') && ! $user->hasRole('companyadmin') ? (int) $user->branch_id : null;
    }

    private function targetBranch(Request $request, int $companyId): int
    {
        $branch = $this->branchId();

        if ($branch === null && $request->filled('branch_id')) {
            $branch = (int) DB::table('branches')->where('company_id', $companyId)->where('id', $request->integer('branch_id'))->value('id');
        }

        $branch = $branch ?: (int) DB::table('branches')->where('company_id', $companyId)->whereNull('deleted_at')->orderBy('id')->value('id');

        if ($branch === 0) {
            throw ValidationException::withMessages(['branch_id' => ['No branch of this company was found to import into.']]);
        }

        return $branch;
    }

    /**
     * @param  list<list<scalar|null>>  $rows
     */
    private function download(DataTransfer $transfer, array $rows, string $name, string $format): StreamedResponse
    {
        $content = $format === 'xlsx' ? $transfer->xlsx($rows, $name) : $transfer->csv($rows);

        return response()->streamDownload(function () use ($content): void {
            echo $content;
        }, $name.'.'.$format, ['Content-Type' => $format === 'xlsx' ? 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' : 'text/csv']);
    }
}
