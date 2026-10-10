<?php

namespace App\Services\Portal\Estrutura\Produtos;

use App\Models\Company;
use App\Models\EstruturaProduto;
use App\Models\EstruturaProdutoVariacao;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

/**
 * A planilha de produtos da EMPRESA (09/10/2026): o modelo vazio com as famílias e os
 * ambientes que ela já cadastrou nas listas, e o mesmo modelo PREENCHIDO com os produtos e
 * variações de hoje — para editar fora do sistema e enviar de novo ("Baixar meus produtos na
 * planilha").
 *
 * ### Ida e volta sem mudança
 * O que sai daqui volta pelo `ImportadorProdutos` como "sem mudança" quando ninguém mexeu:
 * custo em reais ("1.234,50"), volumes no texto que o leitor entende, estoque inteiro (0 é 0;
 * vazio é vazio), a categoria pelo NOME (o id nunca sai: sigilo, e o nome não rebaixa uma
 * categoria já escolhida — BE-CR-02) e a descrição só na 1ª linha do produto.
 *
 * O "Produto (grupo)" é o código do produto; o produto que ficou sem código próprio sai com a
 * Ref da 1ª variação, que a importação também reconhece como o grupo dele.
 *
 * Escopo: só a empresa recebida (todas as consultas filtram `company_id`).
 */
class PlanilhaDosProdutos
{
    public function __construct(private ListasDaEmpresaService $listas) {}

    /** O modelo vazio, com as listas da empresa na aba oculta. */
    public function modelo(Company $empresa): Spreadsheet
    {
        return ModeloProdutosXlsx::gerar($this->nomesDasListas($empresa));
    }

    /** O modelo preenchido com os produtos e variações da empresa. */
    public function exportar(Company $empresa): Spreadsheet
    {
        return ModeloProdutosXlsx::gerar($this->nomesDasListas($empresa), $this->linhas($empresa));
    }

    /**
     * Uma linha por variação, por campo do modelo, na ordem da tela (produto por id, variação
     * pela ordem dela).
     *
     * @return list<array<string, string>>
     */
    public function linhas(Company $empresa): array
    {
        $produtos = EstruturaProduto::query()
            ->where('company_id', $empresa->id)
            ->with([
                'familia',
                'ambientes',
                'variacoes' => fn ($q) => $q->where('company_id', $empresa->id),
                'variacoes.volumes',
            ])
            ->orderBy('id')
            ->get();

        $linhas = [];
        foreach ($produtos as $produto) {
            $variacoes = $produto->variacoes;
            if ($variacoes->isEmpty()) {
                continue;
            }

            $grupo = trim((string) $produto->codigo) !== '' ? (string) $produto->codigo : (string) $variacoes->first()->codigo;
            $ambientes = $produto->ambientes->pluck('nome')->sort(SORT_NATURAL | SORT_FLAG_CASE)->implode(' / ');
            $categoria = trim((string) $produto->categoria_ml_nome);

            foreach ($variacoes->values() as $i => $variacao) {
                $linhas[] = [
                    'codigo'    => (string) $variacao->codigo,
                    'grupo'     => $grupo,
                    'eixo'      => $variacao->eixo ? (string) (EstruturaProdutoVariacao::EIXOS[$variacao->eixo] ?? '') : '',
                    'variacao'  => (string) $variacao->valor,
                    'nome'      => (string) $produto->nome,
                    'familia'   => (string) $produto->familia?->nome,
                    'ambientes' => $ambientes,
                    'categoria' => $categoria,
                    'descricao' => $i === 0 ? (string) $produto->descricao : '',
                    ...self::daVariacao($variacao),
                ];
            }
        }

        return $linhas;
    }

    /** @return array{n_volumes: string, volumes_texto: string, peso_total: string, custo: string, estoque: string} */
    private static function daVariacao(EstruturaProdutoVariacao $variacao): array
    {
        $volumes = $variacao->volumes
            ->map(fn ($v) => ['c' => (float) $v->comprimento, 'l' => (float) $v->largura, 'a' => (float) $v->altura, 'kg' => (float) $v->peso])
            ->values()
            ->all();

        return [
            'n_volumes'     => $volumes === [] ? '' : (string) count($volumes),
            'volumes_texto' => VolumesTexto::formatar($volumes),
            'peso_total'    => $volumes === [] ? '' : self::numero(array_sum(array_column($volumes, 'kg')), 3),
            'custo'         => $variacao->custo === null ? '' : number_format((float) $variacao->custo, 2, ',', '.'),
            'estoque'       => $variacao->estoque === null ? '' : (string) (int) $variacao->estoque,
        ];
    }

    /** Número com vírgula, sem zeros à toa ("33,5"). */
    private static function numero(float $n, int $casas): string
    {
        return rtrim(rtrim(number_format($n, $casas, ',', ''), '0'), ',');
    }

    /** @return array{familias: list<string>, ambientes: list<string>} */
    private function nomesDasListas(Company $empresa): array
    {
        return [
            'familias'  => array_column($this->listas->lista($empresa, ListasDaEmpresaService::FAMILIA), 'nome'),
            'ambientes' => array_column($this->listas->lista($empresa, ListasDaEmpresaService::AMBIENTE), 'nome'),
        ];
    }
}
