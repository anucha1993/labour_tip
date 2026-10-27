<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ActionLog extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'action_type',
        'model_type',
        'model_id',
        'old_values',
        'new_values',
        'created_at',
    ];

    protected $casts = [
        'old_values' => 'array',
        'new_values' => 'array',
        'created_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Older rows were written as json_encode() of an already json-cast value, so the
     * column holds a JSON string of JSON. These accessors unwrap both shapes.
     */
    public function getOldValuesAttribute($value): ?array
    {
        return $this->decodeValues($value);
    }

    public function getNewValuesAttribute($value): ?array
    {
        return $this->decodeValues($value);
    }

    private function decodeValues($value): ?array
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_array($value)) {
            return $value;
        }
        $decoded = json_decode($value, true);
        if (is_string($decoded)) {
            $decoded = json_decode($decoded, true);
        }

        return is_array($decoded) ? $decoded : null;
    }
}
