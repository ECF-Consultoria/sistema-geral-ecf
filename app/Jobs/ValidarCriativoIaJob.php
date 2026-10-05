<?php

namespace App\Jobs;

use App\Models\MlAnuncioCriativo;
use App\Services\Creative\CreativeJuiz;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Valida UMA imagem já gerada contra as fotos originais e o Product Truth
 * gravado (Fase 162, Plano 02, VAL-01) — juiz de visão (`CreativeJuiz`)
 * despachado no FIM da geração, nunca dentro do `handle()` do
 * `GerarCriativoIaJob`.
 *
 * Fila `creative` (mesmo worker dedicado do job de geração) — ⚠️ fila
 * definida no CONSTRUTOR com `onQueue()`; NUNCA redeclarar `$queue`
 * (`Queueable` já declara a propriedade e redeclarar é erro fatal de PHP).
 *
 * CHAVE PRÓPRIA DE UNICIDADE (`validacao:{id}`, diferente de `criativo:{id}`
 * do job de geração) — Decisão 1 do 162-02-PLAN.md: a regeneração automática
 * do Plano 03 precisa despachar `GerarCriativoIaJob` para o MESMO criativo, e
 * se esse despacho saísse de dentro do `handle()` de um job com a MESMA
 * chave de unicidade, o Laravel descartaria o despacho em SILÊNCIO — a
 * mesma armadilha que a 161-02 pagou para descobrir.
 */
class ValidarCriativoIaJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * 2 tentativas — o juiz medido responde em 2,7s–4,9s; se falhar, uma
     * segunda tentativa cobre instabilidade passageira do provedor sem
     * esticar demais o "validando…" da tela.
     */
    public int $tries = 2;

    public array $backoff = [15];

    /** 120s cobre com folga o juiz medido (2,7s–4,9s), mesmo com sobrecarga. */
    public int $timeout = 120;

    /** Se o worker matar o processo, é falha definitiva — vira `indisponivel`. */
    public bool $failOnTimeout = true;

    public function __construct(public int $criativoId)
    {
        // Fila `creative` — definida no construtor porque `Queueable` já
        // declara `$queue` e redeclarar a propriedade é erro fatal de PHP.
        $this->onQueue('creative');
    }

    /**
     * Chave PRÓPRIA de unicidade — `validacao:{id}`, nunca `criativo:{id}`
     * (a do `GerarCriativoIaJob`). Ver docblock da classe.
     */
    public function uniqueId(): string
    {
        return 'validacao:'.$this->criativoId;
    }

    /** TTL do lock — folga sobre timeout(120s) + backoff. */
    public function uniqueFor(): int
    {
        return 300;
    }

    public function handle(CreativeJuiz $juiz): void
    {
        $criativo = MlAnuncioCriativo::find($this->criativoId);

        if (! $criativo) {
            Log::warning("[Creative] Criativo {$this->criativoId} sumiu antes da validação.");

            return;
        }

        // Já subiu ao Mercado Livre — não gastar chamada no que já foi aprovado.
        if ($criativo->status === MlAnuncioCriativo::STATUS_APROVADO) {
            return;
        }

        // Já foi julgado (ou a validação foi desligada entre o despacho e a
        // execução) — não há o que fazer.
        if ($criativo->validacao_status !== MlAnuncioCriativo::VALIDACAO_PENDENTE) {
            return;
        }

        // OPS-03 da validação: chave desligada → `indisponivel` sem chamar o
        // provedor. Rede de segurança para o caso de ter sido desligada
        // ENTRE o despacho (feito com a chave ligada) e esta execução.
        if (! (bool) config('services.creative.validacao.ativa', true)) {
            $criativo->update([
                'validacao_status' => MlAnuncioCriativo::VALIDACAO_INDISPONIVEL,
                'validacao_em'      => now(),
                'validacao'         => [
                    'status'   => MlAnuncioCriativo::VALIDACAO_INDISPONIVEL,
                    'mensagem' => 'A validação automática está desligada.',
                ],
            ]);

            return;
        }

        // VAL-06: pendente travado há mais de LIMITE_VALIDACAO_MINUTOS nunca
        // vai terminar — encerra ANTES de qualquer gasto com o provedor.
        $criativo->encerrarValidacaoSeTravada();
        $criativo->refresh();
        if ($criativo->validacao_status !== MlAnuncioCriativo::VALIDACAO_PENDENTE) {
            return;
        }

        // Decisão 3 (162-02-PLAN.md): "fail-open, nunca fail-closed" —
        // provedor fora do ar, timeout, resposta vazia em todos os modelos
        // (RuntimeException/FalhaDeGeracaoTrocavel do `ImageJudgementProvider`)
        // é ISTO, capturado AQUI, não uma falha de job a ser retentada: a
        // aprovação tem que continuar LIBERADA (indisponível), nunca travada
        // esperando `$tries`/`failed()`. `$tries`/`failed()` seguem existindo
        // para o que pode matar o processo ANTES de chegar aqui (timeout do
        // worker) ou falha de infraestrutura do próprio Laravel.
        try {
            $validacao = $juiz->julgarEGravar($criativo);
        } catch (\Throwable $e) {
            Log::error("[Creative] Validação do criativo {$criativo->id} falhou (provedor): {$e->getMessage()}");

            $criativo->update([
                'validacao_status' => MlAnuncioCriativo::VALIDACAO_INDISPONIVEL,
                'validacao_em'      => now(),
                'validacao'         => [
                    'status'   => MlAnuncioCriativo::VALIDACAO_INDISPONIVEL,
                    'mensagem' => 'Não foi possível validar automaticamente esta imagem — confira você mesmo antes de aprovar.',
                ],
            ]);

            $criativo->kit?->recalcularStatus();

            return;
        }

        $criativo->kit?->recalcularStatus();

        // Nunca o texto do veredito (já está na coluna), nunca prompt, nunca
        // base64, nunca a chave.
        Log::info('[Creative] Validação concluída', [
            'criativo_id'   => $criativo->id,
            'kit_id'        => $criativo->kit_id,
            'slot'          => $criativo->slot,
            'status'        => $validacao->status,
            'modelo'        => $validacao->modelo,
            'latencia_ms'   => $validacao->latenciaMs,
            'qtd_problemas' => count($validacao->problemas),
        ]);
    }

    /**
     * Só marca `indisponivel` quando o Laravel desiste de vez — NUNCA deixa
     * em `pendente` (bloquearia a aprovação até a trava de tempo de 10 min).
     */
    public function failed(\Throwable $e): void
    {
        $criativo = MlAnuncioCriativo::find($this->criativoId);

        $criativo?->update([
            'validacao_status' => MlAnuncioCriativo::VALIDACAO_INDISPONIVEL,
            'validacao_em'      => now(),
            'validacao'         => [
                'status'   => MlAnuncioCriativo::VALIDACAO_INDISPONIVEL,
                'mensagem' => 'Não foi possível validar automaticamente esta imagem — confira você mesmo antes de aprovar.',
            ],
        ]);

        $criativo?->kit?->recalcularStatus();

        Log::error("[Creative] Validação do criativo {$this->criativoId} falhou em definitivo: {$e->getMessage()}");
    }
}
