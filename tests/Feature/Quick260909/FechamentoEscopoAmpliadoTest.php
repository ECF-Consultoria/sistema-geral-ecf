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
 * Quick 260909-lge — amplia o escopo do Quick 260909-e8n de "exclui só
 * `polos`" para "só entra `performance`/`shopee`" (`Servico::SETORES_FINANCEIROS`),
 * e cobre a composição da mensalidade (item 4 do plano).
 *
 * Não repete os testes do Quick 260909-e8n (`FechamentoEscopoDaListaTest`,
 * mesmo diretório) — aqueles seguem valendo e são o cinto de segurança de
 * que a válvula de dinheiro do caso "SEM nenhum contrato ativo" continua
 * intacta. Aqui o foco é o caso NOVO: contrato ativo, mas de setor fora do
 * escopo (o setor manda sobre "tem faturamento").
 */
class FechamentoEscopoAmpliadoTest extends TestCase
{
    use RefreshDatabase;

    private function criarAdmin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function servico(string $nome, string $setor, ?string $plataforma = 'Mercado Livre'): Servico
    {
        $servico = Servico::firstOrCreate(
            ['nome' => $nome],
            ['valor_padrao' => 0, 'tipo_cobranca' => Servico::TIPO_MENSAL, 'ativo' => true]
        );
        $servico->update(['setor' => $setor, 'plataforma' => $plataforma]);

        return $servico->refresh();
    }

