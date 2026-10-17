<?php

namespace App\Services\Publicador\Aprovacao\Fila;

use App\Models\PubAprovacaoOferta;
use App\Models\User;
use App\Services\Publicador\Aprovacao\AprovacaoDaOfertaService;
use App\Services\Publicador\Aprovacao\BloqueiosDaOfertaService;
use App\Services\Publicador\Aprovacao\VocabularioDoPortao;

/**
 * ═══════════════════════════════════════════════════════════════════════════════════════════
 * Fase 187, plano 09 — O LOTE DA FILA: o veredicto de TODAS as selecionadas e a aprovação
 * só das aptas, cada uma na versão que o veredicto indicou.
 *
 * ⚠️⚠️ **APROVAR JÁ PUBLICA, SEM SEGUNDO CLIQUE** (decisão 3 do usuário, 14/10/2026). O
 * rótulo é **"Aprovar para publicação"**, inalterado, e **não existe** confirmação, desfazer,
 * segundo passo nem cancelamento de aprovação. É por isso que o veredicto é EXPLÍCITO antes
 * do clique: a conferência acontece ANTES, porque depois não há volta.
 *
 * ⚠️⚠️ **A FRONTEIRA, DECLARADA: nesta fase aprovar ENFILEIRA.** `aprovarEmLote()` move o
 * andamento, grava `aprovado_por`/`aprovado_em`/`versao_aprovada`, marca `tocado` e congela a
 * composição — **tudo no nosso banco**. O gancho `aoAprovar`, que é quem fala com o Mercado
 * Livre, está **VAZIO** e é da **Fase 193**. Há caso de teste provando por **ausência de
 * requisição HTTP** (`Http::preventStrayRequests()`), não por leitura de código.
 *
 * ⚠️ **E o D-11 não é esticado nem afrouxado.** `tests/js/estrutura-meus-anuncios.test.js`
 * prova que **a LISTA de Publicações nunca escreve no Mercado Livre**, e isso continua
 * verdade: aquele gate é sobre os arquivos da lista, que este plano não toca. **A FILA
 * escreve**, e esta é a primeira escrita dela — no banco, não no ML.
 *
 * ⚠️⚠️ **"APROVAR SÓ AS QUE PASSAM, SILENCIOSAMENTE" FOI RECUSADO PELO USUÁRIO.** A escolha
 * dele foi *"tudo de uma vez, e só libera com todas conferidas"*: o modal lista **TODAS** as
 * selecionadas, com `✓` nas aptas e `×` nas de fora, **e o MOTIVO de cada exclusão**. Um lote
 * que esconde quem ficou de fora manda a pessoa clicar de novo sem saber o que consertar.
 *
 * ⚠️ **COR NUNCA É O ÚNICO SINAL** (regra permanente do projeto): o glifo (`✓`/`×`) e o texto
 * do motivo carregam a informação; a cor só reforça.
 *
 * ⚠️ **UMA LEITURA DE BLOQUEIOS POR OFERTA** (T-186-38 / T-187-83): `BloqueiosDaOfertaService
 * ::para()` consulta a composição, que bate em `/sites/MLB/listing_prices`. Este serviço o
 * chama **uma vez por oferta** e reusa a composição que vem na leitura para o resultado. É a
 * classe do incidente de 30/07/2026, quando requisições lentas ocuparam os workers do php-fpm
 * e até o login parou.
 *
 * ⚠️⚠️ **NADA AQUI É RECOPIADO.** As abreviações vêm de `VocabularioDoPortao::ABREVIACOES`, os
 * rótulos de andamento de `PubAprovacaoOferta::ANDAMENTOS` (via `rotulo()`), e o dinheiro
 * formatado de `LinhaDaOfertaService::preco()`/`::resultado()` — que são os MESMOS números e
 * o MESMO `brl()` da linha da fila. Recopiar criaria duas verdades, e a divergente seria
 * descoberta por um usuário olhando dois números diferentes para o mesmo fato.
 *
 * ⚠️ **VOC-03:** o vocabulário travado é **"Resultado estimado por venda"** / **"Margem de
 * contribuição estimada"**. "Lucro líquido" é **PROIBIDO** na tela — o número não desconta
 * imposto, taxa de cartão, despesa fixa nem devolução.
 *
 * ⚠️ **AS FRASES SÃO LITERAIS MEDIDOS** em `design_handoff_publicador/Aprovacao.dc.html`
 * (1432 linhas, sha256 `68624883ce35547e`), por `grep -n` nesta sessão:
 *   · `:1423` → as linhas do modal (`note`, `glyph`, `version`, `noteColor`)
 *   · `:1425` → `title`, `sub`, `btnText`
 *   · `:1282` → `label` e `okLabel` da barra flutuante
 *   · `:885`  → a nota "Somente as ofertas marcadas com ✓ …"
 * ⚠️ **DIVERGÊNCIA DE PONTEIRO DO PLANO, REGISTRADA:** o plano 187-09 aponta a nota em
 * `:884`; ela está em **`:885`**. Medido, não presumido.
 * ═══════════════════════════════════════════════════════════════════════════════════════════
 */
