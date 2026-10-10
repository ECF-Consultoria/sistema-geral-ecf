<?php

namespace App\Services\Portal\Estrutura\Produtos;

use App\Models\Company;
use App\Models\EstruturaProduto;
use App\Models\EstruturaProdutoAtributo;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * A planilha da FICHA TÉCNICA (09/10/2026): o 2º arquivo, depois que as categorias estão
 * confirmadas. Uma aba por categoria, com nome genérico (o nome da categoria), uma linha por
 * produto dela e uma coluna por campo da ficha da categoria — "*" no obrigatório, a unidade no
 * cabeçalho ("Altura do assento (cm)"). As opções das listas ficam na aba oculta "Listas" e
 * viram validação em lista. A planilha sai já com o que cada produto tem salvo.
 *
 * ### Volta pelo NOME do campo
 * O `ImportadorFichasTecnicas` casa a coluna com o campo pelo NOME, na definição ATUAL da
 * categoria do produto (o id do campo nunca vai para a planilha). As regras de cabeçalho e de
 * célula moram aqui (`cabecalho`, `chaveDoCampo`, `textoDaCelula`) para as duas pontas concordarem.
 *
 * ### Sigilo
 * Abas, cabeçalhos, listas, instruções e mensagens não citam a plataforma: os nomes de campo e
 * de opção já vêm filtrados por {@see FichaTecnicaDaCategoria}.
 */
class PlanilhaDasFichasTecnicas
{
    public const ABA_INSTRUCOES = 'Instruções';
    public const ABA_LISTAS = 'Listas';

    public const COLUNA_GRUPO = 'Produto (grupo)*';
    public const COLUNA_NOME = 'Nome do produto';

    /** O texto do "Não se aplica" na célula (o mesmo rótulo da ficha na tela). */
    public const NAO_SE_APLICA = 'Não se aplica';

    /** Separador das opções de um campo que aceita mais de uma (o mesmo da gravação). */
    public const SEPARADOR = ' | ';

    /** Teto de abas: categorias além disso ficam para outro download (raro). */
    private const MAX_ABAS = 60;

    public function __construct(private FichaTecnicaDaCategoria $definicao) {}

    // ═══ Regras compartilhadas com a importação ═════════════════════════════

    /** O cabeçalho da coluna de um campo: nome, "*" no obrigatório e a unidade padrão entre parênteses. */
    public static function cabecalho(array $campo): string
    {
        $texto = $campo['nome'].($campo['obrigatorio'] ? '*' : '');
        if ($campo['tipo'] === FichaTecnicaDaCategoria::TIPO_NUMERO_UNIDADE && ($campo['unidade_padrao'] ?? null)) {
            $texto .= " ({$campo['unidade_padrao']})";
        }

        return $texto;
    }

    /** Forma de comparar NOMES (de campo, de opção): sem caixa, acento nem espaço sobrando. */
    public static function chave(string $texto): string
    {
        return Str::lower(Str::ascii(trim((string) preg_replace('/\s+/u', ' ', $texto))));
    }

    /**
     * As chaves pelas quais um cabeçalho pode casar com um campo: o texto sem o "*" e, depois, sem
     * o último "(…)" (a unidade). Assim "Largura (com braço) (cm)" ainda acha "Largura (com braço)".
     *
     * @return list<string>
     */
    public static function chavesDoCabecalho(string $cabecalho): array
    {
        $sem = trim(str_replace('*', '', $cabecalho));
        $chaves = [self::chave($sem)];
        if (preg_match('/^(.*\S)\s*\([^()]*\)$/u', $sem, $m)) {
            $chaves[] = self::chave($m[1]);
        }

        return array_values(array_unique(array_filter($chaves, fn ($c) => $c !== '')));
    }

    /** Nome de aba que o Excel aceita: sem []:*?/\, até 31 letras, e diferente das outras. */
    public static function nomeDeAba(string $nome, array $usados): string
    {
        $limpo = trim((string) preg_replace('/[\[\]:*?\/\\\\]+/u', ' ', $nome));
        $limpo = trim((string) preg_replace('/\s+/u', ' ', $limpo), " '");
        $limpo = $limpo === '' ? 'Categoria' : mb_substr($limpo, 0, 31);

        $reservados = array_map(fn ($n) => mb_strtolower($n), [...$usados, self::ABA_INSTRUCOES, self::ABA_LISTAS]);
        $candidato = $limpo;
        for ($n = 2; in_array(mb_strtolower($candidato), $reservados, true); $n++) {
            $sufixo = " {$n}";
            $candidato = mb_substr($limpo, 0, 31 - mb_strlen($sufixo)).$sufixo;
        }

        return $candidato;
    }

