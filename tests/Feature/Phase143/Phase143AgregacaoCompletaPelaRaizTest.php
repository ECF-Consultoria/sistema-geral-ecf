<?php

namespace Tests\Feature\Phase143;

use App\Models\AdmanMetric;
use App\Models\Company;
use App\Models\CompanyGroup;
use App\Models\Configuracao;
use App\Models\ContratoServico;
use App\Models\GrupoFaixaFaturamento;
use App\Models\Servico;
use App\Models\User;
use App\Services\Fechamento\FechamentoRegraTabela;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Fase 143 Plano 02 — Tarefa 1: os DOIS lugares que o 143-01 deixou
 * agrupando por `company_group_id` cru passam a agregar pela RAIZ da árvore.
 *
 * Por que isto é o risco número 1 da fase: enquanto a tela
 * (`AdminController::fechamento()`) e o comparativo
 * (`fechamento:comparar-mensalidade`) agrupam por subgrupo e
 * `fechamento:consolidar-mes` agrupa por raiz, a pessoa CONFERE quatro
 * linhas e o sistema COBRA uma — divergência silenciosa entre o que se vê e
 * o que se fatura. E o comparativo é justamente a ferramenta de
 * antes×depois que o 143-CONTEXT exige para aprovar a montagem da
 * hierarquia em produção: se ele agrupa errado, a conferência que deveria
 * proteger a decisão é que mente.
 *
 * O fixture é o caso real do 143-CONTEXT (D-02), medido em produção em
 * 2026-09-14: MPozenato + DRossi + Gran Belo + Lyam, 10 empresas,
 * R$ 12.679.411,83 em ago/2026.
 *
 * Toda asserção é por RECONSULTA (props Inertia, `--json` do comando, ou o
 * banco) — nunca pelo texto formatado do console.
 */
class Phase143AgregacaoCompletaPelaRaizTest extends TestCase
{
    use RefreshDatabase;

    /** Faturamento por empresa, por grupo — soma exata dos números medidos em produção. */
    private const FATURAMENTO_MPOZENATO = [3_000_000.00, 812_487.89];   // 3.812.487,89
    private const FATURAMENTO_GRAN_BELO = [5_000_000.00, 977_697.79];   // 5.977.697,79
    private const FATURAMENTO_DROSSI    = [400_000.00, 400_000.00, 400_000.00, 38_304.17]; // 1.238.304,17
    private const FATURAMENTO_LYAM      = [1_000_000.00, 650_921.98];   // 1.650.921,98

    private const TOTAL_DO_CLIENTE = 12_679_411.83;

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

    private function criarMembro(Servico $servico, CompanyGroup $grupo, float $faturamento): Company
    {
        $company = Company::factory()->create([
            'adman_account_id' => 'cust-'.uniqid(),
            'company_group_id' => $grupo->id,
        ]);

        ContratoServico::factory()->paraServico($servico)->create([
            'company_id' => $company->id,
            'ativo'      => true,
        ]);

        AdmanMetric::create([
            'company_id'     => $company->id,
            'reference_date' => '2026-08-10',
            'revenue'        => $faturamento,
        ]);

        return $company;
    }

    /**
     * A tabela do MPozenato: até R$ 5 mi cobra R$ 12.000; acima disso,
     * R$ 21.000 (faixa aberta, piso).
     */
    private function criarTabelaDaRaiz(CompanyGroup $raiz): void
    {
        GrupoFaixaFaturamento::create([
            'company_group_id' => $raiz->id, 'ordem' => 1,
            'limite_superior'  => 5_000_000.00, 'valor' => 12_000.00, 'valor_e_piso' => false,
        ]);
        GrupoFaixaFaturamento::create([
            'company_group_id' => $raiz->id, 'ordem' => 2,
            'limite_superior'  => null, 'valor' => 21_000.00, 'valor_e_piso' => true,
        ]);
    }

