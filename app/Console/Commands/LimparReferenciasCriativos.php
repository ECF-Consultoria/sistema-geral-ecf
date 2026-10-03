<?php

namespace App\Console\Commands;

use App\Models\MlAnuncioCriativo;
use App\Services\Creative\ReferenciaEfemeraService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Varredura diária de retenção da foto de referência do Creative Engine
 * (Fase 160 Plano 04, FOTO-03, D-02) — recolhe o que escapou da deleção já
 * feita na hora da aprovação (`MlbAnuncioController::criativoAprovar()`).
 *
 * MOLDE: `MlAcervoCleanup` (mlb:acervo-cleanup) — options nomeadas,
 * relatório por `$this->info()`, `--dry-run` de conferência antes de apagar
 * de verdade, limpeza extra opt-in atrás de flag.
 *
 * POR QUE POR IDADE DO REGISTRO, NÃO DO ARQUIVO. O incidente do ECF Drive
 * (app `ecf-sftp-platform`, 2026-09-14) nasceu de DUAS causas somadas: todas
 * as versões de um CSV gravavam no MESMO caminho local compartilhado, e a
 * retenção decidia por idade do ARQUIVO enquanto o consumidor decidia pelo
 * BANCO — a rotina apagou o arquivo baixado no dia e o sync se recusou a
 * repor. Aqui as duas pontas (esta varredura e `ReferenciaEfemeraService::
 * bytesDe()`) leem a MESMA fonte, a linha de `ml_anuncio_criativos`, e cada
 * criativo tem diretório PRÓPRIO (`creative-referencias/{token}/`) — nenhum
 * caminho é compartilhado entre dois registros.
 *
 * A janela padrão (48h, `services.creative.retencao_referencias_horas`) é
 * DUAS ORDENS DE GRANDEZA acima de `MlAnuncioCriativo::LIMITE_MINUTOS` (12
 * min, 2 tentativas) — por isso, quando esta varredura alcança um registro,
 * nenhum job de geração ainda vivo pode estar lendo aquele arquivo: ou o
 * criativo já terminou (pronto/aprovado/erro), ou está "em andamento" há
 * muito mais tempo do que qualquer job real sobrevive, e `encerrarSeTravada()`
 * o encerra ANTES de a referência ser apagada.
 */
class LimparReferenciasCriativos extends Command
{
    protected $signature = 'creative:limpar-referencias
        {--horas= : Idade mínima do REGISTRO para recolher a referência (default: config)}
        {--dry-run : Mostra o que seria apagado e não apaga nada}
        {--orfaos  : Também remove diretórios em disco sem registro na tabela (desligado por padrão)}';

    protected $description = 'Retenção da foto de referência do Creative Engine (FOTO-03) — varredura diária por idade do registro';

    public function __construct(private ReferenciaEfemeraService $referenciaEfemera)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $horas  = max(1, (int) ($this->option('horas') ?: config('services.creative.retencao_referencias_horas')));
        $dryRun = (bool) $this->option('dry-run');
        $limite = now()->subHours($horas);

        $registros = 0;
        $arquivos  = 0;
        $bytes     = 0;

        // Seleciona pela LINHA da tabela, nunca pelo disco (a amarra central
        // desta task). `referencias_apagadas_em` já null + `referencias` não
        // vazio é o universo de candidatos; a idade do `created_at` decide o
        // resto.
        MlAnuncioCriativo::where('created_at', '<', $limite)
            ->whereNull('referencias_apagadas_em')
            ->whereNotNull('referencias')
            ->chunkById(500, function ($criativos) use (&$registros, &$arquivos, &$bytes, $dryRun) {
                foreach ($criativos as $criativo) {
                    // Contagem calculada ANTES de qualquer apagamento, e do
                    // MESMO jeito em --dry-run e na execução real — é isso
                    // que garante que o relatório do dry-run bata com o que
                    // a execução de verdade reportaria.
                    $referencias = $criativo->referenciasVivas();

                    $registros++;
                    $arquivos += count($referencias);
                    $bytes += array_sum(array_column($referencias, 'bytes'));

                    if ($dryRun) {
                        continue;
                    }

                    // Um registro desta idade ainda "em andamento" está
                    // travado por definição (LIMITE_MINUTOS=12 <<< a janela
                    // de retenção) — encerra ANTES de apagar, para nunca
                    // ficar "pendente"/"rodando" para sempre.
                    $criativo->encerrarSeTravada();
                    $this->referenciaEfemera->apagar($criativo);
                }
            });

        if ($dryRun) {
            $this->warn('--dry-run: nada foi apagado, nenhuma coluna foi escrita.');
        }

        $this->info(sprintf(
            'Referências recolhidas: %d registro(s), %d arquivo(s), %s byte(s) — janela > %dh.',
            $registros,
            $arquivos,
            number_format($bytes, 0, ',', '.'),
            $horas,
        ));

        if ($this->option('orfaos')) {
            $removidos = $this->removerOrfaos($horas, $dryRun);
            $this->info("Diretórios órfãos removidos: {$removidos}.");
        } else {
            $this->line('Limpeza de órfãos NÃO executada (use --orfaos para ativar).');
        }

        return self::SUCCESS;
    }

    /**
     * ÚNICO ramo deste comando que decide por DISCO em vez de por registro —
     * por isso é opt-in e desligado por padrão. Remove diretórios de
     * `creative-referencias/` cujo token não existe em nenhum
     * `MlAnuncioCriativo` E cuja última modificação é mais antiga que
     * `$horas`. Foi exatamente este tipo de decisão (por disco, não por
     * banco) que causou o incidente do ECF Drive — aqui fica isolado,
     * exigindo idade mínima, para quem está olhando e decidiu invocar à mão
     * (ex.: empresa excluída, diretório de teste manual esquecido).
     */
    private function removerOrfaos(int $horas, bool $dryRun): int
    {
        $disco  = Storage::disk('local');
        $limite = now()->subHours($horas)->getTimestamp();

        if (! $disco->exists('creative-referencias')) {
            return 0;
        }

        $removidos = 0;

        foreach ($disco->directories('creative-referencias') as $diretorio) {
            $token = basename($diretorio);

            if (MlAnuncioCriativo::where('token', $token)->exists()) {
                continue;
            }

            $mtime = $disco->lastModified($diretorio);
            if ($mtime !== false && $mtime >= $limite) {
                continue;
            }

            if (! $dryRun) {
                $disco->deleteDirectory($diretorio);
            }

            $removidos++;
        }

        return $removidos;
    }
}
