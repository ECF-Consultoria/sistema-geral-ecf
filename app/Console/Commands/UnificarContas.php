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
 * Este comando entrega o NÚCLEO (carteira, histórico de gestão, cargos,
 * desativação da origem). O plano 159-06 acrescenta as etapas de NPS,
 * snapshots, PPAs e onboardings sobre a mesma estrutura — ver
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
        if ($lote = $this->option('desfazer')) {
            return $this->handleDesfazer($lote);
        }

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
        }

        if (! $this->option('apply')) {
            if (! $this->option('json')) {
                $this->line('');
                $this->warn('DRY-RUN — nada foi gravado. Rode com --apply para aplicar.');
            }

            return (empty($plano['bloqueios']) && empty($pendencias)) ? self::SUCCESS : self::FAILURE;
        }

        try {
            $resultado = $this->service->aplicar($plano, $manter);
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->line('');
        $this->info("Lote: {$resultado['lote']} ({$resultado['operacoes']} operação(ões) gravada(s)).");
        $this->line("Desfazer com: php artisan usuarios:unificar-contas --desfazer={$resultado['lote']} --apply");

        return $this->reconsultar($deId, $paraId, $aPartir);
    }

    /**
     * Reconsulta ao banco depois do --apply: planeja de novo com os mesmos
     * parâmetros e confere que não sobrou nenhuma operação pendente
     * (learnings §4 — o veredito é o exit code, nunca o texto impresso).
     */
    private function reconsultar(int $deId, int $paraId, Carbon $aPartir): int
    {
        $replano = $this->service->planejar($deId, $paraId, $aPartir);
        $pendentes = array_sum(array_map(fn ($etapa) => count($etapa['operacoes']), $replano['etapas']));

        $this->imprimirConsultasDeConferencia($deId, $paraId);

        if ($pendentes > 0) {
            $this->line('');
            $this->error("Reconsulta ao banco encontrou {$pendentes} operação(ões) pendente(s):");
            foreach ($replano['etapas'] as $etapa) {
                if (count($etapa['operacoes']) > 0) {
                    $this->line("  {$etapa['chave']}: " . count($etapa['operacoes']) . ' pendente(s)');
                }
            }

            return self::FAILURE;
        }

        $this->line('');
        $this->info('Reconsulta: 0 operações pendentes.');

        return self::SUCCESS;
    }

    private function handleDesfazer(string $lote): int
    {
        $aplicar = (bool) $this->option('apply');

        try {
            $resultado = $this->service->desfazer($lote, $aplicar);
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if (! $aplicar) {
            $this->info("Lote {$lote}: {$resultado['operacoes']} operação(ões) seriam restauradas ao estado anterior.");
            $this->warn('DRY-RUN — rode com --apply para restaurar.');

            return self::SUCCESS;
        }

        $this->info("Lote {$lote}: {$resultado['operacoes']} operação(ões) restaurada(s) ao estado anterior.");

        return self::SUCCESS;
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

    private function imprimirConsultasDeConferencia(int $deId, int $paraId): void
    {
        $this->line('');
        $this->line('Consultas de conferência independente (rode à parte — o veredito é a reconsulta, não este texto):');
        $this->line("  SELECT COUNT(*) FROM company_users WHERE user_id = {$deId};");
        $this->line("  SELECT COUNT(*) FROM user_setores WHERE user_id = {$paraId};");
        $this->line("  SELECT active FROM users WHERE id = {$deId};");
    }
}
