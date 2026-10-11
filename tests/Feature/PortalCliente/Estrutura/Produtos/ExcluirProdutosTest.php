<?php

namespace Tests\Feature\PortalCliente\Estrutura\Produtos;

use App\Models\Company;
use App\Models\EstruturaAnuncio;
use App\Models\EstruturaOferta;
use App\Models\EstruturaProduto;
use App\Models\EstruturaProdutoVariacao;
use App\Models\EstruturaProdutoVolume;
use App\Models\PubProduto;
use App\Services\Portal\Estrutura\EstruturaOfertaService;
use App\Services\Portal\Estrutura\Produtos\ExclusaoDeProdutos;
use App\Services\Portal\Estrutura\Produtos\ProdutoCadastroService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;
use Tests\Concerns\GabaritoDaPlanilhaEstrutural;
use Tests\TestCase;

/**
 * Excluir produtos inteiros do Produtos, um ou vários (pedido do usuário, 10/10/2026: "tem muita coisa de
 * teste lá e eu preciso excluir"). As ofertas montadas (combo, kit, combit) que usam o produto saem JUNTO —
 * decisão dele —, mas só as que a pessoa viu na prévia: apareceu outra no meio do caminho, recusa. O item
 * que a equipe já tinha do outro lado continua lá, solto (D27).
 */
class ExcluirProdutosTest extends TestCase
{
    use GabaritoDaPlanilhaEstrutural;
    use RefreshDatabase;

    private function svc(): ExclusaoDeProdutos
    {
        return app(ExclusaoDeProdutos::class);
    }

    /**
     * Mesa (1 variação), Cadeira (2 cores) e Banco (1 variação), com um combo da cadeira natural, um kit
     * mesa + cadeira natural e um combo do banco.
     *
     * @return array{mesa: int, cadeira: int, banco: int, ofertas: array<string, int>}
     */
    private function cenario(Company $empresa): array
    {
        $ator = $this->atorCliente($empresa);
        $volume = [['c' => 80, 'l' => 50, 'a' => 10, 'kg' => 8]];
        app(ProdutoCadastroService::class)->gravarLinhas($empresa, [
            ['grupo' => 'MESA', 'codigo' => 'MESA-1', 'nome' => 'Mesa Polo', 'valor' => 'Natural', 'eixo' => 'cor', 'volumes' => $volume],
            ['grupo' => 'CAD', 'codigo' => 'CAD-NT', 'nome' => 'Cadeira Polo', 'valor' => 'Natural', 'eixo' => 'cor', 'volumes' => $volume],
            ['grupo' => 'CAD', 'codigo' => 'CAD-PT', 'nome' => 'Cadeira Polo', 'valor' => 'Preto', 'eixo' => 'cor', 'volumes' => $volume],
            ['grupo' => 'BANCO', 'codigo' => 'BANCO-1', 'nome' => 'Banco Polo', 'valor' => 'Natural', 'eixo' => 'cor', 'volumes' => $volume],
        ], $ator);

        $oferta = fn (string $sku) => (int) EstruturaOferta::where('company_id', $empresa->id)->where('sku', $sku)->value('id');
        $ofertas = app(EstruturaOfertaService::class);
        $ofertas->criar($empresa, ['sku' => 'CAD-NT-CB2', 'fase' => 'combo', 'componentes' => [['id' => $oferta('CAD-NT'), 'quantidade' => 2]]], $ator);
        $ofertas->criar($empresa, ['sku' => 'KT-MESA-CAD', 'fase' => 'kit', 'componentes' => [['id' => $oferta('MESA-1'), 'quantidade' => 1], ['id' => $oferta('CAD-NT'), 'quantidade' => 1]]], $ator);
        $ofertas->criar($empresa, ['sku' => 'BANCO-1-CB2', 'fase' => 'combo', 'componentes' => [['id' => $oferta('BANCO-1'), 'quantidade' => 2]]], $ator);

        $produto = fn (string $nome) => (int) EstruturaProduto::where('company_id', $empresa->id)->where('nome', $nome)->value('id');

        return [
            'mesa' => $produto('Mesa Polo'), 'cadeira' => $produto('Cadeira Polo'), 'banco' => $produto('Banco Polo'),
            'ofertas' => ['CAD-NT-CB2' => $oferta('CAD-NT-CB2'), 'KT-MESA-CAD' => $oferta('KT-MESA-CAD'), 'BANCO-1-CB2' => $oferta('BANCO-1-CB2')],
        ];
    }

    private function skus(Company $empresa): array
    {
        return EstruturaOferta::where('company_id', $empresa->id)->orderBy('sku')->pluck('sku')->all();
    }

