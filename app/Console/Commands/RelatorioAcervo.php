<?php

namespace App\Console\Commands;

use App\Models\AcervoImagem;
use App\Models\MlAnuncioCriativo;
use App\Models\PubImagem;
use App\Models\PubRascunho;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Relatório SÓ DE LEITURA do armazenamento de imagens (v28.0 — MIG-01, RET-01, RET-02). Nunca apaga nada.
 *
 * 1. Inventário do disco local por pasta (o que a migração da Fase 200 vai mover e o que é transitório).
 * 2. Por conta do ML: imagens no acervo, volume, uploads × IA, entradas por mês e crescimento.
 * 3. Candidatos a descarte (só lista): ativo sem uso há mais de N dias, ativo só em rascunho parado há mais
 *    de N dias, e conta sem nenhuma atividade no Publicador há mais de N dias. "Inatividade" aqui é
 *    provisória (sem rascunho atualizado e sem imagem nova) — a definição oficial é da operação da ECF.
 * 4. Com `--orfaos`: arquivos no disco local que nenhum registro do banco aponta.
 */
class RelatorioAcervo extends Command
{
    protected $signature = 'acervo:relatorio
        {--dias=90 : Janela de inatividade para os candidatos a descarte}
        {--meses=6 : Quantos meses mostrar na série de entradas}
        {--orfaos : Também varre o disco local atrás de arquivos sem registro no banco (mais lento)}';

    protected $description = 'Relatório só de leitura: inventário, volume por conta, crescimento e candidatos a descarte';

    private const PASTAS_LOCAIS = ['publicador', 'imagens', 'miniaturas', 'creative-geradas', 'creative-referencias'];

    public function handle(): int
    {
        $dias = max(1, (int) $this->option('dias'));
        $meses = max(1, (int) $this->option('meses'));

        $this->inventarioLocal();
        $this->porConta($meses);
        $this->candidatos($dias);
        if ($this->option('orfaos')) {
            $this->orfaosLocais();
        }

        $this->info('Relatório só de leitura — nada foi alterado.');

        return self::SUCCESS;
    }

    private function inventarioLocal(): void
    {
        $local = Storage::disk('local');
        $linhas = [];
        foreach (self::PASTAS_LOCAIS as $pasta) {
            $arquivos = $local->allFiles($pasta);
            $bytes = array_sum(array_map(fn ($a) => (int) $local->size($a), $arquivos));
            $linhas[] = [$pasta, count($arquivos), self::mb($bytes)];
        }

        $this->info('1. Disco local da VPS (storage/app/private)');
        $this->table(['pasta', 'arquivos', 'MB'], $linhas);
        $this->table(['acervo (banco)', 'quantidade'], [
            ['ativos disponíveis', AcervoImagem::where('status', AcervoImagem::DISPONIVEL)->count()],
            ['  no disco local', AcervoImagem::where('status', AcervoImagem::DISPONIVEL)->where('disco', 'local')->count()],
            ['  no R2', AcervoImagem::where('status', AcervoImagem::DISPONIVEL)->where('disco', 'r2')->count()],
            ['fotos de rascunho sem ativo (com arquivo)', PubImagem::whereNull('acervo_imagem_id')->whereNotNull('caminho')->count()],
            ['excluindo / falhou', AcervoImagem::whereIn('status', [AcervoImagem::EXCLUINDO, AcervoImagem::FALHOU])->count()],
        ]);
    }

    private function porConta(int $meses): void
    {
        $desde = now()->startOfMonth()->subMonths($meses - 1);
        $porMes = AcervoImagem::query()->where('created_at', '>=', $desde)
            ->get(['dono_chave', 'created_at', 'bytes'])
            ->groupBy('dono_chave')
            ->map(fn ($l) => $l->groupBy(fn ($a) => $a->created_at->format('Y-m'))->map->count());

        $mesAtual = now()->format('Y-m');
        $mesAnterior = now()->subMonthNoOverflow()->format('Y-m');

        $linhas = AcervoImagem::query()->where('status', AcervoImagem::DISPONIVEL)
            ->select('dono_chave', DB::raw('count(*) as n'), DB::raw('sum(bytes) as b'),
                DB::raw("sum(case when origem = 'ia' then 1 else 0 end) as ia"))
            ->groupBy('dono_chave')->orderByDesc('b')->get()
            ->map(fn ($r) => [
                $r->dono_chave, (int) $r->n, self::mb((int) $r->b), (int) $r->n - (int) $r->ia, (int) $r->ia,
                (int) ($porMes[$r->dono_chave][$mesAnterior] ?? 0), (int) ($porMes[$r->dono_chave][$mesAtual] ?? 0),
            ])->all();

        $this->info('2. Acervo por conta (c = empresa, m = polo)');
        $this->table(['conta', 'imagens', 'MB', 'enviadas', 'IA', "entradas {$mesAnterior}", "entradas {$mesAtual}"], $linhas);

        $total = AcervoImagem::query()->where('status', AcervoImagem::DISPONIVEL);
        $serie = AcervoImagem::query()->where('created_at', '>=', $desde)->get(['created_at', 'bytes', 'origem'])
            ->groupBy(fn ($a) => $a->created_at->format('Y-m'))
            ->map(fn ($l, $mes) => [$mes, $l->count(), $l->where('origem', 'ia')->count(), self::mb($l->sum('bytes'))])
            ->sortKeys()->values()->all();
        $this->table(['mês', 'entradas', 'das quais IA', 'MB'], $serie);
        $this->line('Total no acervo: '.(clone $total)->count().' imagens, '.self::mb((int) (clone $total)->sum('bytes')).' MB. '
            .'Excluídas até hoje: '.AcervoImagem::where('status', AcervoImagem::EXCLUIDA)->count().'.');
    }

