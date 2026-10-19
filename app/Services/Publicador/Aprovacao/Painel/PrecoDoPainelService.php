<?php

namespace App\Services\Publicador\Aprovacao\Painel;

use App\Models\EstruturaAnuncio;
use App\Models\EstruturaPublicacao;
use App\Models\PubAprovacaoComposicao;
use App\Models\PubAprovacaoExcecao;
use App\Models\PubAprovacaoOferta;
use App\Services\Publicador\Aprovacao\ComposicaoDaOferta;
use App\Services\Publicador\Aprovacao\ComposicaoFinanceiraService;
use App\Services\Publicador\Aprovacao\Fila\LinhaDaOfertaService;
use App\Services\Publicador\Aprovacao\LinhaFinanceira;
use App\Services\Publicador\Aprovacao\VocabularioDoPortao;

/**
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 * A COLUNA DE PREÇO E RESULTADO DO PAINEL DE REVISÃO SIMPLES (E2) — Fase 188, plano 04.
 *
 * Os **3 KPIs**, o aviso de alteração não conferida, a **composição completa com selo de origem
 * por linha**, o dado do frete ausente, a **simulação que nunca é salva**, os atalhos de
 * equilíbrio / margem mínima, a exceção registrada e a **comparação com a oferta irmã**.
 *
 * ═══ ⚠️⚠️ ESTE SERVIÇO SÓ LÊ. NÃO ESCREVE NADA, E NÃO FALA COM O MERCADO LIVRE ════════════════
 *
 * Nem preço, nem andamento, nem composição congelada. **Nenhuma chamada a
 * {@see ComposicaoFinanceiraService::congelar()}** sai daqui, e **nenhuma** forma de escrita
 * (`->save()`, `->update(`, `::create(`, `updateOrCreate`, `insert`, `fill`, `delete`).
 *
 * ⚠️⚠️ **A FRONTEIRA DO CRITÉRIO 7, escrita para que o executor seguinte NÃO "complete" a
 * função:** a simulação **nunca é persistida nem enviada ao ML**; ela só pode ser **salva** com a
 * oferta `live`, e **salvar incrementa a versão, limpa o aviso de alteração e registra no
 * histórico**. ➡️ **Aqui está só a LEITURA.** A **ESCRITA é do plano 188-06**, por
 * {@see \App\Services\Publicador\Aprovacao\AprovacaoDaOfertaService::registrarPrecoSalvo()} +
 * `registrarMudanca(PRECO_SALVO)`. **Sem esta declaração alguém emenda o `simular()` com um
 * `update()` e a simulação passa a gravar** — e aprovar já publica, sem desfazer.
 *
 * ⚠️ **O TRANSPORTE da simulação tem dono, e NÃO é este plano:** a rota
 * `POST …/aprovacao/{oferta}/preco/simular` (`throttle:120,1`, **POST e não GET** porque o valor
 * digitado não é bookmarkável) é do **plano 188-06**, que também já declarou as chaves que lê
 * daqui — `resultado`, `resultado_cor`, `margem`, `margem_cor`, `delta`, `delta_cor`, `simulando`
 * e `pode_salvar`. ➡️ {@see self::simular()} devolve exatamente essas.
 *
 * ⚠️ **O painel APROVA (e aprovar ENFILEIRA), NÃO PUBLICA** — a escrita no Mercado Livre é da
 * **Fase 193** (critério 18). E não existe aqui "confirmar publicação", "segundo passo",
 * "desfazer" nem "cancelar aprovação".
 *
 * ═══ ⚠️⚠️ UMA composição por oferta: ela é RECEBIDA, nunca recarregada ═════════════════════════
 *
 * {@see self::para()} recebe a {@see ComposicaoDaOferta} que
 * {@see \App\Services\Publicador\Aprovacao\BloqueiosDaOfertaService::para()} **já carregou**
 * (chave `composicao`) e que {@see PainelDeRevisaoService::para()} lê **UMA** vez. Chamar
 * `ComposicaoFinanceiraService::para()` de novo aqui dobraria a consulta a
 * `/sites/MLB/listing_prices` **dentro da requisição** — a classe do incidente de **30/07/2026**,
 * quando requisições lentas ocuparam os workers do php-fpm e **até o login parou**.
 * ⚠️ `simular()` é outra requisição (a rota do 188-06) e aí a composição é carregada: a tarifa
 * reusa o cache por **(categoria, tipo, preço em centavos)** de
 * `ComposicaoFinanceiraService::tarifaDoMl()`, então o preço **igual ao salvo** não gera
 * requisição nova. Há espião com isca nas duas direções.
 *
 * ═══ ⚠️⚠️ DECISÃO 9 DO USUÁRIO — CADA MARGEM DIZ DE ONDE VEM, E HÁ **DUAS** DE PROPÓSITO ═══════
 *
 * Aqui (portão e editor) é a **tarifa REAL do Mercado Livre** (`sale_fee_amount`); na visão rápida
 * do lote é o **parâmetro da Precificação**, em
 * **`app/Services/Publicador/Fila/ResumoRapidoService.php:384`-`:391`** — ⚠️ o caminho **NÃO** é
 * `.../Aprovacao/Fila/`, e confundi-lo com `FilaDeAprovacaoService` **APAGARIA** esta decisão. O
 * docblock de `EditorRascunhoService:370`-`:372` diz **"diferente DE PROPÓSITO… não unificar"**.
 *
 * ➡️ A frase `· com a tarifa real do Mercado Livre` **já é montada** por
 * `ComposicaoDaOferta:271` e viaja em `$notaDaMargem`: ela é **LIDA dali**, nunca remontada.
 * ⚠️ **Deixar a margem sem texto foi OFERECIDO e RECUSADO** pelo usuário — sem o texto a
 * divergência entre as duas margens chega à pessoa como **bug de dado**.
 *
 * ═══ ⚠️⚠️ `null` ONDE FALTA DADO — E A **ÚNICA** EXCEÇÃO É A EMBALAGEM ═════════════════════════
 *
 * Custo, comissão, tarifa fixa, **frete** ou imposto ausentes ⇒ `incompleto`, o KPI do resultado
 * diz **"Incompleto"** e a margem sai `—`. ⚠️ **Zero afirmaria que é de graça.** E
 * **equilíbrio/preço-alvo só existem quando TODOS os dados existem** (`hasBE = !f.missing`,
 * `Aprovacao.dc.html:1134`).
 *
 * ⚠️⚠️ **A EXCEÇÃO DELIBERADA E ÚNICA (decisão do usuário, 14/10/2026):** *"Embalagem"* nasce
 * **`Pendente` VISÍVEL** e **NÃO anula** o resultado — o resultado existe marcado **estimado por
 * baixo** (`$estimadoPorBaixo`), e **prejuízo/margem DISPARAM sobre ele**. Sem essa exceção
 * **100%** das ofertas diriam "Incompleto" e **nada seria aprovável**. ⚠️ *"Outros custos"* nasce
 * **R$ 0,00 `Confirmado`**. ⚠️ **O FRETE ausente, no MESMO cenário, ANULA** — e é esse par que
 * separa as duas regras. **Um serviço que tratasse "qualquer pendência" como `Incompleto`
 * passaria em metade dos casos.**
 *
 * ═══ ⚠️ A ORDEM DAS 7 LINHAS É EXPLÍCITA, E ISSO NÃO É ZELO ═══════════════════════════════════
 *
 * As linhas são reordenadas por {@see self::ORDEM_DAS_LINHAS}, que é
 * `array_keys(PubAprovacaoComposicao::LINHAS)` — **a mesma constante**, nunca uma segunda lista.
 * ⚠️ A lição é da wave 188-03: `PubAprovacaoImagem::alertas()` não tinha `orderBy` e a ordem vinha
 * do **ÍNDICE**, que MariaDB e SQLite resolvem diferente — **e nenhuma sonda pega**, porque texto
 * e número concordam entre si apontando para o lugar errado. Aqui a composição vem de um **array
 * literal** (`ComposicaoFinanceiraService:239`-`:247`), não de consulta; a reordenação explícita é
 * o que garante que **continue certo** se um dia a origem mudar, e há caso travando a premissa
 * (a ordem que chega **é** a da constante).
 *
 * ═══ ⚠️ VOC-03 / VOC-04 — O VOCABULÁRIO TRAVADO ═══════════════════════════════════════════════
 *
 * *"Resultado estimado por venda"* e *"Margem de contribuição estimada"* são **LIDOS** de
 * {@see ComposicaoDaOferta::rotuloDoResultado()} / `rotuloDaMargem()`, nunca escritos aqui.
 * ⚠️ **NUNCA o outro nome** — aquele que promete descontar despesa fixa e devolução: nada nesta
 * conta as desconta. Ele existe como campo **legítimo** do Portal (`EstruturaPrecificacaoParametros`)
 * e por isso o gate de ausência é **recortado à lista CONSTANTE dos arquivos deste plano**: varrer
 * `app/` inteiro **barrou um deploy** na Fase 186.
 * ⚠️ **A nota de cada linha NOMEIA a fonte** (DADO-13): `LinhaFinanceira::REGRA_NOTA_VAZIA` prova
 * que nota vazia é **erro**, não decoração. ⚠️ **Cor nunca é o único sinal**, e **jargão não vai
 * para a tela**.
 *
 * ═══ ⚠️ TOK-15 — NENHUM HEX SAI DO PHP ════════════════════════════════════════════════════════
 *
 * O `content` do Tailwind **não varre `.php`**: classe cunhada aqui não geraria CSS. As cores
 * viajam como **CHAVE SEMÂNTICA** e o mapa chave → token é da tela (plano 188-09). O mapeamento
 * MEDIDO, para quem for escrevê-lo:
 *
 *   `texto` #F4F4F5 `pub-txt` · `clara` #D4D4D8 `pub-fase1` · `suave` #A1A1AA `pub-txt-2` ·
 *   `apagada` #8A8A90 `pub-txt-3` · `neutro` #71717A `pub-txt-4` · `trilha` #3F3F46 `pub-line-off` ·
 *   `ambar` #FBBF24 · `vermelho` #F87171 · `verde` #4ADE80 · `barra_baixa` #EF4444 ·
 *   `barra_alerta` #F59E0B · `verde_fraca` rgba(34,197,94,0.35) · `vermelha_fraca` rgba(239,68,68,0.45)
 *
 * ⚠️⚠️ **`clara` É CHAVE NOVA, E A RAZÃO É MEDIDA, não preferência:** o preenchimento da barra de
 * margem do **PAINEL** é `#D4D4D8` (`:1132`) e o da **FILA** é `#A1A1AA` (`:1246`) — o protótipo os
 * escreve **diferentes de propósito**. `LinhaDaOfertaService::corDaBarra()` devolve `COR_SUAVE`
 * (#A1A1AA) e está **CERTO para a fila**; reusá-lo aqui mudaria a cor da barra do painel.
 *
 * ═══ ⚠️⚠️ O QUE É **LIDO** DA 186/187, E O QUE É CÓPIA DECLARADA ══════════════════════════════
 *
 *   · {@see LinhaDaOfertaService::percentualDaBarra()} — a largura da barra **e** a posição do
 *     traço do mínimo. ⚠️ É `public static` **exatamente** para ser reusada ("duas cópias
 *     divergiriam, e a barra passaria a discordar do próprio traço"). ➡️ **CHAMADA, nunca copiada.**
 *   · `LinhaDaOfertaService::MARGEM_SEM_NUMERO` (`—`), `PIOR_CASO_*`, `VocabularioDoPortao::INCOMPLETO`,
 *     `VocabularioDoPortao::CORRECOES`, `PubAprovacaoComposicao::LINHAS`/`ORIGENS_DA_LINHA`,
 *     `EstruturaPublicacao::LISTING_TYPES` + `EstruturaAnuncio::TIPOS` — **LIDOS**.
 *   · `PubAprovacaoExcecao::fraseDeExibicao()` — a frase `§ Exceção autorizada por … · … · …`
 *     **já existe** e já trata `liberada_por` nulo sem mentir. **LIDA.**
 *   · {@see ComposicaoFinanceiraService::comparacaoComAIrma()} e `margemMinimaComOrigem()` — **CHAMADOS**.
 *
 * ⚠️ **`brl()`/`pct()` são a QUINTA cópia privada desta forma neste módulo, e é DELIBERADO.** As
 * outras quatro estão em `BloqueiosDaOfertaService:476`, `ComposicaoFinanceiraService:701`,
 * `AprovacaoDaOfertaService:600` e `LinhaDaOfertaService:776`, **todas privadas**: extrair uma única
 * exigiria **alterar os arquivos da Fase 186**, território **INTOCÁVEL** nesta fase (a 187 declarou
 * essa mesma escolha no docblock dela). ➡️ Há gate conferindo esta cópia contra os literais medidos
 * do protótipo (`:1044`/`:1045`) nas **quatro** formas — positivo, negativo, zero e milhar —,
 * inclusive o **U+2212 MINUS SIGN seguido de espaço**, que **não** é o hífen-menos.
 *
 * ═══ ⚠️⚠️ AS DUAS DIVERGÊNCIAS DECLARADAS DESTE ARQUIVO ═══════════════════════════════════════
 *
 * **(1) A segunda frase de `:665` NÃO é reproduzida.** O protótipo escreve *"Frete pago pelo
 * comprador não entra no resultado. **Taxas de comissão são exemplos configuráveis.**"* — e a
 * segunda frase é **disclaimer da ficção dele próprio** (`:1064` *"taxa de exemplo"*, `src:'est'`).
 * No produto a comissão é a **tarifa REAL do ML** (decisão 9), então a frase seria a tela
 * **afirmando o que não é**. ➡️ Sai só a primeira, byte a byte. ⚠️ **Não é decisão nova:** a
 * `188-FID01.md` §3 já governa (*"o protótipo ganha na FÓRMULA, nos RÓTULOS e na ORDEM, NUNCA nos
 * NÚMEROS"*) e a própria Fase 186 já fez a mesma tradução na nota da linha informativa
 * (`ComposicaoFinanceiraService::linhaInformativaDoFreteDoComprador()`, em produção).
 *
 * **(2) `frete_ausente` é PRECISO, e o `freightMissing` do protótipo é o `missing` INTEIRO**
 * (`:1143`). Mostrar *"Informar frete confirmado"* quando o que falta é o **custo do produto**
 * mandaria a pessoa ao lugar errado — e a 186 **já fez essa correção**, declarada em
 * `BloqueiosDaOfertaService::freteOuCusto()` (*"dizer 'frete' quando o que falta é o custo seria a
 * tela afirmando o que não é"*). ➡️ Saem **os dois** dados, separados: `incompleto` e
 * `frete_ausente`. ⚠️ **E o destino NÃO é cravado aqui:** `frete_ausente` tem **DOIS** destinos
 * (modal de frete ou modal de ajustes), já resolvidos por `PainelDeRevisaoService::destino()` —
 * cravar o modal faria o botão dizer *"Solicitar ajuste"* e abrir o frete. **O botão é das waves
 * 09/12; aqui entrega-se o DADO.**
 *
 * ═══ ⚠️ O QUE ESTE SERVIÇO **NÃO** ENTREGA ════════════════════════════════════════════════════
 *
 *   · o **botão** "Informar frete confirmado" e os cliques da simulação — são da tela (188-09/12);
 *   · a **gravação** do preço simulado, do frete e da exceção — plano **188-06**;
 *   · o **clique** na oferta irmã — a tela navega; aqui sai o `href` do `?oferta=`;
 *   · qualquer `hex`, qualquer classe de CSS, qualquer data formatada por `Carbon` fora dos
 *     formatos já travados na 186.
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 */
class PrecoDoPainelService
{
    // ─── As cores, como CHAVE SEMÂNTICA (TOK-15: nenhum hex sai do PHP) ─────────────────────

