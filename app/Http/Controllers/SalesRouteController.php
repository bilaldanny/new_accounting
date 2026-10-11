<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ScopesToCompany;
use App\Models\Contact;
use App\Models\Rider;
use App\Models\SalesRoute;
use App\Models\SalesRouteStop;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Van sales and route planning: routes (a weekday, a salesperson, a rider and the van, which is a warehouse) with their customer
 * stops in order, the day's visit sheet, and visits marked visited / skipped / sold (a sold visit points at the sale made, which is an
 * ordinary sale taken from the van's warehouse). Routes plan the work; nothing here posts.
 */
class SalesRouteController extends Controller
{
    use ScopesToCompany;

    public function index(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/salesroute');

        return response()->json(['data' => SalesRoute::query()->visibleToCurrentUser()->with('stops')
            ->when($request->filled('company_id'), fn ($q) => $q->where('company_id', $request->integer('company_id')))->orderBy('weekday')->orderBy('name')->get()]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/salesroute/add');

        $data = $this->validated($request);
        $companyId = $this->scopeCompanyId($request);
        $this->assertCustomers($companyId, array_column($data['stops'] ?? [], 'contact_id'));
        $this->assertReferences($companyId, $data);

        $route = DB::transaction(function () use ($data, $request, $companyId): SalesRoute {
            $route = SalesRoute::query()->create(collect($data)->except(['stops', 'company_id', 'branch_id'])->all() + ['company_id' => $companyId, 'branch_id' => $this->scopeBranchId($request, $companyId)]);
            $this->syncStops($route, $data['stops'] ?? []);

            return $route;
        });

        return response()->json(['message' => 'Successfully Saved', 'data' => $route->load('stops')], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $this->authorizeMenuPermission('/salesroute/:id/edit');

        $route = SalesRoute::query()->visibleToCurrentUser()->findOrFail($id);
        $data = $this->validated($request);
        $this->assertCustomers((int) $route->company_id, array_column($data['stops'] ?? [], 'contact_id'));
        $this->assertReferences((int) $route->company_id, $data);

        DB::transaction(function () use ($route, $data): void {
            $route->update(collect($data)->except(['stops', 'company_id', 'branch_id'])->all());

            if (array_key_exists('stops', $data)) {
                $this->syncStops($route, $data['stops']);
            }
        });

        return response()->json(['message' => 'Successfully Saved', 'data' => $route->fresh('stops')]);
    }

    public function destroy(int $id): JsonResponse
    {
        $this->authorizeMenuPermission('/salesroute/delete');

        SalesRoute::query()->visibleToCurrentUser()->findOrFail($id)->delete();

        return response()->json(['message' => 'Successfully Deleted']);
    }

    /**
     * The visit sheet of a day: every active route of that weekday with its stops and the status of each visit.
     */
    public function sheet(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/salesroute');

        $date = $request->input('date', now()->toDateString());
        $weekday = (int) date('w', strtotime($date));

        $routes = SalesRoute::query()->visibleToCurrentUser()->where('is_active', true)
            ->where(fn ($q) => $q->where('weekday', $weekday)->orWhereNull('weekday'))
            ->when($request->filled('company_id'), fn ($q) => $q->where('company_id', $request->integer('company_id')))
            ->with('stops')->get();

        $visits = DB::table('sales_route_visits')->whereIn('sales_route_id', $routes->pluck('id'))->whereDate('visit_date', $date)->get()->groupBy('sales_route_id');
        $names = Contact::query()->whereIn('id', $routes->pluck('stops')->flatten()->pluck('contact_id'))->pluck('business_name', 'id');

        return response()->json(['date' => $date, 'data' => $routes->map(fn (SalesRoute $route): array => [
            'id' => $route->id, 'name' => $route->name, 'warehouse_id' => $route->warehouse_id, 'rider_id' => $route->rider_id,
            'stops' => $route->stops->map(function (SalesRouteStop $stop) use ($visits, $route, $names): array {
                $visit = ($visits[$route->id] ?? collect())->firstWhere('contact_id', $stop->contact_id);

                return [
                    'contact_id' => $stop->contact_id, 'customer' => $names[$stop->contact_id] ?? null, 'sequence' => $stop->sequence, 'note' => $stop->note,
                    'status' => $visit->status ?? 'planned', 'transaction_id' => $visit->transaction_id ?? null, 'visited_at' => $visit->visited_at ?? null,
                ];
            })->values(),
        ])->values()]);
    }

    public function visit(Request $request, int $id): JsonResponse
    {
        $this->authorizeMenuPermission('/salesroute/visit');

        $data = $request->validate([
            'contact_id' => 'required|integer', 'date' => 'nullable|date_format:Y-m-d', 'status' => 'required|in:visited,skipped,sold',
            'transaction_id' => 'nullable|integer', 'note' => 'nullable|string|max:255',
        ]);

        $route = SalesRoute::query()->visibleToCurrentUser()->findOrFail($id);

        if (! SalesRouteStop::query()->where('sales_route_id', $route->id)->where('contact_id', $data['contact_id'])->exists()) {
            throw ValidationException::withMessages(['contact_id' => ['This customer is not a stop of the route.']]);
        }

        if (isset($data['transaction_id']) && ! Transaction::query()->sells()->where('company_id', $route->company_id)->where('contact_id', $data['contact_id'])->whereKey($data['transaction_id'])->exists()) {
            throw ValidationException::withMessages(['transaction_id' => ['Choose a sale of this customer.']]);
        }

        if ($data['status'] === 'sold' && ! isset($data['transaction_id'])) {
            throw ValidationException::withMessages(['transaction_id' => ['Name the sale made on this visit.']]);
        }

        DB::table('sales_route_visits')->updateOrInsert(
            ['sales_route_id' => $route->id, 'contact_id' => $data['contact_id'], 'visit_date' => $data['date'] ?? now()->toDateString()],
            ['company_id' => $route->company_id, 'status' => $data['status'], 'transaction_id' => $data['transaction_id'] ?? null, 'note' => $data['note'] ?? null,
                'visited_at' => now(), 'user_id' => Auth::id(), 'created_at' => now(), 'updated_at' => now()],
        );

        return response()->json(['message' => 'Successfully Saved']);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'company_id' => 'nullable|integer',
            'branch_id' => 'nullable|integer',
            'name' => 'bail|required|string|min:2|max:120',
            'weekday' => 'nullable|integer|between:0,6',
            'salesperson_id' => 'nullable|integer',
            'rider_id' => 'nullable|integer',
            'warehouse_id' => 'nullable|integer',
            'is_active' => 'nullable|boolean',
            'stops' => 'nullable|array|max:200',
            'stops.*.contact_id' => 'required|integer',
            'stops.*.note' => 'nullable|string|max:255',
        ]);
    }

