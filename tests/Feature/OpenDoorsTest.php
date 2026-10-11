<?php

namespace Tests\Feature;

use App\Models\AiInteraction;
use App\Models\Company;
use App\Models\MonitoredApp;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

class OpenDoorsTest extends TestCase
{
    use RefreshDatabase;

    public function test_reserved_api_prefixes_are_not_used_by_any_route(): void
    {
        $reserved = ['api/local-apps/v1', 'api/runner/v1', 'api/monitors'];

        foreach (Route::getRoutes() as $route) {
            foreach ($reserved as $prefix) {
                $this->assertFalse(
                    Str::startsWith($route->uri(), $prefix),
                    "La ruta {$route->uri()} usa el prefijo reservado {$prefix}.",
                );
            }
        }
    }

    public function test_reserved_prefixes_are_documented_in_the_api_routes_file(): void
    {
        $file = file_get_contents(base_path('routes/api.php'));

        foreach (['/api/local-apps/v1', '/api/runner/v1', '/api/monitors/{provider}/{secret}'] as $prefix) {
            $this->assertStringContainsString($prefix, $file);
        }
    }

    public function test_monitored_apps_can_be_stored_with_optional_columns(): void
    {
        $company = Company::factory()->create();

        $app = MonitoredApp::create([
            'company_id' => $company->id,
            'slug' => 'robotcamara',
            'name' => 'Robot cámara',
            'type' => MonitoredApp::TYPE_LOCAL,
            'criticality' => 'high',
        ]);

        $app->refresh();
        $this->assertSame(300, $app->heartbeat_interval_s);
        $this->assertNull($app->last_heartbeat_at);
        $this->assertNull($app->ai_product_id);
        $this->assertTrue($app->company->is($company));

        $global = MonitoredApp::create(['slug' => 'portal', 'name' => 'Portal', 'type' => MonitoredApp::TYPE_WEB, 'url' => 'https://portal.test']);
        $this->assertNull($global->fresh()->company_id);
        $this->assertSame('medium', $global->fresh()->criticality);
    }

    public function test_ai_interactions_can_be_stored(): void
    {
        $user = User::factory()->create();

        $interaction = AiInteraction::create([
            'user_id' => $user->id,
            'provider' => 'deepseek',
            'model' => 'deepseek-chat',
            'feature' => 'assistant',
            'input_tokens' => 1200,
            'cached_input_tokens' => 800,
            'output_tokens' => 300,
            'cost' => '0.000512',
            'currency' => 'USD',
            'duration_ms' => 1530,
            'status' => 'ok',
        ])->fresh();

        $this->assertSame(800, $interaction->cached_input_tokens);
        $this->assertSame('0.000512', $interaction->cost);
        $this->assertTrue($interaction->user->is($user));
        $this->assertNull($interaction->company_id);
    }
}
