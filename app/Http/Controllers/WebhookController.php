<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\HandlesIndexAndBulkDelete;
use App\Models\Webhook;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Throwable;

class WebhookController extends Controller
{
    use HandlesIndexAndBulkDelete;

    /**
     * @return array<string, mixed>
     */
    protected function webhookFormRules(): array
    {
        return [
            'url' => 'bail|required|url|max:500',
            'events' => 'bail|required|array|min:1',
            'events.*' => 'string|in:'.implode(',', Webhook::EVENTS),
            'is_active' => 'nullable|boolean',
        ];
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/webhooks');

        $query = Webhook::query()
            ->visibleToCurrentUser()
            ->when($request->status && $request->status !== 'all', fn ($q) => $q->where('is_active', $request->status));

        $webhooks = $this->paginateSorted($query, $request);
        $trash_count = Webhook::onlyTrashed()->visibleToCurrentUser()->count();

        return response()->json(['data' => $webhooks, 'trash_count' => $trash_count]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/webhooks/add');
        $request->validate($this->webhookFormRules());

        try {
            $webhook = Webhook::createWebhook($request);
        } catch (ValidationException $e) {
            throw $e;
        } catch (Throwable $e) {
            return response()->json(['errormessage' => $e->getMessage()], 500);
        }

        return response()->json([
            'message' => 'Webhook created. Copy the secret now: it will not be shown again.',
            'secret' => $webhook->secret,
            'data' => $webhook,
        ]);
    }

    public function show($id): JsonResponse
    {
        $webhook = Webhook::query()->visibleToCurrentUser()->find($id);

        if ($webhook === null) {
            abort(404);
        }

        return response()->json($webhook);
    }

    public function update(Request $request, $id): JsonResponse
    {
        $this->authorizeMenuPermission('/webhooks/:id/edit');
        $request->validate($this->webhookFormRules());

        try {
            Webhook::updateWebhook($request, $id);
        } catch (ValidationException $e) {
            throw $e;
        } catch (Throwable $e) {
            return response()->json(['errormessage' => $e->getMessage()], 500);
        }

        return response()->json(['message' => 'Successfully Saved']);
    }

    public function destroy($id): JsonResponse
    {
        if (deletepermission('/webhooks/delete')) {
            Webhook::deleteWebhook($id);

            return response()->json(['message' => 'Successfully Deleted']);
        }

        return response()->json('406');
    }

    public function bulk_delete(Request $request): JsonResponse
    {
        return $this->guardedBulkAction('/webhooks/delete', 'Successfully Deleted', function () use ($request) {
            Webhook::query()->visibleToCurrentUser()->whereIn('id', (array) $request->all())->delete();
        });
    }

    public function bulk_delete_per(Request $request): JsonResponse
    {
        return $this->guardedBulkAction('/webhooks/delete', 'Successfully Deleted', function () use ($request) {
            Webhook::onlyTrashed()->visibleToCurrentUser()->whereIn('id', (array) $request->all())->forceDelete();
        });
    }

    public function restore_records(Request $request): JsonResponse
    {
        return $this->guardedBulkAction('/webhooks/restore', 'Successfully Restored', function () use ($request) {
            Webhook::onlyTrashed()->visibleToCurrentUser()->whereIn('id', (array) $request->all())->restore();
        });
    }

    public function trash(Request $request): JsonResponse
    {
        $webhooks = $this->paginateSorted(Webhook::onlyTrashed()->visibleToCurrentUser(), $request);

        return response()->json(['data' => $webhooks]);
    }

    public function deliveries($id): JsonResponse
    {
        $webhook = Webhook::query()->visibleToCurrentUser()->find($id);

        if ($webhook === null) {
            abort(404);
        }

        $deliveries = $webhook->deliveries()->orderByDesc('attempted_at')->limit(50)->get();

        return response()->json(['data' => $deliveries]);
    }
}
