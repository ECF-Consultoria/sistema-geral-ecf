<?php

namespace App\Services\Publicador;

use App\Models\Company;
use App\Models\EstruturaOferta;
use App\Models\MlbEmpresa;
use App\Models\PubProduto;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * §6 da ETAPA-3: combos JÁ cadastrados. Dado um produto que ainda não é fase de
 * ninguém, descobrir se ele é, na verdade, o kit de outro produto da MESMA conta.
 *
 * Só leitura. Este serviço NÃO grava nada — quem grava o vínculo é o 175-08/175-09,
 * sempre com confirmação humana. Aqui a regra é o contrário de adivinhar: o usuário
 * confirma vínculos, nunca corrige palpites errados, então ambiguidade (dois
 * candidatos possíveis) e kit misto NUNCA sugerem.
 *
 * ### Duas camadas, na ordem fato → heurística
 * A §6 propõe duas heurísticas de texto (prefixo de SKU, prefixo de nome) e um
 * detector de kit misto por `"+"`/`" e "` no nome. Mas o Portal **já modela a
 * composição**: `estrutura_oferta_componentes` guarda "Cadeira 01 ×4" e
 * {@see \App\Models\EstruturaOferta::FASES} classifica a oferta em
 * `simples | combo | kit | combit`. Para produto de origem Portal isso é FATO:
 *
 * - oferta `simples` → não é kit, fim;
 * - oferta com **2+ componentes** → kit misto **por construção** (nem olha o nome);
 * - oferta com **exatamente 1** componente de quantidade ≥ 2 → base e N exatos.
 *
 * A heurística de texto continua valendo, intacta, para produto **sem oferta**.
 *
 * ⚠️ Armadilha de nome: `estrutura_ofertas.fase` é o TIPO da oferta no Portal
 * (string), enquanto `pub_produtos.fase` é o NÚMERO da fase do Publicador. Por isso
 * toda consulta aqui qualifica a tabela.
 */
class SugestaoDeKitService
{
    /** Separadores aceitos entre o SKU do base e o sufixo do kit: `CAD-CB2`, `CAD_KIT2`. */
    private const SEPARADORES_SKU = ['-', '_', '.', ' ', '/'];

    /**
     * Os padrões de prefixo da §6, ancorados no começo e NESTA ordem
     * (`unidades` antes de `un`, senão "4 unidades" casaria como "4 un" + "idades").
     * O separador entre o prefixo e o nome do base é opcional (`-`, `:`, espaço).
     */
    private const PADROES_NOME = [
        '/^kit\s+(\d+)\s*[-:]?\s*(.*)$/',
        '/^combo\s+(\d+)\s*[-:]?\s*(.*)$/',
        '/^(\d+)\s*unidades?\b\s*[-:]?\s*(.*)$/',
        '/^(\d+)\s*un\b\s*[-:]?\s*(.*)$/',
    ];

    public function __construct(private ProgramasPublicadorService $programas) {}

    // ═══════════════════════════════════════════════════════════════
    // A PARTE PURA — nada aqui consulta o banco
    // ═══════════════════════════════════════════════════════════════

    /**
     * A forma única de comparação: minúsculas, sem acento, espaços colapsados e
     * pontas aparadas. Toda comparação de SKU e de nome passa por aqui — duas
     * normalizações diferentes é como "Cadeira Escritório" deixa de casar com
     * "CADEIRA ESCRITORIO".
     */
    public static function normalizar(string $t): string
    {
        $t = mb_strtolower(Str::ascii($t));

        return trim((string) preg_replace('/\s+/', ' ', $t));
    }

    /**
     * O SKU do candidato começa com o do base **seguido de um separador**.
     *
     * Nunca substring livre: `CAD` casa `CAD-CB2` e `CAD_KIT2`, mas NÃO `CADEIRA`
     * (produto diferente) nem `CAD` (é o próprio).
     */
    public static function porSku(string $candidato, string $base): bool
    {
        $c = self::normalizar($candidato);
        $b = self::normalizar($base);

        if ($c === '' || $b === '' || $c === $b) {
            return false;
        }

        foreach (self::SEPARADORES_SKU as $sep) {
            if (str_starts_with($c, $b.$sep)) {
                return true;
            }
        }

        return false;
    }

    /**
     * O nome do candidato começa por um dos padrões de prefixo e, tirado o prefixo,
     * o resto começa com o nome do base. Devolve o N; null quando não casa.
     *
     * Kit de 1 unidade (ou 0) NÃO casa: `quantidade_kit = 1` é o valor das bases no
     * unique `pubprod_base_qtd_uq` (175-01), e um "kit" de uma unidade é o próprio
     * produto — sugerir isso seria justamente o palpite errado que a §6 proíbe.
     */
    public static function porNome(string $candidato, string $base): ?int
    {
        $c = self::normalizar($candidato);
        $b = self::normalizar($base);

        if ($c === '' || $b === '') {
            return null;
        }

        foreach (self::PADROES_NOME as $padrao) {
            if (! preg_match($padrao, $c, $m)) {
                continue;
            }

            $n = (int) $m[1];
            $resto = trim($m[2]);

            if ($n < 2 || ! str_starts_with($resto, $b)) {
                return null;
            }

            return $n;
        }

        return null;
    }

