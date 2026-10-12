<?php

namespace App\Services\Documents;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Custom fields a company adds to its documents and records: definitions (key, label, type, options, required, shown on print) and the
 * value of each for one record. The entity decides which table the record is in; a value can only be written for a record of the
 * company that owns the field, and is checked against the field's type (a select value must be one of its options). Values print on
 * the document through the `custom_fields` block.
 */
class CustomFields
{
    public const ENTITIES = ['sale', 'purchase', 'contact', 'product'];

    public const TYPES = ['text', 'number', 'date', 'select', 'checkbox'];

    /**
     * Entity => [table, extra where].
     *
     * @var array<string, array{0: string, 1: array<string, string>}>
     */
    private const RECORDS = [
        'sale' => ['transactions', ['type' => 'sell']],
        'purchase' => ['transactions', ['type' => 'purchaseorder']],
        'contact' => ['contacts', []],
        'product' => ['products', []],
    ];

    public static function available(): bool
    {
        return Schema::hasTable('custom_field_definitions') && Schema::hasTable('custom_field_values');
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function definitions(int $companyId, ?string $entity = null, bool $onlyActive = false): array
    {
        return DB::table('custom_field_definitions')->where('company_id', $companyId)
            ->when($entity !== null, fn ($q) => $q->where('entity', $entity))
            ->when($onlyActive, fn ($q) => $q->where('is_active', true))
            ->orderBy('entity')->orderBy('sort_order')->orderBy('id')->get()
            ->map(fn (object $row): array => $this->present($row))->all();
    }

    /**
     * Creates or updates a definition. The key is fixed once created.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public function save(int $companyId, array $data, ?int $id = null): array
    {
        $entity = (string) ($data['entity'] ?? '');
        $type = (string) ($data['type'] ?? 'text');
        $label = DocumentBlocks::text($data['label'] ?? '', 120);

        if (! in_array($entity, self::ENTITIES, true) || ! in_array($type, self::TYPES, true) || mb_strlen($label) < 2) {
            throw ValidationException::withMessages(['label' => ['Choose where the field lives, its type and a label.']]);
        }

        $options = $type === 'select'
            ? array_values(array_unique(array_filter(array_map(fn ($option): string => DocumentBlocks::text($option, 60), (array) ($data['options'] ?? [])), fn (string $option): bool => $option !== '')))
            : [];

        if ($type === 'select' && $options === []) {
            throw ValidationException::withMessages(['options' => ['A select field needs at least one option.']]);
        }

        $values = [
            'label' => $label, 'type' => $type, 'options' => $options === [] ? null : json_encode($options), 'is_required' => (bool) ($data['is_required'] ?? false),
            'show_on_print' => (bool) ($data['show_on_print'] ?? true), 'is_active' => (bool) ($data['is_active'] ?? true), 'sort_order' => (int) ($data['sort_order'] ?? 0), 'updated_at' => now(),
        ];

        if ($id === null) {
            $key = preg_replace('/[^a-z0-9_]+/', '_', strtolower(trim((string) ($data['field_key'] ?? $label)))) ?? '';
            $key = trim($key, '_');

            if ($key === '' || DB::table('custom_field_definitions')->where('company_id', $companyId)->where('entity', $entity)->where('field_key', $key)->exists()) {
                throw ValidationException::withMessages(['field_key' => ['This field key is empty or already used for this record type.']]);
            }

            $id = (int) DB::table('custom_field_definitions')->insertGetId($values + ['company_id' => $companyId, 'entity' => $entity, 'field_key' => mb_substr($key, 0, 60), 'created_at' => now()]);
        } else {
            DB::table('custom_field_definitions')->where('company_id', $companyId)->where('id', $id)->update($values);
        }

        return $this->present(DB::table('custom_field_definitions')->find($id));
    }

    public function delete(int $companyId, int $id): void
    {
        DB::table('custom_field_values')->where('company_id', $companyId)->where('field_id', $id)->delete();
        DB::table('custom_field_definitions')->where('company_id', $companyId)->where('id', $id)->delete();
    }

    /**
     * The fields of a record with their current values (key => value), every active field of the entity listed.
     *
     * @return list<array<string, mixed>>
     */
    public function valuesFor(int $companyId, string $entity, int $entityId): array
    {
        $values = DB::table('custom_field_values')->where('company_id', $companyId)->where('entity', $entity)->where('entity_id', $entityId)->pluck('value', 'field_id');

        return collect($this->definitions($companyId, $entity, true))->map(fn (array $field): array => $field + ['value' => $values[$field['id']] ?? null])->all();
    }

    /**
     * Writes the values of a record: `$values` is field key => value; a key that is not an active field of the company is refused.
     *
     * @param  array<string, mixed>  $values
     *
     * @throws ValidationException
     */
    public function setValues(int $companyId, string $entity, int $entityId, array $values): void
    {
        if (! array_key_exists($entity, self::RECORDS)) {
            throw ValidationException::withMessages(['entity' => ['Unknown record type.']]);
        }

        [$table, $where] = self::RECORDS[$entity];
        $exists = DB::table($table)->where('company_id', $companyId)->where('id', $entityId)->where($where)->exists();

        if (! $exists) {
            abort(404);
        }

        $fields = collect($this->definitions($companyId, $entity, true))->keyBy('field_key');
        $errors = [];

        foreach ($values as $key => $value) {
            if (! $fields->has($key)) {
                $errors["values.{$key}"] = ['This custom field does not exist.'];

                continue;
            }

            $field = $fields[$key];
            $text = is_bool($value) ? ($value ? '1' : '0') : trim((string) (is_scalar($value) ? $value : ''));

            if ($text === '' && $field['is_required']) {
                $errors["values.{$key}"] = ['This field is required.'];
            } elseif ($text !== '' && $field['type'] === 'number' && ! is_numeric($text)) {
                $errors["values.{$key}"] = ['Enter a number.'];
            } elseif ($text !== '' && $field['type'] === 'date' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $text) !== 1) {
                $errors["values.{$key}"] = ['Enter a date as YYYY-MM-DD.'];
            } elseif ($text !== '' && $field['type'] === 'select' && ! in_array($text, $field['options'], true)) {
                $errors["values.{$key}"] = ['Choose one of the listed options.'];
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        foreach ($values as $key => $value) {
            $field = $fields[$key];
            $text = is_bool($value) ? ($value ? '1' : '0') : mb_substr(trim((string) $value), 0, 500);

            if ($field['type'] === 'checkbox') {
                $text = in_array($text, ['1', 'true', 'on', 'yes'], true) ? '1' : '0';
            }

            DB::table('custom_field_values')->updateOrInsert(
                ['entity' => $entity, 'entity_id' => $entityId, 'field_id' => $field['id']],
                ['company_id' => $companyId, 'value' => $text === '' ? null : DocumentBlocks::text($text, 500), 'updated_at' => now(), 'created_at' => now()],
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function present(object $row): array
    {
        return [
            'id' => (int) $row->id, 'entity' => $row->entity, 'field_key' => $row->field_key, 'label' => $row->label, 'type' => $row->type,
            'options' => $row->options === null ? [] : (array) json_decode((string) $row->options, true), 'is_required' => (bool) $row->is_required,
            'show_on_print' => (bool) $row->show_on_print, 'is_active' => (bool) $row->is_active, 'sort_order' => (int) $row->sort_order,
        ];
    }
}
