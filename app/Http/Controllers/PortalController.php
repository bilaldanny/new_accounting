<?php

namespace App\Http\Controllers;

use App\Models\Contact;
use App\Services\ContactPortal;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

/**
 * The read-only portal a customer or supplier sees after signing in with the account an admin made for them
 * (Settings > Portal Users): their own balance and recent documents, nothing else. It never takes a contact id
 * from the request: the contact is the one the signed-in user is tied to.
 */
class PortalController extends Controller
{
    public function __construct(private readonly ContactPortal $portal) {}

    public function show(): JsonResponse
    {
        $user = Auth::user();
        $contact = $user?->contact_id === null
            ? null
            : Contact::query()->where('company_id', $user->company_id)->where('active', true)->find($user->contact_id);

        if ($contact === null) {
            return response()->json(['message' => 'There is no portal for this account.'], 403);
        }

        return response()->json($this->portal->summary($contact));
    }
}
