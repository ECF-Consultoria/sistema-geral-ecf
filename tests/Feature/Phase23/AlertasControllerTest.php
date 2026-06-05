<?php

// Phase 23 — testes Feature do AlertasController (caixa de entrada do comercial).
// Cobre: roles autorizadas (200), role bloqueada (403), guest (302), ack POST,
// filtros propagados ao wrapper, lookup por ambas as colunas, fallback em erro.

namespace Tests\Feature\Phase23;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AlertasControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.ecf.base' => 'https://files.ecfconsultoria.com.br/api/v1',
            'services.ecf.key'  => 'fake-key',
        ]);
        Cache::flush();
    }

    // ─── Helpers ────────────────────────────────────────────────────────────

    private function createUserComRole(string $role): User
    {
        return User::factory()->create([
            'role'               => $role,
            'email_verified_at'  => now(),
        ]);
    }

    /**
     * Configura Http::fake para simular resposta bem-sucedida do ECF Drive em /signals*.
     * Aceita override de campos do payload via $data.
     */
    private function fakeSignalsOk(array $data = []): void
    {
        $default = [
            'data' => [
                [
                    'id'         => 100,
                    'custId'     => '1007473885',
                    'eventType'  => 'seller.gmv_queda_mom',
                    'severity'   => 'critical',
                    'payload'    => [
                        'delta_pct'    => -76.46,
                        'gmv_atual'    => 11135.42,
                        'gmv_anterior' => 47315.18,
                        'mes_atual'    => '2026-05',
                    ],
                    'detectedAt' => '2026-06-05T12:00:00Z',
                    'ackedAt'    => null,
                ],
            ],
            'total' => 778,
            'page'  => 1,
            'limit' => 50,
        ];

        $payload = array_merge($default, $data);

        Http::fake([
            '*/signals*' => Http::response($payload, 200),
        ]);
    }

    // ─── Testes de autorização ───────────────────────────────────────────────

    /**
     * Admin autenticado recebe 200 com todas as props obrigatórias.
     * Verificação das props: signals, companies_map, stats, filters,
     * type_labels, severity_labels, severity_colors, erro = null.
     */
    public function test_index_admin_recebe_200_com_props_corretas(): void
    {
        $this->fakeSignalsOk();

        $this->actingAs($this->createUserComRole('admin'))
            ->get(route('alertas.index'))
            ->assertStatus(200)
            ->assertInertia(fn (Assert $p) => $p
                ->component('AlertasEstrategicos/Index')
                ->has('signals.data')
                ->has('companies_map')
                ->has('stats')
                ->has('filters')
                ->has('type_labels')
                ->has('severity_labels')
                ->has('severity_colors')
                ->where('erro', null)
            );
    }

    /**
     * Consultor autenticado recebe 200 (permissão compartilhada — D-04).
     */
    public function test_index_consultor_recebe_200(): void
    {
        $this->fakeSignalsOk();

        $this->actingAs($this->createUserComRole('consultor'))
            ->get(route('alertas.index'))
            ->assertStatus(200);
    }

    /**
     * Mentor autenticado recebe 200 (permissão compartilhada — D-04).
     */
    public function test_index_mentor_recebe_200(): void
    {
        $this->fakeSignalsOk();

        $this->actingAs($this->createUserComRole('mentor'))
            ->get(route('alertas.index'))
            ->assertStatus(200);
    }

    /**
     * Usuário sem role autorizada recebe 403 — EnsureUserHasRole bloqueia.
     *
     * Nota: o enum 'role' da tabela users (admin/consultor/mentor) mapeia exatamente
     * os roles autorizados na rota. Para testar o bloqueio real do middleware
     * EnsureUserHasRole, desativamos temporariamente o CHECK constraint do SQLite
     * e inserimos um usuário com role='lider' (fora do enum de produção).
     */
    public function test_index_role_nao_autorizada_recebe_403(): void
    {
        // Desabilita CHECK constraints no SQLite para inserir role fora do enum
        \Illuminate\Support\Facades\DB::statement('PRAGMA ignore_check_constraints = 1');

        \Illuminate\Support\Facades\DB::table('users')->insert([
            'name'              => 'Usuário Lider',
            'email'             => 'lider-teste@example.com',
            'email_verified_at' => now()->toDateTimeString(),
            'password'          => \Illuminate\Support\Facades\Hash::make('password'),
            'role'              => 'lider',
            'remember_token'    => null,
            'created_at'        => now()->toDateTimeString(),
            'updated_at'        => now()->toDateTimeString(),
        ]);

        // Reativa constraints após inserção
        \Illuminate\Support\Facades\DB::statement('PRAGMA ignore_check_constraints = 0');

        $lider = User::where('email', 'lider-teste@example.com')->firstOrFail();

        $this->actingAs($lider)
            ->get(route('alertas.index'))
            ->assertStatus(403);
    }

    /**
     * Guest (não autenticado) recebe redirect 302 para login.
     */
    public function test_index_guest_recebe_302_redirect_login(): void
    {
        $this->get(route('alertas.index'))
            ->assertStatus(302)
            ->assertRedirect(route('login'));
    }

    // ─── Teste de ack ────────────────────────────────────────────────────────

    /**
     * POST /alertas-estrategicos/{id}/ack deve disparar POST no ECF Drive
     * com a URL correta (contendo /signals/42/ack) e retornar 302 com flash success.
     */
    public function test_ack_dispara_post_no_ecf_drive_e_redireciona_back(): void
    {
        Http::fake([
            '*/signals/42/ack' => Http::response(['ok' => true], 200),
        ]);

        $admin = $this->createUserComRole('admin');

        $this->actingAs($admin)
            ->from(route('alertas.index'))
            ->post(route('alertas.ack', 42))
            ->assertStatus(302)
            ->assertSessionHas('success');

        Http::assertSent(fn ($req) =>
            $req->method() === 'POST'
            && str_contains($req->url(), '/signals/42/ack')
            && $req->hasHeader('X-Api-Key', 'fake-key')
        );
    }

    // ─── Teste de filtros ───────────────────────────────────────────────────

    /**
     * Filtros severity + event_type passados via query string devem ser
     * propagados como query params na chamada ao wrapper listSignals.
     */
    public function test_index_aplica_filtros_severity_e_event_type_no_listSignals(): void
    {
        $this->fakeSignalsOk();

        $this->actingAs($this->createUserComRole('admin'))
            ->get(route('alertas.index', [
                'severity'   => 'critical',
                'event_type' => 'seller.gmv_queda_mom',
            ]))
            ->assertStatus(200);

        Http::assertSent(fn ($req) =>
            str_contains($req->url(), 'severity=critical')
            && str_contains($req->url(), 'event_type=seller.gmv_queda_mom')
        );
    }

    // ─── Teste de lookup batch ──────────────────────────────────────────────

    /**
     * Lookup de empresa deve funcionar via adman_account_id E ml_store_id
     * numa única query batch (D-03). Verifica que companies_map está
     * populado corretamente para ambas as colunas.
     */
    public function test_index_lookup_empresa_bate_por_adman_account_id_e_ml_store_id(): void
    {
        // Empresa A identificada via adman_account_id
        Company::create([
            'name'             => 'EMPRESA A',
            'slug'             => 'empresa-a',
            'active'           => true,
            'adman_account_id' => 'CUST_A',
            'ml_store_id'      => null,
        ]);

        // Empresa B identificada via ml_store_id
        Company::create([
            'name'             => 'EMPRESA B',
            'slug'             => 'empresa-b',
            'active'           => true,
            'adman_account_id' => null,
            'ml_store_id'      => 'CUST_B',
        ]);

        Http::fake([
            '*/signals*' => Http::response([
                'data' => [
                    [
                        'id'         => 1,
                        'custId'     => 'CUST_A',
                        'eventType'  => 'seller.score_critico',
                        'severity'   => 'warning',
                        'payload'    => ['score_final_full' => 25],
                        'detectedAt' => '2026-06-05T12:00:00Z',
                        'ackedAt'    => null,
                    ],
                    [
                        'id'         => 2,
                        'custId'     => 'CUST_B',
                        'eventType'  => 'seller.queda_visitas',
                        'severity'   => 'critical',
                        'payload'    => ['delta_pct' => -65, 'visitas_atual' => 100, 'visitas_anterior' => 290],
                        'detectedAt' => '2026-06-05T12:00:00Z',
                        'ackedAt'    => null,
                    ],
                ],
                'total' => 2, 'page' => 1, 'limit' => 50,
            ], 200),
        ]);

        $this->actingAs($this->createUserComRole('admin'))
            ->get(route('alertas.index'))
            ->assertInertia(fn (Assert $p) => $p
                ->where('companies_map.CUST_A.name', 'EMPRESA A')
                ->where('companies_map.CUST_B.name', 'EMPRESA B')
                ->etc()
            );
    }

    // ─── Teste de fallback em erro ───────────────────────────────────────────

    /**
     * Quando o ECF Drive retorna 500 (wrapper lança RuntimeException),
     * a aba deve retornar 200 com props vazias + prop 'erro' com mensagem pt-BR.
     * NUNCA deve quebrar o pageload — D-02 do PLAN.
     */
    public function test_index_em_erro_do_wrapper_retorna_props_vazias_com_flash_erro(): void
    {
        Http::fake([
            '*/signals*' => Http::response('boom', 500),
        ]);

        $this->actingAs($this->createUserComRole('admin'))
            ->get(route('alertas.index'))
            ->assertStatus(200) // NÃO quebra o pageload
            ->assertInertia(fn (Assert $p) => $p
                ->component('AlertasEstrategicos/Index')
                ->where('signals.total', 0)
                ->where('stats.critical', 0)
                ->where('erro', 'API ECF Drive indisponível agora. Tente em alguns segundos.')
            );
    }
}
