<?php

namespace App\Console\Commands;

use App\Jobs\SyncAdmanCompanyJob;
use App\Models\Company;
use App\Services\AdmanService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Quick 260930-njd (T3) — relê da Adman os últimos dias JÁ COLETADOS.
 *
 * PROBLEMA que resolve: `adman:sync` grava D-1 uma vez e nunca volta àquele dia.
 * A Adman revisa dias já passados depois da nossa coleta — devoluções,
 * conciliação — e para os dois lados. Medido em produção em 30/09/2026, mesma
 * empresa:
 *
 *   | dia   | guardado (e quando coletamos) | Adman em 30/09 |        |
 *   |-------|-------------------------------|----------------|--------|
 *   | 01/09 | 24.770,86 (02/09 11:06)       | 26.506,96      | +7,0%  |
 *   | 08/09 | 21.014,07 (09/09 11:20)       | 21.876,93      | +4,1%  |
 *   | 22/09 | 27.568,88 (23/09 11:06)       | 27.568,88      |  0,0%  |
 *   | 28/09 | 15.933,13 (29/09 11:07)       | 17.904,08      | +12,4% |
 *
 * `AdmanMetric::updateOrCreate` é idempotente por `(company_id, reference_date)`:
 * a releitura ATUALIZA a linha do dia, nunca duplica.
 *
 * ⚠️ POR QUE UM COMANDO NOVO, e não "agendar `adman:sync --from=<D-5> --to=<D-1>`"
 * como o plano sugeriu: `--from`/`--to` do `adman:sync` só valem no ramo
 * `--company=`. No fan-out para a base inteira as duas opções são IGNORADAS e o
 * comando enfileira `SyncAdmanCompanyJob` sem data — ou seja, D-1 de novo. O
 * agendamento sugerido rodaria todo dia sem reler nada.
 *
 * ⚠️ SEM CAMPANHAS (`incluirCampanhas: false`). `syncCompany()` chama
 * `syncCampaigns()`, que gasta `fetchCampaigns()` (1 chamada) mais uma
 * `fetchCampaignMetrics()` por campanha da conta. Com campanhas, um dia relido
 * custa `2 + N` chamadas em vez de 1, e a releitura de 5 dias × ~84 empresas
 * sairia de ~420 chamadas para milhares — fora do limite de 10 rpm por uma ordem
 * de grandeza. A releitura conserta o FATURAMENTO de dias passados; o histórico
 * de campanhas não é o assunto. Precedente do mesmo tipo:
 * `AdmanService::syncCompanyMarginOnly()`.
 *
 * Custo por rodada: `dias × empresas` chamadas — 5 × ~84 = ~420, a 7 s de
 * intervalo → ~49 min até o último job processar.
 *
 * ⚠️ A releitura mexe em `adman_metrics`, que alimenta CARTEIRA, DESEMPENHO e
 * BÔNUS. Isso é desejado (o dado fica mais perto da verdade), mas este comando
 * NÃO recalcula nem apaga snapshot de desempenho: competência já consolidada só
 * muda por `desempenho:consolidar-mes --mes=`, decisão humana, nunca de carona
 * numa rotina diária.
 *
 * ⚠️ Fila: `SyncAdmanCompanyJob` fica na fila DEFAULT, como no `adman:sync`.
 * Trabalho em lote não vai para a `high` — ela é dos jobs interativos e de
 * webhook (179 jobs de acervo ML seguraram o webhook do Clicksign por horas em
 * 16/09/2026).
 */
class RelerDiasAdman extends Command
{
    protected $signature = 'adman:reler-dias
        {--dias=5 : Quantos dias já fechados reler, de D-1 para trás}
        {--company= : Relê apenas uma empresa (ID), de forma síncrona}';

    protected $description = 'Relê da Adman os últimos dias já coletados (a Adman revisa dias passados depois da nossa coleta). Só as métricas, sem campanhas.';

    /**
     * Intervalo entre jobs, em segundos. 7 s = o mesmo do fan-out do
     * `adman:sync` (`AdmanService::ADMAN_RATE_LIMIT_RPM` = 10 rpm → 6 s teóricos
     * + 1 s de folga). Respeita o limite global mesmo com `numprocs=2` no
     * Supervisor: só 1 job fica "ready" a cada 7 s, independente do número de
     * workers.
     */
    private const INTERVALO_ENTRE_JOBS_SEG = 7;

