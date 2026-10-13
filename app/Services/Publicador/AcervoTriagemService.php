<?php

namespace App\Services\Publicador;

use App\Models\Company;
use App\Models\MlAcervoItem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Fonte única de triagem/defasagem do acervo ML (Fase 134 D-09/D-08).
 *
 * Extraído de `MlbAnuncioController::meus()` (Fase 173 Plan 02) para que a
 * Visão geral (Fase 173, plans seguintes) use os MESMOS agregados sem
 * reimplementar a query — ver `134-CONTEXT.md` e
 * `design_handoff_publicador/ETAPA-2-visao-geral.md` seção 4.
 *
 * `motivosDef()` continua sendo a FONTE ÚNICA da definição dos 5 motivos —
 * nunca duplicar esta lista em outro lugar do código.
 *
 * Serviço sem dependências de construtor: todo método recebe a `Company` e,
 * quando precisa, os mesmos filtros de busca/status já usados por
 * `MeusAnuncios.jsx`.
 */
class AcervoTriagemService
{
    /**
     * O 7º valor do filtro de status da tela de Publicações (LIST-05).
     *
     * ⚠️ NÃO é um valor de `ml_acervo_itens.status` — é um EIXO PRÓPRIO, o
     * `sub_status` do Mercado Livre. Ver `SUB_STATUS_COM_ERRO` e o bloco de
     * 13/10/2026 no corpo de `escopo()`.
     */
    public const STATUS_COM_ERRO = 'com_erro';

    /** Os dois tipos de anúncio do ML que a tela distingue (LIST-20). */
    public const TIPO_CLASSICO = 'classico';

    public const TIPO_PREMIUM = 'premium';

    /**
     * Lista FECHADA do filtro de tipo → `listing_type_id` real.
     *
     * Valor fora destas duas chaves é IGNORADO pelo `filtros()` — nunca cai no
     * Clássico "porque é o mais comum" e nunca é interpolado (T-180-03-T).
     * `listing_type_id` que não seja nenhum dos dois (`free`, `silver`...) não
     * pertence a tipo nenhum e só aparece quando o filtro está desligado.
     */
    private const LISTING_TYPE_POR_TIPO = [
        self::TIPO_CLASSICO => 'gold_special',
        self::TIPO_PREMIUM  => 'gold_pro',
    ];

    /**
     * ═══ "Com erro" — lista FECHADA de `sub_status` do Mercado Livre ═════════
     *
     * ⚠️ **FORMA CONFIRMADA PELO USUÁRIO EM 13/10/2026 — NÃO PRESUMIDA.** Foram
     * apresentadas três opções para a aba "Com erro" do protótipo: (a) usar
     * "Pedem ação", igual à Visão Geral; (b) "Com erro" de verdade, do
     * `sub_status`, **com o aviso explícito de que pode ficar em zero
     * permanente e a aba existiria sem nunca mostrar nada**; (c) as duas abas
     * separadas. ➡️ Ele escolheu **(b)**.
     *
     * ⚠️ **O ZERO HONESTO É ACEITO.** Aba em 0, sem ponto vermelho, é "medido e
     * é zero" e está certo — não é bug e não é motivo para trocar a fonte.
     *
     * ⚠️ **PROIBIDO derivar "Com erro" de `severidade` ou de `motivos`.** Eles
     * dizem "Pausado"/"Sem estoque" (`AnuncioSaudeService::triagem()`), que é
     * outra coisa: um anúncio pausado pelo vendedor está exatamente como o
     * vendedor quis. Também proibido derivar do título. Há caso de teste
     * negativo para cada uma dessas três tentações
     * (`AcervoBuscaEFiltrosTest::test_com_erro_vem_de_sub_status`).
     *
     * FONTE DE CADA VALOR — a tabela de pós-publicação do próprio projeto,
     * `.planning/publicador-ml-spec/09-tratamento-de-erros.md:134` (derivada de
     * [ML·S16], `products-sync-listings`), que manda ALERTAR em todos os cinco:
     *
     *  · `waiting_for_patch`     — moderado por infração, esperando correção do
     *                              vendedor. ⚠️ ÚNICO MEDIDO EM PRODUÇÃO: 7
     *                              anúncios da conta real da #459 em 03–07/10,
     *                              6 por `DOMAIN` e 1 por `LENGUAJE`
     *                              (`.planning/learnings/publicador-ml.md:1057`).
     *  · `forbidden`             — anúncio proibido pelo ML.
     *  · `held`                  — retido pelo ML.
     *  · `pending_documentation` — bloqueado até o vendedor enviar documento.
     *  · `warning`               — advertência de infração pendente (o mais
     *                              brando dos cinco).
     *
     * SUB-STATUS REAIS DELIBERADAMENTE FORA, medidos na varredura dos fixtures
     * de produção da Fase 134 (`tests/Fixtures/phase134/*.json`, os únicos três
     * valores que aparecem em dado real): `cart_eligible` (3 ocorrências),
     * `paused_by_seller` (2) e `invalid_kit` (2, em itens `closed`/`inactive`).
     * Nenhum é recusa nem suspensão: o primeiro é elegibilidade de carrinho, o
     * segundo é o vendedor pausando e o terceiro é configuração de kit/pack
     * inválida. Incluí-los faria "Com erro" significar duas coisas ao mesmo
     * tempo.
     *
     * ⚠️ **NÃO ACRESCENTAR valor sem fonte escrita ao lado.** String que o ML
     * não emite é um `LIKE` morto que dá falsa sensação de cobertura.
     */
    public const SUB_STATUS_COM_ERRO = [
        'waiting_for_patch',
        'forbidden',
        'held',
        'pending_documentation',
        'warning',
    ];

