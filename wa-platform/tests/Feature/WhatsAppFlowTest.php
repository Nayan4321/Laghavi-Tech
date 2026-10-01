<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\FlagRule;
use App\Models\Message;
use App\Models\User;
use App\Models\WhatsappNumber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WhatsAppFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function makeNumber(): WhatsappNumber
    {
        return WhatsappNumber::create([
            'label' => 'Sales',
            'phone_number_id' => '123456',
            'access_token' => 'test-token',
            'webhook_verify_token' => 'verify-abc',
            'is_active' => true,
        ]);
    }

    protected function inboundPayload(string $phoneNumberId, string $from, string $text, string $wamid): array
    {
        return [
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'id' => 'WABA1',
                'changes' => [[
                    'field' => 'messages',
                    'value' => [
                        'messaging_product' => 'whatsapp',
                        'metadata' => ['display_phone_number' => '919999999999', 'phone_number_id' => $phoneNumberId],
                        'contacts' => [['profile' => ['name' => 'Ravi'], 'wa_id' => $from]],
                        'messages' => [[
                            'from' => $from,
                            'id' => $wamid,
                            'timestamp' => (string) now()->timestamp,
                            'type' => 'text',
                            'text' => ['body' => $text],
                        ]],
                    ],
                ]],
            ]],
        ];
    }

    public function test_webhook_verification_handshake(): void
    {
        $this->makeNumber();

        $this->get('/webhook/whatsapp?hub_mode=subscribe&hub_verify_token=verify-abc&hub_challenge=42')
            ->assertOk()
            ->assertSee('42');

        $this->get('/webhook/whatsapp?hub_mode=subscribe&hub_verify_token=wrong&hub_challenge=42')
            ->assertForbidden();
    }

    public function test_inbound_message_creates_conversation_and_message(): void
    {
        $number = $this->makeNumber();

        $this->postJson('/webhook/whatsapp', $this->inboundPayload('123456', '918888888888', 'Hello, I need a quote', 'wamid.IN1'))
            ->assertOk();

        $this->assertDatabaseCount('conversations', 1);
        $message = Message::first();
        $this->assertSame('in', $message->direction);
        $this->assertSame('Hello, I need a quote', $message->body);

        $conversation = Conversation::first();
        $this->assertTrue($conversation->windowOpen(), 'Inbound message should open the 24h window');
        $this->assertSame(1, $conversation->unread_count);
    }

    public function test_inbound_is_idempotent(): void
    {
        $this->makeNumber();
        $payload = $this->inboundPayload('123456', '918888888888', 'Hi', 'wamid.DUP');

        $this->postJson('/webhook/whatsapp', $payload)->assertOk();
        $this->postJson('/webhook/whatsapp', $payload)->assertOk();

        $this->assertDatabaseCount('messages', 1);
    }

    public function test_agent_can_reply_and_outbound_is_flagged(): void
    {
        Http::fake([
            'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT1']]], 200),
        ]);

        FlagRule::create([
            'name' => 'Personal number',
            'keywords' => 'my personal number',
            'severity' => 'high',
            'applies_to' => 'out',
            'is_active' => true,
        ]);

        $number = $this->makeNumber();
        $agent = User::create([
            'name' => 'Asha', 'email' => 'asha@test.com', 'password' => 'password',
            'role' => 'agent', 'is_active' => true,
        ]);
        $agent->numbers()->attach($number);

        // Inbound opens a conversation.
        $this->postJson('/webhook/whatsapp', $this->inboundPayload('123456', '918888888888', 'Hi', 'wamid.IN2'))->assertOk();
        $conversation = Conversation::first();

        // Agent sends a reply that trips the flag rule.
        $this->actingAs($agent)
            ->postJson("/api/conversations/{$conversation->id}/messages", ['body' => 'Sure, this is my personal number 90000'])
            ->assertCreated();

        $out = Message::where('direction', 'out')->first();
        $this->assertSame('sent', $out->status);
        $this->assertSame('wamid.OUT1', $out->wamid);
        $this->assertSame($agent->id, $out->sender_user_id);
        $this->assertDatabaseHas('message_flags', ['message_id' => $out->id, 'rule' => 'Personal number']);
    }

    public function test_agent_cannot_access_unassigned_number_conversation(): void
    {
        $number = $this->makeNumber();
        $agent = User::create([
            'name' => 'NoAccess', 'email' => 'no@test.com', 'password' => 'password',
            'role' => 'agent', 'is_active' => true,
        ]);
        // Note: agent NOT attached to the number.

        $this->postJson('/webhook/whatsapp', $this->inboundPayload('123456', '918888888888', 'Hi', 'wamid.IN3'))->assertOk();
        $conversation = Conversation::first();

        $this->actingAs($agent)
            ->getJson("/api/conversations/{$conversation->id}")
            ->assertForbidden();
    }
}
