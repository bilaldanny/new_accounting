<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class Currency extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'currency_name',
        'code',
        'symbol',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public static function normalizeCode(?string $code): string
    {
        return strtoupper(trim((string) $code));
    }

    public static function codeExists(string $code, ?int $exceptId = null): bool
    {
        return self::query()
            ->when($exceptId !== null, fn ($query) => $query->where('id', '!=', $exceptId))
            ->where('code', self::normalizeCode($code))
            ->exists();
    }

    /**
     * @throws ValidationException
     */
    public static function assertUniqueCode(string $code, ?int $exceptId = null): void
    {
        if (self::codeExists($code, $exceptId)) {
            throw ValidationException::withMessages([
                'code' => ['This currency code is already taken.'],
            ]);
        }
    }

    public static function storeFromRequest(Request $request): self
    {
        $code = self::normalizeCode($request->string('code')->toString());

        self::assertUniqueCode($code);

        return self::query()->create([
            'currency_name' => $request->string('currency_name')->toString(),
            'code' => $code,
            'symbol' => $request->string('symbol')->toString(),
            'is_active' => $request->boolean('is_active', true),
        ]);
    }

    public function updateFromRequest(Request $request): self
    {
        $code = self::normalizeCode($request->string('code')->toString());

        self::assertUniqueCode($code, $this->id);

        $this->update([
            'currency_name' => $request->string('currency_name')->toString(),
            'code' => $code,
            'symbol' => $request->string('symbol')->toString(),
            'is_active' => $request->boolean('is_active', (bool) $this->is_active),
        ]);

        return $this;
    }

    public static function deleteCurrency(int $id): void
    {
        $currency = self::query()->find($id);

        if ($currency !== null) {
            $currency->delete();
        }
    }
}
