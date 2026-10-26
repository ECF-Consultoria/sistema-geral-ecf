<?php

namespace App\Services\Publicador\Agente;

use App\Models\PubAgenteConfiguracao;
use App\Models\PubAgenteExecucao;
use App\Models\PubAprovacaoOferta;
use App\Models\User;
use App\Services\Publicador\Aprovacao\AndamentoDaOfertaService;

/**
 * O que a tela do agente mostra de UMA loja (AG-07, 20/10/2026): configuração, contadores, a fila de trabalho e
 * a atividade. Só lê o banco — nenhuma chamada ao ML ou à IA no request.
 *
 * Uma oferta aparece na fila do agente e na Aprovação por PROJEÇÃO do mesmo registro: o agente não tem fila de
 * negócio própria. Os contadores declaram a unidade — preparo conta PRODUTOS (execuções), Aprovação e
 * publicação contam OFERTAS.
 */
class LeituraDoAgente
{
    /** Quantas linhas a fila e a atividade trazem (as mais recentes). */
    public const LIMITE = 50;

    private const ESPERANDO_GENTE = [
        PubAprovacaoOferta::AGUARDANDO_APROVACAO, PubAprovacaoOferta::EM_REVISAO_ECF, PubAprovacaoOferta::AJUSTES_SOLICITADOS,
    ];

    private const NA_PUBLICACAO = [PubAprovacaoOferta::APROVADO, PubAprovacaoOferta::PUBLICANDO];

    public function __construct(
        private ConfiguracaoDoAgenteService $config,
        private AndamentoDaOfertaService $andamento,
    ) {}

    public function paraConta(array $conta): array
    {
        $cfg = $this->config->daConta($conta);
        $ofertas = $conta['company'] === null && $conta['mlb_empresa'] === null
            ? null
            : $this->andamento->filaDaConta($conta['company']?->id, $conta['mlb_empresa']?->id);
        $porAndamento = $ofertas === null ? collect() : (clone $ofertas)->toBase()
            ->selectRaw('andamento, count(*) as n')->groupBy('andamento')->pluck('n', 'andamento');
        $soma = fn (array $andamentos) => (int) $porAndamento->only($andamentos)->sum();

        $execucoes = PubAgenteExecucao::query()->where('conta_chave', $conta['chave']);
        $diaSp = now()->setTimezone('America/Sao_Paulo')->startOfDay()->setTimezone(config('app.timezone'));

        return [
            'configuracao' => [
                'existe' => $cfg->exists,
                'versao' => (int) $cfg->versao,
                'habilitado' => (bool) $cfg->habilitado,
                'controle' => $cfg->controle,
                'motivo_controle' => $cfg->motivo_controle,
                'modalidade' => $cfg->modalidade,
                'estoque_minimo' => (int) $cfg->estoque_minimo,
                'limite_diario' => (int) $cfg->limite_diario,
                'teto_fila' => (int) $cfg->teto_fila,
                // ⚠️ O ritmo vai CRU (pode ser `null` = "use o padrão") **e** resolvido, porque a
                // tela precisa dos dois: o campo mostra o cru (vazio = padrão) e a frase de apoio
                // mostra o efetivo. Mandar só o resolvido faria o campo vazio virar "1440" ao
                // salvar, fixando na loja um valor que ela nunca escolheu.
                'cooldown_minutos' => $cfg->cooldown_minutos === null ? null : (int) $cfg->cooldown_minutos,
                'cooldown_efetivo' => $cfg->cooldownEfetivo(),
                'tentativas_max' => $cfg->tentativas_max === null ? null : (int) $cfg->tentativas_max,
                'tentativas_efetivas' => $cfg->tentativasEfetivas(),
                'janela_inicio' => $cfg->janela_inicio ? substr((string) $cfg->janela_inicio, 0, 5) : null,
                'janela_fim' => $cfg->janela_fim ? substr((string) $cfg->janela_fim, 0, 5) : null,
                // ⚠️ O calendário (E1): `[1..7]` em ISO. **Nulo vai como `[]` para a tela**, porque lá
                // "nenhum marcado" e "todo dia" são o MESMO estado — e o servidor já trata os dois igual.
                'dias_semana' => array_values(array_filter(
                    array_map('intval', (array) ($cfg->dias_semana ?? [])),
                    fn (int $d) => $d >= 1 && $d <= 7,
                )),
                'escopo' => $cfg->escopo ?? ['incluir' => [], 'excluir' => []],
                'atualizado_em' => $cfg->updated_at?->toIso8601String(),
            ],
            'contadores' => [
                // produtos (execuções do agente)
                'aguardando_dados' => (clone $execucoes)->where('estado', PubAgenteExecucao::BLOQUEADA)
                    ->where('concluida_em', '>=', now()->subDay())->count(),
                'preparando' => (clone $execucoes)->whereIn('estado', PubAgenteExecucao::VIVAS)->count(),
                'admitidos_hoje' => (clone $execucoes)->where('iniciada_em', '>=', $diaSp)
                    ->whereNotIn('estado', [PubAgenteExecucao::BLOQUEADA, PubAgenteExecucao::CANCELADA])->count(),
                // ofertas (a fila de Aprovação da conta, a mesma da tela da Aprovação)
                'aguardando_aprovacao' => $soma(self::ESPERANDO_GENTE),
                'publicacao' => $soma(self::NA_PUBLICACAO),
                'falhas' => $soma([PubAprovacaoOferta::FALHA]),
                'publicadas' => $soma([PubAprovacaoOferta::PUBLICADO]),
            ],
            // ⚠️ Os rótulos dos dias saem do SERVIDOR, do `ROTULO_DO_DIA` do modelo — **um vocabulário
            // só**. Uma segunda lista em JS divergiria, e divergir é erro medido nas Fases 186/187.
            'dias_opcoes' => array_map(
                fn (int $d) => ['valor' => $d, 'rotulo' => PubAgenteConfiguracao::ROTULO_DO_DIA[$d]],
                PubAgenteConfiguracao::DIAS_ISO,
            ),
            'fila' => $this->fila($conta),
        ];
    }

