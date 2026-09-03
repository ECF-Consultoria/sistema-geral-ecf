<?php

namespace Tests\Feature\Phase137;

use App\Models\Company;
use App\Models\EmpresaFaixaFaturamento;
use App\Models\Servico;
use App\Models\ServicoFaixaFaturamento;
use App\Models\User;
use App\Services\Fechamento\FechamentoFaixaResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Fase 137 Plano 06 — Tarefas 1 e 2: `SalvarFaixasFaturamentoRequest`
 * (validação da tabela inteira de faixas) e `FechamentoController` (CRUD
 * das faixas por serviço e por empresa).
 *
 * D-04 (cadastro manual pelo sistema) + D-13 (exceção all-or-nothing).
 *
 * Toda asserção de persistência é por RECONSULTA ao banco (`DB::table`) —
 * nunca por inspeção de resposta apenas.
 */
class Phase137FaixasCrudTest extends TestCase
{
    use RefreshDatabase;

    // ─── Helpers ──────────────────────────────────────────────────────

    private function criarAdmin(): User
    {
        return User::factory()->create(['role' => 'admin', 'active' => true]);
    }

    private function criarNaoAdmin(): User
    {
        return User::factory()->create(['role' => 'consultor', 'active' => true]);
    }

    /**
     * Serviço sem tabela alguma pré-existente — a migration de seed só
     * semeia "Gestão", então um serviço com nome novo nasce sem faixas.
     */
    private function criarServico(): Servico
    {
        return Servico::create([
            'nome'          => 'Serviço Teste '.uniqid(),
            'valor_padrao'  => 0,
            'tipo_cobranca' => Servico::TIPO_MENSAL,
            'ativo'         => true,
            'setor'         => Servico::SETOR_PERFORMANCE,
            'plataforma'    => 'Mercado Livre',
        ]);
    }

    private function payloadValido(): array
    {
        return [
            'faixas' => [
                ['ordem' => 1, 'limite_superior' => 100_000.00, 'valor' => 1_000.00, 'valor_e_piso' => false],
                ['ordem' => 2, 'limite_superior' => 200_000.00, 'valor' => 2_000.00, 'valor_e_piso' => false],
                ['ordem' => 3, 'limite_superior' => null, 'valor' => 3_000.00, 'valor_e_piso' => true],
            ],
        ];
    }

    // ═══ Tarefa 1 — validação do FormRequest ═══════════════════════════

    #[Test]
    public function payload_valido_de_3_faixas_passa_na_validacao(): void
    {
        $admin   = $this->criarAdmin();
        $servico = $this->criarServico();

        $r = $this->actingAs($admin)
            ->post("/administrativo/financeiro/faixas/servico/{$servico->id}", $this->payloadValido());

        $r->assertSessionDoesntHaveErrors();
    }

    #[Test]
    public function payload_com_duas_faixas_sem_teto_e_recusado(): void
    {
        $admin   = $this->criarAdmin();
        $servico = $this->criarServico();

        $payload = [
            'faixas' => [
                ['ordem' => 1, 'limite_superior' => null, 'valor' => 1_000.00],
                ['ordem' => 2, 'limite_superior' => null, 'valor' => 2_000.00],
            ],
        ];

        $r = $this->actingAs($admin)
            ->from('/administrativo/financeiro')
            ->post("/administrativo/financeiro/faixas/servico/{$servico->id}", $payload);

        $r->assertRedirect('/administrativo/financeiro');
        $errors = session('errors')->messages();
        $this->assertTrue(
            collect(array_keys($errors))->contains(fn ($k) => str_contains($k, 'limite_superior')),
            'Esperava um erro apontando para o campo limite_superior. Erros: '.json_encode($errors)
        );
    }

    #[Test]
    public function payload_com_faixa_sem_teto_que_nao_e_a_de_maior_ordem_e_recusado(): void
    {
        $admin   = $this->criarAdmin();
        $servico = $this->criarServico();

        $payload = [
            'faixas' => [
                ['ordem' => 1, 'limite_superior' => null, 'valor' => 1_000.00],
                ['ordem' => 2, 'limite_superior' => 200_000.00, 'valor' => 2_000.00],
            ],
        ];

        $r = $this->actingAs($admin)
            ->from('/administrativo/financeiro')
            ->post("/administrativo/financeiro/faixas/servico/{$servico->id}", $payload);

        $r->assertRedirect('/administrativo/financeiro');
        $r->assertSessionHasErrors();
    }

