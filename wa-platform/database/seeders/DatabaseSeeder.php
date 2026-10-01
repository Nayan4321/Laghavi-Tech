<?php

namespace Database\Seeders;

use App\Models\FlagRule;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Sensible default monitoring rules the owner can edit/extend.
        $defaults = [
            [
                'name' => 'Sharing personal contact',
                'keywords' => 'my personal number, whatsapp me on, call me on my, my own number, contact me directly, personal whatsapp',
                'severity' => 'high',
                'applies_to' => 'out',
            ],
            [
                'name' => 'Unauthorised discount / freebie',
                'keywords' => 'special discount, extra discount, free of cost, no charge, off the record, cash only',
                'severity' => 'medium',
                'applies_to' => 'out',
            ],
            [
                'name' => 'Rude / unprofessional language',
                'keywords' => 'stupid, idiot, shut up, nonsense, useless',
                'severity' => 'high',
                'applies_to' => 'both',
            ],
            [
                'name' => 'Diverting off-platform',
                'keywords' => 'call me instead, lets talk on, move to, telegram, signal',
                'severity' => 'medium',
                'applies_to' => 'out',
            ],
        ];

        foreach ($defaults as $rule) {
            FlagRule::firstOrCreate(['name' => $rule['name']], $rule + ['is_active' => true]);
        }
    }
}
