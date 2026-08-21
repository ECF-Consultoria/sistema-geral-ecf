<?php

namespace App\Support;

/**
 * NomeCompleto — helper estático puro para validar que um nome tem, no
 * mínimo, duas palavras (nome + sobrenome).
 *
 * Quick 260819-guy (2026-08-19), Tarefa 7 item 4 — a Clicksign exige nome
 * completo de quem assina e devolve `400 "name não está em um formato
 * válido"` para palavra única (caso real medido: `"teste"`), só depois de
 * já ter criado o envelope e o documento — dois round-trips e cerca de
 * 6 minutos até o registro terminar em `status = erro`. Este helper existe
 * para recusar ANTES de qualquer chamada HTTP, mesmo molde de
 * `App\Support\Cnpj`.
 */
class NomeCompleto
{
    /**
     * `"Maria Silva"`      → true.
     * `"teste"`             → false (uma palavra só).
     * `"   "`                → false (só espaço).
     * `null`                 → false.
     */
    public static function valido(?string $nome): bool
    {
        if ($nome === null) {
            return false;
        }

        $palavras = array_filter(
            preg_split('/\s+/', trim($nome)) ?: [],
            fn (string $palavra) => $palavra !== ''
        );

        return count($palavras) >= 2;
    }
}
