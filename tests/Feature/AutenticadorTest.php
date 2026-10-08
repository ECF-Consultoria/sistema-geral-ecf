<?php

namespace Tests\Feature;

use App\Models\Autenticador;
use App\Models\User;
use App\Services\Autenticadores\OtpauthMigrationParser;
use App\Services\Autenticadores\TotpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AutenticadorTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ'; // vetor público da RFC 6238

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        // Chave dedicada de teste (não é segredo de ninguém).
        config(['autenticadores.enc_key' => 'base64:' . base64_encode(str_repeat('k', 32))]);
        $this->user = User::factory()->create(['email_verified_at' => now()]);
    }

    public function test_lista_renderiza_sem_vazar_o_secret(): void
    {
        $this->criar(['cliente' => 'Loja Prime', 'conta' => 'financeiro@lojaprime.com', 'servico' => 'Google']);

        $resp = $this->actingAs($this->user)->get(route('autenticadores.index'));

        $resp->assertOk();
        $resp->assertInertia(fn (Assert $page) => $page
            ->component('Autenticadores/Index')
            ->has('autenticadores', 1)
            ->where('autenticadores.0.cliente', 'Loja Prime')
            ->missing('autenticadores.0.secret'));
        // O secret em claro não aparece em lugar nenhum do payload.
        $resp->assertDontSee(self::SECRET, false);
    }

    public function test_cadastra_por_secret_e_grava_cifrado(): void
    {
        $this->actingAs($this->user)->post(route('autenticadores.store'), [
            'cliente' => 'Casa Nobre',
            'conta'   => 'contato@casanobre.com.br',
            'servico' => 'Amazon',
            'secret'  => 'gezd gnbv gy3t qojq gezd gnbv gy3t qojq', // mesmo secret, formato Google
        ])->assertSessionHas('success');

        $a = Autenticador::first();
        $this->assertSame('Casa Nobre', $a->cliente);
        $this->assertSame(self::SECRET, $a->secret); // cast decifra

        // No banco, a coluna NÃO contém o secret em claro.
        $cru = DB::table('autenticadores')->where('id', $a->id)->value('secret');
        $this->assertNotSame(self::SECRET, $cru);
        $this->assertStringNotContainsString(self::SECRET, $cru);
        // E o hash de dedup bate.
        $this->assertSame(hash('sha256', self::SECRET), DB::table('autenticadores')->where('id', $a->id)->value('secret_hash'));
    }

    public function test_importa_varias_contas_por_uri_de_migracao(): void
    {
        $uri = $this->montarMigracao([
            ['key' => random_bytes(20), 'name' => 'a@ex.com', 'issuer' => 'Amazon'],
            ['key' => random_bytes(20), 'name' => 'b@ex.com', 'issuer' => 'AWS'],
            ['key' => random_bytes(20), 'name' => 'c@ex.com', 'issuer' => 'GitHub'],
        ]);

        $this->actingAs($this->user)->post(route('autenticadores.store'), ['uri' => $uri])
            ->assertSessionHas('success');

        $this->assertSame(3, Autenticador::count());
        $this->assertEqualsCanonicalizing(['Amazon', 'AWS', 'GitHub'], Autenticador::pluck('servico')->all());
    }

    public function test_reimportar_nao_duplica(): void
    {
        $uri = $this->montarMigracao([['key' => random_bytes(20), 'name' => 'x@ex.com', 'issuer' => 'Nova']]);

        $this->actingAs($this->user)->post(route('autenticadores.store'), ['uri' => $uri])->assertSessionHas('success');
        $this->actingAs($this->user)->post(route('autenticadores.store'), ['uri' => $uri])->assertSessionHas('error');

        $this->assertSame(1, Autenticador::count());
    }

    public function test_endpoint_de_codigo_devolve_totp_e_audita_sem_vazar_secret(): void
    {
        $a = $this->criar(['secret' => self::SECRET]);

        $resp = $this->actingAs($this->user)->getJson(route('autenticadores.codigo', $a));
        $resp->assertOk()
            ->assertJsonStructure(['code', 'digits', 'period', 'step', 'remaining_ms', 'server_time'])
            ->assertJsonMissing(['secret' => self::SECRET]);

        // O código bate com o TotpService na mesma janela.
        $totp = new TotpService(new OtpauthMigrationParser());
        $step = $resp->json('step');
        $esperado = $totp->gerarCodigo(self::SECRET, 'SHA1', 6, 30, $step * 30)['code'];
        $this->assertSame($esperado, $resp->json('code'));
        $resp->assertDontSee(self::SECRET, false);

        // A visualização foi auditada.
        $this->assertDatabaseHas('activity_log', [
            'log_name'     => 'autenticadores',
            'description'  => 'Visualizou o código',
            'causer_id'    => $this->user->id,
            'subject_id'   => $a->id,
        ]);
    }

    public function test_copiar_audita(): void
    {
        $a = $this->criar();
        $this->actingAs($this->user)->postJson(route('autenticadores.copiar', $a))->assertOk();
        $this->assertDatabaseHas('activity_log', ['log_name' => 'autenticadores', 'description' => 'Copiou o código']);
    }

    public function test_edita_rotulos_sem_tocar_no_secret_e_audita(): void
    {
        $a = $this->criar(['cliente' => 'Mercado Livre', 'conta' => 'apelido_ml', 'servico' => 'Mercado Livre']);
        $hashAntes = DB::table('autenticadores')->where('id', $a->id)->value('secret_hash');
        $cruAntes  = DB::table('autenticadores')->where('id', $a->id)->value('secret');

        $this->actingAs($this->user)->patch(route('autenticadores.update', $a), [
            'cliente' => 'Loja Prime',
            'conta'   => 'financeiro@lojaprime.com',
            'servico' => 'Mercado Livre',
        ])->assertSessionHas('success');

        $a->refresh();
        $this->assertSame('Loja Prime', $a->cliente);
        $this->assertSame('financeiro@lojaprime.com', $a->conta);
        // Secret e hash intactos: a edição é só de rótulos.
        $this->assertSame(self::SECRET, $a->secret);
        $this->assertSame($hashAntes, DB::table('autenticadores')->where('id', $a->id)->value('secret_hash'));
        $this->assertSame($cruAntes, DB::table('autenticadores')->where('id', $a->id)->value('secret'));

        // A edição entra no log 'autenticadores' (aparece no "Histórico" da tela).
        $this->assertDatabaseHas('activity_log', [
            'log_name'    => 'autenticadores',
            'description' => 'Editou o cadastro',
            'causer_id'   => $this->user->id,
            'subject_id'  => $a->id,
        ]);
    }

    public function test_edicao_exige_cliente(): void
    {
        $a = $this->criar(['cliente' => 'Loja Prime']);

        $this->actingAs($this->user)
            ->patch(route('autenticadores.update', $a), ['cliente' => '', 'servico' => 'Google'])
            ->assertSessionHasErrors('cliente');

        $this->assertSame('Loja Prime', $a->fresh()->cliente);
    }

    public function test_remove(): void
    {
        $a = $this->criar();
        $this->actingAs($this->user)->delete(route('autenticadores.destroy', $a))->assertSessionHas('success');
        $this->assertDatabaseCount('autenticadores', 0);
    }

    public function test_exige_login(): void
    {
        $this->get(route('autenticadores.index'))->assertRedirect(route('login'));
    }

    // ─── Helpers ───

    private function criar(array $attrs = []): Autenticador
    {
        $secret = $attrs['secret'] ?? self::SECRET;

        return Autenticador::create(array_merge([
            'cliente'     => 'Cliente Teste',
            'conta'       => 'conta@teste.com',
            'servico'     => 'Google',
            'secret'      => $secret,
            'secret_hash' => hash('sha256', $secret),
            'algoritmo'   => 'SHA1',
            'digitos'     => 6,
            'periodo'     => 30,
            'status'      => 'ativo',
            'criado_por'  => $this->user->id,
        ], array_diff_key($attrs, ['secret' => null])));
    }

    private function montarMigracao(array $contas): string
    {
        $f = fn (int $field, string $buf) => chr(($field << 3) | 2) . chr(strlen($buf)) . $buf;
        $v = fn (int $field, int $val) => chr($field << 3) . chr($val);

        $otps = '';
        foreach ($contas as $c) {
            $otps .= $f(1, $f(1, $c['key']) . $f(2, $c['name']) . $f(3, $c['issuer']) . $v(4, 1) . $v(5, 1) . $v(6, 2));
        }
        $payload = $otps . $v(2, 2) . $v(3, 1) . $v(4, 0);

        return 'otpauth-migration://offline?data=' . rawurlencode(base64_encode($payload));
    }
}
