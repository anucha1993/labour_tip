<?php

namespace App\Http\Controllers\member;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Permissions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * หน้า "โปรไฟล์สิทธิ์": one profile (role) at a time, each module a switch + View/Create/Edit/Delete picks,
 * plus adding / renaming / deleting profiles. MasterAdmin is shown locked (always everything). Needs admin.roles.
 */
class RoleController extends Controller
{
    private const KEY_RULE = 'regex:/^[A-Za-z][A-Za-z0-9_]{1,29}$/';

    public function __construct()
    {
        $this->middleware('auth');
    }

    /** The profile page: a list of profiles on the left, the selected profile's modules on the right. */
    public function index(Request $request, ?string $key = null)
    {
        $roles = Permissions::roles();
        $counts = User::query()->select('type', DB::raw('COUNT(*) AS c'))->groupBy('type')->pluck('c', 'type');
        foreach ($roles as $k => &$r) {
            $r['users'] = (int) ($counts[$k] ?? 0);
            $r['permissions'] = Permissions::forRole($k);
        }
        unset($r);
        $key = $key ?? $request->query('profile');
        if ($key !== null && ! isset($roles[$key])) {
            abort(404);
        }
        $current = $roles[$key ?? array_key_first($roles)];
        // Users whose profile no longer exists (deleted by hand): show them so they can be fixed on the members page.
        $orphans = $counts->keys()->diff(array_keys($roles))->values();

        return view('roles.index', [
            'roles' => $roles,
            'current' => $current,
            'groups' => Permissions::groups(),
            'orphans' => $orphans,
            'ready' => Permissions::tableReady(),
            'master' => Permissions::MASTER,
        ]);
    }

    /** Save one profile's permissions: perms[] = permission keys. */
    public function update(Request $request, string $key)
    {
        if ($r = $this->notReady()) {
            return $r;
        }
        $roles = Permissions::roles();
        abort_unless(isset($roles[$key]), 404);
        if ($key === Permissions::MASTER) {
            return back()->with('error', 'โปรไฟล์ ' . Permissions::roleName($key) . ' มีทุกสิทธิ์เสมอ แก้ไขไม่ได้');
        }
        $data = $request->validate([
            'perms' => ['nullable', 'array'],
            'perms.*' => ['string', Rule::in(Permissions::all())],
        ]);
        $perms = array_values(array_unique($data['perms'] ?? []));
        // Never let someone lock themselves out of this page.
        if ($request->user()->type === $key && ! in_array('admin.roles', $perms, true)) {
            $perms[] = 'admin.roles';
        }

        DB::transaction(function () use ($key, $perms) {
            DB::table('role_permissions')->where('role_key', $key)->delete();
            if ($perms) {
                DB::table('role_permissions')->insert(array_map(fn ($p) => ['role_key' => $key, 'permission' => $p], $perms));
            }
        });
        Permissions::forget();

        return redirect()->route('roles.index', $key)->with('success', 'บันทึกสิทธิ์ของโปรไฟล์ ' . Permissions::roleName($key) . ' แล้ว (' . count($perms) . ' สิทธิ์) มีผลกับผู้ใช้ในโปรไฟล์นี้ทันที');
    }

