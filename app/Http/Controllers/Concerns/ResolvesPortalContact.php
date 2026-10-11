<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Contact;
use Illuminate\Http\Request;

/**
 * The one way a portal endpoint finds whose data it may touch: the contact the signed-in user is tied to, in the
 * user's own company and still active. No contact id ever comes from the request, so changing an id in a URL can only
 * ask for a document that is then looked up under this contact and 404s when it is not theirs.
 */
trait ResolvesPortalContact
{
    /**
     * @param  'customer'|'supplier'|null  $kind  require the contact to be a customer (customer or both) or a supplier
     */
    protected function portalContact(Request $request, ?string $kind = null): Contact
    {
        $user = $request->user();

        abort_if($user === null || $user->contact_id === null, 403, 'There is no portal for this account.');

        $contact = Contact::query()
            ->where('company_id', $user->company_id)
            ->where('active', true)
            ->find($user->contact_id);

        abort_if($contact === null, 403, 'There is no portal for this account.');

        if ($kind !== null && ! in_array($contact->user_type, [$kind, 'both'], true)) {
            abort(403, 'This is not available for your account.');
        }

        return $contact;
    }
}
