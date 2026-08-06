<?php

namespace App\Services\Timeout;

class FileSessionChecker extends SessionChecker
{
    public function getLastModified()
    {
        $sessionFile = storage_path("framework/sessions/$this->sessionId");
        // dd($this->sessionId);
        if (! file_exists($sessionFile)) {
            throw new TimeoutCalculatorException('Session not found');
        }

        return filemtime($sessionFile);
    }
}
