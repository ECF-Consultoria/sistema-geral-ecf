<?php

namespace Tests\Feature\PortalCliente\Estrutura\Sugestoes;

use App\Models\Company;
use App\Models\EstruturaAnuncioEspera;
use App\Models\EstruturaOferta;
use App\Models\EstruturaPrecificacao;
use App\Models\User;
use App\Services\Portal\Estrutura\EstruturaConjunto;
use App\Services\Portal\Estrutura\EstruturaOfertaService;
use App\Services\Portal\Estrutura\EstruturaPrecificacaoService;
use App\Services\Portal\Estrutura\Geracao\ChaveDeComposicao;
use App\Services\Portal\Estrutura\Geracao\DecisoesDasSugestoes;
use App\Services\Portal\Estrutura\Geracao\SugestoesService;
use App\Services\Portal\Estrutura\Produtos\ProdutoCadastroService;
use App\Support\Portal\AtorDoPortal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;
use Tests\Concerns\CatalogoSinteticoDeSugestoes;
use Tests\Concerns\GabaritoDaPlanilhaEstrutural;
use Tests\TestCase;

/**
 * Fase 168-11: o aceite seguro. O navegador manda só chave+nome+sku e o servidor
 * regera a sugestão.
 *
 * Modos de falha que estes testes impedem: composição vinda do request virar
 * componente (id de outra empresa), um SKU ruim derrubar o lote inteiro, a
 * espera ser varrida a cada oferta, oferta aceita nascer com logística/preço
 * inventados e o aceite tocar ofertas existentes.
 */
class AceitarSugestaoTest extends TestCase
{
    use CatalogoSinteticoDeSugestoes;
    use GabaritoDaPlanilhaEstrutural;
    use RefreshDatabase;

    /** @return array{0: Company, 1: AtorDoPortal, 2: array} */
    private function cenario(): array
    {
        Http::fake();
        $empresa = $this->empresaDoGabarito();
        $ator    = $this->atorCliente($empresa);

        return [$empresa, $ator, $this->catalogoSintetico($empresa, $ator)];
    }

    private function decisoes(): DecisoesDasSugestoes
    {
        return app(DecisoesDasSugestoes::class);
    }

    /** A sugestão vigente com exatamente esta composição (código da variação => quantidade). */
    private function sugestao(Company $empresa, array $cat, array $porCodigo): array
    {
        $mapa = [];
        foreach ($porCodigo as $codigo => $q) {
            $mapa[$cat['variacoes'][$codigo]] = $q;
        }
        $chave = ChaveDeComposicao::de($mapa);

        foreach (app(SugestoesService::class)->gerar($empresa)['sugestoes'] as $s) {
            if ($s['chave'] === $chave) {
                return $s;
            }
        }
        $this->fail("Sugestão {$chave} não gerada.");
    }

    public function test_aceita_o_combit_com_nome_e_sku_sugeridos(): void
    {
        [$empresa, $ator, $cat] = $this->cenario();
        $s = $this->sugestao($empresa, $cat, ['V101' => 1, 'V201' => 4]);

        $r = $this->decisoes()->aceitar($empresa, [['chave' => $s['chave']]], $ator);

        $this->assertCount(1, $r['criadas']);
        $this->assertSame([], $r['erros']);
        $oferta = EstruturaOferta::findOrFail($r['criadas'][0]['oferta_id']);
        $this->assertSame('combit', $oferta->fase);
        $this->assertSame($s['sku'], $oferta->sku);
        $this->assertSame($s['nome'], $oferta->nome);
        $this->assertStringStartsWith('CT4-', $oferta->sku);
        $componentes = $oferta->componentes()->pluck('quantidade', 'componente_id')->all();
        $this->assertSame([$cat['ofertas']['V101'] => 1, $cat['ofertas']['V201'] => 4], $componentes);
    }

