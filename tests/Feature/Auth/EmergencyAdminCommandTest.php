<?php

namespace Tests\Feature\Auth;

use App\Actions\Auth\GenerateEmergencyPassword;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class EmergencyAdminCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    /**
     * @return array{0: int, 1: string}
     */
    private function runCommand(string $email): array
    {
        $exit = Artisan::call('gestor:emergency-admin', ['email' => $email]);
        $output = Artisan::output();

        preg_match('/^\s*Contraseña: (\S+)\s*$/m', $output, $matches);

        return [$exit, $matches[1] ?? ''];
    }

    public function test_creates_super_admin_and_prints_a_strong_password_once(): void
    {
        [$exit, $password] = $this->runCommand('Emergencia@Example.com');

        $this->assertSame(0, $exit);
        $this->assertGreaterThanOrEqual(20, strlen($password));

        $user = User::where('email', 'emergencia@example.com')->firstOrFail();
        $this->assertTrue(Hash::check($password, $user->password));
        $this->assertTrue($user->hasRole('Súper Admin'));
        $this->assertTrue($user->is_global);
        $this->assertNull($user->azure_id);
        $this->assertStringNotContainsString($password, (string) json_encode(Activity::all()));
    }

    public function test_password_is_printed_verbatim_even_with_console_tags(): void
    {
        $tricky = 'aB3<info>x</info>\\<error>y\\Z9!<comment>q</>w\\';
        $this->app->instance(GenerateEmergencyPassword::class, new class($tricky) extends GenerateEmergencyPassword
        {
            public function __construct(private readonly string $fixed) {}

            public function __invoke(): string
            {
                return $this->fixed;
            }
        });

        [$exit, $password] = $this->runCommand('emergencia@example.com');

        $this->assertSame(0, $exit);
        $this->assertSame($tricky, $password);
        $this->assertTrue(Hash::check($tricky, User::where('email', 'emergencia@example.com')->firstOrFail()->password));
    }

    public function test_generated_passwords_are_strong(): void
    {
        $generate = new GenerateEmergencyPassword;

        foreach (range(1, 20) as $ignored) {
            $password = $generate();

            $this->assertGreaterThanOrEqual(20, strlen($password));
            $this->assertMatchesRegularExpression('/[^A-Za-z0-9]/', $password);
            $this->assertDoesNotMatchRegularExpression('/\s/', $password);
        }
    }

    public function test_second_run_rotates_the_password_and_unlocks(): void
    {
        [, $first] = $this->runCommand('emergencia@example.com');
        User::where('email', 'emergencia@example.com')->firstOrFail()
            ->forceFill(['failed_login_attempts' => 5, 'locked_until' => now()->addMinutes(15)])->save();

        [$exit, $second] = $this->runCommand('emergencia@example.com');

        $this->assertSame(0, $exit);
        $this->assertNotSame($first, $second);
        $this->assertSame(1, User::where('email', 'emergencia@example.com')->count());

        $user = User::where('email', 'emergencia@example.com')->firstOrFail();
        $this->assertTrue(Hash::check($second, $user->password));
        $this->assertFalse(Hash::check($first, $user->password));
        $this->assertSame(0, $user->failed_login_attempts);
        $this->assertNull($user->locked_until);
    }

    public function test_rejects_invalid_email(): void
    {
        [$exit] = $this->runCommand('no-es-un-correo');

        $this->assertNotSame(0, $exit);
        $this->assertSame(0, User::count());
    }
}
