<?php

namespace App\Http\Controllers\Webhook;

use App\Http\Controllers\Controller;
use App\Models\WebhookEvent;
use App\Models\WhatsappNumber;
use App\Services\WebhookProcessor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class WhatsAppWebhookController extends Controller
{
    public function __construct(protected WebhookProcessor $processor)
    {
    }

    /**
     * GET verification handshake from Meta.
     * Accepts the global verify token or any number's own token.
     */
    public function verify(Request $request)
    {
        $mode = $request->query('hub_mode');
        $token = $request->query('hub_verify_token');
        $challenge = $request->query('hub_challenge');

        if ($mode !== 'subscribe' || ! $token) {
            abort(403);
        }

        $globalOk = hash_equals((string) config('whatsapp.verify_token'), (string) $token);
        $perNumberOk = WhatsappNumber::query()->get()
            ->contains(fn ($n) => $n->webhook_verify_token && hash_equals($n->webhook_verify_token, $token));

        if ($globalOk || $perNumberOk) {
            return response($challenge, 200)->header('Content-Type', 'text/plain');
        }

        abort(403);
    }

    /**
     * POST inbound events. We store the raw payload, return 200 immediately
     * (Meta requires a fast ack), then process. Processing is done inline so
     * no queue worker is required, but is wrapped so a failure never 500s —
     * which would make Meta retry and eventually disable the webhook.
     */
    public function receive(Request $request)
    {
        $raw = $request->getContent();

        if (! $this->signatureValid($request, $raw)) {
            Log::warning('WhatsApp webhook signature mismatch');

            // Still 200 so Meta does not disable the subscription; just drop it.
            return response()->json(['status' => 'ignored']);
        }

        $payload = $request->json()->all();

        $event = WebhookEvent::create(['payload' => $payload]);

        try {
            $this->processor->handle($payload);
            $event->update(['processed_at' => now()]);
        } catch (\Throwable $e) {
            report($e);
            $event->update(['error' => $e->getMessage()]);
        }

        return response()->json(['status' => 'ok']);
    }

    /** Validate the X-Hub-Signature-256 header against the app secret. */
    protected function signatureValid(Request $request, string $raw): bool
    {
        $secret = (string) config('whatsapp.app_secret');
        if ($secret === '') {
            // Not configured yet — allow (useful for first-time setup/testing).
            return true;
        }

        $header = $request->header('X-Hub-Signature-256');
        if (! $header || ! str_starts_with($header, 'sha256=')) {
            return false;
        }

        $expected = 'sha256='.hash_hmac('sha256', $raw, $secret);

        return hash_equals($expected, $header);
    }
}
