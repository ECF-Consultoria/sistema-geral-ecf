<?php

namespace App\Services\Publicador;

use App\Jobs\Publicador\GerarPalavrasChaveIaJob;
use App\Models\PubRascunho;
use App\Services\Ia\AnaliseAnuncioService;
use App\Services\Incubadora\Publicador\TermosMaisBuscadosService;
use App\Support\Publicador\FatosDoProduto;
use App\Support\Publicador\RegrasDoTitulo;
use App\Support\Publicador\RegraViolada;
use App\Support\Publicador\Schema\CategorySchema;
use App\Support\Publicador\Schema\ValorAtributo;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Termos mais buscados da categoria → campo Modelo e título (melhoria do
 * Publicador de 03/10/2026, `melhoria_publicador.docx` §2 e §3).
 *
 * Os termos vêm do `GET /trends/MLB/{categoria}` (o mesmo serviço da
 * Incubadora). A IA (NVIDIA, `AnaliseAnuncioService`) escolhe os coerentes
 * com o produto; o corte no limite é daqui, porque o modelo erra contagem.
 *
 * A IA leva de segundos a minutos: roda em Job e o resultado fica no cache
 * por pedido (`estado()`), de onde a tela o lê e o aplica pelo caminho normal
 * de edição — nada é gravado no rascunho por trás da pessoa.
 */
class PalavrasChaveService
{
    public const MODELO = 'modelo';

    /** Limite do campo Modelo pedido pela equipe (o ML aceita 255). */
    public const LIMITE_MODELO = 120;

    public const ALVOS = [self::MODELO, 'titulo_gold_special', 'titulo_gold_pro'];

    /** Quantos termos vão para a IA: a lista cheia do ML tem 50. */
    private const TERMOS_PARA_IA = 50;

    private const TTL_PEDIDO = 1800;

    /**
     * Palavras de ligação: não contam como "palavra nova" no Modelo
     * ("puff para sala" com "Sala" no título não traz nada novo).
     */
    private const LIGACAO = ['de', 'da', 'do', 'das', 'dos', 'para', 'pra', 'com', 'sem', 'e', 'em', 'no', 'na', 'a', 'o', 'os', 'as', 'um', 'uma', 'por'];

    public function __construct(
        private TermosMaisBuscadosService $trends,
        private CategorySchemaRepository $schemas,
        private AnaliseAnuncioService $ia,
        private PortalProdutoLeitor $leitor,
    ) {}

    /**
     * Os termos da categoria do rascunho, com a pista `relacionado` (o termo
     * tem palavra do nome do produto que não está no caminho da categoria).
     *
     * @throws RegraViolada sem categoria
     * @throws \RuntimeException quando o ML não responde
     */
    public function termos(PubRascunho $r): array
    {
        [$categoria, $caminho] = $this->categoria($r);

        return [
            'categoria' => ['id' => $categoria, 'caminho' => $caminho],
            ...$this->trends->termos($categoria, $r->produto->nomeExibido(), $caminho),
        ];
    }

    /**
     * Põe o pedido na fila e devolve o id dele. `escolhidos` só vale para título.
     * `titulo` = o que está na tela, talvez ainda não salvo: no Modelo, o(s) título(s)
     * ativo(s) — ele se SOMA aos gravados, nunca os substitui; no título de um tipo, o
     * título do OUTRO tipo, que o resultado não pode repetir (09/10/2026).
     */
    public function pedir(PubRascunho $r, string $alvo, array $escolhidos = [], ?string $titulo = null): string
    {
        $this->categoria($r);
        $pedido = (string) Str::uuid();
        Cache::put(self::chave($r->id, $alvo), ['pedido' => $pedido, 'status' => 'rodando', 'valor' => null, 'erro' => null], self::TTL_PEDIDO);
        $titulo = trim((string) $titulo) ?: null;
        GerarPalavrasChaveIaJob::dispatch($r->id, $alvo, $pedido, array_values(array_slice($escolhidos, 0, 20)), $titulo);

        return $pedido;
    }

    /** O pedido mais recente deste alvo; nulo = nenhum. */
    public function estado(PubRascunho $r, string $alvo): ?array
    {
        $e = Cache::get(self::chave($r->id, $alvo));

        return is_array($e) ? $e : null;
    }

