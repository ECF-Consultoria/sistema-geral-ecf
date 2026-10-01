<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\MercadoLivreService;
use App\Services\MlColetaService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Fase 0 do Publicador — sondagem da API real do Mercado Livre.
 *
 * Salva as respostas como fixtures (`tests/fixtures-ml/sondagem/`) para que as
 * regras sejam escritas sobre o que a API DEVOLVE, e não sobre o que a
 * documentação diz (`.planning/publicador-ml-spec/12-hipoteses-e-pendencias.md`).
 *
 * Duas partes:
 * - `--publico`: dados públicos de categoria com o APP token (roda em qualquer
 *   máquina com as credenciais do aplicativo).
 * - `--empresa=ID`: dados da CONTA do vendedor (tags, envios, tipos de anúncio,
 *   condicionais e `/items/validate`). Os tokens são cifrados com a APP_KEY de
 *   produção, então esta parte só roda lá.
 *
 * ### O que este comando NUNCA faz
 * Nunca cria nada no ML: `POST /items` é recusado antes de sair (`chamar()`),
 * e `/items/validate` é o único POST de item permitido. Nunca grava token: o
 * token só vai no cabeçalho, e cabeçalho não é salvo. Dados pessoais da conta
 * (nome, e-mail, documento, endereço) são removidos antes de gravar
 * (`sanitizar()`), porque as fixtures vão para o git.
 *
 * Arquivo autocontido de propósito: na produção ele roda sem deploy, carregado
 * por um runner que faz o boot do app de lá (ver `.planning/publicador-ml-spec/`).
 */
class PublicadorSondar extends Command
{
    protected $signature = 'publicador:sondar
        {--publico : Dados públicos de categoria (app token)}
        {--empresa= : companies.id da conta de teste (token do vendedor)}
        {--categorias= : IDs separados por vírgula; sem isso, sai do domain_discovery}
        {--saida= : Pasta de saída (padrão tests/fixtures-ml/sondagem)}';

    protected $description = 'Fase 0 do Publicador: grava respostas reais da API do ML como fixtures (só leitura e /items/validate)';

    private const API = 'https://api.mercadolibre.com';

    /** As buscas que escolhem as categorias do briefing (`12` §Fase 0). */
    public const BUSCAS = [
        'cadeira'   => 'cadeira de escritorio executiva giratoria',
        'furadeira' => 'furadeira de impacto eletrica 127v',
        'camiseta'  => 'camiseta basica masculina algodao',
        'autopeca'  => 'pastilha de freio dianteira',
    ];

    /** Imagem pública que o `validate` já aceitou como `source` (sonda de 10/07/2026). */
    private const FOTO = 'https://upload.wikimedia.org/wikipedia/commons/3/3f/JPEG_example_flower.jpg';

    /** Chaves com dado pessoal — removidas em qualquer profundidade antes de gravar. */
    public const CHAVES_PESSOAIS = [
        'first_name', 'last_name', 'email', 'secure_email', 'phone', 'alternative_phone',
        'identification', 'address', 'street_name', 'street_number', 'zip_code',
        'doc_number', 'doc_type', 'bill_data', 'billing_info', 'company', 'credit', 'thumbnail',
    ];

    /** O que fica de `GET /users/me`: só o que o Publicador usa (`02` E0). */
    public const CAMPOS_USUARIO = [
        'id', 'nickname', 'site_id', 'country_id', 'user_type', 'tags', 'status',
        'seller_reputation', 'seller_experience', 'registration_date', 'logo',
    ];

    private string $saida;

    private ?string $tokenVendedor = null;

    private int $gravados = 0;

