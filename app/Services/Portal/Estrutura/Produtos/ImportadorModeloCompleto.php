<?php

namespace App\Services\Portal\Estrutura\Produtos;

use App\Models\Company;
use App\Support\Portal\AtorDoPortal;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Importa o MODELO COMPLETO ({@see ModeloCompleto}, 13/10/2026): cada aba é uma categoria e cada linha traz
 * o produto e a ficha técnica dele.
 *
 * Não há regra nova de validação aqui: são as DUAS importações que já existiam, na ordem certa.
 *  1. Os PRODUTOS de cada aba passam pela {@see ImportadorProdutos} (a mesma prévia, os mesmos motivos),
 *     já com a categoria da aba confirmada — ninguém precisa confirmar categoria depois.
 *  2. A FICHA passa pela {@see ImportadorFichasTecnicas} sobre o mesmo arquivo, que casa cada coluna pelo
 *     nome do campo na categoria do produto e grava mesclando (em branco não apaga).
 *
 * Para o passo 1, as colunas do produto de cada aba viram um arquivo de passagem no formato do modelo de
 * Produtos, com as linhas NOS MESMOS NÚMEROS do arquivo enviado — o "linha 7" da prévia é a linha 7 da aba.
 * Com mais de uma aba, cada item e cada aviso diz de qual aba é.
 *
 * As medidas chegam em colunas separadas (comprimento, largura, altura e peso de cada volume) e viram aqui
 * o texto de volumes que a importação de Produtos já entende. Volume pela metade não é enviado: a linha
 * segue sem mexer nas medidas, com o aviso de qual volume completar.
 *
 * A aba "Sem categoria" (só no arquivo exportado) é a exceção: os produtos dela seguem com a categoria por
 * escrito, a confirmar como na planilha de Produtos, e não têm ficha.
 */
class ImportadorModeloCompleto
{
    public function __construct(
        private ImportadorProdutos $produtos,
        private ImportadorFichasTecnicas $fichas,
    ) {}

    /** O arquivo é o modelo completo? (tem a aba oculta com a marca dele.) */
    public static function reconhece(string $caminho): bool
    {
        return ModeloCompleto::categoriasDoArquivo($caminho) !== [];
    }

    /**
     * A mesma forma da prévia de Produtos, mais `ficha` (a prévia da ficha técnica) e `modelo_completo`.
     *
     * @return array<string, mixed>
     */
    public function previa(Company $empresa, string $caminho): array
    {
        $saida = [
            'erro_geral' => null, 'colunas' => [],
            'totais' => ['novos' => 0, 'atualizados' => 0, 'sem_mudanca' => 0, 'erros' => 0],
            'grupos' => ['novos' => [], 'atualizados' => [], 'sem_mudanca' => [], 'erros' => []],
            'criar_listas' => ['familias' => [], 'ambientes' => []], 'avisos' => [],
            // A categoria vem da aba: só há o que confirmar para os produtos da aba "Sem categoria".
            'categorias_a_confirmar' => ['nomes' => [], 'total_nomes' => 0, 'total_produtos' => 0],
            'modelo_completo' => true, 'ficha' => null,
        ];

        $plano = $this->abasComProdutos($caminho);
        if ($plano['erro_geral'] !== null) {
            return ['erro_geral' => $plano['erro_geral']] + $saida;
        }
        $saida['avisos'] = $plano['avisos'];
        $variasAbas = count($plano['abas']) > 1;

        foreach ($plano['abas'] as $aba) {
            $r = $this->comArquivoDeProdutos($aba['linhas'], fn (string $tmp) => $this->produtos->previa($empresa, $tmp));
            if ($r['erro_geral'] !== null) {
                $saida['avisos'][] = "{$aba['nome']}: {$r['erro_geral']}";
                continue;
            }
            $saida['colunas'] = array_values(array_unique([...$saida['colunas'], ...$r['colunas']]));
            foreach ($saida['totais'] as $g => $_) {
                $saida['totais'][$g] += (int) ($r['totais'][$g] ?? 0);
                foreach ($r['grupos'][$g] ?? [] as $item) {
                    $saida['grupos'][$g][] = $item + ['aba' => $aba['nome']];
                }
            }
            foreach (['familias', 'ambientes'] as $lista) {
                $saida['criar_listas'][$lista] = array_values(array_unique([...$saida['criar_listas'][$lista], ...($r['criar_listas'][$lista] ?? [])]));
            }
            foreach ($r['avisos'] as $aviso) {
                $saida['avisos'][] = $variasAbas ? "{$aba['nome']}: {$aviso}" : $aviso;
            }
            if ($aba['categoria']['id'] === null) {
                $saida['categorias_a_confirmar'] = $r['categorias_a_confirmar'];
            }
        }

        $ficha = $this->fichas->previa($empresa, $caminho, $plano['categoria_da_aba']);
        $saida['ficha'] = [
            'totais'   => $ficha['totais'],
            'produtos' => $ficha['produtos'],
            'avisos'   => $ficha['avisos'],
        ];

        return $saida;
    }