    /**
     * Escopo base do acervo por empresa (T-134-01) — company_id é a fronteira
     * de segurança inteira desta tela e não pode sair de nenhum caminho:
     * busca, triagem, contagem, paginação. A busca é OBRIGATORIAMENTE
     * agrupada dentro de where(function...): um orWhere solto sobe ao topo
     * do WHERE e anula o escopo por empresa (mesma pegadinha travada em
     * historico(), Fase 86).
     *
     * A busca cobre TRÊS campos desde 10/10/2026 (quick 261010-rie): o nome
     * do anúncio (`title`), o código MLB (`ml_item_id`) e o SKU (`skus`) —
     * os três identificadores que o usuário tem na mão.
     *
     * ─── 13/10/2026: a busca passa a cobrir QUATRO identificadores (LIST-03) ──
     *
     * POR QUÊ: queixa literal do usuário no teste real da tela de Publicações —
     * *"a busca não achava o anúncio pelo nome do produto"*. O nome do PRODUTO
     * não vive no acervo: `ml_acervo_itens.title` é o TÍTULO DO ANÚNCIO, que a
     * IA reescreve e que raramente é igual ao nome com que o produto foi
     * cadastrado. Quem sabe ligar um `ml_item_id` ao produto que o originou é
     * `PublicacoesDaListaService::mlItemIdsPorProduto()` (plano 180-02), que
     * começa em `pub_produtos` (lado pequeno) e desce pelas FKs.
     *
     * É EMENDA ADITIVA, igual às de 10/08 e 10/10: `title`, `ml_item_id` e
     * `skus` continuam achando exatamente o que achavam. O 4º braço só
     * ACRESCENTA — e o `$lista` é OPCIONAL justamente para que todo chamador
     * existente (`CockpitDaContaService::resumoDePublicacoes()`,
     * `CabecalhoPublicadorService`, `MlbAnuncioController::meus()`) siga com o
     * comportamento de hoje, byte a byte, sem edição.
     *
     * ⚠️ NÃO "simplificar" o braço novo para um `LIKE` em `pub_produtos.nome`
     * dentro desta consulta: `ml_acervo_itens` tinha 1.080.206 linhas / 1.776 MB
     * na última medição, e um join a partir dele é o lado dirigente errado. Os
     * ids chegam prontos, já escopados por empresa e com teto de
     * `PublicacoesDaListaService::TETO_IDS_POR_BUSCA`.
     *
     * ⚠️ INJEÇÃO POR PARÂMETRO, nunca `app()`: este serviço não tem (e não deve
     * ganhar) dependência de construtor — ver o docblock da classe. O
     * `PublicacoesDaListaService` chega de quem já o tem na mão.
     *
     * IMPACTO NOS CHIPS (D-09) e NA ORDENAÇÃO (D-12): nenhum por si. O braço
     * novo só pode AUMENTAR o conjunto da busca; `triagem()` conta sobre o
     * mesmo escopo, então o universo dos chips acompanha a busca — era o que já
     * acontecia com os três braços antigos.
     */
    public function escopo(Company $company, string $busca, string $statusFiltro, ?PublicacoesDaListaService $lista = null): Builder
    {
        // 'acionaveis' (default) cobre TRÊS status — é o universo sobre o qual
        // a triagem do D-09 conta e a ordenação do D-12 opera. Os demais
        // filtros recortam um status só; 'todos' não filtra.
        //
        // ─── 10/10/2026: `under_review` entrou no default (quick 261010-nke) ──
        //
        // POR QUÊ: `under_review` é o status em que o anúncio recém-criado nasce
        // nessa conta (`under_review [waiting_for_patch]`, §21 dos learnings do
        // Publicador), e é o MAIS ACIONÁVEL de todos — é exatamente o que exige
        // alguém corrigir título/foto e repatchar. Deixá-lo fora do default era
        // o que mantinha o anúncio recém-publicado invisível mesmo depois de o
        // acervo passar a receber a linha; e a busca, montada DENTRO deste mesmo
        // builder, herdava o filtro de status e também não o achava. Era a
        // queixa literal do usuário: "não aparece e a busca não acha".
        //
        // A EMENDA DE 2026-08-10 AO D-03 SEGUE INTACTA: `paused` continua no
        // default. Esta mudança só ACRESCENTA — nenhum status saiu.
        //
        // IMPACTO NOS CHIPS (D-09): o universo da triagem cresce pelos itens
        // `under_review`. O chip "Pausado" NÃO muda —
        // `AnuncioSaudeService::triagem()` só carimba `MOTIVO_PAUSADO` quando
        // `status === 'paused'`, e `MOTIVO_SEM_ESTOQUE` só quando
        // `status === 'active'`. Um `under_review` só pode entrar nos chips de
        // ficha/catálogo/foto, que é o que ele realmente tem.
        //
        // IMPACTO NA ORDENAÇÃO (D-12): nenhum. A ordenação é por
        // `severidade`/`nota_ecf`, agnóstica de status. Um `under_review` sem
        // problema de ficha ordena no fim da lista, como qualquer item saudável
        // — quem precisa achá-lo rápido usa a busca, que agora o alcança.
        //
        // ─── 10/10/2026: `em_revisao` ACRESCENTADO (quick 261010-rie) ─────────
        //
        // POR QUÊ: fala literal do usuário — *"Em revisão quero poder isolar"*.
        // Desde a quick 261010-nke o `under_review` só aparecia DILUÍDO dentro
        // de `acionaveis`, misturado com ativos e pausados; não havia como ver
        // só o que o Mercado Livre está revisando.
        //
        // É EMENDA ADITIVA: `acionaveis` segue sendo o default e segue cobrindo
        // os TRÊS status. As emendas de 10/08/2026 (`paused`) e de 10/10/2026
        // (`under_review`) ficam INTACTAS — desfazer qualquer uma delas deixaria
        // o chip correspondente permanentemente em 0.
        //
        // IMPACTO NOS CHIPS (D-09): com `em_revisao` selecionado, o universo da
        // triagem são só os `under_review`. `AnuncioSaudeService::triagem()` só
        // carimba `MOTIVO_PAUSADO` quando `status === 'paused'` e
        // `MOTIVO_SEM_ESTOQUE` quando `status === 'active'` — logo esses dois
        // chips ficam NECESSARIAMENTE em 0 neste filtro, e isso é a VERDADE
        // daquele universo, não um bug. Ficha/foto/catálogo continuam contando.
        // A contagem dos outros filtros não muda: o arm novo não altera nenhum
        // existente.
        //
        // IMPACTO NA ORDENAÇÃO (D-12): nenhum. A ordenação é por
        // `severidade`/`nota_ecf`/`ml_item_id`, agnóstica de status.
        $statusColunas = match ($statusFiltro) {
            'acionaveis' => ['active', 'paused', 'under_review'],
            'ativos'     => ['active'],
            'pausados'   => ['paused'],
            'em_revisao' => ['under_review'],
            'encerrados' => ['closed'],
            default      => null, // 'todos' não filtra
        };

        // `skus` é texto JSON (`["ABC-1","XYZ"]`) lido pelo cast `array` do
        // model. Um termo que contenha a PONTUAÇÃO do próprio JSON (`"`, `[`,
        // `]`) casaria com TODA linha já coletada e devolveria o acervo inteiro
        // como se fosse resultado de busca. Nesses casos o ramo do SKU é
        // pulado; título e código MLB seguem valendo normalmente.
        $buscaCabeNoJson = $busca !== '' && ! preg_match('/["\[\]]/', $busca);

        return MlAcervoItem::where('company_id', $company->id)
            ->when($busca !== '', function ($q) use ($company, $busca, $buscaCabeNoJson, $lista) {
                // O 4º identificador (LIST-03, 13/10/2026): os `ml_item_id` cujo
                // PRODUTO casa com o termo. Resolvido FORA do acervo, antes do
                // grupo, por quem sabe o caminho `pub_publicacao_itens ->
                // pub_publicacoes -> pub_rascunhos -> pub_produtos`. Lista
                // pequena (teto de PublicacoesDaListaService::TETO_IDS_POR_BUSCA)
                // e já escopada por empresa na origem — dupla trava com o
                // `company_id` desta consulta (T-180-03-I).
                $idsDeProduto = $lista !== null ? $lista->mlItemIdsPorProduto($company, $busca) : [];

                // Os quatro identificadores que o usuário tem na mão: nome do
                // anúncio, código MLB, SKU (quick 261010-rie) e nome/SKU do
                // PRODUTO (LIST-03). O AGRUPAMENTO é o que preserva o escopo
                // por empresa — um orWhere solto sobe ao topo do WHERE e anula
                // o company_id (T-134-01).
                $q->where(function ($s) use ($busca, $buscaCabeNoJson, $idsDeProduto) {
                    $s->where('title', 'like', "%{$busca}%")
                      ->orWhere('ml_item_id', 'like', "%{$busca}%");

                    if ($buscaCabeNoJson) {
                        $s->orWhere('skus', 'like', "%{$busca}%");
                    }

                    // Lista vazia NÃO acrescenta braço: `orWhereIn(col, [])`
                    // gera `0 = 1` e dentro de um OR é inofensivo, mas não
                    // acrescentar é mais barato e mais legível.
                    if ($idsDeProduto !== []) {
                        $s->orWhereIn('ml_item_id', $idsDeProduto);
                    }
                });
            })
            ->when($statusColunas !== null, fn ($q) => $q->whereIn('status', $statusColunas))
            // ─── 13/10/2026: o eixo "Com erro" (LIST-05) ──────────────────────
            //
            // ⚠️ `com_erro` NÃO é valor de `ml_acervo_itens.status`: os valores
            // reais da coluna são `active|paused|closed|under_review` (comentário
            // da própria migration,
            // `2026_08_11_090000_create_ml_acervo_itens_table.php:32`). Ele é
            // outro EIXO — o `sub_status` do Mercado Livre, que o coletor já traz
            // de graça (`MlAcervoService::ATRIBUTOS_MULTIGET`, `:70`) e grava como
            // json no upsert (`:434`).
            //
            // POR QUE ELE NÃO ENTROU NO `match` ACIMA: aquele `match` é a fonte
            // única do mapeamento filtro → COLUNA `status`, e `com_erro` teria de
            // devolver `null` ali — exatamente o valor que significa "não filtra
            // por status" no `'todos'`. Quem lesse o arm concluiria que "Com erro"
            // mostra o acervo inteiro. Os 6 arms seguem byte a byte iguais e este
            // eixo se aplica por fora, em E com eles.
            //
            // CONSEQUÊNCIA QUERIDA: `com_erro` não amarra status nenhum, então um
            // `active` com `forbidden` e um `under_review` com `waiting_for_patch`
            // aparecem os dois. Um anúncio pode estar em "Em revisão" E em "Com
            // erro" ao mesmo tempo — são eixos diferentes, não abas rivais.
            //
            // IMPACTO NOS CHIPS (D-09), escrito como nas emendas de 10/08 e 10/10:
            // com `com_erro` selecionado, `MOTIVO_PAUSADO` e `MOTIVO_SEM_ESTOQUE`
            // podem ficar em 0 POR CONSTRUÇÃO — `AnuncioSaudeService::triagem()`
            // só carimba o primeiro em `status === 'paused'` e o segundo em
            // `status === 'active'`, e um anúncio moderado costuma estar
            // `under_review`. Isso é a VERDADE daquele universo, não bug.
            // Ficha/catálogo/foto continuam contando normalmente.
            //
            // IMPACTO NA ORDENAÇÃO (D-12): nenhum. A ordenação é por
            // `severidade`/`nota_ecf`/`ml_item_id`, agnóstica de status e de
            // sub_status. ⚠️ Um anúncio moderado pode ter `severidade = 0` e cair
            // no FIM da lista dentro da própria aba "Com erro" — a aba é o
            // recorte, a gravidade é outra medida.
            ->when($statusFiltro === self::STATUS_COM_ERRO, fn ($q) => $q->where(function ($e) {
                foreach (self::SUB_STATUS_COM_ERRO as $subStatus) {
                    // Mesmo padrão `like '%"chave"%'` que `triagem()` e
                    // `comMotivos()` já usam sobre coluna json. A chave vem da
                    // constante de lista fechada desta classe, NUNCA da
                    // querystring (T-180-03-T2). O `orWhere` está AGRUPADO, pela
                    // mesma razão de sempre.
                    $e->orWhere('sub_status', 'like', '%"' . $subStatus . '"%');
                }
            }));
    }

