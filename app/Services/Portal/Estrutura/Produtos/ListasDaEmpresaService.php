<?php

namespace App\Services\Portal\Estrutura\Produtos;

use App\Models\Company;
use App\Models\EstruturaAmbiente;
use App\Models\EstruturaFamilia;
use App\Services\Portal\Estrutura\RegistroEstrutura;
use App\Support\Portal\AtorDoPortal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Família e ambiente como listas da própria empresa (D-05, D-07).
 *
 * "Família" aqui é a linha de design da planilha (ex.: Farmhouse), não "o mesmo
 * produto em várias cores" do Onboarding. O nome é criado uma vez e depois
 * escolhido; um produto pode ter vários ambientes, uma só família.
 *
 * ### Por que a normalização é no PHP
 * O `utf8mb4_unicode_ci` do MariaDB ignora caixa e acento ("Cômoda" = "comoda"),
 * o SQLite dos testes é binário. Se a comparação ficasse só no unique do banco,
 * o teste passaria e a produção recusaria (ou o contrário). Então a chave
 * (`chave()`) é calculada aqui e o unique do banco fica como rede de segurança:
 * a mensagem para o cliente vem daqui.
 *
 * Toda lista é filtrada por `company_id`: id de outra empresa dá 404, igual a
 * inexistente — nunca confirma que existe.
 */
class ListasDaEmpresaService
{
    public const FAMILIA  = 'familia';
    public const AMBIENTE = 'ambiente';

    private const MODELOS = [
        self::FAMILIA  => EstruturaFamilia::class,
        self::AMBIENTE => EstruturaAmbiente::class,
    ];

    private const ROTULO = [
        self::FAMILIA  => 'família',
        self::AMBIENTE => 'ambiente',
    ];

    /** Forma usada para COMPARAR nomes: sem acento, sem caixa, espaços colapsados. */
    public static function chave(string $nome): string
    {
        return mb_strtolower(Str::ascii(self::limpar($nome)));
    }

