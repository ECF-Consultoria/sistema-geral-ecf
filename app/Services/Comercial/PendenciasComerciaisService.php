<?php

namespace App\Services\Comercial;

use App\Models\Company;
use App\Models\HubspotLineItemMapping;
use App\Models\Servico;
use Illuminate\Support\Collection;

/**
 * PendenciasComerciaisService — fonte única das 7 pendências COMERCIAIS
 * (FLUXO-03), extraída de `ComercialController::calcularPendenciasComerciais()`
 * na Fase 124 (plano 124-03) SEM alteração de comportamento. Corpo copiado
 * literalmente, incluindo o early-return de origem HubSpot e a cache estática
 * de resolução de nome — mudar qualquer um dos dois é decisão de outra fase
 * (A4, atribuída à Fase 128), não desta extração.
 *
 * Passa a ser consumida por Comercial (`ComercialController::listagem()`),
 * HubSpot (indiretamente, via mesma origem de dado) e, a partir da Fase 131,
 * pela tela do Administrativo.
 *
 * ⚠️ Homônimo que NÃO é este e NÃO deve ser confundido:
 * `HubspotWebhookController::calcularPendencias()` — outro conceito, 4 slugs
 * diferentes (`sem_responsavel`, `sem_cust_id`, `sem_email_colaborador`,
 * `sem_servico`), usado só para decidir se notifica o Comercial. Fica
 * exatamente como está, fora do escopo desta extração.
 */
class PendenciasComerciaisService
{
    /**
     * Calcula as 7 pendencias comerciais de uma empresa (Phase 37 REQ-37-06,
     * Phase 114 HUB-UI-02).
     *
     * REQ-37-10: empresas SEM HubspotEvento::company_id_criada (legacy) sempre
     * retornam array vazio — pendencias comerciais sao calculadas APENAS para
     * empresas de origem HubSpot. Outros tipos de pendencia (sem_responsavel,
     * sem_cust_id, etc.) ficam em /companies (CompanyController::index).
     *
     * @return array<int, string>  Lista de slugs de pendencia encontradas
     */
    public function calcular(Company $c): array
    {
        if (!$c->is_origem_hubspot) {
            return [];
        }

        $pendencias = [];
        $contratosAtivos = $c->contratosServico->where('ativo', true);

        // sem_servico: zero contratos ativos
        if (($slug = $this->pendenciaSemServico($contratosAtivos)) !== null) {
            $pendencias[] = $slug;
        }

        // sem_valor: tem contrato ativo COM valor_contratado=0
        if (($slug = $this->pendenciaSemValor($contratosAtivos)) !== null) {
            $pendencias[] = $slug;
        }

        // servico_nao_reconhecido: payload de algum HubspotEvento tem line_items_nao_mapeados
        // (gravado pelo Plan 37-04 quando line item HubSpot nao bate no mapping).
        //
        // Hotfix 2026-06-19 — re-valida via paraNome() em tempo de leitura. Se o
        // admin cadastrou o mapping depois (ou o paraNome substring agora bate), o
        // nome deixa de contar como nao-reconhecido — a pendencia some sem precisar
        // mexer no payload historico do evento. Cache estatica por request evita
        // re-query do mesmo nome em empresas diferentes.
        static $matchCache = [];
        $resolverNome = function (string $nome) use (&$matchCache): bool {
            $key = mb_strtolower(trim($nome));
            if ($key === '') {
                return true;
            }
            if (!array_key_exists($key, $matchCache)) {
                $matchCache[$key] = (bool) HubspotLineItemMapping::paraNome($nome);
            }
            return $matchCache[$key];
        };

        $temLineItemNaoResolvidoAgora = $c->hubspotEventos->contains(function ($ev) use ($resolverNome) {
            $payload = $ev->payload ?? [];
            $itens = $payload['line_items_nao_mapeados'] ?? [];
            if (empty($itens)) {
                return false;
            }
            foreach ($itens as $item) {
                $nome = (string) ($item['name'] ?? '');
                if ($nome === '') {
                    continue;
                }
                // Se algum item AINDA nao bate em mapping ativo, a pendencia persiste.
                if (!$resolverNome($nome)) {
                    return true;
                }
            }
            return false;
        });
        if ($temLineItemNaoResolvidoAgora) {
            $pendencias[] = 'servico_nao_reconhecido';
        }

        // sem_setor: TODOS os contratos ativos apontam para Servico com setor='outros'
        // (catalogo ainda nao categorizado — admin precisa ajustar). Se nao ha contrato,
        // a pendencia 'sem_servico' ja cobre o caso.
        if (($slug = $this->pendenciaSemSetor($contratosAtivos)) !== null) {
            $pendencias[] = $slug;
        }

        // Quick task 260805-eqk — a pendencia `dados_close_incompletos` foi
        // removida: nicho/dor/faturamento_mensal nunca chegavam do HubSpot
        // (properties inexistentes) e as colunas deixaram de existir.

        // ── Phase 114 (HUB-UI-02) — 3 pendencias novas, aditivas, SO para
        // origem HubSpot (guarda no topo do metodo ja cobre o isolamento).

        // sem_contato: contato principal nao foi resolvido no handoff (Fase 113).
        if (($slug = $this->pendenciaSemContato($c)) !== null) {
            $pendencias[] = $slug;
        }

        // valor_revisar: algum contrato ativo tem inferencia de valor insegura
        // (confidence 'low') OU um warning explicito (ex.: amount indecidivel).
        $temValorParaRevisar = $contratosAtivos->contains(
            fn($ct) => $ct->hubspot_valor_confidence === 'low' || $ct->hubspot_valor_warning !== null
        );
        if ($temValorParaRevisar) {
            $pendencias[] = 'valor_revisar';
        }

        // possivel_duplicidade: o dedup fraco (Fase 113) marcou candidata por
        // nome — checa primeiro o snapshot da empresa (sempre reescrito no
        // ultimo evento), com fallback no payload do(s) HubspotEvento eager-loaded
        // (sem query nova — mesma colecao usada em servico_nao_reconhecido).
        $warningsSnapshot = $c->hubspot_snapshot['warnings'] ?? [];
        $temDuplicidadeSnapshot = collect($warningsSnapshot)->contains(
            fn($w) => ($w['tipo'] ?? null) === 'possivel_duplicidade'
        );
        $temDuplicidadeEvento = $c->hubspotEventos->contains(
            fn($ev) => isset(($ev->payload ?? [])['possivel_duplicidade'])
        );
        if ($temDuplicidadeSnapshot || $temDuplicidadeEvento) {
            $pendencias[] = 'possivel_duplicidade';
        }

        return $pendencias;
    }

