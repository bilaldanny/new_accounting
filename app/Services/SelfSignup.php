<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\Company;
use App\Models\CompanySetting;
use App\Models\Role;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\Company\CompanyBootstrap;
use App\Support\RolePresets;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * A new customer signing up on the public page: the same company, first branch and admin user an administrator creates on
 * the Company page, plus the trial the chosen plan allows and the starter staff roles. All of it happens in one transaction,
 * so a step that fails leaves nothing behind. The withholding tax accounts are mapped by `Branch::createCompanyBranch()`.
 */
class SelfSignup
{
    public const PASSWORD_REGEX = '/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[!@#$%^&*(),.?":{}|<>]).{8,}$/';

    /**
     * Plans a visitor can start a free trial on, cheapest first.
     *
     * @return Collection<int, SubscriptionPlan>
     */
    public static function trialPlans(): Collection
    {
        return SubscriptionPlan::query()->where('is_active', true)->where('trial_days', '>', 0)->orderBy('sort_order')->orderBy('price')->get();
    }

    /**
     * The role of a company admin. Every existing admin shares the one global `companyadmin` role, which the menu migrations
     * keep granting new menus to, so a new tenant gets that same role. Only a database without it falls back to a role of the
     * company's own (which starts with no menus).
     */
    public static function companyAdminRole(int $companyId): Role
    {
        return Role::query()->whereNull('company_id')->whereRaw("LOWER(REPLACE(name, ' ', '')) = ?", [Role::normalizeName(Role::HIDDEN_ROLE_NAME)])->orderBy('id')->first()
            ?? Role::createCompanyRole($companyId);
    }

    /**
     * @param  array{company_name: string, company_email?: string|null, company_phone?: string|null, address?: string|null, country_id?: int|string|null, zipcode?: string|null, ntn_no?: string|null, strn_no?: string|null, first_name: string, last_name?: string|null, username: string, email: string, phone?: string|null, password: string}  $data
     * @param  string|null  $planCode  code of a plan from {@see self::trialPlans()}; null takes the first one, if there is any
     */
    public function register(array $data, ?string $planCode = null): User
    {
        return DB::transaction(function () use ($data, $planCode): User {
            $plan = $planCode !== null ? self::trialPlans()->firstWhere('code', $planCode) : self::trialPlans()->first();

            $request = new Request([
                'name' => $data['company_name'],
                'email' => $data['company_email'] ?? $data['email'],
                'phone' => $data['company_phone'] ?? null,
                'address' => $data['address'] ?? null,
                'country_id' => $data['country_id'] ?? null,
                'zipcode' => $data['zipcode'] ?? null,
                'ntn_no' => $data['ntn_no'] ?? null,
                'strn_no' => $data['strn_no'] ?? null,
                'admin_name' => trim($data['first_name'].' '.($data['last_name'] ?? '')),
                'admin_username' => $data['username'],
                'admin_email' => $data['email'],
                'admin_phone' => $data['phone'] ?? null,
                'password' => $data['password'],
            ]);

            $company = Company::createCompany($request);
            CompanySetting::createCompanySettings($company->id, $data['company_name']);
            $branch = Branch::createCompanyBranch($company->id);
            $role = self::companyAdminRole($company->id);
            $user = User::createCompanyAdmin($request, $role->id, $company->id, $branch->id);
            // The name was typed in two boxes: keep it that way instead of splitting it again at the first space.
            $user->forceFill(['first_name' => $data['first_name'], 'last_name' => $data['last_name'] ?? ''])->save();

            if ($plan !== null) {
                $company->changePlan($plan);
            }

            RolePresets::sync((int) $company->id, (int) $branch->id);
            app(CompanyBootstrap::class)->run($company, $branch, $role);

            return $user;
        });
    }
}
