<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Server-to-server authentication for the worker portal (labour.importer168.com).
 * The portal sends the shared secret in the X-Portal-Key header; browsers never see it.
 */
class VerifyPortalKey
{
    public function handle(Request $request, Closure $next)
    {
        $expected = (string) config('services.portal.key');

        if ($expected === '') {
            return response()->json(['message' => 'PORTAL_API_KEY is not configured on the admin server.'], 503);
        }

        $given = (string) ($request->header('X-Portal-Key') ?: $request->bearerToken());

        if ($given === '' || ! hash_equals($expected, $given)) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        return $next($request);
    }
}
