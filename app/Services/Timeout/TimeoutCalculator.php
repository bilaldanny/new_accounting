<?php

namespace App\Services\Timeout;

use Illuminate\Http\Request;

class TimeoutCalculator
{
    /**
     * Calculates the number of seconds left before session expires.
     *
     * @return int
     */
    public static function getSecondsLeft(Request $request)
    {
        // Read session id from cookie without touching session() (avoids resetting last activity on page load).
        $sessionCookieName = config('session.cookie');
        $sessionId = $request->cookie($sessionCookieName);

        if (! $sessionId) {
            throw new TimeoutCalculatorException('Not logged in');
        }

        switch (config('session.driver')) {
            case 'database':    $checker = new DatabaseSessionChecker($sessionId);
                break;
            case 'file':        $checker = new FileSessionChecker($sessionId);
                break;
            default:            throw new TimeoutCalculatorException('Session driver not supported');
        }

        $secondsSince = time() - $checker->getLastModified();
        $secondsLeft = config('session.lifetime') * 60 - $secondsSince;

        return max(0, $secondsLeft);
    }
}