    /**
     * Helper compartilhado entre `calcular()` (listagem do Comercial, só
     * origem HubSpot) e `calcularUniversais()` (gate administrativo da
     * Fase 128, qualquer origem). Corpo copiado literalmente da checagem
     * que já existia dentro de `calcular()` — nenhuma condição foi
     * reescrita.
     *
     * @return string|null  'sem_servico' se a empresa não tem nenhum contrato ativo
     */
    private function pendenciaSemServico(Collection $contratosAtivos): ?string
    {
        return $contratosAtivos->isEmpty() ? 'sem_servico' : null;
    }

    /**
     * Helper compartilhado entre `calcular()` (listagem do Comercial, só
     * origem HubSpot) e `calcularUniversais()` (gate administrativo da
     * Fase 128, qualquer origem). Corpo copiado literalmente da checagem
     * que já existia dentro de `calcular()` — nenhuma condição foi
     * reescrita.
     *
     * @return string|null  'sem_valor' se algum contrato ativo tem valor_contratado=0
     */
    private function pendenciaSemValor(Collection $contratosAtivos): ?string
    {
        if ($contratosAtivos->isNotEmpty() && $contratosAtivos->contains(fn($ct) => (float) $ct->valor_contratado === 0.0)) {
            return 'sem_valor';
        }

        return null;
    }

    /**
     * Helper compartilhado entre `calcular()` (listagem do Comercial, só
     * origem HubSpot) e `calcularUniversais()` (gate administrativo da
     * Fase 128, qualquer origem). Corpo copiado literalmente da checagem
     * que já existia dentro de `calcular()` — nenhuma condição foi
     * reescrita.
     *
     * @return string|null  'sem_setor' se TODOS os contratos ativos apontam para
     *                       Servico com setor='outros' (ou sem setor)
     */
    private function pendenciaSemSetor(Collection $contratosAtivos): ?string
    {
        if ($contratosAtivos->isEmpty()) {
            return null;
        }

        $todosOutros = $contratosAtivos->every(function ($ct) {
            $setor = optional($ct->servico)->setor;
            return $setor === Servico::SETOR_OUTROS || $setor === null;
        });

        return $todosOutros ? 'sem_setor' : null;
    }

    /**
     * Helper compartilhado entre `calcular()` (listagem do Comercial, só
     * origem HubSpot) e `calcularUniversais()` (gate administrativo da
     * Fase 128, qualquer origem). Corpo copiado literalmente da checagem
     * que já existia dentro de `calcular()` — nenhuma condição foi
     * reescrita.
     *
     * @return string|null  'sem_contato' se o contato principal não foi resolvido
     */
    private function pendenciaSemContato(Company $c): ?string
    {
        return ($c->nome_contato === null || $c->nome_contato === '') ? 'sem_contato' : null;
    }
}
