<?php

namespace App\Console\Commands;

use App\Models\BlingConexao;
use App\Services\Bling\BlingOAuthService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;

/**
 * Fase 184 (BLING-13) — keep-alive ESPALHADO dos tokens do Bling.
 *
 * ⚠️ NÃO copia o padrão de `App\Console\Commands\RefreshMlTokens` (o
 * `ml:refresh-tokens`), que renova TODAS as contas de uma vez: **20 chamadas
 * a `/oauth/token` em 60 s bloqueiam o IP da VPS por 60 minutos, para TODOS
 * os clientes do Bling** — e esse teto se atinge a partir de ~20 clientes se
 * a renovação for em rajada.
 *
 * Este comando renova no máximo `self::MAX_POR_MINUTO` conexões por minuto de
 * execução, pausando entre lotes. 10 é metade do teto de 20 — a folga é de
 * propósito. A pausa passa pelo colaborador fingível do próprio Laravel
 * (`Sleep::fake()` nos testes), nunca por uma pausa bloqueante direta no
 * código — senão o teste de espaçamento levaria minutos de verdade.
 */
class BlingRenovarTokens extends Command
{
    protected $signature = 'bling:renovar-tokens
        {--janela=120 : Minutos de antecedência para considerar a conexão perto de expirar}
        {--dry-run : Lista o que faria, sem chamar o Bling}';

    protected $description = 'Keep-alive espalhado dos tokens do Bling — no máximo 10 renovações por minuto';

    private const MAX_POR_MINUTO = 10;

    public function handle(BlingOAuthService $oauth): int
    {
        $janela = (int) $this->option('janela');
        $dryRun = (bool) $this->option('dry-run');

        // A query JÁ filtra fora o que não pode ser tocado: conexão
        // revogada/divergente/erro ou sem refresh_token. Renovar uma conexão
        // divergente gravaria token numa conexão que não deveria ter nenhum.
        $conexoes = BlingConexao::query()
            ->where('status', BlingConexao::STATUS_ATIVA)
            ->whereNotNull('refresh_token')
            ->where('expires_at', '<=', now()->addMinutes($janela))
            ->orderBy('expires_at')
            ->get();

        if ($conexoes->isEmpty()) {
            $this->info('Nenhuma conexão Bling para renovar.');

            return Command::SUCCESS;
        }

        if ($dryRun) {
            foreach ($conexoes as $conexao) {
                $this->line("  [dry-run] empresa {$conexao->company_id} (conexão {$conexao->id}) — expira em {$conexao->expires_at}");
            }

            $this->info("bling:renovar-tokens (dry-run) — {$conexoes->count()} conexão(ões) seriam renovadas.");

            return Command::SUCCESS;
        }

        $renovadas = 0;
        $falhas = 0;
        $puladas = 0;

        foreach ($conexoes as $i => $conexao) {
            // Espalha o keep-alive: a cada MAX_POR_MINUTO renovações, pausa
            // até o próximo minuto antes de continuar.
            if ($i > 0 && $i % self::MAX_POR_MINUTO === 0) {
                Sleep::sleep(60);
            }

            try {
                $oauth->renovar($conexao);
                $renovadas++;
                $this->line("  ✓ empresa {$conexao->company_id} (conexão {$conexao->id})");
            } catch (\Throwable $e) {
                // Um cliente inadimplente ou fora do ar não pode impedir a
                // renovação dos outros — mesmo padrão de AdmanService::syncAll().
                $falhas++;
                Log::error("[Bling] bling:renovar-tokens falhou para a conexão {$conexao->id}: {$e->getMessage()}");
                $this->warn("  ✗ empresa {$conexao->company_id} (conexão {$conexao->id}): {$e->getMessage()}");
            }
        }

        $this->info("bling:renovar-tokens concluído — renovadas: {$renovadas}, falhas: {$falhas}, puladas: {$puladas}");

        return Command::SUCCESS;
    }
}
