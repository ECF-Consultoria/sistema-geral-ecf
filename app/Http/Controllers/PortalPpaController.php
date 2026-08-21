<?php

namespace App\Http\Controllers;

use App\Models\PpaTask;
use App\Services\Portal\PortalClienteService;
use App\Services\Portal\PortalPpaService;
use App\Support\Portal\ModulosPortal;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * PortalPpaController — o módulo PPA visto pelo cliente.
 *
 * Não existe PPA do portal: existe O PPA, em `ppas`/`ppa_tasks`, e este
 * controller é a segunda janela para ele. A equipe cria e conduz o plano em
 * `/ppa` (carteira) ou `/polos-ppa` (Polos); o cliente acompanha e move
 * tarefas por aqui. Mover uma tarefa neste controller altera exatamente a
 * linha que o kanban interno mostra — não há cópia para sincronizar.
 *
 * A régua de "o que este cliente vê e no que pode mexer" está inteira em
 * {@see PortalPpaService}, e é a mesma para a listagem e para a escrita.
 *
 * ### Relação com `/ppa/workspace/{token}` (o link por PPA que já existia)
 * Aquele workspace continua de pé e não foi tocado: é um link avulso, por PPA,
 * que a equipe gera e envia para um plano específico. O módulo do portal é o
 * caminho por EMPRESA — o cliente entra uma vez e vê todos os planos dele, sem
 * precisar de um link novo a cada PPA. Os dois escrevem nas mesmas linhas.
 */
class PortalPpaController extends Controller
{
    public function __construct(
        private PortalClienteService $portal,
        private PortalPpaService $ppaService,
    ) {
    }

    /** GET /portal-cliente/{token}/ppa */
    public function index(string $token)
    {
        $link = $this->portal->resolver($token);

        return Inertia::render('Portal/Ppa', [
            ...$this->portal->contexto($link, ModulosPortal::PPA),
            'ppas' => $this->ppaService
                ->ppasDaEmpresa($link->company)
                ->map(fn ($ppa) => $this->ppaService->visao($ppa))
                ->values()
                ->all(),
        ]);
    }

    /**
     * PATCH /portal-cliente/{token}/ppa/tarefas/{task} — o cliente move a
     * tarefa entre "A fazer", "Fazendo" e "Concluído".
     *
     * O 403 aqui é a trava que impede o cliente da empresa A de mover a tarefa
     * da empresa B trocando o id na URL: o token diz QUEM é, e
     * `podeMexer()` confere que a tarefa está num plano visível para essa
     * empresa e ainda aberto.
     *
     * Responde JSON porque a tela atualiza o card no lugar, sem recarregar —
     * mesmo padrão do workspace por PPA (`PpaTaskController::clientUpdate()`).
     */
    public function moverTarefa(Request $request, string $token, PpaTask $task)
    {
        $link = $this->portal->resolver($token);

        abort_unless($this->ppaService->podeMexer($link->company, $task), 403);

        $data = $request->validate([
            'status' => ['required', 'in:todo,doing,done'],
        ]);

        // Caminho ÚNICO de movimentação, o mesmo do quadro interno
        // (`PpaTask::moverPara()`): é ele que carimba e limpa `concluida_em`.
        // A coluna extra é preservada quando pertence ao status de destino —
        // o portal mostra três colunas, e mover por aqui não pode desfazer a
        // organização mais fina feita do lado interno.
        $task->moverPara($data['status'], $task->coluna_id);

        // `origem` explícita: o `causer_id` do activity log não distingue os
        // lados aqui, porque o portal roda no grupo `web` e uma sessão interna
        // aberta em outra aba faz o Spatie carimbar um usuário nosso numa ação
        // do cliente. É esta propriedade que o card "Última atualização" do
        // quadro interno lê.
        activity('ppa')
            ->performedOn($task)
            ->withProperties(['origem' => 'cliente', 'status' => $data['status'], 'ip' => $request->ip()])
            ->log("Tarefa movida para \"{$data['status']}\" pelo cliente via Portal do Cliente");

        return response()->json(['ok' => true, 'status' => $task->status]);
    }
}
