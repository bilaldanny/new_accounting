<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesReportScope;
use App\Models\Currency;
use App\Models\Project;
use App\Services\MultiCurrency;
use App\Services\ProjectTag;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The two optional pieces the sale, purchase, expense and journal forms may show: the projects a line can be tagged with, and,
 * only when the company switched multi-currency vouchers on, the foreign currencies with today's rate. Both are the signed-in
 * user's own company (a superadmin names one with `company_id`); with no company nothing is offered. A company that uses neither
 * gets empty lists, so the forms look exactly as before.
 */
class VoucherOptionsController extends Controller
{
    use ResolvesReportScope;

    public function __invoke(Request $request): JsonResponse
    {
        $request->validate(['company_id' => 'nullable|integer']);
        [$companyId] = $this->reportScope($request);

        if ($companyId === null) {
            return response()->json(['multi_currency' => false, 'currencies' => [], 'projects' => []]);
        }

        $projects = ProjectTag::supported('sell_lines')
            ? Project::query()->visibleToCurrentUser()->where('company_id', $companyId)->where('status', 'active')->orderBy('code')->limit(300)->get(['id', 'code', 'name'])
                ->map(fn (Project $p): array => ['id' => (int) $p->id, 'text' => trim($p->code.' '.$p->name)])->all()
            : [];

        $enabled = MultiCurrency::enabled($companyId);
        $base = $enabled ? MultiCurrency::baseCurrencyId($companyId) : null;
        $today = now()->toDateString();
        $currencies = $enabled && $base !== null
            ? Currency::query()->where('is_active', true)->where('id', '!=', $base)->orderBy('code')->limit(100)->get(['id', 'code', 'name'])
                ->map(fn (Currency $c): array => ['id' => (int) $c->id, 'text' => $c->code, 'rate' => MultiCurrency::rateOn($companyId, (int) $c->id, $today)])
                ->filter(fn (array $c): bool => $c['rate'] !== null && $c['rate'] > 0)->values()->all()
            : [];

        return response()->json(['multi_currency' => $enabled && $base !== null, 'currencies' => $currencies, 'projects' => $projects]);
    }
}
