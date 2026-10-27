<?php

namespace App\Models\settings;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class settingsModel extends Model
{
    use HasFactory;

    protected $table = 'settings';
    protected $primaryKey = 'set_id';

    // The table has a misspelled "craeted_at" column, so let the DB manage timestamps itself.
    public $timestamps = false;

    protected $fillable = [
        'set_id',
        'set_name',
        'set_expire',
    ];

    protected $casts = [
        'set_expire' => 'integer',
    ];
}
