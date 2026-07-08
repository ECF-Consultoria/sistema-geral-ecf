<?php

namespace App\Services\Nps;

use App\Models\Company;
use App\Models\NpsTemplate;
use RuntimeException;

/**
 * NpsTemplateService — Phase 69 Plan 01 (REQ NPS-B-01).
 *
 * Resolve o template NPS aplicável a cada empresa respeitando a
 * precedência determinística fixada no research v15.0 §4:
 *
 *   1. Templates active=true com pelo menos 1 serviço em comum com os
 *      contratos ativos da empresa (`nps_template_service_scopes`) —
 *      vence o de MAIOR `priority`; empate resolvido por MENOR `id`
 *      (ordenação secundária determinística — reprodutível em CI/prod).
 *   2. Fallback: template com `is_default=true` (seed "NPS Padrão" —
 *      migration 2026_07_07_100004; unique parcial garante 1 só).
 *   3. Sem nenhum aplicável nem `is_default` → `RuntimeException` com
 *      mensagem acionável em pt-BR. Situação anômala: sinaliza que o
 *      seed retroativo da Plan 68-03 não rodou ou foi revertido.
 *
 * Papel na arquitetura:
 *   Este service é a peça central das próximas fases:
 *     - Plan 69-04 (endpoint manual `POST /empresas/{id}/nps/generate`)
 *     - Plan 69-05 (comando agendado `nps:disparar-mensal`)
 *   Ambos os fluxos criam `NpsSurvey` já com `template_id` correto —
 *   sem este resolver, nenhum dispatch consegue amarrar template à
 *   empresa e o snapshot per-row (Phase 68) fica sem referência.
 *
 * Stateless por design:
 *   Sem propriedades, sem construtor — o Laravel resolve como singleton
 *   implícito via container. DI-friendly em Jobs/Controllers/Commands.
 *
 * Referências:
 *   - .planning/research/v15-nps-templates-schema.md §4 (regra completa)
 *   - .planning/phases/68-schema-modelos-e-seed-retroativo-nps-padr-o/PHASE-SUMMARY.md
 *   - app/Models/NpsTemplate.php (scopes active/default + relação serviceScopes)
 *   - app/Models/Company.php linha 236 (relação contratosServico)
 *   - app/Models/ContratoServico.php linha 74 (scope active — coluna `ativo`)
 */
final class NpsTemplateService
{
    /**
     * Resolve o template NPS aplicável à empresa.
     *
     * Passos (fiel ao research §4):
     *   1. Extrai IDs de serviços dos contratos ATIVOS da empresa
     *      (ContratoServico::scopeActive filtra `ativo=true`).
     *   2. Busca templates active=true com scope em qualquer um desses
     *      serviços; ordena priority DESC, id ASC; pega o primeiro.
     *   3. Se nada match, cai no template is_default=true (seed).
     *   4. Sem default → RuntimeException com contexto para debug.
     *
     * Usa `->first()` + null coalescing ao invés de union/orWhere numa
     * query só porque a lógica de fallback exige que o `is_default` só
     * seja retornado quando NENHUM scope aplicável existe — não quando
     * a soma bate. Query única não expressa isso sem CASE/subquery custosa,
     * e a versão em 2 queries é mais legível e igualmente performática
     * (índice parcial em is_default + índice nps_templates_active_priority
     * cobrem os dois casos individualmente).
     *
     * @throws RuntimeException Quando NENHUM template aplicável existe e
     *                          NENHUM template is_default=true está seedado.
     *                          Sinal de estado pré-deploy inconsistente
     *                          (rodar migrations pendentes antes de invocar).
     */
    public function resolveForCompany(Company $company): NpsTemplate
    {
        // Passo 1: IDs de serviços dos contratos ATIVOS da empresa.
        // Empresa sem contratos ativos → coleção vazia; whereIn com vazia
        // gera SQL `WHERE 0 = 1`, o que já força fallback direto no passo 3.
        $servicoIds = $company->contratosServico()
            ->active()
            ->pluck('servico_id');

        // Passo 2: template active=true com scope aplicável, maior priority
        // vence; empate resolvido por menor id (determinístico para CI/prod).
        //   - orderByDesc('priority')  → regra primária do research §4
        //   - orderBy('id')            → tiebreak reprodutível
        //   - ->first() ao invés de firstOrFail() → controla fallback abaixo
        $template = NpsTemplate::query()
            ->where('active', true)
            ->whereHas('serviceScopes', fn ($q) => $q->whereIn('servico_id', $servicoIds))
            ->orderByDesc('priority')
            ->orderBy('id')
            ->first();

        if ($template) {
            return $template;
        }

        // Passo 3: Fallback no template padrão (seed NPS Padrão).
        // Unique parcial em is_default (Plan 68-01) garante que existe 0 ou 1
        // — nunca mais de um; `->first()` é seguro.
        $default = NpsTemplate::where('is_default', true)->first();

        if ($default) {
            return $default;
        }

        // Passo 4: Estado inconsistente — seed 100004 não rodou/foi revertido.
        // Mensagem menciona "is_default" e "template padrão" para o dev
        // localizar rapidamente a migration culpada (grep-friendly no log).
        throw new RuntimeException(
            "[NpsTemplateService] Nenhum template com is_default=true encontrado — o template padrão "
            . "(seed 'NPS Padrão', migration 2026_07_07_100004) precisa estar aplicado antes de invocar "
            . "resolveForCompany() para empresa {$company->id}. Rodar `php artisan migrate` e revalidar."
        );
    }
}
