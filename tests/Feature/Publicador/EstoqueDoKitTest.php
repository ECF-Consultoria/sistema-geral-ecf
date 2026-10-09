<?php

namespace Tests\Feature\Publicador;

use App\Models\Company;
use App\Models\EstruturaOferta;
use App\Models\PubProduto;
use App\Models\PubRascunho;
use App\Services\Publicador\EditorRascunhoService;
use App\Services\Publicador\RascunhoRepository;
use App\Services\Publicador\RecalculoEstoqueDoKitService;
use App\Support\Publicador\Payload\Alvo;
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
 * 1. **Custo.** Gravar variantes de um produto que não é base de ninguém custa
 *    UMA consulta a mais (o `exists()` indexado por `produto_base_id`), e
 *    gravar as do próprio kit custa ZERO (kit não pode ser base).
 * 2. **Não recorre.** `salvarVariantes` do kit chama o gancho de novo; o laço
 *    só não existe porque kit nunca é base. É um laço que só apareceria em
 *    produção, então ele é provado por execução aqui.
 * 3. **Falha na propagação não derruba a gravação do base.** A escrita do base
 *    é a que a pessoa pediu; o recálculo é consequência.
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

    /** Um produto base da Fase 1 com rascunho e a variante única. */
    private function base(): PubProduto
    {
        $empresa = Company::factory()->create();
        $oferta = EstruturaOferta::create(['company_id' => $empresa->id, 'sku' => 'CAD-01', 'fase' => 'simples', 'nome' => 'Cadeira']);
        $produto = PubProduto::create([
            'company_id' => $empresa->id, 'oferta_id' => $oferta->id, 'sku' => 'CAD-01',
            'nome' => 'Cadeira', 'origem' => PubProduto::ORIGEM_PORTAL,
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

        $r = $this->editor()->salvarVariantes($base->rascunho, [ChaveCanonica::UNICA => ['estoque' => 7]]);

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

    public function test_gravar_variantes_de_produto_sem_kit_custa_uma_consulta_a_mais(): void
    {
        $base = $this->base();
        $r = $base->rascunho;
        // Primeira gravação fora da contagem: ela aquece o que o Eloquent carrega.
        $this->editor()->salvarVariantes($r, [ChaveCanonica::UNICA => ['estoque' => 5]]);

        $semGancho = $this->contarConsultas(fn () => $this->recalculo()->propagar($r->fresh()));

        $this->assertSame(1, $semGancho, 'só o exists() indexado por produto_base_id');
    }

    public function test_recalculo_nao_recorre_porque_kit_nunca_e_base(): void
    {
        $base = $this->base();
        $kit = $this->kit($base, 2);
        $this->editor()->salvarVariantes($base->rascunho, [ChaveCanonica::UNICA => ['estoque' => 7]]);
        $revisaoDoBase = (int) $base->rascunho->fresh()->revisao;

        // Gravar o KIT: o gancho roda de novo e tem de sair sem consulta nenhuma.
        $consultas = $this->contarConsultas(fn () => $this->recalculo()->propagar($kit->rascunho->fresh()));

        $this->assertSame(0, $consultas, 'kit não pode ser base de ninguém: sai antes de consultar');
        $this->assertSame($revisaoDoBase, (int) $base->rascunho->fresh()->revisao, 'e o base não foi reescrito');
        $this->assertSame(7, (int) $base->rascunho->fresh()->variantes()->value('estoque'));
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

    // ═══ Apoio ═══════════════════════════════════════════════════════════════

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
