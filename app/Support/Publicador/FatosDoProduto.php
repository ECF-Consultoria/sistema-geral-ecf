<?php

namespace App\Support\Publicador;

use Illuminate\Support\Str;

/**
 * O que é VERDADE sobre o produto, para o campo Modelo (09/10/2026).
 *
 * Caso que originou: "Puff Redondo" anunciado só em Azul saiu com "puff
 * gigante, puff colorido, puff infantil, puff rosa, puff azul marinho" — a IA
 * só recebia nome, categoria e termos de tendência, e completou com o que o
 * ML diz ser buscado, não com o que o produto é.
 *
 * Duas funções: `paraPrompt()` leva os fatos à IA, e `motivoParaDescartar()`
 * é o filtro determinístico do servidor (a IA não obedece sempre). O filtro
 * cobre três famílias com vocabulário EXPLÍCITO — cor, público e tamanho — e
 * só descarta o termo que cita uma delas sem que os fatos confirmem.
 *
 * Puro: quem lê o rascunho e monta isto é o `PalavrasChaveService`.
 */
final class FatosDoProduto
{
    public const MOTIVO_COR = 'cita cor que o anúncio não tem';

    public const MOTIVO_PUBLICO = 'cita público que a ficha não confirma';

    public const MOTIVO_TAMANHO = 'cita tamanho que a ficha não confirma';

    /** Cores (forma → canônica). "off white" vira "offwhite" antes (`palavras()`). */
    private const CORES = [
        'azul' => 'azul', 'azuis' => 'azul',
        'vermelho' => 'vermelho', 'vermelha' => 'vermelho', 'vermelhos' => 'vermelho', 'vermelhas' => 'vermelho',
        'rosa' => 'rosa', 'rosas' => 'rosa', 'pink' => 'rosa',
        'verde' => 'verde', 'verdes' => 'verde',
        'amarelo' => 'amarelo', 'amarela' => 'amarelo', 'amarelos' => 'amarelo', 'amarelas' => 'amarelo',
        'preto' => 'preto', 'preta' => 'preto', 'pretos' => 'preto', 'pretas' => 'preto',
        'branco' => 'branco', 'branca' => 'branco', 'brancos' => 'branco', 'brancas' => 'branco',
        'cinza' => 'cinza', 'cinzas' => 'cinza',
        'marrom' => 'marrom', 'marrons' => 'marrom',
        'bege' => 'bege', 'beges' => 'bege',
        'roxo' => 'roxo', 'roxa' => 'roxo', 'roxos' => 'roxo', 'roxas' => 'roxo',
        'lilas' => 'lilas', 'violeta' => 'violeta', 'laranja' => 'laranja',
        'dourado' => 'dourado', 'dourada' => 'dourado', 'dourados' => 'dourado', 'douradas' => 'dourado',
        'prateado' => 'prateado', 'prateada' => 'prateado', 'prateados' => 'prateado', 'prateadas' => 'prateado',
        'nude' => 'nude', 'caramelo' => 'caramelo', 'offwhite' => 'offwhite', 'marinho' => 'marinho',
        'vinho' => 'vinho', 'bordo' => 'bordo', 'grafite' => 'grafite', 'creme' => 'creme',
        'turquesa' => 'turquesa', 'coral' => 'coral', 'salmao' => 'salmao',
    ];

    /** Cor "genérica": só com 3+ cores no anúncio ou ficha que diga estampado. */
    private const CORES_GENERICAS = [
        'colorido' => 'colorido', 'colorida' => 'colorido', 'coloridos' => 'colorido', 'coloridas' => 'colorido',
        'multicolor' => 'colorido', 'multicolorido' => 'colorido', 'multicolorida' => 'colorido',
        'estampado' => 'colorido', 'estampada' => 'colorido', 'estampados' => 'colorido', 'estampadas' => 'colorido',
        'estampa' => 'colorido', 'estampas' => 'colorido',
    ];

    /** Público (forma → canônica). */
    private const PUBLICO = [
        'infantil' => 'infantil', 'infantis' => 'infantil', 'crianca' => 'infantil', 'criancas' => 'infantil', 'kids' => 'infantil', 'kid' => 'infantil',
        'menino' => 'menino', 'meninos' => 'menino', 'menina' => 'menina', 'meninas' => 'menina',
        'bebe' => 'bebe', 'bebes' => 'bebe', 'baby' => 'bebe',
        'juvenil' => 'juvenil', 'juvenis' => 'juvenil',
        'adulto' => 'adulto', 'adultos' => 'adulto', 'adulta' => 'adulto', 'adultas' => 'adulto',
        'idoso' => 'idoso', 'idosos' => 'idoso', 'idosa' => 'idoso', 'idosas' => 'idoso',
        'gamer' => 'gamer', 'gamers' => 'gamer',
        'pet' => 'pet', 'pets' => 'pet',
    ];

    /** Que palavras dos fatos confirmam cada público ("Bebês" confirma "infantil"; o contrário não). */
    private const CONFIRMA_PUBLICO = [
        'infantil' => ['infantil', 'menino', 'menina', 'bebe', 'juvenil'],
        'menino' => ['menino'],
        'menina' => ['menina'],
        'bebe' => ['bebe'],
        'juvenil' => ['juvenil'],
        'adulto' => ['adulto'],
        'idoso' => ['idoso'],
        'gamer' => ['gamer'],
        'pet' => ['pet', 'cachorro', 'cachorros', 'cao', 'caes', 'gato', 'gatos', 'animal', 'animais'],
    ];

