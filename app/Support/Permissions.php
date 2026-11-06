<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * สิทธิ์การใช้งาน: the list of every permission the app checks, grouped per menu, with the defaults
 * each built-in role had before permissions became editable.
 *
 * Which role holds which permission lives in the `roles` / `role_permissions` tables (page "สิทธิ์การใช้งาน").
 * Until those tables exist (php artisan migrate) the defaults below apply, so the app behaves exactly
 * as before. MasterAdmin always has every permission and cannot be edited.
 */
class Permissions
{
    public const MASTER = 'MasterAdmin';
    public const CACHE_KEY = 'permissions.map';

    /** Built-in roles: key => [name, description]. */
    public const SYSTEM_ROLES = [
        'MasterAdmin' => ['ผู้ดูแลระบบสูงสุด', 'ทำได้ทุกอย่าง รวมจัดการสมาชิกและสิทธิ์ แก้ไขสิทธิ์ของโปรไฟล์นี้ไม่ได้'],
        'Admin' => ['ผู้ดูแลระบบ', 'เพิ่ม/แก้ไขข้อมูลและเอกสาร แต่ลบไม่ได้'],
        'Member' => ['สมาชิก', 'ดูข้อมูล ออกรายงาน และถามผู้ช่วย AI'],
    ];

    /**
     * Every permission, grouped per module (one row on the profile page). Keys are what the code checks
     * (routes, Blade, services); each item is [short action label, what it allows].
     *
     * @return array<string, array{label: string, icon: string, items: array<string, array{0: string, 1: string}>}>
     */
    public static function groups(): array
    {
        return [
            'labour' => ['label' => 'แรงงานต่างด้าว', 'icon' => 'mdi-account-multiple', 'items' => [
                'labour.view' => ['ดู', 'ดูรายชื่อและรายละเอียดแรงงาน'],
                'labour.create' => ['เพิ่ม', 'เพิ่มแรงงานใหม่'],
                'labour.edit' => ['แก้ไข', 'แก้ไขข้อมูลแรงงาน และ Mass Update วันหมดอายุ'],
                'labour.import' => ['นำเข้า Excel', 'นำเข้าแรงงานจากไฟล์ Excel'],
                'labour.delete' => ['ลบ', 'ลบแรงงาน'],
            ]],
            'company' => ['label' => 'บริษัท / นายจ้าง', 'icon' => 'mdi-domain', 'items' => [
                'company.view' => ['ดู', 'ดูรายชื่อบริษัท'],
                'company.create' => ['เพิ่ม', 'เพิ่มบริษัท'],
                'company.edit' => ['แก้ไข', 'แก้ไขบริษัท'],
                'company.delete' => ['ลบ', 'ลบบริษัท'],
            ]],
            'agency' => ['label' => 'เอเจนซี่', 'icon' => 'mdi-earth', 'items' => [
                'agency.view' => ['ดู', 'ดูรายชื่อเอเจนซี่'],
                'agency.create' => ['เพิ่ม', 'เพิ่มเอเจนซี่'],
                'agency.edit' => ['แก้ไข', 'แก้ไขเอเจนซี่'],
                'agency.delete' => ['ลบ', 'ลบเอเจนซี่'],
            ]],
            'report' => ['label' => 'รายงาน', 'icon' => 'mdi-file-chart', 'items' => [
                'report.view' => ['ดู / ส่งออก', 'ออกรายงานและส่งออก Excel/PDF (รวมรายการเอกสารใกล้หมดอายุ)'],
            ]],
            'documents' => ['label' => 'คลังเอกสาร', 'icon' => 'mdi-folder', 'items' => [
                'documents.view' => ['ดู', 'เปิดคลังเอกสารและดูไฟล์'],
                'documents.download' => ['ดาวน์โหลด zip', 'ดาวน์โหลดเป็น zip (เลือกหลายรายการ)'],
                'documents.upload' => ['อัปโหลด', 'อัปโหลดไฟล์เอกสาร'],
                'documents.rename' => ['แก้ไข', 'เปลี่ยนชื่อเอกสาร'],
                'documents.share' => ['แชร์', 'แชร์โฟลเดอร์เป็นลิงก์ และจัดการลิงก์ที่แชร์'],
                'documents.delete' => ['ลบ', 'ลบไฟล์เอกสาร'],
            ]],
            'ai' => ['label' => 'ผู้ช่วย AI', 'icon' => 'mdi-robot', 'items' => [
                'ai.chat' => ['ถาม', 'ถามผู้ช่วย AI (อ่านข้อมูล อ่านไฟล์ ออกรายงาน)'],
                'ai.update' => ['แก้ไขข้อมูล', 'แก้ไขข้อมูลแรงงานผ่านผู้ช่วย AI (ยืนยัน 2 ขั้น)'],
                'ai.pay' => ['ชำระเงิน', 'เห็นปุ่มชำระเงิน/ต่อแพ็กเกจ AI'],
            ]],
            'ai_logs' => ['label' => 'ประวัติการใช้ AI', 'icon' => 'mdi-comment-text-outline', 'items' => [
                'ai.logs' => ['ดู', 'ดูประวัติการใช้ AI ของทุกคน และให้คะแนนคำตอบของคนอื่น'],
            ]],
            'settings' => ['label' => 'ตั้งค่าวันแจ้งเตือน', 'icon' => 'mdi-bell-ring', 'items' => [
                'admin.settings' => ['ตั้งค่า', 'ตั้งค่าจำนวนวันแจ้งเตือนเอกสารใกล้หมดอายุ'],
            ]],
            'users' => ['label' => 'สมาชิก / ผู้ใช้งาน', 'icon' => 'mdi-account-key', 'items' => [
                'admin.users' => ['จัดการ', 'เพิ่ม แก้ไข ลบสมาชิก และเปลี่ยนโปรไฟล์สิทธิ์ของสมาชิก'],
            ]],
            'roles' => ['label' => 'โปรไฟล์สิทธิ์', 'icon' => 'mdi-shield-account', 'items' => [
                'admin.roles' => ['จัดการ', 'สร้างโปรไฟล์และกำหนดสิทธิ์ (หน้านี้)'],
            ]],
            'logs' => ['label' => 'ประวัติการแก้ไข', 'icon' => 'mdi-history', 'items' => [
                'admin.logs' => ['ดู', 'ดูประวัติการแก้ไขข้อมูล (action logs)'],
            ]],
        ];
    }