    // ═══ Prévia ═════════════════════════════════════════════════════════════

    public function test_a_previa_diz_o_que_sai_junto_sem_tocar_em_nada(): void
    {
        $empresa = $this->empresaDoGabarito();
        $c = $this->cenario($empresa);

        $p = $this->svc()->previa($empresa, [$c['cadeira'], 999999]);

        $this->assertSame([['id' => $c['cadeira'], 'nome' => 'Cadeira Polo', 'codigo' => 'CAD', 'variacoes' => 2, 'em_uso' => false]], $p['produtos']);
        $this->assertSame(['CAD-NT-CB2', 'KT-MESA-CAD'], array_column($p['montadas'], 'sku'), 'o combo da cadeira e o kit que a usa; o combo do banco não');
        $this->assertSame(['combo', 'kit'], array_column($p['montadas'], 'fase'));
        $this->assertSame(['produtos' => 1, 'variacoes' => 2, 'montadas' => 2, 'em_uso' => 0], $p['totais']);
        $this->assertSame(1, $p['nao_encontrados'], 'o id que não existe só é contado');
        $this->assertSame(3, EstruturaProduto::count());
        $this->assertSame(7, EstruturaOferta::count());
    }

    public function test_a_previa_marca_o_que_a_equipe_ja_usa(): void
    {
        $empresa = $this->empresaDoGabarito();
        $c = $this->cenario($empresa);
        $ofertaMesa = EstruturaOferta::where('sku', 'MESA-1')->firstOrFail();
        EstruturaAnuncio::create(['oferta_id' => $ofertaMesa->id, 'tipo' => 'classico', 'status' => 'ativo', 'codigo_mlb' => 'MLB1', 'titulo' => 'x']);
        PubProduto::create(['company_id' => $empresa->id, 'estrutura_produto_id' => $c['banco'], 'sku' => 'BANCO-1', 'nome' => 'Banco Polo', 'origem' => PubProduto::ORIGEM_PORTAL]);
        PubProduto::create(['company_id' => $empresa->id, 'oferta_id' => $c['ofertas']['KT-MESA-CAD'], 'sku' => 'KT-MESA-CAD', 'nome' => 'Kit', 'origem' => PubProduto::ORIGEM_PORTAL]);

        $p = $this->svc()->previa($empresa, [$c['mesa'], $c['cadeira'], $c['banco']]);

        $this->assertSame(['Mesa Polo' => true, 'Cadeira Polo' => false, 'Banco Polo' => true], array_column($p['produtos'], 'em_uso', 'nome'));
        $this->assertSame(['CAD-NT-CB2' => false, 'KT-MESA-CAD' => true, 'BANCO-1-CB2' => false], array_column($p['montadas'], 'em_uso', 'sku'));
        $this->assertSame(3, $p['totais']['em_uso']);
        $this->assertSame(0, $p['nao_encontrados']);
    }

    // ═══ Exclusão ═══════════════════════════════════════════════════════════

    public function test_excluir_um_produto_leva_variacoes_ofertas_e_as_montadas_confirmadas(): void
    {
        $empresa = $this->empresaDoGabarito();
        $c = $this->cenario($empresa);
        $variacoesDaCadeira = EstruturaProdutoVariacao::where('produto_id', $c['cadeira'])->pluck('id')->all();

        $r = $this->svc()->excluir($empresa, [$c['cadeira']], [$c['ofertas']['CAD-NT-CB2'], $c['ofertas']['KT-MESA-CAD']], $this->atorCliente($empresa));

        $this->assertSame(['produtos' => 1, 'variacoes' => 2, 'montadas' => 2, 'ids' => [$c['cadeira']], 'nao_encontrados' => 0], $r);
        $this->assertNull(EstruturaProduto::find($c['cadeira']));
        $this->assertSame(0, EstruturaProdutoVariacao::whereIn('id', $variacoesDaCadeira)->count());
        $this->assertSame(0, EstruturaProdutoVolume::whereIn('variacao_id', $variacoesDaCadeira)->count());
        // Ficam a mesa, o banco e o combo do banco: o kit que usava a cadeira saiu, a mesa não.
        $this->assertSame(['BANCO-1', 'BANCO-1-CB2', 'MESA-1'], $this->skus($empresa));
        $this->assertNotNull(EstruturaProduto::find($c['mesa']));
        $this->assertNotNull(EstruturaProduto::find($c['banco']));
    }

