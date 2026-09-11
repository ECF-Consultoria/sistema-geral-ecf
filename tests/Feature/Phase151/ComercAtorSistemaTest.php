<?php

namespace Tests\Feature\Phase151;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Suite Feature — Fase 151 Plano 07 (COMERC-01/D-17).
 *
 * Prova que `hubspot:criar-usuario-sistema` cria a conta "Sistema HubSpot" —
 * o ator da transição de nascimento do webhook — de forma idempotente e
 * comprovadamente NÃO-LOGÁVEL, sem cargo nem permissão:
 *
 *  1. Dry-run não cria nenhum `User`.
 *  2. `--apply` cria exatamente um `User` com o e-mail canônico,
 *     `active = false`, `role = 'consultor'`, sem linha em `user_setores` e
 *     sem linha em `company_users`.
 *  3. Rodar `--apply` duas vezes não cria um segundo usuário (idempotência).
 *  4. Não-logabilidade: `Auth::attempt` falha para esse e-mail com 4 senhas
 *     óbvias.
 *  5. `Hash::check('password', $user->password)` é `false`.
 *  6. A conta não tem nenhuma permission efetiva — `isAdmin()` é `false` e a
 *     resolução de permissão por setor não devolve nenhuma chave.
 */
class ComercAtorSistemaTest extends TestCase
{
    use RefreshDatabase;

    private const EMAIL = 'sistema.hubspot@ecfconsultoria.com.br';

    public function test_dry_run_nao_cria_nenhum_usuario(): void
    {
        $this->artisan('hubspot:criar-usuario-sistema')->assertExitCode(0);

        $this->assertSame(0, User::where('email', self::EMAIL)->count());
    }

    public function test_apply_cria_exatamente_um_usuario_sem_cargo_e_sem_carteira(): void
    {
        $this->artisan('hubspot:criar-usuario-sistema', ['--apply' => true])->assertExitCode(0);

        $this->assertSame(1, User::where('email', self::EMAIL)->count());

        $user = User::where('email', self::EMAIL)->first();
        $this->assertNotNull($user);
        $this->assertSame('Sistema HubSpot', $user->name);
        $this->assertFalse((bool) $user->active);
        $this->assertSame('consultor', $user->role);

        $this->assertSame(0, DB::table('user_setores')->where('user_id', $user->id)->count());
        $this->assertSame(0, DB::table('company_users')->where('user_id', $user->id)->count());
    }

    public function test_rodar_apply_duas_vezes_nao_cria_segundo_usuario(): void
    {
        $this->artisan('hubspot:criar-usuario-sistema', ['--apply' => true])->assertExitCode(0);
        $this->artisan('hubspot:criar-usuario-sistema', ['--apply' => true])->assertExitCode(0);

        $this->assertSame(1, User::where('email', self::EMAIL)->count());
    }

    public function test_conta_nao_e_logavel_com_senhas_obvias(): void
    {
        $this->artisan('hubspot:criar-usuario-sistema', ['--apply' => true])->assertExitCode(0);

        $senhasObvias = ['password', '', 'Sistema HubSpot', self::EMAIL];

        foreach ($senhasObvias as $senha) {
            $conseguiu = Auth::attempt(['email' => self::EMAIL, 'password' => $senha]);
            $this->assertFalse($conseguiu, "Login não deveria funcionar com a senha '{$senha}'");
            Auth::logout();
        }

        $user = User::where('email', self::EMAIL)->first();
        $this->assertNotNull($user);
        $this->assertFalse(Hash::check('password', $user->password));
    }

    public function test_conta_nao_tem_nenhuma_permission_efetiva(): void
    {
        $this->artisan('hubspot:criar-usuario-sistema', ['--apply' => true])->assertExitCode(0);

        $user = User::where('email', self::EMAIL)->first();
        $this->assertNotNull($user);

        $this->assertFalse($user->isAdmin());
        $this->assertSame([], $user->effectivePermissions());
        $this->assertFalse($user->hasPermission('admin.contratos'));
        $this->assertFalse($user->hasPermission('comercial.entrada'));
    }
}
