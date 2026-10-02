<?php

namespace App\Services\Publicador;

use App\Models\MlAnuncioIaAnalise;
use App\Models\PubPublicacao;
use App\Models\PubPublicacaoItem;
use App\Models\PubRascunho;
use App\Support\Publicador\RegraViolada;
use App\Support\Publicador\Schema\AtributoClassificado;
use App\Support\Publicador\Schema\ClassificadorAtributos;
use App\Support\Publicador\Schema\ContextoClassificacao;
use App\Support\Publicador\Schema\SchemaClassificado;
use App\Support\Publicador\Variacao\ChaveCanonica;
use App\Support\Publicador\Variacao\Eixo;
use App\Support\Publicador\Variacao\ValorEixo;
use App\Support\Publicador\Variacao\Variante;

/**
 * "Anunciar por IA" dentro do Publicador (D14): leva o resultado da geração em
 * fila (`GerarAnaliseAnuncioIaJob`) para o rascunho NOVO (`pub_*`) do produto.
 *
 * SEMPRE pela API do `EditorRascunhoService` — nunca SQL direto em `pub_*` —,
 * para que a saída da IA (não confiável) passe pelas mesmas normalizações do
 * motor. A IA nunca publica: só grava rascunho, e a conferência L1/L2/L3 segue
 * obrigatória antes de publicar.
 *
 * Decisões desta implementação:
 * - Sem migration: o destino mora em `ml_anuncio_ia_analises.resultado.destino =
 *   {tipo: 'publicador', produto_id, rascunho_id, revisao_base, substituir}`.
 * - "Digitado vence" (UI-SPEC §8.2): `sobrescrever = destino.substituir === true E
 *   rascunho.revisao === destino.revisao_base`. Se a equipe editou durante a geração
 *   (a revisão subiu) ou o pedido não foi "substituir", a IA só preenche o vazio.
 * - Idempotência: o resumo fica em `resultado.publicador`; quem chama só aplica
 *   quando `aplicado_em` está vazio.
 * - Rascunho publicando, publicado ou parcialmente publicado nunca é alterado.
 *
 * Duas fatias: (1) dados do anúncio — categoria, características, pacote, títulos,
 * descrição, garantia, variante única; (2) variações — eixos e variantes.
 */
class IaParaRascunhoService
{
    private const INTOCAVEIS = [PubRascunho::PUBLISHING, PubRascunho::PUBLISHED, PubRascunho::PARTIALLY_PUBLISHED];

    /** Tipos de anúncio que recebem o título da IA (Clássico e Premium). */
    private const TIPOS_TITULO = ['gold_special', 'gold_pro'];

    public function __construct(
        private EditorRascunhoService $editor,
        private RascunhoRepository $repo,
        private CategorySchemaRepository $schemas,
    ) {}

    /**
     * Rascunho em que a IA não pode mexer (publicando/publicado). WR-B03: decide pelo FATO —
     * publicação em andamento ou algum item já criado no ML —, não só pelo status, que uma
     * conferência antiga chegava a rebaixar para VALIDATED com o anúncio no ar.
     */
    public static function intocavel(PubRascunho $r): bool
    {
        return in_array($r->status, self::INTOCAVEIS, true)
            || $r->publicacoes()->where('status', PubPublicacao::RUNNING)->exists()
            || PubPublicacaoItem::whereIn('publicacao_id', $r->publicacoes()->select('id'))
                ->where('status', PubPublicacaoItem::CREATED)->exists();
    }

