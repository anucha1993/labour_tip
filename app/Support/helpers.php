<?php

use Carbon\Carbon;

if (! function_exists('is_blank_date')) {
    /**
     * True when a DB date value is empty or the MySQL zero date.
     */
    function is_blank_date($value): bool
    {
        if ($value === null || $value === '') {
            return true;
        }
        $value = (string) $value;

        return $value === '0000-00-00' || str_starts_with($value, '0000-00-00') || str_starts_with($value, '1899-12-30');
    }
}

if (! function_exists('fmt_date')) {
    /**
     * Format a date for display. Returns "-" for empty values instead of 01/01/1970.
     */
    function fmt_date($value, string $format = 'd/m/Y', string $empty = '-'): string
    {
        if (is_blank_date($value)) {
            return $empty;
        }
        try {
            return Carbon::parse($value)->format($format);
        } catch (\Throwable $e) {
            return $empty;
        }
    }
}

if (! function_exists('fmt_datetime')) {
    function fmt_datetime($value, string $empty = '-'): string
    {
        return fmt_date($value, 'd/m/Y H:i', $empty);
    }
}

if (! function_exists('days_left')) {
    /**
     * Whole days from today until the given date. Negative when already past. Null when empty.
     */
    function days_left($value): ?int
    {
        if (is_blank_date($value)) {
            return null;
        }
        try {
            return (int) Carbon::today()->diffInDays(Carbon::parse($value)->startOfDay(), false);
        } catch (\Throwable $e) {
            return null;
        }
    }
}

if (! function_exists('days_left_text')) {
    function days_left_text($value): string
    {
        $days = days_left($value);
        if ($days === null) {
            return '-';
        }
        if ($days < 0) {
            return 'หมดอายุแล้ว ' . abs($days) . ' วัน';
        }
        if ($days === 0) {
            return 'หมดอายุวันนี้';
        }

        return 'เหลือ ' . $days . ' วัน';
    }
}

if (! function_exists('days_left_level')) {
    /**
     * Severity bucket for a date: expired | critical | warning | ok | none.
     */
    function days_left_level($value, int $warnDays = 30): string
    {
        $days = days_left($value);
        if ($days === null) {
            return 'none';
        }
        if ($days < 0) {
            return 'expired';
        }
        if ($days <= 7) {
            return 'critical';
        }
        if ($days <= $warnDays) {
            return 'warning';
        }

        return 'ok';
    }
}

if (! function_exists('age_text')) {
    function age_text($birthday): string
    {
        if (is_blank_date($birthday)) {
            return '-';
        }
        try {
            $d = Carbon::parse($birthday);
            $diff = $d->diff(Carbon::today());

            return $diff->y . ' ปี ' . $diff->m . ' เดือน';
        } catch (\Throwable $e) {
            return '-';
        }
    }
}

if (! function_exists('status_job_label')) {
    function status_job_label($status): string
    {
        return [
            'job' => 'ทำงาน',
            'resign' => 'ลาออก',
            'escape' => 'หลบหนี',
        ][$status] ?? ($status ?: '-');
    }
}

if (! function_exists('sex_label')) {
    function sex_label($sex): string
    {
        return [
            'male' => 'ชาย',
            'man' => 'ชาย',
            'female' => 'หญิง',
        ][$sex] ?? '-';
    }
}

if (! function_exists('enable_label')) {
    function enable_label($status): string
    {
        return $status === 'enable' ? 'เปิดใช้งาน' : 'ปิดใช้งาน';
    }
}

if (! function_exists('role_label')) {
    function role_label($type): string
    {
        return [
            'MasterAdmin' => 'ผู้ดูแลระบบสูงสุด',
            'Admin' => 'ผู้ดูแลระบบ',
            'Member' => 'สมาชิก',
        ][$type] ?? ($type ?: '-');
    }
}

if (! function_exists('user_is')) {
    /**
     * user_is('MasterAdmin') or user_is('Admin', 'MasterAdmin').
     */
    function user_is(string ...$roles): bool
    {
        $user = auth()->user();

        return $user !== null && in_array($user->type, $roles, true);
    }
}

if (! function_exists('tis620')) {
    /**
     * Convert UTF-8 to TIS-620 for FPDF without throwing on characters outside the charset.
     */
    function tis620($text): string
    {
        $text = (string) $text;
        $out = @iconv('UTF-8', 'TIS-620//TRANSLIT//IGNORE', $text);
        if ($out === false) {
            $out = @iconv('UTF-8', 'TIS-620//IGNORE', $text);
        }

        return $out === false ? '' : $out;
    }
}
