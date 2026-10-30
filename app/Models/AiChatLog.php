<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One question → answer exchange with the AI assistant (table ai_chat_logs).
 */
class AiChatLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'user_id', 'conversation_id', 'question', 'answer', 'tools_used', 'reports',
        'prompt_tokens', 'completion_tokens', 'duration_ms', 'status', 'error', 'ip', 'created_at', 'rating', 'feedback',
    ];

    protected $casts = [
        'tools_used' => 'array',
        'reports' => 'array',
        'prompt_tokens' => 'integer',
        'completion_tokens' => 'integer',
        'duration_ms' => 'integer',
        'rating' => 'integer',
        'created_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function scopeOk($q)
    {
        return $q->where('status', 'ok');
    }

    public function totalTokens(): int
    {
        return $this->prompt_tokens + $this->completion_tokens;
    }
}
