<?php

namespace App\Services\Portal\Estrutura\Produtos;

use App\Support\TituloDoProduto;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;

/**
 * Os termos mais buscados DENTRO da célula do "Título de venda" da planilha (21/10/2026).
 *
 * O pedido do usuário: como o cliente escolhe a categoria antes de baixar a planilha, já levar as
 * sugestões e, na hora de escrever o título, ter essas opções "apenas na célula da coluna do Título" —
 * sem coluna nova. A célula ganha uma lista que só SUGERE (a seta da célula); o cliente continua
 * escrevendo o que quiser.
 *
 *  - No modelo vazio a planilha ainda não sabe o nome do produto: a seta traz os termos da categoria
 *    da aba, já com a maiúscula de cada palavra (o que se escolhe é o que se grava).
 *  - No "meus produtos" o nome já existe: a seta de cada produto traz TÍTULOS PRONTOS — o nome com as
 *    palavras novas de cada termo ({@see TituloDoProduto::juntarTermo()}), dentro do limite da
 *    categoria —, com o título de hoje em primeiro.
 *
 * Uma lista suspensa escolhe UM valor e troca o que estava na célula: não dá para ir somando termos
 * como na tela (só com macro, que não roda em arquivo baixado nem no Google Planilhas). Por isso, no
 * modelo vazio, o caminho é escolher o termo e completar escrevendo.
 *
 * Os termos são os mesmos da ficha do produto ({@see TituloDeVendaService}). Falha na leitura não
 * segura a planilha: a categoria fica sem a seta e o título continua sendo digitado. A leitura tem
 * um prazo TOTAL por arquivo ({@see PRAZO_SEGUNDOS}), porque o "meus produtos" pode ter dezenas de
 * categorias, e para de tentar depois da primeira falha.
 */
class TermosDoTituloNaPlanilha
{
    /** Quanto tempo o arquivo inteiro pode esperar pelos termos; depois disso as categorias seguem sem a seta. */
    public const PRAZO_SEGUNDOS = 20;

    /** Quantos títulos prontos a seta de cada produto oferece. */
    public const MAX_PRONTOS = 20;

    /** Palavras que não distinguem nada ao comparar o nome do produto com o termo. */
    private const LIGACAO = ['de', 'da', 'do', 'das', 'dos', 'para', 'pra', 'com', 'sem', 'em', 'no', 'na', 'nos', 'nas', 'um', 'uma', 'por'];

    private ?float $inicio = null;

    private bool $foraDoAr = false;

    public function __construct(private TituloDeVendaService $titulo) {}

    /**
     * Os termos de uma categoria, prontos para a seta: com a maiúscula de cada palavra, sem repetir,
     * sem os que o filtro de sigilo barra e sem os que passam do limite do título. `limite` nulo quando
     * a categoria não foi lida; `termos` vazio quando não há o que sugerir (a célula fica sem a seta).
     *
     * @param  callable(string): bool  $podeIr  o filtro de sigilo da planilha (nada que conte para onde o cadastro vai)
     * @return array{limite: ?int, termos: list<string>, caminho: list<string>}
     */
    public function daCategoria(string $categoria, callable $podeIr): array
    {
        $vazio = ['limite' => null, 'termos' => [], 'caminho' => []];
        $this->inicio ??= microtime(true);
        if ($this->foraDoAr || microtime(true) - $this->inicio > self::PRAZO_SEGUNDOS) {
            return $vazio;
        }

        $lido = $this->titulo->termos($categoria, '');
        $limite = (int) $lido['limite'];
        if ($lido['indisponivel']) {
            // Uma falha costuma ser o serviço fora do ar: as outras categorias do arquivo não esperam por ele.
            $this->foraDoAr = true;

            return ['limite' => $limite] + $vazio;
        }

        $termos = [];
        $vistos = [];
        foreach ($lido['termos'] ?? [] as $t) {
            $termo = (string) TituloDoProduto::capitalizar(trim((string) preg_replace('/\s+/u', ' ', (string) $t['termo'])));
            $chave = Str::lower(Str::ascii($termo));
            if ($termo === '' || isset($vistos[$chave]) || mb_strlen($termo) > $limite || ! $podeIr($termo)) {
                continue;
            }
            $vistos[$chave] = true;
            $termos[] = $termo;
        }

        return ['limite' => $limite, 'termos' => $termos, 'caminho' => array_values(array_map('strval', $lido['categoria']['caminho'] ?? []))];
    }

