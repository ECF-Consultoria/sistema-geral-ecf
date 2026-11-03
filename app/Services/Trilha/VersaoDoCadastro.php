<?php

namespace App\Services\Trilha;

use App\Models\Company;
use App\Models\EstruturaProduto;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

/**
 * A versão do cadastro de um produto (3ª entrega, fatia F6 — a rede contra a ABA VELHA): o `sha1` do JSON canônico
 * do que a gaveta `e:{id}` edita. Ela vai no retrato (rota 1 e toda resposta com retrato) e volta, opcional, no corpo
 * das escritas que mudam conteúdo (rotas 3, 4, 14 e 15); diferente da atual → 409 sem `travado`
 * ({@see CadastroNaTrilha::exigirVersao()}).
 *
 * O que ENTRA (o que a pessoa vê e grava na gaveta):
 * - os campos do produto (`codigo`, `nome`, `titulo`, `familia_id`, a categoria — id, nome e caminho — e `descricao`);
 * - os ids dos ambientes, ordenados;
 * - as variações (`id`, `codigo`, `ordem`, `eixo`, `valor`, `eixo_2`, `valor_2`, `custo`), com as caixas de cada uma;
 * - a ficha técnica (`atributo_id`, `valor`, `valor_id`, `unidade`).
 *
 * O que fica FORA, de propósito:
 * - o ESTOQUE — o Bling grava por fora (`ProdutoCadastroService::gravarEstoqueDoBling`, a varredura periódica): com
 *   ele aqui, toda gaveta aberta numa loja com Bling levaria 409 falso a cada leitura de saldo;
 * - as FOTOS — enviar só acrescenta no fim da galeria e excluir age pelo id da imagem: nenhuma das duas sobrescreve
 *   o que outra aba fez, então não há o que proteger (e a gaveta envia foto sem mandar a versão);
 * - o rótulo do campo da ficha (`atributo_nome`) e os ids das LINHAS da ficha — a ficha grava substituindo, e o
 *   conteúdo é o mesmo;
 * - `updated_at` e afins — mudam sem mudar nada que a gaveta mostre.
 *
 * Números saem como TEXTO com casas fixas (custo e medidas com 2, peso com 3, as casas das colunas): o MariaDB devolve
 * decimal como texto ("890.00") e o SQLite dos testes como número (890) — a forma fixa dá o mesmo hash para o mesmo
 * valor, sem depender do driver.
 *
 * Quatro consultas, FIXAS (não crescem com as variações): o produto; os ambientes; as variações com as caixas (um
 * LEFT JOIN); a ficha. A versão pega a aba velha, não a corrida de milissegundos: duas gravações no mesmo instante
 * podem passar as duas (envolver o `gravarLinhas` numa trava seguraria o HTTP da categoria dentro dela — desenho §6.3).
 */
final class VersaoDoCadastro
{
    /** Os campos do produto que entram, na ordem do JSON. */
    private const CAMPOS_DO_PRODUTO = [
        'codigo', 'nome', 'titulo', 'familia_id', 'categoria_ml_id', 'categoria_ml_nome', 'categoria_ml_caminho', 'descricao',
    ];

    /**
     * A versão de agora do cadastro do produto: 40 caracteres hexadecimais. Lê do banco (não do model que veio, que
     * pode ser de antes da gravação).
     *
     * @throws ModelNotFoundException o produto não existe (mais) nesta empresa
     */
    public static function de(Company $empresa, EstruturaProduto $produto): string
    {
        return sha1(json_encode(self::conteudo($empresa, $produto),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR));
    }

