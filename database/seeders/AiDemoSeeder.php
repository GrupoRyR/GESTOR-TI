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
use App\Models\AiRequest;
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

        foreach (AiRequest::withoutGlobalScopes()->whereIn('key', ['GIA-121', 'GIA-144', 'GIA-146', 'GIA-148', 'GIA-150', 'GIA-151', 'GIA-152', 'GIA-153'])->get() as $request) {
            $request->votes_count = $request->votes()->count();
            $request->recomputePriority();
            $request->save();
        }
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
