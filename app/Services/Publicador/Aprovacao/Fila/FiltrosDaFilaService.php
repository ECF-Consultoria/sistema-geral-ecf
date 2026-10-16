<?php

namespace App\Services\Publicador\Aprovacao\Fila;

use App\Models\EstruturaAnuncio;
use App\Models\EstruturaPublicacao;
use App\Models\User;
use App\Services\Publicador\Aprovacao\BloqueiosDaOfertaService;
use App\Services\Publicador\Aprovacao\VocabularioDoPortao;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 * OS FILTROS DA FILA "AGUARDANDO APROVAÇÃO" (Fase 187, plano 06) — FILA-05, FILA-06, COL-02/03.
 *
 * A busca sem acento **no servidor**, os quatro filtros como listas FECHADAS, e as contagens dos
 * **TRÊS recortes** que o desenho usa de propósito.
 *
 * ═══ ⚠️⚠️ OS TRÊS RECORTES, E ELES SÃO DIFERENTES DE PROPÓSITO (medido) ════════════════════════
 *
 * | recorte            | o que é                                              | medido no protótipo |
 * |--------------------|------------------------------------------------------|---------------------|
 * ⚠️ **ESTE SERVIÇO NÃO MONTA O RECORTE POR CONTA — ele o RECEBE.** Todos os métodos que
 * consultam recebem um `Builder` sobre `pub_aprovacao_ofertas` que veio de
 * `FilaDeAprovacaoService::paraAConta()`, e é lá que vive a trava T-186-14: aquele método
 * **LANÇA** `RegraViolada` quando as duas âncoras de conta são nulas, porque quem não sabe de que
 * conta fala **não recebe a fila**. Reconstruir o `where('company_id', …)` aqui criaria uma
 * segunda verdade — e uma que pode nascer SEM a trava, já que as duas colunas de conta são
 * anuláveis por construção (ver a §5 da migration `2026_10_15_100200_…`).
 *
 * | **da conta**       | TODAS as ofertas da conta, **zero filtro**            | `all` (`:1208`)     |
 * | **base**           | todos os filtros **MENOS** a etapa                    | `base` (`:1210`)    |
 * | **lista**          | a `base` **mais** a etapa                             | `list` (`:1214`)    |
 *
 * ➡️ **As contagens dos dois POPOVERS são sobre o recorte DA CONTA** — `all.filter(...)` em
 * `:1265` (Responsável) e `:1266` (Motivo de bloqueio). É o que torna o popover útil: ele diz
 * quanto **EXISTE**, não quanto sobrou. ⚠️ O plano aponta `:1277`/`:1278`; medido com `grep -n`,
 * são **`:1265`/`:1266`**.
 *
 * ➡️ **As contagens da FAIXA de 9 cards são sobre a `base`** (`:1213`), e quem as calcula é
 * `AndamentoDaOfertaService::faixaDeAndamento()`, da Fase 186 — este serviço **não recalcula**.
 *
 * ⚠️ Colapsar dois destes recortes é o bug que faz o card ativo mostrar o próprio total e todos os
 * outros oito zerarem, e a pessoa perder a navegação.
 *
 * ═══ ⚠️⚠️ A BUSCA SEM ACENTO — ONDE ELA MORA, E A MEDIÇÃO QUE DECIDIU ══════════════════════════
 *
 * **Produção é MySQL 8.0.46, o local é MariaDB 10.4.32, os testes são SQLite — e os três tratam
 * acento de forma DIFERENTE.** As três estratégias possíveis foram **MEDIDAS**, não escolhidas por
 * gosto:
 *
 * **(a) Delegar ao COLLATION** — é o precedente do módulo
 * (`PublicacoesDaListaService::mlItemIdsPorProduto()`), que em 2026-10 decidiu confiar em
 * `utf8mb4_0900_ai_ci` / `utf8mb4_general_ci` e **declarou a limitação**: o `LIKE` do SQLite dobra
 * ASCII e **NÃO** dobra acento, então ali a busca acento-insensível **não é provada em teste
 * nenhum** (ver `test_busca_ignora_caixa_e_o_acento_depende_do_collation`, naquele plano).
 * ⚠️ **RECUSADO aqui:** FILA-05 exige a busca ignorando acento, e um filtro cuja prova depende do
 * banco de produção é um filtro que ninguém mede — ele só é conferido quando alguém reclama.
 *
 * **(b) Normalizar a COLUNA em SQL**, com uma cadeia de `REPLACE()` gerada do mapa e injetada por
 * `whereRaw` (o nome de coluna vindo de constante, o termo por bind).
 * ⚠️⚠️ **RECUSADO POR MEDIÇÃO, em 16/10/2026, nesta máquina:** o SQLite estoura com
 * `SQLSTATE[HY000]: General error: 1 parser stack overflow` em cadeia profunda, e o **teto medido
 * é 28** `REPLACE` encaixados (28 passa, 29 reprova — medido um a um). O alfabeto **do português
 * sozinho** são 14 letras acentuadas × 2 caixas = **28**: a estratégia nasceria **exatamente sobre
 * o teto**, e a primeira letra nova (`ñ` do espanhol, que aparece no catálogo do ML) a derrubaria
 * — em produção, não no teste. Projeto que encosta num limite rígido medido não é projeto, é sorte.
 *
 * **(c) Normalizar em PHP, nos dois lados — ESCOLHIDA.** A busca é resolvida sobre as ofertas da
 * conta, com **UMA** função de normalização ({@see self::normalizarParaBusca}) aplicada ao TERMO e
 * ao CONTEÚDO, e o recorte volta ao `Builder` como `whereIn('id', …)` — **o mesmo mecanismo que o
 * filtro por MOTIVO já exige** e que este plano já paga.
 *
 * ⚠️ **"No SERVIDOR" (FILA-05) está satisfeito:** PHP **é** o servidor. O que FILA-05 proíbe é a
 * busca acontecer no NAVEGADOR — e aqui nada dela sai para o cliente: a tela manda o termo na URL
 * e recebe o recorte pronto.
 *
 * **Por que (c) custa ZERO a mais, e isso é o que fecha a decisão:** este serviço **já** materializa
 * todas as ofertas da conta em memória, incondicionalmente, porque `panoramaDaConta()` chama
 * `BloqueiosDaOfertaService::para()` uma vez por oferta (ver a seção do CUSTO). O "N linhas na
 * memória" **já está pago em todo request**, com ou sem busca. Normalizar texto na mesma passada
 * não acrescenta consulta, não acrescenta laço e não acrescenta requisição ao ML.
 *
 * **E o que (c) ganha:**
 *   · **uma** normalização, não duas — o modo de falhar de (b) era ter `Str::ascii()` de um lado e
 *     uma cadeia menor do outro, e aí um termo com `'ñ'` viraria `'n'` num lado e ficaria `'ñ'` no
 *     outro: a busca **não acharia nada, sem erro nenhum**;
 *   · **comportamento idêntico nos três bancos**, porque **nenhum** banco participa da comparação;
 *   · o mapa pode ser o **Latin-1 acentuado INTEIRO** (não há teto de profundidade), então a
 *     cobertura é MAIOR que em (b), não menor;
 *   · a **CONCATENAÇÃO do protótipo é reproduzida LITERALMENTE** —
 *     `norm(pr.name + ' ' + o.title + ' ' + pr.sku).includes(q)` (`:1211`) —, inclusive o termo que
 *     ATRAVESSA dois campos ("poltrona ABC-1"). Em (b) isso exigiria `CONCAT_WS` (MySQL/MariaDB) ou
 *     `||` (SQLite), operadores que **divergem** entre os três — e `||` é OR LÓGICO no MySQL sem
 *     `PIPES_AS_CONCAT`;
 *   · `%` e `_` digitados pela pessoa **param de ser curinga**: a comparação é `str_contains`, não
 *     `LIKE`. Em `PublicacoesDaListaService` eles ainda são curinga, e ali isso é limitação
 *     declarada.
 *
 * ⚠️ **O limite de (c), declarado:** a busca não usa índice e é O(n) sobre as ofertas da conta.
 * **É o MESMO teto que o filtro por motivo já impõe** (ver a seção do CUSTO) — não é um teto novo,
 * e a mitigação decidida pelo usuário é a mesma: pré-aquecer fora do request (plano `187-10`).
 * ⚠️ **NÃO há coluna normalizada**, e inventar uma criaria uma segunda verdade que fica velha
 * sozinha.
 *
 * ═══ ⚠️ T-187-61 — A QUERYSTRING É HOSTIL ══════════════════════════════════════════════════════
 *
 * **`is_string` ANTES de `trim`, e NUNCA `(string) $valor`:** `?busca[]=x` entrega um ARRAY, e
 * `(string) []` emite `Array to string conversion`, que o `HandleExceptions` transforma em **500**
 * — foi 500 nesta tela antes (T-180-04-T). Vale para os **seis** parâmetros.
 *
 * **Lista FECHADA em todos eles**, e lida de onde a verdade mora:
 *   · `modalidade` → `EstruturaAnuncio::TIPOS` (as chaves) + `todas`;
 *   · `formato`    → {@see self::FORMATOS} (este serviço é o único dono deste vocabulário);
 *   · `responsavel`→ os ids que **a conta de fato tem** (interseção com o banco);
 *   · `motivo`     → as 8 chaves de `VocabularioDoPortao::ROTULOS`.
 * Valor fora da lista é **descartado em silêncio** e cai no default — nunca zera a lista, e nunca
 * chega a SQL. ⚠️ E nada é interpolado: o nome de coluna vem de constante interna, o termo é bind.
 *
 * ═══ ⚠️⚠️ O CUSTO DO FILTRO POR MOTIVO — E A MITIGAÇÃO JÁ DECIDIDA ═════════════════════════════
 *
 * **O filtro "Motivo de bloqueio" NÃO É FILTRÁVEL EM SQL.** Os `kinds` só existem depois de
 * `BloqueiosDaOfertaService::para()`, que lê a composição e consulta a tarifa do Mercado Livre
 * (`/sites/MLB/listing_prices`, `Cache::remember` de 1 h por **categoria+tipo+preço** —
 * `ComposicaoFinanceiraService:474`-`:478`). Não há coluna de `kinds`, e inventar uma criaria um
 * contador denormalizado que fica velho em silêncio.
 *
 * ➡️ **O filtro e as 8 contagens são calculados em PHP** sobre as ofertas da conta, com
 * **EXATAMENTE UMA** chamada a `para()` por oferta ({@see self::panoramaDaConta}) e **fora de
 * qualquer laço aninhado**: o mapa `id => kinds` é montado UMA vez e os dois consumidores (o
 * filtro e as contagens) leem o MESMO mapa. **Este é o limite conhecido da tela.**
 *
 * ⚠️⚠️ **A MITIGAÇÃO ESTÁ DECIDIDA E PLANEJADA: o usuário escolheu PRÉ-AQUECER por tarefa em
 * segundo plano (15/10/2026), e isso é o plano `187-10`.** Ele recusou explicitamente *"aceitar e
 * medir em produção"* e *"limitar N consultas por abertura"* — esta última pela razão que amarra a
 * milestone: **"Incompleto" bloqueia a aprovação**, então limitar protegeria o servidor
 * **travando o trabalho**.
 *
 * ➡️ **Nada aqui degrada e nada aqui limita.** Zero cap por request, zero "sem tarifa", zero
 * "incompleto por timeout". Cache frio produz o número **CERTO**, só mais devagar. ⚠️ E o
 * aquecimento do `187-10` muda **QUANDO** a requisição acontece, **não QUANTAS** — o gate de custo
 * (`FiltrosDaFilaServiceTest`: no máximo **1** requisição por trio `(categoria, tipo, preço)`
 * distinto, com isca nas duas direções) **continua valendo depois dele**.
 *
 * ═══ ⚠️ O "N DE M" É A DIVERGÊNCIA D8, E ELA É REPRODUZIDA ═════════════════════════════════════
 *
 * `countLabel` é `list.length + ' de ' + all.length + ' ofertas'` (`:1276`): o **M** é o total de
 * **TODAS** as ofertas da conta — **inclusive `preparando` e `publicado`** —, **não** o das `live`.
 * ⚠️ **Reproduzido, e NÃO "consertado"** para o total da fila: mudar tornaria o número incoerente
 * com o card "Todas" da faixa, que também conta sobre a `base` sem recorte de estado.
 *
 * ⚠️ E o **N** NÃO é recontado aqui: ele é **LIDO da faixa** que a Fase 186 já calculou
 * ({@see self::exibidasNaEtapa}). Recontar produziria uma segunda consulta livre para divergir —
 * e é justamente na etapa `publicado` que ela divergiria, porque ali o recorte é
 * "**publicado HOJE**" (`whereDate('updated_at', hoje)`, `AndamentoDaOfertaService:294`-`:295`).
 * O usuário recusou número divergente entre conta e lista **duas vezes** (decisões 9 e 16).
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 */
class FiltrosDaFilaService
{
    /** A modalidade "sem filtro" — não é `listing_type_id`, e por isso não está no mapa do ML. */
    public const MODALIDADE_TODAS = 'todas';

