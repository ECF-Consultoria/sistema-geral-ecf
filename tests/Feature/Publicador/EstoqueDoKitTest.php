<?php

namespace Tests\Feature\Publicador;

use App\Models\Company;
use App\Models\MlAcervoItem;
use App\Models\MlToken;
use App\Models\PubProduto;
use App\Models\PubPublicacao;
use App\Models\PubPublicacaoItem;
use App\Models\PubRascunho;
use App\Services\Publicador\EditorRascunhoService;
use App\Services\Publicador\RascunhoRepository;
use App\Services\Publicador\RecalculoEstoqueDoKitService;
use App\Support\Publicador\Payload\Alvo;
use App\Support\Publicador\Payload\MontadorDePlano;
use App\Support\Publicador\Variacao\ChaveCanonica;
use App\Support\Publicador\Variacao\Eixo;
use App\Support\Publicador\Variacao\RegeneradorVariantes;
use App\Support\Publicador\Variacao\ValorEixo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Fase 175 Plano 175-09 (§7 da ETAPA-3) — o estoque do kit acompanha o do
 * produto base: `floor(estoque do base ÷ N)` por variante E por depósito.
 *
 * ⚠️ O gancho vive em `EditorRascunhoService::salvarVariantes()`, o caminho de
 * escrita mais quente do módulo (roda a cada digitação na grade de variantes).
 * Por isso este arquivo guarda três coisas que não são sobre kit nenhum:
 *
 * 1. **Custo.** Gravar variantes de qualquer produto do módulo custa UMA consulta
 *    a mais: o `exists()` indexado por `produto_base_id`. Nem o `PubProduto` é
 *    carregado (seria a segunda).
 * 2. **Não recorre.** `salvarVariantes` do kit chama o gancho de novo; o laço
 *    só não existe porque kit nunca é base. É um laço que só apareceria em
 *    produção, então ele é provado por execução aqui — e há um teste do fusível
 *    de memória para o ciclo que só dados corrompidos criam.
 * 3. **Falha na propagação não derruba a gravação do base.** A escrita do base
 *    é a que a pessoa pediu; o recálculo é consequência.
 *
 * A segunda metade do arquivo é o `estado()` contando a fase ao editor — com o
 * GATE de regressão que prova que nenhuma chave antiga do presenter saiu e que
 * produto que não é kit passa por ele sem mudança.
 *
 * @group phase175
 */
class EstoqueDoKitTest extends TestCase
{
    use RefreshDatabase;