    public function test_montada_que_a_pessoa_nao_viu_recusa_e_nada_sai(): void
    {
        $empresa = $this->empresaDoGabarito();
        $c = $this->cenario($empresa);

        foreach ([[], [$c['ofertas']['CAD-NT-CB2']]] as $confirmadas) {
            try {
                $this->svc()->excluir($empresa, [$c['cadeira']], $confirmadas, $this->atorCliente($empresa));
                $this->fail('Deveria recusar');
            } catch (ValidationException $e) {
                $this->assertSame('O que sai junto com estes produtos mudou. Confira de novo antes de excluir.', $e->errors()['montadas'][0]);
            }
        }

        $this->assertSame(3, EstruturaProduto::count());
        $this->assertSame(7, EstruturaOferta::count());
        $this->assertSame(4, EstruturaProdutoVariacao::count());
    }

    public function test_varios_de_uma_vez_e_o_kit_dividido_sai_uma_vez_so(): void
    {
        $empresa = $this->empresaDoGabarito();
        $c = $this->cenario($empresa);

        // Confirmar a mais não atrapalha: só sai o que de fato usa os produtos.
        $r = $this->svc()->excluir($empresa, [$c['mesa'], $c['cadeira'], $c['mesa']], array_values($c['ofertas']), $this->atorCliente($empresa));

        $this->assertSame(2, $r['produtos']);
        $this->assertSame(3, $r['variacoes']);
        $this->assertSame(2, $r['montadas']);
        $this->assertSame(['BANCO-1', 'BANCO-1-CB2'], $this->skus($empresa), 'o combo do banco foi confirmado mas não usa os excluídos: fica');
        $this->assertSame([$c['banco']], EstruturaProduto::pluck('id')->all());
    }

    public function test_o_item_que_a_equipe_ja_tinha_fica_solto_e_nao_some(): void
    {
        $empresa = $this->empresaDoGabarito();
        $c = $this->cenario($empresa);
        $ofertaBanco = EstruturaOferta::where('sku', 'BANCO-1')->firstOrFail();
        $doProduto = PubProduto::create(['company_id' => $empresa->id, 'oferta_id' => $ofertaBanco->id, 'sku' => 'BANCO-1', 'nome' => 'Banco Polo — Natural', 'origem' => PubProduto::ORIGEM_PORTAL]);
        $doCombo = PubProduto::create(['company_id' => $empresa->id, 'oferta_id' => $c['ofertas']['BANCO-1-CB2'], 'sku' => 'BANCO-1-CB2', 'nome' => 'Combo', 'origem' => PubProduto::ORIGEM_PORTAL]);

        $this->svc()->excluir($empresa, [$c['banco']], [$c['ofertas']['BANCO-1-CB2']], $this->atorCliente($empresa));

        foreach ([$doProduto, $doCombo] as $pub) {
            $pub = $pub->fresh();
            $this->assertNotNull($pub, 'o item da equipe não é apagado');
            $this->assertNull($pub->oferta_id, 'fica solto');
        }
        $this->assertSame('BANCO-1', $doProduto->fresh()->sku);
    }

    public function test_produto_de_outra_empresa_conta_como_inexistente(): void
    {
        $minha = $this->empresaDoGabarito();
        $outra = $this->empresaDoGabarito();
        $meus = $this->cenario($minha);
        $alheios = $this->cenario($outra);

        $this->assertSame(1, $this->svc()->previa($minha, [$alheios['banco']])['nao_encontrados']);
        try {
            $this->svc()->excluir($minha, [$alheios['banco'], $alheios['mesa']], array_values($alheios['ofertas']), $this->atorCliente($minha));
            $this->fail('Deveria dar 404');
        } catch (ModelNotFoundException) {
            // esperado
        }
        $this->assertSame(3, EstruturaProduto::where('company_id', $outra->id)->count());

        // Misturado: sai só o meu.
        $r = $this->svc()->excluir($minha, [$meus['banco'], $alheios['banco']], [$meus['ofertas']['BANCO-1-CB2']], $this->atorCliente($minha));
        $this->assertSame([$meus['banco']], $r['ids']);
        $this->assertSame(1, $r['nao_encontrados']);
        $this->assertSame(3, EstruturaProduto::where('company_id', $outra->id)->count());
        $this->assertSame(7, EstruturaOferta::where('company_id', $outra->id)->count());
    }

