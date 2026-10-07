<?php

namespace Tests\Feature\PortalCliente\Estrutura\Produtos;

use App\Models\Company;
use App\Models\EstruturaAnuncio;
use App\Models\EstruturaAnuncioEspera;
use App\Models\EstruturaOferta;
use App\Models\EstruturaProduto;
use App\Models\EstruturaProdutoVariacao;
use App\Services\Portal\Estrutura\EstruturaConjunto;
use App\Services\Portal\Estrutura\EstruturaOfertaService;
use App\Services\Portal\Estrutura\EstruturaVisaoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;
use Tests\Concerns\GabaritoDaPlanilhaEstrutural;
use Tests\TestCase;

/**
 * Fase 167-03: a oferta ligada a uma variação (D-08, D-22).
 *
 * Modos de falha que estes testes impedem: a Lista SKUs reescrevendo sku, nome
 * ou fase de uma oferta que vem do Produtos (duas verdades); a exclusão pela
 * Lista SKUs deixando a variação sem oferta; a sincronização passando por
 * `atualizar()` e zerando fase e componentes.
 */
class OfertaLigadaNaListaSkusTest extends TestCase
{
    use GabaritoDaPlanilhaEstrutural;
    use RefreshDatabase;

    private function svc(): EstruturaOfertaService
    {
        return app(EstruturaOfertaService::class);
    }

    private function variacao(Company $empresa, string $codigo = 'MESA-1'): EstruturaProdutoVariacao
    {
        $produto = EstruturaProduto::create(['company_id' => $empresa->id, 'nome' => 'Mesa '.uniqid()]);

        return EstruturaProdutoVariacao::create([
            'produto_id' => $produto->id, 'company_id' => $empresa->id, 'ordem' => 1, 'codigo' => $codigo,
        ]);
    }

    private function ligada(Company $empresa, string $codigo = 'MESA-1'): EstruturaOferta
    {
        $v = $this->variacao($empresa, $codigo);

        [$oferta] = $this->svc()->criar($empresa, [
            'sku' => $codigo, 'fase' => 'simples', 'nome' => 'Mesa — Natural', 'variacao_id' => $v->id,
        ], $this->atorCliente($empresa));

        return $oferta;
    }

    public function test_criar_grava_a_oferta_ligada_e_absorve_a_espera(): void
    {
        $empresa = $this->empresaDoGabarito();
        EstruturaAnuncioEspera::create(['company_id' => $empresa->id, 'sku_colado' => 'mesa-1', 'motivo' => 'sem_oferta', 'tipo' => 'classico']);

        $v = $this->variacao($empresa);
        [$oferta, $absorvidos] = $this->svc()->criar($empresa, [
            'sku' => 'MESA-1', 'fase' => 'simples', 'nome' => 'Mesa — Natural', 'variacao_id' => $v->id,
        ], $this->atorCliente($empresa));

        $this->assertSame($v->id, $oferta->fresh()->variacao_id);
        $this->assertTrue($oferta->ligadaAProduto());
        $this->assertSame(1, $absorvidos);
        $this->assertSame(0, EstruturaAnuncioEspera::count());
        $this->assertSame(1, $oferta->anuncios()->count());
    }

    public function test_variacao_de_outra_empresa_e_fase_combo_sao_recusadas(): void
    {
        $empresa = $this->empresaDoGabarito();
        $outra = $this->empresaDoGabarito();
        $alheia = $this->variacao($outra, 'ALHEIA-1');
        $propria = $this->variacao($empresa, 'PROPRIA-1');
        $ator = $this->atorCliente($empresa);

        try {
            $this->svc()->criar($empresa, ['sku' => 'X', 'fase' => 'simples', 'variacao_id' => $alheia->id], $ator);
            $this->fail('Variação de outra empresa deveria ser recusada');
        } catch (ValidationException $e) {
            $this->assertSame('Variação inválida.', $e->errors()['variacao_id'][0]);
        }

        try {
            $this->svc()->criar($empresa, ['sku' => 'X', 'fase' => 'simples', 'variacao_id' => 999999], $ator);
            $this->fail('Variação inexistente deveria ser recusada');
        } catch (ValidationException $e) {
            $this->assertSame('Variação inválida.', $e->errors()['variacao_id'][0]);
        }

        [$base] = $this->svc()->criar($empresa, ['sku' => 'BASE', 'fase' => 'simples'], $ator);
        try {
            $this->svc()->criar($empresa, [
                'sku' => 'X-CB2', 'fase' => 'combo', 'variacao_id' => $propria->id,
                'componentes' => [['id' => $base->id, 'quantidade' => 2]],
            ], $ator);
            $this->fail('Combo ligado deveria ser recusado');
        } catch (ValidationException $e) {
            $this->assertSame('Oferta ligada a produto é sempre Simples.', $e->errors()['fase'][0]);
        }

        $this->assertSame(0, EstruturaOferta::whereNotNull('variacao_id')->count());
    }

    public function test_atualizar_oferta_ligada_mantem_sku_nome_e_fase_mas_grava_logistica_e_observacoes(): void
    {
        $empresa = $this->empresaDoGabarito();
        $ator = $this->atorCliente($empresa);
        $oferta = $this->ligada($empresa);
        [$outra] = $this->svc()->criar($empresa, ['sku' => 'OUTRA', 'fase' => 'simples'], $ator);

        $this->svc()->atualizar($oferta, [
            'sku' => 'TROCADO', 'nome' => 'Outro nome', 'fase' => 'combo',
            'componentes' => [['id' => $outra->id, 'quantidade' => 2]],
            'logistica' => 'flex', 'observacoes' => 'Frágil',
        ], $ator);

        $oferta->refresh();
        $this->assertSame('MESA-1', $oferta->sku);
        $this->assertSame('Mesa — Natural', $oferta->nome);
        $this->assertSame('simples', $oferta->fase);
        $this->assertSame(0, $oferta->componentes()->count());
        $this->assertSame('flex', $oferta->logistica);
        $this->assertSame('Frágil', $oferta->observacoes);
    }

