<?php

namespace Tests\Feature\Ai\Requester;

use App\Domain\Ai\Roadmap;
use App\Enums\Ai\RequestStatus;
use App\Models\AiRequest;
use App\Models\AiRequestVote;
use App\Models\User;
use App\Support\Tenancy\CurrentCompany;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * `Ai/Roadmap/Index` (RoadmapIndexProps) and the roadmap rank rule.
 */
class RoadmapTest extends RequesterTestCase
{
    private function queued(RequestStatus $status, int $priority, array $attributes = []): AiRequest
    {
        return $this->request([
            'status' => $status,
            'first_responded_at' => now(),
            'priority_score' => $priority,
            'final_priority' => $priority,
            ...$attributes,
        ]);
    }

    public function test_columns_ranks_and_exact_props(): void
    {
        $building = $this->queued(RequestStatus::InDevelopment, 50);
        $testing = $this->queued(RequestStatus::InTesting, 40);
        $approved = $this->queued(RequestStatus::Approved, 70);
        $planned = $this->queued(RequestStatus::Planned, 60);
        $low = $this->queued(RequestStatus::Backlog, 30);
        $high = $this->queued(RequestStatus::PendingApproval, 80, ['requester_id' => $this->juan->id, 'created_by_id' => $this->juan->id]);
        $compras = $this->queued(RequestStatus::Backlog, 10, ['area_id' => $this->compras->id]);
        $this->queued(RequestStatus::Received, 99);
        $this->queued(RequestStatus::InProduction, 99);
        $this->queued(RequestStatus::Backlog, 99, [
            'company_id' => $this->derivados->id, 'area_id' => $this->derivadosVentas->id,
            'requester_id' => $this->derivadosUser->id, 'created_by_id' => $this->derivadosUser->id,
        ]);
        AiRequestVote::factory()->create(['ai_request_id' => $high->id, 'user_id' => $this->paola->id]);
        $high->forceFill(['votes_count' => 1])->save();

        $this->actingAs($this->paola)
            ->get(route('ai.roadmap.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Ai/Roadmap/Index', false)
                ->where('columns.now', fn ($rows) => collect($rows)->pluck('key')->all() === [$building->key, $testing->key])
                ->where('columns.next', fn ($rows) => collect($rows)->pluck('key')->all() === [$approved->key, $planned->key])
                ->where('columns.later', fn ($rows) => collect($rows)->pluck('key')->all() === [$high->key, $low->key, $compras->key])
                ->has('columns.later.0', fn (Assert $item) => $item
                    ->where('key', $high->key)
                    ->where('title', $high->title)
                    ->where('area', ['id' => $this->ventas->id, 'name' => 'Ventas'])
                    ->where('company_id', $this->segurex->id)
                    ->where('status', 'pending_approval')
                    ->where('votes', 1)
                    ->where('mine', false)
                    ->where('voted', true)
                    ->where('note', 'Puesto 1 en Ventas')
                    ->where('planned_start', null)
                )
                ->where('columns.later.1.note', 'Puesto 2 en Ventas')
                ->where('columns.later.1.mine', true)
                ->where('columns.later.2.note', 'Puesto 1 en Compras')
                ->where('columns.now.1.note', 'Ventas la está probando')
                ->where('columns.next.0.note', 'Empieza cuando se libere un cupo')
                ->where('filters', ['area_id' => null, 'company_id' => null, 'mine' => false])
                ->where('areas', [['id' => $this->compras->id, 'name' => 'Compras'], ['id' => $this->ventas->id, 'name' => 'Ventas']])
                ->has('roadmap_companies', 1, fn (Assert $company) => $company
                    ->where('id', $this->segurex->id)->where('name', 'Segurex Latam')->has('short_name')->has('colors')
                )
                ->where('later_total', 3)
                ->where('wip_limit', 3)
                ->where('mine_count', 6)
                ->has('updated_at')
            );

        $this->assertEqualsCanonicalizing(
            [...self::SHARED, 'columns', 'filters', 'areas', 'roadmap_companies', 'later_total', 'wip_limit', 'mine_count', 'updated_at'],
            $this->propKeys(route('ai.roadmap.index')),
        );

        $this->assertSame(1, app(Roadmap::class)->rank($high));
        $this->assertSame(2, app(Roadmap::class)->rank($low));
        $this->assertNull(app(Roadmap::class)->rank($approved));
    }

