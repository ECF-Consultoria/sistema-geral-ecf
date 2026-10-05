<?php

namespace Tests\Feature\Publicador\Alavancas;

use App\Jobs\Publicador\ExecutarLoteAlavancaJob;
use App\Models\Company;
use App\Models\PubAlavancaEscrita;
use App\Services\Publicador\Alavancas\ContextoAlavancas;
use App\Services\Publicador\Alavancas\EscritorAlavancas;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Publicador\Alavancas\Concerns\ApoioEscritaHttp;
use Tests\Feature\Publicador\Alavancas\Concerns\CenarioAlavancas;
use Tests\TestCase;

/** 166-11: lote por job em fatias — linhas PENDENTE, trava e vendedor a cada item, sem reenviar, acompanhável. */
class LoteJobTest extends TestCase
{
    use ApoioEscritaHttp;
    use CenarioAlavancas;
    use RefreshDatabase;

    /** Confirma N produtos com a fila falsa e devolve o uuid do lote. */
    private function confirmarLote(int $n = 3): string
    {
        Queue::fake();
        $itens = array_map(fn ($i) => $this->itemDeal($i), range(1, $n));
        $assinatura = $this->previaDe('convite.inscrever', $itens)->assertOk()->json('assinatura');

        $r = $this->confirmarCom('convite.inscrever', $itens, $assinatura);
        $r->assertStatus(202);

        return (string) $r->json('lote');
    }

    private function rodar(string $lote, ?ExecutarLoteAlavancaJob $job = null): ExecutarLoteAlavancaJob
    {
        $job ??= new ExecutarLoteAlavancaJob($lote, $this->admin->id);
        $job->withFakeQueueInteractions()->handle(app(EscritorAlavancas::class), app(ContextoAlavancas::class));

        return $job;
    }

    public function test_confirmar_varios_devolve_202_cria_linhas_pendentes_e_empurra_o_job_na_fila_high(): void
    {
        $this->cenarioDeal(3);

        $lote = $this->confirmarLote(3);

        Queue::assertPushedOn('high', ExecutarLoteAlavancaJob::class, fn ($j) => $j->lote === $lote && $j->userId === $this->admin->id);
        $linhas = PubAlavancaEscrita::where('lote_uuid', $lote)->orderBy('id')->get();
        $this->assertCount(3, $linhas);
        $this->assertSame(['MLB1', 'MLB2', 'MLB3'], $linhas->pluck('item_id')->all());
        foreach ($linhas as $l) {
            $this->assertSame(PubAlavancaEscrita::PENDENTE, $l->resultado);
            $this->assertSame('convite.inscrever', $l->acao);
            $this->assertSame('DEAL', $l->payload['dados']['promotion_type']);
            $this->assertNull($l->enviado_em);
        }
        $this->assertSame([], $this->escritasNoMl(), 'nada escrito ainda');
    }

    public function test_202_traz_total_e_a_url_do_acompanhamento(): void
    {
        $this->cenarioDeal(3);
        Queue::fake();
        $itens = array_map(fn ($i) => $this->itemDeal($i), range(1, 3));
        $previa = $this->previaDe('convite.inscrever', $itens)->assertOk();
        $this->assertCount(3, $previa->json('resumo.itens'), 'o resumo da prévia lista os 3 produtos');

        $r = $this->confirmarCom('convite.inscrever', $itens, $previa->json('assinatura'));

        $r->assertStatus(202)->assertJsonPath('total', 3);
        $this->assertSame($this->rota('lotes', ['lote' => $r->json('lote')]), $r->json('url'));
    }

    public function test_o_job_escreve_os_tres_itens_um_a_um(): void
    {
        $this->cenarioDeal(3);
        $lote = $this->confirmarLote(3);

        $job = $this->rodar($lote);

        $this->assertSame(3, PubAlavancaEscrita::where('lote_uuid', $lote)->where('resultado', 'OK')->count());
        $this->assertCount(3, $this->escritasNoMl());
        $job->assertNotReleased();
        $this->assertGreaterThanOrEqual(1, count($this->chamadasAoMl('GET', '#^/users/me$#')), 'o vendedor foi conferido');
    }

