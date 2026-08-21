<?php

namespace App\Console\Commands;

use App\Jobs\SyncMlAcervoCompanyJob;
use App\Jobs\SyncMlAcervoDetalheJob;
use App\Models\Company;
use App\Services\Mlb\Acervo\MlAcervoDetalheService;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Fan-out diário do acervo "Meus Anúncios" (Fase 134, D-06) — para cada
 * empresa com token ML ativo, enfileira o job da camada BARATA (multiget,
 * D-19, 134-04) e os jobs da camada CARA (fatia da rotação, D-23, 134-05).
 *
 * O comando ENFILEIRA, não coleta em processo (D-05): uma execução manual
 * não pode segurar o terminal por uma hora — a coleta acontece nos workers.
 *
 * Escopo de empresas reusa EXATAMENTE o filtro de SyncMlData (ml:sync):
 * `active=true` + `mlToken.status=active`. Não reinventar "quem tem token".
 */
class SyncMlAcervo extends Command
{
    protected $signature = 'mlb:sync-acervo
        {--company=   : Coleta apenas uma empresa específica (ID da Company)}
        {--n=         : Dias da rotação da camada cara (default: config mlb_acervo.rotacao_n)}
        {--so-barata  : Roda só a camada barata (multiget), sem visitas nem buy box}
        {--so-detalhe : Roda só a camada cara (fatia da rotação)}';

    protected $description = 'Enfileira a coleta diária do acervo "Meus Anúncios" (camada barata + camada cara) por empresa com token ML ativo';

    public function handle(MlAcervoDetalheService $detalheService): int
    {
        $soBarata = (bool) $this->option('so-barata');
        $soDetalhe = (bool) $this->option('so-detalhe');

        if ($soBarata && $soDetalhe) {
            $this->error('--so-barata e --so-detalhe são mutuamente exclusivas.');

            return self::FAILURE;
        }

        // D-23: N é OPÇÃO do comando — nunca constante literal. Cai para a
        // config só quando a opção não vem.
        $n = $this->option('n') !== null
            ? max(1, (int) $this->option('n'))
            : (int) config('mlb_acervo.rotacao_n');

        $companies = $this->resolverEmpresas();

        if ($companies === null) {
            return self::FAILURE;
        }

        $total = $companies->count();

        if ($total === 0) {
            $this->info('Nenhuma empresa com token ML ativo — nada a enfileirar.');

            return self::SUCCESS;
        }

        $this->info("Enfileirando acervo de {$total} empresa(s) (N={$n})...");

        $chunkDetalhe = max(1, (int) config('mlb_acervo.chunk_detalhe'));
        $totalJobsBarata = 0;
        $totalJobsDetalhe = 0;

        foreach ($companies as $i => $company) {
            // Delay de 2s por posição — mesmo padrão já em produção no
            // ml:sync (SyncMlData). O 134-RESEARCH.md §3 registra confiança
            // MÉDIA de que o rate limit é por vendedor (não por app), o que
            // permitiria folga maior — mas recomenda explicitamente NÃO
            // afrouxar o delay já em produção sem confirmação. Mantido 2s.
            $delayBarata = $i * 2;
            $jobsBarata = 0;
            $jobsDetalhe = 0;

            if (! $soDetalhe) {
                SyncMlAcervoCompanyJob::dispatch($company)
                    ->delay(now()->addSeconds($delayBarata));
                $jobsBarata = 1;
                $totalJobsBarata++;
            }

            if (! $soBarata) {
                // A ordem importa: em execução completa, a camada barata é
                // quem insere itens novos na tabela; a fatia da camada cara
                // é selecionada sobre o que JÁ ESTÁ no banco no instante
                // deste comando (síncrono, antes de qualquer job rodar).
                // Item recém-descoberto nesta mesma execução só ganha
                // detalhe na execução seguinte — comportamento aceitável e
                // coerente com o D-18 ("não avaliado" é estado de primeira
                // classe), documentado aqui para ninguém tratar como bug.
                $fatia = $detalheService->selecionarFatia($company, $n);
                $lotes = array_chunk($fatia, $chunkDetalhe);

                // O delay do detalhe continua de onde o fan-out da camada
                // barata DESTA empresa parou.
                //
                // ATENÇÃO — este delay NÃO separa as duas camadas, e nunca
                // separou. O comentário anterior aqui afirmava que ele evitava
                // "os jobs de detalhe competirem com os de multiget da mesma
                // empresa na mesma janela": era falso. A camada barata tem
                // timeout de 1800s e a maior conta faz ~3.340 lotes de
                // multiget, então 2 segundos garantem o OPOSTO — a sobreposição
                // é a regra. Foi essa a origem dos ~20 deadlocks/dia desde
                // 12/08/2026 (.planning/debug/acervo-deadlock-upsert.md, E4).
                //
                // Quem serializa as escritas das duas camadas é o
                // AcervoEscritaLock, no nível da escrita — não este delay, que
                // segue existindo só para escalonar pressão de rate limit no
                // Mercado Livre.
                $delayDetalheBase = $delayBarata + 2;

                foreach ($lotes as $j => $lote) {
                    SyncMlAcervoDetalheJob::dispatch($company->id, $lote)
                        ->delay(now()->addSeconds($delayDetalheBase + $j * 2));
                    $jobsDetalhe++;
                }

                $totalJobsDetalhe += $jobsDetalhe;
            }

            $this->line("  {$company->id} ({$company->name}): {$jobsBarata} job(s) de barata, {$jobsDetalhe} job(s) de detalhe");
        }

        $this->info("Total enfileirado: {$totalJobsBarata} job(s) de camada barata, {$totalJobsDetalhe} job(s) de camada cara.");

        Log::info(
            "[MLB Anuncios] fan-out mlb:sync-acervo: {$total} empresa(s), "
            . "{$totalJobsBarata} jobs de barata, {$totalJobsDetalhe} jobs de detalhe, N={$n}"
        );

        return self::SUCCESS;
    }

    /**
     * Resolve as empresas a processar. Reusa EXATAMENTE o filtro de
     * SyncMlData::handle() — active=true + mlToken.status=active — para não
     * reinventar "quem tem token".
     *
     * @return Collection<int, Company>|null  null em erro (empresa inexistente ou sem token ativo com --company)
     */
    private function resolverEmpresas(): ?Collection
    {
        $companyId = $this->option('company');

        if ($companyId) {
            $company = Company::find($companyId);

            if (! $company) {
                $this->error("Empresa {$companyId} não encontrada.");

                return null;
            }

            if (! $company->mlToken || $company->mlToken->status !== 'active') {
                $this->error("Empresa {$company->name} não tem token ML ativo.");

                return null;
            }

            return collect([$company]);
        }

        return Company::query()
            ->where('active', true)
            ->whereHas('mlToken', fn ($q) => $q->where('status', 'active'))
            ->with('mlToken')
            ->get();
    }
}