    public function handle(MlColetaService $coleta, MercadoLivreService $ml): int
    {
        $this->saida = rtrim((string) ($this->option('saida') ?: base_path('tests/fixtures-ml/sondagem')), '/\\');

        if (! $this->option('publico') && ! $this->option('empresa')) {
            $this->error('Informe --publico e/ou --empresa=ID.');

            return self::FAILURE;
        }

        $appToken = $coleta->getAppToken();
        $categorias = $this->categorias($appToken);
        $this->line('Categorias: '.implode(', ', array_map(fn ($c) => "{$c} ({$this->nomes[$c]})", $categorias)));

        if ($this->option('publico')) {
            $this->publico($appToken, $categorias);
        }

        if ($this->option('empresa')) {
            $empresa = Company::find((int) $this->option('empresa'));
            $token = $empresa ? $ml->ensureValidToken($empresa) : null;
            if (! $token) {
                $this->error('Empresa sem conta do ML conectada (ou token revogado) — nada da parte da conta foi sondado.');

                return self::FAILURE;
            }
            $this->tokenVendedor = $token->access_token;
            $this->conta((string) $token->ml_user_id, $appToken, $categorias);
        }

        $this->info("{$this->gravados} arquivo(s) em {$this->saida}");

        return self::SUCCESS;
    }

    // ═══ Categorias ═════════════════════════════════════════════════════════

    /** @var array<string, string> id → rótulo da busca */
    private array $nomes = [];

    /** @return array<int, string> */
    private function categorias(string $appToken): array
    {
        if ($ids = array_filter(array_map('trim', explode(',', (string) $this->option('categorias'))))) {
            foreach ($ids as $id) {
                $this->nomes[$id] = 'informada';
            }

            return array_values($ids);
        }

        $escolhidas = [];
        foreach (self::BUSCAS as $rotulo => $q) {
            $r = $this->chamar('GET', '/sites/MLB/domain_discovery/search', $appToken, ['q' => $q, 'limit' => 8]);
            $this->gravar("publico/domain_discovery/{$rotulo}.json", $r);
            $id = (string) data_get($r, 'resposta.0.category_id', '');
            if ($id !== '' && ! isset($this->nomes[$id])) {
                $escolhidas[] = $id;
                $this->nomes[$id] = $rotulo;
            }
        }

        return $escolhidas;
    }

    // ═══ Parte pública (app token) ══════════════════════════════════════════

    /** @param array<int, string> $categorias */
    private function publico(string $appToken, array $categorias): void
    {
        foreach ($categorias as $id) {
            $base = "publico/categorias/{$id}";
            $cat = $this->chamar('GET', "/categories/{$id}", $appToken);
            $this->gravar("{$base}/categoria.json", $cat);
            $this->gravar("{$base}/atributos.json", $this->chamar('GET', "/categories/{$id}/attributes", $appToken));
            $this->gravar("{$base}/technical_specs_input.json", $this->chamar('GET', "/categories/{$id}/technical_specs/input", $appToken));
            $this->gravar("{$base}/sale_terms.json", $this->chamar('GET', "/categories/{$id}/sale_terms", $appToken));

            // H-16: o domínio de uma categoria escolhida à mão.
            $dominio = data_get($cat, 'resposta.settings.catalog_domain');
            if (is_string($dominio) && $dominio !== '') {
                $this->gravar("{$base}/catalog_domain.json", $this->chamar('GET', "/catalog_domains/{$dominio}", $appToken));
            }

            // H-03: o pai da folha, para conferir que ele não é publicável.
            $caminho = (array) data_get($cat, 'resposta.path_from_root', []);
            if (count($caminho) >= 2) {
                $pai = (string) $caminho[count($caminho) - 2]['id'];
                $this->gravar("{$base}/pai_{$pai}.json", $this->chamar('GET', "/categories/{$pai}", $appToken));
            }

            // Tarifa (H-14), com e sem a logística — a doc diz que sem ela a tarifa fixa sai errada.
            foreach (['gold_special', 'gold_pro'] as $tipo) {
                $q = ['price' => 150, 'category_id' => $id, 'listing_type_id' => $tipo, 'currency_id' => 'BRL'];
                $this->gravar("{$base}/listing_prices_{$tipo}.json", $this->chamar('GET', '/sites/MLB/listing_prices', $appToken, $q));
                $this->gravar("{$base}/listing_prices_{$tipo}_me2_drop_off.json", $this->chamar('GET', '/sites/MLB/listing_prices', $appToken, [...$q, 'logistic_type' => 'drop_off', 'shipping_mode' => 'me2']));
            }
        }
    }

