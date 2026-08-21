<?php

namespace App\Rules;

use App\Support\NomeCompleto;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * NomeCompletoValido — Rule de validação para uso em `$request->validate([...])`.
 *
 * Quick 260819-guy (2026-08-19), Tarefa 7 item 4 — mesmo molde de
 * `App\Rules\CnpjValido`: `nullable`-aware (nome ausente não é problema
 * desta Rule, é a regra 3 de `ContratoDadosMinimosService`), só recusa
 * quando HÁ valor e ele não tem pelo menos duas palavras.
 */
class NomeCompletoValido implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (blank($value)) {
            return;
        }

        if (! NomeCompleto::valido((string) $value)) {
            $fail('Informe o nome completo de quem assina pela empresa (nome e sobrenome).');
        }
    }
}
