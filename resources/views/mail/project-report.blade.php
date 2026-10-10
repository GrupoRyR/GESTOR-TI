<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $content['project']['key'] }} · informe</title>
</head>
<body style="margin: 0; padding: 24px; background: #f3f4f6;">
    <div style="max-width: 640px; margin: 0 auto; background: #ffffff; border-radius: 12px; padding: 24px;">
        @include('mail.partials.project-report-body', ['content' => $content, 'sections' => $sections])
        <p style="margin: 16px 0 0; font-size: 12px; color: #6b7280;">El mismo informe va adjunto en PDF.</p>
    </div>
</body>
</html>