    /**
     * O que está salvo, como a pessoa escreveria na célula. Valor que não é mais opção do campo
     * sai em branco (a ficha na tela também não o mostra).
     */
    public static function textoDaCelula(array $campo, ?EstruturaProdutoAtributo $salvo): string
    {
        if (! $salvo) {
            return '';
        }
        if ((string) $salvo->valor_id === FichaTecnicaDoProduto::NAO_SE_APLICA) {
            return self::NAO_SE_APLICA;
        }

        $valor = trim((string) $salvo->valor);
        if ($campo['tipo'] === FichaTecnicaDaCategoria::TIPO_LISTA) {
            $partes = ($campo['multivalor'] ?? false) ? explode('|', $valor) : [$salvo->valor_id ?: $valor];
            $nomes = [];
            foreach ($partes as $parte) {
                $opcao = self::opcaoPorTexto($campo, (string) $parte);
                if ($opcao && ! in_array($opcao['nome'], $nomes, true)) {
                    $nomes[] = $opcao['nome'];
                }
            }

            return implode(self::SEPARADOR, $nomes);
        }
        if (in_array($campo['tipo'], [FichaTecnicaDaCategoria::TIPO_NUMERO, FichaTecnicaDaCategoria::TIPO_NUMERO_UNIDADE], true)) {
            $numero = str_replace('.', ',', $valor);
            $unidade = trim((string) $salvo->unidade);

            return $unidade !== '' && $unidade !== (string) ($campo['unidade_padrao'] ?? '') ? "{$numero} {$unidade}" : $numero;
        }

        return $valor;
    }

    /** A opção do campo pelo id ou pelo nome (sem caixa nem acento), ou null. */
    public static function opcaoPorTexto(array $campo, string $texto): ?array
    {
        $texto = trim($texto);
        if ($texto === '') {
            return null;
        }
        foreach ($campo['valores'] as $v) {
            if ($v['id'] === $texto) {
                return $v;
            }
        }
        $chave = self::chave($texto);
        foreach ($campo['valores'] as $v) {
            if (self::chave($v['nome']) === $chave) {
                return $v;
            }
        }

        return null;
    }

    // ═══ O arquivo ══════════════════════════════════════════════════════════

    /**
     * Uma aba por categoria confirmada dos produtos da empresa (nunca de outra).
     *
     * @return array{planilha: Spreadsheet, categorias: int, produtos: int, indisponiveis: int}
     */
    public function gerar(Company $empresa): array
    {
        $produtos = EstruturaProduto::query()
            ->where('company_id', $empresa->id)
            ->whereNotNull('categoria_ml_id')->where('categoria_ml_id', '<>', '')
            ->whereNotNull('categoria_ml_nome')->where('categoria_ml_nome', '<>', '')
            ->with(['variacoes' => fn ($q) => $q->where('company_id', $empresa->id)])
            ->orderBy('categoria_ml_nome')->orderBy('id')
            ->get();

        $salvos = [];
        if ($produtos->isNotEmpty()) {
            EstruturaProdutoAtributo::query()
                ->where('company_id', $empresa->id)
                ->whereIn('produto_id', $produtos->pluck('id'))
                ->get()
                ->each(function (EstruturaProdutoAtributo $a) use (&$salvos) {
                    $salvos[(int) $a->produto_id][(string) $a->atributo_id] = $a;
                });
        }

        $planilha = new Spreadsheet();
        $planilha->getProperties()->setCreator('ECF')->setLastModifiedBy('ECF')->setTitle('Ficha técnica')
            ->setSubject('')->setDescription('')->setKeywords('')->setCategory('');
        $planilha->removeSheetByIndex(0);

        $usados = [];
        $listas = new Worksheet($planilha, self::ABA_LISTAS);
        $colunaDaLista = 0;
        $categorias = 0;
        $indisponiveis = 0;
        $comAba = 0;

        foreach ($produtos->groupBy('categoria_ml_id') as $categoriaId => $daCategoria) {
            if ($categorias >= self::MAX_ABAS) {
                break;
            }
            try {
                $campos = FichaTecnicaDaCategoria::camposPorId($this->definicao->definicao((string) $categoriaId));
            } catch (\Throwable $e) {
                Log::warning("[Estrutura Produtos] ficha técnica da categoria {$categoriaId} indisponível para a planilha", ['erro' => $e->getMessage()]);
                $campos = [];
            }
            if ($campos === []) {
                $indisponiveis += $daCategoria->count();
                continue;
            }

            $nome = self::nomeDeAba((string) $daCategoria->first()->categoria_ml_nome, $usados);
            $usados[] = $nome;
            $aba = new Worksheet($planilha, $nome);
            $planilha->addSheet($aba);
            $categorias++;
            $comAba += $daCategoria->count();

            $this->preencherAba($aba, array_values($campos), $daCategoria->values()->all(), $salvos, $listas, $colunaDaLista);
        }

        $instrucoes = new Worksheet($planilha, self::ABA_INSTRUCOES);
        $planilha->addSheet($instrucoes, $categorias === 0 ? 0 : $categorias);
        $this->preencherInstrucoes($instrucoes, $categorias, $indisponiveis);

        $planilha->addSheet($listas);
        $listas->setSheetState(Worksheet::SHEETSTATE_HIDDEN);
        $planilha->setActiveSheetIndex(0);

        return ['planilha' => $planilha, 'categorias' => $categorias, 'produtos' => $comAba, 'indisponiveis' => $indisponiveis];
    }

