<?php

namespace App\Http\Controllers;

abstract class Controller
{
    protected function authorizeMenuPermission(string $key): void
    {
        abortUnlessMenuPermission($key);
    }
}
