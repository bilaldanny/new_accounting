<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Branch;
use App\Services\ContactImporter;
use App\Services\System\ImportHistory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * Body of the customer / supplier `import` actions: scopes the file to the caller's company (a superadmin
 * names one) and branch, then hands the rows to ContactImporter. `dry_run` checks the file and writes nothing.
 */
trait ImportsContacts
{
    protected function importContacts(Request $request, string $kind): JsonResponse
    {
        $request->validate([
            'rows' => 'bail|required|array|min:1|max:'.ContactImporter::MAX_ROWS,
            'dry_run' => 'nullable|boolean',
            'allow_duplicates' => 'nullable|boolean',
            'company_id' => 'nullable|integer',
            'branch_id' => 'nullable|integer',
            'file_name' => 'nullable|string|max:190',
        ]);

        $user = Auth::user();
        $isSuperadmin = $user?->hasRole('superadmin') === true;
        $companyId = $isSuperadmin ? (int) $request->input('company_id') : (int) $user?->company_id;

        if ($companyId <= 0) {
            throw ValidationException::withMessages(['company_id' => ['Choose the company to import into.']]);
        }

        $branchId = $this->importBranchId($request, $companyId, $isSuperadmin || $user?->hasRole('companyadmin') === true);

        $result = app(ContactImporter::class)->run(
            $kind,
            $request->input('rows'),
            $companyId,
            $branchId,
            $request->boolean('dry_run'),
            $request->boolean('allow_duplicates'),
        );

        if (! $result['dry_run'] && $result['ok']) {
            app(ImportHistory::class)->record(
                $companyId, $user?->id, $kind === 'supplier' ? 'suppliers' : 'customers',
                array_values(array_filter(array_map(fn (array $row): ?int => $row['contact_id'] ?? null, $result['rows']))),
                $result['summary'], 'file', $request->input('file_name'),
            );
        }

        $summary = $result['summary'];
        $label = $kind === 'supplier' ? 'supplier records' : 'customer records';

        if ($result['dry_run']) {
            $message = sprintf('Checked %d rows: %d can be added, %d already exist, %d have errors.', $summary['total'], $summary['created'], $summary['duplicates'], $summary['failed']);
        } elseif ($result['ok']) {
            $message = sprintf('Successfully imported %d new and updated 0 %s.', $summary['created'], $label)
                .($summary['duplicates'] > 0 ? sprintf(' %d rows were skipped as duplicates.', $summary['duplicates']) : '');
        } else {
            $message = 'Import could not be completed: nothing was saved. Fix the rows with errors and try again.';
        }

        $errors = [];

        foreach ($result['rows'] as $row) {
            foreach ($row['errors'] as $error) {
                $errors[] = 'Row '.$row['row'].': '.$error;
            }

            if ($row['status'] === 'duplicate') {
                $errors[] = 'Row '.$row['row'].' skipped: '.$row['duplicate_of'].'.';
            }
        }

        return response()->json([
            'message' => $message,
            'dry_run' => $result['dry_run'],
            'summary' => $summary,
            'rows' => $result['rows'],
            'errors' => $errors,
        ], $result['ok'] || $result['dry_run'] ? 200 : 422);
    }

    private function importBranchId(Request $request, int $companyId, bool $mayChooseBranch): int
    {
        $user = Auth::user();

        if (! $mayChooseBranch && $user?->branch_id) {
            return (int) $user->branch_id;
        }

        $query = Branch::query()->where('company_id', $companyId);

        if ($request->filled('branch_id')) {
            $branchId = $query->where('id', (int) $request->input('branch_id'))->value('id');
        } else {
            $branchId = $query->orderBy('id')->value('id');
        }

        if ($branchId === null) {
            throw ValidationException::withMessages(['branch_id' => ['No branch of this company was found to import into.']]);
        }

        return (int) $branchId;
    }
}
