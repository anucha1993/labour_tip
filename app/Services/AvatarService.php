<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use RuntimeException;

/**
 * Profile pictures: resized to a 256×256 JPEG with GD and kept in public/uploads/avatars
 * (served as a static file, no storage:link needed).
 */
class AvatarService
{
    public const SIZE = 256;

    public static function directory(): string
    {
        return public_path('uploads/avatars');
    }

    public static function url(?string $file): ?string
    {
        return $file ? asset('uploads/avatars/' . $file) : null;
    }

    /** Stores the picture for the user and returns the new file name. */
    public function store(User $user, UploadedFile $file): string
    {
        $raw = @file_get_contents($file->getRealPath());
        $src = $raw ? @imagecreatefromstring($raw) : false;
        if (! $src) {
            throw new RuntimeException('ไม่สามารถอ่านไฟล์รูปภาพได้ กรุณาใช้ไฟล์ JPG, PNG หรือ WEBP');
        }

        $src = $this->applyExifOrientation($src, $file->getRealPath());

        $w = imagesx($src);
        $h = imagesy($src);
        $side = min($w, $h);
        $x = (int) (($w - $side) / 2);
        $y = (int) (($h - $side) / 2);

        $dst = imagecreatetruecolor(self::SIZE, self::SIZE);
        $white = imagecolorallocate($dst, 255, 255, 255);
        imagefill($dst, 0, 0, $white); // transparent PNGs get a white background
        imagecopyresampled($dst, $src, 0, 0, $x, $y, self::SIZE, self::SIZE, $side, $side);
        imagedestroy($src);

        $dir = self::directory();
        if (! is_dir($dir) && ! @mkdir($dir, 0775, true) && ! is_dir($dir)) {
            throw new RuntimeException('ไม่สามารถสร้างโฟลเดอร์ ' . $dir);
        }

        $name = 'u' . $user->id . '-' . bin2hex(random_bytes(6)) . '.jpg';
        if (! imagejpeg($dst, $dir . DIRECTORY_SEPARATOR . $name, 85)) {
            imagedestroy($dst);
            throw new RuntimeException('บันทึกรูปไม่สำเร็จ');
        }
        imagedestroy($dst);

        $this->remove($user);
        $user->avatar = $name;
        $user->save();

        return $name;
    }

    /** Deletes the current picture file (if any) and clears the column. */
    public function remove(User $user): void
    {
        if ($user->avatar) {
            $path = self::directory() . DIRECTORY_SEPARATOR . basename($user->avatar);
            if (is_file($path)) {
                @unlink($path);
            }
            $user->avatar = null;
            $user->save();
        }
    }

    /** Phone photos carry their rotation in EXIF; bake it in so the crop is upright. */
    private function applyExifOrientation($img, string $path)
    {
        if (! function_exists('exif_read_data')) {
            return $img;
        }
        $exif = @exif_read_data($path);
        $o = (int) ($exif['Orientation'] ?? 1);
        $rotated = match ($o) {
            3 => imagerotate($img, 180, 0),
            6 => imagerotate($img, -90, 0),
            8 => imagerotate($img, 90, 0),
            default => null,
        };
        if ($rotated) {
            imagedestroy($img);

            return $rotated;
        }

        return $img;
    }
}
