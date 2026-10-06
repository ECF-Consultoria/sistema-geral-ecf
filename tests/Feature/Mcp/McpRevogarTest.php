<?php

namespace Tests\Feature\Mcp;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/** `mcp:revogar` corta o acesso do usuário e só dele. */
class McpRevogarTest extends TestCase
{
    use RefreshDatabase;

    private function token(User $u): string
    {
        $id = Str::random(80);
        DB::table('oauth_access_tokens')->insert([
            'id' => $id, 'user_id' => $u->id, 'client_id' => (string) Str::uuid(),
            'name' => null, 'scopes' => '["mcp:use"]', 'revoked' => false,
            'created_at' => now(), 'updated_at' => now(), 'expires_at' => now()->addDay(),
        ]);
        DB::table('oauth_refresh_tokens')->insert([
            'id' => Str::random(80), 'access_token_id' => $id, 'revoked' => false, 'expires_at' => now()->addDays(30),
        ]);

        return $id;
    }

    public function test_revoga_acesso_e_refresh_do_usuario_pelo_email(): void
    {
        $alvo  = User::factory()->create(['email' => 'alvo@ecfconsultoria.com.br']);
        $outro = User::factory()->create();
        $this->token($alvo);
        $this->token($alvo);
        $doOutro = $this->token($outro);

        $this->artisan('mcp:revogar', ['usuario' => 'alvo@ecfconsultoria.com.br'])
            ->expectsOutputToContain('2 token(s) revogado(s)')
            ->assertSuccessful();

        $this->assertSame(0, DB::table('oauth_access_tokens')->where('user_id', $alvo->id)->where('revoked', false)->count());
        $this->assertSame(0, DB::table('oauth_refresh_tokens')->where('revoked', false)->whereIn('access_token_id', DB::table('oauth_access_tokens')->where('user_id', $alvo->id)->pluck('id'))->count());
        $this->assertFalse((bool) DB::table('oauth_access_tokens')->where('id', $doOutro)->value('revoked'));
    }

    public function test_usuario_inexistente_falha(): void
    {
        $this->artisan('mcp:revogar', ['usuario' => 'ninguem@x.com'])->assertFailed();
    }
}