    /**
     * @return array{0: CompanyGroup, 1: array<string, CompanyGroup>}
     */
    private function montarCasoMPozenato(bool $comPai): array
    {
        $gestao = $this->criarServicoGestao();

        $raiz = CompanyGroup::create(['name' => 'MPozenato', 'color' => '#000']);

        $subgrupos = [];
        foreach (['DRossi', 'Gran Belo', 'Lyam'] as $nome) {
            $subgrupos[$nome] = CompanyGroup::create(array_filter([
                'name'      => $nome,
                'color'     => '#000',
                'parent_id' => $comPai ? $raiz->id : null,
            ]));
        }

        foreach (self::FATURAMENTO_MPOZENATO as $valor) {
            $this->criarMembro($gestao, $raiz, $valor);
        }
        foreach (self::FATURAMENTO_DROSSI as $valor) {
            $this->criarMembro($gestao, $subgrupos['DRossi'], $valor);
        }
        foreach (self::FATURAMENTO_GRAN_BELO as $valor) {
            $this->criarMembro($gestao, $subgrupos['Gran Belo'], $valor);
        }
        foreach (self::FATURAMENTO_LYAM as $valor) {
            $this->criarMembro($gestao, $subgrupos['Lyam'], $valor);
        }

        $this->criarTabelaDaRaiz($raiz);

        return [$raiz, $subgrupos];
    }

    /** Linhas de GRUPO das props da tela `/administrativo/financeiro`. */
    private function linhasDeGrupoDaTela(User $admin, string $mes = '2026-08'): \Illuminate\Support\Collection
    {
        $response = $this->actingAs($admin)->get("/administrativo/financeiro?mes={$mes}");
        $response->assertOk();

        return collect($response->viewData('page')['props']['companies'])
            ->filter(fn ($l) => ($l['tipo'] ?? 'empresa') === 'grupo')
            ->values();
    }

    /** Roda o comparativo e devolve o array decodificado do `--json`. */
    private function comparativo(string $mes = '2026-08'): array
    {
        $exitCode = Artisan::call('fechamento:comparar-mensalidade', [
            '--mes'  => $mes,
            '--json' => true,
        ]);

        $this->assertSame(0, $exitCode);

        $decodificado = json_decode(Artisan::output(), true);

        $this->assertNotNull($decodificado, 'A saída --json precisa ser parseável.');

        return $decodificado;
    }

    // ─── A tela ao vivo ───────────────────────────────────────────────────

    #[Test]
    public function a_tela_ao_vivo_com_a_arvore_montada_sai_em_uma_linha_por_raiz(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-02'));

        $admin = $this->criarAdmin();
        [$raiz, $subgrupos] = $this->montarCasoMPozenato(comPai: true);

        $linhas = $this->linhasDeGrupoDaTela($admin);

        $this->assertCount(
            1,
            $linhas,
            'A tela tem de mostrar UMA linha de cobrança — a mesma que `fechamento:consolidar-mes` congela. Quatro aqui e uma lá é a divergência que este plano fecha.'
        );

        $linha = $linhas->first();

        $this->assertSame($raiz->id, (int) $linha['company_group_id'], 'A linha é da RAIZ.');
        $this->assertSame('MPozenato', $linha['name'], 'O nome exibido é o do grupo de COBRANÇA, nunca o do subgrupo da âncora.');
        $this->assertSame('MPozenato', $linha['grupo']['name']);
        $this->assertSame($raiz->id, (int) $linha['grupo']['id']);
        $this->assertCount(10, $linha['filhas'], 'As 10 empresas dos quatro grupos entram na composição da mesma linha.');
        $this->assertEqualsWithDelta(self::TOTAL_DO_CLIENTE, (float) $linha['faturamento'], 0.01);

        // R$ 12,68 mi > R$ 5 mi → faixa 2 da tabela da raiz: R$ 21.000.
        $this->assertSame('grupo', $linha['tabela_origem']);
        $this->assertSame(2, (int) $linha['faixa_ordem']);
        $this->assertEqualsWithDelta(21_000.00, (float) $linha['valor_mensal'], 0.01);

        // Nenhum subgrupo vira linha própria na tela.
        foreach ($subgrupos as $nome => $sub) {
            $this->assertNull(
                $linhas->firstWhere('company_group_id', $sub->id),
                "O subgrupo {$nome} não pode virar linha de cobrança própria na tela."
            );
        }
    }

