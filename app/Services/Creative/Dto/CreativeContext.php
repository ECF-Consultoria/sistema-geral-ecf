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
 *
 * Fase 175 (§5 da ETAPA-3): `unidadesDoKit` é a quantidade de unidades
 * IGUAIS que o anúncio vende como kit, lida de `pub_produtos.quantidade_kit`
 * — campo de CADASTRO, preenchido pela pessoa no painel "Criar Fase N".
 * Nulo em todo produto que não é kit (e em todo o ramo antigo). É o único
 * caminho pelo qual esse número chega ao prompt, e ele chega como FATO
 * (`ProductTruth::contagens`, origem `cadastro`), nunca como frase solta:
 * número errado no prompt é pior que número nenhum, porque o modelo obedece
 * com confiança e a imagem passa pela revisão humana sem levantar suspeita.
 *
 * Fase 165 (Creative Engine no Publicador novo): `pubRascunhoId` existe só
 * para o ramo do Publicador (`CreativeContextBuilder::paraPublicador()`) —
 * nesse ramo `rascunhoId` vale `0` porque o campo é `int` não nulo e o
 * rascunho de origem é o do Publicador (`pub_rascunhos`), não o do
 * assistente antigo.
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
        public ?int $pubRascunhoId = null,
        public ?int $unidadesDoKit = null,
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
        $auditoria = [
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

        // Fase 165: só acrescenta a chave quando preenchida — o ramo antigo
        // (payload do assistente antigo) continua com as MESMAS 10 chaves,
        // na mesma ordem, de sempre.
        if ($this->pubRascunhoId !== null) {
            $auditoria['pub_rascunho_id'] = $this->pubRascunhoId;
        }

        // Fase 175: mesma regra do `pubRascunhoId` acima — a chave só aparece
        // quando preenchida, então NENHUM criativo já gravado (nem os kits de
        // 7 slots em produção) muda de forma. O default de ninguém muda.
        if ($this->unidadesDoKit !== null) {
            $auditoria['unidades_do_kit'] = $this->unidadesDoKit;
        }

        return $auditoria;
    }
}
