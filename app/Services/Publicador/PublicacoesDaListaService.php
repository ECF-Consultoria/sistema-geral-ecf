<?php

namespace App\Services\Publicador;

use App\Models\Company;
use App\Models\EstruturaOferta;
use App\Models\EstruturaProduto;
use App\Models\PubProduto;
use App\Models\PubPublicacaoItem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * O caminho de dado que a tela de Publicações (Fase 180) não tinha: dado um
 * conjunto de `ml_item_id` do acervo, dizer de QUAL PRODUTO cada publicação é,
 * em QUE FASE/KIT ela foi feita e QUEM a publicou — e, no sentido inverso,
 * dado um termo de busca, dizer quais `ml_item_id` pertencem a um produto cujo
 * nome ou SKU casa com o termo.
 *
 * Serviço sem dependências de construtor (mesma forma de `AcervoTriagemService`):
 * todo método recebe a `Company`.
 *
 * ═══ ⚠️ O CAMINHO REAL — e por que o LIST-20 descreve o caminho ERRADO ═══════
 *
 * O LIST-20 diz `ml_acervo_itens.rascunho_id` → `pub_rascunhos.produto_id`.
 * **Está errado, e isso está medido no código:** `ml_acervo_itens.rascunho_id`
 * aponta para `ml_anuncio_rascunhos`, o assistente ANTIGO. Duas provas:
 *
 *  · `database/migrations/2026_08_11_090000_create_ml_acervo_itens_table.php:54-61`
 *    — o comentário da própria coluna diz que uma FK ali "criaria dependência de
 *    ordem de drop entre **ml_anuncio_rascunhos** e esta tabela";
 *  · `app/Services/Mlb/Acervo/MlAcervoService::mapaRascunhos()` monta o mapa
 *    `ml_item_id → rascunho_id` a partir de `MlAnuncioRascunho`, não de
 *    `PubRascunho` (chamado em `:205` e `:350`).
 *
 * O caminho REAL, o MESMO que `MlbAnuncioController::historico()` (`:306-317`)
 * já percorre, é a ponte por `pub_publicacao_itens.ml_item_id`:
 *
 *   pub_publicacao_itens
 *     -> pub_publicacoes  (publicacao_id)
 *     -> pub_rascunhos    (rascunho_id)
 *     -> pub_produtos     (produto_id)
 *
 * ═══ ⚠️ CUSTO: a DIREÇÃO do join é a mitigação (T-180-02-D) ══════════════════
 *
 * `ml_acervo_itens` tinha **1.080.206 linhas / 1.776 MB** na última medição.
 * NENHUMA consulta deste serviço toca o acervo nem o deixa ser o lado dirigente:
 *
 *  · `metodologiaPorMlItemId()` recebe os ids JÁ PAGINADOS (<= 50) e faz
 *    `whereIn('pub_publicacao_itens.ml_item_id', ...)` sobre o índice
 *    `pubpi_mlb_ix`. A tabela do acervo não aparece no SQL — o custo cresce com
 *    o tamanho da PÁGINA, nunca com o tamanho do acervo;
 *  · `mlItemIdsPorProduto()` começa em `pub_produtos` (dezenas a poucas centenas
 *    de linhas por empresa, por `pubprod_company_ix`) e desce pelas FKs, com teto
 *    de `TETO_IDS_POR_BUSCA`.
 *
 * As cinco arestas já são indexadas — **este serviço não precisa de migration
 * nem de índice novo**:
 *
 * | aresta                | índice                                      |
 * |-----------------------|---------------------------------------------|
 * | acervo ↔ publicador   | `pubpi_mlb_ix` (2026_10_01_200000:259)      |
 * | item → publicação     | FK `pubpi_publicacao_fk` (:239)             |
 * | publicação → rascunho | FK `pubpu_rascunho_fk` (:221)               |
 * | rascunho → produto    | unique `pubr_produto_uq` (2026_10_02_100100)|
 * | escopo do produto     | `pubprod_company_ix` (2026_10_02_100000:69) |
 */
