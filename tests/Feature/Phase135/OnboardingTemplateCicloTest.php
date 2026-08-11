<?php

namespace Tests\Feature\Phase135;

use App\Models\Servico;
use App\Models\Setor;
use App\Models\TemplatePasso;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Fase 135 Plano 08 (Task 2) — cobre `StoreOnboardingTemplateRequest`
 * exercitada pela rota real `POST /onboarding/templates` (não instanciando a
 * classe na mão): catálogo fechado de `auto_fonte`/`dono`/`condicao`,
 * chave duplicada e a guarda de ciclo (SC-08), direto e indireto.
 */
class OnboardingTemplateCicloTest extends TestCase
{
    use RefreshDatabase;

    private function servicoDeGestao(): Servico
    {
        return Servico::query()
            ->where('ativo', true)
            ->where('setor', Servico::SETOR_PERFORMANCE)
            ->where('nome', 'like', '%Gestão%')
            ->firstOrFail();
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    /** @return array<string, mixed> */
    private function payloadBase(array $passos): array
    {
        return [
            'servico_id' => $this->servicoDeGestao()->id,
            'passos'     => $passos,
        ];
    }

    // ─── Guarda de ciclo (SC-08) ────────────────────────────────────────────

    /** @test */
    public function ciclo_direto_a_b_a_e_rejeitado_com_erro_de_campo_e_mensagem_por_extenso(): void
    {
        $payload = $this->payloadBase([
            ['chave' => 'a', 'titulo' => 'Passo A', 'dono' => TemplatePasso::DONO_INTERNO, 'depende_de' => ['b']],
            ['chave' => 'b', 'titulo' => 'Passo B', 'dono' => TemplatePasso::DONO_INTERNO, 'depende_de' => ['a']],
        ]);

        $response = $this->actingAs($this->admin())
            ->post(route('onboarding.templates.store'), $payload);

        $response->assertSessionHasErrors(['passos.0.depende_de', 'passos']);

        $mensagens = collect(session('errors')->get('passos'))->implode(' ');
        $this->assertStringContainsString('ciclo de dependência entre', $mensagens);
        $this->assertStringContainsString('a', $mensagens);
        $this->assertStringContainsString('b', $mensagens);
    }

    /** @test */
    public function ciclo_indireto_de_3_saltos_tambem_e_rejeitado(): void
    {
        $payload = $this->payloadBase([
            ['chave' => 'a', 'titulo' => 'Passo A', 'dono' => TemplatePasso::DONO_INTERNO, 'depende_de' => ['b']],
            ['chave' => 'b', 'titulo' => 'Passo B', 'dono' => TemplatePasso::DONO_INTERNO, 'depende_de' => ['c']],
            ['chave' => 'c', 'titulo' => 'Passo C', 'dono' => TemplatePasso::DONO_INTERNO, 'depende_de' => ['a']],
        ]);

        $response = $this->actingAs($this->admin())
            ->post(route('onboarding.templates.store'), $payload);

        $mensagens = collect(session('errors')->get('passos'))->implode(' ');
        $this->assertStringContainsString('ciclo de dependência entre', $mensagens);
    }

    // ─── Catálogo fechado (D-09/D-14) ───────────────────────────────────────

    /** @test */
    public function auto_fonte_fora_do_catalogo_fechado_e_rejeitado(): void
    {
        $payload = $this->payloadBase([
            [
                'chave'      => 'passo_a',
                'titulo'     => 'Passo A',
                'dono'       => TemplatePasso::DONO_SISTEMA,
                'auto_fonte' => 'inventado_agora',
            ],
        ]);

        $response = $this->actingAs($this->admin())
            ->post(route('onboarding.templates.store'), $payload);

        $response->assertSessionHasErrors(['passos.0.auto_fonte']);
    }

    /** @test */
    public function dono_administrativo_e_rejeitado_d14(): void
    {
        $payload = $this->payloadBase([
            ['chave' => 'passo_a', 'titulo' => 'Passo A', 'dono' => 'administrativo'],
        ]);

        $response = $this->actingAs($this->admin())
            ->post(route('onboarding.templates.store'), $payload);

        $response->assertSessionHasErrors(['passos.0.dono']);
    }

    /** @test */
    public function condicao_tipo_fora_do_catalogo_e_rejeitada(): void
    {
        $payload = $this->payloadBase([
            [
                'chave'     => 'passo_a',
                'titulo'    => 'Passo A',
                'dono'      => TemplatePasso::DONO_INTERNO,
                'condicao'  => ['tipo' => 'condicao_inventada'],
            ],
        ]);

        $response = $this->actingAs($this->admin())
            ->post(route('onboarding.templates.store'), $payload);

        $response->assertSessionHasErrors(['passos.0.condicao.tipo']);
    }

    // ─── Shape ───────────────────────────────────────────────────────────────

    /** @test */
    public function duas_chaves_iguais_no_payload_e_rejeitado(): void
    {
        $payload = $this->payloadBase([
            ['chave' => 'passo_a', 'titulo' => 'Passo A', 'dono' => TemplatePasso::DONO_INTERNO],
            ['chave' => 'passo_a', 'titulo' => 'Passo A duplicado', 'dono' => TemplatePasso::DONO_INTERNO],
        ]);

        $response = $this->actingAs($this->admin())
            ->post(route('onboarding.templates.store'), $payload);

        $response->assertSessionHasErrors(['passos.1.chave']);
    }

    /** @test */
    public function depende_de_apontando_para_chave_ausente_do_payload_e_rejeitado_sem_falar_em_ciclo(): void
    {
        $payload = $this->payloadBase([
            [
                'chave'      => 'passo_a',
                'titulo'     => 'Passo A',
                'dono'       => TemplatePasso::DONO_INTERNO,
                'depende_de' => ['chave_inexistente'],
            ],
        ]);

        $response = $this->actingAs($this->admin())
            ->post(route('onboarding.templates.store'), $payload);

        $response->assertSessionHasErrors(['passos.0.depende_de.0']);
        $errosPassos = session('errors')->has('passos') ? session('errors')->get('passos') : [];
        $this->assertStringNotContainsString('ciclo', collect($errosPassos)->implode(' '));
    }

    // ─── Autorização (D-04) ──────────────────────────────────────────────────

    /** @test */
    public function usuario_nao_admin_recebe_403_mesmo_com_payload_valido(): void
    {
        $consultor = User::factory()->create(['role' => 'consultor']);

        $payload = $this->payloadBase([
            ['chave' => 'passo_a', 'titulo' => 'Passo A', 'dono' => TemplatePasso::DONO_INTERNO],
        ]);

        $response = $this->actingAs($consultor)
            ->post(route('onboarding.templates.store'), $payload);

        $response->assertForbidden();
    }
}