    /**
     * Os filtros que vivem no PRÓPRIO acervo (LIST-07), aplicados sobre um
     * builder que já veio de `escopo()`.
     *
     * Separado de `escopo()` de propósito: LIST-05 manda as contagens das 7 abas
     * de status serem calculadas sobre os **DEMAIS** filtros. Quem conta a aba
     * "Ativas" chama `filtros(escopo($c, $busca, 'ativos'), $filtros)` — o eixo
     * de status vem da chave da PRÓPRIA aba e o resto dos filtros é o mesmo em
     * todas. Juntar as duas coisas num método só tornaria isso impossível.
     *
     * Cada filtro só é aplicado quando PRESENTE, e nenhum valor chega ao SQL sem
     * passar por lista fechada (T-180-03-T).
     *
     * ⚠️ `com_vendas` é o MESMO predicado `sold_quantity > 0` que o indicador
     * "Com venda" da Visão geral e o `comVenda` de `MlbAnuncioController::meus()`
     * (Fase 173-02) já usam. Este método passa a ser a definição única: o plano
     * 180-04 deve rotear o `comVenda` do controller por aqui em vez de repetir o
     * predicado. ⚠️ É `> 0`, nunca `>= 0` — e `sold_quantity` NULO (anúncio
     * ainda não coletado por inteiro) não é "com venda".
     *
     * ⚠️ NÃO EXISTE PARÂMETRO `periodo`, e isso é MEDIDO, não esquecimento:
     * `ml_acervo_itens` não tem coluna de data de publicação. As únicas datas da
     * tabela são `coletado_em`, `detalhe_coletado_em` e os `timestamps`
     * (migration `2026_08_11_090000_create_ml_acervo_itens_table.php:84-88`;
     * `$fillable` em `app/Models/MlAcervoItem.php:80-81` — nenhum
     * `published_at`/`date_created`). `created_at` é a data em que a COLETA criou
     * a linha, não a da publicação, e já enganou este projeto antes
     * (`.planning/learnings/…/created_at é artefato de reimport`). O filtro
     * "Publicada [hoje/7/30]" entra pelo mesmo caminho da Fase e do Responsável:
     * `ml_item_ids`, resolvido no plano 180-04 a partir de
     * `pub_publicacoes.concluida_em`. **Melhor não ter o parâmetro do que ter um
     * que mente.**
     *
     * @param  array{tipo?:mixed, catalogo?:mixed, com_vendas?:mixed, ml_item_ids?:array<int,mixed>|null}  $filtros
     *                                                                                                              chave desconhecida é ignorada
     */
    public function filtros(Builder $q, array $filtros): Builder
    {
        $tipo = $filtros['tipo'] ?? null;
        if (is_string($tipo) && array_key_exists($tipo, self::LISTING_TYPE_POR_TIPO)) {
            $q->where('listing_type_id', self::LISTING_TYPE_POR_TIPO[$tipo]);
        }

        // ⚠️ `=== true`, nunca truthiness: a STRING `'false'` que uma
        // querystring entrega é truthy em PHP e ligaria o filtro ao contrário
        // do que o usuário pediu. Quem chama usa `$request->boolean()`.
        if (($filtros['catalogo'] ?? null) === true) {
            $q->where('catalog_listing', true);
        }

        if (($filtros['com_vendas'] ?? null) === true) {
            $q->where('sold_quantity', '>', 0);
        }

        // ⚠️ `[]` NÃO É `null`, e colapsar os dois é o pior tipo de mentira de
        // tela. `[]` significa "o filtro de Fase/Responsável/Período casou com
        // ZERO publicações" e TEM de produzir zero linhas — `whereIn(col, [])`
        // gera `0 = 1`, que é exatamente a verdade. `null` (ou chave ausente) é
        // "sem filtro". Tratar `[]` como "sem filtro" mostraria o acervo INTEIRO
        // quando o filtro não casou nada.
        $mlItemIds = $filtros['ml_item_ids'] ?? null;
        if (is_array($mlItemIds)) {
            $q->whereIn('ml_item_id', $mlItemIds);
        }

        return $q;
    }

