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

    /**
     * O rascunho com os dados EFETIVOS: título nulo de um alvo vale o título
     * planejado na aba Anúncios; preço nulo vale o da Precificação (ADR
     * PORTAL-02/03). Serve para validar e montar o payload — e NUNCA é gravado:
     * o Anunciar antigo devolvia os efetivos ao autosave e congelava o preço da
     * Precificação no rascunho (`16` §1.6). Por isso o repositório só lê e grava
     * o que a pessoa digitou.
     *
     * @param  array<string, ?string>  $titulos  listing_type_id → título planejado
     * @param  array<string, ?float>  $precos  listing_type_id → preço anunciado da Precificação
     */
    public function comEfetivos(array $titulos, array $precos): self
    {
        $alvos = array_map(fn (Alvo $a) => trim((string) $a->titulo) !== ''
            ? $a
            : new Alvo($a->listingTypeId, $titulos[$a->listingTypeId] ?? null, $a->ativo), $this->alvos);

        $variantes = array_map(function (Variante $v) use ($precos) {
            $proprios = (array) ($v->dados['precos'] ?? []);
            foreach ($this->alvos as $alvo) {
                $lt = $alvo->listingTypeId;
                if (($proprios[$lt] ?? null) === null && isset($precos[$lt])) {
                    $proprios[$lt] = (float) $precos[$lt];
                }
            }

            return $v->comDados([...$v->dados, 'precos' => $proprios]);
        }, $this->variantes);

        return new self(
            $this->categoriaId, $this->condicao, $this->atributos, $this->eixos, $variantes, $alvos, $this->imagens,
            $this->fotosPorVariante, $this->incluirGeral, $this->descricao, $this->envio, $this->garantia,
        );
    }

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
