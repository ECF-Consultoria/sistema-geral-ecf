<?php

namespace App\Support\Portal;

use App\Models\Company;

/**
 * ModulosPortal — catálogo dos módulos do Portal do Cliente.
 *
 * O Portal deixou de ser "a tela de onboarding do cliente" e virou o ambiente
 * da empresa, com Onboarding como UM dos módulos. Este arquivo é o único lugar
 * que sabe quais módulos existem: o menu lateral, o hub do Início e o
 * destaque do item ativo saem todos de {@see self::paraEmpresa()}.
 *
 * ### Como adicionar um módulo novo
 * 1. Uma constante e uma entrada em {@see self::DEFINICOES}.
 * 2. Um controller que resolva o token pelo {@see \App\Services\Portal\PortalClienteService}
 *    e renderize a página dentro de `PortalClienteLayout`.
 * 3. A rota dentro do grupo `portal-cliente/{token}` em `routes/web.php`.
 *
 * Nada mais precisa mudar — nem o layout, nem o menu, nem o Início.
 *
 * ### Por que módulo sem conteúdo continua no menu
 * Cada módulo declara `disponivel`, mas os três atuais devolvem `true` sempre e
 * resolvem a ausência de dado com estado vazio DENTRO da própria página. Some
 * do menu é o pior comportamento possível aqui: o cliente que ouviu "seu PPA
 * está no portal" abriria o portal, não veria a aba e concluiria que o sistema
 * está quebrado — sem nenhuma mensagem que explicasse. O gancho `disponivel`
 * existe para o dia em que um módulo for de fato contratual (visível só para
 * quem contratou aquele serviço), e aí a ausência será a resposta certa.
 *
 * ### `icone` é string, não componente
 * O nome viaja como string e o JSX o resolve num mapa explícito
 * (`PortalClienteLayout`). Ícone é decisão de apresentação; mandar o
 * componente pelo props do Inertia não é possível, e mandar o nome cru sem
 * mapa deixaria o menu quebrar em silêncio quando alguém digitasse errado.
 */
class ModulosPortal
{
    public const INICIO     = 'inicio';
    public const ONBOARDING = 'onboarding';
    public const PPA        = 'ppa';

    /**
     * Os módulos na ordem em que aparecem no menu. `rota` é o nome da rota
     * Laravel, que recebe o token como único parâmetro.
     */
    private const DEFINICOES = [
        self::INICIO => [
            'rotulo'    => 'Início',
            'descricao' => 'Uma visão geral do que está acontecendo com a sua operação.',
            'icone'     => 'home',
            'rota'      => 'portal.inicio',
            'rota_auth' => 'portal.auth.inicio',
        ],
        self::ONBOARDING => [
            'rotulo'    => 'Onboarding',
            'descricao' => 'As etapas para deixar a sua operação pronta para começar.',
            'icone'     => 'list-checks',
            'rota'      => 'portal.onboarding',
            'rota_auth' => 'portal.auth.onboarding',
        ],
        self::PPA => [
            'rotulo'    => 'PPA',
            'descricao' => 'O Plano Prático de Ação que construímos com você.',
            'icone'     => 'clipboard-list',
            'rota'      => 'portal.ppa',
            'rota_auth' => 'portal.auth.ppa',
        ],
    ];

    /**
     * Os módulos prontos para o front: rótulo, ícone, URL já resolvida com o
     * token, qual está ativo e o badge de cada um.
     *
     * `$token` nulo significa PORTAL AUTENTICADO: as URLs saem das rotas sem
     * token (`/inicio`, `/onboarding`, `/ppa`), que é o caminho novo. Com
     * token, saem das rotas legadas — os dois convivem enquanto os clientes
     * existentes migram.
     *
     * @param  string  $ativo   chave do módulo da página atual
     * @param  array<string, ?int>  $badges  contagem por chave; `null` ou 0 não desenha badge
     * @return array<int, array{chave: string, rotulo: string, descricao: string, icone: string, url: string, ativo: bool, badge: ?int}>
     */
    public static function paraEmpresa(Company $company, ?string $token, string $ativo, array $badges = []): array
    {
        $modulos = [];

        foreach (self::DEFINICOES as $chave => $def) {
            if (! self::disponivel($chave, $company)) {
                continue;
            }

            $badge = $badges[$chave] ?? null;

            $modulos[] = [
                'chave'     => $chave,
                'rotulo'    => $def['rotulo'],
                'descricao' => $def['descricao'],
                'icone'     => $def['icone'],
                'url'       => $token === null
                    ? route($def['rota_auth'])
                    : route($def['rota'], $token),
                'ativo'     => $chave === $ativo,
                'badge'     => $badge > 0 ? (int) $badge : null,
            ];
        }

        return $modulos;
    }

    /**
     * Se o módulo aparece para ESTA empresa. Ver o docblock da classe: hoje os
     * três são incondicionais de propósito, e a decisão está aqui — e não
     * espalhada em `if`s pelo menu — para que restringir um módulo amanhã seja
     * uma linha neste `match`.
     */
    private static function disponivel(string $chave, Company $company): bool
    {
        return match ($chave) {
            self::INICIO, self::ONBOARDING, self::PPA => true,
            default => false,
        };
    }
}
