<?php

namespace App\Console\Commands;

// Comando one-shot Phase 19. Read-only por default; --apply explícito.
// Reduz acúmulo dos 1407 pendentes antigos (CONTEXT.md §Estado atual).
// Candidatos: sugadores pendentes com reference_date anterior a hoje.
// NÃO toca em sugadores de HOJE nem em STATUS_TRAVADOS.

use App\Models\Company;
use App\Models\Sugador;
use App\Models\SugadorAcao;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class LimparOrfaosSugadores extends Command
{
    protected $signature = 'sugadores:limpar-orfaos
                            {--apply : Aplica o UPDATE; sem essa flag é dry-run}';

    protected $description = 'Marca como auto_resolvido sugadores pendentes com reference_date anterior a hoje (limpeza one-shot Phase 19).';

    public function handle(): int
    {
        // Carrega candidatos: pendentes com reference_date < hoje.
        // STATUS_TRAVADOS (em_acao, resolvido, ignorado, movido, auto_resolvido)
        // ficam de fora pelo filtro de status='pendente'.
        $candidatos = Sugador::where('status', Sugador::STATUS_PENDENTE)
            ->where('reference_date', '<', today()->toDateString())
            ->select('id', 'company_id')
            ->get();

        if ($candidatos->isEmpty()) {
            $this->info('Nenhum sugador órfão encontrado.');
            return 0;
        }

        $total      = $candidatos->count();
        $porEmpresa = $candidatos->groupBy('company_id')->map->count()->sortDesc();

        // Carrega nomes das empresas para exibir na tabela (1 query).
        $nomes = Company::whereIn('id', $porEmpresa->keys())->pluck('name', 'id');

        // Tabela top 10 empresas com mais orphans.
        $this->table(
            ['Empresa', 'Sugadores'],
            $porEmpresa->take(10)->map(fn($n, $id) => [$nomes[$id] ?? "#{$id}", $n])->values()->all()
        );

        if ($porEmpresa->count() > 10) {
            $this->line(sprintf(
                '+ %d empresas com %d sugadores no resto.',
                $porEmpresa->count() - 10,
                $porEmpresa->skip(10)->sum()
            ));
        }

        $this->info("Total candidato: {$total} sugadores.");

        if (!$this->option('apply')) {
            $this->warn('Dry-run — nenhuma mudança aplicada. Rode com --apply para aplicar.');
            return 0;
        }

        // Confirmação implícita: --apply explícito é suficiente (gate humano no W4).
        DB::transaction(function () use ($candidatos, $total) {
            $ids = $candidatos->pluck('id')->all();
            $now = now();

            // UPDATE em massa: muda status + seta resolvido_em, mantém resolvido_por=null
            // (limpeza sistêmica, sem usuário responsável).
            Sugador::whereIn('id', $ids)->update([
                'status'       => Sugador::STATUS_AUTO_RESOLVIDO,
                'resolvido_em' => $now,
                'resolvido_por' => null,
            ]);

            // INSERT em audit log (sugador_acoes) em chunks de 500 para não gerar
            // bind params gigantes em ambientes com muitos órfãos.
            $observacao = 'Limpeza one-shot Phase 19 — sugador antigo não-redetectado';
            $rows = $candidatos->map(fn($s) => [
                'sugador_id'      => $s->id,
                'user_id'         => null,
                'acao'            => SugadorAcao::ACAO_LIMPEZA_ORFAOS,
                'status_anterior' => Sugador::STATUS_PENDENTE,
                'status_novo'     => Sugador::STATUS_AUTO_RESOLVIDO,
                'observacao'      => $observacao,
                'created_at'      => $now,
            ])->values()->toArray();

            foreach (array_chunk($rows, 500) as $chunk) {
                SugadorAcao::insert($chunk);
            }

            Log::info("[LimparOrfaos] {$total} sugadores marcados como auto_resolvido");
        });

        $this->info("Aplicado: {$total} sugadores marcados como auto_resolvido.");
        return 0;
    }
}
