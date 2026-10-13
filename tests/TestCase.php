<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Fortify\Features;

abstract class TestCase extends BaseTestCase
{
    /**
     * Upload folders the application writes to under public/. Files a test adds there are removed again when the test ends, so a test
     * run leaves nothing behind in the working tree (they are also ignored by git).
     *
     * @var list<string>
     */
    private const UPLOAD_DIRECTORIES = ['images/company_images', 'images/product_image'];

    /** @var array<string, true> */
    private array $uploadsBefore = [];

    /**
     * Some controllers (Country, Currency, ...) call set_time_limit(180) for their import endpoints.
     * In a test that would cap the whole run at 180 seconds from that point, so reset it every test.
     */
    protected function setUp(): void
    {
        parent::setUp();

        set_time_limit(0);

        foreach (self::UPLOAD_DIRECTORIES as $directory) {
            foreach (glob(public_path($directory.'/*')) ?: [] as $file) {
                $this->uploadsBefore[$file] = true;
            }
        }
    }

    protected function tearDown(): void
    {
        foreach (self::UPLOAD_DIRECTORIES as $directory) {
            foreach (glob(public_path($directory.'/*')) ?: [] as $file) {
                if (! isset($this->uploadsBefore[$file]) && is_file($file)) {
                    @unlink($file);
                }
            }
        }

        parent::tearDown();
    }

    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }
}
