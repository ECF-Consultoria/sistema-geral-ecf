<?php

namespace App\Services\Creative\Contracts;

use App\Services\Creative\Dto\CreativeGenerationRequest;
use App\Services\Creative\Dto\CreativeGenerationResult;

/**
 * Contrato de provedor de geração de imagem por IA (plano-incubadora-v1
 * §8.6). Hoje só existe `GeminiImageProvider`, mas o contrato fica separado
 * da implementação de propósito: trocar de provedor no futuro é escrever uma
 * nova classe, não reescrever quem chama.
 *
 * `gerarTexto()` existe porque o comando de teste manual (§6.3) precisa de
 * uma chamada de conectividade simples e porque a validação do V0.4 vai usar
 * o mesmo contrato — não é escopo-creep, é o mínimo que o teste exige.
 */
interface ImageGenerationProvider
{
    /**
     * Gera uma imagem a partir do prompt e, opcionalmente, de imagens de
     * referência (bytes crus — ver decisão D-02: nunca path em storage).
     *
     * @throws \App\Services\Creative\FalhaDeGeracaoTrocavel quando outro
     *         modelo/provedor pode resolver (sobrecarga, timeout, 200 sem
     *         imagem).
     * @throws \RuntimeException quando trocar de modelo não ajuda (chave
     *         recusada, pedido malformado) ou quando a chave não está
     *         configurada.
     */
    public function gerarImagem(CreativeGenerationRequest $request): CreativeGenerationResult;

    /**
     * Chamada de texto simples — usada hoje só pelo `creative:test-gemini`
     * como prova de conectividade antes de gastar crédito com imagem.
     *
     * @throws \RuntimeException quando a chave não está configurada ou a
     *         resposta vem vazia/com erro definitivo.
     */
    public function gerarTexto(string $prompt): string;
}
