<?php

namespace App\Services\Portal\Estrutura\Geracao;

use App\Models\EstruturaProdutoGeracao;
use App\Models\EstruturaTipoPar;
use App\Models\EstruturaTipoProduto;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Manutenção da lista GLOBAL de tipos e pares pela ECF (Fase 168, D-06/D-13/D-14/D-15).
 * Regras de slug, palavras-chave, quantidades e do par não ordenado ficam aqui; o controller
 * só valida a forma e delega. Toda escrita gera linha em `activity_log` (log 'estrutura_geracao').
 */
class CatalogoDaEcfService
{
    public const MENSAGEM_PAR_REPETIDO = 'Este par já existe. Edite o que está na lista.';

    /**
     * Converte a direção do formulário no par não ordenado guardado (tipo_a_id <= tipo_b_id).
     *
     * @param  string  $combit  'nao' | 'primeiro' | 'segundo' | 'ambos'
     * @return array{tipo_a_id: int, tipo_b_id: int, combit_repete: ?string}
     */
    public static function ordenarPar(int $primeiro, int $segundo, string $combit): array
    {
        $a = min($primeiro, $segundo);
        $b = max($primeiro, $segundo);

        $repete = match ($combit) {
            'ambos'    => 'ambos',
            'primeiro' => $primeiro === $segundo ? 'ambos' : ($primeiro === $a ? 'a' : 'b'),
            'segundo'  => $primeiro === $segundo ? 'ambos' : ($segundo === $a ? 'a' : 'b'),
            default    => null,
        };

        return ['tipo_a_id' => $a, 'tipo_b_id' => $b, 'combit_repete' => $repete];
    }

    /** @param array<string,mixed> $dados nome, plural, palavras, qtd_combo, qtd_combit, ordem */
    public function criarTipo(array $dados): EstruturaTipoProduto
    {
        $campos = $this->camposDoTipo($dados);

        return DB::transaction(function () use ($campos, $dados) {
            $campos['slug'] = $this->slugUnico($campos['nome']);
            $campos['ordem'] = isset($dados['ordem']) && $dados['ordem'] !== ''
                ? (int) $dados['ordem']
                : ((int) EstruturaTipoProduto::max('ordem')) + 10;

            $tipo = EstruturaTipoProduto::create($campos);
            $this->registrar("Tipo {$tipo->nome} salvo", ['tipo_id' => $tipo->id, 'acao' => 'criar']);

            return $tipo;
        });
    }

    /** @param array<string,mixed> $dados */
    public function atualizarTipo(EstruturaTipoProduto $tipo, array $dados): EstruturaTipoProduto
    {
        $campos = $this->camposDoTipo($dados);

        return DB::transaction(function () use ($tipo, $campos, $dados) {
            if (isset($dados['ordem']) && $dados['ordem'] !== '') {
                $campos['ordem'] = (int) $dados['ordem'];
            }
            // O slug nunca muda depois de criado.
            $tipo->update($campos);
            $this->registrar("Tipo {$tipo->nome} salvo", ['tipo_id' => $tipo->id, 'acao' => 'atualizar']);

            return $tipo->refresh();
        });
    }

    public function excluirTipo(EstruturaTipoProduto $tipo): void
    {
        DB::transaction(function () use ($tipo) {
            // Explícito (não depende só da FK): pares somem e os produtos voltam à inferência.
            EstruturaTipoPar::where('tipo_a_id', $tipo->id)->orWhere('tipo_b_id', $tipo->id)->delete();
            EstruturaProdutoGeracao::where('tipo_id', $tipo->id)->update(['tipo_id' => null]);
            $tipo->delete();
            $this->registrar("Tipo {$tipo->nome} excluído", ['tipo_id' => $tipo->id, 'acao' => 'excluir']);
        });
    }

    public function criarPar(int $primeiro, int $segundo, string $combit): EstruturaTipoPar
    {
        $campos = self::ordenarPar($primeiro, $segundo, $combit);
        $this->recusarParExistente($campos);

        return DB::transaction(function () use ($campos) {
            $par = $this->gravarPar(fn () => EstruturaTipoPar::create($campos));
            $this->registrar($this->rotuloDoPar($par).' salvo', ['par_id' => $par->id, 'acao' => 'criar']);

            return $par;
        });
    }