    /**
     * Kit misto nunca recebe sugestão: `+` em qualquer posição, ou a palavra inteira
     * `e` entre dois trechos com pelo menos uma letra cada.
     *
     * ⚠️ Fronteira de palavra obrigatória. `str_contains(' e ')` solto pegaria
     * "Mesa de Jantar" se alguém trocasse o separador, e um `str_contains('e')`
     * pegaria qualquer nome. O literal da §9 que TEM de dar true:
     * `Combit 4 Cadeira Escritório + 1 MESA REDONDA`.
     */
    public static function ehMisto(string $nome): bool
    {
        $n = self::normalizar($nome);

        if (str_contains($n, '+')) {
            return true;
        }

        // Letra antes, conjuncao isolada, letra depois.
        return (bool) preg_match('/[a-z].*\be\b.*[a-z]/', $n);
    }

    /**
     * Exatamente um candidato ou null. Ambiguidade nunca vira palpite.
     *
     * O MESMO base casado por SKU e por nome é UM candidato, não dois — o primeiro
     * da lista ganha, e quem monta a lista põe na frente o casamento que traz a
     * quantidade (o do nome).
     *
     * @param  list<array{base_id: int, base_sku: string, base_nome: string, quantidade: ?int, origem: string}>  $candidatos
     * @return array{base_id: int, base_sku: string, base_nome: string, quantidade: ?int, origem: string}|null
     */
    public static function escolher(array $candidatos): ?array
    {
        $porBase = [];
        foreach ($candidatos as $c) {
            $porBase[$c['base_id']] ??= $c;
        }

        return count($porBase) === 1 ? reset($porBase) : null;
    }

    // ═══════════════════════════════════════════════════════════════
    // AS DUAS CAMADAS — aqui entra o banco, sempre pelo escopo da conta
    // ═══════════════════════════════════════════════════════════════

    /**
     * A sugestão de vínculo para UM produto, ou null quando não há o que sugerir.
     *
     * @return array{base_id: int, base_sku: string, base_nome: string, quantidade: ?int, origem: 'portal'|'sku'|'nome', conflito_heuristica: bool}|null
     */
    public function sugerirPara(PubProduto $p): ?array
    {
        // Guardas puras ANTES de qualquer consulta: "não é kit" encerra o assunto.
        if ($p->produto_base_id !== null || $p->kit_sugestao_recusada_em !== null) {
            return null;
        }

        return $this->calcular($p, $this->contexto(
            $p->mlb_empresa_id ? MlbEmpresa::find($p->mlb_empresa_id) : null,
            $p->company_id ? Company::find($p->company_id) : null,
        ));
    }

    /**
     * As sugestões da conta inteira, prontas para a coluna Fases da lista de Produtos
     * (175-09). Um lote de consultas para a conta — nunca uma consulta por produto.
     *
     * @return array<int, array{base_id: int, base_sku: string, base_nome: string, quantidade: ?int, origem: 'portal'|'sku'|'nome', conflito_heuristica: bool}>
     *                                                                                                                                                         indexado pelo `id` do produto; produto sem sugestão não aparece
     */
    public function candidatosDaConta(?MlbEmpresa $e, ?Company $c): array
    {
        $ctx = $this->contexto($e, $c);

        $saida = [];
        foreach ($ctx['produtos'] as $p) {
            if ($p->produto_base_id !== null || $p->kit_sugestao_recusada_em !== null) {
                continue;
            }
            $sugestao = $this->calcular($p, $ctx);
            if ($sugestao !== null) {
                $saida[(int) $p->id] = $sugestao;
            }
        }

        return $saida;
    }

    /**
     * Tudo que as duas camadas precisam, numa leitura por assunto.
     *
     * T-175-09: os produtos saem SEMPRE de `ProgramasPublicadorService::produtosQuery()`
     * — é esse escopo que impede sugerir (e portanto exibir) o SKU/nome de outra conta.
     *
     * @return array{produtos: Collection<int, PubProduto>, bases: Collection<int, PubProduto>, base_de_alguem: array<int, true>, ofertas: Collection<int, EstruturaOferta>, produto_por_oferta: Collection<int, PubProduto>}
     */
    private function contexto(?MlbEmpresa $e, ?Company $c): array
    {
        $produtos = $this->programas->produtosQuery($e, $c)->with('oferta')->get();

        $baseDeAlguem = [];
        foreach ($produtos as $q) {
            if ($q->produto_base_id !== null) {
                $baseDeAlguem[(int) $q->produto_base_id] = true;
            }
        }

        $ofertaIds = $produtos->pluck('oferta_id')->filter()->unique()->values()->all();
        $ofertas = $ofertaIds === []
            ? collect()
            : EstruturaOferta::query()->whereIn('id', $ofertaIds)->with('componentes')->get()->keyBy('id');

        return [
            'produtos' => $produtos,
            // Base possível: produto da conta que NÃO é kit (não existe cadeia de kits).
            'bases' => $produtos->filter(fn (PubProduto $q) => $q->produto_base_id === null)->values(),
            'base_de_alguem' => $baseDeAlguem,
            'ofertas' => $ofertas,
            'produto_por_oferta' => $produtos->filter(fn (PubProduto $q) => $q->oferta_id !== null)->keyBy('oferta_id'),
        ];
    }

