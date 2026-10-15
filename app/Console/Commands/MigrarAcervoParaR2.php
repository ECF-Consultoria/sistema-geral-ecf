<?php

namespace App\Console\Commands;

use App\Models\AcervoImagem;
use App\Models\PubImagem;
use App\Support\Publicador\Imagem\ArquivosDeImagem;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Migra para o R2 os objetos do acervo que ainda estão no disco local da VPS (v28.0 Fase 200, MIG-02).
 *
 * Para cada ativo `disponivel` fora do destino: lê os bytes, confere o sha256 com o registrado, grava no
 * destino na chave canônica `imagens/{dono}/{sha256}.{ext}`, RELÊ do destino e confere o sha256 de novo,
 * e só então aponta o ativo e TODOS os seus usos (`pub_imagens`, inclusive as cópias físicas redundantes
 * do backfill) para a chave nova. Os arquivos locais NÃO são apagados: ficam em `caminhos_legados` para
 * `acervo:limpar-local-migrado` depois da janela de 30 dias (MIG-03).
 *
 * Idempotente e retomável: ativo já no destino é pulado; rodar de novo continua de onde parou. Sha que não
 * confere não migra (fica no relatório). Sem `--aplicar` é simulação. Não migra foto sem ativo (produto
 * sem conta) — essa segue legível do local.
 */
class MigrarAcervoParaR2 extends Command
{
    protected $signature = 'acervo:migrar-para-r2
        {--aplicar : Migra de verdade; sem esta opção é só simulação}
        {--destino=r2 : Disco de destino}
        {--limite=0 : Máximo de ativos nesta rodada (0 = todos)}';

    protected $description = 'Migra os objetos do acervo do disco local para o R2, conferindo sha256 (MIG-02)';

    public function handle(): int
    {
        $aplicar = (bool) $this->option('aplicar');
        $destino = (string) $this->option('destino');
        $limite = max(0, (int) $this->option('limite'));

        if (! is_array(config("filesystems.disks.{$destino}"))) {
            $this->error("Disco de destino [{$destino}] não existe.");

            return self::FAILURE;
        }

        $placar = ['migrados' => 0, 'ja_no_destino' => 0, 'sem_arquivo' => 0, 'sha_diverge' => 0, 'falhas' => 0, 'bytes' => 0];
        $feitos = 0;
        $placar['ja_no_destino'] = AcervoImagem::query()->where('disco', $destino)->count();

        $query = AcervoImagem::query()->where('status', AcervoImagem::DISPONIVEL)->where('disco', '!=', $destino)->orderBy('id');
        foreach ($query->lazyById(200) as $ativo) {
            if ($limite > 0 && $feitos >= $limite) {
                break;
            }
            $feitos++;

            $conteudo = ArquivosDeImagem::ler($ativo->caminho);
            if ($conteudo === null) {
                $placar['sem_arquivo']++;
                $this->line("  sem arquivo: ativo {$ativo->id} ({$ativo->caminho})");

                continue;
            }
            if (hash('sha256', $conteudo) !== $ativo->sha256) {
                $placar['sha_diverge']++;
                $this->warn("  sha256 não confere: ativo {$ativo->id} ({$ativo->caminho}) — não migrado");

                continue;
            }

            $placar['bytes'] += strlen($conteudo);
            if (! $aplicar) {
                $placar['migrados']++;

                continue;
            }

            try {
                $this->migrar($ativo, $conteudo, $destino);
                $placar['migrados']++;
            } catch (\Throwable $e) {
                $placar['falhas']++;
                $this->error("  falhou: ativo {$ativo->id}: ".class_basename($e).' — '.$e->getMessage());
                Log::warning("[Acervo] migrar-para-r2: ativo {$ativo->id} falhou: ".$e->getMessage());
            }
        }

        $mb = number_format($placar['bytes'] / 1048576, 1, ',', '.');
        $this->info(($aplicar ? 'APLICADO' : 'SIMULAÇÃO — nada foi migrado')." (destino: {$destino})");
        $this->table(['item', 'quantidade'], [
            [$aplicar ? 'migrados nesta rodada' : 'seriam migrados', $placar['migrados']],
            ['volume (MB)', $mb],
            ['já estavam no destino (antes desta rodada)', $placar['ja_no_destino']],
            ['sem arquivo', $placar['sem_arquivo']],
            ['sha256 não confere (não migrados)', $placar['sha_diverge']],
            ['falhas', $placar['falhas']],
        ]);

        if ($aplicar) {
            Log::info("[Acervo] migrar-para-r2: {$placar['migrados']} migrado(s), {$mb} MB, {$placar['falhas']} falha(s), {$placar['sha_diverge']} sha divergente(s)");
        }

        return $placar['falhas'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function migrar(AcervoImagem $ativo, string $conteudo, string $destino): void
    {
        $chave = AcervoImagem::caminhoPara($ativo->dono_chave, $ativo->sha256, $ativo->mime);
        $disco = Storage::disk($destino);

        if ($disco->put($chave, $conteudo) === false) {
            throw new \RuntimeException('put devolveu false');
        }
        $relido = $disco->get($chave);
        if ($relido === null || hash('sha256', $relido) !== $ativo->sha256) {
            throw new \RuntimeException('o objeto relido do destino não confere com o sha256');
        }

        DB::transaction(function () use ($ativo, $chave, $destino) {
            $usos = PubImagem::query()->where('acervo_imagem_id', $ativo->id)->pluck('caminho')->filter()->all();
            $legados = array_values(array_unique(array_filter([$ativo->caminho, ...$usos], fn ($c) => $c !== $chave)));

            PubImagem::query()->where('acervo_imagem_id', $ativo->id)->update(['caminho' => $chave]);
            $ativo->update([
                'disco' => $destino,
                'caminho' => $chave,
                'migrado_em' => now(),
                'caminhos_legados' => array_values(array_unique([...($ativo->caminhos_legados ?? []), ...$legados])),
            ]);
        });
    }
}
