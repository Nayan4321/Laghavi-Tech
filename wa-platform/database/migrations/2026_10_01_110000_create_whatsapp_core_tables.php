<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Core schema for the WhatsApp agent monitoring platform.
 *
 * Designed to be multi-number from day one: one WhatsApp Business Account
 * (WABA) can hold up to 20 numbers, and agents are mapped to numbers via a
 * pivot so the same schema supports both "one number per agent" and
 * "a few shared numbers" models.
 */
return new class extends Migration
{
    public function up(): void
    {
        // The business WhatsApp numbers registered on the Cloud API.
        Schema::create('whatsapp_numbers', function (Blueprint $table) {
            $table->id();
            $table->string('label');                       // friendly name e.g. "Sales - Nayan"
            $table->string('display_phone')->nullable();   // +91 98xxxxxx (human readable)
            $table->string('phone_number_id')->unique();   // Meta phone_number_id
            $table->string('waba_id')->nullable();         // WhatsApp Business Account id
            $table->text('access_token')->nullable();      // encrypted on the model (permanent token)
            $table->string('webhook_verify_token')->nullable(); // used during GET verify
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        // Which agents may work on which numbers.
        Schema::create('number_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('whatsapp_number_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['whatsapp_number_id', 'user_id']);
        });

        // Customers / people on the other side of a conversation.
        Schema::create('contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('whatsapp_number_id')->constrained()->cascadeOnDelete();
            $table->string('wa_id');                        // customer's WhatsApp id (phone, digits only)
            $table->string('profile_name')->nullable();     // name WhatsApp reports
            $table->string('name')->nullable();             // name an agent sets
            $table->timestamp('last_message_at')->nullable();
            $table->timestamps();
            $table->unique(['whatsapp_number_id', 'wa_id']);
        });

        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('whatsapp_number_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();
            $table->foreignId('assigned_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status')->default('open')->index(); // open | pending | resolved
            $table->unsignedInteger('unread_count')->default(0);
            $table->timestamp('last_message_at')->nullable()->index();
            // 24h customer-service window: free-form replies only allowed until this time.
            $table->timestamp('window_expires_at')->nullable();
            $table->timestamps();
            $table->index(['whatsapp_number_id', 'status']);
        });

        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('whatsapp_number_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sender_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('wamid')->nullable()->index();   // Meta message id (for status callbacks)
            $table->string('direction');                    // in | out
            $table->string('type')->default('text');        // text | image | document | audio | video | template | ...
            $table->longText('body')->nullable();
            $table->string('media_path')->nullable();       // stored media (relative to storage/app/public)
            $table->string('media_mime')->nullable();
            $table->string('status')->default('received');  // received | queued | sent | delivered | read | failed
            $table->text('error')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->json('raw')->nullable();                // original payload for audit
            $table->timestamps();
            $table->index(['conversation_id', 'id']);
        });

        // Monitoring: auto-flagged messages (profanity, sharing personal number, etc.)
        Schema::create('message_flags', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_id')->constrained()->cascadeOnDelete();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->string('rule');                         // rule name that matched
            $table->string('matched')->nullable();          // the actual text that matched
            $table->string('severity')->default('low');     // low | medium | high
            $table->boolean('reviewed')->default(false)->index();
            $table->timestamps();
        });

        // Keyword/phrase rules a supervisor configures.
        Schema::create('flag_rules', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('keywords');                       // comma-separated words/phrases
            $table->string('severity')->default('medium');  // low | medium | high
            $table->string('applies_to')->default('out');   // in | out | both
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('canned_replies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('whatsapp_number_id')->nullable()->constrained()->nullOnDelete();
            $table->string('shortcut')->nullable();         // e.g. /hours
            $table->string('title');
            $table->text('body');
            $table->timestamps();
        });

        // Who did what — immutable monitoring trail.
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action')->index();              // viewed_conversation | sent_message | login | ...
            $table->string('subject_type')->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->json('meta')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->nullable()->index();
        });

        // Raw inbound webhook payloads — idempotency + debugging.
        Schema::create('webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('event_id')->nullable()->index(); // for de-dup where Meta provides one
            $table->json('payload');
            $table->timestamp('processed_at')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_events');
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('canned_replies');
        Schema::dropIfExists('flag_rules');
        Schema::dropIfExists('message_flags');
        Schema::dropIfExists('messages');
        Schema::dropIfExists('conversations');
        Schema::dropIfExists('contacts');
        Schema::dropIfExists('number_user');
        Schema::dropIfExists('whatsapp_numbers');
    }
};
