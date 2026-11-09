<?php

namespace App\Services\Renovacao;

use App\Models\ContratoRenovacao;
use App\Services\Hubspot\HubspotErroApi;
use App\Services\HubspotApiClient;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Fase 202 (REN-04/REN-07) — orquestração idempotente da renovação de UM ciclo.
 *
 * Lê o estado local e o remoto, pede a decisão à política e age. A mesma
 * lógica serve o agendador e o botão "Sincronizar novamente": não existe
 * atalho que crie outro negócio.
 *
 * Garantias:
 *  - o negócio ORIGINAL nunca é tocado (não existe caminho de escrita para ele);
 *  - nenhuma etapa/valor/dono de negócio existente é alterado (nada de PATCH);
 *  - criação nunca é repetida às cegas: tentativa remota fica gravada ANTES
 *    do HTTP e só a conciliação por correlação a resolve;
 *  - INVARIANTE: um ciclo em 'criando' ou 'resultado_incerto' nunca volta a
 *    'pendente' ou 'erro' sem passar pela conciliação por correlação;
 *  - em simulação nenhuma escrita remota nem mudança de status acontece.
 */
class RenovacaoOrquestrador
{
    public function __construct(
        private HubspotApiClient $hubspot,
        private RenovacaoDetector $detector,
        private RenovacaoDecisaoPolicy $politica,
        private RenovacaoExtensaoService $extensao,
        private VinculoHubspotResolver $vinculo,
        private NegocioRenovacaoConteudo $conteudo,
    ) {}

    /**
     * @throws HubspotErroApi quando o HubSpot limita (429) na criação — o job reagenda
     */
    public function sincronizar(
        ContratoRenovacao $ciclo,
        string $origem,
        ?int $userId = null,
        ?bool $simulacao = null,
        bool $liberarCriacao = false,
        ?string $justificativa = null,
    ): RenovacaoDecisao {
        $simulacao ??= RenovacaoConfig::simulacao();
        $ciclo = $ciclo->fresh() ?? $ciclo;

        // 1. Elegibilidade a partir do banco (e correção do término, sem criar ciclo).
        $termino  = $this->detector->sincronizarTermino($ciclo, $origem);
        $ciclo    = $ciclo->fresh();
        $elegivel = $termino !== null;

        // Liberação manual: zera o contador; a política ainda exige a janela vencida.
        if ($liberarCriacao && ! $simulacao) {
            $ciclo->update(['tentativas' => 0]);
            $ciclo->registrarEvento('liberacao_manual', $origem, null, [
                'justificativa' => $justificativa,
            ], false, $userId);
        }

        $cfg = config('services.hubspot.renovacao');

        // 2. Leituras remotas. Falha de leitura NUNCA leva a criar.
        $negocioVinculado = null;
        $correlacao       = null;
        $correlacaoBruta  = [];
        $erroLeitura      = null;

        try {
            $props = ['pipeline', 'dealstage', $cfg['propriedade_chave']];

            if ($ciclo->negocio_renovacao_id !== null) {
                $deal = $this->hubspot->buscarDealOuNull((string) $ciclo->negocio_renovacao_id, $props);
                $negocioVinculado = $deal === null
                    ? ['existe' => false]
                    : $this->resumoDeal($deal);
            }

            $correlacaoBruta = $this->hubspot->buscarDealsPorPropriedade(
                $cfg['propriedade_chave'], (string) $ciclo->chave_correlacao, $props
            );
            $correlacao = array_map(fn ($d) => ['id' => $d['id']] + $this->resumoDeal($d), $correlacaoBruta);
        } catch (HubspotErroApi $e) {
            $erroLeitura = $e;
        }

        if ($erroLeitura !== null) {
            $decisao = new RenovacaoDecisao(
                RenovacaoDecisao::ERRO_LEITURA, 'leitura_falhou', $ciclo->negocio_renovacao_id,
                'Não foi possível ler o HubSpot agora; nada foi criado.'
            );
            $this->registrarDecisao($ciclo, $decisao, $origem, $userId, $simulacao, $correlacao, $erroLeitura->getMessage());
            $this->tratarErroLeitura($ciclo, $erroLeitura, $simulacao);

            return $decisao;
        }

        // 3. Decisão.
        $ctx = $this->montarContexto($ciclo, $elegivel, $negocioVinculado, $correlacao, $liberarCriacao);
        $decisao = $this->politica->decidir($ctx);
        $this->registrarDecisao($ciclo, $decisao, $origem, $userId, $simulacao, $correlacao);

        // 5. Simulação: para aqui.
        if ($simulacao) {
            return $decisao;
        }

        // 6. Agir.
        return $this->executar($ciclo, $decisao, $ctx, $origem, $userId, $correlacao);
    }

