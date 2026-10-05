<?php

namespace App\Services\Publicador\Alavancas;

use App\Services\Publicador\ClienteMlPublicador;

/**
 * Lê o `/users/me` da conta (UMA chamada) e devolve só os campos que as
 * Alavancas usam — o corpo inteiro nunca é guardado.
 */
class LeitorContaAlavancas
{
    public function __construct(private ClienteMlPublicador $cliente, private CacheAlavancas $cache) {}

    /** @return array{seller_id: string, nickname: ?string, tags: list<string>, business: bool, reputacao: ?string, experiencia: mixed, site: ?string} */
    public function ler(ContaAlavanca $c, bool $fresco = false): array
    {
        $buscar = function () use ($c): array {
            $r = $this->cliente->daConta($c->conta, 'GET', '/users/me');
            if (! $r->ok() || ! is_array($r->corpo)) {
                throw new \RuntimeException("[Alavancas] /users/me falhou: HTTP {$r->status}");
            }
            $u = $r->corpo;
            $tags = array_values(array_filter((array) ($u['tags'] ?? []), 'is_string'));

            return [
                'seller_id' => (string) ($u['id'] ?? $c->sellerId),
                'nickname' => $u['nickname'] ?? null,
                'tags' => $tags,
                'business' => in_array('business', $tags, true),
                'reputacao' => $u['seller_reputation']['level_id'] ?? null,
                'experiencia' => $u['seller_experience'] ?? null,
                'site' => $u['site_id'] ?? null,
            ];
        };

        // `fresco` chama o ML de novo e renova o que está guardado.
        return $this->cache->lembrar($c, 'conta', [], (int) config('publicador.alavancas.cache.conta', 300), $buscar, $fresco);
    }
}