    /**
     * Os títulos prontos da seta de um produto: o título de hoje (se houver) e, depois, o nome com cada
     * termo — primeiro os termos que têm alguma palavra do nome que não é da categoria (a mesma pista
     * "relacionado" da tela: em "Mesa de Jantar Redonda", os termos com "redonda"), depois na ordem da
     * lista. Fica de fora o termo que não acrescenta palavra que conte (só "de", "com", número solto) e o
     * título que passa do limite. Vazio quando não há título novo para oferecer.
     *
     * @param  array{limite: ?int, termos: list<string>, caminho: list<string>}  $daCategoria  o que {@see daCategoria()} devolveu
     * @return list<string>
     */
    public static function titulosProntos(string $nome, string $tituloDeHoje, array $daCategoria, int $maximo = self::MAX_PRONTOS): array
    {
        $nome = trim($nome);
        if ($nome === '' || $daCategoria['termos'] === []) {
            return [];
        }
        $limite = (int) ($daCategoria['limite'] ?? LimiteDoTitulo::PADRAO);

        $doNome = array_diff_key(self::palavras($nome), self::palavras(implode(' ', $daCategoria['caminho'])));
        $relacionados = [];
        $outros = [];
        foreach ($daCategoria['termos'] as $termo) {
            if (array_intersect_key($doNome, self::palavras($termo)) !== []) {
                $relacionados[] = $termo;
            } else {
                $outros[] = $termo;
            }
        }

        $deHoje = trim($tituloDeHoje) === '' ? null : (string) TituloDoProduto::capitalizar(trim($tituloDeHoje));
        $saida = $deHoje === null ? [] : [$deHoje];
        $tudoDoNome = self::palavras($nome);
        foreach ([...$relacionados, ...$outros] as $termo) {
            // O termo precisa trazer palavra que conte: "Mesa De Jantar" sobre "Mesa Jantar Redonda" só
            // acrescentaria um "De" solto no fim.
            if (array_diff_key(self::palavras($termo), $tudoDoNome) === []) {
                continue;
            }
            $titulo = TituloDoProduto::juntarTermo($nome, $termo);
            if (mb_strlen($titulo) > $limite || in_array($titulo, $saida, true)) {
                continue;
            }
            $saida[] = $titulo;
            if (count($saida) >= $maximo) {
                break;
            }
        }

        return count($saida) > ($deHoje === null ? 0 : 1) ? $saida : [];
    }

    /**
     * A validação da célula do título: lista que só SUGERE (aceita qualquer texto), com a dica que
     * aparece ao selecionar a célula.
     *
     * @param  string  $origem  a fórmula que aponta para a lista na aba oculta
     * @param  bool  $prontos  a lista é de títulos prontos (o produto já tem nome) e não de termos
     */
    public static function validacao(string $origem, bool $prontos, ?int $limite): DataValidation
    {
        $v = new DataValidation();
        $v->setType(DataValidation::TYPE_LIST);
        $v->setFormula1($origem);
        $v->setAllowBlank(true);
        $v->setShowDropDown(true);
        // Só sugere: o título digitado, que não está na lista, também vale.
        $v->setShowErrorMessage(false);
        $v->setErrorStyle(DataValidation::STYLE_INFORMATION);
        $v->setShowInputMessage(true);
        $v->setPromptTitle('Título de venda');
        $v->setPrompt(self::comoUsarASeta($prontos, $limite));

        return $v;
    }

    /** O que a seta traz e como usar: a dica da célula e a linha de ajuda da coluna. */
    public static function comoUsarASeta(bool $prontos, ?int $limite): string
    {
        return ($prontos
            ? 'A seta da célula traz títulos prontos com o nome do produto e os termos mais buscados desta categoria. Escolha um e ajuste se quiser, ou escreva o seu.'
            : 'A seta da célula traz os termos mais buscados desta categoria. Escolha um para começar e complete escrevendo, ou escreva o seu título.')
            .($limite !== null ? " Até {$limite} caracteres." : '');
    }

    /**
     * Palavras comparáveis de um texto, como chaves: minúsculas, sem acento, sem o "s" final
     * (poltrona × poltronas); saem as curtas e as de ligação.
     *
     * @return array<string, true>
     */
    private static function palavras(string $texto): array
    {
        $saida = [];
        foreach (preg_split('/[^\p{L}\p{N}]+/u', Str::lower(Str::ascii($texto)), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $p) {
            if (mb_strlen($p) < 3 || in_array($p, self::LIGACAO, true)) {
                continue;
            }
            $saida[(string) preg_replace('/s$/', '', $p)] = true;
        }

        return $saida;
    }
}
