<?php

namespace App\Console\Commands;

use App\Services\Usuarios\UnificacaoContasService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Fase 159 Plano 159-05 (D-06) — junção das contas de origem (`--de`) em
 * destino (`--para`) a partir de uma competência. Molde:
 * `ReverterRecadastrosCompetencia` (dry-run por padrão, `--apply` explícito,
 * backup por lote, `--desfazer`).
 *
 * Task 1 deste plano entrega SÓ o caminho dry-run (relatório + censo +
 * bloqueios, exit code por bloqueio/pendência). A Task 2 acrescenta
 * `--apply`/`--desfazer` sobre a mesma base — ver
 * `App\Services\Usuarios\UnificacaoContasService`.
 *
 * NUNCA imprime nota, faixa ou valor de bônus (learnings §11) — só contagens
 * e ids, que é o que as telas já exibem.
 */
class UnificarContas extends Command
{
    protected $signature = 'usuarios:unificar-contas
                            {--de= : id do usuário que deixa de existir}
                            {--para= : id do usuário que fica}
                            {--a-partir= : primeira competência afetada, YYYY-MM}
                            {--apply : Grava as alterações (sem esta flag é só relatório)}
                            {--manter=* : tabela.coluna sem regra que fica com a origem de propósito}
                            {--desfazer= : Restaura um lote pelo uuid}
                            {--json : Imprime o plano em JSON}';

    protected $description = 'Junta a conta de origem (--de) na conta de destino (--para) a partir de uma competência (D-06)';

    public function __construct(private readonly UnificacaoContasService $service)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $deId = $this->option('de');
        $paraId = $this->option('para');
        $aPartirRaw = (string) $this->option('a-partir');

        if (! $this->idValido($deId)) {
            $this->error('--de precisa ser o id de um usuário existente.');

            return self::FAILURE;
        }
        if (! $this->idValido($paraId)) {
            $this->error('--para precisa ser o id de um usuário existente.');

            return self::FAILURE;
        }
        if (! preg_match('/^\d{4}-\d{2}$/', $aPartirRaw)) {
            $this->error('--a-partir precisa estar no formato YYYY-MM, ex.: 2026-09.');

            return self::FAILURE;
        }

        $deId = (int) $deId;
        $paraId = (int) $paraId;
        $aPartir = Carbon::parse($aPartirRaw . '-01');
        $manter = (array) $this->option('manter');

        $plano = $this->service->planejar($deId, $paraId, $aPartir);
        $pendencias = $this->service->pendenciasCenso($plano['censo'], $manter);

        if ($this->option('json')) {
            $this->line(json_encode(
                $plano + ['pendencias_censo' => $pendencias],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
            ));
        } else {
            $this->imprimirRelatorio($plano, $pendencias);
            $this->line('');
            $this->warn('DRY-RUN — nada foi gravado. Rode com --apply para aplicar.');
        }

        return (empty($plano['bloqueios']) && empty($pendencias)) ? self::SUCCESS : self::FAILURE;
    }

    private function idValido(mixed $id): bool
    {
        if ($id === null || $id === '' || ! ctype_digit((string) $id)) {
            return false;
        }

        return DB::table('users')->where('id', (int) $id)->exists();
    }

    private function imprimirRelatorio(array $plano, array $pendencias): void
    {
        $this->line('');
        $this->info(sprintf(
            'Junção: usuário %d (%s) → usuário %d (%s) — a partir de %s',
            $plano['de']['id'],
            $plano['de']['nome'],
            $plano['para']['id'],
            $plano['para']['nome'],
            $plano['a_partir']
        ));

        if ($plano['bloqueios']) {
            $this->line('');
            $this->warn('Bloqueios:');
            foreach ($plano['bloqueios'] as $bloqueio) {
                $this->line("  - {$bloqueio}");
            }
        }

        if ($plano['avisos']) {
            $this->line('');
            $this->line('Avisos:');
            foreach ($plano['avisos'] as $aviso) {
                $this->line("  - {$aviso}");
            }
        }

        $this->line('');
        foreach ($plano['etapas'] as $etapa) {
            $porAcao = [];
            foreach ($etapa['operacoes'] as $operacao) {
                $porAcao[$operacao['acao']] = ($porAcao[$operacao['acao']] ?? 0) + 1;
            }

            $resumo = $porAcao === []
                ? 'nada a fazer'
                : implode(', ', array_map(fn ($acao, $n) => "{$n}× {$acao}", array_keys($porAcao), $porAcao));

            $this->line("Etapa {$etapa['chave']} ({$etapa['descricao']}): {$resumo}");

            $ids = array_slice(array_values(array_filter(array_column($etapa['operacoes'], 'linha_id'))), 0, 20);
            if ($ids !== []) {
                $this->line('  linha_id: ' . implode(', ', $ids));
            }
        }

        $censoRelevante = array_values(array_filter($plano['censo'], fn ($c) => $c['linhas_origem'] > 0));
        if ($censoRelevante !== []) {
            $this->line('');
            $this->table(
                ['Tabela', 'Coluna', 'Linhas origem', 'Classificação', 'Motivo'],
                array_map(fn ($c) => [
                    $c['tabela'], $c['coluna'], $c['linhas_origem'], $c['classificacao'], $c['motivo'] ?? '—',
                ], $censoRelevante)
            );
        }

        if ($pendencias) {
            $this->line('');
            $this->error('Censo com pendência de decisão — rode com --manter=tabela.coluna para decidir antes de aplicar:');
            foreach ($pendencias as $pendencia) {
                $this->line("  - {$pendencia}");
            }
        }
    }
}
