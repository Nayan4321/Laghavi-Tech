<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Conversation extends Model
{
    use HasFactory;

    protected $fillable = [
        'whatsapp_number_id', 'contact_id', 'assigned_user_id',
        'status', 'unread_count', 'last_message_at', 'window_expires_at',
    ];

    protected function casts(): array
    {
        return [
            'last_message_at' => 'datetime',
            'window_expires_at' => 'datetime',
        ];
    }

    public function number(): BelongsTo
    {
        return $this->belongsTo(WhatsappNumber::class, 'whatsapp_number_id');
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function assignedAgent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    /** Is the free-form (session) messaging window still open? */
    public function windowOpen(): bool
    {
        return $this->window_expires_at !== null && $this->window_expires_at->isFuture();
    }
}