    public const COR_TEXTO = 'texto';

    /** ⚠️ #D4D4D8 (`pub-fase1`) — o painel (`:1132`), que NÃO é o #A1A1AA da fila (`:1246`). */
    public const COR_CLARA = 'clara';

    public const COR_APAGADA = 'apagada';

    public const COR_NEUTRA = 'neutro';

    public const COR_TRILHA = 'trilha';

    public const COR_AMBAR = 'ambar';

    public const COR_VERMELHA = 'vermelho';

    public const COR_VERDE = 'verde';

    public const COR_BARRA_BAIXA = 'barra_baixa';

    public const COR_BARRA_ALERTA = 'barra_alerta';

    /** As bordas translúcidas do selo de origem (`:1125`) — duas chaves próprias. */
    public const COR_VERDE_FRACA = 'verde_fraca';

    public const COR_VERMELHA_FRACA = 'vermelha_fraca';

    /**
     * ⚠️ As três origens têm **cores de texto E de borda diferentes entre si** (`:1125`).
     *
     * `conf → ['#4ADE80', 'rgba(34,197,94,0.35)']` · `est → ['#D4D4D8', '#3F3F46']` ·
     * `miss → ['#F87171', 'rgba(239,68,68,0.45)']`. **Duas iguais passariam num teste de
     * existência** — por isso há caso exigindo que as seis sejam distintas aos pares.
     *
     * @var array<string, array{0: string, 1: string}>
     */
    public const CORES_DA_ORIGEM = [
        PubAprovacaoComposicao::CONFIRMADO => [self::COR_VERDE, self::COR_VERDE_FRACA],
        PubAprovacaoComposicao::ESTIMADO => [self::COR_CLARA, self::COR_TRILHA],
        PubAprovacaoComposicao::PENDENTE => [self::COR_VERMELHA, self::COR_VERMELHA_FRACA],
    ];

