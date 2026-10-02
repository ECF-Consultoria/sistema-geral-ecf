<?php

namespace Tests\Feature\Phase160;

use App\Models\Company;
use App\Models\Configuracao;
use App\Models\MlAnuncioCriativo;
use App\Models\MlAnuncioRascunho;
use App\Models\MlbEmpresa;
use App\Models\MlToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Terceira fatia do Creative Engine (Fase 160, Plano 03) — aprovação da
 * imagem gerada: upload ao Mercado Livre pelo caminho de sempre
 * (`MlImagemService::enviar()`, PUB-02) e gravação em
 * `ml_anuncio_rascunhos.payload.pictures` na posição do slot (PUB-01).
 *
 * A armadilha do plano (autosave do wizard reconstruindo `pictures` a partir
 * do state do navegador) é um problema de frontend, coberto no Task 3 — este
 * arquivo prova só a metade do servidor: a forma gravada é a MESMA que o
 * wizard produz (`[['source' => url]]`), para a reconstrução não divergir.
 *
 * Nenhum teste fala com o Gemini nem com o Mercado Livre de verdade:
 * `Http::preventStrayRequests()` + `Http::fake()` do upload.
 */
class CriativoAprovacaoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Http::preventStrayRequests();
        Configuracao::set('creative_engine_ativo', '1');
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function naoAdmin(): User
    {
        return User::factory()->create(['role' => 'consultor']);
    }

    private function companyConectada(string $nome = 'Unity Móveis'): Company
    {
        $company = Company::factory()->create(['name' => $nome]);

        MlToken::create([
            'company_id'    => $company->id,
            'ml_user_id'    => '1489433777',
            'access_token'  => 'APP_USR-x',
            'refresh_token' => 'TG-x',
            'expires_at'    => now()->addHours(5),
            'status'        => 'active',
        ]);

        return $company;
    }

    private function rascunho(Company $company, ?int $responsavelId = null, array $payloadExtra = []): MlAnuncioRascunho
    {
        $mlbEmpresaId = null;

        if ($responsavelId !== null) {
            $mlbEmpresaId = MlbEmpresa::create([
                'nome'           => $company->name,
                'tipo'           => 'ecommerce',
                'cust_id'        => 'cust-' . $company->id,
                'company_id'     => $company->id,
                'responsavel_id' => $responsavelId,
            ])->id;
        }

        $userId = $responsavelId ?? User::factory()->create(['role' => 'admin'])->id;

        return MlAnuncioRascunho::create([
            'company_id'     => $company->id,
            'mlb_empresa_id' => $mlbEmpresaId,
            'user_id'        => $userId,
            'category_id'    => 'MLB1574',
            'payload'        => array_merge([
                'title'       => 'Cadeira Gamer Ergonômica',
                'category_id' => 'MLB1574',
                'description' => 'Cadeira gamer reclinável.',
                'attributes'  => [],
                'pictures'    => [],
            ], $payloadExtra),
            'status' => MlAnuncioRascunho::STATUS_RASCUNHO,
        ]);
    }

    /**
     * Criativo já `pronto` (geração concluída, imagem no disco privado) —
     * pronto para ser aprovado. `referencias` preenchidas porque a aprovação
     * nunca as toca (APROV-05 trata só a imagem gerada).
     */
    private function criativoPronto(MlAnuncioRascunho $r): MlAnuncioCriativo
    {
        $token = Str::random(32);

        Storage::disk('local')->put("creative-geradas/{$token}/hero.jpg", 'bytes-da-imagem-gerada');

        return MlAnuncioCriativo::create([
            'token'          => $token,
            'company_id'     => $r->company_id,
            'mlb_empresa_id' => $r->mlb_empresa_id,
            'rascunho_id'    => $r->id,
            'user_id'        => $r->user_id,
            'slot'           => 'hero',
            'status'         => MlAnuncioCriativo::STATUS_PRONTO,
            'imagem_path'    => "creative-geradas/{$token}/hero.jpg",
            'imagem_mime'    => 'image/jpeg',
            'referencias'    => [
                ['indice' => 0, 'nome' => 'foto.jpg', 'path' => "creative-referencias/{$token}/0.jpg", 'mime' => 'image/jpeg'],
            ],
        ]);
    }

    /** Fake do upload ao ML na forma MEDIDA da resposta real (MlImagemService). */
    private function fakeUploadMl(int $status = 200): void
    {
        Http::fake([
            'api.mercadolibre.com/pictures/items/upload' => Http::response([
                'id'         => 'MLB123456-abc',
                'variations' => [
                    ['secure_url' => 'https://http2.mlstatic.com/D_123-O.jpg'],
                ],
            ], $status),
        ]);
    }

    // ═══ PUB-01 / PUB-02 — caminho feliz ═══════════════════════════════

    public function test_aprovar_criativo_pronto_sobe_ao_ml_e_grava_no_payload(): void
    {
        Storage::fake('local');
        $this->fakeUploadMl();

        $company  = $this->companyConectada();
        $rascunho = $this->rascunho($company);
        $criativo = $this->criativoPronto($rascunho);
        $admin    = $this->admin();

        $resposta = $this->actingAs($admin)->postJson(
            route('mlb.anuncios.criativo.aprovar', ['token' => $criativo->token]),
        );

        $resposta->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('url', 'https://http2.mlstatic.com/D_123-O.jpg')
            ->assertJsonPath('picture_id', 'MLB123456-abc');

        // Upload ao ML só pelo caminho de sempre (PUB-02).
        Http::assertSent(fn ($request) => $request->url() === 'https://api.mercadolibre.com/pictures/items/upload');
        Http::assertSentCount(1);

        $criativo->refresh();
        $this->assertSame(MlAnuncioCriativo::STATUS_APROVADO, $criativo->status);
        $this->assertSame($admin->id, $criativo->aprovado_por);
        $this->assertNotNull($criativo->aprovado_em);
        $this->assertSame('MLB123456-abc', $criativo->ml_picture_id);
        $this->assertSame('https://http2.mlstatic.com/D_123-O.jpg', $criativo->ml_picture_url);

        $rascunho->refresh();
        $this->assertSame(
            [['source' => 'https://http2.mlstatic.com/D_123-O.jpg']],
            $rascunho->payload['pictures'],
        );
    }

    // ═══ APROV-05 — estado é o guarda ═══════════════════════════════════

    public function test_aprovar_recusa_quando_criativo_nao_esta_pronto(): void
    {
        Storage::fake('local');
        $this->fakeUploadMl();

        foreach ([MlAnuncioCriativo::STATUS_PENDENTE, MlAnuncioCriativo::STATUS_RODANDO, MlAnuncioCriativo::STATUS_ERRO] as $status) {
            $company  = $this->companyConectada();
            $rascunho = $this->rascunho($company);
            $criativo = $this->criativoPronto($rascunho);
            $criativo->update(['status' => $status]);

            $resposta = $this->actingAs($this->admin())->postJson(
                route('mlb.anuncios.criativo.aprovar', ['token' => $criativo->token]),
            );

            $resposta->assertStatus(422);
            $mensagem = $resposta->json('erros')[0]['mensagem'] ?? $resposta->json('message');
            $this->assertNotEmpty($mensagem);

            $rascunho->refresh();
            $this->assertSame([], $rascunho->payload['pictures']);
            $this->assertSame($status, $criativo->fresh()->status);
        }

        Http::assertNothingSent();
    }

    public function test_aprovar_segunda_vez_recusa_e_nao_sobe_de_novo(): void
    {
        Storage::fake('local');
        $this->fakeUploadMl();

        $company  = $this->companyConectada();
        $rascunho = $this->rascunho($company);
        $criativo = $this->criativoPronto($rascunho);

        $primeira = $this->actingAs($this->admin())->postJson(
            route('mlb.anuncios.criativo.aprovar', ['token' => $criativo->token]),
        );
        $primeira->assertOk();

        $segunda = $this->actingAs($this->admin())->postJson(
            route('mlb.anuncios.criativo.aprovar', ['token' => $criativo->token]),
        );
        $segunda->assertStatus(422);

        // Só a 1ª aprovação subiu imagem — a 2ª tentativa não gerou outro upload.
        Http::assertSentCount(1);
    }

    // ═══ Falha no ML — meia aprovação não existe ════════════════════════

    public function test_falha_no_upload_ao_ml_mantem_criativo_pronto_e_payload_intacto(): void
    {
        Storage::fake('local');
        $this->fakeUploadMl(500);

        $company  = $this->companyConectada();
        $rascunho = $this->rascunho($company);
        $criativo = $this->criativoPronto($rascunho);

        $resposta = $this->actingAs($this->admin())->postJson(
            route('mlb.anuncios.criativo.aprovar', ['token' => $criativo->token]),
        );

        $resposta->assertStatus(422);

        $criativo->refresh();
        $this->assertSame(MlAnuncioCriativo::STATUS_PRONTO, $criativo->status);
        $this->assertNull($criativo->aprovado_em);
        $this->assertNull($criativo->ml_picture_id);

        $rascunho->refresh();
        $this->assertSame([], $rascunho->payload['pictures']);
    }

    // ═══ OPS-03 — chave desligada ════════════════════════════════════════

    public function test_chave_desligada_devolve_404(): void
    {
        Configuracao::set('creative_engine_ativo', '0');
        Storage::fake('local');
        $this->fakeUploadMl();

        $company  = $this->companyConectada();
        $rascunho = $this->rascunho($company);
        $criativo = $this->criativoPronto($rascunho);

        $resposta = $this->actingAs($this->admin())->postJson(
            route('mlb.anuncios.criativo.aprovar', ['token' => $criativo->token]),
        );

        $resposta->assertStatus(404);
        Http::assertNothingSent();
    }

    // ═══ Autorização / escopo ═══════════════════════════════════════════

    public function test_publicador_fora_de_escopo_recebe_403_e_nada_e_gravado(): void
    {
        Storage::fake('local');
        $this->fakeUploadMl();

        $company      = $this->companyConectada();
        $outroUsuario = User::factory()->create(['role' => 'consultor']);
        $rascunho     = $this->rascunho($company, $outroUsuario->id);
        $criativo     = $this->criativoPronto($rascunho);

        $publicadorForaDeEscopo = $this->naoAdmin();

        $resposta = $this->actingAs($publicadorForaDeEscopo)->postJson(
            route('mlb.anuncios.criativo.aprovar', ['token' => $criativo->token]),
        );

        $resposta->assertStatus(403);
        Http::assertNothingSent();

        $criativo->refresh();
        $this->assertSame(MlAnuncioCriativo::STATUS_PRONTO, $criativo->status);

        $rascunho->refresh();
        $this->assertSame([], $rascunho->payload['pictures']);
    }

    // ═══ PUB-01 — ordem do slot ══════════════════════════════════════════

    public function test_imagem_aprovada_ocupa_a_posicao_1_e_preserva_as_demais(): void
    {
        Storage::fake('local');
        $this->fakeUploadMl();

        $company  = $this->companyConectada();
        $rascunho = $this->rascunho($company, null, [
            'pictures' => [
                ['source' => 'https://exemplo.com/colada-a-mao.jpg'],
                ['source' => 'https://exemplo.com/segunda-foto.jpg'],
            ],
        ]);
        $criativo = $this->criativoPronto($rascunho);

        $resposta = $this->actingAs($this->admin())->postJson(
            route('mlb.anuncios.criativo.aprovar', ['token' => $criativo->token]),
        );

        $resposta->assertOk();

        $rascunho->refresh();
        $this->assertSame(
            [
                ['source' => 'https://http2.mlstatic.com/D_123-O.jpg'],
                ['source' => 'https://exemplo.com/segunda-foto.jpg'],
            ],
            $rascunho->payload['pictures'],
        );
    }
}
