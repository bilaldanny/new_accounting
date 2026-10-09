<?php

use App\Models\BlogPost;
use Facebook\WebDriver\WebDriverBy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Dusk\Browser;

/**
 * The public blog and the superadmin page that feeds it, seen from a browser.
 */
test('the landing page and the blog list show published posts, and a post renders its markdown without running any script', function () {
    $this->world();
    BlogPost::query()->create(['title' => 'Dusk Published Post', 'slug' => 'dusk-published', 'body' => "Hello **markdown world**\n\n<script>document.title='hacked'</script>\n\n<img src=x onerror=\"document.title='hacked'\">", 'status' => 'published', 'published_at' => now()->subHour()]);
    BlogPost::query()->create(['title' => 'Dusk Draft Post', 'slug' => 'dusk-draft', 'body' => 'Not public', 'status' => 'draft']);

    $this->browse(function (Browser $browser) {
        $browser->logout()->visit('/')->waitFor('#blog', 30)->assertSee('Latest from the blog')->assertSee('Dusk Published Post')->assertDontSee('Dusk Draft Post');
        $this->assertNoBrowserErrors($browser);

        $browser->visit('/blog')->waitForText('Dusk Published Post', 30)->assertDontSee('Dusk Draft Post');
        $browser->clickLink('Dusk Published Post')->waitForText('markdown world', 30);

        expect($browser->driver->findElement(WebDriverBy::cssSelector('.lp-prose strong'))->getText())->toBe('markdown world')
            ->and($browser->driver->executeScript('return document.title;'))->not->toBe('hacked')
            ->and($browser->driver->executeScript('return document.querySelectorAll(".lp-prose script, .lp-prose img").length;'))->toBe(0);

        $this->assertNoBrowserErrors($browser);

        $browser->visit('/blog/dusk-draft')->assertSee('404');
    });
});

test('the landing page has no blog section while nothing is published', function () {
    $this->world();
    BlogPost::query()->delete();

    $this->browse(function (Browser $browser) {
        $browser->logout()->visit('/')->waitFor('#pricing', 30)->assertMissing('#blog');
    });
});

test('the superadmin creates a blog post with a cover from the admin page, and publishing makes it public', function () {
    $this->world();
    $cover = sys_get_temp_dir().DIRECTORY_SEPARATOR.'dusk-cover.png';
    file_put_contents($cover, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='));
    $title = 'Dusk Admin Post '.uniqid();

    $this->browse(function (Browser $browser) use ($title, $cover) {
        $this->signIn($browser, $this->superadmin())->visit('/blogposts/add')->waitFor('form textarea', 30)->pause(1500);
        duskTypeInto($browser, duskTextInputs(), 0, $title);
        duskTypeInto($browser, 'form textarea', 1, "A paragraph written by the superadmin.\n\n- one\n- two");
        $browser->attach('form input[type=file]', $cover);
        duskClickText($browser, 'Save & Close');
        $browser->waitForLocation('/blogposts', 30)->waitForText($title, 20);
        $this->assertNoBrowserErrors($browser);
    });

    $post = BlogPost::query()->where('title', $title)->firstOrFail();
    expect($post->status)->toBe('draft')->and($post->cover_image)->not->toBeNull()->and(Storage::disk('public')->exists((string) $post->cover_image))->toBeTrue();

    $this->browse(function (Browser $browser) use ($post) {
        $browser->visit('/blog/'.$post->slug)->assertSee('404');
    });

    $post->update(['status' => 'published', 'published_at' => now()->subMinute()]);

    $this->browse(function (Browser $browser) use ($post, $title) {
        $browser->logout()->visit('/blog/'.$post->slug)->waitForText('A paragraph written by the superadmin.', 30)->assertSee($title);
        expect($browser->driver->executeScript('return document.querySelectorAll(".lp-prose li").length;'))->toBe(2);
        $this->assertNoBrowserErrors($browser);
    });

    Storage::disk('public')->delete((string) $post->cover_image);
    DB::table('blog_posts')->where('id', $post->id)->delete();
    @unlink($cover);
});

test('a company admin cannot open the blog admin page', function () {
    $this->world();

    $this->browse(function (Browser $browser) {
        $this->signIn($browser, $this->adminUser())->visit('/blogposts')->assertSee('403');
    });
});
