<?php

use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('the page shell does not load a stylesheet that does not exist or the demo chart script that throws on every page', function () {
    $html = $this->get('/login')->assertSuccessful()->getContent();

    expect($html)->not->toContain('css2?family=Roboto')->not->toContain('assets/js/index.js');
});

test('the default system logo is a file that ships with the app', function () {
    $default = (new Setting)->getAttribute('system_logo');

    expect(public_path($default))->toBeFile();
});