class PublicacoesDaListaService
{
    /**
     * Teto defensivo da busca por produto. Uma empresa com muitos produtos e um
     * termo de 1 caractere ("a") casaria quase tudo; sem teto a lista viraria um
     * `IN (...)` gigante dentro da consulta do acervo (plano 180-03). 2000 é
     * cerca de 40 páginas de 50 — mais do que qualquer busca útil.
     */
    public const TETO_IDS_POR_BUSCA = 2000;

    /** O que o `ator` da publicação não permite afirmar vira rótulo honesto, nunca nome. */
    private const AUTOR_PESSOA = 'pessoa';

    private const AUTOR_DESCONHECIDO = 'desconhecido';

    /** Os dois tipos de anúncio do Mercado Livre que o mapa de cobertura distingue. */
    private const TIPO_CLASSICO = 'classico';

    private const TIPO_PREMIUM = 'premium';

    /**
     * Metodologia de cada `ml_item_id`: de qual produto a publicação é, em que
     * fase/kit, e quem a publicou. UMA consulta.
     *
     * Chaves de cada entrada, nesta ordem EXATA (contrato dos planos 180-04/08/09
     * — campo, nome e ordem não podem mudar):
     * `produto_id, produto_nome, produto_sku, fase, quantidade_kit, estoque,
     *  autor_nome, autor_tipo, publicada_em, publicacao_id`.
     *
     * ⚠️ `ml_item_id` SEM linha em `pub_publicacao_itens` fica **ausente** do mapa.
     * É o caso "Legado": quem desenha a palavra é a tela, por ausência de entrada,
     * nunca uma string vinda daqui. "Não nasceu no Publicador" não é "fase 0".
     *
     * ⚠️ `estoque` sai SEMPRE `null`, de propósito. `pub_produtos.estoque_calculado`
     * é BOOLEANO (se o estoque do kit é derivado do base), não quantidade — devolvê-lo
     * aqui seria um número inventado. O estoque que a linha de grupo mostra
     * ("SKU · Estoque N") vem de `ml_acervo_itens.available_quantity`, que o
     * controller já tem na mão; o plano 180-04 preenche a chave da fonte certa.
     * A chave existe desde já para o contrato não mudar depois.
     *
     * @param  array<int, mixed>  $mlItemIds  ids da página do acervo (<= 50); entrada suja é tolerada
     * @return array<string, array{produto_id:int, produto_nome:string, produto_sku:string, fase:int, quantidade_kit:int, estoque:null, autor_nome:string, autor_tipo:string, publicada_em:?string, publicacao_id:int}>
     */
    public function metodologiaPorMlItemId(Company $company, array $mlItemIds): array
    {
        $ids = $this->idsDeAnuncioLimpos($mlItemIds);

        // Sem id nenhum NÃO se consulta: um `whereIn([])` é desperdício e, pior,
        // esconde o caso de borda atrás de um resultado vazio "natural".
        if ($ids === []) {
            return [];
        }

        $linhas = $this->comNomeDeExibicao($this->base($company))
            ->whereIn('pub_publicacao_itens.ml_item_id', $ids)
            ->select([
                'pub_publicacao_itens.ml_item_id',
                'pub_publicacoes.id as publicacao_id',
                'pub_publicacoes.ator',
                'pub_publicacoes.concluida_em',
                'pub_produtos.id as produto_id',
                'pub_produtos.company_id as produto_company_id',
                'pub_produtos.estrutura_produto_id as produto_estrutura_produto_id',
                'pub_produtos.nome as produto_nome',
                'pub_produtos.sku as produto_sku',
                // ⚠️ `fase` QUALIFICADO: `estrutura_ofertas.fase` está no MESMO
                // select (join de exibição) e é OUTRA COISA — `string(10)` com o
                // TIPO da oferta. Sem qualificar, o MariaDB resolve para a
                // primeira tabela do FROM, calado e errado.
                'pub_produtos.fase as produto_fase',
                'pub_produtos.quantidade_kit as produto_quantidade_kit',
                'estrutura_ofertas.sku as oferta_sku',
                'estrutura_ofertas.nome as oferta_nome',
                'estrutura_produtos.codigo as grupo_codigo',
                'estrutura_produtos.nome as grupo_nome',
                'estrutura_produtos.company_id as grupo_company_id',
            ])
            // O mesmo `ml_item_id` pode casar com mais de uma linha (reenvio,
            // UNKNOWN reconciliado, republicação). Vence a publicação de
            // `concluida_em` mais recente, com o id como desempate — nunca "a
            // que o banco devolver primeiro".
            ->orderByDesc('pub_publicacoes.concluida_em')
            ->orderByDesc('pub_publicacoes.id')
            ->get();

        $mapa = [];
        foreach ($linhas as $linha) {
            $chave = (string) $linha->ml_item_id;
            if ($chave === '' || array_key_exists($chave, $mapa)) {
                continue; // a primeira que chega é a mais recente (ordem acima)
            }

            $produto = $this->produtoParaExibicao($linha);
            $autor = $this->autorDaPublicacao($linha->ator);

            $mapa[$chave] = [
                'produto_id' => (int) $linha->produto_id,
                'produto_nome' => $produto->nomeExibido(),
                'produto_sku' => $produto->skuExibido(),
                'fase' => (int) $linha->produto_fase,
                'quantidade_kit' => (int) $linha->produto_quantidade_kit,
                'estoque' => null, // ver docblock: boolean no banco, quantidade vem do acervo
                'autor_nome' => $autor['nome'],
                'autor_tipo' => $autor['tipo'],
                'publicada_em' => $this->isoOuNulo($linha->concluida_em),
                'publicacao_id' => (int) $linha->publicacao_id,
            ];
        }

        return $mapa;
    }

