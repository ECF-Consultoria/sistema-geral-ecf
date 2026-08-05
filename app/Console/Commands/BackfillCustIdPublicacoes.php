<?php

namespace App\Console\Commands;

use App\Models\MlbEmpresa;
use App\Models\Publicacao;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Preenche o cust_id das publicações que nasceram sem ele, usando o Cust ID que
 * a empresa tem hoje.
 *
 * Complementa o MlbEmpresaObserver: o observer cobre daqui para frente, este
 * comando resolve o passivo. Publicação sem cust_id nunca puxa venda (o
 * VendasSyncService casa por cust_id), então essas linhas ficam com faturamento
 * zerado para sempre.
 *
 * Dry-run por padrão. Use --apply para gravar.
 */
class BackfillCustIdPublicacoes extends Command
{
    protected $signature = 'mlb:backfill-cust-id
                            {--apply : Grava as alterações (sem esta flag é só relatório)}
                            {--empresa= : Limita a uma empresa (id de mlb_empresas)}';

    protected $description = 'Preenche o cust_id das publicações vazias a partir da empresa vinculada';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');

        $query = Publicacao::query()
            ->join('mlb_empresas', 'mlb_empresas.id', '=', 'mlb_publicacoes.mlb_empresa_id')
            ->where(function ($q) {
                $q->whereNull('mlb_publicacoes.cust_id')->orWhere('mlb_publicacoes.cust_id', '');
            })
            ->whereNotNull('mlb_empresas.cust_id')
            ->where('mlb_empresas.cust_id', '!=', '');

        if ($empresaId = $this->option('empresa')) {
            $query->where('mlb_empresas.id', (int) $empresaId);
        }

        $linhas = $query->get([
            'mlb_publicacoes.id',
            'mlb_publicacoes.mlb_code',
            'mlb_publicacoes.data',
            'mlb_publicacoes.user_id',
            'mlb_empresas.id as empresa_id',
            'mlb_empresas.nome as empresa_nome',
            'mlb_empresas.cust_id as empresa_cust_id',
        ]);

        if ($linhas->isEmpty()) {
            $this->info('Nenhuma publicação para corrigir — todas as vazias pertencem a empresas que também não têm Cust ID.');
            $this->pendenteSemEmpresa();

            return self::SUCCESS;
        }

        $this->line('');
        $this->info(sprintf('%d publicação(ões) sem Cust ID, em empresa que já tem:', $linhas->count()));
        $this->line('');

        $porEmpresa = $linhas->groupBy('empresa_id');
        $this->table(
            ['Empresa', 'Cust ID', 'Publicações', 'Competências'],
            $porEmpresa->map(function ($g) {
                $meses = $g->map(fn ($l) => substr((string) $l->data, 0, 7))->unique()->sort()->values()->implode(', ');

                return [$g->first()->empresa_nome, $g->first()->empresa_cust_id, $g->count(), $meses];
            })->values()->all()
        );

        if (! $apply) {
            $this->line('');
            $this->warn('DRY-RUN — nada foi gravado. Rode com --apply para aplicar.');
            $this->pendenteSemEmpresa();

            return self::SUCCESS;
        }

        // O update passa por Eloquent na empresa? Não: aqui a escrita é direta na
        // publicação, então o observer não entra em jogo (nem deveria — ele reage a
        // mudança de cust_id da EMPRESA, e aqui a empresa não muda).
        $total = DB::transaction(function () use ($porEmpresa) {
            $n = 0;
            foreach ($porEmpresa as $grupo) {
                $n += Publicacao::whereIn('id', $grupo->pluck('id'))
                    ->update(['cust_id' => $grupo->first()->empresa_cust_id]);
            }

            return $n;
        });

        $this->line('');
        $this->info("{$total} publicação(ões) atualizada(s).");
        $this->warn('As vendas dessas linhas só aparecem depois de sincronizar a competência delas.');

        activity('mlb')
            ->withProperties(['publicacoes' => $total, 'empresas' => $porEmpresa->count()])
            ->log("Backfill de Cust ID aplicado em {$total} publicação(ões)");

        $this->pendenteSemEmpresa();

        return self::SUCCESS;
    }

    /**
     * O que o backfill não alcança — relatório para a liderança agir no cadastro.
     */
    private function pendenteSemEmpresa(): void
    {
        $semEmpresaCust = Publicacao::query()
            ->join('mlb_empresas', 'mlb_empresas.id', '=', 'mlb_publicacoes.mlb_empresa_id')
            ->where(function ($q) {
                $q->whereNull('mlb_publicacoes.cust_id')->orWhere('mlb_publicacoes.cust_id', '');
            })
            ->where(function ($q) {
                $q->whereNull('mlb_empresas.cust_id')->orWhere('mlb_empresas.cust_id', '');
            })
            ->selectRaw('mlb_empresas.nome, COUNT(*) as total')
            ->groupBy('mlb_empresas.nome')
            ->orderByDesc('total')
            ->get();

        $orfas = Publicacao::whereNull('mlb_empresa_id')
            ->where(function ($q) {
                $q->whereNull('cust_id')->orWhere('cust_id', '');
            })
            ->count();

        if ($semEmpresaCust->isEmpty() && $orfas === 0) {
            return;
        }

        $this->line('');
        $this->warn('Fora do alcance do backfill — precisam de cadastro, não de código:');

        if ($semEmpresaCust->isNotEmpty()) {
            $this->line('');
            $this->line('  Empresas sem Cust ID cadastrado:');
            foreach ($semEmpresaCust as $e) {
                $this->line(sprintf('    %-40s %4d publicação(ões)', $e->nome, $e->total));
            }
            $this->line('');
            $this->line('  Assim que o Cust ID for cadastrado na empresa, as publicações são');
            $this->line('  preenchidas sozinhas (MlbEmpresaObserver) — não precisa recadastrar nada.');
        }

        if ($orfas > 0) {
            $this->line('');
            $this->line("  {$orfas} publicação(ões) de Registro Livre, sem empresa vinculada.");
        }
    }
}