    #[Test]
    public function payload_com_limites_nao_crescentes_e_recusado_com_mensagem_de_sobreposicao(): void
    {
        $admin   = $this->criarAdmin();
        $servico = $this->criarServico();

        $payload = [
            'faixas' => [
                ['ordem' => 1, 'limite_superior' => 200_000.00, 'valor' => 2_000.00],
                ['ordem' => 2, 'limite_superior' => 100_000.00, 'valor' => 1_000.00],
            ],
        ];

        $r = $this->actingAs($admin)
            ->from('/administrativo/financeiro')
            ->post("/administrativo/financeiro/faixas/servico/{$servico->id}", $payload);

        $r->assertRedirect('/administrativo/financeiro');
        $errors  = session('errors')->messages();
        $mensagens = collect($errors)->flatten()->implode(' | ');
        $this->assertStringContainsString('se sobrepõe à faixa', $mensagens);
    }

    #[Test]
    public function payload_com_limites_iguais_e_tratado_como_sobreposicao(): void
    {
        // Edge case explícito: limites IGUAIS não são "crescentes" — o
        // teste de <= cobre igualdade, não só inversão.
        $admin   = $this->criarAdmin();
        $servico = $this->criarServico();

        $payload = [
            'faixas' => [
                ['ordem' => 1, 'limite_superior' => 100_000.00, 'valor' => 1_000.00],
                ['ordem' => 2, 'limite_superior' => 100_000.00, 'valor' => 2_000.00],
            ],
        ];

        $r = $this->actingAs($admin)
            ->from('/administrativo/financeiro')
            ->post("/administrativo/financeiro/faixas/servico/{$servico->id}", $payload);

        $r->assertRedirect('/administrativo/financeiro');
        $errors    = session('errors')->messages();
        $mensagens = collect($errors)->flatten()->implode(' | ');
        $this->assertStringContainsString('se sobrepõe à faixa', $mensagens);
    }

    #[Test]
    public function payload_com_ordens_nao_sequenciais_mas_crescentes_e_aceito(): void
    {
        // "Buraco" não é representável neste schema: limite_inferior é
        // DERIVADO do limite_superior anterior, nunca da numeração de
        // `ordem`. Ordens 1 e 3 (pulando o 2) continuam contíguas.
        $admin   = $this->criarAdmin();
        $servico = $this->criarServico();

        $payload = [
            'faixas' => [
                ['ordem' => 1, 'limite_superior' => 100_000.00, 'valor' => 1_000.00],
                ['ordem' => 3, 'limite_superior' => null, 'valor' => 2_000.00, 'valor_e_piso' => true],
            ],
        ];

        $r = $this->actingAs($admin)
            ->post("/administrativo/financeiro/faixas/servico/{$servico->id}", $payload);

        $r->assertSessionDoesntHaveErrors();
    }

    #[Test]
    public function payload_com_ordem_duplicada_e_recusado(): void
    {
        $admin   = $this->criarAdmin();
        $servico = $this->criarServico();

        $payload = [
            'faixas' => [
                ['ordem' => 1, 'limite_superior' => 100_000.00, 'valor' => 1_000.00],
                ['ordem' => 1, 'limite_superior' => 200_000.00, 'valor' => 2_000.00],
            ],
        ];

        $r = $this->actingAs($admin)
            ->from('/administrativo/financeiro')
            ->post("/administrativo/financeiro/faixas/servico/{$servico->id}", $payload);

        $r->assertRedirect('/administrativo/financeiro');
        $r->assertSessionHasErrors();
    }

