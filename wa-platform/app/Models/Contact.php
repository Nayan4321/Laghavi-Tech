<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Contact extends Model
{
    use HasFactory;

    protected $fillable = [
        'whatsapp_number_id', 'wa_id', 'profile_name', 'name', 'last_message_at',
    ];

    protected function casts(): array
    {
        return ['last_message_at' => 'datetime'];
    }

    public function number(): BelongsTo
    {
        return $this->belongsTo(WhatsappNumber::class, 'whatsapp_number_id');
    }

    public function displayName(): string
    {
        return $this->name ?: ($this->profile_name ?: $this->wa_id);
    }
}