    /** @param  array<string, mixed>  $ctx */
    private function calcular(PubProduto $p, array $ctx): ?array
    {
        if ($p->produto_base_id !== null || $p->kit_sugestao_recusada_em !== null) {
            return null;
        }

        // Quem já é base de um kit é o começo de uma família, não o fim de outra.
        if (isset($ctx['base_de_alguem'][(int) $p->id])) {
            return null;
        }

        return $p->oferta_id !== null
            ? $this->porFatoDoPortal($p, $ctx)
            : $this->porHeuristica($p, $ctx);
    }

    /**
     * Camada de FATO: a composição da oferta do Portal decide, sem olhar o nome.
     *
     * ⚠️ `$oferta->fase` aqui é o TIPO da oferta (`simples|combo|kit|combit`), nunca o
     * número da fase do Publicador — ver o aviso de colisão em {@see PubProduto}.
     *
     * Quando a composição diz "é combo" mas o componente não tem produto nesta conta,
     * a resposta é null e NÃO cai na heurística: adivinhar um base por nome contra um
     * fato parcial é pior que não sugerir nada.
     *
     * @param  array<string, mixed>  $ctx
     */
    private function porFatoDoPortal(PubProduto $p, array $ctx): ?array
    {
        /** @var ?EstruturaOferta $oferta */
        $oferta = $ctx['ofertas'][$p->oferta_id] ?? null;
        if ($oferta === null || $oferta->fase === EstruturaOferta::FASE_SIMPLES) {
            return null;
        }

        $componentes = $oferta->componentes;

        // 2+ componentes = kit misto POR CONSTRUÇÃO. 0 = a composição não diz nada.
        if ($componentes->count() !== 1) {
            return null;
        }

        $componente = $componentes->first();
        $quantidade = (int) $componente->quantidade;
        if ($quantidade < 2) {
            return null;
        }

        /** @var ?PubProduto $base */
        $base = $ctx['produto_por_oferta'][$componente->componente_id] ?? null;
        if ($base === null || $base->produto_base_id !== null || (int) $base->id === (int) $p->id) {
            return null;
        }

        $sugestao = $this->linha($base, $quantidade, 'portal');

        // A heurística roda só por diagnóstico: vale o Portal, mas a tela e o log
        // merecem saber que o nome do produto aponta para outro base.
        $diagnostico = $this->heuristica($p, $ctx);
        $sugestao['conflito_heuristica'] = $diagnostico !== null && $diagnostico['base_id'] !== $sugestao['base_id'];

        return $sugestao;
    }

    /**
     * Camada de HEURÍSTICA (só para produto sem oferta): prefixo de SKU ou de nome,
     * exatamente um candidato, nunca kit misto.
     *
     * @param  array<string, mixed>  $ctx
     */
    private function porHeuristica(PubProduto $p, array $ctx): ?array
    {
        if (self::ehMisto($p->nomeExibido())) {
            return null;
        }

        $achado = $this->heuristica($p, $ctx);

        return $achado === null ? null : $achado + ['conflito_heuristica' => false];
    }

    /**
     * O casamento por texto contra os bases da conta. O casamento por NOME vem antes
     * do por SKU porque é o único que traz a quantidade.
     *
     * @param  array<string, mixed>  $ctx
     */
    private function heuristica(PubProduto $p, array $ctx): ?array
    {
        $sku = $p->skuExibido();
        $nome = $p->nomeExibido();

        $candidatos = [];
        /** @var PubProduto $base */
        foreach ($ctx['bases'] as $base) {
            if ((int) $base->id === (int) $p->id) {
                continue;
            }

            $n = self::porNome($nome, $base->nomeExibido());
            if ($n !== null) {
                $candidatos[] = $this->linha($base, $n, 'nome');

                continue;
            }

            if (self::porSku($sku, $base->skuExibido())) {
                // Sem N no nome a quantidade fica VAZIA, para a pessoa preencher (§6).
                $candidatos[] = $this->linha($base, null, 'sku');
            }
        }

        return self::escolher($candidatos);
    }

    /** @return array{base_id: int, base_sku: string, base_nome: string, quantidade: ?int, origem: string} */
    private function linha(PubProduto $base, ?int $quantidade, string $origem): array
    {
        return [
            'base_id' => (int) $base->id,
            'base_sku' => $base->skuExibido(),
            'base_nome' => $base->nomeExibido(),
            'quantidade' => $quantidade,
            'origem' => $origem,
        ];
    }
}
