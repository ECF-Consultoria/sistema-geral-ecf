<?php

namespace App\Services\Ppa;

use App\Models\Ppa;
use App\Services\Portal\PortalPpaService;
use App\Support\Portal\UrlDoPortal;

/**
 * PpaListaService — uma linha da lista INTERNA de PPA (carteira e Polos).
 *
 * ### Por que ele chama o serviço do Portal
 * A lista interna passou a desenhar o MESMO quadro que o cliente vê
 * (`resources/js/Components/Ppa/PlanoPpa.jsx`, compartilhado pelas duas telas).
 * Componente compartilhado exige payload com as mesmas chaves — e duas
 * montagens paralelas do mesmo payload divergiriam no primeiro ajuste, que é
 * exatamente o problema que a unificação veio resolver.
 *
 * Então a parte comum NÃO é remontada aqui: ela vem de
 * {@see PortalPpaService::visao()}, e este serviço só acrescenta em cima o que
 * é da equipe e que o cliente não recebe.
 *
 * Note a direção da dependência: o interno depende do payload do cliente, e
 * não o contrário. É de propósito — o payload do cliente é o mais restrito dos
 * dois, e por isso é ele quem define o mínimo comum. Se um dia o cliente
 * deixar de receber um campo, a tela interna deixa de mostrá-lo também, em vez
 * de continuar mostrando um dado que ninguém mais alimenta.
 *
 * ### O que só a equipe vê
 * Empresa, responsável, visibilidade no portal, as datas de criação e da última
 * mexida, e os links internos (Trello, quadro do cliente).
 */
class PpaListaService
{
    public function __construct(private PortalPpaService $doCliente) {}

    /**
     * Uma linha da lista interna.
     *
     * Espera `tasks` carregado e, para `atualizado_em`, o scope
     * {@see Ppa::scopeComUltimaAtividade()} aplicado na consulta — sem ele a
     * data cai no `updated_at` do plano (ver `Ppa::atualizadoEm()`).
     *
     * @return array<string, mixed>
     */
    public function linha(Ppa $ppa): array
    {
        $visao = $this->doCliente->visao($ppa);

        return [
            ...$visao,

            'tarefas' => $this->tarefasDaEquipe($ppa, $visao['tarefas']),

            // ─── Só a equipe ────────────────────────────────────────────────
            'empresa'     => $ppa->nomeEmpresa(),
            // O alvo muda por escopo: Company na carteira, MlbEmpresa em Polos.
            'empresa_id'  => $ppa->company_id ?? $ppa->mlb_empresa_id,
            'responsavel' => $ppa->mentor?->name,

            'criado_em'     => $ppa->created_at?->format('d/m/Y'),
            'atualizado_em' => $ppa->atualizadoEm()?->format('d/m/Y'),

            'trello_board_url' => $ppa->trello_board_url,
            'workspace_token'  => $ppa->workspace_token,

            'compartilhar' => $this->compartilhamento($ppa),
        ];
    }

    /**
     * As tarefas do cliente, acrescidas do que o diálogo de edição precisa.
     *
     * Clicar no card abre `DialogTarefa` (23/09/2026). Sem estes campos ele
     * abriria com área, prioridade e prazo em BRANCO, e salvar apagaria o que
     * estava gravado — o mesmo defeito que a descrição do plano já teve em
     * `openEdit()`. Ficam só na lista interna: área e prioridade são
     * refinamento da equipe, e o cliente nunca os recebeu.
     *
     * A ordem é a do cliente (`visao()` ordena por `order`); aqui só se
     * acrescenta chave, nunca se reordena.
     *
     * @param  array<int, array<string, mixed>>  $tarefas
     * @return array<int, array<string, mixed>>
     */
    private function tarefasDaEquipe(Ppa $ppa, array $tarefas): array
    {
        $porId = $ppa->tasks->keyBy('id');

        return array_map(function (array $t) use ($porId) {
            $task = $porId->get($t['id']);

            return [
                ...$t,
                'area'         => $task?->area,
                'prioridade'   => $task?->prioridade,
                // O input date exige ISO; o card mostra dd/mm/aaaa.
                'prazo_iso'    => $task?->prazo?->format('Y-m-d'),
                'concluida_em' => $task?->concluida_em?->format('d/m/Y'),
            ];
        }, $tarefas);
    }

    /**
     * O link que a equipe copia para mandar ESTE plano ao cliente.
     *
     * Dois caminhos, e a escolha não é de tela — é a mesma régua que
     * `PpaController::workspace()` já aplica:
     *
     *  - **PPA de empresa (`company_id`)** → Portal do Cliente, aberto NESTE
     *    plano (`/portal/ppa?plano=ID`). Exige login: o link por token de
     *    Company foi aposentado em 15/09/2026, porque a posse do link dava
     *    leitura e escrita permanentes. Se o cliente não tiver sessão, entra e
     *    volta para cá (`PortalAuthController::destinoAposEntrar()`).
     *  - **PPA de Polos** → o link do quadro por token (`ppa.workspace`), que
     *    abre sem login. `url` nulo quando o token ainda não existe: ele nasce
     *    no clique, pela rota `workspace.generate` — gerar numa listagem (GET)
     *    gravaria token em todo plano só por alguém abrir a página.
     *
     * `disponivel` segue {@see PortalPpaService::STATUS_VISIVEIS}: rascunho não
     * tem link, porque o portal o esconde e mandar um link para "nada" é pior
     * do que dizer por que não há link.
     *
     * @return array{disponivel: bool, via: string, url: ?string}
     */
    public function compartilhamento(Ppa $ppa): array
    {
        $disponivel = in_array($ppa->status, PortalPpaService::STATUS_VISIVEIS, true);

        if ($ppa->company_id) {
            return [
                'disponivel' => $disponivel,
                'via'        => 'portal',
                'url'        => $disponivel ? UrlDoPortal::para('portal.auth.ppa', ['plano' => $ppa->id]) : null,
            ];
        }

        return [
            'disponivel' => $disponivel,
            'via'        => 'link',
            'url'        => $disponivel && $ppa->workspace_token ? route('ppa.workspace', $ppa->workspace_token) : null,
        ];
    }
}