    /** O formato "sem filtro". */
    public const FORMATO_TODOS = 'todos';

    /** Uma unidade por venda — `pub_produtos.quantidade_kit = 1`. */
    public const FORMATO_UNIDADE = 'unidade';

    /** Kit — `pub_produtos.quantidade_kit >= 2`. */
    public const FORMATO_COMBO = 'combo';

    /**
     * A lista FECHADA do segmentado "Formato", na ordem do protótipo (`:1276`: `fmtOpts`).
     *
     * ⚠️ Este serviço é o **único dono** deste vocabulário — não há constante de modelo para ele,
     * porque "unidade × combo" é uma LEITURA de `quantidade_kit`, não um valor gravado.
     */
    public const FORMATOS = [self::FORMATO_TODOS, self::FORMATO_UNIDADE, self::FORMATO_COMBO];

    /**
     * O rótulo da opção "sem filtro" do segmentado de Modalidade — `modOpts[0]`, `:1276`.
     *
     * ⚠️ Só este: "Clássico" e "Premium" vêm de `EstruturaAnuncio::TIPOS`, **lidos**. Escrevê-los
     * aqui criaria uma terceira cópia dos nomes dos dois tipos.
     */
    public const ROTULO_MODALIDADE_TODAS = 'Todas';

    /**
     * Os rótulos do segmentado de Formato, byte a byte como `fmtOpts` os escreve (`:1276`).
     *
     * ⚠️ Não há constante de modelo para eles porque "unidade × combo" é LEITURA de
     * `quantidade_kit`, não valor gravado — este serviço é o dono.
     */
    public const ROTULOS_DO_FORMATO = [
        self::FORMATO_TODOS => 'Todos',
        self::FORMATO_UNIDADE => 'Unidade',
        self::FORMATO_COMBO => 'Combo',
    ];

