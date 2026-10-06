<?php

namespace Tests\Feature\Mcp;

use App\Models\MlbEmpresa;
use App\Models\MlbImplementacao;
use App\Models\Ppa;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * `onboarding_polos` e `ppa`: mesmos números e mesmo recorte de
 * /mlb/implementacao, /ppa e /mlb/polos-ppa.
 */
class PolosEPpaToolTest extends TestCase
{
    use ChamaMcp, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        Queue::fake();
        $this->withoutVite();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function onboarding(string $nome, array $empresa = [], array $dados = [], ?string $criadoEm = null): MlbImplementacao
    {
        $e = MlbEmpresa::create($empresa + ['nome' => $nome, 'projeto' => 'POLOS', 'cust_id' => (string) random_int(100000, 999999), 'polo' => 'Arapongas', 'fase' => 'M0']);

        $impl = MlbImplementacao::create([
            'empresa_id' => $e->id,
            'token'      => 'tok-segredo-'.$e->id,
            'dados'      => $dados ?: null,
        ]);

        if ($criadoEm) {
            $impl->forceFill(['created_at' => $criadoEm])->saveQuietly();
        }

        return $impl->fresh();
    }

    // ═══ onboarding_polos ═══

    public function test_onboarding_bate_com_a_tela_e_nao_expoe_o_token_do_cliente(): void
    {
        $admin = $this->admin();
        $this->onboarding('Moveis Arapongas', [], ['ml_oauth' => ['autorizado_em' => '2026-09-01T10:00:00Z', 'cust_id' => '1', 'divergente' => false]]);
        $this->onboarding('Estofados Serra', ['polo' => 'Serra Gaúcha', 'fase' => 'M1'], [], now()->subDays(10)->toDateTimeString());

        $mcp  = $this->ferramenta($admin, 'onboarding_polos');
        $tela = collect($this->actingAs($admin)->get(route('mlb.implementacao.index'))->viewData('page')['props']['empresas']);

        $this->assertSame($tela->count(), $mcp['total']);
        foreach ($mcp['itens'] as $item) {
            $daTela = $tela->firstWhere('nome', $item['empresa']);
            $this->assertSame($daTela['progresso'], $item['progresso'], "Progresso diverge em {$item['empresa']}");
            $this->assertSame($daTela['fora_do_prazo'], $item['fora_do_prazo']);
            $this->assertSame($daTela['ml_oauth']['conectado'], $item['autorizacao_ml']['conectado']);
            // Nada feito ainda: as pendências são o checklist inteiro.
            $this->assertCount($item['progresso']['total'], $item['pendencias']);
        }

        $this->assertSame(['Estofados Serra'], collect($this->ferramenta($admin, 'onboarding_polos', ['fora_do_prazo' => true])['itens'])->pluck('empresa')->all());
        $this->assertSame(['Estofados Serra'], collect($this->ferramenta($admin, 'onboarding_polos', ['sem_autorizacao_ml' => true])['itens'])->pluck('empresa')->all());
        $this->assertSame(['Estofados Serra'], collect($this->ferramenta($admin, 'onboarding_polos', ['polo' => 'Serra Gaúcha'])['itens'])->pluck('empresa')->all());

        $bruto = $this->rpc($admin, 'tools/call', ['name' => 'onboarding_polos', 'arguments' => (object) []])->getContent();
        $this->assertStringNotContainsString('tok-segredo-', $bruto);
    }

    public function test_onboarding_some_sem_acesso_a_implementacao(): void
    {
        $semAcesso = User::factory()->create(['role' => 'consultor', 'active' => true]);
        $this->assertNotContains('onboarding_polos', $this->ferramentasVisiveis($semAcesso));

        $doSetorPolos = $this->comPermissoes(['mlb.implementacao']);
        $this->assertContains('onboarding_polos', $this->ferramentasVisiveis($doSetorPolos));
    }

    // ═══ ppa ═══

    public function test_ppa_nao_admin_ve_so_o_que_criou(): void
    {
        $empresa = $this->empresaPerformance(['name' => 'Loja PPA']);
        $mentor  = User::factory()->create(['role' => 'mentor', 'active' => true]);
        $outro   = User::factory()->create(['role' => 'mentor', 'active' => true]);

        Ppa::create(['company_id' => $empresa->id, 'mentor_id' => $mentor->id, 'title' => 'Plano do mentor', 'status' => 'sent', 'due_date' => now()->subDays(3)->toDateString()]);
        Ppa::create(['company_id' => $empresa->id, 'mentor_id' => $outro->id, 'title' => 'Plano de outro', 'status' => 'draft']);

        $doMentor = $this->ferramenta($mentor, 'ppa');
        $this->assertSame(['Plano do mentor'], collect($doMentor['itens'])->pluck('titulo')->all());
        $this->assertTrue($doMentor['itens'][0]['visivel_ao_cliente']);
        $this->assertSame(-3, $doMentor['itens'][0]['dias_ate_o_prazo']);

        $admin = $this->admin();
        $this->assertSame(2, $this->ferramenta($admin, 'ppa')['total']);
        $this->assertSame(['Plano do mentor'], collect($this->ferramenta($admin, 'ppa', ['atrasados' => true])['itens'])->pluck('titulo')->all());
        $this->assertSame(['Plano de outro'], collect($this->ferramenta($admin, 'ppa', ['visivel_ao_cliente' => false])['itens'])->pluck('titulo')->all());

        // Mesmo total da tela /ppa.
        $tela = $this->actingAs($admin)->get(route('ppa.index'))->viewData('page')['props']['ppas'];
        $this->assertSame($tela['total'], $this->ferramenta($admin, 'ppa')['total']);

        // O link do quadro (token) não sai pelo MCP.
        $bruto = $this->rpc($admin, 'tools/call', ['name' => 'ppa', 'arguments' => (object) []])->getContent();
        $this->assertStringNotContainsString('workspace_token', $bruto);
    }

    public function test_ppa_dos_polos_exige_a_permissao_de_projetos(): void
    {
        $mentor = User::factory()->create(['role' => 'mentor', 'active' => true]);

        $this->assertStringContainsString('Polos', $this->erroDaFerramenta($mentor, 'ppa', ['escopo' => 'polos']));

        $comProjetos = $this->comPermissoes(['mlb.projetos'], 'mentor');
        $this->assertSame(0, $this->ferramenta($comProjetos, 'ppa', ['escopo' => 'polos'])['total']);
    }
}