    private RascunhoRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = new RascunhoRepository();
        // Nada aqui fala com o Mercado Livre (nem com a API de sinais do layout).
        Http::fake();
    }

    private function editor(): EditorRascunhoService
    {
        return app(EditorRascunhoService::class);
    }

    private function recalculo(): RecalculoEstoqueDoKitService
    {
        return app(RecalculoEstoqueDoKitService::class);
    }

    // ═══ Cenário: um base e os kits dele ═════════════════════════════════════

    /**
     * Um produto base da Fase 1 com rascunho e a variante única.
     *
     * Sem oferta do Portal de propósito: `DadosEfetivosService::daProduto()` sai
     * na hora quando `oferta_id` é NULL (D16), e nenhum teste deste arquivo é
     * sobre título/preço efetivo — é o jeito de `estado()` rodar aqui sem mock.
     */
    private function base(): PubProduto
    {
        $empresa = Company::factory()->create();
        MlToken::create([
            'company_id' => $empresa->id, 'ml_user_id' => '1555596317', 'access_token' => 'fake-access-token',
            'refresh_token' => 'fake-refresh-token', 'token_type' => 'bearer', 'expires_at' => now()->addHours(5),
            'last_refreshed_at' => now(), 'status' => 'active', 'connected_at' => now(),
        ]);
        $produto = PubProduto::create([
            'company_id' => $empresa->id, 'sku' => 'CAD-01',
            'nome' => 'Cadeira', 'origem' => PubProduto::ORIGEM_PUBLICADOR,
        ]);
        $this->repo->criar($produto, [new Alvo('gold_special', 'Cadeira Executiva ECF')]);

        return $produto->fresh('rascunho');
    }

    /**
     * Um kit de N unidades do base, com rascunho próprio e as MESMAS chaves de
     * variante. `$calculado = false` é o combo vinculado (estoque próprio).
     */
    private function kit(PubProduto $base, int $n, bool $calculado = true, ?array $eixos = null): PubProduto
    {
        $kit = PubProduto::create([
            'company_id' => $base->company_id, 'mlb_empresa_id' => $base->mlb_empresa_id,
            'sku' => "CAD-01-KIT{$n}", 'nome' => "Kit {$n} Cadeira", 'origem' => PubProduto::ORIGEM_PUBLICADOR,
            'produto_base_id' => $base->id, 'quantidade_kit' => $n, 'fase' => $n,
            'estoque_calculado' => $calculado,
        ]);
        $r = $this->repo->criar($kit, [new Alvo('gold_special', "Kit {$n} Cadeira Executiva ECF")]);
        if ($eixos !== null) {
            $this->comEixos($r, $eixos);
        }

        return $kit->fresh('rascunho');
    }

    /** Regenera as variantes do rascunho pelos eixos pedidos (molde do `RascunhoRepositoryTest`). */
    private function comEixos(PubRascunho $r, array $eixos): void
    {
        $s = $this->repo->snapshot($r->fresh());
        $regen = RegeneradorVariantes::regenerar($s->variantes, $eixos);
        $this->repo->gravarVariacao($r->fresh(), $eixos, $regen->variantes);
    }

    private static function cor(array $nomes): Eixo
    {
        $ids = ['Preto' => '52049', 'Azul' => '52028', 'Branco' => '52055'];

        return new Eixo('COLOR', 'Cor', 0, true, array_map(fn ($n) => new ValorEixo($ids[$n], $n), $nomes));
    }

    /** `chave da variante => [estoque, estoque_depositos]` do rascunho, direto do banco. */
    private function estoques(PubRascunho $r): array
    {
        return $r->fresh()->variantes()->orderBy('posicao')->orderBy('id')->get()
            ->mapWithKeys(fn ($v) => [$v->combinacao_chave => [
                'estoque' => $v->estoque === null ? null : (int) $v->estoque,
                'depositos' => $v->estoque_depositos,
            ]])->all();
    }

    // ═══ A divisão ═══════════════════════════════════════════════════════════

    public function test_estoque_simples_do_base_divide_por_n_no_kit(): void
    {
        $base = $this->base();
        $kit = $this->kit($base, 2);

        $this->editor()->salvarVariantes($base->rascunho, [ChaveCanonica::UNICA => ['estoque' => 7]]);

        $this->assertSame(7, (int) $base->rascunho->fresh()->variantes()->value('estoque'), 'o base grava o que a pessoa digitou');
        $this->assertSame(3, (int) $kit->rascunho->fresh()->variantes()->value('estoque'), 'floor(7 ÷ 2)');
    }

    public function test_estoque_por_deposito_divide_cada_deposito_e_depois_soma(): void
    {
        $base = $this->base();
        $kit = $this->kit($base, 2);

        $this->editor()->salvarVariantes($base->rascunho, [
            ChaveCanonica::UNICA => ['estoque_depositos' => ['A' => 7, 'B' => 2]],
        ]);

        $this->assertSame([ChaveCanonica::UNICA => ['estoque' => 4, 'depositos' => ['A' => 3, 'B' => 1]]], $this->estoques($kit->rascunho));
    }

    /**
     * ⚠️ O caso que distingue as duas contas possíveis: dividir cada depósito e
     * somar dá 2; somar e dividir (`floor(10 ÷ 3)`) daria 3.
     */
    public function test_divide_cada_deposito_antes_de_somar_nunca_a_soma(): void
    {
        $base = $this->base();
        $kit = $this->kit($base, 3);

        $this->editor()->salvarVariantes($base->rascunho, [
            ChaveCanonica::UNICA => ['estoque_depositos' => ['A' => 5, 'B' => 5]],
        ]);

        $resultado = $this->estoques($kit->rascunho)[ChaveCanonica::UNICA];
        $this->assertSame(['A' => 1, 'B' => 1], $resultado['depositos']);
        $this->assertSame(2, $resultado['estoque'], 'soma dos divididos (2), não floor(soma ÷ N) (3)');
    }

    public function test_dois_kits_do_mesmo_base_sao_recalculados_na_mesma_gravacao(): void
    {
        $base = $this->base();
        $kit2 = $this->kit($base, 2);
        $kit3 = $this->kit($base, 3);

        $this->editor()->salvarVariantes($base->rascunho, [ChaveCanonica::UNICA => ['estoque' => 7]]);

        $this->assertSame(3, (int) $kit2->rascunho->fresh()->variantes()->value('estoque'));
        $this->assertSame(2, (int) $kit3->rascunho->fresh()->variantes()->value('estoque'));
    }

    public function test_estoque_nulo_no_base_deixa_o_kit_nulo_nunca_zero(): void
    {
        $base = $this->base();
        $kit = $this->kit($base, 2);
        $this->editor()->salvarVariantes($base->rascunho, [ChaveCanonica::UNICA => ['estoque' => 9]]);
        $this->assertSame(4, (int) $kit->rascunho->fresh()->variantes()->value('estoque'));

        $this->editor()->salvarVariantes($base->rascunho->fresh(), [ChaveCanonica::UNICA => ['estoque' => null]]);

        $this->assertNull($kit->rascunho->fresh()->variantes()->value('estoque'), 'nulo é "não informado", nunca 0');
    }

    public function test_recalculo_por_variante_com_eixos(): void
    {
        $base = $this->base();
        $this->comEixos($base->rascunho, [self::cor(['Preto', 'Azul'])]);
        $kit = $this->kit($base, 2, eixos: [self::cor(['Preto', 'Azul'])]);

        $this->editor()->salvarVariantes($base->rascunho->fresh(), [
            'COLOR=id:52049' => ['estoque' => 7],
            'COLOR=id:52028' => ['estoque' => 2],
        ]);

        $this->assertSame([
            'COLOR=id:52049' => ['estoque' => 3, 'depositos' => null],
            'COLOR=id:52028' => ['estoque' => 1, 'depositos' => null],
        ], $this->estoques($kit->rascunho));
    }

    // ═══ Quem fica de fora ═══════════════════════════════════════════════════

    public function test_combo_vinculado_com_estoque_calculado_falso_nao_e_tocado(): void
    {
        $base = $this->base();
        $combo = $this->kit($base, 2, calculado: false);
        $this->editor()->salvarVariantes($combo->rascunho, [ChaveCanonica::UNICA => ['estoque' => 99]]);
        $revisaoAntes = $combo->rascunho->fresh()->revisao;

        $this->editor()->salvarVariantes($base->rascunho->fresh(), [ChaveCanonica::UNICA => ['estoque' => 7]]);

        $this->assertSame(99, (int) $combo->rascunho->fresh()->variantes()->value('estoque'), 'o combo mantém o estoque próprio');
        $this->assertSame($revisaoAntes, $combo->rascunho->fresh()->revisao, 'e a revisão dele nem sobe');
    }

    public function test_variante_do_kit_sem_par_no_base_e_ignorada_sem_lancar(): void
    {
        $base = $this->base();
        $this->comEixos($base->rascunho, [self::cor(['Preto'])]);
        // A grade do kit divergiu depois de uma edição de eixos: Branco não existe no base.
        $kit = $this->kit($base, 2, eixos: [self::cor(['Branco'])]);
        $this->editor()->salvarVariantes($kit->rascunho, ['COLOR=id:52055' => ['estoque' => 50]]);

        $this->editor()->salvarVariantes($base->rascunho->fresh(), ['COLOR=id:52049' => ['estoque' => 7]]);

        $this->assertSame(50, (int) $kit->rascunho->fresh()->variantes()->value('estoque'), 'sem par, a variante fica como está');
    }

    // ═══ Kit já publicado: recalcula o rascunho e avisa ══════════════════════

    public function test_kit_publicado_e_recalculado_e_volta_como_divergente(): void
    {
        $base = $this->base();
        $kit = $this->kit($base, 2);
        $kit->rascunho->update(['status' => PubRascunho::PUBLISHED]);

        $resultado = $this->recalculo()->propagar(
            $this->gravarNoBase($base, [ChaveCanonica::UNICA => ['estoque' => 7]]),
        );

        $this->assertSame(3, (int) $kit->rascunho->fresh()->variantes()->value('estoque'), 'o rascunho é a verdade local e acompanha');
        $this->assertSame([$kit->id], $resultado['kits']);
        $this->assertSame([$kit->id], $resultado['divergentes'], 'kit publicado que mudou de estoque é divergente');
    }

    public function test_kit_em_rascunho_que_muda_nao_e_divergente(): void
    {
        $base = $this->base();
        $kit = $this->kit($base, 2);

        $resultado = $this->recalculo()->propagar(
            $this->gravarNoBase($base, [ChaveCanonica::UNICA => ['estoque' => 7]]),
        );

        $this->assertSame([$kit->id], $resultado['kits']);
        $this->assertSame([], $resultado['divergentes'], 'DRAFT não tem anúncio no ar para divergir');
    }

    public function test_a_revisao_do_rascunho_do_kit_sobe_quando_o_estoque_muda(): void
    {
        $base = $this->base();
        $kit = $this->kit($base, 2);
        $kit->rascunho->update(['status' => PubRascunho::VALIDATED]);
        $antes = (int) $kit->rascunho->fresh()->revisao;

        $this->editor()->salvarVariantes($base->rascunho, [ChaveCanonica::UNICA => ['estoque' => 7]]);

        $depois = $kit->rascunho->fresh();
        $this->assertGreaterThan($antes, (int) $depois->revisao, 'conferência antiga não vale para o estoque novo');
        $this->assertSame(PubRascunho::DRAFT, $depois->status, '`tocar()` devolve VALIDATED para DRAFT');
    }

    public function test_gravacao_que_nao_muda_o_estoque_do_kit_nao_sobe_a_revisao_dele(): void
    {
        $base = $this->base();
        $kit = $this->kit($base, 2);
        $this->editor()->salvarVariantes($base->rascunho, [ChaveCanonica::UNICA => ['estoque' => 7]]);
        $antes = (int) $kit->rascunho->fresh()->revisao;

        // A grade do editor salva a cada digitação: regravar o MESMO estoque não
        // pode invalidar a conferência do kit de novo.
        $this->editor()->salvarVariantes($base->rascunho->fresh(), [ChaveCanonica::UNICA => ['estoque' => 7]]);

        $this->assertSame($antes, (int) $kit->rascunho->fresh()->revisao);
    }

    // ═══ Custo e recursão ════════════════════════════════════════════════════

    /**
     * ⚠️ Divergência do `<behavior>` do plano, medida: o plano escreve "não
     * dispara consulta extra nenhuma", e isso é impossível — o produto não sabe
     * se tem kit sem perguntar ao banco. O contrato REAL, que este teste trava,
     * é UMA consulta: o `exists()` indexado por `produto_base_id`. Em especial,
     * o serviço NÃO carrega `$rascunho->produto` (seria a segunda consulta, em
     * toda gravação de variante do módulo) — ele usa `produto_id` do rascunho.
     */
    public function test_gravar_variantes_de_produto_sem_kit_custa_uma_consulta_a_mais(): void
    {
        $base = $this->base();
        $r = $base->rascunho->fresh();
        $this->editor()->salvarVariantes($r, [ChaveCanonica::UNICA => ['estoque' => 5]]);

        $semGancho = $this->contarConsultas(fn () => $this->recalculo()->propagar($r));

        $this->assertSame(1, $semGancho, 'só o exists() indexado por produto_base_id');
    }

    public function test_recalculo_nao_recorre_porque_kit_nunca_e_base(): void
    {
        $base = $this->base();
        $kit = $this->kit($base, 2);
        $this->editor()->salvarVariantes($base->rascunho, [ChaveCanonica::UNICA => ['estoque' => 7]]);
        $revisaoDoBase = (int) $base->rascunho->fresh()->revisao;
        $doKit = $kit->rascunho->fresh();

        // Gravar o KIT: o gancho roda de novo e tem de parar no `exists()`.
        $consultas = $this->contarConsultas(fn () => $this->recalculo()->propagar($doKit));

        $this->assertSame(1, $consultas, 'kit não é base de ninguém: o exists() devolve falso e o laço morre aí');
        $this->assertSame($revisaoDoBase, (int) $base->rascunho->fresh()->revisao, 'e o base não foi reescrito');
        $this->assertSame(7, (int) $base->rascunho->fresh()->variantes()->value('estoque'));
    }

    /**
     * O fusível de memória (T-175-38), para o caso de dados corrompidos criarem o
     * CICLO em `produto_base_id` que o `CriarFaseService` (KIT-02) e o unique
     * `pubprod_base_qtd_uq` recusam: a propagação dá no máximo uma volta de
     * sobra e PARA — nunca estoura a pilha nem escreve para sempre.
     */
    public function test_ciclo_corrompido_na_familia_nao_recursa_infinitamente(): void
    {
        $base = $this->base();
        $kit = $this->kit($base, 2);
        // Ciclo à mão: o base passa a apontar para o próprio kit.
        $base->update(['produto_base_id' => $kit->id, 'quantidade_kit' => 2, 'estoque_calculado' => true]);
        $revisaoDoBase = (int) $base->rascunho->fresh()->revisao;

        $this->editor()->salvarVariantes($base->rascunho->fresh(), [ChaveCanonica::UNICA => ['estoque' => 8]]);

        $this->assertSame(4, (int) $kit->rascunho->fresh()->variantes()->value('estoque'), 'floor(8 ÷ 2)');
        $this->assertLessThanOrEqual(
            $revisaoDoBase + 2,
            (int) $base->rascunho->fresh()->revisao,
            'a escrita pedida mais, no máximo, uma volta de sobra',
        );
    }

    public function test_falha_na_propagacao_nao_desfaz_a_gravacao_do_base(): void
    {
        $base = $this->base();
        $this->kit($base, 2);

        $this->mock(RecalculoEstoqueDoKitService::class, function ($m) {
            $m->shouldReceive('propagar')->andThrow(new \RuntimeException('banco caiu no meio da propagação'));
        });

        $this->editor()->salvarVariantes($base->rascunho, [ChaveCanonica::UNICA => ['estoque' => 7]]);

        $this->assertSame(7, (int) $base->rascunho->fresh()->variantes()->value('estoque'), 'a escrita que a pessoa pediu ficou');
    }

    public function test_falha_num_kit_nao_impede_o_recalculo_do_outro(): void
    {
        $base = $this->base();
        $kit2 = $this->kit($base, 2);
        $kit3 = $this->kit($base, 3);
        // Kit 2 sem rascunho: o recálculo pula e segue para o Kit 3.
        $kit2->rascunho->variantes()->delete();
        $kit2->rascunho->delete();

        $resultado = $this->recalculo()->propagar(
            $this->gravarNoBase($base, [ChaveCanonica::UNICA => ['estoque' => 7]]),
        );

        $this->assertSame([$kit3->id], $resultado['kits']);
        $this->assertSame(2, (int) $kit3->rascunho->fresh()->variantes()->value('estoque'));
    }

    // ═══ `estado()` conta a fase ao editor (Task 2) ══════════════════════════

    /**
     * ⚠️ O GATE DE REGRESSÃO do plano: `estado()` é o presenter do editor
     * inteiro, consumido por `usePublicador`, por `MlbPublicadorController` e por
     * dezenas de testes. Chave nova é ADITIVA; nenhuma antiga pode sair.
     */
    public function test_nenhuma_chave_de_primeiro_nivel_do_estado_saiu(): void
    {
        $base = $this->base();

        $e = $this->editor()->estado($base->rascunho);

        $this->assertSame([
            'produto', 'rascunho', 'alvos', 'atributos', 'eixos', 'variantes', 'imagens',
            'atribuicoes', 'grupos_imagem', 'schema', 'erro_schema', 'conta', 'efetivos',
            'problemas', 'conferencia', 'publicacao', 'ja_publicados',
            // `portal` entrou no merge da Fase 176 (D-09, descrição que o cliente escreveu no
            // Portal). É ADITIVA e o gate pegou a mudança de forma — era para isso que ele existe.
            'portal',
            // `preparo_ia` (09/10/2026): o que a IA escreveu ao salvar no Portal e ainda está no campo —
            // o selo discreto do editor. Também ADITIVA.
            'preparo_ia',
            // `promocao_automatica` (10/10/2026): se a conta cria a promoção de 14 dias sozinha depois de
            // publicar (a frase embaixo do preço). ADITIVA, no fim.
            'promocao_automatica',
        ], array_keys($e));
    }

    public function test_produto_que_nao_e_kit_passa_pelo_estado_sem_mudanca(): void
    {
        $base = $this->base();

        $p = $this->editor()->estado($base->rascunho)['produto'];

        // As sete chaves de antes, com os mesmos valores.
        $this->assertSame($base->id, $p['id']);
        $this->assertSame('CAD-01', $p['sku']);
        $this->assertSame('Cadeira', $p['nome']);
        $this->assertNull($p['oferta_id']);
        $this->assertSame(PubProduto::ORIGEM_PUBLICADOR, $p['origem']);
        $this->assertSame($base->company_id, $p['company_id']);
        $this->assertNull($p['mlb_empresa_id']);

        // E as novas dizem "não é kit", sem nada para a barra renderizar.
        $this->assertFalse($p['eh_kit']);
        $this->assertNull($p['rotulo_fase']);
        $this->assertNull($p['base']);
        $this->assertSame(1, $p['fase']);
        $this->assertSame(1, $p['quantidade_kit']);
        $this->assertFalse($p['estoque_calculado']);
        $this->assertFalse($p['aviso_base_apagado']);
        $this->assertFalse($p['aviso_estoque_ml']);
    }

    public function test_estado_do_kit_traz_a_fase_o_rotulo_e_o_produto_base(): void
    {
        $base = $this->base();
        $kit = $this->kit($base, 2);
        $kit->update(['fase' => 2]);

        $p = $this->editor()->estado($kit->rascunho)['produto'];

        $this->assertTrue($p['eh_kit']);
        $this->assertSame(2, $p['fase']);
        $this->assertSame(2, $p['quantidade_kit']);
        $this->assertSame('Fase 2 · Kit 2', $p['rotulo_fase']);
        $this->assertTrue($p['estoque_calculado']);
        $this->assertSame($base->id, $p['base']['id']);
        $this->assertSame('CAD-01', $p['base']['sku']);
        $this->assertSame('Cadeira', $p['base']['nome']);
        $this->assertStringContainsString((string) $base->id, (string) $p['base']['url']);
        // Todo campo que a barra exibe é string ou número — nunca objeto (07/10).
        foreach (['rotulo_fase'] as $campo) {
            $this->assertIsString($p[$campo]);
        }
        foreach (['id', 'sku', 'nome', 'url'] as $campo) {
            $this->assertNotIsArrayOuObjeto($p['base'][$campo]);
        }
    }

    public function test_kit_de_conta_sem_token_tem_base_sem_url_nunca_link_quebrado(): void
    {
        $base = $this->base();
        $kit = $this->kit($base, 2);
        // A conta perdeu o token: `contaOuNula()` devolve null e não há chave de conta.
        $base->company->mlToken()->delete();

        $p = $this->editor()->estado($kit->rascunho->fresh())['produto'];

        $this->assertSame($base->id, $p['base']['id']);
        $this->assertNull($p['base']['url']);
    }

    public function test_kit_cujo_base_foi_apagado_avisa_e_fica_sem_base(): void
    {
        $base = $this->base();
        $kit = $this->kit($base, 2);
        // `pubprod_base_fk` é SET NULL: o kit fica solto COM o histórico dele.
        $kit->update(['produto_base_id' => null]);

        $p = $this->editor()->estado($kit->rascunho->fresh())['produto'];

        $this->assertTrue($p['aviso_base_apagado']);
        $this->assertNull($p['base']);
        $this->assertTrue($p['eh_kit'], 'órfão continua sendo Kit 2 na barra');
        $this->assertSame('Fase 2 · Kit 2', $p['rotulo_fase']);
    }

    public function test_kit_publicado_com_estoque_diferente_no_acervo_avisa(): void
    {
        [$base, $kit] = $this->kitPublicado(estoqueNoMl: 9);

        $p = $this->editor()->estado($kit->rascunho->fresh())['produto'];

        $this->assertTrue($p['aviso_estoque_ml'], 'calculado 3, no ar 9');
        $this->assertSame('Estoque no ML difere do calculado', EditorRascunhoService::AVISO_ESTOQUE_ML);
    }

    public function test_kit_publicado_com_o_mesmo_estoque_no_acervo_nao_avisa(): void
    {
        [$base, $kit] = $this->kitPublicado(estoqueNoMl: 3);

        $this->assertFalse($this->editor()->estado($kit->rascunho->fresh())['produto']['aviso_estoque_ml']);
    }

    /** Aviso baseado em dado inexistente é pior que silêncio: sem coleta, não avisa. */
    public function test_sem_coleta_de_acervo_o_kit_publicado_nao_avisa(): void
    {
        [$base, $kit] = $this->kitPublicado(estoqueNoMl: null);

        $this->assertFalse($this->editor()->estado($kit->rascunho->fresh())['produto']['aviso_estoque_ml']);

        MlAcervoItem::query()->delete();
        $this->assertFalse($this->editor()->estado($kit->rascunho->fresh())['produto']['aviso_estoque_ml'], 'nem linha de acervo há');
    }

    /** O combo vinculado digita o estoque dele: divergência com o ML não é notícia. */
    public function test_combo_vinculado_publicado_nao_avisa_divergencia(): void
    {
        [$base, $kit] = $this->kitPublicado(estoqueNoMl: 9, calculado: false);

        $this->assertFalse($this->editor()->estado($kit->rascunho->fresh())['produto']['aviso_estoque_ml']);
    }

    // ═══ Apoio ═══════════════════════════════════════════════════════════════

    /**
     * Um kit publicado (um item CREATED no ML) com o estoque do acervo em
     * `$estoqueNoMl` (null = coletado sem quantidade). O calculado é 3.
     *
     * @return array{0: PubProduto, 1: PubProduto}
     */
    private function kitPublicado(?int $estoqueNoMl, bool $calculado = true): array
    {
        $base = $this->base();
        $kit = $this->kit($base, 2, calculado: $calculado);
        $this->editor()->salvarVariantes($base->rascunho, [ChaveCanonica::UNICA => ['estoque' => 7]]);
        if (! $calculado) {
            // Combo vinculado: o estoque dele é o que a pessoa digitou.
            $this->editor()->salvarVariantes($kit->rascunho, [ChaveCanonica::UNICA => ['estoque' => 3]]);
        }

        $r = $kit->rascunho->fresh();
        $r->update(['status' => PubRascunho::PUBLISHED]);
        // ⚠️ `RUNNING` de propósito: com qualquer outro status o `estado()` chama
        // `PublicacaoService::problemas()`, que pede o schema da CATEGORIA — e
        // este cenário é sem categoria, para não falar com o ML. O que importa
        // aqui é o ITEM `CREATED` (é ele que alimenta `ja_publicados`) e o
        // `status` do RASCUNHO, que é PUBLISHED.
        $pub = $r->publicacoes()->create([
            'status' => PubPublicacao::RUNNING, 'revisao' => $r->revisao,
            'modelo_publicacao' => MontadorDePlano::UP, 'chave_idempotencia' => "kit-{$kit->id}-r{$r->revisao}",
        ]);
        $pub->itens()->create([
            'indice' => 0, 'listing_type_id' => 'gold_special', 'variante_chave' => ChaveCanonica::UNICA,
            'status' => PubPublicacaoItem::CREATED, 'ml_item_id' => 'MLB9090',
        ]);
        MlAcervoItem::create([
            'company_id' => $base->company_id, 'ml_item_id' => 'MLB9090',
            'title' => 'Kit 2 Cadeira', 'available_quantity' => $estoqueNoMl,
        ]);

        return [$base, $kit];
    }

    private function assertNotIsArrayOuObjeto(mixed $valor): void
    {
        $this->assertTrue($valor === null || is_scalar($valor), 'campo do presenter não pode ser objeto (tela preta de 07/10)');
    }

    /** Grava no base SEM passar pelo gancho — para testar `propagar()` sozinho. */
    private function gravarNoBase(PubProduto $base, array $porChave): PubRascunho
    {
        $r = $base->rascunho->fresh();
        $s = $this->repo->snapshot($r);
        $variantes = array_map(function ($v) use ($porChave) {
            $novo = $porChave[$v->chave] ?? null;

            return is_array($novo) ? $v->comDados([...$v->dados, ...$novo]) : $v;
        }, $s->variantes);
        $this->repo->gravarVariacao($r, $s->eixos, $variantes);

        return $r->fresh();
    }

    private function contarConsultas(\Closure $fn): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        try {
            $fn();

            return count(DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
    }
}