    /**
     * Roda no Job: grava `pronto` ou `erro` — só se o pedido ainda for o mais recente.
     * No Modelo, `descartados` lista os termos que a IA sugeriu e o servidor tirou por
     * não condizerem com o produto (cor, público, tamanho), com o motivo.
     */
    public function executar(PubRascunho $r, string $alvo, string $pedido, array $escolhidos, ?float $prazo = null, ?string $tituloDaTela = null): void
    {
        try {
            ['valor' => $valor, 'descartados' => $descartados] = $alvo === self::MODELO
                ? $this->modelo($r, $prazo, $tituloDaTela)
                : ['valor' => $this->titulo($r, substr($alvo, strlen('titulo_')), $escolhidos, $prazo, $tituloDaTela), 'descartados' => []];
            if ($valor === '') {
                throw new \RuntimeException('A IA não devolveu nada aproveitável. Tente de novo.');
            }
            $this->concluir($r->id, $alvo, $pedido, ['status' => 'pronto', 'valor' => $valor, 'erro' => null, 'descartados' => $descartados]);
        } catch (\Throwable $e) {
            $this->concluir($r->id, $alvo, $pedido, ['status' => 'erro', 'valor' => null, 'erro' => $e->getMessage()]);

            throw $e;
        }
    }

    /** Marca o pedido como falho (Job que caiu sem passar pelo `executar`). */
    public function falhou(int $rascunhoId, string $alvo, string $pedido, string $mensagem): void
    {
        $this->concluir($rascunhoId, $alvo, $pedido, ['status' => 'erro', 'valor' => null, 'erro' => $mensagem]);
    }

    public static function chave(int $rascunhoId, string $alvo): string
    {
        return "publicador:palavras:{$rascunhoId}:{$alvo}";
    }

    // ═══ Pós-processamento (puro) ════════════════════════════════════════════

    /**
     * O Modelo no formato "termo, termo, termo": minúsculas, sem acento, sem
     * repetir termo, e cortado no último termo INTEIRO que cabe no limite —
     * nunca no meio de uma palavra.
     *
     * Com `titulo`, sai todo termo que não traz nenhuma palavra de conteúdo
     * nova: o Modelo existe para EXPANDIR a busca, e repetir o que o título já
     * tem desperdiça caractere (pedido do usuário, 08/10/2026). "puff sala" com
     * "Puff ... Sala" no título sai; "puff para quarto infantil" fica, porque
     * "infantil" é novo. O prompt pede o mesmo, mas a IA não obedece sempre.
     *
     * Com `fatos`, sai ANTES todo termo que cita cor, público ou tamanho que o
     * produto não tem (`FatosDoProduto::motivoParaDescartar`, 09/10/2026) — aí
     * "infantil" só fica se a ficha confirmar.
     */
    public static function ajustarModelo(string $bruto, int $limite = self::LIMITE_MODELO, string $titulo = '', ?FatosDoProduto $fatos = null): string
    {
        return self::filtrarModelo($bruto, $limite, $titulo, $fatos)['valor'];
    }

    /**
     * O `ajustarModelo` com o que saiu por não condizer com o produto.
     *
     * @return array{valor: string, descartados: list<array{termo: string, motivo: string}>}
     */
    public static function filtrarModelo(string $bruto, int $limite = self::LIMITE_MODELO, string $titulo = '', ?FatosDoProduto $fatos = null): array
    {
        $partes = preg_split('/[,;\n|]+/', Str::lower(Str::ascii($bruto))) ?: [];
        $doTitulo = self::palavrasDoTitulo($titulo);
        $vistos = [];
        $descartados = [];
        $saida = '';
        foreach ($partes as $p) {
            $termo = trim(preg_replace('/\s+/', ' ', preg_replace('/[^a-z0-9 ]+/', ' ', $p)));
            if ($termo === '' || isset($vistos[$termo])) {
                continue;
            }
            $motivo = $fatos?->motivoParaDescartar($termo);
            if ($motivo !== null) {
                $vistos[$termo] = true;
                $descartados[] = ['termo' => $termo, 'motivo' => $motivo];

                continue;
            }
            if ($doTitulo !== [] && ! self::trazPalavraNova($termo, $doTitulo)) {
                continue;
            }
            $candidato = $saida === '' ? $termo : "{$saida}, {$termo}";
            if (strlen($candidato) > $limite) {
                // Um termo grande demais não fecha a lista: o seguinte pode caber.
                continue;
            }
            $vistos[$termo] = true;
            $saida = $candidato;
        }

        return ['valor' => $saida, 'descartados' => $descartados];
    }

