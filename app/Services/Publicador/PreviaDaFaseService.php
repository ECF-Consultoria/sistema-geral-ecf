<?php

namespace App\Services\Publicador;

use App\Models\PubProduto;
use App\Models\PubRascunho;
use App\Models\PubVariante;
use App\Support\Publicador\Variacao\ChaveCanonica;
use Illuminate\Support\Facades\Log;

/**
 * A prévia do painel "Criar Fase 2" (§4 da ETAPA-3, Fase 175 plano 05): o que
 * o operador vê ANTES de confirmar, e exatamente o que o botão Confirmar grava.
 *
 * ═══ Por que a prévia e a criação compartilham este arquivo ══════════════════
 *
 * `CriarFaseService` (175-02) não calcula valor nenhum: ele **grava o que
 * recebeu**. Quem calcula é esta classe, e o endpoint `fases.criar` a chama de
 * novo no servidor com a quantidade recebida — então a prévia nunca pode
 * divergir do que a criação faz. Se o cálculo morasse no painel React, o corpo
 * da requisição decidiria o estoque do kit (T-175-18).
 *
 * ═══ O que é puro e o que precisa de banco ═══════════════════════════════════
 *
 * Tudo o que é conta — SKU, SELLER_SKU, título, descrição, estoque — é
 * `public static` PURO (é o que a §8 pede). `previa()` só orquestra: lê o
 * rascunho do base, pede o `max_title_length` da categoria e junta os avisos.
 *
 * ═══ Decisões medidas, com a razão ═══════════════════════════════════════════
 *
 * 1. **Preço NÃO entra no retorno.** A §4 manda "campo vazio, sem sugestão", e
 *    o kit nasce com `oferta_id` NULL (decisão 5 do `DECISOES.md`): sem oferta
 *    no Portal, preço nulo **não herda da Precificação** — fica realmente
 *    vazio. O que entra é o AVISO `preco_vazio`, decisão do usuário de
 *    2026-10-08 (`175-DECISOES.md` item 2: "como especificado, com aviso no
 *    painel"). O aviso é DADO do servidor de propósito: frase hardcoded só no
 *    front sairia de sincronia com a regra na primeira mudança.
 *
 * 2. **Estoque é por variante E por depósito.** Não existe fonte externa de
 *    estoque: `EstruturaOferta` não tem a coluna, o Portal não carrega isso. O
 *    estoque mora em `pub_variantes.estoque` e `pub_variantes.estoque_depositos`
 *    (`store_id → quantidade`), digitado no editor, e o
 *    `EditorRascunhoService::salvarVariantes()` deriva `estoque` como a SOMA dos
 *    depósitos quando há depósitos. Logo o `estoque` do kit é a **soma dos
 *    divididos**, nunca `floor(soma ÷ N)` — as duas contas dão números
 *    diferentes (A=5, B=5, N=3: 1+1=2, e floor(10÷3)=3).
 *
 * 3. **Estoque nulo continua nulo.** Nulo é "nunca informado"; zero diria "sem
 *    unidades", que é outra coisa — e zero bloquearia a publicação por um dado
 *    que ninguém digitou. Vira o aviso `estoque_desconhecido`.
 *
 * 4. **O título só é higienizado quando estoura o limite.**
 *    `PalavrasChaveService::ajustarTitulo()` é o cortador do módulo (e é o que
 *    se reaproveita, nunca um novo), mas ele TAMBÉM remove tudo o que não é
 *    letra, número ou espaço. Rodá-lo sempre transformaria "Cadeira 1,5m -
 *    Preta", já conferido por uma pessoa na Fase 1, em "Cadeira 15m Preta" sem
 *    avisar. Então ele só entra quando `"Kit {N} " + título` passa do máximo —
 *    aí o corte (e a higienização que vem com ele) é anunciado pelo aviso
 *    `titulo_cortado`.
 *
 * 5. **O SKU é cortado pelo COMEÇO.** O teto de 120 é o da coluna; o sufixo
 *    `-KIT{N}` é a parte que identifica o kit e nunca pode cair.
 *
 * 6. **Variante desligada no base entra no mapa, mas não gera aviso.** O clone
 *    copia TODAS as variantes (inclusive órfãs e desativadas): sem a linha no
 *    mapa, a variante do kit nasceria com estoque NULL. Mas estoque zero numa
 *    variante que o base não vende não é problema de ninguém.
 *
 * 7. **Planejamento × Fase N (decisões do usuário de 09/10/2026).** Quando o
 *    base é AGRUPADO (produto do Portal com cores), o Planejamento do Portal é a
 *    fonte das composições: cada variante que é cor do Portal recebe o SKU da
 *    oferta Combo N daquela cor (`-CB{N}`) — a que já existe, ou a que o Confirmar
 *    vai criar no Portal (`PlanejamentoDaFaseService::garantirOfertas`) — e o preço
 *    vem da Precificação dela (lido na hora pelo `DadosEfetivosService`, nunca
 *    gravado). O SKU do kit passa a `{SKU do base}-CB{N}`. Isso SUBSTITUI a decisão
 *    5 só para o base agrupado; o resto (produto do Publicador, base sem Portal)
 *    segue `-KIT{N}` e o aviso `preco_vazio`, como antes. Cada linha de variante
 *    ganha a chave `planejamento` (aditiva) e os avisos novos são
 *    `preco_do_planejamento` e `ofertas_novas_no_portal`.
 *
 * ⚠️ `pub_produtos.fase` é o NÚMERO da fase; `estrutura_ofertas.fase` é o TIPO
 * da oferta no Portal (`simples|combo|kit|combit`). Nada aqui faz JOIN entre as
 * duas, e nenhum SELECT desta classe usa a coluna `fase` sem qualificar.
 */
