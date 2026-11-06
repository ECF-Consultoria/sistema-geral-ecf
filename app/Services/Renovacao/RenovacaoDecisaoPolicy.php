<?php

namespace App\Services\Renovacao;

use App\Models\ContratoRenovacao;
use Carbon\Carbon;

/**
 * Fase 202 (REN-04) — política de decisão da renovação (matriz §4.3.4).
 *
 * PURA: sem banco, sem HTTP, sem config — tudo chega no contexto, inclusive
 * os IDs de etapa. Regra de ouro: só decide CRIAR quando não há negócio do
 * ciclo, não há conflito, não há tentativa de criação em aberto e a leitura
 * do HubSpot foi executada. Nenhum caminho move negócio de outro pipeline
 * (origens autorizadas vazias — plano-fonte §4.3.3).
 *
 * Contexto: elegivel, status_comercial, status_integracao, tentativas,
 * max_tentativas, incerto_desde (criacao_iniciada_em), tentativa_remota,
 * agora, janela_incerto_minutos, tem_hubspot_company, liberar_criacao,
 * negocio_vinculado_id, negocio_vinculado (null|[existe,pipeline,etapa]),
 * correlacao (null|list<[id,pipeline,etapa]>), pipeline_renovacao,
 * etapas_abertas, etapa_ganho, etapas_perdido.
 */
class RenovacaoDecisaoPolicy
{
    public function decidir(array $ctx): RenovacaoDecisao
    {
        $vinculadoId = $ctx['negocio_vinculado_id'] ?? null;
        $correlacao  = $ctx['correlacao'] ?? null;
        $liberar     = (bool) ($ctx['liberar_criacao'] ?? false);

        // Matriz: ciclo em estado final — nada a fazer.
        if (in_array($ctx['status_comercial'] ?? null, ContratoRenovacao::STATUS_COMERCIAIS_FINAIS, true)) {
            return new RenovacaoDecisao(RenovacaoDecisao::NADA_A_FAZER, 'status_final', $vinculadoId, 'Ciclo já finalizado.');
        }

        // Matriz: contrato/empresa inelegível. Sem negócio ignora; com negócio nunca mexe sozinho.
        if (! ($ctx['elegivel'] ?? false)) {
            $temNegocio = $vinculadoId !== null || (is_array($correlacao) && count($correlacao) > 0);

            return $temNegocio
                ? new RenovacaoDecisao(RenovacaoDecisao::REVISAO_MANUAL, 'contrato_inelegivel_com_negocio_aberto', $vinculadoId, 'Contrato inelegível, mas existe negócio de renovação — conferir à mão.')
                : new RenovacaoDecisao(RenovacaoDecisao::NAO_ELEGIVEL, 'inelegivel', null, 'Contrato ou empresa inelegível para renovação.');
        }

        // Matriz: negócio já vinculado ao ciclo — validar, nunca criar outro.
        if ($vinculadoId !== null) {
            return $this->decidirComVinculo($ctx, (string) $vinculadoId, $correlacao);
        }

        // Matriz: busca por correlação não executada — nunca criar às cegas.
        if ($correlacao === null) {
            return new RenovacaoDecisao(RenovacaoDecisao::ERRO_LEITURA, 'busca_nao_executada', null, 'A busca no HubSpot não foi executada; nada foi criado.');
        }

        // Matriz: correlação devolveu 2+ negócios.
        if (count($correlacao) > 1) {
            return new RenovacaoDecisao(RenovacaoDecisao::REVISAO_MANUAL, 'correlacao_duplicada', null, 'Mais de um negócio com a mesma chave de renovação — conferir à mão.');
        }

        // Matriz: um negócio com a nossa chave, ainda não vinculado.
        if (count($correlacao) === 1) {
            return new RenovacaoDecisao(RenovacaoDecisao::VINCULAR_POR_CORRELACAO, null, (string) $correlacao[0]['id'], 'Negócio encontrado pela chave de renovação; vinculando ao ciclo.');
        }

        // A partir daqui: sem vínculo e correlação vazia.
        $statusIntegracao = $ctx['status_integracao'] ?? null;
        $statusIncerto    = in_array($statusIntegracao, [
            ContratoRenovacao::STATUS_INTEGRACAO_CRIANDO,
            ContratoRenovacao::STATUS_INTEGRACAO_RESULTADO_INCERTO,
        ], true);
        $tentativaRemota = (bool) ($ctx['tentativa_remota'] ?? false);

        // Matriz: criação em andamento / resultado incerto / tentativa remota durável.
        // O registro durável (tentativa_remota) vale mesmo que o status tenha sido trocado.
        if ($statusIncerto || $tentativaRemota) {
            if (! $this->janelaPassou($ctx)) {
                return new RenovacaoDecisao(RenovacaoDecisao::AGUARDAR_CONCILIACAO, 'aguardando_visibilidade', null, 'Uma criação pode estar em andamento; aguardando o HubSpot expor o negócio.');
            }

            if (! $liberar) {
                $motivo = $statusIncerto ? 'resultado_incerto_sem_negocio' : 'tentativa_remota_sem_negocio';

                return new RenovacaoDecisao(RenovacaoDecisao::REVISAO_MANUAL, $motivo, null, 'A criação foi tentada, mas nenhum negócio apareceu no HubSpot — conferir à mão antes de criar.');
            }
            // liberar_criacao + janela vencida: segue para as checagens de identidade.
        } elseif ($statusIntegracao === ContratoRenovacao::STATUS_INTEGRACAO_REVISAO_MANUAL && ! $liberar) {
            // Matriz: revisão manual mantida enquanto ninguém liberar.
            return new RenovacaoDecisao(RenovacaoDecisao::REVISAO_MANUAL, 'revisao_manual_pendente', null, 'Ciclo em revisão manual; aguardando liberação.');
        }

        // Matriz: precisa da empresa no HubSpot para associar o negócio.
        if (! ($ctx['tem_hubspot_company'] ?? false)) {
            return new RenovacaoDecisao(RenovacaoDecisao::REVISAO_MANUAL, 'empresa_sem_hubspot', null, 'A empresa não tem vínculo com o HubSpot.');
        }

        // Matriz: tentativas esgotadas (a liberação ignora o contador).
        if (! $liberar && (int) ($ctx['tentativas'] ?? 0) >= (int) ($ctx['max_tentativas'] ?? 3)) {
            return new RenovacaoDecisao(RenovacaoDecisao::REVISAO_MANUAL, 'tentativas_esgotadas', null, 'Número máximo de tentativas de criação atingido.');
        }

        // Matriz: nada vinculado, nada encontrado, empresa identificada — criar.
        return new RenovacaoDecisao(RenovacaoDecisao::CRIAR, null, null, 'Nenhum negócio de renovação existe para este ciclo; criando.');
    }