    /**
     * @param  list<array>  $campos
     * @param  list<EstruturaProduto>  $produtos
     * @param  array<int, array<string, EstruturaProdutoAtributo>>  $salvos
     */
    private function preencherAba(Worksheet $aba, array $campos, array $produtos, array $salvos, Worksheet $listas, int &$colunaDaLista): void
    {
        $cabecalhos = [self::COLUNA_GRUPO, self::COLUNA_NOME, ...array_map([self::class, 'cabecalho'], $campos)];
        foreach ($cabecalhos as $i => $titulo) {
            self::texto($aba, Coordinate::stringFromColumnIndex($i + 1).'1', $titulo);
            $aba->getColumnDimension(Coordinate::stringFromColumnIndex($i + 1))->setWidth($i === 1 ? 32 : max(14, min(40, mb_strlen($titulo) + 4)));
        }

        foreach ($produtos as $n => $produto) {
            $linha = $n + 2;
            $grupo = trim((string) $produto->codigo) !== '' ? (string) $produto->codigo : (string) ($produto->variacoes->first()?->codigo ?? '');
            self::texto($aba, "A{$linha}", $grupo);
            self::texto($aba, "B{$linha}", (string) $produto->nome);
            foreach ($campos as $i => $campo) {
                $texto = self::textoDaCelula($campo, $salvos[(int) $produto->id][$campo['id']] ?? null);
                if ($texto !== '') {
                    self::texto($aba, Coordinate::stringFromColumnIndex($i + 3).$linha, $texto);
                }
            }
        }

        $ultima = Coordinate::stringFromColumnIndex(count($cabecalhos));
        $aba->getStyle("A1:{$ultima}1")->applyFromArray([
            'font'      => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1F2430']],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
        ]);
        $aba->getRowDimension(1)->setRowHeight(32);
        $aba->freezePane('C2');
        $fim = max(2, count($produtos) + 1);
        $aba->getStyle("A2:A{$fim}")->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_TEXT);
        // Nome do produto é só para a pessoa se achar: a importação não o lê.
        $aba->getStyle("B2:B{$fim}")->getFont()->getColor()->setRGB('6B7280');

