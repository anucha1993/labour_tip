<?php

namespace App\Models\labour;

use App\Models\ActionLog;
use App\Models\agency\AgencyModel;
use App\Models\company\CompanyModel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

class LabourModel extends Model
{
    use HasFactory;

    protected $table = 'labour';
    protected $primaryKey = 'labour_id';
    protected $fillable = [
        'labour_prefix',
        'labour_number',
        'labour_fullname',
        'labour_sex',
        'labour_nationality',
        'labour_agency',
        'labour_birthday',
        'company_id',
        'labour_passport_number',
        'labour_passport_date_start',
        'labour_passport_date_end',
        'labour_visa_number',
        'labour_visa_date_in',
        'labour_visa_date_start',
        'labour_visa_date_end',
        'labour_workpremit_number',
        'labour_labour_number',
        'labour_workpremit_date_start',
        'labour_workpremit_date_end',
        'labour_day90_date_start',
        'labour_day90_date_end',
        'labour_tm_number',
        'labour_status',
        'labour_jobdate_start',
        'labour_status_job',
        'labour_resing_date',
        'labour_escape_date',
        'labour_note',
        'labour_file_passport',
        'labour_file_visa',
        'labour_file_work',
        'created_by',
        'updated_by',
    ];

    public function company()
    {
        return $this->belongsTo(CompanyModel::class, 'company_id', 'company_id');
    }

    public function agency()
    {
        return $this->belongsTo(AgencyModel::class, 'labour_agency', 'agency_id');
    }

    public function documents()
    {
        return $this->hasMany(LabourDocument::class, 'labour_id', 'labour_id')->orderBy('sort_order')->orderBy('id');
    }

    /**
     * Every document for this labour: rows from labour_documents plus any file still only
     * referenced by the legacy labour_file_* columns (shown until `labour:migrate-documents` runs).
     *
     * @return Collection<LabourDocument>
     */
    public function documentList(): Collection
    {
        $docs = LabourDocument::tableReady() ? $this->documents : collect();
        $covered = $docs->pluck('legacy_column')->filter()->all();

        foreach (LabourDocument::LEGACY_COLUMNS as $column => $name) {
            if (! $this->{$column} || in_array($column, $covered, true)) {
                continue;
            }
            $docs->push(new LabourDocument([
                'labour_id' => $this->labour_id,
                'name' => $name,
                'disk' => 'public',
                'path' => $this->labour_passport_number . '/' . $this->{$column},
                'original_name' => $this->{$column},
                'legacy_column' => $column,
            ]));
        }

        return $docs->values();
    }

    /** Audit trail: every create / update / delete is written to action_logs. */
    protected static function booted()
    {
        static::created(function (LabourModel $labour) {
            static::log('created', $labour, null, $labour->getAttributes());
        });

        static::updated(function (LabourModel $labour) {
            $changes = $labour->getChanges();
            unset($changes['updated_at']);
            if (! $changes) {
                return; // nothing meaningful changed
            }
            // Only keep the previous values of the columns that changed, so the diff is readable.
            $old = array_intersect_key($labour->getOriginal(), $changes);
            static::log('updated', $labour, $old, $changes);
        });

        static::deleted(function (LabourModel $labour) {
            static::log('deleted', $labour, $labour->getOriginal(), null);
        });
    }

    private static function log(string $action, LabourModel $labour, ?array $old, ?array $new): void
    {
        try {
            ActionLog::create([
                'user_id' => Auth::id() ?: null,
                'action_type' => $action,
                'model_type' => self::class,
                'model_id' => $labour->labour_id,
                'old_values' => $old,   // arrays: the model cast encodes them exactly once
                'new_values' => $new,
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            report($e); // never let audit logging break the actual save
        }
    }
}
