<?php

namespace App\Notifications;

use Carbon\Carbon;

/**
 * Fase 130 Plano 06 (REDE-04, D-09) — a rede de segurança avisando que ela
 * mesma parou de rodar.
 *
 * Subclasse concreta de `BaseNotification`, mesmo molde de
 * `ContratoPresoNotification` (Fase 130 Plano 05) — NÃO sobrescreve `via()`
 * nem `toArray()`: canal único `database` (o sino) e payload canônico de 6
 * chaves vêm 100% da base.
 *
 * É uma notificação SEPARADA de `ContratoPresoNotification` porque aquela
 * exige um `ContratoAssinatura` no construtor — e no cenário desta
 * notificação ("a varredura não rodou") pode não existir NENHUM carimbo,
 * logo nenhum contrato específico para apontar. Esta notificação fala sobre
 * o COMANDO `clicksign:reconciliar`, não sobre uma empresa.
 *
 * `meta` carrega só `executado_em`/`erro`/`fonte` — nunca e-mail, CPF ou
 * qualquer dado de signatário (o `erro`, quando presente, já vem podado de
 * PII e truncado em 500 chars por `ClicksignReconciliar::podarPii()`, Fase
 * 130 Plano 03 — esta notificação apenas repassa o texto, sem acrescentar
 * dado novo).
 */
class VarreduraParadaNotification extends BaseNotification
{
    public function __construct(?string $executadoEm, ?string $erro)
    {
        parent::__construct(
            titulo:      'A verificação automática de contratos não rodou',
            mensagem:    self::montarMensagem($executadoEm, $erro),
            categoria:   Categoria::MANUAL,
            autorUserId: null, // Sistema — sem autor humano
            url:         null, // Ação é de infraestrutura, não de tela
            meta:        [
                'executado_em' => $executadoEm,
                'erro'         => $erro,
                'fonte'        => 'rede_seguranca',
            ],
        );
    }

    /**
     * Linguagem simples, cobrindo os três casos e sempre dizendo o que
     * fazer a seguir. "cron"/"scheduler"/"job" só apareceriam se
     * explicados na própria frase — e nenhum deles é necessário aqui.
     */
    private static function montarMensagem(?string $executadoEm, ?string $erro): string
    {
        if ($executadoEm === null) {
            return 'A verificação automática que corrige contratos travados nunca rodou neste sistema. '
                . 'Avise o time técnico para conferir se ela foi configurada corretamente.';
        }

        $dataFormatada = Carbon::parse($executadoEm)->timezone('America/Sao_Paulo')->format('d/m/Y H:i');

        if (filled($erro)) {
            return "A verificação automática rodou pela última vez em {$dataFormatada}, mas terminou com um erro. "
                . 'Avise o time técnico para conferir o que aconteceu.';
        }

        return "A verificação automática que corrige contratos travados não roda desde {$dataFormatada}. "
            . 'Avise o time técnico para conferir se ela ainda está configurada para rodar todo dia.';
    }
}
