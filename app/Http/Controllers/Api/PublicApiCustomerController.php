<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\CustomerResource;
use App\Models\Contact;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PublicApiCustomerController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $customers = Contact::query()
            ->visibleToCurrentUser()
            ->customers()
            ->when($request->filled('search'), fn ($q) => $q->where('business_name', 'like', '%'.$request->search.'%'))
            ->orderBy('business_name')
            ->paginate(min((int) $request->integer('per_page', 25), 100));

        return response()->json(CustomerResource::collection($customers)->response()->getData(true));
    }

    public function show($id): JsonResponse
    {
        $customer = Contact::query()->visibleToCurrentUser()->customers()->find($id);

        if ($customer === null) {
            abort(404);
        }

        return response()->json(new CustomerResource($customer));
    }
}
