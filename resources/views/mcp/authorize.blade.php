{{-- Passport consent screen of the MCP connector (Passport::authorizationView). --}}
@extends('mcp.layout')

@section('title', 'Autorizar '.$client->name)

@section('content')
    <h1>¿Autorizar a {{ $client->name }}?</h1>

    <p>{{ $client->name }} quiere conectarse al Gestor IA con tu cuenta ({{ $user->name }}).</p>

    <p>Podrá:</p>
    <ul>
        @forelse ($scopes as $scope)
            <li>{{ $scope->description }}</li>
        @empty
            <li>Consultar y actualizar el Gestor IA en tu nombre</li>
        @endforelse
    </ul>

    <p>Solo verá lo que tú ves en el Gestor IA. Puedes retirar el acceso cuando quieras.</p>

    <div class="actions">
        <form method="post" action="{{ route('passport.authorizations.deny') }}">
            @csrf
            @method('DELETE')
            <input type="hidden" name="state" value="{{ $request->state }}">
            <input type="hidden" name="client_id" value="{{ $client->getKey() }}">
            <input type="hidden" name="auth_token" value="{{ $authToken }}">
            <button type="submit">Cancelar</button>
        </form>

        <form method="post" action="{{ route('passport.authorizations.approve') }}">
            @csrf
            <input type="hidden" name="state" value="{{ $request->state }}">
            <input type="hidden" name="client_id" value="{{ $client->getKey() }}">
            <input type="hidden" name="auth_token" value="{{ $authToken }}">
            <button type="submit" class="primary">Autorizar</button>
        </form>
    </div>
@endsection