    // ─── As chaves dos 3 KPIs (`:642`-`:644`), nesta ordem ──────────────────────────────────

    public const KPI_PRECO = 'preco_de_venda';

    public const KPI_RESULTADO = 'resultado';

    public const KPI_MARGEM = 'margem';

    /** A ordem é o contrato: 3 entradas, sempre estas, sempre assim. */
    public const KPIS_EM_ORDEM = [self::KPI_PRECO, self::KPI_RESULTADO, self::KPI_MARGEM];

    // ─── Os literais do protótipo, UMA casa cada ────────────────────────────────────────────

    /** `:640` — o título da seção. */
    public const TITULO = 'Preço e resultado';

    /** `:642` **e** `:655` — o MESMO texto em dois elementos; uma constante, uma verdade. */
    public const ROTULO_PRECO = 'Preço de venda';

    /** `:644` — ⚠️ NÃO é `rotuloDaMargem()`: o KPI pergunta outra coisa, e são textos distintos. */
    public const ROTULO_MARGEM_KPI = 'Margem sobre o preço';

    /** `:650` — o glifo do aviso de alteração. */
    public const ALTERACAO_GLIFO = '↻';

    /** `:1310` — `'Preço alterado por ' + nome + ' · ' + at + ': ' + brl(from) + ' → ' + brl(to)`. */
    public const ALTERACAO_PREFIXO = 'Preço alterado por ';

    public const ALTERACAO_SEPARADOR = ' · ';

    public const ALTERACAO_DOIS_PONTOS = ': ';

    public const ALTERACAO_SETA = ' → ';

    /** `:1310` — sem quem gravou, a frase **não mente**: ela diz que o registro não tem autor. */
    public const ALTERACAO_SEM_AUTOR = 'autor não registrado';

    /**
     * `:665` — ⚠️ **SÓ a primeira frase.** A segunda (*"Taxas de comissão são exemplos
     * configuráveis."*) é disclaimer da ficção do protótipo e seria falsa aqui. Ver a divergência
     * **(1)** no docblock da classe.
     */
    public const NOTA_DO_FRETE_DO_COMPRADOR = 'Frete pago pelo comprador não entra no resultado.';

    /** `:667` — o rótulo da ação de frete. ⚠️ O **botão** é das waves 09/12; aqui é o rótulo. */
    public const FRETE_ROTULO_ACAO = 'Informar frete confirmado';

    /** `:671`/`:672`/`:675`/`:677` — a caixa de simulação, byte a byte. */
    public const SIMULACAO_TITULO = 'Simular preço';

    public const SIMULACAO_SELO = 'SIMULAÇÃO · NÃO SALVA';

    public const SIMULACAO_PLACEHOLDER = '0,00';

    public const SIMULACAO_DESCARTAR = 'Descartar';

    public const SIMULACAO_SALVAR = 'Salvar no anúncio';

    /** `:1136` — o sufixo do delta e o prefixo do delta não-negativo. */
    public const DELTA_SUFIXO = ' vs. salvo';

    public const DELTA_PREFIXO_POSITIVO = '+ ';

    /** `:681`/`:682` — os dois atalhos. ⚠️ O `12%` do protótipo é o `MIN` (`:914`), não literal. */
    public const ATALHO_EQUILIBRIO_PREFIXO = 'Equilíbrio · ';

    public const ATALHO_MINIMO_PREFIXO = 'Margem mínima ';

    public const ATALHO_MINIMO_SEPARADOR = ' · ';

    public const ATALHO_EQUILIBRIO = 'equilibrio';

    public const ATALHO_MARGEM_MINIMA = 'margem_minima';

    /** `:685` — a frase do indisponível, literal. */
    public const EQUILIBRIO_INDISPONIVEL = 'Preço de equilíbrio indisponível até o frete ser confirmado.';

    /**
     * `:1129` — a tolerância do "o valor digitado difere do salvo". **Literal do protótipo.**
     *
     * ⚠️ `>= 0` faria o selo "SIMULAÇÃO · NÃO SALVA" aparecer com o preço **igual** ao salvo, e
     * `pode_salvar` ficaria verdadeiro para salvar o que já está salvo (versão++ por nada).
     */
    public const TOLERANCIA_DO_SIMULADO = 0.001;

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // ⚠️⚠️ Fase 188, plano 06 — O QUE AS ESCRITAS DE PREÇO E FRETE PRECISAM DAQUI
    //
    // As rotas do plano 06 são actions FINAS. Três coisas que elas precisam **não podem** morar
    // lá: o id estável da recusa (a tela decide por ele onde marcar o erro), as FRASES do
    // histórico (que levam dinheiro formatado, e a formatação do dinheiro mora aqui, numa casa
    // só) e a PRÉVIA do frete. Escrevê-las na action criaria a segunda redação de cada uma.
    // ═════════════════════════════════════════════════════════════════════════════════════════

    /**
     * FIN-11 — o preço digitado não é um preço (`0`, negativo, vazio, texto, array, objeto).
     *
     * ⚠️ O id existe porque a tela precisa do **identificador estável** da recusa, não só do
     * texto: devolver apenas a mensagem obrigaria o cliente a casar português.
     */
    public const REGRA_PRECO_INVALIDO = 'FIN-11-PRECO-INVALIDO';

    /** MOD-03 — o valor de frete digitado não é um valor (mesmo saneamento do preço, só `> 0`). */
    public const REGRA_FRETE_INVALIDO = 'MOD-03-FRETE-SEM-VALOR';

    /** `:1401` — a prévia do frete **sem valor digitado**, literal. */
    public const PREVIA_SEM_VALOR = 'Informe o valor para ver o resultado';

    /** `:1401` — `'Resultado ' + brl(result) + ' · margem ' + pct(margin)`, nas três partes. */
    public const PREVIA_PREFIXO = 'Resultado ';

    public const PREVIA_SEPARADOR = ' · margem ';

    /** `:1169` — `'Alterou o preço de X para Y na revisão · versão N'`, nas quatro partes. */
    public const REGISTRO_PRECO_PREFIXO = 'Alterou o preço de ';

    public const REGISTRO_PRECO_PARA = ' para ';

    public const REGISTRO_PRECO_SUFIXO = ' na revisão · versão ';

    /** `:1204` — `'Confirmou o frete do vendedor: R$ X (origem) · versão N'`, nas partes. */
    public const REGISTRO_FRETE_PREFIXO = 'Confirmou o frete do vendedor: ';

    public const REGISTRO_FRETE_ABRE = ' (';

    public const REGISTRO_FRETE_FECHA = ')';

    public const REGISTRO_VERSAO = ' · versão ';

    /**
     * ⚠️ A ORDEM das 7 linhas da conta — `array_keys(PubAprovacaoComposicao::LINHAS)`.
     *
     * **Uma lista só**: duas divergiriam, e a divergente seria a que a pessoa lê. Ver o bloco "A
     * ORDEM DAS 7 LINHAS É EXPLÍCITA" no docblock da classe.
     *
     * @var list<string>
     */
    public const ORDEM_DAS_LINHAS = ['custo', 'comissao', 'tarifa_fixa', 'frete', 'imposto', 'embalagem', 'outros'];

    public function __construct(
        private ComposicaoFinanceiraService $financeira,
    ) {}

