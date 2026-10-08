<?php

namespace App\Services\Publicador;

use App\Models\CreativeIdentidade;
use App\Models\EstruturaPublicacao;
use App\Models\MlAcervoItem;
use App\Models\PubProduto;
use App\Models\PubPublicacaoItem;
use App\Models\User;
use App\Support\Publicador\AlavancasLiberadas;
use App\Support\Publicador\ContasLiberadas;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Painel da Visão geral do Publicador (Fase 173, Plano 04).
 *
 * Fonte ÚNICA de publicação: `pub_publicacoes` (editor novo). Decisão do
 * usuário que reescreveu este plano — medido em produção: 6 registros em
 * `ml_anuncio_rascunhos`, TODOS em rascunho, ZERO publicações; o assistente
 * antigo não tem o que contar aqui. `MlAnuncioRascunho` NÃO aparece em
 * nenhum método desta classe, nem como fallback.
 *
 * Zero chamada ao Mercado Livre — tudo lido do banco já gravado
 * (design_handoff_publicador/ETAPA-2-visao-geral.md).
 */
class PainelVisaoGeralService
{
    public function __construct(private AcervoTriagemService $acervoTriagem) {}

    // ═══ Base compartilhada ══════════════════════════════════════════════════

    /**
     * UMA query: itens CREATED de `pub_publicacao_itens`, escopados pela
     * dupla-âncora da conta (mesmo padrão de `PubProduto`/`CreativeIdentidade`
     * — nunca duas consultas, uma por âncora). `$comJanela` recorta os
     * últimos 30 dias (indicador); sem janela é o histórico completo lido
     * por `ultimasPublicacoes()` (Task 2).
     */
    private function baseQuery(array $alvo, bool $comJanela): Builder
    {
        $mlbEmpresaId = $alvo['mlb_empresa']?->id;
        $companyId = $alvo['company']?->id;

        $query = PubPublicacaoItem::query()
            ->join('pub_publicacoes', 'pub_publicacoes.id', '=', 'pub_publicacao_itens.publicacao_id')
            ->join('pub_rascunhos', 'pub_rascunhos.id', '=', 'pub_publicacoes.rascunho_id')
            ->join('pub_produtos', 'pub_produtos.id', '=', 'pub_rascunhos.produto_id')
            ->where('pub_publicacao_itens.status', PubPublicacaoItem::CREATED)
            ->where(function (Builder $q) use ($mlbEmpresaId, $companyId) {
                // Agrupado dentro de where(function...) — nunca um orWhere solto
                // (mesma armadilha documentada em AcervoTriagemService::escopo()).
                if ($mlbEmpresaId !== null) {
                    $q->orWhere('pub_produtos.mlb_empresa_id', $mlbEmpresaId);
                }
                if ($companyId !== null) {
                    $q->orWhere('pub_produtos.company_id', $companyId);
                }
                if ($mlbEmpresaId === null && $companyId === null) {
                    $q->whereRaw('1 = 0');
                }
            });

        if ($comJanela) {
            $query->where('pub_publicacoes.concluida_em', '>=', now()->subDays(30));
        }

        return $query;
    }

    /**
     * Decodifica `ator` (coluna de `pub_publicacoes` lida via join — o cast
     * `array` do model `PubPublicacao` não se aplica aqui porque a leitura é
     * hidratada como `PubPublicacaoItem`, que não declara esse cast).
     */
    private function atorDecodificado(mixed $bruto): array
    {
        if (is_array($bruto)) {
            return $bruto;
        }
        $decodificado = json_decode((string) $bruto, true);

        return is_array($decodificado) ? $decodificado : [];
    }

    /**
     * Classifica o ator de UMA publicação nos 3 baldes decididos pelo
     * usuário (ver `interfaces` do plano): `equipe` (nome por
     * `User::find`, fallback 'Equipe'), `cliente` (Portal — T-173-09: nunca
     * expõe nome/id, só quantidade agregada) e `origem_antiga` (migração
     * ANTIGA de `estrutura_publicacoes`, ator sem `id`).
     *
     * Método privado único — reaproveitado por `publicadosRecentes()` e,
     * na Task 2, por `ultimasPublicacoes()`, nunca duplicado.
     *
     * @return array{tipo: 'equipe'|'cliente'|'origem_antiga', id: ?int, nome: ?string}
     */
    private function resolverAtor(array $ator): array
    {
        if (! array_key_exists('id', $ator) || $ator['id'] === null) {
            return ['tipo' => 'origem_antiga', 'id' => null, 'nome' => null];
        }

        if (($ator['equipe'] ?? false) === true) {
            $nome = User::find($ator['id'])?->name ?? 'Equipe';

            return ['tipo' => 'equipe', 'id' => (int) $ator['id'], 'nome' => $nome];
        }

        return ['tipo' => 'cliente', 'id' => (int) $ator['id'], 'nome' => null];
    }

