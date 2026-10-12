<?php

namespace App\Http\Controllers;

use App\Models\DatabaseBackup;
use App\Services\System\BackupTools;
use App\Services\System\DataRetention;
use App\Services\System\DemoCompany;
use App\Services\System\FranchiseGroups;
use App\Services\System\SystemLogs;
use App\Services\System\WebPush;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * The platform System Center, for the superadmin only (whatever menu permissions a role holds): the application log viewer, the
 * remote copy and restore of database backups, the VAPID keys of web push, sandbox (demo) companies, franchise groups, help
 * articles and the platform default retention rules.
 */
class SystemCenterController extends Controller
{
    public function logs(Request $request, SystemLogs $logs): JsonResponse
    {
        $this->guard();
        $data = $request->validate(['level' => ['nullable', Rule::in(SystemLogs::LEVELS)], 'search' => 'nullable|string|max:100', 'limit' => 'nullable|integer|min:1|max:300']);

        return response()->json(['data' => $logs->read($data['level'] ?? null, $data['search'] ?? null, (int) ($data['limit'] ?? 100)), 'levels' => SystemLogs::LEVELS]);
    }

    // ---------------------------------------------------------------- backups

    public function backupRemote(BackupTools $tools): JsonResponse
    {
        $this->guard();

        return response()->json(['data' => ['disk' => $tools->remoteDisk(), 'disks' => $tools->availableDisks()]]);
    }

    public function chooseBackupDisk(Request $request, BackupTools $tools): JsonResponse
    {
        $this->guard();
        $data = $request->validate(['disk' => 'nullable|string|max:60']);

        try {
            $tools->chooseDisk($data['disk'] ?? null);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['message' => 'Successfully Saved']);
    }

    public function copyBackup(int $id, BackupTools $tools): JsonResponse
    {
        $this->guard();
        $backup = DatabaseBackup::query()->findOrFail($id);
        $result = $tools->copyToRemote($backup);

        return response()->json(['message' => $result['ok'] ? 'Copied to the remote disk.' : 'Not copied: '.$result['error'], 'data' => $result], $result['ok'] ? 200 : 422);
    }

    public function restoreBackup(Request $request, int $id, BackupTools $tools): JsonResponse
    {
        $this->guard();
        $data = $request->validate(['confirm' => 'required|string|max:200']);
        $backup = DatabaseBackup::query()->findOrFail($id);
        $result = $tools->restore($backup, $data['confirm'], Auth::id());

        return response()->json(['message' => $result['ok'] ? 'The backup was restored. A backup of the data before it is '.$result['safety_backup'].'.' : 'Not restored: '.$result['error'], 'data' => $result], $result['ok'] ? 200 : 422);
    }

    // ---------------------------------------------------------------- web push keys

    public function push(WebPush $push): JsonResponse
    {
        $this->guard();

        return response()->json(['data' => [
            'public_key' => $push->keys()['public'] ?? null,
            'subject' => DB::table('system_settings')->where('key', WebPush::SUBJECT_SETTING)->value('value'),
            'subscriptions' => DB::table('push_subscriptions')->count(),
        ]]);
    }

    public function generatePushKeys(WebPush $push): JsonResponse
    {
        $this->guard();

        return response()->json(['message' => $push->keys() === null ? 'Keys generated.' : 'Keys already exist: the existing public key is unchanged.', 'data' => ['public_key' => $push->ensureKeys()]]);
    }

    public function pushSubject(Request $request): JsonResponse
    {
        $this->guard();
        $data = $request->validate(['subject' => ['required', 'string', 'max:200', 'regex:#^(mailto:[^\s@]+@[^\s@]+|https://[^\s]+)$#']]);
        DB::table('system_settings')->updateOrInsert(['key' => WebPush::SUBJECT_SETTING], ['value' => $data['subject'], 'updated_at' => now(), 'created_at' => now()]);

        return response()->json(['message' => 'Successfully Saved']);
    }

    // ---------------------------------------------------------------- demo companies

    public function demos(): JsonResponse
    {
        $this->guard();

        return response()->json(['data' => DB::table('companies')->where('code', 'like', DemoCompany::PREFIX.'%')->orderByDesc('id')->get(['id', 'code', 'name', 'is_active', 'created_at'])]);
    }

    public function createDemo(Request $request, DemoCompany $demo): JsonResponse
    {
        $this->guard();
        $data = $request->validate(['name' => 'nullable|string|max:80']);

        return response()->json(['message' => 'The demo company was created. Note the password: it is not shown again.', 'data' => $demo->create(trim((string) ($data['name'] ?? '')) ?: 'Demo Traders')], 201);
    }

    public function removeDemo(int $id, DemoCompany $demo): JsonResponse
    {
        $this->guard();
        abort_unless($demo->remove($id), 422, 'Only a demo company (code starting with DEMO-) can be removed here.');

        return response()->json(['message' => 'The demo company was removed.']);
    }

