<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Message extends Model
{
    use HasFactory;

    protected $fillable = [
        'conversation_id', 'whatsapp_number_id', 'sender_user_id', 'wamid',
        'direction', 'type', 'body', 'media_path', 'media_mime',
        'status', 'error', 'sent_at', 'raw',
    ];

    protected function casts(): array
    {
        return [
            'raw' => 'array',
            'sent_at' => 'datetime',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_user_id');
    }

    public function flags(): HasMany
    {
        return $this->hasMany(MessageFlag::class);
    }

    public function isInbound(): bool
    {
        return $this->direction === 'in';
    }
}
