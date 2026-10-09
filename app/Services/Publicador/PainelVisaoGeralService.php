<?php

namespace App\Services\Publicador;

use App\Models\CreativeIdentidade;
use App\Models\EstruturaPublicacao;
use App\Models\MlAcervoItem;
use App\Models\MlAnuncioCriativoKit;
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
     * ═══ Chaves NOVAS (quick 261009-t02) ═══════════════════════════════════
     *
     * Tudo ADITIVO: nenhuma chave acima mudou de nome, de ordem ou de valor —
     * a Visão geral está em produção desde 08/10 e a tela 02 só reorganiza.
     *
     * - `no_ar_por_fase` — `{fase1, kits}` dos produtos da conta que estão no
     *   ar. ⚠️ Rotulado **"kits"**, nunca "Fase 2": `quantidade_kit >= 2`
     *   inclui o kit de 3, que é Fase 3 (mesma correção da tela 01).
     * - `criativos_packs` — quantos kits de criativo (`ml_anuncio_criativo_kits`)
     *   a conta tem. NÃO existe "reaproveitados": o acervo não conta reuso.
     * - `tracao_pct` — `com_venda ÷ no_ar`, arredondado. **Nulo** quando não há
     *   o que dividir (`no_ar` 0, acervo nunca coletado ou conta sem Company).
     *   ⚠️ Nunca 0%: "não sabemos" não é "é zero" (mesma armadilha do "dia sem
     *   linha ≠ venda zero" dos learnings deste projeto).
     *
     * `$produtos` (shape de `produtosParaTela()`) é opcional pelo mesmo motivo
     * do `oQueFazerAgora()`: chamador antigo que não passa a lista continua
     * funcionando, e aí `no_ar_por_fase` diz "não sei" (null) em vez de mentir
     * um zero. Nenhuma consulta nova sai daqui — a lista já está carregada.
     *
     * @param  array{sem_oferta?: int}  $contagemProdutos
     * @param  list<array>  $produtos
     * @return array{
     *   no_ar: ?int, com_venda: ?int, sem_oferta: int,
     *   publicados_30d: int, publicados_30d_pessoas: int,
     *   acervo_disponivel: bool, nunca_coletado: bool,
     *   no_ar_por_fase: array{fase1: ?int, kits: ?int}, criativos_packs: int, tracao_pct: ?int,
     * }
     */
    public function indicadores(array $alvo, array $contagemProdutos, array $produtos = []): array
    {
        $company = $alvo['company'];
        $publicados = $this->publicadosRecentes($alvo);
        $pessoas = count($publicados['equipe'])
            + ($publicados['cliente']['quantidade'] > 0 ? 1 : 0)
            + ($publicados['origem_antiga']['quantidade'] > 0 ? 1 : 0);

        $novas = [
            'no_ar_por_fase' => $this->noArPorFase($produtos),
            'criativos_packs' => $this->criativosPacks($alvo),
        ];

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
            ] + $novas + ['tracao_pct' => null];
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
        ] + $novas + [
            // Divisão só quando HÁ o que dividir. `no_ar === 0` e `no_ar === null`
            // caem os dois em null — e são coisas diferentes na tela: o primeiro é
            // "medimos e não há anúncio", o segundo é "não medimos".
            'tracao_pct' => ($noAr !== null && $noAr > 0 && $comVenda !== null)
                ? (int) round($comVenda / $noAr * 100)
                : null,
        ];
    }

    /**
     * Os produtos NO AR da conta, separados em base e kit — o "218 Fase 1 ·
     * 124 kits" do topo da tela 02.
     *
     * Lê a lista que `produtosParaTela()` já devolveu (zero consulta nova) e
     * aplica a MESMA regra de "no ar" de `ProgramasPublicadorService::bucketDaFase()`:
     * status derivado publicado/parcial OU anúncio CREATED na lista.
     *
     * ⚠️ Guarda de honestidade (mesmo desenho do `prontosParaFase2()`): lista
     * NÃO ciente de fase (shape antigo, sem `eh_kit`/`quantidade_kit`) devolve
     * `null` nos dois, porque aí não dá para separar nada e zero seria mentira.
     * Lista VAZIA é outra coisa: a conta não tem produto, e aí zero é medido.
     *
     * @param  list<array>  $produtos
     * @return array{fase1: ?int, kits: ?int}
     */
    private function noArPorFase(array $produtos): array
    {
        if ($produtos === []) {
            return ['fase1' => 0, 'kits' => 0];
        }

        $cienteDeFase = false;
        $fase1 = 0;
        $kits = 0;

        foreach ($produtos as $p) {
            if (! array_key_exists('eh_kit', $p) && ! array_key_exists('quantidade_kit', $p)) {
                continue;
            }
            $cienteDeFase = true;

            $noAr = in_array($p['status']['chave'] ?? null, ProgramasPublicadorService::STATUS_NO_AR, true)
                || ($p['anuncios'] ?? []) !== [];
            if (! $noAr) {
                continue;
            }

            // "kit" é quantidade_kit >= 2 — o que inclui o kit de 3 (Fase 3).
            if (($p['eh_kit'] ?? false) === true || (int) ($p['quantidade_kit'] ?? 1) >= 2) {
                $kits++;
            } else {
                $fase1++;
            }
        }

        return $cienteDeFase ? ['fase1' => $fase1, 'kits' => $kits] : ['fase1' => null, 'kits' => null];
    }

    /**
     * Quantos kits de criativo por IA a conta já tem (`ml_anuncio_criativo_kits`).
     *
     * UMA consulta, escopada pela dupla-âncora da conta, com os `orWhere`
     * SEMPRE agrupados dentro de `where(function...)` — orWhere solto sobe ao
     * topo do WHERE e anula o escopo por empresa (armadilha documentada em
     * `AcervoTriagemService::escopo()`). Sem nenhuma das duas âncoras a
     * consulta é fechada com `1 = 0`, nunca aberta à base inteira.
     *
     * ⚠️ É a CONTAGEM de packs, e só. "Reaproveitados" não existe na tabela e
     * não pode ser inventado na tela.
     */
    public function criativosPacks(array $alvo): int
    {
        $mlbEmpresaId = $alvo['mlb_empresa']?->id;
        $companyId = $alvo['company']?->id;

        if ($mlbEmpresaId === null && $companyId === null) {
            return 0;
        }

        return MlAnuncioCriativoKit::query()
            ->where(function ($q) use ($mlbEmpresaId, $companyId) {
                if ($mlbEmpresaId !== null) {
                    $q->orWhere('mlb_empresa_id', $mlbEmpresaId);
                }
                if ($companyId !== null) {
                    $q->orWhere('company_id', $companyId);
                }
            })
            ->count();
    }

    /**
     * Os "Alertas Meli & ERP" do mockup da tela 02 — que são, na prática, a
     * TRIAGEM do acervo que esta tela já carregava.
     *
     * ⚠️ Nenhum motivo é reimplementado aqui: a ordem, os rótulos e as cores
     * saem de `AcervoTriagemService::motivosDef()` (fonte única, decisão da
     * Etapa 2) e os números saem dos chips que o controller já calculou. ZERO
     * consulta nova — só reformata o que chegou.
     *
     * `disponivel` distingue "sem Company, não há acervo para triar" de
     * "triamos e não há alerta": a tela não pode afirmar zero no primeiro caso.
     *
     * `total` é o de anúncios DISTINTOS com pelo menos um motivo (o
     * `triagem['total']`), nunca a soma dos chips — um anúncio pode ter dois
     * motivos e seria contado duas vezes.
     *
     * @param  array{total?: int, chips?: array}  $triagem  retorno de `AcervoTriagemService::triagem()`
     * @return array{disponivel: bool, total: int, itens: list<array{chave: string, label: string, cor: string, total: int}>}
     */
    public function alertas(array $alvo, array $triagem): array
    {
        if ($alvo['company'] === null) {
            return ['disponivel' => false, 'total' => 0, 'itens' => []];
        }

        $porChave = collect($triagem['chips'] ?? [])->keyBy('chave');

        $itens = array_map(fn (array $m) => [
            'chave' => $m['chave'],
            'label' => $m['label'],
            'cor' => $m['cor'],
            'total' => (int) ($porChave[$m['chave']]['count'] ?? 0),
        ], $this->acervoTriagem->motivosDef());

        return [
            'disponivel' => true,
            'total' => (int) ($triagem['total'] ?? 0),
            'itens' => $itens,
        ];
    }

    // ═══ O que fazer agora / situação dos produtos / integrações (Task 2) ══

    /**
     * Ordem fixa da seção 3 do handoff. Token fora de 'ativo' encerra a
     * lista com UMA linha só — sem conta ativa, nada mais funciona.
     *
     * `$produtos` (shape de `ProgramasPublicadorService::produtosParaTela()`)
     * fecha a linha 6 ("Rascunhos com pendências") — Fase 173, Plano 04b.
     * Parâmetro opcional (default `[]`) para não quebrar chamadas antigas
     * que só tinham os agregados; sem produtos, a linha simplesmente não
     * aparece (mesma regra de "número > 0" das demais).
     */
    public function oQueFazerAgora(
        array $alvo,
        array $empresaParaTela,
        array $contagemProdutos,
        array $triagemAcionaveis,
        array $defasagem,
        array $situacaoPortal,
        array $produtos = [],
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

        // §7 (plano 175-08): "Prontos para a Fase 2" = base publicado SEM NENHUM
        // kit. Base que já tem Kit 2 não está mais esperando nada e saiu da conta.
        $prontosParaFase2 = $this->prontosParaFase2($contagemProdutos, $produtos);
        if ($prontosParaFase2 > 0) {
            // `fase=so_base` casa com o filtro "Fase: todas / só base / só kits" da
            // lista (§7): a linha leva a quem ainda pode ganhar uma fase nova.
            $linhas[] = $this->linhaDeProdutos('Prontos para a Fase 2', $prontosParaFase2, $alvo['chave'], 'publicados', 'so_base');
        }

        if ((int) ($contagemProdutos['conferidos'] ?? 0) > 0) {
            $linhas[] = $this->linhaDeProdutos('Conferidos, prontos para publicar', (int) $contagemProdutos['conferidos'], $alvo['chave'], 'conferidos');
        }

        // Linha 6 — "Rascunhos com pendências" (handoff seção 3): status `conferir`
        // com `faltam > 0`; cita o de menor `faltam` (o mais perto de pronto). Não dá
        // pra sair de `contagemProdutos['rascunho']` — esse bucket mistura chave
        // 'rascunho' (a preencher, faltam sempre 0) com 'conferir' (em preenchimento);
        // aqui filtramos $produtos direto pra pegar só quem tem pendência de verdade.
        $comPendencia = array_values(array_filter(
            $produtos,
            fn (array $p) => ($p['status']['chave'] ?? null) === 'conferir' && (int) ($p['status']['faltam'] ?? 0) > 0
        ));
        if (count($comPendencia) > 0) {
            $maisProximo = collect($comPendencia)->sortBy(fn (array $p) => (int) $p['status']['faltam'])->first();
            $linhas[] = [
                'texto' => 'Rascunhos com pendências',
                'numero' => count($comPendencia),
                'exemplo' => ['nome' => $maisProximo['nome'], 'faltam' => (int) $maisProximo['status']['faltam']],
                'destino' => ['rota' => 'mlb.anuncios.publicador.produtos', 'params' => ['conta' => $alvo['chave'], 'filtro' => 'rascunho']],
            ];
        }

        if (($situacaoPortal['situacao'] ?? null) === 'novas') {
            $linhas[] = [
                'texto' => 'Ofertas novas no Portal',
                'numero' => $situacaoPortal['novas'],
                'destino' => ['acao' => 'sincronizar'],
            ];
        }

        // Linha 8 — "Ficha, catálogo ou foto": anúncio JÁ PUBLICADO com problema de
        // ficha/catálogo/foto, não produto em rascunho. Destino é Publicações › No ar
        // filtrado (mesma tela e mesmo formato da linha 2), nunca Produtos — o
        // handoff (ETAPA-2-visao-geral.md §3) é a versão correta; o texto do
        // PLAN.md 173-04 que mandava para Produtos `?motivo=` estava errado
        // (Fase 173, Plano 04b).
        foreach (['ficha_incompleta', 'perdendo_catalogo', 'foto_insuficiente'] as $motivo) {
            $count = (int) ($chips[$motivo]['count'] ?? 0);
            if ($count > 0) {
                $linhas[] = [
                    'texto' => $chips[$motivo]['label'] ?? $motivo,
                    'numero' => $count,
                    'destino' => ['rota' => 'mlb.anuncios.meus', 'params' => ['company' => $companyId, 'motivo' => $motivo]],
                ];
            }
        }

        return $linhas;
    }

    /** `$fase` entra no destino só quando a linha filtra por fase (§7) — nunca nas antigas. */
    private function linhaDeProdutos(string $texto, int $numero, string $chaveConta, string $filtro, ?string $fase = null): array
    {
        $params = ['conta' => $chaveConta, 'filtro' => $filtro];
        if ($fase !== null) {
            $params['fase'] = $fase;
        }

        return [
            'texto' => $texto,
            'numero' => $numero,
            'destino' => ['rota' => 'mlb.anuncios.publicador.produtos', 'params' => $params],
        ];
    }

    /**
     * Quantos produtos estão de fato esperando uma Fase 2: base (não kit)
     * publicado ou parcial e SEM NENHUM kit — a regra nova da §7.
     *
     * ⚠️ Compatibilidade com chamador antigo, de propósito: a regra só pode ser
     * aplicada quando `$produtos` é a lista CIENTE DE FASE (a que
     * `produtosParaTela()` devolve desde o plano 175-08, com `eh_kit` e `kits`).
     * Sem essas chaves — lista omitida ou shape antigo — o número volta a ser
     * `contagemProdutos['publicados']`, que é exatamente o de hoje. Assim a linha
     * NUNCA desaparece por falta de dado: ou ela conta certo, ou conta como antes.
     */
    private function prontosParaFase2(array $contagemProdutos, array $produtos): int
    {
        $cienteDeFase = false;
        $total = 0;

        foreach ($produtos as $p) {
            if (! array_key_exists('eh_kit', $p)) {
                continue;
            }
            $cienteDeFase = true;

            if ($p['eh_kit'] === false
                && in_array($p['status']['chave'] ?? null, ProgramasPublicadorService::STATUS_NO_AR, true)
                && ($p['kits'] ?? []) === []) {
                $total++;
            }
        }

        return $cienteDeFase ? $total : (int) ($contagemProdutos['publicados'] ?? 0);
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
     * Bloco "Produtos por fase" da §7 — passthrough com rótulos sobre
     * `contagemProdutos['por_fase']`, no MESMO padrão de `situacaoProdutos()`.
     *
     * ⚠️ Divergência deliberada da spec, registrada: a §7 diz
     * `"Situação dos produtos" → "Produtos por fase"` (substituir). A regra
     * inviolável do projeto é que nenhuma informação existente desaparece, então
     * os DOIS blocos convivem — `situacaoProdutos()` continua intacto e este
     * nasce ao lado. Se o usuário preferir substituir de fato, é um ajuste de uma
     * linha no JSX, não aqui.
     *
     * **Fonte única:** nunca reconta nada. A contagem é a do
     * `ProgramasPublicadorService`, a MESMA que a lista de Produtos usa — é isso
     * que faz "a Visão geral por fase bate com a lista" ser verdade por
     * construção, e não por coincidência.
     *
     * @return array<string, array{numero: int, rotulo: string}>
     */
    public function produtosPorFase(array $contagemProdutos): array
    {
        $porFase = $contagemProdutos['por_fase'] ?? [];

        return [
            'sem_oferta' => ['numero' => (int) ($porFase['sem_oferta'] ?? 0), 'rotulo' => 'Sem oferta'],
            'fase1_publicada' => ['numero' => (int) ($porFase['fase1_publicada'] ?? 0), 'rotulo' => 'Fase 1 publicada'],
            'fase2_preparacao' => ['numero' => (int) ($porFase['fase2_preparacao'] ?? 0), 'rotulo' => 'Fase 2 em preparação'],
            'fase2_publicada' => ['numero' => (int) ($porFase['fase2_publicada'] ?? 0), 'rotulo' => 'Fase 2 publicada'],
            'fase3_mais' => ['numero' => (int) ($porFase['fase3_mais'] ?? 0), 'rotulo' => 'Fase 3+'],
        ];
    }

    /**
     * Mercado Livre, publicação/Alavancas liberadas, Portal e ERP declarado
     * — bloco "Integrações" da coluna lateral e de Configurações da conta.
     */
    public function integracoes(array $alvo, array $empresaParaTela): array
    {
        $ancora = PubProduto::ancoraComToken($alvo['mlb_empresa'], $alvo['company']);

        // ⚠️ FONTE ÚNICA do ERP declarado (quick 261009-t01): a mesma estática que a
        // lista de empresas (tela A) usa. Enquanto eram duas implementações, a tela A
        // e a Visão geral podiam mostrar ERPs diferentes para a MESMA conta — a lista
        // lê também a coluna `erp` que o sync da planilha de Polos preenche, e aqui só
        // o JSON da ficha era lido. Não duplicar de novo.
        $nomeErp = ProgramasPublicadorService::erpDeclarado($alvo['mlb_empresa']?->implementacao);

        return [
            'mercado_livre' => ['token' => $empresaParaTela['token']],
            'publicacao_liberada' => ContasLiberadas::libera($ancora),
            'alavancas_liberada' => AlavancasLiberadas::libera($ancora),
            'portal' => $empresaParaTela['portal'],
            'erp' => ['valor' => $nomeErp, 'rotulo' => $nomeErp ?? 'Não informado'],
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
                // §7 (plano 175-08): a coluna Fase das últimas publicações.
                // ⚠️ `pub_produtos.fase` QUALIFICADO: `estrutura_ofertas.fase` é
                // outra coisa (o TIPO da oferta no Portal) e, sem qualificar, o
                // MariaDB resolveria para a primeira tabela do FROM — valor calado
                // e errado. Apelidado para não colidir com nada do payload.
                'pub_produtos.fase as produto_fase',
                'pub_produtos.quantidade_kit as produto_quantidade_kit',
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
                // §7: a fase do produto que gerou a publicação — escalares, nunca
                // objeto (a "tela preta" de 07/10). O rótulo sai da fonte única
                // `ProgramasPublicadorService::rotuloFase()`.
                'fase' => (int) $linha->produto_fase,
                'rotulo_fase' => ProgramasPublicadorService::rotuloFase((int) $linha->produto_quantidade_kit),
            ];
        })->values()->all();

        return ['disponivel' => true, 'itens' => $itens];
    }
}
