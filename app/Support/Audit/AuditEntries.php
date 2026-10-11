<?php

namespace App\Support\Audit;

use App\Models\AccessSystem;
use App\Models\Area;
use App\Models\Company;
use App\Models\DepartmentAreaMapping;
use App\Models\Employee;
use App\Models\EmployeeAccess;
use App\Models\Role;
use App\Models\User;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Spatie\Activitylog\Models\Activity;

/**
 * Administración › Auditoría: filters, company scope and the `AuditEntry`
 * shape (types/pages/admin.ts) of activity log rows, shared by the page and
 * the CSV export.
 *
 * - Scope (kept simple on purpose): users without `companies.view_all`, and
 *   global users who picked one company in the top bar, only see activities
 *   caused by users of that company; entries without a causer (jobs,
 *   console, failed sign-ins) only appear in "Todas".
 * - `module`, `sensitive`, `alert`: config `gestor.audit`.
 * - `description`: Spanish sentence rendered here from log name, event,
 *   subject and properties (the stored description is the fallback).
 * - `changes`: `properties.old` vs `properties.attributes`, after
 *   ActivityPropertiesSanitizer.
 */
class AuditEntries
{
    /** @var list<string> */
    public const RANGES = ['24h', '7d', '30d', 'all'];

    public const DEFAULT_RANGE = '7d';

    private const FIELD_LABELS = [
        'name' => 'Nombre',
        'short_name' => 'Nombre corto',
        'slug' => 'Identificador',
        'tenant_id' => 'ID de inquilino',
        'domains' => 'Dominios',
        'sender_email' => 'Correo emisor',
        'sender_name' => 'Nombre del emisor',
        'colors' => 'Colores',
        'logo_path' => 'Logo',
        'logo_dark_path' => 'Logo oscuro',
        'logo_url' => 'Logo',
        'logo_dark_url' => 'Logo oscuro',
        'is_active' => 'Estado',
        'active' => 'Estado',
        'approver_user_id' => 'Aprobador',
        'approver_area' => 'Área que aprueba',
        'roles' => 'Roles',
        'permissions' => 'Permisos',
        'company_id' => 'Empresa',
        'area_id' => 'Área',
        'area_locked' => 'Área fija',
        'user_id' => 'Usuario',
        'manager_id' => 'Jefe',
        'department' => 'Departamento',
        'email' => 'Correo',
        'job_title' => 'Cargo',
        'status' => 'Estado',
        'source' => 'Origen',
        'azure_id' => 'ID de Microsoft',
        'username' => 'Usuario',
        'notes' => 'Notas',
        'description' => 'Descripción',
        'last_rotated_at' => 'Último cambio de contraseña',
        'employee_id' => 'Colaborador',
        'access_system_id' => 'Sistema',
        'label' => 'Nombre',
        'scope' => 'Alcance',
        'approver_areas' => 'Áreas que aprueba',
        'provider' => 'Proveedor de IA',
        'model' => 'Modelo',
        'monthly_cap_usd' => 'Tope mensual (US$)',
        'organization' => 'Organización de GitHub',
    ];

    private const ORIGIN_LABELS = [
        AuditOrigin::WEB => 'Web',
        AuditOrigin::TELEGRAM => 'Telegram',
        AuditOrigin::SYSTEM => 'Sistema',
        AuditOrigin::API => 'API',
    ];

    /** @var array<string, array<int, ?string>> */
    private array $nameCache = [];

    public function __construct(private readonly CurrentCompany $currentCompany) {}

    /**
     * Filters from the query string; unknown values fall back to defaults.
     *
     * @return array{causer_id: ?int, module: ?string, sensitive_only: bool, range: string}
     */
    public function filters(Request $request): array
    {
        $module = $request->query('module');
        $range = $request->query('range');

        return [
            'causer_id' => $request->integer('causer_id') ?: null,
            'module' => is_string($module) && array_key_exists($module, $this->modules()) ? $module : null,
            'sensitive_only' => $request->boolean('sensitive_only'),
            'range' => is_string($range) && in_array($range, self::RANGES, true) ? $range : self::DEFAULT_RANGE,
        ];
    }