    /**
     * Definição fechada dos 5 motivos de triagem (D-09), na mesma ordem de
     * gravidade do D-12: pausado/sem estoque (crítica, red) antes de ficha
     * incompleta/perdendo catálogo/foto insuficiente (atenção, amber).
     * Fonte única para a whitelist de validação e para os chips/selects
     * agregados — nunca duplicar esta lista.
     *
     * @return array<int, array{chave:string, label:string, cor:string}>
     */
    public function motivosDef(): array
    {
        return [
            ['chave' => MlAcervoItem::MOTIVO_PAUSADO,           'label' => 'Pausado',           'cor' => 'red'],
            ['chave' => MlAcervoItem::MOTIVO_SEM_ESTOQUE,       'label' => 'Sem estoque',        'cor' => 'red'],
            ['chave' => MlAcervoItem::MOTIVO_FICHA_INCOMPLETA,  'label' => 'Ficha incompleta',   'cor' => 'amber'],
            ['chave' => MlAcervoItem::MOTIVO_PERDENDO_CATALOGO, 'label' => 'Perdendo catálogo',  'cor' => 'amber'],
            ['chave' => MlAcervoItem::MOTIVO_FOTO_INSUFICIENTE, 'label' => 'Foto insuficiente',  'cor' => 'amber'],
        ];
    }

