<?php

namespace App\Services\Documents;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Which design a document prints with, and the rules for saving a design.
 *
 * Choosing: the template asked for by id (of the company), else the default of the branch for this document type, else the default of
 * the company (no branch), else the built-in design - so a company that never made a template prints exactly what the built-in design
 * gives and nothing changes for it. A default is one per company, branch (or none), document type and language: making a template the
 * default clears the flag on the others of the same kind.
 */
class DocumentTemplates
{
    public static function available(): bool
    {
        return Schema::hasTable('document_templates');
    }

    /**
     * @return array{id: int|null, name: string, blocks: list<array{type: string, props: array<string, mixed>}>, branding: array<string, string|int>, paper: string, language: string, source: string}
     */
    public function resolve(int $companyId, ?int $branchId, string $docType, ?string $language = null, ?int $templateId = null): array
    {
        $language = in_array($language, DocumentBlocks::LANGUAGES, true) ? $language : null;
        $row = null;

        if (self::available()) {
            $base = fn () => DB::table('document_templates')->where('company_id', $companyId)->where('doc_type', $docType)->where('is_active', true)->whereNull('deleted_at');

            if ($templateId !== null) {
                $row = $base()->where('id', $templateId)->first();
            }

            if ($row === null && $templateId === null) {
                foreach ([$branchId, null] as $candidateBranch) {
                    $query = $base()->where('is_default', true)->when($candidateBranch === null, fn ($q) => $q->whereNull('branch_id'), fn ($q) => $q->where('branch_id', $candidateBranch));
                    $row = ($language !== null ? (clone $query)->where('language', $language)->first() : null) ?? $query->orderByRaw("case when language = 'en' then 0 else 1 end")->first();

                    if ($row !== null) {
                        break;
                    }
                }
            }
        }

        if ($row === null) {
            return [
                'id' => null, 'name' => 'Built-in design', 'blocks' => DocumentBlocks::defaults($docType), 'branding' => DocumentBlocks::defaultBranding(),
                'paper' => 'a4', 'language' => $language ?? 'en', 'source' => 'built-in',
            ];
        }

        return [
            'id' => (int) $row->id, 'name' => (string) $row->name, 'blocks' => DocumentBlocks::normalise(json_decode((string) $row->blocks, true)),
            'branding' => DocumentBlocks::normaliseBranding(json_decode((string) $row->branding, true) ?: []), 'paper' => (string) $row->paper,
            'language' => $language ?? (string) $row->language, 'source' => 'template',
        ];
    }

    /**
     * Validates the fields of a template and returns the row values (without company, which the caller sets).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public function prepare(array $data): array
    {
        if (! in_array($data['doc_type'] ?? '', DocumentBlocks::DOC_TYPES, true)) {
            throw ValidationException::withMessages(['doc_type' => ['Choose a document type.']]);
        }

        return [
            'doc_type' => $data['doc_type'],
            'language' => in_array($data['language'] ?? 'en', DocumentBlocks::LANGUAGES, true) ? $data['language'] : 'en',
            'name' => DocumentBlocks::text($data['name'] ?? '', 120),
            'paper' => in_array($data['paper'] ?? 'a4', DocumentBlocks::PAPERS, true) ? $data['paper'] : 'a4',
            'blocks' => json_encode(DocumentBlocks::normalise($data['blocks'] ?? null)),
            'branding' => json_encode(DocumentBlocks::normaliseBranding($data['branding'] ?? [])),
        ];
    }

    /**
     * Makes one template the default of its kind (company, branch, document type, language).
     */
    public function makeDefault(int $id): void
    {
        $row = DB::table('document_templates')->where('id', $id)->first();

        if ($row === null) {
            return;
        }

        DB::table('document_templates')->where('company_id', $row->company_id)->where('doc_type', $row->doc_type)->where('language', $row->language)
            ->when($row->branch_id === null, fn ($q) => $q->whereNull('branch_id'), fn ($q) => $q->where('branch_id', $row->branch_id))
            ->where('id', '!=', $id)->update(['is_default' => false, 'updated_at' => now()]);
        DB::table('document_templates')->where('id', $id)->update(['is_default' => true, 'is_active' => true, 'updated_at' => now()]);
    }
}