    public function atualizarPar(EstruturaTipoPar $par, int $primeiro, int $segundo, string $combit): EstruturaTipoPar
    {
        $campos = self::ordenarPar($primeiro, $segundo, $combit);
        $this->recusarParExistente($campos, $par->id);

        return DB::transaction(function () use ($par, $campos) {
            $this->gravarPar(fn () => $par->update($campos));
            $this->registrar($this->rotuloDoPar($par).' salvo', ['par_id' => $par->id, 'acao' => 'atualizar']);

            return $par->refresh();
        });
    }

    public function excluirPar(EstruturaTipoPar $par): void
    {
        DB::transaction(function () use ($par) {
            $rotulo = $this->rotuloDoPar($par);
            $par->delete();
            $this->registrar("{$rotulo} excluído", ['par_id' => $par->id, 'acao' => 'excluir']);
        });
    }

    /** @return array{nome: string, plural: string, palavras: string, qtd_combo: ?string, qtd_combit: ?string} */
    private function camposDoTipo(array $dados): array
    {
        $palavras = TipoDoProduto::palavras((string) ($dados['palavras'] ?? ''));
        if ($palavras === []) {
            throw ValidationException::withMessages(['palavras' => 'Informe ao menos uma palavra-chave.']);
        }

        $combo = Quantidades::ler($this->textoOuNulo($dados['qtd_combo'] ?? null), 'qtd_combo');
        $combit = Quantidades::ler($this->textoOuNulo($dados['qtd_combit'] ?? null), 'qtd_combit');

        return [
            'nome'       => trim((string) ($dados['nome'] ?? '')),
            'plural'     => trim((string) ($dados['plural'] ?? '')),
            'palavras'   => implode(', ', $palavras),
            'qtd_combo'  => Quantidades::paraTexto($combo),
            'qtd_combit' => Quantidades::paraTexto($combit),
        ];
    }

    private function textoOuNulo(mixed $valor): ?string
    {
        return $valor === null ? null : (string) $valor;
    }

    private function slugUnico(string $nome): string
    {
        $base = Str::slug($nome) ?: 'tipo';
        $slug = $base;
        $n = 2;
        while (EstruturaTipoProduto::where('slug', $slug)->exists()) {
            $slug = $base.'-'.$n++;
        }

        return $slug;
    }

    /** @param array{tipo_a_id: int, tipo_b_id: int, combit_repete: ?string} $campos */
    private function recusarParExistente(array $campos, ?int $ignorarId = null): void
    {
        $existe = EstruturaTipoPar::where('tipo_a_id', $campos['tipo_a_id'])
            ->where('tipo_b_id', $campos['tipo_b_id'])
            ->when($ignorarId, fn ($q) => $q->where('id', '!=', $ignorarId))
            ->exists();

        if ($existe) {
            throw ValidationException::withMessages(['tipo_b_id' => self::MENSAGEM_PAR_REPETIDO]);
        }
    }

    /** Captura a corrida do índice único (23000) com a mesma mensagem. */
    private function gravarPar(\Closure $escrita): mixed
    {
        try {
            return $escrita();
        } catch (QueryException $e) {
            if ((string) $e->getCode() === '23000') {
                throw ValidationException::withMessages(['tipo_b_id' => self::MENSAGEM_PAR_REPETIDO]);
            }
            throw $e;
        }
    }

    private function rotuloDoPar(EstruturaTipoPar $par): string
    {
        $a = EstruturaTipoProduto::find($par->tipo_a_id)?->nome ?? "#{$par->tipo_a_id}";
        $b = EstruturaTipoProduto::find($par->tipo_b_id)?->nome ?? "#{$par->tipo_b_id}";

        return "Par {$a} + {$b}";
    }

    /** @param array<string,mixed> $propriedades */
    private function registrar(string $descricao, array $propriedades): void
    {
        activity('estrutura_geracao')
            ->causedBy(auth()->user())
            ->withProperties($propriedades)
            ->log($descricao);
    }
}
