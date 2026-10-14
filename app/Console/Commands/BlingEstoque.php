<?php

namespace App\Console\Commands;

use App\Models\BlingConexao;
use App\Models\BlingVinculo;
use App\Services\Bling\EstoqueDoBlingService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Fase 184 (BLING-25, BLING-26) — comando agendado da varredura de estoque do Bling.
 *
 * ### `--empresa={id}`
 * É o caminho do botão "Atualizar agora" (plano 11): varre SÓ aquela conexão, e IGNORA o
 * filtro de `status`/`estoque_ativo` da busca — a conexão é carregada de todo jeito, e é
 * dentro do laço que ela conta como "pulada" se não estiver elegível. Sem `--empresa`, a
 * busca já filtra por `status = ativa` e `estoque_ativo = true`: uma conexão divergente,
 * revogada ou com erro nunca aparece na rodada agendada.
 *
 * ### Falha de uma empresa não para as demais
 * Mesmo padrão de `AdmanService::syncAll()` e de `bling:renovar-tokens`: o loop engole o
 * erro por item (log com prefixo `[Bling]`) e segue para a próxima conexão.
 *
 * ### O resumo não é prova de nada
 * O `info()`/`Log::info` final é só leitura humana — a conferência de verdade é por
 * reconsulta ao banco (`BlingConexao.ultimo_estoque_em`, `estrutura_produto_variacoes.estoque`),
 * nunca pelo texto impresso.
 */
class BlingEstoque extends Command
{
    protected $signature = 'bling:estoque
        {--empresa= : varre só esta company_id (ignora o filtro de status/estoque_ativo da busca)}
        {--dry-run : lista as conexões e o número de vínculos de cada uma, sem chamar o Bling}';

    protected $description = 'Varre o estoque das conexões ativas do Bling e grava no Mapeamento Estrutural';

    public function handle(EstoqueDoBlingService $servico): int
    {
        $empresaId = $this->option('empresa');
        $dryRun = (bool) $this->option('dry-run');

        $query = BlingConexao::query()->orderBy('ultimo_estoque_em');

        if ($empresaId !== null) {
            $query->where('company_id', (int) $empresaId);
        } else {
            $query->where('status', BlingConexao::STATUS_ATIVA)->where('estoque_ativo', true);
        }

        $conexoes = $query->get();

        if ($conexoes->isEmpty()) {
            $this->info('Nenhuma conexão Bling para varrer.');

            return Command::SUCCESS;
        }

        if ($dryRun) {
            foreach ($conexoes as $conexao) {
                $vinculos = $conexao->vinculos()->where('situacao', BlingVinculo::SITUACAO_VINCULADO)->count();
                $this->line("  [dry-run] empresa {$conexao->company_id} (conexão {$conexao->id}) — {$vinculos} vínculo(s)");
            }

            $this->info("bling:estoque (dry-run) — {$conexoes->count()} conexão(ões) seriam varridas.");

            return Command::SUCCESS;
        }

        $gravadas = 0;
        $semMudanca = 0;
        $falhas = 0;
        $puladas = 0;

        foreach ($conexoes as $conexao) {
            if ($conexao->status !== BlingConexao::STATUS_ATIVA || ! $conexao->estoque_ativo) {
                $puladas++;
                $this->line("  - empresa {$conexao->company_id} (conexão {$conexao->id}) pulada: status={$conexao->status}, estoque_ativo=".($conexao->estoque_ativo ? '1' : '0'));

                continue;
            }

            try {
                $resultado = $servico->varrer($conexao);
                $gravadas += $resultado['gravadas'];
                $semMudanca += $resultado['sem_mudanca'];

                if ($resultado['erro'] !== null) {
                    $falhas++;
                    $this->warn("  ✗ empresa {$conexao->company_id} (conexão {$conexao->id}): {$resultado['erro']}");
                } else {
                    $this->line("  ✓ empresa {$conexao->company_id} (conexão {$conexao->id}): {$resultado['gravadas']} gravada(s)");
                }
            } catch (\Throwable $e) {
                // Uma empresa fora do ar não pode travar a varredura das demais.
                $falhas++;
                Log::error("[Bling] bling:estoque falhou para a conexão {$conexao->id}: {$e->getMessage()}");
                $this->warn("  ✗ empresa {$conexao->company_id} (conexão {$conexao->id}): {$e->getMessage()}");
            }
        }

        $resumo = "bling:estoque concluído — empresas: {$conexoes->count()}, gravadas: {$gravadas}, sem_mudanca: {$semMudanca}, falhas: {$falhas}, puladas: {$puladas}";
        $this->info($resumo);
        Log::info("[Bling] {$resumo}");

        return Command::SUCCESS;
    }
}