    private function contratar(Company $company, Servico $servico, float $valorContratado = 0): ContratoServico
    {
        return ContratoServico::create([
            'company_id'       => $company->id,
            'servico_id'       => $servico->id,
            'valor_contratado' => $valorContratado,
            'data_contratacao' => Carbon::now()->toDateString(),
            'ativo'            => true,
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

    public function test_empresa_so_publicacao_com_faturamento_sai_da_lista(): void
    {
        // Antes do Quick 260909-lge, o filtro só excluía o setor `polos` —
        // Publicação com faturamento ficava na lista pela válvula de
        // dinheiro. O usuário citou exatamente este caso em produção.
        $admin   = $this->criarAdmin();
        $company = Company::create(['name' => 'So Publicacao', 'cnpj' => '10000000000201', 'active' => true, 'adman_account_id' => 'ACC201']);
        $this->contratar($company, $this->servico('Publicação', Servico::SETOR_PUBLICACAO));
        $this->faturar($company, 300000.00);

        $this->assertSame([], $this->linhas($admin));
    }

    public function test_empresa_polos_com_faturamento_tambem_sai_da_lista(): void
    {
        // Generalização: a mesma regra vale pra Polos (que antes já saía,
        // mas por um caminho hardcoded que este quick substituiu).
        $admin   = $this->criarAdmin();
        $company = Company::create(['name' => 'Polos Faturando', 'cnpj' => '10000000000202', 'active' => true, 'adman_account_id' => 'ACC202']);
        $this->contratar($company, $this->servico('Polos', Servico::SETOR_POLOS));
        $this->faturar($company, 300000.00);

        $this->assertSame([], $this->linhas($admin));
    }

    public function test_membro_de_grupo_so_publicacao_continua_somando_no_grupo(): void
    {
        // A trava de grupo é inegociável mesmo no caso NOVO: o membro sai da
        // lista como linha própria, mas continua contando na soma do grupo.
        $admin = $this->criarAdmin();
        $grupo = CompanyGroup::create(['name' => 'Grupo Publicacao', 'color' => '#000']);

        $ancora = Company::create(['name' => 'Ancora Gestao', 'cnpj' => '10000000000203', 'active' => true, 'adman_account_id' => 'ACC203', 'company_group_id' => $grupo->id]);
        $this->contratar($ancora, $this->servico('Gestão', Servico::SETOR_PERFORMANCE));
        $this->faturar($ancora, 300000.00);

        $membroPublicacao = Company::create(['name' => 'Membro So Publicacao', 'cnpj' => '10000000000204', 'active' => true, 'adman_account_id' => 'ACC204', 'company_group_id' => $grupo->id]);
        $this->contratar($membroPublicacao, $this->servico('Publicação', Servico::SETOR_PUBLICACAO));
        $this->faturar($membroPublicacao, 200000.00);

        $linhas = $this->linhas($admin);

        $this->assertCount(1, $linhas, 'O grupo vira uma linha só.');
        $this->assertSame('grupo', $linhas[0]['tipo']);
        $this->assertEqualsWithDelta(
            500000.00,
            (float) $linhas[0]['faturamento'],
            0.01,
            'O membro só-Publicação continua somando no grupo — é essa soma que define a faixa cobrada.',
        );
    }

    public function test_empresa_com_polos_mais_shopee_continua_na_lista(): void
    {
        // Confirma que a ampliação não regride o caso já coberto pelo
        // Quick 260909-e8n (Polos + outro serviço cobrável): agora "outro
        // serviço cobrável" também vale para Shopee, não só performance.
        $admin   = $this->criarAdmin();
        $company = Company::create(['name' => 'Polos e Shopee', 'cnpj' => '10000000000205', 'active' => true, 'adman_account_id' => 'ACC205']);
        $this->contratar($company, $this->servico('Polos', Servico::SETOR_POLOS));
        $this->contratar($company, $this->servico('Gestão de ADS Shopee', Servico::SETOR_SHOPEE, 'Shopee'));

        $linhas = $this->linhas($admin);

        $this->assertCount(1, $linhas);
        $this->assertSame('Polos e Shopee', $linhas[0]['name']);
    }

    /**
     * Item 4 do plano — a composição da mensalidade. Caso medido em produção
     * (BARAOSHOP, agosto/2026): faixa 1 de Gestão = R$ 3.000, mais o contrato
     * de Gestão de ADS Shopee = R$ 2.500, total R$ 5.500. Este teste protege
     * os DADOS que a tela usa para montar a composição — `valor_mensal`
     * (faixa) + `servicos_contratados` (para achar o extra) precisam somar
     * exatamente `cobranca_mensal`, sem a tela recalcular nada.
     */
    public function test_props_da_composicao_da_mensalidade_batem_com_o_total(): void
    {
        $admin   = $this->criarAdmin();
        $company = Company::create(['name' => 'Baraoshop Teste', 'cnpj' => '10000000000206', 'active' => true, 'adman_account_id' => 'ACC206']);
        $this->contratar($company, $this->servico('Gestão', Servico::SETOR_PERFORMANCE, 'Mercado Livre'), valorContratado: 0);
        $this->contratar($company, $this->servico('Gestão de ADS Shopee', Servico::SETOR_SHOPEE, 'Shopee'), valorContratado: 2500);
        $this->faturar($company, 488262.90);

        $linhas = $this->linhas($admin);

        $this->assertCount(1, $linhas);
        $empresa = $linhas[0];

        $this->assertSame('Gestão', $empresa['tabela_servico_nome']);
        $this->assertEqualsWithDelta(3000.00, (float) $empresa['valor_mensal'], 0.01, 'Faixa 1 de Gestão é R$ 3.000.');
        $this->assertEqualsWithDelta(5500.00, (float) $empresa['cobranca_mensal'], 0.01, 'Faixa + contrato Shopee.');

        $extras = collect($empresa['servicos_contratados'])
            ->where('tipo_cobranca', 'mensal')
            ->where('valor_contratado', '>', 0);

        $this->assertEqualsWithDelta(2500.00, $extras->sum('valor_contratado'), 0.01);
        $this->assertEqualsWithDelta(
            (float) $empresa['cobranca_mensal'],
            (float) $empresa['valor_mensal'] + $extras->sum('valor_contratado'),
            0.01,
            'valor_mensal (faixa) + extras precisa bater com cobranca_mensal — é essa soma que a tela mostra na composição.',
        );
    }
}
