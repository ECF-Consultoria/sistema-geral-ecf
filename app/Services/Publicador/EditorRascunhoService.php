<?php

namespace App\Services\Publicador;

use App\Models\EstruturaAnuncio;
use App\Models\EstruturaPublicacao;
use App\Models\PubImagem;
use App\Models\PubPublicacao;
use App\Models\PubProduto;
use App\Models\PubPublicacaoItem;
use App\Models\PubRascunho;
use App\Models\PubValidacao;
use App\Services\Portal\Estrutura\EstruturaConjunto;
use App\Support\Publicador\Erros\MapeadorErrosMl;
use App\Support\Publicador\Imagem\OpcoesImagem;
use App\Support\Publicador\Imagem\ResolvedorGruposImagem;
use App\Support\Publicador\Payload\Alvo;
use App\Support\Publicador\Payload\MontadorDePlano;
use App\Support\Publicador\RascunhoSnapshot;
use App\Support\Publicador\RegraViolada;
use App\Support\Publicador\Schema\AtributoClassificado;
use App\Support\Publicador\Schema\ClassificadorAtributos;
use App\Support\Publicador\Schema\ContextoClassificacao;
use App\Support\Publicador\Schema\MigradorDeCategoria;
use App\Support\Publicador\Schema\SchemaClassificado;
use App\Support\Publicador\Validacao\ContextoValidacao;
use App\Support\Publicador\Validacao\Problema;
use App\Support\Publicador\Validacao\SimuladorVoceRecebe;
use App\Support\Publicador\Validacao\ValidadorRascunho;
use App\Support\Publicador\Variacao\ChaveCanonica;
use App\Support\Publicador\Variacao\Eixo;
use App\Support\Publicador\Variacao\RegeneradorVariantes;
use App\Support\Publicador\Variacao\ValorEixo;
use App\Support\Publicador\Variacao\Variante;
use Illuminate\Support\Facades\DB;

/**
 * A tela do Publicador no Anunciar (F1.11): abre o rascunho da oferta, grava
 * cada parte que a pessoa edita e devolve o ESTADO inteiro — rascunho, schema
 * classificado, conta, efetivos, problemas da L1/L2, última conferência e
 * última publicação. As regras são do núcleo puro; aqui só se junta e se grava.
 *
 * Toda gravação passa pelo `RascunhoRepository` e termina em `tocar()`: a
 * conferência anterior deixa de valer (`08` §1).
 */
class EditorRascunhoService
{
    /** A conta é relida quando a leitura guardada passa disto (minutos). */
    private const CONTA_VALE_MINUTOS = 10;

    public function __construct(
        private RascunhoRepository $repo,
        private ContaMlService $contas,
        private CategorySchemaRepository $schemas,
        private ClienteMlPublicador $cliente,
        private DadosEfetivosService $efetivos,
        private MigracaoAnunciarAntigo $migracao,
        private PublicacaoService $publicacoes,
        private PortalProdutoLeitor $leitor,
        private ExplicacaoDeAtributos $explicacoes,
    ) {}

    // ═══ Abrir ═══════════════════════════════════════════════════════════════

    /** O rascunho do produto: o que já existe, o migrado do Anunciar antigo (só com oferta), ou um novo com os tipos que faltam. */
    public function abrir(PubProduto $produto): PubRascunho
    {
        $r = $this->rascunhoDoProduto($produto);

        $this->lerContaSeVencida($r);

        return $r->fresh();
    }

    /**
     * O mesmo que `abrir`, SEM a leitura da conta no ML (nenhum HTTP de conta). Quem só grava
     * rascunho (o Sincronizar do Portal, D-10) usa este método, nunca `abrir`.
     *
     * @param  bool  $comSku  false = a variante única nasce sem SELLER_SKU (produto agrupado em cores:
     *                        o regenerador copiaria o SKU do produto para todas as variantes)
     */
    public function rascunhoDoProduto(PubProduto $produto, bool $comSku = true): PubRascunho
    {
        $r = PubRascunho::where('produto_id', $produto->id)->first();
        if (! $r && $produto->oferta_id !== null && ($antiga = EstruturaPublicacao::where('oferta_id', $produto->oferta_id)->first())) {
            $r = $this->migracao->aplicar($antiga);
        }
        if (! $r) {
            $mlbs = $this->mlbsDaRegua($produto);
            $r = $this->repo->criar($produto, array_map(
                fn ($tipo, $lt) => new Alvo($lt, null, $mlbs[$tipo] === null),
                array_keys(EstruturaPublicacao::LISTING_TYPES), EstruturaPublicacao::LISTING_TYPES,
            ), ['origem' => 'publicador']);
            $atributos = $comSku ? ['SELLER_SKU' => ['value_name' => $produto->skuExibido()]] : [];
            $this->repo->gravarVariacao($r, [], [new Variante(ChaveCanonica::UNICA, [], dados: ['atributos' => $atributos])]);
        }

        // Migrado com categoria e sem hash: grava o hash do schema de hoje.
        if ($r->categoria_id && ! $r->schema_hash) {
            try {
                $this->repo->gravarCategoria($r, $this->schemas->obter($r->categoria_id));
            } catch (RegraViolada) {
                // Sem o ML agora: o estado mostra a categoria sem formulário, e tenta de novo depois.
            }
        }

        return $r;
    }