    // ═══ Parte da conta (token do vendedor) ═════════════════════════════════

    /** @param array<int, string> $categorias */
    private function conta(string $sellerId, string $appToken, array $categorias): void
    {
        $t = $this->tokenVendedor;

        $usuario = $this->chamar('GET', '/users/me', $t);
        if (is_array($usuario['resposta'])) {
            $usuario['resposta'] = array_intersect_key($usuario['resposta'], array_flip(self::CAMPOS_USUARIO));
        }
        $this->gravar('conta/usuario.json', $usuario);
        $this->line('Tags da conta: '.implode(', ', (array) data_get($usuario, 'resposta.tags', [])));

        $this->gravar('conta/shipping_preferences.json', $this->chamar('GET', "/users/{$sellerId}/shipping_preferences", $t));

        // H-19: busca por SKU (um que não existe — só a forma da resposta importa).
        $this->gravar('conta/items_search_seller_sku.json', $this->chamar('GET', "/users/{$sellerId}/items/search", $t, ['seller_sku' => 'SONDA-ECF-INEXISTENTE']));

        // H-10: frete grátis obrigatório — em que preço a API começa a exigir.
        foreach ([50, 78.99, 79, 150] as $preco) {
            $q = ['item_price' => $preco, 'listing_type_id' => 'gold_special', 'mode' => 'me2', 'condition' => 'new',
                'logistic_type' => 'drop_off', 'dimensions' => '15x15x20,500', 'verbose' => 'true'];
            $this->gravar('conta/shipping_options_free_'.str_replace('.', '_', (string) $preco).'.json', $this->chamar('GET', "/users/{$sellerId}/shipping_options/free", $t, $q));
        }

        foreach ($categorias as $id) {
            $attrs = (array) ($this->chamar('GET', "/categories/{$id}/attributes", $appToken)['resposta'] ?? []);
            $cat = (array) ($this->chamar('GET', "/categories/{$id}", $appToken)['resposta'] ?? []);
            $base = "conta/categorias/{$id}";

            $this->gravar("{$base}/available_listing_types.json", $this->chamar('GET', "/users/{$sellerId}/available_listing_types", $t, ['category_id' => $id]));

            foreach ($this->cenarios($id, $cat, $attrs) as $nome => $payload) {
                // H-08: condicionais com o payload simples e com o de variações.
                if (in_array($nome, ['base_legado', 'variacoes_legado_soma'], true)) {
                    $this->gravar("{$base}/conditional_{$nome}.json", $this->chamar('POST', "/categories/{$id}/attributes/conditional", $t, [], $payload));
                }
                $this->gravar("{$base}/validate_{$nome}.json", $this->chamar('POST', '/items/validate', $t, [], $payload));
            }
        }
    }

