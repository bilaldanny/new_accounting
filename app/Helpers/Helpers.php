<?php

use App\Mail\DynamicEmail;
use App\Models\Permission;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\URL;
use Torann\GeoIP\Facades\GeoIP;

function deletepermission()
{

    if (Auth::check()) {
        if (Auth::user()->role_id == 1) {
            return true;
        } else {
            $permissions = Permission::join('menus', 'menus.id', '=', 'permissions.menu_id')
                ->select('permissions.*', 'menus.route_name')
                ->where('role_id', '=', Auth::user()->role_id)  // Filter by user's role ID
                ->where('permissions.status', '=', 1)  // Only active permissions
                ->pluck('route_name')  // Get the route names
                ->toArray();  // Convert the collection to an array

            // Check if the current route's name is in the list of permitted route names
            if (in_array(request()->route()->getName(), $permissions)) {
                return true;  // If permitted, return true
            }
        }

        return false;
    }
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
        return Cache::remember('app.settings', now()->addMinutes(15), function () {
            return Setting::first()?->toArray();
        });
    } catch (Throwable) {
        return null;
    }
}

function forgetSettingCache(): void
{
    Cache::forget('app.settings');
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
