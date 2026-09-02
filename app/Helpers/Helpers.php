<?php

use App\Mail\DynamicEmail;
use App\Models\ChartOfAccount;
use App\Models\ChartOfAccountMapping;
use App\Models\Menu;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\URL;
use Torann\GeoIP\Facades\GeoIP;

/**
 * Normalize a menu path or route name so `/brand/add`, `brand/add`, and `brand.add` compare equal.
 */
function normalizeMenuPermissionKey(string $key): string
{
    $key = strtolower(trim($key));
    $key = str_replace('.', '/', $key);
    $key = preg_replace('#/+#', '/', $key) ?? $key;
    $key = '/'.ltrim($key, '/');
    $trimmed = rtrim($key, '/');

    return $trimmed === '' ? '/' : $trimmed;
}

/**
 * Whether the current user may use a sidebar menu path or route name.
 * Superadmin (role_id 1 or the superadmin role) is always allowed.
 * Everyone else is checked against the same menus.route_path / route_name
 * lists already loaded for the sidebar — not a separate permissions table.
 */
function hasMenuPermission(string $key): bool
{
    if (! Auth::check() || $key === '') {
        return false;
    }

    $user = Auth::user();

    if ((int) $user->role_id === 1 || $user->hasRole('superadmin')) {
        return true;
    }

    $wanted = normalizeMenuPermissionKey($key);
    $granted = array_merge(
        $user->getPermissionPaths(),
        Menu::permittedRouteNamesForRole((int) $user->role_id),
    );

    foreach ($granted as $permission) {
        if ($permission === null || $permission === '') {
            continue;
        }

        if (normalizeMenuPermissionKey((string) $permission) === $wanted) {
            return true;
        }
    }

    return false;
}

function abortUnlessMenuPermission(string $key): void
{
    if (! hasMenuPermission($key)) {
        abort(403, 'You do not have permission to perform this action.');
    }
}

function hasAnyMenuPermission(string ...$keys): bool
{
    foreach ($keys as $key) {
        if ($key !== '' && hasMenuPermission($key)) {
            return true;
        }
    }

    return false;
}

/**
 * Company settings have used several sidebar paths over time
 * (`/company/setting`, `/setting`, `/business/settings`). Company admins
 * may always manage settings for their own company.
 */
function hasCompanySettingMenuPermission(): bool
{
    $user = Auth::user();

    if ($user === null) {
        return false;
    }

    if ($user->hasRole('companyadmin')) {
        return true;
    }

    return hasAnyMenuPermission(
        '/company/setting',
        '/setting',
        '/business/settings',
        '/setting/index',
    );
}

function abortUnlessCompanySettingMenuPermission(): void
{
    if (! hasCompanySettingMenuPermission()) {
        abort(403, 'You do not have permission to update company settings.');
    }
}

/**
 * Explicit menu/permission key check for delete (and restore) actions.
 * Callers must pass a sidebar path such as `/brand/delete` — the current
 * web route name is never used, because API routes in routes/api.php are unnamed.
 */
function deletepermission(?string $key = null): bool
{
    if ($key === null || $key === '') {
        return false;
    }

    return hasMenuPermission($key);
}

function getUserDialCode()
{
    $ip = Request::ip();

    // If localhost or testing environment
    if ($ip === '127.0.0.1' || $ip === '::1') {
        return '+92'; // default code for localhost
    }

    try {
        $location = GeoIP::getLocation($ip);

        // Country to dial code mapping
        $dialCodes = config('dial_codes');

        return $dialCodes[$location->iso_code] ?? '+92'; // fallback to +1
    } catch (Exception $e) {
        return '+92'; // default if geo lookup fails
    }
}

function getUserIpAddress()
{
    $ip = Request::ip();

    return $ip;
}

function getSetting(): ?array
{
    try {
        return Cache::remember('app.settings.public', now()->addMinutes(15), function () {
            $setting = Setting::query()->first()?->toArray();

            if ($setting === null) {
                return null;
            }

            unset(
                $setting['smtp_password'],
                $setting['authorize_api_login_id'],
                $setting['authorize_transaction_key'],
                $setting['authorize_signature_key'],
            );

            return $setting;
        });
    } catch (Throwable) {
        return null;
    }
}