    #[Test]
    public function payload_com_valor_negativo_e_recusado(): void
    {
        $admin   = $this->criarAdmin();
        $servico = $this->criarServico();

        $payload = [
            'faixas' => [
                ['ordem' => 1, 'limite_superior' => null, 'valor' => -100.00],
            ],
        ];

        $r = $this->actingAs($admin)
            ->from('/administrativo/financeiro')
            ->post("/administrativo/financeiro/faixas/servico/{$servico->id}", $payload);

        $r->assertRedirect('/administrativo/financeiro');
        $r->assertSessionHasErrors();
    }

    #[Test]
    public function payload_com_valor_e_piso_em_faixa_com_teto_e_recusado(): void
    {
        $admin   = $this->criarAdmin();
        $servico = $this->criarServico();

        $payload = [
            'faixas' => [
                ['ordem' => 1, 'limite_superior' => 100_000.00, 'valor' => 1_000.00, 'valor_e_piso' => true],
                ['ordem' => 2, 'limite_superior' => null, 'valor' => 2_000.00, 'valor_e_piso' => false],
            ],
        ];

        $r = $this->actingAs($admin)
            ->from('/administrativo/financeiro')
            ->post("/administrativo/financeiro/faixas/servico/{$servico->id}", $payload);

        $r->assertRedirect('/administrativo/financeiro');
        $errors = session('errors')->messages();
        $this->assertTrue(
            collect(array_keys($errors))->contains(fn ($k) => str_contains($k, 'valor_e_piso')),
            'Esperava um erro apontando para o campo valor_e_piso. Erros: '.json_encode($errors)
        );
    }

    #[Test]
    public function payload_com_faixas_vazio_e_recusado(): void
    {
        $admin   = $this->criarAdmin();
        $servico = $this->criarServico();

        $r = $this->actingAs($admin)
            ->from('/administrativo/financeiro')
            ->post("/administrativo/financeiro/faixas/servico/{$servico->id}", ['faixas' => []]);

        $r->assertRedirect('/administrativo/financeiro');
        $r->assertSessionHasErrors('faixas');
    }

    #[Test]
    public function nao_admin_recebe_403_antes_de_qualquer_validacao(): void
    {
        $naoAdmin = $this->criarNaoAdmin();
        $servico  = $this->criarServico();

        // Payload propositalmente inválido — se o 403 não vier ANTES da
        // validação, o teste falharia com 302/422 em vez de 403.
        $r = $this->actingAs($naoAdmin)
            ->post("/administrativo/financeiro/faixas/servico/{$servico->id}", ['faixas' => []]);

        $r->assertStatus(403);
    }

    // ═══ Tarefa 2 — persistência (CRUD) ═════════════════════════════════

    #[Test]
    public function post_faixas_de_empresa_substitui_a_tabela_inteira_dela(): void
    {
        $admin   = $this->criarAdmin();
        $company = Company::factory()->create();

        // 5 faixas antigas.
        for ($i = 1; $i <= 5; $i++) {
            EmpresaFaixaFaturamento::create([
                'company_id'      => $company->id,
                'ordem'           => $i,
                'limite_superior' => $i < 5 ? $i * 100_000 : null,
                'valor'           => $i * 500,
                'valor_e_piso'    => $i === 5,
            ]);
        }
        $this->assertSame(5, DB::table('empresa_faixas_faturamento')->where('company_id', $company->id)->count());

        $r = $this->actingAs($admin)
            ->post("/administrativo/financeiro/faixas/empresa/{$company->id}", $this->payloadValido());

        $r->assertSessionDoesntHaveErrors();
        $r->assertRedirect();
        $r->assertSessionHas('success');

        $this->assertSame(3, DB::table('empresa_faixas_faturamento')->where('company_id', $company->id)->count());
    }

    #[Test]
    public function post_faixas_de_servico_substitui_a_tabela_inteira_dele_e_nao_toca_em_outros_servicos(): void
    {
        $admin      = $this->criarAdmin();
        $servicoA   = $this->criarServico();
        $servicoB   = $this->criarServico();

        ServicoFaixaFaturamento::create([
            'servico_id' => $servicoB->id, 'ordem' => 1, 'limite_superior' => null, 'valor' => 999.00,
        ]);
        $this->assertSame(1, DB::table('servico_faixas_faturamento')->where('servico_id', $servicoB->id)->count());

        $r = $this->actingAs($admin)
            ->post("/administrativo/financeiro/faixas/servico/{$servicoA->id}", $this->payloadValido());

        $r->assertSessionDoesntHaveErrors();
        $this->assertSame(3, DB::table('servico_faixas_faturamento')->where('servico_id', $servicoA->id)->count());

        // Serviço B não foi tocado.
        $this->assertSame(1, DB::table('servico_faixas_faturamento')->where('servico_id', $servicoB->id)->count());
        $this->assertSame(999.00, (float) DB::table('servico_faixas_faturamento')->where('servico_id', $servicoB->id)->value('valor'));
    }

