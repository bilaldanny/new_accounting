<?php

namespace App\Actions;

use App\Mail\UserCredentialsMail;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

class SendUserCredentials
{
    public function handle(User $user): void
    {
        $email = strtolower(trim((string) $user->email));

        if ($email === '') {
            throw ValidationException::withMessages([
                'email' => ['This user does not have an email address.'],
            ]);
        }

        $plainPassword = $this->generatePassword();
        $recipientName = trim((string) $user->full_name);
        $username = (string) $user->username;

        $user->loadMissing('company.companySetting');

        DB::transaction(function () use ($user, $email, $plainPassword, $recipientName, $username): void {
            $user->password = $plainPassword;
            $user->save();

            Mail::to($email)->send(new UserCredentialsMail(
                recipientName: $recipientName !== '' ? $recipientName : $email,
                username: $username,
                email: $email,
                plainPassword: $plainPassword,
                loginUrl: url('/login'),
                company: $user->company,
            ));
        });
    }

    private function generatePassword(): string
    {
        $lower = 'abcdefghijklmnopqrstuvwxyz';
        $upper = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $numbers = '0123456789';
        $special = '!@#$%^&*(),.?":{}|<>';
        $all = $lower.$upper.$numbers.$special;

        $pick = static fn (string $set): string => $set[random_int(0, strlen($set) - 1)];

        $characters = [
            $pick($lower),
            $pick($upper),
            $pick($numbers),
            $pick($special),
        ];

        for ($i = 0; $i < 10; $i++) {
            $characters[] = $pick($all);
        }

        shuffle($characters);

        return implode('', $characters);
    }
}
