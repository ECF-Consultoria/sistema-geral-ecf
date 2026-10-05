<?php

namespace App\Jobs;

use App\Models\MlAnuncioCriativo;
use App\Services\Creative\CreativeJuiz;
use App\Services\Creative\Dto\CreativeValidacao;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
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

        // VAL-05/VAL-06 (162-03): reprovada pode regenerar sozinha, UMA vez,
        // dentro do MESMO orçamento da regeneração manual — ver
        // talvezRegenerar(). Quando regenera, o próprio método reabre o
        // slot e chama de novo o recalcularStatus() do kit.
        $this->talvezRegenerar($criativo, $validacao);

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
     * VAL-05 + VAL-06: regenera automaticamente a imagem REPROVADA pelo
     * juiz, UMA vez por asset, dentro do MESMO orçamento da regeneração
     * manual — `MlAnuncioCriativoKit::podeRegenerarAsset()`, nenhum teto
     * paralelo. Qualquer condição falsa abaixo encerra sem gastar nada.
     * Molde literal da transação de `MlbAnuncioController::criativoRegenerar()`.
     *
     * Retorna `true` quando de fato regenerou (para quem chamar decidir se
     * precisa recalcular algo de novo).
     */
    private function talvezRegenerar(MlAnuncioCriativo $criativo, CreativeValidacao $validacao): bool
    {
        // `indisponivel` NUNCA regenera — gastar imagem por falha NOSSA de
        // validação (provedor fora do ar, JSON inválido) seria queimar
        // dinheiro sem evidência de defeito na imagem.
        if (! $validacao->reprovada()) {
            return false;
        }

        // OPS: chave desligável sem deploy — rede de segurança igual à de
        // `services.creative.validacao.ativa`.
        if (! (bool) config('services.creative.validacao.regenerar_automatico', true)) {
            return false;
        }

        // VAL-06: a trava anti-loop — uma vez por asset, para sempre. Mesmo
        // que a 2ª imagem também seja reprovada, não há 3ª tentativa
        // automática.
        if ($criativo->regeneracao_automatica === true) {
            return false;
        }

        $kit = $criativo->kit;

        // Regenerar é recurso do KIT (mesma regra de criativoRegenerar()) —
        // o fluxo de 1 imagem da Fase 160 (sem kit) não regenera.
        if ($kit === null || $criativo->slot_indice === null) {
            return false;
        }

        // O MESMO orçamento da regeneração manual — teto de imagens do
        // kit, teto de regenerações do kit e teto por asset. Nada de
        // limite paralelo.
        if (! $kit->podeRegenerarAsset($criativo)) {
            return false;
        }

        // Texto montado no SERVIDOR a partir do motivo (já sanitizado e
        // cortado em 200 pelo CreativeJuiz) — cortado de novo em 300
        // caracteres porque é ele que o GerarCriativoIaJob vai ler como
        // $ajusteOperador (caminho JÁ EXISTENTE de paraSlot(), trava de
        // coordenação com a Fase 165 — zero linha no CreativePromptBuilder).
        // O texto NUNCA afirma contagem — só manda seguir as fotos de
        // referência (TRUTH-02/03): a autoridade sobre o produto é a foto
        // original, nunca uma frase gerada por outro modelo.
        $motivoCurto = $validacao->motivoCurto ?? 'a validação identificou um risco de divergência com o produto';
        $texto = mb_substr(
            "A validação automática reprovou a imagem anterior: {$motivoCurto}. Refaça seguindo exatamente as fotos de referência.",
            0,
            300,
        );

        DB::transaction(function () use ($criativo, $kit, $validacao, $texto) {
            // Aditivo, nunca sobrescrita — mesma disciplina de
            // `criativoRegenerar()`. `origem` é chave NOVA: entradas antigas
            // (sem a chave) continuam lidas como manuais por ausência, nada
            // a migrar. `user_id` NULL é o que distingue a automática da
            // manual no histórico (nenhuma entrada manual tem `user_id` nulo).
            $motivos = $criativo->regenerar_motivos ?? [];
            $motivos[] = [
                'em'        => now()->toDateTimeString(),
                'user_id'   => null,
                'origem'    => 'automatica',
                'texto'     => $texto,
                'validacao' => [
                    'status' => MlAnuncioCriativo::VALIDACAO_REPROVADA,
                    'motivo' => $validacao->motivoCurto,
                ],
            ];

            $criativo->update([
                'status'                 => MlAnuncioCriativo::STATUS_PENDENTE,
                'etapa'                  => null,
                'erro_mensagem'          => null,
                'regeneracao_automatica' => true,
                'regenerar_motivos'      => $motivos,
            ]);
            $criativo->increment('regeneracoes');
            $kit->increment('regeneracoes');
            $kit->increment('regeneracoes_automaticas');
        });

        // Job SEPARADO (GerarCriativoIaJob) — seguro despachar AQUI porque o
        // lock `ShouldBeUnique` de `criativo:{id}` pertence a ELE, e já
        // terminou (quem está rodando agora é este job de VALIDAÇÃO, chave
        // PRÓPRIA `validacao:{id}`). Mover esta linha para dentro do
        // `handle()` do job de geração reintroduziria a perda silenciosa da
        // Decisão 1 do 162-02-PLAN.md.
        GerarCriativoIaJob::dispatch($criativo->id);

        $kit->recalcularStatus();

        // Nunca o texto do motivo — só o tamanho em caracteres, mesma
        // disciplina do `ajuste_chars` do job de geração.
        Log::info('[Creative] Regeneração automática enfileirada', [
            'criativo_id'  => $criativo->id,
            'kit_id'       => $kit->id,
            'slot_indice'  => $criativo->slot_indice,
            'regeneracoes' => $criativo->regeneracoes,
            'motivo_chars' => mb_strlen($texto),
        ]);

        return true;
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
