<?php

use App\Models\BlogPost;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function blPost(array $extra = []): BlogPost
{
    return BlogPost::query()->create($extra + [
        'title' => 'Hello World '.uniqid(), 'slug' => 'hello-'.uniqid(), 'body' => 'Some **bold** text.', 'status' => 'published', 'published_at' => now()->subHour(),
    ]);
}

function blPayload(array $extra = []): array
{
    return $extra + ['title' => 'A New Post', 'body' => '# Heading', 'status' => 'published'];
}

function blCompanyAdmin(): User
{
    $role = Role::query()->firstOrCreate(['name' => 'companyadmin', 'company_id' => null], ['is_active' => true]);
    grantMenuPermission((int) $role->id, '/blogposts');
    grantMenuPermission((int) $role->id, '/blogposts/add');
    grantMenuPermission((int) $role->id, '/blogposts/:id/edit');

    return createStaffUserForRole($role, ['email_verified_at' => now()]);
}

test('the public list shows published posts only, newest first, nine to a page', function () {
    foreach (range(1, 11) as $i) {
        blPost(['title' => "Post $i", 'slug' => "post-$i", 'published_at' => now()->subDays(20 - $i)]);
    }
    blPost(['title' => 'Draft post', 'slug' => 'draft-post', 'status' => 'draft', 'published_at' => null]);
    blPost(['title' => 'Scheduled post', 'slug' => 'scheduled-post', 'published_at' => now()->addDay()]);
    $trashed = blPost(['title' => 'Deleted post', 'slug' => 'deleted-post']);
    $trashed->delete();

    $this->get('/blog')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('landing/BlogIndex')
        ->has('posts.data', 9)
        ->where('posts.last_page', 2)
        ->where('posts.data.0.slug', 'post-11')
        ->missing('posts.data.0.id')
        ->missing('posts.data.0.status')
        ->missing('posts.data.0.author_id'));

    $this->get('/blog?page=2')->assertInertia(fn (Assert $page) => $page->has('posts.data', 2));
});

test('a published post opens by its slug and a draft, a scheduled or a deleted one is a 404', function () {
    blPost(['slug' => 'live-post', 'title' => 'Live Post']);
    blPost(['slug' => 'draft-post', 'status' => 'draft', 'published_at' => null]);
    blPost(['slug' => 'later-post', 'published_at' => now()->addDay()]);
    blPost(['slug' => 'gone-post'])->delete();

    $this->get('/blog/live-post')->assertOk()->assertInertia(fn (Assert $page) => $page->component('landing/BlogShow')->where('post.title', 'Live Post'));
    $this->get('/blog/draft-post')->assertNotFound();
    $this->get('/blog/later-post')->assertNotFound();
    $this->get('/blog/gone-post')->assertNotFound();
    $this->get('/blog/never-existed')->assertNotFound();
});

test('markdown is rendered with raw html and unsafe links stripped', function () {
    blPost(['slug' => 'xss', 'body' => "Hello **world**\n\n<script>alert(1)</script>\n\n<img src=x onerror=alert(2)>\n\n[click](javascript:alert(3))\n\n<div onclick=\"x()\">raw</div>"]);

    $html = $this->get('/blog/xss')->viewData('page')['props']['post']['html'];

    expect($html)->toContain('<strong>world</strong>')
        ->and(strtolower($html))->not->toContain('<script')->not->toContain('onerror')->not->toContain('javascript:')->not->toContain('<img')->not->toContain('onclick')->not->toContain('<div');
});

test('the landing page shows the latest three published posts and hides the section when there are none', function () {
    $this->get('/')->assertInertia(fn (Assert $page) => $page->has('posts', 0));

    foreach (range(1, 4) as $i) {
        blPost(['slug' => "p-$i", 'title' => "P$i", 'published_at' => now()->subDays(10 - $i)]);
    }
    blPost(['slug' => 'draft', 'status' => 'draft', 'published_at' => null]);

    $this->get('/')->assertInertia(fn (Assert $page) => $page->has('posts', 3)->where('posts.0.slug', 'p-4'));
    expect(file_get_contents(resource_path('js/pages/landing/Index.vue')))->toContain('v-if="posts.length > 0"');
});