    // ═══ Publicados recentes / indicadores (Task 1) ═════════════════════════

    /**
     * Publicações dos últimos 30 dias, agregadas por quem publicou — fonte
     * única, sem dedup (não há mais duas fontes a conciliar). `total` é a
     * contagem bruta da query (cada item CREATED conta uma vez).
     *
     * @return array{
     *   equipe: list<array{nome:string, quantidade:int, responsavel:bool}>,
     *   cliente: array{quantidade:int},
     *   origem_antiga: array{quantidade:int},
     *   total:int,
     * }
     */
    public function publicadosRecentes(array $alvo): array
    {
        $linhas = $this->baseQuery($alvo, comJanela: true)
            ->select('pub_publicacao_itens.ml_item_id', 'pub_publicacoes.ator')
            ->get();

        $responsavelId = $alvo['mlb_empresa']?->responsavel_id;
        $porPessoa = [];
        $cliente = 0;
        $origemAntiga = 0;

        foreach ($linhas as $linha) {
            $resolvido = $this->resolverAtor($this->atorDecodificado($linha->ator));
            match ($resolvido['tipo']) {
                'equipe' => $porPessoa[$resolvido['id']] = [
                    'nome' => $resolvido['nome'],
                    'quantidade' => ($porPessoa[$resolvido['id']]['quantidade'] ?? 0) + 1,
                ],
                'cliente' => $cliente++,
                'origem_antiga' => $origemAntiga++,
            };
        }

        $equipe = [];
        foreach ($porPessoa as $id => $p) {
            $equipe[] = [
                'nome' => $p['nome'],
                'quantidade' => $p['quantidade'],
                // O responsável pela conta vem sempre primeiro, independente da quantidade.
                'responsavel' => $responsavelId !== null && (int) $id === (int) $responsavelId,
            ];
        }
        usort($equipe, fn (array $a, array $b) => $a['responsavel'] !== $b['responsavel']
            ? ($a['responsavel'] ? -1 : 1)
            : $b['quantidade'] <=> $a['quantidade']);

        return [
            'equipe' => $equipe,
            'cliente' => ['quantidade' => $cliente],
            'origem_antiga' => ['quantidade' => $origemAntiga],
            'total' => $linhas->count(),
        ];
    }

    /**
     * Indicadores do topo da Visão geral. `$contagemProdutos` é o retorno de
     * `ProgramasPublicadorService::contagemProdutos()` — MESMA fonte que a
     * aba Produtos usa, nunca recalculado aqui (o número tem que bater).
     *
     * @param  array{sem_oferta?: int}  $contagemProdutos
     * @return array{
     *   no_ar: ?int, com_venda: ?int, sem_oferta: int,
     *   publicados_30d: int, publicados_30d_pessoas: int,
     *   acervo_disponivel: bool, nunca_coletado: bool,
     * }
     */
    public function indicadores(array $alvo, array $contagemProdutos): array
    {
        $company = $alvo['company'];
        $publicados = $this->publicadosRecentes($alvo);
        $pessoas = count($publicados['equipe'])
            + ($publicados['cliente']['quantidade'] > 0 ? 1 : 0)
            + ($publicados['origem_antiga']['quantidade'] > 0 ? 1 : 0);

        if ($company === null) {
            // D23: sem Company não há acervo nenhum pra consultar — "—" na tela, nunca zero.
            return [
                'no_ar' => null,
                'com_venda' => null,
                'sem_oferta' => (int) ($contagemProdutos['sem_oferta'] ?? 0),
                'publicados_30d' => $publicados['total'],
                'publicados_30d_pessoas' => $pessoas,
                'acervo_disponivel' => false,
                'nunca_coletado' => false,
            ];
        }

        $defasagem = $this->acervoTriagem->defasagem($company);
        if ($defasagem['nunca_coletado']) {
            // "Nunca medimos" != "medimos e é zero" — critério de aceite da Visão geral.
            $noAr = null;
            $comVenda = null;
        } else {
            $noAr = MlAcervoItem::where('company_id', $company->id)->whereIn('status', ['active', 'paused'])->count();
            $comVenda = MlAcervoItem::where('company_id', $company->id)
                ->whereIn('status', ['active', 'paused'])
                ->where('sold_quantity', '>', 0)
                ->count();
        }

        return [
            'no_ar' => $noAr,
            'com_venda' => $comVenda,
            'sem_oferta' => (int) ($contagemProdutos['sem_oferta'] ?? 0),
            'publicados_30d' => $publicados['total'],
            'publicados_30d_pessoas' => $pessoas,
            'acervo_disponivel' => true,
            'nunca_coletado' => $defasagem['nunca_coletado'],
        ];
    }