    // ═══ Gravar ══════════════════════════════════════════════════════════════

    /**
     * O que a tela manda por parte. Chave ausente = não mexe.
     *
     * @param  array{atributos?: array, alvos?: list<array{listing_type_id: string, titulo?: ?string, ativo?: bool}>, condicao?: string, descricao?: ?string, envio?: array, garantia?: ?array, fotos_por_variante?: bool, incluir_geral?: bool}  $d
     */
    public function salvar(PubRascunho $r, array $d): void
    {
        DB::transaction(function () use ($r, $d) {
            // WR-B02: a mesma trava da IA e do `iniciar` — um escreve de cada vez.
            $this->repo->travar($r);
            if (array_key_exists('atributos', $d)) {
                $this->repo->gravarAtributos($r, array_filter((array) $d['atributos'], fn ($v) => is_array($v)));
            }
            if (array_key_exists('alvos', $d)) {
                $atuais = collect($this->repo->snapshot($r)->alvos)->keyBy('listingTypeId');
                $this->repo->gravarAlvos($r, array_values(array_map(function ($a) use ($atuais) {
                    $atual = $atuais[$a['listing_type_id']] ?? null;
                    $titulo = array_key_exists('titulo', $a) ? (trim((string) $a['titulo']) === '' ? null : mb_substr(trim((string) $a['titulo']), 0, 255)) : $atual?->titulo;

                    return new Alvo((string) $a['listing_type_id'], $titulo, (bool) ($a['ativo'] ?? $atual?->ativo ?? true));
                }, (array) $d['alvos'])));
            }

            $campos = array_intersect_key($d, array_flip(['condicao', 'descricao', 'envio', 'garantia']));
            if (array_key_exists('fotos_por_variante', $d)) {
                $campos['fotos_por_variante'] = (bool) $d['fotos_por_variante'];
            }
            if (array_key_exists('incluir_geral', $d)) {
                $campos['incluir_geral_nas_variantes'] = (bool) $d['incluir_geral'];
            }
            if ($campos) {
                $r->update($campos);
            }

            $this->repo->tocar($r);
        });
    }

    /**
     * Troca de categoria (`03` §8): o que vale na nova é levado; eixo que não
     * existe lá cai; o resto vira "revisar". Nada de `if categoria ==`.
     *
     * @return array{descartados: list<array>, eixos_removidos: list<array>}
     */
    public function trocarCategoria(PubRascunho $r, string $categoriaId): array
    {
        // Os schemas são lidos antes da trava (podem ir ao ML); dentro dela vem o guardado.
        $nova = $this->schemas->obter($categoriaId);
        if ($r->categoria_id && $r->categoria_id !== $categoriaId) {
            $this->schemas->obter($r->categoria_id);
        }

        return DB::transaction(function () use ($r, $nova, $categoriaId) {
            // WR-B02: trava e relê — a categoria e os atributos de agora, não os de antes da leitura do ML.
            $this->repo->travar($r);
            $r->refresh();
            $s = $this->repo->snapshot($r);
            $novoSchema = (new ClassificadorAtributos())->classificar($nova, new ContextoClassificacao($s->condicao, $this->idsDeEixo($s->eixos)));

            $resultado = ['descartados' => [], 'eixos_removidos' => []];
            $atributos = $s->atributos;
            $eixos = $s->eixos;
            if ($r->categoria_id && $r->categoria_id !== $categoriaId) {
                $anterior = (new ClassificadorAtributos())->classificar($this->schemas->obter($r->categoria_id), new ContextoClassificacao($s->condicao, $this->idsDeEixo($s->eixos)));
                $m = MigradorDeCategoria::migrar($anterior, $novoSchema, $s->atributos, $s->eixos);
                $atributos = $m->atributos;
                $eixos = $m->eixos;
                $resultado = ['descartados' => $m->descartados, 'eixos_removidos' => $m->eixosRemovidos];
            }

            $this->repo->gravarCategoria($r, $nova);
            $this->repo->gravarAtributos($r, $atributos);
            if ($eixos != $s->eixos) {
                $this->repo->gravarVariacao($r, $eixos, RegeneradorVariantes::regenerar($s->variantes, $eixos)->variantes);
            }

            return $resultado;
        });
    }

