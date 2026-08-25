<?php

namespace App\Services\Ppa;

use App\Models\OnboardingLink;
use App\Models\Ppa;
use App\Models\PpaColuna;
use App\Models\PpaTask;
use App\Services\Portal\PortalPpaService;
use Illuminate\Support\Facades\DB;

/**
 * PpaQuadroService — monta o payload da tela individual do PPA (o quadro).
 *
 * Existe porque a MESMA tela serve os dois escopos: `PpaController::kanban()`
 * (carteira) e `PolosPpaController::kanban()` (Polos) renderizam o mesmo
 * componente React. Antes de existir este service os dois montavam o payload
 * por conta própria, e o de Polos já era uma cópia levemente atrasada do outro.
 *
 * Nada aqui inventa regra: progresso, prazo, visibilidade e responsáveis são
 * leituras do que já estava no banco. A régua de visibilidade é a MESMA do
 * Portal do Cliente ({@see PortalPpaService::STATUS_VISIVEIS}) — importada, não
 * reescrita, para que a tela interna nunca diga "o cliente vê" sobre um plano
 * que o portal esconde.
 */
class PpaQuadroService
{
    /**
     * As três colunas fixas do quadro. São o ENUM `ppa_tasks.status` e NÃO
     * vivem em `ppa_colunas` — ver o docblock daquela migration. Ordem, chaves
     * e rótulos são exatamente os que a tela sempre teve; renomeá-los aqui
     * mudaria o vocabulário que a equipe já usa nas reuniões.
     */
    public const COLUNAS_BASE = [
        ['status' => 'todo',  'nome' => 'A Fazer',      'cor' => 'slate'],
        ['status' => 'doing', 'nome' => 'Em Andamento', 'cor' => 'amber'],
        ['status' => 'done',  'nome' => 'Concluído',    'cor' => 'emerald'],
    ];

    /**
     * O quadro inteiro, pronto para o Inertia.
     *
     * @return array{ppa: array, colunas: array, tasks: array, resumo: array}
     */
    public function payload(Ppa $ppa): array
    {
        $ppa->loadMissing(['company', 'mlbEmpresa', 'mentor', 'tasks', 'colunas']);

        return [
            'ppa'     => $this->cabecalho($ppa),
            'colunas' => $this->colunas($ppa),
            'tasks'   => $this->tarefas($ppa),
            'resumo'  => $this->resumo($ppa),
        ];
    }

    /** Identidade do plano — o que o topo da tela mostra. */
    private function cabecalho(Ppa $ppa): array
    {
        return [
            'id'              => $ppa->id,
            'title'           => $ppa->title,
            'description'     => $ppa->description,
            'company_name'    => $ppa->nomeEmpresa(),
            'mentor_name'     => $ppa->mentor?->name,
            'status'          => $ppa->status,
            'due_date'        => $ppa->due_date?->format('d/m/Y'),
            'workspace_token' => $ppa->workspace_token,
            // O link avulso por PPA, que já existia. Continua sendo gerado e
            // enviado do mesmo jeito.
            'workspace_url'   => $ppa->workspace_token ? route('ppa.workspace', $ppa->workspace_token) : null,
            'trello_board_url' => $ppa->trello_board_url,
        ];
    }

    /**
     * As colunas do quadro: as três fixas, com as extras encaixadas logo após a
     * base a que cada uma pertence.
     *
     * A chave `key` é o que a tela usa para agrupar e o que o drag-and-drop
     * devolve ao soltar: `todo` para a base, `extra:12` para a coluna extra de
     * id 12. Assim a tela não precisa saber que existem dois mundos.
     */
    public function colunas(Ppa $ppa): array
    {
        $extras = $ppa->colunas->groupBy('status_base');
        $colunas = [];

        foreach (self::COLUNAS_BASE as $base) {
            $colunas[] = [
                'key'         => $base['status'],
                'nome'        => $base['nome'],
                'cor'         => $base['cor'],
                'status_base' => $base['status'],
                // Coluna fixa não se renomeia, não se recolore e não se apaga:
                // ela É o status. A tela usa isto para esconder o menu de
                // edição em vez de oferecer uma ação que falharia.
                'fixa'        => true,
                'id'          => null,
            ];

            foreach ($extras->get($base['status'], []) as $extra) {
                $colunas[] = [
                    'key'         => 'extra:'.$extra->id,
                    'nome'        => $extra->nome,
                    'cor'         => $extra->cor,
                    'status_base' => $extra->status_base,
                    'fixa'        => false,
                    'id'          => $extra->id,
                ];
            }
        }

        return $colunas;
    }

