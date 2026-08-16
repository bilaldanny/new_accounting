<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class Timezone extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name',
    ];

    public static function normalizeName(?string $name): string
    {
        return trim((string) $name);
    }

    public static function nameExists(string $name, ?int $exceptId = null): bool
    {
        return self::query()
            ->when($exceptId !== null, fn ($query) => $query->where('id', '!=', $exceptId))
            ->where('name', self::normalizeName($name))
            ->exists();
    }

    /**
     * @throws ValidationException
     */
    public static function assertUniqueName(string $name, ?int $exceptId = null): void
    {
        if (self::nameExists($name, $exceptId)) {
            throw ValidationException::withMessages([
                'name' => ['A timezone with this name already exists.'],
            ]);
        }
    }

    public static function storeFromRequest(Request $request): self
    {
        $name = self::normalizeName($request->string('name')->toString());

        self::assertUniqueName($name);

        return self::query()->create([
            'name' => $name,
        ]);
    }

    public function updateFromRequest(Request $request): self
    {
        $name = self::normalizeName($request->string('name')->toString());

        self::assertUniqueName($name, $this->id);

        $this->update([
            'name' => $name,
        ]);

        return $this;
    }

    public static function deleteTimezone(int $id): void
    {
        $timezone = self::query()->find($id);

        if ($timezone !== null) {
            $timezone->delete();
        }
    }
}