    /**
     * Os eixos como a tela os deixou: cada um `{chave, nome, defines_picture, valores: [{id, nome}]}`.
     * As variantes são regeneradas e os dados passam adiante (`05` §4).
     *
     * @return array{conflitos: array<string, list<string>>, descartadas: list<string>}
     */
    public function salvarEixos(PubRascunho $r, array $eixos): array
    {
        $limite = (int) config('publicador.max_eixos', 3);
        if (count($eixos) > $limite) {
            throw new RegraViolada('V-VAR-03', "No máximo {$limite} variações por anúncio.");
        }

        $novos = array_values(array_map(fn ($e, $i) => new Eixo(
            (string) $e['chave'],
            (string) ($e['nome'] ?? $e['chave']),
            $i,
            (bool) ($e['defines_picture'] ?? false),
            array_values(array_map(fn ($v, $j) => new ValorEixo(isset($v['id']) && $v['id'] !== '' ? (string) $v['id'] : null, trim((string) $v['nome']), $j),
                array_values(array_filter((array) ($e['valores'] ?? []), fn ($v) => trim((string) ($v['nome'] ?? '')) !== '')), array_keys(array_values(array_filter((array) ($e['valores'] ?? []), fn ($v) => trim((string) ($v['nome'] ?? '')) !== ''))))),
        ), $eixos, array_keys($eixos)));

        return DB::transaction(function () use ($r, $novos) {
            // WR-B02: trava antes de ler as variantes — os dados que passam adiante são os de agora.
            $this->repo->travar($r);
            $regen = RegeneradorVariantes::regenerar($this->repo->snapshot($r)->variantes, $novos);
            $this->repo->gravarVariacao($r, $novos, $regen->variantes);
            $this->repo->tocar($r);

            return ['conflitos' => $regen->conflitos, 'descartadas' => $regen->descartadas];
        });
    }

    /**
     * Os dados por variante (E5): `{chave: {ativa?, estoque?, estoque_depositos?, precos?, atributos?}}`.
     * Com estoque por depósito, o estoque do item é a soma (vai no payload — N-19).
     */
    public function salvarVariantes(PubRascunho $r, array $porChave): void
    {
        DB::transaction(function () use ($r, $porChave) {
            // WR-B02: trava antes de ler — o que não veio em `porChave` é regravado como está AGORA.
            $this->repo->travar($r);
            $s = $this->repo->snapshot($r);
            $variantes = array_map(function (Variante $v) use ($porChave) {
                $novo = $porChave[$v->chave] ?? null;
                if (! is_array($novo)) {
                    return $v;
                }
                $dados = $v->dados;
                foreach (['estoque', 'precos', 'atributos', 'estoque_depositos'] as $campo) {
                    if (array_key_exists($campo, $novo)) {
                        $dados[$campo] = $novo[$campo];
                    }
                }
                if (is_array($dados['estoque_depositos'] ?? null) && $dados['estoque_depositos'] !== []) {
                    $dados['estoque'] = array_sum(array_map('intval', $dados['estoque_depositos']));
                }
                $v = $v->comDados($dados);

                return array_key_exists('ativa', $novo) && ! $v->publicada ? $v->comAtiva((bool) $novo['ativa']) : $v;
            }, $s->variantes);

            $this->repo->gravarVariacao($r, $s->eixos, $variantes);
            $this->repo->tocar($r);
        });
    }

    /** @param list<array{imagem: int|string, grupo: string, posicao: int}> $atribuicoes */
    public function atribuirFotos(PubRascunho $r, array $atribuicoes): void
    {
        $this->repo->gravarAtribuicoes($r, $atribuicoes);
        $this->repo->tocar($r);
    }

    /**
     * Põe UMA foto no FIM do grupo pedido — upload manual (`foto()`) e aprovação
     * de criativo gerado por IA (165-03) usam o MESMO caminho, por isso ele vive
     * aqui e não num private do controller.
     *
     * Fase 165 (T-165-09, WR-B02, learnings publicador-ml.md §9): sem a trava de
     * linha, duas aprovações seguidas, ou uma aprovação e um arrasto de foto no
     * editor, liam o snapshot ao mesmo tempo e regravavam a lista INTEIRA de
     * atribuições — a última escrita vencia e apagava a outra (RESEARCH
     * Armadilha 4). Por isso: travar PRIMEIRO, ler o snapshot DEPOIS, na MESMA
     * transação (molde de `salvar()`/`salvarEixos()` desta classe).
     *
     * O envio ao Mercado Livre (`ImagemAssetService::receber`/`enviarAoMl`, que
     * pode fazer HTTP) fica de propósito FORA desta transação — quem chama
     * decide a ordem (D-04: aqui só se atribui a foto já guardada).
     */
    public function colocarFotoNoGrupo(PubRascunho $r, PubImagem $imagem, string $grupo): void
    {
        DB::transaction(function () use ($r, $imagem, $grupo) {
            $this->repo->travar($r);
            $atuais = $this->repo->snapshot($r)->imagens;
            $doGrupo = array_values(array_filter($atuais, fn ($a) => $a['grupo'] === $grupo));
            if (in_array((string) $imagem->id, array_map('strval', array_column($doGrupo, 'imagem')), true)) {
                // Já está no grupo: nada a gravar, revisão não sobe (mesmo comportamento do private antigo).
                return;
            }
            $this->repo->gravarAtribuicoes($r, [...$atuais, ['imagem' => $imagem->id, 'grupo' => $grupo, 'posicao' => count($doGrupo)]]);
            $this->repo->tocar($r);
        });
    }

