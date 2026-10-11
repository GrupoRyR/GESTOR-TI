<?php

namespace Tests\Feature\Notifications;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class NotificationsInboxTest extends TestCase
{
    use RefreshDatabase;

    private function notify(User $user, string $title, ?string $readAt = null): string
    {
        $id = (string) Str::uuid();

        $user->notifications()->create([
            'id' => $id,
            'type' => 'App\\Notifications\\AlertRaised',
            'data' => ['title' => $title],
            'read_at' => $readAt,
        ]);

        return $id;
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get(route('notifications.index'))->assertRedirect(route('login'));
        $this->post(route('notifications.read_all'))->assertRedirect(route('login'));
    }

    public function test_index_lists_only_the_user_notifications_paginated(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $first = $this->notify($user, 'Primera');
        $this->travel(1)->minutes();
        $this->notify($user, 'Segunda', now()->toDateTimeString());
        $this->notify($other, 'Ajena');

        $this->actingAs($user)
            ->get(route('notifications.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Notifications/Index')
                ->has('notifications.data', 2)
                ->has('notifications.data.0', fn (Assert $item) => $item
                    ->where('data.title', 'Segunda')
                    ->whereNot('read_at', null)
                    ->hasAll(['id', 'type', 'data', 'read_at', 'created_at']))
                ->where('notifications.data.1.id', $first)
                ->where('notifications.data.1.read_at', null)
                ->has('notifications.links')
                ->where('notifications.meta.total', 2)
                ->where('notifications.meta.current_page', 1)
                ->has('notifications.meta.per_page')
                ->has('notifications.meta.last_page')
                ->where('notifications_unread', 1));
    }

    public function test_read_marks_one_notification_as_read(): void
    {
        $user = User::factory()->create();
        $id = $this->notify($user, 'Primera');
        $this->notify($user, 'Segunda');

        $this->actingAs($user)
            ->from(route('notifications.index'))
            ->post(route('notifications.read'), ['id' => $id])
            ->assertRedirect(route('notifications.index'));

        $this->assertNotNull($user->notifications()->find($id)->read_at);
        $this->assertSame(1, $user->unreadNotifications()->count());
    }

    public function test_read_cannot_touch_another_user_notification(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $foreign = $this->notify($other, 'Ajena');

        $this->actingAs($user)->post(route('notifications.read'), ['id' => $foreign])->assertNotFound();
        $this->actingAs($user)->post(route('notifications.read'), [])->assertSessionHasErrors('id');

        $this->assertNull($other->notifications()->find($foreign)->read_at);
    }

    public function test_read_all_marks_every_unread_notification(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $this->notify($user, 'Primera');
        $this->notify($user, 'Segunda');
        $this->notify($other, 'Ajena');

        $this->actingAs($user)->post(route('notifications.read_all'))->assertRedirect();

        $this->assertSame(0, $user->unreadNotifications()->count());
        $this->assertSame(1, $other->unreadNotifications()->count());
    }
}
