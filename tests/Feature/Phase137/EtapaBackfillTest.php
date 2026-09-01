<?php

namespace Tests\Feature\Phase137;

use App\Models\Company;
use App\Models\CompanyEtapaTransicao;
use App\Models\ContratoServico;
use App\Models\MlbEmpresa;
use App\Models\Onboarding;
use App\Models\Servico;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Fase 137 (plano 05, ETAPA-02) — prova dos dois baldes do `etapa:backfill`
 * (D-04, travado) e da preservação do Success Criteria nº 1: a aba
 * "Empresas" de `/companies` mostra exatamente o mesmo conjunto antes e
 * depois do backfill.
 *
 * Molde de fixture: `tests/Feature/Phase37CompaniesPerformanceFilterTest.php`
 * (helpers `actingAsAdmin`, `criarServico`, `criarEmpresa`, `criarContrato`,
 * `payloadCompanies`) — é o teste que já prova o payload de `/companies`
 * linha a linha.
 */
class EtapaBackfillTest extends TestCase
{
    use RefreshDatabase;

    // ─── Helpers (molde Phase37CompaniesPerformanceFilterTest) ───────────────

    private function actingAsAdmin(): User
    {
        $admin = User::create([
            'name' => 'Admin Phase137-05 ' . uniqid(),
            'email' => 'admin.p137-05.' . uniqid() . '@ecf.test',
            'password' => bcrypt('senha'),
            'role' => 'admin',
            'active' => true,
        ]);
        $this->actingAs($admin);

        return $admin;
    }

    private function criarServico(string $nome, string $setor, float $valor = 1500.0): Servico
    {
        return Servico::create([
            'nome' => $nome . ' ' . uniqid(),
            'valor_padrao' => $valor,
            'tipo_cobranca' => Servico::TIPO_MENSAL,
            'ativo' => true,
            'setor' => $setor,
        ]);
    }

    private function criarEmpresa(array $overrides = []): Company
    {
        return Company::create(array_merge([
            'name' => 'Empresa P137-05 ' . uniqid(),
            'cnpj' => substr(str_pad((string) random_int(1, 99999999999999), 14, '0', STR_PAD_LEFT), 0, 14),
            'active' => true,
            'status' => 'ativo',
            'email_colaborador' => 'colab.' . uniqid() . '@ecf.test',
            'adman_account_id' => (string) random_int(100000, 999999),
            'empresa_nova' => false,
        ], $overrides));
    }

    private function criarContrato(Company $c, Servico $s, bool $ativo = true): ContratoServico
    {
        return ContratoServico::create([
            'company_id' => $c->id,
            'servico_id' => $s->id,
            'valor_contratado' => 1500,
            'data_contratacao' => now()->toDateString(),
            'ativo' => $ativo,
        ]);
    }

    private function payloadCompanies($response): \Illuminate\Support\Collection
    {
        return collect($response->viewData('page')['props']['companies']);
    }

    /** Anexa `$user` como analista (papel 'consultor' na pivot) de performance. */
    private function tornarAnalista(Company $empresa, User $user, ?int $servicoId): void
    {
        $empresa->users()->attach($user->id, [
            'role' => 'consultor',
            'assigned_at' => now(),
            'servico_id' => $servicoId,
        ]);
    }

    /** Anexa `$user` como estrategista de performance. */
    private function tornarEstrategista(Company $empresa, User $user, ?int $servicoId): void
    {
        $empresa->users()->attach($user->id, [
            'role' => 'estrategista',
            'assigned_at' => now(),
            'servico_id' => $servicoId,
        ]);
    }

    // ═══════════════════════════════════════════════════════════════════
    // Balde 1 — analista OU estrategista (D-04)
    // ═══════════════════════════════════════════════════════════════════

    public function test_balde1_empresa_com_analista_performance_recebe_em_operacao(): void
    {
        $servico = $this->criarServico('Gestao', Servico::SETOR_PERFORMANCE);
        $empresa = $this->criarEmpresa();
        $this->criarContrato($empresa, $servico, true);
        $this->tornarAnalista($empresa, User::factory()->create(), $servico->id);

        Artisan::call('etapa:backfill', ['--apply' => true]);

        $empresa->refresh();
        $this->assertSame(
            Company::ETAPA_EM_OPERACAO,
            $empresa->etapa,
            'Empresa com analista de Performance deve receber etapa em_operacao no backfill'
        );
    }

