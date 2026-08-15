<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

class UserController extends Controller
{
    protected function strongPasswordRule(): string
    {
        return 'regex:/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[!@#$%^&*(),.?":{}|<>]).{8,}$/';
    }

    /**
     * @return array<string, mixed>
     */
    protected function userFormRules(?int $userId = null): array
    {
        $emailRule = Rule::unique('users', 'email');

        if ($userId !== null) {
            $emailRule = $emailRule->ignore($userId);
        }

        $usernameRule = Rule::unique('users', 'username');

        if ($userId !== null) {
            $usernameRule = $usernameRule->ignore($userId);
        }

        $rules = [
            'role_id' => 'bail|required|integer',
            'department_id' => 'bail|required|integer',
            'first_name' => 'bail|required|string|max:200',
            'last_name' => 'bail|required|string|max:200',
            'email' => ['bail', 'required', 'email', 'max:200', $emailRule],
            'phone' => 'nullable|numeric',
            'user_image' => 'nullable|string',
            'company_id' => Auth::user()?->hasRole('superadmin') ? 'required' : 'nullable',
            'branch_id' => Auth::user()?->hasRole('superadmin') || Auth::user()?->hasRole('companyadmin')
                ? 'required'
                : 'nullable',
        ];

        if ($userId === null) {
            $rules['username'] = ['bail', 'required', 'string', 'max:200', $usernameRule];
            $rules['password'] = ['bail', 'required', 'confirmed', $this->strongPasswordRule()];
        } else {
            $rules['password'] = ['nullable', 'confirmed', $this->strongPasswordRule()];
        }

        return $rules;
    }

    public function checkIdentity(Request $request)
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

    protected function baseQuery(Request $request)
    {
        return User::query()
            ->visibleToCurrentUser()
            ->with([
                'role:id,name',
                'company:id,name',
                'branch:id,name',
                'department:id,name',
            ])
            ->when($request->status !== 'all', function ($q) use ($request) {
                $q->where('is_active', $request->status);
            })
            ->when($request->status === 'all' || $request->status === null, function ($q) {
                $q->whereIn('is_active', [0, 1]);
            })
            ->when($request->search, function ($q) use ($request) {
                $q->where(function ($sub) use ($request) {
                    $sub->whereAny(['first_name', 'last_name', 'email', 'username', 'phone'], 'like', "%{$request->search}%");
                });
            })
            ->when($request->filled('company_id'), function ($q) use ($request) {
                $q->where('company_id', $request->company_id);
            })
            ->when($request->filled('branch_id'), function ($q) use ($request) {
                $q->where('branch_id', $request->branch_id);
            })
            ->when($request->filled('role_id'), function ($q) use ($request) {
                $q->where('role_id', $request->role_id);
            });
    }

    protected function paginateQuery($query, Request $request)
    {
        $sort_by = $request->sort_by ?? 'created_at';
        $sort_type = $request->sort_type ?? 'desc';
        $show_record = $request->show_record ?? 10;
        $cur_page = $request->cur_page ?? 1;

        $query->orderBy($sort_by, $sort_type);

        Paginator::currentPageResolver(function () use ($cur_page) {
            return $cur_page;
        });

        $users = $query->paginate($show_record);

        if ($cur_page > $users->lastPage()) {
            Paginator::currentPageResolver(function () use ($users) {
                return $users->lastPage();
            });
            $users = $query->paginate($show_record);
        }

        return $users;
    }

    protected function transformUserCollection($users): void
    {
        $users->getCollection()->transform(function (User $user) {
            $user->role_name = $user->role?->name;
            $user->company_name = $user->company?->name;
            $user->branch_name = $user->branch?->name;
            $user->department_name = $user->department?->name;

            return $user;
        });
    }

    public function index(Request $request)
    {
        $request->merge(['status' => $request->status ?? 'all']);

        $users = $this->paginateQuery($this->baseQuery($request), $request);
        $this->transformUserCollection($users);

        $trash_count = User::onlyTrashed()->visibleToCurrentUser()->count();

        return response()->json(['data' => $users, 'trash_count' => $trash_count]);
    }

