<?php

namespace App\Support\Publicador;

use App\Support\Publicador\Payload\Alvo;
use App\Support\Publicador\Variacao\Eixo;
use App\Support\Publicador\Variacao\Variante;

/**
 * O rascunho inteiro, imutável e sem banco — o que as funções puras recebem
 * (classificação, validação L1/L2, montagem do payload). Quem monta a partir
 * das tabelas é o repositório; quem preenche título e preço vindos de fora
 * (aba Anúncios, Precificação) também — aqui já chegam os dados EFETIVOS.
 *
 * Formas:
 * - `atributos`: attribute_id → valor (`value_id`, `value_name`, `value_number`, `value_unit`) — os do PRODUTO.
 * - `variantes[].dados`: `estoque` (int), `precos` (listing_type_id → preço), `atributos` (os da variante: SKU, GTIN…).
 * - `imagens`: atribuições `{imagem, grupo, posicao}` (ver `ResolvedorGruposImagem`).
 * - `envio`: `{modo, frete_gratis, retirada, logistic_type?}`.
 * - `garantia`: `{tipo: value_id de WARRANTY_TYPE, tempo: ?int, unidade: ?string}` ou nula.
 */
final class RascunhoSnapshot
{
    /**
     * @param  'new'|'used'|'refurbished'  $condicao
     * @param  array<string, array>  $atributos
     * @param  list<Eixo>  $eixos
     * @param  list<Variante>  $variantes
     * @param  list<Alvo>  $alvos
     * @param  list<array{imagem: string, grupo: string, posicao: int}>  $imagens
     */
    public function __construct(
        public readonly string $categoriaId,
        public readonly string $condicao = 'new',
        public readonly array $atributos = [],
        public readonly array $eixos = [],
        public readonly array $variantes = [],
        public readonly array $alvos = [],
        public readonly array $imagens = [],
        public readonly bool $fotosPorVariante = false,
        public readonly bool $incluirGeral = true,
        public readonly ?string $descricao = null,
        public readonly array $envio = ['modo' => 'me2', 'frete_gratis' => false, 'retirada' => false],
        public readonly ?array $garantia = null,
    ) {}

    /** As variantes que vão para o ML: ativas e não órfãs. */
    public function variantesAtivas(): array
    {
        return array_values(array_filter($this->variantes, fn (Variante $v) => $v->ativa && ! $v->orfa));
    }

    /** @return list<Alvo> */
    public function alvosAtivos(): array
    {
        return array_values(array_filter($this->alvos, fn (Alvo $a) => $a->ativo));
    }
}
