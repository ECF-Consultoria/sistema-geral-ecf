<?php

namespace App\Services\Creative\Contracts;

use App\Services\Creative\Dto\CreativeJudgementRequest;
use App\Services\Creative\Dto\CreativeJudgementResult;

/**
 * Contrato NOVO e SEPARADO de julgamento (Fase 162, D-06) — N imagens +
 * prompt → TEXTO. Não é método novo em `ImageGenerationProvider`.
 *
 * POR QUE SEPARADO. `ImageGenerationProvider` tem `gerarTexto(string): string`,
 * que não aceita imagem nenhuma — o juiz precisa enviar N imagens (fotos
 * originais + a gerada) junto do prompt. Mas acrescentar um método ABSTRATO
 * novo ao contrato EXISTENTE quebraria em erro fatal de PHP as classes
 * anônimas de teste que já o implementam sem esse método:
 * `tests/Unit/Phase161/CreativePlannerTest.php` (duas, ~linha 51 e ~68) e
 * `tests/Feature/Phase161/CriativoKitPlanejamentoTest.php` (~linha 51) — e
 * isso seria regressão direta nos 175 testes verdes que esta fase não pode
 * quebrar. Um contrato novo e estreito não toca nenhum deles.
 *
 * `GeminiImageProvider` implementa os DOIS contratos — `gerarImagem()` e
 * `gerarTexto()` não mudam uma linha.
 */
interface ImageJudgementProvider
{
    /**
     * Julga N imagens (fotos originais + imagem gerada, NESTA ordem — ver
     * docblock de `CreativeJudgementRequest`) contra o prompt do juiz e
     * devolve o TEXTO cru da resposta — nunca JSON já decodificado: quem
     * decodifica e reconcilia é `CreativeJuiz`.
     *
     * @throws \App\Services\Creative\FalhaDeGeracaoTrocavel quando outro
     *         modelo/provedor pode resolver (sobrecarga, timeout, resposta
     *         vazia).
     * @throws \RuntimeException quando trocar de modelo não ajuda (chave
     *         recusada, pedido malformado) ou quando a chave não está
     *         configurada.
     */
    public function julgar(CreativeJudgementRequest $pedido): CreativeJudgementResult;
}
