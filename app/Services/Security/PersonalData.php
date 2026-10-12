<?php

namespace App\Services\Security;

use App\Models\Contact;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A person's personal data: export (everything held about them as one JSON document) and deletion by anonymisation. Records the
 * books depend on (invoices, payments, vouchers) are never deleted: the person's name, contact details and address are replaced
 * so they can no longer be identified, the record id stays. Every request is kept in `privacy_requests` (who, when, what).
 */
class PersonalData
{
    /**
     * @return array<string, mixed>
     */
    public function exportContact(Contact $contact, int $requestedBy): array
    {
        $documents = DB::table('transactions')->where('contact_id', $contact->id)->whereNull('deleted_at')
            ->orderBy('transaction_date')->get(['id', 'type', 'invoice_no', 'transaction_date', 'final_amount', 'status', 'payment_status']);
        $payments = DB::table('payments')->where('contact_id', $contact->id)->orderBy('paid_on')->get(['id', 'transaction_id', 'amount', 'method', 'paid_on']);

        $this->record((int) $contact->company_id, 'contact', (int) $contact->id, 'export', $requestedBy);

        return [
            'exported_at' => now()->toIso8601String(), 'subject' => 'contact',
            'profile' => collect($contact->only([
                'id', 'business_name', 'prefix', 'first_name', 'middle_name', 'last_name', 'email', 'mobile', 'alternate_no', 'landline', 'address',
                'address_line_2', 'street_name', 'building_number', 'zipcode', 'date_of_birth', 'user_type', 'code', 'ntn_number', 'strn_number', 'created_at',
            ]))->all(),
            'documents' => $documents, 'payments' => $payments,
        ];
    }

    /**
     * @throws ValidationException
     */
    public function anonymizeContact(Contact $contact, int $requestedBy, ?string $note): void
    {
        if (str_starts_with((string) $contact->business_name, 'Anonymised contact')) {
            throw ValidationException::withMessages(['contact_id' => ['This contact has already been anonymised.']]);
        }

        DB::transaction(function () use ($contact, $requestedBy, $note): void {
            DB::table('contacts')->where('id', $contact->id)->update([
                'business_name' => 'Anonymised contact #'.$contact->id, 'prefix' => null, 'first_name' => 'Anonymised', 'middle_name' => null, 'last_name' => '',
                'email' => null, 'mobile' => '0', 'alternate_no' => null, 'landline' => null, 'landmark' => null, 'address' => 'Removed',
                'address_line_2' => null, 'street_name' => null, 'building_number' => null, 'secondary_number' => null, 'zipcode' => null,
                'date_of_birth' => null, 'tags' => null, 'ntn_number' => '0', 'strn_number' => null, 'active' => 0, 'updated_at' => now(),
            ]);
            $this->record((int) $contact->company_id, 'contact', (int) $contact->id, 'anonymize', $requestedBy, $note);
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function exportUser(User $user, int $requestedBy): array
    {
        $this->record((int) $user->company_id, 'user', (int) $user->id, 'export', $requestedBy);

        return [
            'exported_at' => now()->toIso8601String(), 'subject' => 'user',
            'profile' => $user->only(['id', 'first_name', 'last_name', 'email', 'username', 'phone', 'created_at']),
            'sign_ins' => DB::table('login_events')->where('user_id', $user->id)->orderByDesc('id')->limit(500)->get(['event', 'ip', 'device', 'created_at']),
            'sessions' => DB::table('user_sessions')->where('user_id', $user->id)->get(['ip', 'device', 'last_activity', 'created_at']),
        ];
    }

    /**
     * @throws ValidationException
     */
    public function anonymizeUser(User $user, int $requestedBy, ?string $note): void
    {
        if ((int) $user->id === $requestedBy) {
            throw ValidationException::withMessages(['user_id' => ['You cannot anonymise your own account.']]);
        }

        if (str_starts_with((string) $user->username, 'deleted-')) {
            throw ValidationException::withMessages(['user_id' => ['This user has already been anonymised.']]);
        }

        DB::transaction(function () use ($user, $requestedBy, $note): void {
            DB::table('users')->where('id', $user->id)->update([
                'first_name' => 'Deleted', 'last_name' => 'user', 'email' => 'deleted-'.$user->id.'@anonymised.invalid', 'username' => 'deleted-'.$user->id,
                'phone' => null, 'user_image' => null, 'is_active' => 0, 'remember_token' => null, 'updated_at' => now(),
            ]);
            DB::table('user_sessions')->where('user_id', $user->id)->whereNull('revoked_at')->update(['revoked_at' => now()]);
            DB::table('login_events')->where('user_id', $user->id)->update(['identity' => null, 'ip' => null, 'user_agent' => null]);
            $this->record((int) $user->company_id, 'user', (int) $user->id, 'anonymize', $requestedBy, $note);
        });
    }

    private function record(int $companyId, string $type, int $subjectId, string $kind, int $requestedBy, ?string $note = null): void
    {
        DB::table('privacy_requests')->insert([
            'company_id' => $companyId, 'subject_type' => $type, 'subject_id' => $subjectId, 'kind' => $kind, 'status' => 'done',
            'requested_by' => $requestedBy ?: Auth::id(), 'note' => $note, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
