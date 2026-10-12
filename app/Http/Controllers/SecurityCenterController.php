<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ScopesToCompany;
use App\Models\Contact;
use App\Models\User;
use App\Services\Security\IpRules;
use App\Services\Security\PersonalData;
use App\Services\Security\SecuritySettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Security Center (see Services\Security): IP allow / deny list, login history and device list, session management, password
 * policy and maker-checker switch, security log export, and the personal-data export / anonymisation of a contact or a user.
 * Everything is the signed-in user's own company.
 */
class SecurityCenterController extends Controller
{
    use ScopesToCompany;

    public function __construct(private readonly PersonalData $personal) {}

    public function settings(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/security');

        return response()->json(['data' => SecuritySettings::for($this->scopeCompanyId($request))]);
    }

    public function saveSettings(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/security/manage');

        $data = $request->validate([
            'maker_checker' => 'required|boolean', 'policy_enabled' => 'required|boolean', 'min_length' => 'required|integer|min:6|max:64',
            'require_number' => 'required|boolean', 'require_mixed_case' => 'required|boolean', 'require_symbol' => 'required|boolean',
            'expiry_days' => 'required|integer|min:0|max:730', 'history_count' => 'required|integer|min:0|max:20',
        ]);

        DB::table('security_settings')->updateOrInsert(['company_id' => $this->scopeCompanyId($request)], $data + ['updated_at' => now(), 'created_at' => now()]);

        return response()->json(['message' => 'Successfully Saved']);
    }

    public function ipRules(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/security');

        return response()->json(['data' => DB::table('ip_rules')->where('company_id', $this->scopeCompanyId($request))->orderBy('type')->orderBy('id')->get()]);
    }

    public function addIpRule(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/security/manage');

        $data = $request->validate(['type' => ['required', Rule::in(['allow', 'deny'])], 'address' => 'required|string|max:64', 'note' => 'nullable|string|max:190']);
        $address = trim($data['address']);
        $ip = explode('/', $address)[0];

        if (@inet_pton($ip) === false || (str_contains($address, '/') && ! ctype_digit(explode('/', $address)[1] ?? ''))) {
            throw ValidationException::withMessages(['address' => ['Enter an IP address or a CIDR block such as 203.0.113.0/24.']]);
        }

        $companyId = $this->scopeCompanyId($request);

        // refuse the rule that would lock the person setting it out of their own company
        $trial = DB::table('ip_rules')->where('company_id', $companyId)->get(['type', 'address'])->push((object) ['type' => $data['type'], 'address' => $address]);
        $deny = $trial->where('type', 'deny')->contains(fn ($r) => IpRules::matches((string) $request->ip(), (string) $r->address));
        $allow = $trial->where('type', 'allow');

        if (! Auth::user()?->hasRole('superadmin') && ($deny || ($allow->isNotEmpty() && ! $allow->contains(fn ($r) => IpRules::matches((string) $request->ip(), (string) $r->address))))) {
            throw ValidationException::withMessages(['address' => ['This rule would lock out your own address ('.$request->ip().').']]);
        }

        $id = DB::table('ip_rules')->insertGetId(['company_id' => $companyId, 'type' => $data['type'], 'address' => $address, 'note' => $data['note'] ?? null, 'created_by' => Auth::id(), 'created_at' => now(), 'updated_at' => now()]);

        return response()->json(['message' => 'Successfully Saved', 'id' => $id], 201);
    }

    public function deleteIpRule(Request $request, int $id): JsonResponse
    {
        $this->authorizeMenuPermission('/security/manage');

        $deleted = DB::table('ip_rules')->where('company_id', $this->scopeCompanyId($request))->where('id', $id)->delete();
        abort_if($deleted === 0, 404);

        return response()->json(['message' => 'Successfully Deleted']);
    }

