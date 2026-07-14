<?php

namespace Tests\Feature\Phase80;

use App\Jobs\PublicarAnuncioMlJob;
use App\Models\Company;
use App\Models\MlAnuncioRascunho;
use App\Models\MlToken;
use App\Models\MlbEmpresa;
use App\Models\User;
use App\Services\MercadoLivreService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use ReflectionClass;
use Tests\TestCase;

/**
 * Phase 80 Plan 01 — Testes de publicarLote() com Queue::fake.
 *
 * Cobre BULK-01, BULK-02, BULK-03, BULK-04:
 *   (a) Lote com N rascunhos: N jobs enfileirados — um por rascunho
 *   (b) Delays escalonados: resposta JSON expõe inicio=0 e fim=(N-1)*3
 *   (c) STATUS_PUBLICANDO gravado imediatamente após dispatch (assertDatabaseHas)
 *   (d) ShouldBeUnique presente via reflection: uniqueId() == (string)rascunhoId
 *   (e) Token inválido: 422 pt-BR + Queue::assertNothingPushed()
 *   (f) Rascunho de outra empresa: 403 + Queue::assertNothingPushed()
 *   (g) Consultor (sem role:admin): 403 middleware + Queue::assertNothingPushed()
 *   (h) Rota mlb.anuncios.publicar-lote registrada com path correto
 *
 * Estratégia: RefreshDatabase (SQLite in-memory) + Queue::fake().
 * Nunca migrate completo (gotcha NPS no MariaDB local).
 *
 * @group phase80
 */
class PublicarLoteTest extends TestCase
{
    use RefreshDatabase;

    // ─── Helpers de fixture ───

    /** Cria usuário admin com e-mail verificado. */
    private function criarAdmin(): User
    {
        return User::factory()->create([
            'role'              => 'admin',
            'email_verified_at' => now(),
        ]);
    }

    /** Cria usuário consultor (sem role:admin). */
    private function criarConsultor(): User
    {
        return User::factory()->create([
            'role'              => 'consultor',
            'email_verified_at' => now(),
        ]);
    }

    /**
     * Cria Company + MlToken ativo + MlbEmpresa + N rascunhos da mesma empresa.
     * Retorna [$rascunhos (Collection), $empresa (MlbEmpresa), $company (Company), $admin (User)].
     *
     * @param int $quantidade número de rascunhos a criar
     * @param array $overrideRascunho campos para sobrescrever nos rascunhos
     */
    private function criarFixture(int $quantidade = 2, array $overrideRascunho = []): array
    {
        $admin   = $this->criarAdmin();
        $company = Company::factory()->create();

        // Token ML ativo — ensureValidToken usa status+expires_at
        MlToken::create([
            'company_id'        => $company->id,
            'ml_user_id'        => '1555596317',
            'access_token'      => 'fake-access-token',
            'refresh_token'     => 'fake-refresh-token',
            'token_type'        => 'bearer',
            'scope'             => 'read write offline_access',
            'expires_at'        => now()->addDays(6),
            'last_refreshed_at' => now(),
            'status'            => 'active',
            'connected_at'      => now(),
        ]);

        $empresa = MlbEmpresa::create([
            'nome'           => 'Empresa Lote Teste ' . $company->id,
            'tipo'           => 'ASSESSORIA',
            'company_id'     => $company->id,
            'responsavel_id' => $admin->id,
        ]);

        // Cria N rascunhos com status=rascunho
        $rascunhos = collect(range(1, $quantidade))->map(fn ($i) => MlAnuncioRascunho::create(array_merge([
            'company_id'     => $company->id,
            'mlb_empresa_id' => $empresa->id,
            'user_id'        => $admin->id,
            'category_id'    => 'MLB179762',
            'payload'        => [
                'title'           => "Produto Lote {$i}",
                'price'           => 299.90 + ($i * 10),
                'currency_id'     => 'BRL',
                'listing_type_id' => 'gold_special',
                'condition'       => 'new',
            ],
            'status'         => MlAnuncioRascunho::STATUS_RASCUNHO,
            'listing_tier'   => 'classico',
        ], $overrideRascunho)));

        return [$rascunhos, $empresa, $company, $admin];
    }

