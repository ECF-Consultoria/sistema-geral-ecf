<?php

namespace Tests\Feature\PortalCliente\Estrutura;

use App\Models\EstruturaAnuncio;
use App\Services\Portal\Estrutura\ColagemAnunciosService;
use App\Services\Portal\Estrutura\EstruturaAnuncioService;
use App\Services\Portal\Estrutura\EstruturaConjunto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\GabaritoDaPlanilhaEstrutural;
use Tests\TestCase;

/**
 * O exemplo preenchido da planilha é o gabarito da régua.
 *
 * Os números abaixo foram lidos da própria planilha (`data_only=True` do
 * openpyxl) e conferidos contra uma reconta independente em Python:
 * K5=9, K6..K9=2/5/1/1, K11=18, K12=4, K13=14, K14=1, K15=0,2222…
 * K10 ("Kit virtual" = 0) não existe no sistema de propósito: era contagem de
 * FASE, e kit virtual deixou de ser fase (ADR PORTAL-01 §Kit virtual).
 *
 * Se algum destes falhar, a régua está errada — não o gabarito.
 */
class ReguaDoGabaritoTest extends TestCase
{
    use GabaritoDaPlanilhaEstrutural;
    use RefreshDatabase;

    public function test_o_painel_reproduz_os_numeros_da_planilha(): void
    {
        $empresa = $this->empresaDoGabarito();
        $ator = $this->atorCliente($empresa);
        $this->anunciosDoGabarito($this->listaDoGabarito($empresa, $ator), $ator);

        $painel = EstruturaConjunto::daEmpresa($empresa)->painel();

        $this->assertSame(9, $painel['ofertas']);                  // K5
        $this->assertSame(['simples' => 2, 'combo' => 5, 'kit' => 1, 'combit' => 1], $painel['por_fase']); // K6..K9
        $this->assertSame(18, $painel['necessarios']);             // K11
        $this->assertSame(4, $painel['publicados']);               // K12
        $this->assertSame(14, $painel['a_publicar']);              // K13
        $this->assertSame(1, $painel['completas']);                // K14
        $this->assertEqualsWithDelta(0.2222222222, $painel['percentual'], 1e-9); // K15
    }

    /** As colunas E, F, G e H do Mapeamento, linha a linha, e a coluna E da Lista (unidades). */
    public function test_cada_linha_reproduz_o_mapeamento_e_as_unidades_da_planilha(): void
    {
        $empresa = $this->empresaDoGabarito();
        $ator = $this->atorCliente($empresa);
        $this->anunciosDoGabarito($this->listaDoGabarito($empresa, $ator), $ator);

        $linhas = array_map(fn ($o) => [
            $o['sku'], $o['unidades'], $o['classicos'], $o['premiums'], $o['catalogos'], $o['situacao'],
        ], EstruturaConjunto::daEmpresa($empresa)->ofertas());

        // A ordem também é a da planilha: produto, os combos dele, o próximo
        // produto, e por fim kit e combit.
        $this->assertSame([
            ['CAD-01',             1, 1, 1, 0, 'ok'],
            ['CAD-01-CB2',         2, 1, 0, 0, 'falta_premium'],
            ['CAD-01-CB3',         3, 0, 0, 0, 'publicar'],
            ['CAD-01-CB4',         4, 0, 0, 0, 'publicar'],
            ['CAD-01-CB5',         5, 0, 0, 0, 'publicar'],
            ['CAD-01-CB6',         6, 0, 0, 0, 'publicar'],
            ['MSA-MR',             1, 0, 1, 1, 'falta_classico'],
            ['MSA-MR+CAD-01-KIT',  2, 0, 0, 0, 'publicar'],
            ['MSA-MR+CAD-01-CBT4', 5, 0, 0, 0, 'publicar'],
        ], $linhas);
    }

