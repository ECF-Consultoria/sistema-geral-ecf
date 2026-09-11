<?php

namespace Tests\Feature\Quick260911;

use App\Models\AdmanMetric;
use App\Models\Company;
use App\Models\CompanyGroup;
use App\Models\ContratoServico;
use App\Models\FechamentoGrupoSnapshot;
use App\Models\FechamentoSnapshot;
use App\Models\Servico;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Quick 260911-kio (T3) — queda brusca de faturamento fica visível.
 *
 * A tela já destaca quem SOBE de faixa; faltava o irmão simétrico. Dois
 * casos medidos em produção (2026-09-11), os dois cobrados e com as duas
 * fontes concordando: MOVELOVEOFICIAL (R$ 494.502 em julho → R$ 22.493 em
 * agosto, cobrando R$ 4.000) e ARMONARE (R$ 112.467 → R$ 6.782, cobrando
 * R$ 3.000). No meio de 202 linhas, isso passava despercebido.
 *
 * ⚠️ A armadilha desta tarefa é a chave sair em UNS literais de linha e
 * não em outros — foi assim que nasceu o `cobranca_mensal_grupo` fantasma
 * nesta mesma tela (usado 7× no JSX e nunca emitido pelo backend). Por
 * isso o teste mais importante daqui é o que cobre os CINCO ramos.
 */
class QuedaBruscaDeFaturamentoTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function criarAdmin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function criarServicoGestao(): Servico
    {
        $servico = Servico::firstOrCreate(
            ['nome' => 'Gestão'],
            ['valor_padrao' => 0, 'tipo_cobranca' => Servico::TIPO_MENSAL, 'ativo' => true]
        );
        $servico->update(['plataforma' => 'Mercado Livre', 'setor' => Servico::SETOR_PERFORMANCE]);

        return $servico->refresh();
    }

    private function criarEmpresaComContrato(Servico $servico, array $overrides = []): Company
    {
        $company = Company::factory()->create(array_merge([
            'adman_account_id' => 'cust-'.uniqid(),
        ], $overrides));

        ContratoServico::create([
            'company_id'       => $company->id,
            'servico_id'       => $servico->id,
            'valor_contratado' => 0,
            'data_contratacao' => '2026-01-10',
            'ativo'            => true,
        ]);

        return $company;
    }

    private function faturar(Company $company, string $data, float $revenue): void
    {
        AdmanMetric::create([
            'company_id'     => $company->id,
            'reference_date' => $data,
            'revenue'        => $revenue,
        ]);
    }

    private function snapshot(Company $company, string $mes, array $overrides = []): FechamentoSnapshot
    {
        return FechamentoSnapshot::create(array_merge([
            'company_id'        => $company->id,
            'mes_referencia'    => $mes,
            'company_name'      => $company->name,
            'company_group_id'  => $company->company_group_id,
            'faturamento_total' => 0,
            'estado'            => FechamentoSnapshot::ESTADO_OK,
            'origem'            => FechamentoSnapshot::ORIGEM_CONSOLIDAR_MES,
            'gerado_em'         => Carbon::parse($mes)->endOfMonth(),
        ], $overrides));
    }

    /** @return array<int, array<string, mixed>> */
    private function linhasDaTela(): array
    {
        $response = $this->actingAs($this->criarAdmin())->get('/administrativo/financeiro');
        $response->assertOk();

        return $response->viewData('page')['props']['companies'];
    }

    private function linhaDe(array $linhas, int $companyId): array
    {
        foreach ($linhas as $linha) {
            if (($linha['id'] ?? null) === $companyId) {
                return $linha;
            }

            foreach ($linha['filhas'] ?? [] as $filha) {
                if (($filha['id'] ?? null) === $companyId) {
                    return $filha;
                }
            }
        }

        $this->fail("A empresa {$companyId} não apareceu na tela de fechamento.");
    }

    // ─── A REGRA ────────────────────────────────────────────────────────

    public function test_queda_de_mais_da_metade_com_mes_anterior_acima_do_piso_e_marcada(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-15'));

        $company = $this->criarEmpresaComContrato($this->criarServicoGestao());
        // O caso ARMONARE, em escala: R$ 112.467 → R$ 6.782.
        $this->faturar($company, '2026-08-10', 112_467.67);
        $this->faturar($company, '2026-09-05', 6_782.30);

        $linha = $this->linhaDe($this->linhasDaTela(), $company->id);

        $this->assertTrue($linha['queda_brusca'], 'Faturou 6% do mês anterior, com mês anterior de R$ 112 mil — precisa ficar visível.');
    }

    public function test_queda_de_mais_da_metade_com_mes_anterior_abaixo_do_piso_nao_e_marcada(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-15'));

        $company = $this->criarEmpresaComContrato($this->criarServicoGestao());
        // Mês anterior de R$ 9.999 — um centavo abaixo do piso de R$ 10.000.
        $this->faturar($company, '2026-08-10', 9_999.00);
        $this->faturar($company, '2026-09-05', 10.00);

        $linha = $this->linhaDe($this->linhasDaTela(), $company->id);

        $this->assertFalse($linha['queda_brusca'], 'Sem o piso, R$ 200 caindo para R$ 50 viraria alarme — a marca perderia o sentido.');
    }

    public function test_queda_de_menos_da_metade_nao_e_marcada(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-15'));

        $company = $this->criarEmpresaComContrato($this->criarServicoGestao());
        $this->faturar($company, '2026-08-10', 100_000.00);
        // Exatamente a metade não é "abaixo da metade" — a regra é estrita.
        $this->faturar($company, '2026-09-05', 50_000.00);

        $linha = $this->linhaDe($this->linhasDaTela(), $company->id);

        $this->assertFalse($linha['queda_brusca']);
    }

    public function test_mes_anterior_ausente_nao_marca_e_nao_quebra(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-15'));

        $company = $this->criarEmpresaComContrato($this->criarServicoGestao());
        $this->faturar($company, '2026-09-05', 1_000.00);

        $linha = $this->linhaDe($this->linhasDaTela(), $company->id);

        $this->assertFalse($linha['queda_brusca'], 'Sem mês anterior não há queda nenhuma para afirmar — nunca um erro.');
    }

    public function test_faturamento_do_mes_ausente_nao_marca(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-15'));

        $company = $this->criarEmpresaComContrato($this->criarServicoGestao());
        $this->faturar($company, '2026-08-10', 500_000.00);
        // Nenhuma métrica em setembro: faturamento do mês é ausência de
        // dado, não "faturou zero". Tratar como zero transformaria toda
        // falha de leitura em alarme de queda.

        $linha = $this->linhaDe($this->linhasDaTela(), $company->id);

        $this->assertFalse($linha['queda_brusca']);
    }

    public function test_zero_medido_marca_quando_o_mes_anterior_estava_acima_do_piso(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-15'));

        $company = $this->criarEmpresaComContrato($this->criarServicoGestao());
        // O caso MOVELOVEOFICIAL em setembro: a loja parou mesmo, e a
        // métrica do mês existe somando zero.
        $this->faturar($company, '2026-08-10', 494_502.34);
        $this->faturar($company, '2026-09-05', 0.00);

        $linha = $this->linhaDe($this->linhasDaTela(), $company->id);

        $this->assertTrue($linha['queda_brusca'], 'Zero MEDIDO é queda de verdade — diferente de ausência de dado.');
    }

    // ─── OS CINCO LITERAIS DE LINHA ─────────────────────────────────────

    public function test_literal_1_empresa_ao_vivo_emite_a_chave(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-15'));

        $company = $this->criarEmpresaComContrato($this->criarServicoGestao());
        $this->faturar($company, '2026-09-05', 300_000.00);

        $this->assertArrayHasKey('queda_brusca', $this->linhaDe($this->linhasDaTela(), $company->id));
    }

    public function test_literal_2_empresa_congelada_sem_linha_na_competencia_emite_a_chave(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-15'));

        $gestao = $this->criarServicoGestao();

        // A competência de setembro está fechada por causa DESTA empresa...
        $comLinha = $this->criarEmpresaComContrato($gestao);
        $this->snapshot($comLinha, '2026-09-01', ['faturamento_total' => 300_000.00]);

        // ...mas esta outra entrou depois e não tem linha nela.
        $semLinha = $this->criarEmpresaComContrato($gestao);
        $this->faturar($semLinha, '2026-09-05', 50_000.00);

        $linha = $this->linhaDe($this->linhasDaTela(), $semLinha->id);

        $this->assertArrayHasKey('queda_brusca', $linha);
        $this->assertFalse($linha['queda_brusca'], 'Sem linha na competência não há faturamento deste mês para comparar com nada.');
    }

    public function test_literal_3_empresa_congelada_com_linha_emite_a_chave_e_le_o_mes_anterior_congelado(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-15'));

        $company = $this->criarEmpresaComContrato($this->criarServicoGestao());
        $this->snapshot($company, '2026-08-01', ['faturamento_total' => 494_502.34]);
        $this->snapshot($company, '2026-09-01', ['faturamento_total' => 22_493.45]);

        $linha = $this->linhaDe($this->linhasDaTela(), $company->id);

        $this->assertArrayHasKey('queda_brusca', $linha);
        $this->assertTrue($linha['queda_brusca'], 'Competência congelada precisa marcar a queda lendo o que FOI congelado nos dois meses — sem recalcular nada.');
    }

    public function test_literal_3_congelado_respeita_o_piso_do_mes_anterior(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-15'));

        $company = $this->criarEmpresaComContrato($this->criarServicoGestao());
        $this->snapshot($company, '2026-08-01', ['faturamento_total' => 5_000.00]);
        $this->snapshot($company, '2026-09-01', ['faturamento_total' => 100.00]);

        $this->assertFalse($this->linhaDe($this->linhasDaTela(), $company->id)['queda_brusca']);
    }

    public function test_literal_4_grupo_ao_vivo_emite_a_chave(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-15'));

        $gestao = $this->criarServicoGestao();
        $grupo  = CompanyGroup::create(['name' => 'Grupo Queda', 'color' => '#ffe600']);

        $membro = $this->criarEmpresaComContrato($gestao, ['company_group_id' => $grupo->id]);
        $this->faturar($membro, '2026-08-10', 400_000.00);
        $this->faturar($membro, '2026-09-05', 1_000.00);

        $linhas = $this->linhasDaTela();
        $linhaGrupo = collect($linhas)->firstWhere('tipo', 'grupo');

        $this->assertNotNull($linhaGrupo, 'A linha de grupo precisa existir para o literal 4 ser exercido.');
        $this->assertArrayHasKey('queda_brusca', $linhaGrupo);
        $this->assertFalse($linhaGrupo['queda_brusca'], 'Grupo ficou FORA do escopo deste quick — a soma mistura empresas que podem ter entrado e saído.');

        // A empresa-membro, dentro de `filhas`, continua marcada. Busca
        // direta em `filhas` de propósito: a linha de GRUPO reaproveita o
        // `id` da empresa-âncora, então procurar por id no topo devolveria
        // a linha do grupo, não a da empresa.
        $filha = collect($linhaGrupo['filhas'])->firstWhere('id', $membro->id);

        $this->assertNotNull($filha, 'A empresa-membro precisa aparecer dentro de `filhas`.');
        $this->assertTrue($filha['queda_brusca'], 'O escopo deste quick é a linha de EMPRESA — e a empresa-membro é uma.');
    }

    public function test_literal_5_grupo_congelado_emite_a_chave(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-15'));

        $gestao = $this->criarServicoGestao();
        $grupo  = CompanyGroup::create(['name' => 'Grupo Congelado', 'color' => '#ffe600']);

        $membro = $this->criarEmpresaComContrato($gestao, ['company_group_id' => $grupo->id]);
        $this->snapshot($membro, '2026-08-01', ['faturamento_total' => 400_000.00]);
        $this->snapshot($membro, '2026-09-01', ['faturamento_total' => 1_000.00]);

        foreach (['2026-08-01' => 400_000.00, '2026-09-01' => 1_000.00] as $mes => $total) {
            FechamentoGrupoSnapshot::create([
                'company_group_id'  => $grupo->id,
                'mes_referencia'    => $mes,
                'grupo_name'        => $grupo->name,
                'faturamento_total' => $total,
                'empresas_count'    => 1,
                'empresa_ancora_id' => $membro->id,
                'estado'            => FechamentoSnapshot::ESTADO_OK,
                'origem'            => FechamentoSnapshot::ORIGEM_CONSOLIDAR_MES,
                'gerado_em'         => Carbon::parse($mes)->endOfMonth(),
            ]);
        }

        $linhaGrupo = collect($this->linhasDaTela())->firstWhere('tipo', 'grupo');

        $this->assertNotNull($linhaGrupo);
        $this->assertArrayHasKey('queda_brusca', $linhaGrupo);
        $this->assertFalse($linhaGrupo['queda_brusca'], 'Grupo congelado também fica fora do escopo — mas a chave precisa sair.');
    }

    public function test_toda_linha_da_tela_traz_a_chave_nunca_algumas_so(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-15'));

        $gestao = $this->criarServicoGestao();
        $grupo  = CompanyGroup::create(['name' => 'Grupo Misto', 'color' => '#ffe600']);

        $solta = $this->criarEmpresaComContrato($gestao);
        $this->faturar($solta, '2026-09-05', 120_000.00);

        $membro = $this->criarEmpresaComContrato($gestao, ['company_group_id' => $grupo->id]);
        $this->faturar($membro, '2026-09-05', 80_000.00);

        foreach ($this->linhasDaTela() as $linha) {
            $this->assertArrayHasKey('queda_brusca', $linha, "A linha \"{$linha['name']}\" não emitiu queda_brusca — é assim que nasce propriedade fantasma no JSX.");

            foreach ($linha['filhas'] ?? [] as $filha) {
                $this->assertArrayHasKey('queda_brusca', $filha, "A linha-membro \"{$filha['name']}\" não emitiu queda_brusca.");
            }
        }
    }

    // ─── O JSX CONSOME A CHAVE ──────────────────────────────────────────

    public function test_o_jsx_consome_queda_brusca_na_linha_e_no_filtro(): void
    {
        $conteudo = file_get_contents(resource_path('js/Pages/Admin/Financeiro.jsx'));

        $this->assertStringContainsString(
            'empresa.queda_brusca',
            $conteudo,
            'Sem a tag na linha o backend pode estar perfeito e a queda continuar invisível.'
        );
        $this->assertStringContainsString('caiu mais da metade', $conteudo);
        $this->assertMatchesRegularExpression("/key:\s*'queda'/", $conteudo, 'Precisa existir o chip de filtro.');
        $this->assertStringContainsString("filtroChip === 'queda'", $conteudo, 'O chip precisa ter lógica de filtro correspondente.');
    }
}