    /**
     * Triagem agregada (D-09) — UMA query, nunca um laço de ->count() por
     * motivo. Reusa os MESMOS filtros de status/busca de `escopo()`, mas SEM
     * filtro de motivo: os chips precisam continuar mostrando os outros
     * motivos quando um já está filtrado na listagem.
     *
     * ─── 13/10/2026: os filtros novos e o 4º braço da busca (LIST-05/LIST-22) ─
     *
     * `$filtros` é repassado a `filtros()` e `$lista` a `escopo()` pela MESMA
     * razão: o universo dos chips tem de ser o mesmo da listagem. Sem isso a
     * tela mostraria 1 linha com `tipo=premium` ligado (ou com a busca achando
     * pelo nome do produto) e os chips diriam outro número — a queixa clássica
     * de "o número do topo não bate com a lista".
     *
     * ⚠️ O que continua FORA é o filtro de MOTIVO, e isso não mudou: `$filtros`
     * não tem (e não pode ganhar) chave `motivo`. Chave desconhecida é
     * ignorada por `filtros()`.
     *
     * ⚠️ LIST-22: `total` são os anúncios DISTINTOS com >= 1 motivo. A tela
     * NUNCA deve somar os chips para obter o total — um anúncio com dois
     * motivos conta 1 aqui e 2 lá.
     *
     * @param  array<string, mixed>  $filtros  os mesmos de `filtros()`
     * @return array{total:int, chips:array, nao_avaliado:int}
     */
    public function triagem(
        Company $company,
        string $busca,
        string $statusFiltro,
        array $filtros = [],
        ?PublicacoesDaListaService $lista = null,
    ): array {
        $motivosDef = $this->motivosDef();

        $selects = [
            // Total = anúncios DISTINTOS com >=1 motivo, nunca soma dos chips
            // (severidade > 0 <=> motivos não vazio, ver AnuncioSaudeService::triagem()).
            'SUM(CASE WHEN severidade > 0 THEN 1 ELSE 0 END) as total_com_motivo',
            'SUM(CASE WHEN catalog_listing = 1 AND buybox_status IS NULL THEN 1 ELSE 0 END) as nao_avaliado',
        ];
        foreach ($motivosDef as $i => $m) {
            // $m['chave'] vem da whitelist fechada (constantes do model), nunca da querystring.
            $selects[] = "SUM(CASE WHEN motivos LIKE '%\"{$m['chave']}\"%' THEN 1 ELSE 0 END) as motivo_{$i}";
        }

        $linhaTriagem = $this->filtros($this->escopo($company, $busca, $statusFiltro, $lista), $filtros)
            ->selectRaw(implode(', ', $selects))
            ->first();

        $chips = [];
        foreach ($motivosDef as $i => $m) {
            $chips[] = [
                'chave' => $m['chave'],
                'label' => $m['label'],
                'count' => (int) ($linhaTriagem->{"motivo_{$i}"} ?? 0),
                'cor'   => $m['cor'],
            ];
        }

        return [
            'total'        => (int) ($linhaTriagem->total_com_motivo ?? 0),
            'chips'        => $chips,
            'nao_avaliado' => (int) ($linhaTriagem->nao_avaliado ?? 0),
        ];
    }