    /**
     * @param  array  $resultado  o `resultado` da análise (analise, titulos, descricao, ficha, cliente…)
     * @return array{rascunho_id: ?int, aplicado_em: string, secoes: int, variacoes: bool, aviso: ?string, sobrescreveu: bool}
     */
    public function aplicar(MlAnuncioIaAnalise $a, array $resultado): array
    {
        $destino = $a->destinoPublicador() ?? [];
        $r = PubRascunho::find($destino['rascunho_id'] ?? 0);

        if (! $r || (int) $r->produto_id !== (int) ($destino['produto_id'] ?? 0)) {
            return $this->resumo($destino['rascunho_id'] ?? null, 0, false, 'O rascunho deste anúncio não foi encontrado; a IA não mudou nada.', false);
        }
        if (self::intocavel($r)) {
            return $this->resumo($r->id, 0, false, 'O anúncio já estava publicado; a IA não mudou nada.', false);
        }

        $sobrescrever = ($destino['substituir'] ?? false) === true && (int) $r->revisao === (int) ($destino['revisao_base'] ?? -1);
        $ficha = is_array($resultado['ficha'] ?? null) ? $resultado['ficha'] : [];
        $cliente = is_array($resultado['cliente'] ?? null) ? $resultado['cliente'] : null;

        $avisos = [];
        $secoes = [];
        if (! empty($ficha['aviso'])) {
            $avisos[] = (string) $ficha['aviso'];
        }

        // ─── (a) Categoria ───
        $categoria = trim((string) ($ficha['category_id'] ?? ''));
        if ($categoria !== '' && ($r->categoria_id === null || ($sobrescrever && $r->categoria_id !== $categoria))) {
            try {
                $this->editor->trocarCategoria($r, $categoria);
                $secoes['categoria'] = true;
            } catch (RegraViolada $e) {
                $avisos[] = 'A categoria sugerida pela IA não pôde ser aplicada: '.$e->getMessage();
            }
            $r = $r->fresh();
        }

        $schema = $this->schemaDoRascunho($r);
        if ($r->categoria_id && $schema === null) {
            $avisos[] = 'Não foi possível ler a categoria no Mercado Livre agora; características e garantia ficaram para você.';
        }

        // ─── (b) Características e (c) pacote — um só salvamento dos atributos ───
        $idsDeVariacao = $this->idsDeVariacao($ficha);
        if ($schema !== null) {
            $atuais = $this->repo->snapshot($r)->atributos;
            $novos = $atuais;
            $tocouCaracteristicas = false;
            foreach ($this->atributosDaIa($ficha, $schema, $idsDeVariacao) as $id => $valor) {
                if ($this->preenchido($atuais[$id] ?? null) && ! $sobrescrever) {
                    continue;
                }
                $novos[$id] = $valor + ['origem' => 'ia'];
                $tocouCaracteristicas = true;
            }

            $tocouPacote = false;
            foreach ($this->pacote($ficha) as $id => $texto) {
                if ($schema->atributo($id) === null || ($this->preenchido($atuais[$id] ?? null) && ! $sobrescrever)) {
                    continue;
                }
                $novos[$id] = ['value_name' => $texto, 'origem' => 'ia'];
                $tocouPacote = true;
            }

            if ($tocouCaracteristicas || $tocouPacote) {
                $this->editor->salvar($r, ['atributos' => $novos]);
                $secoes['caracteristicas'] = $tocouCaracteristicas;
                $secoes['envio'] = $tocouPacote;
            }
        }

        // ─── (d) Títulos Clássico e Premium, (e) descrição, (f) garantia ───
        $campos = [];
        $r = $r->fresh();
        $snap = $this->repo->snapshot($r);

        $titulo = trim((string) ($ficha['titulo'] ?? ($a->titulos()[0]['texto'] ?? '')));
        if ($titulo !== '' && $snap->alvos !== []) {
            $mudou = false;
            $alvos = [];
            foreach ($snap->alvos as $alvo) {
                $titulo60 = mb_substr($titulo, 0, 60);
                $grava = in_array($alvo->listingTypeId, self::TIPOS_TITULO, true)
                    && (trim((string) $alvo->titulo) === '' || $sobrescrever)
                    && $alvo->titulo !== $titulo60;
                $mudou = $mudou || $grava;
                $alvos[] = ['listing_type_id' => $alvo->listingTypeId, 'titulo' => $grava ? $titulo60 : $alvo->titulo, 'ativo' => $alvo->ativo];
            }
            if ($mudou) {
                $campos['alvos'] = $alvos;
                $secoes['tipos'] = true;
            }
        }

        $descricao = $this->limparDescricao($a->descricao());
        if ($descricao !== '' && (trim((string) $r->descricao) === '' || $sobrescrever)) {
            $campos['descricao'] = $descricao;
            $secoes['descricao'] = true;
        }

        $textoGarantia = trim((string) ($ficha['garantia'] ?? ''));
        if ($textoGarantia !== '' && ($this->garantiaVazia($r->garantia) || $sobrescrever)) {
            $g = $schema !== null ? $this->garantia($textoGarantia, $schema) : null;
            if ($g !== null) {
                $campos['garantia'] = $g;
                $secoes['envio'] = true;
            } else {
                $avisos[] = "A garantia sugerida pela IA (\"{$textoGarantia}\") não casa com as opções da categoria; defina-a no card de envio.";
            }
        }

        if ($campos !== []) {
            $this->editor->salvar($r, $campos);
        }

        // ─── Fatia 2: variações; sem elas, a variante única recebe estoque e preço ───
        $variacoes = $this->aplicarVariacoes($r->fresh(), $ficha, $cliente, $schema, $sobrescrever, $avisos);
        if ($variacoes) {
            $secoes['variacoes'] = true;
            $secoes['variantes'] = true;
        } elseif ($this->varianteUnica($r->fresh(), $cliente, $sobrescrever)) {
            $secoes['variantes'] = true;
        }

        $aviso = $avisos === [] ? null : implode(' ', $avisos);

        return $this->resumo($r->id, count(array_filter($secoes)), $variacoes, $aviso, $sobrescrever);
    }

