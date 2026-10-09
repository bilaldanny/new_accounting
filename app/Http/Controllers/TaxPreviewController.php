<?php

namespace App\Http\Controllers;

use App\Services\TaxCalculator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * What the tax on a sale or purchase form comes to, worked out by the same TaxCalculator that saves the document. Nothing
 * is stored; the form shows the answer while the user is still typing.
 */
class TaxPreviewController extends Controller
{
    public function __invoke(Request $request, TaxCalculator $calculator): JsonResponse
    {
        $data = $request->validate([
            'company_id' => 'nullable|integer',
            'contact_id' => 'nullable|integer',
            'date' => 'nullable|date',
            'inclusive' => 'nullable|boolean',
            'discount' => 'nullable|numeric|min:0',
            'field' => 'nullable|in:selllines,purchaselines',
            'lines' => 'present|array',
            'lines.*.tax_id' => 'nullable|integer',
            'lines.*.product_id' => 'nullable|integer',
            'lines.*.amount' => 'nullable|numeric',
        ]);

        $user = Auth::user();
        $companyId = $user?->hasRole('superadmin') ? ($data['company_id'] ?? null) : $user?->company_id;

        $lines = array_map(fn (array $line): array => [
            'tax_id' => isset($line['tax_id']) && (int) $line['tax_id'] > 0 ? (int) $line['tax_id'] : null,
            'product_id' => isset($line['product_id']) && (int) $line['product_id'] > 0 ? (int) $line['product_id'] : null,
            'amount' => (float) ($line['amount'] ?? 0),
        ], array_values($data['lines']));

        return response()->json($calculator->calculate(
            $lines,
            (bool) ($data['inclusive'] ?? false),
            $companyId === null ? null : (int) $companyId,
            isset($data['contact_id']) ? (int) $data['contact_id'] : null,
            $data['date'] ?? null,
            (float) ($data['discount'] ?? 0),
            $data['field'] ?? 'selllines',
        ));
    }
}
