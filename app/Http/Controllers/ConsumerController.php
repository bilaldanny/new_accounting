<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\HandlesIndexAndBulkDelete;
use App\Models\Consumer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

class ConsumerController extends Controller
{
    use HandlesIndexAndBulkDelete;

    /**
     * @return array<string, mixed>
     */
    protected function consumerFormRules(): array
    {
        return [
            'branch_id' => 'bail|required',
            'name' => 'bail|required|min:3|max:200',
            'email' => 'nullable|email|max:200',
            'consumer_type' => ['nullable', Rule::in(Consumer::CONSUMER_TYPES)],
            'phone_res' => 'nullable|string|max:50',
            'phone_off' => 'nullable|string|max:50',
            'fax_no' => 'nullable|string|max:50',
            'ntn_no' => 'nullable|string|max:50',
            'cnic_no' => 'nullable|string|max:50',
            'sales_tax_no' => 'nullable|string|max:50',
        ];
    }

    public function index(Request $request): JsonResponse
    {
        $status = $request->status ?? 'all';
        $search = $request->search ?? '';

        $query = Consumer::query()
            ->visibleToCurrentUser()
            ->with(['company:id,name', 'branch:id,name', 'country:id,name'])
            ->when($status !== 'all', function ($q) use ($status) {
                $q->where('is_active', $status);
            })
            ->when($search, function ($q) use ($search) {
                $q->where(function ($sub) use ($search) {
                    $sub->whereAny(['name', 'contact_person', 'phone_res', 'phone_off', 'email'], 'like', "%{$search}%");
                });
            })
            ->when($request->filled('company_id'), function ($q) use ($request) {
                $q->where('company_id', $request->company_id);
            })
            ->when($request->filled('branch_id'), function ($q) use ($request) {
                $q->where('branch_id', $request->branch_id);
            });

        $consumers = $this->paginateSorted($query, $request);

        $consumers->getCollection()->transform(function (Consumer $consumer) {
            $consumer->company_name = $consumer->company?->name;
            $consumer->branch_name = $consumer->branch?->name;
            $consumer->country_name = $consumer->country?->name;

            return $consumer;
        });

        $trash_count = Consumer::onlyTrashed()->visibleToCurrentUser()->count();

        return response()->json(['data' => $consumers, 'trash_count' => $trash_count]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/consumer/add');

        $request->validate($this->consumerFormRules());

        DB::beginTransaction();
        try {
            Consumer::createConsumer($request);
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
        $consumer = Consumer::query()
            ->visibleToCurrentUser()
            ->with(['company:id,name', 'branch:id,name'])
            ->find($id);

        if ($consumer === null) {
            abort(404);
        }

        return response()->json($consumer);
    }

    public function update(Request $request, $id): JsonResponse
    {
        $this->authorizeMenuPermission('/consumer/:id/edit');

        $request->validate($this->consumerFormRules());

        DB::beginTransaction();
        try {
            Consumer::updateConsumer($request, $id);
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
        if (deletepermission('/consumer/delete')) {
            Consumer::deleteConsumer($id);

            return response()->json(['message' => 'Successfully Deleted']);
        }

        return response()->json('406');
    }

    public function bulk_delete(Request $request): JsonResponse
    {
        return $this->guardedBulkAction('/consumer/delete', 'Successfully Deleted', function () use ($request) {
            Consumer::query()
                ->visibleToCurrentUser()
                ->whereIn('id', $request->all())
                ->delete();
        });
    }

    public function bulk_delete_per(Request $request): JsonResponse
    {
        return $this->guardedBulkAction('/consumer/delete', 'Successfully Deleted', function () use ($request) {
            Consumer::query()
                ->onlyTrashed()
                ->visibleToCurrentUser()
                ->whereIn('id', (array) $request->all())
                ->forceDelete();
        });
    }

    public function restore_records(Request $request): JsonResponse
    {
        if (deletepermission('/consumer/restore')) {
            DB::beginTransaction();
            try {
                Consumer::onlyTrashed()
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
        $this->authorizeMenuPermission('/consumer/:id/edit');

        $consumers = Consumer::query()
            ->visibleToCurrentUser()
            ->whereIn('id', (array) $request->ids)
            ->get();

        if ($consumers->isEmpty()) {
            return response()->json(['errormessage' => 'Something went wrong']);
        }

        DB::beginTransaction();
        try {
            foreach ($consumers as $consumer) {
                if (isset($request->status)) {
                    $consumer->is_active = $request->status;
                } else {
                    $consumer->is_active = ! $consumer->is_active;
                }
                $consumer->save();
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

        $query = Consumer::onlyTrashed()
            ->visibleToCurrentUser()
            ->with(['company:id,name', 'branch:id,name'])
            ->when($status !== 'all', function ($q) use ($status) {
                $q->where('is_active', $status);
            })
            ->when($search, function ($q) use ($search) {
                $q->where(function ($sub) use ($search) {
                    $sub->whereAny(['name', 'contact_person', 'phone_res', 'phone_off', 'email'], 'like', "%{$search}%");
                });
            });

        $consumers = $this->paginateSorted($query, $request);

        $consumers->getCollection()->transform(function (Consumer $consumer) {
            $consumer->company_name = $consumer->company?->name;
            $consumer->branch_name = $consumer->branch?->name;

            return $consumer;
        });

        return response()->json(['data' => $consumers]);
    }

    public function fetch(Request $request): JsonResponse
    {
        $consumers = Consumer::query()
            ->visibleToCurrentUser()
            ->where('is_active', true)
            ->when($request->filled('branch_id'), function ($q) use ($request) {
                $q->where('branch_id', $request->branch_id);
            })
            ->select('consumers.*')
            ->selectRaw('name as text')
            ->orderBy('name')
            ->get();

        return response()->json($consumers);
    }
}