    public function test_balde1_empresa_com_estrategista_performance_recebe_em_operacao(): void
    {
        $servico = $this->criarServico('Gestao', Servico::SETOR_PERFORMANCE);
        $empresa = $this->criarEmpresa();
        $this->criarContrato($empresa, $servico, true);
        $this->tornarEstrategista($empresa, User::factory()->create(), $servico->id);

        Artisan::call('etapa:backfill', ['--apply' => true]);

        $empresa->refresh();
        $this->assertSame(
            Company::ETAPA_EM_OPERACAO,
            $empresa->etapa,
            'Empresa com estrategista de Performance (sem analista) também deve receber em_operacao — a regra é OU, não E'
        );
    }

    // ═══════════════════════════════════════════════════════════════════
    // Balde 2 — resto fica NULL (caso dominante)
    // ═══════════════════════════════════════════════════════════════════

    public function test_balde2_empresa_com_contrato_performance_sem_responsaveis_fica_null(): void
    {
        $servico = $this->criarServico('Gestao', Servico::SETOR_PERFORMANCE);
        $empresa = $this->criarEmpresa();
        $this->criarContrato($empresa, $servico, true);
        // Sem analista, sem estrategista.

        Artisan::call('etapa:backfill', ['--apply' => true]);

        $empresa->refresh();
        $this->assertNull(
            $empresa->etapa,
            'Empresa com contrato Performance ativo mas sem responsáveis deve ficar com etapa NULL (balde 2)'
        );
    }

    // ═══════════════════════════════════════════════════════════════════
    // D-05 — nenhuma etapa intermediária aparece no legado
    // ═══════════════════════════════════════════════════════════════════

    public function test_d05_nenhuma_etapa_intermediaria_aparece_apos_apply(): void
    {
        $servico = $this->criarServico('Gestao', Servico::SETOR_PERFORMANCE);

        // Balde 1
        $comResponsavel = $this->criarEmpresa();
        $this->criarContrato($comResponsavel, $servico, true);
        $this->tornarAnalista($comResponsavel, User::factory()->create(), $servico->id);

        // Balde 2 (múltiplas variações — nenhuma pode virar etapa intermediária)
        $semResponsavel = $this->criarEmpresa();
        $this->criarContrato($semResponsavel, $servico, true);

        $semContratoAlgum = $this->criarEmpresa();

        Artisan::call('etapa:backfill', ['--apply' => true]);

        $distintos = Company::query()
            ->whereNotNull('etapa')
            ->distinct()
            ->pluck('etapa')
            ->all();

        $this->assertSame(
            [Company::ETAPA_EM_OPERACAO],
            $distintos,
            'D-05: nenhuma etapa intermediária (2..8) pode aparecer no backfill do legado'
        );
    }

    // ═══════════════════════════════════════════════════════════════════
    // D-06 — colisão "distribuir já é em operação" é aceita para o legado
    // ═══════════════════════════════════════════════════════════════════

    public function test_d06_empresa_distribuida_com_onboarding_rascunho_recebe_em_operacao(): void
    {
        $servico = $this->criarServico('Gestao', Servico::SETOR_PERFORMANCE);
        $empresa = $this->criarEmpresa();
        $contrato = $this->criarContrato($empresa, $servico, true);
        $this->tornarAnalista($empresa, User::factory()->create(), $servico->id);

        Onboarding::create([
            'company_id' => $empresa->id,
            'servico_id' => $servico->id,
            'contrato_servico_id' => $contrato->id,
            'status' => Onboarding::STATUS_RASCUNHO,
        ]);

        Artisan::call('etapa:backfill', ['--apply' => true]);

        $empresa->refresh();
        $this->assertSame(
            Company::ETAPA_EM_OPERACAO,
            $empresa->etapa,
            'D-06: empresa já distribuída com onboarding em rascunho recebe em_operacao, não 6/7/8'
        );
    }

    public function test_d06_empresa_distribuida_com_onboarding_andamento_recebe_em_operacao(): void
    {
        $servico = $this->criarServico('Gestao', Servico::SETOR_PERFORMANCE);
        $empresa = $this->criarEmpresa();
        $contrato = $this->criarContrato($empresa, $servico, true);
        $this->tornarEstrategista($empresa, User::factory()->create(), $servico->id);

        Onboarding::create([
            'company_id' => $empresa->id,
            'servico_id' => $servico->id,
            'contrato_servico_id' => $contrato->id,
            'status' => Onboarding::STATUS_ANDAMENTO,
        ]);

        Artisan::call('etapa:backfill', ['--apply' => true]);

        $empresa->refresh();
        $this->assertSame(Company::ETAPA_EM_OPERACAO, $empresa->etapa);
    }