    /**
     * A coluna de preço e resultado desta oferta.
     *
     * ⚠️⚠️ `$composicao` é **RECEBIDA**, não recarregada — ver "UMA composição por oferta" no
     * docblock da classe. Quem a carrega é `BloqueiosDaOfertaService::para()['composicao']`, UMA
     * vez por `PainelDeRevisaoService::para()`.
     *
     * @return array{
     *     titulo: string,
     *     kpis: list<array<string, mixed>>,
     *     incompleto: bool,
     *     estimado_por_baixo: bool,
     *     frete_ausente: bool,
     *     preco: array{valor: string, variante: ?string, nota: ?string},
     *     alteracao: array{glifo: string, texto: string, correcao: string}|null,
     *     composicao: array{cabecalho: array{rotulo: string, valor: string}, linhas: list<array<string, mixed>>, informativas: list<array<string, mixed>>, rodape: array{rotulo: string, valor: string, cor: string}, nota: string},
     *     frete: array{rotulo_acao: string}|null,
     *     simulacao: array<string, mixed>,
     *     atalhos: list<array{chave: string, rotulo: string, preenche: string}>,
     *     atalhos_indisponivel: ?string,
     *     excecao: ?string,
     *     comparacao: list<array<string, mixed>>,
     *     irma: array<string, mixed>|null
     * }
     */
    public function para(PubAprovacaoOferta $oferta, ComposicaoDaOferta $composicao): array
    {
        $kpis = $this->kpis($composicao);
        $doResultado = $kpis[1];

        $comparacao = $this->comparacao($oferta, $composicao);
        $irmas = array_values(array_filter($comparacao, static fn (array $c): bool => $c['atual'] === false));

        return [
            'titulo' => self::TITULO,
            // ⚠️ TRÊS entradas, nesta ordem — ver `KPIS_EM_ORDEM`.
            'kpis' => $kpis,
            'incompleto' => $composicao->incompleto,
            // ⚠️ A marca da exceção da embalagem: o resultado EXISTE e é otimista.
            'estimado_por_baixo' => $composicao->estimadoPorBaixo,
            // ⚠️ Divergência **(2)** do docblock: PRECISO, e sem cravar destino.
            'frete_ausente' => $this->fretePendente($composicao),
            'preco' => [
                'valor' => $this->precoLegivel($composicao),
                // ⚠️ Decisão 2: o PIOR CASO. Os dois saem **LIDOS** do DTO, nunca recalculados.
                'variante' => $composicao->precoDeVariante,
                // ⚠️ `null` com UM preço só — a tela não desenha nota vazia.
                'nota' => $composicao->notaDoPreco,
            ],
            'alteracao' => $this->alteracao($oferta),
            'composicao' => [
                'cabecalho' => ['rotulo' => self::ROTULO_PRECO, 'valor' => $this->precoLegivel($composicao)],
                'linhas' => $this->linhas($composicao),
                'informativas' => $this->informativas($composicao),
                // ⚠️ O rodapé (`:663`) mostra o **RESULTADO**, não o total — e sai do MESMO KPI
                // calculado acima. Recalculá-lo aqui criaria dois números para o mesmo fato.
                'rodape' => [
                    'rotulo' => $composicao->rotuloDoResultado(),
                    'valor' => (string) $doResultado['valor'],
                    'cor' => (string) $doResultado['cor'],
                ],
                'nota' => self::NOTA_DO_FRETE_DO_COMPRADOR,
            ],
            'frete' => $this->fretePendente($composicao) ? ['rotulo_acao' => self::FRETE_ROTULO_ACAO] : null,
            // ⚠️ O estado NEUTRO: nada digitado ainda, nada simulado, nada salvável.
            'simulacao' => $this->simulacaoNeutra($composicao),
            'atalhos' => $this->atalhos($composicao),
            'atalhos_indisponivel' => $composicao->incompleto ? self::EQUILIBRIO_INDISPONIVEL : null,
            'excecao' => $this->excecao($oferta),
            // ⚠️ A grade de `:688`-`:692` lista as DUAS modalidades, com a atual marcada; a ordem é
            // **Clássico antes de Premium** (`:1294`), lida da constante.
            'comparacao' => $comparacao,
            // ⚠️ A irmã propriamente: `null` quando não há. É **derivada** de `comparacao`, para
            // que as duas chaves nunca possam discordar.
            'irma' => $irmas[0] ?? null,
        ];
    }

    /**
     * FIN-11 / FIN-12 — a simulação de outro preço. ⚠️ **NADA é persistido nem enviado ao ML.**
     *
     * ⚠️ `$bruto` é **`mixed` de propósito**: o valor vem da digitação da pessoa e pode chegar `0`,
     * negativo, vazio, texto **ou array**. `(float) []` é a família do `(int) []` que já foi **500**
     * nesta tela. ➡️ Saneado como o `num()` do protótipo (`:1048`): **só `> 0`**. Fora disso, o
     * estado **neutro**, **sem exceção**.
     *
     * ⚠️ `pode_salvar` exige **os dois** eixos: simulação ativa **E** oferta `live` (`canApply`,
     * `:1142`). Quem grava é o plano **188-06**.
     *
     * @return array<string, mixed>
     */
    public function simular(PubAprovacaoOferta $oferta, mixed $bruto): array
    {
        $novo = self::sanear($bruto);

        if ($novo === null) {
            return $this->simulacaoNeutra($this->financeira->para($oferta), $oferta);
        }

        $simulacao = $this->financeira->simular($oferta, $novo);
        /** @var ComposicaoDaOferta $salva */
        $salva = $simulacao['salva'];
        /** @var ComposicaoDaOferta $simulada */
        $simulada = $simulacao['simulada'];

        // ⚠️ `:1129` — o selo só existe quando o digitado DIFERE do salvo, com a tolerância literal.
        $simulando = abs($novo - $salva->preco) > self::TOLERANCIA_DO_SIMULADO;

        if (! $simulando) {
            return $this->simulacaoNeutra($salva, $oferta);
        }

        $delta = $simulacao['diferenca'];

        return [
            'titulo' => self::SIMULACAO_TITULO,
            'placeholder' => self::SIMULACAO_PLACEHOLDER,
            'rotulo_descartar' => self::SIMULACAO_DESCARTAR,
            'rotulo_salvar' => self::SIMULACAO_SALVAR,
            'simulando' => true,
            'selo' => self::SIMULACAO_SELO,
            'preco_salvo' => $this->precoLegivel($salva),
            'preco_simulado' => self::brl($simulada->preco),
            'preenche' => self::digitavel($novo),
            // ⚠️ `:1135` — a simulação **NÃO** usa âmbar no `Incompleto`: `simResultColor` só
            // distingue negativo de normal. É diferente do KPI de propósito.
            'resultado' => $simulada->incompleto
                ? VocabularioDoPortao::INCOMPLETO
                : self::brl((float) $simulada->resultado),
            'resultado_cor' => ! $simulada->incompleto && (float) $simulada->resultado < 0
                ? self::COR_VERMELHA
                : self::COR_TEXTO,
            'margem' => $simulada->incompleto
                ? LinhaDaOfertaService::MARGEM_SEM_NUMERO
                : self::pct((float) $simulada->margem),
            'margem_cor' => $this->corDaMargem($simulada->incompleto ? null : $simulada->margem, $simulada->margemMinima),
            // ⚠️ `:1130` — o delta é `null` quando a composição SALVA está incompleta: não há de
            // que subtrair. A tela não desenha texto vazio.
            'delta' => $delta === null
                ? ''
                : ($delta >= 0 ? self::DELTA_PREFIXO_POSITIVO : '').self::brl((float) $delta).self::DELTA_SUFIXO,
            'delta_cor' => match (true) {
                $delta === null => self::COR_NEUTRA,
                $delta >= 0 => self::COR_VERDE,
                default => self::COR_VERMELHA,
            },
            // ⚠️ OS DOIS EIXOS. Fora do `live` não há como salvar, nem com simulação ativa.
            'pode_salvar' => $oferta->ehLive(),
            // COL-04 — a versão no instante da leitura.
            'versao' => max(1, (int) $oferta->versao),
        ];
    }


    // ═════════════════════════════════════════════════════════════════════════════════════════
    // ⚠️⚠️ Fase 188, plano 06 — A PRÉVIA DO FRETE (critério 14), **SEM PERSISTIR NADA**
    // ═════════════════════════════════════════════════════════════════════════════════════════