    // ═══ O que fazer agora / situação dos produtos / integrações (Task 2) ══

    /**
     * Ordem fixa da seção 3 do handoff. Token fora de 'ativo' encerra a
     * lista com UMA linha só — sem conta ativa, nada mais funciona.
     *
     * ⚠️ Lacuna documentada (ver 173-04-SUMMARY.md): a linha 6 do handoff
     * ("Rascunhos com pendências") pede para citar o rascunho de MENOR
     * `faltam` — `EditorRascunhoService::prontidao()` devolve esse campo,
     * mas a assinatura deste método (definida pelo plano) só recebe
     * agregados, nunca a lista de produtos/rascunhos. Sem a lista não há
     * como apontar QUAL rascunho tem a menor pendência — a linha fica de
     * fora até um plano futuro passar `$produtos` aqui.
     */
    public function oQueFazerAgora(
        array $alvo,
        array $empresaParaTela,
        array $contagemProdutos,
        array $triagemAcionaveis,
        array $defasagem,
        array $situacaoPortal,
    ): array {
        if (($empresaParaTela['token'] ?? null) !== 'ativo') {
            return [[
                'texto' => 'Conta precisa de reconexão',
                'numero' => null,
                'destino' => ['acao' => 'reconectar', 'url' => $empresaParaTela['link_reconexao'] ?? null],
            ]];
        }

        $company = $alvo['company'];
        $companyId = $empresaParaTela['company_id'] ?? null;
        $chips = collect($triagemAcionaveis['chips'] ?? [])->keyBy('chave');
        $linhas = [];

        $nPausados = (int) ($chips[MlAcervoItem::MOTIVO_PAUSADO]['count'] ?? 0)
            + (int) ($chips[MlAcervoItem::MOTIVO_SEM_ESTOQUE]['count'] ?? 0);
        if ($nPausados > 0) {
            $linhas[] = [
                'texto' => 'Anúncios pausados ou sem estoque',
                'numero' => $nPausados,
                'legado' => $company !== null
                    ? $this->acervoTriagem->legadoEntre($company, [MlAcervoItem::MOTIVO_PAUSADO, MlAcervoItem::MOTIVO_SEM_ESTOQUE])
                    : 0,
                'destino' => ['rota' => 'mlb.anuncios.meus', 'params' => ['company' => $companyId]],
            ];
        }

        if ((int) ($contagemProdutos['com_problema'] ?? 0) > 0) {
            $linhas[] = $this->linhaDeProdutos('Produtos com problema na publicação', (int) $contagemProdutos['com_problema'], $alvo['chave'], 'com_problema');
        }

        if ((int) ($contagemProdutos['publicados'] ?? 0) > 0) {
            $linhas[] = $this->linhaDeProdutos('Prontos para a Fase 2', (int) $contagemProdutos['publicados'], $alvo['chave'], 'publicados');
        }

        if ((int) ($contagemProdutos['conferidos'] ?? 0) > 0) {
            $linhas[] = $this->linhaDeProdutos('Conferidos, prontos para publicar', (int) $contagemProdutos['conferidos'], $alvo['chave'], 'conferidos');
        }

        // Linha 6 do handoff ("Rascunhos com pendências") — ver lacuna no docblock acima.

        if (($situacaoPortal['situacao'] ?? null) === 'novas') {
            $linhas[] = [
                'texto' => 'Ofertas novas no Portal',
                'numero' => $situacaoPortal['novas'],
                'destino' => ['acao' => 'sincronizar'],
            ];
        }

        foreach (['ficha_incompleta', 'perdendo_catalogo', 'foto_insuficiente'] as $motivo) {
            $count = (int) ($chips[$motivo]['count'] ?? 0);
            if ($count > 0) {
                $linhas[] = [
                    'texto' => $chips[$motivo]['label'] ?? $motivo,
                    'numero' => $count,
                    'destino' => ['rota' => 'mlb.anuncios.publicador.produtos', 'params' => ['conta' => $alvo['chave'], 'motivo' => $motivo]],
                ];
            }
        }

        return $linhas;
    }

    private function linhaDeProdutos(string $texto, int $numero, string $chaveConta, string $filtro): array
    {
        return [
            'texto' => $texto,
            'numero' => $numero,
            'destino' => ['rota' => 'mlb.anuncios.publicador.produtos', 'params' => ['conta' => $chaveConta, 'filtro' => $filtro]],
        ];
    }

