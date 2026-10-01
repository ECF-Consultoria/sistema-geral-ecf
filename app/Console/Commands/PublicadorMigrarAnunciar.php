<?php

namespace App\Console\Commands;

use App\Models\EstruturaPublicacao;
use App\Services\Publicador\MigracaoAnunciarAntigo;
use Illuminate\Console\Command;

/**
 * Leva os rascunhos do Anunciar antigo para o Publicador (`16` §4.3). Sem
 * `--apply` só mostra o que faria. Idempotente; a tabela antiga não muda.
 */
class PublicadorMigrarAnunciar extends Command
{
    protected $signature = 'publicador:migrar-anunciar {--apply : Grava de fato (sem isto, só simula)}';

    protected $description = 'Migra os rascunhos do Anunciar antigo (estrutura_publicacoes) para o Publicador novo';

    public function handle(MigracaoAnunciarAntigo $migracao): int
    {
        $aplicar = (bool) $this->option('apply');
        $linhas = EstruturaPublicacao::with('oferta')->orderBy('id')->get();
        $this->line(($aplicar ? 'APLICANDO' : 'SIMULAÇÃO (use --apply para gravar)').' — '.$linhas->count().' linha(s)');

        $contagem = ['migrar' => 0, 'pular' => 0];
        foreach ($linhas as $antiga) {
            $plano = $migracao->planejar($antiga);
            $migrar = $plano['acao'] === 'migrar';
            $contagem[$migrar ? 'migrar' : 'pular']++;

            $this->line(sprintf('  oferta %d (%s): %s%s', $plano['oferta_id'], $plano['sku'], $plano['acao'], $migrar
                ? sprintf(' → %s, %d atributo(s), %d foto(s), títulos digitados: %s, preços digitados: %s, publicados: %s',
                    $plano['status'], $plano['atributos'], $plano['fotos'],
                    json_encode(array_filter($plano['titulos']), JSON_UNESCAPED_UNICODE) ?: '{}',
                    json_encode(array_filter($plano['precos'], fn ($p) => $p !== null)) ?: '{}',
                    json_encode($plano['publicados']) ?: '{}')
                : ''));

            if ($aplicar && $migrar) {
                $migracao->aplicar($antiga);
            }
        }

        $this->info("{$contagem['migrar']} a migrar, {$contagem['pular']} pulada(s)".($aplicar ? ' — gravado.' : ' — nada gravado.'));

        return self::SUCCESS;
    }
}
