<?php

namespace App\Services\Publicador\Aprovacao\Fila;

use App\Models\PubAprovacaoComposicao;
use App\Models\PubAprovacaoExcecao;
use App\Models\PubAprovacaoOferta;
use App\Models\EstruturaAnuncio;
use App\Models\EstruturaPublicacao;
use App\Services\Publicador\Aprovacao\BloqueiosDaOfertaService;
use App\Services\Publicador\Aprovacao\ComposicaoDaOferta;
use App\Services\Publicador\Aprovacao\ComposicaoFinanceiraService;
use App\Services\Publicador\Aprovacao\VocabularioDoPortao;

/**
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 * AS 11 CÉLULAS DE UMA OFERTA, RESOLVIDAS NO SERVIDOR (Fase 187, plano 08).
 *
 * É a linha que o operador lê para decidir se abre a revisão. **Cada célula aqui é uma afirmação
 * sobre dinheiro ou sobre foto — e aprovar JÁ PUBLICA, sem desfazer.** Célula que mente é pior que
 * célula ausente.
 *
 * ═══ ⚠️⚠️ O QUE É DECIDIDO AQUI, E O QUE É DECIDIDO NO JSX — E O PORQUÊ É MEDIDO ══════════════
 *
 * O plano manda *"todas as decisões de rótulo e de cor resolvidas AQUI; o JSX só desenha"*, e é o
 * que acontece — com **uma fronteira exata**: este serviço decide **QUAL CASO** é (`vermelho`,
 * `ambar`, `verde`…), e o `.jsx` traduz o caso em **NOME DE TOKEN**.
 *
 * ⚠️⚠️ **Mandar a classe pronta daqui SERIA UM BUG INVISÍVEL, e o motivo é medido:** o `content` do
 * `tailwind.config.js` é `['./resources/js/**\/*.jsx', …]` — ele **não varre `.php`**. Uma classe
 * que existisse **só** neste arquivo **não geraria CSS** e **não existiria para a tela**, com todo
 * teste de render passando. É exatamente a armadilha do `*.js` que o projeto já mediu (GATE-08),
 * com outra roupa. ➡️ Os nomes de token moram no `.jsx`, e há gate exigindo que eles apareçam lá.
 *
 * ⚠️ E o inverso também é gate: **nenhum HEX sai daqui** (TOK-15). A cor é uma CHAVE SEMÂNTICA.
 *
 * ═══ ⚠️⚠️ VOC-03 / VOC-04 — "LUCRO LÍQUIDO" É PROIBIDO ════════════════════════════════════════
 *
 * O vocabulário travado é **"Resultado estimado por venda"** e **"Margem de contribuição
 * estimada"** ({@see PubAprovacaoComposicao::ROTULO_RESULTADO} / `::ROTULO_MARGEM`, **LIDOS** do
 * modelo). O cabeçalho da coluna é **"Resultado/venda"**, literal do grid. Chamar aquele número de
 * *lucro líquido* seria afirmação **FALSA**: ele não desconta imposto sobre o lucro, taxa de cartão,
 * despesa fixa nem devolução. ⚠️ O gate de ausência é **recortado à lista CONSTANTE dos arquivos
 * deste plano** — `lucro_liquido` é campo **legítimo** do Portal, e varrer o `app/` inteiro
 * reprovaria trabalho de outra sessão (foi a 5ª ocorrência de "gate mais largo que o invariante" na
 * Fase 186, e ela **barrou um deploy**).
 *
 * ═══ ⚠️⚠️ A EMBALAGEM É A EXCEÇÃO DELIBERADA E ÚNICA (decisões 7 e 10, 14/10/2026) ════════════
 *
 * `PubAprovacaoComposicao::ANULAM_O_RESULTADO` são **cinco** (`custo`, `comissao`, `tarifa_fixa`,
 * `frete`, `imposto`). **"Embalagem" NÃO está na lista**: ela nasce `Pendente` **VISÍVEL** e **não
 * anula** o resultado — o resultado sai **estimado por baixo**
 * ({@see ComposicaoDaOferta::$estimadoPorBaixo}), e **`prejuízo` e `margem` DISPARAM sobre ele**.
 * "Outros custos" nasce `R$ 0,00 Confirmado`.
 * ➡️ Aqui isso significa: embalagem pendente **não** produz `Incompleto`; frete ausente **produz**.
 * Há caso com as duas pontas no MESMO cenário — é a isca que as separa.
 *
 * ═══ ⚠️ D4 / decisão 9 — CADA MARGEM DIZ DE ONDE VEM ═════════════════════════════════════════
 *
 * O portão usa a **tarifa real do Mercado Livre** (`sale_fee_amount`, via
 * {@see ComposicaoFinanceiraService}); a visão rápida do lote usa o parâmetro da Precificação
 * (`Fila/ResumoRapidoService`, **SOMENTE LEITURA**, *"diferente DE PROPÓSITO… não unificar"*).
 * ➡️ A célula Margem carrega a fonte no `title`: {@see self::FONTE_DA_MARGEM}. **Não unificar os
 * dois números.**
 *
 * ═══ ⚠️⚠️ O PIOR CASO É BALÃO, NÃO FRASE VISÍVEL (decisão do usuário, 15/10/2026) ═════════════
 *
 * A decisão 11 exige que a tela **nomeie a variação**, e o protótipo dá à coluna Preço **92px e UMA
 * LINHA** (`:156`), **sem** sub-linha. ➡️ A célula fica com **uma** linha, fiel ao protótipo, e o
 * `title` nomeia: {@see self::PIOR_CASO_PREFIXO}. A **frase visível** é da **Fase 188**, onde há
 * espaço. ⚠️ Com **um** preço só, `variante` é `null` e **não há nota nenhuma**
 * ({@see ComposicaoFinanceiraService::precoDoAlvo()} já devolve `variante: null` com `ativos === 1`).
 * ⚠️ **Isto NÃO é pendência** — é decisão aplicada, e o gate cobra as duas coisas.
 *
 * ═══ ⚠️ CUSTO: UMA CHAMADA A `para()` POR OFERTA, FORA DE QUALQUER LAÇO ═══════════════════════
 *
 * `BloqueiosDaOfertaService::para()` lê a composição, que consulta `/sites/MLB/listing_prices`
 * (cache de 1 h por categoria+tipo+preço). Há gate com espião exigindo **exatamente uma** chamada
 * por oferta, e no máximo **uma requisição por trio `(categoria, tipo, preço)` distinto**, com isca
 * nas duas direções. ⚠️ O pré-aquecimento fora do request é o plano **187-10**: ele muda **QUANDO**
 * a requisição acontece, **não QUANTAS** — este gate continua valendo sem afrouxamento.
 *
 * ⚠️ **Nada aqui é escrito**, e esta tela **APROVA (e aprovar ENFILEIRA); ela NÃO publica** — a
 * escrita no Mercado Livre é da **Fase 193**.
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 */
class LinhaDaOfertaService
{
    // ─── Os 4 estados do anel de progresso (`:1229`/`:1241`) ────────────────────────────────

