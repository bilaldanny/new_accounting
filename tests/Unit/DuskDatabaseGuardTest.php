<?php

use Tests\DuskTestCase;

test('the browser tests refuse any database but sqlite on database/dusk.sqlite', function () {
    $dusk = dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'database'.DIRECTORY_SEPARATOR.'dusk.sqlite';

    DuskTestCase::assertDuskDatabase('sqlite', $dusk);

    expect(fn () => DuskTestCase::assertDuskDatabase('mysql', 'new_accounting'))->toThrow(RuntimeException::class, 'Refusing to run browser tests')
        ->and(fn () => DuskTestCase::assertDuskDatabase('mysql', $dusk))->toThrow(RuntimeException::class)
        ->and(fn () => DuskTestCase::assertDuskDatabase('sqlite', dirname($dusk).DIRECTORY_SEPARATOR.'database.sqlite'))->toThrow(RuntimeException::class)
        ->and(fn () => DuskTestCase::assertDuskDatabase('sqlite', ':memory:'))->toThrow(RuntimeException::class);
});

test('the browser tests refuse any APP_URL but the sqlite test server', function () {
    DuskTestCase::assertDuskAppUrl('http://127.0.0.1:'.DuskTestCase::PORT);
    DuskTestCase::assertDuskAppUrl('http://localhost:'.DuskTestCase::PORT.'/');

    foreach (['https://new_accounting.test', 'https://accounts.cutesydrama.com', 'http://127.0.0.1', 'http://127.0.0.1:8000', 'https://127.0.0.1:'.DuskTestCase::PORT, 'http://example.com:'.DuskTestCase::PORT, ''] as $url) {
        expect(fn () => DuskTestCase::assertDuskAppUrl($url))->toThrow(RuntimeException::class, 'Refusing to run browser tests');
    }
});