    /**
     * As tarefas. `coluna_key` é onde o card é desenhado — a coluna extra
     * quando houver, a base do `status` quando não.
     *
     * Campo ausente viaja como `null` de propósito: a tela decide não desenhar
     * a linha inteira do rodapé quando não há nada nela, em vez de mostrar
     * "Prazo: —".
     */
    public function tarefas(Ppa $ppa): array
    {
        // Coluna extra que aponta para um `status_base` diferente do `status`
        // da tarefa não pode arrastar o card para o lugar errado: quem manda é
        // o `status`. Isso só acontece se alguém editar o `status_base` de uma
        // coluna que já tem tarefas — e aí o card volta à base correta sozinho.
        $extras = $ppa->colunas->keyBy('id');

        return $ppa->tasks
            ->sortBy('order')
            ->map(function (PpaTask $t) use ($extras) {
                $extra = $t->coluna_id ? $extras->get($t->coluna_id) : null;
                $naColunaCerta = $extra && $extra->status_base === $t->status;

                return [
                    'id'               => $t->id,
                    'title'            => $t->title,
                    'description'      => $t->description,
                    'status'           => $t->status,
                    'order'            => $t->order,
                    'coluna_id'        => $naColunaCerta ? $t->coluna_id : null,
                    'coluna_key'       => $naColunaCerta ? 'extra:'.$t->coluna_id : $t->status,
                    'area'             => $t->area,
                    'prioridade'       => $t->prioridade,
                    'prazo'            => $t->prazo?->format('d/m/Y'),
                    'prazo_iso'        => $t->prazo?->format('Y-m-d'),
                    // Dias até o prazo, para a tela pintar o atraso sem
                    // reimplementar cálculo de data no JS (e sem o fuso do
                    // navegador virar um dia de diferença).
                    'prazo_dias'       => $t->prazo && $t->status !== 'done'
                        ? (int) now()->startOfDay()->diffInDays($t->prazo->startOfDay(), false)
                        : null,
                    'responsavel_lado' => $t->responsavel_lado,
                    'concluida_em'     => $t->concluida_em?->format('d/m'),
                ];
            })
            ->values()
            ->all();
    }

    /** Os cards do topo: progresso, prazo, última atualização, responsáveis, visibilidade. */
    public function resumo(Ppa $ppa): array
    {
        $total  = $ppa->tasks->count();
        $feitas = $ppa->tasks->where('status', 'done')->count();

        return [
            'progresso' => [
                'feitas' => $feitas,
                'total'  => $total,
                'pct'    => $total > 0 ? (int) round(($feitas / $total) * 100) : 0,
            ],
            'prazo'         => $this->prazo($ppa),
            'atualizacao'   => $this->ultimaAtualizacao($ppa),
            'responsaveis'  => $this->responsaveis($ppa),
            'visibilidade'  => $this->visibilidade($ppa),
        ];
    }

    /** Prazo do PLANO (`ppas.due_date`) — não confundir com o prazo da tarefa. */
    private function prazo(Ppa $ppa): array
    {
        if (! $ppa->due_date) {
            return ['definido' => false];
        }

        $dias = (int) now()->startOfDay()->diffInDays($ppa->due_date->startOfDay(), false);

        return [
            'definido' => true,
            'data'     => $ppa->due_date->format('d/m/Y'),
            'dias'     => $dias,
            // Plano concluído não fica gritando atraso: a equipe já o encerrou,
            // e um selo vermelho ali só criaria alarme sobre trabalho fechado.
            'encerrado' => $ppa->status === 'completed',
        ];
    }

