<?php

namespace App\Http\Controllers\labour;

use App\Http\Controllers\Controller;
use App\Models\labour\LabourDocument;
use App\Models\labour\LabourModel;
use Illuminate\Support\Facades\Storage;

class LabourDocumentController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
    }

    /** Open / download a document row (local files are streamed, R2 files get a signed URL). */
    public function open(LabourDocument $document)
    {
        if (! $document->exists()) {
            abort(404, 'ไม่พบไฟล์เอกสารในที่เก็บไฟล์');
        }

        return $document->openResponse();
    }

    /** Open a file that still lives only in a legacy labour_file_* column. */
    public function openLegacy(LabourModel $labourModel, string $column)
    {
        abort_unless(array_key_exists($column, LabourDocument::LEGACY_COLUMNS) && $labourModel->{$column}, 404);

        $path = $labourModel->labour_passport_number . '/' . $labourModel->{$column};
        abort_unless(Storage::disk('public')->exists($path), 404, 'ไม่พบไฟล์เอกสารในที่เก็บไฟล์');

        return Storage::disk('public')->response($path, $labourModel->{$column}, [], 'inline');
    }

    public function destroy(LabourDocument $document)
    {
        $document->deleteFile();

        // Keep the legacy column consistent so the file does not reappear as a "legacy" document.
        if ($document->legacy_column && $document->labour) {
            $document->labour->update([$document->legacy_column => null]);
        }

        $name = $document->name;
        $document->delete();

        return back()->with('success', 'ลบเอกสาร ' . $name . ' เรียบร้อยแล้ว');
    }

    public function destroyLegacy(LabourModel $labourModel, string $column)
    {
        abort_unless(array_key_exists($column, LabourDocument::LEGACY_COLUMNS), 404);

        if ($labourModel->{$column}) {
            Storage::disk('public')->delete($labourModel->labour_passport_number . '/' . $labourModel->{$column});
            $labourModel->update([$column => null]);
        }

        return back()->with('success', 'ลบเอกสาร ' . LabourDocument::LEGACY_COLUMNS[$column] . ' เรียบร้อยแล้ว');
    }
}
