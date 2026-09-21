<?php

namespace App\Http\Controllers;

use App\Models\DocumentSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The barcode, invoice and receipt printer settings of a company (see DocumentSetting::GROUPS). Reading
 * is open to every signed-in user of the company, since the screens that print need the values; saving
 * needs the group's `.../update` menu permission. The superadmin names the company.
 */
class DocumentSettingController extends Controller
{
    /**
     * The settings page of a group (the web routes pass the group).
     */
    public function page(string $group): Response
    {
        abortUnlessMenuPermission(DocumentSetting::GROUPS[$group]['path']);

        return Inertia::render('documentsetting/index', ['group' => $group]);
    }

    public function show(Request $request, string $group): JsonResponse
    {
        if (! DocumentSetting::isGroup($group)) {
            abort(404);
        }

        $companyId = $this->requestCompanyId($request);

        if ($companyId === null) {
            return response()->json(['message' => 'Choose a company.'], 422);
        }

        return response()->json($this->payload($group, $companyId));
    }

    public function update(Request $request, string $group): JsonResponse
    {
        if (! DocumentSetting::isGroup($group)) {
            abort(404);
        }

        $this->authorizeMenuPermission(DocumentSetting::GROUPS[$group]['path'].'/update');

        $request->validate(['company_id' => Auth::user()?->hasRole('superadmin') ? 'required|integer|exists:companies,id' : 'nullable'] + DocumentSetting::rulesFor($group));

        $companyId = $this->requestCompanyId($request);

        if ($companyId === null) {
            return response()->json(['message' => 'Choose a company.'], 422);
        }

        DocumentSetting::saveFor($companyId, $group, $request->all());

        return response()->json(['message' => 'Successfully Saved'] + $this->payload($group, $companyId));
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(string $group, int $companyId): array
    {
        return [
            'group' => $group,
            'label' => DocumentSetting::GROUPS[$group]['label'],
            'company_id' => $companyId,
            'values' => DocumentSetting::valuesFor($companyId, $group),
            'fields' => DocumentSetting::fieldsFor($group),
        ];
    }

    private function requestCompanyId(Request $request): ?int
    {
        $user = Auth::user();

        if ($user?->hasRole('superadmin')) {
            return $request->filled('company_id') ? $request->integer('company_id') : null;
        }

        return $user?->company_id ? (int) $user->company_id : null;
    }
}