    /**
     * ⚠️⚠️ O MAPA DE ACENTOS — **A ÚNICA FONTE DOS DOIS LADOS DA BUSCA.**
     *
     * O TERMO e o CONTEÚDO passam pela **MESMA** função ({@see self::normalizarParaBusca}), que o
     * lê. Não há segunda implementação, e é isso que torna impossível o modo de falhar que a
     * estratégia (b) tinha: `Str::ascii()` de um lado e uma cadeia menor do outro faria um termo
     * com `'ñ'` virar `'n'` num lado e ficar `'ñ'` no outro — e a busca **não acharia nada, sem
     * erro nenhum**.
     *
     * ⚠️ Ele é o **Latin-1 acentuado inteiro**, com as duas caixas. Caber inteiro só é possível
     * porque a normalização é em PHP: em SQL o teto MEDIDO do SQLite são **28** `REPLACE`
     * encaixados, e só as minúsculas já passariam dele num alfabeto a mais.
     *
     * ⚠️ As MAIÚSCULAS mapeiam para a base ASCII **maiúscula**; quem rebaixa a caixa é o
     * `mb_strtolower` de {@see self::normalizarParaBusca}, **depois**. Mapear direto para minúscula
     * aqui funcionaria igual, mas deixaria o mapa afirmando duas coisas num lugar só.
     */
    public const SEM_ACENTO = [
        'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'å' => 'a',
        'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
        'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
        'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o',
        'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
        'ç' => 'c', 'ñ' => 'n', 'ý' => 'y', 'ÿ' => 'y',
        'Á' => 'A', 'À' => 'A', 'Â' => 'A', 'Ã' => 'A', 'Ä' => 'A', 'Å' => 'A',
        'É' => 'E', 'È' => 'E', 'Ê' => 'E', 'Ë' => 'E',
        'Í' => 'I', 'Ì' => 'I', 'Î' => 'I', 'Ï' => 'I',
        'Ó' => 'O', 'Ò' => 'O', 'Ô' => 'O', 'Õ' => 'O', 'Ö' => 'O',
        'Ú' => 'U', 'Ù' => 'U', 'Û' => 'U', 'Ü' => 'U',
        'Ç' => 'C', 'Ñ' => 'N', 'Ý' => 'Y',
    ];

