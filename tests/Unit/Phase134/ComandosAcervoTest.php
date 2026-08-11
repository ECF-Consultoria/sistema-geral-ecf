<?php

namespace Tests\Unit\Phase134;

use App\Jobs\SyncMlAcervoCompanyJob;
use App\Jobs\SyncMlAcervoDetalheJob;
use App\Models\Company;
use App\Models\MlAcervoItem;
use App\Models\MlAcervoMetricaDiaria;
use App\Models\MlToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Fase 134 (Plano 06) — comandos artisan de orquestração do acervo Mercado
 * Livre: fan-out diário (`mlb:sync-acervo`) e retenção (`mlb:acervo-cleanup`).
 *
 * Trava os comportamentos que mais podem quebrar em silêncio:
 *   (1) o fan-out enfileira 1 job de camada barata por empresa COM token —
 *       empresa sem token não gera job (mesmo comportamento de ml:sync)
 *   (2) N da rotação vem da OPÇÃO do comando, não de constante (D-23) — o
 *       gate literal exigido pelo plano
 *   (3) --company restringe o fan-out a uma única empresa
 *   (4) a retenção remove a série antiga e preserva a recente, com a
 *       fronteira exata de 90 vs 91 dias (D-07)
 *   (5) --orfaos só apaga com a flag ligada — token temporariamente
 *       inativo não pode custar o último snapshot (D-08)
 *
 * Estratégia: RefreshDatabase (SQLite in-memory) + Queue::fake() — nunca
 * despacha job de verdade, nunca chama o ML.
 *
 * @group phase134
 */
class ComandosAcervoTest extends TestCase
{
    use RefreshDatabase;

    // ─── Teste 1 — fan-out só gera job pra empresa COM token ────────────────

    /** @test */
    public function fan_out_enfileira_um_job_de_barata_por_empresa_com_token(): void
    {
        Queue::fake();

        $comToken = collect(range(1, 3))->map(fn () => $this->criarEmpresaComToken());
        $semToken = collect(range(1, 2))->map(fn () => Company::factory()->create(['active' => true]));

        $this->artisan('mlb:sync-acervo', ['--so-barata' => true])->assertExitCode(0);

        Queue::assertPushed(SyncMlAcervoCompanyJob::class, 3);

        $idsComJob = [];
        Queue::assertPushed(SyncMlAcervoCompanyJob::class, function ($job) use (&$idsComJob) {
            $idsComJob[] = $job->company->id;

            return true;
        });

        foreach ($comToken as $company) {
            $this->assertContains($company->id, $idsComJob, 'empresa com token ativo tem que gerar job');
        }

        foreach ($semToken as $company) {
            $this->assertNotContains($company->id, $idsComJob, 'empresa sem token não pode gerar job');
        }
    }

    // ─── Teste 2 — D-23: N vem da OPÇÃO, não de constante ───────────────────

    /** @test */
    public function n_da_rotacao_vem_da_opcao_e_altera_o_tamanho_da_fatia(): void
    {
        Queue::fake();

        $company = $this->criarEmpresaComToken();
        $this->semearItensAtivos($company, 100);

        $this->artisan('mlb:sync-acervo', ['--so-detalhe' => true, '--n' => 10])->assertExitCode(0);

        $idsN10 = $this->idsDosLotesDeDetalhe();
        $this->assertCount(10, $idsN10, 'N=10 → fatia de 100/10 = 10 ids (D-23, gate literal)');

        Queue::fake(); // limpa o fake da 1ª rodada antes de medir a 2ª

        $this->artisan('mlb:sync-acervo', ['--so-detalhe' => true, '--n' => 4])->assertExitCode(0);

        $idsN4 = $this->idsDosLotesDeDetalhe();
        $this->assertCount(25, $idsN4, 'N=4 → fatia de 100/4 = 25 ids');
    }

    // ─── Teste 3 — --company restringe o fan-out a uma empresa ──────────────

    /** @test */
    public function company_restringe_o_fan_out_a_uma_empresa(): void
    {
        Queue::fake();

        $empresas = collect(range(1, 3))->map(fn () => $this->criarEmpresaComToken());
        $alvo = $empresas[1];

        $this->artisan('mlb:sync-acervo', ['--so-barata' => true, '--company' => $alvo->id])->assertExitCode(0);

        Queue::assertPushed(SyncMlAcervoCompanyJob::class, 1);
        Queue::assertPushed(SyncMlAcervoCompanyJob::class, fn ($job) => $job->company->id === $alvo->id);
    }

