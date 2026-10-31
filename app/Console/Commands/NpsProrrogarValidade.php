<?php

namespace App\Console\Commands;

use App\Models\NpsGroupSurvey;
use App\Models\NpsSurvey;
use App\Services\Nps\NpsJanelaResolver;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Prorroga a validade dos links de NPS NÃO respondidos de um mês de COLETA
 * (TKT-0029, 30/10/2026).
 *
 * Por que existe: o link nasce valendo até o fim do mês em que foi gerado
 * (`expires_at = endOfMonth`). Quando o mês fecha num fim de semana, o cliente
 * perde o prazo — e gerar link novo no mês seguinte não serve, porque ele
 * entraria no ciclo seguinte.
 *
 * O que faz:
 *  - `nps_surveys` (link individual): competência = `month_reference`, com
 *    fallback `created_at` — a mesma régua de
 *    `NpsElegibilidadeService::competenciaDoSurvey()` e da tela /nps;
 *  - `nps_group_surveys` (link de grupo): `month_reference`;
 *  - move `expires_at` para 23:59:59 do dia `--ate`. Link já marcado `expired`
 *    (alguém abriu depois de vencer) volta para `pending`. Link respondido e
 *    link que já vale até depois de `--ate` não são tocados.
 *
 * O que NÃO faz (limite aceito na decisão do TKT-0029, opção A):
 *  - não muda a competência: o bônus lê a resposta por `completed_at`, então a
 *    resposta que chegar no mês seguinte conta na coleta desse mês seguinte;
 *  - não mexe na nota 1 dos não respondidos (`nps_imputed_assignments`), que
 *    vira definitiva no dia 1 seguinte pela rotina das 09:30;
 *  - não reabre ciclo encerrado à mão (`nps_ciclos`): o link continua
 *    recusando resposta (`NpsController::cicloFechado()`).
 *
 * Grava pelo Eloquent, linha a linha, para o activity log registrar cada troca.
 */
class NpsProrrogarValidade extends Command
{
    protected $signature = 'nps:prorrogar-validade
        {--mes= : Mês de COLETA dos links, AAAA-MM (ex.: 2026-10)}
        {--ate= : Novo último dia de validade, AAAA-MM-DD (vale até 23:59:59)}
        {--dry-run : Só lista o que mudaria, sem gravar}';

    protected $description = 'Prorroga a validade dos links de NPS não respondidos de um mês de coleta';

    public function handle(NpsJanelaResolver $janela): int
    {
        $mesOpt = (string) $this->option('mes');
        $ateOpt = (string) $this->option('ate');

        if (! preg_match('/^\d{4}-\d{2}$/', $mesOpt) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $ateOpt)) {
            $this->error('Informe --mes=AAAA-MM e --ate=AAAA-MM-DD.');

            return self::FAILURE;
        }

        $mes = Carbon::createFromFormat('Y-m-d', $mesOpt.'-01')->startOfMonth();
        $fim = $mes->copy()->endOfMonth();
        $ate = Carbon::createFromFormat('Y-m-d', $ateOpt)->endOfDay();

        // Só prorroga: até o fim do mês o link já vale.
        if ($ate->lte($fim)) {
            $this->error("--ate precisa ser depois de {$fim->format('d/m/Y')} (o link já vale até o fim do mês).");

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');

        $individuais = NpsSurvey::query()
            ->whereIn('status', ['pending', 'expired'])
            ->where(function ($q) use ($mes, $fim) {
                $q->whereBetween('month_reference', [$mes->toDateString(), $fim->toDateString()])
                    ->orWhere(function ($qq) use ($mes, $fim) {
                        $qq->whereNull('month_reference')
                            ->whereBetween('created_at', [$mes, $fim]);
                    });
            })
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '<', $ate))
            ->with('company:id,name')
            ->orderBy('id')
            ->get();

        $grupos = NpsGroupSurvey::query()
            ->whereIn('status', ['pending', 'expired'])
            ->whereBetween('month_reference', [$mes->toDateString(), $fim->toDateString()])
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '<', $ate))
            ->with('grupo:id,name')
            ->orderBy('id')
            ->get();

        $linhas = $individuais->map(fn (NpsSurvey $s) => [
            'individual', $s->id, $s->company?->name ?? '—', $s->status, $s->expires_at?->format('d/m/Y H:i') ?? '—',
        ])->concat($grupos->map(fn (NpsGroupSurvey $g) => [
            'grupo', $g->id, $g->grupo?->name ?? '—', $g->status, $g->expires_at?->format('d/m/Y H:i') ?? '—',
        ]));

        $this->info(sprintf(
            '%s links de %s não respondidos: %d individuais + %d de grupo → valem até %s.',
            $dryRun ? '[DRY-RUN]' : 'Prorrogando',
            $mes->format('m/Y'),
            $individuais->count(),
            $grupos->count(),
            $ate->format('d/m/Y H:i:s'),
        ));

        if ($linhas->isNotEmpty()) {
            $this->table(['Tipo', 'ID', 'Empresa / grupo', 'Status', 'Vence hoje em'], $linhas->all());
        }

        if ($janela->fechadaManualmente($mes)) {
            $this->warn('O ciclo deste mês foi encerrado à mão: os links continuam recusando resposta até alguém reabrir o ciclo.');
        }

        if ($dryRun || $linhas->isEmpty()) {
            return self::SUCCESS;
        }

        foreach ($individuais as $survey) {
            $survey->update(['expires_at' => $ate, 'status' => 'pending']);
        }

        foreach ($grupos as $grupo) {
            $grupo->update(['expires_at' => $ate, 'status' => 'pending']);
        }

        Log::info('[NPS Prorrogação] validade prorrogada', [
            'mes_coleta'  => $mes->format('Y-m'),
            'ate'         => $ate->toDateTimeString(),
            'individuais' => $individuais->pluck('id')->all(),
            'grupos'      => $grupos->pluck('id')->all(),
        ]);

        $this->info('Feito.');

        return self::SUCCESS;
    }
}