    /** As execuções mais recentes da loja, com o produto, o motivo e os destinos reais. */
    private function fila(array $conta): array
    {
        $execucoes = PubAgenteExecucao::query()
            ->where('conta_chave', $conta['chave'])
            ->with(['produto:id,nome,sku'])
            ->latest('updated_at')->latest('id')
            ->limit(self::LIMITE)->get();
        $pessoas = User::query()->whereIn('id', $execucoes->pluck('solicitada_por')->filter()->unique())->pluck('name', 'id');

        $ofertas = PubAprovacaoOferta::query()
            ->whereIn('produto_id', $execucoes->pluck('produto_id')->filter()->unique())
            ->get(['id', 'produto_id', 'andamento', 'company_id'])
            ->keyBy('produto_id');

        return $execucoes->map(function (PubAgenteExecucao $e) use ($ofertas, $conta, $pessoas) {
            $oferta = $e->produto_id !== null ? $ofertas->get($e->produto_id) : null;
            $companyId = $oferta?->company_id ?? $conta['company']?->id;

            return [
                'id' => $e->uuid,
                'produto' => $e->produto ? ['id' => $e->produto->id, 'nome' => $e->produto->nome, 'sku' => $e->produto->sku] : null,
                'estado' => $e->estado,
                'etapa' => $e->etapa,
                'origem' => $e->origem,
                'motivo' => $e->motivo,
                'bloqueios' => array_values(array_map(fn ($b) => [
                    'codigo' => (string) ($b['codigo'] ?? ''),
                    'mensagem' => (string) ($b['mensagem'] ?? ''),
                    'url_correcao' => $b['url_correcao'] ?? null,
                ], (array) ($e->bloqueios ?? []))),
                'tentativas' => (int) $e->tentativas,
                'solicitada_por' => $e->solicitada_por !== null ? ($pessoas[$e->solicitada_por] ?? null) : null,
                'iniciada_em' => $e->iniciada_em?->toIso8601String(),
                'concluida_em' => $e->concluida_em?->toIso8601String(),
                'atualizada_em' => $e->updated_at?->toIso8601String(),
                'oferta' => $oferta === null ? null : [
                    'id' => $oferta->id,
                    'andamento' => $oferta->andamento,
                    'url' => $companyId !== null ? route('mlb.anuncios.meus.aprovacao', ['company' => $companyId, 'oferta' => $oferta->id]) : null,
                ],
                'url_editor' => $e->produto_id !== null ? route('mlb.anuncios.publicador.editor', ['produto' => $e->produto_id]) : null,
            ];
        })->values()->all();
    }
}
