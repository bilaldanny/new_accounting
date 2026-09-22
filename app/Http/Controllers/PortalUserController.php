<?php

namespace App\Http\Controllers;

use App\Models\Contact;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Settings > Portal Users: the accounts customers and suppliers sign in with to see their own balance (see
 * PortalController). An admin creates one for a contact of their company; the password is generated, shown once
 * and can be reset the same way. Removing one stops it signing in. A portal user holds the `portal` role, which
 * has no permissions, and is confined to the portal by RestrictPortalUsers.
 */
class PortalUserController extends Controller
{
    /**
     * How many portal accounts one contact may have.
     */
    public const MAX_PER_CONTACT = 3;

    public function index(): JsonResponse
    {
        $this->authorizeMenuPermission('/portalusers');

        $users = $this->visibleUsers()
            ->with('contact:id,business_name,first_name,last_name,code,user_type')
            ->orderByDesc('id')
            ->get()
            ->map(fn (User $user): array => $this->present($user));

        return response()->json(['data' => $users]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/portalusers/add');

        $data = $request->validate([
            'contact_id' => 'required|integer',
            'email' => ['required', 'email:filter', 'max:255', Rule::unique('users', 'email')->whereNull('deleted_at')],
        ]);

        $contact = Contact::findVisibleContact((int) $data['contact_id']);

        if ($contact === null || ! in_array($contact->user_type, ['customer', 'supplier', 'both'], true) || ! $contact->active) {
            return response()->json(['message' => 'Choose an active customer or supplier.', 'errors' => ['contact_id' => ['Choose an active customer or supplier.']]], 422);
        }

        if (User::query()->where('contact_id', $contact->id)->count() >= self::MAX_PER_CONTACT) {
            return response()->json(['message' => 'This contact already has '.self::MAX_PER_CONTACT.' portal accounts.'], 422);
        }

        $role = Role::query()->where('name', 'portal')->whereNull('company_id')->first();

        if ($role === null) {
            return response()->json(['message' => 'The portal role is missing. Run the migrations.'], 500);
        }

        $password = Str::password(12, symbols: false);
        $name = $this->contactName($contact);

        $user = User::query()->create([
            'company_id' => $contact->company_id,
            'branch_id' => null,
            'role_id' => $role->id,
            'first_name' => Str::limit($name, 120, ''),
            'last_name' => 'Portal',
            'email' => $data['email'],
            'username' => $data['email'],
            'password' => Hash::make($password),
            'is_active' => true,
            'created_by' => Auth::id(),
        ]);
        $user->forceFill(['contact_id' => $contact->id, 'email_verified_at' => now()])->save();

        return response()->json([
            'message' => 'Portal user created. Give them the password now: it will not be shown again.',
            'password' => $password,
            'user' => $this->present($user->load('contact:id,business_name,first_name,last_name,code,user_type')),
        ]);
    }

    /**
     * Gives the portal user a new generated password (the old one stops working).
     */
    public function resetPassword(int $id): JsonResponse
    {
        $this->authorizeMenuPermission('/portalusers/add');

        $user = $this->visibleUsers()->find($id);

        if ($user === null) {
            abort(404);
        }

        $password = Str::password(12, symbols: false);
        $user->forceFill(['password' => Hash::make($password)])->save();
        $user->tokens()->delete();

        return response()->json(['message' => 'Password reset. Give it to them now: it will not be shown again.', 'password' => $password]);
    }

    /**
     * Removes a portal user: they can no longer sign in, and any API key they held stops working.
     */
    public function destroy(int $id): JsonResponse
    {
        $this->authorizeMenuPermission('/portalusers/delete');

        $user = $this->visibleUsers()->find($id);

        if ($user === null) {
            abort(404);
        }

        $user->tokens()->delete();
        $user->delete();

        return response()->json(['message' => 'Portal user removed']);
    }

    /**
     * The portal users the signed-in user may manage: only accounts tied to a contact, of their own company
     * (all of them for the superadmin).
     *
     * @return Builder<User>
     */
    private function visibleUsers(): Builder
    {
        $actor = Auth::user();
        $query = User::query()->whereNotNull('contact_id');

        if ($actor?->hasRole('superadmin')) {
            return $query;
        }

        return $query->where('company_id', $actor?->company_id ?: 0);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(User $user): array
    {
        return [
            'id' => $user->id,
            'email' => $user->email,
            'is_active' => (bool) $user->is_active,
            'contact_id' => $user->contact_id,
            'contact_name' => $user->contact === null ? null : $this->contactName($user->contact),
            'contact_code' => $user->contact?->code,
            'contact_type' => $user->contact?->user_type,
            'created_at' => $user->created_at?->toDateTimeString(),
        ];
    }

    private function contactName(Contact $contact): string
    {
        $business = trim((string) $contact->business_name);

        return $business !== '' ? $business : (trim($contact->first_name.' '.$contact->last_name) ?: 'Portal user');
    }
}