    // ═══ Ações ═══

    private function executar(ContratoRenovacao $ciclo, RenovacaoDecisao $decisao, array $ctx, string $origem, ?int $userId, ?array $correlacao): RenovacaoDecisao
    {
        switch ($decisao->acao) {
            case RenovacaoDecisao::CONCILIAR_ABERTO:
                $this->conciliarAberto($ciclo, $ctx);
                break;

            case RenovacaoDecisao::VINCULAR_POR_CORRELACAO:
                return $this->vincular($ciclo, $decisao, $ctx, $origem, $userId, $correlacao);

            case RenovacaoDecisao::REGISTRAR_GANHO:
                $this->gravarObservado($ciclo, $ctx);
                $this->extensao->registrarGanho($ciclo, $origem, [
                    'negocio_renovacao_id' => $decisao->negocioId,
                ], $userId);
                break;

            case RenovacaoDecisao::REGISTRAR_PERDA:
                $this->gravarObservado($ciclo, $ctx);
                $ciclo->update([
                    'status_comercial'  => ContratoRenovacao::STATUS_COMERCIAL_NAO_RENOVADO,
                    'status_integracao' => ContratoRenovacao::STATUS_INTEGRACAO_SINCRONIZADO,
                    'ultimo_erro'       => null,
                ]);
                break;

            case RenovacaoDecisao::CRIAR:
                $this->criar($ciclo, $origem, $userId);
                break;

            case RenovacaoDecisao::REVISAO_MANUAL:
                $ciclo->update([
                    'status_integracao' => ContratoRenovacao::STATUS_INTEGRACAO_REVISAO_MANUAL,
                    'ultimo_erro'       => $decisao->explicacao,
                ]);
                $this->log('error', "ciclo {$ciclo->id} em revisão manual ({$decisao->motivo}): {$decisao->explicacao}", $ciclo, $decisao);
                break;

            case RenovacaoDecisao::AGUARDAR_CONCILIACAO:
            case RenovacaoDecisao::NAO_ELEGIVEL:
            case RenovacaoDecisao::NADA_A_FAZER:
            default:
                break;
        }

        return $decisao;
    }

    private function conciliarAberto(ContratoRenovacao $ciclo, array $ctx): void
    {
        $etapa = $ctx['negocio_vinculado']['etapa'] ?? null;
        $negociando = (string) config('services.hubspot.renovacao.stage_negociando_id');

        $ciclo->update([
            'pipeline_observado' => $ctx['negocio_vinculado']['pipeline'] ?? null,
            'etapa_observada'    => $etapa,
            'status_comercial'   => $etapa === $negociando
                ? ContratoRenovacao::STATUS_COMERCIAL_EM_NEGOCIACAO
                : ContratoRenovacao::STATUS_COMERCIAL_IDENTIFICADO,
            'status_integracao'  => ContratoRenovacao::STATUS_INTEGRACAO_SINCRONIZADO,
            'ultimo_erro'        => null,
        ]);

        $this->completarAssociacaoEmpresa($ciclo->fresh());
    }

    private function gravarObservado(ContratoRenovacao $ciclo, array $ctx): void
    {
        $ciclo->update([
            'pipeline_observado' => $ctx['negocio_vinculado']['pipeline'] ?? null,
            'etapa_observada'    => $ctx['negocio_vinculado']['etapa'] ?? null,
        ]);
    }

