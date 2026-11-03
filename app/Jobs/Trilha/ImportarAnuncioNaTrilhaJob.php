<?php

namespace App\Jobs\Trilha;

use App\Models\Company;
use App\Models\User;
use App\Services\Publicador\PreparoIaAgenda;
use App\Services\Trilha\ImportacaoDoMercadoLivre;
use App\Support\Portal\AtorDoPortal;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Importa UM anúncio do Mercado Livre como produto da Lista da trilha (3ª entrega, F8, desenho §18 — D36). A regra mora
 * em {@see ImportacaoDoMercadoLivre::executar()}.
 *
 * **Fila da IA (`PreparoIaAgenda::fila()`), não a `high`.** Cada anúncio faz duas chamadas pagas à IA (ficha e título)
 * e baixa até 12 fotos por cor. Em produção a `high` tem UM worker, que também publica anúncio e manda o código de
 * acesso do cliente: 20 importações seguidas o prenderiam por minutos. A fila da IA já é a do preparo do Publicador
 * (onde o produto vai depois), com o worker próprio dela.
 *
 * Sem nova tentativa (`tries 1`): o produto já gravado não pode ser refeito por cima — a pessoa vê o resultado e decide.
 * A trava por anúncio usa a MESMA chave do outro caminho de importação (`estrutura:produtos-ml:importando:…`): os dois
 * nunca importam o mesmo MLB ao mesmo tempo, e a origem gravada deixa o segundo ver que o primeiro já trouxe. O ator é
 * refeito com `AtorDoPortal::daEquipe` (o histórico registra a pessoa); sem a pessoa, o Job não roda.
 */
class ImportarAnuncioNaTrilhaJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    /** Até 12 fotos por cor, baixadas uma a uma (20 s de teto cada), e duas chamadas à IA. */
    public int $timeout = 600;

    public function __construct(public int $companyId, public string $mlb, public int $userId)
    {
        $this->onQueue(PreparoIaAgenda::fila());
    }

    public function handle(ImportacaoDoMercadoLivre $importacao): void
    {
        $empresa = Company::find($this->companyId);
        if ($empresa === null) {
            return;
        }

        $quem = User::find($this->userId);
        if ($quem === null) {
            $importacao->marcarErro($empresa, $this->mlb, ImportacaoDoMercadoLivre::MSG_SEM_PESSOA);

            return;
        }

        $trava = Cache::lock("estrutura:produtos-ml:importando:{$empresa->id}:{$this->mlb}", $this->timeout);
        if (! $trava->get()) {
            // O mesmo anúncio está sendo importado agora pelo outro caminho: sem marcar, a tela esperaria para sempre.
            $importacao->marcarErro($empresa, $this->mlb, ImportacaoDoMercadoLivre::MSG_EM_ANDAMENTO);

            return;
        }

        try {
            $importacao->executar($empresa, $this->mlb, AtorDoPortal::daEquipe($quem));
        } catch (\Throwable $e) {
            $importacao->marcarErro($empresa, $this->mlb, ImportacaoDoMercadoLivre::MSG_FALHA);

            throw $e;
        } finally {
            $trava->release();
        }
    }

    public function failed(\Throwable $e): void
    {
        Log::error("[Trilha] Importar do ML: o anúncio {$this->mlb} da empresa {$this->companyId} falhou: {$e->getMessage()}");
    }
}
