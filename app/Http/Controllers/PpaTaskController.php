<?php

namespace App\Http\Controllers;

use App\Models\Ppa;
use App\Models\PpaTask;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PpaTaskController extends Controller
{
    /**
     * Regras dos campos do card. Todas nullable de propósito: `ppa_tasks` tem
     * linhas criadas quando a tarefa era só título + descrição + status, e
     * tornar qualquer um destes obrigatório impediria editar uma tarefa antiga
     * sem inventar dado que ninguém tem.
     *
     * @return array<string, mixed>
     */
    private function regrasDoCard(): array
    {
        return [
            'area'             => ['nullable', 'string', 'max:40'],
            'prioridade'       => ['nullable', 'in:'.implode(',', PpaTask::PRIORIDADES)],
            'prazo'            => ['nullable', 'date'],
            'responsavel_lado' => ['nullable', 'in:'.implode(',', PpaTask::LADOS)],
        ];
    }

    // ── Admin / Mentor: criar tarefa ─────────────────────────────────────────

    public function store(Request $request, Ppa $ppa)
    {
        $data = $request->validate([
            'title'       => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'status'      => 'nullable|in:todo,doing,done',
            // Coluna EXTRA em que o card foi criado. `moverPara()` valida se ela
            // é deste PPA e se bate com o status — id de outro plano cai fora
            // em silêncio, e o card nasce na coluna base.
            'coluna_id'   => 'nullable|integer',
            ...$this->regrasDoCard(),
        ]);

        $status = $data['status'] ?? 'todo';
        $maxOrder = $ppa->tasks()->where('status', $status)->max('order') ?? -1;

        $task = $ppa->tasks()->create([
            ...$data,
            'status'     => $status,
            'coluna_id'  => null,
            'order'      => $maxOrder + 1,
            'created_by' => $request->user()->id,
        ]);

        // Passa pelo caminho único de movimentação para validar a coluna e
        // carimbar `concluida_em` quando alguém cria a tarefa já concluída.
        $task->moverPara($status, $data['coluna_id'] ?? null);

        return back()->with('success', 'Tarefa adicionada.');
    }

    // ── Admin / Mentor: atualizar tarefa ──────────────────────────────────────

    public function update(Request $request, PpaTask $task)
    {
        $data = $request->validate([
            'title'       => 'nullable|string|max:255',
            'description' => 'nullable|string|max:1000',
            'status'      => 'nullable|in:todo,doing,done',
            'coluna_id'   => 'nullable|integer',
            ...$this->regrasDoCard(),
        ]);

        // Status sai do `update()` em massa: quem o aplica é `moverPara()`, que
        // cuida de `concluida_em` e da coerência com a coluna extra.
        $status   = $data['status'] ?? null;
        $colunaId = array_key_exists('coluna_id', $data) ? $data['coluna_id'] : $task->coluna_id;
        unset($data['status'], $data['coluna_id']);

        $task->update($data);

        if ($status !== null) {
            $task->moverPara($status, $colunaId);
            $this->registrarMovimento($task, $status, $request);
        }

        return back()->with('success', 'Tarefa atualizada.');
    }

    // ── Admin / Mentor: remover tarefa ────────────────────────────────────────

    public function destroy(PpaTask $task)
    {
        $task->delete();
        return back()->with('success', 'Tarefa removida.');
    }

    // ── Admin / Mentor: mover por drag-and-drop ──────────────────────────────

    /**
     * PATCH /ppa/tasks/{task}/mover — destino do arraste.
     *
     * Rota própria, separada de `update()`, por três motivos:
     *
     *  1. É a única que reordena. O arraste devolve a lista inteira de ids na
     *     ordem em que os cards ficaram na coluna de destino, e é isso que
     *     persiste a posição — sem ela, soltar um card no meio da coluna o
     *     mandaria para o fim assim que a página recarregasse.
     *  2. Responde JSON. A tela já moveu o card na hora (atualização otimista);
     *     uma resposta Inertia recarregaria as props e faria o quadro piscar a
     *     cada arraste.
     *  3. Deixa `update()` livre para ser só edição de conteúdo.
     */
    public function mover(Request $request, PpaTask $task)
    {
        $data = $request->validate([
            'status'    => ['required', 'in:todo,doing,done'],
            'coluna_id' => ['nullable', 'integer'],
            // Ids da coluna de destino, na ordem final. Só os desta coluna —
            // mandar o quadro inteiro faria cada arraste reescrever todas as
            // tarefas do plano.
            'ordem'     => ['nullable', 'array'],
            'ordem.*'   => ['integer'],
        ]);

        $task->moverPara($data['status'], $data['coluna_id'] ?? null);
        $this->registrarMovimento($task, $data['status'], $request);

        if (! empty($data['ordem'])) {
            $this->reordenar($task->ppa_id, $data['ordem']);
        }

        return response()->json([
            'ok'           => true,
            'status'       => $task->status,
            'coluna_id'    => $task->coluna_id,
            'concluida_em' => $task->concluida_em?->format('d/m'),
        ]);
    }

    /**
     * Grava a ordem dos cards de uma coluna.
     *
     * `whereIn(ppa_id)` é a trava: um id de outro plano na lista não reordena
     * nada: ele simplesmente não casa. Sem isso, a rota aceitaria reescrever a
     * ordem de tarefas de qualquer PPA do sistema.
     *
     * Numa transação porque a ordem só faz sentido inteira — meia lista
     * aplicada deixaria dois cards disputando a mesma posição.
     *
     * @param  array<int, int>  $ids
     */
    private function reordenar(int $ppaId, array $ids): void
    {
        DB::transaction(function () use ($ppaId, $ids) {
            foreach (array_values($ids) as $posicao => $id) {
                PpaTask::where('id', $id)->where('ppa_id', $ppaId)->update(['order' => $posicao]);
            }
        });
    }

    /**
     * Registra de que LADO veio o movimento.
     *
     * A propriedade `origem` existe porque o `causer_id` do activity log NÃO
     * responde isso: o Portal do Cliente roda no grupo `web`, então uma sessão
     * interna aberta em outra aba faz o Spatie carimbar um usuário nosso numa
     * ação do cliente (medido em 21/08/2026). É esta propriedade que o card
     * "Última atualização" lê em `PpaQuadroService::ultimaAtualizacao()`.
     */
    private function registrarMovimento(PpaTask $task, string $status, Request $request): void
    {
        activity('ppa')
            ->performedOn($task)
            ->withProperties(['origem' => 'interno', 'status' => $status, 'ip' => $request->ip()])
            ->log("Tarefa movida para \"{$status}\" pela equipe");
    }

    // ── Público (cliente): mover tarefa ──────────────────────────────────────
    // Valida pelo workspace_token do PPA - sem autenticação

    public function clientUpdate(Request $request, string $token, PpaTask $task)
    {
        // Verifica que a tarefa pertence ao PPA do token
        $ppa = Ppa::where('workspace_token', $token)->firstOrFail();
        abort_if($task->ppa_id !== $ppa->id, 403);

        $data = $request->validate([
            'status' => 'required|in:todo,doing,done',
        ]);

        // Preserva a coluna extra quando ela pertence ao status de destino —
        // este link mostra só as três colunas base, e mover por aqui não pode
        // desfazer a organização mais fina feita do lado interno.
        $task->moverPara($data['status'], $task->coluna_id);

        activity('ppa')
            ->performedOn($task)
            ->withProperties(['origem' => 'cliente', 'status' => $data['status'], 'ip' => $request->ip()])
            ->log("Tarefa movida para \"{$data['status']}\" pelo cliente via link do quadro");

        return response()->json(['ok' => true, 'status' => $task->status]);
    }
}
