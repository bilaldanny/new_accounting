<?php

namespace App\Http\Controllers;

use App\Models\Contact;
use App\Models\ContactAttachment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Documents kept on a customer or supplier (their Documents tab). The contact is looked up through the same
 * company/branch scope as the customer and supplier pages, and an attachment is only ever found through its contact,
 * so another company's file cannot be listed, downloaded or deleted by changing an id.
 */
class ContactAttachmentController extends Controller
{
    public function index(int $id, string $kind): JsonResponse
    {
        $contact = $this->contact($kind, $id);
        $this->authorizeMenuPermission("/{$kind}/:id/view");

        return response()->json([
            'data' => ContactAttachment::query()
                ->with('uploader:id,first_name,last_name')
                ->where('company_id', $contact->company_id)
                ->where('contact_id', $contact->id)
                ->orderByDesc('id')
                ->get()
                ->map(fn (ContactAttachment $attachment): array => $attachment->present())
                ->all(),
            'max_kilobytes' => ContactAttachment::MAX_KILOBYTES,
        ]);
    }

    public function store(Request $request, int $id, string $kind): JsonResponse
    {
        $contact = $this->contact($kind, $id);
        $this->authorizeMenuPermission("/{$kind}/documents");

        $request->validate([
            'file' => 'bail|required|file|max:'.ContactAttachment::MAX_KILOBYTES,
            'description' => 'nullable|string|max:255',
        ]);

        $attachment = ContactAttachment::storeFor($contact, $request->file('file'), $request->input('description'), Auth::id());

        return response()->json(['message' => 'Document uploaded', 'data' => $attachment->present()], 201);
    }

    public function download(int $id, int $attachmentId, string $kind): StreamedResponse
    {
        $contact = $this->contact($kind, $id);
        $this->authorizeMenuPermission("/{$kind}/:id/view");
        $attachment = $this->attachment($contact, $attachmentId);

        abort_unless(Storage::disk(ContactAttachment::DISK)->exists($attachment->path), 404);

        return Storage::disk(ContactAttachment::DISK)->download($attachment->path, $attachment->file_name);
    }

    public function destroy(int $id, int $attachmentId, string $kind): JsonResponse
    {
        $contact = $this->contact($kind, $id);
        $this->authorizeMenuPermission("/{$kind}/documents");

        $this->attachment($contact, $attachmentId)->discard();

        return response()->json(['message' => 'Document deleted']);
    }

    private function contact(string $kind, int $id): Contact
    {
        $contact = $kind === 'supplier' ? Contact::findVisibleSupplier($id) : Contact::findVisibleCustomer($id);

        abort_if($contact === null, 404);

        return $contact;
    }

    private function attachment(Contact $contact, int $attachmentId): ContactAttachment
    {
        return ContactAttachment::query()
            ->where('company_id', $contact->company_id)
            ->where('contact_id', $contact->id)
            ->findOrFail($attachmentId);
    }
}
