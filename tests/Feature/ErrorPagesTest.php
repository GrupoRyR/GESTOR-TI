<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Exception renderer of bootstrap/app.php: `Errors/Error` with `{ status }`
 * when `gestor.error_pages` is on (default: whenever debug is off).
 */
class ErrorPagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['gestor.error_pages' => true]);

        Route::middleware('web')->group(function () {
            Route::get('/_test/forbidden', fn () => abort(403));
            Route::get('/_test/session-expired', fn () => throw new TokenMismatchException);
            Route::get('/_test/broken', fn () => throw new RuntimeException('boom'));
            Route::get('/_test/maintenance', fn () => throw new HttpException(503));
            Route::get('/_test/teapot', fn () => abort(418));
        });
    }

    public function test_unknown_page_renders_the_404_error_page(): void
    {
        $this->get('/no-such-page')
            ->assertNotFound()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Errors/Error', false)
                ->where('status', 404)
            );
    }

    public function test_error_pages_carry_shared_props_when_available(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/_test/forbidden')
            ->assertForbidden()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Errors/Error', false)
                ->where('status', 403)
                ->where('auth.user.id', $user->id)
                ->has('modules')
            );
    }

    public function test_server_maintenance_and_session_errors_render_their_status(): void
    {
        foreach (['/_test/broken' => 500, '/_test/maintenance' => 503, '/_test/session-expired' => 419] as $uri => $status) {
            $this->get($uri)
                ->assertStatus($status)
                ->assertInertia(fn (Assert $page) => $page
                    ->component('Errors/Error', false)
                    ->where('status', $status)
                );
        }
    }

    public function test_other_statuses_keep_the_default_response(): void
    {
        // Debug off: Laravel's plain error view, not the debug page.
        config(['app.debug' => false]);

        $response = $this->get('/_test/teapot')->assertStatus(418);

        $this->assertStringNotContainsString('Errors\/Error', (string) $response->getContent());
        $this->assertStringNotContainsString('Errors/Error', (string) $response->getContent());
    }

    public function test_json_requests_still_get_json(): void
    {
        $this->getJson('/no-such-page')
            ->assertNotFound()
            ->assertJsonStructure(['message']);

        $this->get('/api/no-such-endpoint')
            ->assertNotFound()
            ->assertHeader('Content-Type', 'application/json');
    }

    public function test_disabled_flag_keeps_the_default_renderer(): void
    {
        config(['gestor.error_pages' => false, 'app.debug' => false]);

        $response = $this->get('/no-such-page')->assertNotFound();

        $this->assertStringNotContainsString('Errors\/Error', (string) $response->getContent());
        $this->assertStringNotContainsString('Errors/Error', (string) $response->getContent());
    }
}
