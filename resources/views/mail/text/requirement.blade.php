TI necesita {{ $requirement_title }}

{{ $project_key }} · {{ $request_key }}@if ($is_overdue) · Vencido · {{ $days_waiting }} días esperando<?php else: ?> · Vence el {{ $due_date }}@endif


Hola. {{ $requested_by_name }}, de TI, necesita tu ayuda para seguir con {{ $request_title }} ({{ $request_key }}), que pidió {{ $area_name }} de {{ $company_name }}.

POR QUÉ TE ESCRIBIMOS
Sin esto, la tarea {{ $blocked_task }} está detenida. Lleva {{ $days_waiting }} días esperando y el proyecto {{ $project_key }} no puede avanzar en esa parte.

QUÉ SE NECESITA
@foreach ($needs as $need)
- {{ $need }}
@endforeach
@if ($asks_for_secrets)
No envíes las claves por correo. Súbelas en el Gestor: quedan guardadas en un lugar protegido y solo TI las ve.
@endif

Fecha límite: {{ $due_date }} · {{ $due_note }}
Lo pide: {{ $requested_by_name }}, de TI
Recordatorios: {{ $reminder_text }}

Responder el requerimiento: {{ $action_url }}

¿No te corresponde? En el Gestor puedes decirnos quién debe responder y se lo pasamos.

--
Gestor TI · Grupo RYR · {{ $company_name }}
Recibes este correo porque TI te pidió un dato para el proyecto {{ $project_key }}. Lo envía {{ $company_sender }}.
Cambiar mis avisos: {{ $preferences_url }}