    /**
     * A contradição do exemplo deixa de existir: na planilha, a CB3 estava
     * OK/OK na agenda de 23/09 e "Publicar" no Mapeamento. Aqui, concluir pela
     * agenda É cadastrar o anúncio — e o painel anda.
     */
    public function test_concluir_a_cb3_pela_agenda_move_o_painel(): void
    {
        $empresa = $this->empresaDoGabarito();
        $ator = $this->atorCliente($empresa);
        $ofertas = $this->listaDoGabarito($empresa, $ator);
        $this->anunciosDoGabarito($ofertas, $ator);

        $cb3 = $ofertas['CAD-01-CB3'];
        foreach (['classico' => 'MLB0000000005', 'premium' => 'MLB0000000006'] as $tipo => $mlb) {
            $this->entrarNoPortal($empresa)
                ->post(route('portal.auth.estrutura.anuncios.criar', $cb3->id), [
                    'tipo' => $tipo, 'codigo_mlb' => $mlb, 'via_agenda' => true,
                ])
                ->assertSessionHasNoErrors();
        }

        $painel = EstruturaConjunto::daEmpresa($empresa)->painel();

        $this->assertSame(6, $painel['publicados']);
        $this->assertSame(12, $painel['a_publicar']);
        $this->assertSame(2, $painel['completas']);
        $this->assertEqualsWithDelta(1 / 3, $painel['percentual'], 1e-9);
    }

    /**
     * Colar a aba "Anúncios" da própria planilha — com o cabeçalho dela — dá o
     * MESMO painel que cadastrar um a um. É o passo 2 da aula, ao pé da letra.
     */
    public function test_colar_a_aba_anuncios_da_planilha_da_o_mesmo_painel(): void
    {
        $empresa = $this->empresaDoGabarito();
        $ator = $this->atorCliente($empresa);
        $this->listaDoGabarito($empresa, $ator);

        $totais = app(ColagemAnunciosService::class)->aplicar($empresa, $this->abaAnunciosColada(), 'acrescentar', $ator);

        $this->assertSame(['novos' => 4, 'atualizados' => 0, 'espera' => 0, 'erros' => 0, 'removidos' => 0], $totais);

        $painel = EstruturaConjunto::daEmpresa($empresa)->painel();
        $this->assertSame([9, 18, 4, 14, 1], [$painel['ofertas'], $painel['necessarios'], $painel['publicados'], $painel['a_publicar'], $painel['completas']]);
    }

    /**
     * "Anúncios já publicados conta 1 Clássico e 1 Premium por oferta
     * (anúncios duplicados do mesmo tipo não entram). Status Inativo é
     * ignorado." — nota J17 da planilha. Pausado CONTA.
     */
    public function test_duplicado_nao_soma_pausado_conta_e_inativo_nao(): void
    {
        $empresa = $this->empresaDoGabarito();
        $ator = $this->atorCliente($empresa);
        $ofertas = $this->listaDoGabarito($empresa, $ator);
        $this->anunciosDoGabarito($ofertas, $ator);
        $svc = app(EstruturaAnuncioService::class);

        // Segundo Clássico na CAD-01: aparece, não soma.
        $svc->cadastrar($ofertas['CAD-01'], ['tipo' => 'classico', 'codigo_mlb' => 'MLB0000000010'], $ator);
        // Premium PAUSADO na CB2: completa a oferta.
        $svc->cadastrar($ofertas['CAD-01-CB2'], ['tipo' => 'premium', 'codigo_mlb' => 'MLB0000000011', 'status' => 'pausado'], $ator);
        // Clássico INATIVO na MSA-MR: não conta.
        $svc->cadastrar($ofertas['MSA-MR'], ['tipo' => 'classico', 'codigo_mlb' => 'MLB0000000012', 'status' => 'inativo'], $ator);

        $conjunto = EstruturaConjunto::daEmpresa($empresa);
        $painel = $conjunto->painel();

        $this->assertSame(5, $painel['publicados']);   // 4 + o Premium pausado da CB2
        $this->assertSame(2, $painel['completas']);    // CAD-01 e CB2
        $this->assertSame(2, $conjunto->oferta($ofertas['CAD-01']->id)['classicos']);
        $this->assertSame('falta_classico', $conjunto->oferta($ofertas['MSA-MR']->id)['situacao']);
    }

    public function test_empresa_sem_nada_tem_painel_zerado_sem_divisao_por_zero(): void
    {
        $painel = EstruturaConjunto::daEmpresa($this->empresaDoGabarito())->painel();

        $this->assertSame(0, $painel['ofertas']);
        $this->assertSame(0.0, $painel['percentual']);
        $this->assertSame(0, EstruturaAnuncio::count());
    }
}
