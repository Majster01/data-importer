<?php

/*
 * RequireToken.php
 * FireflyX fork of the Firefly III Data Importer. AGPL-3.0, see LICENSE.
 */

declare(strict_types=1);

namespace App\FireflyX\Http;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Upstream's own /api routes have no authentication. Ours carry bank sessions,
 * so every request needs the shared Bearer token.
 */
final class RequireToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('fireflyx.api_token');
        if (strlen($expected) < 32 || !hash_equals($expected, (string) $request->bearerToken())) {
            return response()->json(['error' => 'unauthorized'], 401);
        }

        return $next($request);
    }
}