    /**
     * @param  list<int|string>  $contactIds
     */
    private function assertCustomers(int $companyId, array $contactIds): void
    {
        $found = Contact::query()->where('company_id', $companyId)->whereIn('user_type', ['customer', 'both'])->whereIn('id', $contactIds)->count();

        if ($found !== count(array_unique($contactIds))) {
            throw ValidationException::withMessages(['stops' => ['Every stop must be a customer of this company.']]);
        }
    }

    /**
     * The rider, van warehouse and salesperson of a route must belong to the route's company.
     *
     * @param  array<string, mixed>  $data
     */
    private function assertReferences(int $companyId, array $data): void
    {
        $checks = [
            'rider_id' => fn (int $id): bool => Rider::query()->where('company_id', $companyId)->whereKey($id)->exists(),
            'warehouse_id' => fn (int $id): bool => Warehouse::query()->where('company_id', $companyId)->whereKey($id)->exists(),
            'salesperson_id' => fn (int $id): bool => User::query()->where('company_id', $companyId)->whereKey($id)->exists(),
        ];

        foreach ($checks as $field => $exists) {
            if (! empty($data[$field]) && ! $exists((int) $data[$field])) {
                throw ValidationException::withMessages([$field => ['Choose a record of this company.']]);
            }
        }
    }

    /**
     * @param  list<array<string, mixed>>  $stops
     */
    private function syncStops(SalesRoute $route, array $stops): void
    {
        SalesRouteStop::query()->where('sales_route_id', $route->id)->delete();

        foreach (array_values($stops) as $index => $stop) {
            SalesRouteStop::query()->create(['sales_route_id' => $route->id, 'contact_id' => $stop['contact_id'], 'sequence' => $index + 1, 'note' => $stop['note'] ?? null]);
        }
    }
}
