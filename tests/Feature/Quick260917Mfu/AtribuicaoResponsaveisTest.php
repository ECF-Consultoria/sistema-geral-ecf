<?php

namespace Tests\Feature\Quick260917Mfu;

use App\Models\Company;
use App\Models\Servico;
use App\Models\Setor;
use App\Models\SetorPermissao;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Tests\Feature\V16\CriaCenarioResponsaveis;
use Tests\TestCase;

/**
 * Quick 260917-mfu — atribuição de responsáveis em `/companies`.
 *
 * Dois bugs relatados no mesmo dia, pelo mesmo usuário:
 *
 *  1. o líder do setor Performance levava 403 no botão de editar que a tela
 *     mostrava para ele (rota dentro de `role:admin`);
 *  2. como admin, a troca de analista "salvava" e a tela seguia mostrando o
 *     antigo — a escrita apagava só o slot `servico_id` do contrato ativo e a
 *     linha legada `servico_id NULL` sobrevivia; a leitura enxerga as duas.
 *
 * O caso (2) é o coração daqui: escrita e leitura precisam ter o MESMO escopo.
 */
class AtribuicaoResponsaveisTest extends TestCase
{
    use RefreshDatabase;
    use CriaCenarioResponsaveis;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin', 'active' => true]);
    }

    /** Usuário com `core.empresas` (alcança a tela) mas sem poder de edição. */
    private function comAcessoAEmpresas(?User $u = null): User
    {
        $u ??= User::factory()->create(['role' => 'consultor', 'active' => true]);

        $setor = Setor::firstOrCreate(
            ['slug' => 'acesso-empresas-mfu'],
            ['nome' => 'Acesso Empresas mfu', 'active' => true]
        );
        SetorPermissao::firstOrCreate(['setor_id' => $setor->id, 'permission_key' => 'core.empresas']);

        if (! $setor->membros()->where('users.id', $u->id)->exists()) {
            $setor->membros()->attach($u->id, ['is_principal' => false, 'assigned_at' => now()]);
        }

        return $u;
    }

    /** O Luiz: líder do setor Performance, sem ser admin. */
    private function liderDaPerformance(): User
    {
        $u = $this->comAcessoAEmpresas();

        DB::table('setor_lideres')->insert([
            'setor_id'   => Setor::where('slug', 'performance')->value('id'),
            'user_id'    => $u->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $u->refresh();
    }

    /**
     * Empresa como as de produção ANTES do split por serviço: contrato
     * performance ativo e responsáveis na linha legada `servico_id NULL`.
     *
     * @return array{company: Company, servicoPerf: int, analistaAntigo: User, estrategistaAntigo: User}
     */
    private function empresaComLinhaLegadaNull(): array
    {
        $company     = Company::factory()->create();
        $servicoPerf = $this->criarServico(Servico::SETOR_PERFORMANCE, true);
        $this->criarContrato($company->id, $servicoPerf, true);

        $analistaAntigo     = User::factory()->create();
        $estrategistaAntigo = User::factory()->create();

        $this->inserirPivot($company->id, $analistaAntigo->id, 'consultor', null);
        $this->inserirPivot($company->id, $estrategistaAntigo->id, 'estrategista', null);

        return compact('company', 'servicoPerf', 'analistaAntigo', 'estrategistaAntigo');
    }

    /** Linhas da pivot no MESMO escopo que a tela lê (performance OU NULL). */
    private function linhasDoEscopoLido(int $companyId, string $role): Collection
    {
        return DB::table('company_users')
            ->where('company_id', $companyId)
            ->where('role', $role)
            ->where(function ($q) {
                $q->whereIn('servico_id', function ($sub) {
                    $sub->select('id')->from('servicos')->where('setor', Servico::SETOR_PERFORMANCE);
                })->orWhereNull('servico_id');
            })
            ->get();
    }

    // ═════════════════════════════════════════════════════════════════════════
    // Bug 2 — a troca precisa SUBSTITUIR a linha legada, não conviver com ela
    // ═════════════════════════════════════════════════════════════════════════

    public function test_troca_de_analista_substitui_a_linha_legada_null(): void
    {
        $this->actingAs($this->admin());

        ['company' => $company, 'analistaAntigo' => $antigo, 'estrategistaAntigo' => $estrategista] =
            $this->empresaComLinhaLegadaNull();

        $novoAnalista = User::factory()->create();

        $this->putJson('/companies/' . $company->id, [
            'name'            => $company->name,
            'active'          => true,
            'consultor_id'    => $novoAnalista->id,
            'estrategista_id' => $estrategista->id,
        ])->assertStatus(302);

        $linhas = $this->linhasDoEscopoLido($company->id, 'consultor');

        $this->assertCount(
            1,
            $linhas,
            'A linha legada servico_id NULL tem de SAIR — duas linhas no escopo de leitura fazem a tela mostrar a antiga.'
        );
        $this->assertSame($novoAnalista->id, (int) $linhas->first()->user_id);

        // A régua que o usuário enxerga: a relação que alimenta a coluna
        // "Analista" da listagem precisa devolver o novo.
        $this->assertSame(
            $novoAnalista->id,
            $company->fresh()->analistaPerformance()->first()?->id,
            'A tela continua mostrando o analista antigo — foi exatamente o bug relatado.'
        );
        $this->assertDatabaseMissing('company_users', [
            'company_id' => $company->id,
            'user_id'    => $antigo->id,
            'role'       => 'consultor',
        ]);
    }

    public function test_bulk_assign_tambem_substitui_a_linha_legada_null(): void
    {
        $this->actingAs($this->admin());

        ['company' => $company] = $this->empresaComLinhaLegadaNull();
        $novoAnalista = User::factory()->create();

        $this->postJson('/companies/bulk-assign', [
            'ids'     => [$company->id],
            'role'    => 'consultor',
            'user_id' => $novoAnalista->id,
        ])->assertStatus(302);

        $linhas = $this->linhasDoEscopoLido($company->id, 'consultor');

        $this->assertCount(1, $linhas);
        $this->assertSame($novoAnalista->id, (int) $linhas->first()->user_id);
    }

    public function test_limpar_os_dois_responsaveis_apaga_de_fato(): void
    {
        $this->actingAs($this->admin());

        $cenario = $this->criarCenarioMlComResponsaveis();
        $company = $cenario['company'];

        $this->putJson('/companies/' . $company->id, [
            'name'            => $company->name,
            'active'          => true,
            'consultor_id'    => null,
            'estrategista_id' => null,
        ])->assertStatus(302);

        $this->assertCount(0, $this->linhasDoEscopoLido($company->id, 'consultor'));
        $this->assertCount(0, $this->linhasDoEscopoLido($company->id, 'estrategista'));
    }

    public function test_troca_de_responsavel_nao_toca_a_linha_shopee(): void
    {
        $this->actingAs($this->admin());

        ['company' => $company] = $this->empresaComLinhaLegadaNull();

        $responsavelShopee = User::factory()->create();
        $shopee = $this->inserirLinhaShopee($company->id, $responsavelShopee->id, 'consultor');

        $novoAnalista = User::factory()->create();

        $this->putJson('/companies/' . $company->id, [
            'name'            => $company->name,
            'active'          => true,
            'consultor_id'    => $novoAnalista->id,
            'estrategista_id' => null,
        ])->assertStatus(302);

        // O escopo maior (agora inclui a linha NULL) NÃO pode alcançar Shopee —
        // era o que a Fase 76 (DEC-A3) protegia.
        $this->assertDatabaseHas('company_users', [
            'company_id' => $company->id,
            'user_id'    => $responsavelShopee->id,
            'role'       => 'consultor',
            'servico_id' => $shopee['servicoShopee'],
        ]);
    }

    // ═════════════════════════════════════════════════════════════════════════
    // Bug 1 — quem pode editar
    // ═════════════════════════════════════════════════════════════════════════

    public function test_lider_da_performance_edita_responsaveis(): void
    {
        $lider = $this->liderDaPerformance();

        ['company' => $company] = $this->empresaComLinhaLegadaNull();
        $novoAnalista = User::factory()->create();

        $this->actingAs($lider)
            ->putJson('/companies/' . $company->id, [
                'name'            => $company->name,
                'active'          => true,
                'consultor_id'    => $novoAnalista->id,
                'estrategista_id' => null,
            ])
            ->assertStatus(302);

        $this->assertSame(
            $novoAnalista->id,
            (int) $this->linhasDoEscopoLido($company->id, 'consultor')->first()->user_id
        );
    }

    public function test_lider_da_performance_atribui_em_massa(): void
    {
        $lider = $this->liderDaPerformance();

        ['company' => $company] = $this->empresaComLinhaLegadaNull();
        $novoAnalista = User::factory()->create();

        $this->actingAs($lider)
            ->postJson('/companies/bulk-assign', [
                'ids'     => [$company->id],
                'role'    => 'consultor',
                'user_id' => $novoAnalista->id,
            ])
            ->assertStatus(302);

        $this->assertSame(
            $novoAnalista->id,
            (int) $this->linhasDoEscopoLido($company->id, 'consultor')->first()->user_id
        );
    }

    public function test_quem_so_alcanca_a_tela_continua_sem_editar(): void
    {
        // Tem `core.empresas` (passa pelo middleware) mas não é admin nem líder.
        $semPoder = $this->comAcessoAEmpresas();

        ['company' => $company, 'analistaAntigo' => $antigo] = $this->empresaComLinhaLegadaNull();

        $this->actingAs($semPoder)
            ->putJson('/companies/' . $company->id, [
                'name'            => $company->name,
                'active'          => true,
                'consultor_id'    => User::factory()->create()->id,
                'estrategista_id' => null,
            ])
            ->assertStatus(403);

        // Nada gravado — fail-closed.
        $this->assertSame(
            $antigo->id,
            (int) $this->linhasDoEscopoLido($company->id, 'consultor')->first()->user_id
        );
    }

    public function test_a_tela_esconde_o_lapis_de_quem_nao_pode_editar(): void
    {
        $props = fn (User $u) => $this->actingAs($u)
            ->get(route('companies.index'))
            ->assertOk()
            ->viewData('page')['props'];

        $this->assertTrue($props($this->admin())['pode_editar_empresa']);
        $this->assertTrue($props($this->liderDaPerformance())['pode_editar_empresa']);
        $this->assertFalse($props($this->comAcessoAEmpresas())['pode_editar_empresa']);
    }
}