    // ═══ Fatia 2: variações ══════════════════════════════════════════════════

    /** @return bool gravou eixos e variantes */
    private function aplicarVariacoes(PubRascunho $r, array $ficha, ?array $cliente, ?SchemaClassificado $schema, bool $sobrescrever, array &$avisos): bool
    {
        $ia = array_values(array_filter((array) ($ficha['variacoes'] ?? []), 'is_array'));
        if ($ia === []) {
            return false;
        }
        if ($this->repo->snapshot($r)->eixos !== [] && ! $sobrescrever) {
            $avisos[] = 'O rascunho já tem variações; a IA não mudou as variações existentes.';

            return false;
        }

        // Eixos na ordem da primeira aparição; valores sem repetir.
        $eixos = [];
        foreach ($ia as $variacao) {
            foreach ((array) ($variacao['attribute_combinations'] ?? []) as $c) {
                $id = (string) ($c['id'] ?? '');
                $nome = trim((string) ($c['value_name'] ?? ''));
                if ($id === '' || ($nome === '' && empty($c['value_id']))) {
                    continue;
                }
                $eixos[$id] ??= [
                    'chave' => $id,
                    'nome' => (string) ($c['name'] ?? $id),
                    'defines_picture' => $schema?->atributo($id)?->definePicture ?? false,
                    'valores' => [],
                ];
                $eixos[$id]['valores'][$this->chaveDoValor($c['value_id'] ?? null, $nome)] ??= [
                    'id' => isset($c['value_id']) && $c['value_id'] !== '' ? (string) $c['value_id'] : null,
                    'nome' => $nome,
                ];
            }
        }

        $limite = (int) config('publicador.max_eixos', 3);
        if (count($eixos) > $limite) {
            $avisos[] = 'A IA sugeriu mais variações do que o Mercado Livre aceita; defina-as no card Variações.';

            return false;
        }
        if ($eixos === []) {
            return false;
        }

        $eixos = array_values(array_map(fn ($e) => ['valores' => array_values($e['valores'])] + $e, $eixos));

        try {
            $this->editor->salvarEixos($r, $eixos);
        } catch (RegraViolada $e) {
            $avisos[] = 'As variações da IA não puderam ser aplicadas: '.$e->getMessage();

            return false;
        }

        // Cada variante do motor recebe os dados da combinação da IA que casa com ela.
        $porChave = [];
        foreach ($this->repo->snapshot($r->fresh())->variantes as $v) {
            $alvo = $this->mapaDaVariante($v);
            foreach ($ia as $variacao) {
                if ($this->mapaDaVariacao($variacao) !== $alvo) {
                    continue;
                }
                $porChave[$v->chave] = $this->dadosDaVariacao($v, $variacao, $cliente);
                break;
            }
        }
        if ($porChave !== []) {
            $this->editor->salvarVariantes($r->fresh(), $porChave);
        }

        return true;
    }

