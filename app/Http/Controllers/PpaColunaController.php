<?php

namespace App\Http\Controllers;

use App\Models\Ppa;
use App\Models\PpaColuna;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * PpaColunaController — as colunas EXTRAS do quadro de um PPA.
 *
 * As três colunas fixas (`A Fazer`, `Em Andamento`, `Concluído`) NÃO passam por
 * aqui: elas são o ENUM `ppa_tasks.status` e não têm linha em `ppa_colunas`.
 * Não há rota neste controller capaz de renomeá-las, recolori-las, movê-las ou
 * apagá-las — a proteção é estrutural, não uma verificação que alguém possa
 * esquecer.
 *
 * Toda coluna extra se ancora num `status_base`, e é isso que mantém o Portal
 * do Cliente e os contadores de progresso funcionando sem saber que colunas
 * extras existem. Ver o docblock da migration `create_ppa_colunas_table`.
 *
 * Serve os dois escopos: o quadro de carteira e o de Polos são a mesma tela, e
 * a coluna pertence ao PPA, não ao escopo.
 */
class PpaColunaController extends Controller
{
    public function store(Request $request, Ppa $ppa)
    {
        $data = $request->validate([
            'nome'        => ['required', 'string', 'max:60'],
            'status_base' => ['required', Rule::in(PpaColuna::STATUS_BASE)],
            'cor'         => ['nullable', Rule::in(PpaColuna::CORES)],
        ]);

        // Entra no fim do bloco do seu `status_base` — a coluna nova aparece
        // depois das que já existem ali, e não no meio delas.
        $posicao = $ppa->colunas()->where('status_base', $data['status_base'])->max('posicao') ?? 0;

        $ppa->colunas()->create([
            ...$data,
            'cor'     => $data['cor'] ?? 'slate',
            'posicao' => $posicao + 1,
        ]);

        return back()->with('success', 'Coluna adicionada.');
    }

    public function update(Request $request, PpaColuna $coluna)
    {
        $data = $request->validate([
            'nome' => ['nullable', 'string', 'max:60'],
            'cor'  => ['nullable', Rule::in(PpaColuna::CORES)],
        ]);

        // `status_base` NÃO é editável. Trocá-lo moveria de etapa, de uma só
        // vez e sem aviso, todas as tarefas da coluna — uma coluna de revisão
        // virando "Concluído" marcaria como feito trabalho que ninguém
        // terminou, e isso apareceria na hora no portal do cliente. Para mudar
        // de etapa, cria-se a coluna certa e arrastam-se os cards.
        $coluna->update(array_filter($data, fn ($v) => $v !== null));

        return back()->with('success', 'Coluna atualizada.');
    }

    /**
     * Apagar a coluna devolve as tarefas dela à coluna BASE do status que já
     * tinham (`coluna_id` vira null pela FK `nullOnDelete`). Nenhuma tarefa é
     * apagada junto: perder trabalho por uma decisão de organização visual
     * seria o pior resultado possível aqui.
     */
    public function destroy(PpaColuna $coluna)
    {
        $quantas = $coluna->tasks()->count();
        $coluna->delete();

        return back()->with('success', $quantas > 0
            ? "Coluna removida. {$quantas} ".($quantas === 1 ? 'tarefa voltou' : 'tarefas voltaram').' para a coluna original.'
            : 'Coluna removida.');
    }
}
