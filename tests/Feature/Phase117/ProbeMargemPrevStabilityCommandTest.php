<?php

namespace Tests\Feature\Phase117;

use App\Models\AdmanProbeMargemPrevLeitura;
use App\Models\AdmanProbeMargemPrevVeredito;
use App\Models\Company;
use App\Models\Servico;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Spatie\Activitylog\Traits\LogsActivity;
use Tests\Feature\V16\CriaCenarioResponsaveis;
use Tests\TestCase;

/**
 * Suite de mecânica do probe de estabilidade de `percentageMargin.prev`
 * (Fase 117, Plano 02 — gate MPP-04).
 *
 * Cobre schema/models (Task 1), o modo de leitura `adman:probe-margem-prev`
 * (Task 2) e o modo `--relatorio` (Task 3). Todos os cenários com HTTP usam
 * `Http::fake()` — nenhum teste chama a Adman real (D-11 é resolvido fora
 * desta suíte, na VPS). Toda conclusão é verificada por reconsulta ao banco
 * via Eloquent, nunca por `expectsOutput()` (D-10).
 *
 * @see .planning/phases/117-margem-em-pontos-percentuais-probe-de-estabilidade-de-prev/117-02-PLAN.md
 */
class ProbeMargemPrevStabilityCommandTest extends TestCase
{
    use RefreshDatabase;
    use CriaCenarioResponsaveis;

    // ─────────────────────── Task 1 — schema/models ───────────────────────

    public function test_tabelas_existem_com_as_colunas_esperadas(): void
    {
        $this->assertTrue(Schema::hasTable('adman_probe_margem_prev_leituras'));
        $this->assertTrue(Schema::hasTable('adman_probe_margem_prev_vereditos'));

        $this->assertTrue(Schema::hasColumns('adman_probe_margem_prev_leituras', [
            'id', 'company_id', 'periodo_key', 'lida_em', 'janela_esperada',
            'value', 'prev', 'diff_nativo', 'margem_var_pp', 'nota_regua',
            'leitura_hash', 'http_falhou', 'created_at', 'updated_at',
        ]));

        $this->assertTrue(Schema::hasColumns('adman_probe_margem_prev_vereditos', [
            'id', 'periodo_key', 'gerado_em', 'total_leituras', 'total_empresas',
            'total_rodadas', 'cobertura_prev', 'empresas_com_flip_count',
            'empresas_com_flip', 'veredito', 'motivos', 'created_at', 'updated_at',
        ]));
    }

    public function test_precisao_de_6_casas_decimais_sobrevive_a_ida_e_volta_do_banco(): void
    {
        $company = Company::factory()->create();

        $leitura = AdmanProbeMargemPrevLeitura::create([
            'company_id'  => $company->id,
            'periodo_key' => '2026-06',
            'lida_em'     => now(),
            'value'       => 27.471234,
            'prev'        => 24.081111,
        ]);

        $recarregada = AdmanProbeMargemPrevLeitura::findOrFail($leitura->id);

        // A proteção real: 6 casas decimais NÃO podem ser achatadas para 2 —
        // isso fabricaria identidade bit-a-bit e quebraria a checagem anti-cache.
        $this->assertNotEquals(27.47, $recarregada->value);
        $this->assertNotEquals(24.08, $recarregada->prev);
        $this->assertEqualsWithDelta(27.471234, $recarregada->value, 0.0000001);
        $this->assertEqualsWithDelta(24.081111, $recarregada->prev, 0.0000001);
    }

    public function test_casts_do_model_de_leitura(): void
    {
        $company = Company::factory()->create();

        $leitura = AdmanProbeMargemPrevLeitura::create([
            'company_id'    => $company->id,
            'periodo_key'   => '2026-06',
            'lida_em'       => '2026-07-27 11:15:00',
            'value'         => 27.47,
            'prev'          => 24.08,
            'diff_nativo'   => 14.09,
            'margem_var_pp' => 3.39,
            'nota_regua'    => 4,
            'http_falhou'   => false,
        ]);

        $recarregada = AdmanProbeMargemPrevLeitura::findOrFail($leitura->id);

        $this->assertInstanceOf(\Carbon\Carbon::class, $recarregada->lida_em);
        $this->assertIsFloat($recarregada->value);
        $this->assertIsFloat($recarregada->prev);
        $this->assertIsFloat($recarregada->diff_nativo);
        $this->assertIsFloat($recarregada->margem_var_pp);
        $this->assertIsInt($recarregada->nota_regua);
        $this->assertIsBool($recarregada->http_falhou);
    }

    public function test_casts_do_model_de_veredito(): void
    {
        $veredito = AdmanProbeMargemPrevVeredito::create([
            'periodo_key'             => '2026-06',
            'gerado_em'               => now(),
            'total_leituras'          => 10,
            'total_empresas'          => 2,
            'total_rodadas'           => 5,
            'cobertura_prev'          => 1.0,
            'empresas_com_flip_count' => 1,
            'empresas_com_flip'       => [['company_id' => 1, 'company_name' => 'X', 'notas' => [3, 4]]],
            'veredito'                => AdmanProbeMargemPrevVeredito::VEREDITO_REPROVADO,
            'motivos'                 => ['flip de nota detectado'],
        ]);

        $recarregado = AdmanProbeMargemPrevVeredito::findOrFail($veredito->id);

        $this->assertIsArray($recarregado->empresas_com_flip);
        $this->assertIsArray($recarregado->motivos);
        $this->assertInstanceOf(\Carbon\Carbon::class, $recarregado->gerado_em);
    }

    public function test_http_falhou_default_false_e_aceita_value_prev_nulos(): void
    {
        $company = Company::factory()->create();

        $leitura = AdmanProbeMargemPrevLeitura::create([
            'company_id'  => $company->id,
            'periodo_key' => '2026-06',
            'lida_em'     => now(),
        ]);

        $this->assertFalse($leitura->fresh()->http_falhou);
        $this->assertNull($leitura->fresh()->value);
        $this->assertNull($leitura->fresh()->prev);
    }

    public function test_relacao_company_devolve_a_empresa_correta(): void
    {
        $company = Company::factory()->create();

        $leitura = AdmanProbeMargemPrevLeitura::create([
            'company_id'  => $company->id,
            'periodo_key' => '2026-06',
            'lida_em'     => now(),
        ]);

        $this->assertTrue($leitura->company->is($company));
    }

    public function test_model_de_leitura_nao_usa_logs_activity(): void
    {
        $this->assertFalse(in_array(
            LogsActivity::class,
            class_uses_recursive(AdmanProbeMargemPrevLeitura::class),
            true
        ));
    }
}
