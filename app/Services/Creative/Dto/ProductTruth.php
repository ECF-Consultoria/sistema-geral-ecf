<?php

namespace App\Services\Creative\Dto;

/**
 * Product Truth — os fatos VERIFICADOS do produto que o `CreativePromptBuilder`
 * (160-02 Task 2) tem permissão de usar no prompt. O que não está aqui fica
 * FORA do prompt por construção (TRUTH-03): o builder de prompt só recebe
 * este DTO, nunca o corpo salvo de `ml_anuncio_rascunhos` nem `CreativeContext` cru.
 *
 * `contagens` é o campo mais sensível desta classe: TRUTH-02 exige que cada
 * entrada venha do cadastro (`origem: 'cadastro'`) ou de leitura conferida —
 * NUNCA de inferência sobre título/descrição. Ver o docblock de
 * `ProductTruthBuilder::contagens()` para a lista fechada de ids aceitos.
 */
final readonly class ProductTruth
{
    /**
     * @param  array<string, string>  $fatosVerificados  rótulo legível → valor
     * @param  array<int, array{peca: string, quantidade: string, origem: string}>  $contagens
     * @param  array<int, string>  $beneficiosVerificados
     * @param  array<int, string>  $claimsProibidas  nunca vazio (TRUTH-04)
     * @param  array<int, array{indice: int|null, mime: string|null, bytes: int|null, nome: string|null}>  $referenciasMeta
     */
    public function __construct(
        public ?string $marca,
        public ?string $modelo,
        public array $fatosVerificados,
        public array $contagens,
        public array $beneficiosVerificados,
        public array $claimsProibidas,
        public array $referenciasMeta,
    ) {}

    /**
     * O que o `CreativePromptBuilder` pode ler — sem metadado de referência
     * (que não é fato do produto, é controle interno da geração).
     *
     * @return array<string, mixed>
     */
    public function paraPrompt(): array
    {
        return [
            'marca'                   => $this->marca,
            'modelo'                  => $this->modelo,
            'fatos_verificados'       => $this->fatosVerificados,
            'contagens'               => $this->contagens,
            'beneficios_verificados'  => $this->beneficiosVerificados,
            'claims_proibidas'        => $this->claimsProibidas,
        ];
    }

    /**
     * Forma que vai para a coluna `ml_anuncio_criativos.truth` e para log.
     * Nunca contém bytes nem base64 — só metadado (GEN-05).
     *
     * @return array<string, mixed>
     */
    public function paraAuditoria(): array
    {
        return [...$this->paraPrompt(), 'referencias_meta' => $this->referenciasMeta];
    }
}
