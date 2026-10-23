<?php

namespace App\Console\Commands;

use App\Jobs\Publicador\RelerEstadoDoPublicadoJob;
use App\Models\PubPublicacao;
use App\Models\PubPublicacaoItem;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * R17 (pós-demo, 22/10/2026) — relê no Mercado Livre o estado dos anúncios JÁ publicados pelo Publicador.
 *
 * O `RelerEstadoDoPublicadoJob` só nasceu em 22/10 e só é agendado no fim de cada publicação nova: os anúncios
 * de antes (na #459, MLB5439825875, MLB5439857995 e os 4 da Opala) seguiam "Publicado" na lista final do
 * editor estando "Anúncio pausado" no ML. Este comando escolhe os itens criados (`pub_publicacao_itens` com
 * `ml_item_id`) e agenda o MESMO Job, com a lista de itens, para uma leitura só.
 *
 * - **Simulação por padrão:** sem `--aplicar` só conta e mostra; nada vai para a fila.
 * - **Só leitura no ML** (`GET /items/{id}`, pelo `ClienteMlPublicador` do Job) e só na conta liberada do
 *   MESMO vendedor que publicou (`RelerEstadoDoPublicadoJob::contaQueLe`); o resto aparece como "fora".
 * - **Quem entra:** publicado nos últimos `--dias`, não lido nas últimas `--horas` (`avisos.estado.lido_em`) e
 *   não `closed` no ML (encerrado não volta). Os nunca lidos primeiro, depois os lidos há mais tempo.
 * - **Teto e lotes:** no máximo `--teto` anúncios por rodada (= GETs); Jobs de até `lote` anúncios, um a cada
 *   `intervalo_s` segundos (`publicador.releitura_publicados`), para não fazer rajada no limite do ML.
 *
 * A rodada diária (`routes/console.php`) roda com `--aplicar` e os padrões da config. À mão, `--horas=0` relê
 * mesmo o que foi lido hoje e `--conta=company-459` (a chave da URL do Publicador) restringe a uma conta.
 */
class PublicadorRelerPublicados extends Command
{
    protected $signature = 'publicador:reler-publicados
        {--conta= : só esta conta, pela chave do Publicador (company-459, empresa-12)}
        {--dias= : publicados nos últimos N dias (padrão: publicador.releitura_publicados.dias)}
        {--horas= : não relê o que foi lido há menos de N horas (padrão: publicador.releitura_publicados.horas)}
        {--teto= : no máximo N anúncios nesta rodada (padrão: publicador.releitura_publicados.teto)}
        {--aplicar : agenda as releituras de verdade (sem isto, só simula)}';

    protected $description = 'Relê no Mercado Livre o estado dos anúncios publicados pelo Publicador (simulação por padrão)';

    public function handle(): int
    {
        $cfg = (array) config('publicador.releitura_publicados', []);
        $dias = max(1, (int) ($this->option('dias') ?? $cfg['dias'] ?? 30));
        $horas = max(0, (int) ($this->option('horas') ?? $cfg['horas'] ?? 24));
        $teto = max(0, (int) ($this->option('teto') ?? $cfg['teto'] ?? 300));
        $lote = max(1, (int) ($cfg['lote'] ?? 20));
        $intervalo = max(0, (int) ($cfg['intervalo_s'] ?? 60));
        $conta = trim((string) ($this->option('conta') ?? ''));
        $aplicar = (bool) $this->option('aplicar');

        $r = $this->escolher($dias, $horas, $conta === '' ? null : $conta);
        $escolhidos = array_slice($r['candidatos'], 0, $teto);
        $acimaDoTeto = count($r['candidatos']) - count($escolhidos);

        // Jobs por publicação, de até `lote` anúncios; o atraso cresce de lote em lote.
        $jobs = [];
        foreach (collect($escolhidos)->groupBy('publicacao_id') as $publicacaoId => $itens) {
            foreach ($itens->pluck('id')->chunk($lote) as $pedaco) {
                $jobs[] = ['publicacao_id' => (int) $publicacaoId, 'itens' => $pedaco->values()->all()];
            }
        }

        $this->info(($aplicar ? '' : 'SIMULAÇÃO — nada foi agendado. ')
            ."Publicados nos últimos {$dias} dias, não lidos há {$horas} h".($conta !== '' ? ", conta {$conta}" : '').':');
        $this->line("  a reler: ".count($escolhidos).' anúncio(s) em '.count($jobs).' Job(s)'
            .($acimaDoTeto > 0 ? " · {$acimaDoTeto} acima do teto de {$teto} (ficam para a próxima rodada)" : ''));
        $this->line("  lidos há menos de {$horas} h: {$r['recentes']} · encerrados no ML: {$r['encerrados']}"
            ." · fora (conta não liberada ou outro vendedor): {$r['fora']}");
        if ($r['por_conta'] !== []) {
            $this->line('  a reler por conta (antes do teto):');
        }
        foreach ($r['por_conta'] as $chave => $n) {
            $this->line("    {$chave}: {$n}");
        }

        if (! $aplicar) {
            if ($jobs !== []) {
                $this->line('Rode com --aplicar para agendar (fila high, só leitura no Mercado Livre).');
            }

            return self::SUCCESS;
        }

        foreach ($jobs as $n => $job) {
            RelerEstadoDoPublicadoJob::dispatch($job['publicacao_id'], $job['itens'])->delay(now()->addSeconds($n * $intervalo));
        }
        $this->info(count($jobs).' Job(s) agendado(s), um a cada '.$intervalo.' s.');

        return self::SUCCESS;
    }

    /**
     * @return array{candidatos: list<array{id: int, publicacao_id: int, lido: int}>, recentes: int, encerrados: int, fora: int, por_conta: array<string, int>}
     */
    private function escolher(int $dias, int $horas, ?string $soConta): array
    {
        $corte = now()->subDays($dias);
        $lidoAntesDe = now()->subHours($horas);

        $itens = PubPublicacaoItem::query()
            ->where('status', PubPublicacaoItem::CREATED)
            ->whereNotNull('ml_item_id')
            ->where(fn ($q) => $q->where('criado_em', '>=', $corte)->orWhere(fn ($q) => $q->whereNull('criado_em')->where('created_at', '>=', $corte)))
            ->orderByDesc('id')
            ->get(['id', 'publicacao_id', 'ml_item_id', 'avisos'])
            // O mesmo MLB em duas publicações (reconciliado, republicado): uma leitura basta — a do item mais novo.
            ->unique('ml_item_id');

        $contas = [];
        $r = ['candidatos' => [], 'recentes' => 0, 'encerrados' => 0, 'fora' => 0, 'por_conta' => []];
        foreach ($itens as $item) {
            $pid = (int) $item->publicacao_id;
            if (! array_key_exists($pid, $contas)) {
                $p = PubPublicacao::find($pid);
                $chave = (string) ($p?->ator['conta']['chave'] ?? '');
                $contas[$pid] = ['chave' => $chave, 'le' => $p !== null && ($soConta === null || $chave === $soConta) && RelerEstadoDoPublicadoJob::contaQueLe($p) !== null, 'filtrada' => $soConta !== null && $chave !== $soConta];
            }
            if ($contas[$pid]['filtrada']) {
                continue;
            }
            if (! $contas[$pid]['le']) {
                $r['fora']++;

                continue;
            }

            $estado = (array) ($item->avisos['estado'] ?? []);
            if (($estado['status'] ?? null) === 'closed') {
                $r['encerrados']++;

                continue;
            }
            $lidoEm = self::data($estado['lido_em'] ?? null);
            if ($lidoEm !== null && $horas > 0 && $lidoEm->greaterThan($lidoAntesDe)) {
                $r['recentes']++;

                continue;
            }

            $r['candidatos'][] = ['id' => (int) $item->id, 'publicacao_id' => $pid, 'lido' => $lidoEm?->getTimestamp() ?? 0];
            $chave = $contas[$pid]['chave'] !== '' ? $contas[$pid]['chave'] : 'sem chave';
            $r['por_conta'][$chave] = ($r['por_conta'][$chave] ?? 0) + 1;
        }

        // Nunca lido (0) antes do lido há mais tempo; empate pelo item mais antigo.
        usort($r['candidatos'], fn ($a, $b) => [$a['lido'], $a['id']] <=> [$b['lido'], $b['id']]);
        ksort($r['por_conta']);

        return $r;
    }

    private static function data(mixed $valor): ?Carbon
    {
        if (! is_string($valor) || $valor === '') {
            return null;
        }
        try {
            return Carbon::parse($valor);
        } catch (\Throwable) {
            return null;
        }
    }
}