    // ═══ Simulador "Você recebe" (E10, H-14) ════════════════════════════════

    /** @return array<string, array> listing_type_id → simulação do preço da 1ª variante ativa */
    public function simular(PubRascunho $r): array
    {
        if (! $r->categoria_id) {
            return [];
        }
        $e = $this->efetivos->daProduto($r->produto);
        $s = $this->repo->snapshot($r)->comEfetivos($e['titulos'], $e['precos'], $e['precos_por_variante'] ?? []);
        $primeira = $s->variantesAtivas()[0] ?? null;
        $conta = (array) ($r->step_state['conta'] ?? []);
        $pacote = $this->dimensoes($s->atributos);

        $saida = [];
        foreach ($s->alvosAtivos() as $alvo) {
            $preco = (float) ($primeira?->dados['precos'][$alvo->listingTypeId] ?? 0);
            if ($preco <= 0) {
                continue;
            }
            $tarifa = $this->cliente->publico('/sites/MLB/listing_prices', ['price' => $preco, 'category_id' => $r->categoria_id,
                'listing_type_id' => $alvo->listingTypeId, 'currency_id' => 'BRL', 'logistic_type' => 'drop_off', 'shipping_mode' => 'me2']);
            if (! $tarifa->ok() || ! isset($tarifa->corpo['sale_fee_amount'])) {
                continue;
            }

            $frete = null;
            if (isset($conta['sellerId']) && $pacote !== null) {
                $f = $this->cliente->daConta($r->conta(), 'GET', "/users/{$conta['sellerId']}/shipping_options/free", [
                    'item_price' => $preco, 'listing_type_id' => $alvo->listingTypeId, 'mode' => 'me2', 'condition' => $s->condicao === 'used' ? 'used' : 'new',
                    'logistic_type' => 'drop_off', 'dimensions' => $pacote, 'verbose' => 'true',
                ]);
                $frete = $f->ok() && isset($f->corpo['coverage']['all_country']['list_cost']) ? (float) $f->corpo['coverage']['all_country']['list_cost'] : null;
            }

            $saida[$alvo->listingTypeId] = SimuladorVoceRecebe::calcular($preco, (float) $tarifa->corpo['sale_fee_amount'], $frete);
        }

        return $saida;
    }

    /**
     * Frete grátis obrigatório pela faixa de preço (melhoria de 03/10/2026, docx §5).
     *
     * Quem diz é o `shipping_options/free` (H-10): `discount.type = mandatory` SEM
     * `free_shipping_by_meli` = o vendedor TEM de oferecer frete grátis; com
     * `free_shipping_by_meli` o ML banca o frete abaixo da faixa. O limite (hoje
     * R$ 79) nunca fica no código (RN-83). Consulta o menor e o maior preço das
     * variações ativas de cada tipo: a flag de frete grátis é do rascunho inteiro,
     * então ela só é obrigatória quando até a variação mais barata cai na faixa
     * (`obrigatorio`); quando só as mais caras caem, é `parcial` (o ML liga nelas).
     *
     * @return array{conhecido: bool, obrigatorio: bool, parcial: bool, por_tipo: array<string, array{obrigatorio: bool, parcial: bool, menor: float, maior: float}>}
     */
    public function freteGratis(PubRascunho $r): array
    {
        $saida = ['conhecido' => false, 'obrigatorio' => false, 'parcial' => false, 'por_tipo' => []];
        $conta = (array) ($r->step_state['conta'] ?? []);
        if (! $r->categoria_id || ! isset($conta['sellerId'])) {
            return $saida;
        }
        $e = $this->efetivos->daProduto($r->produto);
        $s = $this->repo->snapshot($r)->comEfetivos($e['titulos'], $e['precos'], $e['precos_por_variante'] ?? []);
        // Fora do Mercado Envios não há frete grátis obrigatório.
        if (($s->envio['modo'] ?? 'me2') !== 'me2') {
            return ['conhecido' => true] + $saida;
        }
        $pacote = $this->dimensoes($s->atributos);

        foreach ($s->alvosAtivos() as $alvo) {
            $precos = array_values(array_filter(array_map(
                fn (Variante $v) => (float) ($v->dados['precos'][$alvo->listingTypeId] ?? 0), $s->variantesAtivas(),
            ), fn ($p) => $p > 0));
            if ($precos === []) {
                continue;
            }
            $menor = min($precos);
            $maior = max($precos);
            $consulta = fn (float $preco) => $this->exigeFreteGratis($r, (string) $conta['sellerId'], $preco, $alvo->listingTypeId, $s->condicao, $pacote);
            $doMenor = $consulta($menor);
            $doMaior = $maior === $menor ? $doMenor : $consulta($maior);
            if ($doMenor === null || $doMaior === null) {
                continue;
            }
            $saida['por_tipo'][$alvo->listingTypeId] = ['obrigatorio' => $doMenor, 'parcial' => ! $doMenor && $doMaior, 'menor' => $menor, 'maior' => $maior];
        }

        $tipos = $saida['por_tipo'];

        return [
            'conhecido' => $tipos !== [],
            'obrigatorio' => (bool) array_filter($tipos, fn ($t) => $t['obrigatorio']),
            'parcial' => (bool) array_filter($tipos, fn ($t) => $t['parcial']),
            'por_tipo' => $tipos,
        ];
    }

