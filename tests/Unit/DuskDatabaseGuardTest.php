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
