<?php

namespace App\Services\Timeout;

abstract class SessionChecker
{
    /**
     * The session id.
     *
     * @var string
     */
    protected $sessionId;

    public function __construct($sessionId)
    {
        $this->sessionId = $sessionId;
    }

    abstract public function getLastModified();
}
