<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\InvoiceResource;
use App\Models\Transaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PublicApiInvoiceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $invoices = Transaction::query()
            ->visibleToCurrentUser()
            ->where('type', Transaction::TYPE_SELL)
            ->whereNotIn('status', ['draft', 'quotation'])
            ->with('contact:id,first_name,last_name,business_name')
            ->when($request->filled('search'), fn ($q) => $q->where('invoice_no', 'like', '%'.$request->search.'%'))
            ->orderByDesc('created_at')
            ->paginate(min((int) $request->integer('per_page', 25), 100));

        return response()->json(InvoiceResource::collection($invoices)->response()->getData(true));
    }

    public function show($id): JsonResponse
    {
        $invoice = Transaction::query()
            ->visibleToCurrentUser()
            ->where('type', Transaction::TYPE_SELL)
            ->with('contact:id,first_name,last_name,business_name')
            ->find($id);

        if ($invoice === null) {
            abort(404);
        }

        return response()->json(new InvoiceResource($invoice));
    }
}