    public function __construct(
        private BloqueiosDaOfertaService $bloqueios,
    ) {}

    // ─────────────────────────────────────────────────────────────────────────────────────────
    // 1. O SANEAMENTO — seis parâmetros, seis listas fechadas.
    // ─────────────────────────────────────────────────────────────────────────────────────────

    /**
     * Os filtros da querystring, SANEADOS (T-187-61).
     *
     * ⚠️ `?oferta=<id>` **NÃO** é filtro e não entra aqui: ele dá FOCO a uma linha (plano 187-05).
     * Se entrasse, abrir uma oferta faria "Limpar filtros" aparecer.
     *
     * @param  list<int>  $responsaveisDaConta os ids que a conta de fato tem (a lista fechada)
     * @return array{busca: string, modalidade: string, formato: string, responsavel: list<int>, motivo: list<string>}
     */
    public function saneados(Request $request, array $responsaveisDaConta = []): array
    {
        return [
            'busca' => $this->textoSaneado($request->query('busca')),
            'modalidade' => $this->daLista($request->query('modalidade'), $this->modalidades(), self::MODALIDADE_TODAS),
            'formato' => $this->daLista($request->query('formato'), self::FORMATOS, self::FORMATO_TODOS),
            'responsavel' => $this->inteirosDaLista($request->query('responsavel'), $responsaveisDaConta),
            'motivo' => $this->textosDaLista($request->query('motivo'), $this->motivos()),
        ];
    }

    /**
     * A lista FECHADA do segmentado "Modalidade", na ordem do protótipo (`modOpts`, `:1276`).
     *
     * ⚠️ **LIDA de `EstruturaAnuncio::TIPOS`**, nunca escrita à mão: é o mesmo par que
     * `EstruturaPublicacao::LISTING_TYPES` inverte para chegar ao `listing_type_id`, e
     * `ProximoARevisarService::modalidade()` já lê os dois. Uma terceira cópia ficaria velha
     * sozinha no dia em que um terceiro tipo entrar.
     *
     * @return list<string>
     */
    public function modalidades(): array
    {
        return array_merge([self::MODALIDADE_TODAS], array_keys(EstruturaAnuncio::TIPOS));
    }

    /**
     * As 3 opções do segmentado "Modalidade", na ordem do protótipo (`modOpts`, `:1276`).
     *
     * @return list<array{valor: string, rotulo: string}>
     */
    public function opcoesDeModalidade(): array
    {
        $opcoes = [['valor' => self::MODALIDADE_TODAS, 'rotulo' => self::ROTULO_MODALIDADE_TODAS]];

        foreach (EstruturaAnuncio::TIPOS as $valor => $rotulo) {
            $opcoes[] = ['valor' => $valor, 'rotulo' => $rotulo];
        }

        return $opcoes;
    }

    /**
     * As 3 opções do segmentado "Formato", na ordem do protótipo (`fmtOpts`, `:1276`).
     *
     * @return list<array{valor: string, rotulo: string}>
     */
    public function opcoesDeFormato(): array
    {
        $opcoes = [];

        // ⚠️ A ordem é a de `FORMATOS`, não a do mapa de rótulos: é a lista fechada que manda, e
        // um rótulo que faltasse deixaria o segmentado com DUAS opções em vez de três — por isso o
        // `??` nunca inventa texto, ele deixa o buraco aparecer no gate.
        foreach (self::FORMATOS as $valor) {
            $opcoes[] = ['valor' => $valor, 'rotulo' => self::ROTULOS_DO_FORMATO[$valor] ?? $valor];
        }

        return $opcoes;
    }

    /**
     * As **8** chaves do filtro "Motivo de bloqueio", na ordem de `BLK` (`:973`).
     *
     * ⚠️ **São os 8 de `VocabularioDoPortao::ROTULOS`, e NÃO `OS_OITO`** — os dois conjuntos têm o
     * mesmo tamanho e conteúdo DIFERENTE: `alerta_em_foto_de_ia` e `falha_na_publicacao` são
     * `kinds` e não bloqueios; `sem_fotos`, `titulo_longo` e `em_preparacao` não são filtráveis.
     * Isso é do desenho (ver o docblock de `ROTULOS`).
     *
     * ⚠️ **DIVERGÊNCIA D6, reproduzida pelo DADO:** o template do popover declara
     * `hint-placeholder-count="6"` (`Aprovacao.dc.html:96`) e `BLK` declara **OITO** motivos
     * (`:973`). O 6 é artefato do protótipo — **o dado ganha do placeholder**.
     *
     * @return list<string>
     */
    public function motivos(): array
    {
        return array_keys(VocabularioDoPortao::ROTULOS);
    }