    /**
     * As formas de comparar das palavras do título (ver `formas()`), como chaves.
     *
     * @return array<string, true>
     */
    public static function palavrasDoTitulo(string $titulo): array
    {
        $saida = [];
        foreach (preg_split('/[^a-z0-9]+/', Str::lower(Str::ascii($titulo)), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $p) {
            foreach (self::formas($p) as $f) {
                $saida[$f] = true;
            }
        }

        return $saida;
    }

    /** O termo tem ao menos uma palavra de conteúdo (fora as de ligação) que o título não tem? */
    public static function trazPalavraNova(string $termo, array $doTitulo): bool
    {
        foreach (preg_split('/[^a-z0-9]+/', Str::lower(Str::ascii($termo)), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $p) {
            if (in_array($p, self::LIGACAO, true)) {
                continue;
            }
            if (array_intersect_key(array_flip(self::formas($p)), $doTitulo) === []) {
                return true;
            }
        }

        return false;
    }

    /**
     * Singular simples para comparar: a palavra, sem o "s" e sem o "es" final.
     * Duas palavras são a mesma quando têm uma forma em comum — "mesas" × "mesa",
     * "cores" × "cor", "chaves" × "chave" — sem dicionário (o mesmo espírito do
     * `relacionado` do `TermosMaisBuscadosService`, que só tira o "s").
     *
     * @return list<string>
     */
    private static function formas(string $p): array
    {
        $formas = [$p];
        if (strlen($p) > 3 && str_ends_with($p, 's')) {
            $formas[] = substr($p, 0, -1);
        }
        if (strlen($p) > 4 && str_ends_with($p, 'es')) {
            $formas[] = substr($p, 0, -2);
        }

        return $formas;
    }

    /**
     * Título limpo: sem os caracteres que o ruleset ECF proíbe e cortado na
     * última palavra inteira que cabe no máximo da categoria.
     */
    public static function ajustarTitulo(string $bruto, int $maximo): string
    {
        $limpo = trim(preg_replace('/\s+/u', ' ', preg_replace('/[^\p{L}\p{N} ]+/u', ' ', $bruto)));
        if (mb_strlen($limpo) <= $maximo) {
            return $limpo;
        }
        $cortado = mb_substr($limpo, 0, $maximo + 1);

        return trim(mb_substr($cortado, 0, (int) mb_strrpos($cortado, ' ')) ?: mb_substr($limpo, 0, $maximo));
    }

    // ═══ Apoio ═══════════════════════════════════════════════════════════════

    /**
     * @param  string|list<string>|null  $tituloDaTela  o título da tela, ou os dois gerados pelo preparo
     * @return array{valor: string, descartados: list<array{termo: string, motivo: string}>}
     */
    private function modelo(PubRascunho $r, ?float $prazo, string|array|null $tituloDaTela): array
    {
        [$categoria, $caminho] = $this->categoria($r);
        $termos = $this->termosParaIa($categoria, $r, $caminho);
        $titulo = $this->tituloParaModelo($r, $tituloDaTela);
        $produto = $r->produto->nomeExibido();
        $fatos = $this->fatos($r, $this->schemas->obter($categoria), $produto, implode(' > ', $caminho).' '.$titulo);
        $ia = $prazo !== null ? $this->ia->comPrazo($prazo) : $this->ia;

        $bruto = $ia->modeloPorTermos($produto, implode(' > ', $caminho), $termos, self::LIMITE_MODELO, $titulo, $fatos->paraPrompt())['dados'];
        $filtrado = self::filtrarModelo($bruto, self::LIMITE_MODELO, $titulo, $fatos);
        if ($filtrado['valor'] === '' && self::ajustarModelo($bruto) !== '') {
            // Sobrou termo sem o filtro do título? Então foi o título que tirou tudo.
            throw new \RuntimeException($titulo !== '' && self::ajustarModelo($bruto, self::LIMITE_MODELO, '', $fatos) !== ''
                ? 'Todos os termos que a IA sugeriu já estão no título. Tente de novo.'
                : 'Os termos que a IA sugeriu citam característica que o produto não tem. Tente de novo.');
        }

        return $filtrado;
    }

    /** O rótulo do fato com o Modelo que o cliente gravou no Portal antes de o campo sair da ficha. */
    public const FATO_MODELO_DO_CLIENTE = 'Nome/modelo informado pelo cliente';

    /** Atributos que não descrevem o produto (identificação, embalagem, o próprio Modelo) ou que viram cor. */
    private const FORA_DOS_FATOS = ['MODEL', 'GTIN', 'SELLER_SKU', 'EMPTY_GTIN_REASON', 'SHIPMENT_PACKING', 'VERTICAL_TAGS', 'ITEM_CONDITION', 'FILTRABLE_COLOR'];

    /** Atributos de cor: o eixo da variação ou, sem variação, o valor da ficha. */
    private const ATRIBUTOS_DE_COR = ['COLOR', 'MAIN_COLOR'];

    /** Quantas linhas da ficha vão para a IA (o prompt é curto). */
    private const MAX_LINHAS_FICHA = 40;

    /**
     * Os fatos do produto lidos do rascunho NO SERVIDOR: a ficha preenchida (nome
     * pt-BR do schema), as medidas com unidade, o público/idade e as cores das
     * variantes ATIVAS — o eixo COLOR/MAIN_COLOR ou um eixo próprio chamado "Cor",
     * mais a Cor principal de cada variante; sem variação, a cor da ficha.
     * Vazio e "Não se aplica" ficam de fora.
     */
    private function fatos(PubRascunho $r, CategorySchema $schema, string $produto, string $contexto, bool $semMarca = false): FatosDoProduto
    {
        // Título (09/10/2026): a marca não entra nele, então nem vai como fato — vai como proibição.
        $fora = $semMarca ? [...self::FORA_DOS_FATOS, self::ID_MARCA] : self::FORA_DOS_FATOS;
        $nomes = [];
        $numericos = [];
        foreach ($schema->atributos as $a) {
            if (isset($a['id'])) {
                $nomes[(string) $a['id']] = (string) ($a['name'] ?? $a['id']);
                if (in_array($a['value_type'] ?? null, ['number', 'number_unit'], true)) {
                    $numericos[(string) $a['id']] = true;
                }
            }
        }

        $ficha = [];
        $medidas = [];
        $publico = [];
        $coresDaFicha = [];
        foreach ($r->atributos()->orderBy('id')->get() as $a) {
            $id = (string) $a->attribute_id;
            $valor = self::valorDoAtributo($a->value_id, $a->value_name, $a->value_number, $a->value_unit, $a->values_multi);
            if ($valor === '' || in_array($id, $fora, true) || preg_match('/PACKAGE|DATA_SOURCE|SIZE_GRID/', $id)) {
                continue;
            }
            // Valor da ficha pode ter vindo do cliente (Portal): sem link nem e-mail.
            $valor = mb_substr(DescricaoIaService::semContato($valor, telefones: false), 0, 120);
            $nome = $nomes[$id] ?? $id;
            if (in_array($id, self::ATRIBUTOS_DE_COR, true)) {
                $coresDaFicha[] = $valor;
            } elseif (preg_match('/AGE|GENDER/', $id)) {
                $publico[] = [$nome, $valor];
            } elseif (isset($numericos[$id]) || ($a->value_number !== null && trim((string) $a->value_name) === '')) {
                $medidas[] = [$nome, $valor];
            } elseif (count($ficha) < self::MAX_LINHAS_FICHA) {
                $ficha[] = [$nome, $valor];
            }
        }

        // O Modelo saiu da ficha do Portal (09/10/2026): o que o cliente escreveu ali antes não é o valor
        // do campo, mas é um FATO sobre o produto — vai para a IA, sem link nem e-mail.
        $doCliente = $r->produto !== null ? $this->leitor->modeloDoCliente($r->produto) : null;
        if ($doCliente !== null && trim($doCliente) !== '') {
            array_unshift($ficha, [self::FATO_MODELO_DO_CLIENTE, mb_substr(DescricaoIaService::semContato($doCliente, telefones: false), 0, 120)]);
        }

        return new FatosDoProduto(
            cores: $this->coresDasVariantes($r) ?: array_values(array_unique($coresDaFicha)),
            ficha: $ficha,
            medidas: $medidas,
            publico: $publico,
            produto: $produto,
            contexto: $contexto,
        );
    }

    /** @return list<string> as cores das variantes ATIVAS (nunca as órfãs nem as desligadas), sem repetir */
    private function coresDasVariantes(PubRascunho $r): array
    {
        $eixosDeCor = $r->eixos()->where('removido', false)->get()
            ->filter(fn ($e) => in_array($e->attribute_id, self::ATRIBUTOS_DE_COR, true)
                || ($e->attribute_id === null && preg_match('/^cor(es)?( principal)?$/', Str::lower(Str::ascii(trim((string) $e->nome))))))
            ->pluck('id')->map(fn ($id) => (int) $id)->all();

        $cores = [];
        $variantes = $r->variantes()->where('ativa', true)->where('orfa', false)->with(['valoresDosEixos', 'atributos'])->get();
        foreach ($variantes as $v) {
            foreach ($v->valoresDosEixos as $valor) {
                if (in_array((int) $valor->pivot->eixo_id, $eixosDeCor, true) && ! $valor->removido && trim((string) $valor->value_name) !== '') {
                    $cores[] = trim((string) $valor->value_name);
                }
            }
            foreach ($v->atributos->whereIn('attribute_id', self::ATRIBUTOS_DE_COR) as $a) {
                $valor = self::valorDoAtributo($a->value_id, $a->value_name, null, null, null);
                if ($valor !== '') {
                    $cores[] = $valor;
                }
            }
        }

        return array_values(array_unique($cores));
    }

    /** O valor legível de uma linha de atributo; vazio para nada preenchido ou "Não se aplica". */
    private static function valorDoAtributo(?string $valueId, ?string $valueName, ?float $numero, ?string $unidade, ?array $multi): string
    {
        if ((string) $valueId === ValorAtributo::NAO_SE_APLICA) {
            return '';
        }
        $valor = trim((string) $valueName);
        if ($valor === '' && $numero !== null) {
            $valor = rtrim(rtrim(number_format($numero, 4, '.', ''), '0'), '.').($unidade ? ' '.$unidade : '');
        }
        if ($valor === '' && is_array($multi)) {
            $valor = implode(', ', array_filter(array_map(fn ($v) => trim((string) (is_array($v) ? ($v['name'] ?? $v['value_name'] ?? '') : $v)), $multi)));
        }

        return in_array(Str::lower(Str::ascii($valor)), ['n/a', 'na', 'nao se aplica'], true) ? '' : $valor;
    }

    /**
     * O título que o Modelo não deve repetir: os títulos ATIVOS gravados
     * (Clássico e Premium) mais o da tela, sem repetir — lidos aqui, e não só
     * do navegador. Vazio = ainda não há título; o Modelo sai sem o filtro.
     */
    private function tituloParaModelo(PubRascunho $r, string|array|null $tituloDaTela): string
    {
        $titulos = $r->alvos()->where('ativo', true)->pluck('titulo')->push(...(array) $tituloDaTela)
            ->map(fn ($t) => trim((string) $t))->filter()->unique()->values();

        return $titulos->implode(' / ');
    }

    /** O atributo da marca: ela nunca entra no título (09/10/2026). */
    private const ID_MARCA = 'BRAND';

    /**
     * O título de UM tipo pelos termos mais buscados (o botão "Sugerir com IA"), com os FATOS DO
     * PRODUTO no prompt (09/10/2026, o mesmo bloco do Modelo), sem marca nem número de especificação
     * (`RegrasDoTitulo::limpar`) e cortado no `max_title_length` da categoria.
     *
     * Nunca igual ao título do OUTRO tipo (o gravado e o da tela, `outroDaTela`): o ML barra dois
     * anúncios com o mesmo nome. O prompt pede; se a IA repetir, a diferença sai daqui; sem saída,
     * erro para a pessoa tentar de novo — nunca dois iguais.
     */
    private function titulo(PubRascunho $r, string $listingType, array $escolhidos, ?float $prazo, ?string $outroDaTela = null): string
    {
        $c = $this->contextoDoTitulo($r);
        $outros = $r->alvos()->where('ativo', true)->where('listing_type_id', '<>', $listingType)->whereIn('listing_type_id', ['gold_special', 'gold_pro'])
            ->pluck('titulo')->push($outroDaTela)
            ->map(fn ($t) => trim((string) $t))->filter()->unique()->values()->all();
        $ia = $prazo !== null ? $this->ia->comPrazo($prazo) : $this->ia;

        $bruto = $ia->tituloPorTermos($c['produto'], $c['caminho'], $c['termos'], $escolhidos, $c['maximo'], $c['fatos']->paraPrompt(),
            implode(', ', $c['marcas']), implode(' / ', $outros))['dados'];
        $titulo = RegrasDoTitulo::semRepetir([$listingType => $this->finalizarTitulo($bruto, $c)], $outros, $c['candidatos'], $c['maximo'])[$listingType];
        if ($titulo === '' && $this->finalizarTitulo($bruto, $c) !== '') {
            throw new \RuntimeException('A IA sugeriu o mesmo título do outro tipo de anúncio. Tente de novo.');
        }

        return $titulo;
    }

    /** O bruto da IA sem marca/"ECF"/número de especificação e cortado no máximo da categoria. */
    private function finalizarTitulo(string $bruto, array $c): string
    {
        return self::ajustarTitulo(RegrasDoTitulo::limpar($bruto, $c['marcas'], $c['referencia']), $c['maximo']);
    }

    /**
     * Tudo o que o título precisa, lido do rascunho uma vez: categoria, limite, termos, fatos (sem a
     * marca), a marca (BRAND), a referência das medidas (nome do produto + termos) e as palavras que
     * podem diferenciar dois títulos.
     *
     * @return array{caminho: string, maximo: int, termos: list<string>, produto: string, fatos: FatosDoProduto, marcas: list<string>, referencia: string, candidatos: list<string>}
     */
    private function contextoDoTitulo(PubRascunho $r): array
    {
        [$categoria, $caminho] = $this->categoria($r);
        $schema = $this->schemas->obter($categoria);
        $termos = $this->termosParaIa($categoria, $r, $caminho);
        $produto = $r->produto->nomeExibido();
        $fatos = $this->fatos($r, $schema, $produto, implode(' > ', $caminho), semMarca: true);
        $marca = $r->atributos()->where('attribute_id', self::ID_MARCA)->first();
        $marca = $marca ? self::valorDoAtributo($marca->value_id, $marca->value_name, null, null, $marca->values_multi) : '';
        $marcas = $marca === '' ? [] : [$marca];

        return [
            'caminho' => implode(' > ', $caminho),
            'maximo' => (int) ($schema->settings()['max_title_length'] ?? 60) ?: 60,
            'termos' => $termos,
            'produto' => $produto,
            'fatos' => $fatos,
            'marcas' => $marcas,
            'referencia' => $produto.' '.implode(' ', $termos),
            'candidatos' => RegrasDoTitulo::candidatos($termos, $produto, $fatos, $marcas),
        ];
    }

    // ═══ Geração direta (o preparo pelo Portal, sem pedido nem cache) ════════

    /**
     * Um título para o rascunho, devolvido direto (sem pedido no cache). Mesmo prompt do botão da tela.
     *
     * @throws RegraViolada sem categoria
     */
    public function gerarTitulo(PubRascunho $r, ?float $prazo = null): string
    {
        return $this->titulo($r, 'gold_special', [], $prazo);
    }

    /**
     * Os DOIS títulos do preparo pelo Portal (09/10/2026), numa chamada só à IA: Clássico e Premium
     * do mesmo produto, nunca iguais entre si nem a um título que a equipe já escreveu (`evitar`,
     * listing_type_id → título). Quem grava é o `PreparoIaDoRascunhoService`, sob a trava do rascunho.
     * Um tipo que não deu para diferenciar volta VAZIO (não é escrito).
     *
     * @param  array<string, string>  $evitar
     * @return array{gold_special: string, gold_pro: string}
     *
     * @throws RegraViolada sem categoria
     */
    public function gerarTitulos(PubRascunho $r, ?float $prazo = null, array $evitar = []): array
    {
        $c = $this->contextoDoTitulo($r);
        $evitar = array_filter(array_map(fn ($t) => trim((string) $t), $evitar));
        $ia = $prazo !== null ? $this->ia->comPrazo($prazo) : $this->ia;

        $dados = $ia->titulosPorTermos($c['produto'], $c['caminho'], $c['termos'], [], $c['maximo'], $c['fatos']->paraPrompt(),
            implode(', ', $c['marcas']), implode(' / ', array_unique(array_values($evitar))))['dados'];
        $classico = $this->finalizarTitulo((string) ($dados['classico'] ?? ''), $c);
        $premium = $this->finalizarTitulo((string) ($dados['premium'] ?? ''), $c);

        // Um só veio: ele vale para os dois e a diferença sai do servidor.
        return RegrasDoTitulo::semRepetir(
            ['gold_special' => $classico ?: $premium, 'gold_pro' => $premium ?: $classico],
            $evitar, $c['candidatos'], $c['maximo'],
        );
    }

    /**
     * O Modelo para o rascunho, devolvido direto. `titulo` = o(s) título(s) que acabaram de ser
     * gerados (somam-se aos ativos gravados, como o da tela).
     *
     * @param  string|list<string>|null  $titulo
     * @return array{valor: string, descartados: list<array{termo: string, motivo: string}>}
     *
     * @throws RegraViolada sem categoria
     */
    public function gerarModelo(PubRascunho $r, ?float $prazo = null, string|array|null $titulo = null): array
    {
        return $this->modelo($r, $prazo, $titulo);
    }

    /**
     * As cores das variantes ATIVAS do rascunho (a mesma leitura dos fatos), para quem precisa
     * saber se os fatos mudaram (o hash do preparo pelo Portal).
     *
     * @return list<string>
     */
    public function coresDoRascunho(PubRascunho $r): array
    {
        return $this->coresDasVariantes($r);
    }

    /**
     * Os termos que vão para a IA: os relacionados ao produto primeiro (a
     * pista do serviço de trends), depois os demais na ordem do ML. Sem
     * termos (ML fora), a IA trabalha só com o nome do produto.
     *
     * @return list<string>
     */
    private function termosParaIa(string $categoria, PubRascunho $r, array $caminho): array
    {
        try {
            $lista = $this->trends->termos($categoria, $r->produto->nomeExibido(), $caminho)['termos'];
        } catch (\RuntimeException) {
            return [];
        }
        usort($lista, fn ($a, $b) => [(int) $b['relacionado'], $a['posicao']] <=> [(int) $a['relacionado'], $b['posicao']]);

        return array_slice(array_column($lista, 'termo'), 0, self::TERMOS_PARA_IA);
    }

    /** @return array{0: string, 1: list<string>} */
    private function categoria(PubRascunho $r): array
    {
        if (! $r->categoria_id) {
            throw new RegraViolada('V-CAT-01', 'Escolha a categoria do produto antes.');
        }

        return [(string) $r->categoria_id, $this->schemas->obter((string) $r->categoria_id)->caminho()];
    }

    private function concluir(int $rascunhoId, string $alvo, string $pedido, array $resultado): void
    {
        $chave = self::chave($rascunhoId, $alvo);
        $atual = Cache::get($chave);
        // Um pedido mais novo já está na fila: este resultado perdeu a vez.
        if (is_array($atual) && ($atual['pedido'] ?? null) !== $pedido) {
            return;
        }
        Cache::put($chave, ['pedido' => $pedido, ...$resultado], self::TTL_PEDIDO);
    }
}