    public const PROGRESSO_NAO_ANALISADA = 'nao_analisada';

    public const PROGRESSO_PARCIAL = 'parcial';

    public const PROGRESSO_APROVADA = 'aprovada';

    public const PROGRESSO_FALHA = 'falha';

    /**
     * ⚠️ As CORES são CHAVES SEMÂNTICAS, nunca hex nem classe — ver o docblock da classe.
     *
     * A tradução para token vive no `.jsx` (há gate), e cada chave abaixo traz o hex do protótipo
     * no comentário **só para conferência**.
     */
    public const COR_VERMELHA = 'vermelho';      // #F87171 → `pub-danger-txt`

    public const COR_AMBAR = 'ambar';            // #FBBF24 → `pub-warn-txt`

    public const COR_VERDE = 'verde';            // #4ADE80 → `pub-ok-txt`

    public const COR_TEXTO = 'texto';            // #F4F4F5 → `pub-txt`

    public const COR_NEUTRA = 'neutro';          // #71717A → `pub-txt-4`

    public const COR_APAGADA = 'apagada';        // #8A8A90 → `pub-txt-3`

    public const COR_SUAVE = 'suave';            // #A1A1AA → `pub-txt-2`

    public const COR_DESTAQUE = 'destaque';      // #FACC15 → `pub-accent`

    public const COR_TRILHA = 'trilha';          // #3F3F46 → `pub-line-off`

    public const COR_BARRA_BAIXA = 'barra_baixa'; // #EF4444 → `pub-danger`

    public const COR_BARRA_ALERTA = 'barra_alerta'; // #F59E0B → `pub-warn`

    // ─── As 4 paletas da ação (`:1240`) ─────────────────────────────────────────────────────

    public const PALETA_DESTAQUE = 'destaque';   // #FACC15 / #0A0A0A / #FACC15

    public const PALETA_VER = 'ver';             // #111111 / #D4D4D8 / #2E2E2E

    public const PALETA_AGUARDE = 'aguarde';     // #111111 / #52525B / #1F1F1F

    public const PALETA_NEUTRA = 'neutro';       // #161616 / #F4F4F5 / #2E2E2E

    // ─── Os 5 fundos da linha (`:1250`) ─────────────────────────────────────────────────────

    public const FUNDO_ABERTA = 'aberta';        // #221D07 → `pub-row-sel`

    public const FUNDO_FOCO = 'foco';            // #1A1A1A → `pub-hover-3`

    public const FUNDO_MARCADA = 'marcada';      // #18160C → `pub-row-checked`

    public const FUNDO_PADRAO = 'padrao';        // #111111 → `pub-surface`

    // ─── Os literais que NÃO estão no vocabulário da Fase 186, e nascem AQUI ────────────────
    //
    // ⚠️ O gate os cobra **byte a byte**, com os separadores `·` (U+00B7) corretos. Os que JÁ
    // existem na 186 (`TUDO_CONFERIDO`, `BLOQUEADO_PREFIXO`, `INCOMPLETO`, as 9 abreviações e o
    // mapa `CTA`) são **CHAMADOS**, nunca recopiados.

