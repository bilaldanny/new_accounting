<?php

namespace App\Services;

use App\Models\Project;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Validates the project a line is tagged with: it must be a project of the line's own company. A tag is a dimension only; it is
 * ignored (not an error) while the project tables are not installed, so deploying before migrating changes nothing.
 */
final class ProjectTag
{
    /** @var array<string, bool> */
    private static array $columns = [];

    public static function supported(string $table): bool
    {
        return self::$columns[$table] ??= Schema::hasTable('projects') && Schema::hasColumn($table, 'project_id');
    }

    /**
     * @throws ValidationException
     */
    public static function resolve(mixed $projectId, ?int $companyId, string $table): ?int
    {
        if ($projectId === null || $projectId === '' || $projectId === 'undefined' || ! self::supported($table)) {
            return null;
        }

        $id = (int) $projectId;

        if ($companyId === null || ! Project::query()->where('company_id', $companyId)->whereKey($id)->exists()) {
            throw ValidationException::withMessages(['project_id' => ['The project is not part of this company.']]);
        }

        return $id;
    }
}
