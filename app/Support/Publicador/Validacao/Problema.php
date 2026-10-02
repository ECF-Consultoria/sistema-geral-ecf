<?php

namespace App\Support\Publicador\Validacao;

/**
 * Um problema encontrado no rascunho — o `validation_issue` do `04` §2.12.
 *
 * `regra` é o ID da spec (`V-IMG-05`…); `alvo` é a referência estruturada que
 * a tela usa para "ir para o campo" (`['grupo' => 'COLOR=id:52028']`,
 * `['variante' => …]`, `['atributo' => 'MODEL']`…); `mlCausa` é a causa crua
 * do ML quando o problema veio da L3 (mostrada recolhida, `08` §3).
 */
final class Problema
{
    public const BLOQUEIO = 'BLOCKER';
    public const AVISO = 'WARNING';
    public const INFO = 'INFO';

    public function __construct(
        public readonly string $regra,
        public readonly string $severidade,
        public readonly string $mensagem,
        public readonly string $camada = 'L2',
        public readonly array $alvo = [],
        public readonly ?array $mlCausa = null,
    ) {}

    public static function bloqueio(string $regra, string $mensagem, array $alvo = [], string $camada = 'L2'): self
    {
        return new self($regra, self::BLOQUEIO, $mensagem, $camada, $alvo);
    }

    public static function aviso(string $regra, string $mensagem, array $alvo = [], string $camada = 'L2'): self
    {
        return new self($regra, self::AVISO, $mensagem, $camada, $alvo);
    }

    public static function info(string $regra, string $mensagem, array $alvo = [], string $camada = 'L2'): self
    {
        return new self($regra, self::INFO, $mensagem, $camada, $alvo);
    }

    public function bloqueia(): bool
    {
        return $this->severidade === self::BLOQUEIO;
    }
}