    /**
     * Defasagem da coleta (D-08) — nunca resposta vazia. `nunca_coletado`
     * distingue "nunca medimos" de "medimos e é zero": acervo nunca coletado
     * tem que aparecer na tela como "—" + "Atualizar agora", NUNCA como zero
     * (critério de aceite da Visão geral,
     * design_handoff_publicador/ETAPA-2-visao-geral.md seção 7).
     *
     * @return array{coletado_em:?string, horas:?int, defasado:bool, nunca_coletado:bool, motivo:?string}
     */
    public function defasagem(Company $company): array
    {
        $temLinhas     = MlAcervoItem::where('company_id', $company->id)->exists();
        $nuncaColetado = ! $temLinhas;
        $coletadoEmRaw = $nuncaColetado ? null : MlAcervoItem::where('company_id', $company->id)->max('coletado_em');
        $coletadoEm    = $coletadoEmRaw !== null ? Carbon::parse($coletadoEmRaw) : null;
        $horas         = $coletadoEm !== null ? $coletadoEm->diffInHours(now()) : null;
        $limiteHoras   = (int) config('mlb_acervo.defasagem_horas');
        $motivoErro    = MlAcervoItem::where('company_id', $company->id)
            ->whereNotNull('coleta_erro')
            ->orderByDesc('updated_at')
            ->value('coleta_erro');

        return [
            'coletado_em'    => $coletadoEm?->toIso8601String(),
            'horas'          => $horas,
            'defasado'       => $horas !== null && $horas > $limiteHoras,
            'nunca_coletado' => $nuncaColetado,
            'motivo'         => $motivoErro,
        ];
    }

