<?php

namespace App\Support\Publicador;

/**
 * Uma regra da especificação do Publicador recusou a operação.
 *
 * `regra` é o ID estável da spec (`V-VAR-03`, `RN-61`, `H-17`…) — o mesmo que
 * aparece nos testes e nas mensagens — e `contexto` leva o que a tela precisa
 * para ajudar (ex.: `['sugestao' => 'COLOR']`). A mensagem já é a do usuário.
 */
class RegraViolada extends \DomainException
{
    public function __construct(
        public readonly string $regra,
        string $mensagem,
        public readonly array $contexto = [],
    ) {
        parent::__construct($mensagem);
    }
}
