<?php

namespace App\Services;

use App\Models\WhatsappNumber;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * Thin wrapper over the WhatsApp Business Cloud API (Meta Graph API).
 *
 * Every call is scoped to one WhatsappNumber because each number carries its
 * own phone_number_id and permanent access token.
 */
class WhatsAppCloudService
{
    public function __construct(protected WhatsappNumber $number)
    {
    }

    public static function for(WhatsappNumber $number): self
    {
        return new self($number);
    }

    protected function base(): string
    {
        $version = config('whatsapp.graph_version');
        $root = rtrim(config('whatsapp.graph_base'), '/');

        return "{$root}/{$version}";
    }

    protected function client()
    {
        return Http::withToken($this->number->access_token)
            ->acceptJson()
            ->timeout(30);
    }

    /**
     * Send a plain text message. Returns the Meta message id (wamid) on success.
     *
     * @throws \Illuminate\Http\Client\RequestException
     */
    public function sendText(string $toWaId, string $body, bool $previewUrl = true): array
    {
        return $this->postMessage([
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $toWaId,
            'type' => 'text',
            'text' => [
                'preview_url' => $previewUrl,
                'body' => $body,
            ],
        ]);
    }

    /**
     * Send a media message by uploaded media id or by a hosted link.
     *
     * @param  'image'|'document'|'audio'|'video'  $type
     */
    public function sendMedia(string $toWaId, string $type, string $linkOrId, ?string $caption = null, bool $isId = false): array
    {
        $media = $isId ? ['id' => $linkOrId] : ['link' => $linkOrId];
        if ($caption !== null && in_array($type, ['image', 'document', 'video'], true)) {
            $media['caption'] = $caption;
        }

        return $this->postMessage([
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $toWaId,
            'type' => $type,
            $type => $media,
        ]);
    }

    /**
     * Send a pre-approved template message (used to start conversations or
     * re-open past the 24h window, and for engagement/broadcast campaigns).
     *
     * @param  array  $components  template components (body params, buttons, etc.)
     */
    public function sendTemplate(string $toWaId, string $templateName, string $language = 'en_US', array $components = []): array
    {
        $template = [
            'name' => $templateName,
            'language' => ['code' => $language],
        ];
        if (! empty($components)) {
            $template['components'] = $components;
        }

        return $this->postMessage([
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $toWaId,
            'type' => 'template',
            'template' => $template,
        ]);
    }

    /** Mark an inbound message as read (blue ticks for the customer). */
    public function markRead(string $wamid): void
    {
        $this->client()->post($this->base().'/'.$this->number->phone_number_id.'/messages', [
            'messaging_product' => 'whatsapp',
            'status' => 'read',
            'message_id' => $wamid,
        ]);
    }

    protected function postMessage(array $payload): array
    {
        $response = $this->client()
            ->post($this->base().'/'.$this->number->phone_number_id.'/messages', $payload)
            ->throw();

        return $response->json();
    }

    /**
     * Download inbound media by its media id and store it on the public disk.
     * Returns [relativePath, mimeType] or null on failure.
     */
    public function downloadMedia(string $mediaId): ?array
    {
        $meta = $this->client()->get($this->base().'/'.$mediaId);
        if (! $meta->ok()) {
            return null;
        }

        $url = $meta->json('url');
        $mime = $meta->json('mime_type');
        if (! $url) {
            return null;
        }

        // The media URL itself also requires the bearer token.
        $binary = $this->client()->get($url);
        if (! $binary->ok()) {
            return null;
        }

        $ext = $this->extensionFor($mime);
        $path = 'wa-media/'.$this->number->id.'/'.$mediaId.($ext ? ".{$ext}" : '');
        Storage::disk('public')->put($path, $binary->body());

        return [$path, $mime];
    }

    protected function extensionFor(?string $mime): ?string
    {
        return match ($mime) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'application/pdf' => 'pdf',
            'audio/ogg' => 'ogg',
            'audio/mpeg' => 'mp3',
            'video/mp4' => 'mp4',
            default => null,
        };
    }
}