    /** Tamanho (forma → canônica). Lista enxuta de propósito. */
    private const TAMANHO = [
        'gigante' => 'gigante', 'gigantes' => 'gigante',
        'grande' => 'grande', 'grandes' => 'grande',
        'enorme' => 'enorme', 'enormes' => 'enorme',
        'mini' => 'mini',
        'pequeno' => 'pequeno', 'pequena' => 'pequeno', 'pequenos' => 'pequeno', 'pequenas' => 'pequeno',
        'compacto' => 'compacto', 'compacta' => 'compacto', 'compactos' => 'compacto', 'compactas' => 'compacto',
        'xl' => 'xl', 'g' => 'g', 'gg' => 'gg',
    ];

    /** @var array<string, true> palavras canônicas das cores do anúncio */
    private array $palavrasDasCores = [];

    /** @var array<string, true> palavras (canônicas) que confirmam público/tamanho/estampado */
    private array $confirmadas = [];

    /** @var array<string, true> palavras do nome e da categoria: ali uma "cor" é o produto ("taça vinho") */
    private array $doProduto = [];

    /**
     * @param  list<string>  $cores  das variantes ativas (ou da ficha, sem variação)
     * @param  list<array{0: string, 1: string}>  $ficha  [nome, valor] — texto da ficha técnica
     * @param  list<array{0: string, 1: string}>  $medidas  [nome, valor com unidade]
     * @param  list<array{0: string, 1: string}>  $publico  [nome, valor] — idade, gênero
     * @param  string  $contexto  nome do produto + caminho da categoria + título (confirma, não vai ao prompt)
     */
    public function __construct(
        public readonly array $cores = [],
        public readonly array $ficha = [],
        public readonly array $medidas = [],
        public readonly array $publico = [],
        public readonly string $produto = '',
        public readonly string $contexto = '',
    ) {
        foreach ($cores as $c) {
            foreach (self::palavras($c) as $p) {
                $this->palavrasDasCores[self::CORES[$p] ?? self::CORES_GENERICAS[$p] ?? $p] = true;
            }
        }
        foreach (self::palavras($produto) as $p) {
            $this->doProduto[$p] = true;
        }
        // Medida (número) não confirma nada: "500 g" não é tamanho G.
        $texto = implode(' ', [$produto, $contexto, ...array_column($ficha, 1), ...array_column($publico, 1)]);
        foreach (self::palavras($texto) as $p) {
            $this->confirmadas[$p] = true;
            $this->confirmadas[self::PUBLICO[$p] ?? self::TAMANHO[$p] ?? self::CORES_GENERICAS[$p] ?? $p] = true;
        }
    }

    public function vazio(): bool
    {
        return $this->cores === [] && $this->ficha === [] && $this->medidas === [] && $this->publico === [];
    }

    /**
     * Nulo = o termo pode entrar; senão, o motivo curto (vai para a tela).
     */
    public function motivoParaDescartar(string $termo): ?string
    {
        $palavras = array_filter(self::palavras($termo), fn ($p) => ! isset($this->doProduto[$p]));

        foreach ($palavras as $p) {
            if (isset(self::CORES_GENERICAS[$p])) {
                // "colorido/estampado": só com 3+ cores ou ficha que diga estampado.
                if (count($this->cores) < 3 && ! isset($this->confirmadas['colorido']) && ! isset($this->palavrasDasCores['colorido'])) {
                    return self::MOTIVO_COR;
                }
            } elseif (isset(self::CORES[$p]) && ! isset($this->palavrasDasCores[self::CORES[$p]])) {
                // "azul marinho" com anúncio "Azul": o "marinho" não está nas cores — é outra cor.
                return self::MOTIVO_COR;
            }
        }
        foreach ($palavras as $p) {
            if (isset(self::PUBLICO[$p]) && array_intersect(self::CONFIRMA_PUBLICO[self::PUBLICO[$p]], array_keys($this->confirmadas)) === []) {
                return self::MOTIVO_PUBLICO;
            }
            if (isset(self::TAMANHO[$p]) && ! isset($this->confirmadas[self::TAMANHO[$p]])) {
                return self::MOTIVO_TAMANHO;
            }
        }

        return null;
    }

    /** O bloco "FATOS DO PRODUTO" do prompt do Modelo. */
    public function paraPrompt(): string
    {
        $linha = fn (array $par) => "  - {$par[0]}: {$par[1]}";
        $cores = $this->cores === []
            ? 'não informadas (não use nenhum termo com cor)'
            : implode(', ', $this->cores).(count($this->cores) === 1 ? ' (uma cor só)' : '');
        $partes = ["- Cores do anúncio: {$cores}"];
        $partes[] = $this->publico === []
            ? '- Público/idade: não informado (não cite público)'
            : "- Público/idade:\n".implode("\n", array_map($linha, $this->publico));
        $partes[] = $this->medidas === []
            ? '- Medidas: não informadas (não cite tamanho)'
            : "- Medidas:\n".implode("\n", array_map($linha, $this->medidas));
        if ($this->ficha !== []) {
            $partes[] = "- Ficha técnica:\n".implode("\n", array_map($linha, $this->ficha));
        }

        return implode("\n", $partes);
    }

    /**
     * Minúsculas, sem acento, só letras e números; "off white" vira uma palavra.
     *
     * @return list<string>
     */
    public static function palavras(string $texto): array
    {
        $t = Str::lower(Str::ascii($texto));
        $t = (string) preg_replace('/\boff[\s-]*white\b/', 'offwhite', $t);

        return preg_split('/[^a-z0-9]+/', $t, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }
}
