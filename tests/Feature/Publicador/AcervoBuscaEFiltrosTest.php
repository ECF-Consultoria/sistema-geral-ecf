<?php

namespace Tests\Feature\Publicador;

use App\Models\Company;
use App\Models\MlAcervoItem;
use App\Models\MlbEmpresa;
use App\Models\PubProduto;
use App\Models\PubPublicacao;
use App\Models\PubPublicacaoItem;
use App\Models\PubRascunho;
use App\Services\Publicador\AcervoTriagemService;
use App\Services\Publicador\PublicacoesDaListaService;
use App\Support\Publicador\Variacao\ChaveCanonica;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Fase 180, plano 03 — a BUSCA e os FILTROS de `AcervoTriagemService::escopo()`.
 *
 * ═══ O que este arquivo guarda (e uma asserção isolada não guardaria) ════════
 *
 * 1. **AS TRÊS EMENDAS QUE NÃO PODEM SER DESFEITAS** (LIST-21/LIST-23). O
 *    `match` de `escopo()` é a fonte única do mapeamento filtro → `status`, e
 *    três emendas datadas vivem nele: `paused` no default (10/08/2026),
 *    `under_review` no default (quick 261010-nke) e `em_revisao` como arm
 *    próprio (quick 261010-rie, fala literal do usuário *"Em revisão quero
 *    poder isolar"*). Desfazer qualquer uma deixa um chip do D-09
 *    permanentemente em 0 — por isso são TRÊS asserções separadas, uma por
 *    status, para que cada emenda reprove por motivo PRÓPRIO.
 * 2. **A BUSCA DE 10/10 CONTINUA INTEIRA** e a de 13/10 só ACRESCENTA. Título,
 *    código MLB e SKU continuam achando, a guarda `$buscaCabeNoJson` continua
 *    impedindo que um termo com a pontuação do próprio JSON (`"`, `[`, `]`)
 *    devolva o acervo inteiro, e o 4º braço (nome do produto) entra ao lado.
 * 3. **O ESCOPO POR EMPRESA** (T-134-01/T-180-03-I). O braço novo é um
 *    `orWhereIn` — a coisa mais fácil de deixar escapar do grupo. Fora do
 *    grupo ele sobe ao topo do WHERE e anula o `company_id`. O caso
 *    `test_busca_por_produto_nao_vaza_entre_empresas` existe só para isso, e
 *    tem a SEGUNDA conta cheia no banco (senão a negativa seria vazia por
 *    construção).
 * 4. **"COM ERRO" É MEDIDO, NUNCA INFERIDO** (LIST-05/EXMP-01). O arm vem do
 *    `sub_status` do Mercado Livre, de lista FECHADA. Dois casos negativos
 *    impedem a próxima sessão de derivá-lo de `severidade` ou de confundi-lo
 *    com "Em revisão".
 * 5. **`ml_item_ids = []` ≠ `null`.** `[]` é "o filtro casou com zero
 *    publicações" e tem de dar ZERO linhas; `null` é "sem filtro". Colapsar os
 *    dois mostraria o acervo inteiro quando o filtro de Fase/Responsável não
 *    casou nada — o pior tipo de mentira de tela.
 *
 * @group phase180
 */
class AcervoBuscaEFiltrosTest extends TestCase
{
    use RefreshDatabase;

    private function servico(): AcervoTriagemService
    {
        return new AcervoTriagemService;
    }

    private function lista(): PublicacoesDaListaService
    {
        return new PublicacoesDaListaService;
    }

    /** @return array{0: Company, 1: MlbEmpresa} */
    private function conta(string $nome = 'Polo das Publicações'): array
    {
        $company = Company::factory()->create(['name' => $nome]);
        $empresa = MlbEmpresa::create(['nome' => $nome, 'projeto' => 'POLOS', 'company_id' => $company->id]);

        return [$company->fresh(), $empresa->fresh()];
    }

    /**
     * Uma linha do acervo. Os defaults são de item SAUDÁVEL: cada caso muda só
     * o que o caso é sobre, para que nenhuma asserção passe por acaso.
     */
    private function acervo(Company $c, string $mlItemId, array $extra = []): MlAcervoItem
    {
        return MlAcervoItem::create($extra + [
            'company_id' => $c->id,
            'ml_item_id' => $mlItemId,
            'title' => 'Anúncio '.$mlItemId,
            'status' => 'active',
            'sub_status' => [],
            'listing_type_id' => 'gold_special',
            'catalog_listing' => false,
            'available_quantity' => 10,
            'sold_quantity' => 0,
            'skus' => ['SKU-'.$mlItemId],
            'nota_ecf' => 60,
            'motivos' => [],
            'severidade' => MlAcervoItem::SEVERIDADE_SAUDAVEL,
            'origem' => MlAcervoItem::ORIGEM_LEGADO,
            'coletado_em' => now(),
        ]);
    }

    private function produto(Company $c, MlbEmpresa $e, string $sku, string $nome, array $extra = []): PubProduto
    {
        return PubProduto::create($extra + [
            'company_id' => $c->id,
            'mlb_empresa_id' => $e->id,
            'sku' => $sku,
            'nome' => $nome,
            'origem' => PubProduto::ORIGEM_PUBLICADOR,
        ]);
    }

    /**
     * Uma publicação CONCLUÍDA do produto, com item CREATED no `ml_item_id`.
     *
     * ⚠️ `pub_rascunhos.produto_id` é UNIQUE: duas publicações do mesmo produto
     * são REVISÕES do mesmo rascunho, como no editor.
     */
    private function publicada(PubProduto $produto, string $mlItemId, string $listingType = 'gold_special'): PubPublicacao
    {
        $rascunho = PubRascunho::firstOrCreate(
            ['produto_id' => $produto->id],
            ['status' => PubRascunho::PUBLISHED, 'revisao' => 1],
        );

        $publicacao = PubPublicacao::create([
            'rascunho_id' => $rascunho->id,
            'revisao' => PubPublicacao::where('rascunho_id', $rascunho->id)->count() + 1,
            'modelo_publicacao' => 'items',
            'status' => PubPublicacao::PUBLISHED,
            'chave_idempotencia' => (string) Str::uuid(),
            'concluida_em' => now()->subDay(),
            'ator' => ['equipe' => true, 'id' => 7, 'nome' => 'Ana Ribeiro'],
        ]);

        PubPublicacaoItem::create([
            'publicacao_id' => $publicacao->id,
            'indice' => PubPublicacaoItem::where('publicacao_id', $publicacao->id)->count(),
            'listing_type_id' => $listingType,
            'variante_chave' => ChaveCanonica::UNICA,
            'status' => PubPublicacaoItem::CREATED,
            'ml_item_id' => $mlItemId,
            'payload' => [],
        ]);

        return $publicacao;
    }

    /** Os `ml_item_id` do escopo, ordenados — a ordem da consulta não é contrato. */
    private function achados(
        Company $c,
        string $busca,
        string $status,
        ?PublicacoesDaListaService $lista = null,
        array $filtros = [],
    ): array {
        $q = $this->servico()->escopo($c, $busca, $status, $lista);

        if ($filtros !== []) {
            $q = $this->servico()->filtros($q, $filtros);
        }

        $ids = $q->pluck('ml_item_id')->all();
        sort($ids);

        return $ids;
    }

    // ═══ Task 3 / caso 1 — as três emendas do `match` ═══════════════════════

    /**
     * @group phase180
     */
    public function test_as_tres_emendas_continuam_intactas(): void
    {
        [$c] = $this->conta();

        $this->acervo($c, 'MLB-ATIVO', ['status' => 'active']);
        $this->acervo($c, 'MLB-PAUSADO', ['status' => 'paused']);
        $this->acervo($c, 'MLB-REVISAO', ['status' => 'under_review']);
        $this->acervo($c, 'MLB-FECHADO', ['status' => 'closed']);

        $acionaveis = $this->achados($c, '', 'acionaveis');

        // ─── Emenda 1 (10/08/2026): `paused` no default ───
        // Desfazê-la deixa o chip "Pausado" (MOTIVO_PAUSADO) permanentemente em 0.
        $this->assertContains(
            'MLB-PAUSADO',
            $acionaveis,
            'EMENDA DE 10/08/2026 DESFEITA: `paused` saiu do default `acionaveis` — o chip "Pausado" do D-09 fica permanentemente em 0',
        );

        // ─── Emenda 2 (quick 261010-nke): `under_review` no default ───
        // Desfazê-la esconde o anúncio recém-publicado (ele NASCE under_review).
        $this->assertContains(
            'MLB-REVISAO',
            $acionaveis,
            'EMENDA DE 10/10/2026 (261010-nke) DESFEITA: `under_review` saiu do default — o anúncio recém-publicado volta a ficar invisível na tela E na busca',
        );

        // ─── O `active`, que nunca saiu ───
        $this->assertContains('MLB-ATIVO', $acionaveis, '`active` tem de estar no default `acionaveis`');

        // ─── E o default NÃO é "todos": `closed` fica fora ───
        $this->assertNotContains('MLB-FECHADO', $acionaveis, '`closed` NÃO pertence a `acionaveis` — o default não é `todos`');
        $this->assertSame(['MLB-ATIVO', 'MLB-PAUSADO', 'MLB-REVISAO'], $acionaveis);

        // ─── Emenda 3 (quick 261010-rie): `em_revisao` isola `under_review` ───
        $this->assertSame(
            ['MLB-REVISAO'],
            $this->achados($c, '', 'em_revisao'),
            'EMENDA DE 10/10/2026 (261010-rie) DESFEITA: `em_revisao` não isola mais `under_review` — fala literal do usuário "Em revisão quero poder isolar"',
        );

        // ─── Os outros arms, que o plano exige byte a byte iguais ───
        $this->assertSame(['MLB-ATIVO'], $this->achados($c, '', 'ativos'));
        $this->assertSame(['MLB-PAUSADO'], $this->achados($c, '', 'pausados'));
        $this->assertSame(['MLB-FECHADO'], $this->achados($c, '', 'encerrados'));
        $this->assertSame(
            ['MLB-ATIVO', 'MLB-FECHADO', 'MLB-PAUSADO', 'MLB-REVISAO'],
            $this->achados($c, '', 'todos'),
            '`todos` não filtra status nenhum',
        );

        // ─── Valor fora da lista fechada NUNCA é interpolado (T-180-03-T) ───
        //
        // ⚠️ MEDIDO, não presumido: no SERVIÇO o `default` do `match` é o mesmo
        // do `'todos'` — não filtra status. Quem coage valor estranho para
        // `acionaveis` é a LISTA FECHADA do controller (`MlbAnuncioController::
        // meus()`), antes de chamar `escopo()`. O serviço só garante que o termo
        // não viaja para o SQL.
        $this->assertSame(
            ['MLB-ATIVO', 'MLB-FECHADO', 'MLB-PAUSADO', 'MLB-REVISAO'],
            $this->achados($c, '', "'; DROP TABLE ml_acervo_itens; --"),
            'valor de status fora da lista fechada cai no `default` do match (= não filtra) e NUNCA é interpolado em SQL',
        );
        $this->assertNotEmpty(
            MlAcervoItem::where('company_id', $c->id)->count(),
            'a tabela continua de pé: o termo foi tratado como valor, nunca como SQL',
        );

        // E a lista fechada do controller, que é quem coage, continua lá.
        // ⚠️ Cobrada pela EXISTÊNCIA do `in_array` e pelo arm `em_revisao`, não
        // pelo conteúdo inteiro da lista: o plano 180-04 vai ACRESCENTAR
        // `com_erro` a ela, e um teste que travasse a lista literal reprovaria o
        // trabalho legítimo do plano vizinho.
        $controller = (string) file_get_contents(app_path('Http/Controllers/MlbAnuncioController.php'));
        $this->assertStringContainsString(
            'in_array($statusFiltro,',
            $controller,
            'a lista fechada de status do controller é a primeira trava do T-180-03-T e não pode desaparecer',
        );
        $this->assertStringContainsString("'em_revisao'", $controller, "a opção 'em_revisao' (quick 261010-rie) não pode sair da lista fechada do controller");
    }

    // ═══ Caso 3 — a busca de 10/10 continua inteira ═════════════════════════

    /**
     * @group phase180
     */
    public function test_busca_por_titulo_mlb_e_sku_continua_valendo(): void
    {
        [$c] = $this->conta();

        $this->acervo($c, 'MLB900000001', ['title' => 'Poltrona Oslo Veludo', 'skus' => ['POL-OSLO-VD']]);
        $this->acervo($c, 'MLB900000002', ['title' => 'Mesa de Centro Nórdica', 'skus' => ['MES-NORD-01']]);
        $this->acervo($c, 'MLB900000003', ['title' => 'Luminária de Arco', 'skus' => []]);

        // ─── 1. por TÍTULO ───
        $this->assertSame(['MLB900000001'], $this->achados($c, 'Poltrona', 'acionaveis'), 'a busca por TÍTULO parou de achar');

        // ─── 2. por CÓDIGO MLB ───
        $this->assertSame(['MLB900000002'], $this->achados($c, '900000002', 'acionaveis'), 'a busca por CÓDIGO MLB parou de achar');

        // ─── 3. por SKU (quick 261010-rie) ───
        $this->assertSame(['MLB900000001'], $this->achados($c, 'POL-OSLO-VD', 'acionaveis'), 'a busca por SKU parou de achar — regressão da quick 261010-rie');

        // ─── A guarda `$buscaCabeNoJson`: o termo que é a PONTUAÇÃO do json
        // casaria com TODA linha coletada e devolveria o acervo inteiro. ───
        foreach (['"', '[', ']', '["'] as $pontuacao) {
            $this->assertSame(
                [],
                $this->achados($c, $pontuacao, 'acionaveis'),
                "o termo {$pontuacao} furou a guarda \$buscaCabeNoJson e devolveu linhas pela pontuação do próprio JSON de `skus`",
            );
            // E também com o 4º braço ligado — a guarda não pode depender dele.
            $this->assertSame(
                [],
                $this->achados($c, $pontuacao, 'acionaveis', $this->lista()),
                "o termo {$pontuacao} furou a guarda \$buscaCabeNoJson com o braço do produto ligado",
            );
        }

        // Prova de que a negativa acima NÃO é vazia por construção: as três
        // linhas existem e um termo normal as acha.
        $this->assertCount(3, $this->achados($c, 'a', 'acionaveis'), 'o cenário tem de ter linhas, senão a negativa da pontuação é vazia por construção');
    }

    // ═══ Casos 4, 6 e 7 — o 4º braço: nome do produto ══════════════════════

    /**
     * @group phase180
     */
    public function test_busca_acha_pelo_nome_do_produto(): void
    {
        [$c, $e] = $this->conta();

        // O título do ANÚNCIO não contém o nome do produto — é o caso real: a
        // IA reescreve o título e o usuário busca pelo nome que ele cadastrou.
        $this->acervo($c, 'MLB001', ['title' => 'Cadeira Gamer Ergonômica Reclinável 180 Graus', 'skus' => ['CAD-01']]);
        $this->acervo($c, 'MLB002', ['title' => 'Mesa Escritório Industrial 120cm', 'skus' => ['MES-02']]);

        $this->produto($c, $e, 'PRD-SOFIA', 'Poltrona Sofia');
        $cadeira = $this->produto($c, $e, 'PRD-CAD', 'Poltrona Oslo');
        $this->publicada($cadeira, 'MLB001');

        // ─── Caso 4: acha pelo NOME DO PRODUTO ───
        $this->assertSame(
            ['MLB001'],
            $this->achados($c, 'Poltrona Oslo', 'acionaveis', $this->lista()),
            'LIST-03: a busca tem de achar o anúncio pelo NOME DO PRODUTO — queixa literal do usuário no teste real',
        );

        // Também pelo SKU do produto (que não é o SKU do anúncio).
        $this->assertSame(
            ['MLB001'],
            $this->achados($c, 'PRD-CAD', 'acionaveis', $this->lista()),
            'LIST-03: o SKU do PRODUTO também é identificador que o usuário tem na mão',
        );

        // ─── Caso 6: SEM o 4º parâmetro, o comportamento é o de hoje ───
        $this->assertSame(
            [],
            $this->achados($c, 'Poltrona Oslo', 'acionaveis'),
            'sem o PublicacoesDaListaService o escopo tem de se comportar EXATAMENTE como antes do plano — é o que mantém MeusAnunciosTest e os 4 chamadores existentes verdes sem edição',
        );

        // E o braço novo não atropela os antigos: buscar pelo título continua
        // achando, com ou sem o serviço.
        $this->assertSame(['MLB002'], $this->achados($c, 'Industrial', 'acionaveis'));
        $this->assertSame(['MLB002'], $this->achados($c, 'Industrial', 'acionaveis', $this->lista()));

        // ─── Produto sem anúncio nenhum não inventa resultado ───
        $this->assertSame(
            [],
            $this->achados($c, 'Poltrona Sofia', 'acionaveis', $this->lista()),
            'produto sem publicação CREATED não pode trazer linha nenhuma',
        );
    }

    /**
     * @group phase180
     */
    public function test_braco_do_produto_respeita_o_filtro_de_status(): void
    {
        [$c, $e] = $this->conta();

        $this->acervo($c, 'MLB010', ['status' => 'closed', 'title' => 'Anúncio encerrado qualquer']);
        $produto = $this->produto($c, $e, 'PRD-ENC', 'Escrivaninha Encerrada');
        $this->publicada($produto, 'MLB010');

        // Caso 7: o braço do produto mora DENTRO do grupo da busca, e o grupo
        // está em E com o filtro de status — logo `closed` não entra em
        // `acionaveis` só porque o produto casou.
        $this->assertSame(
            [],
            $this->achados($c, 'Escrivaninha', 'acionaveis', $this->lista()),
            'o braço do produto não pode furar o filtro de status: um anúncio `closed` não pertence a `acionaveis`',
        );

        // E a negativa não é vazia por construção: em `encerrados` ele aparece.
        $this->assertSame(
            ['MLB010'],
            $this->achados($c, 'Escrivaninha', 'encerrados', $this->lista()),
            'o mesmo anúncio TEM de aparecer em `encerrados` — senão a negativa acima seria vazia por construção',
        );
    }

    // ═══ Caso 5 — o escopo por empresa, que a mutação (a) reprova ══════════

    /**
     * @group phase180
     */
    public function test_busca_por_produto_nao_vaza_entre_empresas(): void
    {
        [$a, $ea] = $this->conta('Empresa A');
        [$b, $eb] = $this->conta('Empresa B');

        // ─── 5a: produto da B com nome que casa, anúncio da B no acervo da B ───
        $this->acervo($a, 'MLB-A-1', ['title' => 'Título da A sem relação']);
        $this->acervo($b, 'MLB-B-1', ['title' => 'Título da B sem relação']);

        $produtoA = $this->produto($a, $ea, 'PRD-A', 'Poltrona Compartilhada');
        $produtoB = $this->produto($b, $eb, 'PRD-B', 'Poltrona Compartilhada');
        $this->publicada($produtoA, 'MLB-A-1');
        $this->publicada($produtoB, 'MLB-B-1');

        // ─── 5b: a MESMA string de `ml_item_id` existe nas DUAS empresas.
        // É permitido: `mai_company_item_unq` é COMPOSTO e o próprio model
        // documenta (`MlAcervoItem:121-126`) que `ml_item_id` sozinho NÃO é
        // único globalmente. É isto que torna o vazamento visível: com o
        // `orWhereIn` fora do grupo, `ml_item_id IN (ids da A)` casa a linha da
        // B e passa por cima do `company_id`. ───
        $this->acervo($a, 'MLB-COMUM', ['title' => 'Linha da A']);
        $this->acervo($b, 'MLB-COMUM', ['title' => 'Linha da B']);
        $produtoComumA = $this->produto($a, $ea, 'PRD-COMUM', 'Mesa Compartilhada');
        $this->publicada($produtoComumA, 'MLB-COMUM');

        // A busca da A: só as linhas da A.
        $escopoA = $this->servico()->escopo($a, 'Compartilhada', 'acionaveis', $this->lista())->get();

        $this->assertSame(
            [$a->id],
            array_values(array_unique($escopoA->pluck('company_id')->all())),
            'T-180-03-I: a busca da empresa A devolveu linha de OUTRA empresa — o orWhereIn do braço do produto saiu do grupo e anulou o company_id',
        );
        $ids = $escopoA->pluck('ml_item_id')->all();
        sort($ids);
        $this->assertSame(['MLB-A-1', 'MLB-COMUM'], $ids, 'a busca da A tem de achar exatamente os 2 anúncios dela que casam');

        // E o inverso: a B não vê a Mesa da A (ela não tem produto com esse nome).
        $this->assertSame(
            [],
            $this->achados($b, 'Mesa Compartilhada', 'acionaveis', $this->lista()),
            'a empresa B não pode achar o produto da A nem pelo `ml_item_id` que as duas compartilham',
        );

        // A B acha o DELA, e isso prova que o cenário da B não está vazio.
        $this->assertSame(
            ['MLB-B-1'],
            $this->achados($b, 'Poltrona Compartilhada', 'acionaveis', $this->lista()),
            'a segunda conta tem de estar CHEIA, senão a negativa de vazamento é vazia por construção',
        );
    }

    // ═══ Casos 8 a 12 — os filtros do acervo (LIST-07) ═════════════════════

    /**
     * @group phase180
     */
    public function test_filtro_de_tipo_usa_lista_fechada(): void
    {
        [$c] = $this->conta();

        $this->acervo($c, 'MLB-CLAS', ['listing_type_id' => 'gold_special']);
        $this->acervo($c, 'MLB-PREM', ['listing_type_id' => 'gold_pro']);
        $this->acervo($c, 'MLB-OUTRO', ['listing_type_id' => 'free']);

        $this->assertSame(
            ['MLB-PREM'],
            $this->achados($c, '', 'acionaveis', null, ['tipo' => AcervoTriagemService::TIPO_PREMIUM]),
            'tipo=premium tem de devolver só `gold_pro` (LIST-20)',
        );
        $this->assertSame(
            ['MLB-CLAS'],
            $this->achados($c, '', 'acionaveis', null, ['tipo' => AcervoTriagemService::TIPO_CLASSICO]),
            'tipo=classico tem de devolver só `gold_special` (LIST-20)',
        );

        // ⚠️ Valor fora da lista fechada é IGNORADO — nunca "cai no Clássico
        // porque é o mais comum" e nunca é interpolado (T-180-03-T).
        foreach (['gold_pro', 'gold_special', 'premiun', '', 'classico; DROP TABLE x', '1'] as $forajogo) {
            $this->assertSame(
                ['MLB-CLAS', 'MLB-OUTRO', 'MLB-PREM'],
                $this->achados($c, '', 'acionaveis', null, ['tipo' => $forajogo]),
                "tipo={$forajogo} não está na lista fechada: tem de ser IGNORADO, não virar filtro",
            );
        }

        // `listing_type_id` desconhecido não pertence a nenhum dos dois tipos.
        $this->assertNotContains('MLB-OUTRO', $this->achados($c, '', 'acionaveis', null, ['tipo' => AcervoTriagemService::TIPO_CLASSICO]));
        $this->assertNotContains('MLB-OUTRO', $this->achados($c, '', 'acionaveis', null, ['tipo' => AcervoTriagemService::TIPO_PREMIUM]));
    }

    /**
     * @group phase180
     */
    public function test_filtros_de_catalogo_e_com_vendas(): void
    {
        [$c] = $this->conta();

        $this->acervo($c, 'MLB-CAT-VEND', ['catalog_listing' => true, 'sold_quantity' => 3]);
        $this->acervo($c, 'MLB-CAT-SEM', ['catalog_listing' => true, 'sold_quantity' => 0]);
        $this->acervo($c, 'MLB-SEM-VEND', ['catalog_listing' => false, 'sold_quantity' => 7]);
        $this->acervo($c, 'MLB-SEM-SEM', ['catalog_listing' => false, 'sold_quantity' => 0]);
        // Caso 10, a borda: `sold_quantity` nulo NÃO é "com venda".
        $this->acervo($c, 'MLB-NULO', ['catalog_listing' => false, 'sold_quantity' => null]);

        $this->assertSame(
            ['MLB-CAT-SEM', 'MLB-CAT-VEND'],
            $this->achados($c, '', 'acionaveis', null, ['catalogo' => true]),
            'catalogo=true tem de devolver só `catalog_listing = 1`',
        );

        // ⚠️ `> 0`, nunca `>= 0`: com `>=` o item de 0 vendas entraria e o
        // indicador "Com venda" da Visão geral passaria a contar o acervo todo.
        $this->assertSame(
            ['MLB-CAT-VEND', 'MLB-SEM-VEND'],
            $this->achados($c, '', 'acionaveis', null, ['com_vendas' => true]),
            'com_vendas=true é `sold_quantity > 0` — com `>= 0` o item de 0 vendas (e o de vendas nula) entraria',
        );

        // ─── Caso 12: dois filtros juntos são E, não OU ───
        $this->assertSame(
            ['MLB-CAT-VEND'],
            $this->achados($c, '', 'acionaveis', null, ['catalogo' => true, 'com_vendas' => true]),
            'dois filtros juntos têm de ser E (interseção), nunca OU',
        );

        // ─── Falso e ausente são a MESMA coisa: não filtram ───
        $todos = ['MLB-CAT-SEM', 'MLB-CAT-VEND', 'MLB-NULO', 'MLB-SEM-SEM', 'MLB-SEM-VEND'];
        $this->assertSame($todos, $this->achados($c, '', 'acionaveis'));
        $this->assertSame($todos, $this->achados($c, '', 'acionaveis', null, ['catalogo' => false, 'com_vendas' => false]));

        // ⚠️ E a string `'false'` NÃO liga o filtro: em PHP ela é truthy, e um
        // `if ($filtros['catalogo'])` solto ligaria o filtro ao receber o texto
        // "false" da querystring. O contrato é bool de verdade.
        $this->assertSame(
            $todos,
            $this->achados($c, '', 'acionaveis', null, ['catalogo' => 'false', 'com_vendas' => 'false']),
            "a string 'false' é truthy em PHP: o filtro só liga com bool `true`, nunca por truthiness",
        );
    }

    /**
     * @group phase180
     */
    public function test_ml_item_ids_vazio_nao_e_ml_item_ids_nulo(): void
    {
        [$c] = $this->conta();

        $this->acervo($c, 'MLB100');
        $this->acervo($c, 'MLB200');
        $this->acervo($c, 'MLB300');

        // ⚠️ Caso 11, o mais perigoso dos filtros: `[]` significa "o filtro de
        // Fase/Responsável casou com ZERO publicações". Tratá-lo como "sem
        // filtro" mostraria o acervo inteiro — a tela diria o oposto da verdade.
        $this->assertSame(
            [],
            $this->achados($c, '', 'acionaveis', null, ['ml_item_ids' => []]),
            'ml_item_ids = [] é "casou com zero publicações" e tem de dar ZERO linhas, nunca o acervo inteiro',
        );

        // `null` (e ausente) é "sem filtro".
        $this->assertSame(
            ['MLB100', 'MLB200', 'MLB300'],
            $this->achados($c, '', 'acionaveis', null, ['ml_item_ids' => null]),
            'ml_item_ids = null é "sem filtro"',
        );
        $this->assertSame(['MLB100', 'MLB200', 'MLB300'], $this->achados($c, '', 'acionaveis'));

        // E a lista cheia recorta.
        $this->assertSame(
            ['MLB200'],
            $this->achados($c, '', 'acionaveis', null, ['ml_item_ids' => ['MLB200']]),
            'ml_item_ids cheia recorta o escopo — é por aqui que Fase e Responsável entram (plano 180-04)',
        );
    }

    // ═══ Casos 13 a 16 — "Com erro" vem do `sub_status` ════════════════════

    /**
     * @group phase180
     */
    public function test_com_erro_vem_de_sub_status(): void
    {
        [$c] = $this->conta();

        // ⚠️ FORMA CONFIRMADA PELO USUÁRIO EM 13/10/2026, com o aviso explícito
        // de que a aba pode ficar em zero permanente: "'Com erro' de verdade, do
        // sub_status do ML". O zero honesto é aceito — ver o caso 16.

        // Caso 13: sub-status de erro medido. `waiting_for_patch` é o valor
        // MEDIDO em produção na conta real (#459): 7 anúncios moderados, 6 por
        // infração DOMAIN e 1 por LENGUAJE (learnings do Publicador §21).
        $this->acervo($c, 'MLB-ERRO', ['status' => 'under_review', 'sub_status' => ['waiting_for_patch']]);
        // Erro ao lado de um sub-status inofensivo: o braço casa pelo erro.
        $this->acervo($c, 'MLB-ERRO-2', ['status' => 'active', 'sub_status' => ['cart_eligible', 'forbidden']]);

        // Caso 14: `under_review` SEM sub-status de erro é "Em revisão", outra
        // coisa. A aba "Com erro" não pode confundir os dois eixos.
        $this->acervo($c, 'MLB-REVISAO-LIMPO', ['status' => 'under_review', 'sub_status' => []]);

        // Caso 15: severidade CRÍTICA e `sub_status` vazio. ⚠️ Este é o caso que
        // impede a próxima sessão de "derivar Com erro da severidade": severidade
        // vem de `AnuncioSaudeService` e diz "Pausado"/"Sem estoque", outra coisa.
        $this->acervo($c, 'MLB-CRITICO-SEM-ERRO', [
            'status' => 'paused',
            'sub_status' => [],
            'severidade' => MlAcervoItem::SEVERIDADE_CRITICA,
            'motivos' => [MlAcervoItem::MOTIVO_PAUSADO, MlAcervoItem::MOTIVO_SEM_ESTOQUE],
        ]);

        // Sub-status REAIS que NÃO são erro — os três que a varredura dos
        // fixtures de produção da Fase 134 encontrou (`cart_eligible`,
        // `paused_by_seller`, `invalid_kit`).
        $this->acervo($c, 'MLB-CARRINHO', ['sub_status' => ['cart_eligible']]);
        $this->acervo($c, 'MLB-PAUSA-VENDEDOR', ['status' => 'paused', 'sub_status' => ['paused_by_seller']]);
        $this->acervo($c, 'MLB-KIT-INVALIDO', ['status' => 'closed', 'sub_status' => ['invalid_kit']]);

        $comErro = $this->achados($c, '', AcervoTriagemService::STATUS_COM_ERRO);

        $this->assertSame(
            ['MLB-ERRO', 'MLB-ERRO-2'],
            $comErro,
            '"Com erro" tem de vir do `sub_status` do ML, da lista fechada SUB_STATUS_COM_ERRO',
        );

        // As negativas, uma por uma, com mensagem própria.
        $this->assertNotContains('MLB-REVISAO-LIMPO', $comErro, '`under_review` sem sub-status de erro é "Em revisão", não "Com erro" — são eixos diferentes');
        $this->assertNotContains('MLB-CRITICO-SEM-ERRO', $comErro, 'PROIBIDO derivar "Com erro" de `severidade`: severidade CRÍTICA com `sub_status` vazio NÃO é erro do ML');
        $this->assertNotContains('MLB-CARRINHO', $comErro, '`cart_eligible` é sub-status REAL e inofensivo');
        $this->assertNotContains('MLB-PAUSA-VENDEDOR', $comErro, '`paused_by_seller` é o vendedor pausando, não erro do ML');
        $this->assertNotContains('MLB-KIT-INVALIDO', $comErro, '`invalid_kit` não está na lista fechada — ele ficou FORA de propósito, com a razão escrita na constante');

        // ⚠️ O eixo é o `sub_status`, NÃO o `status`: `com_erro` não filtra
        // `status` nenhum, por isso achou um `under_review` e um `active`.
        $statusAchados = MlAcervoItem::whereIn('ml_item_id', $comErro)->pluck('status')->sort()->values()->all();
        $this->assertSame(['active', 'under_review'], $statusAchados, '`com_erro` é eixo de `sub_status` e não pode estar amarrado a um `status`');

        // ─── Os 6 arms antigos não mudaram com a chegada do 7º ───
        $this->assertContains('MLB-REVISAO-LIMPO', $this->achados($c, '', 'em_revisao'));
        $this->assertContains('MLB-ERRO', $this->achados($c, '', 'em_revisao'), 'um anúncio pode estar nas DUAS abas: "Em revisão" é status, "Com erro" é sub_status');
        $this->assertContains('MLB-KIT-INVALIDO', $this->achados($c, '', 'encerrados'));

        // ─── A lista fechada, travada por literal: mexer nela exige decisão ───
        $this->assertSame(
            ['waiting_for_patch', 'forbidden', 'held', 'pending_documentation', 'warning'],
            AcervoTriagemService::SUB_STATUS_COM_ERRO,
            'a lista fechada mudou: a fonte de cada valor está escrita na constante e trocar a lista é decisão, não refactor',
        );
    }

    /**
     * @group phase180
     */
    public function test_com_erro_sem_nenhum_item_casavel_devolve_zero_sem_lancar(): void
    {
        [$c] = $this->conta();

        // Acervo CHEIO, mas sem nenhum erro do ML — é o universo esperado da
        // maioria das contas, e é o cenário que o usuário aceitou em 13/10/2026
        // ao escolher a fonte: aba em 0, sem ponto vermelho. "Medido e é zero".
        $this->acervo($c, 'MLB-OK-1');
        $this->acervo($c, 'MLB-OK-2', ['sub_status' => ['cart_eligible']]);
        $this->acervo($c, 'MLB-OK-3', ['sub_status' => null]);

        $this->assertSame([], $this->achados($c, '', AcervoTriagemService::STATUS_COM_ERRO));
        $this->assertSame(
            0,
            $this->servico()->escopo($c, '', AcervoTriagemService::STATUS_COM_ERRO)->count(),
            'a contagem de "Com erro" sem item casável é 0 (int), nunca null — é "medido e é zero", não "nunca medimos"',
        );

        // E o cenário não está vazio: `todos` acha as 3 linhas.
        $this->assertCount(3, $this->achados($c, '', 'todos'), 'o acervo tem de estar CHEIO, senão o zero acima é vazio por construção');
    }

    // ═══ Caso 17 — as contagens das 7 abas sobre os DEMAIS filtros ═════════

    /**
     * @group phase180
     */
    public function test_contagens_das_abas_sao_sobre_os_demais_filtros(): void
    {
        [$c] = $this->conta();

        $this->acervo($c, 'MLB-A-PREM', ['status' => 'active', 'listing_type_id' => 'gold_pro']);
        $this->acervo($c, 'MLB-A-CLAS', ['status' => 'active', 'listing_type_id' => 'gold_special']);
        $this->acervo($c, 'MLB-P-PREM', ['status' => 'paused', 'listing_type_id' => 'gold_pro']);
        $this->acervo($c, 'MLB-R-PREM', ['status' => 'under_review', 'listing_type_id' => 'gold_pro', 'sub_status' => ['waiting_for_patch']]);
        $this->acervo($c, 'MLB-F-CLAS', ['status' => 'closed', 'listing_type_id' => 'gold_special']);

        $abas = ['acionaveis', 'ativos', 'pausados', 'em_revisao', 'encerrados', AcervoTriagemService::STATUS_COM_ERRO, 'todos'];

        $contar = fn (array $filtros) => array_map(
            fn (string $aba) => $this->servico()->filtros($this->servico()->escopo($c, '', $aba), $filtros)->count(),
            array_combine($abas, $abas),
        );

        // Sem filtro nenhum.
        $this->assertSame(
            ['acionaveis' => 4, 'ativos' => 2, 'pausados' => 1, 'em_revisao' => 1, 'encerrados' => 1, 'com_erro' => 1, 'todos' => 5],
            $contar([]),
            'as 7 contagens de aba sem filtro',
        );

        // ⚠️ LIST-05: com `tipo=premium` ativo as contagens MUDAM — é isto que
        // significa "calculadas sobre os DEMAIS filtros". Se `filtros()` não
        // fosse aplicado, os dois conjuntos seriam idênticos.
        $this->assertSame(
            ['acionaveis' => 3, 'ativos' => 1, 'pausados' => 1, 'em_revisao' => 1, 'encerrados' => 0, 'com_erro' => 1, 'todos' => 3],
            $contar(['tipo' => AcervoTriagemService::TIPO_PREMIUM]),
            'LIST-05: as contagens das abas têm de ser calculadas sobre os DEMAIS filtros (aqui, tipo=premium)',
        );

        // E cada contagem é a da SUA aba: nenhuma delas depende de qual aba o
        // usuário tem selecionada, porque `escopo()` recebe a chave da própria
        // aba. Prova: a contagem de `ativos` é a mesma nas duas chamadas.
        $this->assertSame(
            $contar(['tipo' => AcervoTriagemService::TIPO_PREMIUM])['ativos'],
            $this->servico()->filtros(
                $this->servico()->escopo($c, '', 'ativos'),
                ['tipo' => AcervoTriagemService::TIPO_PREMIUM],
            )->count(),
        );

        // ⚠️ `filtros()` NÃO mexe em `status`: aplicá-lo sobre `todos` não pode
        // recortar status nenhum (só o `tipo`).
        $this->assertSame(
            ['MLB-A-PREM', 'MLB-P-PREM', 'MLB-R-PREM'],
            $this->achados($c, '', 'todos', null, ['tipo' => AcervoTriagemService::TIPO_PREMIUM]),
            '`filtros()` só aplica os filtros do PRÓPRIO acervo — o eixo de status é de `escopo()`',
        );
    }

    // ═══ Caso 18 — `triagem.total` nunca é a soma dos chips ════════════════

    /**
     * @group phase180
     */
    public function test_triagem_total_nao_e_a_soma_dos_chips_e_respeita_os_filtros(): void
    {
        [$c] = $this->conta();

        // Um anúncio com DOIS motivos: conta 1 no total e 2 nos chips.
        $this->acervo($c, 'MLB-DOIS-MOTIVOS', [
            'status' => 'paused',
            'listing_type_id' => 'gold_pro',
            'severidade' => MlAcervoItem::SEVERIDADE_CRITICA,
            'motivos' => [MlAcervoItem::MOTIVO_PAUSADO, MlAcervoItem::MOTIVO_FICHA_INCOMPLETA],
        ]);
        // Um Clássico com um motivo — é o que sai quando `tipo=premium` entra.
        $this->acervo($c, 'MLB-UM-MOTIVO', [
            'status' => 'paused',
            'listing_type_id' => 'gold_special',
            'severidade' => MlAcervoItem::SEVERIDADE_CRITICA,
            'motivos' => [MlAcervoItem::MOTIVO_PAUSADO],
        ]);

        $triagem = $this->servico()->triagem($c, '', 'acionaveis');
        $porChave = array_column($triagem['chips'], 'count', 'chave');

        // ⚠️ LIST-22: o total são os anúncios DISTINTOS com >= 1 motivo. A soma
        // dos chips aqui dá 3 — e mentiria sobre "N anúncios precisam de você".
        $this->assertSame(2, $triagem['total'], 'LIST-22: `triagem.total` são anúncios DISTINTOS com motivo, nunca a soma dos chips');
        $this->assertSame(3, array_sum($porChave), 'a soma dos chips tem de ser DIFERENTE do total, senão o caso é vazio por construção');
        $this->assertSame(2, $porChave[MlAcervoItem::MOTIVO_PAUSADO]);
        $this->assertSame(1, $porChave[MlAcervoItem::MOTIVO_FICHA_INCOMPLETA]);

        // ⚠️ LIST-05: `triagem()` tem de respeitar os MESMOS filtros da
        // listagem, senão a tela mostra 1 linha e os chips contam 2.
        $triagemPremium = $this->servico()->triagem($c, '', 'acionaveis', ['tipo' => AcervoTriagemService::TIPO_PREMIUM]);
        $premiumPorChave = array_column($triagemPremium['chips'], 'count', 'chave');

        $this->assertSame(1, $triagemPremium['total'], '`triagem()` tem de aplicar os filtros novos — sem isso os chips contam itens que a listagem não mostra');
        $this->assertSame(1, $premiumPorChave[MlAcervoItem::MOTIVO_PAUSADO]);
        $this->assertSame(1, $premiumPorChave[MlAcervoItem::MOTIVO_FICHA_INCOMPLETA]);

        // ─── E o filtro de MOTIVO continua FORA da triagem (contrato antigo):
        // os chips mostram os outros motivos quando um já está filtrado. O
        // array de filtros não tem chave `motivo` nenhuma — por construção. ───
        $this->assertSame(
            $triagem,
            $this->servico()->triagem($c, '', 'acionaveis', ['motivo' => MlAcervoItem::MOTIVO_PAUSADO]),
            'chave desconhecida em $filtros tem de ser ignorada: `motivo` nunca entra na triagem (os chips precisam mostrar os outros motivos)',
        );

        // ─── A triagem segue o 4º braço da busca: o universo dos chips é o
        // MESMO da listagem, inclusive quando a busca achou pelo produto. ───
        [$c2, $e2] = $this->conta('Conta da busca por produto');
        $this->acervo($c2, 'MLB-VIA-PRODUTO', [
            'status' => 'paused',
            'title' => 'Título que a IA reescreveu',
            'severidade' => MlAcervoItem::SEVERIDADE_CRITICA,
            'motivos' => [MlAcervoItem::MOTIVO_PAUSADO],
        ]);
        $produto = $this->produto($c2, $e2, 'PRD-TRIAGEM', 'Banqueta Alta');
        $this->publicada($produto, 'MLB-VIA-PRODUTO');

        $this->assertSame(
            0,
            $this->servico()->triagem($c2, 'Banqueta', 'acionaveis')['total'],
            'sem o serviço da lista, a triagem se comporta como antes do plano',
        );
        $this->assertSame(
            1,
            $this->servico()->triagem($c2, 'Banqueta', 'acionaveis', [], $this->lista())['total'],
            'com o serviço da lista, os chips contam o MESMO universo que a listagem mostra — senão a tela mostra 1 linha e os chips dizem 0',
        );
    }

    // ═══ Custo — o acervo não pode dirigir a busca por produto ═════════════

    /**
     * @group phase180
     */
    public function test_custo_da_busca_por_produto_nao_cresce_com_o_acervo(): void
    {
        [$c, $e] = $this->conta();

        $produto = $this->produto($c, $e, 'PRD-CUSTO', 'Estante Modular');
        $this->publicada($produto, 'MLB-ALVO');
        $this->acervo($c, 'MLB-ALVO', ['title' => 'Título diferente do produto']);

        for ($n = 0; $n < 40; $n++) {
            $this->acervo($c, 'MLB-RUIDO-'.$n);
        }

        // Aquece: a 1ª chamada pode popular cache de serviço vivo, e contar a
        // primeira faria o custo parecer maior (ou menor) do que é.
        $this->achados($c, 'Estante', 'acionaveis', $this->lista());

        DB::enableQueryLog();
        DB::flushQueryLog();
        $achados = $this->achados($c, 'Estante', 'acionaveis', $this->lista());
        $log = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertSame(['MLB-ALVO'], $achados);

        // ⚠️ DUAS consultas, e só DUAS: a do produto (que NÃO toca o acervo) e a
        // do acervo (que recebe os ids prontos). Nunca uma por linha.
        $this->assertCount(2, $log, 'a busca por produto é 1 consulta em pub_produtos + 1 no acervo — nunca uma por linha');

        $sqlDoProduto = $log[0]['query'];
        $this->assertStringContainsString('pub_produtos', $sqlDoProduto);
        $this->assertStringNotContainsString(
            'ml_acervo_itens',
            $sqlDoProduto,
            'o acervo (1.080.206 linhas / 1.776 MB na última medição) NÃO pode entrar na junção da busca por produto — ele não é o lado dirigente',
        );

        $sqlDoAcervo = $log[1]['query'];
        $this->assertStringContainsString('ml_acervo_itens', $sqlDoAcervo);
        $this->assertStringNotContainsString(
            'pub_produtos',
            $sqlDoAcervo,
            'a consulta do acervo recebe os ids PRONTOS — nenhum join com o publicador a partir do acervo',
        );
        $this->assertStringContainsString('"company_id" = ?', $sqlDoAcervo, 'o escopo por empresa tem de estar no SQL do acervo');

        // O teto é o do serviço irmão — cobrado pela CONSTANTE, não pelo número.
        $this->assertLessThanOrEqual(
            PublicacoesDaListaService::TETO_IDS_POR_BUSCA,
            substr_count($sqlDoAcervo, '?'),
            'o IN da busca por produto tem de caber no teto público de PublicacoesDaListaService',
        );

        // ─── Acervo 10× maior: a contagem de consultas NÃO se mexe ───
        for ($n = 40; $n < 440; $n++) {
            $this->acervo($c, 'MLB-RUIDO-'.$n);
        }

        DB::enableQueryLog();
        DB::flushQueryLog();
        $achadosDepois = $this->achados($c, 'Estante', 'acionaveis', $this->lista());
        $logDepois = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertSame(['MLB-ALVO'], $achadosDepois, '400 linhas a mais no acervo não podem mudar o resultado da busca por produto');
        $this->assertCount(2, $logDepois, 'o custo da busca por produto não pode crescer com o tamanho do acervo');
    }

    // ═══ Compatibilidade — o escopo sem o 4º parâmetro ═════════════════════

    /**
     * @group phase180
     */
    public function test_assinatura_de_escopo_e_aditiva(): void
    {
        $escopo = new \ReflectionMethod(AcervoTriagemService::class, 'escopo');
        $parametros = $escopo->getParameters();

        $this->assertSame(['company', 'busca', 'statusFiltro', 'lista'], array_map(fn ($p) => $p->getName(), $parametros));
        $this->assertTrue($parametros[3]->isOptional(), 'o 4º parâmetro TEM de ser opcional: é o que mantém os 4 chamadores existentes e MeusAnunciosTest verdes sem edição');
        $this->assertNull($parametros[3]->getDefaultValue());

        $triagem = new \ReflectionMethod(AcervoTriagemService::class, 'triagem');
        $paramsTriagem = $triagem->getParameters();
        $this->assertSame(['company', 'busca', 'statusFiltro', 'filtros', 'lista'], array_map(fn ($p) => $p->getName(), $paramsTriagem));
        $this->assertTrue($paramsTriagem[3]->isOptional());
        $this->assertSame([], $paramsTriagem[3]->getDefaultValue());
        $this->assertTrue($paramsTriagem[4]->isOptional());

        // E o serviço continua SEM dependência de construtor (convenção escrita
        // no docblock da classe): o PublicacoesDaListaService chega por
        // parâmetro, de quem já o tem, nunca por `app()`.
        $construtor = (new \ReflectionClass(AcervoTriagemService::class))->getConstructor();
        $this->assertNull($construtor, 'AcervoTriagemService não pode ganhar dependência de construtor');
    }
}
