<?php

namespace Database\Seeders;

use App\Domain\Ai\RequestKeys;
use App\Enums\Ai\AcceptanceResult;
use App\Enums\Ai\ApprovalDecision;
use App\Enums\Ai\ApprovalKind;
use App\Enums\Ai\CommentVisibility;
use App\Enums\Ai\EffortSize;
use App\Enums\Ai\RequestStatus;
use App\Enums\Ai\RequestType;
use App\Enums\Ai\RiskLevel;
use App\Enums\Ai\Urgency;
use App\Models\AiProject;
use App\Models\AiRequest;
use App\Models\AiRequirement;
use App\Models\AiTask;
use App\Models\Area;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Local-development sample of Gestor IA requests, shaped like the design
 * fixtures (resources/js/mocks/ai-fixtures.ts). NOT part of DatabaseSeeder:
 * run it by hand (`php artisan db:seed --class=AiDemoSeeder`) after
 * DatabaseSeeder. Idempotent by key; demo people use `@demo.local` emails.
 */
class AiDemoSeeder extends Seeder
{
    public function run(): void
    {
        $company = Company::query()->orderBy('id')->first();

        if ($company === null) {
            $this->command?->warn('No hay empresas: corre primero DatabaseSeeder.');

            return;
        }

        $areas = [];
        foreach (['Ventas', 'Compras', 'Tesorería', 'Cartera', 'Diseño'] as $name) {
            $areas[$name] = Area::withoutGlobalScopes()->firstOrCreate(
                ['company_id' => $company->id, 'slug' => Str::slug($name)],
                ['name' => $name, 'is_active' => true],
            );
        }

        $jhon = $this->person($company, 'Jhon Castro (demo)', 'jhon', 'Líder IA', null);
        $edward = $this->person($company, 'Edward Ramanan (demo)', 'edward', 'Aprobador de proyectos', null);
        $paola = $this->person($company, 'Paola Ríos', 'paola', 'Usuario final', $areas['Ventas']);
        $andres = $this->person($company, 'Andrés Méndez', 'andres', 'Usuario final', $areas['Compras']);
        $monica = $this->person($company, 'Mónica Salazar', 'monica', 'Usuario final', $areas['Tesorería']);
        $diego = $this->person($company, 'Diego Restrepo', 'diego', 'Usuario final', $areas['Cartera']);
        $camila = $this->person($company, 'Camila Torres', 'camila', 'Usuario final', $areas['Diseño']);

        // Demo keys follow the fixtures (GIA-121…GIA-153).
        app(RequestKeys::class)->ensureAtLeast(120);

        $this->request('GIA-121', $company, $areas['Ventas'], $paola, [
            'type' => RequestType::Improvement,
            'title' => 'Alerta de pedidos bloqueados por cartera',
            'problem' => 'Cuando un pedido queda bloqueado por cartera el asesor no se entera hasta que el cliente llama.',
            'hours_per_week' => 4, 'people_count' => 6, 'urgency' => Urgency::High,
            'status' => RequestStatus::Backlog, 'size' => EffortSize::M, 'risk' => RiskLevel::Medium,
            'evaluated_by_id' => $jhon->id,
        ]);

        $gia146 = $this->request('GIA-146', $company, $areas['Compras'], $andres, [
            'type' => RequestType::Improvement,
            'title' => 'Portafolio de productos: exportar fichas técnicas en PDF',
            'problem' => 'Compras arma a mano las fichas técnicas en Word para enviarlas a los clientes.',
            'hours_per_week' => 3, 'people_count' => 4, 'urgency' => Urgency::High,
            'status' => RequestStatus::PendingApproval, 'size' => EffortSize::M, 'risk' => RiskLevel::Low,
            'evaluated_by_id' => $jhon->id,
        ]);
        $gia146->approvals()->firstOrCreate(['kind' => ApprovalKind::Project->value, 'user_id' => $edward->id], [
            'decision' => ApprovalDecision::Pending,
            'estimate' => ['hours_by_person' => [['person_id' => $jhon->id, 'hours' => 40]], 'hours_total' => 40, 'external_costs' => 0, 'due_date' => now()->addWeeks(3)->toDateString(), 'total_cost' => 6_400_000],
            'requested_by_id' => $jhon->id,
            'requested_at' => now()->subDay(),
        ]);

        $gia148 = $this->request('GIA-148', $company, $areas['Ventas'], $paola, [
            'type' => RequestType::Improvement,
            'title' => 'Toma Pedidos SAP: avisar al asesor cuando el pedido quede bloqueado por cartera',
            'problem' => 'El asesor no sabe que el pedido quedó bloqueado por cartera; se entera cuando el cliente reclama.',
            'hours_per_week' => 6, 'people_count' => 8, 'urgency' => Urgency::High,
            'status' => RequestStatus::Received, 'systems' => ['SAP Business One', 'Toma Pedidos SAP'],
        ]);
        $gia148->criteria()->firstOrCreate(['position' => 1], ['text' => 'El asesor recibe el aviso por Telegram en menos de 5 minutos']);
        foreach ([$andres, $diego, $monica] as $voter) {
            $gia148->votes()->firstOrCreate(['user_id' => $voter->id]);
        }

        $gia150 = $this->request('GIA-150', $company, $areas['Compras'], $andres, [
            'type' => RequestType::Improvement,
            'title' => 'Comparar precios de proveedores en el Portafolio',
            'problem' => 'Comparamos precios de proveedores en Excel copiando de los correos.',
            'hours_per_week' => 6, 'people_count' => 8, 'urgency' => Urgency::High,
            'status' => RequestStatus::NeedsInfo,
        ]);
        $gia150->comments()->firstOrCreate(['body' => '¿Cuántos proveedores comparan por semana?'], ['user_id' => $jhon->id, 'visibility' => CommentVisibility::Public]);
        $gia150->comments()->firstOrCreate(['body' => 'Revisar si el Portafolio ya guarda el histórico.'], ['user_id' => $jhon->id, 'visibility' => CommentVisibility::Internal]);

        $this->request('GIA-151', $company, $areas['Cartera'], $diego, [
            'type' => RequestType::Improvement,
            'title' => 'Visualizador de cartera: exportar a Excel la vista filtrada',
            'problem' => 'Para enviar la cartera filtrada a los asesores tenemos que copiarla a mano.',
            'hours_per_week' => 3, 'people_count' => 2, 'urgency' => Urgency::Medium,
        ]);

        $this->request('GIA-152', $company, $areas['Diseño'], $camila, [
            'type' => RequestType::Improvement,
            'title' => 'Portafolio: subir fotos de producto en lote',
            'problem' => 'Diseño sube las fotos de producto una por una al Portafolio.',
            'hours_per_week' => 2, 'people_count' => 1, 'urgency' => Urgency::Low,
        ]);

        $this->request('GIA-153', $company, $areas['Tesorería'], $monica, [
            'type' => RequestType::New,
            'title' => 'Flujo de caja semanal desde SAP B1',
            'problem' => 'Tesorería arma el flujo de caja semanal en Excel con datos que saca a mano de SAP B1.',
            'hours_per_week' => 4, 'people_count' => 2, 'urgency' => Urgency::Medium,
        ]);

        $testing = $this->request('GIA-144', $company, $areas['Tesorería'], $monica, [
            'type' => RequestType::New,
            'title' => 'Conciliación bancaria automática desde SAP B1',
            'problem' => 'Tesorería concilia los extractos bancarios contra SAP a mano cada mañana.',
            'hours_per_week' => 5, 'people_count' => 2, 'urgency' => Urgency::High,
            'status' => RequestStatus::InTesting, 'size' => EffortSize::L, 'risk' => RiskLevel::High,
            'evaluated_by_id' => $jhon->id,
        ]);
        $testing->criteria()->firstOrCreate(['position' => 1], ['text' => 'Concilia los movimientos del día anterior', 'result' => AcceptanceResult::Pass, 'validated_by_id' => $monica->id, 'validated_at' => now()]);
        $testing->criteria()->firstOrCreate(['position' => 2], ['text' => 'Marca las diferencias para revisar']);

        $gia139 = $this->request('GIA-139', $company, $areas['Cartera'], $diego, [
            'type' => RequestType::Improvement,
            'title' => 'Visualizador de cartera: filtrar por asesor y por zona',
            'problem' => 'La Gerencia de cartera pide a TI reportes por asesor y por zona cada semana.',
            'expected_result' => 'Que la Gerencia de cartera filtre la cartera por asesor y por zona sin pedir reportes a TI.',
            'hours_per_week' => 3, 'people_count' => 3, 'urgency' => Urgency::Medium,
            'status' => RequestStatus::InDevelopment, 'size' => EffortSize::M, 'risk' => RiskLevel::Low,
            'evaluated_by_id' => $jhon->id,
        ]);
        $this->project($gia139, $jhon, $diego, $camila);

        foreach (AiRequest::withoutGlobalScopes()->whereIn('key', ['GIA-121', 'GIA-139', 'GIA-144', 'GIA-146', 'GIA-148', 'GIA-150', 'GIA-151', 'GIA-152', 'GIA-153'])->get() as $request) {
            $request->votes_count = $request->votes()->count();
            $request->recomputePriority();
            $request->save();
        }
    }