function forgetSettingCache(): void
{
    Cache::forget('app.settings');
    Cache::forget('app.settings.public');
}

function forgetUserPermissionsCache(?int $roleId = null): void
{
    if ($roleId !== null) {
        Cache::forget("user_menu_permissions:{$roleId}");

        return;
    }

    foreach (range(1, 50) as $id) {
        Cache::forget("user_menu_permissions:{$id}");
    }
}

/**
 * Send email verification link to user
 *
 * @param  User  $user
 * @return bool|string Returns true on success, error message on failure
 */
function sendEmailVerificationLink($user)
{
    try {
        $setting = Setting::first();

        // Check if user email is already verified
        if ($user->hasVerifiedEmail()) {
            return 'Email is already verified';
        }

        // Generate verification URL (valid for 60 minutes)
        $verificationUrl = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            ['id' => $user->id, 'hash' => sha1($user->email)]
        );

        // Get email validation template from settings
        $emailTemplate = $setting->email_validation ?? '';

        // Replace placeholders
        $emailTemplate = str_replace("['appname']", config('app.name') ?? env('APP_NAME'), $emailTemplate);
        $emailTemplate = str_replace("['appemail']", $setting->email ?? '', $emailTemplate);
        $emailTemplate = str_replace("['useremail']", $user->email, $emailTemplate);
        $emailTemplate = str_replace("['verification_link']", $verificationUrl, $emailTemplate);
        $emailTemplate = str_replace("['reset_password_link']", $resetPasswordUrl, $emailTemplate);

        // Replace the button with actual verification link
        // $verificationLinkHtml = '<a href="' . htmlspecialchars($verificationUrl) . '" class="btn btn-primary" style="display: inline-block; padding: 10px 20px; background-color: #007bff; color: #ffffff; text-decoration: none; border-radius: 4px; margin: 10px 0;">Verify Email Address</a>';
        $verificationLinkHtml = $verificationUrl;
        $emailTemplate = preg_replace(
            '/<button[^>]*id="btn-send-validation-email"[^>]*>.*?<\/button>/i',
            $verificationLinkHtml,
            $emailTemplate
        );

        // Add verification link after instructions if button wasn't found
        if (strpos($emailTemplate, $verificationLinkHtml) === false) {
            $emailTemplate .= '<p style="margin-top: 20px;"><a href="'.htmlspecialchars($verificationUrl).'" class="btn btn-primary" style="display: inline-block; padding: 10px 20px; background-color: #007bff; color: #ffffff; text-decoration: none; border-radius: 4px;">Click here to verify your email address</a></p>';
        }

        // Prepare email data
        $emailData = [
            'from_email' => $setting->smtp_from_address ?? $setting->email ?? config('mail.from.address'),
            'from_name' => $setting->smtp_from_name ?? config('app.name') ?? 'Student Dashboard',
            'cc_email' => [],
            'bcc_email' => [],
        ];

        // Send email
        Mail::to($user->email)->send(new DynamicEmail(
            $emailTemplate,
            'Email Verification Required',
            $emailData
        ));

        return true;
    } catch (Throwable $e) {
        return 'Failed to send verification email: '.$e->getMessage();
    }
}

