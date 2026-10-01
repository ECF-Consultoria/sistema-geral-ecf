<?php

namespace App\Services\Ia;

use App\Models\MlAnuncioIaAnalise;
use App\Models\MlAnuncioRascunho;
use App\Models\MlbEmpresa;
use App\Services\Mlb\Publicacao\MlCatalogoMetaService;
use Illuminate\Support\Facades\Log;

/**
 * O lado "Mercado Livre" do Anunciar por IA: transforma o que a IA gerou num
 * RASCUNHO completo do wizard de /mlb/anuncios.
 *
 * Escolhe a categoria pelo preditor do próprio ML (o mesmo do botão
 * "Categoria" do wizard), recorta a ficha técnica que o wizard MOSTRA, confere
 * cada valor devolvido pela IA contra o catálogo da categoria e monta o payload
 * no formato do `montarPayload()` de AnunciarML.jsx — o wizard abre o rascunho
 * como abriria qualquer outro.
 *
 * NUNCA PUBLICA. Não há MlPublicacaoService aqui, de propósito: o pedido de
 * 30/09/2026 foi "a IA faz tudo, mas só deixa em rascunho". Fotos, conferência
 * e o clique de publicar ficam com o publicador.
 */
class RascunhoAnuncioIaService
{
    /**
     * Fora da ficha que a IA preenche. GTIN/EAN e SKU são do produto físico
     * (a IA não tem como saber); catálogo está desligado por decisão do
     * negócio; grade de moda e pacote têm campo próprio no wizard.
     */
    private const FORA_DA_FICHA = ['CATALOG_PRODUCT_ID', 'GTIN', 'EMPTY_GTIN_REASON', 'SELLER_SKU', 'SIZE_GRID_ID'];

    /** Teto de opções listadas por atributo no prompt — a conferência usa a lista inteira. */
    private const MAX_OPCOES_PROMPT = 30;

    /** Teto de atributos no prompt (obrigatórios entram primeiro). */
    private const MAX_ATRIBUTOS_PROMPT = 70;

    private const MAX_VARIACOES = 20;

    /** Mesmo corte do aviso do wizard (PRECO_FRETE_GRATIS_OBRIGATORIO em mlAnuncioRegras.js). */
    private const PRECO_FRETE_GRATIS = 79;

    /** Garantia padrão do wizard quando ninguém informou outra. */
    private const GARANTIA_PADRAO = '30 dias';

    public function __construct(private MlCatalogoMetaService $meta) {}

    // ═══ Etapa "ficha" ════════════════════════════════════════════════════════