    public function test_nome_e_sku_editados_valem_no_aceite(): void
    {
        [$empresa, $ator, $cat] = $this->cenario();
        $s = $this->sugestao($empresa, $cat, ['V101' => 1, 'V201' => 1]);

        $r = $this->decisoes()->aceitar($empresa, [['chave' => $s['chave'], 'nome' => '  Meu jogo  ', 'sku' => '  MEU-SKU ']], $ator);

        $oferta = EstruturaOferta::findOrFail($r['criadas'][0]['oferta_id']);
        $this->assertSame('MEU-SKU', $oferta->sku);
        $this->assertSame('Meu jogo', $oferta->nome);
        $this->assertSame('kit', $oferta->fase);
    }

    public function test_erro_de_uma_sugestao_nao_impede_as_outras(): void
    {
        [$empresa, $ator, $cat] = $this->cenario();
        $a = $this->sugestao($empresa, $cat, ['V101' => 1, 'V201' => 1]);
        $b = $this->sugestao($empresa, $cat, ['V101' => 1, 'V201' => 2]);
        $c = $this->sugestao($empresa, $cat, ['V101' => 1, 'V201' => 4]);

        $r = $this->decisoes()->aceitar($empresa, [
            ['chave' => $a['chave']],
            ['chave' => $b['chave'], 'sku' => str_repeat('X', 121)],
            ['chave' => $c['chave']],
        ], $ator);

        $this->assertCount(2, $r['criadas']);
        $this->assertCount(1, $r['erros']);
        $this->assertSame($b['chave'], $r['erros'][0]['chave']);
        $this->assertSame('O SKU pode ter no máximo 120 caracteres.', $r['erros'][0]['mensagem']);
        $this->assertTrue(EstruturaOferta::where('company_id', $empresa->id)->where('sku', $a['sku'])->exists());
        $this->assertTrue(EstruturaOferta::where('company_id', $empresa->id)->where('sku', $c['sku'])->exists());
    }

    public function test_lote_acima_do_limite_nao_cria_nada(): void
    {
        [$empresa, $ator] = $this->cenario();
        $antes = EstruturaOferta::where('company_id', $empresa->id)->count();
        $pedidos = array_fill(0, 101, ['chave' => 'v1*1+v2*1']);

        try {
            $this->decisoes()->aceitar($empresa, $pedidos, $ator);
            $this->fail('Deveria recusar o lote.');
        } catch (ValidationException $e) {
            $this->assertSame('Você pode aceitar até 100 de uma vez.', $e->errors()['sugestoes'][0]);
        }

        $this->assertSame($antes, EstruturaOferta::where('company_id', $empresa->id)->count());
    }

    public function test_a_espera_e_varrida_uma_vez_por_lote(): void
    {
        [$empresa, $ator, $cat] = $this->cenario();
        $a = $this->sugestao($empresa, $cat, ['V101' => 1, 'V201' => 1]);
        $b = $this->sugestao($empresa, $cat, ['V101' => 1, 'V201' => 2]);

        EstruturaAnuncioEspera::create([
            'company_id' => $empresa->id, 'sku_colado' => $a['sku'], 'motivo' => EstruturaAnuncioEspera::MOTIVO_SEM_OFERTA,
            'tipo' => 'classico', 'codigo_mlb' => 'MLB123456789', 'titulo' => 'Anúncio', 'status' => 'ativo',
        ]);

        $real = app(EstruturaOfertaService::class);
        $contagem = 0;
        $espiao = $this->partialMock(EstruturaOfertaService::class, function ($mock) use (&$contagem, $real) {
            $mock->shouldReceive('varrerEspera')->andReturnUsing(function (...$args) use (&$contagem, $real) {
                $contagem++;

                return $real->varrerEspera(...$args);
            });
        });
        $this->app->instance(EstruturaOfertaService::class, $espiao);

        $r = app(DecisoesDasSugestoes::class)->aceitar($empresa, [['chave' => $a['chave']], ['chave' => $b['chave']]], $ator);

        $this->assertCount(2, $r['criadas']);
        $this->assertSame(1, $contagem);
        $oferta = EstruturaOferta::where('company_id', $empresa->id)->where('sku', $a['sku'])->firstOrFail();
        $this->assertSame(1, $oferta->anuncios()->count());
        $this->assertSame(0, EstruturaAnuncioEspera::where('company_id', $empresa->id)->count());
    }

