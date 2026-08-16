<?php

namespace App\Http\Controllers;

use App\Models\Contact;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ContactController extends Controller
{
    public function fetchCustomers(Request $request): JsonResponse
    {
        $contacts = Contact::query()
            ->where('active', true)
            ->where(function ($query) {
                $query->where('user_type', 'customer')
                    ->orWhere('user_type', 'both');
            })
            ->when($request->filled('company_id'), fn ($query) => $query->where('company_id', $request->integer('company_id')))
            ->when($request->filled('branch_id'), fn ($query) => $query->where('branch_id', $request->integer('branch_id')))
            ->select('contacts.*', 'business_name as text')
            ->orderBy('business_name')
            ->get();

        return response()->json($contacts);
    }
}
