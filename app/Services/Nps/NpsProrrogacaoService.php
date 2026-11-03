<?php

namespace App\Services\Nps;

use App\Models\NpsGroupSurvey;
use App\Models\NpsSurvey;
use Carbon\Carbon;

/**
 * Prorrogação de um ciclo de NPS (TKT-0029 → 03/11/2026).
 *
 * A prorrogação não tem tabela própria: ela É o que o comando
 * `nps:prorrogar-validade` grava — o `expires_at` dos links do mês de coleta
 * empurrado para depois do fim do mês. Sem prorrogação, todo link nasce
 * valendo até o fim do mês em que foi gerado, então "algum link do mês vale
 * além do fim do mês" só acontece quando alguém prorrogou.
 *
 * Por que importa: com o ciclo prorrogado, a tela precisa conseguir gerar link
 * NOVO daquele mês (empresa que ficou sem link), valendo até a mesma data. Sem
 * isso, o link gerado cai no mês corrente — outro ciclo.
 *
 * O que a prorrogação NÃO muda (limite aceito na opção A do TKT-0029, ver
 * `.planning/learnings/nps-visibilidade-e-elegibilidade.md` §8): a nota 1 do
 * não respondido do mês já é definitiva, e o bônus lê a resposta pelo
 * `completed_at` — resposta em novembro entra na coleta de novembro.
 */
final class NpsProrrogacaoService
{
    public function __construct(private NpsJanelaResolver $janela)
    {
    }

    /**
     * Até quando o ciclo do mês de COLETA aceita link novo e resposta por
     * prorrogação. `null` = não prorrogado, prorrogação já vencida, ou ciclo
     * encerrado à mão (o fechamento manual sempre vence — mesmo comportamento
     * de `NpsController::cicloFechado()` no envio).
     */
    public function vigenteAte(Carbon $mesColeta): ?Carbon
    {
        $ate = $this->prorrogadoAte($mesColeta);

        if ($ate === null || now()->gt($ate) || $this->janela->fechadaManualmente($mesColeta)) {
            return null;
        }

        return $ate;
    }

    /**
     * Maior validade entre os links (individuais e de grupo) do mês de coleta,
     * quando passa do fim do mês. Competência do link individual pela mesma
     * régua da tela e do comando: `month_reference`, com fallback `created_at`.
     */
    public function prorrogadoAte(Carbon $mesColeta): ?Carbon
    {
        $inicio = $mesColeta->copy()->startOfMonth();
        $fim    = $inicio->copy()->endOfMonth();

        $individual = NpsSurvey::query()
            ->where(function ($q) use ($inicio, $fim) {
                $q->whereBetween('month_reference', [$inicio->toDateString(), $fim->toDateString()])
                    ->orWhere(function ($qq) use ($inicio, $fim) {
                        $qq->whereNull('month_reference')
                            ->whereBetween('created_at', [$inicio, $fim]);
                    });
            })
            ->max('expires_at');

        $grupo = NpsGroupSurvey::query()
            ->whereBetween('month_reference', [$inicio->toDateString(), $fim->toDateString()])
            ->max('expires_at');

        $ate = collect([$individual, $grupo])
            ->filter()
            ->map(fn ($valor) => Carbon::parse($valor))
            ->max();

        return ($ate && $ate->gt($fim)) ? $ate : null;
    }

    /**
     * Para qual ciclo vai um link gerado agora, olhando o mês que a tela exibe.
     *
     * Mês exibido prorrogado e ainda vigente → o link nasce NAQUELE mês, valendo
     * até a prorrogação. Qualquer outro caso → mês corrente, valendo até o fim
     * do mês (comportamento de sempre).
     *
     * @return array{mes: Carbon, vale_ate: Carbon, prorrogado: bool}
     */
    public function cicloDoLinkNovo(?Carbon $mesExibido = null): array
    {
        $atual = now()->startOfMonth();

        if ($mesExibido !== null && ! $mesExibido->copy()->startOfMonth()->equalTo($atual)) {
            $ate = $this->vigenteAte($mesExibido);
            if ($ate !== null) {
                return ['mes' => $mesExibido->copy()->startOfMonth(), 'vale_ate' => $ate, 'prorrogado' => true];
            }
        }

        return ['mes' => $atual, 'vale_ate' => $atual->copy()->endOfMonth(), 'prorrogado' => false];
    }

    /**
     * Etiqueta do ciclo como a tela mostra: "nov/26 · ref. out/26" para o mês
     * de coleta nov/26. O primeiro mês é o do LINK (coleta); o "ref." é a
     * competência que a nota avalia, sempre o mês anterior.
     */
    public function rotulo(Carbon $mesColeta): string
    {
        $mes = $mesColeta->copy()->startOfMonth()->locale('pt_BR');

        return $mes->isoFormat('MMM/YY').' · ref. '.$mes->copy()->subMonthNoOverflow()->isoFormat('MMM/YY');
    }

    /**
     * Mensagem de sucesso da geração de link — diz em que ciclo o link nasceu
     * e até quando vale. 03/11/2026: 42 links nasceram em out/26 pelo conector
     * (MCP), que escolhe o `mes` sozinho, e ninguém percebeu porque a resposta
     * dizia só "gerado com sucesso". Com o ciclo na mensagem, quem gera — pessoa
     * ou Claude — vê na hora se caiu no mês errado.
     *
     * @param  array{mes: Carbon, vale_ate: Carbon, prorrogado: bool}  $ciclo
     */
    public function mensagemDeGerado(string $oQue, array $ciclo): string
    {
        return sprintf(
            '%s gerado no ciclo %s, válido até %s.',
            $oQue,
            $this->rotulo($ciclo['mes']),
            $ciclo['vale_ate']->format('d/m/Y'),
        );
    }
}
