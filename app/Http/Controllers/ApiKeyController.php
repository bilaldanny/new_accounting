<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Settings > API Keys: Sanctum personal access tokens a user creates to call the API from another program.
 *
 * A key acts as its owner: every request made with it goes through the same permission and company checks as
 * the owner's own, so a key can never do more than the person who made it. It is shown once when it is created
 * and only its hash is stored. Who sees what: a user their own keys, a company admin the keys of everyone in
 * the company, the superadmin every key. A key cannot be used to make another key (a leaked key must not be
 * able to keep itself alive), so keys are created from the signed-in web app.
 */
class ApiKeyController extends Controller
{
    /**
     * How many live keys one user may hold.
     */
    public const MAX_KEYS_PER_USER = 20;

    /**
     * The lifetimes a key may be created with, in days (null = never expires).
     *
     * @var list<int>
     */
    public const LIFETIMES = [30, 90, 180, 365];

    public function index(): JsonResponse
    {
        $this->authorizeMenuPermission('/apikeys');

        $keys = $this->visibleKeys()
            ->with('tokenable:id,first_name,last_name,email,company_id')
            ->orderByDesc('id')
            ->get()
            ->map(fn (PersonalAccessToken $key): array => $this->present($key));

        return response()->json(['data' => $keys, 'max_per_user' => self::MAX_KEYS_PER_USER, 'lifetimes' => self::LIFETIMES]);
    }

    /**
     * Creates a key for the signed-in user and returns the token once.
     */
    public function store(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/apikeys/add');

        if ($request->bearerToken() !== null) {
            return response()->json(['message' => 'An API key cannot create another key. Create keys from the web app.'], 403);
        }

        $data = $request->validate([
            'name' => 'required|string|min:2|max:100',
            'expires_in_days' => ['nullable', 'integer', Rule::in(self::LIFETIMES)],
        ]);

        /** @var User $user */
        $user = Auth::user();

        if ($user->tokens()->count() >= self::MAX_KEYS_PER_USER) {
            return response()->json(['message' => 'You already have '.self::MAX_KEYS_PER_USER.' API keys. Revoke one first.'], 422);
        }

        $expiresAt = isset($data['expires_in_days']) ? now()->addDays((int) $data['expires_in_days']) : null;
        $created = $user->createToken(trim($data['name']), ['*'], $expiresAt);

        return response()->json([
            'message' => 'API key created. Copy it now: it will not be shown again.',
            'token' => $created->plainTextToken,
            'key' => $this->present($created->accessToken->load('tokenable:id,first_name,last_name,email,company_id')),
        ]);
    }

    /**
     * Revokes a key: it stops working at once.
     */
    public function destroy(int $id): JsonResponse
    {
        $this->authorizeMenuPermission('/apikeys/delete');

        $key = $this->visibleKeys()->find($id);

        if ($key === null) {
            abort(404);
        }

        $key->delete();

        return response()->json(['message' => 'API key revoked']);
    }

    /**
     * The keys the signed-in user may see: their own, the company's for a company admin, all for a superadmin.
     *
     * @return Builder<PersonalAccessToken>
     */
    private function visibleKeys(): Builder
    {
        /** @var User $user */
        $user = Auth::user();
        $query = PersonalAccessToken::query()->where('tokenable_type', $user->getMorphClass());

        if ($user->hasRole('superadmin')) {
            return $query;
        }

        if ($user->hasRole('companyadmin') && $user->company_id) {
            return $query->whereIn('tokenable_id', User::query()->where('company_id', $user->company_id)->select('id'));
        }

        return $query->where('tokenable_id', $user->id);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(PersonalAccessToken $key): array
    {
        $owner = $key->tokenable;
        $expiresAt = $key->expires_at;

        return [
            'id' => $key->id,
            'name' => $key->name,
            'owner_id' => $owner?->id,
            'owner_name' => $owner === null ? null : trim($owner->first_name.' '.$owner->last_name),
            'owner_email' => $owner?->email,
            'is_mine' => (int) $owner?->id === (int) Auth::id(),
            'abilities' => $key->abilities,
            'created_at' => $key->created_at?->toDateTimeString(),
            'last_used_at' => $key->last_used_at?->toDateTimeString(),
            'expires_at' => $expiresAt?->toDateTimeString(),
            'is_expired' => $expiresAt !== null && $expiresAt->isPast(),
        ];
    }
}