        foreach ($campos as $i => $campo) {
            $coluna = Coordinate::stringFromColumnIndex($i + 3);
            $validacao = $this->validacaoDoCampo($aba, $campo, $listas, $colunaDaLista);
            if ($validacao) {
                $aba->setDataValidation("{$coluna}2:{$coluna}{$fim}", $validacao);
            }
        }
    }

    /** A validação da coluna: lista (as opções na aba oculta), Sim/Não, ou só a dica do que vai ali. */
    private function validacaoDoCampo(Worksheet $aba, array $campo, Worksheet $listas, int &$colunaDaLista): ?DataValidation
    {
        $naoSeAplica = (bool) ($campo['nao_se_aplica'] ?? false);
        $v = new DataValidation();
        $v->setAllowBlank(true);
        $v->setShowInputMessage(true);
        $v->setPromptTitle(mb_substr($campo['nome'], 0, 32));

        if ($campo['tipo'] === FichaTecnicaDaCategoria::TIPO_LISTA && $campo['valores'] !== []) {
            $nomes = array_column($campo['valores'], 'nome');
            if ($naoSeAplica) {
                $nomes[] = self::NAO_SE_APLICA;
            }
            $colunaDaLista++;
            $col = Coordinate::stringFromColumnIndex($colunaDaLista);
            self::texto($listas, "{$col}1", mb_substr($aba->getTitle().' · '.$campo['nome'], 0, 200));
            foreach ($nomes as $n => $nome) {
                self::texto($listas, $col.($n + 2), $nome);
            }
            $v->setType(DataValidation::TYPE_LIST);
            $v->setFormula1("'".self::ABA_LISTAS."'!\${$col}\$2:\${$col}\$".(count($nomes) + 1));
            $v->setShowDropDown(true);
            $multivalor = (bool) ($campo['multivalor'] ?? false);
            // Mais de uma opção numa célula não passa numa lista estrita: ali a lista só sugere.
            $v->setShowErrorMessage(! $multivalor);
            $v->setErrorStyle(DataValidation::STYLE_STOP);
            $v->setErrorTitle(mb_substr($campo['nome'], 0, 32));
            $v->setError('Escolha uma das opções da lista.');
            $v->setPrompt($multivalor ? 'Escolha da lista. Pode mais de uma: separe com |.' : 'Escolha da lista.');

            return $v;
        }

        if ($campo['tipo'] === FichaTecnicaDaCategoria::TIPO_SIM_NAO) {
            $v->setType(DataValidation::TYPE_LIST);
            $v->setFormula1($naoSeAplica ? '"Sim,Não,'.self::NAO_SE_APLICA.'"' : '"Sim,Não"');
            $v->setShowDropDown(true);
            $v->setShowErrorMessage(true);
            $v->setErrorStyle(DataValidation::STYLE_STOP);
            $v->setError('Escolha Sim ou Não.');
            $v->setPrompt('Sim ou Não.');

            return $v;
        }

        $dica = match ($campo['tipo']) {
            FichaTecnicaDaCategoria::TIPO_NUMERO => 'Um número.',
            FichaTecnicaDaCategoria::TIPO_NUMERO_UNIDADE => 'Um número'.(($campo['unidade_padrao'] ?? null) ? ", em {$campo['unidade_padrao']}." : '.'),
            default => ($campo['max'] ?? null) ? "Texto, até {$campo['max']} letras." : 'Texto.',
        };
        $v->setPrompt($naoSeAplica ? "{$dica} Ou: ".self::NAO_SE_APLICA.'.' : $dica);

        return $v;
    }

    private function preencherInstrucoes(Worksheet $aba, int $categorias, int $indisponiveis): void
    {
        $aba->getColumnDimension('A')->setWidth(120);
        $linhas = [
            'Como preencher a ficha técnica',
            $categorias === 0
                ? 'Nenhum produto com a categoria confirmada ainda. Confirme a categoria dos produtos e baixe esta planilha de novo.'
                : 'Uma aba por categoria, uma linha por produto. Preencha as colunas e envie a planilha de volta.',
            'Os campos com * são obrigatórios. A unidade vem no nome da coluna, como (cm).',
            'Nas colunas com lista, escolha uma opção. Onde vale mais de uma, separe com |, como Algodão | Couro.',
            'Onde aceitar, escreva '.self::NAO_SE_APLICA.' para o campo que não vale para o produto.',
            'Célula em branco não apaga nada do que já está salvo.',
            'Não mude a coluna Produto (grupo): é por ela que cada linha volta para o seu produto.',
            'O campo que é a variação do produto (como a cor de quem tem cores) fica em branco: o valor vem de cada variação.',
            'O que estiver fora das opções ou no formato errado não é gravado, e a lista do que faltou aparece antes de confirmar.',
        ];
        if ($indisponiveis > 0) {
            $linhas[] = "A ficha de {$indisponiveis} produto(s) não pôde ser montada agora. Baixe de novo mais tarde.";
        }
        foreach ($linhas as $i => $linha) {
            self::texto($aba, 'A'.($i + 1), $linha);
        }
        $aba->getStyle('A1')->getFont()->setBold(true);
    }

    private static function texto(Worksheet $folha, string $celula, string $valor): void
    {
        // Texto explícito: nunca fórmula, nunca notação científica.
        $folha->setCellValueExplicit($celula, $valor, DataType::TYPE_STRING);
    }
}
