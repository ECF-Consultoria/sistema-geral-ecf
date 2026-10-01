<?php

namespace Tests\Feature\Quick261001;

use App\Models\Company;
use App\Models\CompanyGroup;
use App\Models\FechamentoSnapshot;
use App\Services\Fechamento\FechamentoSnapshotWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * `fechamento:verificar-consolidacao` não acusa SEM_SNAPSHOT para quem
 * CORRETAMENTE não tem linha no mês.
 *
 * Defeito medido em produção em 2026-10-01: a verificação de setembro saía com
 * exit 1 e `SEM_SNAPSHOT: 3`, e as três eram RELOJOARIA WENUS (#2) e DSG
 * VARIEDADES (#130) — fora pelo GRUPO Wenus marcado — e Rações Soldera (#253),
 * marcada na própria empresa (quick 260916-onn). O comando montava o universo
 * de elegíveis por conta própria e não conhecia a marcação.
 *
 * Por que isso importa mais do que parece: exit 1 em toda competência, para
 * sempre. Alarme que vive aceso ensina a ignorar justamente o mês em que o
 * número está errado — o oposto do que o quick 261001-gi1 construiu.
 *
 * O veredito é o EXIT CODE e o `--json`, nunca o texto impresso.
 */
class SemSnapshotRespeitaForaDoFechamentoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Http::fake();

        Carbon::setTestNow(Carbon::parse('2026-10-01 11:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /** Empresa ativa e com integração financeira — elegível pelo critério antigo. */
    private function empresaComIntegracao(array $overrides = []): Company
    {
        return Company::factory()->create(array_merge([
            'adman_account_id' => '555000111',
            'ml_store_id'      => '555000111',
        ], $overrides));
    }

    /** Linha mínima gravada, só para a competência não ficar vazia. */
    private function gravarLinha(Company $company): void
    {
        DB::table('fechamento_snapshots')->insert([
            'company_id'        => $company->id,
            'company_name'      => $company->name,
            'mes_referencia'    => '2026-09-01',
            'faturamento_total' => null,
            'faturamento_fonte' => FechamentoSnapshot::FONTE_SOMA_DIARIA,
            'cobranca_mensal'   => 3_000.00,
            'estado'            => FechamentoSnapshot::ESTADO_SEM_FATURAMENTO,
            'origem'            => FechamentoSnapshotWriter::ORIGEM_CONSOLIDAR_MES,
            'gerado_em'         => now(),
            'created_at'        => now(),
            'updated_at'        => now(),
        ]);
    }

    /** @return array<int, array<string, mixed>> as entidades acusadas como SEM_SNAPSHOT */
    private function semSnapshot(): array
    {
        Artisan::call('fechamento:verificar-consolidacao', ['--mes' => '2026-09', '--json' => true]);
        $relatorio = json_decode(Artisan::output(), true);

        foreach ($relatorio['inconsistencias'] ?? [] as $i) {
            if (($i['classe'] ?? null) === 'SEM_SNAPSHOT') {
                return $i['entidades'] ?? [];
            }
        }

        return [];
    }

    #[Test]
    public function empresa_marcada_como_fora_do_fechamento_nao_e_acusada_de_falta_de_linha(): void
    {
        $naoParticipa = $this->empresaComIntegracao([
            'name'                      => 'Racoes soldera - Petshopbrasil',
            'fora_do_fechamento'        => true,
            'fora_do_fechamento_motivo' => 'Contrato de Brigada sem tabela progressiva.',
            'fora_do_fechamento_em'     => now(),
        ]);

        // Uma empresa normal COM linha, para a competência existir de verdade.
        $this->gravarLinha($this->empresaComIntegracao(['adman_account_id' => '777000222', 'ml_store_id' => '777000222']));

        $acusadas = array_column($this->semSnapshot(), 'company_id');

        $this->assertNotContains($naoParticipa->id, $acusadas);
        $this->artisan('fechamento:verificar-consolidacao', ['--mes' => '2026-09'])->assertExitCode(0);
    }

    #[Test]
    public function empresa_de_grupo_marcado_nao_e_acusada_de_falta_de_linha(): void
    {
        $grupo = CompanyGroup::create([
            'name'                      => 'Wenus',
            'fora_do_fechamento'        => true,
            'fora_do_fechamento_motivo' => 'Valor fixo de R$ 4.000 por mês, sem tabela progressiva.',
            'fora_do_fechamento_em'     => now(),
        ]);

        $membro = $this->empresaComIntegracao([
            'name'             => 'RELOJOARIA WENUS',
            'company_group_id' => $grupo->id,
        ]);

        $this->gravarLinha($this->empresaComIntegracao(['adman_account_id' => '777000333', 'ml_store_id' => '777000333']));

        $acusadas = array_column($this->semSnapshot(), 'company_id');

        $this->assertNotContains($membro->id, $acusadas);
        $this->artisan('fechamento:verificar-consolidacao', ['--mes' => '2026-09'])->assertExitCode(0);
    }

    #[Test]
    public function empresa_normal_sem_linha_continua_sendo_acusada(): void
    {
        // A trava não pode ficar cega: quem DEVERIA ter linha e não tem segue
        // aparecendo, com exit 1. É o motivo de a classe existir.
        $semLinha = $this->empresaComIntegracao(['name' => 'Empresa que deveria ter linha']);

        $this->gravarLinha($this->empresaComIntegracao(['adman_account_id' => '777000444', 'ml_store_id' => '777000444']));

        $acusadas = array_column($this->semSnapshot(), 'company_id');

        $this->assertContains($semLinha->id, $acusadas);
        $this->artisan('fechamento:verificar-consolidacao', ['--mes' => '2026-09'])->assertExitCode(1);
    }
}
