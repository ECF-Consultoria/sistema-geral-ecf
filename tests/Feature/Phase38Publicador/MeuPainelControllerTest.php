<?php

// Fase 38 (Wave 0) — Suíte de feature RED do meuPainel() evoluído.
// Namespace Phase38Publicador (NÃO Phase38 — evita colisão com a suíte dos Polos).
// Descreve as props novas que o controller deve emitir (implementadas na Wave 2, plano 38-03).
// Os testes FALHAM agora porque meuPainel() ainda não emite score_publicador/faturamento_mes/
// net_billing_timeseries.
// Cobre: PUB-04, PUB-05, PUB-06.

namespace Tests\Feature\Phase38Publicador;

use App\Models\Publicacao;
use App\Models\Setor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class MeuPainelControllerTest extends TestCase
{
    use RefreshDatabase;

    private static int $seq = 0;

    /** Publicador de teste — admin bypassa checkPubAccess('meu_painel'). */
    private function publicador(): User
    {
        return User::factory()->create([
            'role'              => 'admin',
            'email_verified_at' => now(),
        ]);
    }

    /**
     * Cria uma publicação. Usa forceFill porque net_billing não está no
     * $fillable de Publicacao (é populado pelo sync de vendas).
     */
    private function pub(int $userId, array $attrs = []): Publicacao
    {
        $p = new Publicacao();
        $p->forceFill(array_merge([
            'data'                 => now()->format('Y-m-d'),
            'user_id'              => $userId,
            'empresa'              => 'Empresa Teste',
            'mlb_code'             => 'MLB' . str_pad((string) (++self::$seq), 6, '0', STR_PAD_LEFT),
            'tipo'                 => 'anuncio',
            'vendido'              => false,
            'vendas_qty'           => 0,
            'net_billing'          => null,
            'problema'             => false,
            'comentario_resolvido' => false,
        ], $attrs));
        $p->save();

        return $p;
    }

    // ─── PUB-04: props novas presentes + props existentes preservadas ───────────
    public function test_meu_painel_passa_props_novas(): void
    {
        $user = $this->publicador();
        $this->pub($user->id, ['vendido' => true, 'vendas_qty' => 2, 'net_billing' => 150.0]);
        $this->pub($user->id, ['net_billing' => 90.0]);

        $this->actingAs($user)
            ->get(route('mlb.meu-painel'))
            ->assertStatus(200)
            ->assertInertia(fn (Assert $p) => $p
                ->component('Mlb/MeuPainel')
                // Props novas (Fase 38)
                ->has('score_publicador')
                ->has('faturamento_mes')
                ->has('net_billing_timeseries')
                // Props existentes que devem CONTINUAR presentes
                ->has('kpis')
                ->has('meta')
                ->has('mesRef')
            );
    }

    // ─── PUB-05: publicador sem publicações → props vazias válidas, sem exceção ──
    public function test_sem_publicacoes(): void
    {
        $user = $this->publicador();

        $this->actingAs($user)
            ->get(route('mlb.meu-painel'))
            ->assertStatus(200)
            ->assertInertia(fn (Assert $p) => $p
                ->component('Mlb/MeuPainel')
                ->where('faturamento_mes', fn ($v) => (float) $v === 0.0)
                ->where('score_publicador.score', fn ($v) => (float) $v === 0.0)
            );
    }

    // ─── PUB-06: net_billing null em todas → faturamento_mes = 0 (sem div/zero) ──
    public function test_net_billing_null(): void
    {
        $user = $this->publicador();
        $this->pub($user->id, ['net_billing' => null]);
        $this->pub($user->id, ['net_billing' => null]);

        $this->actingAs($user)
            ->get(route('mlb.meu-painel'))
            ->assertStatus(200)
            ->assertInertia(fn (Assert $p) => $p
                ->component('Mlb/MeuPainel')
                ->where('faturamento_mes', fn ($v) => (float) $v === 0.0)
            );
    }

    // ─── Supervisão: admin com publicadores cadastrados → VISÃO GERAL (grid) ────
    public function test_admin_com_publicadores_ve_visao_geral(): void
    {
        $admin = $this->publicador(); // role admin → vê todos

        [$p1] = $this->criarPublicadores(2);
        $this->pub($p1->id, ['vendido' => true, 'vendas_qty' => 1, 'net_billing' => 100.0]);

        $this->actingAs($admin)
            ->get(route('mlb.meu-painel'))
            ->assertStatus(200)
            ->assertInertia(fn (Assert $p) => $p
                ->component('Mlb/MeuPainel')
                ->where('visaoGeral', true)
                ->has('cards', 2)
                ->has('publicadores', 2)
            );
    }

    // ─── Supervisão: admin com ?pub=ID → painel INDIVIDUAL daquele publicador ───
    public function test_admin_com_pub_abre_painel_individual(): void
    {
        $admin = $this->publicador();

        [$p1] = $this->criarPublicadores(1);
        $this->pub($p1->id, ['vendido' => true, 'vendas_qty' => 1, 'net_billing' => 200.0]);

        $this->actingAs($admin)
            ->get(route('mlb.meu-painel', ['pub' => $p1->id]))
            ->assertStatus(200)
            ->assertInertia(fn (Assert $p) => $p
                ->component('Mlb/MeuPainel')
                ->where('visaoGeral', false)
                ->where('pubFiltro', $p1->id)
                ->where('faturamento_mes', fn ($v) => (float) $v === 200.0)
            );
    }

    /**
     * Cria N publicadores reais (setor 'publicacao' + cargo 'publicador' + vínculo
     * no pivot user_setores) para que apareçam em MlbController::publicadores().
     *
     * @return array<int,User>
     */
    private function criarPublicadores(int $n): array
    {
        $setor = Setor::firstOrCreate(['slug' => 'publicacao'], ['nome' => 'Publicação', 'active' => true]);
        $cargo = $setor->cargos()->firstOrCreate(
            ['slug' => 'publicador'],
            ['nome' => 'Publicador', 'meta_publicacoes' => 220],
        );

        $users = [];
        for ($i = 1; $i <= $n; $i++) {
            $u = User::factory()->create(['role' => 'consultor', 'email_verified_at' => now()]);
            DB::table('user_setores')->insert([
                'user_id'      => $u->id,
                'setor_id'     => $setor->id,
                'cargo_id'     => $cargo->id,
                'is_principal' => true,
                'assigned_at'  => now(),
                'created_at'   => now(),
                'updated_at'   => now(),
            ]);
            $users[] = $u;
        }

        return $users;
    }
}
