<?php

namespace Tests\Feature\Quick261006;

use App\Models\Company;
use App\Models\CompanyGroup;
use App\Models\ContratoAssinatura;
use App\Models\EmpresaFaixaFaturamento;
use App\Models\GrupoFaixaFaturamento;
use App\Models\Servico;
use App\Models\ServicoFaixaFaturamento;
use App\Services\Fechamento\TabelaDeContratoAssinadoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * Quick 261006-gf5 — `TabelaDeContratoAssinadoService`: contrato assinado pelo próprio sistema
 * carimba a tabela da empresa como CONFIRMADA POR CONTRATO.
 *
 * Cobre as seis bordas obrigatórias do PLAN.md: presumida vira contrato com as MESMAS faixas;
 * `manual` e `contrato` não são sobrescritas (ato humano vence); duas fases do MESMO serviço
 * gravam UMA vez; serviços diferentes com tabelas diferentes não gravam; serviço sem faixas não
 * grava; e empresa de grupo com tabela grava igual (a precedência do resolver decide sozinha).
 *
 * Toda asserção de persistência é por RECONSULTA ao banco (`DB::table`), nunca pelo retorno do
 * método sozinho — disciplina de `.planning/learnings/desempenho-bonificacao.md`.
 */
class Quick261006TabelaDeContratoAssinadoTest extends TestCase
{
    use RefreshDatabase;

    private function service(): TabelaDeContratoAssinadoService
    {
        return app(TabelaDeContratoAssinadoService::class);
    }

    /**
     * Serviço com tabela progressiva própria. `$valorBase` muda a tabela inteira — é assim que o
     * teste de "tabelas diferentes" monta duas réguas distintas.
     */
    private function servicoComTabela(string $nome, float $valorBase = 1_500.00, array $overrides = []): Servico
    {
        $servico = Servico::create(array_merge([
            'nome'          => $nome.' '.uniqid(),
            'valor_padrao'  => 0,
            'tipo_cobranca' => Servico::TIPO_MENSAL,
            'ativo'         => true,
            'setor'         => Servico::SETOR_PERFORMANCE,
            'plataforma'    => 'Mercado Livre',
        ], $overrides));

        ServicoFaixaFaturamento::create([
            'servico_id' => $servico->id, 'ordem' => 1, 'limite_superior' => 300_000.00, 'valor' => $valorBase, 'valor_e_piso' => false,
        ]);
        ServicoFaixaFaturamento::create([
            'servico_id' => $servico->id, 'ordem' => 2, 'limite_superior' => null, 'valor' => $valorBase * 2, 'valor_e_piso' => true,
        ]);

        return $servico;
    }

    private function servicoSemTabela(string $nome = 'Mentoria'): Servico
    {
        return Servico::create([
            'nome'          => $nome.' '.uniqid(),
            'valor_padrao'  => 990.00,
            'tipo_cobranca' => Servico::TIPO_MENSAL,
            'ativo'         => true,
            'setor'         => Servico::SETOR_PERFORMANCE,
        ]);
    }

    /**
     * Contrato ASSINADO da empresa para o serviço, com `servicos_snapshot` congelado — uma
     * entrada por nome de serviço informado (é assim que o pagamento escalonado e o contrato
     * combinado aparecem no snapshot real).
     *
     * @param  array<int, string>  $nomesNoSnapshot
     */
    private function contratoAssinado(Company $company, Servico $servico, array $nomesNoSnapshot): ContratoAssinatura
    {
        return ContratoAssinatura::factory()->assinado()->create([
            'company_id'        => $company->id,
            'servico_id'        => $servico->id,
            'servicos_snapshot' => array_map(fn (string $nome) => [
                'servico'          => $nome,
                'valor_contratado' => 1_847.32,
                'data_contratacao' => '2026-01-15',
                'data_vencimento'  => '2027-01-15',
            ], $nomesNoSnapshot),
        ]);
    }

    private function criarTabelaDaEmpresa(Company $company, string $origem, float $valor = 999.00): void
    {
        EmpresaFaixaFaturamento::create([
            'company_id'      => $company->id,
            'ordem'           => 1,
            'limite_superior' => null,
            'valor'           => $valor,
            'valor_e_piso'    => false,
            'origem'          => $origem,
        ]);
    }