    private function decidirComVinculo(array $ctx, string $vinculadoId, ?array $correlacao): RenovacaoDecisao
    {
        $negocio = $ctx['negocio_vinculado'] ?? null;

        if ($negocio === null) {
            return new RenovacaoDecisao(RenovacaoDecisao::ERRO_LEITURA, 'vinculado_nao_consultado', $vinculadoId, 'O negócio vinculado não pôde ser consultado agora.');
        }

        // Matriz: negócio vinculado removido/arquivado.
        if (! ($negocio['existe'] ?? false)) {
            return new RenovacaoDecisao(RenovacaoDecisao::REVISAO_MANUAL, 'vinculado_removido', $vinculadoId, 'O negócio vinculado foi apagado ou arquivado no HubSpot.');
        }

        // Matriz: negócio vinculado em outro pipeline — nunca move.
        if (($negocio['pipeline'] ?? null) !== ($ctx['pipeline_renovacao'] ?? null)) {
            return new RenovacaoDecisao(RenovacaoDecisao::REVISAO_MANUAL, 'vinculado_fora_do_pipeline', $vinculadoId, 'O negócio vinculado está fora do pipeline de renovação.');
        }

        $etapa = $negocio['etapa'] ?? null;
        $conhecidas = array_merge(
            (array) ($ctx['etapas_abertas'] ?? []),
            (array) ($ctx['etapas_perdido'] ?? []),
            [$ctx['etapa_ganho'] ?? null],
        );

        // Matriz: etapa que não conhecemos.
        if (! in_array($etapa, $conhecidas, true)) {
            return new RenovacaoDecisao(RenovacaoDecisao::REVISAO_MANUAL, 'etapa_desconhecida', $vinculadoId, 'O negócio está numa etapa que o sistema não reconhece.');
        }

        // Matriz: correlação com mais de um negócio ou com outro negócio.
        if (is_array($correlacao)) {
            if (count($correlacao) > 1) {
                return new RenovacaoDecisao(RenovacaoDecisao::REVISAO_MANUAL, 'correlacao_duplicada', $vinculadoId, 'Mais de um negócio com a mesma chave de renovação — conferir à mão.');
            }
            if (count($correlacao) === 1 && (string) $correlacao[0]['id'] !== $vinculadoId) {
                return new RenovacaoDecisao(RenovacaoDecisao::REVISAO_MANUAL, 'conflito_de_vinculo', $vinculadoId, 'A chave de renovação aponta para um negócio diferente do vinculado.');
            }
        }

        if ($etapa === ($ctx['etapa_ganho'] ?? null)) {
            return new RenovacaoDecisao(RenovacaoDecisao::REGISTRAR_GANHO, null, $vinculadoId, 'Renovação ganha no HubSpot.');
        }

        if (in_array($etapa, (array) ($ctx['etapas_perdido'] ?? []), true)) {
            return new RenovacaoDecisao(RenovacaoDecisao::REGISTRAR_PERDA, null, $vinculadoId, 'Renovação perdida no HubSpot.');
        }

        // Matriz: etapa aberta — só acompanha, nunca mexe na etapa.
        return new RenovacaoDecisao(RenovacaoDecisao::CONCILIAR_ABERTO, null, $vinculadoId, 'Negócio de renovação em andamento.');
    }

    private function janelaPassou(array $ctx): bool
    {
        $desde = $ctx['incerto_desde'] ?? null;

        if (! $desde instanceof Carbon) {
            return true;
        }

        $limite = $desde->copy()->addMinutes((int) ($ctx['janela_incerto_minutos'] ?? 60));

        return $limite->lte($ctx['agora']);
    }
}
