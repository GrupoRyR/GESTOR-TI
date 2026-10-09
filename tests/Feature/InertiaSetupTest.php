<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Inertia wiring checked on the login page (the only Inertia page a guest
 * can reach since A5 protected the home page with `auth`).
 */
class InertiaSetupTest extends TestCase
{
    use RefreshDatabase;

    public function test_root_is_the_home_page_and_requires_login(): void
    {
        $this->assertSame(url('/'), route('home'));
        $this->get('/')->assertRedirect(route('login'));
    }

    public function test_login_renders_inertia_page_with_flash_prop(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Auth/Login', false)
                ->has('flash', fn (Assert $flash) => $flash
                    ->where('success', null)
                    ->where('error', null)
                )
            );
    }

    public function test_flash_prop_exposes_session_messages(): void
    {
        $this->withSession(['success' => 'Guardado correctamente'])
            ->get(route('login'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('flash.success', 'Guardado correctamente')
                ->where('flash.error', null)
            );
    }
}
