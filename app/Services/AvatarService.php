<?php

namespace App\Services;

use App\Models\User;
use App\Support\SquareImage;
use Illuminate\Http\UploadedFile;
use RuntimeException;

/**
 * User profile pictures: 256×256 JPEG kept in public/uploads/avatars
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
        $bytes = SquareImage::fromUpload($file, self::SIZE);

        $dir = self::directory();
        if (! is_dir($dir) && ! @mkdir($dir, 0775, true) && ! is_dir($dir)) {
            throw new RuntimeException('ไม่สามารถสร้างโฟลเดอร์ ' . $dir);
        }

        $name = 'u' . $user->id . '-' . bin2hex(random_bytes(6)) . '.jpg';
        if (file_put_contents($dir . DIRECTORY_SEPARATOR . $name, $bytes) === false) {
            throw new RuntimeException('บันทึกรูปไม่สำเร็จ');
        }

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
}