    public function test_o_registro_guarda_quem_excluiu_e_o_que_saiu(): void
    {
        $empresa = $this->empresaDoGabarito();
        $c = $this->cenario($empresa);

        $this->svc()->excluir($empresa, [$c['banco']], [$c['ofertas']['BANCO-1-CB2']], $this->atorCliente($empresa));

        $log = Activity::where('log_name', 'portal')->get()->first(fn ($a) => $a->getExtraProperty('evento') === 'produtos_excluidos');
        $this->assertNotNull($log);
        $this->assertSame('cliente', $log->getExtraProperty('origem'));
        $this->assertSame([$c['banco']], $log->getExtraProperty('produto_ids'));
        $this->assertSame(['BANCO-1-CB2'], $log->getExtraProperty('montadas'));
        $eventos = Activity::where('log_name', 'portal')->get()->map(fn ($a) => $a->getExtraProperty('evento'));
        $this->assertTrue($eventos->contains('variacao_excluida') && $eventos->contains('oferta_excluida') && $eventos->contains('produto_excluido'));
    }

    // ═══ Pelo Portal ════════════════════════════════════════════════════════

    public function test_pelo_portal_previa_e_exclusao_com_a_mensagem_que_o_cliente_le(): void
    {
        $empresa = $this->empresaDoGabarito();
        $c = $this->cenario($empresa);
        $sessao = $this->entrarNoPortal($empresa);

        $previa = $sessao->postJson(route('portal.auth.estrutura.produtos.exclusao.previa'), ['produtos' => [$c['cadeira'], $c['banco']]])
            ->assertOk()->assertJsonPath('totais.produtos', 2)->assertJsonPath('totais.montadas', 3)->json();

        // Sem confirmar as montadas: 422, com a mensagem no campo.
        $sessao->postJson(route('portal.auth.estrutura.produtos.exclusao'), ['produtos' => [$c['cadeira'], $c['banco']]])
            ->assertUnprocessable()->assertJsonPath('errors.montadas.0', 'O que sai junto com estes produtos mudou. Confira de novo antes de excluir.');
        $this->assertSame(3, EstruturaProduto::count());

        $r = $sessao->postJson(route('portal.auth.estrutura.produtos.exclusao'), [
            'produtos' => [$c['cadeira'], $c['banco']], 'montadas' => array_column($previa['montadas'], 'id'),
        ])->assertOk()->json();

        $this->assertSame('2 produtos excluídos. 3 ofertas montadas saíram junto.', $r['mensagem']);
        $this->assertSame(['MESA-1'], $this->skus($empresa));

        // O que o cliente lê não fala da plataforma (sigilo do Portal).
        $textos = json_encode([$previa, $r], JSON_UNESCAPED_UNICODE);
        $this->assertDoesNotMatchRegularExpression('/mercado|an[uú]ncio|publicad|\bmlb?\b/iu', $textos);

        $sessao->postJson(route('portal.auth.estrutura.produtos.exclusao'), ['produtos' => [$c['mesa']], 'montadas' => []])
            ->assertOk()->assertJsonPath('mensagem', '1 produto excluído.');
        $this->assertSame(0, EstruturaProduto::count());
    }

    public function test_pelo_portal_valida_a_lista_e_nao_acha_o_que_nao_e_da_empresa(): void
    {
        $empresa = $this->empresaDoGabarito();
        $outra = $this->empresaDoGabarito();
        $alheios = $this->cenario($outra);
        $sessao = $this->entrarNoPortal($empresa);

        foreach (['portal.auth.estrutura.produtos.exclusao.previa', 'portal.auth.estrutura.produtos.exclusao'] as $rota) {
            $sessao->postJson(route($rota), [])->assertUnprocessable()->assertJsonPath('errors.produtos.0', 'Escolha ao menos um produto.');
            $sessao->postJson(route($rota), ['produtos' => range(1, ExclusaoDeProdutos::MAXIMO + 1)])
                ->assertUnprocessable()->assertJsonPath('errors.produtos.0', 'Dá para excluir até 200 produtos de uma vez.');
            $sessao->postJson(route($rota), ['produtos' => ['abc']])->assertUnprocessable();
        }

        $sessao->postJson(route('portal.auth.estrutura.produtos.exclusao.previa'), ['produtos' => [$alheios['mesa']]])
            ->assertOk()->assertJsonPath('totais.produtos', 0)->assertJsonPath('nao_encontrados', 1);
        $sessao->postJson(route('portal.auth.estrutura.produtos.exclusao'), ['produtos' => [$alheios['mesa']], 'montadas' => array_values($alheios['ofertas'])])
            ->assertNotFound();
        $this->assertSame(3, EstruturaProduto::where('company_id', $outra->id)->count());

        // Sem sessão do Portal, nada.
        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $this->postJson(route('portal.auth.estrutura.produtos.exclusao'), ['produtos' => [$alheios['mesa']]])->assertStatus(401);
    }
}
