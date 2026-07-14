<?php

namespace Tests\Feature\Phase77;

use App\Models\Company;
use App\Models\MlAnuncioRascunho;
use App\Models\MlToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Suíte de testes do endpoint POST /rascunho/{id}/imagem (Phase 77, Plan 02).
 *
 * Cobre WIZ-05:
 *  - Upload com sucesso → 200 com picture_id
 *  - Publicador sem atribuição → 403 (T-77-04)
 *  - Falha do ML (HTTP 500) → 422 pt-BR (T-77-06)
 *
 * Estratégia de banco: RefreshDatabase (SQLite in-memory, mesmo padrão do
 * TituloCatalogoTest.php da Phase 77). O gotcha da migration NPS (GENERATED
 * column no MariaDB) só ocorre no MySQL/MariaDB; SQLite não tem a restrição.
 *
 * Http::fake impede chamadas reais à API do ML.
 *
 * @group phase77
 */
class UploadImagemTest extends TestCase
{
    use RefreshDatabase;

    // ─── Helpers de fixture ──────────────────────────────────────────────────

    /** Cria usuário admin com e-mail verificado. */
    private function criarAdmin(): User
    {
        return User::factory()->create([
            'role'              => 'admin',
            'email_verified_at' => now(),
        ]);
    }

    /**
     * Cria Company + MlToken + MlAnuncioRascunho prontos para o upload.
     * Retorna [$rascunho, $company, $admin].
     */
    private function criarFixture(?int $userId = null): array
    {
        $admin   = $this->criarAdmin();
        $company = Company::factory()->create();

        // Token ML ativo (MlImagemService::enviar() chama ensureValidToken)
        MlToken::create([
            'company_id'        => $company->id,
            'ml_user_id'        => '9876543210',
            'access_token'      => 'fake-access-token',
            'refresh_token'     => 'fake-refresh-token',
            'token_type'        => 'bearer',
            'scope'             => 'read write offline_access',
            'expires_at'        => now()->addDays(6),
            'last_refreshed_at' => now(),
            'status'            => 'active',
            'connected_at'      => now(),
        ]);

        $rascunho = MlAnuncioRascunho::create([
            'company_id'     => $company->id,
            'mlb_empresa_id' => null,   // sem mlb_empresa — cai no fallback por user_id
            'user_id'        => $userId ?? $admin->id,
            'category_id'    => 'MLB179762',
            'payload'        => [],
            'status'         => MlAnuncioRascunho::STATUS_RASCUNHO,
        ]);

        return [$rascunho, $company, $admin];
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // Caso 1: upload com sucesso → 200 com picture_id
    // ═══════════════════════════════════════════════════════════════════════════

    /** @test */
    public function upload_com_sucesso_retorna_200_com_picture_id(): void
    {
        // Http::fake simula o endpoint /pictures/items/upload retornando picture_id
        Http::fake([
            'api.mercadolibre.com/pictures/items/upload' => Http::response(['id' => 'MLB-123456789-1'], 200),
            '*' => Http::response([], 200),
        ]);

        [$rascunho, $company, $admin] = $this->criarFixture();

        // Imagem fake em memória (não toca o disco real)
        $imagem = UploadedFile::fake()->image('foto-variacao.jpg', 400, 400);

        $response = $this->actingAs($admin)
            ->postJson("/mlb/anuncios/rascunho/{$rascunho->id}/imagem", [
                'imagem' => $imagem,
            ]);

        $response->assertStatus(200);
        $response->assertJson(['ok' => true, 'picture_id' => 'MLB-123456789-1']);
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // Caso 2: publicador sem atribuição → 403 (T-77-04)
    // ═══════════════════════════════════════════════════════════════════════════

    /** @test */
    public function publicador_sem_atribuicao_recebe_403(): void
    {
        Http::fake([
            'api.mercadolibre.com/pictures/items/upload' => Http::response(['id' => 'MLB-999'], 200),
            '*' => Http::response([], 200),
        ]);

        // Rascunho pertence ao admin (userId=admin.id)
        [$rascunho, $company, $admin] = $this->criarFixture();

        // Outro usuário (consultor) que não é o dono do rascunho e nem admin
        $consultor = User::factory()->create([
            'role'              => 'consultor',
            'email_verified_at' => now(),
        ]);

        $imagem = UploadedFile::fake()->image('foto-variacao.jpg');

        // Consultor tenta fazer upload na rota — mas a rota está protegida por role:admin
        // (middleware EnsureUserHasRole) → retorno será 302/403 antes do double-check.
        // O comportamento esperado é: NÃO 200 (o upload não deve acontecer).
        $response = $this->actingAs($consultor)
            ->postJson("/mlb/anuncios/rascunho/{$rascunho->id}/imagem", [
                'imagem' => $imagem,
            ]);

        // O middleware role:admin bloqueia antes do double-check do controller
        $this->assertNotEquals(200, $response->status());

        // Http::fake deve registrar que NENHUMA chamada foi feita ao ML (T-77-04)
        Http::assertNothingSent();
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // Caso 3: falha do ML (HTTP 500) → 422 pt-BR (T-77-06)
    // ═══════════════════════════════════════════════════════════════════════════

    /** @test */
    public function falha_do_ml_retorna_422_com_mensagem_pt_br(): void
    {
        // Simula falha no endpoint de upload do ML (HTTP 500)
        Http::fake([
            'api.mercadolibre.com/pictures/items/upload' => Http::response(['error' => 'internal_error'], 500),
            '*' => Http::response([], 200),
        ]);

        [$rascunho, $company, $admin] = $this->criarFixture();

        $imagem = UploadedFile::fake()->image('foto-variacao.jpg', 400, 400);

        $response = $this->actingAs($admin)
            ->postJson("/mlb/anuncios/rascunho/{$rascunho->id}/imagem", [
                'imagem' => $imagem,
            ]);

        $response->assertStatus(422);

        // Resposta deve ter shape {ok: false, erros: [{mensagem: ...}]} em pt-BR
        $json = $response->json();
        $this->assertFalse($json['ok'] ?? true);
        $this->assertArrayHasKey('erros', $json);
        $this->assertNotEmpty($json['erros']);

        // Mensagem em pt-BR (não expõe detalhe técnico — T-77-06)
        $mensagem = $json['erros'][0]['mensagem'] ?? '';
        $this->assertStringContainsString('Falha', $mensagem);
        $this->assertStringContainsString('imagem', $mensagem);
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // Caso bônus: rota mlb.anuncios.rascunho.imagem está registrada
    // ═══════════════════════════════════════════════════════════════════════════

    /** @test */
    public function rota_rascunho_imagem_esta_registrada(): void
    {
        // Verifica que a rota nomeada existe (gera URL válida sem lançar exceção)
        $rascunhoId = 1;
        $url = route('mlb.anuncios.rascunho.imagem', ['rascunho' => $rascunhoId]);
        $this->assertStringContainsString("/mlb/anuncios/rascunho/{$rascunhoId}/imagem", $url);
    }
}
