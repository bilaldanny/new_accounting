<?php

namespace App\Http\Controllers;

use App\Concerns\PasswordValidationRules;
use App\Models\Contact;
use App\Services\ContactPortal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

/**
 * The read-only portal a customer or supplier sees after signing in with the account an admin made for them
 * (Settings > Portal Users): their own balance and recent documents, nothing else. It never takes a contact id
 * from the request: the contact is the one the signed-in user is tied to.
 */
class PortalController extends Controller
{
    use PasswordValidationRules;

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

    /**
     * Lets a signed-in portal account set its own password, in place of an admin resetting it for them.
     */
    public function changePassword(Request $request): JsonResponse
    {
        $request->validate([
            'current_password' => $this->currentPasswordRules(),
            'password' => $this->passwordRules(),
        ]);

        $user = Auth::user();
        $user->forceFill(['password' => Hash::make($request->string('password')->toString())])->save();

        return response()->json(['message' => 'Password changed.']);
    }
}
