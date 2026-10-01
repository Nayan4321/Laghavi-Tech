<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'is_active',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
    }

    public function isOwner(): bool
    {
        return $this->role === 'owner';
    }

    public function isSupervisor(): bool
    {
        return in_array($this->role, ['owner', 'supervisor'], true);
    }

    public function isAgent(): bool
    {
        return $this->role === 'agent';
    }

    /** Numbers this agent is allowed to work on. */
    public function numbers(): BelongsToMany
    {
        return $this->belongsToMany(WhatsappNumber::class, 'number_user');
    }

    public function assignedConversations(): HasMany
    {
        return $this->hasMany(Conversation::class, 'assigned_user_id');
    }

    /** Numbers visible to this user: supervisors/owners see all, agents see assigned. */
    public function visibleNumberIds(): array
    {
        if ($this->isSupervisor()) {
            return WhatsappNumber::query()->pluck('id')->all();
        }

        return $this->numbers()->pluck('whatsapp_numbers.id')->all();
    }
}