    public function loginEvents(Request $request): JsonResponse|StreamedResponse
    {
        $this->authorizeMenuPermission('/security');

        $data = $request->validate([
            'event' => 'nullable|string|max:14', 'user_id' => 'nullable|integer', 'from' => 'nullable|date', 'to' => 'nullable|date', 'export' => 'nullable|in:csv',
        ]);
        $companyId = $this->scopeCompanyId($request);

        $query = DB::table('login_events as e')->leftJoin('users as u', 'u.id', '=', 'e.user_id')
            ->where(fn ($q) => $q->where('e.company_id', $companyId)->orWhere(fn ($failed) => $failed->whereNull('e.company_id')->whereIn('e.identity', DB::table('users')->where('company_id', $companyId)->select('email')->union(DB::table('users')->where('company_id', $companyId)->select('username')))))
            ->when(! empty($data['event']), fn ($q) => $q->where('e.event', $data['event']))
            ->when(! empty($data['user_id']), fn ($q) => $q->where('e.user_id', $data['user_id']))
            ->when(! empty($data['from']), fn ($q) => $q->whereDate('e.created_at', '>=', $data['from']))
            ->when(! empty($data['to']), fn ($q) => $q->whereDate('e.created_at', '<=', $data['to']))
            ->orderByDesc('e.id');

        $columns = ['e.id', 'e.created_at', 'e.event', 'e.identity', 'e.user_id', 'u.email as user_email', 'e.ip', 'e.device'];

        if (($data['export'] ?? null) === 'csv') {
            return response()->streamDownload(function () use ($query, $columns): void {
                $out = fopen('php://output', 'w');
                fputcsv($out, ['id', 'time', 'event', 'identity', 'user_id', 'user_email', 'ip', 'device']);

                foreach ((clone $query)->limit(20000)->get($columns) as $row) {
                    fputcsv($out, array_values((array) $row));
                }

                fclose($out);
            }, 'security-log-'.now()->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv']);
        }

        return response()->json(['data' => $query->limit(500)->get($columns)]);
    }

    /**
     * The devices each user of the company has signed in from.
     */
    public function devices(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/security');

        $rows = DB::table('login_events as e')->join('users as u', 'u.id', '=', 'e.user_id')
            ->where('e.company_id', $this->scopeCompanyId($request))->where('e.event', 'login')
            ->groupBy('e.user_id', 'u.email', 'e.device', 'e.ip')
            ->selectRaw('e.user_id, u.email, e.device, e.ip, count(*) as sign_ins, max(e.created_at) as last_seen')
            ->orderByDesc('last_seen')->limit(300)->get();

        return response()->json(['data' => $rows]);
    }

    public function sessions(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/security');

        $rows = DB::table('user_sessions as s')->join('users as u', 'u.id', '=', 's.user_id')
            ->where('s.company_id', $this->scopeCompanyId($request))->whereNull('s.revoked_at')
            ->orderByDesc('s.last_activity')->limit(300)->get(['s.id', 's.user_id', 'u.email', 's.ip', 's.device', 's.last_activity', 's.created_at']);

        return response()->json(['data' => $rows]);
    }

    public function revokeSession(Request $request, int $id): JsonResponse
    {
        $this->authorizeMenuPermission('/security/manage');

        $changed = DB::table('user_sessions')->where('company_id', $this->scopeCompanyId($request))->where('id', $id)->whereNull('revoked_at')->update(['revoked_at' => now()]);
        abort_if($changed === 0, 404);

        return response()->json(['message' => 'Session ended']);
    }

    public function revokeUserSessions(Request $request, int $userId): JsonResponse
    {
        $this->authorizeMenuPermission('/security/manage');

        $companyId = $this->scopeCompanyId($request);
        abort_unless(User::query()->where('company_id', $companyId)->whereKey($userId)->exists(), 404);

        $count = DB::table('user_sessions')->where('company_id', $companyId)->where('user_id', $userId)->whereNull('revoked_at')->update(['revoked_at' => now()]);

        return response()->json(['message' => 'Sessions ended', 'count' => $count]);
    }

    public function privacy(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/security/privacy');

        return response()->json(['data' => DB::table('privacy_requests')->where('company_id', $this->scopeCompanyId($request))->orderByDesc('id')->limit(200)->get()]);
    }

    public function privacyExport(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/security/privacy');

        [$subject, $companyId] = $this->subject($request);

        return response()->json(['data' => $subject instanceof Contact
            ? $this->personal->exportContact($subject, (int) Auth::id())
            : $this->personal->exportUser($subject, (int) Auth::id())]);
    }

    public function privacyAnonymize(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/security/privacy');

        $request->validate(['confirm' => 'accepted', 'note' => 'nullable|string|max:255']);
        [$subject] = $this->subject($request);

        $subject instanceof Contact
            ? $this->personal->anonymizeContact($subject, (int) Auth::id(), $request->input('note'))
            : $this->personal->anonymizeUser($subject, (int) Auth::id(), $request->input('note'));

        return response()->json(['message' => 'Personal data anonymised']);
    }

    /**
     * @return array{0: Contact|User, 1: int}
     */
    private function subject(Request $request): array
    {
        $data = $request->validate(['subject_type' => ['required', Rule::in(['contact', 'user'])], 'subject_id' => 'required|integer']);
        $companyId = $this->scopeCompanyId($request);
        $model = $data['subject_type'] === 'contact'
            ? Contact::query()->where('company_id', $companyId)->find($data['subject_id'])
            : User::query()->where('company_id', $companyId)->find($data['subject_id']);

        abort_if($model === null, 404);

        return [$model, $companyId];
    }
}
