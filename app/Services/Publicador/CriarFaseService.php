<?php

namespace App\Services\Publicador;

use App\Models\PubImagem;
use App\Models\PubProduto;
use App\Models\PubRascunho;
use App\Models\PubVariante;
use App\Support\Publicador\RegraViolada;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * A Fase N de um produto (§5 da ETAPA-3): cria o `PubProduto` do kit e copia o
 * rascunho do produto base inteiro, numa transação.
 *
 * Padrão do `duplicarComoTemplate` do assistente antigo: **copia conteúdo,
 * nunca histórico**. Vem o que a pessoa conferiu — categoria, condição,
 * atributos, eixos, valores, variantes com a combinação, alvos, envio,
 * garantia, descrição, fotos e atribuições — e NÃO vem nada de
 * `pub_publicacoes`, `pub_publicacao_itens`, `pub_validacoes`, nem kit de
 * criativos do base. O kit nasce `DRAFT`, revisão 1.
 *
 * ═══ O que este serviço NÃO decide ═══════════════════════════════════════════
 *
 * SKU, SELLER_SKU por variante, título por tipo, descrição e estoque calculado
 * são de quem chama (a prévia do 175-05): aqui se **grava o que se recebeu**. O
 * serviço decide sozinho só duas coisas — a `fase` (`max(fase da família) + 1`)
 * e as quatro recusas (`KIT-01` a `KIT-04`).
 *
 * As âncoras (`mlb_empresa_id`, `company_id`) são copiadas do BASE e nunca lidas
 * de `$dados` (T-175-05): é o que impede criar kit numa conta alheia mandando a
 * âncora no corpo da requisição.
 *
 * ═══ Por que cada decisão está assim ════════════════════════════════════════
 *
 * 1. **`dominio_id` e `schema_hash` copiados à mão.** A §5 não cita os dois.
 *    Sem eles o kit abre renegociando o schema da categoria no ML (o
 *    `EditorRascunhoService::abrir()` regrava a categoria quando há
 *    `categoria_id` sem `schema_hash`) e o V-CAT-03 acusa mudança que não houve.
 *
 * 2. **`step_state` NÃO é copiado.** É o progresso de etapas do editor do base;
 *    o kit começa a própria conferência do zero (a §4 manda abrir o editor do kit
 *    na etapa "Condições de venda", e isso é da tela, não do banco).
 *
 * 3. **`pub_variante_eixo_valores` remapeada.** A tabela não aparece na §5 e tem
 *    PK composta `(variante_id, eixo_id)`, sem `id` — então se insere por
 *    `DB::table()->insert()`, com `eixo_id` E `eixo_valor_id` apontando para as
 *    linhas NOVAS. Sem o remapeamento a variante do kit nasce **sem combinação**
 *    e a grade do editor fica inutilizável.
 *
 * 4. **As fotos ganham bytes próprios.** `ImagemAssetService::remover()` faz
 *    `Storage::delete($imagem->caminho)` e só depois `$imagem->delete()`, e o
 *    caminho é namespaced por rascunho (`publicador/{rascunho_id}/{sha}.ext`).
 *    Copiar a linha de `pub_imagens` com o MESMO `caminho` — "mesmo arquivo, sem
 *    reupload", como a §5 escreve ao pé da letra — faria "tirar uma foto do kit"
 *    APAGAR o arquivo debaixo do produto base, que ficaria com linha apontando
 *    para arquivo inexistente (T-175-04). Então copiamos os BYTES para
 *    `publicador/{rascunho_do_kit}/{sha}.ext` e PRESERVAMOS `ml_picture_id`,
 *    `ml_url` e `upload_status`: é a mesma conta do Mercado Livre, a foto já está
 *    lá, e preservar os ids entrega exatamente o "sem reupload" que a §5 queria,
 *    sem compartilhar arquivo. O unique `(rascunho_id, sha256)` de `pub_imagens`
 *    garante estruturalmente que dois rascunhos nunca compartilhem caminho.
 *
 * 5. **`receber()` e `enviarAoMl()` são PROIBIDOS aqui** (T-175-06): `receber()`
 *    revalida a imagem e TERMINA enviando ao ML — HTTP dentro da transação e
 *    cota de foto gasta de novo, por uma foto que já está na conta.
 *
 * 6. **`travar()` é o primeiro passo dentro da transação**, leitura depois
 *    (disciplina WR-B02, T-175-07): o editor do base pode estar escrevendo ao
 *    mesmo tempo, e o clone precisa ler o que ele gravou, não um meio-caminho.
 *
 * 7. **`identificacao` é coluna MORTA.** Nada em `app/` lê ou grava
 *    `pub_rascunhos.identificacao` hoje. A §5 manda copiar: copiamos — é
 *    inofensivo e inútil — só para não divergir da spec sem avisar.
 *
 * 8. **`oferta_id` NULL nos dois lugares.** No produto porque o SKU do kit existe
 *    só no Publicador e não vai para o Portal/Mapeamento (decisão 5 do
 *    `DECISOES.md`); no rascunho porque `pub_rascunhos.oferta_id` é coluna legada
 *    dormente (D27). Consequência medida: preço nulo do kit NÃO herda da
 *    Precificação (ela vem da oferta) — fica realmente vazio, como a §5 pede.
 *
 * 9. **Planejamento × Fase N (decisões do usuário de 09/10/2026).** Para o base
 *    AGRUPADO (produto do Portal com cores), a decisão 5 cai: o Planejamento é a
 *    fonte das composições. Dentro da MESMA transação do kit,
 *    `PlanejamentoDaFaseService::garantirOfertas()` cria no Portal as ofertas
 *    Combo N das cores que ainda não têm (pela Lista SKUs) e o SELLER_SKU de cada
 *    cor passa a ser o da oferta — o que vier de quem chama para essas variantes
 *    é trocado. Se o kit não nascer, as ofertas também não. O `oferta_id` do kit
 *    continua NULL (são N ofertas, uma por cor; o vínculo é derivado pela cor) e o
 *    preço segue sem ser gravado: o `DadosEfetivosService` o lê da Precificação
 *    de cada oferta, na hora.
 *
 * ⚠️ `pub_produtos.fase` é o NÚMERO da fase; `estrutura_ofertas.fase` é o TIPO da
 * oferta no Portal (`simples|combo|kit|combit`). Qualificar a tabela em todo SELECT.
 */