class PreviaDaFaseService
{
    /** `pub_produtos.sku` e o SELLER_SKU das variantes são `string(120)`. */
    public const MAX_SKU = 120;

    /** O que `PalavrasChaveService::titulo()` usa quando a categoria não diz nada. */
    public const MAX_TITULO_PADRAO = 60;

    /** `pub_produtos.quantidade_kit` é `unsignedSmallInteger`: o teto REAL é este, embora a §4 diga "sem teto". */
    public const MAX_QUANTIDADE = 65535;

    /** Quantos lugares o aviso de estoque zero nomeia antes de resumir ("e mais N"). */
    private const LUGARES_NO_AVISO = 5;

    public function __construct(private CategorySchemaRepository $schemas) {}

    // ═══ Os cálculos puros (§8: "funções puras com teste unitário") ══════════

    /**
     * `{sku base}-KIT{N}`, no teto de 120 da coluna. Corta o COMEÇO: o sufixo
     * é o que identifica o kit e nunca cai (decisão 5 do docblock).
     *
     * `$marca = 'CB'` dá o `{sku base}-CB{N}` do Planejamento (decisão 7).
     */
    public static function skuSugerido(string $skuBase, int $n, string $marca = 'KIT'): string
    {
        $sufixo = "-{$marca}{$n}";
        $limite = max(1, self::MAX_SKU - mb_strlen($sufixo));

        return mb_substr(trim($skuBase), 0, $limite).$sufixo;
    }

    /**
     * `{SELLER_SKU}-KIT{N}` da variante (§4). Variante do base sem SELLER_SKU
     * — produto simples recém-criado, por exemplo — usa o SKU do próprio kit:
     * deixar vazio faria o clone pular a linha e o kit ficaria sem código.
     */
    public static function sellerSkuSugerido(?string $sellerSkuBase, string $skuDoKit, int $n): string
    {
        $base = trim((string) $sellerSkuBase);
        if ($base === '') {
            return mb_substr($skuDoKit, 0, self::MAX_SKU);
        }

        return self::skuSugerido($base, $n);
    }

