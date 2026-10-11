<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Support\Base64Upload;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * A document kept on a customer or supplier. Stored on the private disk, listed and downloaded only through
 * ContactAttachmentController (company scoped), and counted in Company::storageUsedBytes().
 */
class ContactAttachment extends Model
{
    use Auditable;

    public const MAX_KILOBYTES = 5120;

    public const DISK = 'local';

    /**
     * Types besides the images and PDF Base64Upload already trusts. The content type is what the server detects, never
     * what the client declares; a type listed here is accepted only when the file's extension is one of those named.
     *
     * @var array<string, list<string>>
     */
    private const EXTRA_TYPES = [
        'text/plain' => ['txt', 'csv'],
        'text/csv' => ['csv'],
        'application/msword' => ['doc'],
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => ['docx'],
        'application/vnd.ms-excel' => ['xls', 'csv'],
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => ['xlsx'],
        'application/zip' => ['docx', 'xlsx'],
    ];

    protected $fillable = [
        'company_id', 'branch_id', 'contact_id', 'file_name', 'path', 'ext', 'mime', 'size', 'description', 'uploaded_by',
    ];

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /**
     * Stores an uploaded file for the contact after checking its real type and the company's storage limit.
     *
     * @throws ValidationException
     */
    public static function storeFor(Contact $contact, UploadedFile $file, ?string $description, ?int $userId): self
    {
        $mime = (string) $file->getMimeType();
        $clientExtension = strtolower($file->getClientOriginalExtension());
        $extension = Base64Upload::extensionForMime($mime)
            ?? (in_array($clientExtension, self::EXTRA_TYPES[$mime] ?? [], true) ? $clientExtension : null);

        if ($extension === null) {
            throw ValidationException::withMessages([
                'file' => ['This file type is not allowed. Upload a PDF, image, Word, Excel, CSV or text file.'],
            ]);
        }

        $size = (int) $file->getSize();

        Company::query()->find($contact->company_id)?->assertCanAddStorageBytes($size);

        $path = $file->storeAs(
            'contact-documents/'.$contact->company_id.'/'.$contact->id,
            Str::ulid().'.'.$extension,
            self::DISK,
        );

        return self::query()->create([
            'company_id' => $contact->company_id,
            'branch_id' => $contact->branch_id,
            'contact_id' => $contact->id,
            'file_name' => mb_substr(basename($file->getClientOriginalName()), 0, 250),
            'path' => (string) $path,
            'ext' => $extension,
            'mime' => $mime,
            'size' => $size,
            'description' => filled($description) ? mb_substr((string) $description, 0, 255) : null,
            'uploaded_by' => $userId,
        ]);
    }

    /**
     * Removes the row and its file.
     */
    public function discard(): void
    {
        Storage::disk(self::DISK)->delete($this->path);
        $this->delete();
    }

    /**
     * @return array<string, mixed>
     */
    public function present(): array
    {
        return [
            'id' => $this->id,
            'file_name' => $this->file_name,
            'ext' => $this->ext,
            'size' => (int) $this->size,
            'description' => $this->description,
            'uploaded_by' => $this->uploader?->first_name ? trim($this->uploader->first_name.' '.$this->uploader->last_name) : null,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