    /**
     * MOD-03 / critério 14 — o resultado e a margem **como ficariam** com o frete informado,
     * para a pessoa ver ANTES de confirmar. ⚠️ **NADA é gravado.**
     *
     * ═══ ⚠️⚠️ POR QUE ESTE MÉTODO EXISTE EM VEZ DE `ComposicaoFinanceiraService::para()` ══════
     *
     * A `188-FID01.md` §F.3 prescreve "ComposicaoFinanceiraService::para($oferta, …) com o frete
     * simulado", espelhando o `fin(Object.assign({}, o, {freight: v}), o.price)` do protótipo
     * (`:1400` — **remedido por `sed`**; a prosa do plano dizia `:1402`, que é o ramo `reject`).
     * ⚠️ **Essa assinatura NÃO EXISTE:** `para(PubAprovacaoOferta, ?float $precoForcado)` força o
     * **PREÇO**, nunca o frete, e a linha de frete é montada dentro dele por
     * `PubAprovacaoComposicao::linhaDeFrete($vivo, PubAprovacaoFrete::vigenteDe($oferta))`.
     * Acrescentar um `?float $freteForcado` lá seria **alterar um serviço da Fase 186 que está em
     * produção**, o que esta fase não faz.
     *
     * ⚠️ **As duas saídas que foram MEDIDAS e DESCARTADAS, para ninguém as tentar de novo:**
     *
     *   (a) **Dublar `DadosEfetivosService`** e pedir `para()` ao serviço real. **Não funciona:**
     *       em `PubAprovacaoComposicao::linhaDeFrete()` o frete **informado à mão VENCE** o da
     *       Precificação — então, numa oferta que já tem frete confirmado (o caso de corrigir o
     *       valor), a prévia mostraria o frete **ANTIGO**. Prévia que ignora o que a pessoa
     *       digitou é pior que prévia nenhuma.
     *   (b) **Subtrair o delta do `resultado` atual** (`resultado − (novo − velho)`). **Não
     *       funciona no caso principal:** sem frete a composição é `incompleta` e `resultado` é
     *       **`null`** — e o modal de frete existe justamente porque o frete falta.
     *
     * ➡️ A saída: **trocar a LINHA e somar como o DTO da própria Fase 186 soma**. O que este
     * método decide é só qual linha entra; `total`, `resultado` e `margem` seguem a definição de
     * {@see ComposicaoDaOferta::montar()} — **menos** os dois percentuais, que são privados de
     * `ComposicaoFinanceiraService` e **não são recuperáveis sem perda** (o da comissão só
     * existe formatado, com 0 ou 1 casa, em `percentual()`).
     *
     * ⚠️⚠️ **E É POR ISSO QUE A PREMISSA FICA TRAVADA POR ASSERÇÃO, não por confiança:** o gate
     * `frete-confirmado` exige que, com o valor **IGUAL** ao frete que já vale, esta prévia
     * devolva **exatamente** o `resultado` e a margem de `ComposicaoFinanceiraService::para()`.
     * É o ponto fixo. Se a fórmula do DTO mudar, aquela asserção **quebra** — que é o
     * comportamento certo. ⚠️ **`equilibrio` e `precoAlvo` NÃO são calculados aqui** (eles
     * precisariam dos percentuais): número plausível e errado é pior que número ausente, e a
     * prévia do protótipo não os mostra.
     *
     * ⚠️ `$bruto` é **`mixed` de propósito** — mesmo saneamento do preço (`num()`, `:1048`, só
     * `> 0`): `0`, negativo, vazio, texto, **array** e objeto caem no estado "sem valor", **sem
     * exceção**. `(float) []` é a família do `(int) []` que já foi **500** nesta tela.
     *
     * @return array{preenchido: bool, valor: ?float, texto: string, cor: string, resultado: ?string, margem: ?string, incompleto: bool}
     */
    public function previaDoFrete(PubAprovacaoOferta $oferta, mixed $bruto): array
    {
        $valor = self::sanear($bruto);

        if ($valor === null) {
            // ⚠️ `:1401` — sem valor a frase é a do protótipo e a cor é a NEUTRA. Nunca
            // "Resultado R$ 0,00", que a tela leria como verdade.
            return [
                'preenchido' => false,
                'valor' => null,
                'texto' => self::PREVIA_SEM_VALOR,
                'cor' => self::COR_NEUTRA,
                'resultado' => null,
                'margem' => null,
                'incompleto' => false,
            ];
        }

        $atual = $this->financeira->para($oferta);
        $comFrete = $this->comFreteSubstituido($atual, $valor);

        if ($comFrete === null) {
            // ⚠️ Falta OUTRO dado da conta (custo, comissão, tarifa fixa ou imposto): confirmar o
            // frete não destrava o resultado, e dizer um número aqui seria afirmar o que não é.
            // ⚠️ O rótulo é LIDO de `VocabularioDoPortao::INCOMPLETO` — uma terceira redação de
            // "Incompleto" seria a que a pessoa leria.
            return [
                'preenchido' => true,
                'valor' => $valor,
                'texto' => VocabularioDoPortao::INCOMPLETO,
                'cor' => self::COR_AMBAR,
                'resultado' => null,
                'margem' => null,
                'incompleto' => true,
            ];
        }

        [$resultado, $margem] = $comFrete;

        return [
            'preenchido' => true,
            'valor' => $valor,
            'texto' => self::PREVIA_PREFIXO.self::brl($resultado).self::PREVIA_SEPARADOR.self::pct($margem),
            // ⚠️ `:1401` — a ORDEM dos ramos é a do protótipo: **resultado negativo vence**
            // margem baixa. Invertê-la pintaria de âmbar um prejuízo.
            'cor' => match (true) {
                $resultado < 0 => self::COR_VERMELHA,
                $margem < $atual->margemMinima => self::COR_AMBAR,
                default => self::COR_VERDE,
            },
            'resultado' => self::brl($resultado),
            'margem' => self::pct($margem),
            'incompleto' => false,
        ];
    }

    /**
     * ⚠️ As FRASES do histórico das duas escritas, **numa casa só** — elas levam dinheiro
     * formatado, e `brl()` é privado daqui.
     *
     * `:1169` — "Alterou o preço de R$ 100,00 para R$ 120,00 na revisão · versão 3".
     */
    public function registroDePrecoSalvo(float $de, float $para, int $versao): string
    {
        return self::REGISTRO_PRECO_PREFIXO.self::brl($de)
            .self::REGISTRO_PRECO_PARA.self::brl($para)
            .self::REGISTRO_PRECO_SUFIXO.$versao;
    }

    /**
     * `:1204` — "Confirmou o frete do vendedor: R$ 18,00 (Tabela do cliente) · versão 4".
     *
     * ⚠️ `$rotuloDaOrigem` é o RÓTULO, nunca a chave (VOC-06): quem traduz é
     * `PubAprovacaoFrete::ORIGENS`, e `cotacao_transportadora` não vai para a linha do tempo.
     */
    public function registroDeFreteConfirmado(float $valor, string $rotuloDaOrigem, int $versao): string
    {
        return self::REGISTRO_FRETE_PREFIXO.self::brl($valor)
            .self::REGISTRO_FRETE_ABRE.$rotuloDaOrigem.self::REGISTRO_FRETE_FECHA
            .self::REGISTRO_VERSAO.$versao;
    }

