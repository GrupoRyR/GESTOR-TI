<?php

namespace Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * TrimStrings keeps exact whitespace for Markdown (docs autosave) and for
 * secret fields (vault values, passwords); everything else is trimmed.
 */
class TrimStringsTest extends TestCase
{
    public function test_markdown_and_secret_fields_keep_their_whitespace(): void
    {
        Route::middleware('web')->post('/_test/trim', fn (Request $request) => response()->json($request->all()));

        $this->postJson('/_test/trim', [
            'markdown' => "  - indented\n",
            'value' => " secreto con espacios \n",
            'secret' => ' s ',
            'password' => ' clave ',
            'password_confirmation' => ' clave ',
            'current_password' => ' vieja ',
            'name' => '  Nombre  ',
        ])->assertExactJson([
            'markdown' => "  - indented\n",
            'value' => " secreto con espacios \n",
            'secret' => ' s ',
            'password' => ' clave ',
            'password_confirmation' => ' clave ',
            'current_password' => ' vieja ',
            'name' => 'Nombre',
        ]);
    }
}