class LoteDaFilaService
{
    /** `:1423` — o prefixo da frase da oferta APTA. */
    public const APTA_PREFIXO = 'Será aprovada · ';

    /** `:1423` — o que vem entre o preço e o resultado. */
    public const APTA_RESULTADO = ' · resultado ';

    /** `:1423` — o prefixo de quem fica de fora, nos DOIS caminhos. */
    public const FORA_PREFIXO = 'Fica de fora: ';

    /** `:1423` — o separador entre as abreviações dos bloqueios da inapta AO VIVO. */
    public const FORA_SEPARADOR = ', ';

    /** `:1425` — o título do modal. ⚠️ "Aprovar para publicação" aparece AQUI, inalterado. */
    public const TITULO_DO_MODAL = 'Aprovar para publicação em lote';

    /** `:1425` — o meio do sub: `K` + isto + `M` + {@see self::SUB_SUFIXO}. */
    public const SUB_MEIO = ' de ';

    /** `:1425` — o fim do sub do modal. */
    public const SUB_SUFIXO = ' ofertas selecionadas podem ser aprovadas';

    /** `:1425` — o prefixo do botão de submeter. */
    public const BOTAO_PREFIXO = 'Aprovar ';

    /** `:1425` — o sufixo PLURAL do botão (`> 1`). */
    public const BOTAO_PLURAL = ' ofertas';

    /**
     * `:1425` — o sufixo SINGULAR do botão.
     *
     * ⚠️ **MEDIDO:** o protótipo escreve `okIds.length > 1 ? ' ofertas' : ' oferta'`, então
     * **zero também sai no singular** ("Aprovar 0 oferta"). Não é `>= 1`, e não foi
     * "arredondado para o que soa melhor": é o literal.
     */
    public const BOTAO_SINGULAR = ' oferta';

    /** `:885` — a nota do modal, byte a byte. O `✓` é U+2713. */
    public const NOTA_DO_MODAL = 'Somente as ofertas marcadas com ✓ serão aprovadas, cada uma na versão indicada.';

    /** `:1282` — o sufixo PLURAL do rótulo da barra flutuante. */
    public const SELECAO_PLURAL = ' selecionadas';

    /** `:1282` — o sufixo SINGULAR do rótulo da barra (`> 1` é plural, igual ao botão). */
    public const SELECAO_SINGULAR = ' selecionada';

    /**
     * `:1282` — o rótulo do botão da barra flutuante.
     *
     * ⚠️⚠️ **ELE É "Aprovar para publicação" E NÃO MUDA.** Decisão 3 do usuário: aprovar já
     * publica. Quem trocar isto por "Confirmar publicação", "Revisar e aprovar" ou qualquer
     * segundo passo está desfazendo a decisão — e há gate de ausência COM ISCA provando que
     * nenhuma dessas formas existe nos arquivos deste plano.
     */
    public const APROVAR_PREFIXO = 'Aprovar ';

