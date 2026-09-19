<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\HandlesIndexAndBulkDelete;
use App\Models\BankIssuer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class BankIssuerController extends Controller
{
    use HandlesIndexAndBulkDelete;

    /**
     * @return array<string, mixed>
     */
    protected function bankIssuerFormRules(): array
    {
        return [
            'name' => 'bail|required|min:3|max:200',
        ];
    }

    public function index(Request $request): JsonResponse
    {
        $status = $request->status ?? 'all';
        $search = $request->search ?? '';

        $query = BankIssuer::query()
            ->visibleToCurrentUser()
            ->with('company:id,name')
            ->when($status !== 'all', function ($q) use ($status) {
                $q->where('is_active', $status);
            })
            ->when($search, function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%");
            })
            ->when($request->filled('company_id'), function ($q) use ($request) {
                $q->where('company_id', $request->company_id);
            });

        $bankIssuers = $this->paginateSorted($query, $request);

        $bankIssuers->getCollection()->transform(function (BankIssuer $bankIssuer) {
            $bankIssuer->company_name = $bankIssuer->company?->name;

            return $bankIssuer;
        });

        $trash_count = BankIssuer::onlyTrashed()->visibleToCurrentUser()->count();

        return response()->json(['data' => $bankIssuers, 'trash_count' => $trash_count]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/bankissuer/add');

        $request->validate($this->bankIssuerFormRules());

        DB::beginTransaction();
        try {
            BankIssuer::createBankIssuer($request);
            DB::commit();
        } catch (ValidationException $e) {
            DB::rollBack();
            throw $e;
        } catch (Throwable $e) {
            DB::rollBack();

            return response()->json(['errormessage' => $e->getMessage()], 500);
        }

        return response()->json(['message' => 'Successfully Saved']);
    }

    public function show($id): JsonResponse
    {
        $bankIssuer = BankIssuer::query()
            ->visibleToCurrentUser()
            ->with('company:id,name')
            ->find($id);

        if ($bankIssuer === null) {
            abort(404);
        }

        return response()->json($bankIssuer);
    }

    public function update(Request $request, $id): JsonResponse
    {
        $this->authorizeMenuPermission('/bankissuer/:id/edit');

        $request->validate($this->bankIssuerFormRules());

        DB::beginTransaction();
        try {
            BankIssuer::updateBankIssuer($request, $id);
            DB::commit();
        } catch (ValidationException $e) {
            DB::rollBack();
            throw $e;
        } catch (Throwable $e) {
            DB::rollBack();

            return response()->json(['errormessage' => $e->getMessage()], 500);
        }

        return response()->json(['message' => 'Successfully Saved']);
    }

    public function destroy($id): JsonResponse
    {
        if (deletepermission('/bankissuer/delete')) {
            BankIssuer::deleteBankIssuer($id);

            return response()->json(['message' => 'Successfully Deleted']);
        }

        return response()->json('406');
    }

    public function bulk_delete(Request $request): JsonResponse
    {
        return $this->guardedBulkAction('/bankissuer/delete', 'Successfully Deleted', function () use ($request) {
            BankIssuer::query()
                ->visibleToCurrentUser()
                ->whereIn('id', $request->all())
                ->delete();
        });
    }

    public function bulk_delete_per(Request $request): JsonResponse
    {
        return $this->guardedBulkAction('/bankissuer/delete', 'Successfully Deleted', function () use ($request) {
            BankIssuer::query()
                ->onlyTrashed()
                ->visibleToCurrentUser()
                ->whereIn('id', (array) $request->all())
                ->forceDelete();
        });
    }

    public function restore_records(Request $request): JsonResponse
    {
        if (deletepermission('/bankissuer/restore')) {
            DB::beginTransaction();
            try {
                BankIssuer::onlyTrashed()
                    ->visibleToCurrentUser()
                    ->whereIn('id', $request->all())
                    ->restore();
                DB::commit();

                return response()->json(['message' => 'Successfully Restored']);
            } catch (Throwable $e) {
                DB::rollBack();

                return response()->json(['errormessage' => $e->getMessage()], 500);
            }
        }

        return response()->json('406');
    }

    public function updatestatus(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/bankissuer/:id/edit');

        $bankIssuers = BankIssuer::query()
            ->visibleToCurrentUser()
            ->whereIn('id', $request->ids)
            ->get();

        if ($bankIssuers->isEmpty()) {
            return response()->json(['errormessage' => 'Something went wrong']);
        }

        DB::beginTransaction();
        try {
            foreach ($bankIssuers as $bankIssuer) {
                if (isset($request->status)) {
                    $bankIssuer->is_active = $request->status;
                } else {
                    $bankIssuer->is_active = ! $bankIssuer->is_active;
                }
                $bankIssuer->save();
            }
            DB::commit();
        } catch (Throwable $e) {
            DB::rollBack();

            return response()->json(['errormessage' => $e->getMessage()], 500);
        }

        return response()->json(['message' => 'Successfully Saved']);
    }

    public function trash(Request $request): JsonResponse
    {
        $status = $request->status ?? 'all';
        $search = $request->search ?? '';

        $query = BankIssuer::onlyTrashed()
            ->visibleToCurrentUser()
            ->with('company:id,name')
            ->when($status !== 'all', function ($q) use ($status) {
                $q->where('is_active', $status);
            })
            ->when($search, function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%");
            });

        $bankIssuers = $this->paginateSorted($query, $request);

        $bankIssuers->getCollection()->transform(function (BankIssuer $bankIssuer) {
            $bankIssuer->company_name = $bankIssuer->company?->name;

            return $bankIssuer;
        });

        return response()->json(['data' => $bankIssuers]);
    }

    public function fetch(Request $request): JsonResponse
    {
        $bankIssuers = BankIssuer::query()
            ->visibleToCurrentUser()
            ->where('is_active', true)
            ->select('bank_issuers.*')
            ->selectRaw('name as text')
            ->orderBy('name')
            ->get();

        return response()->json($bankIssuers);
    }
}