    /** `:1233` — a falha preserva o que já foi revisado. */
    public const FALHA_SUB = 'Revisão preservada · pode tentar de novo';

    /** `:1234` — o prefixo; o nome de quem aprovou vem emendado. */
    public const APROVADO_SUB_PREFIXO = 'aguardando publicação · ';

    /** `:1235` — está subindo ao ML agora. */
    public const PUBLICANDO_SUB = 'enviando ao Mercado Livre';

    /** `:1236` — publicado sem `ml_item_id` legível ainda. */
    public const PUBLICADO_SUB_PADRAO = 'confirmado';

    /** `:1239` — nada bloqueia. */
    public const PRONTO_SUB = 'Pronto para aprovar';

    /** `:1244` — o resumo das fotos quando nada pende. */
    public const FOTOS_TODAS_APROVADAS = 'Todas aprovadas';

    /** `:1245` — a sub-linha do resultado quando falta dado. */
    public const RESULTADO_SUB_FALTANDO = 'frete pendente';

    /** `:1245` — a sub-linha do resultado negativo. */
    public const RESULTADO_SUB_PREJUIZO = 'prejuízo';

    /** `:1245` — a sub-linha do resultado positivo. */
    public const RESULTADO_SUB_ESTIMADO = 'estimado';

    /** `:1246` — a margem sem número: travessão, nunca zero. */
    public const MARGEM_SEM_NUMERO = '—';

    /** `:1243` — o sufixo do formato. */
    public const UNIDADE_SUFIXO = ' · 1 un.';

    /** `:1242` — o selo de exceção vigente, em caixa alta. */
    public const EXCECAO = 'EXCEÇÃO';

    /** D4 / decisão 9 — a célula Margem diz DE ONDE o número vem. */
    public const FONTE_DA_MARGEM = 'com a tarifa real do Mercado Livre';

    /** Decisão 11 — o balão do pior caso. A variação vem emendada depois do `·`. */
    public const PIOR_CASO_PREFIXO = 'pior caso entre ';

    public const PIOR_CASO_SUFIXO = ' variações · ';

    /** `:1246` — o teto da barra de margem, em pontos percentuais. */
    public const TETO_DA_BARRA = 40.0;

    /** `:1247` — a fronteira do âmbar na espera: **ESTRITAMENTE maior** que 1440 min (24 h). */
    public const ESPERA_AMBAR_ACIMA_DE = 1440;

    public function __construct(
        private BloqueiosDaOfertaService $bloqueios,
        private ComposicaoFinanceiraService $financeira,
        private GaleriaDaOfertaService $galeria,
        private ProximoARevisarService $proximo,
    ) {}

    /**
     * As 11 células de UMA oferta.
     *
     * ⚠️ `para()` é chamado **UMA** vez aqui, fora de qualquer laço (T-186-38 / T-187-83).
     *
     * @param  bool  $ehProxima  é a oferta do "PRÓXIMO A REVISAR"? (só ela tem `Revisar` AMARELO)
     * @param  array{aberta?: bool, foco?: bool, marcada?: bool}  $estado  o fundo da linha
     * @return array<string, mixed>
     */
    public function para(PubAprovacaoOferta $oferta, bool $ehProxima = false, array $estado = []): array
    {
        $leitura = $this->bloqueios->para($oferta);
        /** @var ComposicaoDaOferta $composicao */
        $composicao = $leitura['composicao'];

        $progresso = $this->progresso($oferta);
        $unidades = $this->unidades($oferta);
        $galeria = $this->galeria->doRascunho((int) $oferta->rascunho_id);
        $minutos = $this->esperaEmMinutos($oferta);

        return [
            'id' => (int) $oferta->id,

            // ─── 2. O anel de progresso ───
            'progresso' => $progresso,

            // ─── 3. A oferta: título + meta numa linha ───
            // ⚠️ `:1242` — peso 600 **só** quando ninguém analisou ainda. Nos outros três, 500.
            'titulo' => $this->texto($oferta->alvo?->titulo),
            'titulo_forte' => $progresso === self::PROGRESSO_NAO_ANALISADA,
            'meta' => [
                // `:1242` — o SKU do combo ganha `-K{N}`.
                'sku' => $this->skuDaOferta($oferta, $unidades),
                'origem' => $this->origem($oferta),
                'versao' => 'v'.max(1, (int) $oferta->versao),
                // ⚠️ `PubAprovacaoExcecao::vigentesDa()` — `ativa` é o ÚNICO filtro de vigência.
                'excecao' => PubAprovacaoExcecao::vigentesDa((int) $oferta->id)->exists(),
                'excecao_rotulo' => self::EXCECAO,
            ],

            // ─── 4. A modalidade e o formato ───
            'modalidade' => $this->modalidade($oferta, $unidades),

            // ─── 5. As fotos ───
            'fotos' => $this->fotos($galeria),

            // ─── 6. O preço: UMA linha, e o pior caso no BALÃO ───
            'preco' => $this->preco($oferta),

            // ─── 7. O resultado ───
            'resultado' => $this->resultado($composicao),

            // ─── 8. A margem ───
            'margem' => $this->margem($composicao),

            // ─── 9. O responsável ───
            'responsavel' => $this->responsavel($oferta, $minutos),

            // ─── 10. O andamento ───
            'andamento' => [
                'chave' => (string) $oferta->andamento,
                'sub' => $this->subDoAndamento($oferta, $leitura),
                'sub_cor' => $this->corDoSubDoAndamento($oferta, $leitura),
            ],

            // ─── 11. A ação ───
            'acao' => $this->acao($oferta, $ehProxima),

            // ⚠️ `:1252` — `preparando` **NÃO** abre a linha. Ver `self::abre()`.
            'abre' => $this->abre($oferta),
            'fundo' => $this->fundo($estado),
        ];
    }

