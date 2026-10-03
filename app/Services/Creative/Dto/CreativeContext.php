<?php

namespace App\Services\Creative\Dto;

/**
 * Contexto do criativo — tudo que o Creative Engine sabe sobre o produto,
 * montado EXCLUSIVAMENTE pelo `CreativeContextBuilder` a partir do que o
 * publicador já tem (CTX-01). Nenhum outro arquivo monta este DTO.
 *
 * `imagensReferencia` guarda BYTES CRUS (CTX-03) — existem apenas em memória
 * durante a geração; banco e log recebem só metadado (`referenciasMeta`,
 * via `paraAuditoria()`). Nunca serializar este DTO inteiro: bytes de foto
 * de cliente não podem entrar em coluna nem em log (FOTO-01/GEN-05).
 */
final readonly class CreativeContext
{
    /**
     * @param  array<string, string>  $atributos  id do atributo → value_name (TRUTH-01 só lê daqui)
     * @param  array{quantidade: int, combinacoes: array<int, array<int, string>>}  $variacoes
     * @param  array<int, array{mime: string, bytes: string}>  $imagensReferencia  bytes crus — nunca path (CTX-03)
     * @param  array<int, array{indice: int|null, mime: string|null, bytes: int|null, nome: string|null}>  $referenciasMeta
     */
    public function __construct(
        public int $rascunhoId,
        public string $produto,
        public ?string $marca,
        public ?string $modelo,
        public ?string $categoriaId,
        public ?string $descricao,
        public array $atributos,
        public array $variacoes,
        public ?string $loja,
        public array $imagensReferencia,
        public array $referenciasMeta,
    ) {}

    /**
     * Forma que vai para a coluna `ml_anuncio_criativos.contexto` e para log.
     * NUNCA contém `imagensReferencia` (bytes) — só o metadado das referências,
     * já sem base64 nem conteúdo binário (GEN-05).
     *
     * @return array<string, mixed>
     */
    public function paraAuditoria(): array
    {
        return [
            'rascunho_id'      => $this->rascunhoId,
            'produto'          => $this->produto,
            'marca'            => $this->marca,
            'modelo'           => $this->modelo,
            'categoria_id'     => $this->categoriaId,
            'descricao'        => $this->descricao,
            'atributos'        => $this->atributos,
            'variacoes'        => $this->variacoes,
            'loja'             => $this->loja,
            'referencias_meta' => $this->referenciasMeta,
        ];
    }
}
