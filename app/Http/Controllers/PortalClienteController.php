<?php

namespace App\Http\Controllers;

use App\Services\Onboarding\OnboardingLinkService;
use App\Services\Portal\PortalClienteService;
use App\Services\Portal\PortalPpaService;
use App\Support\Portal\ModulosPortal;
use App\Support\Portal\PortalContexto;
use Inertia\Inertia;

/**
 * PortalClienteController — o Início do Portal do Cliente.
 *
 * O Início é o hub: a porta de entrada de `/portal-cliente/{token}`, com um
 * cartão por módulo dizendo em uma linha o que está esperando o cliente ali
 * dentro.
 *
 * ### O que ele deliberadamente NÃO faz ainda
 * Nada de métrica de operação — faturamento, ACOS, ranking, evolução. O
 * negócio ainda vai definir o que o cliente deve ver, e um dashboard inventado
 * agora seria pior do que nenhum: número no portal do cliente vira conversa
 * com o cliente, e cada gráfico publicado aqui é um compromisso de que aquele
 * número está certo, atualizado e é explicável. O hub entrega hoje o que já é
 * verdadeiro e verificável — onde ele está e o que falta —, e o espaço para os
 * indicadores fica reservado na própria tela.
 *
 * ### Por que o resumo é "pendências", e não uma barra de progresso
 * A barra de progresso do Onboarding tem uma régua não trivial (passos +
 * mapeamentos visíveis + reuniões) que vive em `Onboarding/Publico.jsx`.
 * Recalculá-la aqui em PHP criaria uma SEGUNDA régua para o mesmo número, e as
 * duas divergiriam na primeira vez que alguém mudasse uma sem lembrar da
 * outra — o cliente veria 60% no Início e 75% no Onboarding. O hub usa a
 * contagem de pendências acionáveis, que já é a régua única do badge do menu.
 */
class PortalClienteController extends Controller
{
    public function __construct(
        private PortalClienteService $portal,
        private OnboardingLinkService $linkService,
        private PortalPpaService $ppaService,
    ) {
    }

    /** GET /portal-cliente/{token} */
    public function inicio(string $token)
    {
        $link    = $this->portal->resolver($token);
        $company = $link->company;

        $ppas = $this->ppaService->ppasDaEmpresa($company);

        return Inertia::render('Portal/Inicio', [
            ...$this->portal->contexto($link, ModulosPortal::INICIO),
            'resumo' => [
                'ppa' => [
                    // "Ativo" aqui é o plano que ainda não foi encerrado pela
                    // equipe — é o que o cliente pode tocar.
                    'planos_ativos' => $ppas->where('status', '!=', 'completed')->count(),
                    'planos_total'  => $ppas->count(),
                ],
            ],
            // Quem atende o cliente, com rosto. Mesma fonte do módulo de
            // Onboarding (ver a emenda ao T-135-11-02 documentada em
            // `OnboardingPublicoController::workspace()`): só nome, foto e
            // papel — nenhum dado de operação interna.
            'responsaveis' => $this->linkService->responsaveisDaEmpresa($company),
        ]);
    }

    /**
     * GET /inicio — o mesmo hub, no portal AUTENTICADO.
     *
     * A empresa vem do usuário logado (`PortalContexto`), nunca da URL. Fora
     * isso, o payload é idêntico ao do modo por token: a tela é a mesma.
     */
    public function inicioAutenticado()
    {
        $empresa = PortalContexto::empresa();
        $ator = PortalContexto::ator();

        $ppas = $this->ppaService->ppasDaEmpresa($empresa);

        return Inertia::render('Portal/Inicio', [
            ...$this->portal->contextoAutenticado($empresa, ModulosPortal::INICIO, $ator),
            'resumo' => [
                'ppa' => [
                    'planos_ativos' => $ppas->where('status', '!=', 'completed')->count(),
                    'planos_total'  => $ppas->count(),
                ],
            ],
            'responsaveis' => $this->linkService->responsaveisDaEmpresa($empresa),
        ]);
    }
}