    public function test_conta_que_sai_da_lista_no_meio_do_lote_recusa_o_resto(): void
    {
        $this->cenarioDeal(3);
        $this->responder('POST', '#^/seller-promotions/items/MLB\d+$#', function (Request $r) {
            config(['publicador.alavancas.contas_liberadas' => ['companies' => [], 'mlb_empresas' => []]]);

            return Http::response(self::fixtureAlavanca('doc/acoes/post_item_ok'), 200);
        });
        $lote = $this->confirmarLote(3);

        $this->rodar($lote);

        $this->assertSame(['OK', 'RECUSADA', 'RECUSADA'], PubAlavancaEscrita::where('lote_uuid', $lote)->orderBy('id')->pluck('resultado')->all());
        $this->assertSame('ALAV-LIB', PubAlavancaEscrita::where('lote_uuid', $lote)->orderByDesc('id')->first()->erro_codigo);
        $this->assertCount(1, $this->escritasNoMl());
    }

    public function test_linha_com_enviado_em_vira_incerto_sem_reenviar(): void
    {
        $this->cenarioDeal(3);
        $lote = $this->confirmarLote(3);
        PubAlavancaEscrita::where('lote_uuid', $lote)->orderBy('id')->first()->update(['enviado_em' => now()]);

        $this->rodar($lote);

        $this->assertSame(['INCERTO', 'OK', 'OK'], PubAlavancaEscrita::where('lote_uuid', $lote)->orderBy('id')->pluck('resultado')->all());
        $this->assertCount(2, $this->escritasNoMl());
    }

    public function test_conta_trocada_na_ancora_da_linha_recusa_sem_escrever(): void
    {
        $this->cenarioDeal(2);
        $lote = $this->confirmarLote(2);
        PubAlavancaEscrita::where('lote_uuid', $lote)->update(['conta_chave' => 'company-99999']);

        $this->rodar($lote);

        $this->assertSame(2, PubAlavancaEscrita::where('lote_uuid', $lote)->where('resultado', 'RECUSADA')->where('erro_codigo', 'V-ACC-03')->count());
        $this->assertSame([], $this->escritasNoMl());
    }

    public function test_com_fatia_zero_processa_um_item_e_devolve_para_a_fila(): void
    {
        $this->cenarioDeal(3);
        $lote = $this->confirmarLote(3);
        config(['publicador.fatia_segundos' => 0]);

        $job = $this->rodar($lote);

        $job->assertReleased(15);
        $this->assertSame(1, PubAlavancaEscrita::where('lote_uuid', $lote)->where('resultado', 'OK')->count());
        $this->assertSame(2, PubAlavancaEscrita::where('lote_uuid', $lote)->where('resultado', 'PENDENTE')->count());

        // A reexecução continua de onde parou, sem repetir o que já saiu.
        config(['publicador.fatia_segundos' => 45]);
        $this->rodar($lote);
        $this->assertSame(3, PubAlavancaEscrita::where('lote_uuid', $lote)->where('resultado', 'OK')->count());
        $this->assertCount(3, $this->escritasNoMl());
    }

    public function test_trava_ocupada_devolve_para_a_fila_sem_processar(): void
    {
        $this->cenarioDeal(2);
        $lote = $this->confirmarLote(2);
        $trava = Cache::lock("alavancas:lote:{$lote}", 60);
        $this->assertTrue($trava->get());

        $job = $this->rodar($lote);

        $job->assertReleased(20);
        $this->assertSame(2, PubAlavancaEscrita::where('lote_uuid', $lote)->where('resultado', 'PENDENTE')->count());
        $this->assertSame([], $this->escritasNoMl());
        $trava->release();
    }

    public function test_failed_marca_pendente_sem_envio_como_recusada_e_com_envio_como_incerto(): void
    {
        $this->cenarioDeal(2);
        $lote = $this->confirmarLote(2);
        PubAlavancaEscrita::where('lote_uuid', $lote)->orderBy('id')->first()->update(['enviado_em' => now()]);

        (new ExecutarLoteAlavancaJob($lote, $this->admin->id))->failed(new \RuntimeException('boom'));

        $linhas = PubAlavancaEscrita::where('lote_uuid', $lote)->orderBy('id')->get();
        $this->assertSame('INCERTO', $linhas[0]->resultado);
        $this->assertSame('RECUSADA', $linhas[1]->resultado);
        $this->assertSame('ALAV-LOTE-PAROU', $linhas[1]->erro_codigo);
    }

