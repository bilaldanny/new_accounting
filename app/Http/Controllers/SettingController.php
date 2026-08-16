<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SettingController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('company/setting');
    }

    public function email_template(): Response
    {
        abort(404);
    }

    public function email_test_send(Request $request): JsonResponse
    {
        abort(404);
    }
}