    /**
     * A BUSCA, no servidor (LIST-03/LIST-21): dado um termo, quais `ml_item_id`
     * pertencem a um produto cujo nome ou SKU casa com ele. UMA consulta.
     *
     * Quem consome é o plano 180-03, que injeta a lista como
     * `orWhereIn('ml_item_id', $ids)` DENTRO do grupo da busca do acervo — o
     * acervo continua sendo varrido UMA vez, pelo `company_id` que já varria.
     *
     * ⚠️ Começa em `pub_produtos` (lado PEQUENO: dezenas a poucas centenas de
     * linhas por empresa, por `pubprod_company_ix`). Nunca pelo acervo.
     *
     * ⚠️ `normalizar()` NÃO entra aqui, e isso é decisão medida: "ignora acento e
     * caixa" é responsabilidade do COLLATION do banco de produção
     * (MySQL 8 `utf8mb4_0900_ai_ci` / MariaDB `utf8mb4_general_ci` são `_ai_ci`),
     * o mesmo que já faz a busca por `title` do acervo ignorar caixa. Normalizar em
     * PHP e comparar com a coluna CRUA daria MENOS resultados, não mais: "luminaria"
     * normalizado jamais casaria com "Luminária" gravado. O SQLite dos testes é mais
     * pobre (`LIKE` só dobra ASCII, não dobra acento) — ver
     * `test_busca_ignora_caixa_e_o_acento_depende_do_collation`.
     *
     * ⚠️ O termo é BIND (T-180-02-T): `->where(col, 'like', "%{$termo}%")` é bind
     * posicional do Eloquent. Nenhum `whereRaw`/`selectRaw` recebe entrada do
     * usuário aqui.
     *
     * @return list<string>
     */
    public function mlItemIdsPorProduto(Company $company, string $termo): array
    {
        $termo = trim($termo);

        // Termo vazio ou só espaços: `[]` SEM consultar. Um `LIKE '%%'` devolveria
        // a empresa inteira como se fosse resultado de busca.
        if ($termo === '') {
            return [];
        }

        $ids = $this->comNomeDeExibicao($this->base($company))
            ->whereNotNull('pub_publicacao_itens.ml_item_id')
            ->where(function ($termos) use ($termo) {
                // ⚠️ O GRUPO é obrigatório, mesma razão de `base()`: um `orWhere`
                // solto aqui subiria ao topo do WHERE e anularia o escopo por
                // empresa E o filtro de status.
                $termos->where('pub_produtos.nome', 'like', "%{$termo}%")
                    ->orWhere('pub_produtos.sku', 'like', "%{$termo}%")
                    // As colunas de EXIBIÇÃO também, senão a busca não acha o que a
                    // tela mostra: em produto de origem `portal`, `nomeExibido()`
                    // devolve o nome VIVO da oferta/grupo, não o snapshot da coluna.
                    ->orWhere('estrutura_ofertas.nome', 'like', "%{$termo}%")
                    ->orWhere('estrutura_ofertas.sku', 'like', "%{$termo}%")
                    ->orWhere('estrutura_produtos.nome', 'like', "%{$termo}%")
                    ->orWhere('estrutura_produtos.codigo', 'like', "%{$termo}%");
            })
            ->distinct()
            ->limit(self::TETO_IDS_POR_BUSCA)
            ->pluck('pub_publicacao_itens.ml_item_id');

        return array_values(array_unique(array_map('strval', $ids->all())));
    }

