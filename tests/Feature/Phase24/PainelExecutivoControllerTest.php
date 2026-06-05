<?php

// Phase 24 — testes Feature do PainelExecutivoController.
// Cobre: 200 admin, 302 guest, 403 consultor/mentor/publicador,
// props estruturadas, fallback em erro com mensagem pt-BR exata,
// e verificação de que as 5 chamadas ao ECF Drive são disparadas.

namespace Tests\Feature\Phase24;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PainelExecutivoControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Garante que EcfDriveService está configurado corretamente em testes
        config([
            'services.ecf.base' => 'https://files.ecfconsultoria.com.br/api/v1',
            'services.ecf.key'  => 'test-key',
        ]);
        // Limpa cache para reprodutibilidade (wrapper usa Cache::remember)
        Cache::flush();
    }

    // ─── Helpers ────────────────────────────────────────────────────────────

    /**
     * Cria um usuário com role definida e email verificado.
     */
    private function usuarioComRole(string $role): User
    {
        return User::factory()->create([
            'role'              => $role,
            'email_verified_at' => now(),
        ]);
    }

    /**
     * Configura Http::fake com respostas válidas para os 5 endpoints do ECF Drive.
     * Payload mínimo realista baseado em interfaces do PLAN (§<interfaces>).
     */
    private function mockEcfRespostas(): void
    {
        Http::fake([
            // /carteira/resumo — KPIs do mês atual + MoM
            '*/carteira/resumo*' => Http::response([
                'mesAtual'         => '202605',
                'mesAnterior'      => '202604',
                'gmv'              => ['atual' => 42859191.37, 'anterior' => 48562310.41, 'delta' => -5703119.04, 'deltaPct' => -11.74],
                'vendas'           => ['atual' => 357531, 'anterior' => 351109, 'delta' => 6422, 'deltaPct' => 1.83],
                'sellersAtivos'    => ['atual' => 1238, 'anterior' => 1070, 'delta' => 168, 'deltaPct' => 15.70],
                'investimentoAds'  => ['atual' => 1540459.82, 'anterior' => 1849358.32, 'delta' => -308898.50, 'deltaPct' => -16.70],
                'gmvAds'           => ['atual' => 15341245.54, 'anterior' => 16509889.49, 'delta' => -1168643.95, 'deltaPct' => -7.08],
                'gmvFull'          => ['atual' => 15166178.20, 'anterior' => 18105279.77, 'deltaPct' => -16.23],
                'gmvFlex'          => ['atual' => 762818.88, 'anterior' => 686938.91, 'deltaPct' => 11.05],
                'visitas'          => ['atual' => 12691798, 'anterior' => 13490825, 'deltaPct' => -5.92],
            ], 200),

            // /carteira/historico?periodicidade=mensal — série 12 meses
            '*/carteira/historico*' => Http::response([
                'data' => [
                    ['timMonthId' => '202506', 'gmv' => 41000000.0, 'sellersAtivos' => 1050, 'vendas' => 320000, 'investimentoAds' => 1600000.0],
                    ['timMonthId' => '202507', 'gmv' => 43500000.0, 'sellersAtivos' => 1080, 'vendas' => 340000, 'investimentoAds' => 1550000.0],
                    ['timMonthId' => '202605', 'gmv' => 42859191.37, 'sellersAtivos' => 1238, 'vendas' => 357531, 'investimentoAds' => 1540459.82],
                ],
            ], 200),

            // /carteira/breakdown?dimensao=* — distribuição por dimensão
            // Matcher wildcard cobre programa, frete, cluster e localidade
            '*/carteira/breakdown*' => Http::response([
                'dimensao'    => 'programa',
                'total'       => 1238,
                'distribuicao' => [
                    ['programa' => 'POLOS', 'count' => 697, 'pct' => 56.30, 'gmv' => 4782948.18],
                    ['programa' => 'CPP',   'count' => 541, 'pct' => 43.70, 'gmv' => 38076243.19],
                ],
            ], 200),
        ]);
    }

    // ─── Testes de autorização ───────────────────────────────────────────────

    /**
     * Admin autenticado recebe 200 com props estruturadas corretas.
     * Verifica componente Inertia, chaves de props e erro = null.
     */
    public function test_index_admin_retorna_200_com_props_estruturadas(): void
    {
        $this->mockEcfRespostas();

        $this->actingAs($this->usuarioComRole('admin'))
            ->get(route('painel-executivo.index'))
            ->assertStatus(200)
            ->assertInertia(fn (Assert $p) => $p
                ->component('PainelExecutivo/Index')
                ->has('resumo')
                ->has('historico')
                ->has('breakdowns.programa')
                ->has('breakdowns.frete')
                ->has('breakdowns.cluster')
                ->has('breakdowns.localidade')
                ->where('erro', null)
            );
    }

    /**
     * Guest (não autenticado) é redirecionado para /login com 302.
     */
    public function test_index_guest_redireciona_para_login_302(): void
    {
        $this->get(route('painel-executivo.index'))
            ->assertStatus(302)
            ->assertRedirect(route('login'));
    }

    /**
     * Consultor autenticado recebe 403 — apenas admin tem acesso (D-02 do CONTEXT).
     */
    public function test_index_consultor_retorna_403(): void
    {
        $this->actingAs($this->usuarioComRole('consultor'))
            ->get(route('painel-executivo.index'))
            ->assertStatus(403);
    }

    /**
     * Mentor autenticado recebe 403.
     */
    public function test_index_mentor_retorna_403(): void
    {
        $this->actingAs($this->usuarioComRole('mentor'))
            ->get(route('painel-executivo.index'))
            ->assertStatus(403);
    }

    /**
     * Publicador (role fora do enum DB padrão) recebe 403.
     * Requer bypass de CHECK constraint do SQLite para inserir role incomum.
     */
    public function test_index_publicador_retorna_403(): void
    {
        // Desabilita CHECK constraints do SQLite para inserir role fora do enum
        DB::statement('PRAGMA ignore_check_constraints = 1');

        DB::table('users')->insert([
            'name'              => 'Publicador Teste',
            'email'             => 'publicador-phase24@example.com',
            'email_verified_at' => now()->toDateTimeString(),
            'password'          => Hash::make('password'),
            'role'              => 'publicador',
            'remember_token'    => null,
            'created_at'        => now()->toDateTimeString(),
            'updated_at'        => now()->toDateTimeString(),
        ]);

        DB::statement('PRAGMA ignore_check_constraints = 0');

        $publicador = User::where('email', 'publicador-phase24@example.com')->firstOrFail();

        $this->actingAs($publicador)
            ->get(route('painel-executivo.index'))
            ->assertStatus(403);
    }

    // ─── Teste de props estruturadas ─────────────────────────────────────────

    /**
     * Verifica que as props Inertia têm a estrutura correta no caminho feliz.
     * resumo não é null, historico tem entradas, breakdowns tem 4 chaves, erro = null.
     */
    public function test_index_props_estruturadas_caminho_feliz(): void
    {
        $this->mockEcfRespostas();

        $this->actingAs($this->usuarioComRole('admin'))
            ->get(route('painel-executivo.index'))
            ->assertInertia(fn (Assert $p) => $p
                ->component('PainelExecutivo/Index')
                ->has('resumo.gmv')
                ->has('resumo.vendas')
                ->has('resumo.sellersAtivos')
                ->has('historico', 3) // mockEcfRespostas retorna 3 entradas
                ->has('breakdowns.programa.distribuicao')
                ->has('breakdowns.frete.distribuicao')
                ->has('breakdowns.cluster.distribuicao')
                ->has('breakdowns.localidade.distribuicao')
                ->where('erro', null)
            );
    }

    // ─── Teste de fallback em erro ────────────────────────────────────────────

    /**
     * Quando ECF Drive retorna 500 na primeira chamada (carteiraResumo),
     * o controller deve:
     * - Retornar HTTP 200 (NUNCA quebrar o pageload — D-03 do PLAN)
     * - Props: resumo = null, historico = [], breakdowns com 4 chaves vazias
     * - Prop 'erro' = mensagem pt-BR exata definida no controller
     */
    public function test_index_fallback_com_estrutura_vazia_quando_ecf_lanca(): void
    {
        Http::fake([
            // Primeira chamada (resumo) falha — deve disparar o catch global
            '*/carteira/resumo*'    => Http::response('boom', 500),
            // Demais chamadas não chegam a ser disparadas, mas configuradas como fallback
            '*/carteira/historico*' => Http::response(['data' => []], 200),
            '*/carteira/breakdown*' => Http::response(['distribuicao' => []], 200),
        ]);

        $this->actingAs($this->usuarioComRole('admin'))
            ->get(route('painel-executivo.index'))
            ->assertStatus(200) // pageload não quebra
            ->assertInertia(fn (Assert $p) => $p
                ->component('PainelExecutivo/Index')
                ->where('resumo', null)
                ->where('historico', [])
                ->has('breakdowns.programa.distribuicao')
                ->has('breakdowns.frete.distribuicao')
                ->has('breakdowns.cluster.distribuicao')
                ->has('breakdowns.localidade.distribuicao')
                ->where('erro', 'Não foi possível buscar dados do painel agora. Tente em alguns segundos.')
            );
    }

    // ─── Teste de chamadas ao ECF Drive ──────────────────────────────────────

    /**
     * Verifica que o controller dispara os 5 endpoints corretos do ECF Drive
     * no caminho feliz:
     *   1× /carteira/resumo
     *   1× /carteira/historico com periodicidade=mensal
     *   1× /carteira/breakdown com dimensao=programa
     *   1× /carteira/breakdown com dimensao=frete
     *   1× /carteira/breakdown com dimensao=cluster
     *   1× /carteira/breakdown com dimensao=localidade
     */
    public function test_index_chama_5_endpoints_ecf_corretos(): void
    {
        $this->mockEcfRespostas();

        $this->actingAs($this->usuarioComRole('admin'))
            ->get(route('painel-executivo.index'))
            ->assertStatus(200);

        // Verifica que /carteira/resumo foi chamado
        Http::assertSent(fn ($req) => str_contains($req->url(), '/carteira/resumo'));

        // Verifica que /carteira/historico foi chamado com periodicidade=mensal
        Http::assertSent(fn ($req) =>
            str_contains($req->url(), '/carteira/historico')
            && str_contains($req->url(), 'periodicidade=mensal')
        );

        // Verifica que breakdowns foram chamados para as 4 dimensões
        Http::assertSent(fn ($req) =>
            str_contains($req->url(), '/carteira/breakdown')
            && str_contains($req->url(), 'dimensao=programa')
        );
        Http::assertSent(fn ($req) =>
            str_contains($req->url(), '/carteira/breakdown')
            && str_contains($req->url(), 'dimensao=frete')
        );
        Http::assertSent(fn ($req) =>
            str_contains($req->url(), '/carteira/breakdown')
            && str_contains($req->url(), 'dimensao=cluster')
        );
        Http::assertSent(fn ($req) =>
            str_contains($req->url(), '/carteira/breakdown')
            && str_contains($req->url(), 'dimensao=localidade')
        );
    }
}
