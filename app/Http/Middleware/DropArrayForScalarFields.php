<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Several of the app's controllers read these request values as single values (`"%{$search}%"`,
 * `where('company_id', $id)`, `paginate($showRecord)`, `Str::slug($name)`), so `?search[]=x`,
 * `?company_id[]=2` or a JSON `{"name": ["x"]}` used to end in a 500 from an array-to-string conversion deep
 * in the controller. An array under one of these keys is simply not a value: the key is dropped, so a list
 * answers as if it had not been sent and a form fails its ordinary `required` validation with a 422.
 * Every other key (`ids`, `purchaselines`, `product_id[]` ...) is left alone.
 */
class DropArrayForScalarFields
{
    /**
     * Request keys that are always a single value.
     *
     * @var list<string>
     */
    public const SINGLE_VALUE_KEYS = [
        'name', 'search', 'status', 'company_id', 'branch_id', 'contact_id', 'department_id', 'warehouse_id',
        'sort_by', 'sort_type', 'show_record', 'cur_page', 'page',
        'from_date', 'to_date', 'date', 'start_date', 'end_date',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        // the query string, plus the body of a write request (JSON or form)
        $bags = [$request->query];

        if (! $request->isMethod('GET')) {
            $bags[] = $request->isJson() ? $request->json() : $request->request;
        }

        foreach ($bags as $bag) {
            $values = $bag->all();

            foreach (self::SINGLE_VALUE_KEYS as $key) {
                if (isset($values[$key]) && is_array($values[$key])) {
                    $bag->remove($key);
                }
            }
        }

        return $next($request);
    }
}