    /** `:1282` — o sufixo do botão da barra. */
    public const APROVAR_SUFIXO = ' para publicação';

    /** `:1423` — o glifo da oferta APTA. É U+2713. */
    public const GLIFO_APTA = '✓';

    /** `:1423` — o glifo de quem fica de fora. É U+00D7 (o sinal de multiplicação), não a letra x. */
    public const GLIFO_FORA = '×';

    /** `:1423` — o prefixo da versão na linha do modal (`'versão ' + o.version`). */
    public const VERSAO_PREFIXO = 'versão ';

    /**
     * `:1423` — a cor do motivo da oferta APTA (`#A1A1AA`, o `pub-txt-2`).
     *
     * ⚠️ Chave de dado, nunca hex: hex cru em payload de tela é vetado pelo GATE-03 e por
     * FID-04. Quem traduz a chave em classe é o componente.
     */
    public const COR_APTA = 'suave';

    /**
     * `:1423` — a cor do motivo de quem fica de fora (`#F87171`).
     *
     * ⚠️ É a MESMA chave que a linha da fila usa para o vermelho de texto
     * ({@see LinhaDaOfertaService::COR_VERMELHA}) — lida de lá, não escrita aqui, para não
     * haver duas palavras para a mesma cor.
     */
    public const COR_FORA = LinhaDaOfertaService::COR_VERMELHA;

    /** Quando o título do alvo não existe. ⚠️ Nunca string vazia: elemento vazio DESAPARECE na tela. */
    public const SEM_NOME = 'Oferta sem título';

    /**
     * O motivo quando não há abreviação nenhuma a mostrar, ou o andamento do banco é
     * desconhecido.
     *
     * ⚠️ **NUNCA string vazia e NUNCA a chave crua.** "Fica de fora: " sozinho é frase
     * truncada na tela, e `frete_ausente` é jargão — a regra permanente do projeto é que
     * jargão não vai para a tela. Esta frase é honesta: ela diz que há pendência **e** que o
     * sistema não soube nomeá-la, que é exatamente o que aconteceu.
     */
    public const MOTIVO_DESCONHECIDO = 'pendência em aberto não identificada';

    public function __construct(
        private BloqueiosDaOfertaService $bloqueios,
        private LinhaDaOfertaService $linha,
        private AprovacaoDaOfertaService $aprovacao,
    ) {}