    /** Teto de segurança: reler muito para trás vira varredura de histórico. */
    private const DIAS_MAXIMO = 31;

    public function handle(AdmanService $adman): int
    {
        $dias = (int) $this->option('dias');

        if ($dias < 1 || $dias > self::DIAS_MAXIMO) {
            $this->error("--dias precisa estar entre 1 e ".self::DIAS_MAXIMO.". Recebido: {$dias}.");

            return self::FAILURE;
        }

        // De D-1 para trás. Nunca HOJE: a Adman é D-1 e o dia em curso vem
        // incompleto — gravá-lo seria trocar um dado bom por um dado pela metade.
        $datas = collect(range(1, $dias))
            ->map(fn (int $n) => Carbon::now()->subDays($n)->toDateString())
            ->values();

        // ── Uma empresa só: síncrono, para conferência manual ──────────────
        if ($companyId = $this->option('company')) {
            $company = Company::findOrFail($companyId);
            $this->info("Relendo {$dias} dia(s) de {$company->name} (custId: {$company->adman_account_id})...");

            $ok = 0;
            $falhas = 0;

            foreach ($datas as $i => $data) {
                if ($i > 0) {
                    $this->pausarEntreChamadas();
                }

                try {
                    $metrica = $adman->syncCompany($company, $data, false);
                    $ok++;
                    $this->line("  {$data}: faturamento R\$ {$metrica->revenue}");
                } catch (\Throwable $e) {
                    $falhas++;
                    $this->warn("  {$data}: {$e->getMessage()}");
                }
            }

            $this->info("Releitura concluída: {$ok} dia(s) atualizado(s), {$falhas} falha(s).");

            return self::SUCCESS;
        }

        // ── Base inteira: fan-out espaçado ────────────────────────────────
        $empresas = $this->empresasParaReler();

        if ($empresas->isEmpty()) {
            $this->info('[RelerDias] Nenhuma empresa ativa com ID Adman/ML — nada a enfileirar.');

            return self::SUCCESS;
        }

        $enfileirados = 0;

        foreach ($empresas as $company) {
            foreach ($datas as $data) {
                SyncAdmanCompanyJob::dispatch($company, $data, false)
                    ->delay(Carbon::now()->addSeconds($enfileirados * self::INTERVALO_ENTRE_JOBS_SEG));

                $enfileirados++;
            }
        }

        $minutos = (int) ceil(($enfileirados * self::INTERVALO_ENTRE_JOBS_SEG) / 60);

        $this->info(
            "[RelerDias] {$empresas->count()} empresa(s) × {$dias} dia(s) = {$enfileirados} job(s) "
            ."enfileirado(s) com intervalo de ".self::INTERVALO_ENTRE_JOBS_SEG."s (~{$minutos}min até o último processar). "
            ."Janela relida: {$datas->last()}..{$datas->first()}."
        );

        return self::SUCCESS;
    }

    /**
     * MESMA população do fan-out do `adman:sync`, e a exclusão do token ML ativo
     * é a parte que não pode mudar: para essas empresas o `adman_metrics` é
     * escrito pelo `ml:sync`, e reler da Adman sobrescreveria o faturamento do ML
     * com o da Adman — exatamente a linha mista (revenue ML + margem Adman) que o
     * cutover de 01/06/2026 existiu para acabar.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, Company>
     */
    private function empresasParaReler(): \Illuminate\Database\Eloquent\Collection
    {
        return Company::query()
            ->where('active', true)
            ->where(function ($q) {
                $q->where(function ($q2) { $q2->whereNotNull('ml_store_id')->where('ml_store_id', '!=', ''); })
                  ->orWhere(function ($q2) { $q2->whereNotNull('adman_account_id')->where('adman_account_id', '!=', ''); });
            })
            ->whereDoesntHave('mlToken', fn ($q) => $q->where('status', 'active'))
            ->get();
    }

    /** Espera entre chamadas do ramo síncrono — no-op durante os testes. */
    protected function pausarEntreChamadas(): void
    {
        if (app()->runningUnitTests()) {
            return;
        }

        sleep(self::INTERVALO_ENTRE_JOBS_SEG);
    }
}