    /** Nulo = o ML não respondeu (a tela segue sem a regra, como antes). */
    private function exigeFreteGratis(PubRascunho $r, string $sellerId, float $preco, string $listingType, ?string $condicao, ?string $pacote): ?bool
    {
        $f = $this->cliente->daConta($r->conta(), 'GET', "/users/{$sellerId}/shipping_options/free", array_filter([
            'item_price' => $preco, 'listing_type_id' => $listingType, 'mode' => 'me2', 'condition' => $condicao === 'used' ? 'used' : 'new',
            'logistic_type' => 'drop_off', 'dimensions' => $pacote, 'verbose' => 'true',
        ], fn ($x) => $x !== null));
        $cobertura = $f->ok() && is_array($f->corpo) ? ($f->corpo['coverage']['all_country'] ?? null) : null;
        if (! is_array($cobertura)) {
            return null;
        }

        return ($cobertura['discount']['type'] ?? null) === 'mandatory' && empty($cobertura['free_shipping_by_meli']);
    }

    /** "AxLxC,peso" dos SELLER_PACKAGE_* (cm e g), como o `shipping_options/free` pede. */
    private function dimensoes(array $atributos): ?string
    {
        $n = function (string $id) use ($atributos): ?int {
            $v = $atributos[$id] ?? null;
            $num = $v['value_number'] ?? (preg_match('/^\s*(\d+(?:[.,]\d+)?)/', (string) ($v['value_name'] ?? ''), $m) ? (float) str_replace(',', '.', $m[1]) : null);

            return $num !== null ? (int) round((float) $num) : null;
        };
        $partes = [$n('SELLER_PACKAGE_HEIGHT'), $n('SELLER_PACKAGE_WIDTH'), $n('SELLER_PACKAGE_LENGTH'), $n('SELLER_PACKAGE_WEIGHT')];

        return in_array(null, $partes, true) ? null : "{$partes[0]}x{$partes[1]}x{$partes[2]},{$partes[3]}";
    }

    // ═══ O estado para a tela ════════════════════════════════════════════════

