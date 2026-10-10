{{-- Shown instead of the consent screen to users without `ai.mcp.use` (App\Http\Middleware\EnsureMcpConsent). --}}
@extends('mcp.layout')

@section('title', 'Sin permiso')

@section('content')
    <h1 role="alert">No tienes permiso para usar el conector del Gestor IA</h1>

    <p>{{ $user->name }}, tu cuenta no tiene habilitado el conector de Claude. Si lo necesitas, pídeselo al Líder IA o a TI.</p>

    <p><a href="{{ route('home') }}">Volver al Gestor TI</a></p>
@endsection
