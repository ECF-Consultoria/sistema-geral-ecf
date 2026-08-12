<?php

namespace Tests\Feature\Phase136;

use App\Models\Company;
use App\Models\DesempenhoCompanyScoreSnapshot;
use App\Models\DesempenhoMetricaManual;
use App\Models\Servico;
use App\Models\User;
use App\Services\Desempenho\CompanyScoreSnapshotWriter;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\V16\CriaCenarioResponsaveis;
use Tests\TestCase;

/**
 * Fase 136 Plano 04 — cobertura HTTP fim-a-fim das duas rotas admin-only de
 * lançamento manual: acesso (T-136-01), whitelist e limites de valor
 * (T-136-04/05), empresa inativa (T-136-06), trava de competência
 * consolidada (T-136-02) e o ciclo auto → manual → auto (D-01/D-02/D-12).
 *
 * Toda asserção de escrita e de recusa é feita por RECONSULTA AO BANCO, nunca
 * pelo conteúdo do flash (learnings §4 — uma operação pode parecer
 * bem-sucedida na tela sem ter gravado o que deveria).
 *
 * `Http::preventStrayRequests()` no `setUp()`: nenhum cenário desta suíte pode
 * disparar HTTP à Adman. A grade só resolve valor de API para célula COM
 * lançamento, e o único cenário com lançamento ativo usa fonte `shopee`
 * (leitura 100% local de `shopee_metrics`).
 *
 * @see .planning/phases/136-.../136-04-PLAN.md
 * @see app/Http/Controllers/DesempenhoMetricasManuaisController.php
 */
class MetricaManualRotaAdminTest extends TestCase
{
    use RefreshDatabase;
    use CriaCenarioResponsaveis;

    private const MES = '2026-08';

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-08-11 10:00:00'));

        Http::preventStrayRequests();

