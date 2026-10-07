<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SharedPropsTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_receives_empty_shared_props(): void
    {
        $this->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('auth.user', null)
                ->where('companies', [])
                ->where('current_company_id', null)
                ->where('notifications_unread', 0)
                ->where('modules', [])
                ->has('flash', fn (Assert $flash) => $flash
                    ->where('success', null)
                    ->where('error', null)
                )
            );
    }

    public function test_authenticated_user_has_exact_auth_user_shape(): void
    {
        Permission::create(['name' => 'assistant.use']);
        Role::create(['name' => 'Usuario final'])->givePermissionTo('assistant.use');

        $user = User::factory()->create(['name' => 'Ana Pérez', 'email' => 'ana@segurex.com']);
        $user->assignRole('Usuario final');

        // `has` with a scoped closure fails on any unexpected key, so this
        // pins the exact contract of section 7.1.
        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('auth.user', fn (Assert $authUser) => $authUser
                    ->where('id', $user->id)
                    ->where('name', 'Ana Pérez')
                    ->where('email', 'ana@segurex.com')
                    ->where('avatar_url', null)
                    ->where('company_id', null)
                    ->where('roles', ['Usuario final'])
                    ->where('permissions', ['assistant.use'])
                )
            );
    }

    public function test_notifications_unread_counts_only_unread_database_notifications(): void
    {
        $user = User::factory()->create();

        foreach ([null, null, now()] as $readAt) {
            DatabaseNotification::create([
                'id' => (string) Str::uuid(),
                'type' => 'App\Notifications\Example',
                'notifiable_type' => $user->getMorphClass(),
                'notifiable_id' => $user->id,
                'data' => ['message' => 'Hola'],
                'read_at' => $readAt,
            ]);
        }

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page->where('notifications_unread', 2));
    }
}
