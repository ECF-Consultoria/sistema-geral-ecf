<?php

namespace App\Services\Ia;

/**
 * Falha em que OUTRO modelo pode dar certo (sobrecarga, timeout, modelo fora,
 * resposta vazia ou sem JSON). Interna ao serviço: quem está fora só vê
 * `RuntimeException`, que é a classe-mãe.
 */
class FalhaTrocavel extends \RuntimeException
{
}
