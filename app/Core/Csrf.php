<?php

declare(strict_types=1);

namespace App\Core;

final class Csrf
{
    public static function token(): string
    {
        $token = Session::get('_csrf');
        if (!is_string($token)) {
            $token = bin2hex(random_bytes(32));
            Session::set('_csrf', $token);
        }
        return $token;
    }

    public static function check(Request $request): bool
    {
        $sent = $request->input('_csrf') ?? $request->header('X-CSRF-Token') ?? '';
        return is_string($sent) && hash_equals(self::token(), $sent);
    }
}