class CriarFaseService
{
    /**
     * O disco privado das fotos do Publicador. Espelha `ImagemAssetService::DISCO`,
     * que é `private` lá — e este plano não mexe naquele arquivo. Os dois têm de
     * continuar iguais: se um dia o disco mudar, muda nos dois.
     */
    private const DISCO = 'local';

    /** O EAN é da UNIDADE, não do kit: o GTIN nasce vazio no produto e em cada variante (§5). */
    private const GTIN = 'GTIN';

    /** O SKU da variante, trocado pelo do kit (`{SELLER_SKU}-KIT{N}`, calculado por quem chama). */
    private const SELLER_SKU = 'SELLER_SKU';

    /**
     * Medidas e peso do pacote: copiados e MARCADOS PARA REVISÃO (§5) — N unidades
     * mudam a caixa, e publicar com a medida da unidade erra o frete.
     *
     * Lista explícita, nunca regex sobre nome de atributo. Os `SELLER_PACKAGE_*`
     * são os que o Publicador grava (seção EMBALAGEM do `ClassificadorAtributos`);
     * os `PACKAGE_*` são os nativos do ML, que aparecem em categoria que os exige.
     */
    private const MEDIDAS_DO_PACOTE = [
        'SELLER_PACKAGE_HEIGHT',
        'SELLER_PACKAGE_WIDTH',
        'SELLER_PACKAGE_LENGTH',
        'SELLER_PACKAGE_WEIGHT',
        'PACKAGE_HEIGHT',
        'PACKAGE_WIDTH',
        'PACKAGE_LENGTH',
        'PACKAGE_WEIGHT',
    ];

    /**
     * O resultado da CAPA da última chamada a `criar()` — `null` quando a capa
     * não foi pedida.
     *
     * Por que uma propriedade e não o retorno: `criar()` devolve o `PubProduto`
     * do kit, e esse contrato é lido por quem já chama. A capa é um EFEITO de
     * fora da transação, que não pode desfazer a fase criada nem mudar o
     * retorno; quem precisa do motivo (o endpoint, para a resposta) lê daqui.
     * Uma chamada por requisição — este serviço é resolvido por requisição.
     *
     * @var array{ok: bool, motivo: ?string, kit_id: ?int}|null
     */
    public ?array $resultadoDaCapa = null;