    #[Test]
    public function a_tela_ao_vivo_sem_nenhum_pai_sai_exatamente_como_hoje_quatro_linhas(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-02'));

        $admin = $this->criarAdmin();
        [$raiz, $subgrupos] = $this->montarCasoMPozenato(comPai: false);

        $linhas = $this->linhasDeGrupoDaTela($admin)->keyBy('company_group_id');

        $this->assertCount(
            4,
            $linhas,
            'Regressão zero: enquanto ninguém tem pai, cada grupo é a própria raiz — quatro linhas, idêntico ao de antes da Fase 143.'
        );

        $this->assertEqualsWithDelta(3_812_487.89, (float) $linhas[$raiz->id]['faturamento'], 0.01);
        $this->assertSame('MPozenato', $linhas[$raiz->id]['name']);
        $this->assertSame(1, (int) $linhas[$raiz->id]['faixa_ordem']);
        $this->assertEqualsWithDelta(12_000.00, (float) $linhas[$raiz->id]['valor_mensal'], 0.01);

        $this->assertEqualsWithDelta(1_238_304.17, (float) $linhas[$subgrupos['DRossi']->id]['faturamento'], 0.01);
        $this->assertSame('DRossi', $linhas[$subgrupos['DRossi']->id]['name']);
        $this->assertEqualsWithDelta(5_977_697.79, (float) $linhas[$subgrupos['Gran Belo']->id]['faturamento'], 0.01);
        $this->assertEqualsWithDelta(1_650_921.98, (float) $linhas[$subgrupos['Lyam']->id]['faturamento'], 0.01);

        $this->assertSame(
            'servico',
            $linhas[$subgrupos['Lyam']->id]['tabela_origem'],
            'Sem pai, o subgrupo não alcança a tabela da raiz — continua caindo na régua do serviço, como hoje.'
        );
    }

    // ─── A tela no ramo CONGELADO ─────────────────────────────────────────

    #[Test]
    public function a_tela_congelada_reencontra_o_snapshot_da_raiz(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-02'));

        $admin = $this->criarAdmin();
        [$raiz] = $this->montarCasoMPozenato(comPai: true);

        $this->artisan('fechamento:consolidar-mes', ['--mes' => '2026-08'])->assertExitCode(0);

        $linhas = $this->linhasDeGrupoDaTela($admin);

        $this->assertCount(1, $linhas);

        $linha = $linhas->first();

        // ⚠️ O snapshot de grupo é indexado pela RAIZ desde o 143-01. Se o
        // ramo congelado agrupasse por `company_group_id` cru, cada subgrupo
        // procuraria um snapshot inexistente e a tela exibiria linhas de
        // grupo em BRANCO (faturamento, faixa e mensalidade nulos) numa
        // competência já fechada.
        $this->assertSame($raiz->id, (int) $linha['company_group_id']);
        $this->assertSame('MPozenato', $linha['name']);
        $this->assertEqualsWithDelta(self::TOTAL_DO_CLIENTE, (float) $linha['faturamento'], 0.01);
        $this->assertSame(2, (int) $linha['faixa_ordem']);
        $this->assertCount(10, $linha['filhas']);
    }

    // ─── O comparativo antes×depois ───────────────────────────────────────

    #[Test]
    public function o_comparativo_de_mensalidade_com_a_arvore_montada_compara_raiz_com_raiz(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-02'));

        [$raiz, $subgrupos] = $this->montarCasoMPozenato(comPai: true);

        $relatorio = $this->comparativo();

        $grupos = collect($relatorio['grupos']);

        $this->assertCount(
            1,
            $grupos,
            'Esta é a ferramenta de conferência que precede a montagem em produção: ela tem de mostrar a MESMA linha única que a consolidação congela.'
        );

        $linha = $grupos->first();

        $this->assertSame($raiz->id, (int) $linha['id']);
        $this->assertSame('MPozenato', $linha['nome'], 'O nome comparado é o do grupo de COBRANÇA, nunca o do subgrupo da âncora.');
        $this->assertEqualsWithDelta(self::TOTAL_DO_CLIENTE, (float) $linha['antes']['faturamento_total'], 0.01);
        $this->assertEqualsWithDelta(self::TOTAL_DO_CLIENTE, (float) $linha['depois']['faturamento_total'], 0.01);
        $this->assertSame('tabela_grupo', $linha['depois']['regua']);
        $this->assertSame(2, (int) $linha['depois']['faixa_ordem']);
        $this->assertEqualsWithDelta(21_000.00, (float) $linha['depois']['cobranca_mensal'], 0.01);

        foreach ($subgrupos as $nome => $sub) {
            $this->assertNull(
                $grupos->firstWhere('id', $sub->id),
                "O subgrupo {$nome} não pode aparecer como linha própria no comparativo."
            );
        }
    }