    /**
     * Categoria + ficha técnica + variações + pacote + garantia.
     *
     * `$usarIa = false` é o caminho de degradação: a IA falhou na última
     * tentativa e o rascunho sai mesmo assim, com categoria e o que veio do
     * cliente — melhor que nenhum rascunho.
     *
     * @return array{dados: array, meta: array}
     *
     * @throws \RuntimeException quando a chamada à IA falha (o job decide se retenta)
     */
    public function preencherFicha(
        AnaliseAnuncioService $ia,
        string $produto,
        string $specs,
        array $titulos,
        ?array $cliente,
        bool $usarIa = true,
    ): array {
        $categoria = $this->resolverCategoria($produto, $this->escolherTitulo($titulos, 60, $produto));
        $titulo    = $this->escolherTitulo($titulos, $categoria['max_titulo'], $produto);

        $ficha = [
            'category_id'              => $categoria['category_id'],
            'categoria_nome'           => $categoria['nome'],
            'caminho'                  => $categoria['caminho'],
            'domain_id'                => $categoria['domain_id'],
            'titulo'                   => $titulo,
            'atributos'                => [],
            'variacoes'                => [],
            'pacote'                   => $this->pacote($cliente, null),
            'garantia'                 => null,
            'descartados'              => [],
            'obrigatorios_total'       => 0,
            'obrigatorios_faltando'    => [],
            'aviso'                    => null,
        ];

        if ($categoria['category_id'] === null) {
            $ficha['aviso'] = "O Mercado Livre não sugeriu categoria para \"{$produto}\" — escolha no passo 1.";

            return ['dados' => $ficha, 'meta' => []];
        }

        $recorte = $this->recortarCatalogo($this->atributosSeguros($categoria['category_id']));

        if ($recorte['ficha'] === [] && $recorte['variacao'] === []) {
            $ficha['aviso'] = 'A ficha técnica desta categoria não carregou — preencha no passo 2.';

            return ['dados' => $ficha, 'meta' => []];
        }

        $obrigatorios = array_filter($recorte['ficha'], fn ($a) => ! empty($a['tags']['required']));
        $ficha['obrigatorios_total'] = count($obrigatorios);

        $meta     = [];
        $resposta = [];

        if ($usarIa) {
            $r = $ia->ficha(
                $produto,
                $specs,
                $titulo,
                (string) ($categoria['caminho'] ?? $categoria['nome'] ?? ''),
                $this->catalogoParaPrompt($recorte),
            );
            $resposta = is_array($r['dados']) ? $r['dados'] : [];
            $meta     = $r['meta'];
        }

        $mapeados = $this->mapearAtributos($resposta['atributos'] ?? [], $recorte['ficha']);

        $ficha['atributos']   = $mapeados['atributos'];
        $ficha['descartados'] = $mapeados['descartados'];
        $ficha['pacote']      = $this->pacote($cliente, $resposta['pacote'] ?? null);
        $ficha['garantia']    = $this->garantia($resposta['garantia'] ?? null);
        $ficha['variacoes']   = $this->mapearVariacoes(
            $resposta['variacoes'] ?? [],
            $recorte['variacao'],
            $this->precoCliente($cliente),
            $this->estoqueCliente($cliente),
            $cliente['sku'] ?? null,
        );

        $preenchidos = array_column($ficha['atributos'], 'id');
        $ficha['obrigatorios_faltando'] = array_values(array_map(
            fn ($a) => (string) ($a['name'] ?? $a['id']),
            array_filter($obrigatorios, fn ($a, $id) => ! in_array($id, $preenchidos, true), ARRAY_FILTER_USE_BOTH),
        ));

        return ['dados' => $ficha, 'meta' => $meta];
    }

    /**
     * Categoria pelo preditor do ML. Tenta o nome do produto (a frase de quem
     * cadastra) e, sem resposta, o título escolhido.
     *
     * Falha de rede/token não derruba nada: sem categoria o rascunho sai do
     * mesmo jeito e o publicador escolhe no passo 1.
     *
     * @return array{category_id: ?string, nome: ?string, caminho: ?string, domain_id: ?string, max_titulo: int}
     */
    public function resolverCategoria(string $produto, string $titulo): array
    {
        $vazio = ['category_id' => null, 'nome' => null, 'caminho' => null, 'domain_id' => null, 'max_titulo' => 60];

        foreach (array_unique(array_filter([trim($produto), trim($titulo)])) as $busca) {
            try {
                $candidatos = $this->meta->preverCategoria($busca);
            } catch (\Throwable $e) {
                Log::warning("[IA] Preditor de categoria falhou para '{$busca}': {$e->getMessage()}");

                return $vazio;
            }

            $c = collect($candidatos)->first(fn ($c) => is_array($c) && ! empty($c['category_id']));

            if ($c === null) {
                continue;
            }

            $cat     = $this->categoriaSegura((string) $c['category_id']);
            $caminho = collect($cat['path_from_root'] ?? [])->pluck('name')->filter()->implode(' › ');

            return [
                'category_id' => (string) $c['category_id'],
                'nome'        => $c['category_name'] ?? ($cat['name'] ?? null),
                'caminho'     => $caminho !== '' ? $caminho : ($c['category_name'] ?? null),
                'domain_id'   => $c['domain_id'] ?? null,
                'max_titulo'  => (int) data_get($cat, 'settings.max_title_length', 60) ?: 60,
            ];
        }

        return $vazio;
    }

