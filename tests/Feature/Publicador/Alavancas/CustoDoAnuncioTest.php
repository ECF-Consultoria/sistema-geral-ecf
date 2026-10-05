<?php

namespace Tests\Feature\Publicador\Alavancas;

use App\Models\Company;
use App\Models\EstruturaAnuncio;
use App\Models\EstruturaOferta;
use App\Models\EstruturaPrecificacao;
use App\Models\EstruturaPrecificacaoParametros;
use App\Models\PubProduto;
use App\Services\Portal\Estrutura\EstruturaPrecificacaoService;
use App\Services\Publicador\Alavancas\CustoDoAnuncioService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Publicador\Alavancas\Concerns\CenarioAlavancas;
use Tests\TestCase;

/** 166-06: do MLB ao custo da Precificação do Portal (D-11). */
class CustoDoAnuncioTest extends TestCase
{
    use CenarioAlavancas;
    use RefreshDatabase;

    private function oferta(Company $empresa, string $sku, ?float $custo, array $extra = []): EstruturaOferta
    {
        $o = EstruturaOferta::create(['company_id' => $empresa->id, 'sku' => $sku, 'fase' => 'simples', 'nome' => "Produto {$sku}"]);
        if ($custo !== null || $extra !== []) {
            EstruturaPrecificacao::create(['oferta_id' => $o->id, 'custo' => $custo, ...$extra]);
        }

        return $o;
    }

    private function liga(EstruturaOferta $o, string $mlb): void
    {
        EstruturaAnuncio::create(['oferta_id' => $o->id, 'tipo' => 'classico', 'status' => 'ativo', 'codigo_mlb' => $mlb, 'titulo' => 'x']);
    }

    private function servico(): CustoDoAnuncioService
    {
        return app(CustoDoAnuncioService::class);
    }

    public function test_oferta_ligada_pelo_codigo_mlb_devolve_custo_e_imposto_da_empresa(): void
    {
        $this->montarAlavancas('company');
        $o = $this->oferta($this->ancora, 'A-1', 40.0);
        $this->liga($o, 'MLB1');
        $padrao = EstruturaPrecificacaoParametros::PADROES['imposto'];

        $r = $this->servico()->custos($this->contaAlavanca(), ['MLB1', 'MLB2']);

        $this->assertSame(['MLB1'], array_keys($r));
        $this->assertSame(40.0, $r['MLB1']['custo']);
        $this->assertSame((float) $padrao, $r['MLB1']['imposto_percentual']);
        $this->assertSame($o->id, $r['MLB1']['oferta_id']);
    }

    public function test_excecao_de_imposto_da_oferta_vence_a_da_empresa(): void
    {
        $this->montarAlavancas('company');
        $o = $this->oferta($this->ancora, 'A-1', 40.0, ['imposto' => 4.5]);
        $this->liga($o, 'MLB1');

        $this->assertSame(4.5, $this->servico()->custos($this->contaAlavanca(), ['MLB1'])['MLB1']['imposto_percentual']);
    }

    public function test_caminho_de_reserva_pelo_publicador(): void
    {
        $this->montarAlavancas('company');
        $o = $this->oferta($this->ancora, 'A-1', 40.0);
        $produto = PubProduto::create(['company_id' => $this->ancora->id, 'oferta_id' => $o->id, 'sku' => 'A-1', 'nome' => 'x', 'origem' => PubProduto::ORIGEM_PORTAL]);
        $agora = now();
        $rascunho = DB::table('pub_rascunhos')->insertGetId(['produto_id' => $produto->id, 'status' => 'DRAFT', 'revisao' => 1,
            'condicao' => 'new', 'created_at' => $agora, 'updated_at' => $agora]);
        $pub = DB::table('pub_publicacoes')->insertGetId(['rascunho_id' => $rascunho, 'revisao' => 1, 'modelo_publicacao' => 'items',
            'chave_idempotencia' => (string) Str::uuid(), 'created_at' => $agora, 'updated_at' => $agora]);
        DB::table('pub_publicacao_itens')->insert(['publicacao_id' => $pub, 'indice' => 0, 'listing_type_id' => 'gold_special',
            'variante_chave' => 'v', 'ml_item_id' => 'MLB777', 'created_at' => $agora, 'updated_at' => $agora]);

        $r = $this->servico()->custos($this->contaAlavanca(), ['MLB777']);

        $this->assertSame(40.0, $r['MLB777']['custo']);
        $this->assertSame($o->id, $r['MLB777']['oferta_id']);
    }

    public function test_oferta_sem_custo_nao_aparece(): void
    {
        $this->montarAlavancas('company');
        $o = $this->oferta($this->ancora, 'A-1', null);
        $this->liga($o, 'MLB1');

        $this->assertSame([], $this->servico()->custos($this->contaAlavanca(), ['MLB1']));
    }

    public function test_mlb_empresa_sem_company_nao_consulta_a_precificacao(): void
    {
        $this->montarAlavancas('mlb_empresa');
        $this->mock(EstruturaPrecificacaoService::class)->shouldNotReceive('pagina');

        $this->assertSame([], $this->servico()->custos($this->contaAlavanca(), ['MLB1']));
    }

    public function test_mlb_de_outra_company_nao_entra(): void
    {
        $this->montarAlavancas('company');
        $outra = Company::factory()->create();
        $this->liga($this->oferta($outra, 'B-1', 99.0), 'MLB1');

        $this->assertSame([], $this->servico()->custos($this->contaAlavanca(), ['MLB1']));
    }

    public function test_sku_repetido_cada_mlb_pega_a_sua_oferta_e_pagina_roda_uma_vez(): void
    {
        $this->montarAlavancas('company');
        $a = $this->oferta($this->ancora, 'REPETIDO', 10.0);
        $b = $this->oferta($this->ancora, 'REPETIDO', 20.0);
        $this->liga($a, 'MLB1');
        $this->liga($b, 'MLB2');

        $real = app(EstruturaPrecificacaoService::class);
        $chamadas = 0;
        $this->mock(EstruturaPrecificacaoService::class, function ($m) use ($real, &$chamadas) {
            $m->shouldReceive('pagina')->andReturnUsing(function (...$args) use ($real, &$chamadas) {
                $chamadas++;

                return $real->pagina(...$args);
            });
        });

        $r = $this->servico()->custos($this->contaAlavanca(), ['MLB1', 'MLB2']);

        $this->assertSame(10.0, $r['MLB1']['custo']);
        $this->assertSame(20.0, $r['MLB2']['custo']);
        $this->assertSame(1, $chamadas);
    }
}