    public function test_excluir_oferta_ligada_so_via_produto(): void
    {
        $empresa = $this->empresaDoGabarito();
        $ator = $this->atorCliente($empresa);
        $oferta = $this->ligada($empresa);
        $this->svc()->criar($empresa, ['sku' => 'LIVRE', 'fase' => 'simples'], $ator);
        EstruturaAnuncio::create(['oferta_id' => $oferta->id, 'tipo' => 'classico', 'status' => 'ativo', 'codigo_mlb' => 'MLB1', 'titulo' => 'Mesa']);

        try {
            $this->svc()->excluir($oferta, $ator);
            $this->fail('Deveria recusar');
        } catch (ValidationException $e) {
            $this->assertSame('Esta oferta vem do Produtos. Exclua a variação lá.', $e->errors()['oferta'][0]);
        }
        $this->assertNotNull(EstruturaOferta::find($oferta->id));
        $this->assertSame(1, $oferta->anuncios()->count());

        $this->svc()->excluir($oferta, $ator, viaProduto: true);

        $this->assertNull(EstruturaOferta::find($oferta->id));
        $this->assertSame(1, EstruturaAnuncioEspera::where('company_id', $empresa->id)->count());
    }

    public function test_sincronizar_sem_mudanca_nao_registra_nada(): void
    {
        $empresa = $this->empresaDoGabarito();
        $oferta = $this->ligada($empresa);
        $ator = $this->atorCliente($empresa);
        $antes = Activity::count();

        $r = $this->svc()->sincronizarDaVariacao($oferta, 'MESA-1', 'Mesa — Natural', $ator);

        $this->assertSame(0, $r);
        $this->assertSame($antes, Activity::count());
    }

    public function test_sincronizar_sku_novo_atualiza_varre_espera_e_mantem_anuncios(): void
    {
        $empresa = $this->empresaDoGabarito();
        $ator = $this->atorCliente($empresa);
        $oferta = $this->ligada($empresa);
        EstruturaAnuncio::create(['oferta_id' => $oferta->id, 'tipo' => 'classico', 'status' => 'ativo', 'codigo_mlb' => 'MLB1', 'titulo' => 'Mesa']);
        EstruturaAnuncioEspera::create(['company_id' => $empresa->id, 'sku_colado' => 'mesa-2', 'motivo' => 'sem_oferta', 'tipo' => 'premium']);

        $absorvidos = $this->svc()->sincronizarDaVariacao($oferta, 'MESA-2', 'Mesa — Carvalho', $ator);

        $oferta->refresh();
        $this->assertSame(1, $absorvidos);
        $this->assertSame('MESA-2', $oferta->sku);
        $this->assertSame('Mesa — Carvalho', $oferta->nome);
        $this->assertSame('simples', $oferta->fase);
        $this->assertSame(2, $oferta->anuncios()->count());

        $log = Activity::where('log_name', 'portal')->latest('id')->first();
        $this->assertSame('oferta_editada', $log->getExtraProperty('evento'));
        $this->assertSame('produtos', $log->getExtraProperty('via'));
        $this->assertSame('MESA-1', $log->getExtraProperty('sku_antigo'));
    }

    public function test_conjunto_e_pagina_da_lista_trazem_a_variacao_id(): void
    {
        $empresa = $this->empresaDoGabarito();
        $ator = $this->atorCliente($empresa);
        $ligada = $this->ligada($empresa);
        [$avulsa] = $this->svc()->criar($empresa, ['sku' => 'AVULSA', 'fase' => 'simples'], $ator);

        $conjunto = EstruturaConjunto::daEmpresa($empresa);
        $this->assertSame($ligada->variacao_id, $conjunto->oferta($ligada->id)['variacao_id']);
        $this->assertNull($conjunto->oferta($avulsa->id)['variacao_id']);

        $pagina = app(EstruturaVisaoService::class)->paginaOfertas($empresa, 'todas', '', 1);
        $porId = [];
        foreach ($pagina['blocos'] as $bloco) {
            foreach ($bloco['ofertas'] as $o) {
                $porId[$o['id']] = $o;
            }
        }

        $this->assertArrayHasKey('variacao_id', $porId[$ligada->id]);
        $this->assertSame($ligada->variacao_id, $porId[$ligada->id]['variacao_id']);
        $this->assertNull($porId[$avulsa->id]['variacao_id']);
    }

    public function test_pelo_http_do_portal_a_oferta_ligada_e_protegida(): void
    {
        $empresa = $this->empresaDoGabarito();
        $oferta = $this->ligada($empresa);

        $sessao = $this->entrarNoPortal($empresa);

        $sessao->put(route('portal.auth.estrutura.ofertas.atualizar', $oferta->id), [
            'sku' => 'NOVO-SKU', 'nome' => 'Nome novo', 'fase' => 'simples', 'logistica' => 'full',
        ]);
        $oferta->refresh();
        $this->assertSame('MESA-1', $oferta->sku);
        $this->assertSame('Mesa — Natural', $oferta->nome);
        $this->assertSame('full', $oferta->logistica);

        $sessao->delete(route('portal.auth.estrutura.ofertas.excluir', $oferta->id))
            ->assertSessionHasErrors('oferta');
        $this->assertNotNull(EstruturaOferta::find($oferta->id));
    }
}