    /**
     * Separa o catálogo da categoria do jeito que o wizard separa:
     *   ficha    = obrigatórios + secundários (mesmos filtros de AnunciarML.jsx)
     *   variacao = allow_variations (vão na etapa Variações)
     *
     * Só entra o que o wizard mostra: valor em campo invisível seria enviado
     * ao ML sem ninguém ter visto.
     *
     * @return array{ficha: array<string, array>, variacao: array<string, array>}
     */
    public function recortarCatalogo(array $atributos): array
    {
        $ficha    = [];
        $variacao = [];

        foreach ($atributos as $a) {
            $id = (string) ($a['id'] ?? '');

            if ($id === ''
                || str_contains($id, 'GRID')
                || str_starts_with($id, 'SELLER_PACKAGE_')
                || in_array($id, self::FORA_DA_FICHA, true)) {
                continue;
            }

            $tags = (array) ($a['tags'] ?? []);

            if (! empty($tags['allow_variations'])) {
                $variacao[$id] = $a;
                continue;
            }

            // Secundário oculto/somente-leitura o wizard não mostra; obrigatório mostra sempre.
            if (empty($tags['required']) && (! empty($tags['hidden']) || ! empty($tags['read_only']))) {
                continue;
            }

            $ficha[$id] = $a;
        }

        // Obrigatórios primeiro: se o teto do prompt cortar, corta secundário.
        uasort($ficha, fn ($x, $y) => (int) empty($x['tags']['required']) <=> (int) empty($y['tags']['required']));

        return ['ficha' => $ficha, 'variacao' => $variacao];
    }

    /** Lista de atributos no formato que o prompt da ficha espera. */
    public function catalogoParaPrompt(array $recorte): string
    {
        $linhas = [];
        foreach (array_slice($recorte['ficha'], 0, self::MAX_ATRIBUTOS_PROMPT, true) as $id => $a) {
            $linhas[] = $this->linhaCatalogo((string) $id, $a);
        }

        $texto = implode("\n", $linhas);

        if ($recorte['variacao'] !== []) {
            $vars = [];
            foreach ($recorte['variacao'] as $id => $a) {
                $vars[] = $this->linhaCatalogo((string) $id, $a);
            }
            $texto .= "\n\nATRIBUTOS DE VARIAÇÃO (use só dentro de \"variacoes\"):\n" . implode("\n", $vars);
        }

        return $texto;
    }

    // ═══ Conferência do que a IA devolveu ═════════════════════════════════════

    /**
     * Confere cada atributo contra o catálogo. O que não bate (ID que não é
     * da categoria, opção fora da lista, unidade não aceita) é DESCARTADO —
     * nunca "ajustado" para parecer certo.
     *
     * @return array{atributos: array<int, array>, descartados: array<int, string>}
     */
    public function mapearAtributos(mixed $resposta, array $catalogo): array
    {
        $ok          = [];
        $descartados = [];

        foreach (is_array($resposta) ? $resposta : [] as $id => $valor) {
            $id = strtoupper(trim((string) $id));

            if (! isset($catalogo[$id])) {
                $descartados[] = $id;
                continue;
            }

            $v = $this->valorAtributo($catalogo[$id], $valor);

            if ($v === null) {
                $descartados[] = $id;
                continue;
            }

            $ok[] = ['id' => $id] + $v;
        }

        return ['atributos' => $ok, 'descartados' => array_values(array_filter($descartados))];
    }

