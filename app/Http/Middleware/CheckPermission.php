<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Usage: ->middleware('perm:labour.edit') or ->middleware('perm:labour.view,documents.view') (any of them).
 * MasterAdmin passes everything. Denied users go back to the dashboard with a message (JSON callers get 403).
 */
class CheckPermission
{
    public function handle(Request $request, Closure $next, string ...$permissions)
    {
        $user = $request->user();

        if ($user === null) {
            return redirect()->route('login');
        }

        foreach ($permissions as $permission) {
            if ($user->can($permission)) {
                return $next($request);
            }
        }

        if ($request->expectsJson()) {
            return response()->json(['message' => 'ไม่มีสิทธิ์เข้าถึง'], 403);
        }

        return redirect()->route('dashboard')->with('error', 'คุณไม่มีสิทธิ์ใช้งานส่วนนี้ (ต้องมีสิทธิ์ "' . \App\Support\Permissions::label($permissions[0] ?? '') . '")');
    }
}
