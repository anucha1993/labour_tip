<?php

namespace App\Models\labour;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * One uploaded document belonging to a labour. The row records which disk holds the
 * file, so documents can live on local storage and Cloudflare R2 at the same time.
 */
class LabourDocument extends Model
{
    protected $table = 'labour_documents';

    protected $fillable = [
        'labour_id', 'name', 'disk', 'path', 'original_name', 'size', 'mime', 'legacy_column', 'sort_order', 'uploaded_by',
    ];

    protected $casts = ['size' => 'integer', 'sort_order' => 'integer'];

    /** Map of legacy labour columns => default document name. */
    public const LEGACY_COLUMNS = [
        'labour_file_passport' => 'Passport',
        'labour_file_visa' => 'Visa',
        'labour_file_work' => 'Work Permit',
    ];

    /** Suggested document names shown in the form (free text is also allowed). */
    public const SUGGESTED_NAMES = [
        'Passport', 'Visa', 'Work Permit', '90-Day Report', 'TM.', 'Employment Contract', 'Photo', 'Other',
    ];

    public const ALLOWED_EXTENSIONS = ['pdf', 'jpg', 'jpeg', 'png'];

    public function labour()
    {
        return $this->belongsTo(LabourModel::class, 'labour_id', 'labour_id');
    }

    private static ?bool $tableReady = null;

    /** True once `php artisan migrate` has created labour_documents. Lets the app run before that. */
    public static function tableReady(): bool
    {
        if (self::$tableReady === null) {
            try {
                self::$tableReady = Schema::hasTable('labour_documents');
            } catch (\Throwable $e) {
                self::$tableReady = false;
            }
        }

        return self::$tableReady;
    }

    public static function forgetTableReady(): void
    {
        self::$tableReady = null;
    }

    public static function targetDisk(): string
    {
        return (string) config('filesystems.labour_documents_disk', 'public');
    }

    public function storage()
    {
        return Storage::disk($this->disk ?: 'public');
    }

    public function exists(): bool
    {
        try {
            return $this->storage()->exists($this->path);
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function fileName(): string
    {
        return basename($this->path);
    }

    public function extension(): string
    {
        return strtolower(pathinfo($this->path, PATHINFO_EXTENSION));
    }

    public function isImage(): bool
    {
        return in_array($this->extension(), ['jpg', 'jpeg', 'png', 'gif', 'webp'], true);
    }

    public function icon(): string
    {
        return $this->isImage() ? 'mdi-file-image' : 'mdi-file-pdf';
    }

    public function humanSize(): string
    {
        $b = (int) $this->size;
        if ($b <= 0) {
            return '-';
        }
        if ($b < 1024 * 1024) {
            return number_format($b / 1024, 0) . ' KB';
        }

        return number_format($b / (1024 * 1024), 2) . ' MB';
    }

    /**
     * URL the browser can open. Private S3-compatible disks get a short-lived signed URL;
     * local disks go through the app so the auth middleware still applies.
     */
    public function url(): string
    {
        return route('labour.document.open', $this->id);
    }

    /** Resolve the real file URL / response for the open route. */
    public function openResponse()
    {
        $disk = $this->storage();
        $driver = config('filesystems.disks.' . ($this->disk ?: 'public') . '.driver');

        if ($driver === 's3') {
            return redirect()->away($disk->temporaryUrl($this->path, now()->addMinutes(15), [
                'ResponseContentDisposition' => 'inline; filename="' . $this->fileName() . '"',
            ]));
        }

        return $disk->response($this->path, $this->fileName(), [], 'inline');
    }

    public function deleteFile(): void
    {
        try {
            if ($this->exists()) {
                $this->storage()->delete($this->path);
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