function parentChartOfAccount(int $companyId, int $branchId): void
{
    $parents = [
        ['name' => 'Equity', 'code' => '100-00000', 'bs' => 1, 'acc_nature' => 'cr'],
        ['name' => 'Assets', 'code' => '200-00000', 'bs' => 1, 'acc_nature' => 'dr'],
        ['name' => 'Liabilities', 'code' => '300-00000', 'bs' => 1, 'acc_nature' => 'cr'],
        ['name' => 'Expenses', 'code' => '400-00000', 'bs' => 0, 'acc_nature' => 'dr'],
        ['name' => 'Revenue', 'code' => '500-00000', 'bs' => 0, 'acc_nature' => 'cr'],
        ['name' => 'Cost of Goods Sold', 'code' => '600-00000', 'bs' => 0, 'acc_nature' => 'dr'],
    ];

    $now = now();

    foreach ($parents as $value) {
        DB::table('chart_of_accounts')->insert([
            'company_id' => $companyId,
            'branch_id' => $branchId,
            'name' => $value['name'],
            'code' => $value['code'],
            'bs' => $value['bs'],
            'acc_nature' => $value['acc_nature'],
            'acc_type' => 'c',
            'active' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}

function accountMapping(int $companyId, int $branchId): void
{
    $mapping = [
        ['name' => 'Equity', 'key' => 'equity', 'value' => null],
        ['name' => 'Assets', 'key' => 'assets', 'value' => null],
        ['name' => 'Liabilities', 'key' => 'liability', 'value' => null],
        ['name' => 'Expenses', 'key' => 'expenses', 'value' => null],
        ['name' => 'Revenue', 'key' => 'revenue', 'value' => null],
        ['name' => 'Customer', 'key' => 'customer', 'value' => null],
        ['name' => 'Supplier', 'key' => 'supplier', 'value' => null],
        ['name' => 'Customer Advance', 'key' => 'customeradvance', 'value' => null],
        ['name' => 'Bank', 'key' => 'bank', 'value' => null],
        ['name' => 'Cash', 'key' => 'cash', 'value' => null],
        ['name' => 'Purchase', 'key' => 'purchase', 'value' => null],
        ['name' => 'Import Purchase', 'key' => 'importpurchase', 'value' => null],
        ['name' => 'Local Purchase', 'key' => 'localpurchase', 'value' => null],
        ['name' => 'Input Tax', 'key' => 'inputtax', 'value' => null],
        ['name' => 'Sales', 'key' => 'sale', 'value' => null],
        ['name' => 'Local Sales', 'key' => 'localsales', 'value' => null],
        ['name' => 'Export Sales', 'key' => 'exportsale', 'value' => null],
        ['name' => 'Output Tax', 'key' => 'outputtax', 'value' => null],
        ['name' => 'Profit And Loss', 'key' => 'pnl', 'value' => null],
    ];

    $now = now();

    foreach ($mapping as $value) {
        DB::table('chart_of_account_mappings')->insert([
            'company_id' => $companyId,
            'branch_id' => $branchId,
            'name' => $value['name'],
            'key' => $value['key'],
            'value' => $value['value'],
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}

function generateChartOfAccountCode(string $type, ChartOfAccountMapping $mapping, object $request): string
{
    if (! in_array($type, ['supplier', 'customer', 'bank'], true)) {
        return '000';
    }

    $account = ChartOfAccount::query()
        ->with(['parent.parent'])
        ->find($mapping->value);

    if ($account === null) {
        return '000';
    }

    $companyId = (int) $request->company_id;
    $branchId = (int) $request->branch_id;

    if ($account->parent_id === null) {
        $childCount = ChartOfAccount::query()
            ->where('parent_id', $account->id)
            ->where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->count();

        return $account->code.($childCount + 1).'0-00000';
    }

    $codePrefix = explode('-', (string) $account->code)[0];

    if ($account->parent?->parent_id === null) {
        $childCount = ChartOfAccount::query()
            ->where('parent_id', $account->id)
            ->where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->count();

        return substr($codePrefix, 0, 2).($childCount + 1).'-00000';
    }

    $childCount = ChartOfAccount::query()
        ->where('parent_id', $account->id)
        ->count();

    $code = $codePrefix.'-'.str_pad((string) ($childCount + 1), 5, '0', STR_PAD_LEFT);

    return ensureUniqueChartOfAccountCode($code, $companyId, $branchId);
}

function ensureUniqueChartOfAccountCode(string $code, int $companyId, int $branchId): string
{
    $candidate = $code;

    while (ChartOfAccount::query()
        ->where('code', $candidate)
        ->where('company_id', $companyId)
        ->where('branch_id', $branchId)
        ->exists()) {
        $parts = explode('-', $candidate);
        $lastIndex = count($parts) - 1;
        $next = ((int) $parts[$lastIndex]) + 1;
        $parts[$lastIndex] = str_pad((string) $next, 5, '0', STR_PAD_LEFT);
        $candidate = implode('-', $parts);
    }

    return $candidate;
}
