<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * MasterAdmin-only gate (route alias "type"). Kept for backwards compatibility; prefer "role:...".
 */
class IsAdmin
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();

        if ($user === null) {
            return redirect()->route('login');
        }

        if ($user->type !== 'MasterAdmin') {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'ไม่มีสิทธิ์เข้าถึง'], 403);
            }

            return redirect()->route('dashboard')->with('error', 'เฉพาะผู้ดูแลระบบสูงสุดเท่านั้นที่ใช้งานส่วนนี้ได้');
        }

        return $next($request);
    }
}
