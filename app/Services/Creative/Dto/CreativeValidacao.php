<?php

namespace App\Services\Creative\Dto;

use App\Models\MlAnuncioCriativo;

/**
 * Veredito RECONCILIADO do juiz (Fase 162, D-06) — já passou pela
 * reconciliação de `CreativeJuiz::julgar()` ("nada é aceito do modelo");
 * nunca é construído direto do JSON cru do provedor.
 *
 * `status` é sempre uma das 4 constantes `MlAnuncioCriativo::VALIDACAO_*`.
 * `mensagem` é a frase pt-BR já pronta para a tela (APROV-04) — montada no
 * SERVIDOR a partir do status + motivo curto, nunca string crua do modelo.
 */
final readonly class CreativeValidacao
{
    /**
     * @param  array<int, array{tipo: string, gravidade: string, explicacao: string}>  $problemas
     */
    public function __construct(
        public string $status,
        public ?string $fidelidade,
        public ?string $motivoCurto,
        public array $problemas,
        public ?string $modelo,
        public ?int $latenciaMs,
        public string $mensagem,
    ) {}

    public function reprovada(): bool
    {
        return $this->status === MlAnuncioCriativo::VALIDACAO_REPROVADA;
    }

    /** O que vai para a coluna `ml_anuncio_criativos.validacao` (VAL-03). */
    public function paraColuna(): array
    {
        return [
            'status'       => $this->status,
            'fidelidade'   => $this->fidelidade,
            'motivo_curto' => $this->motivoCurto,
            'problemas'    => $this->problemas,
            'modelo'       => $this->modelo,
            'latencia_ms'  => $this->latenciaMs,
            'mensagem'     => $this->mensagem,
        ];
    }
}