    /**
     * O mapa F1/F2 × Clássico/Premium da linha de grupo (LIST-09). UMA consulta
     * agregada sobre a MESMA cadeia de joins.
     *
     * As 4 chaves são EXATAMENTE as que `MapaDeCobertura.jsx` consome
     * (`resources/js/Components/Mlb/Publicador/identidade/MapaDeCobertura.jsx:24-28`,
     * conferido no componente): chave com nome diferente faria o mapa desenhar 4
     * células tracejadas em silêncio.
     *
     * ⚠️ `listing_type_id` fora de `gold_special`/`gold_pro` **não vira nenhum dos
     * dois** — não cai no Clássico "porque é o mais comum". Conta no `total` e em
     * nenhuma das 4 células. `pub_produtos.fase` fora de {1,2} (dado antigo) idem.
     *
     * ⚠️ TODO id pedido recebe entrada, mesmo sem publicação nenhuma — `total => 0`
     * com as 4 células `false` é a verdade MEDIDA daquele produto, e evita que o
     * consumidor tenha de distinguir "chave ausente" de "nada publicado". Id de
     * outra empresa cai no mesmo caso (o escopo tirou as linhas dele), então o mapa
     * não confirma nem nega a existência de produto alheio.
     *
     * @param  array<int, mixed>  $produtoIds
     * @return array<int, array{f1_classico:bool, f1_premium:bool, f2_classico:bool, f2_premium:bool, total:int}>
     */
    public function coberturaPorProduto(Company $company, array $produtoIds): array
    {
        $ids = $this->produtoIdsLimpos($produtoIds);
        if ($ids === []) {
            return [];
        }

        $mapa = [];
        foreach ($ids as $id) {
            $mapa[$id] = [
                'f1_classico' => false,
                'f1_premium' => false,
                'f2_classico' => false,
                'f2_premium' => false,
                'total' => 0,
            ];
        }

        $linhas = $this->base($company)
            ->whereIn('pub_produtos.id', $ids)
            ->groupBy('pub_produtos.id', 'pub_produtos.fase', 'pub_publicacao_itens.listing_type_id')
            ->select([
                'pub_produtos.id as produto_id',
                // QUALIFICADO — ver o aviso de colisão em `metodologiaPorMlItemId()`.
                'pub_produtos.fase as produto_fase',
                'pub_publicacao_itens.listing_type_id',
            ])
            // Agregado sem entrada de usuário: nenhum termo chega a este selectRaw.
            ->selectRaw('COUNT(*) as quantidade')
            ->get();

        foreach ($linhas as $linha) {
            $produtoId = (int) $linha->produto_id;
            if (! array_key_exists($produtoId, $mapa)) {
                continue;
            }

            $mapa[$produtoId]['total'] += (int) $linha->quantidade;

            $celula = $this->celulaDeCobertura((int) $linha->produto_fase, (string) $linha->listing_type_id);
            if ($celula !== null) {
                $mapa[$produtoId][$celula] = true;
            }
        }

        return $mapa;
    }

