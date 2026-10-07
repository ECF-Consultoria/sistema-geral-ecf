<?php

namespace App\Services\Portal\Estrutura\Produtos;

use App\Models\Company;
use App\Models\EstruturaProduto;
use App\Models\EstruturaProdutoAtributo;
use App\Services\Portal\Estrutura\RegistroEstrutura;
use App\Support\Portal\AtorDoPortal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Lê e grava a ficha técnica de UM produto: os campos da categoria dele, preenchidos
 * pelo cliente, em `estrutura_produto_atributos`.
 *
 * ### Gravar é SUBSTITUIR
 * O corpo é a ficha inteira, como a tela a mostra. Campo preenchido grava ou atualiza;
 * campo ausente ou vazio é removido; linha de um campo que a categoria atual não tem
 * (categoria trocada) também sai. Assim a tabela nunca guarda lixo de outra categoria.
 *
 * ### Validação no servidor
 * Contra a definição da categoria do produto ({@see FichaTecnicaDaCategoria}): obrigatório
 * presente, opção de lista válida, número numérico, unidade permitida. Id que a categoria
 * não conhece é IGNORADO (a tela pode estar com a definição de antes) — nunca gravado.
 *
 * ### Sigilo
 * Nenhuma mensagem de erro cita a origem dos campos; os rótulos vêm da definição, já filtrada.
 */
class FichaTecnicaDoProduto
{
    private const MAX_PADRAO = 255;

    public function __construct(private FichaTecnicaDaCategoria $definicao) {}

    /**
     * O que já está salvo, no formato que a tela consome.
     *
     * @return array<int, array{id: string, nome: string, valor: ?string, valor_id: ?string, unidade: ?string}>
     */
    public function salvos(EstruturaProduto $produto): array
    {
        return EstruturaProdutoAtributo::query()
            ->where('company_id', $produto->company_id)
            ->where('produto_id', $produto->id)
            ->orderBy('id')
            ->get()
            ->map(fn (EstruturaProdutoAtributo $a) => [
                'id'       => $a->atributo_id,
                'nome'     => $a->atributo_nome,
                'valor'    => $a->valor,
                'valor_id' => $a->valor_id,
                'unidade'  => $a->unidade,
            ])
            ->all();
    }

    /**
     * @param  array<int, mixed>  $recebidos  [{id, valor, unidade}, ...] como a tela mandou
     * @return array<int, array{id: string, nome: string, valor: ?string, valor_id: ?string, unidade: ?string}>
     *
     * @throws ValidationException
     */
    public function gravar(Company $empresa, EstruturaProduto $produto, array $recebidos, AtorDoPortal $ator): array
    {
        $categoria = trim((string) $produto->categoria_ml_id);
        if ($categoria === '') {
            throw ValidationException::withMessages([
                'atributos' => 'Escolha a categoria do produto antes de preencher a ficha técnica.',
            ]);
        }

        $campos = FichaTecnicaDaCategoria::camposPorId($this->definicao->definicao($categoria));
        if ($campos === []) {
            throw ValidationException::withMessages([
                'atributos' => 'A ficha técnica desta categoria não está disponível agora. Tente novamente em instantes.',
            ]);
        }

        // id => entrada (a última vence); ids que a categoria não conhece não entram.
        $porId = [];
        foreach ($recebidos as $entrada) {
            if (is_array($entrada) && isset($entrada['id']) && isset($campos[(string) $entrada['id']])) {
                $porId[(string) $entrada['id']] = $entrada;
            }
        }

        $preenchidos = [];
        $erros = [];
        foreach ($campos as $id => $campo) {
            try {
                $normal = $this->normalizar($campo, $porId[$id] ?? []);
            } catch (InvalidArgumentException $e) {
                $erros["atributos.{$id}"] = $e->getMessage();
                continue;
            }

            if ($normal === null) {
                if ($campo['obrigatorio']) {
                    $erros["atributos.{$id}"] = "Preencha “{$campo['nome']}”.";
                }
                continue;
            }

            $preenchidos[$id] = ['campo' => $campo] + $normal;
        }

        if ($erros !== []) {
            throw ValidationException::withMessages($erros);
        }

        DB::transaction(function () use ($empresa, $produto, $preenchidos) {
            // Lista vazia vira `['']` só para o whereNotIn não ser um no-op ambíguo.
            EstruturaProdutoAtributo::query()
                ->where('company_id', $empresa->id)
                ->where('produto_id', $produto->id)
                ->whereNotIn('atributo_id', array_keys($preenchidos) ?: [''])
                ->delete();

            foreach ($preenchidos as $id => $p) {
                EstruturaProdutoAtributo::updateOrCreate(
                    ['company_id' => $empresa->id, 'produto_id' => $produto->id, 'atributo_id' => (string) $id],
                    [
                        'atributo_nome' => mb_substr($p['campo']['nome'], 0, 160),
                        'valor'         => $p['valor'],
                        'valor_id'      => $p['valor_id'],
                        'unidade'       => $p['unidade'],
                    ],
                );
            }
        });

        RegistroEstrutura::registrar($ator, $empresa, $produto, 'ficha_tecnica_gravada',
            "Ficha técnica de “{$produto->nome}” gravada", ['produto_id' => (int) $produto->id, 'campos' => count($preenchidos)]);

        return $this->salvos($produto);
    }

