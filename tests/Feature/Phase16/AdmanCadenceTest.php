<?php

namespace Tests\Feature\Phase16;

use App\Jobs\SyncAdmanCompanyJob;
use App\Models\Company;
use App\Models\User;
use App\Services\AdmanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

/**
 * Phase 16 (W1) — Cobre as mudanças de cadência D-1 da API Adman.
 *
 * Verifica:
 *  - Constante AdmanService::ADMAN_RATE_LIMIT_RPM = 10 (W1-T2)
 *  - Comando `adman:sync` faz fan-out com delays incrementais de 7s (W1-T4 Sub-task B)
 */
class AdmanCadenceTest extends TestCase
{
    use RefreshDatabase;

    /** Cria empresa ativa com identificador Adman. */
    private function company(string $name, string $custId): Company
    {
        return Company::create([
            'name'             => $name,
            'cnpj'             => str_pad((string) random_int(1, 99999999999999), 14, '0', STR_PAD_LEFT),
            'adman_account_id' => $custId,
            'active'           => true,
        ]);
    }

    public function test_constante_rate_limit_documentada(): void
    {
        $this->assertSame(10, AdmanService::ADMAN_RATE_LIMIT_RPM,
            'AdmanService::ADMAN_RATE_LIMIT_RPM deve ser 10 conforme limite Adman (2026-05-27).');
    }

    public function test_sync_adman_data_fan_out_com_delay(): void
    {
        Bus::fake([SyncAdmanCompanyJob::class]);

        $c1 = $this->company('Empresa A', 'ACC-A');
        $c2 = $this->company('Empresa B', 'ACC-B');
        $c3 = $this->company('Empresa C', 'ACC-C');

        Artisan::call('adman:sync');

        // Devem ter sido despachados 3 jobs.
        Bus::assertDispatchedTimes(SyncAdmanCompanyJob::class, 3);

        // Inspeciona os jobs despachados — os delays devem ser 0s, 7s e 14s (monotônico).
        $dispatched = collect(Bus::dispatched(SyncAdmanCompanyJob::class))
            ->map(fn ($job) => $job->delay)
            ->values();

        $this->assertCount(3, $dispatched);

        // Job[0]: delay = 0s (now()->addSeconds(0)) — PendingDispatch grava como DateTimeInterface.
        // Comparamos a diferença em segundos com tolerância de 2s pra absorver latência do teste.
        $base = $dispatched->first();
        $this->assertNotNull($base, 'Primeiro job deve ter delay registrado.');

        // O delay pode ser instância de Carbon/DateTime — converter pra timestamp.
        $delays = $dispatched->map(function ($d) {
            if ($d instanceof \DateTimeInterface) {
                return $d->getTimestamp();
            }
            // Se for inteiro (segundos), retorna direto
            return is_int($d) ? $d : 0;
        });

        // Diferenças entre delays consecutivos = 7s (com tolerância).
        $diff01 = $delays[1] - $delays[0];
        $diff12 = $delays[2] - $delays[1];

        $this->assertEqualsWithDelta(7, $diff01, 1,
            "Delay entre job 0 e job 1 deve ser 7s, obtido: {$diff01}");
        $this->assertEqualsWithDelta(7, $diff12, 1,
            "Delay entre job 1 e job 2 deve ser 7s, obtido: {$diff12}");
    }

    public function test_sync_adman_data_sem_empresas_nao_despacha(): void
    {
        Bus::fake([SyncAdmanCompanyJob::class]);

        Artisan::call('adman:sync');

        Bus::assertNotDispatched(SyncAdmanCompanyJob::class);
    }
}
