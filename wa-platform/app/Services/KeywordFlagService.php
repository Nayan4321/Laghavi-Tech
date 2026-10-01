<?php

namespace App\Services;

use App\Models\FlagRule;
use App\Models\Message;
use App\Models\MessageFlag;
use Illuminate\Support\Facades\Cache;

/**
 * Scans messages against supervisor-defined keyword rules and records flags.
 * This is the core of "are agents chatting fairly" monitoring.
 */
class KeywordFlagService
{
    /** Scan one message and persist any flags it triggers. */
    public function scan(Message $message): void
    {
        $body = (string) $message->body;
        if ($body === '') {
            return;
        }

        foreach ($this->activeRules() as $rule) {
            if (! $this->ruleAppliesToDirection($rule, $message->direction)) {
                continue;
            }

            foreach ($rule->keywordList() as $keyword) {
                if ($this->contains($body, $keyword)) {
                    MessageFlag::create([
                        'message_id' => $message->id,
                        'conversation_id' => $message->conversation_id,
                        'rule' => $rule->name,
                        'matched' => $keyword,
                        'severity' => $rule->severity,
                    ]);
                    // One flag per rule is enough.
                    break;
                }
            }
        }
    }

    protected function ruleAppliesToDirection(FlagRule $rule, string $direction): bool
    {
        return $rule->applies_to === 'both' || $rule->applies_to === $direction;
    }

    protected function contains(string $haystack, string $needle): bool
    {
        return $needle !== '' && mb_stripos($haystack, $needle) !== false;
    }

    /** @return \Illuminate\Support\Collection<int, FlagRule> */
    protected function activeRules()
    {
        // Rules change rarely; cache for a minute to keep ingestion fast.
        return Cache::remember('flag_rules_active', 60, function () {
            return FlagRule::query()->where('is_active', true)->get();
        });
    }
}