    /**
     * Demo project of GIA-139 (B5 board): columns, tasks, a subtask list
     * and a requirement that blocks a task. Created once (next P-n key).
     */
    private function project(AiRequest $request, User $lead, User $recipient, User $designer): void
    {
        if ($request->projects()->exists()) {
            return;
        }

        $project = AiProject::create([
            'company_id' => $request->company_id,
            'area_id' => $request->area_id,
            'name' => 'Visualizador de cartera · filtros',
            'objective' => $request->expected_result,
            'status' => RequestStatus::InDevelopment,
            'lead_id' => $lead->id,
            'start_date' => now()->subDays(6)->toDateString(),
            'due_date' => now()->addWeeks(3)->toDateString(),
            'started_at' => now()->subDays(6),
            'approved_at' => now()->subDays(7),
        ]);
        $project->requests()->attach($request->id);
        $project->members()->create(['user_id' => $lead->id, 'role' => 'Líder del proyecto', 'position' => 0]);

        $tasks = [];
        foreach ([
            ['Diseñar filtros', 'done', ['Diseño']],
            ['Consulta SAP por asesor', 'done', ['SAP']],
            ['Endpoint de zonas', 'review', ['Backend']],
            ['Consulta de cartera por asesor desde SAP B1', 'in_progress', ['SAP', 'Backend']],
            ['Total vencido por rango de días', 'in_progress', ['SAP']],
            ['Pruebas con cartera real', 'todo', ['Pruebas']],
            ['Manual de usuario', 'backlog', ['Documentación']],
        ] as [$title, $column, $labels]) {
            $task = AiTask::create([
                'ai_project_id' => $project->id,
                'ai_project_column_id' => $project->column($column)->id,
                'title' => $title,
                'labels' => $labels,
                'due_date' => $column === 'done' ? null : now()->addDays(count($tasks) + 2)->toDateString(),
                'completed_at' => $column === 'done' ? now()->subDays(2) : null,
                'started_at' => in_array($column, ['done', 'review', 'in_progress'], true) ? now()->subDays(5) : null,
            ]);
            $task->assignees()->sync([$lead->id]);
            $tasks[$title] = $task;
        }

        $tasks['Consulta de cartera por asesor desde SAP B1']->subtasks()->createMany([
            ['title' => 'Conectar a la vista de cartera de SAP B1 en pruebas', 'done' => true, 'position' => 1],
            ['title' => 'Filtrar por código de asesor', 'done' => true, 'position' => 2],
            ['title' => 'Agrupar por rango de días vencidos', 'done' => false, 'position' => 3],
        ]);

        AiRequirement::create([
            'company_id' => $project->company_id,
            'ai_project_id' => $project->id,
            'title' => 'Lista de zonas y asesores vigentes',
            'recipient_id' => $recipient->id,
            'recipient_label' => $recipient->name,
            'area_id' => $request->area_id,
            'area_name' => 'Cartera',
            'due_date' => now()->addDays(2)->toDateString(),
            'blocks_task_id' => $tasks['Total vencido por rango de días']->id,
            'requested_by_id' => $lead->id,
        ]);
        AiRequirement::create([
            'company_id' => $project->company_id,
            'ai_project_id' => $project->id,
            'title' => 'Íconos para la vista en celular',
            'recipient_id' => $designer->id,
            'recipient_label' => $designer->name,
            'area_name' => 'Diseño',
            'due_date' => now()->subDay()->toDateString(),
            'requested_by_id' => $lead->id,
        ]);

        $project->recordActivity('task', 'movió T3 Endpoint de zonas a En revisión', $lead);
    }

