<?php

namespace App\Services\Publicador;

use App\Contracts\ContaMercadoLivre;
use App\Support\Publicador\Payload\MontadorDePlano;
use App\Support\Publicador\RegraViolada;

/**
 * Lê a conta do ML (`02` E0). Sem cache e sem valor reserva: o
 * `MlPublicacaoService` antigo guardava o modelo por 24h e, numa falha
 * momentânea, gravava "clássico" no cache (`16` §3, V2) — aqui uma falha é
 * falha, e quem chama mostra "tente de novo".
 *
 * O modelo sai da tag `user_product_seller` (RN-02; medido em 01/10: as 33
 * contas de cliente com token válido são UP). O perfil público não traz tags —
 * só o `/users/me` com o token da conta.
 */
class ContaMlService
{
    public function __construct(private ClienteMlPublicador $cliente) {}

    public function contexto(ContaMercadoLivre $conta): ContextoConta
    {
        $eu = $this->cliente->daConta($conta, 'GET', '/users/me');
        if (! $eu->ok() || ! is_array($eu->corpo) || ! isset($eu->corpo['id'])) {
            throw new RegraViolada('V-ACC-01', 'Não foi possível ler a conta do Mercado Livre agora. Tente de novo em instantes.');
        }

        $sellerId = (string) $eu->corpo['id'];
        $tags = array_values(array_map('strval', (array) ($eu->corpo['tags'] ?? [])));

        $envio = $this->cliente->daConta($conta, 'GET', "/users/{$sellerId}/shipping_preferences");
        $modos = $envio->ok() && is_array($envio->corpo) ? array_values(array_map('strval', (array) ($envio->corpo['modes'] ?? []))) : null;

        $depositos = null;
        if (in_array('warehouse_management', $tags, true)) {
            $lojas = $this->cliente->daConta($conta, 'GET', "/users/{$sellerId}/stores/search", ['tags' => 'stock_location']);
            if ($lojas->ok() && is_array($lojas->corpo)) {
                $depositos = array_values(array_map(fn ($l) => [
                    'store_id' => (string) $l['id'],
                    'nome' => (string) ($l['description'] ?? $l['id']),
                    'network_node_id' => isset($l['network_node_id']) ? (string) $l['network_node_id'] : null,
                ], array_filter((array) ($lojas->corpo['results'] ?? []), fn ($l) => ($l['status'] ?? 'active') === 'active')));
            }
        }

        return new ContextoConta(
            sellerId: $sellerId,
            modelo: in_array('user_product_seller', $tags, true) ? MontadorDePlano::UP : MontadorDePlano::LEGADO,
            tags: $tags,
            modosEnvio: $modos,
            depositos: $depositos,
            lidaEm: now()->toIso8601String(),
        );
    }
}
