<?php

namespace App\Services\Publicador\Alavancas;

use Illuminate\Support\Facades\Cache;

/**
 * Cache curto por conta (Padrão 3). Só guarda o que a leitura devolveu: quem chama
 * LANÇA em falha, para nunca guardar erro.
 */
class CacheAlavancas
{
    public function lembrar(ContaAlavanca $c, string $painel, array $partes, int $ttl, \Closure $fn, bool $atualizar = false): mixed
    {
        $chave = "alavancas:{$c->chaveConta()}:v{$this->versao($c)}:{$painel}:".sha1((string) json_encode($partes));
        if ($atualizar) {
            Cache::forget($chave);
        }

        return Cache::remember($chave, $ttl, $fn);
    }

    /**
     * Escrita bem-sucedida invalida a conta inteira trocando a versão da chave;
     * Redis/array não apagam por prefixo.
     */
    public function invalidar(ContaAlavanca $c): void
    {
        Cache::forever("alavancas:{$c->chaveConta()}:versao", $this->versao($c) + 1);
    }

    private function versao(ContaAlavanca $c): int
    {
        return (int) Cache::get("alavancas:{$c->chaveConta()}:versao", 1);
    }
}