    /**
     * Tabela da empresa reconsultada do banco, no shape comparável — nunca os models crus.
     *
     * @return array<int, array{ordem:int, limite_superior:float|null, valor:float, valor_e_piso:bool, origem:string}>
     */
    private function tabelaNoBanco(Company $company): array
    {
        return DB::table('empresa_faixas_faturamento')
            ->where('company_id', $company->id)
            ->orderBy('ordem')
            ->get()
            ->map(fn ($f) => [
                'ordem'           => (int) $f->ordem,
                'limite_superior' => $f->limite_superior !== null ? (float) $f->limite_superior : null,
                'valor'           => (float) $f->valor,
                'valor_e_piso'    => (bool) $f->valor_e_piso,
                'origem'          => $f->origem,
            ])
            ->all();
    }

    // ── 1. presumida_servico → contrato, mesmas faixas ───────────────────

    #[Test]
    public function tabela_presumida_passa_a_contrato_com_as_mesmas_faixas(): void
    {
        $company = Company::factory()->create();
        $servico = $this->servicoComTabela('Gestão');
        $contrato = $this->contratoAssinado($company, $servico, [$servico->nome]);

        // O estado real medido em produção: a materialização da Fase 141 copiou a tabela do
        // serviço com o selo de presunção.
        foreach (ServicoFaixaFaturamento::where('servico_id', $servico->id)->ordenadas()->get() as $faixa) {
            EmpresaFaixaFaturamento::create([
                'company_id'        => $company->id,
                'ordem'             => $faixa->ordem,
                'limite_superior'   => $faixa->limite_superior,
                'valor'             => $faixa->valor,
                'valor_e_piso'      => $faixa->valor_e_piso,
                'origem'            => EmpresaFaixaFaturamento::ORIGEM_PRESUMIDA_SERVICO,
                'servico_origem_id' => $servico->id,
            ]);
        }

        $antes = $this->tabelaNoBanco($company);

        $resultado = $this->service()->aplicar($contrato);

        $this->assertTrue($resultado['gravou']);
        $this->assertSame(TabelaDeContratoAssinadoService::MOTIVO_GRAVOU, $resultado['motivo']);
        $this->assertSame(EmpresaFaixaFaturamento::ORIGEM_PRESUMIDA_SERVICO, $resultado['origem_anterior']);

        $depois = $this->tabelaNoBanco($company);

        // Mesma QUANTIDADE e mesmos VALORES — só o selo muda.
        $this->assertCount(count($antes), $depois);
        $this->assertSame(
            array_map(fn (array $f) => [$f['ordem'], $f['limite_superior'], $f['valor'], $f['valor_e_piso']], $antes),
            array_map(fn (array $f) => [$f['ordem'], $f['limite_superior'], $f['valor'], $f['valor_e_piso']], $depois),
            'As faixas gravadas tem que ser IDENTICAS as presumidas — o quick muda o selo, nao o valor'
        );

        foreach ($depois as $faixa) {
            $this->assertSame(EmpresaFaixaFaturamento::ORIGEM_CONTRATO, $faixa['origem']);
        }

        $this->assertSame(
            $servico->id,
            (int) DB::table('empresa_faixas_faturamento')->where('company_id', $company->id)->value('servico_origem_id')
        );

        // A porta única de escrita (Fase 142) deixou a trilha com a tabela inteira antes/depois.
        $trilha = Activity::where('log_name', 'faixa_faturamento_tabela')
            ->where('subject_type', Company::class)
            ->where('subject_id', $company->id)
            ->latest('id')
            ->first();

        $this->assertNotNull($trilha, 'A gravacao tem que passar pelo GravarTabelaEmpresaService, que registra a trilha');
        $this->assertSame(TabelaDeContratoAssinadoService::FEITO_DE, $trilha->properties['feito_de']);
        $this->assertSame(EmpresaFaixaFaturamento::ORIGEM_CONTRATO, $trilha->properties['origem_nova']);
    }

    // ── 2. tabela manual não é sobrescrita ───────────────────────────────