    /** Passthrough com rótulos — MESMA fonte de `contagemProdutos()` usada por Produtos.jsx. */
    public function situacaoProdutos(array $contagemProdutos): array
    {
        return [
            'rascunho' => ['numero' => (int) ($contagemProdutos['rascunho'] ?? 0), 'rotulo' => 'Rascunho'],
            'conferidos' => ['numero' => (int) ($contagemProdutos['conferidos'] ?? 0), 'rotulo' => 'Conferidos'],
            'publicados' => ['numero' => (int) ($contagemProdutos['publicados'] ?? 0), 'rotulo' => 'Publicados'],
            'com_problema' => ['numero' => (int) ($contagemProdutos['com_problema'] ?? 0), 'rotulo' => 'Com problema'],
        ];
    }

    /**
     * Mercado Livre, publicação/Alavancas liberadas, Portal e ERP declarado
     * — bloco "Integrações" da coluna lateral e de Configurações da conta.
     */
    public function integracoes(array $alvo, array $empresaParaTela): array
    {
        $ancora = PubProduto::ancoraComToken($alvo['mlb_empresa'], $alvo['company']);
        $itemErp = $alvo['mlb_empresa']?->implementacao?->dados['itens']['erp'] ?? null;
        $valorErp = is_array($itemErp) ? trim((string) ($itemErp['valor'] ?? '')) : '';

        $rotuloErp = match (true) {
            $valorErp === '' || $valorErp === '---' => 'Não informado',
            $valorErp === 'Outro' => (trim((string) ($itemErp['outro'] ?? '')) !== '' ? trim((string) $itemErp['outro']) : 'Outro'),
            default => $valorErp,
        };

        return [
            'mercado_livre' => ['token' => $empresaParaTela['token']],
            'publicacao_liberada' => ContasLiberadas::libera($ancora),
            'alavancas_liberada' => AlavancasLiberadas::libera($ancora),
            'portal' => $empresaParaTela['portal'],
            'erp' => ['valor' => $valorErp !== '' ? $valorErp : null, 'rotulo' => $rotuloErp],
        ];
    }

    /** 3 primeiras linhas não vazias do texto livre — sem registro/texto vazio vira `tem_identidade=false`. */
    public function identidadeResumo(array $alvo): array
    {
        $identidade = CreativeIdentidade::paraAncora($alvo['company']?->id, $alvo['mlb_empresa']?->id);
        $texto = trim((string) ($identidade?->texto ?? ''));
        if ($texto === '') {
            return ['tem_identidade' => false, 'texto_resumo' => null];
        }

        $linhas = array_values(array_filter(
            array_map('trim', preg_split('/\r\n|\r|\n/', $texto)),
            fn (string $l) => $l !== ''
        ));

        return ['tem_identidade' => true, 'texto_resumo' => array_slice($linhas, 0, 3)];
    }

    /**
     * 5 publicações mais recentes da conta, qualquer data — fonte única, sem
     * nenhuma referência a `MlAnuncioRascunho`. D23: sem Company não há o
     * que mostrar (nunca lança, nunca inventa dado).
     *
     * @return array{disponivel:bool, itens:list<array>}
     */
    public function ultimasPublicacoes(array $alvo): array
    {
        if ($alvo['company'] === null) {
            return ['disponivel' => false, 'itens' => []];
        }

        $tipoPorListing = array_flip(EstruturaPublicacao::LISTING_TYPES);

        $linhas = $this->baseQuery($alvo, comJanela: false)
            ->select([
                'pub_publicacao_itens.ml_item_id',
                'pub_publicacao_itens.listing_type_id',
                'pub_publicacao_itens.payload',
                'pub_publicacoes.ator',
                'pub_publicacoes.concluida_em',
                'pub_publicacoes.status as publicacao_status',
            ])
            ->orderByDesc('pub_publicacoes.concluida_em')
            ->limit(5)
            ->get();

        $companyId = $alvo['company']->id;
        $itens = $linhas->map(function ($linha) use ($tipoPorListing, $companyId) {
            $payload = is_array($linha->payload) ? $linha->payload : [];
            $resolvido = $this->resolverAtor($this->atorDecodificado($linha->ator));

            return [
                'titulo' => $payload['family_name'] ?? null,
                'ml_item_id' => $linha->ml_item_id,
                'tipo' => $tipoPorListing[$linha->listing_type_id] ?? null,
                'quem' => ['tipo' => $resolvido['tipo'], 'nome' => $resolvido['nome']],
                'quando' => $linha->concluida_em ? Carbon::parse($linha->concluida_em)->toIso8601String() : null,
                // Escopado por company_id (Rule 2): ml_item_id sozinho NÃO é único globalmente
                // (docblock de MlAcervoItem) — sem o escopo, duas empresas com o mesmo MLB
                // (corrida de dados legados) vazariam venda uma da outra.
                'vendas' => MlAcervoItem::where('company_id', $companyId)->where('ml_item_id', $linha->ml_item_id)->value('sold_quantity'),
                'situacao' => $linha->publicacao_status,
            ];
        })->values()->all();

        return ['disponivel' => true, 'itens' => $itens];
    }
}
