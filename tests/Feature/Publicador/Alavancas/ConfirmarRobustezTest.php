<?php

namespace Tests\Feature\Publicador\Alavancas;

use App\Models\PubAlavancaEscrita;
use App\Models\User;
use App\Services\Publicador\Alavancas\Acoes\AcaoAlavanca;
use App\Services\Publicador\Alavancas\Acoes\GravarExclusaoDoItem;
use App\Services\Publicador\Alavancas\CacheAlavancas;
use App\Services\Publicador\Alavancas\EscritorAlavancas;
use App\Services\Publicador\Alavancas\LeitorContaAlavancas;
use App\Services\Publicador\ClienteMlPublicador;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Validator;
use Tests\Feature\Publicador\Alavancas\Concerns\ApoioEscritaHttp;
use Tests\Feature\Publicador\Alavancas\Concerns\CenarioAlavancas;
use Tests\TestCase;

/** 166 review: confirmação robusta (WR-BE-02, WR-BE-03, IN-BE-01) — forma dos itens e lote atômico. */
class ConfirmarRobustezTest extends TestCase
{
    use ApoioEscritaHttp;
    use CenarioAlavancas;
    use RefreshDatabase;

    public function test_item_id_com_mais_de_17_digitos_ou_com_quebra_de_linha_final_e_422_sem_linha(): void
    {
        $this->cenarioDeal(2);
        Queue::fake();

        foreach (['MLB'.str_repeat('9', 18), "MLB1\n"] as $ruim) {
            $itens = [$this->itemDeal(1), [...$this->itemDeal(2), 'item_id' => $ruim]];
            $this->confirmarCom('convite.inscrever', $itens, 'qualquer')->assertStatus(422);
        }

        $this->assertSame(0, PubAlavancaEscrita::count());
        Queue::assertNothingPushed();
        // O limite de 17 dígitos cabe na coluna string(20); mais que isso e a quebra de linha final não passam.
        $regras = ['item_id' => GravarExclusaoDoItem::regras()['item_id']];
        $this->assertTrue(Validator::make(['item_id' => 'MLB'.str_repeat('1', 17)], $regras)->passes());
        $this->assertSame(20, strlen('MLB'.str_repeat('1', 17)));
        $this->assertTrue(Validator::make(['item_id' => 'MLB'.str_repeat('1', 18)], $regras)->fails());
        $this->assertTrue(Validator::make(['item_id' => "MLB1
"], $regras)->fails());
    }

    public function test_falha_ao_abrir_uma_linha_no_meio_do_lote_nao_deixa_pendente_orfa_e_devolve_a_assinatura(): void
    {
        $this->cenarioDeal(3);
        Queue::fake();
        $itens = array_map(fn ($i) => $this->itemDeal($i), range(1, 3));
        $assinatura = $this->previaDe('convite.inscrever', $itens)->assertOk()->json('assinatura');

        $this->app->instance(EscritorAlavancas::class, new class(app(ClienteMlPublicador::class), app(LeitorContaAlavancas::class), app(CacheAlavancas::class)) extends EscritorAlavancas
        {
            private int $n = 0;

            public function abrirLinha(AcaoAlavanca $acao, User $ator, ?string $lote = null): PubAlavancaEscrita
            {
                if (++$this->n === 2) {
                    throw new \RuntimeException('coluna estourou');
                }

                return parent::abrirLinha($acao, $ator, $lote);
            }
        });

        $this->confirmarCom('convite.inscrever', $itens, $assinatura)->assertStatus(502);

        $this->assertSame(0, PubAlavancaEscrita::count(), 'a primeira linha foi desfeita junto');
        Queue::assertNothingPushed();

        // Nada foi gravado: a mesma confirmação ainda vale (assinatura não ficou queimada).
        $this->app->forgetInstance(EscritorAlavancas::class);
        $this->app->forgetInstance(\App\Services\Publicador\Alavancas\PreviaAlavancasService::class);
        $this->confirmarCom('convite.inscrever', $itens, $assinatura)->assertStatus(202);
        $this->assertSame(3, PubAlavancaEscrita::where('resultado', PubAlavancaEscrita::PENDENTE)->count());
    }

    public function test_fila_fora_do_ar_marca_as_linhas_do_lote_como_erro_e_nao_deixa_pendente(): void
    {
        $this->cenarioDeal(3);
        $itens = array_map(fn ($i) => $this->itemDeal($i), range(1, 3));
        $assinatura = $this->previaDe('convite.inscrever', $itens)->assertOk()->json('assinatura');

        $this->mock(Dispatcher::class, function ($m) {
            $m->shouldReceive('dispatch')->andThrow(new \RuntimeException('redis fora do ar'));
        });

        $this->confirmarCom('convite.inscrever', $itens, $assinatura)->assertStatus(502);

        $linhas = PubAlavancaEscrita::all();
        $this->assertCount(3, $linhas);
        foreach ($linhas as $l) {
            $this->assertSame(PubAlavancaEscrita::ERRO, $l->resultado);
            $this->assertSame('ALAV-LOTE-FILA', $l->erro_codigo);
            $this->assertStringContainsString('Nada foi enviado', (string) $l->mensagem);
            $this->assertNull($l->enviado_em);
        }
        $this->assertSame([], $this->escritasNoMl());
    }

    public function test_chave_extra_longa_e_descartada_e_nao_chega_ao_historico(): void
    {
        $this->cenarioDeal(2);
        Queue::fake();
        $lixo = str_repeat('x', 500);
        $itens = array_map(fn ($i) => [...$this->itemDeal($i), 'lixo' => $lixo, 'ator_nome' => $lixo], range(1, 2));
        $assinatura = $this->previaDe('convite.inscrever', $itens)->assertOk()->json('assinatura');

        $r = $this->confirmarCom('convite.inscrever', $itens, $assinatura);

        $r->assertStatus(202);
        $linhas = PubAlavancaEscrita::where('lote_uuid', $r->json('lote'))->get();
        $this->assertCount(2, $linhas);
        foreach ($linhas as $l) {
            $this->assertArrayNotHasKey('lixo', $l->payload['dados']);
            $this->assertArrayNotHasKey('ator_nome', $l->payload['dados']);
            $this->assertSame('DEAL', $l->payload['dados']['promotion_type'], 'chave declarada segue');
            $this->assertStringNotContainsString($lixo, json_encode($l->payload));
        }
    }

    public function test_promotion_id_longo_em_acao_que_nao_declara_a_regra_nao_estoura_a_coluna(): void
    {
        $this->cenarioDeal(2);
        Queue::fake();
        $itens = array_map(fn ($i) => ['item_id' => "MLB{$i}", 'promotion_id' => str_repeat('a', 60), 'promotion_type' => str_repeat('T', 60)], range(1, 2));
        $assinatura = $this->assinaturaForjada('desconto.remover', array_map(fn ($i) => ['item_id' => $i['item_id']], $itens));

        $r = $this->confirmarCom('desconto.remover', $itens, $assinatura);

        $r->assertStatus(202);
        foreach (PubAlavancaEscrita::where('lote_uuid', $r->json('lote'))->get() as $l) {
            $this->assertNull($l->promotion_id);
            $this->assertSame('PRICE_DISCOUNT', $l->promotion_type, 'vem da própria ação, não do corpo');
        }
    }
}
