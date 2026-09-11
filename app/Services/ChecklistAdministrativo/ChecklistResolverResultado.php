<?php

namespace App\Services\ChecklistAdministrativo;

/**
 * Resultado de 3 estados de um {@see \App\Contracts\ChecklistResolver}
 * (D-10) — nunca um booleano.
 *
 * Molde copiado quase literalmente de
 * {@see \App\Services\Onboarding\OnboardingResolverResultado}, sem os ramos
 * de coleta assíncrona: os 4 resolvers desta fase são síncronos (leitura de
 * coluna local, sem rede), então não existe aqui a chave reservada de
 * "coleta em andamento" nem o predicado correspondente.
 *
 * `INDETERMINADO` permanece no catálogo mesmo sem nenhum resolver desta fase
 * o produzir hoje — é a disciplina do D-10: "nunca concluir a partir de um
 * estado indeterminado" precisa existir como estado nomeável antes de
 * alguém precisar dele, não ser inventado depois sob pressão.
 */
final readonly class ChecklistResolverResultado
{
    // ─── Catálogo fechado de estados (D-10 — três, nunca um bool) ───────────
    public const CONCLUIDO = 'concluido';
    public const NAO_COLETADO = 'nao_coletado';
    public const INDETERMINADO = 'indeterminado';

    private function __construct(
        public string $estado,
        public array $valor = [],
        public ?string $motivo = null,
    ) {
    }

    public static function concluido(array $valor = []): self
    {
        return new self(self::CONCLUIDO, $valor);
    }

    public static function naoColetado(?string $motivo = null, array $valor = []): self
    {
        return new self(self::NAO_COLETADO, $valor, $motivo);
    }

    /** Estado indeterminado (429/timeout/rede) — nunca conclua a partir dele. */
    public static function indeterminado(string $motivo): self
    {
        return new self(self::INDETERMINADO, [], $motivo);
    }

    public function ehConcluido(): bool
    {
        return $this->estado === self::CONCLUIDO;
    }

    public function ehNaoColetado(): bool
    {
        return $this->estado === self::NAO_COLETADO;
    }

    public function ehIndeterminado(): bool
    {
        return $this->estado === self::INDETERMINADO;
    }
}