    public function __construct(
        private RascunhoRepository $repo,
        private CapaDoKitService $capa,
    ) {}

    /**
     * Cria a próxima fase (o kit de N unidades) do produto base.
     *
     * @param  array{quantidade: int, sku?: ?string, seller_skus?: array<string, ?string>,
     *     titulo_por_tipo?: array<string, ?string>, descricao?: ?string,
     *     estoque_por_variante?: array<string, array{estoque?: ?int, depositos?: ?array}>,
     *     ator?: ?array, capa?: ?bool, user?: ?\App\Models\User}  $dados  tudo já
     *     calculado por quem chama (a prévia do 175-05)
     *
     * @throws RegraViolada KIT-06 (composto do Planejamento), KIT-01 (base sem rascunho),
     *                      KIT-02 (base que já é kit), KIT-03 (quantidade < 2), KIT-04 (Kit N já existe)
     */
    public function criar(PubProduto $base, array $dados): PubProduto
    {
        $quantidade = (int) ($dados['quantidade'] ?? 0);
        $rascunhoDoBase = $base->rascunho;
        $this->resultadoDaCapa = null;

        // ── 1. Recusas, ANTES de qualquer escrita ────────────────────────────
        // KIT-06 primeiro: para um composto, o conselho do KIT-01 ("abra a Fase 1") seria o errado.
        $this->recusarComposto($base);
        if ($rascunhoDoBase === null) {
            throw new RegraViolada('KIT-01', 'Este produto ainda não tem um anúncio preparado. Abra a Fase 1 no editor antes de criar um kit.');
        }
        if ($base->ehKit()) {
            throw new RegraViolada('KIT-02', 'Este produto já é um kit. Crie a fase nova a partir do produto base (1 unidade).');
        }
        if ($quantidade < 2) {
            throw new RegraViolada('KIT-03', 'Um kit tem 2 unidades ou mais.', ['campo' => 'quantidade']);
        }
        $this->recusarQuantidadeRepetida($base->familia(), $quantidade);

        // ── 2. A transação. Arquivo escrito no disco é anotado: o banco volta
        //       sozinho num rollback, o disco não.
        $escritos = [];

        try {
            $kit = DB::transaction(function () use ($base, $rascunhoDoBase, $dados, $quantidade, &$escritos) {
                // WR-B02: travar PRIMEIRO, ler DEPOIS — o editor do base pode estar gravando agora.
                $this->repo->travar($rascunhoDoBase);

                $rb = $rascunhoDoBase->fresh();
                $rb->load(['alvos', 'atributos', 'eixos.valores', 'variantes.atributos', 'imagens.atribuicoes']);

                $familia = $base->familia();
                // Relido sob a trava: a conferência de fora pode ter envelhecido.
                $this->recusarQuantidadeRepetida($familia, $quantidade);

                // Decisão 9: base agrupado — o SKU de cada cor é o da oferta Combo N do Portal, e a que
                // falta nasce lá agora, nesta transação (idempotente, sob a trava da Company).
                $user = ($dados['user'] ?? null) instanceof \App\Models\User ? $dados['user'] : null;
                $doPlanejamento = $this->planejamento()->garantirOfertas($base, $quantidade, $user);
                if ($doPlanejamento !== []) {
                    $dados['seller_skus'] = array_replace((array) ($dados['seller_skus'] ?? []), $doPlanejamento);
                }

                $kit = $this->criarProdutoDoKit($base, $dados, $quantidade);
                $rk = $this->criarRascunhoDoKit($rb, $kit, $dados);

                $mapaDeAlvos = $this->copiarAlvos($rb, $rk, $dados);
                $this->copiarAtributos($rb, $rk);
                $mapaDeEixos = $this->copiarEixos($rb, $rk);
                $this->copiarVariantes($rb, $rk, $dados, $mapaDeEixos, $mapaDeAlvos);
                $this->copiarImagens($rb, $rk, $escritos);

                return $kit->fresh('rascunho');
            });
        } catch (\Throwable $e) {
            $this->resultadoDaCapa = null;

            // O banco já voltou; o disco não. Órfão residual é inócuo (caminho por
            // sha, sem linha apontando), mas não se deixa de propósito (T-175-08).
            $this->apagarArquivos($escritos);

            // Corrida no unique `pubprod_base_qtd_uq`: o outro processo criou o mesmo
            // Kit N entre a conferência e o insert. Mesma mensagem de campo do KIT-04.
            if ($e instanceof QueryException && $e->getCode() === '23000') {
                throw new RegraViolada('KIT-04', "Já existe Kit {$quantidade} deste produto.", ['campo' => 'quantidade']);
            }

            throw $e;
        }

        // ── 3. A CAPA (§5), **fora** da transação e nunca dentro dela: o
        //       planejamento enfileira job, pega lock de cache e lê disco — nada
        //       disso volta atrás num rollback, e segurar a transação enquanto
        //       isso acontece prenderia a trava do rascunho do base.
        //
        //       Falha da capa NÃO desfaz a fase: a fase criada é o trabalho da
        //       pessoa, a capa é um extra que ela pode pedir de novo depois. O
        //       motivo volta em `$resultadoDaCapa`, para a resposta do endpoint.
        if (($dados['capa'] ?? false) && ($dados['user'] ?? null) instanceof \App\Models\User) {
            $this->resultadoDaCapa = $this->capa->planejar($kit, $dados['user']);
        }

        return $kit;
    }