    public function estado(PubRascunho $r): array
    {
        $r = $r->fresh(['produto.oferta']);
        $e = $this->efetivos->daProduto($r->produto);
        $digitado = $this->repo->snapshot($r);
        $snapshot = $digitado->comEfetivos($e['titulos'], $e['precos'], $e['precos_por_variante'] ?? []);

        [$schema, $erroSchema] = $this->schemaDe($r, $snapshot);
        $conta = (array) ($r->step_state['conta'] ?? []);
        $metadados = $this->repo->metadadosDasImagens($r);

        $problemas = [];
        if ($schema) {
            $ctx = new ContextoValidacao(
                modelo: (string) ($r->modelo_publicacao ?? MontadorDePlano::UP),
                tagsDaConta: (array) ($conta['tags'] ?? []),
                modosEnvio: $conta['modosEnvio'] ?? null,
                imagens: $metadados,
                plausibilidade: (array) (config('publicador.plausibilidade')[$schema->dominio] ?? []),
                termosProibidosTitulo: (array) config('publicador.termos_proibidos_titulo', ContextoValidacao::TERMOS_PROIBIDOS_TITULO),
                hashSchemaDoRascunho: $r->schema_hash,
                limiteFamilyName: config('publicador.limite_family_name'),
                avisarAcimaDeItens: (int) config('publicador.avisar_acima_de_itens', 20),
            );
            $problemas = (new ValidadorRascunho())->validar($snapshot, $schema, $ctx)->problemas;
        }

        $this->gravarResumo($r, count(array_filter($problemas, fn (Problema $p) => $p->bloqueia())));

        $eixos = Eixo::ordenar($snapshot->eixos);
        $grupos = $schema ? ResolvedorGruposImagem::resolver($snapshot->variantes, $eixos, $snapshot->imagens, new OpcoesImagem(
            OpcoesImagem::UP, $schema->limites['max_pictures_per_item'] ?? null, $schema->limites['max_pictures_per_item_var'] ?? null,
            $snapshot->fotosPorVariante, $snapshot->incluirGeral,
        ))->grupos : [];

        /** @var ?PubValidacao $v */
        $v = $r->validacoes()->latest('id')->first();
        /** @var ?PubPublicacao $p */
        $p = $r->publicacoes()->latest('id')->first();

        return [
            'produto' => [
                'id' => $r->produto->id, 'sku' => $r->produto->skuExibido(), 'nome' => $r->produto->nomeExibido(),
                'oferta_id' => $r->produto->oferta_id, 'origem' => $r->produto->origem,
                'mlb_empresa_id' => $r->produto->mlb_empresa_id, 'company_id' => $r->produto->company_id,
            ],
            'rascunho' => [
                'id' => $r->id, 'revisao' => $r->revisao, 'status' => $r->status,
                'categoria_id' => $r->categoria_id, 'condicao' => $r->condicao, 'descricao' => $r->descricao,
                'envio' => $digitado->envio, 'garantia' => $r->garantia,
                'fotos_por_variante' => (bool) $r->fotos_por_variante, 'incluir_geral' => (bool) $r->incluir_geral_nas_variantes,
                'modelo' => $r->modelo_publicacao,
            ],
            'alvos' => array_map(fn (Alvo $a, Alvo $ef) => [
                'listing_type_id' => $a->listingTypeId, 'titulo' => $a->titulo, 'titulo_efetivo' => $ef->titulo, 'ativo' => $a->ativo,
                'mlb_na_regua' => $this->mlbDoTipo($r, $a->listingTypeId),
            ], $digitado->alvos, $snapshot->alvos),
            'atributos' => $digitado->atributos,
            'eixos' => array_map(fn (Eixo $x) => [
                'chave' => $x->chave, 'nome' => $x->nome, 'defines_picture' => $x->definesPicture, 'customizado' => $x->ehCustomizado(),
                'valores' => array_map(fn (ValorEixo $val) => ['chave' => $val->chave(), 'id' => $val->valueId, 'nome' => $val->valueName], $x->valores),
            ], $eixos),
            'variantes' => array_map(fn (Variante $vd, Variante $ve) => [
                'chave' => $vd->chave, 'rotulo' => $vd->rotulo($eixos), 'ativa' => $vd->ativa, 'orfa' => $vd->orfa, 'publicada' => $vd->publicada,
                // Eixo → valor: a tela tira, restaura e cria variação pelo valor (cartão "como no ML", 03/10).
                'valores' => array_map(fn (ValorEixo $val) => ['id' => $val->valueId, 'nome' => $val->valueName], $vd->valores),
                'estoque' => $vd->dados['estoque'] ?? null, 'estoque_depositos' => $vd->dados['estoque_depositos'] ?? null,
                'precos' => (array) ($vd->dados['precos'] ?? []), 'precos_efetivos' => (array) ($ve->dados['precos'] ?? []),
                'atributos' => (array) ($vd->dados['atributos'] ?? []),
            ], $digitado->variantes, $snapshot->variantes),
            'imagens' => $r->imagens()->orderBy('id')->get()->map(fn (PubImagem $i) => [
                'id' => (string) $i->id, 'url' => $i->ml_url, 'largura' => $i->largura, 'altura' => $i->altura,
                'upload_status' => $i->upload_status, 'erro' => $i->upload_erro['mensagem'] ?? null,
                'tem_arquivo' => $i->caminho !== null,
            ])->all(),
            'atribuicoes' => $snapshot->imagens,
            'grupos_imagem' => $grupos,
            'schema' => $schema ? self::schemaParaTela($schema, $this->explicacoes) : null,
            'erro_schema' => $erroSchema,
            'conta' => $conta ? [
                'modelo' => $conta['modelo'] ?? null,
                'multi_deposito' => in_array('warehouse_management', (array) ($conta['tags'] ?? []), true),
                'depositos' => $conta['depositos'] ?? null,
                'modos_envio' => $conta['modosEnvio'] ?? null,
                'erro' => $conta['erro'] ?? null,
            ] : null,
            'efetivos' => ['titulos' => $e['titulos'], 'precos' => $e['precos']],
            'problemas' => array_map([self::class, 'problemaParaTela'], $problemas),
            'conferencia' => $v ? [
                'id' => $v->id, 'revisao' => $v->revisao, 'resultado' => $v->resultado, 'vale' => $v->revisao === $r->revisao,
                'em' => $v->created_at?->toIso8601String(),
                // Conferência gravada antes do filtro de ruído (03/10) ainda traz o 4053.
                'issues' => array_values(array_filter((array) $v->issues, fn ($i) => ! MapeadorErrosMl::ehRuido((array) ($i['ml_causa'] ?? [])))),
                'itens' => count((array) ($v->respostas_ml['itens'] ?? [])),
                'local' => $v->camada === 'L2',
            ] : null,
            'publicacao' => $p ? [
                'id' => $p->id, 'status' => $p->status, 'motivo' => $p->conta_snapshot['motivo'] ?? null,
                'iniciada_em' => $p->iniciada_em?->toIso8601String(), 'concluida_em' => $p->concluida_em?->toIso8601String(),
                'itens' => $p->itens()->get()->map(fn ($i) => [
                    'id' => $i->id, 'indice' => $i->indice, 'listing_type_id' => $i->listing_type_id, 'variante_chave' => $i->variante_chave,
                    'status' => $i->status, 'ml_item_id' => $i->ml_item_id, 'caminho' => $i->caminho, 'descricao_status' => $i->descricao_status,
                    'estado' => $i->avisos['estado'] ?? null, 'mensagem' => $i->avisos['mensagem'] ?? $i->avisos['regua'] ?? null,
                    'plano_b' => isset($i->avisos['plano_b']),
                ])->all(),
                'problemas' => $p->status === PubPublicacao::RUNNING ? [] : array_map([self::class, 'problemaParaTela'], $this->publicacoes->problemas($p)),
            ] : null,
            // "tipo|variante" → MLB, de TODAS as publicações: o que "publicar de novo" não reenvia (RN-94).
            'ja_publicados' => PubPublicacaoItem::query()
                ->whereIn('publicacao_id', $r->publicacoes()->select('id'))
                ->where('status', PubPublicacaoItem::CREATED)->orderBy('id')->get()
                ->mapWithKeys(fn ($i) => [$i->listing_type_id.'|'.$i->variante_chave => $i->ml_item_id])->all(),
            // Fase 172 (D-09): o que o cliente escreveu no Portal, lido ao vivo — insumo da IA de descrição.
            'portal' => ['descricao_cliente' => $this->leitor->descricaoDoCliente($r->produto)],
        ];
    }