    /** @return array<string, string> chave do eixo → chave do valor, ordenado */
    private function mapaDaVariante(Variante $v): array
    {
        $mapa = array_map(fn (ValorEixo $x) => $this->chaveDoValor($x->valueId, $x->valueName), $v->valores);
        ksort($mapa);

        return $mapa;
    }

    private function mapaDaVariacao(array $variacao): array
    {
        $mapa = [];
        foreach ((array) ($variacao['attribute_combinations'] ?? []) as $c) {
            $mapa[(string) ($c['id'] ?? '')] = $this->chaveDoValor($c['value_id'] ?? null, trim((string) ($c['value_name'] ?? '')));
        }
        ksort($mapa);

        return $mapa;
    }

    /** value_id quando houver; senão o nome normalizado (minúsculas, sem espaços nas pontas). */
    private function chaveDoValor(mixed $valueId, string $nome): string
    {
        return $valueId !== null && $valueId !== '' ? 'id:'.$valueId : 'nome:'.mb_strtolower(trim($nome));
    }

    private function dadosDaVariacao(Variante $v, array $variacao, ?array $cliente): array
    {
        $preco = $this->numeroPositivo($variacao['price'] ?? null);
        // O preço único da variação vale para o Clássico; o Premium prefere o preço Premium do cliente.
        $precos = (array) ($v->dados['precos'] ?? []);
        $especial = $preco ?? $this->numeroPositivo($cliente['preco_c'] ?? null);
        $pro = $this->numeroPositivo($cliente['preco_p'] ?? null) ?? $preco;
        if ($especial !== null) {
            $precos['gold_special'] = $especial;
        }
        if ($pro !== null) {
            $precos['gold_pro'] = $pro;
        }

        $atributos = (array) ($v->dados['atributos'] ?? []);
        foreach ((array) ($variacao['attributes'] ?? []) as $at) {
            $id = (string) ($at['id'] ?? '');
            $nome = trim((string) ($at['value_name'] ?? ''));
            if (in_array($id, ['SELLER_SKU', 'GTIN'], true) && $nome !== '') {
                $atributos[$id] = ['value_name' => $nome];
            }
        }

        $dados = ['precos' => $precos, 'atributos' => $atributos];
        if (isset($variacao['available_quantity']) && is_numeric($variacao['available_quantity'])) {
            $dados['estoque'] = max(0, (int) $variacao['available_quantity']);
        }

        return $dados;
    }

    /** @return list<string> ids de atributo que a IA usou como variação */
    private function idsDeVariacao(array $ficha): array
    {
        $ids = [];
        foreach ((array) ($ficha['variacoes'] ?? []) as $v) {
            foreach ((array) ($v['attribute_combinations'] ?? []) as $c) {
                $ids[] = (string) ($c['id'] ?? '');
            }
        }

        return array_values(array_unique(array_filter($ids)));
    }

    // ═══ Variante única ══════════════════════════════════════════════════════