    /**
     * `"Kit {N} " + título da Fase 1`, cortado por palavra inteira quando passa
     * do `max_title_length` da categoria.
     *
     * Ver a decisão 4 do docblock: `ajustarTitulo()` só entra no ramo do corte,
     * porque ele também higieniza — e higienizar um título já conferido seria
     * uma mudança silenciosa.
     *
     * @return array{titulo: string, cortado: bool}
     */
    public static function tituloSugerido(string $tituloBase, int $n, int $maximo): array
    {
        $maximo = $maximo > 0 ? $maximo : self::MAX_TITULO_PADRAO;
        $bruto = trim("Kit {$n} ".trim($tituloBase));

        if (mb_strlen($bruto) <= $maximo) {
            return ['titulo' => $bruto, 'cortado' => false];
        }

        return ['titulo' => PalavrasChaveService::ajustarTitulo($bruto, $maximo), 'cortado' => true];
    }

    /**
     * `"Este kit contém {N} unidades de {nome}.\n\n" + descrição da Fase 1`.
     * Base sem descrição fica só com a frase, sem linhas em branco sobrando.
     */
    public static function descricaoSugerida(string $nomeBase, int $n, ?string $descricaoBase): string
    {
        $frase = "Este kit contém {$n} unidades de ".trim($nomeBase).'.';
        $doBase = trim((string) $descricaoBase);

        return $doBase === '' ? $frase : $frase."\n\n".$doBase;
    }

    /**
     * O estoque do kit a partir dos dados da variante do base: `floor(÷ N)` por
     * depósito quando há depósitos, senão no estoque simples.
     *
     * `zerou` traz os depósitos que caíram para 0. O zero do estoque inteiro NÃO
     * entra em `zerou` — quem monta o aviso vê isso em `estoque === 0`, e assim
     * `zerou` guarda só nomes de verdade.
     *
     * @param  array{estoque?: ?int, estoque_depositos?: ?array}  $dadosDaVariante
     * @return array{estoque: ?int, depositos: ?array<string, int>, zerou: list<string>}
     */
    public static function estoqueDoKit(array $dadosDaVariante, int $n): array
    {
        $n = max(1, $n);
        $depositos = $dadosDaVariante['estoque_depositos'] ?? null;

        if (is_array($depositos) && $depositos !== []) {
            $divididos = [];
            $zerou = [];
            foreach ($depositos as $deposito => $quantidade) {
                $valor = intdiv(max(0, (int) $quantidade), $n);
                $divididos[(string) $deposito] = $valor;
                if ($valor === 0) {
                    $zerou[] = (string) $deposito;
                }
            }

            // O `estoque` é a SOMA DOS DIVIDIDOS — é o que o editor grava e o
            // que o ML recebe por depósito. `floor(soma ÷ N)` daria outro número.
            return ['estoque' => array_sum($divididos), 'depositos' => $divididos, 'zerou' => $zerou];
        }

        $estoque = $dadosDaVariante['estoque'] ?? null;
        if ($estoque === null || ! is_numeric($estoque)) {
            // Nunca informado: continua nulo. Zero diria "sem unidades" (decisão 3).
            return ['estoque' => null, 'depositos' => null, 'zerou' => []];
        }

        return ['estoque' => intdiv(max(0, (int) $estoque), $n), 'depositos' => null, 'zerou' => []];
    }

    // ═══ A orquestração ══════════════════════════════════════════════════════