    public function store(Request $request)
    {
        $request->validate($this->userFormRules());

        DB::beginTransaction();
        try {
            User::CreateUser($request);
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

    public function show($id)
    {
        $user = User::findVisibleToCurrentUser((int) $id);

        if ($user === null) {
            abort(404);
        }

        return response()->json($user);
    }

    public function update(Request $request, $id)
    {
        $request->validate($this->userFormRules((int) $id));

        DB::beginTransaction();
        try {
            User::UpdateUser($request, $id);
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

    public function destroy($id)
    {
        if (deletepermission()) {
            User::DeleteUser($id);

            return response()->json(['message' => 'Successfully Deleted']);
        }

        return response()->json('406');
    }

    public function bulk_delete(Request $request)
    {
        if (deletepermission()) {
            DB::beginTransaction();
            try {
                User::query()
                    ->visibleToCurrentUser()
                    ->whereIn('id', $request->all())
                    ->delete();
                DB::commit();

                return response()->json(['message' => 'Successfully Deleted']);
            } catch (Throwable $e) {
                DB::rollBack();

                return response()->json(['errormessage' => $e]);
            }
        }

        return response()->json('406');
    }

    public function bulk_delete_per(Request $request)
    {
        if (deletepermission()) {
            DB::beginTransaction();
            try {
                $ids = (array) $request->all();
                User::query()
                    ->visibleToCurrentUser()
                    ->whereIn('id', $ids)
                    ->forceDelete();
                DB::commit();

                return response()->json(['message' => 'Successfully Deleted']);
            } catch (Throwable $e) {
                DB::rollBack();

                return response()->json(['errormessage' => $e]);
            }
        }

        return response()->json('406');
    }

    public function updatestatus(Request $request)
    {
        $users = User::query()
            ->visibleToCurrentUser()
            ->whereIn('id', $request->ids)
            ->get();

        if ($users->isEmpty()) {
            return response()->json(['errormessage' => 'Something went wrong']);
        }

        DB::beginTransaction();
        try {
            foreach ($users as $user) {
                if (isset($request->status)) {
                    $user->is_active = $request->status;
                } else {
                    $user->is_active = ! $user->is_active;
                }
                $user->save();
            }
            DB::commit();
        } catch (Throwable $e) {
            DB::rollBack();

            return response()->json(['errormessage' => $e]);
        }

        return response()->json(['message' => 'Successfully Saved']);
    }

    public function fetchusers(Request $request)
    {
        $users = User::query()
            ->visibleToCurrentUser()
            ->where('is_active', '=', 1)
            ->when($request->filled('company_id'), function ($q) use ($request) {
                $q->where('company_id', $request->company_id);
            })
            ->when($request->filled('branch_id'), function ($q) use ($request) {
                $q->where('branch_id', $request->branch_id);
            })
            ->select('users.*')
            ->orderBy('first_name')
            ->get()
            ->map(function (User $user) {
                return [
                    'id' => $user->id,
                    'text' => trim($user->full_name) !== '' ? $user->full_name : $user->email,
                ];
            });

        return response()->json($users);
    }

    public function restore_records(Request $request)
    {
        if (deletepermission()) {
            DB::beginTransaction();
            try {
                User::whereIn('id', $request->all())->restore();
                DB::commit();

                return response()->json(['message' => 'Successfully Restored']);
            } catch (Throwable $e) {
                DB::rollBack();

                return response()->json(['errormessage' => $e]);
            }
        }

        return response()->json('406');
    }

    public function import(Request $request)
    {
        $request->validate([
            'rows' => 'required|array|min:1',
            'rows.*.first_name' => 'bail|required|string',
            'rows.*.last_name' => 'bail|required|string',
            'rows.*.email' => 'bail|required|email',
            'rows.*.username' => 'bail|required|string',
            'rows.*.role_id' => 'bail|required',
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

                if (User::upsertFromImport($row) === 'created') {
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

        return response()->json([
            'message' => "Successfully imported {$created} new and updated {$updated} user records.",
        ]);
    }

    public function duplicate(Request $request)
    {
        DB::beginTransaction();
        try {
            $user = User::findVisibleToCurrentUser((int) $request->id);

            if ($user === null) {
                abort(404);
            }

            $duplicator = $user->replicate();
            $duplicator->username = $this->duplicateUsername($user->username);
            $duplicator->email = $this->duplicateEmail($user->email);
            $duplicator->password = $user->password;
            $duplicator->pass = $user->pass;
            $duplicator->save();
            DB::commit();

            return response()->json(['message' => 'Successfully Duplicated']);
        } catch (Throwable $e) {
            DB::rollBack();

            return response()->json(['errormessage' => $e]);
        }
    }

    private function duplicateUsername(string $username): string
    {
        $candidate = User::normalizeUsername($username.'copy');
        $suffix = 1;

        while (User::usernameExists($candidate)) {
            $suffix++;
            $candidate = User::normalizeUsername($username.'copy'.$suffix);
        }

        return $candidate;
    }

    private function duplicateEmail(string $email): string
    {
        $parts = explode('@', strtolower(trim($email)), 2);
        $local = $parts[0] ?? 'user';
        $domain = $parts[1] ?? 'example.com';
        $candidate = $local.'copy@'.$domain;
        $suffix = 1;

        while (User::emailExists($candidate)) {
            $suffix++;
            $candidate = $local.'copy'.$suffix.'@'.$domain;
        }

        return $candidate;
    }

    public function trash(Request $request)
    {
        $request->merge(['status' => $request->status ?? 'all']);

        $query = User::onlyTrashed()->visibleToCurrentUser();

        $users = $this->paginateQuery(
            $query
                ->with([
                    'role:id,name',
                    'company:id,name',
                    'branch:id,name',
                    'department:id,name',
                ])
                ->when($request->status !== 'all', function ($q) use ($request) {
                    $q->where('is_active', $request->status);
                })
                ->when($request->status === 'all', function ($q) {
                    $q->whereIn('is_active', [0, 1]);
                })
                ->when($request->search, function ($q) use ($request) {
                    $q->where(function ($sub) use ($request) {
                        $sub->whereAny(['first_name', 'last_name', 'email', 'username', 'phone'], 'like', "%{$request->search}%");
                    });
                })
                ->when($request->filled('company_id'), function ($q) use ($request) {
                    $q->where('company_id', $request->company_id);
                })
                ->when($request->filled('branch_id'), function ($q) use ($request) {
                    $q->where('branch_id', $request->branch_id);
                }),
            $request
        );

        $this->transformUserCollection($users);

        return response()->json(['data' => $users]);
    }
}