    /**
     * O JSON canônico de que sai a versão (exposto para o teste e para conferir à mão uma divergência).
     *
     * @return array{produto: array<string, mixed>, ambientes: list<int>, variacoes: list<array<string, mixed>>, ficha: list<array<string, ?string>>}
     *
     * @throws ModelNotFoundException
     */
    public static function conteudo(Company $empresa, EstruturaProduto $produto): array
    {
        $id = (int) $produto->id;
        $linha = DB::table('estrutura_produtos')
            ->where('company_id', $empresa->id)
            ->where('id', $id)
            ->first(self::CAMPOS_DO_PRODUTO);
        if ($linha === null) {
            throw (new ModelNotFoundException())->setModel(EstruturaProduto::class, [$id]);
        }

        $dados = [];
        foreach (self::CAMPOS_DO_PRODUTO as $campo) {
            $dados[$campo] = $campo === 'familia_id' ? self::inteiro($linha->familia_id) : self::texto($linha->{$campo});
        }

        $ambientes = DB::table('estrutura_produto_ambiente')
            ->where('produto_id', $id)
            ->pluck('ambiente_id')
            ->map(fn ($a) => (int) $a)
            ->sort()
            ->values()
            ->all();

        // As variações e as caixas numa consulta só (LEFT JOIN: a variação sem caixa vem com as colunas nulas).
        $variacoes = [];
        $linhas = DB::table('estrutura_produto_variacoes as v')
            ->leftJoin('estrutura_produto_volumes as c', 'c.variacao_id', '=', 'v.id')
            ->where('v.company_id', $empresa->id)
            ->where('v.produto_id', $id)
            ->orderBy('v.ordem')->orderBy('v.id')->orderBy('c.ordem')->orderBy('c.id')
            ->get([
                'v.id', 'v.codigo', 'v.ordem', 'v.eixo', 'v.valor', 'v.eixo_2', 'v.valor_2', 'v.custo',
                'c.id as caixa_id', 'c.ordem as caixa_ordem', 'c.comprimento', 'c.largura', 'c.altura', 'c.peso',
            ]);
        foreach ($linhas as $l) {
            $vid = (int) $l->id;
            $variacoes[$vid] ??= [
                'id'      => $vid,
                'codigo'  => self::texto($l->codigo),
                'ordem'   => (int) $l->ordem,
                'eixo'    => self::texto($l->eixo),
                'valor'   => self::texto($l->valor),
                'eixo_2'  => self::texto($l->eixo_2),
                'valor_2' => self::texto($l->valor_2),
                'custo'   => self::decimal($l->custo, 2),
                'volumes' => [],
            ];
            if ($l->caixa_id !== null) {
                $variacoes[$vid]['volumes'][] = [
                    'ordem' => (int) $l->caixa_ordem,
                    'c'     => self::decimal($l->comprimento, 2),
                    'l'     => self::decimal($l->largura, 2),
                    'a'     => self::decimal($l->altura, 2),
                    'kg'    => self::decimal($l->peso, 3),
                ];
            }
        }

        $ficha = DB::table('estrutura_produto_atributos')
            ->where('company_id', $empresa->id)
            ->where('produto_id', $id)
            ->orderBy('atributo_id')->orderBy('id')
            ->get(['atributo_id', 'valor', 'valor_id', 'unidade'])
            ->map(fn ($a) => [
                'id'       => self::texto($a->atributo_id),
                'valor'    => self::texto($a->valor),
                'valor_id' => self::texto($a->valor_id),
                'unidade'  => self::texto($a->unidade),
            ])
            ->all();

        return ['produto' => $dados, 'ambientes' => $ambientes, 'variacoes' => array_values($variacoes), 'ficha' => $ficha];
    }

    private static function texto(mixed $v): ?string
    {
        return $v === null ? null : (string) $v;
    }

    private static function inteiro(mixed $v): ?int
    {
        return $v === null ? null : (int) $v;
    }

    /** O decimal com as casas da coluna, como texto ("890.00"): o mesmo valor dá o mesmo texto em qualquer driver. */
    private static function decimal(mixed $v, int $casas): ?string
    {
        return $v === null || $v === '' ? null : number_format((float) $v, $casas, '.', '');
    }
}
