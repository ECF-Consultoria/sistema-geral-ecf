<?php

namespace App\Services\Nps;

use App\Models\NpsCiclo;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Régua de LEITURA da janela M+1 do bônus — Fase 118 (118-CONTEXT.md, C-01).
 *
 * Extraída de `DesempenhoScoreService::computeNpsWindow()` (linha 748-772),
 * que decide se o componente de NPS do bônus é `null` (excluído, M+1 ainda em
 * coleta) ou penalizado (`0.0`, M+1 já encerrada). É a régua consultada por
 * QUEM PRECISA LER "a janela M+1 já fechou?" sem herdar as 5 outras
 * dependências do construtor de `DesempenhoScoreService`.
 *
 * ─── Regras travadas (118-CONTEXT.md) ────────────────────────────────────
 *  - Esta classe usa `gte` (`fechada()` abaixo) — é a régua IRMÃ, mas
 *    DIFERENTE, da régua de MATERIALIZAÇÃO em
 *    `NpsImputationService::materializar()` (linha 127), que usa `gt`. A
 *    divergência é DELIBERADA e documentada nos dois lugares: o link do NPS
 *    vale até 23:59:59 do último dia do mês (`expires_at = endOfMonth`);
 *    usar `gte` na materialização marcaria definitivo às 00:00 do último dia
 *    enquanto o cliente ainda tem 24h de prazo para responder. Usar `gt` na
 *    LEITURA do bônus reintroduziria o bug oposto (Blocker 1, ver abaixo).
 *    **Quem "unificar" as duas réguas reintroduz um bug conhecido** — a C-01
 *    do `118-CONTEXT.md` proíbe explicitamente essa unificação.
 *  - A comparação é sempre por DATA, nunca por timestamp cru (BLOCKER 1,
 *    docblock de `computeNpsWindow()` linhas 735-743): o cron
 *    `desempenho:consolidar-mes` congela no ÚLTIMO DIA de M+1 às 14h —
 *    comparar `endOfMonth($mesNps) < now()` seria SEMPRE FALSO nesse
 *    instante (23:59:59 < 14:00 é falso) e inverteria a regra. Comparar por
 *    `startOfDay()->gte(startOfDay())` é imune a esse boundary.
 *  - Nesta fase (118), `DesempenhoScoreService` NÃO foi refatorado para
 *    chamar esta classe — de propósito. A fase é aditiva e não pode tocar
 *    naquele arquivo (gate de hash). A duplicação da expressão de data em
 *    dois lugares fica vigiada por
 *    `tests/Feature/Phase118/NpsJanelaResolverTest::test_resolver_concorda_com_computeNpsWindow_nos_tres_casos`,
 *    que invoca `computeNpsWindow()` por reflection e compara com esta
 *    classe nos 3 casos de boundary — se um dos dois mudar sem o outro, o
 *    teste fica vermelho.
 *    **Follow-up registrado (não é desta fase):** quando a Fase 119/120
 *    tocar `DesempenhoScoreService` por outro motivo legítimo,
 *    `computeNpsWindow()` deve passar a delegar para esta classe (Opção B
 *    completa do `118-PATTERNS.md` §4), eliminando a duplicação.
 *
 * @see .planning/phases/118-nps-por-empresa-v21-0/118-CONTEXT.md
 * @see app/Services/DesempenhoScoreService.php:748-772 (computeNpsWindow — régua irmã de LEITURA, não refatorada)
 * @see app/Services/Nps/NpsImputationService.php:119-127 (régua irmã de MATERIALIZAÇÃO, gt)
 */
final class NpsJanelaResolver
{
    /**
     * Meses de coleta fechados MANUALMENTE, chaveados por 'YYYY-MM-01'.
     * `null` = ainda não consultado neste request (ver `fechadaManualmente()`).
     *
     * @var Collection<string, int>|null
     */
    private ?Collection $mesesFechados = null;

    /**
     * Desloca a competência FINANCEIRA M para o mês de COLETA do NPS (M+1).
     * `addMonthNoOverflow` evita o edge do dia 31 (espelha
     * `computeNpsWindow():760` — `$mes->copy()->addMonthNoOverflow()`).
     */
    public function mesDeColeta(Carbon $mes): Carbon
    {
        return $mes->copy()->addMonthNoOverflow();
    }

    /**
     * A janela de coleta do mês de NPS (`$mesNps`, já deslocado por
     * `mesDeColeta()`) já fechou?
     *
     * Duas formas de fechar, nesta ordem:
     *
     *  1. FECHAMENTO MANUAL (spec 2026-08-14, item 2) — alguém autorizado
     *     encerrou o ciclo pela tela. PREVALECE mesmo que a data automática
     *     ainda não tenha chegado, que é exatamente o que a spec pede.
     *  2. Data — cópia caractere a caractere da expressão de
     *     `DesempenhoScoreService::computeNpsWindow():769`: comparação por DATA
     *     (`startOfDay()`), nunca por timestamp cru (Blocker 1, ver docblock da
     *     classe).
     *
     * O manual só ADIANTA o fechamento; nunca reabre um mês que a data já
     * fechou (é um OR). Reabrir por engano faria survey antigo voltar a aceitar
     * resposta e reabriria competência de bônus já paga.
     */
    public function fechada(Carbon $mesNps): bool
    {
        if ($this->fechadaManualmente($mesNps)) {
            return true;
        }

        return now()->startOfDay()->gte($mesNps->copy()->endOfMonth()->startOfDay());
    }

    /**
     * O ciclo foi encerrado À MÃO? Consulta `nps_ciclos` — a fonte única do
     * estado (spec 2026-08-14, item 2).
     *
     * Memoizado POR REQUEST: `fechada()` é chamado num laço de 12 meses na
     * série do `/nps` e por empresa no bônus; sem isto seriam dezenas de
     * queries idênticas. O cache é de instância — o resolver é resolvido pelo
     * container e vive o request, então um fechamento feito NESTE request é
     * visto por quem pedir depois (o cache é preenchido de uma vez, com todos
     * os meses fechados, e `esquecerCache()` existe para os testes e para o
     * próprio ato de fechar).
     */
    public function fechadaManualmente(Carbon $mesNps): bool
    {
        if ($this->mesesFechados === null) {
            $this->mesesFechados = NpsCiclo::query()
                ->pluck('mes_coleta')
                ->map(fn ($data) => Carbon::parse($data)->startOfMonth()->toDateString())
                ->flip();
        }

        return $this->mesesFechados->has($mesNps->copy()->startOfMonth()->toDateString());
    }

    /**
     * Descarta a memoização — chamar depois de fechar um ciclo dentro do mesmo
     * request (e nos testes, que fecham e releem em sequência).
     */
    public function esquecerCache(): void
    {
        $this->mesesFechados = null;
    }
}