    // ═══ Internos ═══════════════════════════════════════════════════════════

    /**
     * Qual das 4 células de `MapaDeCobertura.jsx` esta combinação acende — ou
     * `null`, que é resposta legítima: tipo de anúncio desconhecido e fase fora de
     * {1,2} contam no total e não acendem nada.
     *
     * Os literais dos tipos são o RAW do Mercado Livre, os mesmos que
     * `historico()` já devolve como `listing_tier`.
     */
    private function celulaDeCobertura(int $fase, string $listingTypeId): ?string
    {
        $tipo = match ($listingTypeId) {
            'gold_special' => self::TIPO_CLASSICO,
            'gold_pro' => self::TIPO_PREMIUM,
            default => null,
        };

        return match (true) {
            $fase === 1 && $tipo === self::TIPO_CLASSICO => 'f1_classico',
            $fase === 1 && $tipo === self::TIPO_PREMIUM => 'f1_premium',
            $fase === 2 && $tipo === self::TIPO_CLASSICO => 'f2_classico',
            $fase === 2 && $tipo === self::TIPO_PREMIUM => 'f2_premium',
            default => null,
        };
    }

    /**
     * Só inteiro positivo entra como id de produto — mesma razão de
     * `idsDeAnuncioLimpos()`. `'abc'`, `null` e `0` não podem virar bind.
     *
     * @param  array<int, mixed>  $produtoIds
     * @return list<int>
     */
    private function produtoIdsLimpos(array $produtoIds): array
    {
        $limpos = [];
        foreach ($produtoIds as $id) {
            if (! is_int($id) && ! (is_string($id) && ctype_digit($id))) {
                continue;
            }
            $id = (int) $id;
            if ($id > 0) {
                $limpos[] = $id;
            }
        }

        return array_values(array_unique($limpos));
    }


    /**
     * A cadeia de joins e, principalmente, O ESCOPO — a única coisa que separa a
     * empresa A da empresa B em `pub_produtos`/`pub_publicacao_itens`, que são
     * tabelas MULTI-EMPRESA (T-180-02-I).
     *
     * ⚠️ O `OR` do escopo mora DENTRO de `where(function ...)`. Um `orWhere` solto
     * sobe ao topo do WHERE e **anula** o escopo inteiro — é a pegadinha que o
     * docblock de `AcervoTriagemService::escopo()` documenta e que `historico()`
     * já trata.
     *
     * ⚠️ DIVERGÊNCIA DELIBERADA do plano 180-02, que prescrevia resolver a âncora
     * com `MlbEmpresa::where('company_id', ...)->value('id')` como `historico()`
     * faz. Aqui ela é SUBCONSULTA, por duas razões medidas:
     *   1. `value('id')` é uma SEGUNDA consulta — o mesmo plano exige (e o teste
     *      cobra) UMA consulta por método;
     *   2. `mlb_empresas.company_id` **não é unique**
     *      (`2026_05_25_100002_add_company_id_to_mlb_empresas.php:18-19` cria a
     *      coluna sem unique), então `value('id')` pega UMA âncora arbitrária e
     *      deixa invisível o produto ancorado na segunda. A subconsulta é
     *      superconjunto estrito, e todas as linhas que ela acrescenta são da
     *      MESMA `company_id` — o escopo não afrouxa.
     * Empresa sem nenhuma `MlbEmpresa`: a subconsulta sai vazia e o braço do `OR`
     * não casa nada — exatamente como o braço ausente do `historico()`.
     */
    private function base(Company $company): Builder
    {
        return PubPublicacaoItem::query()
            ->join('pub_publicacoes', 'pub_publicacoes.id', '=', 'pub_publicacao_itens.publicacao_id')
            ->join('pub_rascunhos', 'pub_rascunhos.id', '=', 'pub_publicacoes.rascunho_id')
            ->join('pub_produtos', 'pub_produtos.id', '=', 'pub_rascunhos.produto_id')
            // Só o que de fato nasceu no ML. SENT/PENDING/FAILED/UNKNOWN não são
            // "publicado" — o mesmo filtro de `historico()`.
            ->where('pub_publicacao_itens.status', PubPublicacaoItem::CREATED)
            ->where(function ($q) use ($company) {
                $q->where('pub_produtos.company_id', $company->id)
                    ->orWhereIn('pub_produtos.mlb_empresa_id', function ($ancoras) use ($company) {
                        $ancoras->select('id')->from('mlb_empresas')->where('company_id', $company->id);
                    });
            });
    }

