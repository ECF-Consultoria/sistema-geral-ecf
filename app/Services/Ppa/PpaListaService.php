<?php

namespace App\Services\Ppa;

use App\Models\Ppa;
use App\Services\Portal\PortalPpaService;

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
        return [
            ...$this->doCliente->visao($ppa),

            // ─── Só a equipe ────────────────────────────────────────────────
            'empresa'     => $ppa->nomeEmpresa(),
            // O alvo muda por escopo: Company na carteira, MlbEmpresa em Polos.
            'empresa_id'  => $ppa->company_id ?? $ppa->mlb_empresa_id,
            'responsavel' => $ppa->mentor?->name,

            'criado_em'     => $ppa->created_at?->format('d/m/Y'),
            'atualizado_em' => $ppa->atualizadoEm()?->format('d/m/Y'),

            'trello_board_url' => $ppa->trello_board_url,
            'workspace_token'  => $ppa->workspace_token,
        ];
    }
}