    /** Estoque e preços do cliente na variante única, só onde está vazio (ou sobrescrevendo). */
    private function varianteUnica(PubRascunho $r, ?array $cliente, bool $sobrescrever): bool
    {
        if ($cliente === null) {
            return false;
        }
        $s = $this->repo->snapshot($r);
        if ($s->eixos !== []) {
            return false;
        }
        $unica = collect($s->variantes)->first(fn (Variante $v) => $v->chave === ChaveCanonica::UNICA);
        if (! $unica) {
            return false;
        }

        $novo = [];
        $estoque = $cliente['estoque'] ?? null;
        if (is_numeric($estoque) && ((int) $estoque >= 0) && (($unica->dados['estoque'] ?? null) === null || $sobrescrever)) {
            $novo['estoque'] = (int) $estoque;
        }

        $precos = (array) ($unica->dados['precos'] ?? []);
        $mudouPreco = false;
        foreach (['gold_special' => 'preco_c', 'gold_pro' => 'preco_p'] as $tipo => $campo) {
            $valor = $this->numeroPositivo($cliente[$campo] ?? null);
            if ($valor !== null && (($precos[$tipo] ?? null) === null || $sobrescrever)) {
                $precos[$tipo] = $valor;
                $mudouPreco = true;
            }
        }
        if ($mudouPreco) {
            $novo['precos'] = $precos;
        }
        if ($novo === []) {
            return false;
        }

        $this->editor->salvarVariantes($r, [ChaveCanonica::UNICA => $novo]);

        return true;
    }

    // ═══ Apoio ═══════════════════════════════════════════════════════════════

    private function schemaDoRascunho(PubRascunho $r): ?SchemaClassificado
    {
        if (! $r->categoria_id) {
            return null;
        }
        try {
            $s = $this->repo->snapshot($r);
            $eixos = array_values(array_filter(array_map(fn (Eixo $e) => $e->attributeId(), $s->eixos)));

            return (new ClassificadorAtributos())->classificar($this->schemas->obter($r->categoria_id), new ContextoClassificacao($s->condicao, $eixos));
        } catch (RegraViolada) {
            return null;
        }
    }

    /**
     * Características da ficha da IA no formato do rascunho, só dos ids do schema que são do PRODUTO
     * (nada de sistema, SKU/GTIN de variante nem atributo que a IA usou como variação).
     *
     * @param  list<string>  $idsDeVariacao
     * @return array<string, array>
     */
    private function atributosDaIa(array $ficha, SchemaClassificado $schema, array $idsDeVariacao): array
    {
        $saida = [];
        foreach ((array) ($ficha['atributos'] ?? []) as $item) {
            if (! is_array($item)) {
                continue;
            }
            $id = (string) ($item['id'] ?? '');
            $def = $schema->atributo($id);
            if ($def === null || $def->papel !== AtributoClassificado::PRODUCT || in_array($id, $idsDeVariacao, true)
                || $def->secao === AtributoClassificado::SECAO_EMBALAGEM) {
                continue;
            }

            $valor = [];
            if (isset($item['value_id']) && $item['value_id'] !== '') {
                // Lista fechada: o id tem de existir no schema (a IA não confiável não inventa opção).
                if ($def->valores !== [] && ! in_array((string) $item['value_id'], array_column($def->valores, 'id'), true)) {
                    continue;
                }
                $valor['value_id'] = (string) $item['value_id'];
            }
            if (isset($item['value_name']) && trim((string) $item['value_name']) !== '') {
                $valor['value_name'] = mb_substr(trim((string) $item['value_name']), 0, 255);
            }
            if (isset($item['value_number']) && is_numeric($item['value_number'])) {
                $valor['value_number'] = (float) $item['value_number'];
                if (isset($item['value_unit']) && $item['value_unit'] !== '') {
                    $valor['value_unit'] = (string) $item['value_unit'];
                }
            }
            if ($valor !== []) {
                $saida[$id] = $valor;
            }
        }

        return $saida;
    }

