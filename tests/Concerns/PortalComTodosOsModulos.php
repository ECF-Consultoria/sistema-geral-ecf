<?php

namespace Tests\Concerns;

use App\Models\Configuracao;
use App\Support\Portal\VisibilidadeDosModulos;

/**
 * Liga o menu completo do Portal (Início, Onboarding, Calculadora de Custo, PPA e Mapeamento
 * Estrutural) para os testes que descrevem esse regime.
 *
 * Desde 13/10/2026 o menu mostra só o Mapeamento Estrutural, e o Início, escondido, leva a ele
 * ({@see VisibilidadeDosModulos}). Os testes de login, de sessão e do próprio Início não são sobre
 * o menu: eles precisam de uma página do portal que responda 200 e do menu inteiro para conferir
 * ordem e contadores. Em vez de reescrever cada um, a classe usa esta trait e roda como o portal
 * roda quando `portal_modulos_cliente` vale `todos`. O padrão novo tem teste próprio
 * (`VisibilidadeDosModulosTest`).
 *
 * O Laravel chama `setUp{NomeDaTrait}` sozinho, depois de recriar o banco.
 */
trait PortalComTodosOsModulos
{
    protected function setUpPortalComTodosOsModulos(): void
    {
        Configuracao::set(VisibilidadeDosModulos::CHAVE_PADRAO, VisibilidadeDosModulos::TODOS);
    }
}
