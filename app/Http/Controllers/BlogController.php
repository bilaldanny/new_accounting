<?php

namespace App\Http\Controllers;

use App\Models\BlogPost;
use App\Support\PublicBranding;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The public blog: published posts only, a draft or a scheduled post answers 404 like a post that does not exist.
 */
class BlogController extends Controller
{
    public function index(): Response
    {
        $posts = BlogPost::query()->published()->orderByDesc('published_at')->paginate(9)
            ->through(fn (BlogPost $post): array => self::card($post));

        return Inertia::render('landing/BlogIndex', [
            'branding' => PublicBranding::get(),
            'posts' => $posts,
            'registrationEnabled' => (bool) config('landing.registration_enabled'),
        ]);
    }

    public function show(string $slug): Response
    {
        $post = BlogPost::query()->published()->where('slug', $slug)->firstOrFail();

        return Inertia::render('landing/BlogShow', [
            'branding' => PublicBranding::get(),
            'registrationEnabled' => (bool) config('landing.registration_enabled'),
            'post' => self::card($post) + [
                'html' => $post->renderedBody(),
                'meta_title' => $post->meta_title ?: $post->title,
                'meta_description' => $post->meta_description ?: $post->summary(),
                'author' => $post->author?->full_name,
            ],
        ]);
    }

    /**
     * The fields of a post a visitor sees in a list: no ids, no status, no author id.
     *
     * @return array{title: string, slug: string, excerpt: string, cover_url: string|null, published_at: string|null}
     */
    public static function card(BlogPost $post): array
    {
        return [
            'title' => $post->title,
            'slug' => $post->slug,
            'excerpt' => $post->summary(),
            'cover_url' => $post->coverUrl(),
            'published_at' => $post->published_at?->toIso8601String(),
        ];
    }
}