    /**
     * O `resultado` e a `margem` da composição **com a linha de frete trocada** — ou `null`
     * quando falta OUTRO dado da conta.
     *
     * ⚠️ A lista de quem anula é **LIDA** de `PubAprovacaoComposicao::ANULAM_O_RESULTADO`, nunca
     * reescrita: ela é a decisão 3 do usuário (a Embalagem nasce `Pendente` e **não** anula; o
     * FRETE, no mesmo cenário, **anula**) e uma segunda lista divergiria dela em silêncio.
     *
     * ⚠️ A soma é sobre `linhasDaConta()`, que já **exclui** a informativa do frete do comprador
     * (FIN-08) — e é por isso que a prévia não desconta duas vezes o frete.
     *
     * @return array{0: float, 1: float}|null
     */
    private function comFreteSubstituido(ComposicaoDaOferta $atual, float $frete): ?array
    {
        if ($atual->preco <= 0) {
            return null;
        }

        $total = 0.0;
        foreach ($atual->linhasDaConta() as $linha) {
            // ⚠️ Flag DENTRO do laço.
            $ehFrete = $linha->chave === 'frete';

            if ($ehFrete) {
                $total += $frete;

                continue;
            }

            if ($linha->ehPendente() && in_array($linha->chave, PubAprovacaoComposicao::ANULAM_O_RESULTADO, true)) {
                return null;
            }

            $total += (float) ($linha->valor ?? 0.0);
        }

        $total = round($total, 2);
        $resultado = round($atual->preco - $total, 2);

        return [$resultado, $resultado / $atual->preco * 100];
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // AS REGRAS PÚBLICAS E PURAS
    // ═════════════════════════════════════════════════════════════════════════════════════════

    /**
     * O saneamento do preço digitado — o `num()` do protótipo (`:1048`), **só `> 0`**.
     *
     * ⚠️⚠️ **GUARDA DE TIPO ANTES DE QUALQUER CAST** (REND-03): `(float) []` emite warning e dá
     * `1.0` em PHP, e `(string) []` lança *"Array to string conversion"*. Array, objeto e booleano
     * caem em `null` **sem exceção** — nunca um número inventado.
     *
     * ⚠️ `,` → `.` é a substituição **DA PRIMEIRA**, como no JS (`String.replace(',', '.')` troca
     * só a primeira ocorrência). `str_replace` trocaria **todas** e `1,2,3` viraria `1.2.3`.
     *
     * ⚠️⚠️ **DIVERGÊNCIA (3), MEDIDA NESTA WAVE E DECLARADA — o SINAL DE MENOS RECUSA a entrada.**
     *
     * O `num()` do protótipo tira **tudo** que não é dígito/vírgula/ponto **ANTES** do `> 0`, então
     * `'-10,00'` perde o `-` e volta como **`10.0`** — medido: o caso de teste reprovou com
     * *"preço inválido virou simulação: negativo_texto"*. ➡️ O `> 0` dele só pega número negativo,
     * que **um campo de texto nunca produz**.
     *
     * **Por que o produto NÃO reproduz isso:** o `<behavior>` do plano 188-04 e o **T-188-30** do
     * registro de ameaças dizem, os dois, que **negativo cai no estado neutro**. E a razão é de
     * segurança, não de estilo: silenciosamente trocar `-10,00` por `10,00` faria a coluna simular
     * um preço **que a pessoa não digitou** e oferecer *"Salvar no anúncio"* sobre ele — e salvar
     * incrementa a versão e troca o preço de verdade, **sem desfazer**. ⚠️ Não é conflito novo com
     * o protótipo: a `188-FID01.md` §3 dá a ele a **FÓRMULA, os RÓTULOS e a ORDEM**, e isto é
     * **validação de entrada numa fronteira de confiança**.
     *
     * ⚠️ O `+` **continua sendo tirado** (ele não muda o valor que a pessoa quis dizer); só o menos
     * — `-` **e** o U+2212, que é o que o próprio `brl()` imprime e portanto pode ser colado de
     * volta no campo — recusa.
     */
    public static function sanear(mixed $bruto): ?float
    {
        if (is_float($bruto) || is_int($bruto)) {
            $n = (float) $bruto;

            return is_finite($n) && $n > 0 ? $n : null;
        }

        if (! is_string($bruto)) {
            return null;
        }

        // ⚠️ ANTES de tirar os não-dígitos: ver a divergência (3) no docblock deste método.
        if (str_contains($bruto, '-') || str_contains($bruto, "\u{2212}")) {
            return null;
        }

        $t = preg_replace('/[^\d,.]/', '', $bruto) ?? '';
        // O separador de milhar: ponto seguido de exatamente 3 dígitos e então não-dígito ou fim.
        $t = preg_replace('/\.(?=\d{3}(\D|$))/', '', $t) ?? '';
        $t = preg_replace('/,/', '.', $t, 1) ?? '';

        if ($t === '' || ! is_numeric($t)) {
            return null;
        }

        $v = (float) $t;

        return is_finite($v) && $v > 0 ? $v : null;
    }

    /**
     * O `fmtIn()` do protótipo (`:1049`): `n.toFixed(2).replace('.', ',')` — o que vai para dentro
     * do campo de digitação, em pt-BR e **sem separador de milhar** (o campo o removeria de novo).
     */
    public static function digitavel(float $n): string
    {
        return number_format($n, 2, ',', '');
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // OS 3 KPIs (`:642`-`:646`)
    // ═════════════════════════════════════════════════════════════════════════════════════════

    /**
     * ⚠️ **TRÊS entradas, nesta ordem.** O rótulo do KPI 2 é **LIDO** de
     * {@see ComposicaoDaOferta::rotuloDoResultado()} — escrevê-lo à mão seria a terceira redação do
     * mesmo nome, e a que divergisse seria a que a pessoa lê (VOC-03).
     *
     * @return list<array<string, mixed>>
     */
    private function kpis(ComposicaoDaOferta $composicao): array
    {
        $margem = $composicao->incompleto ? null : $composicao->margem;
        $minimo = (float) $composicao->margemMinima;

        return [
            [
                'chave' => self::KPI_PRECO,
                'rotulo' => self::ROTULO_PRECO,
                'valor' => $this->precoLegivel($composicao),
                'cor' => self::COR_TEXTO,
            ],
            [
                'chave' => self::KPI_RESULTADO,
                // ⚠️ LIDO do DTO (VOC-03). ⚠️ E **nunca** o nome que promete descontar despesa fixa.
                'rotulo' => $composicao->rotuloDoResultado(),
                // ⚠️ LIDO de `VocabularioDoPortao::INCOMPLETO`: custo ou frete ausente **não vira
                // zero**, porque zero afirmaria que é de graça.
                'valor' => $composicao->incompleto
                    ? VocabularioDoPortao::INCOMPLETO
                    : self::brl((float) $composicao->resultado),
                // ⚠️ `:1132` — TRÊS cores diferentes, e a do incompleto não é a do prejuízo.
                'cor' => match (true) {
                    $composicao->incompleto => self::COR_AMBAR,
                    (float) $composicao->resultado < 0 => self::COR_VERMELHA,
                    default => self::COR_TEXTO,
                },
            ],
            [
                'chave' => self::KPI_MARGEM,
                // ⚠️ O KPI pergunta "margem sobre o preço" (`:644`); `rotuloDaMargem()` é o nome
                // travado do CONCEITO. São textos distintos de propósito — os dois viajam.
                'rotulo' => self::ROTULO_MARGEM_KPI,
                'rotulo_do_conceito' => $composicao->rotuloDaMargem(),
                // ⚠️ `—`, nunca `0%`: zero afirmaria margem zero.
                'valor' => $margem === null
                    ? LinhaDaOfertaService::MARGEM_SEM_NUMERO
                    : self::pct((float) $margem),
                'cor' => $this->corDaMargem($margem, $minimo),
                'barra' => [
                    // ⚠️⚠️ **A FÓRMULA, CHAMADA** — `min(40, max(0, m))/40` clampada nas DUAS pontas
                    // (`:1127`). `percentualDaBarra()` é `public static` exatamente para isto.
                    'largura' => LinhaDaOfertaService::percentualDaBarra($margem),
                    'cor' => $this->corDaBarra($margem, $minimo),
                    // ⚠️⚠️ O traço do mínimo vai em `minimo/40`, pela **MESMA** fórmula: dá
                    // exatamente `30%` com 12 (`12/40`) e continua certo com outro mínimo. O
                    // `left:30%` de `:645` é CONSEQUÊNCIA de `MIN = 12` (`:914`), nunca literal.
                    //
                    // ⚠️⚠️ **DEFEITO REAL CONSERTADO AQUI (Regra 1), achado pelo gate do 188-02:**
                    // a primeira redação também devolvia `minimo_valor` e `teto` como **`float`**.
                    // `float` de valor inteiro **NÃO sobrevive ao `json_encode`/`json_decode`**
                    // (`12.0` volta `12`, `40.0` volta `40`), e
                    // `PainelDeRevisaoServiceTest::test_o_payload_tem_todas_as_chaves_…_188_02`
                    // **REPROVOU** com *"o payload não sobrevive ao `json_encode` — algo nele não é
                    // dado"*. O payload atravessa o Inertia, então o gate está CERTO.
                    // ➡️ As duas chaves **saíram**: a tela não precisa delas (o traço é `minimo`, a
                    // largura é `largura`, e o percentual escrito já vem em `nota_da_margem` e no
                    // rótulo do atalho). ⚠️ **Não foi o gate alheio que se afrouxou.**
                    'minimo' => LinhaDaOfertaService::percentualDaBarra($minimo),
                ],
                // ⚠️⚠️ DECISÃO 9 — **LIDA** de `ComposicaoDaOferta::$notaDaMargem`, que já fecha a
                // frase com `· com a tarifa real do Mercado Livre` (`ComposicaoDaOferta:271`).
                // **Remontá-la aqui apagaria a decisão.**
                'nota_da_margem' => $composicao->notaDaMargem,
                // `:1133` — a cor da nota NÃO é a cor do número.
                'nota_cor' => match (true) {
                    $composicao->incompleto => self::COR_AMBAR,
                    (float) $margem < 0 => self::COR_VERMELHA,
                    (float) $margem < $minimo => self::COR_AMBAR,
                    default => self::COR_APAGADA,
                },
                // ⚠️ De qual lado do fallback o mínimo veio (FIN-09), em português.
                'minimo_origem' => $composicao->rotuloDoMinimo(),
            ],
        ];
    }

    /** `:1128` — a cor do NÚMERO da margem. */
    private function corDaMargem(?float $margem, float $minimo): string
    {
        return match (true) {
            $margem === null => self::COR_NEUTRA,
            $margem < 0 => self::COR_VERMELHA,
            $margem < $minimo => self::COR_AMBAR,
            default => self::COR_TEXTO,
        };
    }

    /** `:1132` — a cor do PREENCHIMENTO. ⚠️ São hex DIFERENTES dos do número, e do da FILA. */
    private function corDaBarra(?float $margem, float $minimo): string
    {
        return match (true) {
            $margem === null => self::COR_TRILHA,
            $margem < 0 => self::COR_BARRA_BAIXA,
            $margem < $minimo => self::COR_BARRA_ALERTA,
            default => self::COR_CLARA,
        };
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // A COMPOSIÇÃO (`:654`-`:663`) — as 7 linhas com ORIGEM e NOTA por linha
    // ═════════════════════════════════════════════════════════════════════════════════════════

    /**
     * As 7 linhas da conta, **na ordem explícita de {@see self::ORDEM_DAS_LINHAS}**.
     *
     * ⚠️ `array_filter` + `array_values` preservam a ordem de entrada, mas a ordem de entrada é
     * responsabilidade de OUTRO arquivo. Reordenar aqui é o que mantém a coluna certa se a origem
     * mudar — e há caso travando a premissa de que a ordem que chega **é** a da constante.
     *
     * @return list<array<string, mixed>>
     */
    private function linhas(ComposicaoDaOferta $composicao): array
    {
        $porChave = [];
        foreach ($composicao->linhasDaConta() as $linha) {
            // ⚠️ Flag DENTRO do laço.
            $porChave[$linha->chave] = $linha;
        }

        $linhas = [];
        foreach (self::ORDEM_DAS_LINHAS as $chave) {
            // ⚠️ Flag DENTRO do laço.
            $linha = $porChave[$chave] ?? null;
            if ($linha !== null) {
                $linhas[] = $this->linha($linha);
            }
        }

        // ⚠️ Linha que chegou e NÃO está na ordem conhecida não desaparece em silêncio: ela vai
        // para o fim, na ordem de chegada. Sumir com um custo da conta é pior que mostrá-lo fora
        // de ordem — a soma do rodapé continuaria contando-o, e a tela não fecharia.
        foreach ($composicao->linhasDaConta() as $linha) {
            // ⚠️ Flag DENTRO do laço.
            if (! in_array($linha->chave, self::ORDEM_DAS_LINHAS, true)) {
                $linhas[] = $this->linha($linha);
            }
        }

        return $linhas;
    }

    /** A linha informativa de FIN-08: **aparece e não entra na soma**. */
    /** @return list<array<string, mixed>> */
    private function informativas(ComposicaoDaOferta $composicao): array
    {
        return array_map(fn (LinhaFinanceira $l): array => $this->linha($l), $composicao->linhasInformativas());
    }

    /**
     * UMA linha: rótulo, detalhe, valor com o **SINAL INVERTIDO**, selo de origem e nota.
     *
     * ⚠️⚠️ `:1126` — `r.v == null ? 'Pendente' : brl(r.v === 0 ? 0 : -r.v)`: o custo positivo é
     * **exibido negativo** (é o que sai do preço), **zero sai SEM sinal** e em neutro, e `null` sai
     * como o **texto** `Pendente` em vermelho. ⚠️ O texto do `null` é **LIDO** de
     * `rotuloDaOrigem()`, nunca escrito — ele é o MESMO que o selo mostra, e duas redações
     * divergiriam.
     *
     * @return array<string, mixed>
     */
    private function linha(LinhaFinanceira $linha): array
    {
        $pendente = $linha->ehPendente();
        $valor = $linha->valor;
        [$cor, $borda] = self::CORES_DA_ORIGEM[$linha->origem] ?? [self::COR_CLARA, self::COR_TRILHA];

        return [
            'chave' => $linha->chave,
            'rotulo' => $linha->rotulo,
            'detalhe' => $linha->detalhe,
            'valor' => $pendente || $valor === null
                ? $linha->rotuloDaOrigem()
                : self::brl($valor === 0.0 ? 0.0 : -$valor),
            'valor_cor' => match (true) {
                $pendente || $valor === null => self::COR_VERMELHA,
                $valor === 0.0 => self::COR_NEUTRA,
                default => self::COR_CLARA,
            },
            'origem' => $linha->origem,
            'origem_rotulo' => $linha->rotuloDaOrigem(),
            'origem_cor' => $cor,
            'origem_borda' => $borda,
            // ⚠️ DADO-13 / VOC-04 — a nota NOMEIA a fonte e **nunca é vazia**
            // (`LinhaFinanceira::REGRA_NOTA_VAZIA` prova que vazia é erro, não decoração).
            'nota' => $linha->nota,
            'pendente' => $pendente,
            'informativa' => $linha->informativa,
        ];
    }

    /** O frete da conta está `Pendente`? ⚠️ PRECISO — ver a divergência **(2)** do docblock. */
    private function fretePendente(ComposicaoDaOferta $composicao): bool
    {
        return $composicao->linha('frete')?->ehPendente() === true;
    }

    /**
     * `<= 0` é AUSENTE, não zero.
     *
     * ⚠️ É a MESMA correção que `LinhaDaOfertaService::preco()` já declara em produção:
     * `precoDoAlvo()` devolve `0.0` (não `null`) quando a Precificação traz zero, e `R$ 0,00`
     * afirmaria que o produto é **de graça**. `ComposicaoFinanceiraService:228` usa exatamente
     * `$preco === null || $preco <= 0`; copiar metade da condição faria o KPI discordar da
     * composição que está ao lado.
     */
    private function precoLegivel(ComposicaoDaOferta $composicao): string
    {
        return $composicao->preco <= 0.0
            ? LinhaDaOfertaService::MARGEM_SEM_NUMERO
            : self::brl($composicao->preco);
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // O AVISO DE ALTERAÇÃO (`:649`-`:651` / `:1310`)
    // ═════════════════════════════════════════════════════════════════════════════════════════

    /**
     * ⚠️ O texto do PAINEL (`:1310`) traz **quem e quando**; o do bloqueio (`:1095`) não. São dois
     * textos de propósito, como "Automática"/"Preparado automaticamente" na identificação.
     *
     * ⚠️ `preco_conferido_em` **não** entra na decisão: o que manda é a **presença** do aviso, e
     * quem o limpa é `AprovacaoDaOfertaService::conferirAlteracao()` (BLOQ-07) — conferir a
     * alteração **não altera o preço**. É a mesma régua de `BloqueiosDaOfertaService:429`.
     *
     * @return array{glifo: string, texto: string, correcao: string}|null
     */
    private function alteracao(PubAprovacaoOferta $oferta): ?array
    {
        $aviso = $oferta->preco_alterado;

        if (! is_array($aviso) || ! isset($aviso['de'], $aviso['para'])) {
            return null;
        }

        $quem = is_string($aviso['quem'] ?? null) ? trim($aviso['quem']) : '';
        $quem = $quem === '' ? self::ALTERACAO_SEM_AUTOR : $quem;
        $quando = is_string($aviso['quando'] ?? null) ? trim($aviso['quando']) : '';

        $texto = self::ALTERACAO_PREFIXO.$quem
            .($quando === '' ? '' : self::ALTERACAO_SEPARADOR.$quando)
            .self::ALTERACAO_DOIS_PONTOS.self::brl((float) $aviso['de'])
            .self::ALTERACAO_SETA.self::brl((float) $aviso['para']);

        return [
            'glifo' => self::ALTERACAO_GLIFO,
            'texto' => $texto,
            // ⚠️ LIDO do vocabulário travado da 186, nunca escrito aqui.
            'correcao' => VocabularioDoPortao::CORRECOES[VocabularioDoPortao::ALTERACAO_NAO_CONFERIDA],
        ];
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // A SIMULAÇÃO NEUTRA, OS ATALHOS, A EXCEÇÃO E A IRMÃ
    // ═════════════════════════════════════════════════════════════════════════════════════════

    /**
     * O estado neutro da caixa de simulação: **sem selo, sem delta, sem salvar**.
     *
     * ⚠️ `simulando` falso ⇒ `selo` **`null`** e `pode_salvar` **falso**, **mesmo no `live`**: o
     * selo "SIMULAÇÃO · NÃO SALVA" só existe quando o digitado **difere** do salvo (`:1129`).
     *
     * @return array<string, mixed>
     */
    private function simulacaoNeutra(ComposicaoDaOferta $composicao, ?PubAprovacaoOferta $oferta = null): array
    {
        return [
            'titulo' => self::SIMULACAO_TITULO,
            'placeholder' => self::SIMULACAO_PLACEHOLDER,
            'rotulo_descartar' => self::SIMULACAO_DESCARTAR,
            'rotulo_salvar' => self::SIMULACAO_SALVAR,
            'simulando' => false,
            'selo' => null,
            'preco_salvo' => $this->precoLegivel($composicao),
            'preco_simulado' => null,
            'preenche' => '',
            'resultado' => LinhaDaOfertaService::MARGEM_SEM_NUMERO,
            'resultado_cor' => self::COR_TEXTO,
            'margem' => LinhaDaOfertaService::MARGEM_SEM_NUMERO,
            'margem_cor' => self::COR_NEUTRA,
            'delta' => '',
            'delta_cor' => self::COR_NEUTRA,
            'pode_salvar' => false,
            'versao' => $oferta === null ? null : max(1, (int) $oferta->versao),
        ];
    }

    /**
     * Os dois atalhos (`:681`/`:682`) — **só quando o dado está completo** (`hasBE = !f.missing`).
     *
     * ⚠️ O `12%` do protótipo é o `MIN` (`:914`), não literal: o rótulo é montado com o mínimo
     * **RESOLVIDO** da empresa (FIN-09), que já vem no DTO. Com o mínimo cravado, a tela mentiria
     * para a empresa que configurou outro.
     *
     * ⚠️⚠️ **`onSimTarget` preenche `ceil(precoAlvo) − 0,1`** (`:1140`), **não** o `precoAlvo`. É
     * contraintuitivo e fácil de omitir: ele arredonda para o inteiro de cima e desce 10 centavos
     * (R$ 118,43 ⇒ preenche `118,90`). **Reproduzido de propósito.** O atalho de equilíbrio
     * preenche o valor **sem** esse ajuste (`:1139`).
     *
     * @return list<array{chave: string, rotulo: string, preenche: string}>
     */
    private function atalhos(ComposicaoDaOferta $composicao): array
    {
        if ($composicao->incompleto) {
            return [];
        }

        $atalhos = [];

        $equilibrio = $composicao->equilibrio;
        if ($equilibrio !== null && $equilibrio > 0) {
            $atalhos[] = [
                'chave' => self::ATALHO_EQUILIBRIO,
                'rotulo' => self::ATALHO_EQUILIBRIO_PREFIXO.self::brl($equilibrio),
                'preenche' => self::digitavel($equilibrio),
            ];
        }

        $alvo = $composicao->precoAlvo;
        if ($alvo !== null && $alvo > 0) {
            $preenche = ceil($alvo) - 0.1;
            $atalhos[] = [
                'chave' => self::ATALHO_MARGEM_MINIMA,
                'rotulo' => self::ATALHO_MINIMO_PREFIXO.self::percentual((float) $composicao->margemMinima)
                    .self::ATALHO_MINIMO_SEPARADOR.self::brl($alvo),
                'preenche' => self::digitavel($preenche > 0 ? $preenche : $alvo),
            ];
        }

        return $atalhos;
    }

    /**
     * A frase da exceção vigente, ou `null`.
     *
     * ⚠️ **A exceção só REGISTRA quem liberou** (decisão 6 do usuário): *"exceção aguardando
     * autorização" **NÃO EXISTE***, e `ativa` é o único filtro de vigência (não há estado de
     * espera a conferir). ⚠️ **E ela libera SÓ `resultado_negativo` e `margem_abaixo_do_minimo`**
     * (`PubAprovacaoExcecao::LIBERA`, lista FECHADA) — nunca fotos, frete, alteração, ajuste,
     * título, preparação ou falha.
     *
     * ⚠️ A frase é **LIDA** de `PubAprovacaoExcecao::fraseDeExibicao()`, que já é a FORMA de
     * `:1142` com o glifo `§` e já trata `liberada_por` nulo **sem mentir**. Remontá-la aqui seria
     * a segunda redação da mesma frase.
     */
    private function excecao(PubAprovacaoOferta $oferta): ?string
    {
        $excecao = PubAprovacaoExcecao::vigentesDa((int) $oferta->id)
            ->with('liberadaPor')
            ->orderByDesc('id')
            ->first();

        return $excecao?->fraseDeExibicao();
    }

    /**
     * FIN-13 / REVS-11 — a grade de `:688`-`:692`: as duas modalidades do mesmo alvo-par.
     *
     * ⚠️ **A ORDEM é Clássico antes de Premium** (`:1294`), e vem de
     * `array_keys(EstruturaPublicacao::LISTING_TYPES)` — a **constante**, nunca um literal
     * `'gold_pro'` (seria a terceira cópia daquele valor) e nunca `orderBy('id')`, que depende de
     * qual foi criada primeiro.
     *
     * ⚠️ A irmã é **CHAMADA** de `ComposicaoFinanceiraService::comparacaoComAIrma()`, que parte do
     * **mesmo `rascunho_id`** — é assim que o `href` não pode apontar para outra conta; o `?oferta=`
     * ainda passa pelo recorte de conta do plano 188-02.
     *
     * ⚠️ As chaves `andamento_da_irma` e `rotulo_do_andamento` são **as mesmas** que a 186 já usa,
     * passadas adiante. O nome não é estilo: a sonda da **camada B** do gate de andamento casa
     * `'andamento' =>` em **qualquer** array (inclusive de saída), e a 186 já escolheu este nome
     * mais específico para não aparecer como escritor. ⚠️ **Não renomear de volta.**
     *
     * @return list<array<string, mixed>>
     */
    private function comparacao(PubAprovacaoOferta $oferta, ComposicaoDaOferta $composicao): array
    {
        $irma = $this->financeira->comparacaoComAIrma($oferta);

        $celulas = [
            $this->celula(
                (int) $oferta->id,
                $oferta->alvo?->listing_type_id,
                $composicao->preco,
                $composicao->resultado,
                $composicao->margem,
                $composicao->incompleto,
                (float) $composicao->margemMinima,
                (string) $oferta->andamento,
                $oferta->rotulo(),
                atual: true,
            ),
        ];

        if ($irma !== null) {
            $celulas[] = $this->celula(
                (int) $irma['oferta_id'],
                $irma['listing_type_id'],
                (float) $irma['preco'],
                $irma['resultado'],
                $irma['margem'],
                (bool) $irma['incompleto'],
                (float) $composicao->margemMinima,
                $irma['andamento_da_irma'],
                $irma['rotulo_do_andamento'],
                atual: false,
            );
        }

        // ⚠️ A ordem, pela CONSTANTE: Clássico (posição 0) antes de Premium (posição 1). Chave
        // desconhecida vai para o fim, nunca para a frente de uma conhecida.
        usort($celulas, static function (array $a, array $b): int {
            // ⚠️ Flag DENTRO do callback (o Rollup não é o problema aqui, mas a régua é a mesma).
            return $a['ordem'] <=> $b['ordem'] ?: $a['oferta_id'] <=> $b['oferta_id'];
        });

        return array_values($celulas);
    }

    /**
     * UMA célula da comparação.
     *
     * ⚠️ `href` é o `?oferta=<id>` que o plano 188-02 já atende — **o clique é da tela**.
     *
     * @return array<string, mixed>
     */
    private function celula(
        int $ofertaId,
        ?string $listingType,
        float $preco,
        ?float $resultado,
        ?float $margem,
        bool $incompleto,
        float $minimo,
        ?string $andamento,
        ?string $rotuloDoAndamento,
        bool $atual,
    ): array {
        $tipo = $listingType === null || $listingType === ''
            ? false
            : array_search($listingType, EstruturaPublicacao::LISTING_TYPES, true);

        $ordem = array_search($tipo, array_keys(EstruturaPublicacao::LISTING_TYPES), true);
        $daMargem = $incompleto ? null : $margem;

        return [
            'oferta_id' => $ofertaId,
            // ⚠️ "Clássico"/"Premium" têm UMA casa, e não é esta: LIDOS de `EstruturaAnuncio::TIPOS`.
            'rotulo' => $tipo === false ? null : (EstruturaAnuncio::TIPOS[$tipo] ?? null),
            'premium' => $listingType === (EstruturaPublicacao::LISTING_TYPES[EstruturaAnuncio::TIPO_PREMIUM] ?? null),
            // ⚠️ Chave desconhecida vai para o FIM (`PHP_INT_MAX`), nunca para a frente.
            'ordem' => $ordem === false ? PHP_INT_MAX : $ordem,
            'preco' => $preco <= 0.0 ? LinhaDaOfertaService::MARGEM_SEM_NUMERO : self::brl($preco),
            'resultado' => $incompleto ? VocabularioDoPortao::INCOMPLETO : self::brl((float) $resultado),
            'resultado_cor' => match (true) {
                $incompleto => self::COR_AMBAR,
                (float) $resultado < 0 => self::COR_VERMELHA,
                default => self::COR_TEXTO,
            },
            'margem' => $daMargem === null
                ? LinhaDaOfertaService::MARGEM_SEM_NUMERO
                : self::pct((float) $daMargem),
            // ⚠️ `:1294` — a cor da margem da célula tem o ramo `null` separado, e o normal é
            // `clara` (#D4D4D8), não `texto`. É diferente do KPI de propósito.
            'margem_cor' => match (true) {
                $daMargem === null => self::COR_NEUTRA,
                $daMargem < 0 => self::COR_VERMELHA,
                $daMargem < $minimo => self::COR_AMBAR,
                default => self::COR_CLARA,
            },
            'andamento_da_irma' => $andamento,
            'rotulo_do_andamento' => $rotuloDoAndamento,
            'atual' => $atual,
            // ⚠️ A célula ATUAL não navega para si mesma (`:1294`: `x.id !== o.id`).
            'href' => $atual ? null : '?oferta='.$ofertaId,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────────────────────
    // A formatação — ⚠️ a QUINTA cópia privada desta forma neste módulo, e é DELIBERADO.
    //   · `brl(-12.03)` = "− R$ 12,03" — **U+2212 MINUS SIGN seguido de espaço** (`E2 88 92 20`),
    //     **não** o hífen-menos. Trocar por `-` passa desapercebido na tela.
    //   · `pct(2.46)` = "2,5%" — SEMPRE uma casa decimal (`:1045`).
    //   · `percentual()` é a forma do MÍNIMO (0 ou 1 casa), igual à de `ComposicaoDaOferta`: com o
    //     número cru, um mínimo de 12,5 sairia "12.5%".
    // ⚠️ Extrair uma única exigiria ALTERAR os arquivos da Fase 186 — intocáveis nesta fase.
    // ─────────────────────────────────────────────────────────────────────────────────────────

    private static function brl(float $n): string
    {
        return ($n < 0 ? "\u{2212} " : '').'R$ '.number_format(abs($n), 2, ',', '.');
    }

    private static function pct(float $n): string
    {
        return number_format(round($n * 10) / 10, 1, ',', '.').'%';
    }

    private static function percentual(float $n): string
    {
        $casas = abs($n - round($n)) < 0.0005 ? 0 : 1;

        return number_format($n, $casas, ',', '.').'%';
    }
}
