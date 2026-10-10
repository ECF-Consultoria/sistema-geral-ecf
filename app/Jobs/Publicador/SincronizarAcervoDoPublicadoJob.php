<?php

namespace App\Jobs\Publicador;

use App\Models\Company;
use App\Services\Mlb\Acervo\MlAcervoService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Publicou: os MLB recém-criados entram no acervo AGORA (quick 261010-nke,
 * 10/10/2026).
 *
 * ─── O que este job resolve ─────────────────────────────────────────────────
 *
 * A publicação do Publicador grava em `pub_publicacao_itens`; a aba
 * Publicações (`/mlb/anuncios/meus/{company}`) lê EXCLUSIVAMENTE
 * `ml_acervo_itens` (D-05). Nada ligava as duas coisas: o anúncio recém
 * publicado só aparecia na varredura do dia seguinte. Medido em produção em
 * 10/10/2026 — `MLB5366398961` e `MLB5366495199` (Poltrona Beny) existiam no
 * Mercado Livre e não existiam no acervo.
 *
 * A decisão do usuário foi FONTE ÚNICA: a publicação dispara o sync daquele
 * item; não insere linha no acervo por um segundo caminho. Por isso este job
 * é o ÚNICO consumidor de `MlAcervoService::coletarItens()`, e toda escrita
 * continua acontecendo dentro de `processarLote()` — serializada por
 * `AcervoEscritaLock::naEmpresa()`, com o 3º argumento literal do `upsert()`.
 *
 * ─── Por que a fila `high` ──────────────────────────────────────────────────
 *
 * Job INTERATIVO: alguém acabou de clicar em Publicar e vai olhar a aba. A
 * `default` fica atrás do Adman e do Acervo (§16/§22 dos learnings do
 * Publicador) e já segurou webhook por horas. `onQueue('high')` é chamado no
 * CONSTRUTOR de propósito: `Queueable` já declara `$queue`, e redeclarar a
 * propriedade é erro fatal de PHP neste projeto.
 *
 * Recebe `companyId` (int), não o model `Company` serializado — molde de
 * `SyncMlAcervoDetalheJob`.
 */
class SincronizarAcervoDoPublicadoJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    /** Uma publicação cria no máximo ~20 itens, ou seja 1 multiget. */
    public int $timeout = 120;

    public function __construct(
        public readonly int $companyId,
        public readonly array $mlItemIds,
    ) {
        // Interativo: vai para a `high`. NUNCA redeclarar $queue (ver docblock).
        $this->onQueue('high');
    }

    /**
     * Chave de unicidade por LOTE (`companyId` + hash dos ids), nunca só por
     * empresa: duas publicações de produtos DIFERENTES da mesma empresa
     * precisam sincronizar as duas. Chave por empresa descartaria a segunda em
     * silêncio — exatamente o modo de falha do `SyncMlAcervoCompanyJob` que
     * fez este caminho existir.
     *
     * (A chave do lock de unicidade do Laravel inclui a classe do job, então
     * ela nunca colide com `SyncMlAcervoCompanyJob` — e, pelo mesmo motivo,
     * também não protege contra a camada cara. Quem serializa a escrita é o
     * `AcervoEscritaLock`.)
     */
    public function uniqueId(): string
    {
        return $this->companyId . ':' . md5(implode(',', $this->mlItemIds));
    }

    /** TTL do lock > timeout + backoff máximo. */
    public function uniqueFor(): int
    {
        return 600;
    }

    /** Backoff: 30s, 2min — o item acabou de nascer no ML e pode demorar a responder. */
    public function backoff(): array
    {
        return [30, 120];
    }

    public function handle(MlAcervoService $acervo): void
    {
        $company = Company::with('mlToken')->find($this->companyId);

        // A conta que publica pode ser uma `MlbEmpresa` sem Company (535 de
        // 539 não têm): nesse caso não existe aba Publicações para essa conta
        // e o acervo não é indexado por ela. PULAR com log, nunca lançar — o
        // anúncio já está no ML e falhar aqui não desfaz nada.
        if ($company === null) {
            Log::warning("[MLB Anuncios] sync do recém-publicado pulado: Company {$this->companyId} não existe.");

            return;
        }

        $token = $company->mlToken;

        if ($token === null || $token->status !== 'active') {
            Log::warning(
                "[MLB Anuncios] sync do recém-publicado pulado: empresa {$this->companyId} sem token ML ativo "
                . '(status: ' . ($token->status ?? 'sem token') . ').'
            );

            return;
        }

        $resumo = $acervo->coletarItens($company, $this->mlItemIds);

        Log::info(
            "[MLB Anuncios] acervo do recém-publicado — empresa {$this->companyId}: "
            . count($this->mlItemIds) . " MLB pedidos, {$resumo['itens']} itens, "
            . "{$resumo['lotes']} lote(s), {$resumo['falhas']} falhas."
        );
    }

    /**
     * Falha definitiva: SÓ log. Nunca carimba `coleta_erro` — o carimbo de
     * faixa inteira mentia na tela e realimentava o deadlock
     * (`.planning/debug/resolved/acervo-deadlock-upsert.md`, E6). Sem a linha,
     * a varredura diária do acervo cobre o item no dia seguinte.
     */
    public function failed(\Throwable $e): void
    {
        Log::error(
            "[MLB Anuncios] falha definitiva no sync do recém-publicado — empresa {$this->companyId}, "
            . count($this->mlItemIds) . " MLB: {$e->getMessage()}"
        );
    }
}
