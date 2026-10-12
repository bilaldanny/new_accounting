<?php

namespace App\Services\System;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * The branch hierarchy of a company (head office, regions, shops). A branch has at most one parent inside the same company, a branch
 * can never be placed under itself or under one of its own descendants, and a branch with children cannot be deleted by the tree
 * itself. Only the tree is new: every report, permission and number sequence still works per branch exactly as before.
 */
class BranchTree
{
    public static function available(): bool
    {
        return Schema::hasColumn('branches', 'parent_id');
    }

    /**
     * @return list<array{id: int, name: string, code: string, parent_id: int|null, is_active: bool, depth: int, children: list<int>}>
     */
    public function flat(int $companyId): array
    {
        $rows = DB::table('branches')->where('company_id', $companyId)->whereNull('deleted_at')->orderBy('name')->get(['id', 'name', 'code', 'is_active', 'parent_id']);
        $byParent = $rows->groupBy(fn (object $row): string => (string) ($row->parent_id ?? ''));
        $result = [];
        $walk = function (string $parent, int $depth) use (&$walk, $byParent, &$result): void {
            foreach ($byParent[$parent] ?? [] as $row) {
                $result[] = [
                    'id' => (int) $row->id, 'name' => (string) $row->name, 'code' => (string) $row->code, 'parent_id' => $row->parent_id === null ? null : (int) $row->parent_id,
                    'is_active' => (bool) $row->is_active, 'depth' => $depth, 'children' => ($byParent[(string) $row->id] ?? collect())->pluck('id')->map(fn ($id): int => (int) $id)->all(),
                ];
                $walk((string) $row->id, $depth + 1);
            }
        };

        // roots, and any branch whose parent was deleted
        $known = $rows->pluck('id')->map(fn ($id): string => (string) $id)->all();
        $orphans = $rows->filter(fn (object $row): bool => $row->parent_id !== null && ! in_array((string) $row->parent_id, $known, true));

        $walk('', 0);

        foreach ($orphans as $row) {
            $result[] = ['id' => (int) $row->id, 'name' => (string) $row->name, 'code' => (string) $row->code, 'parent_id' => null, 'is_active' => (bool) $row->is_active, 'depth' => 0, 'children' => []];
            $walk((string) $row->id, 1);
        }

        return $result;
    }

    /**
     * The ids of a branch and everything below it.
     *
     * @return list<int>
     */
    public function subtree(int $companyId, int $branchId): array
    {
        $ids = [$branchId];
        $rows = DB::table('branches')->where('company_id', $companyId)->whereNull('deleted_at')->pluck('parent_id', 'id');
        $changed = true;

        while ($changed) {
            $changed = false;

            foreach ($rows as $id => $parent) {
                if ($parent !== null && in_array((int) $parent, $ids, true) && ! in_array((int) $id, $ids, true)) {
                    $ids[] = (int) $id;
                    $changed = true;
                }
            }
        }

        return $ids;
    }

    /**
     * Places a branch under another (or at the top with null).
     *
     * @throws ValidationException
     */
    public function move(int $companyId, int $branchId, ?int $parentId): void
    {
        if (! DB::table('branches')->where('company_id', $companyId)->where('id', $branchId)->whereNull('deleted_at')->exists()) {
            abort(404);
        }

        if ($parentId !== null) {
            if (! DB::table('branches')->where('company_id', $companyId)->where('id', $parentId)->whereNull('deleted_at')->exists()) {
                throw ValidationException::withMessages(['parent_id' => ['Choose a branch of this company.']]);
            }

            if (in_array($parentId, $this->subtree($companyId, $branchId), true)) {
                throw ValidationException::withMessages(['parent_id' => ['A branch cannot be placed under itself or under one of its own branches.']]);
            }
        }

        DB::table('branches')->where('id', $branchId)->update(['parent_id' => $parentId, 'updated_at' => now()]);
    }
}
