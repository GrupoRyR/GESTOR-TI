<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>{{ $content['project']['key'] }} · informe</title>
    <style>
        @page { margin: 32px 40px; }
        body { font-family: DejaVu Sans, sans-serif; }
    </style>
</head>
<body>
    @include('mail.partials.project-report-body', ['content' => $content, 'sections' => $sections])
</body>
</html>
