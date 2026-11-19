<?php

namespace App\Http\Controllers;

use App\Support\Api;
use Illuminate\Http\Request;

abstract class Controller
{
    protected function user(Request $request): array
    {
        return $request->attributes->get('user') ?? [];
    }

    protected function ok(array $data = [], int $status = 200)
    {
        return Api::ok($data, $status);
    }

    protected function error(string $message, int $status = 400, array $extra = [])
    {
        return Api::error($message, $status, $extra);
    }
}
