<?php

namespace App\Http\Controllers;

use App\Actions\SendUserCredentials;
use App\Http\Controllers\Concerns\HandlesBulkImport;
use App\Http\Controllers\Concerns\HandlesIndexAndBulkDelete;
use App\Models\Branch;
use App\Models\Company;
use App\Models\CompanySetting;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class CompanyController extends Controller
{
    use HandlesBulkImport, HandlesIndexAndBulkDelete;

    public function index(Request $request): JsonResponse
    {
        $this->authorizeSuperadmin($request);
        $status = $request->status ?? 'all';
        $search = $request->search ?? '';

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
            });

        $companies = $this->paginateSorted($query, $request);

        Company::enrichIndexCollection($companies);

        $trash_count = Company::onlyTrashed()->count();

        return response()->json(['data' => $companies, 'trash_count' => $trash_count]);
    }

    public function generateCode(Request $request): JsonResponse
    {
        $this->authorizeSuperadmin($request);

        return response()->json([
            'code' => Company::nextCode(),
        ]);
    }

    public function checkCode(Request $request): JsonResponse
    {
        $this->authorizeSuperadmin($request);
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
        $this->authorizeSuperadmin($request);
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
        $this->authorizeSuperadmin($request);
        $this->authorizeMenuPermission('/company/add');
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
            'email' => 'nullable|email',
            'phone' => 'nullable|numeric',
        ]);

        DB::beginTransaction();
        try {
            $company = Company::createCompany($request);
            CompanySetting::createCompanySettings($company->id, $request->string('name')->toString());
            $branch = Branch::createCompanyBranch($company->id);
            $role = Role::createCompanyRole($company->id);
            User::createCompanyAdmin($request, $role->id, $company->id, $branch->id);
            DB::commit();
        } catch (ValidationException $e) {
            DB::rollBack();
            throw $e;
        } catch (Throwable $e) {
            DB::rollBack();

            return response()->json(['errormessage' => $e->getMessage()], 500);
        }

        return response()->json(['message' => 'Successfully Saved']);
    }

    public function show(Request $request, $id): JsonResponse
    {
        $this->authorizeSuperadmin($request);

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
            $company->admin_name = trim($user->first_name.' '.$user->last_name);

            if ($this->isSuperadmin($request)) {
                $company->admin_email = $user->email;
                $company->admin_username = $user->username;
                $company->admin_phone = $user->phone;
            }
        }

        $company->logo_url = Company::logoUrl($company->logo);

        return response()->json($company);
    }

    public function update(Request $request, $id): JsonResponse
    {
        $this->authorizeSuperadmin($request);
        $this->authorizeMenuPermission('/company/:id/edit');
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

            return response()->json(['errormessage' => $e->getMessage()], 500);
        }

        return response()->json(['message' => 'Successfully Saved']);
    }

    public function sendCredentials(Request $request, int $id, SendUserCredentials $sendUserCredentials): JsonResponse
    {
        $this->authorizeSuperadmin($request);
        $this->authorizeMenuPermission('/company/:id/edit');

        $company = Company::query()->findOrFail($id);

        $user = User::query()
            ->where('company_id', $company->id)
            ->orderBy('created_at')
            ->first();

        if ($user === null) {
            return response()->json([
                'message' => 'This company does not have an administrator account.',
            ], 422);
        }

        $sendUserCredentials->handle($user);

        return response()->json(['message' => 'Login credentials have been queued for email.']);
    }

    public function import(Request $request): JsonResponse
    {
        $this->authorizeSuperadmin($request);
        $this->authorizeMenuPermission('/company/import');
        $request->validate([
            'rows' => 'required|array|min:1',
            'rows.*.name' => 'bail|required|string',
        ]);

        return $this->importRows($request, Company::class, 'company records');
    }

    public function destroy(Request $request, $id): JsonResponse
    {
        $this->authorizeSuperadmin($request);
        if (deletepermission('/company/delete')) {
            Company::deleteCompany($id);

            return response()->json(['message' => 'Successfully Deleted']);
        }

        return response()->json('406');
    }

    public function bulk_delete(Request $request): JsonResponse
    {
        $this->authorizeSuperadmin($request);

        return $this->guardedBulkAction('/company/delete', 'Successfully Deleted', function () use ($request) {
            Company::whereIn('id', $request->all())->delete();
        });
    }

    public function bulk_delete_per(Request $request): JsonResponse
    {
        $this->authorizeSuperadmin($request);

        return $this->guardedBulkAction('/company/delete', 'Successfully Deleted', function () use ($request) {
            $ids = (array) $request->all();
            Company::whereIn('id', $ids)->forceDelete();
        });
    }

    public function updatestatus(Request $request): JsonResponse
    {
        $this->authorizeSuperadmin($request);
        $this->authorizeMenuPermission('/company/:id/edit');
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

                return response()->json(['errormessage' => $e->getMessage()], 500);
            }
        } else {
            return response()->json(['errormessage' => 'Something went wrong']);
        }

        return response()->json(['message' => 'Successfully Saved']);
    }

    public function restore_records(Request $request): JsonResponse
    {
        $this->authorizeSuperadmin($request);
        if (deletepermission('/company/restore')) {
            DB::beginTransaction();
            try {
                Company::whereIn('id', $request->all())->restore();
                DB::commit();

                return response()->json(['message' => 'Successfully Restored']);
            } catch (Throwable $e) {
                DB::rollBack();

                return response()->json(['errormessage' => $e->getMessage()], 500);
            }
        }

        return response()->json('406');
    }

    public function duplicate(Request $request): JsonResponse
    {
        $this->authorizeSuperadmin($request);
        $this->authorizeMenuPermission('/company/add');
        $company = Company::find($request->id);

        if ($company === null) {
            abort(404);
        }

        DB::beginTransaction();
        try {
            $duplicator = $company->replicate();
            $duplicator->name = $company->name.' Copy';
            $duplicator->code = Company::nextCode();
            $duplicator->save();
            DB::commit();

            return response()->json(['message' => 'Successfully Duplicated']);
        } catch (Throwable $e) {
            DB::rollBack();

            return response()->json(['errormessage' => $e->getMessage()], 500);
        }
    }

    public function fetch(Request $request): JsonResponse
    {
        $this->authorizeSuperadmin($request);
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
        $this->authorizeSuperadmin($request);
        $status = $request->status ?? 'all';
        $search = $request->search ?? '';

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
            });

        $companies = $this->paginateSorted($query, $request);

        return response()->json(['data' => $companies]);
    }

    private function isSuperadmin(Request $request): bool
    {
        $user = $request->user();
        $roleName = strtolower(str_replace(' ', '', (string) ($user?->rolename ?? '')));

        return $roleName === 'superadmin';
    }

    private function authorizeSuperadmin(Request $request): void
    {
        if (! $this->isSuperadmin($request)) {
            abort(403);
        }
    }
}