        // A página `Desempenho/MetricasManuais.jsx` é entrega do Plano 05 —
        // este plano só cria rota e controller. Sem isto, o `@vite` do
        // `app.blade.php` aborta o GET procurando o JSX no manifest, e o teste
        // falharia por uma ausência que não é a que ele mede.
        $this->withoutVite();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ═══ Fixtures ══════════════════════════════════════════════════════════

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin', 'active' => true]);
    }

    /** Empresa ativa com vínculo Shopee — entra no universo da grade sem custo de HTTP. */
    private function empresaComVinculoShopee(?string $nome = null): Company
    {
        $empresa = Company::factory()->create(array_filter([
            'active' => true,
            'name'   => $nome,
        ], fn ($v) => $v !== null));

        $user    = User::factory()->create(['role' => 'consultor', 'active' => true]);
        $servico = $this->criarServico(Servico::SETOR_SHOPEE, true);
        $this->inserirPivot($empresa->id, $user->id, 'consultor', $servico);

        return $empresa;
    }

    private function congelarCompetencia(string $mes): void
    {
        DesempenhoCompanyScoreSnapshot::create([
            'user_id'               => User::factory()->create(['role' => 'consultor'])->id,
            'company_id'            => Company::factory()->create()->id,
            'mes_referencia'        => $mes . '-01',
            'company_name'          => 'Empresa congelada',
            'fonte_financeira'      => 'adman',
            'status'                => 'complete',
            'componentes_presentes' => 3,
            'origem'                => CompanyScoreSnapshotWriter::ORIGEM_CONSOLIDAR_MES,
            'gerado_em'             => now(),
        ]);
    }

    /** @param array<string, mixed> $sobrescrever */
    private function payload(int $companyId, array $sobrescrever = []): array
    {
        return array_merge([
            'company_id'     => $companyId,
            // As empresas desta suíte têm vínculo Shopee — o canal precisa
            // bater com o que a empresa realmente atende, senão o FormRequest
            // recusa (o valor ficaria gravado e invisível para todo mundo).
            'fonte'          => DesempenhoMetricaManual::FONTE_SHOPEE,
            'mes_referencia' => self::MES,
            'metrica'        => DesempenhoMetricaManual::METRICA_FATURAMENTO,
            'valor'          => 1000.00,
            'ativo'          => true,
        ], $sobrescrever);
    }

    private function celula(int $companyId, string $metrica): ?DesempenhoMetricaManual
    {
        return DesempenhoMetricaManual::query()
            ->where('company_id', $companyId)
            ->where('metrica', $metrica)
            ->first();
    }

    // ═══ T-136-01: acesso ══════════════════════════════════════════════════

    #[Test]
    public function consultor_autenticado_recebe_403_no_get_e_no_post(): void
    {
        $consultor = User::factory()->create(['role' => 'consultor', 'active' => true]);
        $empresa   = $this->empresaComVinculoShopee();

        $this->actingAs($consultor)
            ->get(route('desempenho.metricas-manuais.index'))
            ->assertForbidden();

        $this->actingAs($consultor)
            ->postJson(route('desempenho.metricas-manuais.lancar'), $this->payload($empresa->id))
            ->assertForbidden();

        // Reconsulta ao banco: a recusa não gravou nada.
        $this->assertSame(0, DesempenhoMetricaManual::count());
    }

    #[Test]
    public function visitante_nao_autenticado_e_redirecionado_para_login_nas_duas_rotas(): void
    {
        $empresa = $this->empresaComVinculoShopee();

        $this->get(route('desempenho.metricas-manuais.index'))
            ->assertRedirect(route('login'));

        $this->post(route('desempenho.metricas-manuais.lancar'), $this->payload($empresa->id))
            ->assertRedirect(route('login'));

        $this->assertSame(0, DesempenhoMetricaManual::count());
    }

    #[Test]
    public function admin_recebe_200_no_get_e_a_pagina_inertia_esperada(): void
    {
        $this->empresaComVinculoShopee('Loja Shopee Alfa');

        $response = $this->actingAs($this->admin())
            ->get(route('desempenho.metricas-manuais.index'));

        $response->assertOk();
        $response->assertInertia(fn (AssertableInertia $page) => $page
            // `shouldExist: false` — o nome do componente é o contrato que
            // este plano entrega; o arquivo `.jsx` é do Plano 05.
            ->component('Desempenho/MetricasManuais', false)
            ->where('mes', self::MES)
            ->where('consolidada', false)
            ->has('meses')
            ->has('metricas', 2)
            ->has('empresas', 1)
            ->where('empresas.0.company_name', 'Loja Shopee Alfa')
            ->where('empresas.0.faturamento.ativo', false)
            ->where('empresas.0.margem_cmv.ativo', false)
        );
    }

    #[Test]
    public function empresa_sem_vinculo_financeiro_ou_inativa_nao_entra_na_grade(): void
    {
        $this->empresaComVinculoShopee('Loja Elegivel');

        // Vínculo em setor sem fonte financeira (Polos) — nunca produz linha
        // de Desempenho, não tem por que aparecer na grade.
        $semFonte = Company::factory()->create(['active' => true, 'name' => 'Loja Polos']);
        $this->inserirPivot(
            $semFonte->id,
            User::factory()->create(['role' => 'consultor'])->id,
            'consultor',
            $this->criarServico(Servico::SETOR_POLOS, true)
        );

        // Empresa inativa com vínculo elegível.
        $inativa = Company::factory()->create(['active' => false, 'name' => 'Loja Inativa']);
        $this->inserirPivot(
            $inativa->id,
            User::factory()->create(['role' => 'consultor'])->id,
            'consultor',
            $this->criarServico(Servico::SETOR_PERFORMANCE, true)
        );

        $this->actingAs($this->admin())
            ->get(route('desempenho.metricas-manuais.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('empresas', 1)
                ->where('empresas.0.company_name', 'Loja Elegivel')
                ->etc()
            );
    }

    #[Test]
    public function empresa_com_dois_vinculos_do_mesmo_setor_aparece_uma_unica_vez(): void
    {
        // `company_users` tem VÁRIAS linhas por (empresa, papel) desde a Fase
        // 76 — uma por serviço. Sem `distinct`, a mesma empresa duplicaria.
        $empresa = Company::factory()->create(['active' => true, 'name' => 'Loja Duplicada']);

        foreach (range(1, 2) as $i) {
            $this->inserirPivot(
                $empresa->id,
                User::factory()->create(['role' => 'consultor'])->id,
                'consultor',
                $this->criarServico(Servico::SETOR_SHOPEE, true)
            );
        }

        $this->actingAs($this->admin())
            ->get(route('desempenho.metricas-manuais.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->has('empresas', 1)->etc());
    }

    // ═══ D-09: competência consolidada listada, marcada e read-only ════════

    #[Test]
    public function competencia_consolidada_continua_listada_e_vem_marcada_como_read_only(): void
    {
        $this->empresaComVinculoShopee();
        $this->congelarCompetencia(self::MES);

        $this->actingAs($this->admin())
            ->get(route('desempenho.metricas-manuais.index', ['mes' => self::MES]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('mes', self::MES)
                ->where('consolidada', true)
                ->has('empresas', 1)
                ->etc()
            );
    }

    #[Test]
    public function post_em_competencia_consolidada_devolve_422_e_nao_grava_nada(): void
    {
        $empresa = $this->empresaComVinculoShopee();
        $this->congelarCompetencia(self::MES);

        $this->actingAs($this->admin())
            ->postJson(route('desempenho.metricas-manuais.lancar'), $this->payload($empresa->id))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['mes_referencia']);

        // Reconsulta ao banco — a recusa é o produto, não a mensagem.
        $this->assertSame(0, DesempenhoMetricaManual::count());
    }

    // ═══ T-136-04/05/06: entrada não confiável ═════════════════════════════

    #[Test]
    public function post_com_metrica_fora_da_whitelist_devolve_422(): void
    {
        $empresa = $this->empresaComVinculoShopee();

        $this->actingAs($this->admin())
            ->postJson(route('desempenho.metricas-manuais.lancar'), $this->payload($empresa->id, [
                'metrica' => 'inexistente',
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['metrica']);

        $this->assertSame(0, DesempenhoMetricaManual::count());
    }

    #[Test]
    public function post_com_valor_negativo_devolve_422(): void
    {
        $empresa = $this->empresaComVinculoShopee();

        $this->actingAs($this->admin())
            ->postJson(route('desempenho.metricas-manuais.lancar'), $this->payload($empresa->id, [
                'valor' => -1,
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['valor']);

        $this->assertSame(0, DesempenhoMetricaManual::count());
    }

    #[Test]
    public function post_com_valor_acima_do_teto_devolve_422(): void
    {
        $empresa = $this->empresaComVinculoShopee();

        $this->actingAs($this->admin())
            ->postJson(route('desempenho.metricas-manuais.lancar'), $this->payload($empresa->id, [
                'valor' => 100000000,
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['valor']);

        $this->assertSame(0, DesempenhoMetricaManual::count());
    }

    #[Test]
    public function post_em_empresa_inativa_devolve_422(): void
    {
        $inativa = Company::factory()->create(['active' => false]);

        $this->actingAs($this->admin())
            ->postJson(route('desempenho.metricas-manuais.lancar'), $this->payload($inativa->id))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['company_id']);

        $this->assertSame(0, DesempenhoMetricaManual::count());
    }

    // ═══ Ciclo auto → manual → auto (D-01/D-02/D-12) ═══════════════════════

    #[Test]
    public function ciclo_completo_lanca_edita_e_reverte_preservando_a_linha(): void
    {
        $admin   = $this->admin();
        $empresa = $this->empresaComVinculoShopee();
        $metrica = DesempenhoMetricaManual::METRICA_FATURAMENTO;

        // 1) Lançar.
        $this->actingAs($admin)
            ->postJson(route('desempenho.metricas-manuais.lancar'), $this->payload($empresa->id, ['valor' => 1000]))
            ->assertRedirect();

        $linha = $this->celula($empresa->id, $metrica);
        $this->assertNotNull($linha, 'O primeiro POST precisa criar a linha da célula.');
        $this->assertTrue($linha->ativo);
        $this->assertEquals(1000.0, (float) $linha->valor);
        $this->assertNull($linha->valor_anterior);
        $this->assertSame($admin->id, $linha->lancado_por);
        $this->assertNotNull($linha->lancado_em);
        $this->assertSame(self::MES, $linha->mes_referencia->format('Y-m'));

        // 2) Editar — `valor_anterior` recebe o valor que estava lá.
        $this->actingAs($admin)
            ->postJson(route('desempenho.metricas-manuais.lancar'), $this->payload($empresa->id, ['valor' => 1500]))
            ->assertRedirect();

        $this->assertSame(1, DesempenhoMetricaManual::count(), 'A edição precisa atualizar a MESMA linha, nunca criar outra.');

        $linha = $this->celula($empresa->id, $metrica);
        $this->assertTrue($linha->ativo);
        $this->assertEquals(1500.0, (float) $linha->valor);
        $this->assertEquals(1000.0, (float) $linha->valor_anterior);

        // 3) Reverter para automático — a linha CONTINUA existindo (D-12).
        $this->actingAs($admin)
            ->postJson(route('desempenho.metricas-manuais.lancar'), [
                'company_id'     => $empresa->id,
                'fonte'          => DesempenhoMetricaManual::FONTE_SHOPEE,
                'mes_referencia' => self::MES,
                'metrica'        => $metrica,
                'ativo'          => false,
            ])
            ->assertRedirect();

        $this->assertSame(1, DesempenhoMetricaManual::count(), 'Reverter para auto NUNCA apaga a linha.');

        $linha = $this->celula($empresa->id, $metrica);
        $this->assertFalse($linha->ativo);
        $this->assertEquals(1500.0, (float) $linha->valor, 'O valor lançado sobrevive à reversão.');
        $this->assertEquals(1500.0, (float) $linha->valor_anterior);
    }

    #[Test]
    public function os_dois_eixos_da_mesma_empresa_alternam_de_forma_independente(): void
    {
        $admin   = $this->admin();
        $empresa = $this->empresaComVinculoShopee();

        $this->actingAs($admin)
            ->postJson(route('desempenho.metricas-manuais.lancar'), $this->payload($empresa->id, [
                'metrica' => DesempenhoMetricaManual::METRICA_MARGEM_CMV,
                'valor'   => 700,
            ]))
            ->assertRedirect();

        // D-07: só o eixo da margem virou manual; o faturamento continua auto.
        $this->assertSame(1, DesempenhoMetricaManual::count());
        $this->assertNull($this->celula($empresa->id, DesempenhoMetricaManual::METRICA_FATURAMENTO));

        $cmv = $this->celula($empresa->id, DesempenhoMetricaManual::METRICA_MARGEM_CMV);
        $this->assertTrue($cmv->ativo);
        $this->assertEquals(700.0, (float) $cmv->valor);
    }

    // ═══ T-136-08: o autor não vaza nas props da grade ═════════════════════

    #[Test]
    public function a_prop_empresas_nao_expoe_lancado_por_nem_nome_de_usuario(): void
    {
        $admin   = $this->admin();
        $empresa = $this->empresaComVinculoShopee();

        $this->actingAs($admin)
            ->postJson(route('desempenho.metricas-manuais.lancar'), $this->payload($empresa->id, ['valor' => 4321]))
            ->assertRedirect();

        // Sanity: o autor EXISTE no banco (D-12) — a auditoria depende disso.
        $this->assertSame($admin->id, $this->celula($empresa->id, DesempenhoMetricaManual::METRICA_FATURAMENTO)->lancado_por);

        $response = $this->actingAs($admin)->get(route('desempenho.metricas-manuais.index'));
        $response->assertOk();

        $empresasJson = json_encode($response->viewData('page')['props']['empresas']);

        $this->assertStringNotContainsString('lancado_por', $empresasJson);
        $this->assertStringNotContainsString($admin->name, $empresasJson);
        // O valor lançado, esse sim, precisa aparecer.
        $this->assertStringContainsString('4321', $empresasJson);
    }
}
