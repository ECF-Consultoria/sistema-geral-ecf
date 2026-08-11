<?php

namespace App\Services\Onboarding;

/**
 * Resultado de 3 estados de um {@see \App\Contracts\OnboardingResolver}
 * (D-11, Fase 135) — nunca um booleano.
 *
 * Distingue "concluído" de "ainda não coletado" de "indeterminado"
 * (429/timeout/rede) — a mesma distinção que já custou caro no Shopee (conta
 * nova fica vazia até o backfill, e "vazio" foi lido como "zero"). Um
 * resolver que devolva `bool` está errado por construção.
 *
 * **Chave reservada `coleta_em_andamento`** — contrato de toda a Fase 135
 * (usado pelos Planos 04/06/07). {@see self::sinalizouColetaEmAndamento()}
 * só é verdadeiro quando o estado é {@see self::NAO_COLETADO} **e**
 * `valor['coleta_em_andamento'] === true`. Só pode setar essa chave o
 * resolver que efetivamente despachou um job de coleta — ou que constatou
 * que uma coleta despachada por ele antes ainda está em curso (na v1,
 * exclusividade do `AcervoColetadoResolver` do Plano 07). É canal de
 * controle, não dado de negócio: `OnboardingEngineService::aplicarResultado()`
 * (Plano 04) não recebe o resolver, só o passo e este resultado — a chave é
 * o único canal desse sinal. A chave NÃO é persistida em
 * `onboarding_passos.valor`.
 */
final readonly class OnboardingResolverResultado
{
    // ─── Catálogo fechado de estados (D-11 — três, nunca um bool) ───────────
    public const CONCLUIDO = 'concluido';
    public const NAO_COLETADO = 'nao_coletado';
    public const INDETERMINADO = 'indeterminado';

    /** Chave reservada — ver docblock da classe. */
    public const CHAVE_COLETA_EM_ANDAMENTO = 'coleta_em_andamento';

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

    /** 429/timeout/rede — nunca conclua a partir de um estado indeterminado. */
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

    /**
     * Verdadeiro somente quando o estado é NAO_COLETADO e o resolver marcou
     * explicitamente que despachou (ou constatou em curso) uma coleta
     * assíncrona — ver docblock da classe.
     */
    public function sinalizouColetaEmAndamento(): bool
    {
        return $this->ehNaoColetado()
            && ($this->valor[self::CHAVE_COLETA_EM_ANDAMENTO] ?? false) === true;
    }
}