    public function test_usuario_inexistente_recusa_o_lote_inteiro(): void
    {
        $this->cenarioDeal(2);
        $lote = $this->confirmarLote(2);

        $this->rodar($lote, new ExecutarLoteAlavancaJob($lote, 999999));

        $this->assertSame(2, PubAlavancaEscrita::where('lote_uuid', $lote)->where('erro_codigo', 'ALAV-LOTE-USUARIO')->count());
        $this->assertSame([], $this->escritasNoMl());
    }

    public function test_lote_de_50_le_os_produtos_em_blocos_de_20(): void
    {
        $this->cenarioDeal(50);
        $lote = $this->confirmarLote(50);
        $this->chamadas = [];

        $this->rodar($lote);

        $this->assertSame(50, PubAlavancaEscrita::where('lote_uuid', $lote)->where('resultado', 'OK')->count());
        $this->assertLessThanOrEqual(3, count($this->chamadasAoMl('GET', '#^/items$#')));
        $this->assertCount(1, $this->chamadasAoMl('GET', '#^/seller-promotions/promotions/P-1/items$#'));
    }

    public function test_no_driver_sync_o_job_roda_inline_sem_recursar(): void
    {
        $this->cenarioDeal(3);
        $itens = array_map(fn ($i) => $this->itemDeal($i), range(1, 3));
        $assinatura = $this->previaDe('convite.inscrever', $itens)->json('assinatura');

        $this->confirmarCom('convite.inscrever', $itens, $assinatura)->assertStatus(202);

        $this->assertSame(3, PubAlavancaEscrita::where('resultado', 'OK')->count());
    }

    // ═══ Acompanhamento ═══

    public function test_lote_mostra_contagem_por_resultado_e_o_fim(): void
    {
        $this->cenarioDeal(3);
        $lote = $this->confirmarLote(3);

        $antes = $this->actingAs($this->admin)->getJson($this->rota('lotes', ['lote' => $lote]));
        $antes->assertOk()->assertJsonPath('total', 3)->assertJsonPath('terminado', false)->assertJsonPath('por_resultado.PENDENTE', 3);

        $this->rodar($lote);

        $depois = $this->actingAs($this->admin)->getJson($this->rota('lotes', ['lote' => $lote]));
        $depois->assertOk()->assertJsonPath('terminado', true)->assertJsonPath('por_resultado.OK', 3)->assertJsonPath('por_resultado.PENDENTE', 0)
            ->assertJsonPath('itens.0.item_id', 'MLB1')->assertJsonPath('itens.0.resultado', 'OK');
    }

    public function test_lote_de_outra_empresa_e_404_e_uuid_invalido_tambem(): void
    {
        $this->cenarioDeal(1);
        $outra = Company::factory()->create();
        $uuid = '22222222-2222-4222-8222-222222222222';
        PubAlavancaEscrita::create(['lote_uuid' => $uuid, 'company_id' => $outra->id, 'conta_chave' => $outra->chaveContaMl(), 'ml_seller_id' => '1',
            'user_id' => $this->admin->id, 'ator_nome' => 'x', 'alavanca' => 'promocao', 'acao' => 'convite.inscrever', 'resultado' => 'PENDENTE']);

        $this->actingAs($this->admin)->getJson($this->rota('lotes', ['lote' => $uuid]))->assertNotFound();
        $this->actingAs($this->admin)->getJson($this->rota('lotes', ['lote' => '33333333-3333-4333-8333-333333333333']))->assertNotFound();
        $this->actingAs($this->admin)->getJson(preg_replace('#/lotes/.*$#', '/lotes/nao-e-uuid', $this->rota('lotes', ['lote' => $uuid])))->assertNotFound();
    }

    public function test_a_fonte_do_job_nao_se_redespacha(): void
    {
        $fonte = file_get_contents(base_path('app/Jobs/Publicador/ExecutarLoteAlavancaJob.php'));
        $semComentarios = implode('', array_map(fn ($t) => is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true) ? '' : (is_array($t) ? $t[1] : $t), token_get_all($fonte)));

        $this->assertStringNotContainsString('self::dispatch', $semComentarios);
        $this->assertStringNotContainsString('static::dispatch', $semComentarios);
        $this->assertStringContainsString('release(15)', $semComentarios);
    }
}