    /**
     * KIT-06 (Planejamento × Fase N, decisões do usuário de 09/10/2026): o produto ligado a uma oferta
     * Combo/Kit/Combit do Portal é uma composição pronta do Planejamento, não a Fase 1 de um produto —
     * criar "Kit 2" dele seria o Kit 2 de um combo. Público para o endpoint recusar ANTES do KIT-05
     * ("publique a Fase 1"), que daria o conselho errado.
     *
     * @throws RegraViolada KIT-06
     */
    public function recusarComposto(PubProduto $base): void
    {
        $tipo = PlanejamentoDaFaseService::tipoComposto($base);
        if ($tipo !== null) {
            throw new RegraViolada('KIT-06', PlanejamentoDaFaseService::motivoKit06($tipo));
        }
    }

    /**
     * Resolvido sob demanda, nunca no construtor: os testes deste módulo constroem o serviço à mão
     * com os dois argumentos de hoje, e o container do módulo tem ciclos conhecidos.
     */
    private function planejamento(): PlanejamentoDaFaseService
    {
        return app(PlanejamentoDaFaseService::class);
    }

    // ═══ O produto e o rascunho do kit ═══════════════════════════════════════

    /** @param  \Illuminate\Support\Collection<int, PubProduto>  $familia */
    private function recusarQuantidadeRepetida($familia, int $quantidade): void
    {
        if ($familia->contains(fn (PubProduto $p) => (int) $p->quantidade_kit === $quantidade && $p->produto_base_id !== null)) {
            throw new RegraViolada('KIT-04', "Já existe Kit {$quantidade} deste produto.", ['campo' => 'quantidade']);
        }
    }

    private function criarProdutoDoKit(PubProduto $base, array $dados, int $quantidade): PubProduto
    {
        $sku = trim((string) ($dados['sku'] ?? ''));

        return PubProduto::create([
            // Âncoras do BASE, nunca de `$dados` (T-175-05).
            'mlb_empresa_id' => $base->mlb_empresa_id,
            'company_id' => $base->company_id,
            // O SKU do kit existe só no Publicador: sem oferta no Portal (decisão 5).
            'oferta_id' => null,
            'sku' => mb_substr($sku !== '' ? $sku : "{$base->sku}-KIT{$quantidade}", 0, 120),
            'nome' => mb_substr("Kit {$quantidade} ".$base->nomeExibido(), 0, 255),
            'origem' => PubProduto::ORIGEM_PUBLICADOR,
            'produto_base_id' => $base->id,
            'quantidade_kit' => $quantidade,
            // Kit N é a Fase N: o degrau vem da quantidade, não da ordem de criação.
            'fase' => PubProduto::faseDaQuantidade($quantidade),
            // O estoque do kit é derivado do base (floor ÷ N), nunca digitado.
            'estoque_calculado' => true,
        ]);
    }

