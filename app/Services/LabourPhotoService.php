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
 * (local "public" or Cloudflare R2) under photos/, recorded in labour.labour_photo(+_disk),
 * plus the upload itself at full resolution under photos/originals/ (labour.labour_photo_original) for printing.
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
        $name = $labour->labour_id . '_' . Str::lower(Str::random(8));
        $path = 'photos/' . $name . '.jpg';
        // the upload byte for byte (full resolution, not cropped) — printed on official documents, used for retouching
        $original = 'photos/originals/' . $name . '.' . (strtolower($file->getClientOriginalExtension()) === 'png' ? 'png' : (strtolower($file->getClientOriginalExtension()) === 'webp' ? 'webp' : 'jpg'));

        Storage::disk($disk)->put($path, $bytes, ['visibility' => 'private']);
        Storage::disk($disk)->put($original, (string) file_get_contents($file->getRealPath()), ['visibility' => 'private']);

        $this->remove($labour, false);
        $labour->forceFill(['labour_photo' => $path, 'labour_photo_disk' => $disk, 'labour_photo_original' => $original])->saveQuietly();

        return $path;
    }

    /** Bytes of the best source for printing / retouching: the full original when kept, else the 512 thumbnail. */
    public function sourceBytes(LabourModel $labour): ?array
    {
        $disk = Storage::disk($labour->labour_photo_disk ?: 'public');
        foreach (array_filter([$labour->labour_photo_original, $labour->labour_photo]) as $path) {
            try {
                if ($disk->exists($path)) {
                    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

                    return ['bytes' => (string) $disk->get($path), 'mime' => $ext === 'png' ? 'image/png' : ($ext === 'webp' ? 'image/webp' : 'image/jpeg'),
                        'original' => $path === $labour->labour_photo_original];
                }
            } catch (\Throwable $e) {
                // try the next one
            }
        }

        return null;
    }

    /**
     * Replace the profile photo with image bytes (the AI-retouched picture). The old file is KEPT and the change goes
     * through Eloquent, so the labour edit history records old -> new path and the old photo can be restored.
     *
     * @return array{path: string, old: ?string}
     */
    public function replaceKeepingOld(LabourModel $labour, string $bytes, string $updatedBy): array
    {
        $jpeg = SquareImage::fromBytes($bytes, self::SIZE, 90, true); // whole portrait (head to chest), not a centre crop
        $disk = LabourDocument::targetDisk();
        $name = $labour->labour_id . '_ai_' . Str::lower(Str::random(8));
        $path = 'photos/' . $name . '.jpg';
        $original = 'photos/originals/' . $name . '.jpg'; // the retouched picture at full size, for printing
        Storage::disk($disk)->put($path, $jpeg, ['visibility' => 'private']);
        Storage::disk($disk)->put($original, $bytes, ['visibility' => 'private']);

        $old = $labour->labour_photo ? ($labour->labour_photo_disk ?: 'public') . ':' . $labour->labour_photo : null;
        $data = ['labour_photo' => $path, 'labour_photo_disk' => $disk, 'labour_photo_original' => $original];
        if (in_array('updated_by', $labour->getFillable(), true)) {
            $data['updated_by'] = $updatedBy;
        }
        $labour->update($data);

        return ['path' => $path, 'old' => $old];
    }

    public function remove(LabourModel $labour, bool $save = true): void
    {
        if (! $labour->labour_photo) {
            return;
        }
        try {
            Storage::disk($labour->labour_photo_disk ?: 'public')->delete(array_filter([$labour->labour_photo, $labour->labour_photo_original]));
        } catch (\Throwable $e) {
            // a missing file must not block the update
        }
        $labour->labour_photo = null;
        $labour->labour_photo_disk = null;
        $labour->labour_photo_original = null;
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
    public function response(LabourModel $labour, bool $original = false)
    {
        $diskName = $labour->labour_photo_disk ?: 'public';
        $disk = Storage::disk($diskName);
        $driver = config('filesystems.disks.' . $diskName . '.driver');
        $path = $original && $labour->labour_photo_original ? $labour->labour_photo_original : $labour->labour_photo;
        $name = $original ? preg_replace('/[^A-Za-z0-9_\-]+/', '_', $labour->labour_passport_number ?: 'labour') . '_photo.' . pathinfo($path, PATHINFO_EXTENSION) : 'photo.jpg';

        if ($driver === 's3') {
            return redirect()->away($disk->temporaryUrl($path, now()->addMinutes(15), $original ? ['ResponseContentDisposition' => 'inline; filename="' . $name . '"'] : []));
        }

        return $disk->response($path, $name, ['Cache-Control' => 'private, max-age=3600'], 'inline');
    }
}
