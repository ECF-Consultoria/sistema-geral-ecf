<?php

namespace App\Console\Commands;

use App\Models\MlbEmpresa;
use App\Models\PoloRosterSnapshot;
use App\Support\CustId;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Congela o roster de polos de um mês — quem estava ativo e em que fase.
 *
 * Dois modos:
 *   php artisan polos:congelar-roster --apply              → mês corrente, do cadastro ao vivo
 *   php artisan polos:congelar-roster --mes=202608 --do-log --apply
 *                                                          → mês passado, desfazendo o log
 *
 * O modo `--do-log` existe para o backfill: o roster de um mês já encerrado não está mais
 * no cadastro (o time avança todas as fases na virada), mas TODA mudança de fase passa pelo
 * activity_log com data e hora. Reverter os eventos posteriores ao fim do mês devolve o
 * estado exato daquele momento — validado contra a planilha de agosto/2026: 134 ativos
 * reconstruídos (M2=54 M3=37 M4=43) contra os 133 da planilha.
 *
 * Dry-run por padrão: sem --apply só mostra o que gravaria.
 */
class CongelarRosterPolos extends Command
{
    protected $signature = 'polos:congelar-roster
        {--mes= : Mês YYYYMM (padrão: corrente)}
        {--do-log : Reconstrói o roster do mês desfazendo o activity_log (backfill de mês passado)}
        {--apply : Grava de fato (padrão é dry-run)}';

    protected $description = 'Congela o roster (quem estava ativo e em que fase) dos polos de um mês';

    /**
     * Campos do cadastro que o snapshot preserva e que, portanto, precisam ser revertidos
     * ao reconstruir um mês passado. `projeto` e `arquivado_em` entram porque decidem se a
     * empresa contava como polo ativo — não só como ela aparecia.
     */
    private const CAMPOS_REVERSIVEIS = [
        'fase', 'polo', 'projeto', 'arquivado_em', 'problema', 'problema_desconsidera_meta',
    ];

    public function handle(): int
    {
        $mes = (string) ($this->option('mes') ?: now()->format('Ym'));
        if (! preg_match('/^\d{6}$/', $mes)) {
            $this->error("Mês inválido: '{$mes}'. Use o formato YYYYMM (ex: 202608).");

            return self::FAILURE;
        }

        $apply = (bool) $this->option('apply');
        $doLog = (bool) $this->option('do-log');

        [$roster, $origem] = $doLog ? $this->rosterPeloLog($mes) : $this->rosterAoVivo();

        if (empty($roster)) {
            $this->warn("Nenhuma empresa ativa encontrada para {$mes} — nada a congelar.");

            return self::SUCCESS;
        }

        $porFase = [];
        foreach ($roster as $e) {
            $porFase[$e['fase']] = ($porFase[$e['fase']] ?? 0) + 1;
        }
        ksort($porFase);

        $this->info(sprintf(
            '%s roster de %s (origem: %s) — %d ativos',
            $apply ? 'Congelando' : '[DRY-RUN] Congelaria',
            $mes,
            $origem,
            count($roster),
        ));
        $this->line('   ' . collect($porFase)->map(fn ($n, $f) => "{$f}={$n}")->implode('  '));

        if (! $apply) {
            $this->comment('Nada gravado. Rode de novo com --apply.');

            return self::SUCCESS;
        }

        $agora = now();
        DB::transaction(function () use ($roster, $mes, $origem, $agora) {
            foreach ($roster as $e) {
                PoloRosterSnapshot::updateOrCreate(
                    ['mes' => $mes, 'cust_id' => $e['cust_id']],
                    [
                        'mlb_empresa_id' => $e['id'],
                        'nome'           => $e['nome'],
                        'fase'           => $e['fase'],
                        'polo'           => $e['polo'],
                        'problema'       => $e['problema'],
                        'problema_desconsidera_meta' => $e['problema_desconsidera_meta'],
                        'ads_desligado'  => $e['ads_desligado'],
                        'congelado_em'   => $agora,
                        'origem'         => $origem,
                    ],
                );
            }
        });

        $this->info('✔ Roster congelado.');

        return self::SUCCESS;
    }

