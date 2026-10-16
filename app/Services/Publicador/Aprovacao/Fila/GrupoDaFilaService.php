<?php

namespace App\Services\Publicador\Aprovacao\Fila;

use App\Models\PubAprovacaoOferta;
use App\Models\PubProduto;
use App\Models\PubRascunho;
use App\Models\PubRascunhoAtributo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 * OS TRÊS NÍVEIS DA TABELA DA FILA — família → produto (conjunto de fotos) → oferta.
 *
 * Fase 187, plano 07. É aqui que a granularidade do DESENHO encontra a deste sistema, e a tradução
 * está **MEDIDA no repositório**, não inventada:
 *
 * | nível do protótipo | o que é aqui | medido em |
 * |---|---|---|
 * | **produto** (tag PRODUTO, "N ofertas") | a **FAMÍLIA**: o produto base + os kits dele | `PubProduto::familia()` (`app/Models/PubProduto.php:121`) — `produto_base_id ?? id` |
 * | **conjunto de fotos** ("Unidade" / "Combo ×N") | o **PRODUTO** (base ou kit) e o rascunho dele, que é **1:1** | `pub_rascunhos.produto_id` é **UNIQUE** (`pubr_produto_uq`) |
 * | **oferta** (Clássico / Premium) | o **ALVO** do rascunho; a oferta do portão é extensão **1:1** dele | `pub_aprovacao_ofertas.alvo_id` **UNIQUE** (`pubapof_alvo_uq`) |
 *
 * ⚠️ **O `grupo_chave` de `pub_aprovacao_conjuntos` é OUTRO EIXO** — é o grupo de **VARIAÇÃO**
 * (`GENERAL` ou a chave da variante), **não** Unidade/Combo. Um rascunho tem **N** conjuntos de
 * variação, e o bloqueio de fotos cobre **TODOS** eles (decisão do usuário de 14/10/2026:
 * aprovação cor por cor foi oferecida e RECUSADA). ➡️ Os mini-quadrados da sub-linha são a galeria
 * final **de todos os grupos**, agregada por {@see GaleriaDaOfertaService}.
 *
 * ═══ ⚠️⚠️ OS PONTEIROS DO PROTÓTIPO, REMEDIDOS — O PLANO ERRAVA EM 11 DE 17 ════════════════════
 *
 * Medidos com `sed`/`awk` em `design_handoff_publicador/Aprovacao.dc.html` (1432 linhas, sha256
 * `68624883ce35547e`) em 16/10/2026. **O agrupamento é `:1256`-`:1262`** — o plano escreveu
 * `:1266`-`:1273`, que é a região dos *filtros*:
 *
 *   · `order` / `byP` (primeira aparição)        → **`:1256`-`:1257`**  (plano: —)
 *   · `setKeys.sort(units)`                      → **`:1258`**          (plano: `:1269`)
 *   · `n = setKeys.reduce(...)` do "N ofertas"    → **`:1259`**         (plano: `:1270`)
 *   · `border: gi ? '1px solid #262626' : 'none'` → **`:1260`**         (plano: `:1270`)
 *   · Clássico antes de Premium                   → **`:1261`**         (plano: `:1272`)
 *   · `shared: setOffers(sk).length > 1`          → **`:1262`**         (plano: `:1272`)
 *   · `label` Unidade/Combo, `photos`, `progress` → **`:1262`**
 *
 * E na TELA: `<section>` **`:108`** (plano `:104`), cabeçalho **`:109`** (`:105`), checkbox do
 * cabeçalho **`:110`** (`:106`), `text-align:right` **`:111`** (`:107`), `g.border` **`:114`**
 * (`:109`), linha de produto **`:115`** (`:110`), tile **`:116`** (`:111`), nome + tag PRODUTO
 * **`:117`** (`:112`), SKU **`:118`** (`:113`), "N ofertas" **`:119`** (`:114`). Do `:120` em
 * diante o plano acerta.
 *
 * ═══ ⚠️ `compartilhado` CONTA AS OFERTAS DO RASCUNHO, NÃO AS FILTRADAS ═════════════════════════
 *
 * `setOffers(sk)` do protótipo (`:1054`) varre **todas** as ofertas do conjunto, sem filtro. Aqui é
 * o mesmo, e **isso é a parte que importa**: o pill *"Mesmas fotos em Clássico e Premium ·
 * aprovação compartilhada"* é um fato sobre o RASCUNHO (as duas ofertas leem o MESMO conjunto —
 * não existe coluna onde pudessem divergir). Contá-lo sobre a lista já filtrada faria o pill
 * **desaparecer** quando alguém filtrasse por "Premium", como se a aprovação deixasse de ser
 * compartilhada por causa de um filtro. ➡️ UMA consulta agregada por `rascunho_id`, nunca uma por
 * conjunto.
 *
 * ═══ ⚠️⚠️ A FRASE DO CADASTRO SÓ APARECE QUANDO É VERDADE ═════════════════════════════════════
 *
 * *"Cadastro completo · categoria e ficha técnica organizadas"* (`:120`) é **afirmação sobre o
 * cadastro**, e D4 / EXMP-01 proíbem imprimi-la como decoração (confirmado pelo usuário em
 * 15/10/2026: *medir no rascunho; não sendo verdade, a frase NÃO aparece*).
 *
 * A fonte, medida no schema: **`pub_rascunhos.categoria_id` preenchido** E **pelo menos um
 * `pub_rascunho_atributos`** E **nenhum deles com `revisar = true`**. Faltando qualquer uma das
 * três, `cadastro` é `null` e o componente **não desenha a frase** — nunca um texto fixo, nunca
 * "pendente" ocupando o lugar dela. ⚠️ *"Sem atributo nenhum"* conta como FALSO de propósito: não
 * há de onde afirmar que a ficha está organizada.
 *
 * ═══ ⚠️ IDENT-08 — AS INICIAIS E O TOM NÃO SAEM DAQUI ═════════════════════════════════════════
 *
 * Este serviço manda **`produto_id`** (o hue estável) e **`nome`**; quem desenha o tile é
 * `identidade/TileDoProduto.jsx`, que já tem `iniciais()` e `tomDoProduto()`. ⚠️ Cunhar as
 * iniciais no PHP foi **medido como segunda implementação divergente** na wave 6 (*"Ana de Souza
 * Lima"* dava **AL** no PHP e **AS** no módulo) e a chave `iniciais` **saiu** do payload. Não
 * recriar aqui.
 *
 * ═══ ⚠️ CUSTO ════════════════════════════════════════════════════════════════════════════════
 *
 * Três consultas FIXAS para a árvore inteira (os rascunhos · a contagem de ofertas por rascunho ·
 * os atributos agregados) **mais** as três da galeria por rascunho. ⚠️ **Zero chamada a
 * `BloqueiosDaOfertaService::para()`**: ele lê a composição, que consulta
 * `/sites/MLB/listing_prices` — uma chamada por grupo seria uma requisição ao Mercado Livre por
 * grupo, a classe do incidente de 30/07/2026. Há gate com espião contando ZERO, com isca provando
 * que o espião conta.
 *
 * ⚠️ **Nada aqui é escrito**, e esta tela **APROVA (e aprovar ENFILEIRA); ela NÃO publica** — a
 * escrita no Mercado Livre é da **Fase 193**.
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 */
class GrupoDaFilaService
{
    /** `:1262` — o conjunto de uma unidade. */
    public const UNIDADE = 'Unidade';

    /** `:1262` — o prefixo do combo. O `×` é U+00D7, **nunca** a letra `x`. */
    public const PREFIXO_COMBO = 'Combo ×';

    /** `:1259` — o SINGULAR, que é fácil de esquecer. */
    public const SUFIXO_OFERTA = ' oferta';

    /** `:1259` — o plural. */
    public const SUFIXO_OFERTAS = ' ofertas';

    /**
     * `:120` — a frase do cadastro, byte a byte, com o separador U+00B7.
     *
     * ⚠️ Ela **só** sai quando é verdade. Ver o docblock da classe.
     */
    public const CADASTRO_COMPLETO = 'Cadastro completo · categoria e ficha técnica organizadas';

    /** `:1262` — o literal das fotos da sub-linha: `N fotos (M geradas por IA)`. */
    public const FOTOS_SUFIXO_IA = ' geradas por IA)';

    /** `:1262` — o sufixo do progresso da sub-linha. */
    public const PROGRESSO_SUFIXO = ' aprovadas';

    public function __construct(
        private GaleriaDaOfertaService $galeria,
    ) {}

    /**
     * A árvore de 3 níveis, a partir da coleção **já ordenada** por
     * {@see FilaDeAprovacaoService::ordenar()}.
     *
     * ⚠️ **Recebe a coleção ordenada e NÃO reordena nada**: a ordem dos grupos é a de **primeira
     * aparição** (`:1257`, `order.push(p)`), que é o que faz o grupo da oferta mais urgente vir
     * primeiro. Reordenar aqui criaria **duas ordens da mesma fila**, livres para divergir na
     * primeira correção feita de um lado só — é a MESMA decisão que `ProximoARevisarService`
     * declarou e pelo mesmo motivo.
     *
     * @param  Collection<int, PubAprovacaoOferta>  $ofertas  já ordenada pela fila
     * @param  ?callable(PubAprovacaoOferta): array  $linhaDaOferta  o payload de UMA oferta.
     *   ⚠️ **O SLOT EXISTE DESDE AGORA de propósito:** as 11 células da linha são o plano
     *   **187-08**, e `GrupoDaFilaService.php` **não está** nos `files_modified` dele — sem o slot,
     *   aquela wave teria de abrir uma SEGUNDA varredura da mesma árvore. É a forma literal do
     *   protótipo, que também injeta a linha (`rows: rows.map(rowVm)`, `:1262`). O default já tem
     *   consumidor (o nível 2 sozinho), então isto não é código sem uso.
     * @return list<array{
     *     produto_id: int|null, nome: string, sku: string, contagem: string,
     *     borda_superior: bool, cadastro: string|null,
     *     conjuntos: list<array{
     *         rascunho_id: int, produto_id: int|null, rotulo: string, unidades: int,
     *         fotos: string, quadrados: list<array<string, mixed>>, progresso: string,
     *         completo: bool, compartilhado: bool, ofertas: list<array<string, mixed>>
     *     }>
     * }>
     */
    public function montar(Collection $ofertas, ?callable $linhaDaOferta = null): array
    {
        // ─── Nível 1 e 2, pela ordem de PRIMEIRA APARIÇÃO (`:1256`-`:1257`) ──────────────────
        $ordemDosGrupos = [];
        $porGrupo = [];

        foreach ($ofertas as $oferta) {
            $produto = $oferta->produto;

            if ($produto === null) {
                // ⚠️ `produto_id` é `nullOnDelete`: oferta sem produto é estado ALCANÇÁVEL. Ela
                // sai da árvore em vez de derrubar a tela — e não inventa um grupo "sem nome".
                continue;
            }

            $chaveDoGrupo = (int) ($produto->produto_base_id ?? $produto->id);
            $chaveDoConjunto = (int) $oferta->rascunho_id;

            if (! array_key_exists($chaveDoGrupo, $porGrupo)) {
                $ordemDosGrupos[] = $chaveDoGrupo;
                $porGrupo[$chaveDoGrupo] = [];
            }

            if (! array_key_exists($chaveDoConjunto, $porGrupo[$chaveDoGrupo])) {
                $porGrupo[$chaveDoGrupo][$chaveDoConjunto] = ['produto' => $produto, 'ofertas' => []];
            }

            $porGrupo[$chaveDoGrupo][$chaveDoConjunto]['ofertas'][] = $oferta;
        }

        if ($ordemDosGrupos === []) {
            return [];
        }

        $rascunhoIds = [];
        foreach ($porGrupo as $conjuntos) {
            foreach (array_keys($conjuntos) as $rascunhoId) {
                $rascunhoIds[] = $rascunhoId;
            }
        }

        $rascunhos = $this->rascunhos($rascunhoIds);
        $ofertasPorRascunho = $this->ofertasPorRascunho($rascunhoIds);
        $fichaPorRascunho = $this->fichaPorRascunho($rascunhoIds);

        $arvore = [];

        foreach ($ordemDosGrupos as $indice => $chaveDoGrupo) {
            $conjuntos = $porGrupo[$chaveDoGrupo];

            // ⚠️ Os conjuntos por **unidades crescentes** (`:1258`): Unidade, Combo ×2, Combo ×3.
            // `uasort` preserva a chave (o `rascunho_id`), e o desempate por `rascunho_id` existe
            // para que dois kits de MESMA quantidade saiam numa ordem estável — sem ele, a ordem
            // dependeria do hash do array.
            uasort($conjuntos, static function (array $a, array $b): int {
                $ua = max(1, (int) $a['produto']->quantidade_kit);
                $ub = max(1, (int) $b['produto']->quantidade_kit);

                return $ua <=> $ub ?: ((int) $a['produto']->id <=> (int) $b['produto']->id);
            });

            // O produto que NOMEIA a família: o da RAIZ quando ele está na lista; não estando, o
            // primeiro do grupo na ordem da fila.
            //
            // ⚠️ **Nunca uma consulta a mais por grupo, e nunca um nome inventado.** Carregar a
            // raiz ausente custaria uma consulta por grupo para um nome que, nesta família, é o
            // mesmo do kit (o kit nasce do base). ⚠️ E `nome`/`sku` saem CRUS do `pub_produtos`,
            // como `ProximoARevisarService::nomeDe()` já faz: `skuExibido()` consulta
            // `estruturaProduto` e seria uma consulta POR PRODUTO na fila.
            $daRaiz = null;
            $oPrimeiro = null;
            foreach ($conjuntos as $conjunto) {
                $oPrimeiro ??= $conjunto['produto'];
                if ((int) $conjunto['produto']->id === $chaveDoGrupo) {
                    $daRaiz = $conjunto['produto'];
                }
            }
            $doGrupo = $daRaiz ?? $oPrimeiro;

            $totalDeOfertas = 0;
            $conjuntosDaArvore = [];

            foreach ($conjuntos as $rascunhoId => $conjunto) {
                /** @var PubProduto $produto */
                $produto = $conjunto['produto'];
                $unidades = max(1, (int) $produto->quantidade_kit);
                $totalDeOfertas += count($conjunto['ofertas']);

                $galeria = $this->galeria->doRascunho((int) $rascunhoId);

                // ⚠️ Clássico antes de Premium (`:1261`). A ordem vem de `pub_rascunho_alvos.
                // posicao`, que é 0 para o Clássico e 1 para o Premium — e o desempate por `id`
                // existe para que posição repetida não produza ordem instável.
                $ofertasDoConjunto = $conjunto['ofertas'];
                usort($ofertasDoConjunto, static function (PubAprovacaoOferta $a, PubAprovacaoOferta $b): int {
                    return ((int) ($a->alvo?->posicao ?? 0) <=> (int) ($b->alvo?->posicao ?? 0))
                        ?: ((int) $a->id <=> (int) $b->id);
                });

                $conjuntosDaArvore[] = [
                    'rascunho_id' => (int) $rascunhoId,
                    'produto_id' => (int) $produto->id,
                    'rotulo' => $this->rotuloDoConjunto($produto->quantidade_kit),
                    'unidades' => $unidades,
                    'fotos' => $this->fotosDoConjunto($galeria['total'], $galeria['de_ia']),
                    'quadrados' => $galeria['imagens'],
                    'progresso' => $galeria['aprovadas'].'/'.$galeria['total'].self::PROGRESSO_SUFIXO,
                    // ⚠️ VOC-05 — a COR não é o único sinal: o texto "x/y aprovadas" já diz tudo,
                    // e `completo` é o que escolhe entre verde e âmbar (`:1262`).
                    'completo' => $galeria['total'] > 0 && $galeria['aprovadas'] === $galeria['total'],
                    // ⚠️ Conta as ofertas DO RASCUNHO, não as filtradas — ver o docblock.
                    'compartilhado' => ($ofertasPorRascunho[$rascunhoId] ?? 0) > 1,
                    'ofertas' => array_map(
                        $linhaDaOferta ?? static fn (PubAprovacaoOferta $o): array => ['id' => (int) $o->id],
                        $ofertasDoConjunto,
                    ),
                ];
            }

            $arvore[] = [
                'produto_id' => $doGrupo === null ? null : (int) $doGrupo->id,
                'nome' => (string) ($doGrupo->nome ?? ''),
                'sku' => (string) ($doGrupo->sku ?? ''),
                'contagem' => $this->contagemDeOfertas($totalDeOfertas),
                // ⚠️ `:1260` — o PRIMEIRO grupo NÃO tem borda superior; os seguintes têm. É o
                // detalhe que a tabela de fidelidade omite com mais facilidade.
                'borda_superior' => $indice > 0,
                'cadastro' => $this->fraseDoCadastro($conjuntos, $rascunhos, $fichaPorRascunho),
                'conjuntos' => $conjuntosDaArvore,
            ];
        }

        return $arvore;
    }

    /**
     * A LISTA: a `$base` (todos os filtros **menos** a etapa) recortada **pela etapa**.
     *
     * ⚠️⚠️ **POR QUE ISTO NASCE AQUI, E É DESVIO DECLARADO (Regra 3).** O controller precisava da
     * `lista` para a tabela (`:1214`: `list = base.filter(stage)`), e esse recorte **não existia em
     * lugar nenhum**: `AndamentoDaOfertaService::faixaDeAndamento()` conta os 9 cards mas não
     * devolve consulta, e `FiltrosDaFilaService::exibidasNaEtapa()` só LÊ o número da faixa já
     * calculada. Escrevê-lo no controller faria o recorte morar na action, longe de qualquer gate.
     *
     * ⚠️⚠️ **E O RISCO REAL É A ETAPA `publicado`:** ali o card conta "publicado **HOJE**"
     * (`whereDate('updated_at', hoje)`, `AndamentoDaOfertaService::faixaDeAndamento()`), e uma
     * segunda regra escrita de cabeça mostraria na tabela publicações de ontem com o card dizendo
     * outro número. **O usuário recusou número divergente entre conta e lista DUAS vezes**
     * (decisões 9 e 16).
     * ➡️ A regra mora num lugar só, e há gate comparando `recortarPelaEtapa()->count()` com o total
     * do card correspondente **nas 9 chaves**, com isca — a MESMA técnica que a tarefa 1 usa para
     * a galeria contra o bloqueio. Se um dia os dois divergirem, o gate acusa.
     *
     * ⚠️ `$etapa` é a chave **já SANEADA** pelo controller contra a lista fechada das 9
     * (`MlbPublicadorAprovacaoController::etapaSaneada()`): aqui ela só é comparada, nunca
     * interpolada em SQL. Chave desconhecida devolve a base inteira — o mesmo que "todas".
     */
    public function recortarPelaEtapa(Builder $base, mixed $etapa): Builder
    {
        if (! is_string($etapa) || ! array_key_exists($etapa, PubAprovacaoOferta::ANDAMENTOS)) {
            return $base;
        }

        $recortada = $base->where('andamento', $etapa);

        // ⚠️ "Publicado hoje" é recorte DO DIA, e é o único card que não é só o andamento.
        if ($etapa === PubAprovacaoOferta::PUBLICADO) {
            $recortada = $recortada->whereDate('updated_at', now()->toDateString());
        }

        return $recortada;
    }

    /**
     * "Unidade" ou "Combo ×N", de `quantidade_kit`.
     *
     * ⚠️⚠️ **DIVERGÊNCIA DECLARADA, e é a MESMA que `ProximoARevisarService::formato()` já tomou:**
     * `PubProduto::ehKit()` exige **também** `produto_base_id` não-nulo, e aqui ele **não** é
     * consultado. O que este rótulo descreve é *"quantas unidades vão nesta oferta"*, que é
     * `quantidade_kit` e só. Um kit de 2 cadastrado sem base ainda vende 2 unidades por venda, e
     * dizer "Unidade" ali seria afirmação **FALSA** sobre o que o comprador recebe. É também o que
     * o protótipo mede (`set.units > 1`, `:1262`).
     *
     * ⚠️ Valor que não é inteiro positivo cai em "Unidade": "Combo ×0" seria pior que o silêncio.
     */
    public function rotuloDoConjunto(mixed $quantidade): string
    {
        if (! is_int($quantidade) || $quantidade < 2) {
            return self::UNIDADE;
        }

        return self::PREFIXO_COMBO.$quantidade;
    }

    /** `:1259` — "1 oferta" / "N ofertas", com o SINGULAR que é fácil de esquecer. */
    public function contagemDeOfertas(int $total): string
    {
        return $total.($total > 1 ? self::SUFIXO_OFERTAS : self::SUFIXO_OFERTA);
    }

    /** `:1262` — `N fotos (M geradas por IA)`. */
    private function fotosDoConjunto(int $total, int $deIa): string
    {
        return $total.' fotos ('.$deIa.self::FOTOS_SUFIXO_IA;
    }

    /**
     * A frase do cadastro, ou `null` — ver o docblock da classe para a fonte e as três maneiras
     * de ela ser falsa.
     *
     * ⚠️ A família só é "completa" quando **TODOS** os rascunhos dela estão completos: o grupo é
     * UM cabeçalho, e afirmar "cadastro completo" com um kit sem categoria seria afirmar pelo
     * conjunto o que vale para parte dele.
     *
     * @param  array<int, array{produto: \App\Models\PubProduto, ofertas: list<PubAprovacaoOferta>}>  $conjuntos
     * @param  array<int, ?string>  $rascunhos  `rascunho_id` => `categoria_id`
     * @param  array<int, array{total: int, revisar: int}>  $ficha
     */
    private function fraseDoCadastro(array $conjuntos, array $rascunhos, array $ficha): ?string
    {
        foreach (array_keys($conjuntos) as $rascunhoId) {
            $categoria = $rascunhos[$rascunhoId] ?? null;

            if (! is_string($categoria) || trim($categoria) === '') {
                return null;
            }

            $daFicha = $ficha[$rascunhoId] ?? ['total' => 0, 'revisar' => 0];

            // ⚠️ "Sem atributo nenhum" é FALSO de propósito: não há de onde afirmar que a ficha
            // técnica está organizada.
            if ($daFicha['total'] < 1 || $daFicha['revisar'] > 0) {
                return null;
            }
        }

        return self::CADASTRO_COMPLETO;
    }

    /**
     * `rascunho_id` => `categoria_id`. **UMA** consulta.
     *
     * @param  list<int>  $ids
     * @return array<int, ?string>
     */
    private function rascunhos(array $ids): array
    {
        $saida = [];

        foreach (PubRascunho::query()->whereIn('id', $ids)->get(['id', 'categoria_id']) as $rascunho) {
            $saida[(int) $rascunho->id] = $rascunho->categoria_id;
        }

        return $saida;
    }

    /**
     * `rascunho_id` => quantas ofertas do portão aquele rascunho tem, **sem filtro**. UMA consulta.
     *
     * ⚠️ É o `setOffers(sk).length` do protótipo (`:1054`/`:1262`), e o "sem filtro" é a parte que
     * importa — ver o docblock da classe.
     *
     * @param  list<int>  $ids
     * @return array<int, int>
     */
    private function ofertasPorRascunho(array $ids): array
    {
        $saida = [];

        $linhas = PubAprovacaoOferta::query()
            ->whereIn('rascunho_id', $ids)
            ->selectRaw('rascunho_id, COUNT(*) as total')
            ->groupBy('rascunho_id')
            ->get();

        foreach ($linhas as $linha) {
            $saida[(int) $linha->rascunho_id] = (int) $linha->total;
        }

        return $saida;
    }

    /**
     * `rascunho_id` => `{total, revisar}` dos atributos da ficha técnica. UMA consulta.
     *
     * ⚠️ Agregado em SQL, nunca `->atributos()->count()` por rascunho: no lote isso é o N+1
     * clássico, e a fila pode ter trinta linhas.
     *
     * @param  list<int>  $ids
     * @return array<int, array{total: int, revisar: int}>
     */
    private function fichaPorRascunho(array $ids): array
    {
        $saida = [];

        $linhas = PubRascunhoAtributo::query()
            ->whereIn('rascunho_id', $ids)
            ->selectRaw('rascunho_id, COUNT(*) as total, SUM(CASE WHEN revisar = 1 THEN 1 ELSE 0 END) as revisar')
            ->groupBy('rascunho_id')
            ->get();

        foreach ($linhas as $linha) {
            $saida[(int) $linha->rascunho_id] = [
                'total' => (int) $linha->total,
                'revisar' => (int) $linha->revisar,
            ];
        }

        return $saida;
    }
}