    /**
     * O veredicto de **TODAS** as selecionadas — as aptas com `✓` e a frase do dinheiro, as
     * inaptas com `×` e o **MOTIVO**.
     *
     * ⚠️ **Nenhuma oferta é omitida.** Esconder quem ficou de fora foi explicitamente recusado
     * pelo usuário.
     *
     * @param  iterable<PubAprovacaoOferta>  $ofertas
     * @return list<array{id: int, nome: string, versao: int, versao_rotulo: string, apta: bool, glifo: string, motivo: string, motivo_cor: string}>
     */
    public function veredicto(iterable $ofertas): array
    {
        $linhas = [];

        foreach ($ofertas as $oferta) {
            $versao = max(1, (int) $oferta->versao);
            $nome = $this->nomeDa($oferta);

            // ⚠️⚠️ FORA DO AO VIVO: o motivo é o rótulo do andamento em MINÚSCULAS, e aqui o
            // `BloqueiosDaOfertaService` NÃO é chamado — fora dos três ao vivo não há bloqueio
            // a listar (`aprovado` já foi, `publicando`/`publicado` já saíram, `preparando`
            // ainda não existe e `falha` é retry). Chamá-lo custaria uma leitura de composição
            // para produzir uma lista que a tela não usaria.
            if (! $oferta->ehLive()) {
                $linhas[] = [
                    'id' => (int) $oferta->id,
                    'nome' => $nome,
                    'versao' => $versao,
                    'versao_rotulo' => self::VERSAO_PREFIXO.$versao,
                    'apta' => false,
                    'glifo' => self::GLIFO_FORA,
                    'motivo' => self::FORA_PREFIXO.$this->andamentoEmMinusculas($oferta),
                    'motivo_cor' => self::COR_FORA,
                ];

                continue;
            }

            // ⚠️ UMA chamada por oferta. A composição que vem nela é reusada no resultado.
            $leitura = $this->bloqueios->para($oferta);

            if ($leitura['aprovavel'] !== true) {
                $linhas[] = [
                    'id' => (int) $oferta->id,
                    'nome' => $nome,
                    'versao' => $versao,
                    'versao_rotulo' => self::VERSAO_PREFIXO.$versao,
                    'apta' => false,
                    'glifo' => self::GLIFO_FORA,
                    'motivo' => self::FORA_PREFIXO.$this->abreviacoesDos($leitura['chaves']),
                    'motivo_cor' => self::COR_FORA,
                ];

                continue;
            }

            // ⚠️ O dinheiro vem FORMATADO de quem já o formata: `preco()` e `resultado()` são
            // públicos em `LinhaDaOfertaService` e usam o `brl()` do protótipo (`:1044`, com o
            // U+2212 do negativo). Reformatá-lo aqui faria dois números para o mesmo fato.
            $preco = $this->linha->preco($oferta);
            $resultado = $this->linha->resultado($leitura['composicao']);

            $linhas[] = [
                'id' => (int) $oferta->id,
                'nome' => $nome,
                'versao' => $versao,
                'versao_rotulo' => self::VERSAO_PREFIXO.$versao,
                'apta' => true,
                'glifo' => self::GLIFO_APTA,
                'motivo' => self::APTA_PREFIXO.$preco['valor'].self::APTA_RESULTADO.$resultado['valor'],
                'motivo_cor' => self::COR_APTA,
            ];
        }

        return $linhas;
    }

    /**
     * O painel inteiro do lote: o rótulo da barra, o do botão, o título/sub do modal, as
     * linhas do veredicto e a nota.
     *
     * ⚠️ Os literais nascem **aqui**, no servidor, e a tela os **LÊ**. Montá-los no `.jsx`
     * daria duas verdades para a mesma frase — e foi assim que a Fase 186 descobriu o bug S3.
     *
     * @param  iterable<PubAprovacaoOferta>  $ofertas
     * @return array{
     *     selecionadas: int,
     *     aptas: int,
     *     rotulo_da_selecao: string,
     *     rotulo_de_aprovar: string,
     *     pode_aprovar: bool,
     *     titulo: string,
     *     sub: string,
     *     rotulo_do_botao: string,
     *     nota: string,
     *     linhas: list<array{id: int, nome: string, versao: int, versao_rotulo: string, apta: bool, glifo: string, motivo: string, motivo_cor: string}>,
     *     ids_aptos: list<int>
     * }
     */
    public function painel(iterable $ofertas): array
    {
        $linhas = $this->veredicto($ofertas);

        $aptas = array_values(array_filter($linhas, static fn (array $l): bool => $l['apta'] === true));
        $idsAptos = array_map(static fn (array $l): int => $l['id'], $aptas);

        $total = count($linhas);
        $k = count($idsAptos);

        return [
            'selecionadas' => $total,
            'aptas' => $k,
            'rotulo_da_selecao' => self::rotuloDaSelecao($total),
            'rotulo_de_aprovar' => self::rotuloDeAprovar($k),
            'pode_aprovar' => $k > 0,
            'titulo' => self::TITULO_DO_MODAL,
            'sub' => self::subDoModal($k, $total),
            'rotulo_do_botao' => self::rotuloDoBotao($k),
            'nota' => self::NOTA_DO_MODAL,
            'linhas' => $linhas,
            'ids_aptos' => $idsAptos,
        ];
    }

