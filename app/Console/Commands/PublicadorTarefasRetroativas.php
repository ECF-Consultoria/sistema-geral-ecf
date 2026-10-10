<?php

namespace App\Console\Commands;

use App\Models\PubPublicacao;
use App\Models\PubPublicacaoItem;
use App\Models\PubTarefa;
use App\Services\Publicador\Tarefas\TarefasPosPublicacao;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Tarefas pós-publicação para publicações ANTERIORES ao gatilho (09/10/2026). O gatilho só vale para o
 * que for publicado depois do deploy (sem backfill, decisão do usuário); este comando é para quem
 * quiser trazer o passado. Mesma regra e mesma idempotência do gatilho (`TarefasPosPublicacao::abrir`):
 * rodar duas vezes não duplica. NÃO avisa no sino — a tarefa antiga só aparece na fila (já atrasada:
 * o prazo é D+1 útil da publicação).
 */
class PublicadorTarefasRetroativas extends Command
{
    protected $signature = 'publicador:tarefas-retroativas
        {--desde= : Data inicial (AAAA-MM-DD) da conclusão da publicação}
        {--dry-run : Só lista o que seria aberto, sem gravar}';

    protected $description = 'Abre as tarefas pós-publicação (alavancas) de publicações concluídas desde a data informada';

    public function handle(TarefasPosPublicacao $tarefas): int
    {
        $desde = (string) $this->option('desde');
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $desde) !== 1) {
            $this->error('Informe --desde=AAAA-MM-DD. Nada foi gravado.');

            return self::FAILURE;
        }
        $inicio = CarbonImmutable::createFromFormat('!Y-m-d', $desde, config('app.timezone'));
        $simular = (bool) $this->option('dry-run');

        // Concluída (não RUNNING) desde a data e com algum item CRIADO — a mesma regra do gatilho.
        $publicacoes = PubPublicacao::query()
            ->where('status', '!=', PubPublicacao::RUNNING)
            ->where('concluida_em', '>=', $inicio)
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('pub_publicacao_itens')
                ->whereColumn('pub_publicacao_itens.publicacao_id', 'pub_publicacoes.id')
                ->where('pub_publicacao_itens.status', PubPublicacaoItem::CREATED)
                ->whereNotNull('pub_publicacao_itens.ml_item_id'))
            ->orderBy('id')->get();

        $linhas = [];
        $abertas = 0;
        foreach ($publicacoes as $p) {
            $quando = $p->concluida_em?->format('d/m/Y H:i');
            if ($simular) {
                $linhas[] = [$p->id, $p->rascunho_id, $quando, $this->previsao($p)];

                continue;
            }
            $t = $tarefas->abrir($p, avisar: false);
            if ($t !== null && $t->wasRecentlyCreated) {
                $abertas++;
            }
            $linhas[] = [$p->id, $p->rascunho_id, $quando, match (true) {
                $t === null => 'nada a abrir',
                $t->wasRecentlyCreated => "aberta (#{$t->id})",
                default => "coberta pela #{$t->id}",
            }];
        }

        $this->table(['Publicação', 'Rascunho', 'Concluída em', 'Tarefa'], $linhas);
        $this->info($simular
            ? 'SIMULAÇÃO — nada foi gravado. '.count($publicacoes).' publicação(ões) com item criado desde '.$inicio->format('d/m/Y').'.'
            : "{$abertas} tarefa(s) aberta(s), sem aviso no sino.");

        return self::SUCCESS;
    }

    /** O que o `abrir()` faria, sem gravar. */
    private function previsao(PubPublicacao $p): string
    {
        $ja = PubTarefa::query()->alavancas()->where('publicacao_id', $p->id)->value('id');
        if ($ja !== null) {
            return "já existe (#{$ja})";
        }
        $ids = PubPublicacaoItem::query()->where('publicacao_id', $p->id)->where('status', PubPublicacaoItem::CREATED)
            ->whereNotNull('ml_item_id')->pluck('ml_item_id')->map(fn ($id) => (string) $id)->all();
        $doRascunho = PubTarefa::query()->alavancas()->where('rascunho_id', $p->rascunho_id)->orderBy('id')->get();
        $cobertos = $doRascunho->flatMap(fn (PubTarefa $t) => $t->idsDosItens())->all();
        if (array_diff($ids, $cobertos) === []) {
            return 'já coberta';
        }
        $aberta = $doRascunho->first(fn (PubTarefa $t) => $t->aberta());

        return $aberta !== null ? "juntaria à #{$aberta->id}" : 'abriria';
    }
}