    /** @return string[] every permission key */
    public static function all(): array
    {
        $out = [];
        foreach (self::groups() as $g) {
            $out = array_merge($out, array_keys($g['items']));
        }

        return $out;
    }

    /** What one permission allows (long text). */
    public static function label(string $permission): string
    {
        foreach (self::groups() as $g) {
            if (isset($g['items'][$permission])) {
                return $g['label'] . ': ' . $g['items'][$permission][1];
            }
        }

        return $permission;
    }

    /** Short action name of one permission (ดู / เพิ่ม / แก้ไข / ลบ …). */
    public static function action(string $permission): string
    {
        foreach (self::groups() as $g) {
            if (isset($g['items'][$permission])) {
                return $g['items'][$permission][0];
            }
        }

        return $permission;
    }

    /** What each built-in role could do before permissions became editable. */
    public static function defaults(string $role): array
    {
        $member = ['labour.view', 'company.view', 'agency.view', 'report.view', 'documents.view', 'documents.download', 'ai.chat'];
        $admin = array_merge($member, [
            'labour.create', 'labour.edit', 'labour.import',
            'company.create', 'company.edit',
            'agency.create', 'agency.edit',
            'documents.upload', 'documents.rename', 'documents.share',
            'admin.settings',
        ]);

        return match ($role) {
            self::MASTER => self::all(),
            'Admin' => $admin,
            'Member' => $member,
            default => [],
        };
    }

    // ------------------------------------------------------------------ checks

    /** Does this role hold the permission? MasterAdmin always does. */
    public static function has(?string $role, string $permission): bool
    {
        if ($role === self::MASTER) {
            return true;
        }

        return in_array($permission, self::map()[$role] ?? [], true);
    }

    /** Permissions of one role (MasterAdmin: all). */
    public static function forRole(?string $role): array
    {
        return $role === self::MASTER ? self::all() : (self::map()[$role] ?? []);
    }

    /**
     * Roles in display order: key => ['key', 'name', 'description', 'is_system', 'users' => null].
     *
     * @return array<string, array>
     */
    public static function roles(): array
    {
        return self::state()['roles'];
    }

    public static function roleName(?string $role): string
    {
        return self::roles()[$role]['name'] ?? ($role ?: '-');
    }

    /** True once the tables from migration 2026_11_06_000001 exist. */
    public static function tableReady(): bool
    {
        return self::state()['ready'];
    }

    /** Forget the cached matrix (after the roles page saves). */
    public static function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
        app()->forgetInstance('permissions.state');
    }

    /** role => [permissions] for every non-master role */
    private static function map(): array
    {
        return self::state()['map'];
    }

    /**
     * Roles + matrix, read once per request; from the tables when they exist, else the built-in defaults.
     * Cached in the cache store for an hour (cleared whenever the roles page saves).
     */
    private static function state(): array
    {
        if (app()->bound('permissions.state')) {
            return app('permissions.state');
        }

        $state = Cache::remember(self::CACHE_KEY, 3600, function () {
            try {
                $ready = Schema::hasTable('roles') && Schema::hasTable('role_permissions');
            } catch (\Throwable $e) {
                $ready = false;
            }
            if (! $ready) {
                return ['ready' => false, 'roles' => self::defaultRoles(), 'map' => self::defaultMap()];
            }

            $roles = [];
            foreach (DB::table('roles')->orderBy('sort')->orderBy('key')->get() as $r) {
                $roles[$r->key] = ['key' => $r->key, 'name' => $r->name, 'description' => $r->description, 'is_system' => (bool) $r->is_system];
            }
            $map = [];
            foreach (DB::table('role_permissions')->get() as $p) {
                if (isset($roles[$p->role_key]) && $p->role_key !== self::MASTER) {
                    $map[$p->role_key][] = $p->permission;
                }
            }
            // A built-in role missing from the table (e.g. deleted by hand) still has its defaults.
            foreach (self::SYSTEM_ROLES as $key => [$name, $desc]) {
                if (! isset($roles[$key])) {
                    $roles[$key] = ['key' => $key, 'name' => $name, 'description' => $desc, 'is_system' => true];
                    $map[$key] = self::defaults($key);
                }
            }

            return ['ready' => true, 'roles' => $roles, 'map' => $map];
        });

        app()->instance('permissions.state', $state);

        return $state;
    }

    public static function defaultRoles(): array
    {
        $out = [];
        foreach (self::SYSTEM_ROLES as $key => [$name, $desc]) {
            $out[$key] = ['key' => $key, 'name' => $name, 'description' => $desc, 'is_system' => true];
        }

        return $out;
    }

    private static function defaultMap(): array
    {
        return ['Admin' => self::defaults('Admin'), 'Member' => self::defaults('Member')];
    }
}
