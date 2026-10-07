<?php

namespace Tests\Feature\PortalCliente\Estrutura\Sugestoes;

use App\Models\Company;
use App\Models\EstruturaOferta;
use App\Models\EstruturaSugestaoDescartada;
use App\Services\Portal\Estrutura\EstruturaOfertaService;
use App\Services\Portal\Estrutura\Geracao\ChaveDeComposicao;
use App\Services\Portal\Estrutura\Geracao\DecisoesDasSugestoes;
use App\Services\Portal\Estrutura\Geracao\SugestoesService;
use App\Support\Portal\AtorDoPortal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\CatalogoSinteticoDeSugestoes;
use Tests\Concerns\GabaritoDaPlanilhaEstrutural;
use Tests\TestCase;

/**
 * Fase 168-11 (D-03): nada duplicado, nem por duplo clique, nem por oferta criada
 * à mão entre gerar e aceitar, nem por chave de outra empresa.
 *
 * Modo de falha impedido: a mesma composição virar duas ofertas, ou uma chave
 * forjada puxar componente de outra empresa.
 */
class NadaDuplicadoTest extends TestCase
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

    private function chave(array $cat, array $porCodigo): string
    {
        $mapa = [];
        foreach ($porCodigo as $codigo => $q) {
            $mapa[$cat['variacoes'][$codigo]] = $q;
        }

        return ChaveDeComposicao::de($mapa);
    }

    private function total(Company $empresa): int
    {
        return EstruturaOferta::where('company_id', $empresa->id)->count();
    }

    public function test_aceitar_duas_vezes_seguidas_cria_uma_oferta(): void
    {
        [$empresa, $ator, $cat] = $this->cenario();
        $chave = $this->chave($cat, ['V101' => 1, 'V201' => 4]);
        $antes = $this->total($empresa);

        $a = app(DecisoesDasSugestoes::class)->aceitar($empresa, [['chave' => $chave]], $ator);
        $b = app(DecisoesDasSugestoes::class)->aceitar($empresa, [['chave' => $chave]], $ator);

        $this->assertCount(1, $a['criadas']);
        $this->assertSame([], $b['criadas']);
        $this->assertSame([$chave], $b['ja_existiam']);
        $this->assertSame($antes + 1, $this->total($empresa));
    }

    public function test_a_mesma_chave_duas_vezes_no_mesmo_lote_cria_uma(): void
    {
        [$empresa, $ator, $cat] = $this->cenario();
        $chave = $this->chave($cat, ['V101' => 1, 'V201' => 2]);
        $antes = $this->total($empresa);

        $r = app(DecisoesDasSugestoes::class)->aceitar($empresa, [['chave' => $chave], ['chave' => $chave]], $ator);

        $this->assertCount(1, $r['criadas']);
        $this->assertSame([$chave], $r['ja_existiam']);
        $this->assertSame($antes + 1, $this->total($empresa));
    }

    public function test_kit_criado_a_mao_entre_gerar_e_aceitar_nao_duplica(): void
    {
        [$empresa, $ator, $cat] = $this->cenario();
        $chave = $this->chave($cat, ['V101' => 1, 'V201' => 1]);
        $sugestao = collect(app(SugestoesService::class)->gerar($empresa)['sugestoes'])->firstWhere('chave', $chave);
        $this->assertNotNull($sugestao);

        app(EstruturaOfertaService::class)->criar($empresa, [
            'sku' => 'KIT-MAO', 'fase' => 'kit', 'nome' => 'Kit à mão',
            'componentes' => [['id' => $cat['ofertas']['V101'], 'quantidade' => 1], ['id' => $cat['ofertas']['V201'], 'quantidade' => 1]],
        ], $ator);
        $antes = $this->total($empresa);

        $r = app(DecisoesDasSugestoes::class)->aceitar($empresa, [['chave' => $chave]], $ator);

        $this->assertSame([], $r['criadas']);
        $this->assertSame([$chave], $r['ja_existiam']);
        $this->assertSame($antes, $this->total($empresa));
    }

    public function test_a_chave_aceita_nao_volta_na_geracao(): void
    {
        [$empresa, $ator, $cat] = $this->cenario();
        $chave = $this->chave($cat, ['V101' => 1, 'V201' => 4]);

        app(DecisoesDasSugestoes::class)->aceitar($empresa, [['chave' => $chave]], $ator);

        $chaves = array_column(app(SugestoesService::class)->gerar($empresa)['sugestoes'], 'chave');
        $this->assertNotContains($chave, $chaves);
    }

    public function test_chave_inexistente_mal_formada_ou_de_outra_empresa_nao_cria_nada(): void
    {
        [$empresa, $ator, $cat] = $this->cenario();

        $outra = $this->empresaDoGabarito();
        $atorOutra = $this->atorCliente($outra);
        $catOutra = $this->catalogoSintetico($outra, $atorOutra);
        $chaveDeOutra = $this->chave($catOutra, ['V101' => 1, 'V201' => 4]);
        $this->assertNotSame($cat['variacoes']['V101'], $catOutra['variacoes']['V101']);

        $antesA = $this->total($empresa);
        $antesB = $this->total($outra);

        $r = app(DecisoesDasSugestoes::class)->aceitar($empresa, [
            ['chave' => 'v999999*1+v999998*2'],
            ['chave' => 'lixo'],
            ['chave' => ''],
            ['chave' => $chaveDeOutra],
        ], $ator);

        $this->assertSame([], $r['criadas']);
        $this->assertCount(4, $r['ja_existiam']);
        $this->assertSame($antesA, $this->total($empresa));
        $this->assertSame($antesB, $this->total($outra));
    }

    public function test_chave_descartada_nao_e_aceita(): void
    {
        [$empresa, $ator, $cat] = $this->cenario();
        $chave = $this->chave($cat, ['V101' => 1, 'V201' => 4]);
        EstruturaSugestaoDescartada::create(['company_id' => $empresa->id, 'chave' => $chave, 'fase' => 'combit']);
        $antes = $this->total($empresa);

        $r = app(DecisoesDasSugestoes::class)->aceitar($empresa, [['chave' => $chave]], $ator);

        $this->assertSame([], $r['criadas']);
        $this->assertSame([$chave], $r['ja_existiam']);
        $this->assertSame($antes, $this->total($empresa));
    }
}
