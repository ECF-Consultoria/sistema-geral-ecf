<?php

namespace Tests\Feature\Desempenho;

use App\Models\Company;
use App\Models\DesempenhoScoreSnapshot;
use App\Models\Servico;
use App\Models\ShopeeMetric;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * `desempenho:consolidar-mes` precisa gravar a linha MENSAL mesmo quando já
 * existe uma linha DIÁRIA ocupando o mesmo par `(user_id, ref_date)`.
 *
 * ### O incidente que originou este arquivo (2026-08-31)
 * Em produção, julho/2026 ficou 2 meses sem fechar: 11 dos 12 profissionais
 * estouravam `SQLSTATE[23000] 1062 Duplicate entry '<user>-2026-07-01'` em
 * TODA rodada (27/08, 28/08, 31/08). Causa: o unique legado
 * `(user_id, ref_date)` sobreviveu à migration `2026_07_09_140001`, que
 * mandava trocá-lo pelo `(user_id, ref_date, mes_referencia)` mas dropava
 * ANTES de criar o substituto — e o MariaDB recusou o drop com 1553 (índice
 * necessário à FK de `user_id`), recusa engolida pelo `try/catch` de
 * idempotência. Corrigido pela migration
 * `2026_08_31_150000_drop_unique_legado_desempenho_score_snapshots`.
 *
 * ### Leia isto antes de confiar que a suíte protege contra a repetição
 * **Ela não protege sozinha.** Os testes rodam em SQLite, que não exige
 * índice de apoio para FK — lá o drop da `140001` SEMPRE deu certo e o índice
 * legado nunca existiu. Ou seja: o bug era invisível para esta suíte por
 * construção, e continua sendo. É a armadilha nº 3 do §6 de
 * `.planning/learnings/desempenho-bonificacao.md`.
 *
 * O que estes dois testes de fato entregam:
 *  - Teste A trava o ESTADO do schema — se alguém recriar o unique legado numa
 *    migration futura, ele acusa (inclusive em SQLite).
 *  - Teste B trava o COMPORTAMENTO — diária e mensal coexistindo na mesma
 *    data, que é a semântica D-02 da Fase 74. Ele quebraria se alguém
 *    rechaveasse o `updateOrCreate` do comando por `ref_date`.
 *
 * A verificação que realmente vale para MariaDB é por reconsulta ao banco de
 * produção (`SHOW INDEX FROM desempenho_score_snapshots`), nunca por esta
 * suíte passando.
 */
class ConsolidarMesComSnapshotDiarioTest extends TestCase
{
    use RefreshDatabase;

    private int $setorId;
    private int $cargoAnalistaId;
    private ?int $servicoPerfId = null;