    /**
     * Um valor da IA no formato que o wizard lê: `value_id` para lista/Sim-Não
     * (o wizard renderiza <select>), `value_name` para texto e número.
     *
     * @return array{value_id: string}|array{value_name: string}|null
     */
    public function valorAtributo(array $def, mixed $valor): ?array
    {
        $tipo    = (string) ($def['value_type'] ?? 'string');
        $valores = is_array($def['values'] ?? null) ? $def['values'] : [];
        $fechado = in_array($tipo, ['list', 'boolean'], true);

        if (is_array($valor)) {
            $partes = array_values(array_filter(array_map(fn ($x) => is_scalar($x) ? trim((string) $x) : '', $valor)));
            // Lista fechada aceita UM valor no wizard; texto aceita vários.
            $valor = $fechado ? ($partes[0] ?? '') : implode(', ', $partes);
        }

        if (is_bool($valor)) {
            $valor = $valor ? 'Sim' : 'Não';
        }

        if (! is_scalar($valor)) {
            return null;
        }

        $texto = trim((string) $valor);

        if ($texto === '' || in_array($this->normalizar($texto), ['null', 'n/a', 'na', '-', 'nao informado', 'desconhecido', 'nenhum'], true)) {
            return null;
        }

        if ($fechado) {
            if ($valores === []) {
                // Sem opções o wizard mostra campo de texto.
                return $tipo === 'boolean'
                    ? (($b = $this->booleano($texto)) !== null ? ['value_name' => $b] : null)
                    : ['value_name' => mb_substr($texto, 0, 255)];
            }

            $casado = $this->casarValor($texto, $valores, $tipo === 'boolean');

            return $casado !== null ? ['value_id' => (string) $casado['id']] : null;
        }

        if ($tipo === 'number') {
            $n = $this->numero($texto);

            return $n !== null ? ['value_name' => $n] : null;
        }

        if ($tipo === 'number_unit') {
            return $this->numeroComUnidade($texto, $def);
        }

        // Texto: usa a grafia oficial quando bate com uma sugestão (ex.: marca).
        $casado = $this->casarValor($texto, $valores, false);

        return ['value_name' => mb_substr((string) ($casado['name'] ?? $texto), 0, 255)];
    }

    /**
     * Variações no formato do payload do wizard. Cada uma nasce com GTIN/EAN
     * gerado, igual ao "Adicionar variação" do wizard (`novaVariacao()`), e
     * sem foto — foto é do publicador.
     *
     * @return array<int, array>
     */
    public function mapearVariacoes(mixed $resposta, array $catalogoVar, ?float $preco, int $estoqueTotal, ?string $sku): array
    {
        if ($catalogoVar === [] || ! is_array($resposta)) {
            return [];
        }

        $combinacoes = [];

        foreach ($resposta as $v) {
            if (! is_array($v)) {
                continue;
            }

            $comb = [];

            foreach ($v as $id => $valor) {
                $id = strtoupper(trim((string) $id));

                if (! isset($catalogoVar[$id])) {
                    continue;
                }

                $m = $this->valorAtributo($catalogoVar[$id], $valor);

                if ($m === null) {
                    continue;
                }

                // O editor de variações mostra value_name ao lado do value_id.
                if (isset($m['value_id'])) {
                    $m['value_name'] = (string) (collect($catalogoVar[$id]['values'] ?? [])
                        ->firstWhere('id', $m['value_id'])['name'] ?? '');
                }

                $comb[] = ['id' => $id, 'name' => (string) ($catalogoVar[$id]['name'] ?? $id)] + $m;
            }

            if ($comb === []) {
                continue;
            }

            $chave = collect($comb)->map(fn ($c) => $c['id'] . ':' . ($c['value_id'] ?? $c['value_name']))->sort()->implode('|');
            $combinacoes[$chave] = $comb;

            if (count($combinacoes) >= self::MAX_VARIACOES) {
                break;
            }
        }

        $n = count($combinacoes);
        if ($n === 0) {
            return [];
        }

        $estoque = max(1, intdiv($estoqueTotal, $n));
        $gtins   = [];
        $saida   = [];

        foreach ($combinacoes as $comb) {
            $gtin    = $this->gerarEan13($gtins);
            $gtins[] = $gtin;

            $attrs = [['id' => 'GTIN', 'value_name' => $gtin]];
            // SKU do cliente só com UMA variação: repetir o mesmo SKU em várias confunde o estoque.
            if ($n === 1 && $sku) {
                $attrs[] = ['id' => 'SELLER_SKU', 'value_name' => $sku];
            }

            $saida[] = [
                'price'                  => $preco,
                'available_quantity'     => $estoque,
                'attribute_combinations' => $comb,
                'attributes'             => $attrs,
                'picture_ids'            => [],
            ];
        }

        return $saida;
    }