    /**
     * O estado do anel (`:1229`), literal:
     *
     *     ['approved','publishing','published'].includes(st) ? 'done'
     *       : st==='failed' ? 'fail'
     *       : (touched || st!=='awaiting') && st!=='preparing' ? 'partial'
     *       : 'new'
     *
     * ⚠️⚠️ **`preparando` cai em `nao_analisada`, NÃO em `parcial`** — é o `&& st!=='preparing'`
     * do protótipo, e é contraintuitivo: uma oferta que o sistema ainda está montando não tem
     * "revisão parcial salva", porque ninguém a tocou. Há isca no gate.
     */
    public function progresso(PubAprovacaoOferta $oferta): string
    {
        $andamento = (string) $oferta->andamento;

        if (in_array($andamento, [
            PubAprovacaoOferta::APROVADO,
            PubAprovacaoOferta::PUBLICANDO,
            PubAprovacaoOferta::PUBLICADO,
        ], true)) {
            return self::PROGRESSO_APROVADA;
        }

        if ($andamento === PubAprovacaoOferta::FALHA) {
            return self::PROGRESSO_FALHA;
        }

        $tocadoOuMovido = $oferta->tocado === true || $andamento !== PubAprovacaoOferta::AGUARDANDO_APROVACAO;

        if ($tocadoOuMovido && $andamento !== PubAprovacaoOferta::PREPARANDO) {
            return self::PROGRESSO_PARCIAL;
        }

        return self::PROGRESSO_NAO_ANALISADA;
    }

    /**
     * `:1252` — a linha abre o painel? **`preparando` não abre.**
     *
     * ⚠️ **NÃO é `abreRevisao()`**, e a diferença é medida: `PubAprovacaoOferta::abreRevisao()` é
     * `ehLive()` (só os três ao vivo), porque ela responde *"a revisão é EDITÁVEL?"*. O protótipo
     * abre o painel para **tudo menos `preparing`** — `aprovado`/`publicado`/`falha` abrem em
     * LEITURA. Confundir as duas esconderia o histórico de uma oferta já publicada.
     */
    public function abre(PubAprovacaoOferta $oferta): bool
    {
        return $oferta->andamento !== PubAprovacaoOferta::PREPARANDO;
    }

    /**
     * A sub-linha do andamento — a cadeia de **SETE** casos de `:1232`-`:1239`, na ordem.
     *
     * ⚠️ Os literais que a Fase 186 **já tem** são CHAMADOS: `resumoDoRodape()` monta
     * *"Bloqueado: {abreviação} +N"* com o `+N` contando **os DEMAIS** (com 2 bloqueios a linha é
     * "… +1"). Recopiar aquela montagem criaria duas verdades.
     *
     * @param  array<string, mixed>  $leitura  o retorno de `BloqueiosDaOfertaService::para()`
     */
    public function subDoAndamento(PubAprovacaoOferta $oferta, array $leitura): ?string
    {
        $andamento = (string) $oferta->andamento;

        if ($andamento === PubAprovacaoOferta::FALHA) {
            return self::FALHA_SUB;
        }

        if ($andamento === PubAprovacaoOferta::APROVADO) {
            $quem = $this->texto($oferta->aprovadoPor?->name);

            // ⚠️ Sem nome não se inventa responsável: sai só o prefixo aparado.
            return $quem === null ? rtrim(self::APROVADO_SUB_PREFIXO, ' ·') : self::APROVADO_SUB_PREFIXO.$quem;
        }

        if ($andamento === PubAprovacaoOferta::PUBLICANDO) {
            return self::PUBLICANDO_SUB;
        }

        if ($andamento === PubAprovacaoOferta::PUBLICADO) {
            return $this->texto($oferta->ml_item_id) ?? self::PUBLICADO_SUB_PADRAO;
        }

        if ($andamento === PubAprovacaoOferta::PREPARANDO) {
            // ⚠️ A frase REAL do que falta (AUTO-01), nunca um rótulo genérico. Sem ela, nada.
            return $this->texto($oferta->preparo_frase);
        }

        $bloqueios = is_array($leitura['bloqueios'] ?? null) ? $leitura['bloqueios'] : [];

        if ($bloqueios !== []) {
            // ⚠️ CHAMADO, nunca recopiado — o `+N` conta os DEMAIS.
            return $this->bloqueios->resumoDoRodape($bloqueios);
        }

        return self::PRONTO_SUB;
    }

