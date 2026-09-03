<?php

namespace Tests\Feature\Phase138;

use App\Models\Company;
use App\Models\CompanyGroup;
use App\Models\GrupoFaixaFaturamento;
use App\Models\User;
use App\Services\Fechamento\FechamentoFaixaResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Fase 138 Plano 06 — Tarefas 1 e 2: CRUD da tabela de faixas do GRUPO
 * (`FechamentoController::salvarFaixasGrupo`/`removerFaixasGrupo`) e a
 * trava de contrato do bloco novo em `TabelaFaixasSection.jsx`.
 *
 * Molde: `Tests\Feature\Phase137\Phase137FaixasCrudTest` (CRUD por
 * serviço/empresa) + `Phase137FinanceiroUiContratoTest` (frente de arquivo,
 * já que o projeto não tem framework de teste JS).
 *
 * Toda asserção de persistência é por RECONSULTA ao banco (`DB::table`) —
 * nunca por inspeção de resposta apenas.
 */
class Phase138FaixasGrupoCrudTest extends TestCase
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

    // ═══ (a)/(b) — cadastrar e SUBSTITUIR a tabela do grupo ══════════════

    #[Test]
    public function post_faixas_de_grupo_cria_as_linhas_na_ordem_certa(): void
    {
        $admin = $this->criarAdmin();
        $grupo = CompanyGroup::create(['name' => 'Grupo Teste '.uniqid()]);

        $r = $this->actingAs($admin)
            ->post("/administrativo/financeiro/faixas/grupo/{$grupo->id}", $this->payloadValido());

        $r->assertSessionDoesntHaveErrors();
        $r->assertRedirect();
        $r->assertSessionHas('success');

        $linhas = DB::table('grupo_faixas_faturamento')
            ->where('company_group_id', $grupo->id)
            ->orderBy('ordem')
            ->get();

        $this->assertCount(3, $linhas);
        $this->assertSame([1, 2, 3], $linhas->pluck('ordem')->map(fn ($v) => (int) $v)->all());
        $this->assertSame(3000.00, (float) $linhas->last()->valor);
        $this->assertTrue((bool) $linhas->last()->valor_e_piso);
    }

    #[Test]
    public function post_faixas_de_grupo_substitui_a_tabela_inteira_sem_sobrar_linha_antiga(): void
    {
        $admin = $this->criarAdmin();
        $grupo = CompanyGroup::create(['name' => 'Grupo Teste '.uniqid()]);

        for ($i = 1; $i <= 5; $i++) {
            GrupoFaixaFaturamento::create([
                'company_group_id' => $grupo->id,
                'ordem'             => $i,
                'limite_superior'   => $i < 5 ? $i * 100_000 : null,
                'valor'             => $i * 500,
                'valor_e_piso'      => $i === 5,
            ]);
        }
        $this->assertSame(5, DB::table('grupo_faixas_faturamento')->where('company_group_id', $grupo->id)->count());

        $r = $this->actingAs($admin)
            ->post("/administrativo/financeiro/faixas/grupo/{$grupo->id}", $this->payloadValido());

        $r->assertSessionDoesntHaveErrors();

        $this->assertSame(3, DB::table('grupo_faixas_faturamento')->where('company_group_id', $grupo->id)->count());
        $this->assertSame(
            [1, 2, 3],
            DB::table('grupo_faixas_faturamento')->where('company_group_id', $grupo->id)->orderBy('ordem')->pluck('ordem')->map(fn ($v) => (int) $v)->all()
        );
    }

    // ═══ (c) — sobreposição recusada pela mesma régua reaproveitada ══════

    #[Test]
    public function payload_com_faixas_sobrepostas_e_recusado_com_a_mensagem_do_form_request_reaproveitado(): void
    {
        $admin = $this->criarAdmin();
        $grupo = CompanyGroup::create(['name' => 'Grupo Teste '.uniqid()]);

        $payload = [
            'faixas' => [
                ['ordem' => 1, 'limite_superior' => 200_000.00, 'valor' => 2_000.00],
                ['ordem' => 2, 'limite_superior' => 100_000.00, 'valor' => 1_000.00],
            ],
        ];

        $r = $this->actingAs($admin)
            ->from('/administrativo/financeiro')
            ->post("/administrativo/financeiro/faixas/grupo/{$grupo->id}", $payload);

        $r->assertRedirect('/administrativo/financeiro');
        $errors    = session('errors')->messages();
        $mensagens = collect($errors)->flatten()->implode(' | ');
        $this->assertStringContainsString('se sobrepõe à faixa', $mensagens);

        // Prova de que nada foi persistido — a régua barrou antes do save.
        $this->assertSame(0, DB::table('grupo_faixas_faturamento')->where('company_group_id', $grupo->id)->count());
    }

    // ═══ (d) — 403 pras duas rotas novas ══════════════════════════════════

    #[Test]
    public function nao_admin_recebe_403_nas_duas_rotas_de_faixas_de_grupo(): void
    {
        $naoAdmin = $this->criarNaoAdmin();
        $grupo    = CompanyGroup::create(['name' => 'Grupo Teste '.uniqid()]);

        $r1 = $this->actingAs($naoAdmin)
            ->post("/administrativo/financeiro/faixas/grupo/{$grupo->id}", $this->payloadValido());
        $r1->assertStatus(403);

        $r2 = $this->actingAs($naoAdmin)
            ->delete("/administrativo/financeiro/faixas/grupo/{$grupo->id}");
        $r2->assertStatus(403);
    }

    // ═══ (e) — DELETE apaga tudo e o resolver volta a herdar da empresa ══

    #[Test]
    public function delete_faixas_de_grupo_apaga_tudo_e_resolver_volta_a_herdar_da_empresa(): void
    {
        $admin  = $this->criarAdmin();
        $grupo  = CompanyGroup::create(['name' => 'Grupo Teste '.uniqid()]);
        $ancora = Company::factory()->create(['company_group_id' => $grupo->id, 'name' => 'Empresa Âncora']);

        \App\Models\EmpresaFaixaFaturamento::create([
            'company_id' => $ancora->id, 'ordem' => 1, 'limite_superior' => null, 'valor' => 5_000.00,
        ]);

        GrupoFaixaFaturamento::create([
            'company_group_id' => $grupo->id, 'ordem' => 1, 'limite_superior' => null, 'valor' => 9_999.00,
        ]);

        $resolver      = app(FechamentoFaixaResolver::class);
        $resolverAntes = $resolver->paraGrupo($grupo, $ancora);
        $this->assertSame('grupo', $resolverAntes['origem']);

        $r = $this->actingAs($admin)->delete("/administrativo/financeiro/faixas/grupo/{$grupo->id}");

        $r->assertRedirect();
        $r->assertSessionHas('success');
        $this->assertSame(0, DB::table('grupo_faixas_faturamento')->where('company_group_id', $grupo->id)->count());

        $resolverDepois = $resolver->paraGrupo($grupo, $ancora);
        $this->assertSame('propria', $resolverDepois['origem'], 'Sem a tabela do grupo, volta a herdar da tabela própria da empresa-âncora.');
        $this->assertSame($ancora->id, $resolverDepois['herdada_de_company_id']);
    }

    // ═══ (f) — trava de arquivo (regressão silenciosa de UI) ═════════════

    #[Test]
    public function tabela_faixas_section_jsx_tem_a_frase_de_heranca_e_a_rota_nova(): void
    {
        $conteudo = file_get_contents(resource_path('js/Pages/Admin/Financeiro/TabelaFaixasSection.jsx'));

        $this->assertStringContainsString('tabela_herdada_de_nome', $conteudo, 'Sem essa chave a tela não nomeia de qual empresa a tabela do grupo foi herdada.');
        $this->assertStringContainsString('admin.financeiro.faixas.grupo', $conteudo, 'Sem essa rota o bloco de grupo não tem para onde salvar.');

        // Copy sem jargão interno — "âncora" é termo de código, nunca de tela.
        $this->assertStringNotContainsString('âncora', $conteudo);
        $this->assertStringNotContainsString('ancora', mb_strtolower($conteudo));
    }

    #[Test]
    public function financeiro_jsx_passa_faixas_por_grupo_para_a_secao_de_tabela(): void
    {
        $conteudo = file_get_contents(resource_path('js/Pages/Admin/Financeiro.jsx'));

        $this->assertStringContainsString('faixasPorGrupo', $conteudo, 'Sem o encadeamento da prop, o bloco de grupo renderiza vazio em silêncio.');
        $this->assertStringContainsString('faixas_por_grupo', $conteudo);
    }
}