    /**
     * Pacote embalado, campo a campo: o cliente (planilha) vence; a estimativa
     * da IA só entra onde o cliente não informou e se o número for plausível.
     *
     * @return array{peso_g: ?int, comprimento_cm: ?int, largura_cm: ?int, altura_cm: ?int, origem: array<string, string>}
     */
    public function pacote(?array $cliente, mixed $ia): array
    {
        $doCliente = [
            'peso_g'         => $this->positivo(($cliente['peso_kg'] ?? null) !== null ? (float) str_replace(',', '.', (string) $cliente['peso_kg']) * 1000 : null),
            'comprimento_cm' => $this->positivo($cliente['profundidade'] ?? null),
            'largura_cm'     => $this->positivo($cliente['largura'] ?? null),
            'altura_cm'      => $this->positivo($cliente['altura'] ?? null),
        ];

        $ia     = is_array($ia) ? $ia : [];
        $limite = ['peso_g' => 300000, 'comprimento_cm' => 400, 'largura_cm' => 400, 'altura_cm' => 400];

        $saida = ['origem' => []];

        foreach ($limite as $campo => $max) {
            if ($doCliente[$campo] !== null) {
                $saida[$campo] = $doCliente[$campo];
                $saida['origem'][$campo] = 'cliente';
                continue;
            }

            $v = $this->positivo($ia[$campo] ?? null);
            $saida[$campo] = ($v !== null && $v <= $max) ? $v : null;

            if ($saida[$campo] !== null) {
                $saida['origem'][$campo] = 'ia';
            }
        }

        return $saida;
    }

    /** "90 dias", "12 meses", "1 ano" — qualquer outra forma é descartada. */
    public function garantia(mixed $texto): ?string
    {
        if (! is_scalar($texto)) {
            return null;
        }

        if (! preg_match('/^\s*(\d{1,3})\s*(dias?|m[eê]s|meses|anos?)\s*$/iu', (string) $texto, $m)) {
            return null;
        }

        $n = (int) $m[1];
        if ($n <= 0) {
            return null;
        }

        $unidade = $this->normalizar($m[2]);

        return match (true) {
            str_starts_with($unidade, 'dia') => $n . ($n === 1 ? ' dia' : ' dias'),
            str_starts_with($unidade, 'ano') => $n . ($n === 1 ? ' ano' : ' anos'),
            default                          => $n . ($n === 1 ? ' mês' : ' meses'),
        };
    }

    /**
     * O título do rascunho: o primeiro dentro da régua ECF que cabe na
     * categoria; senão o primeiro sem nome da loja que cabe; senão o primeiro,
     * cortado na última palavra inteira.
     */
    public function escolherTitulo(array $titulos, int $max, string $reserva): string
    {
        $lista = collect($titulos)
            ->map(fn ($t) => is_array($t) ? $t : ['texto' => (string) $t])
            ->filter(fn ($t) => trim((string) ($t['texto'] ?? '')) !== '');

        $cabe = fn ($t) => mb_strlen(trim((string) $t['texto'])) <= $max;

        $t = $lista->first(fn ($t) => ! empty($t['dentro_da_regra']) && $cabe($t))
            ?? $lista->first(fn ($t) => empty($t['tem_loja']) && $cabe($t))
            ?? $lista->first();

        return $this->cortar(trim((string) ($t['texto'] ?? $reserva)), $max);
    }

    // ═══ Etapa "rascunho" ═════════════════════════════════════════════════════