    /**
     * Payloads deliberadamente variados para o `/items/validate` — cada um
     * isola UMA pergunta do `12`. O base usa a receita que já passou em 10/07
     * (atributos obrigatórios com o 1º valor da lista, embalagem, me2 drop_off,
     * foto por URL externa).
     *
     * @return array<string, array>
     */
    public function cenarios(string $categoria, array $cat, array $attrs): array
    {
        $principais = [];
        $eixos = [];
        foreach ($attrs as $a) {
            $id = (string) ($a['id'] ?? '');
            $tags = (array) ($a['tags'] ?? []);
            if ($id === '' || ! empty($tags['read_only']) || ! empty($tags['fixed']) || ! empty($tags['inferred'])) {
                continue;
            }
            if (! empty($tags['allow_variations']) && ! empty($a['values'])) {
                $eixos[$id] = $a;
            }
            if (empty($tags['required']) || $id === 'GTIN' || str_contains($id, 'GRID')) {
                continue;
            }
            $principais[$id] = $this->valorDeExemplo($a);
        }

        $embalagem = ['SELLER_PACKAGE_WEIGHT' => '500 g', 'SELLER_PACKAGE_HEIGHT' => '15 cm', 'SELLER_PACKAGE_LENGTH' => '20 cm', 'SELLER_PACKAGE_WIDTH' => '15 cm'];
        $titulo = mb_substr('Teste Sonda ECF '.((string) ($cat['name'] ?? 'Produto')).' Modelo Basico', 0, 60);

        $comAtributos = fn (array $extra = [], array $sem = []) => array_values(array_map(
            fn ($id, $v) => ['id' => $id, ...$v],
            array_keys($m = array_diff_key([...$principais, ...$extra], array_flip($sem))),
            $m,
        ));

        $base = [
            'title' => $titulo,
            'category_id' => $categoria,
            'price' => 150.00,
            'currency_id' => 'BRL',
            'available_quantity' => 1,
            'buying_mode' => 'buy_it_now',
            'condition' => 'new',
            'listing_type_id' => 'gold_special',
            'pictures' => [['source' => self::FOTO]],
            'attributes' => $comAtributos([...array_map(fn ($v) => ['value_name' => $v], $embalagem), 'SELLER_SKU' => ['value_name' => 'SONDA-ECF-001']]),
            'sale_terms' => [['id' => 'WARRANTY_TYPE', 'value_name' => 'Garantia do vendedor'], ['id' => 'WARRANTY_TIME', 'value_name' => '30 dias']],
            'shipping' => ['mode' => 'me2', 'local_pick_up' => false, 'free_shipping' => false, 'logistic_type' => 'drop_off'],
        ];
        $semTitulo = array_diff_key($base, ['title' => 1]);

        $c = [
            'base_legado'        => $base,                                                       // H-01/H-02
            'base_up'            => [...$semTitulo, 'family_name' => $titulo],                  // H-02
            'base_title_e_family' => [...$base, 'family_name' => $titulo],                       // H-02
            'sem_fotos'          => [...$base, 'pictures' => []],                                 // 173
            'sem_embalagem'      => [...$base, 'attributes' => $comAtributos(['SELLER_SKU' => ['value_name' => 'SONDA-ECF-001']])],  // H-05
            'embalagem_sem_unidade' => [...$base, 'attributes' => $comAtributos([...array_map(fn ($v) => ['value_name' => (string) (int) $v], $embalagem), 'SELLER_SKU' => ['value_name' => 'SONDA-ECF-001']])], // H-05
        ];

        // H-06: N/A num obrigatório que não seja marca nem modelo.
        $alvoNa = array_values(array_diff(array_keys($principais), ['BRAND', 'MODEL']))[0] ?? null;
        if ($alvoNa !== null) {
            $c['na_em_obrigatorio'] = [...$base, 'attributes' => $comAtributos([$alvoNa => ['value_id' => '-1', 'value_name' => null], ...array_map(fn ($v) => ['value_name' => $v], $embalagem)])];
        }

        // H-03: o pai da folha.
        $caminho = (array) ($cat['path_from_root'] ?? []);
        if (count($caminho) >= 2) {
            $c['categoria_nao_folha'] = [...$base, 'category_id' => (string) $caminho[count($caminho) - 2]['id']];
        }

        // H-04: recondicionado via ITEM_CONDITION, quando a categoria tem o atributo.
        $itemCondicao = collect($attrs)->firstWhere('id', 'ITEM_CONDITION');
        $recond = $itemCondicao ? collect((array) ($itemCondicao['values'] ?? []))->first(fn ($v) => str_contains(mb_strtolower((string) ($v['name'] ?? '')), 'recondicionad')) : null;
        if ($recond) {
            $c['recondicionado'] = [...$base, 'attributes' => [...$base['attributes'], ['id' => 'ITEM_CONDITION', 'value_id' => (string) $recond['id']]],
                'sale_terms' => [['id' => 'WARRANTY_TYPE', 'value_name' => 'Garantia do vendedor'], ['id' => 'WARRANTY_TIME', 'value_name' => '90 dias']]];
        }

        // Variações (H-26, RN-45, RN-03, H-17): 2 valores num eixo. Voltagem
        // primeiro (o eixo SEM defines_picture do TC-02), depois Cor.
        $prioridade = fn (string $id) => ['VOLTAGE' => 0, 'COLOR' => 1][$id] ?? 9;
        uksort($eixos, fn ($a, $b) => $prioridade($a) <=> $prioridade($b));
        $listaEixos = array_values($eixos);
        if ($listaEixos) {
            $e1 = $listaEixos[0];
            $valores = array_slice((array) $e1['values'], 0, 2);
            $semEixo = array_values(array_filter($base['attributes'], fn ($a) => ! isset($eixos[$a['id']]) && $a['id'] !== 'SELLER_SKU'));
            $variacao = fn (array $v, int $i, float $preco, array $extra = []) => [
                'attribute_combinations' => [['id' => $e1['id'], 'value_id' => (string) $v['id']], ...$extra],
                'price' => $preco,
                'available_quantity' => 2,
                'picture_ids' => [self::FOTO],
                'attributes' => [['id' => 'SELLER_SKU', 'value_name' => 'SONDA-ECF-V'.($i + 1)]],
            ];
            $vars = array_map(fn ($v, $i) => $variacao($v, $i, 150.00), $valores, array_keys($valores));
            $comVars = [...$base, 'attributes' => $semEixo, 'variations' => $vars];

            $c['variacoes_legado_soma'] = [...$comVars, 'available_quantity' => 2 * count($vars)];
            $c['variacoes_legado_zero'] = [...$comVars, 'available_quantity' => 0];
            $c['variacoes_legado_sem_qtd'] = array_diff_key($comVars, ['available_quantity' => 1]);
            $c['variacoes_precos_diferentes'] = [...$comVars, 'available_quantity' => 2 * count($vars),
                'variations' => array_map(fn ($v, $i) => $variacao($v, $i, 150.00 + 15 * $i), $valores, array_keys($valores))];
            $c['variacoes_com_family_name'] = [...array_diff_key($comVars, ['title' => 1]), 'family_name' => $titulo, 'available_quantity' => 2 * count($vars)];

            // H-17 / eixo customizado: 1º eixo + um eixo livre `{name, value_name}`.
            $c['variacoes_eixo_customizado'] = [...$comVars, 'available_quantity' => 2 * count($vars),
                'variations' => array_map(fn ($v, $i) => $variacao($v, $i, 150.00, [['name' => 'Estampa', 'value_name' => 'Lisa']]), $valores, array_keys($valores))];

            // Dois eixos da categoria (ex.: Cor × Material do estofado — o "Azul / Couro" do print), 2 × 2.
            if (isset($listaEixos[1])) {
                $e2 = $listaEixos[1];
                $vars2 = [];
                foreach ($valores as $v) {
                    foreach (array_slice((array) $e2['values'], 0, 2) as $w) {
                        $vars2[] = $variacao($v, count($vars2), 150.00, [['id' => $e2['id'], 'value_id' => (string) $w['id']]]);
                    }
                }
                $semDoisEixos = array_values(array_filter($semEixo, fn ($a) => $a['id'] !== $e2['id']));
                $c['variacoes_dois_eixos'] = [...$base, 'attributes' => $semDoisEixos, 'variations' => $vars2, 'available_quantity' => 2 * count($vars2)];
            }
        }

        return $c;
    }

