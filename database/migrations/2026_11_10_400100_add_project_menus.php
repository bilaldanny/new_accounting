<?php

use App\Support\MenuMigration;
use Illuminate\Database\Migrations\Migration;

/**
 * Menu rows for Projects (job costing master, tagging and profitability), in the group of Cost Centers, granted to the global
 * companyadmin role.
 */
return new class extends Migration
{
    /**
     * @return list<array{0: string, 1: string, 2: string, 3: string, 4: string, 5: bool}>
     */
    private function rows(): array
    {
        return [
            ['sibling:/costcenter', 'Projects', '/project', 'project', 'FolderKanban', false],
            ['/project', 'Add Project', '/project/add', 'project.add', 'Grid', true],
            ['/project', 'Edit Project', '/project/:id/edit', 'project.edit', 'Grid', true],
            ['/project', 'Delete Project', '/project/delete', 'project.delete', 'Grid', true],
            ['/project', 'Tag Documents To A Project', '/project/tag', 'project.tag', 'Grid', true],
        ];
    }

    public function up(): void
    {
        MenuMigration::install($this->rows());
    }

    public function down(): void
    {
        MenuMigration::remove(array_column($this->rows(), 2));
    }
};