    /** Grafia que se grava: aparada e com espaços colapsados. */
    public static function limpar(string $nome): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $nome));
    }

    /** @return list<array{id: int, nome: string, em_uso: int}> */
    public function lista(Company $empresa, string $tipo): array
    {
        $modelo = $this->modelo($tipo);

        return $modelo::query()
            ->where('company_id', $empresa->id)
            ->orderBy('nome')
            ->get()
            ->map(fn (Model $i) => ['id' => (int) $i->id, 'nome' => $i->nome, 'em_uso' => $this->emUso($tipo, $i)])
            ->values()
            ->all();
    }

    /** @return array{0: Model, 1: bool} o item e se foi criado agora */
    public function criar(Company $empresa, string $tipo, string $nome, AtorDoPortal $ator): array
    {
        $nome = $this->validar($tipo, $nome);

        return DB::transaction(function () use ($empresa, $tipo, $nome, $ator) {
            $existente = $this->achar($empresa, $tipo, $nome);
            if ($existente) {
                return [$existente, false];
            }

            $modelo = $this->modelo($tipo);

            try {
                $item = DB::transaction(fn () => $modelo::create(['company_id' => $empresa->id, 'nome' => $nome]));
            } catch (QueryException $e) {
                // Corrida: outro pedido criou o mesmo nome entre o achar() e o insert.
                if ((string) $e->getCode() === '23000') {
                    $existente = $this->achar($empresa, $tipo, $nome);
                    if ($existente) {
                        return [$existente, false];
                    }
                    throw ValidationException::withMessages(['nome' => "Já existe “{$nome}”."]);
                }
                throw $e;
            }

            RegistroEstrutura::registrar($ator, $empresa, $item, 'lista_criada',
                ucfirst(self::ROTULO[$tipo])." “{$item->nome}” criada", ['tipo' => $tipo]);

            return [$item, true];
        });
    }

    public function renomear(Company $empresa, string $tipo, int $id, string $nome, AtorDoPortal $ator): Model
    {
        $item = $this->modelo($tipo)::query()->where('company_id', $empresa->id)->findOrFail($id);
        $nome = $this->validar($tipo, $nome);

        $outro = $this->achar($empresa, $tipo, $nome);
        if ($outro && $outro->id !== $item->id) {
            throw ValidationException::withMessages(['nome' => "Já existe “{$outro->nome}”."]);
        }

        return DB::transaction(function () use ($empresa, $tipo, $item, $nome, $ator) {
            $antigo = $item->nome;

            try {
                $item->update(['nome' => $nome]);
            } catch (QueryException $e) {
                if ((string) $e->getCode() === '23000') {
                    throw ValidationException::withMessages(['nome' => "Já existe “{$nome}”."]);
                }
                throw $e;
            }

            RegistroEstrutura::registrar($ator, $empresa, $item, 'lista_renomeada',
                ucfirst(self::ROTULO[$tipo])." “{$antigo}” renomeada para “{$nome}”", ['tipo' => $tipo, 'nome_antigo' => $antigo]);

            return $item;
        });
    }

    public function excluir(Company $empresa, string $tipo, int $id, AtorDoPortal $ator): void
    {
        $item = $this->modelo($tipo)::query()->where('company_id', $empresa->id)->findOrFail($id);

        $uso = $this->emUso($tipo, $item);
        if ($uso > 0) {
            $quais = $uso === 1 ? 'Em uso em 1 produto.' : "Em uso em {$uso} produtos.";

            throw ValidationException::withMessages(['nome' => "{$quais} Troque nos produtos para poder excluir."]);
        }

        DB::transaction(function () use ($empresa, $tipo, $item, $ator) {
            RegistroEstrutura::registrar($ator, $empresa, null, 'lista_excluida',
                ucfirst(self::ROTULO[$tipo])." “{$item->nome}” excluída", ['tipo' => $tipo, 'nome' => $item->nome]);

            $item->delete();
        });
    }

    /**
     * Resolve nomes digitados para ids da lista, deduplicando por chave (a 1ª
     * grafia vence). Com `$ator` nulo só SIMULA — nada é gravado (serve à prévia
     * da importação); com ator, cria os que faltam.
     *
     * @param  array<int, string>  $nomes
     * @return array{ids: list<int>, novos: list<string>}
     */
    public function resolverNomes(Company $empresa, string $tipo, array $nomes, ?AtorDoPortal $ator): array
    {
        $this->modelo($tipo);

        $unicos = [];
        foreach ($nomes as $nome) {
            $limpo = self::limpar((string) $nome);
            if ($limpo === '') {
                continue;
            }
            $unicos[self::chave($limpo)] ??= $limpo;
        }

        $ids = [];
        $novos = [];

        foreach ($unicos as $nome) {
            $existente = $this->achar($empresa, $tipo, $nome);

            if ($existente) {
                $ids[] = (int) $existente->id;
                continue;
            }

            if ($ator === null) {
                $novos[] = $nome;
                continue;
            }

            [$item, $criado] = $this->criar($empresa, $tipo, $nome, $ator);
            $ids[] = (int) $item->id;
            if ($criado) {
                $novos[] = $item->nome;
            }
        }

        return ['ids' => $ids, 'novos' => $novos];
    }

    // ═══ Internos ═══════════════════════════════════════════════════════════

    /** @return class-string<Model> */
    private function modelo(string $tipo): string
    {
        return self::MODELOS[$tipo] ?? throw new InvalidArgumentException("Tipo de lista desconhecido: {$tipo}");
    }

    private function validar(string $tipo, string $nome): string
    {
        $this->modelo($tipo);
        $nome = self::limpar($nome);

        if ($nome === '') {
            throw ValidationException::withMessages(['nome' => 'Informe o nome.']);
        }
        if (preg_match('/[\/,|]/u', $nome)) {
            throw ValidationException::withMessages(['nome' => 'Não use / , | no nome. Escolha um nome simples.']);
        }
        if (mb_strlen($nome) > 80) {
            throw ValidationException::withMessages(['nome' => 'O nome pode ter no máximo 80 caracteres.']);
        }

        return $nome;
    }

    /** Item da empresa com a mesma chave (comparação feita no PHP, ver docblock da classe). */
    private function achar(Company $empresa, string $tipo, string $nome): ?Model
    {
        $chave = self::chave($nome);

        return $this->modelo($tipo)::query()
            ->where('company_id', $empresa->id)
            ->get()
            ->first(fn (Model $i) => self::chave($i->nome) === $chave);
    }

    private function emUso(string $tipo, Model $item): int
    {
        return $tipo === self::FAMILIA
            ? $item->produtos()->count()
            : DB::table('estrutura_produto_ambiente')->where('ambiente_id', $item->id)->count();
    }
}
