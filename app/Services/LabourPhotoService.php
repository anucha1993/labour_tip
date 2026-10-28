<?php

namespace App\Services;

use App\Models\labour\LabourDocument;
use App\Models\labour\LabourModel;
use App\Support\SquareImage;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Labour profile photo: a 512×512 JPEG stored on the same disk as the labour documents
 * (local "public" or Cloudflare R2) under photos/, recorded in labour.labour_photo(+_disk).
 */
class LabourPhotoService
{
    public const SIZE = 512;

    /** Called after a labour is saved: apply the uploaded picture or the "remove" checkbox. */
    public function sync(Request $request, LabourModel $labour): void
    {
        $file = $request->file('labour_photo');
        if ($file instanceof UploadedFile && $file->isValid()) {
            $this->store($labour, $file);
        } elseif ($request->boolean('remove_photo')) {
            $this->remove($labour);
        }
    }

    public function store(LabourModel $labour, UploadedFile $file): string
    {
        $bytes = SquareImage::fromUpload($file, self::SIZE);
        $disk = LabourDocument::targetDisk();
        $path = 'photos/' . $labour->labour_id . '_' . Str::lower(Str::random(8)) . '.jpg';

        Storage::disk($disk)->put($path, $bytes, ['visibility' => 'private']);

        $this->remove($labour, false);
        $labour->forceFill(['labour_photo' => $path, 'labour_photo_disk' => $disk])->saveQuietly();

        return $path;
    }

    public function remove(LabourModel $labour, bool $save = true): void
    {
        if (! $labour->labour_photo) {
            return;
        }
        try {
            Storage::disk($labour->labour_photo_disk ?: 'public')->delete($labour->labour_photo);
        } catch (\Throwable $e) {
            // a missing file must not block the update
        }
        $labour->labour_photo = null;
        $labour->labour_photo_disk = null;
        if ($save) {
            $labour->saveQuietly();
        }
    }

    public function exists(LabourModel $labour): bool
    {
        if (! $labour->labour_photo) {
            return false;
        }
        try {
            return Storage::disk($labour->labour_photo_disk ?: 'public')->exists($labour->labour_photo);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** Inline image response: R2 gets a short-lived signed URL, local files stream through the app. */
    public function response(LabourModel $labour)
    {
        $diskName = $labour->labour_photo_disk ?: 'public';
        $disk = Storage::disk($diskName);
        $driver = config('filesystems.disks.' . $diskName . '.driver');

        if ($driver === 's3') {
            return redirect()->away($disk->temporaryUrl($labour->labour_photo, now()->addMinutes(15)));
        }

        return $disk->response($labour->labour_photo, 'photo.jpg', ['Cache-Control' => 'private, max-age=3600'], 'inline');
    }
}
