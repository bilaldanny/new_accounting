<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * A post on the public blog. Platform content written by a superadmin, so there is deliberately no company scoping here: the
 * public pages show published posts to everyone, and the admin pages are for the superadmin only.
 */
class BlogPost extends Model
{
    use SoftDeletes;

    public const STATUSES = ['draft', 'published'];

    /**
     * @var list<string>
     */
    public const SORTABLE = ['title', 'status', 'published_at', 'created_at'];

    protected $fillable = [
        'title',
        'slug',
        'excerpt',
        'body',
        'cover_image',
        'status',
        'published_at',
        'author_id',
        'meta_title',
        'meta_description',
    ];

    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    /**
     * Posts a visitor may read: published, and not scheduled for later.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', 'published')->whereNotNull('published_at')->where('published_at', '<=', now());
    }

    /**
     * The body as HTML. Raw HTML and unsafe links in the markdown are dropped, so nothing a writer pastes can run on a visitor's page.
     */
    public function renderedBody(): string
    {
        return (string) Str::markdown((string) $this->body, ['html_input' => 'strip', 'allow_unsafe_links' => false]);
    }

    /**
     * The excerpt, or the first words of the body when none was written.
     */
    public function summary(): string
    {
        $excerpt = trim((string) $this->excerpt);

        if ($excerpt !== '') {
            return $excerpt;
        }

        $plain = trim(preg_replace('/\s+/', ' ', strip_tags($this->renderedBody())) ?? '');

        return Str::limit($plain, 180);
    }

    public function coverUrl(): ?string
    {
        return $this->cover_image ? asset('storage/'.ltrim($this->cover_image, '/')) : null;
    }

    public static function createPost(object $request, ?int $authorId): self
    {
        $post = new self;
        self::fillFromRequest($post, $request);
        $post->author_id = $authorId;
        $post->slug = self::uniqueSlug((string) ($request->input('slug') ?: $request->input('title')));
        $post->cover_image = self::storeCover($request, null);
        $post->save();

        return $post;
    }

    public static function updatePost(object $request, int|string $id): self
    {
        $post = self::query()->findOrFail($id);
        self::fillFromRequest($post, $request);

        if ($request->filled('slug') && $request->input('slug') !== $post->slug) {
            $post->slug = self::uniqueSlug((string) $request->input('slug'), (int) $post->id);
        }

        $post->cover_image = self::storeCover($request, $post->cover_image);
        $post->save();

        return $post;
    }

    public static function deletePost(int|string $id): void
    {
        self::query()->find($id)?->delete();
    }

    /**
     * A slug from the given text that no post, trashed ones included, uses yet.
     */
    public static function uniqueSlug(string $text, ?int $exceptId = null): string
    {
        $base = Str::slug($text) ?: 'post';
        $slug = $base;
        $counter = 2;

        while (self::withTrashed()->where('slug', $slug)->when($exceptId !== null, fn ($query) => $query->where('id', '!=', $exceptId))->exists()) {
            $slug = $base.'-'.$counter++;
        }

        return $slug;
    }

    private static function fillFromRequest(self $post, object $request): void
    {
        $post->title = trim((string) $request->input('title'));
        $post->excerpt = $request->filled('excerpt') ? trim((string) $request->input('excerpt')) : null;
        $post->body = (string) $request->input('body');
        $post->meta_title = $request->filled('meta_title') ? trim((string) $request->input('meta_title')) : null;
        $post->meta_description = $request->filled('meta_description') ? trim((string) $request->input('meta_description')) : null;
        $post->status = in_array($request->input('status'), self::STATUSES, true) ? $request->input('status') : 'draft';

        if ($post->status === 'published') {
            $post->published_at = $request->filled('published_at') ? $request->date('published_at') : ($post->published_at ?? now());
        } else {
            $post->published_at = $request->filled('published_at') ? $request->date('published_at') : null;
        }
    }

    /**
     * Stores a newly uploaded cover (replacing and deleting the old file), removes it on request, or keeps what is there.
     */
    private static function storeCover(object $request, ?string $existing): ?string
    {
        $file = $request->file('cover_image');

        if ($file instanceof UploadedFile) {
            if ($existing !== null) {
                Storage::disk('public')->delete($existing);
            }

            return $file->store('blog', 'public');
        }

        if ($request->boolean('remove_cover') && $existing !== null) {
            Storage::disk('public')->delete($existing);

            return null;
        }

        return $existing;
    }
}
