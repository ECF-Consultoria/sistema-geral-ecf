<?php

namespace App\Services\Creative;

/**
 * Falha em que OUTRO modelo pode dar certo: sobrecarga, timeout, modelo fora
 * do ar, ou resposta 200 sem imagem (o modelo pode ter recusado o pedido por
 * política de conteúdo, mas um modelo diferente pode aceitar). Quem está fora
 * do `GeminiImageProvider` só vê `RuntimeException`, que é a classe-mãe.
 *
 * Classe própria em vez de reusar `App\Services\Ia\FalhaTrocavel`: são
 * camadas distintas (texto vs. imagem) e a de lá é documentada como interna
 * ao serviço de análise de anúncio.
 */
class FalhaDeGeracaoTrocavel extends \RuntimeException
{
}
