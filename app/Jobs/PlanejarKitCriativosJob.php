<?php

namespace App\Jobs;

use App\Models\MlAnuncioCriativo;
use App\Models\MlAnuncioCriativoKit;
use App\Services\Creative\CreativeCategoriaMobiliarioService;
use App\Services\Creative\CreativeContextBuilder;
use App\Services\Creative\CreativePlanner;
use App\Services\Creative\ProductTruthBuilder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Planeja o kit de 7 (contexto → Product Truth → `CreativePlanner` → grava)
 * — Fase 161, PLAN-01/02/03/04. NENHUMA imagem é gerada aqui: é uma chamada
 * de TEXTO (mais barata e mais rápida de errar que a de imagem, objetivo do
 * 161-02).
 *
 * Fila `creative` (worker dedicado já provisionado na VPS), NUNCA `high`
 * (que é a fila da GERAÇÃO de imagem, Fase 160) nem `default` (represada —
 * ver `GerarCriativoIaJob`). `ShouldBeUnique` por KIT (não por rascunho,
 * diferente de `GerarCriativoIaJob`): a unicidade por rascunho já é
 * garantida pelo lock do controller ANTES de o kit existir; depois que o
 * kit existe, a chave natural de "não planejar o mesmo kit duas vezes" é o
 * próprio `kit_id`.
 */
class PlanejarKitCriativosJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * 2 tentativas — a 2ª retoma do zero (o planejamento não salva etapa
     * parcial útil para retomar: é uma única chamada de texto). Mais
     * tentativas só esticam o "planejando…" na tela.
     */
    public int $tries = 2;

    public array $backoff = [15];

    /**
     * O planner é TEXTO: a medição do spike foi 1,3s no modelo reserva e
     * 503 no principal — o teto existe para o caso ruim, não para o normal.
     */
    public int $timeout = 180;

    public bool $failOnTimeout = true;

    /**
     * Fase 175 (§5): os tipos de slot FIXADOS por quem despacha, na ordem.
     * `null` (o default, e o que TODO despacho existente continua usando) =
     * a quantidade sai de `config('services.creative.kit.slots')` e os tipos
     * saem do catálogo/LLM, exatamente como sempre — nenhum kit existente
     * muda de comportamento, e não há migration nem mudança de default.
     *
     * Hoje só a CAPA DE KIT usa isto, com `['lifestyle', 'hero']`: a decisão
     * do usuário em 2026-10-08 é "gerar as duas e você escolhe", e esses dois
     * precisam ser EXATAMENTE esses — não os dois que o planner escolheria
     * sozinho a partir do Truth (que, com medida no cadastro, seriam
     * `hero` + `dimensions`).
     *
     * @var list<string>|null
     */
    public function __construct(public int $criativoReferenciaId, public int $kitId, public ?array $tiposFixos = null)
    {
        // Fila `creative`, NUNCA `high` nem `default`. Definido no construtor
        // porque `Queueable` já declara `$queue` e redeclarar a propriedade
        // é erro fatal de PHP.
        $this->onQueue('creative');
    }

    /**
     * Chave do KIT — unicidade natural depois que ele já existe.
     *
     * ⚠️ Fase 175: `tiposFixos` NÃO entra aqui de propósito. A unicidade é por
     * kit, e um kit tem um plano só; dois despachos do mesmo kit com tipos
     * diferentes continuam sendo o MESMO trabalho duplicado, que é exatamente
     * o que esta chave existe para evitar.
     */
    public function uniqueId(): string
    {
        return 'kit-plano:' . $this->kitId;
    }

    /** TTL do lock de unicidade em segundos — folga sobre timeout(180s) + backoff. */
    public function uniqueFor(): int
    {
        return 300;
    }

    /**
     * Fase 175 (plano 175-11, §5) — a COMPOSIÇÃO de N unidades acrescentada à
     * `cena` do slot. Puro e estático de propósito: testável sem banco e sem
     * fila.
     *
     * ═══ Por que aqui e não no `CreativePromptBuilder` ══════════════════════
     *
     * O builder já imprime `'CENA: '.$slotPlano['cena']` em `paraSlot()`, e a
     * `cena` vem do PLANO. Este job é o único lugar que sabe que o kit é uma
     * CAPA DE KIT (`$tiposFixos`) e quantas unidades ele tem
     * (`$contexto->unidadesDoKit`) — então a composição entra pelo plano, sem
     * tocar o arquivo que monta o prompt de TODOS os criativos em produção.
     *
     * O canal `ajusteOperador` NÃO serviria: `linhasVariacao()` devolve vazio
     * quando `$regeneracao < 1` (e a capa nasce com 0), e o próprio bloco
     * instrui o modelo a ignorar o que vier por ali em matéria de QUANTIDADE —
     * proteção deliberada de TRUTH-02/03.
     *
     * ═══ TRUTH-02/03 ═══════════════════════════════════════════════════════
     *
     * `$unidades` é o INTEIRO do cadastro (`pub_produtos.quantidade_kit`, via
     * `CreativeContext::$unidadesDoKit`) — NUNCA texto livre, nome de produto
     * ou qualquer coisa que o operador digite. O número já está no bloco
     * "CONTAGENS CONFIRMADAS NO CADASTRO" do mesmo prompt: esta linha pede só o
     * ARRANJO, não afirma fato novo.
     *
     * Abaixo de 2 nada é emitido: "exatamente 1 unidades idênticas lado a lado"
     * é um pedido sem sentido que o modelo obedeceria com confiança, e número
     * errado no prompt é pior que número nenhum.
     *
     * A cena original (o enquadramento do slot — `lifestyle` ambientada ×
     * `hero` fundo limpo) é PRESERVADA: a composição vem depois dela, nunca em
     * lugar dela. Idempotente — chamar duas vezes não duplica a frase.
     */
    public static function cenaComComposicao(string $cenaOriginal, int $unidades): string
    {
        if ($unidades < 2) {
            return $cenaOriginal;
        }

        $marca = 'Mostre exatamente ' . $unidades . ' unidades idênticas';

        if (str_contains($cenaOriginal, $marca)) {
            return $cenaOriginal;
        }

        $frase = $marca . ' do mesmo produto, lado a lado, preservando cor, forma'
            . ' e acabamento de todas; nada além do produto na cena.';

        $base = rtrim($cenaOriginal);

        if ($base === '') {
            return $frase;
        }

        // Cena já terminada em pontuação só precisa de emenda; sem pontuação, o
        // travessão separa o enquadramento do slot do pedido de composição.
        return str_ends_with($base, '.') || str_ends_with($base, '!') || str_ends_with($base, '?')
            ? $base . ' ' . $frase
            : $base . ' — ' . $frase;
    }

    public function handle(
        CreativeContextBuilder $ctxBuilder,
        ProductTruthBuilder $truthBuilder,
        CreativePlanner $planner,
        CreativeCategoriaMobiliarioService $categoriaMobiliario,
    ): void {
        $kit      = MlAnuncioCriativoKit::find($this->kitId);
        $portador = MlAnuncioCriativo::find($this->criativoReferenciaId);

        // (1) kit ausente ou já fora de `planejando`: idempotente, não refaz.
        if (! $kit || $kit->status !== MlAnuncioCriativoKit::STATUS_PLANEJANDO) {
            return;
        }

        // (2) a tela já desistiu deste (passou do limite): não gastar cota
        // numa geração que ninguém vai ver.
        $kit->encerrarSeTravado();
        if ($kit->status === MlAnuncioCriativoKit::STATUS_ERRO) {
            return;
        }

        if (! $portador) {
            $kit->update([
                'status'        => MlAnuncioCriativoKit::STATUS_ERRO,
                'etapa'         => null,
                'erro_mensagem' => 'O criativo de referência deste kit não existe mais.',
                'finished_at'   => now(),
            ]);

            return;
        }

        $kit->update(['started_at' => $kit->started_at ?? now()]);

        // (3) contexto → truth → plano, salvos por etapa (mesma disciplina
        // de GerarCriativoIaJob) — se algo falhar, dá para ver onde parou.
        $kit->update(['etapa' => 'contexto']);
        $contexto = $ctxBuilder->paraCriativo($portador);

        $kit->update(['etapa' => 'truth']);
        $truth = $truthBuilder->paraContexto($contexto);

        $kit->update(['etapa' => 'plano']);
        // Fase 175: só a capa de kit fixa os tipos. Sem `tiposFixos` (todo
        // despacho que já existe), a fonte da quantidade continua sendo a
        // config — nenhum kit existente muda de comportamento.
        $fixos = array_values(array_filter(array_map('strval', $this->tiposFixos ?? [])));
        $quantidade = $fixos !== []
            ? count($fixos)
            : (int) config('services.creative.kit.slots', MlAnuncioCriativoKit::SLOTS_PADRAO);
        // Quick 261007-amb: categoria de móvel troca o 1º slot do kit para
        // AMBIENTAÇÃO em vez do hero de fundo branco — detecção por
        // `path_from_root` (nunca uma chamada nova à API: reaproveita o
        // cache de `MlCatalogoMetaService::categoria()`, já aquecido pelo
        // wizard). Degrada para `false` (hero) em qualquer falha.
        $categoriaMoveis = $categoriaMobiliario->ehMoveis($contexto->categoriaId);
        $plano = $planner->planejar($contexto, $truth, $quantidade, $categoriaMoveis, $fixos);

        // Fase 175 (plano 175-11, §5): a COMPOSIÇÃO de N unidades só entra na
        // CAPA DE KIT, e só com o N do cadastro. As duas condições juntas:
        //
        // - `$fixos !== []` — hoje só a capa de kit fixa os tipos. Sem isso,
        //   NADA é acrescentado: é o caminho de todo kit em produção, inclusive
        //   os kits de 7 slots, cuja `cena` sai byte a byte igual à do plano.
        // - `unidadesDoKit !== null` — o inteiro de `pub_produtos.quantidade_kit`
        //   (>= 2). Sem ele, nenhuma linha de composição é emitida (TRUTH-02/03:
        //   na dúvida, não afirmar quantidade).
        $unidadesDaComposicao = ($fixos !== [] && $contexto->unidadesDoKit !== null)
            ? (int) $contexto->unidadesDoKit
            : null;

        $t0 = microtime(true);

        // (4) grava plano + cria os N criativos, numa única transação —
        // nenhum criativo órfão se algo falhar no meio.
        DB::transaction(function () use ($kit, $portador, $plano, $unidadesDaComposicao) {
            $kit->update([
                // ⚠️ A auditoria guarda o plano COMO O PLANNER O PRODUZIU — a
                // composição da capa (175-11) é acrescentada só no
                // `slot_plano` de cada criativo, que é o que de fato alimenta
                // o prompt. Assim dá para ver os dois: o que foi planejado e o
                // que foi pedido ao modelo.
                'plano'               => $plano->paraAuditoria(),
                'plano_origem'        => $plano->origem,
                'planner_provider'    => (string) config('services.creative.provider', 'gemini'),
                'planner_modelo'      => $plano->modelo,
                'planner_latencia_ms' => $plano->latenciaMs,
                'planner_tentativas'  => $kit->planner_tentativas + 1,
                'total_slots'         => count($plano->slots),
                'minimo_aprovadas'    => (int) config('services.creative.kit.minimo_aprovadas', MlAnuncioCriativoKit::MINIMO_APROVADAS),
                'status'              => MlAnuncioCriativoKit::STATUS_PLANEJADO,
                'etapa'               => null,
                'finished_at'         => now(),
            ]);

            foreach ($plano->slots as $slotPlano) {
                $paraPrompt = $slotPlano->paraPrompt();

                // Plano 175-11: a `cena` é o canal certo — o builder já a
                // imprime como a linha CENA. Fora da capa de kit
                // (`$unidadesDaComposicao === null`), o array sai INTACTO.
                if ($unidadesDaComposicao !== null) {
                    $paraPrompt['cena'] = self::cenaComComposicao(
                        (string) ($paraPrompt['cena'] ?? ''),
                        $unidadesDaComposicao,
                    );
                }

                MlAnuncioCriativo::create([
                    'token'          => Str::random(32),
                    'company_id'     => $portador->company_id,
                    'mlb_empresa_id' => $portador->mlb_empresa_id,
                    'rascunho_id'    => $portador->rascunho_id,
                    'user_id'        => $portador->user_id,
                    'kit_id'         => $kit->id,
                    'slot'           => $slotPlano->tipo,
                    'slot_indice'    => $slotPlano->indice,
                    'slot_plano'     => $paraPrompt,
                    'status'         => MlAnuncioCriativo::STATUS_PENDENTE,
                ]);
            }

            // O portador NUNCA é reciclado como um dos N slots (Decisão 1b):
            // ganha kit_id e o slot 'referencia', slot_indice fica NULL.
            $portador->update(['kit_id' => $kit->id, 'slot' => 'referencia']);
        });

        // (5) GEN-05: sem prompt, sem chave, sem o plano inteiro — só o que
        // ajuda a medir custo e desempenho.
        Log::info("[Creative] Kit {$kit->id} planejado", [
            'slots'            => count($plano->slots),
            'origem'           => $plano->origem,
            'modelo'           => $plano->modelo,
            'latencia_ms'      => $plano->latenciaMs,
            'duracao_total_ms' => (int) round((microtime(true) - $t0) * 1000),
        ]);
    }

    /**
     * Só marca erro quando o Laravel desiste de vez. As referências NÃO são
     * apagadas: o operador vai tentar de novo.
     */
    public function failed(\Throwable $e): void
    {
        $kit = MlAnuncioCriativoKit::find($this->kitId);

        $kit?->update([
            'status'        => MlAnuncioCriativoKit::STATUS_ERRO,
            'etapa'         => null,
            'erro_mensagem' => $e->getMessage(),
            'finished_at'   => now(),
        ]);

        Log::error("[Creative] Kit {$this->kitId} falhou em definitivo ao planejar: {$e->getMessage()}");
    }
}
