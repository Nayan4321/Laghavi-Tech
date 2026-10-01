<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PagesRenderTest extends TestCase
{
    use RefreshDatabase;

    protected function owner(): User
    {
        return User::create([
            'name' => 'Owner', 'email' => 'owner@test.com', 'password' => 'password',
            'role' => 'owner', 'is_active' => true,
        ]);
    }

    public function test_guest_pages_render(): void
    {
        $this->get('/login')->assertOk()->assertSee('Log in');
        // Installer redirects to login once an owner exists.
        $this->owner();
        $this->get('/install')->assertRedirect('/login');
    }

    public function test_owner_pages_render(): void
    {
        $owner = $this->owner();

        foreach ([
            '/inbox',
            '/supervisor',
            '/supervisor/agents',
            '/supervisor/conversations',
            '/supervisor/flags',
            '/supervisor/audit',
            '/settings/numbers',
            '/settings/numbers/create',
            '/settings/users',
            '/settings/users/create',
            '/settings/flag-rules',
            '/settings/flag-rules/create',
            '/settings/canned',
            '/settings/canned/create',
        ] as $url) {
            $this->actingAs($owner)->get($url)->assertOk();
        }
    }

    public function test_agent_cannot_reach_settings(): void
    {
        $agent = User::create([
            'name' => 'Agent', 'email' => 'agent@test.com', 'password' => 'password',
            'role' => 'agent', 'is_active' => true,
        ]);

        $this->actingAs($agent)->get('/settings/numbers')->assertForbidden();
        $this->actingAs($agent)->get('/supervisor')->assertForbidden();
        $this->actingAs($agent)->get('/inbox')->assertOk();
    }
}
