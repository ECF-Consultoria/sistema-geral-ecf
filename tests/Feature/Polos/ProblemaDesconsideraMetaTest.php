<?php

namespace Tests\Feature\Polos;

use App\Http\Controllers\PolosController;
use App\Models\MlbEmpresa;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Quick 260805-dzu — ter problema deixou de tirar a empresa da meta por si só.
 *
 * Só sai da meta (status 'Problema' na Distribuição de status do Painel Polos)
 * quem foi marcado explicitamente com `problema_desconsidera_meta`. Problemas
 * básicos seguem contando em No alvo / Em progresso / Não.
 *
 * @group polos
 */
class ProblemaDesconsideraMetaTest extends TestCase
{
    use RefreshDatabase;

    private function empresa(array $opts = []): MlbEmpresa
    {
        return MlbEmpresa::create(array_merge([
            'nome'    => 'Loja ' . Str::random(4),
            'tipo'    => 'POLO',
            'projeto' => 'POLOS',
            'fase'    => 'M2',
            'polo'    => 'Arapongas',
            'estagio' => 'Não Listado',
        ], $opts));
    }

    /** Chama o helper privado que decide se a empresa sai da meta. */
    private function desconsidera(array $ativo): bool
    {
        $m = new ReflectionMethod(PolosController::class, 'desconsideraDaMeta');
        $m->setAccessible(true);

        return $m->invoke(app(PolosController::class), $ativo);
    }

    /** Chama o cálculo de status usado pela Distribuição. */
    private function statusDe(array $ativo, float $fat, float $limiar): string
    {
        $m = new ReflectionMethod(PolosController::class, 'calcularStatus');
        $m->setAccessible(true);

        return $m->invoke(app(PolosController::class), $this->desconsidera($ativo), $fat, $limiar);
    }

    // ─── Regra de cálculo ────────────────────────────────────────────────────

    public function test_problema_sem_desconsiderar_continua_contando_pra_meta(): void
    {
        $ativo = ['problema' => true, 'problema_desconsidera_meta' => false];

        $this->assertFalse($this->desconsidera($ativo));
        // Faturamento acima do limiar → No alvo, apesar do problema.
        $this->assertSame('Sim', $this->statusDe($ativo, 5000, 1000));
        // Abaixo do limiar → Em progresso, também dentro da meta.
        $this->assertSame('Em progresso', $this->statusDe($ativo, 500, 1000));
        // Zerado → Não (e não 'Problema').
        $this->assertSame('Não', $this->statusDe($ativo, 0, 1000));
    }

    public function test_problema_marcado_como_desconsiderar_sai_da_meta(): void
    {
        $ativo = ['problema' => true, 'problema_desconsidera_meta' => true];

        $this->assertTrue($this->desconsidera($ativo));
        // Precedência máxima: mesmo faturando acima do limiar, fica em 'Problema'.
        $this->assertSame('Problema', $this->statusDe($ativo, 5000, 1000));
    }

    public function test_flag_de_meta_sozinho_nao_tira_da_meta(): void
    {
        // Sem problema aberto o flag é inerte (o remover zera os dois, mas o
        // roster histórico do CSV também chega sem nenhum dos campos).
        $this->assertFalse($this->desconsidera(['problema' => false, 'problema_desconsidera_meta' => true]));
        $this->assertFalse($this->desconsidera([]));
    }

    // ─── Persistência pela tela (rota do Painel Polos) ───────────────────────

    public function test_marcar_problema_nasce_contando_pra_meta(): void
    {
        $empresa = $this->empresa();

        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->patch(route('mlb.empresas.problema', $empresa->id), ['acao' => 'toggle'])
            ->assertRedirect();

        $empresa->refresh();
        $this->assertTrue($empresa->problema);
        $this->assertFalse($empresa->problema_desconsidera_meta);
    }

    public function test_marcar_problema_pedindo_para_tirar_da_meta(): void
    {
        $empresa = $this->empresa();

        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->patch(route('mlb.empresas.problema', $empresa->id), [
                'acao'              => 'toggle',
                'desconsidera_meta' => true,
                'problema_nota'     => 'Conta suspensa',
            ])
            ->assertRedirect();

        $empresa->refresh();
        $this->assertTrue($empresa->problema);
        $this->assertTrue($empresa->problema_desconsidera_meta);
        $this->assertSame('Conta suspensa', $empresa->problema_nota);
    }

    public function test_acao_meta_alterna_sem_mexer_no_problema(): void
    {
        $empresa = $this->empresa(['problema' => true, 'problema_nota' => 'Sem acesso']);
        $admin   = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)
            ->patch(route('mlb.empresas.problema', $empresa->id), ['acao' => 'meta', 'desconsidera_meta' => true])
            ->assertRedirect();

        $empresa->refresh();
        $this->assertTrue($empresa->problema);
        $this->assertTrue($empresa->problema_desconsidera_meta);
        $this->assertSame('Sem acesso', $empresa->problema_nota);

        $this->actingAs($admin)
            ->patch(route('mlb.empresas.problema', $empresa->id), ['acao' => 'meta', 'desconsidera_meta' => false])
            ->assertRedirect();

        $this->assertTrue($empresa->refresh()->problema);
        $this->assertFalse($empresa->problema_desconsidera_meta);
    }

    public function test_remover_problema_zera_o_flag_de_meta(): void
    {
        $empresa = $this->empresa(['problema' => true, 'problema_desconsidera_meta' => true]);

        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->patch(route('mlb.empresas.problema', $empresa->id), ['acao' => 'remover'])
            ->assertRedirect();

        $empresa->refresh();
        $this->assertFalse($empresa->problema);
        $this->assertFalse($empresa->problema_desconsidera_meta);
    }

    public function test_empresas_ja_marcadas_nascem_dentro_da_meta(): void
    {
        // Backfill da migration: default false → o passivo volta a contar pra meta.
        $empresa = $this->empresa(['problema' => true, 'problema_nota' => 'Problema básico']);

        $this->assertFalse($empresa->refresh()->problema_desconsidera_meta);
        $this->assertSame('Sim', $this->statusDe($empresa->toArray(), 5000, 1000));
    }
}
