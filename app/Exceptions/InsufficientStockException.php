<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * A POS sale that would sell more than is in stock. It is a 422 on `selllines`, so the POS shows it
 * through its normal validation handling, with the shortages added to the JSON body.
 */
class InsufficientStockException extends ValidationException
{
    /**
     * @var list<array{product_id: int, variation_id: int, product_name: string, requested: float, available: float}>
     */
    public array $shortages = [];

    /**
     * @param  list<array{product_id: int, variation_id: int, product_name: string, requested: float, available: float}>  $shortages
     */
    public static function forShortages(array $shortages): static
    {
        $first = $shortages[0];
        $more = count($shortages) - 1;

        $exception = static::withMessages([
            'selllines' => [sprintf(
                'Insufficient stock for %s: requested %s, available %s%s.',
                $first['product_name'] !== '' ? $first['product_name'] : 'product #'.$first['product_id'],
                self::quantity($first['requested']),
                self::quantity($first['available']),
                $more > 0 ? " (and {$more} more item".($more === 1 ? '' : 's').')' : '',
            )],
        ]);

        $exception->shortages = $shortages;

        return $exception;
    }

    public function render(Request $request): ?JsonResponse
    {
        if (! $request->is('api/*') && ! $request->expectsJson()) {
            return null;
        }

        return response()->json([
            'message' => $this->errors()['selllines'][0],
            'errors' => $this->errors(),
            'code' => 'insufficient_stock',
            'shortages' => $this->shortages,
        ], $this->status);
    }

    private static function quantity(float $value): string
    {
        return rtrim(rtrim(number_format($value, 4, '.', ''), '0'), '.');
    }
}
