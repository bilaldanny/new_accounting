<?php

namespace App\Services\Dashboard;

use App\Http\Controllers\DashboardInsightsController;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Which dashboard cards a user sees and in which order, by the first of: their own saved layout (Dashboard Insights), the layout the
 * company set for their role, the built-in layout for the kind of role (owner, accountant, sales manager, warehouse incharge,
 * cashier, found from the role name), and every card. A layout only chooses among cards: a card still needs its menu permission, so
 * no layout can show a figure the user may not open.
 */
class DashboardLayouts
{
    /**
     * Built-in layouts: the cards each kind of role starts with.
     *
     * @var array<string, list<string>>
     */
    public const PRESETS = [
        'owner' => ['sales', 'financial', 'receivables', 'approvals', 'forecast', 'trends', 'inventory', 'cheques', 'aging', 'movement', 'stats', 'recent'],
        'accountant' => ['financial', 'receivables', 'aging', 'cheques', 'approvals', 'trends', 'forecast', 'recent'],
        'sales_manager' => ['sales', 'receivables', 'forecast', 'trends', 'approvals', 'recent', 'stats', 'aging'],
        'warehouse' => ['inventory', 'movement', 'approvals', 'stats'],
        'cashier' => ['sales', 'recent', 'cheques', 'stats'],
    ];

    public static function available(): bool
    {
        return Schema::hasTable('dashboard_role_layouts');
    }

    /**
     * The kind of preset a role name stands for, or null.
     */
    public static function kindFor(?string $roleName): ?string
    {
        $name = Role::normalizeName($roleName);

        return match (true) {
            $name === '' => null,
            str_contains($name, 'companyadmin') || str_contains($name, 'owner') => 'owner',
            str_contains($name, 'accountant') || str_contains($name, 'finance') => 'accountant',
            str_contains($name, 'warehouse') || str_contains($name, 'storekeeper') || str_contains($name, 'inventory') => 'warehouse',
            str_contains($name, 'cashier') => 'cashier',
            str_contains($name, 'salesmanager') || str_contains($name, 'manager') => 'sales_manager',
            default => null,
        };
    }

    /**
     * @return array{order: list<string>, hidden: list<string>, source: string, kind: string|null}
     */
    public function effective(User $user): array
    {
        $all = DashboardInsightsController::WIDGETS;
        $personal = DB::table('dashboard_preferences')->where('user_id', $user->id)->value('layout');

        if ($personal !== null) {
            $layout = (array) json_decode((string) $personal, true);

            return ['order' => $this->known((array) ($layout['order'] ?? $all)), 'hidden' => $this->known((array) ($layout['hidden'] ?? [])), 'source' => 'personal', 'kind' => null];
        }

        if (self::available() && $user->company_id !== null && $user->role_id !== null) {
            $row = DB::table('dashboard_role_layouts')->where('company_id', $user->company_id)->where('role_id', $user->role_id)->value('layout');

            if ($row !== null) {
                return $this->fromShown((array) json_decode((string) $row, true), 'role', null);
            }
        }

        $roleName = $user->role_id === null ? null : (string) Role::query()->whereKey($user->role_id)->value('name');
        $kind = self::kindFor($roleName);

        if ($kind !== null) {
            return $this->fromShown(['shown' => self::PRESETS[$kind]], 'preset', $kind);
        }

        return ['order' => $all, 'hidden' => [], 'source' => 'default', 'kind' => null];
    }

    /**
     * @param  list<string>  $shown
     */
    public function saveForRole(int $companyId, int $roleId, array $shown): void
    {
        DB::table('dashboard_role_layouts')->updateOrInsert(
            ['company_id' => $companyId, 'role_id' => $roleId],
            ['layout' => json_encode(['shown' => array_values(array_unique($this->known($shown)))]), 'updated_at' => now(), 'created_at' => now()],
        );
    }

    /**
     * @param  array<string, mixed>  $layout
     * @return array{order: list<string>, hidden: list<string>, source: string, kind: string|null}
     */
    private function fromShown(array $layout, string $source, ?string $kind): array
    {
        $shown = $this->known((array) ($layout['shown'] ?? []));
        $all = DashboardInsightsController::WIDGETS;

        return ['order' => array_values(array_merge($shown, array_diff($all, $shown))), 'hidden' => array_values(array_diff($all, $shown)), 'source' => $source, 'kind' => $kind];
    }

    /**
     * @param  array<int, mixed>  $values
     * @return list<string>
     */
    private function known(array $values): array
    {
        return array_values(array_filter(array_map('strval', $values), fn (string $v): bool => in_array($v, DashboardInsightsController::WIDGETS, true)));
    }
}
