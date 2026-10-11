Ya puedes usar lo que pediste

{{ $request_key }} · En producción
Hola, {{ $requester_first_name }}. {{ $request_title }} ya está disponible en {{ $product_name }} v{{ $release_version }} desde el {{ $released_on }}.

QUÉ CAMBIA PARA TI
{{ $change_title }}
@foreach ($change_paragraphs as $paragraph)
{{ $paragraph }}
@endforeach

¿CÓMO TE FUE?
Toca una opción y queda guardada. Si quieres, después dejas un comentario en el Gestor.
1 · Nada útil: {{ $rating_url_1 }}
2 · Poco útil: {{ $rating_url_2 }}
3 · Regular: {{ $rating_url_3 }}
4 · Útil: {{ $rating_url_4 }}
5 · Muy útil: {{ $rating_url_5 }}
1 = no resolvió tu necesidad · 5 = la resolvió por completo

Calificar en el Gestor: {{ $action_url }}

Dentro de 30 días te preguntaremos cuántas horas al mes te ahorra este cambio. Así TI sabe qué vale la pena seguir mejorando.

--
Gestor TI · Grupo RYR · {{ $company_name }}
Recibes este correo porque pediste {{ $request_key }}. Lo envía {{ $company_sender }}.
Cambiar mis avisos: {{ $preferences_url }}
