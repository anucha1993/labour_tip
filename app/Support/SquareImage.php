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

        return self::square(self::applyExifOrientation($src, $file->getRealPath()), $size, $quality);
    }

    /**
     * Same as fromUpload() for image bytes already in memory (e.g. a photo retouched by the AI).
     * $contain = fit the whole picture inside the square on white instead of cropping (keeps the full head of a portrait).
     */
    public static function fromBytes(string $raw, int $size, int $quality = 85, bool $contain = false): string
    {
        $src = $raw !== '' ? @imagecreatefromstring($raw) : false;
        if (! $src) {
            throw new RuntimeException('ไม่สามารถอ่านไฟล์รูปภาพได้');
        }

        return self::square($src, $size, $quality, $contain);
    }

    private static function square($src, int $size, int $quality, bool $contain = false): string
    {
        $w = imagesx($src);
        $h = imagesy($src);
        $dst = imagecreatetruecolor($size, $size);
        imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255)); // transparent PNGs get a white background
        if ($contain) {
            $scale = $size / max($w, $h);
            [$dw, $dh] = [(int) round($w * $scale), (int) round($h * $scale)];
            imagecopyresampled($dst, $src, (int) (($size - $dw) / 2), (int) (($size - $dh) / 2), 0, 0, $dw, $dh, $w, $h);
        } else {
            $side = min($w, $h);
            imagecopyresampled($dst, $src, 0, 0, (int) (($w - $side) / 2), (int) (($h - $side) / 2), $size, $size, $side, $side);
        }
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