    /** Add a role, optionally copying another role's permissions. */
    public function store(Request $request)
    {
        if ($r = $this->notReady()) {
            return $r;
        }
        $roles = Permissions::roles();
        $data = $request->validate([
            'key' => ['required', 'string', self::KEY_RULE, Rule::notIn(array_keys($roles)), 'unique:roles,key'],
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:255'],
            'copy_from' => ['nullable', 'string', Rule::in(array_keys($roles))],
        ], [
            'key.required' => 'กรุณาระบุรหัสโปรไฟล์',
            'key.regex' => 'รหัสโปรไฟล์ต้องเป็นอักษรอังกฤษ/ตัวเลข/ขีดล่าง ขึ้นต้นด้วยตัวอักษร ยาว 2-30 ตัว เช่น Head หรือ HR_Staff',
            'key.not_in' => 'มีรหัสโปรไฟล์นี้อยู่แล้ว',
            'key.unique' => 'มีรหัสโปรไฟล์นี้อยู่แล้ว',
            'name.required' => 'กรุณาระบุชื่อโปรไฟล์',
        ]);

        $copy = $data['copy_from'] ?? null;
        $perms = $copy ? array_values(array_diff(Permissions::forRole($copy), ['admin.roles'])) : [];
        DB::transaction(function () use ($data, $perms) {
            $sort = (int) DB::table('roles')->max('sort') + 10;
            DB::table('roles')->insert([
                'key' => $data['key'], 'name' => $data['name'], 'description' => $data['description'] ?? null,
                'is_system' => false, 'sort' => $sort, 'created_at' => now(), 'updated_at' => now(),
            ]);
            if ($perms) {
                DB::table('role_permissions')->insert(array_map(fn ($p) => ['role_key' => $data['key'], 'permission' => $p], $perms));
            }
        });
        Permissions::forget();

        return redirect()->route('roles.index', $data['key'])->with('success', 'สร้างโปรไฟล์ "' . $data['name'] . '" แล้ว' . ($copy ? ' (คัดลอกสิทธิ์จาก ' . Permissions::roleName($copy) . ')' : '') . ' เปิด/ปิดสิทธิ์ที่ต้องการแล้วกดบันทึก');
    }

    /** Rename / re-describe a role (system roles too; their key never changes). */
    public function rename(Request $request, string $key)
    {
        if ($r = $this->notReady()) {
            return $r;
        }
        abort_unless(isset(Permissions::roles()[$key]), 404);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:255'],
        ], ['name.required' => 'กรุณาระบุชื่อโปรไฟล์']);

        DB::table('roles')->where('key', $key)->update(['name' => $data['name'], 'description' => $data['description'] ?? null, 'updated_at' => now()]);
        Permissions::forget();

        return redirect()->route('roles.index', $key)->with('success', 'แก้ไขโปรไฟล์ ' . $key . ' แล้ว');
    }

    /** Put a built-in role back to the rights it shipped with. */
    public function reset(string $key)
    {
        if ($r = $this->notReady()) {
            return $r;
        }
        $role = Permissions::roles()[$key] ?? null;
        abort_unless($role && $role['is_system'] && $key !== Permissions::MASTER, 404);

        DB::transaction(function () use ($key) {
            DB::table('role_permissions')->where('role_key', $key)->delete();
            DB::table('role_permissions')->insert(array_map(fn ($p) => ['role_key' => $key, 'permission' => $p], Permissions::defaults($key)));
        });
        Permissions::forget();

        return redirect()->route('roles.index', $key)->with('success', 'คืนค่าเริ่มต้นของโปรไฟล์ ' . Permissions::roleName($key) . ' แล้ว');
    }

    /** Delete a custom role nobody uses. */
    public function destroy(string $key)
    {
        if ($r = $this->notReady()) {
            return $r;
        }
        $role = Permissions::roles()[$key] ?? null;
        abort_unless($role, 404);
        if ($role['is_system']) {
            return back()->with('error', 'ลบโปรไฟล์พื้นฐานของระบบไม่ได้');
        }
        $users = User::where('type', $key)->count();
        if ($users > 0) {
            return back()->with('error', 'ยังมีผู้ใช้ ' . $users . ' คนอยู่ในโปรไฟล์นี้ กรุณาย้ายไปโปรไฟล์อื่นในหน้าสมาชิกก่อน');
        }

        DB::transaction(function () use ($key) {
            DB::table('role_permissions')->where('role_key', $key)->delete();
            DB::table('roles')->where('key', $key)->delete();
        });
        Permissions::forget();

        return redirect()->route('roles.index')->with('success', 'ลบโปรไฟล์ ' . $role['name'] . ' แล้ว');
    }

    private function notReady()
    {
        if (Permissions::tableReady()) {
            return null;
        }

        return back()->with('error', 'ยังแก้สิทธิ์ไม่ได้ กรุณารัน php artisan migrate ก่อน (migration 2026_11_06_000001) ระหว่างนี้ระบบใช้สิทธิ์ค่าเริ่มต้น');
    }
}