    /**
     * `true` quando QUALQUER um dos seis filtros está ativo (`anyFilter`, `:1270`).
     *
     * ⚠️ A **etapa** entra na conta, e isso é medido: `S.stage !== 'all'` está no `anyFilter` do
     * protótipo e `onClear` a zera junto com os outros cinco (`:1276`). "Limpar filtros" é a
     * ausência dos SEIS.
     *
     * ⚠️⚠️ A etapa chega como **BOOLEANO**, e isso é decisão: o vocabulário da etapa (as 9 chaves
     * mais `todas`) mora no CONTROLLER, que já a saneia contra `PubAprovacaoOferta::ANDAMENTOS`.
     * Receber a string aqui obrigaria este serviço a conhecer o valor `'todas'` — uma **segunda
     * cópia** daquela verdade, livre para divergir —, e importar o controller num serviço
     * inverteria a dependência. ➡️ Quem sabe se a etapa está filtrada é quem a saneou.
     *
     * @param  array{busca: string, modalidade: string, formato: string, responsavel: list<int>, motivo: list<string>}  $filtros
     * @param  bool  $etapaFiltrada `true` quando a etapa NÃO é a "sem filtro" (quem decide é o controller)
     */
    public function algumFiltro(array $filtros, bool $etapaFiltrada): bool
    {
        return ($filtros['busca'] ?? '') !== ''
            || ($filtros['modalidade'] ?? self::MODALIDADE_TODAS) !== self::MODALIDADE_TODAS
            || ($filtros['formato'] ?? self::FORMATO_TODOS) !== self::FORMATO_TODOS
            || ($filtros['responsavel'] ?? []) !== []
            || ($filtros['motivo'] ?? []) !== []
            || $etapaFiltrada;
    }

    // ─────────────────────────────────────────────────────────────────────────────────────────
    // 2. A APLICAÇÃO — a `base` (todos os filtros MENOS a etapa).
    // ─────────────────────────────────────────────────────────────────────────────────────────

    /**
     * Aplica os quatro filtros à base recortada por conta, devolvendo a **`base`** do protótipo
     * (`:1210`): todos os filtros **MENOS** a etapa.
     *
     * ⚠️ Os filtros compõem por **INTERSEÇÃO** (`&&` em `:1211`): dois ativos ao mesmo tempo é
     * **E**, nunca OU. Cada um é um `where` no MESMO builder, e os `orWhere` da busca vivem dentro
     * de um GRUPO — sem o grupo, um `orWhere` solto subiria ao topo do `WHERE` e **anularia o
     * recorte por conta**, devolvendo fila de outra empresa.
     *
     * ⚠️ **`whereHas`, nunca `join`:** um `join` com `pub_produtos` deixaria `updated_at` ambíguo,
     * e `faixaDeAndamento()` (Fase 186, somente leitura) faz `whereDate('updated_at', …)` sem
     * qualificar. O `join` produziria erro de coluna ambígua no MySQL — e **não** no SQLite.
     *
     * ⚠️ **Dois mecanismos, e eles são escolhidos por critério, não por gosto:** modalidade,
     * formato e responsável são **igualdade** sobre coluna — SQL faz isso bem e portavelmente, e
     * fica em SQL. Busca e motivo **não são portáveis em SQL** (ver as seções da BUSCA e do CUSTO)
     * e viram `whereIn('id', …)` sobre o panorama que este request já materializou.
     *
     * @param  array{busca: string, modalidade: string, formato: string, responsavel: list<int>, motivo: list<string>}  $filtros
     * @param  array<int, array{kinds: list<string>, texto: string}>  $panorama o mapa de {@see self::panoramaDaConta}
     */
    public function aplicar(Builder $base, array $filtros, array $panorama = []): Builder
    {
        $busca = $filtros['busca'] ?? '';
        if ($busca !== '') {
            // ⚠️ A busca é resolvida em PHP, sobre o MESMO panorama do motivo — zero consulta nova.
            $base->whereIn('id', $this->idsComBusca($panorama, $busca));
        }

        $modalidade = $filtros['modalidade'] ?? self::MODALIDADE_TODAS;
        if ($modalidade !== self::MODALIDADE_TODAS) {
            // ⚠️ O `listing_type_id` sai do mapa LIDO, nunca de literal `gold_special`.
            $tipoMl = EstruturaPublicacao::LISTING_TYPES[$modalidade] ?? null;

            // ⚠️ Modalidade saneada SEM tipo no mapa não vira "sem filtro": seria filtro que a
            // pessoa pediu e o servidor ignorou. Lista fechada + mapa lido tornam isto inalcançável
            // hoje, e a guarda está aqui para o dia em que um terceiro tipo entrar em `TIPOS` sem
            // entrar em `LISTING_TYPES`.
            // ⚠️ `whereIn` com lista VAZIA (o Eloquent emite `0 = 1`) em vez de um sentinela
            // inventado: "nada casa" fica legível no SQL.
            $base->whereHas(
                'alvo',
                fn ($alvo) => $alvo->whereIn('listing_type_id', $tipoMl === null ? [] : [$tipoMl]),
            );
        }

        $formato = $filtros['formato'] ?? self::FORMATO_TODOS;
        if ($formato !== self::FORMATO_TODOS) {
            $base->whereHas('produto', function ($produto) use ($formato) {
                // ⚠️ **DIVERGÊNCIA DECLARADA do protótipo (`:1211`):** lá o ramo "unidade" é
                // `!(units > 1)`, ou seja `<= 1`, e aqui é `= 1`. `quantidade_kit` zero ou negativo
                // é dado que **não deveria existir** (a coluna é `unsignedSmallInteger` com default
                // 1), e chamar isso de "Unidade" seria afirmação FALSA sobre o que o comprador
                // recebe — a MESMA decisão que `ProximoARevisarService::formato()` já tomou ao
                // devolver `null` abaixo de 1. Oferta com kit 0 aparece em "Todos" e em nenhum dos
                // dois, e isso é consistente com o módulo.
                $formato === self::FORMATO_COMBO
                    ? $produto->where('quantidade_kit', '>=', 2)
                    : $produto->where('quantidade_kit', '=', 1);
            });
        }

        $responsaveis = $filtros['responsavel'] ?? [];
        if ($responsaveis !== []) {
            $base->whereIn('responsavel_id', $responsaveis);
        }

        $motivos = $filtros['motivo'] ?? [];
        if ($motivos !== []) {
            // ⚠️ O filtro por MOTIVO não é SQL (não há coluna de `kinds`): ele vira uma lista de
            // ids resolvida em PHP sobre o MESMO panorama que alimenta as 8 contagens — **zero**
            // chamada extra a `para()`.
            $base->whereIn('id', $this->idsComMotivo($panorama, $motivos));
        }

        return $base;
    }