    private function criarRascunhoDoKit(PubRascunho $base, PubProduto $kit, array $dados): PubRascunho
    {
        return PubRascunho::create([
            'produto_id' => $kit->id,
            // Nasce do zero: nenhuma validação nem publicação do base vale para o kit.
            'status' => PubRascunho::DRAFT,
            'revisao' => 1,
            'step_state' => null,
            'conta_checada_em' => null,
            'oferta_id' => null,
            'ator' => $dados['ator'] ?? null,

            // Conteúdo copiado do base.
            'categoria_id' => $base->categoria_id,
            // Sem estes dois o kit renegocia o schema da categoria na primeira abertura.
            'dominio_id' => $base->dominio_id,
            'schema_hash' => $base->schema_hash,
            'condicao' => $base->condicao,
            'envio' => $base->envio,
            'garantia' => $base->garantia,
            'fotos_por_variante' => $base->fotos_por_variante,
            'incluir_geral_nas_variantes' => $base->incluir_geral_nas_variantes,
            'modelo_publicacao' => $base->modelo_publicacao,
            // Coluna MORTA (nada em `app/` lê ou grava): copiada só para não divergir da §5.
            'identificacao' => $base->identificacao,

            // Trocado por quem chama.
            'descricao' => $dados['descricao'] ?? null,
        ]);
    }

    // ═══ As partes do rascunho ═══════════════════════════════════════════════

    /**
     * Os mesmos tipos de anúncio do base (§4: "Tipos iguais aos da Fase 1"), com o
     * título do kit. Devolve `alvo_id antigo → novo`: os preços dependem do mapa.
     *
     * @return array<int, int>
     */
    protected function copiarAlvos(PubRascunho $base, PubRascunho $kit, array $dados): array
    {
        $porTipo = (array) ($dados['titulo_por_tipo'] ?? []);
        $mapa = [];

        foreach ($base->alvos as $a) {
            $titulo = trim((string) ($porTipo[$a->listing_type_id] ?? ''));
            $novo = $kit->alvos()->create([
                'listing_type_id' => $a->listing_type_id,
                // Título vazio continua vazio: na hora de publicar ele herda o planejado.
                'titulo' => $titulo === '' ? null : mb_substr($titulo, 0, 255),
                'ativo' => $a->ativo,
                'posicao' => $a->posicao,
            ]);
            $mapa[$a->id] = $novo->id;
        }

        return $mapa;
    }

    /** Atributos do PRODUTO: todos, menos o GTIN; medidas do pacote vão marcadas para revisão. */
    protected function copiarAtributos(PubRascunho $base, PubRascunho $kit): void
    {
        foreach ($base->atributos as $a) {
            if ($a->attribute_id === self::GTIN) {
                continue;
            }

            $kit->atributos()->create([
                'attribute_id' => $a->attribute_id,
                'value_id' => $a->value_id,
                'value_name' => $a->value_name,
                'value_number' => $a->value_number,
                'value_unit' => $a->value_unit,
                'values_multi' => $a->values_multi,
                'origem' => $a->origem,
                'revisar' => $a->revisar || in_array($a->attribute_id, self::MEDIDAS_DO_PACOTE, true),
            ]);
        }
    }

    /**
     * Eixos e valores, com `chave`/`chave_hash`/`removido` preservados (valor
     * removido continua existindo: variante órfã ainda aponta para ele).
     *
     * @return array{eixos: array<int, int>, valores: array<int, int>} os dois mapas antigo → novo
     */
    protected function copiarEixos(PubRascunho $base, PubRascunho $kit): array
    {
        $mapaEixos = [];
        $mapaValores = [];

        foreach ($base->eixos as $e) {
            $novo = $kit->eixos()->create([
                'attribute_id' => $e->attribute_id,
                'nome' => $e->nome,
                'posicao' => $e->posicao,
                'defines_picture' => $e->defines_picture,
                'removido' => $e->removido,
            ]);
            $mapaEixos[$e->id] = $novo->id;

            foreach ($e->valores as $v) {
                $novoValor = $novo->valores()->create([
                    'value_id' => $v->value_id,
                    'value_name' => $v->value_name,
                    'chave' => $v->chave,
                    'chave_hash' => $v->chave_hash,
                    'posicao' => $v->posicao,
                    'removido' => $v->removido,
                ]);
                $mapaValores[$v->id] = $novoValor->id;
            }
        }

        return ['eixos' => $mapaEixos, 'valores' => $mapaValores];
    }

