<?php

namespace App\Rules;

use App\Support\Cnpj;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * CnpjValido — Rule de validação para uso em `$request->validate([...])`.
 *
 * Quick 260819-guy (2026-08-19) — só valida dígito verificador; presença e
 * comprimento continuam regras `string`/`max` separadas onde já existiam
 * (`ContratoAdminController::atualizarCadastro()`), para não duplicar
 * mensagem. Campo `nullable` em todo lugar que usa esta Rule — CNPJ ausente
 * não é problema desta Rule (é a regra 1 de `ContratoDadosMinimosService`).
 */
class CnpjValido implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (blank($value)) {
            return;
        }

        if (! Cnpj::valido((string) $value)) {
            $fail('O CNPJ informado não é válido — confira os dígitos.');
        }
    }
}
