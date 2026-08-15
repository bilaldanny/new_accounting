<?php

namespace App\Actions\Fortify;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Fortify;

class AuthenticateUser
{
    /**
     * Validate credentials and return the user, or null on failure.
     *
     * @throws ValidationException
     */
    public function __invoke(Request $request): ?User
    {
        $login = trim((string) $request->input(Fortify::username(), ''));

        if ($login === '') {
            return null;
        }

        $user = User::query()
            ->where(function ($query) use ($login): void {
                $query->where('email', strtolower($login))
                    ->orWhere('username', User::normalizeUsername($login));
            })
            ->first();

        if ($user === null || ! Hash::check($request->password, $user->password)) {
            return null;
        }

        if (! $user->is_active) {
            throw ValidationException::withMessages([
                Fortify::username() => ['Your account is inactive. Please contact your administrator.'],
            ]);
        }

        return $user;
    }
}