    /**
     * Variantes, a combinação de cada uma (remapeada), os atributos de variante e
     * os preços vazios.
     *
     * @param  array{eixos: array<int, int>, valores: array<int, int>}  $mapaDeEixos
     * @param  array<int, int>  $mapaDeAlvos
     */
    protected function copiarVariantes(PubRascunho $base, PubRascunho $kit, array $dados, array $mapaDeEixos, array $mapaDeAlvos): void
    {
        $skus = (array) ($dados['seller_skus'] ?? []);
        $estoques = (array) ($dados['estoque_por_variante'] ?? []);

        foreach ($base->variantes as $v) {
            $chave = $v->combinacao_chave;
            $estoque = (array) ($estoques[$chave] ?? []);

            $nova = $kit->variantes()->create([
                'combinacao_chave' => $chave,
                'combinacao_hash' => $v->combinacao_hash,
                'ativa' => $v->ativa,
                'orfa' => $v->orfa,
                // O kit nunca foi publicado: nenhuma variante dele nasce publicada.
                'publicada' => false,
                // Estoque calculado por quem chama (floor ÷ N, por variante E por depósito).
                // Ausente = NULL ("não informado"), nunca o estoque do base — que seria o número errado.
                'estoque' => isset($estoque['estoque']) && is_numeric($estoque['estoque']) ? (int) $estoque['estoque'] : null,
                'estoque_depositos' => $estoque['depositos'] ?? null,
                'posicao' => $v->posicao,
            ]);

            $this->copiarCombinacao($v->id, $nova->id, $mapaDeEixos, $base->id);
            $this->copiarAtributosDaVariante($v, $nova, $skus[$chave] ?? null);

            // "Preços vazios" da §5: uma linha por alvo NOVO, sem valor. Nulo não
            // herda da Precificação aqui — o kit não tem oferta no Portal.
            foreach ($mapaDeAlvos as $alvoNovo) {
                $nova->precos()->create(['alvo_id' => $alvoNovo, 'preco' => null]);
            }
        }
    }

    /**
     * ⚠️ `pub_variante_eixo_valores`: PK composta `(variante_id, eixo_id)`, sem `id`
     * — insert por query builder. `eixo_id` E `eixo_valor_id` REMAPEADOS; sem isso a
     * variante do kit nasce sem combinação.
     *
     * @param  array{eixos: array<int, int>, valores: array<int, int>}  $mapaDeEixos
     */
    private function copiarCombinacao(int $varianteAntiga, int $varianteNova, array $mapaDeEixos, int $rascunhoDoBase): void
    {
        $linhas = [];

        foreach (DB::table('pub_variante_eixo_valores')->where('variante_id', $varianteAntiga)->get() as $pivo) {
            $eixo = $mapaDeEixos['eixos'][$pivo->eixo_id] ?? null;
            $valor = $mapaDeEixos['valores'][$pivo->eixo_valor_id] ?? null;

            if ($eixo === null || $valor === null) {
                // Falha ALTO: silenciar aqui deixaria o kit com variante sem combinação.
                throw new \RuntimeException("A variante {$varianteAntiga} aponta para eixo/valor fora do rascunho {$rascunhoDoBase}: o clone não pode remapear a combinação.");
            }

            $linhas[] = ['variante_id' => $varianteNova, 'eixo_id' => $eixo, 'eixo_valor_id' => $valor];
        }

        if ($linhas !== []) {
            DB::table('pub_variante_eixo_valores')->insert($linhas);
        }
    }

    /** Dados da variante: SELLER_SKU trocado pelo do kit, GTIN vazio, o resto copiado. */
    private function copiarAtributosDaVariante(PubVariante $antiga, PubVariante $nova, ?string $sellerSku): void
    {
        $sellerSku = trim((string) $sellerSku);
        $tinhaSellerSku = false;

        foreach ($antiga->atributos as $a) {
            if ($a->attribute_id === self::GTIN) {
                continue;
            }

            if ($a->attribute_id === self::SELLER_SKU) {
                $tinhaSellerSku = true;
                if ($sellerSku === '') {
                    // Quem chama não mandou SKU para esta variante: melhor sem linha do
                    // que com o SKU da unidade, que colidiria com o anúncio da Fase 1.
                    continue;
                }
                $nova->atributos()->create(['attribute_id' => self::SELLER_SKU, 'value_name' => mb_substr($sellerSku, 0, 120)]);

                continue;
            }

            $nova->atributos()->create([
                'attribute_id' => $a->attribute_id,
                'value_id' => $a->value_id,
                'value_name' => $a->value_name,
                'value_number' => $a->value_number,
                'value_unit' => $a->value_unit,
            ]);
        }

        // Base sem SELLER_SKU na variante (produto simples recém-criado, por exemplo):
        // o kit ganha o dele de qualquer jeito.
        if (! $tinhaSellerSku && $sellerSku !== '') {
            $nova->atributos()->create(['attribute_id' => self::SELLER_SKU, 'value_name' => mb_substr($sellerSku, 0, 120)]);
        }
    }