    private function candidatos(int $dias): void
    {
        $limite = now()->subDays($dias);
        $publicado = [PubRascunho::PUBLISHED];

        $semUso = AcervoImagem::query()->where('status', AcervoImagem::DISPONIVEL)->where('created_at', '<', $limite)->doesntHave('usos');

        $soEmRascunhoParado = AcervoImagem::query()->where('status', AcervoImagem::DISPONIVEL)
            ->whereHas('usos')
            ->whereDoesntHave('usos.rascunho', fn ($q) => $q->where(fn ($w) => $w->whereIn('status', $publicado)->orWhere('updated_at', '>=', $limite)));

        $ativosRecentes = AcervoImagem::query()->where('created_at', '>=', $limite)->distinct()->pluck('dono_chave');
        $rascunhosRecentes = PubRascunho::query()->where('pub_rascunhos.updated_at', '>=', $limite)
            ->join('pub_produtos', 'pub_produtos.id', '=', 'pub_rascunhos.produto_id')
            ->get(['pub_produtos.mlb_empresa_id', 'pub_produtos.company_id'])
            ->map(fn ($p) => AcervoImagem::donoDe($p->mlb_empresa_id, $p->company_id)['chave'] ?? null)->filter();
        $ativas = $ativosRecentes->merge($rascunhosRecentes)->unique()->all();
        $inativas = AcervoImagem::query()->where('status', AcervoImagem::DISPONIVEL)->whereNotIn('dono_chave', $ativas)
            ->select('dono_chave', DB::raw('count(*) as n'), DB::raw('sum(bytes) as b'))->groupBy('dono_chave')->get();

        $this->info("3. Candidatos a descarte (inatividade de {$dias} dias) — SÓ LISTA, nada é apagado");
        $this->table(['categoria', 'imagens', 'MB'], [
            ['sem uso em nenhum anúncio', (clone $semUso)->count(), self::mb((int) (clone $semUso)->sum('bytes'))],
            ['só em rascunho parado (não publicado)', (clone $soEmRascunhoParado)->count(), self::mb((int) (clone $soEmRascunhoParado)->sum('bytes'))],
            ['de contas sem atividade ('.$inativas->count().' conta(s))', (int) $inativas->sum('n'), self::mb((int) $inativas->sum('b'))],
        ]);
        $this->line('Gerações de IA não aprovadas com mais de '.config('publicador.acervo.retencao_geracoes_dias', 30).' dias: ver `acervo:limpar-geracoes` (simulação).');
    }

    private function orfaosLocais(): void
    {
        $local = Storage::disk('local');
        $orfaos = 0;
        $bytes = 0;
        // Tudo o que o banco aponta, montado UMA vez (uma consulta por arquivo não escala).
        $referenciados = array_flip(array_filter([
            ...PubImagem::whereNotNull('caminho')->pluck('caminho')->all(),
            ...AcervoImagem::pluck('caminho')->all(),
            ...AcervoImagem::whereNotNull('miniatura_caminho')->pluck('miniatura_caminho')->all(),
            ...AcervoImagem::whereNotNull('caminhos_legados')->pluck('caminhos_legados')->flatten()->all(),
            ...MlAnuncioCriativo::whereNotNull('imagem_path')->pluck('imagem_path')->all(),
        ], 'is_string'));
        foreach (['publicador', 'imagens', 'miniaturas'] as $pasta) {
            foreach ($local->allFiles($pasta) as $arquivo) {
                if (! isset($referenciados[$arquivo])) {
                    $orfaos++;
                    $bytes += (int) $local->size($arquivo);
                }
            }
        }

        $this->info('4. Arquivos locais sem registro no banco (publicador/, imagens/, miniaturas/)');
        $this->table(['órfãos', 'MB'], [[$orfaos, self::mb($bytes)]]);
    }

    private static function mb(int $bytes): string
    {
        return number_format($bytes / 1048576, 1, ',', '.');
    }
}
