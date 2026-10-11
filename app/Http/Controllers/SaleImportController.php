<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ScopesToCompany;
use App\Models\Branch;
use App\Services\SaleImporter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * Import Sales from a spreadsheet (see App\Services\SaleImporter). `dry_run` checks the file and saves nothing; imported sales
 * are drafts unless the file is imported as `final`, which posts them like any sale.
 */
class SaleImportController extends Controller
{
    use ScopesToCompany;

    public function import(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/sell/import');

        $request->validate([
            'rows' => 'bail|required|array|min:1|max:'.SaleImporter::MAX_ROWS,
            'dry_run' => 'nullable|boolean',
            'status' => 'nullable|in:draft,final',
            'company_id' => 'nullable|integer',
            'branch_id' => 'nullable|integer',
        ]);

        $companyId = $this->scopeCompanyId($request);
        $branchId = $this->scopeBranchId($request, $companyId)
            ?? (Auth::user()?->branch_id ? (int) Auth::user()->branch_id : null)
            ?? Branch::query()->where('company_id', $companyId)->orderBy('id')->value('id');

        if ($branchId === null) {
            throw ValidationException::withMessages(['branch_id' => ['No branch of this company was found to import into.']]);
        }

        $result = app(SaleImporter::class)->run($request->input('rows'), $companyId, (int) $branchId, $request->input('status', 'draft'), $request->boolean('dry_run'));

        $errors = [];

        foreach ($result['invoices'] as $invoice) {
            foreach ($invoice['errors'] as $error) {
                $errors[] = $error;
            }

            if ($invoice['status'] === 'duplicate') {
                $errors[] = 'Invoice '.$invoice['invoice_ref'].' was imported before and is skipped.';
            }
        }

        $summary = $result['summary'];
        $message = $result['dry_run']
            ? sprintf('Checked %d invoices: %d can be made, %d already imported, %d have errors.', $summary['invoices'], $summary['created'], $summary['duplicates'], $summary['failed'])
            : ($result['ok'] ? sprintf('Imported %d invoice(s) from %d rows.', $summary['created'], $summary['rows']) : 'Import could not be completed: nothing was saved. Fix the rows with errors and try again.');

        return response()->json([
            'message' => $message, 'dry_run' => $result['dry_run'], 'summary' => $summary, 'invoices' => $result['invoices'], 'errors' => $errors,
        ], $result['ok'] || $result['dry_run'] ? 200 : 422);
    }
}