    /** URL base para a rota de publicar lote (com company fixada). */
    private function urlLote(int $companyId): string
    {
        return "/mlb/anuncios/empresa/{$companyId}/publicar-lote";
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // (a) Lote com N rascunhos: N jobs enfileirados — BULK-01 / BULK-02
    // ═══════════════════════════════════════════════════════════════════════════

    /** @test */
    public function publicar_lote_enfileira_um_job_por_rascunho(): void
    {
        // BULK-02: Queue::fake intercepta dispatches sem executar
        Queue::fake();

        [$rascunhos, , $company, $admin] = $this->criarFixture(3);

        $response = $this->actingAs($admin)->postJson($this->urlLote($company->id), [
            'company_id'   => $company->id,
            'rascunho_ids' => $rascunhos->pluck('id')->all(),
        ]);

        $response->assertOk();

        // BULK-01: exatamente 3 jobs enfileirados (um por rascunho)
        Queue::assertPushed(PublicarAnuncioMlJob::class, 3);
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // (b) Delay escalonado: inicio=0, fim=(N-1)*3 — BULK-02
    // ═══════════════════════════════════════════════════════════════════════════

    /** @test */
    public function publicar_lote_retorna_delays_escalonados_na_resposta(): void
    {
        // BULK-02: delay crescente de 3s por posição exposto na resposta JSON
        Queue::fake();

        [$rascunhos, , $company, $admin] = $this->criarFixture(4);

        $response = $this->actingAs($admin)->postJson($this->urlLote($company->id), [
            'company_id'   => $company->id,
            'rascunho_ids' => $rascunhos->pluck('id')->all(),
        ]);

        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('delays.inicio', 0)
            ->assertJsonPath('delays.fim', 9); // (4-1)*3 = 9s
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // (c) STATUS_PUBLICANDO gravado no banco — BULK-04
    // ═══════════════════════════════════════════════════════════════════════════

    /** @test */
    public function publicar_lote_marca_rascunhos_como_publicando_no_banco(): void
    {
        // BULK-04: status=publicando imediatamente após dispatch (antes do job rodar)
        Queue::fake();

        [$rascunhos, , $company, $admin] = $this->criarFixture(2);

        $this->actingAs($admin)->postJson($this->urlLote($company->id), [
            'company_id'   => $company->id,
            'rascunho_ids' => $rascunhos->pluck('id')->all(),
        ])->assertOk();

        // Cada rascunho deve estar com status=publicando no banco
        foreach ($rascunhos as $r) {
            $this->assertDatabaseHas('ml_anuncio_rascunhos', [
                'id'     => $r->id,
                'status' => MlAnuncioRascunho::STATUS_PUBLICANDO,
            ]);
        }
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // (d) ShouldBeUnique via reflection — BULK-03
    // ═══════════════════════════════════════════════════════════════════════════

    /** @test */
    public function publicar_anuncio_job_implementa_should_be_unique(): void
    {
        // BULK-03: assert estrutural via reflection — sem precisar executar o job
        $rc = new ReflectionClass(PublicarAnuncioMlJob::class);

        $this->assertTrue(
            $rc->implementsInterface(ShouldBeUnique::class),
            'PublicarAnuncioMlJob deve implementar ShouldBeUnique (BULK-03)'
        );
    }

    /** @test */
    public function publicar_anuncio_job_unique_id_retorna_rascunho_id_como_string(): void
    {
        // BULK-03: uniqueId() deve retornar o rascunhoId como string para o lock ser por rascunho
        $job = new PublicarAnuncioMlJob(42);

        $this->assertEquals(
            '42',
            $job->uniqueId(),
            'uniqueId() deve retornar (string) $this->rascunhoId'
        );
    }

    /** @test */
    public function publicar_anuncio_job_unique_for_retorna_300(): void
    {
        // BULK-03: uniqueFor() = 300s cobre timeout(120s) + backoff máximo (300s)
        $job = new PublicarAnuncioMlJob(1);

        $this->assertEquals(
            300,
            $job->uniqueFor(),
            'uniqueFor() deve retornar 300 para evitar lock órfão'
        );
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // (e) Token inválido: 422 pt-BR + nenhum job enfileirado — BULK-02 / T-80-04
    // ═══════════════════════════════════════════════════════════════════════════

    /** @test */
    public function publicar_lote_retorna_422_quando_token_invalido(): void
    {
        // BULK-02: token inválido aborta o lote antes de qualquer dispatch
        Queue::fake();

        [$rascunhos, , $company, $admin] = $this->criarFixture(2);

        // Mock do MercadoLivreService para simular token inválido
        $this->mock(MercadoLivreService::class, function ($mock) {
            $mock->shouldReceive('ensureValidToken')->andReturn(null);
        });

        $response = $this->actingAs($admin)->postJson($this->urlLote($company->id), [
            'company_id'   => $company->id,
            'rascunho_ids' => $rascunhos->pluck('id')->all(),
        ]);

        // T-80-04: 422 com mensagem pt-BR de conta desconectada
        $response->assertStatus(422);

        $json = $response->json();
        $this->assertFalse($json['ok'] ?? true, 'ok deve ser false quando token inválido');

        $mensagem = $json['erros'][0]['mensagem'] ?? '';
        $this->assertStringContainsString(
            'desconectada',
            $mensagem,
            "Mensagem de erro deve conter 'desconectada'"
        );
        $this->assertStringContainsString(
            'reconecte via Configurações',
            $mensagem,
            "Mensagem de erro deve conter 'reconecte via Configurações'"
        );

        // Nenhum job deve ter sido enfileirado
        Queue::assertNothingPushed();
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // (f) Rascunho de outra empresa: 403 + nenhum job — T-80-01 / T-80-02
    // ═══════════════════════════════════════════════════════════════════════════

    /** @test */
    public function publicar_lote_retorna_403_quando_rascunho_pertence_a_outra_empresa(): void
    {
        // T-80-02: double-check de company_id — rascunho de outra empresa é rejeitado
        Queue::fake();

        [$rascunhosA, , $companyA, $admin] = $this->criarFixture(1);
        [$rascunhosB, , $companyB]         = $this->criarFixture(1);

        // Envia rascunho da empresa B mas declara empresa A no corpo
        $response = $this->actingAs($admin)->postJson($this->urlLote($companyA->id), [
            'company_id'   => $companyA->id,
            'rascunho_ids' => [$rascunhosB->first()->id], // id de outra empresa
        ]);

        $response->assertStatus(403);

        // Nenhum job deve ter sido enfileirado
        Queue::assertNothingPushed();
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // (g) Consultor sem role:admin: 403 middleware — BULK-01
    // ═══════════════════════════════════════════════════════════════════════════

    /** @test */
    public function publicar_lote_retorna_403_para_consultor_sem_role_admin(): void
    {
        // BULK-01: middleware role:admin bloqueia antes do controller
        Queue::fake();

        [$rascunhos, , $company] = $this->criarFixture(1);
        $consultor = $this->criarConsultor();

        $response = $this->actingAs($consultor)->postJson($this->urlLote($company->id), [
            'company_id'   => $company->id,
            'rascunho_ids' => $rascunhos->pluck('id')->all(),
        ]);

        $response->assertForbidden();

        // Nenhum job deve ter sido enfileirado
        Queue::assertNothingPushed();
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // (h) Rota registrada com path correto
    // ═══════════════════════════════════════════════════════════════════════════

    /** @test */
    public function rota_publicar_lote_esta_registrada(): void
    {
        // Assert que a rota existe com o path esperado
        $url = route('mlb.anuncios.publicar-lote', ['company' => 99]);
        $this->assertStringContainsString('/mlb/anuncios/empresa/99/publicar-lote', $url);
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // (i) Rascunhos já em publicando/publicado são pulados — BULK-04
    // ═══════════════════════════════════════════════════════════════════════════

    /** @test */
    public function publicar_lote_pula_rascunhos_ja_em_publicacao(): void
    {
        // BULK-04: rascunhos já com status=publicando ou publicado não são re-enfileirados
        Queue::fake();

        [$rascunhos, , $company, $admin] = $this->criarFixture(3);

        // Marca o primeiro como já publicando
        $rascunhos->first()->update(['status' => MlAnuncioRascunho::STATUS_PUBLICANDO]);

        $response = $this->actingAs($admin)->postJson($this->urlLote($company->id), [
            'company_id'   => $company->id,
            'rascunho_ids' => $rascunhos->pluck('id')->all(),
        ]);

        $response->assertOk();

        // Apenas 2 jobs devem ser enfileirados (o primeiro já estava publicando)
        Queue::assertPushed(PublicarAnuncioMlJob::class, 2);
    }
}