    /** Um valor plausível para um atributo obrigatório, no formato que o ML pede. */
    private function valorDeExemplo(array $a): array
    {
        $id = (string) $a['id'];
        $tipo = (string) ($a['value_type'] ?? 'string');

        // Com lista, sempre o id: é o formato que não depende de grafia.
        if (! empty($a['values'])) {
            return ['value_id' => (string) $a['values'][0]['id']];
        }
        if ($tipo === 'number_unit') {
            $unidade = (string) ($a['default_unit'] ?? data_get($a, 'allowed_units.0.id', 'cm'));

            return ['value_name' => "10 {$unidade}"];
        }
        if ($tipo === 'number') {
            return ['value_name' => '10'];
        }

        return ['value_name' => match ($id) {
            'BRAND' => 'Genérica',
            'MODEL' => 'Sonda',
            default => 'Teste',
        }];
    }

    // ═══ HTTP e gravação ════════════════════════════════════════════════════

    /**
     * Uma chamada à API, devolvida como envelope `{requisicao, status, resposta}`.
     * Recusa `POST /items` (criaria um anúncio real) e qualquer verbo de escrita.
     *
     * @return array{requisicao: array, status: int, resposta: mixed, capturado_em: string}
     */
    public function chamar(string $metodo, string $caminho, string $token, array $query = [], ?array $corpo = null): array
    {
        self::garantirSomenteLeitura($metodo, $caminho);

        try {
            $req = Http::withToken($token)->acceptJson()->timeout(30);
            $resp = $metodo === 'GET'
                ? $req->get(self::API.$caminho, $query)
                : $req->post(self::API.$caminho.($query ? '?'.http_build_query($query) : ''), $corpo ?? []);
            $status = $resp->status();
            $corpoResp = $resp->json() ?? ($resp->body() === '' ? null : $resp->body());
        } catch (\Throwable $e) {
            $status = 0;
            $corpoResp = ['erro_de_rede' => $e->getMessage()];
        }

        $this->line(sprintf('  %s %s → %d', $metodo, $caminho, $status));

        return [
            'requisicao'   => ['metodo' => $metodo, 'caminho' => $caminho, 'query' => $query ?: null, 'corpo' => $corpo],
            'status'       => $status,
            'resposta'     => $corpoResp,
            'capturado_em' => now()->toIso8601String(),
        ];
    }