    /**
     * Os dois `leftJoin` que permitem usar `PubProduto::nomeExibido()`/`skuExibido()`
     * — a convenção de tela do módulo — SEM uma segunda consulta.
     *
     * Por que não a coluna crua: em produto de origem `portal`, `pub_produtos.nome`
     * é um SNAPSHOT do momento em que o produto nasceu (`PubProduto::daOferta()`
     * copia o nome da oferta). Renomear a oferta no Portal faria a tela de
     * Publicações mostrar o nome velho enquanto Produtos mostra o novo — duas telas
     * discordando do mesmo produto. `nomeExibido()` é a fonte única dessa regra.
     */
    private function comNomeDeExibicao(Builder $query): Builder
    {
        return $query
            ->leftJoin('estrutura_ofertas', 'estrutura_ofertas.id', '=', 'pub_produtos.oferta_id')
            ->leftJoin('estrutura_produtos', 'estrutura_produtos.id', '=', 'pub_produtos.estrutura_produto_id');
    }

    /**
     * Um `PubProduto` NÃO PERSISTIDO com as relações já presas (`setRelation`), só
     * para chamar `nomeExibido()`/`skuExibido()` sem lazy load. Reuso da regra de
     * exibição, nunca uma segunda implementação dela — e zero consulta extra.
     */
    private function produtoParaExibicao(object $linha): PubProduto
    {
        $produto = new PubProduto;
        $produto->forceFill([
            'id' => (int) $linha->produto_id,
            'company_id' => $linha->produto_company_id === null ? null : (int) $linha->produto_company_id,
            'estrutura_produto_id' => $linha->produto_estrutura_produto_id === null ? null : (int) $linha->produto_estrutura_produto_id,
            'sku' => (string) $linha->produto_sku,
            'nome' => (string) $linha->produto_nome,
        ]);

        $oferta = null;
        if ($linha->oferta_sku !== null || $linha->oferta_nome !== null) {
            $oferta = new EstruturaOferta;
            $oferta->forceFill(['sku' => $linha->oferta_sku, 'nome' => $linha->oferta_nome]);
        }
        $produto->setRelation('oferta', $oferta);

        // Sem `company_id` do grupo não há como conferir a defesa de vínculo
        // cruzado que `produtoDoGrupo()` faz — então não há grupo.
        $grupo = null;
        if ($linha->grupo_company_id !== null) {
            $grupo = new EstruturaProduto;
            $grupo->forceFill([
                'company_id' => (int) $linha->grupo_company_id,
                'codigo' => $linha->grupo_codigo,
                'nome' => $linha->grupo_nome,
            ]);
        }
        $produto->setRelation('estruturaProduto', $grupo);

        return $produto;
    }