    /**
     * Resumo de bloqueios para a lista de produtos e para a faixa do editor. Não é uma
     * edição: `DB::table` de propósito, para não subir `revisao` (a conferência continua
     * valendo) nem mexer em `updated_at`. Só grava quando o número muda.
     */
    private function gravarResumo(PubRascunho $r, int $bloqueios): void
    {
        if ($bloqueios === ($r->step_state['resumo']['bloqueios'] ?? null)) {
            return;
        }
        $atual = json_decode((string) DB::table('pub_rascunhos')->where('id', $r->id)->value('step_state'), true) ?: [];
        $atual['resumo'] = ['bloqueios' => $bloqueios, 'revisao' => $r->revisao];
        DB::table('pub_rascunhos')->where('id', $r->id)->update(['step_state' => json_encode($atual, JSON_UNESCAPED_UNICODE)]);
    }

    /**
     * O selo do card da oferta na lista do Anunciar, pelo rascunho do
     * Publicador (o da lista antiga lê o par antigo e diria "falta categoria").
     *
     * @return array{chave: string, rotulo: string}
     */
    public static function prontidao(?PubRascunho $r, ?PubValidacao $ultima): array
    {
        if (! $r) {
            return ['chave' => 'rascunho', 'rotulo' => 'a preencher', 'faltam' => 0];
        }
        if ($r->status === PubRascunho::DRAFT && $ultima && $ultima->revisao === $r->revisao && in_array($ultima->resultado, [ConferenciaService::OK, ConferenciaService::AVISOS], true)) {
            return ['chave' => 'pronto', 'rotulo' => 'conferido', 'faltam' => (int) ($r->step_state['resumo']['bloqueios'] ?? 0)];
        }
        $faltam = $r->status === PubRascunho::DRAFT ? (int) ($r->step_state['resumo']['bloqueios'] ?? 0) : 0;

        return ['faltam' => $faltam] + match ($r->status) {
            PubRascunho::VALIDATED => ['chave' => 'pronto', 'rotulo' => 'conferido'],
            PubRascunho::PUBLISHING => ['chave' => 'publicando', 'rotulo' => 'publicando'],
            PubRascunho::PUBLISHED => ['chave' => 'publicado', 'rotulo' => 'publicado'],
            PubRascunho::PARTIALLY_PUBLISHED => ['chave' => 'parcial', 'rotulo' => 'parte publicada'],
            PubRascunho::FAILED => ['chave' => 'erro', 'rotulo' => 'não publicado'],
            default => ['chave' => 'conferir', 'rotulo' => 'em preenchimento'],
        };
    }

    /** @return array{0: ?SchemaClassificado, 1: ?string} */
    private function schemaDe(PubRascunho $r, RascunhoSnapshot $s): array
    {
        if (! $r->categoria_id) {
            return [null, null];
        }
        try {
            $condicionais = array_values((array) ($r->step_state['condicionais']['ids'] ?? []));

            return [(new ClassificadorAtributos())->classificar($this->schemas->obter($r->categoria_id), new ContextoClassificacao($s->condicao, $this->idsDeEixo($s->eixos), $condicionais)), null];
        } catch (RegraViolada $e) {
            return [null, $e->getMessage()];
        }
    }

