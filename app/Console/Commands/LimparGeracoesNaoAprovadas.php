<?php

namespace App\Console\Commands;

use App\Models\AcervoImagem;
use App\Models\MlAnuncioCriativo;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Apaga do disco da VPS as gerações de IA que ninguém aprovou depois do prazo (v28.0 Fase 199, EXC-04).
 *
 * Pelo D3 da v28.0 só a imagem APROVADA vira acervo; a geração não aprovada é rascunho transitório em
 * `creative-geradas/{token}/{slot}.jpg` (disco `local`), que até aqui nenhum código apagava. Critério:
 * criativo NÃO aprovado, com arquivo, criado há mais de `publicador.acervo.retencao_geracoes_dias`
 * (30 dias, decisão do usuário) — e cujo arquivo NÃO é objeto de nenhum ativo do acervo (o backfill da
 * Fase 197 aponta ativos para criativos antigos aprovados nesse mesmo diretório).
 *
 * SIMULAÇÃO POR PADRÃO: sem `--aplicar` só lista e soma. Ao apagar, zera `imagem_path` do criativo (a
 * tela de criativos já trata "sem imagem") — a linha e o histórico ficam. Por idade do REGISTRO, não
 * do arquivo (lição do ECF Drive, ver `LimparReferenciasCriativos`). Não está agendado: liga-se depois
 * de o usuário ver os números da simulação em produção.
 */
class LimparGeracoesNaoAprovadas extends Command
{
    protected $signature = 'acervo:limpar-geracoes
        {--dias= : Idade mínima do criativo em dias (padrão: config publicador.acervo.retencao_geracoes_dias)}
        {--aplicar : Apaga de verdade; sem esta opção é só simulação}';

    protected $description = 'Apaga gerações de IA não aprovadas mais velhas que o prazo (simulação por padrão)';

    public function handle(): int
    {
        $dias = max(1, (int) ($this->option('dias') ?: config('publicador.acervo.retencao_geracoes_dias', 30)));
        $aplicar = (bool) $this->option('aplicar');
        $disco = Storage::disk('local');

        $criativos = 0;
        $arquivos = 0;
        $bytes = 0;
        $protegidos = 0;

        MlAnuncioCriativo::query()
            ->where('status', '!=', MlAnuncioCriativo::STATUS_APROVADO)
            ->whereNotIn('status', MlAnuncioCriativo::STATUS_EM_ANDAMENTO)
            ->whereNotNull('imagem_path')
            ->where('created_at', '<', now()->subDays($dias))
            ->chunkById(200, function ($lote) use ($disco, $aplicar, &$criativos, &$arquivos, &$bytes, &$protegidos) {
                foreach ($lote as $c) {
                    if (AcervoImagem::query()->where('caminho', $c->imagem_path)->exists()) {
                        $protegidos++;

                        continue;
                    }

                    $criativos++;
                    if ($disco->exists($c->imagem_path)) {
                        $arquivos++;
                        $bytes += (int) $disco->size($c->imagem_path);
                        if ($aplicar) {
                            $disco->delete($c->imagem_path);
                        }
                    }
                    if ($aplicar) {
                        $c->update(['imagem_path' => null]);
                    }
                }
            });

        $mb = number_format($bytes / 1048576, 1, ',', '.');
        $this->info(($aplicar ? 'APLICADO' : 'SIMULAÇÃO — nada foi apagado').": gerações não aprovadas com mais de {$dias} dias");
        $this->table(['item', 'quantidade'], [
            ['criativos', $criativos],
            ['arquivos', $arquivos],
            ['espaço (MB)', $mb],
            ['protegidos (são objeto de um ativo do acervo)', $protegidos],
        ]);

        if ($aplicar) {
            Log::info("[Acervo] limpar-geracoes: {$arquivos} arquivo(s), {$mb} MB, de {$criativos} criativo(s) não aprovados com mais de {$dias} dias");
        }

        return self::SUCCESS;
    }
}