    /**
     * Quem publicou, pronto para a tela (LIST-13 / EXMP-01): ou o nome REAL gravado
     * em `pub_publicacoes.ator`, ou um dos literais de `PainelVisaoGeralService` —
     * **nunca** um nome inventado, nunca `'—'`, nunca `null`.
     *
     * ⚠️ Nenhum `User::find()` por linha. `ator.nome` já está gravado no json por
     * `PublicacaoService::atorParaGravar()` (`:758-761`); a dívida de N+1 da Fase 179
     * em `PainelVisaoGeralService::resolverAtor()` NÃO se repete aqui.
     *
     * ⚠️ DIVERGÊNCIA DELIBERADA do plano 180-02, que prescrevia devolver
     * `data_get($ator, 'nome')` sempre que fosse string não vazia. O `ator` do
     * CLIENTE pelo Portal também tem `nome` (é `equipe => false` com o nome do
     * cliente), e **T-173-09 fixou que nome/id de cliente não saem do servidor** —
     * `PainelVisaoGeralService` devolve `QUEM_CLIENTE` nesse caso. Obedecer a
     * prescrição vazaria nome de cliente numa tela interna. Aqui o cliente vira o
     * literal `Cliente`, que é verdade e já é fonte única. `autor_tipo` continua
     * com os DOIS valores do contrato (`pessoa`/`desconhecido`): `Cliente` é rótulo
     * genérico, do mesmo balde de `Equipe` e `Origem antiga` — não é nome de pessoa.
     *
     * @return array{nome: string, tipo: string}
     */
    private function autorDaPublicacao(mixed $atorBruto): array
    {
        $ator = $this->atorDecodificado($atorBruto);

        // `ator` nulo/ausente/vazio: ninguém foi registrado.
        if ($ator === []) {
            return ['nome' => PainelVisaoGeralService::QUEM_SEM_AUTOR, 'tipo' => self::AUTOR_DESCONHECIDO];
        }

        // Sem `id` = linha migrada do assistente antigo (`estrutura_publicacoes`).
        if (! array_key_exists('id', $ator) || $ator['id'] === null) {
            return ['nome' => PainelVisaoGeralService::QUEM_ORIGEM_ANTIGA, 'tipo' => self::AUTOR_DESCONHECIDO];
        }

        // `equipe` falso = o CLIENTE pelo Portal (T-173-09, ver docblock).
        if (($ator['equipe'] ?? false) !== true) {
            return ['nome' => PainelVisaoGeralService::QUEM_CLIENTE, 'tipo' => self::AUTOR_DESCONHECIDO];
        }

        $nome = is_string($ator['nome'] ?? null) ? trim($ator['nome']) : '';

        return $nome !== ''
            ? ['nome' => $nome, 'tipo' => self::AUTOR_PESSOA]
            : ['nome' => PainelVisaoGeralService::QUEM_EQUIPE_SEM_NOME, 'tipo' => self::AUTOR_DESCONHECIDO];
    }

    /** `pub_publicacoes.ator` é json; aqui ele chega cru (sem cast, por causa do select com joins). */
    private function atorDecodificado(mixed $bruto): array
    {
        if (is_array($bruto)) {
            return $bruto;
        }
        if ($bruto === null) {
            return [];
        }
        $decodificado = json_decode((string) $bruto, true);

        return is_array($decodificado) ? $decodificado : [];
    }

    /**
     * Só string não vazia entra como bind. Um objeto, um `null` ou um número vindo
     * do chamador não pode virar id de anúncio — é a herança da tela preta de
     * 07/10/2026, em que um campo de shape inesperado derrubou a página inteira.
     *
     * @param  array<int, mixed>  $mlItemIds
     * @return list<string>
     */
    private function idsDeAnuncioLimpos(array $mlItemIds): array
    {
        $limpos = [];
        foreach ($mlItemIds as $id) {
            if (! is_string($id)) {
                continue;
            }
            $id = trim($id);
            if ($id !== '') {
                $limpos[] = $id;
            }
        }

        return array_values(array_unique($limpos));
    }

    /** Data sem `new Date()`/parse cego: valor vazio é null, nunca "agora" nem Invalid Date. */
    private function isoOuNulo(mixed $valor): ?string
    {
        if ($valor === null || $valor === '') {
            return null;
        }

        return Carbon::parse($valor)->toIso8601String();
    }
}
