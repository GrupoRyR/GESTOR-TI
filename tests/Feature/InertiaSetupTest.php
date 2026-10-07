<?php

namespace Tests\Feature;

use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class InertiaSetupTest extends TestCase
{
    public function test_root_redirects_to_dashboard(): void
    {
        $this->get('/')->assertRedirect(route('dashboard'));
    }

    public function test_dashboard_renders_inertia_page_with_flash_prop(): void
    {
        $this->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard')
                ->has('flash', fn (Assert $flash) => $flash
                    ->where('success', null)
                    ->where('error', null)
                )
            );
    }

    public function test_flash_prop_exposes_session_messages(): void
    {
        $this->withSession(['success' => 'Guardado correctamente'])
            ->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('flash.success', 'Guardado correctamente')
                ->where('flash.error', null)
            );
    }
}