    public function test_a_oferta_aceita_aparece_na_lista_e_na_precificacao_com_custo_somado(): void
    {
        [$empresa, $ator, $cat] = $this->cenario();
        $s = $this->sugestao($empresa, $cat, ['V101' => 1, 'V201' => 4]);
        $linhasAntes = EstruturaPrecificacao::count();

        $r = $this->decisoes()->aceitar($empresa, [['chave' => $s['chave']]], $ator);
        $id = $r['criadas'][0]['oferta_id'];

        $this->assertContains($id, array_column(EstruturaConjunto::daEmpresa($empresa)->ofertas(), 'id'));
        $oferta = EstruturaOferta::findOrFail($id);
        $this->assertNull($oferta->logistica);
        $this->assertSame($linhasAntes, EstruturaPrecificacao::count());

        $p = app(EstruturaPrecificacaoService::class)->pagina($empresa, [$id])['por_oferta'][$id];
        $this->assertSame(620.0, (float) $p['custo']['valor']);
    }

    public function test_registra_o_ator_cliente_e_o_interno(): void
    {
        [$empresa, $ator, $cat] = $this->cenario();
        $a = $this->sugestao($empresa, $cat, ['V101' => 1, 'V201' => 1]);
        $b = $this->sugestao($empresa, $cat, ['V101' => 1, 'V201' => 2]);
        $equipe = AtorDoPortal::daEquipe(User::factory()->create(['role' => 'admin', 'active' => true]));
        Activity::query()->delete(); // só o rastro do aceite, sem o do catálogo da fixture

        $this->decisoes()->aceitar($empresa, [['chave' => $a['chave']]], $ator);
        $this->decisoes()->aceitar($empresa, [['chave' => $b['chave']]], $equipe);

        $eventos = Activity::where('log_name', 'portal')->get()->groupBy(fn ($l) => $l->properties['evento']);
        $this->assertCount(2, $eventos['oferta_criada']);
        $this->assertCount(2, $eventos['sugestoes_aceitas']);
        $this->assertSame(['cliente', 'interno'], $eventos['sugestoes_aceitas']->map(fn ($l) => $l->properties['origem'])->all());
    }

    public function test_lote_vazio_nao_cria_nada_e_deixa_rastro(): void
    {
        [$empresa, $ator] = $this->cenario();
        $antes = EstruturaOferta::where('company_id', $empresa->id)->count();

        $r = $this->decisoes()->aceitar($empresa, [], $ator);

        $this->assertSame(['criadas' => [], 'ja_existiam' => [], 'erros' => []], $r);
        $this->assertSame($antes, EstruturaOferta::where('company_id', $empresa->id)->count());
        $this->assertTrue(Activity::where('log_name', 'portal')->get()->contains(fn ($l) => $l->properties['evento'] === 'sugestoes_aceitas'));
    }

    public function test_ofertas_existentes_nao_mudam(): void
    {
        [$empresa, $ator, $cat] = $this->cenario();
        $s = $this->sugestao($empresa, $cat, ['V101' => 1, 'V201' => 4]);
        $ler = fn () => EstruturaOferta::where('company_id', $empresa->id)->orderBy('id')->get()
            ->map(fn ($o) => [$o->id, $o->sku, $o->nome, (string) $o->updated_at, $o->componentes()->pluck('quantidade', 'componente_id')->all()])->all();
        $antes = $ler();

        $this->decisoes()->aceitar($empresa, [['chave' => $s['chave']]], $ator);

        $depois = array_slice($ler(), 0, count($antes));
        $this->assertSame($antes, $depois);
    }

    public function test_variacao_usada_em_oferta_aceita_nao_pode_ser_excluida(): void
    {
        [$empresa, $ator, $cat] = $this->cenario();
        $s = $this->sugestao($empresa, $cat, ['V101' => 1, 'V201' => 1]);
        $this->decisoes()->aceitar($empresa, [['chave' => $s['chave']]], $ator);

        try {
            app(ProdutoCadastroService::class)->excluirVariacao($empresa, $cat['variacoes']['V201'], $ator);
            $this->fail('Deveria recusar a exclusão.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString($s['sku'], collect($e->errors())->flatten()->implode(' '));
        }
    }
}
