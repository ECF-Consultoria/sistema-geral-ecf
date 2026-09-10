<?php

namespace Tests\Feature\Quick260909;

use App\Models\AdmanMetric;
use App\Models\Company;
use App\Models\CompanyGroup;
use App\Models\ContratoServico;
use App\Models\Servico;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Quick 260909-e8n — escopo da lista de fechamento.
 *
 * A tela responde "quem a ECF vai cobrar neste mês": cadastro pendente,
 * empresa sem serviço e empresa só de Polos não pertencem a essa pergunta.
 *
 * O que estes testes protegem de verdade são as TRAVAS: esconder linha que
 * representa dinheiro é pior do que a poluição que o filtro resolve. Foi o
 * caso real medido em produção (2026-09-09) — três clientes Shopee com
 * `status = 'pendente'` e R$ 2.000/mês de cobrança cada, e uma empresa de
 * contratos inativos com R$ 204.427 dentro de um grupo, cuja saída mudaria
 * a faixa cobrada do grupo inteiro.
 */
class FechamentoEscopoDaListaTest extends TestCase
{
    use RefreshDatabase;

    private function criarAdmin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function servico(string $nome, string $setor): Servico
    {
        $servico = Servico::firstOrCreate(
            ['nome' => $nome],
            ['valor_padrao' => 0, 'tipo_cobranca' => Servico::TIPO_MENSAL, 'ativo' => true]
        );
        $servico->update(['setor' => $setor, 'plataforma' => 'Mercado Livre']);

        return $servico->refresh();
    }

    private function contratar(Company $company, Servico $servico, bool $ativo = true): ContratoServico
    {
        return ContratoServico::create([
            'company_id'       => $company->id,
            'servico_id'       => $servico->id,
            'valor_contratado' => 0,
            'data_contratacao' => Carbon::now()->toDateString(),
            'ativo'            => $ativo,
        ]);
    }

    private function faturar(Company $company, float $revenue): void
    {
        AdmanMetric::create([
            'company_id'     => $company->id,
            'reference_date' => Carbon::now()->startOfMonth()->toDateString(),
            'revenue'        => $revenue,
            'synced_at'      => now(),
            'raw_data'       => [],
        ]);
    }

    /** @return array<int, array<string, mixed>> */
    private function linhas(User $admin): array
    {
        $response = $this->actingAs($admin)->get('/administrativo/financeiro');
        $response->assertOk();

        return $response->viewData('page')['props']['companies'];
    }

    public function test_empresa_sem_contrato_de_servico_sai_da_lista(): void
    {
        $admin = $this->criarAdmin();
        Company::create(['name' => 'Sem Servico', 'cnpj' => '10000000000101', 'active' => true, 'adman_account_id' => 'ACC101']);

        $this->assertSame([], $this->linhas($admin));
    }

    public function test_empresa_so_de_polos_sai_da_lista(): void
    {
        $admin   = $this->criarAdmin();
        $company = Company::create(['name' => 'So Polos', 'cnpj' => '10000000000102', 'active' => true, 'adman_account_id' => 'ACC102']);
        $this->contratar($company, $this->servico('Polos', Servico::SETOR_POLOS));

        $this->assertSame([], $this->linhas($admin));
    }

    public function test_cadastro_pendente_sai_da_lista(): void
    {
        $admin   = $this->criarAdmin();
        $company = Company::create(['name' => 'Pendente', 'cnpj' => '10000000000103', 'active' => true, 'status' => 'pendente', 'adman_account_id' => 'ACC103']);
        $this->contratar($company, $this->servico('Gestão', Servico::SETOR_PERFORMANCE));

        $this->assertSame([], $this->linhas($admin));
    }

    public function test_empresa_com_polos_mais_outro_servico_continua_na_lista(): void
    {
        $admin   = $this->criarAdmin();
        $company = Company::create(['name' => 'Polos e Gestao', 'cnpj' => '10000000000104', 'active' => true, 'adman_account_id' => 'ACC104']);
        $this->contratar($company, $this->servico('Polos', Servico::SETOR_POLOS));
        $this->contratar($company, $this->servico('Gestão', Servico::SETOR_PERFORMANCE));

        $linhas = $this->linhas($admin);

        $this->assertCount(1, $linhas);
        $this->assertSame('Polos e Gestao', $linhas[0]['name']);
    }

    public function test_trava_faturamento_mantem_pendente_que_esta_faturando(): void
    {
        // O caso real: cliente Shopee ativo cujo `status` ficou desatualizado
        // no cadastro. Sumir com ele tiraria a cobrança da tela.
        $admin   = $this->criarAdmin();
        $company = Company::create(['name' => 'Pendente Faturando', 'cnpj' => '10000000000105', 'active' => true, 'status' => 'pendente', 'adman_account_id' => 'ACC105']);
        $this->contratar($company, $this->servico('Gestão', Servico::SETOR_PERFORMANCE));
        $this->faturar($company, 150000.00);

        $linhas = $this->linhas($admin);

        $this->assertCount(1, $linhas);
        $this->assertSame('Pendente Faturando', $linhas[0]['name']);
    }

    public function test_trava_faturamento_mantem_empresa_sem_servico_que_esta_faturando(): void
    {
        $admin   = $this->criarAdmin();
        $company = Company::create(['name' => 'Sem Servico Faturando', 'cnpj' => '10000000000106', 'active' => true, 'adman_account_id' => 'ACC106']);
        $this->faturar($company, 200000.00);

        $linhas = $this->linhas($admin);

        $this->assertCount(1, $linhas);
        $this->assertSame('Sem Servico Faturando', $linhas[0]['name']);
    }

    public function test_trava_de_grupo_preserva_a_soma_do_grupo(): void
    {
        // Membro sem contrato ativo, dentro de grupo: se saísse da lista, o
        // faturamento dele sairia junto da soma do grupo e a faixa cobrada
        // do grupo poderia cair.
        $admin = $this->criarAdmin();
        $grupo = CompanyGroup::create(['name' => 'Grupo Teste', 'color' => '#000']);

        $ancora = Company::create(['name' => 'Ancora', 'cnpj' => '10000000000107', 'active' => true, 'adman_account_id' => 'ACC107', 'company_group_id' => $grupo->id]);
        $this->contratar($ancora, $this->servico('Gestão', Servico::SETOR_PERFORMANCE));
        $this->faturar($ancora, 300000.00);

        $membroSemContrato = Company::create(['name' => 'Membro Sem Contrato', 'cnpj' => '10000000000108', 'active' => true, 'adman_account_id' => 'ACC108', 'company_group_id' => $grupo->id]);
        $this->faturar($membroSemContrato, 200000.00);

        $linhas = $this->linhas($admin);

        $this->assertCount(1, $linhas, 'O grupo vira uma linha só.');
        $this->assertSame('grupo', $linhas[0]['tipo']);
        $this->assertEqualsWithDelta(
            500000.00,
            (float) $linhas[0]['faturamento'],
            0.01,
            'O membro sem contrato continua somando no grupo — é essa soma que define a faixa cobrada.',
        );
    }
}