    /**
     * A cor da sub-linha (`:1238`), nos QUATRO casos.
     *
     * ⚠️ Vermelha quando o PRIMEIRO bloqueio é `resultado_negativo | frete_ausente | sem_fotos`
     * **ou** há alerta em foto; âmbar nos outros bloqueios; verde em "Pronto para aprovar" e em
     * `publicado`; vermelha em `falha`.
     *
     * ⚠️ A lista dos vermelhos é **LIDA** de {@see VocabularioDoPortao::VERMELHOS}, nunca escrita
     * à mão: ela é a mesma de `severidade()`, e duas cópias divergiriam.
     *
     * @param  array<string, mixed>  $leitura
     */
    public function corDoSubDoAndamento(PubAprovacaoOferta $oferta, array $leitura): string
    {
        $andamento = (string) $oferta->andamento;

        if ($andamento === PubAprovacaoOferta::FALHA) {
            return self::COR_VERMELHA;
        }

        if ($andamento === PubAprovacaoOferta::PUBLICADO) {
            return self::COR_VERDE;
        }

        if (in_array($andamento, [
            PubAprovacaoOferta::APROVADO,
            PubAprovacaoOferta::PUBLICANDO,
            PubAprovacaoOferta::PREPARANDO,
        ], true)) {
            return self::COR_APAGADA;
        }

        $bloqueios = is_array($leitura['bloqueios'] ?? null) ? $leitura['bloqueios'] : [];

        if ($bloqueios === []) {
            return self::COR_VERDE;
        }

        $comAlerta = ((int) ($leitura['com_alerta'] ?? 0)) > 0;
        $primeiro = (string) ($bloqueios[0]['chave'] ?? '');

        // ⚠️ A severidade do vocabulário já resolve o par (chave, temAlerta) — CHAMADA, não
        // reimplementada. `fotos_a_conferir` é vermelha só COM alerta, e é ela que o segundo
        // argumento cobre.
        return VocabularioDoPortao::severidade($primeiro, $comAlerta) === VocabularioDoPortao::SEVERIDADE_VERMELHA
            || $comAlerta
            ? self::COR_VERMELHA
            : self::COR_AMBAR;
    }

    /**
     * A ação (`:1240`): o rótulo vem do mapa `CTA` do **MODELO** e a paleta, do estado.
     *
     * ⚠️ `PubAprovacaoOferta::cta()` **já é** o mapa dos 8 estados, com fallback em
     * `CTA_NEUTRO`. **Chamado, nunca recopiado** — e o gate espelha lendo o modelo.
     *
     * ⚠️⚠️ `Revisar` é **AMARELO só na oferta PRÓXIMA** (`isNext`, `:1240`). Pintar todas de
     * amarelo apagaria o sinal de "comece por esta" — é o T-187-85, e há mutação provando.
     */
    public function acao(PubAprovacaoOferta $oferta, bool $ehProxima): array
    {
        $andamento = (string) $oferta->andamento;
        $rotulo = $oferta->cta();

        if ($andamento === PubAprovacaoOferta::FALHA) {
            return ['rotulo' => $rotulo, 'paleta' => self::PALETA_DESTAQUE];
        }

        if (in_array($andamento, [
            PubAprovacaoOferta::APROVADO,
            PubAprovacaoOferta::PUBLICADO,
            PubAprovacaoOferta::PUBLICANDO,
        ], true)) {
            return ['rotulo' => $rotulo, 'paleta' => self::PALETA_VER];
        }

        if ($andamento === PubAprovacaoOferta::PREPARANDO) {
            return ['rotulo' => $rotulo, 'paleta' => self::PALETA_AGUARDE];
        }

        return ['rotulo' => $rotulo, 'paleta' => $ehProxima ? self::PALETA_DESTAQUE : self::PALETA_NEUTRA];
    }

