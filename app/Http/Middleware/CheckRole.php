<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Usage: ->middleware('role:MasterAdmin') or ->middleware('role:Admin,MasterAdmin')
 */
class CheckRole
{
    public function handle(Request $request, Closure $next, string ...$roles)
    {
        $user = $request->user();

        if ($user === null) {
            return redirect()->route('login');
        }

        if (! in_array($user->type, $roles, true)) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'ไม่มีสิทธิ์เข้าถึง'], 403);
            }

            return redirect()->route('dashboard')->with('error', 'คุณไม่มีสิทธิ์เข้าถึงหน้านี้');
        }

        return $next($request);
    }
}