    /**
     * Conta anúncios no escopo 'acionaveis' (sem busca) cujo `motivos`
     * contém QUALQUER um dos motivos passados — usado pela linha 2 ("Anúncios
     * pausados ou sem estoque") e linha 8 ("Ficha, catálogo ou foto") de "O
     * que fazer agora" (Fase 173 Plan 05).
     *
     * Um orWhere por motivo, SEMPRE agrupado dentro de where(function...) —
     * nunca um orWhere solto, mesma armadilha documentada no docblock de
     * `escopo()`.
     *
     * @param  array<int,string> $motivos  chaves de MlAcervoItem::MOTIVO_*
     */
    public function comMotivos(Company $company, array $motivos): int
    {
        return $this->escopo($company, '', 'acionaveis')
            ->where(function ($q) use ($motivos) {
                foreach ($motivos as $motivo) {
                    $q->orWhere('motivos', 'like', '%"' . $motivo . '"%');
                }
            })
            ->count();
    }

    /**
     * Mesmo filtro de `comMotivos()`, restrito a `origem = legado` — usado
     * pela linha 2 de "O que fazer agora" para mostrar "quantos são legado"
     * (Fase 173 Plan 05).
     *
     * @param  array<int,string> $motivos  chaves de MlAcervoItem::MOTIVO_*
     */
    public function legadoEntre(Company $company, array $motivos): int
    {
        return $this->escopo($company, '', 'acionaveis')
            ->where('origem', MlAcervoItem::ORIGEM_LEGADO)
            ->where(function ($q) use ($motivos) {
                foreach ($motivos as $motivo) {
                    $q->orWhere('motivos', 'like', '%"' . $motivo . '"%');
                }
            })
            ->count();
    }
}