    /**
     * Uma entrada da tela → o que se grava, ou null quando está vazia.
     *
     * @return array{valor: ?string, valor_id: ?string, unidade: ?string}|null
     *
     * @throws InvalidArgumentException com a mensagem que vai para o cliente
     */
    private function normalizar(array $campo, array $entrada): ?array
    {
        $bruto = $entrada['valor'] ?? null;
        $nome = $campo['nome'];

        if ($bruto === null || (is_string($bruto) && trim($bruto) === '')) {
            return null;
        }

        if (! is_scalar($bruto)) {
            throw new InvalidArgumentException("Valor inválido em “{$nome}”.");
        }

        switch ($campo['tipo']) {
            case FichaTecnicaDaCategoria::TIPO_NUMERO:
                return ['valor' => $this->numero($nome, $bruto), 'valor_id' => null, 'unidade' => null];

            case FichaTecnicaDaCategoria::TIPO_NUMERO_UNIDADE:
                return [
                    'valor'    => $this->numero($nome, $bruto),
                    'valor_id' => null,
                    'unidade'  => $this->unidade($campo, $entrada['unidade'] ?? null),
                ];

            case FichaTecnicaDaCategoria::TIPO_SIM_NAO:
                return ['valor' => $this->simNao($nome, $bruto), 'valor_id' => null, 'unidade' => null];

            case FichaTecnicaDaCategoria::TIPO_LISTA:
                $opcao = $this->opcao($campo, (string) $bruto);

                return ['valor' => $opcao['nome'], 'valor_id' => $opcao['id'], 'unidade' => null];

            default:
                $texto = trim((string) $bruto);
                $max = $campo['max'] ?? self::MAX_PADRAO;
                if (mb_strlen($texto) > $max) {
                    throw new InvalidArgumentException("“{$nome}” aceita até {$max} caracteres.");
                }

                return ['valor' => $texto, 'valor_id' => null, 'unidade' => null];
        }
    }

    private function numero(string $nome, mixed $bruto): string
    {
        if (is_bool($bruto)) {
            throw new InvalidArgumentException("“{$nome}”: ".NumeroBr::MENSAGEM);
        }

        try {
            $n = NumeroBr::interpretar($bruto);
        } catch (InvalidArgumentException) {
            throw new InvalidArgumentException("“{$nome}”: ".NumeroBr::MENSAGEM);
        }

        if ($n === null) {
            throw new InvalidArgumentException("“{$nome}”: ".NumeroBr::MENSAGEM);
        }

        // 12.5 e não 12.5000; inteiro sem casa decimal.
        return rtrim(rtrim(number_format($n, 4, '.', ''), '0'), '.');
    }

    private function unidade(array $campo, mixed $bruta): ?string
    {
        if ($campo['unidades'] === []) {
            return null;
        }

        $texto = is_scalar($bruta) ? mb_strtolower(trim((string) $bruta)) : '';
        if ($texto === '') {
            return $campo['unidade_padrao'];
        }

        foreach ($campo['unidades'] as $u) {
            if (mb_strtolower($u['id']) === $texto || mb_strtolower($u['nome']) === $texto) {
                return $u['id'];
            }
        }

        throw new InvalidArgumentException("Escolha uma unidade válida para “{$campo['nome']}”.");
    }

    private function simNao(string $nome, mixed $bruto): string
    {
        $t = mb_strtolower(trim(is_bool($bruto) ? ($bruto ? 'sim' : 'nao') : (string) $bruto));
        $t = strtr($t, ['ã' => 'a']);

        return match ($t) {
            'sim', '1', 'true', 's' => 'Sim',
            'nao', '0', 'false', 'n' => 'Não',
            default => throw new InvalidArgumentException("Escolha Sim ou Não em “{$nome}”."),
        };
    }

    /** @return array{id: string, nome: string} */
    private function opcao(array $campo, string $bruto): array
    {
        $texto = trim($bruto);

        foreach ($campo['valores'] as $v) {
            if ($v['id'] === $texto) {
                return $v;
            }
        }

        throw new InvalidArgumentException("Escolha uma das opções de “{$campo['nome']}”.");
    }
}
