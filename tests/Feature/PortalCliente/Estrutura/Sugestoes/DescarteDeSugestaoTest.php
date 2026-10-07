<?php

namespace Tests\Feature\PortalCliente\Estrutura\Sugestoes;

use App\Models\Company;
use App\Models\EstruturaSugestaoDescartada;
use App\Services\Portal\Estrutura\Geracao\ChaveDeComposicao;
use App\Services\Portal\Estrutura\Geracao\DecisoesDasSugestoes;
use App\Services\Portal\Estrutura\Geracao\SugestoesService;
use App\Support\Portal\AtorDoPortal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Spatie\Activitylog\Models\Activity;
use Tests\Concerns\CatalogoSinteticoDeSugestoes;
use Tests\Concerns\GabaritoDaPlanilhaEstrutural;
use Tests\TestCase;

/**
 * Fase 168-11 (D-01): descartar persiste por empresa + chave, a sugestão não
 * volta e restaurar devolve.
 *
 * Modos de falha impedidos: descarte de uma empresa esconder sugestão de outra,
 * restaurar apagar a linha alheia, gravar lixo vindo do navegador e descarte
 * duplicado estourar o unique.
 */
class DescarteDeSugestaoTest extends TestCase
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

    private function sugestoes(Company $empresa): array
    {
        $porChave = [];
        foreach (app(SugestoesService::class)->gerar($empresa)['sugestoes'] as $s) {
            $porChave[$s['chave']] = $s;
        }

        return $porChave;
    }

    private function decisoes(): DecisoesDasSugestoes
    {
        return app(DecisoesDasSugestoes::class);
    }

    public function test_descartar_grava_por_empresa_e_a_sugestao_vem_marcada(): void
    {
        [$empresa, $ator, $cat] = $this->cenario();
        $a = $this->chave($cat, ['V201' => 2]);
        $b = $this->chave($cat, ['V201' => 4]);

        $r = $this->decisoes()->descartar($empresa, [$a, $b], $ator);

        $this->assertSame(['descartadas' => 2], $r);
        $linhas = EstruturaSugestaoDescartada::where('company_id', $empresa->id)->orderBy('chave')->get();
        $this->assertSame([$a, $b], $linhas->pluck('chave')->all());
        $this->assertSame(['combo', 'combo'], $linhas->pluck('fase')->all());

        $geradas = $this->sugestoes($empresa);
        $this->assertTrue($geradas[$a]['descartada']);
        $this->assertTrue($geradas[$b]['descartada']);
    }

    public function test_descartar_de_novo_e_idempotente(): void
    {
        [$empresa, $ator, $cat] = $this->cenario();
        $a = $this->chave($cat, ['V201' => 2]);

        $this->decisoes()->descartar($empresa, [$a], $ator);
        $this->decisoes()->descartar($empresa, [$a, $a], $ator);

        $this->assertSame(1, EstruturaSugestaoDescartada::where('company_id', $empresa->id)->count());
    }

    public function test_chave_que_nao_e_sugestao_e_ignorada(): void
    {
        [$empresa, $ator] = $this->cenario();

        $r = $this->decisoes()->descartar($empresa, ['v999999*2', 'lixo', ''], $ator);

        $this->assertSame(['descartadas' => 0], $r);
        $this->assertSame(0, EstruturaSugestaoDescartada::count());
    }

    public function test_restaurar_devolve_a_sugestao_como_vigente(): void
    {
        [$empresa, $ator, $cat] = $this->cenario();
        $a = $this->chave($cat, ['V201' => 2]);
        $this->decisoes()->descartar($empresa, [$a], $ator);

        $r = $this->decisoes()->restaurar($empresa, [$a], $ator);

        $this->assertSame(['restauradas' => 1, 'ja_existem' => 0], $r);
        $this->assertSame(0, EstruturaSugestaoDescartada::where('company_id', $empresa->id)->count());
        $this->assertFalse($this->sugestoes($empresa)[$a]['descartada']);
    }

    public function test_restaurar_chave_que_virou_oferta_conta_como_ja_existe(): void
    {
        [$empresa, $ator, $cat] = $this->cenario();
        $a = $this->chave($cat, ['V201' => 2]);
        $this->decisoes()->descartar($empresa, [$a], $ator);
        // A pessoa criou o combo por outro caminho depois de descartar.
        $oferta = $cat['ofertas']['V201'];
        app(\App\Services\Portal\Estrutura\EstruturaOfertaService::class)->criar($empresa, [
            'sku' => 'COMBO-MAO', 'fase' => 'combo', 'nome' => 'Combo à mão',
            'componentes' => [['id' => $oferta, 'quantidade' => 2]],
        ], $ator);

        $r = $this->decisoes()->restaurar($empresa, [$a], $ator);

        $this->assertSame(1, $r['ja_existem']);
    }

    public function test_o_descarte_de_uma_empresa_nao_afeta_outra(): void
    {
        [$empresa, $ator, $cat] = $this->cenario();
        $outra = $this->empresaDoGabarito();
        $catOutra = $this->catalogoSintetico($outra, $this->atorCliente($outra));
        $atorOutra = $this->atorCliente($outra);
        $a = $this->chave($cat, ['V201' => 2]);
        $aOutra = $this->chave($catOutra, ['V201' => 2]);

        $this->decisoes()->descartar($empresa, [$a], $ator);

        $this->assertFalse($this->sugestoes($outra)[$aOutra]['descartada']);

        // Restaurar na outra, mesmo com a chave da primeira, não apaga a linha da primeira.
        $this->decisoes()->restaurar($outra, [$a], $atorOutra);
        $this->assertSame(1, EstruturaSugestaoDescartada::where('company_id', $empresa->id)->count());
    }

    public function test_deixa_rastro_com_a_origem(): void
    {
        [$empresa, $ator, $cat] = $this->cenario();
        $a = $this->chave($cat, ['V201' => 2]);
        Activity::query()->delete();

        $this->decisoes()->descartar($empresa, [$a], $ator);
        $this->decisoes()->restaurar($empresa, [$a], $ator);

        $eventos = Activity::where('log_name', 'portal')->get()->keyBy(fn ($l) => $l->properties['evento']);
        $this->assertSame('cliente', $eventos['sugestoes_descartadas']->properties['origem']);
        $this->assertSame('cliente', $eventos['sugestoes_restauradas']->properties['origem']);
    }
}
