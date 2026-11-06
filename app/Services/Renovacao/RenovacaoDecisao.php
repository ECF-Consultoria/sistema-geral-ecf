<?php

namespace App\Services\Renovacao;

/**
 * Fase 202 — resultado da política de decisão (matriz §4.3.4 do plano-fonte).
 */
final readonly class RenovacaoDecisao
{
    public const NAO_ELEGIVEL            = 'nao_elegivel';
    public const NADA_A_FAZER            = 'nada_a_fazer';
    public const CONCILIAR_ABERTO        = 'conciliar_aberto';
    public const REGISTRAR_GANHO         = 'registrar_ganho';
    public const REGISTRAR_PERDA         = 'registrar_perda';
    public const VINCULAR_POR_CORRELACAO = 'vincular_por_correlacao';
    public const CRIAR                   = 'criar';
    public const AGUARDAR_CONCILIACAO    = 'aguardar_conciliacao';
    public const REVISAO_MANUAL          = 'revisao_manual';
    public const ERRO_LEITURA            = 'erro_leitura';

    public function __construct(
        public string $acao,
        public ?string $motivo,
        public ?string $negocioId,
        public string $explicacao,
    ) {}
}