    /**
     * Quando o quadro mexeu pela última vez, e de que LADO veio o movimento.
     *
     * A origem NÃO sai do `causer_id` do activity log. O Portal do Cliente
     * roda no grupo `web`, então uma sessão interna aberta em outra aba faz o
     * Spatie carimbar um usuário nosso numa ação do cliente — medido em
     * 21/08/2026, com `causer_id` preenchido em movimentações feitas pelo
     * portal. Quem responde com precisão é a propriedade `origem`, gravada
     * explicitamente pela rota que executou a ação.
     */
    private function ultimaAtualizacao(Ppa $ppa): array
    {
        $maisRecente = $ppa->tasks->max('updated_at') ?? $ppa->updated_at;

        if (! $maisRecente) {
            return ['houve' => false];
        }

        $log = DB::table('activity_log')
            ->where('log_name', 'ppa')
            ->where('subject_type', PpaTask::class)
            ->whereIn('subject_id', $ppa->tasks->pluck('id'))
            ->orderByDesc('id')
            ->first(['properties', 'created_at']);

        $origem = null;
        if ($log) {
            $props = json_decode($log->properties ?? '{}', true);
            $origem = $props['origem'] ?? null;
        }

        return [
            'houve'    => true,
            'quando'   => $maisRecente->diffForHumans(),
            'data'     => $maisRecente->format('d/m/Y H:i'),
            'hoje'     => $maisRecente->isToday(),
            'hora'     => $maisRecente->format('H:i'),
            // 'cliente' | 'interno' | null (movimentação anterior ao registro
            // de origem, ou feita por caminho que não a grava).
            'origem'   => $origem,
        ];
    }

    /**
     * Quem está no plano. O responsável interno é o `mentor` do PPA — o único
     * que sempre existiu neste módulo, e nenhuma regra nova de responsável foi
     * criada.
     *
     * O "Cliente" só entra na lista quando alguma tarefa foi de fato atribuída
     * a ele: um avatar de cliente num plano 100% interno diria que ele
     * participa de algo que ninguém pediu que ele fizesse.
     */
    private function responsaveis(Ppa $ppa): array
    {
        $lista = [];

        if ($ppa->mentor) {
            $lista[] = [
                'nome'  => $ppa->mentor->name,
                'papel' => 'ECF',
                'foto'  => $ppa->mentor->avatar_url,
                'lado'  => PpaTask::LADO_ECF,
            ];
        }

        if ($ppa->tasks->where('responsavel_lado', PpaTask::LADO_CLIENTE)->isNotEmpty()) {
            $lista[] = [
                'nome'  => $ppa->nomeEmpresa(),
                'papel' => 'Cliente',
                'foto'  => null,
                'lado'  => PpaTask::LADO_CLIENTE,
            ];
        }

        return $lista;
    }

    /**
     * O compartilhamento como ESTADO do plano, não como um botão solto.
     *
     * A régua é a do Portal do Cliente, importada de
     * {@see PortalPpaService::STATUS_VISIVEIS}: rascunho é interno, enviado e
     * concluído são compartilhados. Se aquela constante mudar, esta tela muda
     * junto — que é exatamente o que se quer, porque dizer "o cliente vê" sobre
     * um plano que o portal esconde é pior do que não dizer nada.
     *
     * `portal_url` é o Portal do Cliente por EMPRESA (o caminho novo, em que o
     * cliente vê todos os planos dele de uma vez). Só existe para PPA de
     * carteira com link de portal gerado — PPA de Polos amarra em `MlbEmpresa`
     * e nem toda uma tem `company_id`. Quando não existe, a tela cai no link
     * avulso por PPA (`workspace_url`), que sempre funcionou e não foi tocado.
     */
    private function visibilidade(Ppa $ppa): array
    {
        $compartilhado = in_array($ppa->status, PortalPpaService::STATUS_VISIVEIS, true);

        $portalUrl = null;
        if ($compartilhado && $ppa->company_id) {
            $link = OnboardingLink::where('company_id', $ppa->company_id)->first();
            $portalUrl = $link ? \App\Support\Portal\UrlDoPortal::para('portal.ppa', $link->token) : null;
        }

        return [
            'compartilhado' => $compartilhado,
            'rotulo'        => $compartilhado ? 'ECF + Cliente' : 'Somente interno',
            'detalhe'       => $compartilhado
                ? 'O cliente acompanha e pode atualizar as tarefas pelo Portal.'
                : 'Rascunho não aparece para o cliente. Mude o status para "Enviado" para compartilhar.',
            // Plano concluído continua visível, mas em leitura — a mesma regra
            // que `PortalPpaController` aplica do outro lado.
            'somente_leitura' => $ppa->status === 'completed',
            'portal_url'      => $portalUrl,
        ];
    }
}