    private function person(Company $company, string $name, string $handle, string $role, ?Area $area): User
    {
        $user = User::query()->firstOrCreate(['email' => $handle.'@demo.local'], ['name' => $name, 'password' => Str::random(32)]);
        $user->forceFill(['company_id' => $company->id])->save();

        if (Role::query()->where('name', $role)->exists()) {
            $user->assignRole($role);
        }

        if ($area !== null) {
            Employee::withoutGlobalScopes()->firstOrCreate(
                ['user_id' => $user->id],
                ['company_id' => $company->id, 'area_id' => $area->id, 'name' => $name, 'email' => $user->email, 'source' => Employee::SOURCE_MANUAL, 'status' => Employee::STATUS_ACTIVE],
            );
        }

        return $user;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function request(string $key, Company $company, Area $area, User $requester, array $attributes): AiRequest
    {
        $existing = AiRequest::withoutGlobalScopes()->withTrashed()->where('key', $key)->first();

        if ($existing !== null) {
            return $existing;
        }

        $status = $attributes['status'] ?? RequestStatus::Received;
        $request = AiRequest::create(array_replace([
            'key' => $key,
            'company_id' => $company->id,
            'area_id' => $area->id,
            'requester_id' => $requester->id,
            'created_by_id' => $requester->id,
            'expected_result' => 'Que el proceso deje de hacerse a mano.',
            'systems' => ['SAP Business One', 'Excel'],
            'first_response_due_at' => now()->addDays(3),
            'first_responded_at' => $status === RequestStatus::Received ? null : now()->subDays(2),
            'evaluated_at' => isset($attributes['size']) ? now()->subDays(2) : null,
        ], $attributes));

        $request->benefitedAreas()->syncWithoutDetaching([$area->id => ['is_primary' => true]]);
        $request->statusChanges()->create(['from_status' => null, 'to_status' => RequestStatus::Received, 'user_id' => $requester->id]);

        if ($status !== RequestStatus::Received) {
            $request->statusChanges()->create(['from_status' => RequestStatus::Received, 'to_status' => $status, 'user_id' => $attributes['evaluated_by_id'] ?? null]);
        }

        return $request;
    }
}
