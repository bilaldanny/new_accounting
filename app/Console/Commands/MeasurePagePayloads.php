<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\System\PagePayloadSizes;
use Illuminate\Console\Command;

/**
 * Lists the pages that send the most data to the browser, for a given user (the pages and shared props depend on the role).
 * Run it as a company admin to see what a real session gets; the data in the database changes the list prop sizes.
 */
class MeasurePagePayloads extends Command
{
    /**
     * @var string
     */
    protected $signature = 'pages:payload-sizes {--user= : id of the user to measure as (default: first user of role 1)} {--top=15 : how many pages to list}';

    /**
     * @var string
     */
    protected $description = 'Measure the Inertia response size of every page and the props shared with all of them';

    public function handle(PagePayloadSizes $sizes): int
    {
        $user = $this->option('user') ? User::query()->find((int) $this->option('user')) : User::query()->where('role_id', 1)->orderBy('id')->first();

        if ($user === null) {
            $this->error('No such user.');

            return self::FAILURE;
        }

        $result = $sizes->measure($user);
        $kb = fn (int $bytes): string => number_format($bytes / 1024, 1).' KB';

        $this->info('Shared props (sent on every page):');
        $this->table(['Prop', 'Size'], collect($result['shared'])->map(fn (int $bytes, string $name): array => [$name, $kb($bytes)])->values()->all());
        $this->info('Largest pages ('.count($result['pages']).' measured, '.$result['skipped'].' skipped):');
        $this->table(['Page', 'Total', 'Shared', 'Own', 'Largest own prop'], collect($result['pages'])->take((int) $this->option('top'))
            ->map(fn (array $page): array => [$page['uri'], $kb($page['bytes']), $kb($page['shared_bytes']), $kb($page['own_bytes']), $page['largest_prop'].' '.$kb($page['largest_bytes'])])->all());

        return self::SUCCESS;
    }
}
