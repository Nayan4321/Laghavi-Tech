<?php

namespace App\Services;

use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\WhatsappNumber;
use Carbon\Carbon;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;

/**
 * Turns a raw WhatsApp Cloud API webhook payload into stored conversations
 * and messages. Written to be safe to run inline (no queue worker required),
 * which keeps deployment shared-hosting friendly.
 */
class WebhookProcessor
{
    public function __construct(protected KeywordFlagService $flags)
    {
    }

    public function handle(array $payload): void
    {
        foreach (Arr::get($payload, 'entry', []) as $entry) {
            foreach (Arr::get($entry, 'changes', []) as $change) {
                $value = Arr::get($change, 'value', []);
                $this->handleChange($value);
            }
        }
    }

    protected function handleChange(array $value): void
    {
        $phoneNumberId = Arr::get($value, 'metadata.phone_number_id');
        if (! $phoneNumberId) {
            return;
        }

        $number = WhatsappNumber::where('phone_number_id', $phoneNumberId)->first();
        if (! $number) {
            Log::warning('Webhook for unknown phone_number_id', ['id' => $phoneNumberId]);

            return;
        }

        // Map wa_id => profile name from the contacts array.
        $profiles = [];
        foreach (Arr::get($value, 'contacts', []) as $c) {
            $profiles[Arr::get($c, 'wa_id')] = Arr::get($c, 'profile.name');
        }

        foreach (Arr::get($value, 'messages', []) as $msg) {
            $this->ingestInbound($number, $msg, $profiles);
        }

        foreach (Arr::get($value, 'statuses', []) as $status) {
            $this->applyStatus($status);
        }
    }

    protected function ingestInbound(WhatsappNumber $number, array $msg, array $profiles): void
    {
        $waId = Arr::get($msg, 'from');
        $wamid = Arr::get($msg, 'id');
        if (! $waId || ! $wamid) {
            return;
        }

        // Idempotency: never store the same inbound message twice.
        if (Message::where('wamid', $wamid)->exists()) {
            return;
        }

        $contact = Contact::firstOrCreate(
            ['whatsapp_number_id' => $number->id, 'wa_id' => $waId],
            ['profile_name' => $profiles[$waId] ?? null],
        );
        if (isset($profiles[$waId]) && $profiles[$waId] && $contact->profile_name !== $profiles[$waId]) {
            $contact->update(['profile_name' => $profiles[$waId]]);
        }

        $ts = Carbon::createFromTimestamp((int) Arr::get($msg, 'timestamp', time()));

        $conversation = Conversation::firstOrCreate(
            ['whatsapp_number_id' => $number->id, 'contact_id' => $contact->id],
            ['status' => 'open'],
        );

        [$type, $body, $mediaInfo] = $this->extractContent($number, $msg);

        $message = Message::create([
            'conversation_id' => $conversation->id,
            'whatsapp_number_id' => $number->id,
            'wamid' => $wamid,
            'direction' => 'in',
            'type' => $type,
            'body' => $body,
            'media_path' => $mediaInfo[0] ?? null,
            'media_mime' => $mediaInfo[1] ?? null,
            'status' => 'received',
            'sent_at' => $ts,
            'raw' => $msg,
        ]);

        // A new inbound message (re)opens the 24h customer-service window.
        $conversation->forceFill([
            'last_message_at' => $ts,
            'window_expires_at' => $ts->copy()->addHours((int) config('whatsapp.session_window_hours')),
            'unread_count' => $conversation->unread_count + 1,
            'status' => $conversation->status === 'resolved' ? 'open' : $conversation->status,
        ])->save();

        $contact->update(['last_message_at' => $ts]);

        // Monitor inbound too (in case rules apply to both directions).
        $this->flags->scan($message);
    }

    /**
     * @return array{0:string,1:?string,2:?array} [type, body, [mediaPath, mime]]
     */
    protected function extractContent(WhatsappNumber $number, array $msg): array
    {
        $type = Arr::get($msg, 'type', 'text');

        return match ($type) {
            'text' => ['text', Arr::get($msg, 'text.body'), null],
            'button' => ['text', Arr::get($msg, 'button.text'), null],
            'interactive' => ['text', $this->interactiveReply($msg), null],
            'image', 'document', 'audio', 'video', 'sticker' => $this->media($number, $type, $msg),
            'location' => ['location', $this->locationText($msg), null],
            default => [$type, json_encode(Arr::get($msg, $type, [])), null],
        };
    }

    protected function interactiveReply(array $msg): ?string
    {
        return Arr::get($msg, 'interactive.button_reply.title')
            ?? Arr::get($msg, 'interactive.list_reply.title');
    }

    protected function locationText(array $msg): string
    {
        return 'Location: '.Arr::get($msg, 'location.latitude').','.Arr::get($msg, 'location.longitude');
    }

    protected function media(WhatsappNumber $number, string $type, array $msg): array
    {
        $mediaId = Arr::get($msg, "{$type}.id");
        $caption = Arr::get($msg, "{$type}.caption");
        $path = null;
        $mime = Arr::get($msg, "{$type}.mime_type");

        if ($mediaId) {
            try {
                $result = WhatsAppCloudService::for($number)->downloadMedia($mediaId);
                if ($result) {
                    [$path, $mime] = $result;
                }
            } catch (\Throwable $e) {
                // Never let a media-download failure drop the whole message.
                Log::warning('Media download failed', ['error' => $e->getMessage()]);
            }
        }

        return [$type, $caption, [$path, $mime]];
    }

    protected function applyStatus(array $status): void
    {
        $wamid = Arr::get($status, 'id');
        $state = Arr::get($status, 'status'); // sent | delivered | read | failed
        if (! $wamid || ! $state) {
            return;
        }

        $message = Message::where('wamid', $wamid)->first();
        if (! $message) {
            return;
        }

        // Never downgrade a status (read should not become delivered).
        $rank = ['queued' => 0, 'sent' => 1, 'delivered' => 2, 'read' => 3, 'failed' => 4];
        if (($rank[$state] ?? 0) >= ($rank[$message->status] ?? 0)) {
            $message->status = $state;
            if ($state === 'failed') {
                $message->error = json_encode(Arr::get($status, 'errors', []));
            }
            $message->save();
        }
    }
}
