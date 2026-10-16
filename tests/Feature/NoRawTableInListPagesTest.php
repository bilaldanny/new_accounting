<?php

/**
 * A list page (resources/js/pages/<name>/index.vue) shows its rows with theTable (or ClientTable, which wraps it), never with a raw
 * <table>: the search, column menu, export, sorting, paging, row keyboard keys and empty state come from one component.
 *
 * A page that really needs its own markup is named below with the reason. Nothing may be added to this list without one; take pages
 * off it as they are converted (docs/design-audit.md lists the state of every page).
 */
$allowed = [
    'analytics/index.vue' => 'report tabs with pivot style grids',
    'auditlog/index.vue' => 'expandable before / after rows',
    'cashflowforecast/index.vue' => 'forecast grid with a totals footer per week',
    'chart-of-account/index.vue' => 'indented account tree',
    'dashboardinsights/index.vue' => 'small ranked lists inside cards',
    'documentcenter/index.vue' => 'the print preview of a block design',
    'opening-balance/index.vue' => 'editable grid with a totals footer',
    'posoffline/index.vue' => 'offline queue with per-row sync state',
    'saasadmin/index.vue' => 'platform summary tables',
    'depreciation/index.vue' => 'preview table with a totals footer',
    'expenseclaim/index.vue' => 'claim lines inside a detail panel with a totals footer',
    'fixedasset/index.vue' => 'register with a totals footer',
    'foreigncurrency/index.vue' => 'headerless rate list inside a card',
    'landedcost/index.vue' => 'editable cost lines with a totals footer',
    'landing/index.vue' => 'public marketing page, not an application list',
    'posshift/index.vue' => 'shift report key / value tables',
    'saleskpi/index.vue' => 'KPI table with a totals footer',
    'salesroute/index.vue' => 'stops of a route inside a row',
    'security/index.vue' => 'headerless rule and request lists inside cards',
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