    // ─────────────────────────────────────────────────────────────────────────────────────────
    // 3. AS CONTAGENS — o recorte DA CONTA (`all`), com zero filtro.
    // ─────────────────────────────────────────────────────────────────────────────────────────

    /**
     * O **M** do "N de M ofertas": o total de TODAS as ofertas da conta (divergência **D8**).
     *
     * ⚠️ Inclui `preparando` e `publicado` — é `all.length` (`:1276`), **não** o das `live`.
     */
    public function totalDaConta(Builder $daConta): int
    {
        return (clone $daConta)->count();
    }

    /**
     * O **N** do "N de M ofertas", **LIDO da faixa** que a Fase 186 calculou sobre a `base`.
     *
     * ⚠️ **Lido, não recontado**, e a razão é a decisão 19: a etapa `publicado` recorta
     * "**publicado HOJE**" (`whereDate('updated_at', hoje)`), então uma segunda consulta aqui
     * poderia divergir do card. **Conta e lista têm de bater** — o usuário recusou número
     * divergente duas vezes (decisões 9 e 16).
     *
     * @param  list<array{chave: string, rotulo: string, total: int}>  $faixa
     */
    public function exibidasNaEtapa(array $faixa, string $etapa): ?int
    {
        foreach ($faixa as $recorte) {
            if (is_array($recorte) && ($recorte['chave'] ?? null) === $etapa && is_int($recorte['total'] ?? null)) {
                return $recorte['total'];
            }
        }

        return null;
    }

    /**
     * Os responsáveis do popover, com a contagem sobre **TODAS** as ofertas da conta (`:1265`).
     *
     * ⚠️ A contagem **não muda** quando outro filtro está ativo: é o que torna o popover útil (ele
     * diz quanto EXISTE, não quanto sobrou). Ela muda quando a **conta** muda, e o gate prova as
     * duas direções.
     *
     * ⚠️ Oferta **sem** responsável não entra em opção nenhuma — e isso é honesto: não existe
     * "Sem responsável" no protótipo, e inventar a opção faria a soma das opções divergir do M.
     *
     * ⚠️⚠️ **SEM `iniciais` no payload, e isso é correção MEDIDA em 16/10/2026.** A primeira
     * redação deste método calculava as iniciais em PHP (primeira + última palavra) — uma SEGUNDA
     * implementação da regra, e **divergente** da única declarada:
     * `resources/js/Components/Mlb/Publicador/identidade/identidade.js:27` (`iniciais()`), que é
     * **fonte ÚNICA por IDENT-08** (*"nenhuma tela reimplementa"*) e só considera palavras com
     * MAIS de duas letras. Medido: "Ana de Souza Lima" daria **AL** no PHP e **AS** no módulo.
     * ➡️ O servidor manda o **nome**; quem deriva as iniciais é o componente, pelo módulo.
     *
     * @return list<array{id: int, nome: string, total: int}>
     */
    public function responsaveisDaConta(Builder $daConta): array
    {
        // ⚠️ `toBase()` e não modelo hidratado: o agregado é do query builder, e hidratar a fila
        // inteira só para somar custaria a memória de todas as ofertas da conta.
        $contagens = (clone $daConta)
            ->whereNotNull('responsavel_id')
            ->toBase()
            ->select('responsavel_id')
            ->selectRaw('COUNT(*) as total')
            ->groupBy('responsavel_id')
            ->pluck('total', 'responsavel_id');

        if ($contagens->isEmpty()) {
            return [];
        }

        $nomes = User::query()
            ->whereIn('id', $contagens->keys()->map(fn ($id) => (int) $id)->all())
            ->orderBy('name')
            ->get(['id', 'name']);

        $opcoes = [];

        foreach ($nomes as $pessoa) {
            $nome = is_string($pessoa->name) ? trim($pessoa->name) : '';

            // ⚠️ Pessoa sem nome não vira opção: um item em branco com contagem é pior que a
            // ausência dele, e não há rótulo honesto para pôr ali.
            if ($nome === '') {
                continue;
            }

            $opcoes[] = [
                'id' => (int) $pessoa->id,
                'nome' => $nome,
                'total' => (int) ($contagens[$pessoa->id] ?? 0),
            ];
        }

        return $opcoes;
    }

