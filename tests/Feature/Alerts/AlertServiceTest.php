<?php

namespace Tests\Feature\Alerts;

use App\Models\Alert;
use App\Models\Company;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Notifications\AlertRaised;
use App\Services\Alerts\AlertService;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class AlertServiceTest extends TestCase
{
    use RefreshDatabase;

    private Company $segurex;

    private Company $derivados;

    private User $globalManager;

    private User $segurexManager;

    private User $derivadosManager;

    private User $plain;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        Permission::create(['name' => 'ai.manage']);
        Permission::create(['name' => 'companies.view_all']);
        $leader = Role::create(['name' => 'Líder IA']);
        $leader->givePermissionTo(['ai.manage', 'companies.view_all']);

        $this->segurex = Company::factory()->create(['sender_email' => 'segurex.info@segurex.com']);
        $this->derivados = Company::factory()->create();

        $this->globalManager = User::factory()->create(['company_id' => $this->segurex->id]);
        $this->globalManager->assignRole($leader);
        $this->segurexManager = User::factory()->create(['company_id' => $this->segurex->id]);
        $this->segurexManager->givePermissionTo('ai.manage');
        $this->derivadosManager = User::factory()->create(['company_id' => $this->derivados->id]);
        $this->derivadosManager->givePermissionTo('ai.manage');
        $this->plain = User::factory()->create(['company_id' => $this->segurex->id]);
    }

    private function alerts(): AlertService
    {
        return app(AlertService::class);
    }

    public function test_raise_creates_the_alert_and_notifies_the_company_and_global_managers(): void
    {
        $subject = $this->segurexManager;

        $alert = $this->alerts()->raise(
            Alert::LEVEL_CRITICAL,
            'ai',
            'Solicitud sin primera respuesta',
            'GIA-12 lleva 3 días hábiles sin respuesta.',
            $this->segurex,
            $subject,
            'ai.first_response:GIA-12',
        );

        $this->assertDatabaseHas('alerts', [
            'id' => $alert->id,
            'company_id' => $this->segurex->id,
            'level' => 'critical',
            'source' => 'ai',
            'source_type' => $subject->getMorphClass(),
            'source_id' => $subject->id,
            'group_key' => 'ai.first_response:GIA-12',
            'occurrences' => 1,
            'resolved_at' => null,
        ]);
        $this->assertNotNull($alert->first_seen_at);
        $this->assertNotNull($alert->last_notified_at);

        Notification::assertSentTo([$this->globalManager, $this->segurexManager], AlertRaised::class,
            fn (AlertRaised $n) => $n->alert->is($alert) && $n->eventType() === 'alert.critical' && $n->company()?->is($this->segurex));
        Notification::assertNotSentTo([$this->derivadosManager, $this->plain], AlertRaised::class);
    }

    public function test_global_alerts_notify_every_manager(): void
    {
        $this->alerts()->raise(Alert::LEVEL_INFO, 'vault', 'Bóveda consultada');

        Notification::assertSentTo([$this->globalManager, $this->segurexManager, $this->derivadosManager], AlertRaised::class,
            fn (AlertRaised $n) => $n->eventType() === 'alert.info');
        Notification::assertNotSentTo($this->plain, AlertRaised::class);
    }

    public function test_explicit_recipients_override_the_default(): void
    {
        $this->alerts()->raise(Alert::LEVEL_WARNING, 'vault', 'Acceso a la bóveda', recipients: [$this->plain]);

        Notification::assertSentTo($this->plain, AlertRaised::class);
        Notification::assertNotSentTo($this->globalManager, AlertRaised::class);
    }

    public function test_the_recipient_permission_is_configurable(): void
    {
        Permission::create(['name' => 'monitoring.view']);
        $this->plain->givePermissionTo('monitoring.view');
        config(['gestor.alerts.recipient_permission' => 'monitoring.view']);

        $this->alerts()->raise(Alert::LEVEL_WARNING, 'monitoring', 'App sin latido', company: $this->segurex);

        Notification::assertSentTo($this->plain, AlertRaised::class);
        Notification::assertNotSentTo($this->segurexManager, AlertRaised::class);
    }

    public function test_a_missing_recipient_permission_notifies_nobody(): void
    {
        config(['gestor.alerts.recipient_permission' => 'does.not.exist']);

        $alert = $this->alerts()->raise(Alert::LEVEL_INFO, 'ai', 'Sin destinatarios');

        $this->assertTrue($alert->exists);
        Notification::assertNothingSent();
    }

    public function test_grouped_alerts_increment_without_renotifying_inside_the_cooldown(): void
    {
        $first = $this->alerts()->raise(Alert::LEVEL_WARNING, 'ai', 'Tope de gasto al 80 %', groupKey: 'ai.cost_cap');
        $this->travel(30)->minutes();
        $second = $this->alerts()->raise(Alert::LEVEL_WARNING, 'ai', 'Tope de gasto al 85 %', groupKey: 'ai.cost_cap');

        $this->assertTrue($first->is($second));
        $this->assertSame(1, Alert::query()->count());
        $this->assertSame(2, $second->occurrences);
        $this->assertSame('Tope de gasto al 85 %', $second->title);
        $this->assertTrue($second->last_seen_at->greaterThan($second->first_seen_at));
        Notification::assertSentToTimes($this->globalManager, AlertRaised::class, 1);
    }

    public function test_grouped_alerts_renotify_after_the_cooldown(): void
    {
        app(Settings::class)->set('alerts.cooldown_minutes', 10);

        $this->alerts()->raise(Alert::LEVEL_WARNING, 'ai', 'Tope', groupKey: 'ai.cost_cap');
        $this->travel(11)->minutes();
        $alert = $this->alerts()->raise(Alert::LEVEL_WARNING, 'ai', 'Tope', groupKey: 'ai.cost_cap');

        $this->assertSame(2, $alert->occurrences);
        Notification::assertSentToTimes($this->globalManager, AlertRaised::class, 2);
    }

    public function test_a_more_severe_occurrence_escalates_the_level(): void
    {
        $this->alerts()->raise(Alert::LEVEL_WARNING, 'ai', 'Tope', groupKey: 'ai.cost_cap');
        $alert = $this->alerts()->raise(Alert::LEVEL_CRITICAL, 'ai', 'Tope al 100 %', groupKey: 'ai.cost_cap');

        $this->assertSame('critical', $alert->level);

        $alert = $this->alerts()->raise(Alert::LEVEL_INFO, 'ai', 'Tope', groupKey: 'ai.cost_cap');
        $this->assertSame('critical', $alert->level);
    }

    public function test_resolve_closes_the_group_and_a_new_raise_starts_over(): void
    {
        $first = $this->alerts()->raise(Alert::LEVEL_WARNING, 'ai', 'Tope', groupKey: 'ai.cost_cap');

        $this->assertSame(1, $this->alerts()->resolve('ai.cost_cap'));
        $this->assertNotNull($first->fresh()->resolved_at);
        $this->assertSame(0, $this->alerts()->resolve('ai.cost_cap'));

        $second = $this->alerts()->raise(Alert::LEVEL_WARNING, 'ai', 'Tope', groupKey: 'ai.cost_cap');

        $this->assertFalse($first->is($second));
        $this->assertSame(1, $second->occurrences);
        Notification::assertSentToTimes($this->globalManager, AlertRaised::class, 2);
    }

    public function test_alerts_without_group_key_are_never_merged(): void
    {
        $this->alerts()->raise(Alert::LEVEL_INFO, 'ai', 'Uno');
        $this->alerts()->raise(Alert::LEVEL_INFO, 'ai', 'Uno');

        $this->assertSame(2, Alert::query()->count());
    }

    public function test_invalid_levels_are_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->alerts()->raise('panic', 'ai', 'x');
    }

    public function test_the_notification_renders_in_spanish_with_the_company_sender(): void
    {
        $alert = $this->alerts()->raise(Alert::LEVEL_CRITICAL, 'ai', 'Solicitud vencida', 'GIA-12', $this->segurex);
        $notification = new AlertRaised($alert);

        $mail = $notification->toMail($this->segurexManager);
        $this->assertSame('segurex.info@segurex.com', $mail->from[0]);
        $this->assertStringContainsString('Crítica', $mail->subject);
        $this->assertStringContainsString('Solicitud vencida', $notification->toTelegram($this->segurexManager)->text);
        $this->assertSame([
            'alert_id' => $alert->id,
            'level' => 'critical',
            'source' => 'ai',
            'title' => 'Solicitud vencida',
            'body' => 'GIA-12',
            'company_id' => $this->segurex->id,
        ], $notification->toArray($this->segurexManager));
    }
}
