<?php

namespace Tests\Feature\Publicador;

use App\Jobs\Publicador\GerarDescricaoIaJob;
use App\Models\PubRascunho;
use App\Services\Ia\AnaliseAnuncioService;
use App\Services\Publicador\DescricaoIaService;
use App\Services\Publicador\PortalProdutoLeitor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Publicador\Concerns\CenarioCadeira;
use Tests\TestCase;

/**
 * Descrição do cliente como matéria-prima do MAG T8 (Fase 172, D-09/D-11): o serviço pede,
 * o Job gera no cache e NUNCA grava no rascunho; o automático vale uma vez por rascunho.
 * Nenhuma chamada real à IA: o `AnaliseAnuncioService` é substituído.
 */
class DescricaoIaTest extends TestCase
{
    use CenarioCadeira;
    use RefreshDatabase;

    private ?string $descricaoCliente = 'Cadeira com encosto em tela, 2 anos de uso, ótima para home office.';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->montarCenario();
        $this->mock(PortalProdutoLeitor::class, function ($m) {
            $m->shouldReceive('descricaoDoCliente')->andReturnUsing(fn () => $this->descricaoCliente);
        });
    }

    private function servico(): DescricaoIaService
    {
        return app(DescricaoIaService::class);
    }

    private function vazio(): PubRascunho
    {
        $this->r->update(['descricao' => '']);

        return $this->r->fresh();
    }

    public function test_pedir_manual_poe_job_na_fila_high_e_marca_rodando(): void
    {
        Queue::fake();

        $pedido = $this->servico()->pedir($this->r);

        $this->assertNotNull($pedido);
        Queue::assertPushedOn('high', GerarDescricaoIaJob::class, fn ($j) => $j->pedido === $pedido && $j->rascunhoId === $this->r->id);
        $this->assertSame(['pedido' => $pedido, 'status' => 'rodando', 'valor' => null, 'erro' => null], $this->servico()->estado($this->r));
    }

    public function test_automatico_so_uma_vez_por_rascunho(): void
    {
        Queue::fake();
        $r = $this->vazio();

        $this->assertNotNull($this->servico()->pedir($r, true));
        $this->assertNull($this->servico()->pedir($r, true));
        Queue::assertPushed(GerarDescricaoIaJob::class, 1);
        // O botão (manual) continua valendo.
        $this->assertNotNull($this->servico()->pedir($r));
    }

    public function test_automatico_nao_pede_com_descricao_preenchida_nem_sem_texto_do_cliente(): void
    {
        Queue::fake();

        $this->assertNull($this->servico()->pedir($this->r, true), 'rascunho com descrição');

        $this->descricaoCliente = null;
        $this->assertNull($this->servico()->pedir($this->vazio(), true), 'sem descrição do cliente');

        Queue::assertNothingPushed();
        // Sem material não gasta a única chance do rascunho.
        $this->assertNull(Cache::get(DescricaoIaService::chaveAuto($this->r->id)));
    }

    public function test_executar_monta_specs_chama_analise_antes_da_descricao_e_nao_toca_o_rascunho(): void
    {
        $this->r->atributos()->updateOrCreate(['attribute_id' => 'SELLER_PACKAGE_WEIGHT'], ['value_name' => '9500 g']);
        $r = $this->r->fresh();
        $antes = [$r->descricao, $r->revisao];
        $ordem = [];
        $specsVistas = [];

        $this->mock(AnaliseAnuncioService::class, function ($m) use (&$ordem, &$specsVistas) {
            $m->shouldReceive('analise')->once()->andReturnUsing(function ($produto, $loja, $specs) use (&$ordem, &$specsVistas) {
                $ordem[] = 'analise';
                $specsVistas = $specs;

                return ['dados' => ['posicionamento' => 'x'], 'meta' => []];
            });
            $m->shouldReceive('descricao')->once()->andReturnUsing(function ($produto, $loja, $specs, $analise) use (&$ordem) {
                $ordem[] = 'descricao';
                $this->assertSame(['posicionamento' => 'x'], $analise);

                return ['dados' => '<p>Cadeira <b>executiva</b>.</p><p>Envio rápido.</p>', 'meta' => []];
            });
        });

        $this->servico()->executar($r, 'p-1');

        $this->assertSame(['analise', 'descricao'], $ordem);
        $this->assertStringContainsString('Descrição fornecida pelo cliente:', $specsVistas);
        $this->assertStringContainsString('encosto em tela', $specsVistas);
        $this->assertStringContainsString('Peso da embalagem: 9500 g', $specsVistas);
        $this->assertStringContainsString('ECF', $specsVistas, 'atributo preenchido da ficha');

        $e = Cache::get(DescricaoIaService::chave($r->id));
        $this->assertSame('pronto', $e['status']);
        $this->assertSame("Cadeira executiva.\nEnvio rápido.", $e['valor']);

        $depois = $r->fresh();
        $this->assertSame($antes, [$depois->descricao, $depois->revisao], 'o Job nunca grava no rascunho');
    }

    public function test_specs_sao_cortadas_no_limite(): void
    {
        $this->descricaoCliente = str_repeat('a', 20000);

        $this->assertSame(DescricaoIaService::LIMITE_SPECS, mb_strlen($this->servico()->specs($this->r)));
    }

    public function test_ia_vazia_vira_erro_e_excecao_vira_erro_e_relanca(): void
    {
        $this->mock(AnaliseAnuncioService::class, function ($m) {
            $m->shouldReceive('analise')->andReturn(['dados' => [], 'meta' => []]);
            $m->shouldReceive('descricao')->once()->andReturn(['dados' => '   ', 'meta' => []]);
        });
        try {
            $this->servico()->executar($this->r, 'p-1');
            $this->fail('devia lançar');
        } catch (\RuntimeException) {
        }
        $this->assertSame('erro', Cache::get(DescricaoIaService::chave($this->r->id))['status']);
        $this->assertNotEmpty(Cache::get(DescricaoIaService::chave($this->r->id))['erro']);
    }

    public function test_excecao_da_ia_vira_erro_e_relanca(): void
    {
        $this->mock(AnaliseAnuncioService::class, function ($m) {
            $m->shouldReceive('analise')->andThrow(new \RuntimeException('NVIDIA fora'));
        });

        $this->expectException(\RuntimeException::class);
        try {
            $this->servico()->executar($this->r, 'p-1');
        } finally {
            $e = Cache::get(DescricaoIaService::chave($this->r->id));
            $this->assertSame(['erro', 'NVIDIA fora'], [$e['status'], $e['erro']]);
        }
    }

    public function test_failed_do_job_grava_erro_de_prazo(): void
    {
        $pedido = 'ped-1';
        Cache::put(DescricaoIaService::chave($this->r->id), ['pedido' => $pedido, 'status' => 'rodando', 'valor' => null, 'erro' => null], 600);

        (new GerarDescricaoIaJob($this->r->id, $pedido))->failed(new \RuntimeException('timeout'));

        $e = Cache::get(DescricaoIaService::chave($this->r->id));
        $this->assertSame(['erro', 'A IA não terminou a tempo. Tente de novo.'], [$e['status'], $e['erro']]);
    }

    public function test_resultado_de_pedido_velho_nao_pisa_no_novo(): void
    {
        Queue::fake();
        $velho = $this->servico()->pedir($this->r);
        $novo = $this->servico()->pedir($this->r);

        $this->servico()->falhou($this->r->id, $velho, 'atrasado');

        $e = $this->servico()->estado($this->r);
        $this->assertSame(['rodando', $novo], [$e['status'], $e['pedido']]);
    }

    public function test_job_roda_pela_fila_sem_http_real(): void
    {
        Http::fake();
        $this->mock(AnaliseAnuncioService::class, function ($m) {
            $m->shouldReceive('comPrazo')->andReturnSelf();
            $m->shouldReceive('analise')->andReturn(['dados' => [], 'meta' => []]);
            $m->shouldReceive('descricao')->andReturn(['dados' => 'Texto pronto.', 'meta' => []]);
        });
        Cache::put(DescricaoIaService::chave($this->r->id), ['pedido' => 'p-9', 'status' => 'rodando', 'valor' => null, 'erro' => null], 600);

        (new GerarDescricaoIaJob($this->r->id, 'p-9'))->handle($this->servico());

        $this->assertSame('Texto pronto.', Cache::get(DescricaoIaService::chave($this->r->id))['valor']);
        Http::assertNothingSent();
    }
}