    /**
     * Grava: primeiro os produtos de cada aba (com a categoria dela), depois a ficha de todos.
     *
     * @param  list<array{texto?: mixed, id?: mixed}>  $confirmadas  as categorias que a pessoa confirmou na prévia (só valem para a aba "Sem categoria")
     * @return array<string, mixed>  os totais da importação de Produtos somados, mais `ficha`
     */
    public function aplicar(Company $empresa, string $caminho, AtorDoPortal $ator, array $confirmadas = []): array
    {
        $plano = $this->abasComProdutos($caminho);
        if ($plano['erro_geral'] !== null) {
            return ['erro_geral' => $plano['erro_geral']];
        }

        $saida = ['novos' => 0, 'atualizados' => 0, 'sem_mudanca' => 0, 'erros' => 0, 'nao_entraram' => [],
            'categorias_confirmadas' => 0, 'categorias_a_confirmar' => 0, 'avisos' => $plano['avisos']];
        $variasAbas = count($plano['abas']) > 1;

        foreach ($plano['abas'] as $aba) {
            $daAba = $aba['categoria']['id'] === null ? $confirmadas : [['texto' => $aba['categoria']['nome'], 'id' => $aba['categoria']['id']]];
            $r = $this->comArquivoDeProdutos($aba['linhas'], fn (string $tmp) => $this->produtos->aplicar($empresa, $tmp, $ator, $daAba));
            if (isset($r['erro_geral'])) {
                $saida['avisos'][] = "{$aba['nome']}: {$r['erro_geral']}";
                continue;
            }
            foreach (['novos', 'atualizados', 'sem_mudanca', 'erros', 'categorias_confirmadas', 'categorias_a_confirmar'] as $k) {
                $saida[$k] += (int) ($r[$k] ?? 0);
            }
            foreach ($r['nao_entraram'] ?? [] as $e) {
                $saida['nao_entraram'][] = $variasAbas ? ['motivo' => "{$aba['nome']}: {$e['motivo']}"] + $e : $e;
            }
        }

        // Os produtos já existem (e já têm a categoria): agora a ficha de cada um.
        $saida['ficha'] = $this->fichas->aplicar($empresa, $caminho, $ator, $plano['categoria_da_aba']);

        return $saida;
    }

