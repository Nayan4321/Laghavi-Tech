<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FlagRule extends Model
{
    protected $fillable = [
        'name', 'keywords', 'severity', 'applies_to', 'is_active',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /** @return string[] */
    public function keywordList(): array
    {
        return collect(explode(',', (string) $this->keywords))
            ->map(fn ($k) => trim($k))
            ->filter()
            ->values()
            ->all();
    }
}
