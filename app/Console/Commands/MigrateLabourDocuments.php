<?php

namespace App\Console\Commands;

use App\Models\labour\LabourDocument;
use App\Models\labour\LabourModel;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Moves labour documents to a new disk (e.g. Cloudflare R2) without touching the originals.
 *
 *   php artisan labour:migrate-documents --dry-run          # show what would happen
 *   php artisan labour:migrate-documents --sync-only        # only register legacy files in labour_documents
 *   php artisan labour:migrate-documents --to=r2            # copy every document to R2 and point rows at it
 *   php artisan labour:migrate-documents --to=r2 --delete-local   # (later) remove local copies that were verified on R2
 *
 * Safe to re-run: already-migrated rows are skipped, missing source files are reported, never deleted.
 */
class MigrateLabourDocuments extends Command
{
    protected $signature = 'labour:migrate-documents
                            {--to= : Target disk name from config/filesystems.php (e.g. r2). Defaults to LABOUR_DOCS_DISK}
                            {--sync-only : Only create labour_documents rows for legacy labour_file_* columns}
                            {--delete-local : Delete the source file after a verified copy (only when the source disk is local)}
                            {--dry-run : Report without writing anything}
                            {--limit=0 : Stop after N copied files (0 = no limit)}';

    protected $description = 'Register legacy labour files in labour_documents and copy documents to another disk (Cloudflare R2)';

    /** Rows step 1 would have created (dry-run only), so step 2 can report a realistic total. */
    private int $wouldRegister = 0;

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $target = $this->option('to') ?: LabourDocument::targetDisk();

        if (! config("filesystems.disks.$target")) {
            $this->error("ไม่พบ disk '$target' ใน config/filesystems.php");

            return self::FAILURE;
        }

        $this->syncLegacy($dry);

        if ($this->option('sync-only')) {
            return self::SUCCESS;
        }

        return $this->copyTo($target, $dry, (bool) $this->option('delete-local'), (int) $this->option('limit'));
    }

    /** Step 1: make sure every legacy labour_file_* value has a labour_documents row. */
    private function syncLegacy(bool $dry): void
    {
        $this->info('ขั้นที่ 1: ลงทะเบียนไฟล์จากคอลัมน์เดิม (labour_file_passport / visa / work) ลงตาราง labour_documents');

        $created = 0;
        $missing = 0;
        $skipped = 0;

        LabourModel::query()
            ->where(function ($q) {
                foreach (array_keys(LabourDocument::LEGACY_COLUMNS) as $column) {
                    $q->orWhereNotNull($column);
                }
            })
            ->with('documents')
            ->orderBy('labour_id')
            ->chunkById(300, function ($labours) use (&$created, &$missing, &$skipped, $dry) {
                foreach ($labours as $labour) {
                    $covered = $labour->documents->pluck('legacy_column')->filter()->all();
                    foreach (LabourDocument::LEGACY_COLUMNS as $column => $name) {
                        if (! $labour->{$column}) {
                            continue;
                        }
                        if (in_array($column, $covered, true)) {
                            $skipped++;
                            continue;
                        }
                        $path = $labour->labour_passport_number . '/' . $labour->{$column};
                        $exists = Storage::disk('public')->exists($path);
                        if (! $exists) {
                            $missing++;
                            $this->line("  <comment>ไม่พบไฟล์</comment> labour #{$labour->labour_id}: {$path}");
                        }
                        if (! $dry) {
                            LabourDocument::create([
                                'labour_id' => $labour->labour_id,
                                'name' => $name,
                                'disk' => 'public',
                                'path' => $path,
                                'original_name' => $labour->{$column},
                                'size' => $exists ? Storage::disk('public')->size($path) : null,
                                'legacy_column' => $column,
                                'uploaded_by' => $labour->updated_by ?: $labour->created_by,
                            ]);
                        }
                        $created++;
                    }
                }
            }, 'labour_id');

        $this->line("  ลงทะเบียนใหม่ {$created} รายการ, มีอยู่แล้ว {$skipped}, ไฟล์หาย {$missing}" . ($dry ? '  (dry-run: ไม่ได้บันทึก)' : ''));
        $this->wouldRegister = $dry ? $created - $missing : 0;
    }

    /** Step 2: copy each document that is not yet on the target disk. Originals are kept unless --delete-local. */
    private function copyTo(string $target, bool $dry, bool $deleteLocal, int $limit): int
    {
        $this->info("ขั้นที่ 2: คัดลอกเอกสารไปยัง disk '{$target}'");

        $targetDisk = Storage::disk($target);
        $copied = 0;
        $failed = 0;
        $missing = 0;
        $pending = LabourDocument::where('disk', '!=', $target)->count();
        $this->line("  รอคัดลอก {$pending} รายการ" . ($this->wouldRegister > 0 ? " (+{$this->wouldRegister} รายการจากขั้นที่ 1 เมื่อรันจริง)" : ''));

        $bar = $this->output->createProgressBar($limit > 0 ? min($limit, $pending) : $pending);
        $bar->start();

        LabourDocument::where('disk', '!=', $target)->orderBy('id')->chunkById(100, function ($docs) use (&$copied, &$failed, &$missing, $targetDisk, $target, $dry, $deleteLocal, $limit, $bar) {
            foreach ($docs as $doc) {
                if ($limit > 0 && $copied >= $limit) {
                    return false;
                }
                $source = Storage::disk($doc->disk ?: 'public');

                if (! $source->exists($doc->path)) {
                    $missing++;
                    $this->newLine();
                    $this->line("  <comment>ไม่พบไฟล์ต้นทาง</comment> doc #{$doc->id} ({$doc->disk}:{$doc->path})");
                    continue;
                }

                if ($dry) {
                    $copied++;
                    $bar->advance();
                    continue;
                }

                try {
                    $stream = $source->readStream($doc->path);
                    $ok = $targetDisk->writeStream($doc->path, $stream);
                    if (is_resource($stream)) {
                        fclose($stream);
                    }
                    $sourceSize = $source->size($doc->path);
                    if (! $ok || ! $targetDisk->exists($doc->path) || $targetDisk->size($doc->path) !== $sourceSize) {
                        throw new \RuntimeException('verify failed');
                    }

                    $oldDisk = $doc->disk;
                    $doc->update(['disk' => $target, 'size' => $sourceSize]);
                    $copied++;

                    if ($deleteLocal && config("filesystems.disks.$oldDisk.driver") === 'local') {
                        $source->delete($doc->path);
                    }
                } catch (\Throwable $e) {
                    $failed++;
                    $this->newLine();
                    $this->line("  <error>ล้มเหลว</error> doc #{$doc->id}: " . $e->getMessage());
                }
                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine();
        $this->line("  คัดลอกสำเร็จ {$copied}, ล้มเหลว {$failed}, ไฟล์ต้นทางหาย {$missing}" . ($dry ? '  (dry-run: ไม่ได้คัดลอกจริง)' : ''));

        if (! $dry && $copied > 0 && LabourDocument::targetDisk() !== $target) {
            $this->warn("อย่าลืมตั้ง LABOUR_DOCS_DISK={$target} ใน .env เพื่อให้ไฟล์ใหม่ขึ้น {$target} ด้วย");
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