    /**
     * O resultado (`:1245`): `Incompleto` em âmbar, negativo em vermelho com "prejuízo", positivo
     * em texto com "estimado".
     *
     * ⚠️ `Incompleto` é **LIDO** de {@see VocabularioDoPortao::INCOMPLETO} — *"custo ou frete
     * ausente não vira zero"* (README §D), porque zero afirmaria que é de graça.
     *
     * ⚠️⚠️ **A EMBALAGEM NÃO ENTRA AQUI.** `incompleto` é `true` só pelas CINCO de
     * `ANULAM_O_RESULTADO`; embalagem pendente marca `estimadoPorBaixo` e o resultado **existe** —
     * então `prejuízo`/`margem` **disparam** sobre ele. Ver o docblock da classe.
     */
    public function resultado(ComposicaoDaOferta $composicao): array
    {
        if ($composicao->incompleto) {
            return [
                'valor' => VocabularioDoPortao::INCOMPLETO,
                'cor' => self::COR_AMBAR,
                'sub' => self::RESULTADO_SUB_FALTANDO,
                'sub_cor' => self::COR_AMBAR,
                'rotulo_longo' => PubAprovacaoComposicao::ROTULO_RESULTADO,
            ];
        }

        $valor = (float) $composicao->resultado;
        $negativo = $valor < 0;

        return [
            'valor' => self::brl($valor),
            'cor' => $negativo ? self::COR_VERMELHA : self::COR_TEXTO,
            'sub' => $negativo ? self::RESULTADO_SUB_PREJUIZO : self::RESULTADO_SUB_ESTIMADO,
            'sub_cor' => $negativo ? self::COR_VERMELHA : self::COR_NEUTRA,
            'rotulo_longo' => PubAprovacaoComposicao::ROTULO_RESULTADO,
        ];
    }

    /**
     * A margem (`:1246`), com a barra de 0 a 40% e o traço do mínimo.
     *
     * ⚠️⚠️ **DIVERGÊNCIA D10 — É PRESCRIÇÃO DE FÓRMULA, NÃO DE NÚMERO.** O protótipo escreve
     * `left:30%` (`:163`) **porque `MIN = 12` e `12/40 = 30%`** (`:914`). ➡️ O traço vai em
     * `minimo/40`, que dá **exatamente 30% com 12** e continua certo com outro mínimo — e o mínimo
     * vem do parâmetro/config (FIN-09), **nunca de literal**.
     *
     * ⚠️ A largura é `min(40, max(0, m))/40` — **CLAMPADA** nas duas pontas: margem negativa não
     * desenha barra para a esquerda, e 95% não estoura a trilha.
     *
     * ⚠️ **A COR NUNCA É O ÚNICO SINAL:** o percentual vai escrito ao lado, e `null` sai como
     * `—` (nunca `0%`, que afirmaria margem zero).
     */
    public function margem(ComposicaoDaOferta $composicao): array
    {
        $minimo = (float) $composicao->margemMinima;
        $margem = $composicao->incompleto ? null : $composicao->margem;

        return [
            'valor' => $margem === null ? self::MARGEM_SEM_NUMERO : self::pct((float) $margem),
            'cor' => $this->corDaMargem($margem, $minimo),
            'largura' => self::percentualDaBarra($margem),
            'barra' => $this->corDaBarra($margem, $minimo),
            // ⚠️ D10: a FÓRMULA, não o `30%` literal.
            'minimo' => self::percentualDaBarra($minimo),
            'minimo_valor' => $minimo,
            'rotulo_longo' => PubAprovacaoComposicao::ROTULO_MARGEM,
            // ⚠️ D4 / decisão 9 — cada margem diz de onde vem.
            'fonte' => self::FONTE_DA_MARGEM,
        ];
    }

    /**
     * O preço (`:1245`/`:156`): **UMA** linha, com o pior caso no BALÃO.
     *
     * ⚠️ `precoDoAlvo()` devolve o **MENOR** preço ativo (o pior caso). Com `ativos > 1` a
     * `variante` vem nomeada e o `title` a nomeia; com **um** só, `variante` é `null` e **não há
     * nota nenhuma**. Ver o docblock da classe (decisão 11, 15/10/2026).
     */
    public function preco(PubAprovacaoOferta $oferta): array
    {
        $doAlvo = $this->financeira->precoDoAlvo($oferta);
        $ativos = (int) ($doAlvo['ativos'] ?? 0);
        $variante = $this->texto($doAlvo['variante'] ?? null);

        // ⚠️⚠️ **`<= 0` É AUSENTE, NÃO ZERO — e isto é correção MEDIDA nesta sessão.** A primeira
        // redação testava só `=== null`, e `precoDoAlvo()` devolve **`0.0`** (não `null`) quando a
        // Precificação traz zero (`ComposicaoFinanceiraService::precoDoAlvo()`, o ramo do
        // `$daPrecificacao`). O resultado era a célula exibindo **`R$ 0,00`**, que afirma que o
        // produto é **de graça** — exatamente o que o README §D proíbe (*"custo ou frete ausente
        // não vira zero"*).
        // ➡️ A régua é a MESMA que a própria Fase 186 usa: `ComposicaoFinanceiraService:228` trata
        // `$preco === null || $preco <= 0` como *"sem preço não há composição nenhuma"*. Copiar só
        // metade dessa condição faria a célula de Preço discordar da composição que está ao lado.
        $preco = $doAlvo['preco'];
        $semPreco = $preco === null || (float) $preco <= 0.0;

        return [
            'valor' => $semPreco ? self::MARGEM_SEM_NUMERO : self::brl((float) $preco),
            // ⚠️ `null` quando NÃO há nota: o componente não desenha `title` vazio.
            'titulo' => $ativos > 1 && $variante !== null
                ? self::PIOR_CASO_PREFIXO.$ativos.self::PIOR_CASO_SUFIXO.$variante
                : null,
            'ativos' => $ativos,
        ];
    }