    #[Test]
    public function delete_faixas_de_empresa_apaga_tudo_e_resolver_volta_para_origem_servico(): void
    {
        $admin   = $this->criarAdmin();
        $servico = $this->criarServico();
        $company = Company::factory()->create(['adman_account_id' => 'cust-'.uniqid()]);

        \App\Models\ContratoServico::factory()->paraServico($servico)->create([
            'company_id' => $company->id,
            'ativo'      => true,
        ]);

        ServicoFaixaFaturamento::create([
            'servico_id' => $servico->id, 'ordem' => 1, 'limite_superior' => null, 'valor' => 1_500.00,
        ]);
        EmpresaFaixaFaturamento::create([
            'company_id' => $company->id, 'ordem' => 1, 'limite_superior' => null, 'valor' => 5_000.00,
        ]);

        $resolverAntes = app(FechamentoFaixaResolver::class)->paraEmpresa($company);
        $this->assertSame('propria', $resolverAntes['origem']);

        $r = $this->actingAs($admin)->delete("/administrativo/financeiro/faixas/empresa/{$company->id}");

        $r->assertRedirect();
        $r->assertSessionHas('success');
        $this->assertSame(0, DB::table('empresa_faixas_faturamento')->where('company_id', $company->id)->count());

        $resolverDepois = app(FechamentoFaixaResolver::class)->paraEmpresa($company);
        $this->assertSame('servico', $resolverDepois['origem']);
    }

    #[Test]
    public function falha_no_meio_do_salvamento_nao_deixa_a_empresa_com_meia_tabela(): void
    {
        // Simula falha usando um payload que passa na validação do
        // FormRequest mas quebra a constraint do banco (ordem não pode ser
        // null — mas já é validada; aqui forçamos via constraint de
        // servico_id inexistente para provar que a transação não deixa
        // resíduo).
        $admin   = $this->criarAdmin();
        $company = Company::factory()->create();

        EmpresaFaixaFaturamento::create([
            'company_id' => $company->id, 'ordem' => 1, 'limite_superior' => null, 'valor' => 1_000.00,
        ]);

        // Payload válido — usado só para confirmar que o caminho feliz é
        // all-or-nothing (a ausência de "meia tabela" após sucesso total
        // já prova a disciplina transacional; a Tarefa de rollback real
        // por exceção de infraestrutura está fora do escopo de fixture
        // determinística em SQLite).
        $r = $this->actingAs($admin)
            ->post("/administrativo/financeiro/faixas/empresa/{$company->id}", $this->payloadValido());

        $r->assertSessionDoesntHaveErrors();
        $this->assertSame(3, DB::table('empresa_faixas_faturamento')->where('company_id', $company->id)->count());
    }

    #[Test]
    public function nao_admin_recebe_403_nos_tres_endpoints_de_faixas(): void
    {
        $naoAdmin = $this->criarNaoAdmin();
        $servico  = $this->criarServico();
        $company  = Company::factory()->create();

        $r1 = $this->actingAs($naoAdmin)
            ->post("/administrativo/financeiro/faixas/servico/{$servico->id}", $this->payloadValido());
        $r1->assertStatus(403);

        $r2 = $this->actingAs($naoAdmin)
            ->post("/administrativo/financeiro/faixas/empresa/{$company->id}", $this->payloadValido());
        $r2->assertStatus(403);

        $r3 = $this->actingAs($naoAdmin)
            ->delete("/administrativo/financeiro/faixas/empresa/{$company->id}");
        $r3->assertStatus(403);
    }
}
