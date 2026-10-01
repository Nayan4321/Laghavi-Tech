<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MessageFlag extends Model
{
    protected $fillable = [
        'message_id', 'conversation_id', 'rule', 'matched', 'severity', 'reviewed',
    ];

    protected function casts(): array
    {
        return ['reviewed' => 'boolean'];
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }
}
