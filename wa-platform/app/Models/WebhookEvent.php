<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WebhookEvent extends Model
{
    protected $fillable = ['event_id', 'payload', 'processed_at', 'error'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'processed_at' => 'datetime'];
    }
}