    /** As fotos (`:1244`): `N fotos · M IA` + o resumo colorido, LIDOS da galeria. */
    private function fotos(array $galeria): array
    {
        $total = (int) $galeria['total'];
        $pendentes = (int) $galeria['pendentes'];
        $comAlerta = (int) $galeria['com_alerta'];

        if ($comAlerta > 0) {
            $sub = $comAlerta.' com alerta';
            $cor = self::COR_VERMELHA;
        } elseif ($pendentes > 0) {
            $sub = $pendentes.' a conferir';
            $cor = self::COR_AMBAR;
        } else {
            $sub = self::FOTOS_TODAS_APROVADAS;
            $cor = self::COR_VERDE;
        }

        return [
            'resumo' => $total.' fotos · '.(int) $galeria['de_ia'].' IA',
            'quadrados' => $galeria['imagens'],
            'sub' => $sub,
            'sub_cor' => $cor,
        ];
    }

    /** A modalidade e o formato (`:1243`). "Clássico"/"Premium" têm UMA casa, e não é esta. */
    private function modalidade(PubAprovacaoOferta $oferta, int $unidades): array
    {
        $listingType = $oferta->alvo?->listing_type_id;
        $rotulo = null;

        if (is_string($listingType) && $listingType !== '') {
            $tipo = array_search($listingType, EstruturaPublicacao::LISTING_TYPES, true);
            $rotulo = $tipo === false ? null : (EstruturaAnuncio::TIPOS[$tipo] ?? null);
        }

        return [
            'rotulo' => $rotulo,
            // ⚠️ O losango (`:148`) é só do Premium, e a comparação é pelas DUAS constantes —
            // `'gold_pro'` escrito à mão aqui seria uma terceira cópia daquele literal.
            'premium' => $listingType === (EstruturaPublicacao::LISTING_TYPES[EstruturaAnuncio::TIPO_PREMIUM] ?? null),
            'formato' => $unidades > 1
                ? GrupoDaFilaService::PREFIXO_COMBO.$unidades.' · '.$unidades.' un.'
                : GrupoDaFilaService::UNIDADE.self::UNIDADE_SUFIXO,
        ];
    }

    /** O responsável (`:1247`): o nome e a espera, com o âmbar acima de 24 h. */
    private function responsavel(PubAprovacaoOferta $oferta, ?int $minutos): array
    {
        return [
            // ⚠️⚠️ **O `id` VAI, e isso é DESVIO DECLARADO (Regra 1) desta sessão.** O avatar de
            // 24px (`:166`) tem o tom computado por `tomDoProduto()` — a `tone(h)` do protótipo,
            // fonte ÚNICA por IDENT-08 —, e o popover "Responsável" (`PopoverDeFiltro:276`) já a
            // chama com a **CHAVE**, que é `String(id)` (`FiltrosDaFila:279`). Mandar só o `nome`
            // obrigaria a linha a derivar o tom do NOME, e **a mesma pessoa sairia de uma cor na
            // linha e de outra no filtro** — duas afirmações diferentes sobre quem é quem, na
            // mesma tela. ⚠️ As INICIAIS continuam sendo derivadas no `.jsx` por `iniciais()`:
            // cunhá-las no PHP foi medido como segunda implementação divergente na wave 6
            // (*"Ana de Souza Lima"* dava **AL** no PHP e **AS** no módulo).
            'id' => $oferta->responsavel?->id === null ? null : (int) $oferta->responsavel->id,
            'nome' => $this->texto($oferta->responsavel?->name),
            // ⚠️ A espera chega FORMATADA do servidor, pela fórmula PÚBLICA do 187-05 — copiá-la
            // aqui criaria duas verdades para o mesmo número na mesma tela.
            'espera' => $minutos === null ? null : 'há '.$this->proximo->rotuloDaEspera($minutos),
            'espera_minutos' => $minutos,
            // ⚠️⚠️ **ESTRITAMENTE maior** que 1440 (`:1247`): 1440 **NÃO** é âmbar. A fronteira é
            // exata e há caso para ela.
            'espera_cor' => $minutos !== null && $minutos > self::ESPERA_AMBAR_ACIMA_DE
                ? self::COR_AMBAR
                : self::COR_NEUTRA,
        ];
    }

    /** A origem (`:1242`): `auto` → ⚡ Automática em destaque; senão ✎ Manual em suave. */
    private function origem(PubAprovacaoOferta $oferta): array
    {
        $ehAuto = $oferta->origem === PubAprovacaoOferta::ORIGEM_AUTO;

        return [
            'rotulo' => $ehAuto ? 'Automática' : 'Manual',
            'glifo' => $ehAuto ? '⚡' : '✎',
            'cor' => $ehAuto ? self::COR_DESTAQUE : self::COR_SUAVE,
        ];
    }