    /**
     * As abas do arquivo que são do modelo e têm algum produto preenchido, com as linhas do produto por
     * número de linha. Aba que não é do modelo é ignorada com aviso.
     *
     * @return array{erro_geral: ?string, abas: list<array{nome: string, categoria: array{id: ?string, nome: string}, linhas: array<int, array<string, string>>}>, categoria_da_aba: array<string, ?string>, avisos: list<string>}
     */
    private function abasComProdutos(string $caminho): array
    {
        $vazio = fn (?string $erro) => ['erro_geral' => $erro, 'abas' => [], 'categoria_da_aba' => [], 'avisos' => []];

        $categorias = ModeloCompleto::categoriasDoArquivo($caminho);
        if ($categorias === []) {
            return $vazio('Não reconhecemos este arquivo. Baixe o modelo de novo e preencha nele.');
        }
        $lido = $this->fichas->ler($caminho);
        if ($lido['erro_geral'] !== null) {
            return $vazio($lido['erro_geral']);
        }

        $chaveDoCabecalho = ModeloCompleto::chavesDosCabecalhos();
        $abas = [];
        $avisos = [];
        $categoriaDaAba = [];

        foreach ($lido['abas'] as $aba) {
            $categoria = $categorias[$aba['nome']] ?? null;
            if ($categoria === null) {
                $avisos[] = "A aba “{$aba['nome']}” não é uma das categorias deste modelo e foi ignorada. Não mude o nome das abas.";
                continue;
            }
            $categoriaDaAba[$aba['nome']] = $categoria['id'];

            $colunas = [];
            foreach ($aba['cabecalhos'] as $coluna => $titulo) {
                $chave = $chaveDoCabecalho[PlanilhaDasFichasTecnicas::chave(str_replace('*', '', $titulo))] ?? null;
                // A "Categoria" por escrito só vale na aba sem categoria; nas outras, a aba É a categoria.
                if ($chave !== null && ($chave !== 'categoria' || $categoria['id'] === null)) {
                    $colunas[$coluna] = $chave;
                }
            }

            $linhas = [];
            foreach ($aba['linhas'] as $linha) {
                $campos = [];
                $volumes = [];
                foreach ($colunas as $coluna => $chave) {
                    $texto = self::comoTexto($linha[$coluna] ?? null);
                    if ($texto === '') {
                        continue;
                    }
                    if (str_starts_with($chave, 'vol:')) {
                        [, $n, $medida] = explode(':', $chave);
                        $volumes[(int) $n][$medida] = $texto;
                    } else {
                        $campos[$chave] = $texto;
                    }
                }
                [$texto, $incompletos] = self::volumesEmTexto($volumes);
                foreach ($incompletos as $n) {
                    $avisos[] = "{$aba['nome']}, linha {$linha['__linha']}: o Volume {$n} está incompleto (preencha comprimento, largura, altura e peso). As medidas desta linha não foram alteradas.";
                }
                if ($texto !== '') {
                    $campos['volumes_texto'] = $texto;
                }
                if ($campos !== []) {
                    $linhas[(int) $linha['__linha']] = $categoria['id'] === null ? $campos : $campos + ['categoria' => $categoria['nome']];
                }
            }
            if ($linhas !== []) {
                $abas[] = ['nome' => $aba['nome'], 'categoria' => $categoria, 'linhas' => $linhas];
            }
        }

        if ($abas === []) {
            return $vazio('A planilha não tem nenhum produto preenchido.');
        }

        return ['erro_geral' => null, 'abas' => $abas, 'categoria_da_aba' => $categoriaDaAba, 'avisos' => $avisos];
    }

    /**
     * As medidas em colunas → o texto de volumes da importação de Produtos ("60×50×40 · 8,5 | …"), na
     * ordem dos volumes. Com algum volume pela metade, não devolve texto nenhum (a linha não mexe nas
     * medidas) e diz quais são.
     *
     * @param  array<int, array<string, string>>  $volumes  número do volume => medida => texto
     * @return array{0: string, 1: list<int>}
     */
    private static function volumesEmTexto(array $volumes): array
    {
        ksort($volumes);
        $partes = [];
        $incompletos = [];
        foreach ($volumes as $n => $v) {
            if (count(array_intersect_key($v, ModeloCompleto::MEDIDAS)) < count(ModeloCompleto::MEDIDAS)) {
                $incompletos[] = $n;
                continue;
            }
            $partes[] = "{$v['c']}\u{00D7}{$v['l']}\u{00D7}{$v['a']} \u{00B7} {$v['kg']}";
        }

        return $incompletos !== [] ? ['', $incompletos] : [implode(' | ', $partes), []];
    }

    /**
     * Roda `$usar` com um arquivo de passagem no formato do modelo de Produtos, com cada linha no MESMO
     * número de linha da aba enviada (as que faltam ficam em branco), e o apaga em seguida.
     *
     * @param  array<int, array<string, string>>  $linhas  número da linha => campos do produto
     */
    private function comArquivoDeProdutos(array $linhas, callable $usar): array
    {
        $sequencia = [];
        for ($n = 2, $fim = max(array_keys($linhas)); $n <= $fim; $n++) {
            $sequencia[] = $linhas[$n] ?? [];
        }

        $tmp = tempnam(sys_get_temp_dir(), 'ecf-mc-').'.xlsx';
        try {
            (new Xlsx(ModeloProdutosXlsx::gerar([], $sequencia)))->save($tmp);

            return $usar($tmp);
        } finally {
            @unlink($tmp);
            @unlink(substr($tmp, 0, -5));
        }
    }

    /** O valor da célula como a pessoa o digitaria: número com vírgula decimal, inteiro sem casas. */
    private static function comoTexto(mixed $valor): string
    {
        if ($valor === null || is_bool($valor)) {
            return '';
        }
        if (is_int($valor)) {
            return (string) $valor;
        }
        if (is_float($valor)) {
            return floor($valor) === $valor
                ? (string) (int) $valor
                : str_replace('.', ',', rtrim(rtrim(number_format($valor, 4, '.', ''), '0'), '.'));
        }

        return trim((string) $valor);
    }
}
