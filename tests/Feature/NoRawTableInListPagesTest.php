<?php

/**
 * A list page (resources/js/pages/<name>/index.vue) shows its rows with theTable (or ClientTable, which wraps it), never with a raw
 * <table>: the search, column menu, export, sorting, paging, row keyboard keys and empty state come from one component.
 *
 * A page that really needs its own markup is named below with the reason. Nothing may be added to this list without one; take pages
 * off it as they are converted (docs/design-audit.md lists the state of every page).
 */
$allowed = [
    'opening-balance/index.vue' => 'editable grid: every cell is an input with its own validation and a totals footer',
    'posoffline/index.vue' => 'offline sync queue: the action cell exists only on conflict rows (a td that appears per row)',
    'landing/index.vue' => 'public marketing page, not an application list',
];

test('list pages use theTable instead of a raw table', function () use ($allowed) {
    $offenders = [];

    foreach (glob(resource_path('js/pages/*/index.vue')) ?: [] as $file) {
        $relative = basename(dirname($file)).'/index.vue';
        $source = (string) file_get_contents($file);
        $template = str_contains($source, '<template') ? substr($source, (int) strpos($source, '<template')) : $source;

        if (preg_match('/<table\b/', $template) === 1 && ! array_key_exists($relative, $allowed)) {
            $offenders[] = $relative;
        }
    }

    expect($offenders)->toBe([], 'these list pages use a raw <table>; use theTable / ClientTable (or give the reason in this test)');
});

test('the allowed raw table pages still exist and still need it', function () use ($allowed) {
    foreach ($allowed as $relative => $reason) {
        $file = resource_path('js/pages/'.$relative);

        expect(file_exists($file))->toBeTrue("{$relative} no longer exists: remove it from the list");
        expect(str_contains((string) file_get_contents($file), '<table'))->toBeTrue("{$relative} no longer has a raw table ({$reason}): remove it from the list");
    }
});
