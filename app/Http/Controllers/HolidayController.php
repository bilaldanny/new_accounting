<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ScopesToCompany;
use App\Services\Hrm\PakistanHolidays;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * The company's holiday list (shown as dots on the header calendar). Reading needs the Holidays page, changing needs HR Settings.
 * Every row belongs to one company; the one-click import adds the public holidays of Pakistan for a year and never duplicates a
 * day that is already listed.
 */
class HolidayController extends Controller
{
    use ScopesToCompany;

    public function index(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/hrm/holidays');
        $companyId = $this->scopeCompanyId($request);
        $request->validate(['year' => 'nullable|integer|min:2000|max:2100']);

        $rows = DB::table('company_holidays')->where('company_id', $companyId)
            ->when($request->filled('year'), fn ($q) => $q->whereYear('holiday_date', (int) $request->query('year')))
            ->orderBy('holiday_date')->get(['id', 'holiday_date', 'name', 'kind']);

        return response()->json(['data' => $rows, 'islamic_years' => PakistanHolidays::islamicYears()]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/hrm/manage');
        $companyId = $this->scopeCompanyId($request);
        $data = $request->validate(['holiday_date' => 'required|date_format:Y-m-d', 'name' => 'required|string|min:2|max:120', 'company_id' => 'nullable|integer']);
        $name = trim(strip_tags($data['name']));

        abort_if(DB::table('company_holidays')->where('company_id', $companyId)->where('holiday_date', $data['holiday_date'])->where('name', $name)->exists(), 422, 'This holiday is already listed.');

        $id = DB::table('company_holidays')->insertGetId([
            'company_id' => $companyId, 'holiday_date' => $data['holiday_date'], 'name' => $name, 'kind' => 'company', 'created_by' => Auth::id(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        return response()->json(['message' => 'Successfully Saved', 'data' => ['id' => $id]], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $this->authorizeMenuPermission('/hrm/manage');
        $companyId = $this->scopeCompanyId($request);
        $data = $request->validate(['holiday_date' => 'required|date_format:Y-m-d', 'name' => 'required|string|min:2|max:120', 'company_id' => 'nullable|integer']);
        $name = trim(strip_tags($data['name']));

        abort_unless(DB::table('company_holidays')->where('company_id', $companyId)->where('id', $id)->exists(), 404);
        abort_if(DB::table('company_holidays')->where('company_id', $companyId)->where('holiday_date', $data['holiday_date'])->where('name', $name)->where('id', '!=', $id)->exists(), 422, 'This holiday is already listed.');

        DB::table('company_holidays')->where('id', $id)->update(['holiday_date' => $data['holiday_date'], 'name' => $name, 'updated_at' => now()]);

        return response()->json(['message' => 'Successfully Updated']);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->authorizeMenuPermission('/hrm/manage');
        $deleted = DB::table('company_holidays')->where('company_id', $this->scopeCompanyId($request))->where('id', $id)->delete();
        abort_if($deleted === 0, 404);

        return response()->json(['message' => 'Successfully Deleted']);
    }

    public function importPakistan(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/hrm/manage');
        $companyId = $this->scopeCompanyId($request);
        $data = $request->validate(['year' => 'required|integer|min:2000|max:2100', 'company_id' => 'nullable|integer']);
        $added = 0;

        foreach (PakistanHolidays::forYear((int) $data['year']) as ['date' => $date, 'name' => $name]) {
            if (DB::table('company_holidays')->where('company_id', $companyId)->where('holiday_date', $date)->where('name', $name)->exists()) {
                continue;
            }

            DB::table('company_holidays')->insert(['company_id' => $companyId, 'holiday_date' => $date, 'name' => $name, 'kind' => 'public', 'created_by' => Auth::id(), 'created_at' => now(), 'updated_at' => now()]);
            $added++;
        }

        return response()->json(['message' => "{$added} holidays added", 'data' => ['added' => $added, 'islamic_dates_listed' => in_array((int) $data['year'], PakistanHolidays::islamicYears(), true)]]);
    }
}
