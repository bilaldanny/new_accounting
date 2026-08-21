<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Company;
use App\Models\CompanySetting;
use App\Models\Role;
use App\Models\User;
use App\Support\ImportResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class CompanyController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $sort_by = $request->sort_by ?? 'created_at';
        $sort_type = $request->sort_type ?? 'desc';
        $show_record = $request->show_record ?? 10;
        $status = $request->status ?? 'all';
        $search = $request->search ?? '';
        $cur_page = $request->cur_page ?? 1;

        $query = Company::query()
            ->withCount(['branches', 'users'])
            ->when($status !== 'all', function ($q) use ($status) {
                $q->where('is_active', $status);
            })
            ->when($status === 'all', function ($q) {
                $q->whereIn('is_active', [0, 1]);
            })
            ->when($search, function ($q) use ($search) {
                $q->where(function ($sub) use ($search) {
                    $sub->whereAny(['name', 'email', 'phone', 'ntn_no'], 'like', "%{$search}%")
                        ->orWhereHas('users', function ($userQuery) use ($search) {
                            $userQuery->where(function ($adminQuery) use ($search) {
                                $adminQuery->where('email', 'like', "%{$search}%")
                                    ->orWhere('phone', 'like', "%{$search}%")
                                    ->orWhere('username', 'like', "%{$search}%")
                                    ->orWhereRaw("CONCAT(first_name, ' ', last_name) like ?", ["%{$search}%"]);
                            });
                        });
                });
            })
            ->orderBy($sort_by, $sort_type);

        Paginator::currentPageResolver(function () use ($cur_page) {
            return $cur_page;
        });

        $companies = $query->paginate($show_record);

        if ($cur_page > $companies->lastPage()) {
            Paginator::currentPageResolver(function () use ($companies) {
                return $companies->lastPage();
            });
            $companies = $query->paginate($show_record);
        }

        Company::enrichIndexCollection($companies);

        $trash_count = Company::onlyTrashed()->count();

        return response()->json(['data' => $companies, 'trash_count' => $trash_count]);
    }

    public function generateCode(Request $request): JsonResponse
    {
        return response()->json([
            'code' => Company::nextCode(),
        ]);
    }

    public function checkCode(Request $request): JsonResponse
    {
        $request->validate([
            'code' => 'required|string',
            'except_id' => 'nullable|integer',
        ]);

        return response()->json([
            'code_taken' => Company::codeExists(
                $request->string('code')->toString(),
                $request->integer('except_id') ?: null,
            ),
        ]);
    }

    public function checkAdminIdentity(Request $request): JsonResponse
    {
        $request->validate([
            'email' => 'nullable|email',
            'username' => 'nullable|string',
            'except_user_id' => 'nullable|integer',
        ]);

        $exceptUserId = $request->integer('except_user_id') ?: null;
        $response = [];

        if ($request->filled('email')) {
            $response['email_taken'] = User::emailExists(
                $request->string('email')->toString(),
                $exceptUserId,
            );
        }

        if ($request->filled('username')) {
            $response['username_taken'] = User::usernameExists(
                $request->string('username')->toString(),
                $exceptUserId,
            );
        }

        return response()->json($response);
    }

    public function store(Request $request): JsonResponse
    {
        $request->merge([
            'code' => Company::resolveCode($request->input('code'), (string) $request->input('name', 'COMP')),
            'admin_username' => User::normalizeUsername((string) $request->input('admin_username', '')),
        ]);

        $request->validate([
            'code' => 'bail|required|regex:/^CO-\d{5}$/|unique:companies,code',
            'name' => 'bail|required|min:3|max:200',
            'password' => [
                'bail',
                'required',
                'confirmed',
                'regex:/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[!@#$%^&*(),.?":{}|<>]).{8,}$/',
            ],
            'admin_name' => 'bail|required',
            'admin_username' => 'bail|required|unique:users,username',
            'admin_email' => 'bail|required|email|unique:users,email',
            'admin_phone' => 'nullable|numeric',
            'max_users' => 'bail|required|numeric',
            'max_branches' => 'bail|required|numeric',
            'logo' => 'bail|required|string',
            'email' => 'nullable|email',
            'phone' => 'nullable|numeric',
        ]);

        DB::beginTransaction();
        try {
            $company = Company::createCompany($request);
            CompanySetting::createCompanySettings($company->id, $request->string('name')->toString());
            $branch = Branch::createCompanyBranch($company->id);
            // $role = Role::createCompanyRole($company->id);
            $role = Role::find(2);
            User::createCompanyAdmin($request, $role->id, $company->id, $branch->id);
            DB::commit();
        } catch (ValidationException $e) {
            DB::rollBack();
            throw $e;
        } catch (Throwable $e) {
            DB::rollBack();

            return response()->json(['errormessage' => $e]);
        }

        return response()->json(['message' => 'Successfully Saved']);
    }

    public function show($id): JsonResponse
    {
        $company = Company::find($id);

        if ($company === null) {
            return response()->json(null, 404);
        }

        $user = User::query()
            ->where('company_id', $company->id)
            ->orderBy('created_at')
            ->first();

        if ($user !== null) {
            $company->user_id = $user->id;
            $company->admin_email = $user->email;
            $company->admin_username = $user->username;
            $company->admin_name = trim($user->first_name.' '.$user->last_name);
            $company->admin_phone = $user->phone;
        }

        $company->logo_url = Company::logoUrl($company->logo);

        return response()->json($company);
    }

    public function update(Request $request, $id): JsonResponse
    {
        $company = Company::findOrFail($id);
        $adminUserId = User::query()
            ->where('company_id', $company->id)
            ->orderBy('created_at')
            ->value('id');

        $request->merge([
            'code' => Company::normalizeCode((string) $request->input('code', $company->code)),
            'admin_username' => User::normalizeUsername((string) $request->input('admin_username', '')),
        ]);

        $request->validate([
            'code' => 'bail|required|regex:/^CO-\d{5}$/|unique:companies,code,'.$id,
            'name' => 'bail|required|min:3|max:200',
            'admin_name' => 'bail|required',
            'admin_username' => 'bail|required|unique:users,username,'.($adminUserId ?? 'NULL'),
            'admin_email' => 'bail|required|email|unique:users,email,'.($adminUserId ?? 'NULL'),
            'admin_phone' => 'nullable|numeric',
            'max_users' => 'bail|required|numeric',
            'max_branches' => 'bail|required|numeric',
            'email' => 'nullable|email',
            'phone' => 'nullable|numeric',
        ]);

        DB::beginTransaction();
        try {
            Company::updateCompany($request, $id);
            User::updateCompanyAdmin($request, (int) $id);
            DB::commit();
        } catch (Throwable $e) {
            DB::rollBack();

            return response()->json(['errormessage' => $e]);
        }

        return response()->json(['message' => 'Successfully Saved']);
    }

    public function import(Request $request): JsonResponse
    {
        $request->validate([
            'rows' => 'required|array|min:1',
            'rows.*.name' => 'bail|required|string',
        ]);

        DB::beginTransaction();

        try {
            $created = 0;
            $updated = 0;

            foreach ($request->rows as $index => $row) {
                if (! is_array($row)) {
                    throw ValidationException::withMessages([
                        'rows' => ['Row '.($index + 1).' is invalid.'],
                    ]);
                }

                if (Company::upsertFromImport($row) === 'created') {
                    $created++;
                } else {
                    $updated++;
                }
            }

            DB::commit();
        } catch (ValidationException $e) {
            DB::rollBack();

            throw $e;
        } catch (Throwable $e) {
            DB::rollBack();

            return response()->json(['errormessage' => $e]);
        }

        return ImportResponse::success(
            count($request->rows),
            $created,
            $updated,
            'company records'
        );
    }

    public function destroy($id): JsonResponse
    {
        if (deletepermission()) {
            Company::deleteCompany($id);

            return response()->json(['message' => 'Successfully Deleted']);
        }

        return response()->json('406');
    }

    public function bulk_delete(Request $request): JsonResponse
    {
        if (deletepermission()) {
            DB::beginTransaction();
            try {
                Company::whereIn('id', $request->all())->delete();
                DB::commit();

                return response()->json(['message' => 'Successfully Deleted']);
            } catch (Throwable $e) {
                DB::rollBack();

                return response()->json(['errormessage' => $e]);
            }
        }

        return response()->json('406');
    }

    public function bulk_delete_per(Request $request): JsonResponse
    {
        if (deletepermission()) {
            DB::beginTransaction();
            try {
                $ids = (array) $request->all();
                Company::whereIn('id', $ids)->forceDelete();
                DB::commit();

                return response()->json(['message' => 'Successfully Deleted']);
            } catch (Throwable $e) {
                DB::rollBack();

                return response()->json(['errormessage' => $e]);
            }
        }

        return response()->json('406');
    }

    public function updatestatus(Request $request): JsonResponse
    {
        $companies = Company::whereIn('id', $request->ids)->get();

        if (isset($companies)) {
            DB::beginTransaction();
            try {
                foreach ($companies as $company) {
                    if (isset($request->status)) {
                        $company->is_active = $request->status;
                    } else {
                        if ($company->is_active == false) {
                            $company->is_active = 'true';
                        } else {
                            $company->is_active = 'false';
                        }
                    }
                    $company->save();
                }
                DB::commit();
            } catch (Throwable $e) {
                DB::rollBack();

                return response()->json(['errormessage' => $e]);
            }
        } else {
            return response()->json(['errormessage' => 'Something went wrong']);
        }

        return response()->json(['message' => 'Successfully Saved']);
    }

    public function restore_records(Request $request): JsonResponse
    {
        if (deletepermission()) {
            DB::beginTransaction();
            try {
                Company::whereIn('id', $request->all())->restore();
                DB::commit();

                return response()->json(['message' => 'Successfully Restored']);
            } catch (Throwable $e) {
                DB::rollBack();

                return response()->json(['errormessage' => $e]);
            }
        }

        return response()->json('406');
    }

    public function duplicate(Request $request): JsonResponse
    {
        DB::beginTransaction();
        try {
            $company = Company::find($request->id);
            $duplicator = $company->replicate();
            $duplicator->name = $company->name.' Copy';
            $duplicator->code = Company::nextCode();
            $duplicator->save();
            DB::commit();

            return response()->json(['message' => 'Successfully Duplicated']);
        } catch (Throwable $e) {
            DB::rollBack();

            return response()->json(['errormessage' => $e]);
        }
    }

    public function fetch(): JsonResponse
    {
        $companies = Company::query()
            ->where('is_active', true)
            ->select('companies.*')
            ->selectRaw('name as text')
            ->orderBy('name')
            ->get();

        return response()->json($companies);
    }

    public function trash(Request $request): JsonResponse
    {
        $sort_by = $request->sort_by ?? 'created_at';
        $sort_type = $request->sort_type ?? 'desc';
        $show_record = $request->show_record ?? 10;
        $status = $request->status ?? 'all';
        $search = $request->search ?? '';
        $cur_page = $request->cur_page ?? 1;

        $query = Company::onlyTrashed()
            ->when($status !== 'all', function ($q) use ($status) {
                $q->where('is_active', $status);
            })
            ->when($status === 'all', function ($q) {
                $q->whereIn('is_active', [0, 1]);
            })
            ->when($search, function ($q) use ($search) {
                $q->where(function ($sub) use ($search) {
                    $sub->whereAny(['name', 'email', 'phone', 'ntn_no'], 'like', "%{$search}%");
                });
            })
            ->orderBy($sort_by, $sort_type);

        Paginator::currentPageResolver(function () use ($cur_page) {
            return $cur_page;
        });

        $companies = $query->paginate($show_record);

        if ($cur_page > $companies->lastPage()) {
            Paginator::currentPageResolver(function () use ($companies) {
                return $companies->lastPage();
            });
            $companies = $query->paginate($show_record);
        }

        return response()->json(['data' => $companies]);
    }
}
