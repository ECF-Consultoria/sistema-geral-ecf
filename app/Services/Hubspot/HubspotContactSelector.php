<?php

namespace App\Services\Hubspot;

/**
 * HubspotContactSelector — escolhe o contato principal de uma empresa/deal
 * HubSpot de forma determinística (Fase 113, plano 01 — HUB-CONTATO-01).
 *
 * Unidade pura: recebe/devolve apenas arrays, sem tocar DB/config/HTTP.
 * Cada contato de entrada é um array associativo com (todas opcionais
 * exceto 'id'): id, firstname, lastname, email, phone, mobilephone,
 * jobtitle.
 *
 * Regra de prioridade (prompt canônico Fase 5 + CONTEXT.md da Fase 113):
 *   tier 3 — tem email E telefone (phone OU mobilephone)
 *   tier 2 — tem email
 *   tier 1 — tem telefone/mobilephone
 *   tier 0 — nenhum dos dois
 * Em empate de tier, desempata pelo MENOR id.
 *
 * NOTA DE DISCRIÇÃO (registrada também no SUMMARY): a decisão (5) do
 * CONTEXT.md prevê "fallback: primeiro contato retornado" como último
 * critério de prioridade. Aqui usamos "menor id" como desempate em TODOS
 * os tiers (não só no fallback), porque a API do HubSpot não garante
 * ordem estável de retorno entre chamadas — "primeiro retornado" não é
 * determinístico na prática. É um refinamento consciente do mesmo
 * objetivo (determinismo), não uma divergência de intenção.
 *
 * Ponto de extensão futuro (best-effort, NÃO implementado agora): se o
 * HubSpot expuser uma "label útil de association" (ex.: Decision maker,
 * Billing, Primary), ela entraria como critério de prioridade ACIMA dos
 * tiers de email/telefone abaixo — ver <deferred> do CONTEXT.md da Fase 113.
 */
class HubspotContactSelector
{
    /**
     * Seleciona o contato principal entre os contatos recebidos.
     *
     * @param  array  $contatos  lista de contatos (cada um um array associativo)
     * @return array|null  o contato escolhido (mesmo array de entrada) ou null se a lista for vazia
     */
    public static function selecionar(array $contatos): ?array
    {
        if (empty($contatos)) {
            return null;
        }

        $melhor      = null;
        $melhorTier  = -1;

        foreach ($contatos as $contato) {
            $tier = self::calcularTier($contato);

            if ($melhor === null) {
                $melhor     = $contato;
                $melhorTier = $tier;
                continue;
            }

            if ($tier > $melhorTier) {
                $melhor     = $contato;
                $melhorTier = $tier;
                continue;
            }

            if ($tier === $melhorTier && self::idMenor($contato['id'] ?? null, $melhor['id'] ?? null)) {
                $melhor = $contato;
            }
        }

        return $melhor;
    }

    /**
     * Calcula o tier de prioridade de um contato: 3 (email+telefone),
     * 2 (só email), 1 (só telefone/mobilephone) ou 0 (nenhum).
     * "Tem" = valor não-vazio após trim.
     */
    private static function calcularTier(array $contato): int
    {
        $temEmail    = self::preenchido($contato['email'] ?? null);
        $temTelefone = self::preenchido($contato['phone'] ?? null) || self::preenchido($contato['mobilephone'] ?? null);

        if ($temEmail && $temTelefone) {
            return 3;
        }

        if ($temEmail) {
            return 2;
        }

        if ($temTelefone) {
            return 1;
        }

        return 0;
    }

    /**
     * Verdadeiro quando o valor, após trim, não é vazio.
     */
    private static function preenchido(mixed $valor): bool
    {
        return trim((string) $valor) !== '';
    }

    /**
     * Verdadeiro quando $candidatoId é MENOR que $atualId. Compara
     * numericamente quando ambos são numéricos; senão, como string.
     */
    private static function idMenor(mixed $candidatoId, mixed $atualId): bool
    {
        if (is_numeric($candidatoId) && is_numeric($atualId)) {
            return (float) $candidatoId < (float) $atualId;
        }

        return (string) $candidatoId < (string) $atualId;
    }
}