    /**
     * O schema como a tela usa. Cada atributo leva `explicacao` (o texto do ícone de informação ao
     * lado do rótulo, 08/10/2026): glossário > guardado > ML > texto montado — e o que faltar vai
     * para a IA, uma vez por atributo (`ExplicacaoDeAtributos`). Atributo oculto recebe texto, mas
     * não gasta IA. `explicacoes_campos` = os campos fixos que não são atributo (estoque).
     */
    public static function schemaParaTela(SchemaClassificado $s, ?ExplicacaoDeAtributos $explicacoes = null): array
    {
        $explicacoes ??= app(ExplicacaoDeAtributos::class);
        $textos = $explicacoes->paraAtributos(array_values(array_map(fn (AtributoClassificado $a) => [
            'id' => $a->id, 'nome' => $a->nome, 'tooltip' => $a->tooltip, 'hint' => $a->dica, 'tipo' => $a->valueType,
            'unidades' => $a->unidades, 'unidade_padrao' => $a->unidadePadrao, 'valores' => $a->valores,
            'oculto' => $a->secao === AtributoClassificado::SECAO_OCULTO,
        ], $s->atributos)), $s->caminho !== [] ? implode(' > ', $s->caminho) : $s->dominio);

        return [
            'categoria_id' => $s->categoriaId, 'dominio' => $s->dominio, 'caminho' => $s->caminho, 'hash' => $s->schemaHash,
            'limites' => $s->limites, 'flags' => $s->flags, 'bloqueios_fase2' => $s->bloqueiosFase2, 'garantia' => $s->garantia,
            'grupos' => $s->grupos,
            'explicacoes_campos' => $explicacoes->camposFixos(),
            'atributos' => array_map(fn (AtributoClassificado $a) => [
                'id' => $a->id, 'nome' => $a->nome, 'papel' => $a->papel, 'obrigatoriedade' => $a->obrigatoriedade, 'secao' => $a->secao,
                'grupo' => $a->grupo, 'tipo' => $a->valueType, 'valores' => $a->valores, 'unidades' => $a->unidades, 'unidade_padrao' => $a->unidadePadrao,
                'texto_livre' => $a->aceitaTextoLivre, 'nao_se_aplica' => $a->aceitaNaoSeAplica, 'pode_ser_eixo' => $a->podeSerEixo,
                'define_foto' => $a->definePicture, 'multivalor' => $a->multivalor, 'max' => $a->maxLength,
                'dica' => $a->dica, 'exemplo' => $a->exemplo, 'tooltip' => $a->tooltip,
                'explicacao' => $textos[$a->id] ?? ExplicacaoDeAtributos::provisorio(['nome' => $a->nome, 'tipo' => $a->valueType, 'unidades' => $a->unidades]),
            ], $s->atributos),
        ];
    }

    public static function problemaParaTela(Problema $p): array
    {
        return ['regra' => $p->regra, 'severidade' => $p->severidade, 'mensagem' => $p->mensagem, 'camada' => $p->camada, 'alvo' => $p->alvo, 'ml_causa' => $p->mlCausa];
    }

    // ═══ Apoio ═══════════════════════════════════════════════════════════════

    private function lerContaSeVencida(PubRascunho $r): void
    {
        if ($r->conta_checada_em && $r->conta_checada_em->gt(now()->subMinutes(self::CONTA_VALE_MINUTOS)) && isset($r->step_state['conta'])) {
            return;
        }
        // A conta vem do produto (Company ou MlbEmpresa); sem token, `conta()` lança V-ACC-01 e o erro fica no estado.
        try {
            $conta = $this->contas->contexto($r->conta());
            $r->update([
                'step_state' => [...(array) $r->step_state, 'conta' => $conta->paraSnapshot()],
                'modelo_publicacao' => $r->modelo_publicacao ?? $conta->modelo,
                'conta_checada_em' => now(),
            ]);
        } catch (RegraViolada $e) {
            $r->update(['step_state' => [...(array) $r->step_state, 'conta' => ['erro' => $e->getMessage()]]]);
        }
    }

    /** @return array<string, ?string> tipo da régua → MLB que já conta */
    private function mlbsDaRegua(PubProduto $produto): array
    {
        if ($produto->oferta_id === null) {
            return ['classico' => null, 'premium' => null];
        }
        $oferta = $produto->oferta;
        $o = EstruturaConjunto::daEmpresa($oferta->company)->oferta($oferta->id) ?? ['anuncios' => []];
        $r = ['classico' => null, 'premium' => null];
        foreach ((array) $o['anuncios'] as $a) {
            if (($r[$a['tipo']] ?? null) === null && EstruturaAnuncio::conta($a['status'], $a['codigo_mlb'])) {
                $r[$a['tipo']] = $a['codigo_mlb'];
            }
        }

        return $r;
    }

    private function mlbDoTipo(PubRascunho $r, string $listingType): ?string
    {
        $tipo = array_flip(EstruturaPublicacao::LISTING_TYPES)[$listingType] ?? null;

        return $tipo ? $this->mlbsDaRegua($r->produto)[$tipo] ?? null : null;
    }

    /** @param list<Eixo> $eixos @return list<string> */
    private function idsDeEixo(array $eixos): array
    {
        return array_values(array_filter(array_map(fn (Eixo $e) => $e->attributeId(), $eixos)));
    }
}