    /**
     * Grava o rascunho — e só isso. Devolve null quando a análise não tem dono
     * ou empresa (não há como abrir o rascunho no wizard sem os dois).
     */
    public function criarRascunho(MlAnuncioIaAnalise $analise, array $resultado): ?MlAnuncioRascunho
    {
        if ($analise->company_id === null || $analise->user_id === null) {
            return null;
        }

        $ficha   = is_array($resultado['ficha'] ?? null) ? $resultado['ficha'] : [];
        $cliente = is_array($resultado['cliente'] ?? null) ? $resultado['cliente'] : null;

        $payload = $this->montarPayload(
            titulo:     (string) ($ficha['titulo'] ?? $this->escolherTitulo($resultado['titulos'] ?? [], 60, $analise->produto)),
            categoryId: $ficha['category_id'] ?? null,
            preco:      $this->precoCliente($cliente),
            estoque:    $this->estoqueCliente($cliente),
            atributos:  $ficha['atributos'] ?? [],
            pacote:     $ficha['pacote'] ?? $this->pacote($cliente, null),
            variacoes:  $ficha['variacoes'] ?? [],
            garantia:   $ficha['garantia'] ?? null,
            descricao:  (string) ($resultado['descricao'] ?? ''),
            estoqueDoCliente: (int) ($cliente['estoque'] ?? 0) > 0,
        );

        return MlAnuncioRascunho::create([
            'company_id'     => $analise->company_id,
            'mlb_empresa_id' => MlbEmpresa::where('company_id', $analise->company_id)->value('id'),
            'user_id'        => $analise->user_id,
            'category_id'    => $ficha['category_id'] ?? null,
            'payload'        => $payload,
            'status'         => MlAnuncioRascunho::STATUS_RASCUNHO,
            'sku_origem'     => $cliente['sku'] ?? null,
            'listing_tier'   => 'classico',
        ]);
    }

    /**
     * Payload no formato do `montarPayload()` de AnunciarML.jsx, mais
     * `meta_campos` (origem de cada campo: o wizard mostra o selo "IA" ou
     * "cliente" e troca para "editado" quando o publicador mexe).
     */
    public function montarPayload(
        string $titulo,
        ?string $categoryId,
        ?float $preco,
        int $estoque,
        array $atributos,
        array $pacote,
        array $variacoes,
        ?string $garantia,
        string $descricao,
        bool $estoqueDoCliente = false,
    ): array {
        $meta = [];
        if ($titulo !== '')     { $meta['title'] = 'ia'; }
        if ($descricao !== '')  { $meta['description'] = 'ia'; }
        if ($preco !== null)    { $meta['price'] = 'cliente'; }
        if ($estoqueDoCliente)  { $meta['available_quantity'] = 'cliente'; }
        if ($garantia !== null) { $meta['garantia'] = 'ia'; }
        foreach ($atributos as $a) {
            $meta['attr:' . $a['id']] = 'ia';
        }

        // Pacote → SELLER_PACKAGE_* (os mesmos nomes de estado do wizard em meta_campos)
        $mapaPacote = [
            'peso_g'         => ['SELLER_PACKAGE_WEIGHT', 'g',  'pesoG'],
            'altura_cm'      => ['SELLER_PACKAGE_HEIGHT', 'cm', 'alturaCm'],
            'comprimento_cm' => ['SELLER_PACKAGE_LENGTH', 'cm', 'comprimentoCm'],
            'largura_cm'     => ['SELLER_PACKAGE_WIDTH',  'cm', 'larguraCm'],
        ];
        $attrsPacote = [];
        foreach ($mapaPacote as $campo => [$id, $unidade, $chaveMeta]) {
            if (! empty($pacote[$campo])) {
                $attrsPacote[] = ['id' => $id, 'value_name' => "{$pacote[$campo]} {$unidade}"];
                $meta[$chaveMeta] = $pacote['origem'][$campo] ?? 'ia';
            }
        }

        $attributes = array_merge($atributos, $attrsPacote);

        // ML erro 146: atributo usado nas variações não pode ficar também no item.
        if ($variacoes !== []) {
            $nasVariacoes = [];
            foreach ($variacoes as $v) {
                foreach (array_merge($v['attribute_combinations'] ?? [], $v['attributes'] ?? []) as $a) {
                    $nasVariacoes[$a['id']] = true;
                }
            }
            $attributes = array_values(array_filter($attributes, fn ($a) => ! isset($nasVariacoes[$a['id']])));
        }

        // Acima do corte o ME2 exige frete grátis — ligar evita a recusa.
        $freteGratis = $preco !== null && $preco >= self::PRECO_FRETE_GRATIS;
        $shipping    = ['mode' => 'me2', 'local_pick_up' => false, 'free_shipping' => $freteGratis];
        if ($freteGratis) {
            $shipping['free_methods'] = [];
        }

        $payload = [
            'title'              => $titulo,
            'category_id'        => $categoryId,
            'price'              => $preco,
            'currency_id'        => 'BRL',
            // Regra ML: com variações o estoque fica nelas e o raiz vai 0.
            'available_quantity' => $variacoes !== [] ? 0 : $estoque,
            'condition'          => 'new',
            'listing_type_id'    => 'gold_special',
            'attributes'         => $attributes,
            'pictures'           => [],
            'sale_terms'         => [
                ['id' => 'WARRANTY_TYPE', 'value_name' => 'Garantia do vendedor'],
                ['id' => 'WARRANTY_TIME', 'value_name' => $garantia ?? self::GARANTIA_PADRAO],
            ],
            'shipping'           => $shipping,
            'description'        => $descricao,
            'meta_campos'        => $meta,
        ];

        if ($variacoes !== []) {
            $payload['variations'] = $variacoes;
        }

        return $payload;
    }

