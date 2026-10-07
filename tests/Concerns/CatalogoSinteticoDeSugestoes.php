<?php

namespace Tests\Concerns;

use App\Models\Company;
use App\Models\EstruturaOferta;
use App\Models\EstruturaProduto;
use App\Models\EstruturaProdutoGeracao;
use App\Models\EstruturaProdutoVariacao;
use App\Models\EstruturaTipoPar;
use App\Models\EstruturaTipoProduto;
use App\Services\Portal\Estrutura\Produtos\ProdutoCadastroService;
use App\Support\Portal\AtorDoPortal;

/**
 * Catálogo SINTÉTICO no banco, com a MESMA forma do `GabaritoDaGeracaoTest`
 * (Fase 168, PR168-15). Nada da planilha real: nomes, SKUs, medidas e custos são
 * fictícios.
 *
 * Sem nenhuma composição existente nem descarte, a geração dá 29 sugestões:
 * 15 Combo, 6 Kit e 8 Combit (o gabarito puro dá 5 Kit porque já traz 1 existente).
 *
 * Os planos 168-10, 168-11 e 168-13 reutilizam este trait.
 */
trait CatalogoSinteticoDeSugestoes
{
    /**
     * Deixa os tipos com quantidades conhecidas e só os 4 pares do teste, para o
     * resultado não depender da lista aprovada nem de edição do admin.
     */
    protected function paresDoTeste(): void
    {
        $quantidades = [
            'cadeira'     => ['2, 4, 6', '2, 4, 6'],
            'banqueta'    => ['2, 3, 4', '2, 3, 4'],
            'mesa'        => ['0', '0'],
            'banco'       => [null, null],
            'cama'        => [null, null],
            'criado-mudo' => [null, null],
        ];

        foreach ($quantidades as $slug => [$combo, $combit]) {
            EstruturaTipoProduto::where('slug', $slug)->update(['qtd_combo' => $combo, 'qtd_combit' => $combit]);
        }

        $ids = EstruturaTipoProduto::pluck('id', 'slug');

        EstruturaTipoPar::query()->delete();

        // [tipo A, tipo B, tipo que se repete no Combit]
        foreach ([
            ['mesa', 'cadeira', 'cadeira'],
            ['mesa', 'banco', 'banco'],
            ['mesa', 'banqueta', 'banqueta'],
            ['cama', 'criado-mudo', 'criado-mudo'],
        ] as [$x, $y, $repete]) {
            // Guardado com tipo_a_id <= tipo_b_id; a direção acompanha a troca.
            [$a, $b] = $ids[$x] <= $ids[$y] ? [$x, $y] : [$y, $x];

            EstruturaTipoPar::create([
                'tipo_a_id'     => $ids[$a],
                'tipo_b_id'     => $ids[$b],
                'combit_repete' => $repete === $a ? 'a' : 'b',
            ]);
        }
    }

    /**
     * @return array{produtos: array<string,int>, variacoes: array<string,int>, ofertas: array<string,int>}
     *         produtos por nome, variações por código e ofertas simples por SKU
     */
    protected function catalogoSintetico(Company $empresa, AtorDoPortal $ator): array
    {
        $this->paresDoTeste();

        $mesa    = [['c' => 160, 'l' => 90, 'a' => 15, 'kg' => 40]];
        $cadeira = [['c' => 50, 'l' => 50, 'a' => 20, 'kg' => 6]];
        $caixa   = [['c' => 30, 'l' => 30, 'a' => 30, 'kg' => 3]];

        $sala = ['Sala de jantar'];

        // A 1ª linha de cada grupo leva família, ambientes e categoria do produto.
        $produto = function (string $grupo, string $nome, ?string $familia, array $ambientes, string $categoria, array $variacoes, array $volumes, float $custo): array {
            $linhas = [];
            foreach ($variacoes as $i => [$codigo, $valor]) {
                $linha = [
                    'grupo' => $grupo, 'codigo' => $codigo, 'nome' => $nome,
                    'eixo' => $valor !== null ? 'cor' : null, 'valor' => $valor,
                    'volumes' => $volumes, 'custo' => $custo,
                ];
                if ($i === 0) {
                    $linha += ['familia' => $familia, 'ambientes' => $ambientes, 'categoria_texto' => $categoria];
                }
                $linhas[] = $linha;
            }

            return $linhas;
        };

        $linhas = array_merge(
            $produto('P1', 'Mesa Polo', 'Polo', $sala, 'Mesas de Jantar', [['V101', 'Natural'], ['V102', 'Preto']], $mesa, 300),
            // P2: a variação v203 (Branco) é criada à parte, SEM oferta.
            $produto('P2', 'Cadeira Polo', 'Polo', $sala, 'Cadeiras', [['V201', 'Natural'], ['V202', 'Preto']], $cadeira, 80),
            // P3 sem volumes: o caso "pendente".
            $produto('P3', 'Banco Polo', 'Polo', $sala, 'Bancos', [['V301', null]], [], 120),
            $produto('P4', 'Banqueta Polo', 'Polo', ['Varanda'], 'Banquetas', [['V401', null]], $caixa, 90),
            $produto('P5', 'Cama Polo', 'Polo', ['Quarto'], 'Camas', [['V501', null]], $caixa, 500),
            $produto('P6', 'Criado-mudo Polo', 'Polo', ['Quarto'], 'Criados-mudos', [['V601', null], ['V602', null]], $caixa, 150),
            $produto('P7', 'Peça Decorativa Polo', 'Polo', $sala, 'Decoração', [['V701', null]], $caixa, 40),
            $produto('P8', 'Cadeira Solo', 'Solo A', $sala, 'Cadeiras', [['V801', null]], $cadeira, 80),
            $produto('P9', 'Mesa Solo', 'Solo B', $sala, 'Mesas de Jantar', [['V901', null]], $mesa, 300),
            $produto('P10', 'Cadeira Avulsa', null, $sala, 'Cadeiras', [['V1001', null]], $cadeira, 80),
        );

        $resultado = app(ProdutoCadastroService::class)->gravarLinhas($empresa, $linhas, $ator);
        if ($resultado['erros'] !== []) {
            throw new \RuntimeException('Catálogo sintético com erro: '.json_encode($resultado['erros'], JSON_UNESCAPED_UNICODE));
        }

        $produtos = EstruturaProduto::where('company_id', $empresa->id)->pluck('id', 'nome');

        // v203 (Branco): variação do P2 sem oferta simples ligada.
        EstruturaProdutoVariacao::create([
            'produto_id' => $produtos['Cadeira Polo'], 'company_id' => $empresa->id,
            'ordem' => 2, 'codigo' => 'V203', 'eixo' => 'cor', 'valor' => 'Branco', 'custo' => 80,
        ]);

        // P6: o criado-mudo só repete em 2 unidades no Combit.
        EstruturaProdutoGeracao::create([
            'produto_id' => $produtos['Criado-mudo Polo'], 'company_id' => $empresa->id,
            'tipo_id' => null, 'qtd_combo' => null, 'qtd_combit' => '2',
        ]);

        return [
            'produtos'  => $produtos->all(),
            'variacoes' => EstruturaProdutoVariacao::where('company_id', $empresa->id)->pluck('id', 'codigo')->all(),
            'ofertas'   => EstruturaOferta::where('company_id', $empresa->id)->where('fase', EstruturaOferta::FASE_SIMPLES)
                ->whereNotNull('variacao_id')->pluck('id', 'sku')->all(),
        ];
    }
}
