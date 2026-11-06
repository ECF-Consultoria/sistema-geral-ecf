<?php

namespace Tests\Feature\Phase202;

use App\Jobs\SincronizarRenovacaoJob;
use App\Models\Company;
use App\Models\ContratoAssinatura;
use App\Models\ContratoRenovacao;
use App\Models\ContratoServico;
use App\Models\Servico;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Fase 202 (REN-06/REN-07) — tela de contratos com renovação: filtros,
 * dados por linha e ações (sincronizar, dispensar, histórico).
 */
class ContratosTelaRenovacaoTest extends TestCase
{
    use RefreshDatabase;

    private Servico $servico;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-06 10:00:00');
        $this->servico = Servico::create([
            'nome' => 'Gestão (tela renovação)', 'valor_padrao' => 100, 'tipo_cobranca' => Servico::TIPO_MENSAL,
            'ativo' => true, 'setor' => Servico::SETOR_PERFORMANCE, 'exige_contrato' => true,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function empresa(?string $termino, string $nome, array $ct = []): Company
    {
        $c = Company::factory()->create(['active' => true, 'name' => $nome]);
        ContratoServico::factory()->create(array_merge([
            'company_id' => $c->id, 'servico_id' => $this->servico->id,
            'data_contratacao' => '2025-01-10', 'data_vencimento' => $termino, 'ativo' => true,
        ], $ct));

        return $c;
    }

    private function ciclo(array $dados): ContratoRenovacao
    {
        return ContratoRenovacao::create(array_merge(['termino_capturado' => '2027-01-01'], $dados));
    }

    private function nomes(string $url): array
    {
        $resp = $this->actingAs($this->admin())->get($url);
        $resp->assertOk();

        return collect($resp->viewData('page')['props']['linhas']['data'])->pluck('company_nome')->sort()->values()->all();
    }

    private function cenarioFiltros(): array
    {
        $a = $this->empresa('2026-11-20', 'Venc30');      // 14 dias
        $b = $this->empresa('2027-01-05', 'Venc2m');      // na janela, > 30 dias
        $c = $this->empresa('2026-10-01', 'Vencida');
        $d = $this->empresa(null, 'SemTermino');
        $e = $this->empresa('2027-06-01', 'Renovada');
        $f = $this->empresa('2027-08-01', 'ComErro');
        $g = $this->empresa('2027-08-01', 'Revisao');
        $h = $this->empresa('2027-08-01', 'Aguardando');

        $mk = fn (Company $co, array $x) => $this->ciclo(array_merge([
            'company_id' => $co->id, 'servico_id' => $this->servico->id, 'numero_ciclo' => 1,
            'chave_ciclo' => "c{$co->id}:s{$this->servico->id}:1", 'chave_correlacao' => "k{$co->id}",
            'status_comercial' => 'identificado', 'status_integracao' => 'pendente_sincronizacao',
        ], $x));

        $mk($e, ['status_comercial' => 'renovado', 'status_integracao' => 'sincronizado', 'renovado_em' => now()]);
        $mk($f, ['status_integracao' => 'erro', 'ultimo_erro' => 'falhou']);
        $mk($g, ['status_integracao' => 'revisao_manual']);
        $mk($h, ['status_integracao' => 'resultado_incerto']);

        return compact('a', 'b', 'c', 'd', 'e', 'f', 'g', 'h');
    }

    public function test_props_de_renovacao_presentes_nos_dois_ramos(): void
    {
        $com = $this->empresa('2026-11-20', 'ComContrato');
        ContratoAssinatura::factory()->create(['company_id' => $com->id, 'servico_id' => $this->servico->id]);
        $this->empresa('2026-11-20', 'SemContrato');

        $resp = $this->actingAs($this->admin())->get(route('admin.contratos.index'));
        $props = $resp->viewData('page')['props'];

        $this->assertCount(2, $props['linhas']['data']);
        foreach ($props['linhas']['data'] as $l) {
            $this->assertArrayHasKey('renovacao', $l);
            $this->assertSame(14, $l['renovacao']['dias_restantes']);
            $this->assertSame('manual', $l['renovacao']['termino_origem']);
        }
        $this->assertSame(['ativa', 'simulacao', 'antecedencia_meses'], array_keys($props['renovacao_config']));
        $this->assertCount(8, $props['renovacao_resumo']);
    }

    public function test_cada_filtro_devolve_so_as_linhas_certas(): void
    {
        $this->cenarioFiltros();

        $this->assertSame(['Venc30'], $this->nomes(route('admin.contratos.index', ['renovacao' => 'vence_30d'])));
        $this->assertSame(['Venc2m', 'Venc30'], $this->nomes(route('admin.contratos.index', ['renovacao' => 'vence_2m'])));
        $this->assertSame(['Vencida'], $this->nomes(route('admin.contratos.index', ['renovacao' => 'vencido'])));
        $this->assertSame(['Renovada'], $this->nomes(route('admin.contratos.index', ['renovacao' => 'renovado'])));
        $this->assertSame(['SemTermino'], $this->nomes(route('admin.contratos.index', ['renovacao' => 'sem_termino'])));
        $this->assertSame(['ComErro'], $this->nomes(route('admin.contratos.index', ['renovacao' => 'erro_sync'])));
        $this->assertSame(['Revisao'], $this->nomes(route('admin.contratos.index', ['renovacao' => 'revisao_manual'])));
        $this->assertSame(['Aguardando'], $this->nomes(route('admin.contratos.index', ['renovacao' => 'aguardando_conciliacao'])));
    }

    public function test_filtro_invalido_e_ignorado_e_resumo_de_7_status_nao_muda(): void
    {
        $this->cenarioFiltros();

        $semFiltro = $this->actingAs($this->admin())->get(route('admin.contratos.index'))->viewData('page')['props'];
        $comFiltro = $this->actingAs($this->admin())->get(route('admin.contratos.index', ['renovacao' => 'vencido']))->viewData('page')['props'];
        $lixo      = $this->actingAs($this->admin())->get(route('admin.contratos.index', ['renovacao' => 'xxx']))->viewData('page')['props'];

        $this->assertSame($semFiltro['resumo'], $comFiltro['resumo']);
        $this->assertCount(7, $comFiltro['resumo']);
        $this->assertSame($semFiltro['renovacao_resumo'], $comFiltro['renovacao_resumo']);
        $this->assertNull($lixo['filters']['renovacao']);
        $this->assertSame($semFiltro['linhas']['total'], $lixo['linhas']['total']);
        $this->assertSame(8, $semFiltro['linhas']['total']);
    }

    public function test_links_de_negocio_ficam_separados(): void
    {
        config(['services.hubspot.portal_id' => '555']);
        $c = $this->empresa('2026-11-20', 'Links');
        $c->forceFill(['hubspot_deal_id' => 'ORIG-1'])->save();

        $l = $this->actingAs($this->admin())->get(route('admin.contratos.index'))
            ->viewData('page')['props']['linhas']['data'][0]['renovacao'];
        $this->assertSame('https://app.hubspot.com/contacts/555/record/0-3/ORIG-1', $l['negocio_original_url']);
        $this->assertNull($l['negocio_renovacao_id']);
        $this->assertNull($l['negocio_renovacao_url']);

        $this->ciclo([
            'company_id' => $c->id, 'servico_id' => $this->servico->id, 'numero_ciclo' => 1,
            'chave_ciclo' => 'x1', 'chave_correlacao' => 'k1', 'negocio_renovacao_id' => 'REN-9',
            'status_comercial' => 'identificado', 'status_integracao' => 'sincronizado',
        ]);
        $l = $this->actingAs($this->admin())->get(route('admin.contratos.index'))
            ->viewData('page')['props']['linhas']['data'][0]['renovacao'];
        $this->assertSame('ORIG-1', $l['negocio_original_id']);
        $this->assertSame('REN-9', $l['negocio_renovacao_id']);
        $this->assertStringContainsString('REN-9', $l['negocio_renovacao_url']);
    }

    public function test_sem_permissao_recebe_403_nas_tres_rotas(): void
    {
        $c = $this->empresa('2026-11-20', 'X');
        $ciclo = $this->ciclo([
            'company_id' => $c->id, 'servico_id' => $this->servico->id, 'numero_ciclo' => 1,
            'chave_ciclo' => 'y1', 'chave_correlacao' => 'ky1',
            'status_comercial' => 'identificado', 'status_integracao' => 'pendente_sincronizacao',
        ]);
        $u = User::factory()->create(['role' => 'consultor']);
        Queue::fake();

        $this->actingAs($u)->post(route('admin.contratos.renovacao.sincronizar', [$c->id, $this->servico->id]))->assertForbidden();
        $this->actingAs($u)->post(route('admin.contratos.renovacao.dispensar', $ciclo->id), ['justificativa' => 'x'])->assertForbidden();
        $this->actingAs($u)->get(route('admin.contratos.renovacao.historico', [$c->id, $this->servico->id]))->assertForbidden();
        Queue::assertNothingPushed();
        $this->assertSame('identificado', $ciclo->fresh()->status_comercial);
    }

    public function test_sincronizar_na_janela_enfileira_job_manual_com_o_usuario(): void
    {
        Queue::fake();
        $c = $this->empresa('2026-12-20', 'NaJanela');
        $admin = $this->admin();

        $this->actingAs($admin)
            ->post(route('admin.contratos.renovacao.sincronizar', [$c->id, $this->servico->id]))
            ->assertRedirect();

        Queue::assertPushed(SincronizarRenovacaoJob::class, fn ($j) => $j->origem === 'manual'
            && $j->userId === $admin->id && $j->liberarCriacao === false);
    }

    public function test_sincronizar_fora_da_janela_da_erro_e_nao_enfileira(): void
    {
        Queue::fake();
        $c = $this->empresa('2027-08-20', 'Longe');

        $this->actingAs($this->admin())
            ->post(route('admin.contratos.renovacao.sincronizar', [$c->id, $this->servico->id]))
            ->assertSessionHas('error');

        Queue::assertNothingPushed();
    }

    public function test_sincronizar_vencido_enfileira(): void
    {
        Queue::fake();
        $c = $this->empresa('2026-10-01', 'Vencido');

        $this->actingAs($this->admin())
            ->post(route('admin.contratos.renovacao.sincronizar', [$c->id, $this->servico->id]))
            ->assertSessionHasNoErrors();

        Queue::assertPushed(SincronizarRenovacaoJob::class);
    }

    public function test_liberar_criacao_exige_justificativa(): void
    {
        Queue::fake();
        $c = $this->empresa('2026-12-20', 'Liberar');
        $url = route('admin.contratos.renovacao.sincronizar', [$c->id, $this->servico->id]);

        $this->actingAs($this->admin())->post($url, ['liberar_criacao' => true])->assertSessionHasErrors('justificativa');
        Queue::assertNothingPushed();

        $this->actingAs($this->admin())
            ->post($url, ['liberar_criacao' => true, 'justificativa' => 'Conferi no HubSpot, nada existe.'])
            ->assertSessionHasNoErrors();
        Queue::assertPushed(SincronizarRenovacaoJob::class, fn ($j) => $j->liberarCriacao === true
            && $j->justificativa === 'Conferi no HubSpot, nada existe.');
    }

    public function test_dispensar_sem_justificativa_falha_e_com_justificativa_grava_evento(): void
    {
        $c = $this->empresa('2026-12-20', 'Dispensa');
        $ciclo = $this->ciclo([
            'company_id' => $c->id, 'servico_id' => $this->servico->id, 'numero_ciclo' => 1,
            'chave_ciclo' => 'd1', 'chave_correlacao' => 'kd1',
            'status_comercial' => 'identificado', 'status_integracao' => 'pendente_sincronizacao',
        ]);
        $admin = $this->admin();
        $url = route('admin.contratos.renovacao.dispensar', $ciclo->id);

        $this->actingAs($admin)->post($url, [])->assertSessionHasErrors('justificativa');
        $this->assertSame('identificado', $ciclo->fresh()->status_comercial);

        $this->actingAs($admin)->post($url, ['justificativa' => 'Cliente encerrou a operação.'])->assertSessionHasNoErrors();

        $f = $ciclo->fresh();
        $this->assertSame('dispensado', $f->status_comercial);
        $this->assertSame($admin->id, (int) $f->dispensado_por);
        $this->assertNotNull($f->dispensado_em);
        $this->assertSame('Cliente encerrou a operação.', $f->dispensa_justificativa);
        $ev = $f->eventos()->where('tipo', 'dispensado')->first();
        $this->assertNotNull($ev);
        $this->assertSame($admin->id, (int) $ev->user_id);

        // Ciclo já encerrado não dispensa de novo.
        $this->actingAs($admin)->post($url, ['justificativa' => 'Outra tentativa qualquer.'])->assertSessionHas('error');
    }

    public function test_historico_nao_expoe_chaves_fora_da_whitelist(): void
    {
        $c = $this->empresa('2026-12-20', 'Historico');
        $ciclo = $this->ciclo([
            'company_id' => $c->id, 'servico_id' => $this->servico->id, 'numero_ciclo' => 1,
            'chave_ciclo' => 'h1', 'chave_correlacao' => 'kh1',
            'status_comercial' => 'identificado', 'status_integracao' => 'pendente_sincronizacao',
        ]);
        $ciclo->registrarEvento('simulacao', 'manual', 'criar', [
            'motivo' => 'ok', 'negocio_original_id' => 'D1', 'email' => 'secreto@x.com', 'token' => 'abc',
        ], true, $this->admin()->id);

        $json = $this->actingAs($this->admin())
            ->getJson(route('admin.contratos.renovacao.historico', [$c->id, $this->servico->id]))
            ->assertOk()->json();

        $this->assertCount(1, $json['ciclos']);
        $this->assertSame(['motivo' => 'ok', 'negocio_original_id' => 'D1'], $json['eventos'][0]['dados']);
        $this->assertStringNotContainsString('secreto', json_encode($json));
    }
}
