<?php

namespace App\Models\agency;

use App\Models\labour\LabourModel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AgencyModel extends Model
{
    use HasFactory;

    protected $table = 'agency';
    protected $primaryKey = 'agency_id';
    protected $fillable = [
        'agency_name',
        'agency_tax',
        'agency_nationality',
        'agency_address',
        'agency_email',
        'agency_contact',
        'agency_phone',
        'agency_note',
        'created_by',
        'updated_by',
    ];

    public function labours()
    {
        return $this->hasMany(LabourModel::class, 'labour_agency', 'agency_id');
    }
}