    /**
     * Os **8** motivos do popover, com a contagem sobre **TODAS** as ofertas da conta (`:1266`).
     *
     * ⚠️ Os oito aparecem SEMPRE, inclusive com contagem **0** — zero medido é FATO, e esconder a
     * opção faria a pessoa achar que o motivo não existe.
     *
     * @param  array<int, array{kinds: list<string>, texto: string}>  $panorama o mapa de {@see self::panoramaDaConta}
     * @return list<array{chave: string, total: int}>
     */
    public function motivosDaConta(array $panorama): array
    {
        $contagem = [];

        foreach ($this->motivos() as $motivo) {
            $contagem[$motivo] = 0;
        }

        foreach ($panorama as $linha) {
            foreach ($linha['kinds'] ?? [] as $kind) {
                if (array_key_exists($kind, $contagem)) {
                    $contagem[$kind]++;
                }
            }
        }

        $saida = [];

        foreach ($contagem as $chave => $total) {
            $saida[] = ['chave' => $chave, 'total' => $total];
        }

        return $saida;
    }

    /**
     * ⚠️⚠️ **O PANORAMA DA CONTA — UMA passada, UM `get()`, UMA chamada a `para()` por oferta.**
     *
     * Devolve `id => {kinds, texto}`: os `kinds` para o filtro/contagem de MOTIVO, e o `texto` já
     * normalizado para a BUSCA. Os **três** consumidores (as 8 contagens, o filtro de motivo e a
     * busca) leem o MESMO mapa.
     *
     * ⚠️ **É O ÚNICO LUGAR QUE CHAMA `para()` NESTE SERVIÇO**, e é por isso que o custo é
     * mensurável. Chamar `para()` dentro do laço das 8 contagens multiplicaria as requisições ao
     * ML por OITO.
     *
     * ⚠️ As três relações que `para()` e a busca leem entram por `with()`: sem isso cada oferta
     * custaria três consultas a mais, e o total cresceria com o tamanho da fila sem gate nenhum
     * acusar.
     *
     * ⚠️ **O `texto` é a CONCATENAÇÃO LITERAL do protótipo** (`:1211`:
     * `norm(pr.name + ' ' + o.title + ' ' + pr.sku)`), nessa ordem e com o espaço no meio — é o que
     * faz um termo que atravessa dois campos casar, exatamente como lá.
     *
     * ⚠️ **Sem cap e sem "sem tarifa"** — ver o docblock da classe: a tela nunca degrada, e o
     * pré-aquecimento fora do request é o plano `187-10`.
     *
     * @return array<int, array{kinds: list<string>, texto: string}>
     */
    public function panoramaDaConta(Builder $daConta): array
    {
        $mapa = [];

        foreach ((clone $daConta)->with(['rascunho', 'alvo', 'produto'])->get() as $oferta) {
            $leitura = $this->bloqueios->para($oferta);
            $kinds = $leitura['kinds'] ?? [];

            $mapa[(int) $oferta->id] = [
                'kinds' => is_array($kinds) ? array_values(array_filter($kinds, 'is_string')) : [],
                'texto' => $this->normalizarParaBusca(implode(' ', [
                    // ⚠️ `is_string` em cada um: `titulo` é anulável no schema, e `(string) null`
                    // seria `''` por acidente em vez de por decisão. Campo ausente não casa nada —
                    // e não faz a oferta casar QUALQUER termo, que é o oposto do defeito.
                    is_string($oferta->produto?->nome) ? $oferta->produto->nome : '',
                    is_string($oferta->alvo?->titulo) ? $oferta->alvo->titulo : '',
                    is_string($oferta->produto?->sku) ? $oferta->produto->sku : '',
                ])),
            ];
        }

        return $mapa;
    }

    /**
     * Os ids das ofertas cujo texto CONTÉM o termo, as duas pontas normalizadas pela MESMA função.
     *
     * ⚠️ `str_contains` e não `LIKE`: `%` e `_` digitados pela pessoa são texto, não curinga.
     *
     * @param  array<int, array{kinds: list<string>, texto: string}>  $panorama
     * @return list<int>
     */
    public function idsComBusca(array $panorama, string $termo): array
    {
        $alvo = $this->normalizarParaBusca($termo);

        if ($alvo === '') {
            return array_map('intval', array_keys($panorama));
        }

        $ids = [];

        foreach ($panorama as $id => $linha) {
            if (str_contains($linha['texto'] ?? '', $alvo)) {
                $ids[] = (int) $id;
            }
        }

        return $ids;
    }

