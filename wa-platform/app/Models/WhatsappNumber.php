<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WhatsappNumber extends Model
{
    use HasFactory;

    protected $fillable = [
        'label', 'display_phone', 'phone_number_id', 'waba_id',
        'access_token', 'webhook_verify_token', 'is_active',
    ];

    protected $hidden = ['access_token', 'webhook_verify_token'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            // Permanent access tokens are secrets: encrypt at rest.
            'access_token' => 'encrypted',
            'webhook_verify_token' => 'encrypted',
        ];
    }

    public function agents(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'number_user');
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(Contact::class);
    }
}