    // ---------------------------------------------------------------- franchise groups

    public function groups(): JsonResponse
    {
        $this->guard();

        return response()->json(['data' => DB::table('company_groups')->orderBy('name')->get()->map(fn (object $g): array => [
            'id' => (int) $g->id, 'name' => $g->name, 'owner_user_id' => (int) $g->owner_user_id,
            'company_ids' => DB::table('company_group_members')->where('company_group_id', $g->id)->pluck('company_id')->map(fn ($id): int => (int) $id)->all(),
        ])->all()]);
    }

    public function saveGroup(Request $request, FranchiseGroups $groups, ?int $id = null): JsonResponse
    {
        $this->guard();
        $data = $request->validate(['name' => 'required|string|min:2|max:120', 'owner_user_id' => 'required|integer', 'company_ids' => 'required|array|min:1|max:50', 'company_ids.*' => 'integer']);

        if ($id !== null) {
            abort_unless(DB::table('company_groups')->where('id', $id)->exists(), 404);
        }

        return response()->json(['message' => 'Successfully Saved', 'id' => $groups->save($id, strip_tags($data['name']), (int) $data['owner_user_id'], $data['company_ids'])], 201);
    }

    public function deleteGroup(int $id): JsonResponse
    {
        $this->guard();
        DB::table('company_group_members')->where('company_group_id', $id)->delete();
        DB::table('company_groups')->where('id', $id)->delete();

        return response()->json(['message' => 'Successfully Deleted']);
    }

    // ---------------------------------------------------------------- help articles

    public function articles(): JsonResponse
    {
        $this->guard();

        return response()->json(['data' => DB::table('help_articles')->orderBy('category')->orderBy('sort_order')->orderBy('title')->get()]);
    }

    public function saveArticle(Request $request, ?int $id = null): JsonResponse
    {
        $this->guard();
        $data = $request->validate(['title' => 'required|string|min:3|max:160', 'category' => 'nullable|string|max:60', 'body' => 'required|string|min:3|max:20000', 'is_published' => 'nullable|boolean', 'sort_order' => 'nullable|integer|min:0|max:9999']);
        $row = [
            'title' => strip_tags($data['title']), 'category' => strip_tags($data['category'] ?? 'general') ?: 'general', 'body' => strip_tags($data['body']),
            'is_published' => (bool) ($data['is_published'] ?? false), 'sort_order' => (int) ($data['sort_order'] ?? 0), 'updated_at' => now(),
        ];

        if ($id === null) {
            $slug = Str::slug($row['title']) ?: 'article';

            while (DB::table('help_articles')->where('slug', $slug)->exists()) {
                $slug = Str::slug($row['title']).'-'.Str::lower(Str::random(4));
            }

            $id = (int) DB::table('help_articles')->insertGetId($row + ['slug' => $slug, 'created_at' => now()]);
        } else {
            abort_unless(DB::table('help_articles')->where('id', $id)->exists(), 404);
            DB::table('help_articles')->where('id', $id)->update($row);
        }

        return response()->json(['message' => 'Successfully Saved', 'data' => DB::table('help_articles')->find($id)], 201);
    }

    public function deleteArticle(int $id): JsonResponse
    {
        $this->guard();
        DB::table('help_articles')->where('id', $id)->delete();

        return response()->json(['message' => 'Successfully Deleted']);
    }

    // ---------------------------------------------------------------- platform retention

    public function retention(): JsonResponse
    {
        $this->guard();

        return response()->json(['data' => DB::table('data_retention_rules')->whereNull('company_id')->get(), 'entities' => collect(DataRetention::ENTITIES)->map(fn (array $e, string $key): array => ['entity' => $key, 'label' => $e[2]])->values()->all(), 'min_days' => DataRetention::MIN_DAYS]);
    }

    public function saveRetention(Request $request): JsonResponse
    {
        $this->guard();
        $data = $request->validate(['entity' => ['required', Rule::in(array_keys(DataRetention::ENTITIES))], 'keep_days' => 'required|integer|min:'.DataRetention::MIN_DAYS.'|max:3650', 'archive' => 'nullable|boolean', 'is_active' => 'nullable|boolean']);

        DB::table('data_retention_rules')->updateOrInsert(
            ['company_id' => null, 'entity' => $data['entity']],
            ['keep_days' => $data['keep_days'], 'archive' => (bool) ($data['archive'] ?? true), 'is_active' => (bool) ($data['is_active'] ?? true), 'updated_at' => now(), 'created_at' => now()],
        );

        return response()->json(['message' => 'Successfully Saved']);
    }

    private function guard(): void
    {
        abort_unless(Auth::user()?->hasRole('superadmin'), 403);
    }
}
