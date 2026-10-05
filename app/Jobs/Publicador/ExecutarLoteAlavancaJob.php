<?php

namespace App\Jobs\Publicador;

use App\Models\PubAlavancaEscrita;
use App\Models\User;
use App\Services\Publicador\Alavancas\ContextoAlavancas;
use App\Services\Publicador\Alavancas\EscritorAlavancas;
use App\Services\Publicador\Alavancas\LeiturasDaAcao;
use App\Services\Publicador\Alavancas\RegistroDeAcoes;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Lote das Alavancas (D-04) — uma linha de histórico por produto, todas gravadas PENDENTE antes.
 * A trava e o vendedor valem no meio do lote (como o contaFixada da 164): cada item passa pelo
 * EscritorAlavancas, que reaplica as duas. Trabalha em fatias (~45 s); a próxima fatia é
 * release(), nunca dispatch (learnings §6: no driver sync um dispatch aqui dentro recursaria).
 *
 * Reentrega: linha com `enviado_em` vira INCERTO e nunca é reenviada; `Cache::lock` por lote
 * impede duas execuções juntas. Fila `high` (clique de pessoa).
 */
class ExecutarLoteAlavancaJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;

    public bool $failOnTimeout = true;

    public array $backoff = [30];

    public function __construct(public string $lote, public int $userId)
    {
        // No construtor porque `Queueable` já declara `$queue`.
        $this->onQueue('high');
    }

    /** Pode voltar para a fila várias vezes (trava ocupada, próximas fatias) — mas não para sempre. */
    public function retryUntil(): \DateTimeInterface
    {
        return now()->addMinutes(30);
    }

    public function handle(EscritorAlavancas $escritor, ContextoAlavancas $contexto): void
    {
        $trava = Cache::lock("alavancas:lote:{$this->lote}", (int) config('publicador.trava_segundos', 600));
        if (! $trava->get()) {
            // Outra execução está neste lote (ou morreu segurando a trava): tenta depois.
            $this->release(20);

            return;
        }

        try {
            if (! $this->fatia($escritor, $contexto)) {
                // A próxima fatia é este mesmo Job de volta à fila (não um dispatch novo).
                $this->release(15);
            }
        } finally {
            $trava->release();
        }
    }

    /** @return bool true quando não sobrou linha PENDENTE */
    private function fatia(EscritorAlavancas $escritor, ContextoAlavancas $contexto): bool
    {
        $ids = PubAlavancaEscrita::query()->where('lote_uuid', $this->lote)
            ->where('resultado', PubAlavancaEscrita::PENDENTE)->orderBy('id')->pluck('id')->all();
        if ($ids === []) {
            return true;
        }

        $user = User::find($this->userId);
        if ($user === null) {
            PubAlavancaEscrita::query()->whereIn('id', $ids)->get()->each->marcar(PubAlavancaEscrita::RECUSADA, [
                'erro_codigo' => 'ALAV-LOTE-USUARIO',
                'mensagem' => 'O usuário que confirmou o lote não existe mais. Nada foi enviado.',
            ]);

            return true;
        }

        $inicio = microtime(true);
        $limite = (float) config('publicador.fatia_segundos', 45);
        $leituras = $this->preCarregar($contexto, $ids);

        foreach ($ids as $k => $id) {
            $linha = PubAlavancaEscrita::find($id);
            if ($linha === null || $linha->resultado !== PubAlavancaEscrita::PENDENTE) {
                continue;
            }

            $this->processar($escritor, $contexto, $user, $linha, $leituras);

            // Passou do tempo da fatia e ainda sobra linha: devolve para a fila.
            if (microtime(true) - $inicio >= $limite && $k < count($ids) - 1) {
                return false;
            }
        }

        return true;
    }

    private function processar(EscritorAlavancas $escritor, ContextoAlavancas $contexto, User $user, PubAlavancaEscrita $linha, array $leituras): void
    {
        // A escrita pode ter saído antes de uma interrupção: nunca reenvia.
        if ($linha->enviado_em !== null) {
            $linha->marcar(PubAlavancaEscrita::INCERTO, [
                'mensagem' => 'A escrita pode ter saído antes de uma interrupção; confira o estado no Mercado Livre antes de repetir.',
            ]);

            return;
        }

        $conta = $contexto->daLinha($linha);
        if ($conta === null || $conta->chaveConta() !== $linha->conta_chave) {
            $linha->marcar(PubAlavancaEscrita::RECUSADA, [
                'erro_codigo' => 'V-ACC-03',
                'mensagem' => 'A conta do Mercado Livre desta empresa mudou depois da conferência. Nada foi enviado.',
            ]);

            return;
        }

        $classe = RegistroDeAcoes::classe((string) $linha->acao);
        $acao = new $classe($conta, (array) ($linha->payload['dados'] ?? []));
        if (isset($leituras[$linha->conta_chave])) {
            $acao->usarLeituras($leituras[$linha->conta_chave]);
        }

        $escritor->executar($acao, User::find($this->userId) ?? $user, $this->lote, $linha);
    }

    /**
     * Uma LeiturasDaAcao por conta, pré-carregada no início da fatia com os dados de todas as linhas
     * PENDENTE (um multiget por 20; cada promoção uma vez só). Conta fora da lista não lê nada.
     *
     * @param  list<int>  $ids
     * @return array<string, LeiturasDaAcao>
     */
    private function preCarregar(ContextoAlavancas $contexto, array $ids): array
    {
        $porConta = [];
        foreach (PubAlavancaEscrita::query()->whereIn('id', $ids)->orderBy('id')->get() as $linha) {
            $porConta[$linha->conta_chave]['linhas'][] = $linha;
        }

        $leituras = [];
        foreach ($porConta as $chave => $grupo) {
            $conta = $contexto->daLinha($grupo['linhas'][0]);
            if ($conta === null || $conta->chaveConta() !== $chave || ! $conta->liberada()) {
                continue;
            }
            try {
                $l = LeiturasDaAcao::para($conta);
                $l->preCarregar(array_map(fn (PubAlavancaEscrita $x) => (array) ($x->payload['dados'] ?? []), $grupo['linhas']));
                $leituras[$chave] = $l;
            } catch (\Throwable $e) {
                // Sem o pré-carregamento cada ação lê por item: mais lento, mas correto.
                Log::warning("[Alavancas] pré-carga do lote {$this->lote} falhou: ".$e->getMessage());
            }
        }

        return $leituras;
    }

    public function failed(\Throwable $e): void
    {
        Log::error("[Alavancas] lote {$this->lote} quebrou: ".$e->getMessage());

        $pendentes = PubAlavancaEscrita::query()->where('lote_uuid', $this->lote)->where('resultado', PubAlavancaEscrita::PENDENTE)->get();
        foreach ($pendentes as $linha) {
            if ($linha->enviado_em !== null) {
                $linha->marcar(PubAlavancaEscrita::INCERTO, [
                    'mensagem' => 'O lote foi interrompido depois de enviar; confira o estado no Mercado Livre antes de repetir.',
                ]);
            } else {
                $linha->marcar(PubAlavancaEscrita::RECUSADA, [
                    'erro_codigo' => 'ALAV-LOTE-PAROU',
                    'mensagem' => 'O lote foi interrompido antes de enviar este produto. Nada foi enviado; confirme de novo.',
                ]);
            }
        }
    }
}
