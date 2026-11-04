<?php

namespace App\Services;

use App\Models\labour\LabourDocument;
use App\Models\labour\LabourModel;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\StreamedResponse;
use ZipStream\Option\Archive as ArchiveOptions;
use ZipStream\Option\File as FileOptions;
use ZipStream\Option\Method;
use ZipStream\ZipStream;

/**
 * "ดาวน์โหลดเป็น zip" for any selection of worker folders and single files.
 *
 * The archive is streamed: every file is read from its disk (R2 or local) as a stream and written
 * straight to the HTTP response, so memory stays at a few MB whatever the size, nothing is written
 * to the server disk, and the browser starts receiving within a second. PDFs and photos do not
 * compress, so entries are stored uncompressed (no CPU spent on deflate).
 */
class DocumentZipService
{
    public const SLOT_PREFIX = 'docs-zip-slot-';
    private const SLOT_SECONDS = 900; // a slot frees itself if the process dies mid-stream

    public static function maxFiles(): int
    {
        return max(1, (int) config('documents.zip_max_files', 500));
    }

    public static function maxBytes(): int
    {
        return max(1, (int) config('documents.zip_max_mb', 1024)) * 1024 * 1024;
    }

    public static function maxConcurrent(): int
    {
        return max(1, (int) config('documents.zip_concurrent', 2));
    }

    /**
     * Turn a selection into zip entries: every file of each folder (worker) plus the single files,
     * each under "<passport fullname>/" when more than one worker is involved.
     *
     * @param  Collection<LabourModel>  $labours  whole folders
     * @param  Collection<LabourDocument>  $documents  single files (their labour relation loaded)
     * @return Collection<array{doc: LabourDocument, entry: string}>
     *
     * @throws \InvalidArgumentException when the selection is empty or beyond the limits
     */
    public function entries(Collection $labours, Collection $documents): Collection
    {
        $docs = collect();
        foreach ($labours as $labour) {
            foreach ($labour->documentList() as $doc) {
                $doc->setRelation('labour', $labour);
                $docs->push($doc);
            }
        }
        $folderIds = $labours->pluck('labour_id')->map(fn ($id) => (int) $id)->all();
        foreach ($documents as $doc) {
            if (! in_array((int) $doc->labour_id, $folderIds, true)) {
                $docs->push($doc);
            }
        }
        $docs = $docs->unique(fn ($d) => $d->exists ? 'id:' . $d->id : 'legacy:' . $d->labour_id . ':' . $d->legacy_column)->values();

        if ($docs->isEmpty()) {
            throw new \InvalidArgumentException('ไม่มีไฟล์ในรายการที่เลือก');
        }
        if ($docs->count() > self::maxFiles()) {
            throw new \InvalidArgumentException('เลือกได้ไม่เกิน ' . number_format(self::maxFiles()) . ' ไฟล์ต่อ zip (เลือกไว้ ' . number_format($docs->count()) . ' ไฟล์) กรุณาแบ่งเป็นหลายครั้ง');
        }
        $bytes = (int) $docs->sum(fn ($d) => (int) $d->size);
        if ($bytes > self::maxBytes()) {
            throw new \InvalidArgumentException('ขนาดรวมเกิน ' . self::human(self::maxBytes()) . ' ต่อ zip (เลือกไว้ ' . self::human($bytes) . ') กรุณาแบ่งเป็นหลายครั้ง');
        }

        $multi = $docs->pluck('labour_id')->unique()->count() > 1;
        $used = [];

        return $docs->map(function (LabourDocument $doc) use ($multi, &$used) {
            $folder = $multi ? self::folderName($doc->labour) . '/' : '';
            $base = $doc->downloadName();
            $entry = $folder . $base;
            $n = 1;
            while (isset($used[$entry])) {
                $entry = $folder . pathinfo($base, PATHINFO_FILENAME) . ' (' . (++$n) . ').' . $doc->extension();
            }
            $used[$entry] = true;

            return ['doc' => $doc, 'entry' => $entry];
        });
    }

