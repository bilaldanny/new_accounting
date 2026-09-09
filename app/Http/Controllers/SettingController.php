<?php

namespace App\Http\Controllers;

use App\Mail\SmtpTestMail;
use App\Models\Setting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class SettingController extends Controller
{
    public function index(): Response
    {
        $this->authorizeSoftwareSettings();

        return Inertia::render('software/setting');
    }

    public function show(): JsonResponse
    {
        $this->authorizeSoftwareSettings();

        return response()->json([
            'softwareSetting' => Setting::formatForResponse(Setting::instance()),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $this->authorizeSoftwareSettings();
        $this->authorizeMenuPermission('/software/setting');
        $this->normalizeSmtpInput($request);

        $request->validate([
            'name' => 'bail|required|string|min:3|max:200',
            'email' => 'nullable|email|max:200',
            'contact_no' => 'nullable|string|max:50',
            'address' => 'nullable|string',
            'system_logo' => 'nullable|string|max:500',
            'email_logo' => 'nullable|string|max:500',
            'login_logo' => 'nullable|string|max:500',
            'smtp_host' => 'nullable|string|max:200',
            'smtp_port' => 'nullable|integer|min:1|max:65535',
            'smtp_username' => 'nullable|string|max:200',
            'smtp_password' => 'nullable|string|max:500',
            'smtp_encryption' => 'nullable|string|in:tls,ssl,none,',
            'smtp_scheme' => 'nullable|string|in:smtp,smtps',
            'smtp_from_address' => 'nullable|email|max:200',
            'smtp_from_name' => 'nullable|string|max:200',
        ]);

        $setting = Setting::instance();
        Setting::updateFromRequest($request, $setting);
        $setting->refresh();

        return response()->json([
            'message' => 'Successfully Saved',
            'softwareSetting' => Setting::formatForResponse($setting),
        ]);
    }

    public function testSmtp(Request $request): JsonResponse
    {
        $this->authorizeSoftwareSettings();
        $this->normalizeSmtpInput($request);

        $result = Setting::testSmtpConnection(
            Setting::query()->first(),
            $this->smtpOverridesFromRequest($request),
        );

        return response()->json([
            'status' => $result['ok'] ? 'success' : 'alert',
            'message' => $result['message'],
        ], $result['ok'] ? 200 : 400);
    }

    public function testSend(Request $request): JsonResponse
    {
        $this->authorizeSoftwareSettings();
        $this->normalizeSmtpInput($request);

        $validated = $request->validate([
            'test_email' => 'bail|required|email|max:200',
            'smtp_host' => 'nullable|string|max:200',
            'smtp_port' => 'nullable|integer|min:1|max:65535',
            'smtp_username' => 'nullable|string|max:200',
            'smtp_password' => 'nullable|string|max:500',
            'smtp_encryption' => 'nullable|string|in:tls,ssl,none,',
            'smtp_scheme' => 'nullable|string|in:smtp,smtps',
            'smtp_from_address' => 'nullable|email|max:200',
            'smtp_from_name' => 'nullable|string|max:200',
        ]);

        Setting::applyMailConfig(
            Setting::query()->first(),
            $this->smtpOverridesFromRequest($request),
        );

        try {
            Mail::to($validated['test_email'])->send(new SmtpTestMail);
        } catch (Throwable $e) {
            return response()->json([
                'status' => 'alert',
                'message' => 'Failed to send test email: '.$e->getMessage(),
            ], 400);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Test email sent successfully.',
        ]);
    }

    public function email_template(): Response
    {
        abort(404);
    }

    public function email_test_send(Request $request): JsonResponse
    {
        return $this->testSend($request);
    }

    private function authorizeSoftwareSettings(): void
    {
        $user = request()->user();

        if ($user === null || (! $user->hasRole('superadmin') && (int) $user->role_id !== 1)) {
            abort(403);
        }
    }

    private function normalizeSmtpInput(Request $request): void
    {
        if ($request->input('smtp_port') === '') {
            $request->merge(['smtp_port' => null]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function smtpOverridesFromRequest(Request $request): array
    {
        return $request->only([
            'smtp_host',
            'smtp_port',
            'smtp_username',
            'smtp_password',
            'smtp_encryption',
            'smtp_scheme',
            'smtp_from_address',
            'smtp_from_name',
        ]);
    }
}
