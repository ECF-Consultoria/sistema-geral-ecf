<?php

namespace App\Services\Hubspot;

use App\Services\HubspotApiClient;
use Illuminate\Support\Facades\Cache;

/**
 * HubspotOwnerResolver — resolve o nome de exibição do responsável comercial
 * a partir do `hubspot_owner_id` do deal (Fase 151 Plano 03, COMERC-02, D-08).
 *
 * Classe pequena, um propósito só: dado um owner id, devolve o nome (ou
 * `null`). Nunca lança — `HubspotApiClient::fetchOwner()` já é resiliente por
 * desenho, e esta classe preserva essa garantia.
 *
 * Cache de 7 dias por owner id: a ECF tem poucos vendedores (D-08) e o nome
 * muda raramente. Sem cache, toda listagem (Contrato/Entrada) dispararia uma
 * chamada HTTP por empresa exibida — o mesmo risco de DoS que motivou o
 * cache em `MercadoLivreService::resolveAdvertiserId()`.
 */
class HubspotOwnerResolver
{
    /**
     * TTL do cache do nome resolvido — 7 dias (deliberado, ver docblock da classe).
     */
    private const TTL_DIAS = 7;

    /**
     * Sentinela cacheável para "owner sem nome resolvível" — nunca `null`,
     * porque `Cache::remember` reconsultaria a rede toda vez que o valor
     * armazenado fosse `null`. Mesmo padrão do `0` como sentinela em
     * `MercadoLivreService::resolveAdvertiserId()`.
     */
    private const SENTINELA_VAZIO = '';

    public function __construct(private HubspotApiClient $client)
    {
    }

    /**
     * Resolve o nome de exibição do owner a partir do id do HubSpot.
     *
     * `$ownerId` nulo ou string vazia devolve `null` imediatamente, sem
     * tocar em cache nem em rede — é o estado NORMAL de empresa de cadastro
     * manual, que nunca tem deal HubSpot e por isso nunca tem owner.
     */
    public function resolverNome(?string $ownerId): ?string
    {
        if ($ownerId === null || trim($ownerId) === '') {
            return null;
        }

        $nome = Cache::remember(
            "hubspot_owner_nome_{$ownerId}",
            now()->addDays(self::TTL_DIAS),
            function () use ($ownerId): string {
                $owner = $this->client->fetchOwner($ownerId);

                if ($owner === null) {
                    return self::SENTINELA_VAZIO;
                }

                $primeiroNome = trim((string) ($owner['firstName'] ?? ''));
                $sobrenome    = trim((string) ($owner['lastName'] ?? ''));
                $nomeComposto = trim("{$primeiroNome} {$sobrenome}");

                if ($nomeComposto !== '') {
                    return $nomeComposto;
                }

                $email = trim((string) ($owner['email'] ?? ''));

                return $email !== '' ? $email : self::SENTINELA_VAZIO;
            }
        );

        return $nome !== self::SENTINELA_VAZIO ? $nome : null;
    }
}