    #[Test]
    public function tabela_manual_nao_e_sobrescrita_e_o_motivo_e_registrado(): void
    {
        Log::spy();

        $company  = Company::factory()->create();
        $servico  = $this->servicoComTabela('Gestão');
        $contrato = $this->contratoAssinado($company, $servico, [$servico->nome]);

        $this->criarTabelaDaEmpresa($company, EmpresaFaixaFaturamento::ORIGEM_MANUAL, 777.00);
        $antes = $this->tabelaNoBanco($company);

        $resultado = $this->service()->aplicar($contrato);

        $this->assertFalse($resultado['gravou']);
        $this->assertSame(TabelaDeContratoAssinadoService::MOTIVO_JA_CONFIRMADA, $resultado['motivo']);
        $this->assertSame($antes, $this->tabelaNoBanco($company), 'Ato humano vence: tabela manual nao muda nem uma linha');

        Log::shouldHaveReceived('info')
            ->withArgs(fn (string $mensagem, array $contexto = []) => str_contains($mensagem, 'tabela confirmada'))
            ->atLeast()->once();
    }

    // ── 3. tabela já `contrato` não muda ─────────────────────────────────

    #[Test]
    public function tabela_ja_confirmada_por_contrato_nao_muda(): void
    {
        $company  = Company::factory()->create();
        $servico  = $this->servicoComTabela('Gestão');
        $contrato = $this->contratoAssinado($company, $servico, [$servico->nome]);

        $this->criarTabelaDaEmpresa($company, EmpresaFaixaFaturamento::ORIGEM_CONTRATO, 1_234.00);
        $antes = $this->tabelaNoBanco($company);

        $resultado = $this->service()->aplicar($contrato);

        $this->assertFalse($resultado['gravou']);
        $this->assertSame(TabelaDeContratoAssinadoService::MOTIVO_JA_CONFIRMADA, $resultado['motivo']);
        $this->assertSame($antes, $this->tabelaNoBanco($company));
    }

    #[Test]
    public function rodar_duas_vezes_e_idempotente(): void
    {
        $company  = Company::factory()->create();
        $servico  = $this->servicoComTabela('Gestão');
        $contrato = $this->contratoAssinado($company, $servico, [$servico->nome]);

        $this->service()->aplicar($contrato);
        $primeira = $this->tabelaNoBanco($company);

        // Segunda passada cai no guard de "já confirmada" — nunca reescreve nem duplica.
        $segundo = $this->service()->aplicar($contrato);

        $this->assertSame(TabelaDeContratoAssinadoService::MOTIVO_JA_CONFIRMADA, $segundo['motivo']);
        $this->assertSame($primeira, $this->tabelaNoBanco($company));
    }

    // ── 4. duas fases do MESMO serviço gravam uma vez ────────────────────

    #[Test]
    public function contrato_com_duas_fases_do_mesmo_servico_grava_a_tabela_uma_vez(): void
    {
        $company = Company::factory()->create();
        $servico = $this->servicoComTabela('Gestão');

        // O caso da Maderatto: pagamento escalonado — duas FASES do mesmo serviço, duas entradas
        // no snapshot congelado com o MESMO nome de serviço.
        $contrato = $this->contratoAssinado($company, $servico, [$servico->nome, $servico->nome]);

        $resultado = $this->service()->aplicar($contrato);

        $this->assertTrue($resultado['gravou']);
        $this->assertSame([$servico->id], $resultado['servico_ids'], 'A dedup e por servico_id');

        // Duas faixas (a tabela do serviço), nunca quatro.
        $this->assertSame(2, DB::table('empresa_faixas_faturamento')->where('company_id', $company->id)->count());
        $this->assertSame(
            [1, 2],
            DB::table('empresa_faixas_faturamento')->where('company_id', $company->id)->orderBy('ordem')->pluck('ordem')->map(fn ($o) => (int) $o)->all()
        );
    }

    #[Test]
    public function contrato_combinado_de_servicos_com_a_MESMA_tabela_grava(): void
    {
        $company = Company::factory()->create();
        $dono    = $this->servicoComTabela('Gestão', 1_500.00);
        // Mesma régua, serviço diferente — imprimiu a mesma tabela duas vezes no documento, o
        // que não é divergência nenhuma.
        $combinado = $this->servicoComTabela('Gestão de ADS Shopee', 1_500.00, [
            'contrato_junto_com_servico_id' => $dono->id,
            'plataforma'                    => 'Shopee',
        ]);

        $contrato = $this->contratoAssinado($company, $dono, [$dono->nome, $combinado->nome]);

        $resultado = $this->service()->aplicar($contrato);

        $this->assertTrue($resultado['gravou']);
        $this->assertSame([$dono->id, $combinado->id], $resultado['servico_ids']);
        // Procedência carimbada no DONO do contrato, nunca no combinado.
        $this->assertSame($dono->id, $resultado['servico_origem_id']);
        $this->assertSame(2, DB::table('empresa_faixas_faturamento')->where('company_id', $company->id)->count());
    }