    /**
     * Tudo o que o painel "Criar Fase N" precisa mostrar — e exatamente o que o
     * `fases.criar` vai gravar. **Nada é escrito aqui.**
     *
     * @return array{quantidade: int, sku: string, titulo_por_tipo: array<string, string>,
     *     descricao: string, variantes: array<string, array{seller_sku: string, estoque: ?int,
     *     depositos: ?array<string, int>, ativa: bool}>, avisos: list<array{chave: string, mensagem: string}>,
     *     erro_campo: ?string, max_title_length: int, tipos: list<string>}
     */
    public function previa(PubProduto $base, int $quantidade): array
    {
        $avisos = [];
        // Decisão 7 (Planejamento × Fase N): base agrupado puxa SKU e preço do Planejamento do Portal.
        $doPlanejamento = $this->planejamento()->daFase($base, $quantidade);
        $sku = self::skuSugerido($base->skuExibido(), $quantidade, $doPlanejamento !== null ? 'CB' : 'KIT');
        $nome = $base->nomeExibido();
        $rascunho = $base->rascunho;

        [$maximo, $semCategoria] = $this->maxTitulo($rascunho);
        if ($semCategoria) {
            $avisos[] = self::aviso('sem_categoria', 'O produto base ainda não tem categoria confirmada, então o limite de caracteres do título é o padrão de 60. Confira o título depois de abrir o editor do kit.');
        }

        if ($rascunho === null) {
            // O painel só abre com o base publicado (§4), mas a prévia nunca explode:
            // a recusa de verdade é o KIT-01, no `CriarFaseService`.
            $avisos[] = self::aviso('sem_rascunho', 'Este produto ainda não tem um anúncio preparado. Abra a Fase 1 no editor antes de criar um kit.');

            return $this->payload($quantidade, $sku, [], self::descricaoSugerida($nome, $quantidade, null), [], $avisos, null, $maximo, []);
        }

        [$tipos, $titulos, $cortou] = $this->titulos($rascunho, $nome, $quantidade, $maximo);
        if ($cortou) {
            $avisos[] = self::aviso('titulo_cortado', "O título ficou maior que o limite desta categoria ({$maximo} caracteres) e foi cortado na última palavra inteira. Confira antes de confirmar.");
        }

        [$variantes, $lugaresEmZero, $temDesconhecido, $temZero] = $this->variantes($rascunho, $sku, $quantidade);
        if ($doPlanejamento !== null) {
            $variantes = self::comPlanejamento($variantes, $doPlanejamento);
        }
        if ($temDesconhecido) {
            $avisos[] = self::aviso('estoque_desconhecido', 'O produto base ainda não tem estoque informado, então o kit nasce sem estoque. Informe o estoque no editor do kit antes de publicar.');
        }
        if ($temZero) {
            $avisos[] = self::aviso('estoque_zero', $this->mensagemDeEstoqueZero($lugaresEmZero));
        }

        if ($this->skuRepetido($base, $sku)) {
            $avisos[] = self::aviso('sku_repetido', 'Já existe um produto com este SKU nesta empresa. Dá para seguir assim, mas vale confirmar se é o código que você quer.');
        }

        // Decisão do usuário em 2026-10-08 (`175-DECISOES.md` item 2): o kit nasce
        // sem preço e o painel avisa ANTES de confirmar. O aviso não bloqueia nada.
        // Decisão 7 (09/10): com o Planejamento o preço vem da Precificação do Portal,
        // e o `preco_vazio` fica só para as cores que ainda não têm preço lá.
        if ($doPlanejamento === null) {
            $avisos[] = self::aviso('preco_vazio', 'O kit vai nascer sem preço. Ele é criado normalmente, mas só vai para o ar depois que você informar o valor de cada tipo de anúncio no editor do kit.');
        } else {
            $avisos = [...$avisos, ...self::avisosDoPlanejamento($doPlanejamento, $variantes, $quantidade)];
        }

        return $this->payload(
            $quantidade,
            $sku,
            $titulos,
            self::descricaoSugerida($nome, $quantidade, $rascunho->descricao),
            $variantes,
            $avisos,
            $this->erroDaQuantidade($base, $quantidade),
            $maximo,
            $tipos,
        );
    }

    // ═══ Peças da orquestração ═══════════════════════════════════════════════

    /**
     * Decisão 7: a variante que é cor do Portal recebe o SKU da oferta Combo N daquela cor e a chave
     * `planejamento` (oferta, se é nova, a cor e o preço da Precificação). A que não é cor do Portal
     * (criada pela equipe no editor) fica com o `-KIT{N}` de sempre.
     *
     * @param  array<string, array>  $variantes
     * @param  array{por_variante: array<string, array>}  $doPlanejamento
     * @return array<string, array>
     */
    private static function comPlanejamento(array $variantes, array $doPlanejamento): array
    {
        foreach ($doPlanejamento['por_variante'] as $chave => $item) {
            if (! isset($variantes[$chave])) {
                continue;
            }
            $variantes[$chave]['seller_sku'] = mb_substr((string) $item['sku'], 0, self::MAX_SKU);
            $variantes[$chave]['planejamento'] = [
                'oferta_id' => $item['oferta_id'],
                'nova' => (bool) $item['nova'],
                'cor' => (string) $item['cor'],
                'precos' => $item['precos'],
            ];
        }

        return $variantes;
    }