    #[Test]
    public function o_comparativo_de_mensalidade_sem_nenhum_pai_sai_exatamente_como_hoje(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-02'));

        [$raiz, $subgrupos] = $this->montarCasoMPozenato(comPai: false);

        $relatorio = $this->comparativo();

        $grupos = collect($relatorio['grupos'])->keyBy('id');

        $this->assertCount(4, $grupos, 'Regressão zero: sem pai, quatro linhas comparadas, como sempre foi.');

        $this->assertSame('MPozenato', $grupos[$raiz->id]['nome']);
        $this->assertEqualsWithDelta(3_812_487.89, (float) $grupos[$raiz->id]['depois']['faturamento_total'], 0.01);
        $this->assertEqualsWithDelta(12_000.00, (float) $grupos[$raiz->id]['depois']['cobranca_mensal'], 0.01);

        $this->assertSame('DRossi', $grupos[$subgrupos['DRossi']->id]['nome']);
        $this->assertEqualsWithDelta(1_238_304.17, (float) $grupos[$subgrupos['DRossi']->id]['depois']['faturamento_total'], 0.01);
        $this->assertEqualsWithDelta(5_977_697.79, (float) $grupos[$subgrupos['Gran Belo']->id]['depois']['faturamento_total'], 0.01);
        $this->assertEqualsWithDelta(1_650_921.98, (float) $grupos[$subgrupos['Lyam']->id]['depois']['faturamento_total'], 0.01);
    }

    #[Test]
    public function o_comparativo_continua_sem_escrever_nada(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-02'));

        $this->montarCasoMPozenato(comPai: true);

        $this->comparativo();

        $this->assertSame(0, DB::table('fechamento_snapshots')->count());
        $this->assertSame(0, DB::table('fechamento_grupo_snapshots')->count());
        $this->assertNull(
            Configuracao::where('chave', FechamentoRegraTabela::CHAVE)->first(),
            'O comparativo nunca persiste a flag — `forcar()` é só em memória.'
        );
    }

    // ─── A tela e o comando têm de contar a MESMA história ────────────────

    #[Test]
    public function a_tela_ao_vivo_e_a_consolidacao_agrupam_pela_mesma_chave(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-02'));
        Configuracao::set(FechamentoRegraTabela::CHAVE, '1');

        $admin = $this->criarAdmin();
        $this->montarCasoMPozenato(comPai: true);

        // Primeiro a tela AO VIVO (nenhuma competência congelada ainda).
        $linhaAoVivo = $this->linhasDeGrupoDaTela($admin)->first();

        $this->artisan('fechamento:consolidar-mes', ['--mes' => '2026-08'])->assertExitCode(0);

        $snapshot = DB::table('fechamento_grupo_snapshots')->first();

        $this->assertNotNull($snapshot);
        $this->assertSame(
            (int) $snapshot->company_group_id,
            (int) $linhaAoVivo['company_group_id'],
            'A chave de agregação da tela e a do comando PRECISAM ser a mesma — é por ela que a conferência casa com a cobrança.'
        );
        $this->assertEqualsWithDelta(
            (float) $snapshot->faturamento_total,
            (float) $linhaAoVivo['faturamento'],
            0.01,
            'O faturamento que a pessoa aprova na tela tem de ser o que o comando congela.'
        );
        $this->assertEqualsWithDelta(
            (float) $snapshot->cobranca_mensal,
            (float) $linhaAoVivo['cobranca_mensal'],
            0.01
        );
    }
}
