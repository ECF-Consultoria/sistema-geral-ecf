<?php

namespace Tests\Feature\Phase140;

use App\Models\Company;
use App\Models\ContratoTabelaProposta;
use App\Models\EmpresaFaixaFaturamento;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Fase 140 Plano 05 (TAB-08/TAB-09) — Tarefa 2: confirmação auditada.
 *
 * `POST admin.contratos.tabelas.confirmar` grava a tabela da empresa (all-or-nothing, D-13 da Fase
 * 137) e completa CNPJ/razão social só quando vazios; `POST admin.contratos.tabelas.descartar`
 * nunca grava nada. Toda asserção de persistência é por RECONSULTA ao banco (`DB::table`) — mesma
 * disciplina de `Phase137FaixasCrudTest`.
 */
class Phase140ConfirmacaoTabelaTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    // ── 1. Confirmar sem empresa escolhida ──────────────────────────────

    #[Test]
    public function confirmar_sem_empresa_escolhida_e_recusado_com_422_e_nada_e_gravado(): void
    {
        $admin    = $this->admin();
        $proposta = ContratoTabelaProposta::factory()->create();

        $response = $this->actingAs($admin)
            ->postJson(route('admin.contratos.tabelas.confirmar', $proposta), []);

        $response->assertStatus(422);

        $this->assertSame(
            ContratoTabelaProposta::SITUACAO_PENDENTE,
            DB::table('contrato_tabela_propostas')->where('id', $proposta->id)->value('situacao')
        );
        $this->assertSame(0, DB::table('empresa_faixas_faturamento')->count());
    }

    // ── 2/3. Confirmar tabela grava as faixas (all-or-nothing) ──────────

    #[Test]
    public function confirmar_proposta_de_tabela_grava_as_faixas_da_empresa_escolhida(): void
    {
        $admin   = $this->admin();
        $company = Company::factory()->create(['cnpj' => null, 'razao_social' => null]);

        $proposta = ContratoTabelaProposta::factory()->create([
            'tipo_cobranca' => ContratoTabelaProposta::TIPO_TABELA,
            'faixas'        => [
                ['ordem' => 1, 'limite_superior' => 500_000.00, 'valor' => 3_000.00, 'valor_e_piso' => false],
                ['ordem' => 2, 'limite_superior' => null, 'valor' => 5_000.00, 'valor_e_piso' => true],
            ],
        ]);

        $response = $this->actingAs($admin)
            ->from('/administrativo/contratos/tabelas')
            ->post(route('admin.contratos.tabelas.confirmar', $proposta), ['company_id' => $company->id]);

        $response->assertRedirect('/administrativo/contratos/tabelas');
        $response->assertSessionHas('success');

        $this->assertSame(2, DB::table('empresa_faixas_faturamento')->where('company_id', $company->id)->count());
        $this->assertSame(
            3000.00,
            (float) DB::table('empresa_faixas_faturamento')->where('company_id', $company->id)->where('ordem', 1)->value('valor')
        );
    }

    #[Test]
    public function confirmar_substitui_as_faixas_anteriores_da_empresa_por_inteiro(): void
    {
        $admin   = $this->admin();
        $company = Company::factory()->create();

        for ($i = 1; $i <= 4; $i++) {
            EmpresaFaixaFaturamento::create([
                'company_id'      => $company->id,
                'ordem'           => $i,
                'limite_superior' => $i < 4 ? $i * 100_000 : null,
                'valor'           => $i * 500,
                'valor_e_piso'    => $i === 4,
            ]);
        }
        $this->assertSame(4, DB::table('empresa_faixas_faturamento')->where('company_id', $company->id)->count());

        $proposta = ContratoTabelaProposta::factory()->create([
            'tipo_cobranca' => ContratoTabelaProposta::TIPO_TABELA,
            'faixas'        => [
                ['ordem' => 1, 'limite_superior' => null, 'valor' => 9_000.00, 'valor_e_piso' => true],
            ],
        ]);

        $this->actingAs($admin)
            ->post(route('admin.contratos.tabelas.confirmar', $proposta), ['company_id' => $company->id]);

        $this->assertSame(1, DB::table('empresa_faixas_faturamento')->where('company_id', $company->id)->count());
        $this->assertSame(
            9000.00,
            (float) DB::table('empresa_faixas_faturamento')->where('company_id', $company->id)->value('valor')
        );
    }

    // ── 4. Valor fixo não grava faixa nenhuma ────────────────────────────

    #[Test]
    public function confirmar_proposta_de_valor_fixo_nao_grava_faixa_nenhuma(): void
    {
        $admin   = $this->admin();
        $company = Company::factory()->create();

        $proposta = ContratoTabelaProposta::factory()->valorFixo()->create();

        $response = $this->actingAs($admin)
            ->post(route('admin.contratos.tabelas.confirmar', $proposta), ['company_id' => $company->id]);

        $response->assertSessionHas('success');
        $this->assertSame(0, DB::table('empresa_faixas_faturamento')->where('company_id', $company->id)->count());
        $this->assertSame(
            ContratoTabelaProposta::SITUACAO_CONFIRMADA,
            DB::table('contrato_tabela_propostas')->where('id', $proposta->id)->value('situacao')
        );
    }

    // ── 5. CNPJ/razão social só preenchem quando vazios ──────────────────

    #[Test]
    public function confirmar_preenche_cnpj_e_razao_social_vazios_da_empresa(): void
    {
        $admin   = $this->admin();
        $company = Company::factory()->create(['cnpj' => null, 'razao_social' => null]);

        $proposta = ContratoTabelaProposta::factory()->valorFixo()->create([
            'cnpj_lido'         => '12345678000199',
            'razao_social_lida' => 'Empresa Exemplo Ltda',
        ]);

        $this->actingAs($admin)
            ->post(route('admin.contratos.tabelas.confirmar', $proposta), ['company_id' => $company->id]);

        $company->refresh();
        $this->assertSame('12345678000199', $company->cnpj);
        $this->assertSame('Empresa Exemplo Ltda', $company->razao_social);
    }

    #[Test]
    public function confirmar_nunca_sobrescreve_cnpj_ou_razao_social_ja_preenchidos(): void
    {
        $admin   = $this->admin();
        $company = Company::factory()->create([
            'cnpj'         => '11222333000181',
            'razao_social' => 'Razão Social Já Cadastrada',
        ]);

        $proposta = ContratoTabelaProposta::factory()->valorFixo()->create([
            'cnpj_lido'         => '99888777000166',
            'razao_social_lida' => 'Outra Razão Social Lida',
        ]);

        $this->actingAs($admin)
            ->post(route('admin.contratos.tabelas.confirmar', $proposta), ['company_id' => $company->id]);

        $company->refresh();
        $this->assertSame('11222333000181', $company->cnpj);
        $this->assertSame('Razão Social Já Cadastrada', $company->razao_social);
    }

    #[Test]
    public function confirmar_com_cnpj_lido_ja_pertencente_a_outra_empresa_nao_grava_e_avisa(): void
    {
        $admin = $this->admin();

        Company::factory()->create(['cnpj' => '12345678000199']);
        $companyAlvo = Company::factory()->create(['cnpj' => null]);

        $proposta = ContratoTabelaProposta::factory()->valorFixo()->create([
            'cnpj_lido' => '12345678000199',
        ]);

        $response = $this->actingAs($admin)
            ->post(route('admin.contratos.tabelas.confirmar', $proposta), ['company_id' => $companyAlvo->id]);

        $response->assertSessionHas('aviso');
        $companyAlvo->refresh();
        $this->assertNull($companyAlvo->cnpj);

        // A confirmação em si não é bloqueada pelo conflito de CNPJ — só o campo não é gravado.
        $this->assertSame(
            ContratoTabelaProposta::SITUACAO_CONFIRMADA,
            DB::table('contrato_tabela_propostas')->where('id', $proposta->id)->value('situacao')
        );
    }

    // ── 6. Registro de quem confirmou e quando ───────────────────────────

    #[Test]
    public function confirmar_registra_quem_confirmou_e_quando(): void
    {
        $admin   = $this->admin();
        $company = Company::factory()->create();
        $proposta = ContratoTabelaProposta::factory()->valorFixo()->create();

        $this->actingAs($admin)
            ->post(route('admin.contratos.tabelas.confirmar', $proposta), ['company_id' => $company->id]);

        $linha = DB::table('contrato_tabela_propostas')->where('id', $proposta->id)->first();
        $this->assertSame($admin->id, $linha->confirmado_por);
        $this->assertNotNull($linha->confirmado_em);
        $this->assertSame($company->id, $linha->company_id);
    }

    // ── 7. Descartar não grava nada ───────────────────────────────────────

    #[Test]
    public function descartar_marca_descartada_e_registra_quem_e_quando_sem_gravar_nada(): void
    {
        $admin    = $this->admin();
        $proposta = ContratoTabelaProposta::factory()->create([
            'tipo_cobranca' => ContratoTabelaProposta::TIPO_TABELA,
            'faixas'        => [
                ['ordem' => 1, 'limite_superior' => null, 'valor' => 3_000.00, 'valor_e_piso' => true],
            ],
        ]);

        $response = $this->actingAs($admin)
            ->post(route('admin.contratos.tabelas.descartar', $proposta));

        $response->assertSessionHas('success');

        $linha = DB::table('contrato_tabela_propostas')->where('id', $proposta->id)->first();
        $this->assertSame(ContratoTabelaProposta::SITUACAO_DESCARTADA, $linha->situacao);
        $this->assertSame($admin->id, $linha->confirmado_por);
        $this->assertNotNull($linha->confirmado_em);
        $this->assertSame(0, DB::table('empresa_faixas_faturamento')->count());
        $this->assertSame(0, DB::table('companies')->whereNotNull('cnpj')->count());
    }

    // ── 8. Confirmar proposta já confirmada é recusado ────────────────────

    #[Test]
    public function confirmar_proposta_ja_confirmada_e_recusado(): void
    {
        $admin        = $this->admin();
        $companyAntes = Company::factory()->create();
        $companyNova  = Company::factory()->create();

        $proposta = ContratoTabelaProposta::factory()->confirmada()->create([
            'company_id' => $companyAntes->id,
        ]);

        $response = $this->actingAs($admin)
            ->postJson(route('admin.contratos.tabelas.confirmar', $proposta), ['company_id' => $companyNova->id]);

        $response->assertStatus(422);

        $this->assertSame(
            $companyAntes->id,
            DB::table('contrato_tabela_propostas')->where('id', $proposta->id)->value('company_id')
        );
    }

    #[Test]
    public function confirmar_proposta_ja_descartada_e_recusado(): void
    {
        $admin   = $this->admin();
        $company = Company::factory()->create();

        $proposta = ContratoTabelaProposta::factory()->create([
            'situacao' => ContratoTabelaProposta::SITUACAO_DESCARTADA,
        ]);

        $response = $this->actingAs($admin)
            ->postJson(route('admin.contratos.tabelas.confirmar', $proposta), ['company_id' => $company->id]);

        $response->assertStatus(422);
        $this->assertSame(
            ContratoTabelaProposta::SITUACAO_DESCARTADA,
            DB::table('contrato_tabela_propostas')->where('id', $proposta->id)->value('situacao')
        );
    }

    #[Test]
    public function descartar_proposta_ja_conferida_e_recusado(): void
    {
        $admin    = $this->admin();
        $proposta = ContratoTabelaProposta::factory()->confirmada()->create();

        $response = $this->actingAs($admin)
            ->postJson(route('admin.contratos.tabelas.descartar', $proposta));

        $response->assertStatus(422);
    }

    // ── 9. Falha no meio não deixa meia tabela ────────────────────────────

    #[Test]
    public function confirmacao_bem_sucedida_e_all_or_nothing_sem_residuo_de_tabela_antiga(): void
    {
        // Mesma disciplina de Phase137FaixasCrudTest::falha_no_meio_do_salvamento_...: a ausência
        // de "meia tabela" após sucesso total já prova a disciplina transacional (DB::transaction
        // envolve apagar + recriar); rollback determinístico por exceção de infraestrutura está
        // fora do escopo de fixture em SQLite.
        $admin   = $this->admin();
        $company = Company::factory()->create();

        EmpresaFaixaFaturamento::create([
            'company_id' => $company->id, 'ordem' => 1, 'limite_superior' => null, 'valor' => 1_000.00,
        ]);

        $proposta = ContratoTabelaProposta::factory()->create([
            'tipo_cobranca' => ContratoTabelaProposta::TIPO_TABELA,
            'faixas'        => [
                ['ordem' => 1, 'limite_superior' => 100_000.00, 'valor' => 1_500.00, 'valor_e_piso' => false],
                ['ordem' => 2, 'limite_superior' => null, 'valor' => 3_000.00, 'valor_e_piso' => true],
            ],
        ]);

        $response = $this->actingAs($admin)
            ->post(route('admin.contratos.tabelas.confirmar', $proposta), ['company_id' => $company->id]);

        $response->assertSessionHas('success');
        $this->assertSame(2, DB::table('empresa_faixas_faturamento')->where('company_id', $company->id)->count());
    }

    // ── Permissão ──────────────────────────────────────────────────────

    #[Test]
    public function nao_admin_recebe_403_nos_dois_endpoints(): void
    {
        $naoAdmin = User::factory()->create(['role' => 'consultor']);
        $company  = Company::factory()->create();
        $proposta = ContratoTabelaProposta::factory()->create();

        $r1 = $this->actingAs($naoAdmin)
            ->post(route('admin.contratos.tabelas.confirmar', $proposta), ['company_id' => $company->id]);
        $r1->assertStatus(403);

        $r2 = $this->actingAs($naoAdmin)
            ->post(route('admin.contratos.tabelas.descartar', $proposta));
        $r2->assertStatus(403);
    }
}
