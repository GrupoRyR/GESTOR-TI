<?php

namespace Tests\Feature;

use App\Support\DeployedRevision;
use Tests\TestCase;

class DeployedRevisionTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        parent::setUp();

        $this->file = sys_get_temp_dir().DIRECTORY_SEPARATOR.'gestor-revision-'.uniqid().'.txt';
        config(['gestor.revision_file' => $this->file]);
    }

    protected function tearDown(): void
    {
        @unlink($this->file);

        parent::tearDown();
    }

    public function test_reads_the_commit_and_date_written_by_the_deploy(): void
    {
        file_put_contents($this->file, "0123456789abcdef0123456789abcdef01234567 2026-10-20T18:00:00Z\n");

        $this->assertSame(
            ['commit' => '0123456789abcdef0123456789abcdef01234567', 'short' => '0123456', 'deployed_at' => '2026-10-20T18:00:00Z'],
            DeployedRevision::current(),
        );
    }

    public function test_without_a_revision_file_there_is_no_revision(): void
    {
        $this->assertNull(DeployedRevision::current());
    }

    public function test_about_shows_the_deployed_commit(): void
    {
        file_put_contents($this->file, "0123456789abcdef0123456789abcdef01234567 2026-10-20T18:00:00Z\n");

        $this->artisan('about', ['--only' => 'gestor_ti'])
            ->expectsOutputToContain('0123456')
            ->expectsOutputToContain('2026-10-20T18:00:00Z')
            ->assertSuccessful();
    }
}