    /**
     * Visible activities matching the filters, newest first.
     *
     * @param  array{causer_id: ?int, module: ?string, sensitive_only: bool, range: string}  $filters
     * @return Builder<Activity>
     */
    public function query(array $filters): Builder
    {
        $since = match ($filters['range']) {
            '24h' => now()->subDay(),
            '7d' => now()->subDays(7),
            '30d' => now()->subDays(30),
            default => null,
        };

        return $this->scoped()
            ->with(['causer', 'subject'])
            ->when($filters['causer_id'], fn (Builder $query, int $causerId) => $query
                ->where('causer_type', (new User)->getMorphClass())
                ->where('causer_id', $causerId))
            ->when($filters['module'], fn (Builder $query, string $module) => $query
                ->whereIn('log_name', $this->modules()[$module]['log_names']))
            ->when($filters['sensitive_only'], fn (Builder $query) => $query->where(fn (Builder $q) => $q
                ->whereIn('event', $this->config('sensitive_events'))
                ->orWhereIn('log_name', $this->config('sensitive_log_names'))
                ->orWhere(fn (Builder $tenant) => $tenant
                    ->where('log_name', 'companies')
                    ->where('properties', 'like', '%"tenant_id"%'))))
            ->when($since, fn (Builder $query) => $query->where('created_at', '>=', $since))
            ->latest('id');
    }

    /**
     * Activities visible in the current company context.
     *
     * @return Builder<Activity>
     */
    public function scoped(): Builder
    {
        $query = Activity::query();

        if ($this->currentCompany->isAll()) {
            return $query;
        }

        $companyId = $this->currentCompany->id();

        if ($companyId === null) {
            return $query->whereRaw('1 = 0');
        }

        return $query
            ->where('causer_type', (new User)->getMorphClass())
            ->whereIn('causer_id', User::query()->select('id')->where('company_id', $companyId));
    }

    /**
     * Users who caused a visible activity, by name.
     *
     * @return list<array{id: int, name: string}>
     */
    public function causerOptions(): array
    {
        return User::query()
            ->whereIn('id', $this->scoped()
                ->where('causer_type', (new User)->getMorphClass())
                ->select('causer_id'))
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (User $user) => ['id' => $user->id, 'name' => $user->name])
            ->values()
            ->all();
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public function moduleOptions(): array
    {
        return collect($this->modules())
            ->map(fn (array $module, string $key) => ['value' => $key, 'label' => $module['label']])
            ->values()
            ->all();
    }

    /**
     * `AuditEntry` of types/pages/admin.ts.
     *
     * @return array{id: int, at: ?string, causer: array{id: int, name: string}|null, description: string, origin: string, ip: ?string, sensitive: bool, alert: bool, changes: list<array{field: string, old: ?string, new: ?string}>|null}
     */
    public function present(Activity $activity): array
    {
        $properties = $this->properties($activity);
        $causer = $activity->causer;
        $origin = $properties['origin'] ?? null;
        $ip = $properties['ip'] ?? null;

        return [
            'id' => (int) $activity->id,
            'at' => $activity->created_at?->toIso8601String(),
            'causer' => $causer === null ? null : ['id' => (int) $causer->getKey(), 'name' => (string) ($causer->name ?? '')],
            'description' => $this->description($activity, $properties),
            'origin' => is_string($origin) && in_array($origin, AuditOrigin::ALL, true) ? $origin : AuditOrigin::SYSTEM,
            'ip' => is_string($ip) && $ip !== '' ? $ip : null,
            'sensitive' => $this->isSensitive($activity, $properties),
            'alert' => in_array($activity->event, $this->config('alert_events'), true),
            'changes' => $this->changes($properties),
        ];
    }

    /**
     * One CSV row (Spanish values) of the export.
     *
     * @return list<string>
     */
    public function csvRow(Activity $activity): array
    {
        $entry = $this->present($activity);
        $module = $this->moduleOf((string) $activity->log_name);

        return [
            $activity->created_at?->clone()->setTimezone('America/Bogota')->format('Y-m-d H:i:s') ?? '',
            $entry['causer']['name'] ?? '',
            $entry['description'],
            $module === null ? '' : $this->modules()[$module]['label'],
            self::ORIGIN_LABELS[$entry['origin']],
            $entry['ip'] ?? '',
            $entry['sensitive'] ? 'Sí' : 'No',
            $entry['alert'] ? 'Sí' : 'No',
            collect($entry['changes'] ?? [])
                ->map(fn (array $change) => sprintf('%s: %s → %s', $change['field'], $change['old'] ?? '(vacío)', $change['new'] ?? '(vacío)'))
                ->implode('; '),
        ];
    }

    /**
     * @return list<string>
     */
    public static function csvHeaders(): array
    {
        return ['Fecha', 'Usuario', 'Acción', 'Módulo', 'Origen', 'IP', 'Sensible', 'Alerta', 'Cambios'];
    }

