<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * A 422 on `contact_id`, so the sell and POS forms show it through their normal validation
 * handling, with the figures added to the JSON body for API clients.
 */
class CreditLimitExceededException extends ValidationException
{
    /**
     * @var array{credit_limit: float, current_balance: float, sale_amount: float, projected_balance: float}
     */
    public array $details = [
        'credit_limit' => 0.0,
        'current_balance' => 0.0,
        'sale_amount' => 0.0,
        'projected_balance' => 0.0,
    ];

    /**
     * Whether this is the warn-mode warning, which the user may pass by sending a reason, rather than a hard stop.
     */
    public bool $overridable = false;

    public static function forSale(string $customer, float $creditLimit, float $currentBalance, float $saleAmount, bool $warning = false): static
    {
        $projectedBalance = round($currentBalance + $saleAmount, 2);

        $exception = static::withMessages([
            'contact_id' => [sprintf(
                'Credit limit exceeded for %s: limit %s, current balance %s, this sale adds %s (would reach %s).',
                $customer,
                number_format($creditLimit, 2),
                number_format($currentBalance, 2),
                number_format($saleAmount, 2),
                number_format($projectedBalance, 2),
            )],
        ]);

        $exception->overridable = $warning;
        $exception->details = [
            'credit_limit' => $creditLimit,
            'current_balance' => $currentBalance,
            'sale_amount' => $saleAmount,
            'projected_balance' => $projectedBalance,
        ];

        return $exception;
    }

    public function render(Request $request): ?JsonResponse
    {
        if (! $request->is('api/*') && ! $request->expectsJson()) {
            return null;
        }

        return response()->json([
            'message' => $this->errors()['contact_id'][0],
            'errors' => $this->errors(),
            'code' => $this->overridable ? 'credit_limit_warning' : 'credit_limit_exceeded',
        ] + $this->details + ($this->overridable ? ['requires_override_reason' => true] : []), $this->status);
    }
}