    /**
     * Os avisos de preço com o Planejamento (decisão 7), no lugar do `preco_vazio` fixo:
     * - `ofertas_novas_no_portal`: as ofertas Combo N que o Confirmar vai criar no Portal;
     * - `preco_do_planejamento`: o preço de cada cor vem da Precificação (nunca é copiado);
     * - `preco_vazio`: só as cores ATIVAS sem preço lá, e as variações que não são cor do Portal.
     *
     * @param  array{por_variante: array<string, array>, sem_cor: list<string>}  $doPlanejamento
     * @param  array<string, array>  $variantes
     * @return list<array{chave: string, mensagem: string}>
     */
    private static function avisosDoPlanejamento(array $doPlanejamento, array $variantes, int $n): array
    {
        $avisos = [];
        $novas = [];
        $comPreco = 0;
        $semPreco = [];
        foreach ($doPlanejamento['por_variante'] as $chave => $item) {
            if ($item['nova']) {
                $novas[] = (string) $item['sku'];

                continue;
            }
            if (($variantes[$chave]['ativa'] ?? true) === false) {
                continue;
            }
            if (array_filter((array) $item['precos'], fn ($p) => $p !== null) !== []) {
                $comPreco++;
            } else {
                $semPreco[] = (string) $item['cor'];
            }
        }

        if ($novas !== []) {
            $avisos[] = self::aviso('ofertas_novas_no_portal', 'Ao confirmar, o Portal ganha '.count($novas)." oferta(s) Combo {$n}, uma por cor ("
                .implode(', ', $novas).'), na Precificação do Portal. O preço do kit sai de lá: confira o custo e o frete delas.');
        }
        if ($comPreco > 0) {
            $avisos[] = self::aviso('preco_do_planejamento', "O preço de cada cor vem da Precificação do Portal (Combo {$n}). Ele não é copiado para o kit: se mudar lá, muda aqui.");
        }
        $semCor = array_values(array_filter($doPlanejamento['sem_cor'], fn (string $c) => ($variantes[$c]['ativa'] ?? true) !== false));
        if ($semPreco !== [] || $semCor !== []) {
            $partes = [];
            if ($semPreco !== []) {
                $partes[] = 'a Precificação do Portal ainda não tem preço para '.implode(', ', $semPreco);
            }
            if ($semCor !== []) {
                $partes[] = count($semCor).' variação(ões) não são cor do Portal';
            }
            $avisos[] = self::aviso('preco_vazio', ucfirst(implode('; ', $partes)).'. O kit é criado normalmente, mas essas variações só vão para o ar depois que o preço for informado (na Precificação do Portal ou no editor do kit).');
        }

        return $avisos;
    }

    /** Resolvido sob demanda: nenhuma dependência nova no construtor (o módulo tem ciclos conhecidos no container). */
    private function planejamento(): PlanejamentoDaFaseService
    {
        return app(PlanejamentoDaFaseService::class);
    }

    /**
     * @param  array<string, string>  $titulos
     * @param  array<string, array>  $variantes
     * @param  list<array{chave: string, mensagem: string}>  $avisos
     * @param  list<string>  $tipos
     */
    private function payload(int $quantidade, string $sku, array $titulos, string $descricao, array $variantes, array $avisos, ?string $erroCampo, int $maximo, array $tipos): array
    {
        return [
            'quantidade' => $quantidade,
            'sku' => $sku,
            'titulo_por_tipo' => $titulos,
            'descricao' => $descricao,
            'variantes' => $variantes,
            'avisos' => array_values($avisos),
            'erro_campo' => $erroCampo,
            'max_title_length' => $maximo,
            'tipos' => array_values($tipos),
        ];
    }

    /** @return array{chave: string, mensagem: string} */
    private static function aviso(string $chave, string $mensagem): array
    {
        // Sempre as MESMAS duas chaves, sempre string: a tela exibe `mensagem`
        // direto, e campo que chega como objeto derruba a página.
        return ['chave' => $chave, 'mensagem' => $mensagem];
    }