    // ═══ Apoio ════════════════════════════════════════════════════════════════

    private function linhaCatalogo(string $id, array $a): string
    {
        $obrigatorio = ! empty($a['tags']['required']) ? '*' : '';

        return "{$id}{$obrigatorio} | " . (string) ($a['name'] ?? $id) . ' | ' . $this->formato($a);
    }

    private function formato(array $a): string
    {
        $tipo    = (string) ($a['value_type'] ?? 'string');
        $valores = collect($a['values'] ?? [])->pluck('name')->filter()->values();

        if ($tipo === 'boolean') {
            return 'Sim/Não';
        }

        if ($tipo === 'list') {
            $mais = $valores->count() > self::MAX_OPCOES_PROMPT ? '; …' : '';

            return 'opções: ' . $valores->take(self::MAX_OPCOES_PROMPT)->implode('; ') . $mais;
        }

        if ($tipo === 'number_unit') {
            $unidades = collect($a['allowed_units'] ?? [])->pluck('id')->filter()->implode(', ');

            return 'número + unidade (' . ($unidades !== '' ? $unidades : ($a['default_unit'] ?? 'cm')) . ')';
        }

        if ($tipo === 'number') {
            return 'número';
        }

        return $valores->isNotEmpty()
            ? 'texto (ex.: ' . $valores->take(8)->implode('; ') . ')'
            : 'texto';
    }

    /** @return array{id: mixed, name: mixed}|null */
    private function casarValor(string $texto, array $valores, bool $booleano): ?array
    {
        $alvo = $this->normalizar($booleano ? ($this->booleano($texto) ?? $texto) : $texto);

        foreach ($valores as $v) {
            if (! is_array($v)) {
                continue;
            }

            if ((string) ($v['id'] ?? '') === trim($texto)
                || $this->normalizar((string) ($v['name'] ?? '')) === $alvo) {
                return $v;
            }
        }

        return null;
    }

    private function booleano(string $texto): ?string
    {
        return match ($this->normalizar($texto)) {
            'sim', 's', 'yes', 'true', '1'       => 'Sim',
            'nao', 'n', 'no', 'false', '0'       => 'Não',
            default                              => null,
        };
    }

    private function numero(string $texto): ?string
    {
        if (! preg_match('/^\s*(\d+(?:[.,]\d+)?)(?!\S*\d)/u', $texto, $m)) {
            return null;
        }

        return str_replace(',', '.', $m[1]);
    }