    private function vincular(ContratoRenovacao $ciclo, RenovacaoDecisao $decisao, array $ctx, string $origem, ?int $userId, ?array $correlacao): RenovacaoDecisao
    {
        try {
            $ciclo->update(['negocio_renovacao_id' => $decisao->negocioId]);
        } catch (QueryException $e) {
            $nova = new RenovacaoDecisao(
                RenovacaoDecisao::REVISAO_MANUAL, 'negocio_ja_vinculado_a_outro_ciclo', $decisao->negocioId,
                'O negócio encontrado já está vinculado a outro ciclo — conferir à mão.'
            );
            $this->registrarDecisao($ciclo, $nova, $origem, $userId, false, $correlacao);

            return $this->executar($ciclo, $nova, $ctx, $origem, $userId, $correlacao);
        }

        $ciclo = $ciclo->fresh();
        $achado = collect($correlacao ?? [])->firstWhere('id', $decisao->negocioId) ?? [];

        // Reaplica a política uma vez, agora com o negócio vinculado.
        $ctx2 = $this->montarContexto(
            $ciclo, (bool) $ctx['elegivel'],
            ['existe' => true, 'pipeline' => $achado['pipeline'] ?? null, 'etapa' => $achado['etapa'] ?? null],
            $correlacao, false
        );
        $segunda = $this->politica->decidir($ctx2);
        $this->registrarDecisao($ciclo, $segunda, $origem, $userId, false, $correlacao);

        return $this->executar($ciclo, $segunda, $ctx2, $origem, $userId, $correlacao);
    }

    private function criar(ContratoRenovacao $ciclo, string $origem, ?int $userId): void
    {
        $cfg = config('services.hubspot.renovacao');

        $anteriorInicio    = $ciclo->criacao_iniciada_em;
        $anteriorTentativa = (int) $ciclo->tentativas;
        $anteriorStatus    = $ciclo->status_integracao;

        // Registro durável da tentativa remota — commit ANTES do HTTP.
        DB::transaction(function () use ($ciclo) {
            $ciclo->update([
                'status_integracao'   => ContratoRenovacao::STATUS_INTEGRACAO_CRIANDO,
                'criacao_iniciada_em' => now(),
                'tentativas'          => $ciclo->tentativas + 1,
            ]);
        });

        $company = $ciclo->company;
        $props   = $this->propriedadesDoDeal($ciclo);

        // 09/11/2026 — descobre empresa/contato do HubSpot que faltam no
        // cadastro (negócio original → e-mail). Falha de leitura aqui NÃO
        // impede a criação: o usuário decidiu que sem vínculo o negócio sai
        // mesmo assim, sem associação, para o prazo não se perder.
        $vinculo = null;
        try {
            $vinculo = $this->vinculo->resolver($company, true);
        } catch (HubspotErroApi $e) {
            $this->log('warning', "ciclo {$ciclo->id}: não foi possível procurar o vínculo no HubSpot: " . mb_substr($e->getMessage(), 0, 300), $ciclo);
        }

        $companyIdHs = $ciclo->hubspot_company_id ?: ($vinculo['company_id'] ?? $company->hubspot_company_id);
        $contactIdHs = $vinculo['contact_id'] ?? $company->hubspot_contact_id;

        try {
            $deal = $this->hubspot->criarDeal(
                $props,
                $companyIdHs ?: null,
                $contactIdHs ?: null
            );
        } catch (HubspotErroApi $e) {
            $this->tratarErroCriacao($ciclo, $e, $origem, $userId, $anteriorInicio, $anteriorTentativa, $anteriorStatus);

            if ($e->ehLimite()) {
                throw $e;
            }

            return;
        }

        try {
            $ciclo->update([
                'negocio_renovacao_id' => $deal['id'],
                'status_integracao'    => ContratoRenovacao::STATUS_INTEGRACAO_SINCRONIZADO,
                'pipeline_observado'   => (string) $cfg['pipeline_id'],
                'etapa_observada'      => (string) $cfg['stage_inicial_id'],
                'ultimo_erro'          => null,
            ]);
        } catch (QueryException $e) {
            // O negócio foi criado, mas o ID já pertence a outro ciclo: nunca sobrescrever.
            $ciclo->update([
                'status_integracao' => ContratoRenovacao::STATUS_INTEGRACAO_REVISAO_MANUAL,
                'ultimo_erro'       => "Negócio {$deal['id']} criado, mas já vinculado a outro ciclo.",
            ]);
            $this->log('error', "ciclo {$ciclo->id}: negócio {$deal['id']} criado já pertence a outro ciclo", $ciclo);

            return;
        }

        $ciclo->registrarEvento('negocio_criado', $origem, RenovacaoDecisao::CRIAR, [
            'negocio_renovacao_id' => $deal['id'],
            'negocio_original_id'  => $ciclo->negocio_original_id,
            'negocio_original_ausente' => $ciclo->negocio_original_id === null,
            'chave_correlacao'     => $ciclo->chave_correlacao,
            // 09/11/2026 — com o que o negócio foi associado (empresa,
            // contato, nenhum) e de onde veio cada vínculo.
            'associado_empresa'    => $companyIdHs ?: null,
            'associado_contato'    => $contactIdHs ?: null,
            'fonte_empresa'        => $vinculo['fonte_empresa'] ?? null,
            'fonte_contato'        => $vinculo['fonte_contato'] ?? null,
        ], false, $userId);
        $this->log('info', "ciclo {$ciclo->id}: negócio {$deal['id']} criado", $ciclo);

        $this->completarAssociacaoEmpresa($ciclo->fresh());
        $this->criarObservacao($ciclo->fresh(), $origem);
    }