    /**
     * O `max_title_length` da categoria do base, com fallback 60.
     *
     * Vem do cache de `ml_categoria_schemas` quando a categoria já foi vista; se
     * o ML estiver fora e não houver nada guardado, `obter()` lança `RegraViolada`
     * (V-CAT-03) e a prévia segue com o padrão e um aviso — nunca um 500.
     *
     * ⚠️ O `catch` é de `\Throwable` de propósito, e não só de `RegraViolada`:
     * a camada de token do ML (`ClienteMlPublicador` → `MlColetaService`) lança
     * `RuntimeException` quando a resposta vem sem `access_token`, e isso
     * derrubaria a prévia inteira por um número COSMÉTICO. O limite padrão 60
     * mais o aviso é a resposta certa para qualquer falha de leitura aqui.
     *
     * @return array{0: int, 1: bool} o máximo e se faltou categoria
     */
    private function maxTitulo(?PubRascunho $rascunho): array
    {
        if ($rascunho === null || ! $rascunho->categoria_id) {
            return [self::MAX_TITULO_PADRAO, true];
        }

        try {
            $maximo = (int) ($this->schemas->obter((string) $rascunho->categoria_id)->settings()['max_title_length'] ?? 0);

            return $maximo > 0 ? [$maximo, false] : [self::MAX_TITULO_PADRAO, false];
        } catch (\Throwable $e) {
            Log::warning('[Publicador] prévia da fase sem max_title_length de '.$rascunho->categoria_id.': '.$e->getMessage());

            return [self::MAX_TITULO_PADRAO, true];
        }
    }

    /**
     * Os tipos de anúncio ATIVOS da Fase 1 (§4: "Tipos iguais aos da Fase 1") e o
     * título do kit para cada um.
     *
     * Alvo sem título no base (ele herdaria o planejado na hora de publicar) parte
     * do NOME do produto: devolver vazio deixaria o campo do painel em branco, e o
     * operador não teria de onde partir.
     *
     * @return array{0: list<string>, 1: array<string, string>, 2: bool}
     */
    private function titulos(PubRascunho $rascunho, string $nome, int $quantidade, int $maximo): array
    {
        $tipos = [];
        $titulos = [];
        $cortou = false;

        foreach ($rascunho->alvos()->where('ativo', true)->orderBy('posicao')->orderBy('id')->get() as $alvo) {
            $tipos[] = (string) $alvo->listing_type_id;
            $doBase = trim((string) $alvo->titulo);
            $r = self::tituloSugerido($doBase !== '' ? $doBase : $nome, $quantidade, $maximo);
            $titulos[(string) $alvo->listing_type_id] = $r['titulo'];
            $cortou = $cortou || $r['cortado'];
        }

        return [$tipos, $titulos, $cortou];
    }

    /**
     * Uma linha por variante do base — TODAS, inclusive as desligadas e as órfãs,
     * porque o clone copia todas e sem a linha a variante do kit nasceria sem
     * estoque (decisão 6 do docblock).
     *
     * `$lugares` são os nomes para o aviso; `$temZero` diz SE houve zero. Os dois
     * são separados porque o produto simples não tem nome de variação a mostrar —
     * e sem o booleano o zero dele sairia calado.
     *
     * @return array{0: array<string, array>, 1: list<string>, 2: bool, 3: bool}
     */
    private function variantes(PubRascunho $rascunho, string $sku, int $quantidade): array
    {
        $variantes = [];
        $lugares = [];
        $desconhecido = false;
        $temZero = false;

        $doBase = $rascunho->variantes()->with(['atributos', 'valoresDosEixos'])->orderBy('posicao')->orderBy('id')->get();

        foreach ($doBase as $v) {
            /** @var PubVariante $v */
            $chave = (string) $v->combinacao_chave;
            $calculado = self::estoqueDoKit(
                ['estoque' => $v->estoque, 'estoque_depositos' => $v->estoque_depositos],
                $quantidade,
            );

            $variantes[$chave] = [
                'seller_sku' => self::sellerSkuSugerido($this->sellerSkuDaVariante($v), $sku, $quantidade),
                'estoque' => $calculado['estoque'],
                'depositos' => $calculado['depositos'],
                'ativa' => (bool) $v->ativa,
            ];

            // Variante desligada no base não vende: zero nela não é problema de ninguém.
            if (! $v->ativa) {
                continue;
            }
            if ($calculado['estoque'] === null) {
                $desconhecido = true;

                continue;
            }

            $rotulo = $this->rotuloDaVariante($v, $doBase->count());
            foreach ($calculado['zerou'] as $deposito) {
                $temZero = true;
                $lugares[] = $rotulo === null ? "depósito {$deposito}" : "{$rotulo} — depósito {$deposito}";
            }
            if ($calculado['estoque'] === 0 && $calculado['zerou'] === []) {
                $temZero = true;
                $lugares[] = $rotulo;
            }
        }

        return [
            $variantes,
            array_values(array_unique(array_filter($lugares, fn ($l) => $l !== null))),
            $desconhecido,
            $temZero,
        ];
    }