    // ─── Teste 4 — D-07: retenção remove antiga, preserva recente, fronteira exata ──

    /** @test */
    public function retencao_remove_serie_antiga_e_preserva_a_recente(): void
    {
        $company = Company::factory()->create();

        foreach ([120, 91, 90, 10] as $dias) {
            MlAcervoMetricaDiaria::create([
                'company_id' => $company->id,
                'ml_item_id' => "MLB_D{$dias}",
                'data' => now()->subDays($dias)->toDateString(),
            ]);
        }

        $this->artisan('mlb:acervo-cleanup')->assertExitCode(0);

        $restantes = MlAcervoMetricaDiaria::where('company_id', $company->id)
            ->pluck('ml_item_id')
            ->all();

        $this->assertEqualsCanonicalizing(
            ['MLB_D90', 'MLB_D10'],
            $restantes,
            'keep-days=90 mantém 90 dias e remove 91 — fronteira exata'
        );
    }

    // ─── Teste 5 — --orfaos só apaga com a flag ligada (D-08) ───────────────

    /** @test */
    public function orfaos_so_apaga_com_a_flag_ligada(): void
    {
        $companyInativa = Company::factory()->create();

        MlToken::create([
            'company_id' => $companyInativa->id,
            'ml_user_id' => '999999999',
            'access_token' => 'fake-access-token',
            'refresh_token' => 'fake-refresh-token',
            'token_type' => 'bearer',
            'scope' => 'read write offline_access',
            'expires_at' => now()->addDays(6),
            'last_refreshed_at' => now(),
            'status' => 'inactive',
            'connected_at' => now(),
        ]);

        MlAcervoItem::create([
            'company_id' => $companyInativa->id,
            'ml_item_id' => 'MLB_ORFAO',
            'status' => 'active',
            'available_quantity' => 5,
            'catalog_listing' => false,
            'origem' => MlAcervoItem::ORIGEM_LEGADO,
        ]);

        $this->artisan('mlb:acervo-cleanup')->assertExitCode(0);

        $this->assertNotNull(
            MlAcervoItem::where('ml_item_id', 'MLB_ORFAO')->first(),
            'sem --orfaos, nada é apagado — token temporariamente inativo não pode custar o snapshot (D-08)'
        );

        $this->artisan('mlb:acervo-cleanup', ['--orfaos' => true])->assertExitCode(0);

        $this->assertNull(
            MlAcervoItem::where('ml_item_id', 'MLB_ORFAO')->first(),
            'com --orfaos, o item de empresa sem token ativo é removido'
        );
    }

    // ─── Helpers ──────────────────────────────────────────────────────────

    /** Cria Company + MlToken ativo (mesmo padrão de ColetaAcervoTest/RotacaoDetalheTest). */
    private function criarEmpresaComToken(): Company
    {
        $company = Company::factory()->create(['active' => true]);

        MlToken::create([
            'company_id' => $company->id,
            'ml_user_id' => (string) random_int(100000000, 999999999),
            'access_token' => 'fake-access-token',
            'refresh_token' => 'fake-refresh-token',
            'token_type' => 'bearer',
            'scope' => 'read write offline_access',
            'expires_at' => now()->addDays(6),
            'last_refreshed_at' => now(),
            'status' => 'active',
            'connected_at' => now(),
        ]);

        return $company;
    }

    /** Semeia N itens ativos (status=active) direto em ml_acervo_itens, sem passar pela camada barata. */
    private function semearItensAtivos(Company $company, int $quantidade): void
    {
        for ($i = 1; $i <= $quantidade; $i++) {
            MlAcervoItem::create([
                'company_id' => $company->id,
                'ml_item_id' => 'MLB' . str_pad((string) $i, 10, '0', STR_PAD_LEFT),
                'status' => 'active',
                'available_quantity' => 5,
                'catalog_listing' => false,
                'origem' => MlAcervoItem::ORIGEM_LEGADO,
            ]);
        }
    }

    /** Junta os ml_item_id de TODOS os SyncMlAcervoDetalheJob enfileirados na execução corrente. */
    private function idsDosLotesDeDetalhe(): array
    {
        $ids = [];

        Queue::assertPushed(SyncMlAcervoDetalheJob::class, function ($job) use (&$ids) {
            $ids = array_merge($ids, $job->mlItemIds);

            return true;
        });

        return $ids;
    }
}
