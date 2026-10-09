<?php

namespace App\Http\Controllers;

use App\Models\CostCenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Cost centers and profit centers: the master list, and the plain lookup the voucher forms read.
 */
class CostCenterController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/costcenter');

        $centers = CostCenter::query()
            ->visibleToCurrentUser()
            ->with(['parent:id,name', 'branch:id,name', 'department:id,name'])
            ->when($request->filled('search'), fn ($q) => $q->where(fn ($w) => $w->where('name', 'like', '%'.$request->input('search').'%')->orWhere('code', 'like', '%'.$request->input('search').'%')))
            ->when($request->filled('company_id'), fn ($q) => $q->where('company_id', $request->input('company_id')))
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->input('type')))
            ->orderBy('code')
            ->paginate(min((int) ($request->input('show_record') ?: 25), 200));

        $centers->getCollection()->transform(fn (CostCenter $center) => $center->present());

        return response()->json(['data' => $centers]);
    }

    /**
     * The active centers of a company for the picker on a voucher line. Needs no menu permission, like the account lists.
     */
    public function fetch(Request $request): JsonResponse
    {
        if (! $request->filled('company_id')) {
            return response()->json([]);
        }

        $centers = CostCenter::query()
            ->visibleToCurrentUser()
            ->where('company_id', $request->integer('company_id'))
            ->where('active', true)
            ->orderBy('code')
            ->get()
            ->map(fn (CostCenter $center) => ['id' => $center->id, 'code' => $center->code, 'name' => $center->name, 'text' => $center->code.' - '.$center->name, 'type' => $center->type, 'branch_id' => $center->branch_id]);

        return response()->json($centers);
    }

    public function show(int $id): JsonResponse
    {
        $this->authorizeMenuPermission('/costcenter');

        return response()->json(['data' => CostCenter::query()->visibleToCurrentUser()->with(['parent:id,name', 'branch:id,name', 'department:id,name'])->findOrFail($id)->present()]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/costcenter/add');

        $user = $request->user();
        $companyId = (int) ($user?->company_id ?: $request->integer('company_id'));

        if ($companyId === 0) {
            throw ValidationException::withMessages(['company_id' => ['Choose a company.']]);
        }

        $center = CostCenter::query()->create($this->validated($request, $companyId) + ['company_id' => $companyId, 'created_by' => $user?->id]);

        return response()->json(['message' => 'Cost center saved', 'data' => $center->load(['parent:id,name', 'branch:id,name', 'department:id,name'])->present()]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $this->authorizeMenuPermission('/costcenter/:id/edit');

        $center = CostCenter::query()->visibleToCurrentUser()->findOrFail($id);
        $data = $this->validated($request, (int) $center->company_id, $center);

        if (! empty($data['parent_id']) && CostCenter::query()->findOrFail($data['parent_id'])->isInside($center->id)) {
            throw ValidationException::withMessages(['parent_id' => ['A cost center cannot sit under itself or one of its own children.']]);
        }

        $center->update($data);

        return response()->json(['message' => 'Cost center saved', 'data' => $center->refresh()->load(['parent:id,name', 'branch:id,name', 'department:id,name'])->present()]);
    }

    public function destroy(int $id): JsonResponse
    {
        $this->authorizeMenuPermission('/costcenter/delete');

        $center = CostCenter::query()->visibleToCurrentUser()->findOrFail($id);

        if ($center->isUsed()) {
            throw ValidationException::withMessages(['cost_center' => ['This cost center has postings or assets and cannot be deleted. Mark it inactive instead.']]);
        }

        if ($center->children()->exists()) {
            throw ValidationException::withMessages(['cost_center' => ['This cost center has sub-centers. Move or delete them first.']]);
        }

        $center->delete();

        return response()->json(['message' => 'Cost center deleted']);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, int $companyId, ?CostCenter $current = null): array
    {
        return $request->validate([
            'code' => ['required', 'string', 'max:40', Rule::unique('cost_centers', 'code')->where('company_id', $companyId)->ignore($current?->id)],
            'name' => 'required|string|max:150',
            'type' => ['required', Rule::in(CostCenter::TYPES)],
            'parent_id' => ['nullable', 'integer', Rule::exists('cost_centers', 'id')->where('company_id', $companyId)->whereNull('deleted_at')],
            'branch_id' => ['nullable', 'integer', Rule::exists('branches', 'id')->where('company_id', $companyId)],
            'department_id' => ['nullable', 'integer', Rule::exists('departments', 'id')->where('company_id', $companyId)->whereNull('deleted_at')],
            'active' => 'nullable|boolean',
        ]) + ['active' => true];
    }
}