    /**
     * O rótulo humano da variante: os valores dos eixos separados por " / ",
     * como a grade do editor mostra. NULL no produto simples (`__single__`) e
     * quando a variante é a única — não há variação a nomear.
     *
     * ⚠️ `combinacao_chave` (`COLOR=id:52049|SIZE=txt:m`) é identidade interna e
     * **nunca** vai para a tela.
     */
    private function rotuloDaVariante(PubVariante $v, int $total): ?string
    {
        if ($v->combinacao_chave === ChaveCanonica::UNICA || $total <= 1) {
            return null;
        }

        $nomes = $v->valoresDosEixos->pluck('value_name')->filter()->values()->all();

        return $nomes === [] ? null : 'variação '.implode(' / ', $nomes);
    }

    private function sellerSkuDaVariante(PubVariante $v): ?string
    {
        return $v->atributos->firstWhere('attribute_id', 'SELLER_SKU')?->value_name;
    }

    /** @param  list<string>  $lugares */
    private function mensagemDeEstoqueZero(array $lugares): string
    {
        $fim = 'Você pode criar o kit assim, mas ele só vai para o ar com estoque maior que zero.';

        // Produto simples, estoque inteiro em zero: não há lugar a nomear.
        if ($lugares === []) {
            return "O estoque calculado do kit ficou em zero. {$fim}";
        }

        $mostrados = array_slice($lugares, 0, self::LUGARES_NO_AVISO);
        $sobrando = count($lugares) - count($mostrados);
        $lista = implode('; ', $mostrados).($sobrando > 0 ? " e mais {$sobrando}" : '');

        return "O estoque calculado do kit ficou em zero em: {$lista}. {$fim}";
    }

    /**
     * SKU já usado nesta empresa: AVISO, não bloqueio — a mesma regra do
     * `criarProduto`, com a mesma comparação sem caixa.
     *
     * O escopo vem das ÂNCORAS DO BASE (`mlb_empresa_id`/`company_id`), nunca de
     * nada vindo da requisição: é a mesma disciplina do `produtosQuery()`.
     */
    private function skuRepetido(PubProduto $base, string $sku): bool
    {
        if ($base->mlb_empresa_id === null && $base->company_id === null) {
            return false;
        }

        return PubProduto::query()
            ->where(function ($q) use ($base) {
                if ($base->mlb_empresa_id !== null) {
                    $q->orWhere('mlb_empresa_id', $base->mlb_empresa_id);
                }
                if ($base->company_id !== null) {
                    $q->orWhere('company_id', $base->company_id);
                }
            })
            ->whereRaw('LOWER(sku) = ?', [mb_strtolower($sku)])
            ->exists();
    }

    /**
     * O ÚNICO erro de campo da prévia (§4: "Duplicado: erro no campo"). Mesma
     * mensagem do KIT-04 do `CriarFaseService` — a recusa de verdade é lá, sob
     * trava; aqui é só para o painel marcar o campo antes de o operador clicar.
     */
    private function erroDaQuantidade(PubProduto $base, int $quantidade): ?string
    {
        $repetida = $base->familia()->contains(
            fn (PubProduto $p) => (int) $p->quantidade_kit === $quantidade && $p->produto_base_id !== null,
        );

        return $repetida ? "Já existe Kit {$quantidade} deste produto." : null;
    }
}
