<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * A write that the period lock (or the back-dating / future-dating rule) refuses. A validation error, so every controller that
 * already turns ValidationException into a 422 and a rollback handles it unchanged; the response also says whether the user
 * may override it, so the screen can offer that.
 */
class PeriodLockedException extends ValidationException
{
    public function __construct(string $message, public readonly ?string $lockedThrough, public readonly bool $canOverride)
    {
        parent::__construct(validator([], []), null, 'default');

        $this->errors = ['period_lock' => [$message]];
        $this->message = $message;
    }

    /**
     * @var array<string, list<string>>
     */
    private array $errors;

    /**
     * @return array<string, list<string>>
     */
    public function errors(): array
    {
        return $this->errors;
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'errors' => $this->errors,
            'period_locked' => true,
            'locked_through' => $this->lockedThrough,
            'can_override' => $this->canOverride,
        ], 422);
    }
}
