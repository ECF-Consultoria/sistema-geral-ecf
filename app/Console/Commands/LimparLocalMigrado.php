<?php

namespace App\Console\Commands;

use App\Models\AcervoImagem;
use App\Models\MlAnuncioCriativo;
use App\Models\PubImagem;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Apaga do disco local da VPS os arquivos de ativos já migrados para o R2, depois da janela de segurança
 * (v28.0 Fase 200, MIG-03 — 30 dias por padrão).
 *
 * Só apaga caminhos listados em `acervo_imagens.caminhos_legados` de ativos migrados há mais que a janela,
 * e só se NADA mais aponta para eles: nenhuma `pub_imagens.caminho`, nenhum `acervo_imagens.caminho`/
 * miniatura e nenhum `ml_anuncio_criativos.imagem_path` (o criativo antigo aprovado continua lendo o
 * arquivo dele em `creative-geradas/`). Sem `--aplicar` é simulação.
 */
class LimparLocalMigrado extends Command
{
    protected $signature = 'acervo:limpar-local-migrado
        {--dias=30 : Janela mínima desde a migração}
        {--aplicar : Apaga de verdade; sem esta opção é só simulação}';

    protected $description = 'Apaga do disco local os arquivos já migrados para o R2 depois da janela de segurança (MIG-03)';

    public function handle(): int
    {
        $dias = max(1, (int) $this->option('dias'));
        $aplicar = (bool) $this->option('aplicar');
        $local = Storage::disk('local');
        $placar = ['arquivos' => 0, 'bytes' => 0, 'ainda_referenciados' => 0];

        $query = AcervoImagem::query()->whereNotNull('migrado_em')->where('migrado_em', '<', now()->subDays($dias))->whereNotNull('caminhos_legados');
        foreach ($query->lazyById(200) as $ativo) {
            $restam = [];
            foreach ((array) $ativo->caminhos_legados as $caminho) {
                if ($this->referenciado($caminho)) {
                    $placar['ainda_referenciados']++;
                    $restam[] = $caminho;

                    continue;
                }
                if ($local->exists($caminho)) {
                    $placar['arquivos']++;
                    $placar['bytes'] += (int) $local->size($caminho);
                    if ($aplicar) {
                        $local->delete($caminho);
                    }
                }
            }
            if ($aplicar) {
                $ativo->update(['caminhos_legados' => $restam === [] ? null : $restam]);
            }
        }

        $mb = number_format($placar['bytes'] / 1048576, 1, ',', '.');
        $this->info(($aplicar ? 'APLICADO' : 'SIMULAÇÃO — nada foi apagado').": ativos migrados há mais de {$dias} dias");
        $this->table(['item', 'quantidade'], [
            ['arquivos locais', $placar['arquivos']],
            ['espaço (MB)', $mb],
            ['ainda referenciados (ficam)', $placar['ainda_referenciados']],
        ]);
        if ($aplicar) {
            Log::info("[Acervo] limpar-local-migrado: {$placar['arquivos']} arquivo(s), {$mb} MB");
        }

        return self::SUCCESS;
    }

    private function referenciado(string $caminho): bool
    {
        return PubImagem::query()->where('caminho', $caminho)->exists()
            || AcervoImagem::query()->where('caminho', $caminho)->orWhere('miniatura_caminho', $caminho)->exists()
            || MlAnuncioCriativo::query()->where('imagem_path', $caminho)->exists();
    }
}
