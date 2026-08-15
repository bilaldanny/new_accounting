<?php

namespace App\Models;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class Menu extends Model
{
    use SoftDeletes;

    protected $fillable = ['parent_id', 'name', 'icon', 'route_name', 'route_path', 'menu_color', 'sort_order', 'type', 'is_active', 'is_hidden', 'is_admin', 'is_permission'];

    protected function isActive(): Attribute
    {
        return Attribute::make(
            // Getter: Return true if the value is 1
            get: fn ($value) => $value === 1,

            // Setter: Convert true/false to 1/0
            set: fn ($value) => ($value === 'false') ? 0 : 1,
        );
    }

    protected function isHidden(): Attribute
    {
        return Attribute::make(
            // Getter: Return true if the value is 1
            get: fn ($value) => $value === 1,

            // Setter: Convert true/false to 1/0
            set: fn ($value) => ($value === 'false') ? 0 : 1,
        );
    }

    protected function isPermission(): Attribute
    {
        return Attribute::make(
            // Getter: Return true if the value is 1
            get: fn ($value) => $value === 1,

            // Setter: Convert true/false to 1/0
            set: fn ($value) => ($value === 'false') ? 0 : 1,
        );
    }

    protected function isAdmin(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => $value === 1,
            set: fn ($value) => ($value === 'false') ? 0 : 1,
        );
    }

    protected function parentId(): Attribute
    {
        return Attribute::make(
            // Getter: Return an empty string if the value is null
            get: fn ($value) => $value === null ? '' : $value
        );
    }

    public function children()
    {
        return $this->hasMany(self::class, 'parent_id', 'id')
            ->whereColumn('menus.id', '!=', 'menus.parent_id');
    }

    /**
     * @return HasMany<Permission, $this>
     */
    public function permission(): HasMany
    {
        return $this->hasMany(Permission::class, 'menu_id', 'id')
            ->where('role_id', '=', auth()->user()->role_id);
    }

    /**
     * @return list<string>
     */
    public static function permittedRoutePathsForRole(int $roleId): array
    {
        return self::permittedMenusQuery($roleId)
            ->whereNotNull('route_path')
            ->where('route_path', '!=', '')
            ->pluck('route_path')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    public static function permittedRouteNamesForRole(int $roleId): array
    {
        return self::permittedMenusQuery($roleId)
            ->whereNotNull('route_name')
            ->where('route_name', '!=', '')
            ->pluck('route_name')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return Builder<self>
     */
    protected static function permittedMenusQuery(int $roleId): Builder
    {
        if ($roleId === 1) {
            return self::query()->where('is_active', 1);
        }

        $permittedMenuIds = Permission::query()
            ->where('role_id', $roleId)
            ->where('status', 1)
            ->pluck('menu_id');

        return self::query()
            ->where('is_active', 1)
            ->where(function ($query) use ($permittedMenuIds): void {
                $query->whereIn('id', $permittedMenuIds)
                    ->orWhereIn('route_path', ['checkout', 'support']);
            });
    }

    /**
     * Build a nested sidebar menu tree for the given role.
     *
     * @return list<array<string, mixed>>
     */
    public static function sidebarMenusForRole(int $roleId): array
    {
        $roots = self::query()
            ->select('route_path AS my_route', 'is_active AS status', 'menus.*')
            ->where('is_active', 1)
            ->whereNull('parent_id')
            ->orderBy('sort_order')
            ->with(['children' => self::sidebarChildrenRelation()])
            ->get();

        if ($roleId === 1) {
            return $roots
                ->map(fn (self $menu): array => self::formatSidebarMenu($menu))
                ->values()
                ->all();
        }

        $permittedIds = self::expandedPermittedMenuIdsForRole($roleId);

        return $roots
            ->map(fn (self $menu): ?array => self::filterSidebarMenuBranch($menu, $permittedIds))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * Include ancestor menus for every permitted menu item.
     *
     * @return list<int>
     */
    public static function expandedPermittedMenuIdsForRole(int $roleId): array
    {
        $permitted = Permission::query()
            ->where('role_id', $roleId)
            ->where('status', 1)
            ->pluck('menu_id')
            ->all();

        $alwaysIncluded = self::query()
            ->whereIn('route_path', ['checkout', 'support'])
            ->pluck('id')
            ->all();

        $menus = self::query()->get(['id', 'parent_id'])->keyBy('id');
        $expanded = [];

        foreach (array_merge($permitted, $alwaysIncluded) as $menuId) {
            $currentId = (int) $menuId;

            while ($currentId) {
                $expanded[$currentId] = true;
                $menu = $menus->get($currentId);

                if ($menu === null || ! $menu->parent_id) {
                    break;
                }

                $parentId = (int) $menu->parent_id;

                if ($parentId === $currentId) {
                    break;
                }

                $currentId = $parentId;
            }
        }

        return array_map('intval', array_keys($expanded));
    }

    /**
     * Menus available on the role-permission assignment screen for the given assigner role.
     */
    public static function permissionMenusForAssigner(int $assignerRoleId): Collection
    {
        $menus = self::query()
            ->with('children.children')
            ->where('is_active', 1)
            ->select('name as text', 'menus.*')
            ->get();

        if ($assignerRoleId === 1) {
            return $menus;
        }

        $permittedIds = self::expandedPermittedMenuIdsForRole($assignerRoleId);

        return $menus
            ->filter(fn (self $menu): bool => in_array($menu->id, $permittedIds, true))
            ->map(fn (self $menu): ?self => self::filterPermissionMenuNode($menu, $permittedIds))
            ->filter()
            ->values();
    }

    /**
     * Whether the assigner role may toggle permissions for a menu branch.
     */
    public static function assignerCanManageMenu(int $assignerRoleId, int $menuId): bool
    {
        if ($assignerRoleId === 1) {
            return true;
        }

        $menu = self::query()->with('children.children')->find($menuId);

        if ($menu === null) {
            return false;
        }

        $permittedIds = self::expandedPermittedMenuIdsForRole($assignerRoleId);

        foreach (self::collectMenuTreeIds($menu) as $id) {
            if (! in_array($id, $permittedIds, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return list<int>
     */
    public static function collectMenuTreeIds(self $menu): array
    {
        $ids = [(int) $menu->id];

        if ($menu->relationLoaded('children')) {
            foreach ($menu->children as $child) {
                $ids = array_merge($ids, self::collectMenuTreeIds($child));
            }
        }

        return $ids;
    }

    /**
     * @param  list<int>  $permittedIds
     */
    protected static function filterPermissionMenuNode(self $menu, array $permittedIds): ?self
    {
        if (! in_array($menu->id, $permittedIds, true)) {
            return null;
        }

        if ($menu->relationLoaded('children')) {
            $filteredChildren = $menu->children
                ->map(fn (self $child): ?self => self::filterPermissionMenuNode($child, $permittedIds))
                ->filter()
                ->values();

            $menu->setRelation('children', $filteredChildren);
        }

        return $menu;
    }

    /**
     * @return Closure(mixed): void
     */
    protected static function sidebarChildrenRelation(): Closure
    {
        return function ($query): void {
            $query
                ->select('route_path AS my_route', 'is_active AS status', 'menus.*')
                ->where('is_active', 1)
                ->whereColumn('menus.id', '!=', 'menus.parent_id')
                ->orderBy('sort_order')
                ->with(['children' => self::sidebarChildrenRelation()]);
        };
    }

    /**
     * @param  list<int>  $permittedIds
     * @return array<string, mixed>|null
     */
    protected static function filterSidebarMenuBranch(self $menu, array $permittedIds): ?array
    {
        if (! in_array($menu->id, $permittedIds, true)) {
            return null;
        }

        $formatted = self::formatSidebarMenu($menu);

        if ($menu->relationLoaded('children')) {
            $formatted['children'] = $menu->children
                ->map(fn (self $child): ?array => self::filterSidebarMenuBranch($child, $permittedIds))
                ->filter()
                ->values()
                ->all();
        }

        return $formatted;
    }

    /**
     * @return array<string, mixed>
     */
    protected static function formatSidebarMenu(self $menu): array
    {
        $array = $menu->toArray();
        $array['my_route'] = $array['my_route'] ?? $menu->route_path;
        $array['status'] = (int) ($array['status'] ?? $menu->getAttributes()['is_active'] ?? 0);

        if ($menu->relationLoaded('children')) {
            $array['children'] = $menu->children
                ->map(fn (self $child): array => self::formatSidebarMenu($child))
                ->values()
                ->all();
        }

        return $array;
    }

    public static function CreateMenu($request)
    {
        $menu = new Menu;
        $menu->parent_id = $request->parent_id;
        $menu->name = $request->name;
        $menu->route_name = strtolower(str_replace(' ', '', $request->route_name));
        $menu->icon = $request->icon;
        $menu->route_path = $request->route_path;
        $menu->menu_color = ($request->menu_color) ?? '#6a0dad';
        if (isset($request->sort_order)) {
            $menu->sort_order = $request->sort_order;
        } else {
            $menu->sort_order = 0;
        }
        $menu->type = $request->type;
        $menu->is_active = $request->is_active;
        $menu->is_hidden = $request->is_hidden;
        $menu->is_admin = $request->is_admin ?? 0;
        $menu->is_permission = $request->is_permission;
        $menu->save();

        return $menu;
    }

    public static function UpdateMenu($request, $id)
    {
        $menu = Menu::find($id);
        $menu->parent_id = ($request->parent_id) ?? null;
        $menu->name = $request->name;
        $menu->route_name = strtolower(str_replace(' ', '', $request->route_name));
        $menu->icon = $request->icon;
        $menu->route_path = $request->route_path;
        $menu->menu_color = ($request->menu_color) ?? '#6a0dad';
        if (isset($request->sort_order)) {
            $menu->sort_order = $request->sort_order;
        } else {
            $menu->sort_order = 0;
        }
        $menu->type = $request->type;
        $menu->is_active = $request->is_active;
        $menu->is_hidden = $request->is_hidden;
        $menu->is_admin = $request->is_admin ?? 0;
        $menu->is_permission = $request->is_permission;
        $menu->save();

        return $menu;
    }

    public static function DeleteMenu($id)
    {
        $menu = Menu::find($id);
        if (isset($menu)) {
            $menu->delete();
        }
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public static function upsertFromImport(array $row): string
    {
        $id = self::normalizeImportId($row['id'] ?? null);
        $payload = self::buildImportPayload($row);

        if ($id !== null) {
            $menu = self::query()->find($id);

            if ($menu === null) {
                throw ValidationException::withMessages([
                    'rows' => ["Menu with id {$id} was not found."],
                ]);
            }

            self::UpdateMenu($payload, $id);

            return 'updated';
        }

        self::CreateMenu($payload);

        return 'created';
    }

    protected static function normalizeImportId(mixed $id): ?int
    {
        if ($id === null || $id === '' || $id === 0 || $id === '0') {
            return null;
        }

        if (is_numeric($id)) {
            $normalized = (int) $id;

            return $normalized > 0 ? $normalized : null;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    protected static function buildImportPayload(array $row): object
    {
        $parentId = $row['parent_id'] ?? null;

        if ($parentId === '' || $parentId === 0 || $parentId === '0') {
            $parentId = null;
        }

        return (object) [
            'parent_id' => $parentId,
            'name' => (string) ($row['name'] ?? ''),
            'type' => $row['type'] ?? 1,
            'route_path' => (string) ($row['route_path'] ?? ''),
            'route_name' => (string) ($row['route_name'] ?? ''),
            'icon' => (string) ($row['icon'] ?? 'Grid'),
            'menu_color' => (string) ($row['menu_color'] ?? '#6a0dad'),
            'sort_order' => isset($row['sort_order']) && $row['sort_order'] !== '' ? (int) $row['sort_order'] : 0,
            'is_active' => self::normalizeImportBool($row['is_active'] ?? 1),
            'is_hidden' => self::normalizeImportBool($row['is_hidden'] ?? 0),
            'is_admin' => self::normalizeImportBool($row['is_admin'] ?? 0),
            'is_permission' => self::normalizeImportBool($row['is_permission'] ?? 0),
        ];
    }

    protected static function normalizeImportBool(mixed $value): int
    {
        if ($value === true || $value === 1 || $value === '1' || $value === 'true') {
            return 1;
        }

        return 0;
    }
}