    private function tratarErroCriacao(ContratoRenovacao $ciclo, HubspotErroApi $e, string $origem, ?int $userId, $anteriorInicio, int $anteriorTentativa, string $anteriorStatus): void
    {
        $msg = mb_substr($e->getMessage(), 0, 500);

        if ($e->ehLimite()) {
            // 429 = não processado: devolve o estado anterior.
            $restaurar = in_array($anteriorStatus, [
                ContratoRenovacao::STATUS_INTEGRACAO_RESULTADO_INCERTO,
                ContratoRenovacao::STATUS_INTEGRACAO_REVISAO_MANUAL,
            ], true) ? $anteriorStatus : ContratoRenovacao::STATUS_INTEGRACAO_PENDENTE;

            $ciclo->update([
                'status_integracao'   => $restaurar,
                'criacao_iniciada_em' => $anteriorInicio,
                'tentativas'          => $anteriorTentativa,
            ]);
            $this->log('error', "ciclo {$ciclo->id}: HubSpot limitou a criação (429); reagendando", $ciclo);

            return;
        }

        if ($e->ehIncerto() || $e->tipo === HubspotErroApi::CONFLITO) {
            // Mantém criacao_iniciada_em: a conciliação pela correlação resolve.
            $ciclo->update([
                'status_integracao' => ContratoRenovacao::STATUS_INTEGRACAO_RESULTADO_INCERTO,
                'ultimo_erro'       => $msg,
            ]);
            $ciclo->registrarEvento('resultado_incerto', $origem, RenovacaoDecisao::CRIAR, [
                'tipo_erro' => $e->tipo, 'status_http' => $e->status, 'chave_correlacao' => $ciclo->chave_correlacao,
            ], false, $userId);
            $this->log('error', "ciclo {$ciclo->id}: resultado incerto na criação ({$e->tipo}): {$msg}", $ciclo);

            return;
        }

        // Permanente (4xx claro): não criou.
        $ciclo->update([
            'status_integracao'   => ContratoRenovacao::STATUS_INTEGRACAO_ERRO,
            'ultimo_erro'         => $msg,
            'criacao_iniciada_em' => $anteriorInicio,
        ]);
        $ciclo->registrarEvento('erro_criacao', $origem, RenovacaoDecisao::CRIAR, [
            'status_http' => $e->status,
        ], false, $userId);
        $this->log('error', "ciclo {$ciclo->id}: erro permanente ao criar negócio: {$msg}", $ciclo);
    }

    private function tratarErroLeitura(ContratoRenovacao $ciclo, HubspotErroApi $e, bool $simulacao): void
    {
        $msg = mb_substr($e->getMessage(), 0, 500);
        $this->log('error', "ciclo {$ciclo->id}: falha de leitura no HubSpot ({$e->tipo}): {$msg}", $ciclo);

        if ($simulacao) {
            return;
        }

        $tentativaRemota = $ciclo->criacao_iniciada_em !== null && $ciclo->negocio_renovacao_id === null;
        $preserva = $tentativaRemota || in_array($ciclo->status_integracao, [
            ContratoRenovacao::STATUS_INTEGRACAO_CRIANDO,
            ContratoRenovacao::STATUS_INTEGRACAO_RESULTADO_INCERTO,
            ContratoRenovacao::STATUS_INTEGRACAO_REVISAO_MANUAL,
        ], true);

        $dados = ['ultimo_erro' => $msg];
        if (! $preserva) {
            $dados['status_integracao'] = ContratoRenovacao::STATUS_INTEGRACAO_ERRO;
        }
        $ciclo->update($dados);
    }