    // ═══════════════════════════════════════════════════════════════════
    // Dry-run não escreve nada
    // ═══════════════════════════════════════════════════════════════════

    public function test_dry_run_nao_altera_nenhuma_linha(): void
    {
        $servico = $this->criarServico('Gestao', Servico::SETOR_PERFORMANCE);
        $empresa = $this->criarEmpresa();
        $this->criarContrato($empresa, $servico, true);
        $this->tornarAnalista($empresa, User::factory()->create(), $servico->id);

        Artisan::call('etapa:backfill'); // sem --apply

        $empresa->refresh();
        $this->assertNull($empresa->etapa, 'Sem --apply o comando não pode gravar etapa em nenhuma empresa');
        $this->assertSame(0, Company::query()->whereNotNull('etapa')->count());
    }

    // ═══════════════════════════════════════════════════════════════════
    // carimbarBackfill() não gera histórico (T-137-15, aceito por decisão)
    // ═══════════════════════════════════════════════════════════════════

    public function test_apply_nao_gera_linha_de_historico(): void
    {
        $servico = $this->criarServico('Gestao', Servico::SETOR_PERFORMANCE);
        $empresa = $this->criarEmpresa();
        $this->criarContrato($empresa, $servico, true);
        $this->tornarAnalista($empresa, User::factory()->create(), $servico->id);

        Artisan::call('etapa:backfill', ['--apply' => true]);

        $this->assertSame(
            0,
            CompanyEtapaTransicao::query()->count(),
            'carimbarBackfill() é deliberadamente sem histórico — não deve inventar duração fictícia no SLA da Fase 143'
        );
    }

    // ═══════════════════════════════════════════════════════════════════
    // Success Criteria nº 1 — o payload de /companies é idêntico antes/depois
    // ═══════════════════════════════════════════════════════════════════

    public function test_payload_de_companies_e_identico_antes_e_depois_do_backfill(): void
    {
        $admin = $this->actingAsAdmin();
        $servico = $this->criarServico('Gestao', Servico::SETOR_PERFORMANCE);

        // Balde 1 — vai virar etapa=em_operacao, continua aparecendo.
        $comAnalista = $this->criarEmpresa();
        $this->criarContrato($comAnalista, $servico, true);
        $this->tornarAnalista($comAnalista, User::factory()->create(), $servico->id);

        // Balde 2 — fica etapa=NULL, mas a query base de /companies não lê
        // `etapa` nesta fase (Pitfall 3), então continua aparecendo pelo
        // cálculo legado (contrato Performance ativo).
        $semResponsavel = $this->criarEmpresa();
        $this->criarContrato($semResponsavel, $servico, true);

        // Fora do recorte da tela por desenho — precisa continuar fora
        // depois do backfill também (Pitfall 3: dois filtros de backend
        // empilhados, não só `em_operacao`).
        $comMlbEmpresa = $this->criarEmpresa();
        $this->criarContrato($comMlbEmpresa, $servico, true);
        MlbEmpresa::create([
            'nome' => 'Empresa MLB ' . uniqid(),
            'tipo' => 'POLO',
            'projeto' => 'POLOS',
            'fase' => 'M1',
            'polo' => 'Arapongas',
            'estagio' => 'Não Listado',
            'criado_por' => $admin->id,
            'company_id' => $comMlbEmpresa->id,
        ]);

        $semContratoPerformance = $this->criarEmpresa();
        $outroServico = $this->criarServico('Polos', Servico::SETOR_OUTROS);
        $this->criarContrato($semContratoPerformance, $outroServico, true);

        $antes = $this->payloadCompanies($this->get('/companies'))->pluck('id')->sort()->values()->all();

        Artisan::call('etapa:backfill', ['--apply' => true]);

        $depois = $this->payloadCompanies($this->get('/companies'))->pluck('id')->sort()->values()->all();

        $this->assertSame(
            $antes,
            $depois,
            'Success Criteria nº 1: a tela "Empresas" de /companies não pode perder (nem ganhar) nenhuma empresa após o backfill'
        );

        // Confirma que o conjunto não é trivialmente vazio (o teste provaria
        // pouco se $antes === $depois === []).
        $this->assertContains($comAnalista->id, $antes);
        $this->assertContains($semResponsavel->id, $antes);
        $this->assertNotContains($comMlbEmpresa->id, $antes);
        $this->assertNotContains($semContratoPerformance->id, $antes);
    }
}
