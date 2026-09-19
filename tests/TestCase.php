<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Fortify\Features;

abstract class TestCase extends BaseTestCase
{
    /**
     * Some controllers (Country, Currency, ...) call set_time_limit(180) for their import endpoints.
     * In a test that would cap the whole run at 180 seconds from that point, so reset it every test.
     */
    protected function setUp(): void
    {
        parent::setUp();

        set_time_limit(0);
    }

    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }
}