    protected function setUp(): void
    {
        parent::setUp();

        // Nenhuma requisição real à Adman: o comando chama `compute()` puro.
        Http::preventStrayRequests();
        Http::fake([
            '*/performance/*'       => Http::response([], 404),
            '*/accounts/*/metrics*' => Http::response([], 404),
        ]);

        // As migrations de hoje já semeiam o setor Performance (nome único) e
        // os cargos dele — reaproveitar em vez de inserir de novo.
        $this->setorId = (int) (DB::table('setores')->where('nome', 'Performance')->value('id')
            ?? DB::table('setores')->insertGetId([
                'nome'       => 'Performance',
                'slug'       => 'performance-consolidar-diario',
                'active'     => true,
                'is_system'  => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]));
        $this->cargoAnalistaId = (int) (DB::table('cargos')
            ->where('setor_id', $this->setorId)->where('slug', 'analista')->value('id')
            ?? DB::table('cargos')->insertGetId([
                'setor_id'   => $this->setorId,
                'nome'       => 'Analista',
                'slug'       => 'analista',
                'active'     => true,
                'ordem'      => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    #[Test]
    public function o_unique_legado_user_id_ref_date_nao_existe_mais(): void
    {
        $nomes = collect(DB::select('PRAGMA index_list(desempenho_score_snapshots)'))
            ->pluck('name')
            ->all();

        $this->assertNotContains(
            'desempenho_score_snapshots_user_id_ref_date_unique',
            $nomes,
            'O unique legado (user_id, ref_date) impede a linha mensal de coexistir com a diária '
                . 'na mesma data — foi ele que travou o fechamento de julho/2026 por 2 meses.',
        );

        $this->assertContains(
            'desempenho_score_snapshots_user_ref_mes_unique',
            $nomes,
            'O unique (user_id, ref_date, mes_referencia) precisa existir: é ele que garante a '
                . 'idempotência do consolidar-mes e apoia a FK de user_id.',
        );
    }

    #[Test]
    public function consolidar_mes_grava_a_linha_mensal_mesmo_com_diaria_na_mesma_data(): void
    {
        // Reproduz o cenário exato de produção: hoje é o último dia de agosto,
        // o comando fecha julho, e a linha diária de 01/07 já está lá desde
        // que o `desempenho:snapshot-scores` rodou naquele dia.
        Carbon::setTestNow(Carbon::parse('2026-08-31 14:00:00'));

        // Carteira SHOPEE de propósito: o gate FIXMARG-03 só avalia cobertura
        // de margem quando há empresa Adman elegível. A Shopee não fornece CMV,
        // então `n_elegivel = 0`, cobertura = 1.0 e o gate deixa passar — o que
        // mantém este teste falando sobre o índice, e não sobre a régua de
        // margem. Com fixture Adman ele reprovaria por cobertura 0,0 (a API
        // fakeada devolve 404 e o `calculated_fallback` local foi revogado pelo
        // hotfix de 2026-07-24).
        $user    = $this->criarUserAnalista();
        $company = $this->criarEmpresaElegivel($user, Servico::SETOR_SHOPEE);
        $this->semearShopee($company, '2026-07', 1000.0);
        $this->semearShopee($company, '2026-06', 900.0);

        // A linha DIÁRIA que ocupa (user, 2026-07-01) — `mes_referencia` NULL.
        DesempenhoScoreSnapshot::create([
            'user_id'              => $user->id,
            'ref_date'             => '2026-07-01',
            'mes_referencia'       => null,
            'score'                => 85,
            'classificacao'        => '',
            'tem_base_comparativa' => true,
            'empresas_carteira'    => 1,
            'empresas_eligiveis'   => 1,
            'breakdown_json'       => [],
        ]);

        $this->artisan('desempenho:consolidar-mes', ['--mes' => '2026-07'])
            ->assertExitCode(0);

        // Reconsulta ao banco — o produto é a linha gravada, nunca o stdout
        // (learnings §4). As DUAS precisam coexistir na mesma data.
        $this->assertSame(
            1,
            DesempenhoScoreSnapshot::query()
                ->where('user_id', $user->id)
                ->whereDate('ref_date', '2026-07-01')
                ->whereNull('mes_referencia')
                ->count(),
            'a linha diária de 01/07 não pode ser sobrescrita pela consolidação',
        );

        $this->assertSame(
            1,
            DesempenhoScoreSnapshot::query()
                ->where('user_id', $user->id)
                ->whereDate('mes_referencia', '2026-07-01')
                ->count(),
            'a linha MENSAL de julho precisa existir — é ela que paga o bônus e alimenta o ranking',
        );
    }

    // ═══ Fixtures (espelhadas de V18\ConsolidarMesJanelaNpsTest) ═══════════

    private function criarUserAnalista(string $nome = 'Analista consolidar-diario'): User
    {
        $user = User::factory()->create(['name' => $nome, 'role' => 'consultor', 'active' => true]);

        DB::table('user_setores')->insert([
            'user_id'      => $user->id,
            'setor_id'     => $this->setorId,
            'cargo_id'     => $this->cargoAnalistaId,
            'is_principal' => true,
            'assigned_at'  => now(),
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);

        return $user;
    }

    private function servicoId(string $setor): int
    {
        if ($this->servicoPerfId === null) {
            $this->servicoPerfId = (int) DB::table('servicos')->insertGetId([
                'nome'          => 'Serviço ' . $setor . ' (fixture consolidar-diario)',
                'valor_padrao'  => 0,
                'tipo_cobranca' => Servico::TIPO_MENSAL,
                'ativo'         => true,
                'setor'         => $setor,
                'created_at'    => now(),
                'updated_at'    => now(),
            ]);
        }

        return $this->servicoPerfId;
    }

    private function criarEmpresaElegivel(User $user, string $setor): Company
    {
        $ts = Carbon::parse('-6 months')->toDateTimeString();

        $company = Company::factory()->create();
        $company->timestamps = false;
        $company->forceFill(['created_at' => $ts, 'updated_at' => $ts])->save();
        $company->timestamps = true;

        $servicoId = $this->servicoId($setor);

        DB::table('contratos_servico')->insert([
            'company_id'       => $company->id,
            'servico_id'       => $servicoId,
            'valor_contratado' => 0,
            'data_contratacao' => now()->toDateString(),
            'ativo'            => true,
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);

        DB::table('company_users')->insert([
            'company_id'  => $company->id,
            'user_id'     => $user->id,
            'role'        => 'consultor',
            'servico_id'  => $servicoId,
            'assigned_at' => $ts,
            'created_at'  => $ts,
            'updated_at'  => $ts,
        ]);

        return $company->fresh();
    }

    /** Faturamento Shopee do mês — leitura 100% local, zero HTTP. */
    private function semearShopee(Company $c, string $mesYm, float $revenue): void
    {
        ShopeeMetric::create([
            'company_id'     => $c->id,
            'reference_date' => Carbon::parse($mesYm . '-10')->toDateString(),
            'revenue'        => $revenue,
        ]);
    }
}