    /** @return array<string, string> SELLER_PACKAGE_* → "N cm" / "N g", só o que veio */
    private function pacote(array $ficha): array
    {
        $p = is_array($ficha['pacote'] ?? null) ? $ficha['pacote'] : [];
        $saida = [];
        foreach (['SELLER_PACKAGE_HEIGHT' => ['altura_cm', 'cm'], 'SELLER_PACKAGE_WIDTH' => ['largura_cm', 'cm'],
            'SELLER_PACKAGE_LENGTH' => ['comprimento_cm', 'cm'], 'SELLER_PACKAGE_WEIGHT' => ['peso_g', 'g']] as $id => [$campo, $unidade]) {
            $n = $this->numeroPositivo($p[$campo] ?? null);
            if ($n !== null) {
                $saida[$id] = rtrim(rtrim(number_format($n, 2, '.', ''), '0'), '.').' '.$unidade;
            }
        }

        return $saida;
    }

    /**
     * "90 dias" / "12 meses" / "1 ano" → {tipo, tempo, unidade} com os ids do schema
     * (garantia do vendedor); "sem garantia" → tipo Sem garantia. Sem correspondência: null.
     */
    private function garantia(string $texto, SchemaClassificado $schema): ?array
    {
        $tipos = (array) ($schema->garantia['tipos'] ?? []);
        $unidades = (array) ($schema->garantia['unidades'] ?? []);
        $achar = fn (string $trecho) => collect($tipos)->first(fn ($t) => str_contains(mb_strtolower((string) $t['name']), $trecho))['id'] ?? null;

        if (preg_match('/^\s*sem\s+garantia\s*$/iu', $texto)) {
            $semGarantia = $achar('sem garantia');

            return $semGarantia !== null ? ['tipo' => (string) $semGarantia, 'tempo' => null, 'unidade' => null] : null;
        }
        if (! preg_match('/^\s*(\d{1,3})\s*(dias?|m[eê]s(?:es)?|anos?)\s*$/iu', $texto, $m) || (int) $m[1] <= 0) {
            return null;
        }

        $unidade = match (true) {
            str_starts_with(mb_strtolower($m[2]), 'dia') => 'dias',
            str_starts_with(mb_strtolower($m[2]), 'ano') => 'anos',
            default => 'meses',
        };
        $vendedor = $achar('vendedor');
        if ($vendedor === null || ! in_array($unidade, $unidades, true)) {
            return null;
        }

        return ['tipo' => (string) $vendedor, 'tempo' => (int) $m[1], 'unidade' => $unidade];
    }

    private function garantiaVazia(?array $g): bool
    {
        return $g === null || trim((string) ($g['tipo'] ?? '')) === '';
    }

    private function preenchido(?array $valor): bool
    {
        return $valor !== null && (trim((string) ($valor['value_id'] ?? '')) !== ''
            || trim((string) ($valor['value_name'] ?? '')) !== ''
            || isset($valor['value_number']));
    }

    /** A IA escreve em HTML simples; o rascunho guarda texto puro. */
    private function limparDescricao(?string $html): string
    {
        $texto = preg_replace('~</(p|li|h[1-6]|div)>|<br\s*/?>~i', "\n", (string) $html);
        $texto = html_entity_decode(strip_tags((string) $texto), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim((string) preg_replace("/\n{3,}/", "\n\n", str_replace("\r", '', $texto)));
    }

    private function numeroPositivo(mixed $v): ?float
    {
        if (is_string($v)) {
            $v = str_replace(',', '.', trim($v));
        }

        return is_numeric($v) && (float) $v > 0 ? (float) $v : null;
    }

    private function resumo(?int $rascunhoId, int $secoes, bool $variacoes, ?string $aviso, bool $sobrescreveu): array
    {
        return [
            'rascunho_id' => $rascunhoId,
            'aplicado_em' => now()->toIso8601String(),
            'secoes' => $secoes,
            'variacoes' => $variacoes,
            'aviso' => $aviso,
            'sobrescreveu' => $sobrescreveu,
        ];
    }
}