    /**
     * As fotos do kit: BYTES próprios em `publicador/{rascunho_do_kit}/{sha}.ext`,
     * ids do Mercado Livre preservados. Ver a decisão 4 do docblock da classe —
     * é o que impede "tirar a foto do kit" de apagar o arquivo do base (T-175-04).
     *
     * **NUNCA** chamar `ImagemAssetService::receber()` nem `enviarAoMl()` daqui:
     * `receber()` revalida a imagem (L1) e TERMINA enviando ao ML — HTTP dentro da
     * transação e cota de foto gasta de novo, por uma foto que já está na conta
     * (T-175-06). O que o kit precisa é só o `ml_picture_id`, e ele vem copiado.
     *
     * A cópia de bytes é I/O dentro da transação de propósito: é disco LOCAL, não
     * HTTP. Cada `put()` entra em `$escritos` para o rollback de disco.
     *
     * @param  list<string>  $escritos  caminhos gravados no disco, para o rollback
     */
    protected function copiarImagens(PubRascunho $base, PubRascunho $kit, array &$escritos): void
    {
        $disco = Storage::disk(self::DISCO);
        // Dedup pelo unique `(rascunho_id, sha256)`: se duas fotos do base caírem no
        // mesmo sha (só possível quando o base tem `sha256` NULL), a segunda reusa a
        // primeira em vez de estourar o unique e derrubar o clone inteiro.
        $porSha = [];

        foreach ($base->imagens as $original) {
            $conteudo = null;
            $sha = $original->sha256;

            if ($original->caminho !== null && $disco->exists($original->caminho)) {
                $conteudo = $disco->get($original->caminho);
                // `sha256` NULL com arquivo presente: calcula, porque é ele que nomeia o arquivo.
                $sha ??= hash('sha256', (string) $conteudo);
            }

            if ($conteudo !== null && isset($porSha[$sha])) {
                $this->copiarAtribuicoes($original, $porSha[$sha]);

                continue;
            }

            $caminho = null;
            if ($conteudo !== null) {
                // MESMA extensão do original (o ML aceita jpg e png; não se converte nada aqui).
                $ext = strtolower(pathinfo((string) $original->caminho, PATHINFO_EXTENSION)) ?: 'jpg';
                $caminho = "publicador/{$kit->id}/{$sha}.{$ext}";
                $disco->put($caminho, $conteudo);
                $escritos[] = $caminho;
            }

            // `caminho` NULL: ou a foto veio do Anunciar antigo só com o id do ML (H-22),
            // ou o arquivo sumiu do disco. Nos dois casos a linha é copiada SEM arquivo,
            // nunca apontando para um caminho que não existe — e nada é lançado.
            $copia = $kit->imagens()->create([
                'caminho' => $caminho,
                'sha256' => $conteudo !== null ? $sha : $original->sha256,
                'mime' => $original->mime,
                'bytes' => $original->bytes,
                'largura' => $original->largura,
                'altura' => $original->altura,
                // Mesma conta do ML ⇒ a foto já está lá: preservar os ids É o "sem reupload" da §5.
                'ml_picture_id' => $original->ml_picture_id,
                'ml_url' => $original->ml_url,
                'upload_status' => $original->upload_status,
                'upload_erro' => $original->upload_erro,
            ]);

            if ($conteudo !== null) {
                $porSha[$sha] = $copia;
            }

            $this->copiarAtribuicoes($original, $copia);
        }
    }

    /** O grupo e a ordem da foto (`06` §3), apontando para a imagem NOVA. */
    private function copiarAtribuicoes(PubImagem $original, PubImagem $copia): void
    {
        foreach ($original->atribuicoes as $a) {
            $copia->atribuicoes()->firstOrCreate(
                ['grupo_hash' => $a->grupo_hash],
                ['grupo_chave' => $a->grupo_chave, 'posicao' => $a->posicao],
            );
        }
    }

    /** @param  list<string>  $caminhos */
    private function apagarArquivos(array $caminhos): void
    {
        foreach ($caminhos as $caminho) {
            rescue(fn () => Storage::disk(self::DISCO)->delete($caminho), report: false);
        }
    }
}