test('a post has its own title, description and image for sharing', function () {
    blPost(['slug' => 'seo', 'title' => 'Plain Title', 'meta_title' => 'Custom Meta Title', 'meta_description' => 'Custom description', 'excerpt' => 'Excerpt text']);
    blPost(['slug' => 'seo-default', 'title' => 'Default Title', 'excerpt' => 'Default excerpt']);

    $this->get('/blog/seo')->assertInertia(fn (Assert $page) => $page->where('post.meta_title', 'Custom Meta Title')->where('post.meta_description', 'Custom description'));
    $this->get('/blog/seo-default')->assertInertia(fn (Assert $page) => $page->where('post.meta_title', 'Default Title')->where('post.meta_description', 'Default excerpt'));
});

test('the superadmin creates, edits, trashes and restores a post with a cover image', function () {
    Storage::fake('public');
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->post('/api/blog-posts', blPayload(['title' => 'My First Post', 'cover_image' => UploadedFile::fake()->image('cover.jpg', 800, 450)]))
        ->assertSuccessful()->assertJson(['message' => 'Successfully Saved']);

    $post = BlogPost::query()->where('title', 'My First Post')->firstOrFail();
    expect($post->slug)->toBe('my-first-post')->and($post->author_id)->toBe(1)->and($post->published_at)->not->toBeNull()->and($post->cover_image)->not->toBeNull();
    Storage::disk('public')->assertExists($post->cover_image);
    $oldCover = $post->cover_image;

    $this->post("/api/blog-posts/{$post->id}", ['_method' => 'PUT', 'title' => 'My First Post, edited', 'body' => 'New body', 'status' => 'draft', 'cover_image' => UploadedFile::fake()->image('new.png', 800, 450)])
        ->assertSuccessful();

    $post->refresh();
    expect($post->title)->toBe('My First Post, edited')->and($post->status)->toBe('draft')->and($post->slug)->toBe('my-first-post');
    Storage::disk('public')->assertMissing($oldCover);
    Storage::disk('public')->assertExists($post->cover_image);

    $this->deleteJson("/api/blog-posts/{$post->id}")->assertSuccessful();
    expect(BlogPost::onlyTrashed()->count())->toBe(1);
    $this->getJson('/api/blog-posts/trash')->assertOk()->assertJsonCount(1, 'data.data');

    $this->postJson('/api/blog-posts/restore_records', [$post->id])->assertSuccessful();
    expect(BlogPost::query()->count())->toBe(1);
    $this->getJson('/api/blog-posts')->assertOk()->assertJsonPath('data.data.0.title', 'My First Post, edited');
});

test('a company admin and any non-superadmin are refused, even with the menu ticked', function () {
    $admin = blCompanyAdmin();
    $post = blPost();
    Sanctum::actingAs($admin);

    $this->getJson('/api/blog-posts')->assertForbidden();
    $this->postJson('/api/blog-posts', blPayload())->assertForbidden();
    $this->putJson("/api/blog-posts/{$post->id}", blPayload())->assertForbidden();
    $this->deleteJson("/api/blog-posts/{$post->id}")->assertForbidden();
    $this->postJson('/api/blog-posts/bulk_delete', [$post->id])->assertForbidden();

    expect(BlogPost::query()->count())->toBe(1)->and(BlogPost::query()->first()->deleted_at)->toBeNull();
});

test('the admin pages are for the superadmin only', function () {
    $admin = blCompanyAdmin();

    foreach (['blogposts', 'blogposts/add', 'blogposts/1/edit'] as $page) {
        $this->actingAs($admin)->get('/'.$page)->assertForbidden();
        $this->actingAs(User::query()->findOrFail(1))->get('/'.$page)->assertOk();
    }
});

