<?php

namespace App\Support\Publicador;

use App\Models\EstruturaOferta;
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
 * - `unidadesPorOferta`: quantas unidades do MESMO produto a oferta contém — `1`
 *   na Fase 1, `pub_produtos.quantidade_kit` (≥ 2) no combo da Fase 2. É FATO do
 *   cadastro, NUNCA lido do título (título é texto que uma pessoa edita; a
 *   quantidade é dado estruturado). Quem consome hoje é a montagem do payload,
 *   para a capa do combo não rotacionar entre Clássico e Premium.
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
        public readonly int $unidadesPorOferta = 1,
    ) {}

    /**
     * O rascunho com os dados EFETIVOS: título nulo de um alvo vale o título
     * planejado na aba Anúncios; preço nulo vale o da Precificação (ADR
     * PORTAL-02/03). Serve para validar e montar o payload — e NUNCA é gravado:
     * o Anunciar antigo devolvia os efetivos ao autosave e congelava o preço da
     * Precificação no rascunho (`16` §1.6). Por isso o repositório só lê e grava
     * o que a pessoa digitou.
     *
     * 10/10/2026 — `$portal` (o resto do que o `DadosEfetivosService::daProduto` devolve: `promocoes`,
     * `sem_frete` e os `_por_variante`) acrescenta a cada variante, NA CÓPIA EFETIVA:
     *  - `dados.portal[lt]` = `{anunciado, minimo, sem_frete}` da MESMA linha da Precificação que dá o
     *    preço (a da cor quando ela tem preço, senão a da âncora) — mesmo com preço digitado: a promoção
     *    automática aplica o percentual do Portal sobre o digitado (`PrecoDaPromocao`);
     *  - `dados.preco_do_portal[lt] = true` quando o preço VEIO do Portal (não digitado) — é o que o
     *    V-SAL-08 olha: preço do Portal calculado sem frete bloqueia; o digitado é decisão da equipe.
     * Sem `$portal`, o resultado é exatamente o de antes (nenhuma chave nova).
     *
     * @param  array<string, ?string>  $titulos  listing_type_id → título planejado
     * @param  array<string, ?float>  $precos  listing_type_id → preço anunciado da Precificação
     * @param  array<string, array<string, ?float>>  $porVariante  SKU normalizado → (listing_type_id → preço) da oferta daquela cor (produto agrupado); a variante sem casamento usa `$precos`
     * @param  array{promocoes?: array<string, ?float>, sem_frete?: array<string, bool>, promocoes_por_variante?: array<string, array<string, ?float>>, sem_frete_por_variante?: array<string, array<string, bool>>}  $portal
     */
    public function comEfetivos(array $titulos, array $precos, array $porVariante = [], array $portal = []): self
    {
        $alvos = array_map(fn (Alvo $a) => trim((string) $a->titulo) !== ''
            ? $a
            : new Alvo($a->listingTypeId, $titulos[$a->listingTypeId] ?? null, $a->ativo), $this->alvos);

        $comPortal = $portal !== [];
        $promocoes = (array) ($portal['promocoes'] ?? []);
        $semFrete = (array) ($portal['sem_frete'] ?? []);
        $promocoesPorVariante = (array) ($portal['promocoes_por_variante'] ?? []);
        $semFretePorVariante = (array) ($portal['sem_frete_por_variante'] ?? []);

        $variantes = array_map(function (Variante $v) use ($precos, $porVariante, $comPortal, $promocoes, $semFrete, $promocoesPorVariante, $semFretePorVariante) {
            $proprios = (array) ($v->dados['precos'] ?? []);
            $sku = EstruturaOferta::normalizarSku($v->dados['atributos']['SELLER_SKU']['value_name'] ?? $this->atributos['SELLER_SKU']['value_name'] ?? null);
            $daVariante = $sku !== null ? ($porVariante[$sku] ?? null) : null;
            $doPortal = [];
            $veioDoPortal = [];
            foreach ($this->alvos as $alvo) {
                $lt = $alvo->listingTypeId;
                $daCor = ($daVariante[$lt] ?? null) !== null;
                $efetivo = $daCor ? $daVariante[$lt] : ($precos[$lt] ?? null);
                if ($comPortal && $efetivo !== null) {
                    $minimo = $daCor ? ($promocoesPorVariante[$sku][$lt] ?? null) : ($promocoes[$lt] ?? null);
                    $doPortal[$lt] = [
                        'anunciado' => (float) $efetivo,
                        'minimo' => $minimo !== null ? (float) $minimo : null,
                        'sem_frete' => (bool) ($daCor ? ($semFretePorVariante[$sku][$lt] ?? false) : ($semFrete[$lt] ?? false)),
                    ];
                }
                if (($proprios[$lt] ?? null) !== null) {
                    continue;
                }
                if ($efetivo !== null) {
                    $proprios[$lt] = (float) $efetivo;
                    $veioDoPortal[$lt] = true;
                }
            }

            $dados = [...$v->dados, 'precos' => $proprios];
            if ($doPortal !== []) {
                $dados['portal'] = $doPortal;
            }
            if ($comPortal && $veioDoPortal !== []) {
                $dados['preco_do_portal'] = $veioDoPortal;
            }

            return $v->comDados($dados);
        }, $this->variantes);

        // ⚠️ Argumentos POSICIONAIS: todo campo novo do construtor precisa ser
        // repetido aqui, senão volta ao default silenciosamente — e é por este
        // caminho que a PUBLICAÇÃO passa (`comEfetivos()` é o que a conferência e
        // o payload usam). `unidadesPorOferta` esquecido aqui faria o combo
        // publicar como Fase 1 sem nenhum erro aparecer.
        return new self(
            $this->categoriaId, $this->condicao, $this->atributos, $this->eixos, $variantes, $alvos, $this->imagens,
            $this->fotosPorVariante, $this->incluirGeral, $this->descricao, $this->envio, $this->garantia,
            $this->unidadesPorOferta,
        );
    }

    /**
     * O `comEfetivos` com TUDO o que o `DadosEfetivosService::daProduto` devolveu (preço, mínimo da
     * promoção e a marca sem frete). É o caminho de quem VALIDA (conferência, publicação e o estado do
     * editor): sem ele o V-SAL-08 não enxerga o preço do Portal calculado sem frete.
     *
     * @param  array<string, mixed>  $e
     */
    public function comEfetivosDe(array $e): self
    {
        return $this->comEfetivos((array) ($e['titulos'] ?? []), (array) ($e['precos'] ?? []), (array) ($e['precos_por_variante'] ?? []), [
            'promocoes' => (array) ($e['promocoes'] ?? []),
            'sem_frete' => (array) ($e['sem_frete'] ?? []),
            'promocoes_por_variante' => (array) ($e['promocoes_por_variante'] ?? []),
            'sem_frete_por_variante' => (array) ($e['sem_frete_por_variante'] ?? []),
        ]);
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
