{{--
    Body of the project report, shared by the e-mail and the PDF.
    $content: design ReportContent (budget null when the recipient may not see time and cost).
    $sections: chosen sections (status, done, next, blockers, budget).
--}}
@php
    $project = $content['project'];
    $status = \App\Enums\Ai\RequestStatus::tryFrom($project['status'])?->label() ?? $project['status'];
    $budget = in_array('budget', $sections, true) ? $content['budget'] : null;
    $cop = fn ($amount) => \App\Domain\Ai\Money::cop((int) $amount);
    $date = fn ($value) => \Illuminate\Support\Carbon::parse($value)->format('d/m/Y');
@endphp
<div style="font-family: Arial, Helvetica, sans-serif; color: #1f2937; font-size: 14px; line-height: 1.5;">
    <p style="margin: 0 0 4px; color: #6b7280; font-size: 12px;">Informe al {{ $date($content['as_of']) }}</p>
    <h1 style="margin: 0 0 4px; font-size: 20px;">{{ $project['key'] }} · {{ $project['name'] }}</h1>
    <p style="margin: 0 0 16px; color: #4b5563;">
        Para {{ $content['requester_name'] }}@if ($content['request_key']) · {{ $content['request_key'] }}@endif
    </p>

    @if (in_array('status', $sections, true))
        <h2 style="font-size: 16px; margin: 16px 0 6px;">Estado</h2>
        <p style="margin: 0;">
            {{ $status }}. Tareas terminadas: {{ $content['tasks']['done'] }} de {{ $content['tasks']['total'] }}.
            Criterios de aceptación validados: {{ $content['criteria']['done'] }} de {{ $content['criteria']['total'] }}.
        </p>
    @endif

    @if (in_array('done', $sections, true))
        <h2 style="font-size: 16px; margin: 16px 0 6px;">Lo que se hizo</h2>
        @forelse ($content['done'] as $line)
            <p style="margin: 0 0 4px;">• {{ $line }}</p>
        @empty
            <p style="margin: 0; color: #6b7280;">Nada terminado esta semana.</p>
        @endforelse
    @endif

    @if (in_array('next', $sections, true))
        <h2 style="font-size: 16px; margin: 16px 0 6px;">Lo que sigue</h2>
        @forelse ($content['next'] as $line)
            <p style="margin: 0 0 4px;">• {{ $line }}</p>
        @empty
            <p style="margin: 0; color: #6b7280;">Sin tareas pendientes.</p>
        @endforelse
    @endif

    @if (in_array('blockers', $sections, true))
        <h2 style="font-size: 16px; margin: 16px 0 6px;">Lo que nos frena</h2>
        @forelse ($content['blockers'] as $line)
            <p style="margin: 0 0 4px;">• {{ $line }}</p>
        @empty
            <p style="margin: 0; color: #6b7280;">Nada nos frena.</p>
        @endforelse
    @endif

    @if ($budget !== null)
        <h2 style="font-size: 16px; margin: 16px 0 6px;">Tiempo y costo</h2>
        <p style="margin: 0 0 4px;">Horas: {{ $budget['hours']['actual'] }} de {{ $budget['hours']['estimate'] }} estimadas.</p>
        @if ($budget['cost'] !== null)
            <p style="margin: 0 0 4px;">Costo: {{ $cop($budget['cost']['actual']) }} de {{ $cop($budget['cost']['estimate']) }} aprobados.</p>
        @endif
        <p style="margin: 0 0 4px;">Entrega aprobada: {{ $date($budget['due_date']) }}. Proyectada: {{ $date($budget['projected_due_date']) }}.</p>
    @endif

    <p style="margin: 24px 0 0; font-size: 12px; color: #6b7280;">
        Ver el proyecto: <a href="{{ $content['project_url'] }}">{{ $content['project_url'] }}</a><br>
        Enviado por el Gestor TI desde {{ $content['sender_email'] }}.
    </p>
</div>