    /** O único POST de item permitido é o `/items/validate`; o resto da escrita, nunca. */
    public static function garantirSomenteLeitura(string $metodo, string $caminho): void
    {
        $caminho = '/'.trim(parse_url($caminho, PHP_URL_PATH) ?? '', '/');
        $permitidoPost = $caminho === '/items/validate' || preg_match('#^/categories/[A-Z0-9]+/attributes/conditional$#', $caminho);

        if ($metodo !== 'GET' && ! ($metodo === 'POST' && $permitidoPost)) {
            throw new \LogicException("A sondagem não escreve no ML: {$metodo} {$caminho} recusado.");
        }
    }

    /** Remove dado pessoal em qualquer profundidade — as fixtures vão para o git. */
    public static function sanitizar(mixed $dado): mixed
    {
        if (! is_array($dado)) {
            return $dado;
        }

        $saida = [];
        foreach ($dado as $chave => $valor) {
            if (is_string($chave) && in_array($chave, self::CHAVES_PESSOAIS, true)) {
                continue;
            }
            $saida[$chave] = self::sanitizar($valor);
        }

        return $saida;
    }

    private function gravar(string $relativo, array $envelope): void
    {
        $envelope['resposta'] = self::sanitizar($envelope['resposta']);
        $arquivo = $this->saida.'/'.$relativo;
        if (! is_dir(dirname($arquivo))) {
            mkdir(dirname($arquivo), 0775, true);
        }
        file_put_contents($arquivo, json_encode($envelope, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n");
        $this->gravados++;
    }
}
