<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CannedReply extends Model
{
    protected $fillable = [
        'whatsapp_number_id', 'shortcut', 'title', 'body',
    ];

    public function number(): BelongsTo
    {
        return $this->belongsTo(WhatsappNumber::class, 'whatsapp_number_id');
    }
}
