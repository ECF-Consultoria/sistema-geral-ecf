<?php

namespace Tests\Concerns;

use App\Models\Company;
use App\Models\PortalUsuario;

/**
 * Entra no Portal do Cliente pela porta AUTENTICADA — desde 15/09/2026 a única.
 * O link por token só leva ao login (`AposentaTokenDoPortal`).
 *
 * ### Por que não o fluxo do código
 * O código por e-mail tem teste próprio (`LoginDoPortalTest`). Repeti-lo em todo
 * teste de módulo deixaria cada regra de negócio refém do OTP, e uma mudança no
 * login quebraria dezenas de testes que não são sobre login.
 *
 * ### Por que não `actingAs()`
 * `actingAs($usuario, 'portal')` também troca o guard PADRÃO da aplicação. Aí
 * `$request->user()` passa a devolver o `PortalUsuario`, e o
 * `HandleInertiaRequests` — que espera um `User` interno — chama
 * `hasPermission()` nele: 500 em toda página. É um cenário que não existe em
 * produção, onde o login do cliente grava só no guard `portal` e o padrão segue
 * sendo o `web`. Por isso a fixture grava a SESSÃO do guard `portal`, que é
 * exatamente o que `PortalAuthController` deixa depois do código certo.
 *
 * ### Por que a fixture cria as DUAS linhas
 * `EnsurePortalAutenticado` relê o usuário do banco e confere o vínculo com a
 * empresa em `portal_usuario_empresa` a cada request. Uma sessão apontando para
 * a empresa, sem o vínculo de verdade, é recusada — e é justamente essa recusa
 * que isola um cliente do outro. A fixture precisa passar pela mesma régua.
 */
trait EntraNoPortal
{
    /** Uma pessoa ativa com acesso à empresa, marcada como principal. */
    protected function clienteDoPortal(Company $empresa, array $atributos = []): PortalUsuario
    {
        $usuario = PortalUsuario::create(array_merge([
            'nome'  => 'Cliente '.$empresa->id,
            'email' => 'cliente.'.$empresa->id.'.'.uniqid().'@example.test',
            'ativo' => true,
        ], $atributos));

        $usuario->empresas()->attach($empresa->id, ['principal' => true]);

        return $usuario;
    }

    /**
     * Sessão do portal aberta na empresa. Encadeável:
     * `$this->entrarNoPortal($empresa)->get(route('portal.auth.inicio'))`.
     *
     * Passar `$usuario` de outra empresa é legítimo: é assim que se testa a
     * sessão apontando para uma empresa sem vínculo.
     */
    protected function entrarNoPortal(Company $empresa, ?PortalUsuario $usuario = null): static
    {
        $usuario ??= $this->clienteDoPortal($empresa);

        return $this->withSession([
            $this->app['auth']->guard('portal')->getName() => $usuario->getAuthIdentifier(),
            'portal_empresa_id'                             => $empresa->id,
        ]);
    }
}