    /**
     * Aprova o lote — **só as aptas, cada uma na versão que o VEREDICTO indicou**, delegando a
     * `AprovacaoDaOfertaService::aprovarEmLote()`.
     *
     * ⚠️⚠️ **A TRANSAÇÃO É DELE, e não se abre outra por fora.** Cada aprovação é a sua
     * própria transação em `aprovar()`: uma oferta que falhe **não** desfaz as outras já
     * aprovadas — elas já foram enfileiradas para publicação, e não há desfazer.
     *
     * ═══ ⚠️⚠️ POR QUE A VERSÃO ESPERADA É CONFERIDA **AQUI**, E NÃO LÁ ═════════════════════
     *
     * **MEDIDO em `AprovacaoDaOfertaService:258`:** `aprovarEmLote()` passa
     * `$versao = (int) $oferta->versao` para `aprovar()` — a versão **do próprio modelo que ele
     * acabou de receber**. No caminho do POST o modelo é recém-carregado do banco, então
     * esperado e atual são **sempre iguais** e `COL-04-VERSAO-DIVERGENTE` **nunca dispararia**.
     * Delegar a conferência para ele seria uma trava que não trava.
     *
     * ➡️ A versão que importa é a que a pessoa **VIU no modal**, e ela vem no corpo do POST. A
     * oferta cuja versão mudou entre o veredicto e o clique é **retirada do lote ANTES** de
     * chegar a `aprovarEmLote()`, com a mesma chave de regra (`COL-04-VERSAO-DIVERGENTE`) —
     * assim ela é recusada **sem derrubar as outras**, que é o comportamento pedido.
     *
     * ⚠️ **Oferta AUSENTE do mapa é aprovada na versão atual**, e isso é decisão: sem
     * afirmação de versão não há o que divergir. Recusar por omissão quebraria o caminho de
     * quem aprovou uma oferta só pelo botão da linha.
     *
     * @param  iterable<PubAprovacaoOferta>  $ofertas
     * @param  array<int, int>  $versoesEsperadas  `oferta_id => versão que o veredicto mostrou`
     * @return array{selecionadas: int, aprovadas: int, recusadas_por_versao: list<int>, veredicto: list<array<string, mixed>>}
     */
    public function aprovar(iterable $ofertas, User $quem, array $versoesEsperadas = []): array
    {
        $noLote = [];
        $divergentes = [];

        foreach ($ofertas as $oferta) {
            $id = (int) $oferta->id;

            // ⚠️ Flag DENTRO do laço. `array_key_exists` e nunca `isset`: versão `null` no mapa
            // é dado quebrado, não "ausente".
            $afirmada = array_key_exists($id, $versoesEsperadas) ? $versoesEsperadas[$id] : null;

            if (is_int($afirmada) && $afirmada !== max(1, (int) $oferta->versao)) {
                $divergentes[] = [
                    'oferta_id' => $id,
                    'apta' => false,
                    'versao' => max(1, (int) $oferta->versao),
                    'aprovada' => false,
                    'motivo' => AprovacaoDaOfertaService::REGRA_VERSAO_DIVERGENTE,
                    'bloqueios' => [],
                ];

                continue;
            }

            $noLote[] = $oferta;
        }

        $veredicto = $noLote === [] ? [] : $this->aprovacao->aprovarEmLote($noLote, $quem);

        $todos = array_merge($veredicto, $divergentes);

        return [
            'selecionadas' => count($todos),
            // ⚠️ A contagem é CONTADA, nunca presumida do tamanho da entrada: lista vazia e
            // lista só com inaptas têm de dizer **0**, nunca um sucesso falso.
            'aprovadas' => count(array_filter($todos, static fn (array $v): bool => $v['aprovada'] === true)),
            'recusadas_por_versao' => array_map(static fn (array $v): int => $v['oferta_id'], $divergentes),
            'veredicto' => $todos,
        ];
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // Os literais, como funções PURAS — testáveis sem banco e sem HTTP
    // ═════════════════════════════════════════════════════════════════════════════════════════

    /** `:1282` — "N selecionadas" / "1 selecionada". ⚠️ `> 1` é plural (medido). */
    public static function rotuloDaSelecao(int $n): string
    {
        return $n.($n > 1 ? self::SELECAO_PLURAL : self::SELECAO_SINGULAR);
    }

    /** `:1282` — "Aprovar K para publicação". ⚠️ O rótulo NÃO varia e NÃO ganha segundo passo. */
    public static function rotuloDeAprovar(int $k): string
    {
        return self::APROVAR_PREFIXO.$k.self::APROVAR_SUFIXO;
    }

    /** `:1425` — "K de M ofertas selecionadas podem ser aprovadas". */
    public static function subDoModal(int $aptas, int $total): string
    {
        return $aptas.self::SUB_MEIO.$total.self::SUB_SUFIXO;
    }

    /** `:1425` — "Aprovar K ofertas" / "Aprovar 1 oferta". ⚠️ Zero sai no SINGULAR (medido). */
    public static function rotuloDoBotao(int $aptas): string
    {
        return self::BOTAO_PREFIXO.$aptas.($aptas > 1 ? self::BOTAO_PLURAL : self::BOTAO_SINGULAR);
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // Privados
    // ═════════════════════════════════════════════════════════════════════════════════════════

    /**
     * As abreviações dos bloqueios, na ordem em que o `BloqueiosDaOfertaService` as devolveu,
     * separadas por ", ".
     *
     * ⚠️ **LIDAS de `VocabularioDoPortao::ABREVIACOES`.** Chave sem abreviação cadastrada
     * **não** vira a chave crua na tela (jargão não vai para a tela — regra permanente): ela é
     * descartada, e se TODAS forem descartadas o motivo cai no resumo do rodapé, que a Fase 186
     * já monta.
     *
     * @param  list<string>  $chaves
     */
    private function abreviacoesDos(array $chaves): string
    {
        $abreviacoes = [];

        foreach ($chaves as $chave) {
            // ⚠️ Flag DENTRO do laço, e `array_key_exists` em vez de `MAPA[$chave]` cru:
            // chave desconhecida em acesso direto é `null` silencioso (ou warning), e
            // `isset()` mentiria num valor `null` legítimo.
            if (is_string($chave) && array_key_exists($chave, VocabularioDoPortao::ABREVIACOES)) {
                $abreviacoes[] = VocabularioDoPortao::ABREVIACOES[$chave];
            }
        }

        if ($abreviacoes === []) {
            // ⚠️ Nunca vazio depois do prefixo: "Fica de fora: " sozinho é frase truncada na
            // tela, e a chave crua é jargão.
            return self::MOTIVO_DESCONHECIDO;
        }

        return implode(self::FORA_SEPARADOR, $abreviacoes);
    }

    /**
     * O rótulo do andamento em minúsculas — `this.STM[o.status][0].toLowerCase()` (`:1423`).
     *
     * ⚠️ `mb_strtolower`, nunca `strtolower`: "Aguardando aprovação" tem `ç` e `ã`, e o
     * `strtolower` de byte único os corrompe em UTF-8.
     * ⚠️ E `rotulo()` pode devolver `null` (andamento desconhecido no banco). O fallback é
     * TEXTO, nunca string vazia.
     */
    private function andamentoEmMinusculas(PubAprovacaoOferta $oferta): string
    {
        $rotulo = $oferta->rotulo();

        if (! is_string($rotulo) || $rotulo === '') {
            return self::MOTIVO_DESCONHECIDO;
        }

        return mb_strtolower($rotulo, 'UTF-8');
    }

    /** O nome da oferta — o título do alvo, com fallback de TEXTO. */
    private function nomeDa(PubAprovacaoOferta $oferta): string
    {
        $titulo = $oferta->alvo?->titulo;

        return is_string($titulo) && trim($titulo) !== '' ? $titulo : self::SEM_NOME;
    }
}