    /**
     * Os ids das ofertas que casam QUALQUER um dos motivos pedidos.
     *
     * ⚠️ É `some` (`:1211`: `S.block.some(k => o.inf.kinds.includes(k))`): dois motivos marcados é
     * **OU** entre eles. ⚠️ E a composição com os OUTROS filtros continua sendo **E** — são dois
     * níveis, e colapsá-los num só devolveria fila errada nas duas direções.
     *
     * @param  array<int, array{kinds: list<string>, texto: string}>  $panorama
     * @param  list<string>  $motivos
     * @return list<int>
     */
    public function idsComMotivo(array $panorama, array $motivos): array
    {
        $ids = [];

        foreach ($panorama as $id => $linha) {
            foreach ($motivos as $motivo) {
                if (in_array($motivo, $linha['kinds'] ?? [], true)) {
                    $ids[] = (int) $id;
                    break;
                }
            }
        }

        return $ids;
    }

    // ─────────────────────────────────────────────────────────────────────────────────────────
    // 4. Os saneadores e a normalização.
    // ─────────────────────────────────────────────────────────────────────────────────────────

    /**
     * O termo de busca saneado: string não-vazia, ou `''`.
     *
     * ⚠️ `is_string` ANTES de `trim`, e NUNCA `(string) $valor`: `?busca[]=x` entrega ARRAY e
     * `(string) []` emite `Array to string conversion`, que vira **500** (T-180-04-T).
     */
    private function textoSaneado(mixed $cru): string
    {
        return is_string($cru) ? trim($cru) : '';
    }

    /**
     * O valor se ele está na lista FECHADA; o default no resto. Descarte em SILÊNCIO.
     *
     * @param  list<string>  $permitidos
     */
    private function daLista(mixed $cru, array $permitidos, string $default): string
    {
        if (! is_string($cru)) {
            return $default;
        }

        $chave = trim($cru);

        return in_array($chave, $permitidos, true) ? $chave : $default;
    }

    /**
     * Os inteiros da querystring que estão na lista FECHADA, sem repetição e em ordem.
     *
     * ⚠️ `?responsavel=abc` é descartado em silêncio e **não zera a lista** — ele cai no default
     * `[]`, que é "sem filtro". ⚠️ E id que a conta não tem também cai fora: a interseção com o
     * banco é a lista fechada deste filtro.
     *
     * @param  list<int>  $permitidos
     * @return list<int>
     */
    private function inteirosDaLista(mixed $cru, array $permitidos): array
    {
        if (! is_string($cru) || $permitidos === []) {
            return [];
        }

        $escolhidos = [];

        foreach (explode(',', $cru) as $parte) {
            $parte = trim($parte);

            // ⚠️ `ctype_digit` e NUNCA `(int) $parte`: `(int) 'abc'` é **0**, e um id 0 passaria a
            // "existir" se algum dia houvesse linha com esse id.
            if ($parte === '' || ! ctype_digit($parte)) {
                continue;
            }

            $id = (int) $parte;

            if (in_array($id, $permitidos, true) && ! in_array($id, $escolhidos, true)) {
                $escolhidos[] = $id;
            }
        }

        return $escolhidos;
    }

    /**
     * As chaves da querystring que estão na lista FECHADA, sem repetição e **na ordem do mapa**.
     *
     * ⚠️ A ordem é a da lista fechada, não a da querystring: é ela que o popover desenha, e a
     * ordem de `BLK` é parte da especificação (ver o docblock de `BLOQUEIOS_EM_ORDEM`).
     *
     * @param  list<string>  $permitidos
     * @return list<string>
     */
    private function textosDaLista(mixed $cru, array $permitidos): array
    {
        if (! is_string($cru)) {
            return [];
        }

        $pedidos = array_map('trim', explode(',', $cru));

        return array_values(array_filter($permitidos, fn (string $chave) => in_array($chave, $pedidos, true)));
    }

    /** O texto sem acento, pelo mapa — sem rebaixar caixa (quem rebaixa é quem chama). */
    public function semAcento(string $texto): string
    {
        return str_replace(array_keys(self::SEM_ACENTO), array_values(self::SEM_ACENTO), $texto);
    }

    /**
     * ⚠️⚠️ **A NORMALIZAÇÃO DA BUSCA — A ÚNICA, e ela vale para o TERMO e para o CONTEÚDO.**
     *
     * É o `norm()` do protótipo (`:1052`: `normalize('NFD')` + tira as combinantes + `toLowerCase`),
     * reproduzido pelo mapa + `mb_strtolower`.
     *
     * ⚠️ **Acento ANTES da caixa**, nesta ordem: depois do mapa não sobra letra latina acentuada,
     * então o rebaixamento é trivial. Inverter funcionaria igual aqui, mas a ordem está fixada para
     * que não haja duas respostas possíveis à mesma pergunta.
     *
     * ⚠️ **`mb_strtolower` é seguro porque os DOIS lados são PHP.** Era justamente o que NÃO se
     * podia fazer na estratégia (b): lá a coluna era rebaixada pelo banco, e o `LOWER()` do MySQL
     * rebaixa multibyte enquanto o do SQLite **só** rebaixa ASCII — o par divergiria entre os
     * bancos, e a busca funcionaria local e falharia em produção.
     *
     * ⚠️ `trim` no fim: espaço de borda num termo colado da planilha não pode mudar o resultado.
     */
    public function normalizarParaBusca(string $texto): string
    {
        return trim(mb_strtolower($this->semAcento($texto)));
    }

}