    /** `:1242` — o SKU do combo ganha `-K{N}`. Sem SKU, nada (nunca um `-K2` solto). */
    private function skuDaOferta(PubAprovacaoOferta $oferta, int $unidades): ?string
    {
        $sku = $this->texto($oferta->produto?->sku);

        if ($sku === null) {
            return null;
        }

        return $unidades > 1 ? $sku.'-K'.$unidades : $sku;
    }

    /**
     * Quantas unidades a oferta leva.
     *
     * ⚠️ Mesma divergência declarada de `GrupoDaFilaService::rotuloDoConjunto()` e de
     * `ProximoARevisarService::formato()`: é `quantidade_kit` e só, **não** `ehKit()`.
     */
    private function unidades(PubAprovacaoOferta $oferta): int
    {
        return max(1, (int) ($oferta->produto?->quantidade_kit ?? 1));
    }

    /** `:1246` — a cor do NÚMERO da margem. */
    private function corDaMargem(?float $margem, float $minimo): string
    {
        if ($margem === null) {
            return self::COR_NEUTRA;
        }

        if ($margem < 0) {
            return self::COR_VERMELHA;
        }

        return $margem < $minimo ? self::COR_AMBAR : self::COR_TEXTO;
    }

    /** `:1246` — a cor do PREENCHIMENTO da barra. ⚠️ São hex DIFERENTES dos do número. */
    private function corDaBarra(?float $margem, float $minimo): string
    {
        if ($margem === null) {
            return self::COR_TRILHA;
        }

        if ($margem < 0) {
            return self::COR_BARRA_BAIXA;
        }

        return $margem < $minimo ? self::COR_BARRA_ALERTA : self::COR_SUAVE;
    }

    /**
     * `:1246` / D10 — `min(40, max(0, n))/40` em porcentagem, CLAMPADA nas duas pontas.
     *
     * ⚠️ **Público e estático** porque é a MESMA fórmula da largura do preenchimento **e** da
     * posição do traço do mínimo: duas cópias divergiriam, e a barra passaria a discordar do
     * próprio traço.
     */
    public static function percentualDaBarra(?float $n): string
    {
        if ($n === null || ! is_finite($n)) {
            return '0%';
        }

        $clampado = min(self::TETO_DA_BARRA, max(0.0, $n));

        return self::numero($clampado / self::TETO_DA_BARRA * 100).'%';
    }

    /** Os minutos de espera, ou `null`. Carimbo no futuro é clampado em 0. */
    private function esperaEmMinutos(PubAprovacaoOferta $oferta): ?int
    {
        $entrou = $oferta->entrou_na_fila_em;

        if ($entrou === null) {
            return null;
        }

        return intdiv(max(0, now()->getTimestamp() - $entrou->getTimestamp()), 60);
    }

    /** `:1250` — os 5 fundos, na PRECEDÊNCIA do ternário: aberta > foco > marcada > padrão. */
    private function fundo(array $estado): string
    {
        if (($estado['aberta'] ?? false) === true) {
            return self::FUNDO_ABERTA;
        }

        if (($estado['foco'] ?? false) === true) {
            return self::FUNDO_FOCO;
        }

        if (($estado['marcada'] ?? false) === true) {
            return self::FUNDO_MARCADA;
        }

        return self::FUNDO_PADRAO;
    }

    /** Texto não-vazio, ou `null`. ⚠️ Nunca `(string) $valor`, que LANÇA com array. */
    private function texto(mixed $valor): ?string
    {
        if (! is_string($valor)) {
            return null;
        }

        $limpo = trim($valor);

        return $limpo === '' ? null : $limpo;
    }

    /**
     * `brl()` do protótipo (`:1044`), byte a byte — inclusive o **U+2212** (sinal de menos) no
     * negativo, que **não** é o hífen-menos.
     *
     * ⚠️ **QUARTA cópia privada desta forma neste módulo** (as outras três estão em
     * `BloqueiosDaOfertaService:476`, `ComposicaoFinanceiraService:701` e
     * `AprovacaoDaOfertaService:600`), e é deliberado: extrair uma única exigiria **alterar os três
     * arquivos da Fase 186**, que são território **INTOCÁVEL** nesta fase. ➡️ Há gate conferindo
     * esta cópia contra os literais medidos do protótipo, nas quatro formas (positivo, negativo,
     * zero e milhar).
     */
    private static function brl(float $n): string
    {
        return ($n < 0 ? "\u{2212} " : '').'R$ '.number_format(abs($n), 2, ',', '.');
    }

    /** `pct()` do protótipo (`:1045`): uma decimal, vírgula decimal. */
    private static function pct(float $n): string
    {
        return number_format(round($n * 10) / 10, 1, ',', '.').'%';
    }

    /** O número da largura: sem casa decimal quando é inteiro, até duas quando não. */
    private static function numero(float $n): string
    {
        $arredondado = round($n, 2);

        return $arredondado === floor($arredondado)
            ? (string) (int) $arredondado
            : rtrim(rtrim(number_format($arredondado, 2, '.', ''), '0'), '.');
    }
}