    /**
     * Roster do mês CORRENTE: o cadastro ao vivo já é a verdade do momento.
     *
     * @return array{0: array<int,array<string,mixed>>, 1: string}
     */
    private function rosterAoVivo(): array
    {
        $roster = MlbEmpresa::whereIn('fase', PoloRosterSnapshot::FASES_ATIVAS)
            ->where('projeto', 'POLOS')
            ->whereNull('arquivado_em')
            ->get(['id', 'nome', 'cust_id', 'polo', 'fase', 'problema', 'problema_desconsidera_meta', 'ads_desligado'])
            ->map(fn ($e) => [
                'id'       => $e->id,
                'cust_id'  => CustId::normaliza((string) $e->cust_id),
                'nome'     => (string) $e->nome,
                'fase'     => (string) $e->fase,
                'polo'     => $e->polo,
                'problema' => (bool) $e->problema,
                'problema_desconsidera_meta' => (bool) $e->problema_desconsidera_meta,
                'ads_desligado' => $e->ads_desligado,
            ])
            ->filter(fn ($e) => $e['cust_id'] !== '')
            ->unique('cust_id')
            ->values()
            ->all();

        return [$roster, PoloRosterSnapshot::ORIGEM_VIVO];
    }

    /**
     * Roster de um mês PASSADO, reconstruído a partir do estado atual desfazendo, do mais
     * recente para o mais antigo, todo evento do activity_log posterior ao fim do mês.
     *
     * Empresas criadas depois do corte são descartadas: não existiam naquele mês.
     *
     * @return array{0: array<int,array<string,mixed>>, 1: string}
     */
    private function rosterPeloLog(string $mes): array
    {
        $corte = Carbon::createFromFormat('Ym', $mes)->endOfMonth()->endOfDay();

        $estado = MlbEmpresa::query()
            ->get(['id', 'nome', 'cust_id', 'polo', 'fase', 'projeto', 'arquivado_em',
                'problema', 'problema_desconsidera_meta', 'ads_desligado', 'created_at'])
            ->keyBy('id')
            ->map(fn ($e) => $e->getAttributes())
            ->all();

        // Do mais recente para o mais antigo: cada evento devolve o valor que existia ANTES dele.
        $logs = DB::table('activity_log')
            ->where('subject_type', 'like', '%MlbEmpresa%')
            ->where('created_at', '>', $corte)
            ->orderByDesc('id')
            ->get(['subject_id', 'properties']);

        foreach ($logs as $log) {
            $id = (int) $log->subject_id;
            if (! isset($estado[$id])) {
                continue;
            }

            $props = json_decode((string) $log->properties, true);
            if (! isset($props['old']) || ! is_array($props['old'])) {
                continue;
            }

            foreach (self::CAMPOS_REVERSIVEIS as $campo) {
                if (array_key_exists($campo, $props['old'])) {
                    $estado[$id][$campo] = $props['old'][$campo];
                }
            }
        }

        $roster = [];
        foreach ($estado as $e) {
            // Cadastrada depois do mês: não fazia parte dele.
            if (! empty($e['created_at']) && Carbon::parse($e['created_at'])->gt($corte)) {
                continue;
            }
            if (($e['projeto'] ?? null) !== 'POLOS' || ! empty($e['arquivado_em'])) {
                continue;
            }
            if (! in_array($e['fase'] ?? '', PoloRosterSnapshot::FASES_ATIVAS, true)) {
                continue;
            }

            $cust = CustId::normaliza((string) ($e['cust_id'] ?? ''));
            if ($cust === '' || isset($roster[$cust])) {
                continue;
            }

            $roster[$cust] = [
                'id'       => (int) $e['id'],
                'cust_id'  => $cust,
                'nome'     => (string) ($e['nome'] ?? ''),
                'fase'     => (string) $e['fase'],
                'polo'     => $e['polo'] ?? null,
                'problema' => (bool) ($e['problema'] ?? false),
                'problema_desconsidera_meta' => (bool) ($e['problema_desconsidera_meta'] ?? false),
                'ads_desligado' => $e['ads_desligado'] ?? null,
            ];
        }

        return [array_values($roster), PoloRosterSnapshot::ORIGEM_LOG];
    }
}