    /** Completa só o que falta: associação do negócio com a empresa. */
    private function completarAssociacaoEmpresa(ContratoRenovacao $ciclo): void
    {
        $companyId = $ciclo->hubspot_company_id ?: $ciclo->company->hubspot_company_id;
        $dealId    = $ciclo->negocio_renovacao_id;

        if (! $companyId || ! $dealId) {
            return;
        }

        try {
            $associadas = $this->hubspot->fetchAssociatedCompanyIds((string) $dealId);

            if (! in_array((string) $companyId, $associadas, true)) {
                $this->hubspot->associarDealPadrao((string) $dealId, 'companies', (string) $companyId);
                $ciclo->registrarEvento('associacao_completada', 'sistema', null, [
                    'negocio_renovacao_id' => $dealId,
                ]);
            }
        } catch (HubspotErroApi $e) {
            $this->log('error', "ciclo {$ciclo->id}: não foi possível completar a associação com a empresa: " . mb_substr($e->getMessage(), 0, 300), $ciclo);
        }
    }

    // ═══ Montagem ═══

    private function propriedadesDoDeal(ContratoRenovacao $ciclo): array
    {
        $cfg     = config('services.hubspot.renovacao');
        $company = $ciclo->company;

        // 09/11/2026 — nome, data de fechamento, descrição e dados do cliente
        // legíveis vêm de NegocioRenovacaoConteudo (pedido do usuário).
        $props = $this->conteudo->propriedades($ciclo) + [
            'pipeline'                => (string) $cfg['pipeline_id'],
            'dealstage'               => (string) $cfg['stage_inicial_id'],
            $cfg['propriedade_chave'] => $ciclo->chave_correlacao,
        ];

        if ($ciclo->negocio_original_id !== null) {
            $props[$cfg['propriedade_negocio_original']] = (string) $ciclo->negocio_original_id;
            $portal = config('services.hubspot.portal_id');
            $props['description'] .= "
Negócio original: " . ($portal
                ? "https://app.hubspot.com/contacts/{$portal}/record/0-3/{$ciclo->negocio_original_id}"
                : $ciclo->negocio_original_id);
        }
        // Dono: só se a empresa tem — nunca inventa (D-04).
        if (filled($company->hubspot_owner_id)) {
            $props['hubspot_owner_id'] = (string) $company->hubspot_owner_id;
        }