    // ── 5. serviços diferentes com tabelas diferentes não gravam ─────────

    #[Test]
    public function contrato_combinado_com_tabelas_diferentes_nao_grava_e_avisa(): void
    {
        Log::spy();

        $company   = Company::factory()->create();
        $dono      = $this->servicoComTabela('Gestão', 1_500.00);
        $combinado = $this->servicoComTabela('Gestão de ADS Shopee', 2_900.00, [
            'contrato_junto_com_servico_id' => $dono->id,
            'plataforma'                    => 'Shopee',
        ]);

        $contrato = $this->contratoAssinado($company, $dono, [$dono->nome, $combinado->nome]);

        $resultado = $this->service()->aplicar($contrato);

        $this->assertFalse($resultado['gravou']);
        $this->assertSame(TabelaDeContratoAssinadoService::MOTIVO_TABELAS_DIVERGENTES, $resultado['motivo']);
        $this->assertSame(
            0,
            DB::table('empresa_faixas_faturamento')->where('company_id', $company->id)->count(),
            'Adivinhar qual tabela vale e pior que deixar a empresa como presumida'
        );

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $mensagem, array $contexto = []) => str_contains($mensagem, 'tabelas progressivas diferentes'))
            ->atLeast()->once();
    }

    // ── 6. serviço sem faixas não grava ──────────────────────────────────

    #[Test]
    public function servico_sem_tabela_progressiva_nao_grava_nada(): void
    {
        $company  = Company::factory()->create();
        $servico  = $this->servicoSemTabela();
        $contrato = $this->contratoAssinado($company, $servico, [$servico->nome]);

        $resultado = $this->service()->aplicar($contrato);

        $this->assertFalse($resultado['gravou']);
        $this->assertSame(TabelaDeContratoAssinadoService::MOTIVO_SERVICO_SEM_FAIXAS, $resultado['motivo']);
        $this->assertSame(0, DB::table('empresa_faixas_faturamento')->where('company_id', $company->id)->count());
    }

    // ── borda: contrato que não está assinado nunca grava ────────────────

    #[Test]
    public function contrato_que_ainda_nao_foi_assinado_nunca_grava(): void
    {
        $company = Company::factory()->create();
        $servico = $this->servicoComTabela('Gestão');

        $contrato = ContratoAssinatura::factory()->emAndamento()->create([
            'company_id' => $company->id,
            'servico_id' => $servico->id,
        ]);

        $resultado = $this->service()->aplicar($contrato);

        $this->assertFalse($resultado['gravou']);
        $this->assertSame(TabelaDeContratoAssinadoService::MOTIVO_NAO_ASSINADO, $resultado['motivo']);
        $this->assertSame(0, DB::table('empresa_faixas_faturamento')->where('company_id', $company->id)->count());
    }

    // ── borda: empresa de grupo com tabela grava igual ───────────────────

    #[Test]
    public function empresa_de_grupo_com_tabela_recebe_a_tabela_propria_igual(): void
    {
        $company = Company::factory()->create();
        $servico = $this->servicoComTabela('Gestão');
        $contrato = $this->contratoAssinado($company, $servico, [$servico->nome]);

        $grupo = CompanyGroup::create(['name' => 'Grupo Teste '.uniqid()]);
        $company->update(['company_group_id' => $grupo->id]);
        GrupoFaixaFaturamento::create([
            'company_group_id' => $grupo->id, 'ordem' => 1, 'limite_superior' => null, 'valor' => 4_321.00,
        ]);

        $resultado = $this->service()->aplicar($contrato);

        // Grava igual: quem decide que a tabela do grupo manda na cobrança é a precedência de
        // `FechamentoFaixaResolver::paraEmpresa()`, intocada por este quick.
        $this->assertTrue($resultado['gravou']);
        $this->assertSame(2, DB::table('empresa_faixas_faturamento')->where('company_id', $company->id)->count());
        $this->assertSame(
            1,
            DB::table('grupo_faixas_faturamento')->where('company_group_id', $grupo->id)->count(),
            'A tabela do GRUPO nao e tocada'
        );
    }
}
