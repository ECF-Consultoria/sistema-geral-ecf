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
 *
 * `beneficiosVerificados` e `medidasConfirmadas` são a "leitura conferida"
 * que este docblock já previa desde a Fase 160/161 — Fase 169 (TXT-01/TXT-02)
 * finalmente os popula, a partir de `CreativeContext::fatosHumanosBeneficios`/
 * `fatosHumanosMedidas` (confirmação EXPLÍCITA do operador, nunca promovida a
 * partir de texto livre). Continuam fora de `fatosVerificados`/`contagens`
 * (TRUTH-01/02 intactas) — campos separados, nunca misturados no mesmo array.
 */
final readonly class ProductTruth
{
    /**
     * @param  array<string, string>  $fatosVerificados  rótulo legível → valor
     * @param  array<int, array{peca: string, quantidade: string, origem: string}>  $contagens
     * @param  array<int, string>  $beneficiosVerificados
     * @param  array<int, string>  $claimsProibidas  nunca vazio (TRUTH-04)
     * @param  array<int, array{indice: int|null, mime: string|null, bytes: int|null, nome: string|null}>  $referenciasMeta
     * @param  array<string, string>  $atributosIds  id do atributo → value_name (Fase 161,
     *         `CreativeSlotCatalog::elegiveis()`) — id de atributo NÃO é material de prompt,
     *         por isso fica FORA de `paraPrompt()`/`paraAuditoria()`. Último parâmetro, com
     *         default, para não mudar a forma serializada já consumida pela Fase 160.
     * @param  array<int, string>  $medidasConfirmadas  medidas confirmadas pelo operador (Fase 169)
     */
    public function __construct(
        public ?string $marca,
        public ?string $modelo,
        public array $fatosVerificados,
        public array $contagens,
        public array $beneficiosVerificados,
        public array $claimsProibidas,
        public array $referenciasMeta,
        public array $atributosIds = [],
        public array $medidasConfirmadas = [],
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
            'medidas_confirmadas'     => $this->medidasConfirmadas,
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