test('the blog menu is added for the superadmin and granted to no role', function () {
    $paths = DB::table('menus')->where('route_path', 'like', '/blogposts%')->pluck('route_path')->all();

    expect($paths)->toContain('/blogposts', '/blogposts/add', '/blogposts/:id/edit', '/blogposts/delete', '/blogposts/restore')
        ->and(DB::table('permissions')->whereIn('menu_id', DB::table('menus')->where('route_path', 'like', '/blogposts%')->pluck('id'))->count())->toBe(0);
});

test('slugs are unique, also against deleted posts, and an explicit slug must be free', function () {
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->post('/api/blog-posts', blPayload(['title' => 'Same Title']))->assertSuccessful();
    $this->post('/api/blog-posts', blPayload(['title' => 'Same Title']))->assertSuccessful();
    BlogPost::query()->where('slug', 'same-title-2')->first()->delete();
    $this->post('/api/blog-posts', blPayload(['title' => 'Same Title']))->assertSuccessful();

    expect(BlogPost::withTrashed()->orderBy('id')->pluck('slug')->all())->toBe(['same-title', 'same-title-2', 'same-title-3']);

    $this->postJson('/api/blog-posts', blPayload(['title' => 'Other', 'slug' => 'same-title']))->assertUnprocessable()->assertJsonValidationErrors('slug');
});

test('a cover must be a small jpg, png or webp image', function () {
    Storage::fake('public');
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->postJson('/api/blog-posts', blPayload(['cover_image' => UploadedFile::fake()->create('cover.pdf', 100, 'application/pdf')]))->assertUnprocessable()->assertJsonValidationErrors('cover_image');
    $this->postJson('/api/blog-posts', blPayload(['cover_image' => UploadedFile::fake()->image('huge.jpg')->size(3000)]))->assertUnprocessable()->assertJsonValidationErrors('cover_image');
    $this->postJson('/api/blog-posts', blPayload(['cover_image' => UploadedFile::fake()->create('script.svg', 10, 'image/svg+xml')]))->assertUnprocessable()->assertJsonValidationErrors('cover_image');

    expect(BlogPost::query()->count())->toBe(0);
});

test('a cover can be removed and a permanent delete removes the file', function () {
    Storage::fake('public');
    Sanctum::actingAs(User::query()->findOrFail(1));
    $this->post('/api/blog-posts', blPayload(['title' => 'With Cover', 'cover_image' => UploadedFile::fake()->image('c.jpg')]))->assertSuccessful();
    $post = BlogPost::query()->firstOrFail();
    $cover = $post->cover_image;

    $this->post("/api/blog-posts/{$post->id}", ['_method' => 'PUT', 'title' => 'With Cover', 'body' => 'x', 'status' => 'published', 'remove_cover' => true])->assertSuccessful();
    expect($post->refresh()->cover_image)->toBeNull();
    Storage::disk('public')->assertMissing($cover);

    $this->post('/api/blog-posts', blPayload(['title' => 'Second', 'cover_image' => UploadedFile::fake()->image('d.jpg')]))->assertSuccessful();
    $second = BlogPost::query()->where('title', 'Second')->firstOrFail();
    $secondCover = $second->cover_image;
    $second->delete();
    $this->postJson('/api/blog-posts/bulk_delete_per', [$second->id])->assertSuccessful();

    expect(BlogPost::withTrashed()->where('id', $second->id)->exists())->toBeFalse();
    Storage::disk('public')->assertMissing($secondCover);
});

test('blog posts are not company scoped: no company column and no scope but soft delete', function () {
    expect(Schema::hasColumn('blog_posts', 'company_id'))->toBeFalse()
        ->and(array_keys((new BlogPost)->getGlobalScopes()))->toBe([SoftDeletingScope::class]);
});