    /** "45 cm" / "45cm" / "45,5 cm" → "45.5 cm"; unidade fora das aceitas descarta. */
    private function numeroComUnidade(string $texto, array $def): ?array
    {
        if (! preg_match('/^\s*(\d+(?:[.,]\d+)?)\s*(\S*)\s*$/u', $texto, $m)) {
            return null;
        }

        $n       = str_replace(',', '.', $m[1]);
        $unidade = trim($m[2]);
        $aceitas = collect($def['allowed_units'] ?? [])->pluck('id')->filter()->map(fn ($u) => (string) $u);

        if ($unidade === '') {
            $unidade = (string) ($def['default_unit'] ?? $aceitas->first() ?? 'cm');

            return ['value_name' => "{$n} {$unidade}"];
        }

        if ($aceitas->isEmpty()) {
            return ['value_name' => "{$n} {$unidade}"];
        }

        $oficial = $aceitas->first(fn ($u) => mb_strtolower($u) === mb_strtolower($unidade));

        return $oficial !== null ? ['value_name' => "{$n} {$oficial}"] : null;
    }

    private function positivo(mixed $v): ?int
    {
        if ($v === null || $v === '' || ! is_numeric(str_replace(',', '.', (string) $v))) {
            return null;
        }

        $n = (int) ceil((float) str_replace(',', '.', (string) $v));

        return $n > 0 ? $n : null;
    }

    private function precoCliente(?array $cliente): ?float
    {
        $p = $cliente['preco_c'] ?? null;

        return is_numeric($p) && (float) $p > 0 ? round((float) $p, 2) : null;
    }

    private function estoqueCliente(?array $cliente): int
    {
        $e = (int) ($cliente['estoque'] ?? 0);

        return $e > 0 ? $e : 1;
    }

    private function cortar(string $texto, int $max): string
    {
        if (mb_strlen($texto) <= $max) {
            return $texto;
        }

        $corte  = mb_substr($texto, 0, $max);
        $espaco = mb_strrpos($corte, ' ');

        return rtrim($espaco !== false && $espaco > $max / 2 ? mb_substr($corte, 0, $espaco) : $corte);
    }

    /** Mesmo EAN-13 do `gerarEan13()` do wizard: 789 + 9 dígitos + verificador. */
    private function gerarEan13(array $existentes): string
    {
        do {
            $base = '789';
            for ($i = 0; $i < 9; $i++) {
                $base .= random_int(0, 9);
            }

            $soma = 0;
            for ($i = 0; $i < 12; $i++) {
                $soma += (int) $base[$i] * (($i + 1) % 2 === 0 ? 3 : 1);
            }

            $ean = $base . ((10 - $soma % 10) % 10);
        } while (in_array($ean, $existentes, true));

        return $ean;
    }

    private function categoriaSegura(string $categoryId): array
    {
        try {
            return $this->meta->categoria($categoryId);
        } catch (\Throwable $e) {
            Log::warning("[IA] Categoria {$categoryId} não carregou: {$e->getMessage()}");

            return [];
        }
    }

    private function atributosSeguros(string $categoryId): array
    {
        try {
            return array_values(array_filter($this->meta->atributos($categoryId), 'is_array'));
        } catch (\Throwable $e) {
            Log::warning("[IA] Atributos da categoria {$categoryId} não carregaram: {$e->getMessage()}");

            return [];
        }
    }

    /** Minúsculas, sem acento, espaços simples — "Aço  Inox" casa com "aço inox". */
    private function normalizar(string $s): string
    {
        $s = mb_strtolower(trim($s));
        $s = strtr($s, [
            'á' => 'a', 'à' => 'a', 'ã' => 'a', 'â' => 'a', 'ä' => 'a',
            'é' => 'e', 'ê' => 'e', 'è' => 'e', 'ë' => 'e',
            'í' => 'i', 'î' => 'i', 'ì' => 'i', 'ï' => 'i',
            'ó' => 'o', 'õ' => 'o', 'ô' => 'o', 'ò' => 'o', 'ö' => 'o',
            'ú' => 'u', 'û' => 'u', 'ù' => 'u', 'ü' => 'u',
            'ç' => 'c', 'ñ' => 'n',
        ]);

        return (string) preg_replace('/\s+/u', ' ', $s);
    }
}
