<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards the bulk and duplicate actions every list screen has, whichever controller they belong to, by the
 * name of the controller action that handles the route:
 *
 * - `bulk_delete`, `bulk_delete_per` and `restore_records` receive the selected ids as the whole body (a JSON
 *   list, `[3, 5, 8]`), which the controllers hand to `whereIn('id', $request->all())`. Anything else in there
 *   (a nested array, text, a list too long for one query) used to end in a 500 from the database driver; it is
 *   a 422 now. An empty list stays a harmless no-op, an id that does not exist is still just skipped.
 * - `duplicate` receives `{ "id": 3 }`; a missing or non-numeric id is a 422. (An id that names no record is a
 *   404, answered by the controller.)
 *
 * A new controller with one of these actions is covered as soon as its route sits in the authenticated group,
 * with nothing to add to the controller.
 */
class ValidateBulkActionBody
{
    /**
     * The most ids one bulk request may carry: every action runs its ids through a few `whereIn` queries, and
     * the database refuses a statement with too many placeholders (32,766 on SQLite, 65,535 on MySQL).
     */
    public const MAX_IDS = 10000;

    /**
     * Controller actions whose whole body is a list of ids.
     *
     * @var list<string>
     */
    private const BULK_ACTIONS = ['bulk_delete', 'bulk_delete_per', 'restore_records'];

    public function handle(Request $request, Closure $next): Response
    {
        $action = $request->route()?->getActionMethod();

        if (in_array($action, self::BULK_ACTIONS, true)) {
            $this->assertIdList($request->all());
        } elseif ($action === 'duplicate') {
            $this->assertId($request->input('id'), 'id');
        }

        return $next($request);
    }

    /**
     * @param  array<array-key, mixed>  $ids
     */
    private function assertIdList(array $ids): void
    {
        if (count($ids) > self::MAX_IDS) {
            throw ValidationException::withMessages(['ids' => 'Send at most '.self::MAX_IDS.' ids at a time.']);
        }

        foreach ($ids as $id) {
            $this->assertId($id, 'ids');
        }
    }

    private function assertId(mixed $id, string $key): void
    {
        if (! self::isRecordId($id)) {
            throw ValidationException::withMessages([$key => $key === 'id' ? 'The id must be a positive whole number.' : 'Every id must be a positive whole number.']);
        }
    }

    /**
     * A positive whole number, as a JSON number or a string of digits (the ids a list screen holds are either).
     */
    public static function isRecordId(mixed $value): bool
    {
        if (is_int($value)) {
            return $value > 0;
        }

        return is_string($value) && ctype_digit($value) && strlen($value) <= 18 && (int) $value > 0;
    }
}