    public function moduleOf(string $logName): ?string
    {
        foreach ($this->modules() as $key => $module) {
            if (in_array($logName, $module['log_names'], true)) {
                return $key;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function properties(Activity $activity): array
    {
        return ActivityPropertiesSanitizer::sanitize($activity->properties?->toArray() ?? []);
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    private function isSensitive(Activity $activity, array $properties): bool
    {
        if (in_array($activity->event, $this->config('sensitive_events'), true)
            || in_array($activity->log_name, $this->config('sensitive_log_names'), true)) {
            return true;
        }

        return $activity->log_name === 'companies' && $this->touches($properties, 'tenant_id');
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    private function touches(array $properties, string $field): bool
    {
        return array_key_exists($field, (array) ($properties['attributes'] ?? []))
            || array_key_exists($field, (array) ($properties['old'] ?? []));
    }

    /**
     * @param  array<string, mixed>  $properties
     * @return list<array{field: string, old: ?string, new: ?string}>|null
     */
    private function changes(array $properties): ?array
    {
        $new = is_array($properties['attributes'] ?? null) ? $properties['attributes'] : [];
        $old = is_array($properties['old'] ?? null) ? $properties['old'] : [];

        if ($new === [] && $old === []) {
            return null;
        }

        $changes = [];

        foreach (array_unique([...array_keys($new), ...array_keys($old)]) as $field) {
            $before = $this->format((string) $field, $old[$field] ?? null);
            $after = $this->format((string) $field, $new[$field] ?? null);

            if ($before === $after) {
                continue;
            }

            $changes[] = ['field' => self::FIELD_LABELS[$field] ?? (string) $field, 'old' => $before, 'new' => $after];
        }

        return $changes === [] ? null : $changes;
    }

    private function format(string $field, mixed $value): ?string
    {
        if ($value === null || $value === '' || $value === []) {
            return null;
        }

        if (in_array($field, ['is_active', 'active'], true)) {
            return (bool) $value ? 'Activo' : 'Inactivo';
        }

        if (is_bool($value)) {
            return $value ? 'Sí' : 'No';
        }

        $resolved = match ($field) {
            'approver_user_id', 'user_id' => $this->nameOf(User::class, $value),
            'company_id' => $this->nameOf(Company::class, $value),
            'area_id' => $this->nameOf(Area::class, $value),
            default => null,
        };

        if ($resolved !== null) {
            return $resolved;
        }

        if (is_array($value)) {
            return array_is_list($value)
                ? implode(', ', array_map(fn ($item) => is_scalar($item) ? (string) $item : json_encode($item), $value))
                : collect($value)->map(fn ($item, $key) => $key.': '.(is_scalar($item) ? (string) $item : json_encode($item)))->implode(', ');
        }

        return is_scalar($value) ? (string) $value : (string) json_encode($value);
    }

    /**
     * @param  class-string<Model>  $class
     */
    private function nameOf(string $class, mixed $id): ?string
    {
        if (! is_int($id) && ! (is_string($id) && ctype_digit($id))) {
            return null;
        }

        $id = (int) $id;

        if (! array_key_exists($id, $this->nameCache[$class] ?? [])) {
            $model = $class::query()->withoutGlobalScopes()->find($id);
            $this->nameCache[$class][$id] = $model === null ? null : (string) ($model->short_name ?? $model->name);
        }

        return $this->nameCache[$class][$id];
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    private function description(Activity $activity, array $properties): string
    {
        $event = (string) $activity->event;
        $subject = $this->subjectName($activity, $properties);
        $new = (array) ($properties['attributes'] ?? []);
        $old = (array) ($properties['old'] ?? []);

        $sentence = match ($event) {
            'credential_revealed' => $this->credentialSentence($properties),
            'vault_access_granted' => 'Dio acceso a la bóveda a '.($subject ?? 'un usuario'),
            'role_permissions_updated' => 'Cambió los permisos del rol '.($subject ?? ''),
            'role_created' => 'Creó el rol '.($subject ?? ''),
            'user_roles_updated' => $this->rolesSentence((array) ($old['roles'] ?? []), (array) ($new['roles'] ?? []), $subject ?? 'un usuario'),
            'user_deactivated' => 'Desactivó a '.($subject ?? 'un usuario'),
            'emergency_login_succeeded' => 'Entró con la cuenta de emergencia',
            'emergency_login_failed' => 'Intento fallido en la cuenta de emergencia',
            'emergency_login_locked' => 'Intento en la cuenta de emergencia bloqueada',
            'emergency_login_throttled' => 'Intento rechazado por demasiados fallos en la cuenta de emergencia',
            'emergency_login_inactive' => 'Intento con la cuenta de emergencia desactivada',
            'emergency_admin_created' => 'Creó la cuenta de emergencia',
            'emergency_admin_password_rotated' => 'Cambió la contraseña de la cuenta de emergencia',
            'employees_synced' => 'Sincronizó los colaboradores de '.($properties['company'] ?? $subject ?? 'una empresa').' con Microsoft',
            'created', 'updated', 'deleted' => $this->modelSentence($activity, $event, $subject, $new, $old),
            default => null,
        };

        return trim((string) ($sentence ?? $activity->description));
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    private function credentialSentence(array $properties): string
    {
        $system = $properties['access_system'] ?? 'un sistema';
        $employee = $properties['employee_name'] ?? 'un colaborador';

        return ($properties['channel'] ?? 'web') === 'web'
            ? "Reveló la contraseña de {$system} de {$employee}"
            : "Recibió la contraseña de {$system} de {$employee} por el Asistente";
    }

    /**
     * @param  array<mixed>  $before
     * @param  array<mixed>  $after
     */
    private function rolesSentence(array $before, array $after, string $user): string
    {
        $added = array_values(array_diff($after, $before));
        $removed = array_values(array_diff($before, $after));

        return match (true) {
            count($added) === 1 && $removed === [] => "Dio el rol {$added[0]} a {$user}",
            count($removed) === 1 && $added === [] => "Quitó el rol {$removed[0]} a {$user}",
            default => "Cambió los roles de {$user}",
        };
    }

    /**
     * @param  array<string, mixed>  $new
     * @param  array<string, mixed>  $old
     */
    private function modelSentence(Activity $activity, string $event, ?string $subject, array $new, array $old): ?string
    {
        $name = $subject ?? '';
        $type = $activity->subject_type === null
            ? null
            : (Relation::getMorphedModel($activity->subject_type) ?? $activity->subject_type);
        $verb = ['created' => 'Creó', 'updated' => 'Editó', 'deleted' => 'Eliminó'][$event];

        return match (true) {
            $activity->log_name === 'companies' && $event === 'updated' => $this->companySentence($name, $new, $old),
            $activity->log_name === 'companies' => "{$verb} la empresa {$name}",
            $activity->log_name === 'areas' => "{$verb} el área {$name}",
            $type === DepartmentAreaMapping::class => match ($event) {
                'created' => "Asignó el departamento {$name} a un área",
                'updated' => "Cambió el área del departamento {$name}",
                default => "Quitó el área del departamento {$name}",
            },
            $type === Employee::class => match ($event) {
                'created' => "Agregó a {$name} a Colaboradores",
                'updated' => "Editó los datos de {$name}",
                default => "Eliminó a {$name} de Colaboradores",
            },
            $type === EmployeeAccess::class => match ($event) {
                'created' => "Agregó un acceso de {$name}",
                'updated' => "Editó un acceso de {$name}",
                default => "Eliminó un acceso de {$name}",
            },
            $type === AccessSystem::class => "{$verb} el sistema {$name}",
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $new
     * @param  array<string, mixed>  $old
     */
    private function companySentence(string $name, array $new, array $old): string
    {
        $fields = array_unique([...array_keys($new), ...array_keys($old)]);

        return match (true) {
            in_array('tenant_id', $fields, true) => "Cambió el inquilino de Microsoft de {$name}",
            $fields === ['sender_email'] => "Cambió el correo emisor de {$name}",
            $fields === ['is_active'] => ((bool) ($new['is_active'] ?? false) ? 'Activó' : 'Desactivó')." la empresa {$name}",
            default => "Editó la empresa {$name}",
        };
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    private function subjectName(Activity $activity, array $properties): ?string
    {
        $subject = $activity->subject;

        $name = match (true) {
            $subject instanceof Company => $subject->short_name ?: $subject->name,
            $subject instanceof Role => $subject->displayLabel(),
            $subject instanceof DepartmentAreaMapping => $subject->department,
            $subject instanceof EmployeeAccess => $subject->employee()->withoutGlobalScopes()->value('name'),
            $subject !== null => $subject->getAttribute('name'),
            default => null,
        };

        if (is_string($name) && $name !== '') {
            return $name;
        }

        $values = [...(array) ($properties['old'] ?? []), ...(array) ($properties['attributes'] ?? [])];
        $fallback = Arr::first(
            [$values['short_name'] ?? null, $values['label'] ?? null, $values['name'] ?? null, $values['department'] ?? null],
            fn ($value) => is_string($value) && $value !== '',
        );

        return is_string($fallback) ? $fallback : null;
    }

    /**
     * @return array<string, array{label: string, log_names: list<string>}>
     */
    private function modules(): array
    {
        return config('gestor.audit.modules', []);
    }

    /**
     * @return list<string>
     */
    private function config(string $key): array
    {
        return array_values((array) config('gestor.audit.'.$key, []));
    }
}