    /** Stream the entries as one zip download. Fails with 429 when too many zips are already running. */
    public function stream(Collection $entries, string $zipName): StreamedResponse
    {
        $slot = $this->acquireSlot();
        abort_unless($slot, 429, 'ขณะนี้มีการสร้าง zip พร้อมกัน ' . self::maxConcurrent() . ' งานแล้ว กรุณาลองใหม่ในอีกสักครู่');

        $name = preg_replace('/[\\\\\/:*?"<>|\x00-\x1F]+/u', '-', trim($zipName)) ?: 'documents';
        if (! str_ends_with(strtolower($name), '.zip')) {
            $name .= '.zip';
        }

        return response()->streamDownload(function () use ($entries, $slot) {
            @set_time_limit(0);
            try {
                $opt = new ArchiveOptions;
                $opt->setSendHttpHeaders(false);
                $opt->setFlushOutput(true);
                $opt->setEnableZip64(true);
                $zip = new ZipStream(null, $opt);

                foreach ($entries as $e) {
                    /** @var LabourDocument $doc */
                    $doc = $e['doc'];
                    try {
                        $stream = $doc->storage()->readStream($doc->path);
                    } catch (\Throwable $ex) {
                        $stream = null;
                    }
                    if (! is_resource($stream)) {
                        continue; // file missing on disk: skip rather than abort the whole archive
                    }
                    $fo = new FileOptions;
                    $fo->setMethod(Method::STORE());
                    if ((int) $doc->size > 0) {
                        $fo->setSize((int) $doc->size);
                    }
                    if ($doc->created_at) {
                        $fo->setTime($doc->created_at);
                    }
                    $zip->addFileFromStream($e['entry'], $stream, $fo);
                    fclose($stream);
                }
                $zip->finish();
            } finally {
                optional($slot)->release();
            }
        }, $name, ['Content-Type' => 'application/zip', 'X-Accel-Buffering' => 'no']);
    }

    /** Zip file name for a selection: the worker, the company, or a dated generic name. */
    public static function nameFor(Collection $labours, Collection $documents, ?string $fallback = null): string
    {
        $all = $labours->merge($documents->map->labour)->filter()->unique('labour_id');
        if ($all->count() === 1) {
            $l = $all->first();

            return trim(($l->labour_passport_number ?: 'labour') . ' ' . $l->labour_fullname);
        }
        if ($fallback) {
            return $fallback;
        }
        $companies = $all->map(fn ($l) => optional($l->company)->company_name)->filter()->unique();

        return ($companies->count() === 1 ? $companies->first() : 'เอกสาร') . ' ' . now()->format('Ymd-Hi');
    }

    public static function folderName(?LabourModel $l): string
    {
        if (! $l) {
            return 'unknown';
        }

        return preg_replace('/[\\\\\/:*?"<>|\x00-\x1F]+/u', '-', trim(($l->labour_passport_number ?: '') . ' ' . $l->labour_fullname)) ?: ('labour-' . $l->labour_id);
    }

    public static function human(int $bytes): string
    {
        if ($bytes >= 1073741824) {
            return round($bytes / 1073741824, 1) . ' GB';
        }
        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 1) . ' MB';
        }

        return max(1, round($bytes / 1024)) . ' KB';
    }

    /** One of N concurrency slots (cache locks), or null when all are busy. */
    private function acquireSlot()
    {
        for ($i = 1; $i <= self::maxConcurrent(); $i++) {
            try {
                $lock = Cache::lock(self::SLOT_PREFIX . $i, self::SLOT_SECONDS);
                if ($lock->get()) {
                    return $lock;
                }
            } catch (\Throwable $e) {
                return new class { public function release() {} }; // cache driver without locks: no limit
            }
        }

        return null;
    }
}
