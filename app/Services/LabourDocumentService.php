<?php

namespace App\Services;

use App\Models\labour\LabourDocument;
use App\Models\labour\LabourModel;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

/**
 * Stores an uploaded file as a document of a labour. Shared by the labour form and the
 * document explorer so both put files in the same place with the same naming.
 */
class LabourDocumentService
{
    public const ALLOWED_MIMES = 'pdf,jpg,jpeg,png';
    public const MAX_KB = 10240;
    public const MAX_PER_REQUEST = 30;

    public function store(LabourModel $labour, UploadedFile $file, string $name, ?string $uploadedBy = null): LabourDocument
    {
        if (! LabourDocument::tableReady()) {
            throw new \RuntimeException('ยังไม่ได้สร้างตาราง labour_documents กรุณารัน php artisan migrate ก่อนอัปโหลดเอกสาร');
        }

        $name = trim($name) ?: 'Document';
        $disk = LabourDocument::targetDisk();
        $ext = strtolower($file->getClientOriginalExtension() ?: $file->extension());
        $slug = Str::slug($name, '-') ?: 'document';
        $folder = $labour->labour_passport_number ?: ('labour-' . $labour->labour_id);
        $path = $folder . '/' . now()->format('Ymd_His') . '_' . $slug . '_' . Str::lower(Str::random(4)) . '.' . $ext;

        $file->storeAs(dirname($path), basename($path), $disk);

        return $labour->documents()->create([
            'name' => $name,
            'disk' => $disk,
            'path' => $path,
            'original_name' => $file->getClientOriginalName(),
            'size' => $file->getSize(),
            'mime' => $file->getClientMimeType(),
            'sort_order' => (int) ($labour->documents()->max('sort_order') ?? 0) + 1,
            'uploaded_by' => $uploadedBy,
        ]);
    }
}
