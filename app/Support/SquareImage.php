<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use RuntimeException;

/**
 * Turns an uploaded picture into a centre-cropped square JPEG (GD only, no extra packages).
 * Used for user avatars and labour profile photos.
 */
class SquareImage
{
    /** @return string JPEG bytes */
    public static function fromUpload(UploadedFile $file, int $size, int $quality = 85): string
    {
        $raw = @file_get_contents($file->getRealPath());
        $src = $raw ? @imagecreatefromstring($raw) : false;
        if (! $src) {
            throw new RuntimeException('ไม่สามารถอ่านไฟล์รูปภาพได้ กรุณาใช้ไฟล์ JPG, PNG หรือ WEBP');
        }

        $src = self::applyExifOrientation($src, $file->getRealPath());

        $w = imagesx($src);
        $h = imagesy($src);
        $side = min($w, $h);
        $x = (int) (($w - $side) / 2);
        $y = (int) (($h - $side) / 2);

        $dst = imagecreatetruecolor($size, $size);
        imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255)); // transparent PNGs get a white background
        imagecopyresampled($dst, $src, 0, 0, $x, $y, $size, $size, $side, $side);
        imagedestroy($src);

        ob_start();
        $ok = imagejpeg($dst, null, $quality);
        $bytes = (string) ob_get_clean();
        imagedestroy($dst);

        if (! $ok || $bytes === '') {
            throw new RuntimeException('แปลงรูปภาพไม่สำเร็จ');
        }

        return $bytes;
    }

    /** Phone photos carry their rotation in EXIF; bake it in so the crop is upright. */
    private static function applyExifOrientation($img, string $path)
    {
        if (! function_exists('exif_read_data')) {
            return $img;
        }
        $exif = @exif_read_data($path);
        $rotated = match ((int) ($exif['Orientation'] ?? 1)) {
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