        return $props;
    }

    /**
     * 09/11/2026 — observação (Note) com a situação do contrato. Falha aqui
     * NÃO desfaz nem repete o negócio: fica registrada no ciclo e no log, e
     * `renovacao:atualizar-negocio` refaz depois.
     */
    public function criarObservacao(ContratoRenovacao $ciclo, string $origem = 'sistema'): ?string
    {
        if (! $ciclo->negocio_renovacao_id) {
            return null;
        }

        try {
            $notaId = $this->hubspot->criarNotaNoDeal((string) $ciclo->negocio_renovacao_id, $this->conteudo->observacao($ciclo));
            $ciclo->registrarEvento('observacao_criada', $origem, null, [
                'negocio_renovacao_id' => $ciclo->negocio_renovacao_id,
                'nota_id'              => $notaId,
            ]);

            return $notaId;
        } catch (HubspotErroApi $e) {
            $this->log('error', "ciclo {$ciclo->id}: negócio criado, mas a observação falhou: " . mb_substr($e->getMessage(), 0, 300), $ciclo);
            $ciclo->registrarEvento('observacao_falhou', $origem, null, [
                'negocio_renovacao_id' => $ciclo->negocio_renovacao_id,
                'erro'                 => mb_substr($e->getMessage(), 0, 300),
            ]);

            return null;
        }
    }

    /**
     * 09/11/2026 — reaplica nome/descrição/data/dados do cliente num negócio
     * que o PRÓPRIO sistema criou (sem tocar em etapa, pipeline ou dono) e
     * acrescenta a observação. Usado por `renovacao:atualizar-negocio` para o
     * primeiro negócio criado antes desta mudança.
     *
     * @throws HubspotErroApi
     */
    public function reaplicarConteudo(ContratoRenovacao $ciclo, string $origem = 'comando'): ?string
    {
        $props = $this->conteudo->propriedades($ciclo);
        $this->hubspot->atualizarPropriedadesDoDeal((string) $ciclo->negocio_renovacao_id, $props);
        $ciclo->registrarEvento('negocio_atualizado', $origem, null, [
            'negocio_renovacao_id' => $ciclo->negocio_renovacao_id,
            'propriedades'         => array_keys($props),
        ]);

        return $this->criarObservacao($ciclo, $origem);
    }

    private function resumoDeal(array $deal): array
    {
        return [
            'existe'   => true,
            'pipeline' => isset($deal['properties']['pipeline']) ? (string) $deal['properties']['pipeline'] : null,
            'etapa'    => isset($deal['properties']['dealstage']) ? (string) $deal['properties']['dealstage'] : null,
        ];
    }

    private function montarContexto(ContratoRenovacao $ciclo, bool $elegivel, ?array $negocioVinculado, ?array $correlacao, bool $liberar): array
    {
        $cfg = config('services.hubspot.renovacao');

        return [
            'elegivel'               => $elegivel,
            'status_comercial'       => $ciclo->status_comercial,
            'status_integracao'      => $ciclo->status_integracao,
            'tentativas'             => (int) $ciclo->tentativas,
            'max_tentativas'         => (int) $cfg['max_tentativas_criacao'],
            'incerto_desde'          => $ciclo->criacao_iniciada_em,
            'tentativa_remota'       => $ciclo->criacao_iniciada_em !== null && $ciclo->negocio_renovacao_id === null,
            'agora'                  => now(),
            'janela_incerto_minutos' => (int) $cfg['janela_incerto_minutos'],
            'tem_hubspot_company'    => filled($ciclo->hubspot_company_id) || filled($ciclo->company->hubspot_company_id),
            'liberar_criacao'        => $liberar,
            'negocio_vinculado_id'   => $ciclo->negocio_renovacao_id,
            'negocio_vinculado'      => $negocioVinculado,
            'correlacao'             => $correlacao,
            'pipeline_renovacao'     => (string) $cfg['pipeline_id'],
            'etapas_abertas'         => [(string) $cfg['stage_inicial_id'], (string) $cfg['stage_negociando_id']],
            'etapa_ganho'            => (string) $cfg['stage_fechado_id'],
            'etapas_perdido'         => array_map('strval', (array) $cfg['stages_perdido_ids']),
        ];
    }

    private function registrarDecisao(ContratoRenovacao $ciclo, RenovacaoDecisao $decisao, string $origem, ?int $userId, bool $simulacao, ?array $correlacao, ?string $erro = null): void
    {
        $ciclo->update([
            'ultima_decisao'          => $decisao->acao,
            'ultima_decisao_simulada' => $simulacao,
            'ultima_sincronizacao_em' => now(),
        ]);

        // Sem dados pessoais: só IDs, chave e motivo.
        $ciclo->registrarEvento($simulacao ? 'simulacao' : $decisao->acao, $origem, $decisao->acao, [
            'negocio_original_id'  => $ciclo->negocio_original_id,
            'negocio_renovacao_id' => $ciclo->negocio_renovacao_id,
            'chave_correlacao'     => $ciclo->chave_correlacao,
            'motivo'               => $decisao->motivo,
            'explicacao'           => $decisao->explicacao,
            'origem'               => $origem,
            'ids_correlacao'       => $correlacao === null ? null : array_column($correlacao, 'id'),
            'erro'                 => $erro,
        ], $simulacao, $userId);
    }

    private function log(string $nivel, string $mensagem, ContratoRenovacao $ciclo, ?RenovacaoDecisao $decisao = null): void
    {
        Log::channel('ecf-webhooks')->{$nivel}("[Renovacao] {$mensagem}", [
            'ciclo_id'   => $ciclo->id,
            'company_id' => $ciclo->company_id,
            'chave'      => $ciclo->chave_correlacao,
            'decisao'    => $decisao?->acao,
        ]);
    }
}