    public function test_rank_ties_go_to_votes_then_oldest(): void
    {
        $older = $this->queued(RequestStatus::Backlog, 50, ['created_at' => now()->subDays(3)]);
        $newer = $this->queued(RequestStatus::Backlog, 50);
        $voted = $this->queued(RequestStatus::Backlog, 50, ['votes_count' => 2, 'created_at' => now()->subDay()]);
        $unrated = $this->queued(RequestStatus::Backlog, 0, ['final_priority' => null, 'priority_score' => null]);

        $roadmap = app(Roadmap::class);
        $this->assertSame([1, 2, 3, 4], [$roadmap->rank($voted), $roadmap->rank($older), $roadmap->rank($newer), $roadmap->rank($unrated)]);
    }

    public function test_filters_area_and_mine(): void
    {
        $mine = $this->queued(RequestStatus::Backlog, 30);
        $this->queued(RequestStatus::Backlog, 40, ['requester_id' => $this->juan->id, 'created_by_id' => $this->juan->id]);
        $compras = $this->queued(RequestStatus::Backlog, 10, ['area_id' => $this->compras->id, 'requester_id' => $this->juan->id, 'created_by_id' => $this->juan->id]);

        $this->actingAs($this->paola)
            ->get(route('ai.roadmap.index', ['mine' => 1]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.mine', true)
                ->where('columns.later', fn ($rows) => collect($rows)->pluck('key')->all() === [$mine->key])
                ->where('columns.later.0.note', 'Puesto 2 en Ventas')
                ->where('mine_count', 1)
                ->where('later_total', 1)
            );

        $this->actingAs($this->paola)
            ->get(route('ai.roadmap.index', ['area_id' => $this->compras->id]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.area_id', $this->compras->id)
                ->where('columns.later', fn ($rows) => collect($rows)->pluck('key')->all() === [$compras->key])
            );
    }

    public function test_view_all_users_filter_by_company(): void
    {
        $segurex = $this->queued(RequestStatus::Backlog, 30);
        $derivados = $this->queued(RequestStatus::Backlog, 30, [
            'company_id' => $this->derivados->id, 'area_id' => $this->derivadosVentas->id,
            'requester_id' => $this->derivadosUser->id, 'created_by_id' => $this->derivadosUser->id,
        ]);
        // Súper Admin (`companies.view_all`) on "Todas".
        $this->actingAs($this->superAdmin)
            ->withSession([CurrentCompany::SESSION_KEY => null])
            ->get(route('ai.roadmap.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('columns.later', fn ($rows) => collect($rows)->pluck('key')->all() === [$segurex->key, $derivados->key])
                ->has('roadmap_companies', 2)
                ->where('areas', fn ($areas) => collect($areas)->pluck('name')->sort()->values()->all() === collect([
                    'Ventas · '.$this->derivados->short_name,
                    'Ventas · '.$this->segurex->short_name,
                ])->sort()->values()->all())
            );

        $this->actingAs($this->superAdmin)
            ->withSession([CurrentCompany::SESSION_KEY => null])
            ->get(route('ai.roadmap.index', ['company_id' => $this->derivados->id]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.company_id', $this->derivados->id)
                ->where('columns.later', fn ($rows) => collect($rows)->pluck('key')->all() === [$derivados->key])
            );
    }

    public function test_roadmap_needs_the_module_permission(): void
    {
        $nobody = User::factory()->create(['company_id' => $this->segurex->id]);

        $this->actingAs($nobody)->get(route('ai.roadmap.index'))->assertForbidden();
        $this->actingAs($this->jhon)->get(route('ai.roadmap.index'))->assertOk();
    }
}
