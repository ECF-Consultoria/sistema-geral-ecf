<?php

namespace Tests\Feature\Phase160;

use App\Models\Company;
use App\Models\Configuracao;
use App\Models\MlAnuncioRascunho;
use App\Models\MlbEmpresa;
use App\Models\MlToken;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Fatia fina do Creative Engine (Fase 160, Plano 01) — upload da foto de
 * referência, atrás da chave `creative_engine_ativo` (OPS-03).
 *
 * Nenhum teste desta fase fala com o Gemini: `Http::preventStrayRequests()`
 * garante isso. Molde: tests/Feature/AnuncioIaAnaliseTest.php.
 */
class CriativoReferenciaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        // Nenhum teste desta fatia gera imagem — só upload/armazenagem. Qualquer
        // chamada de rede não prevista precisa estourar, não ir para a internet.
        Http::preventStrayRequests();
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

    private function rascunho(Company $company, ?int $responsavelId = null): MlAnuncioRascunho
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

        // user_id é NOT NULL na tabela — rascunho sem responsável explícito (caso
        // comum nos testes de upload) precisa de algum "dono" válido mesmo assim.
        $userId = $responsavelId ?? User::factory()->create(['role' => 'admin'])->id;

        return MlAnuncioRascunho::create([
            'company_id'     => $company->id,
            'mlb_empresa_id' => $mlbEmpresaId,
            'user_id'        => $userId,
            'category_id'    => 'MLB1574',
            'payload'        => [
                'title'       => 'Cadeira Gamer Ergonômica',
                'category_id' => 'MLB1574',
                'description' => 'Cadeira gamer reclinável.',
                'attributes'  => [],
            ],
            'status' => MlAnuncioRascunho::STATUS_RASCUNHO,
        ]);
    }

    private function ligarChave(): void
    {
        Configuracao::set('creative_engine_ativo', '1');
    }

    // ═══ OPS-03 — chave desligada ═══════════════════════════════════════

    public function test_chave_desligada_responde_404_e_nao_grava_nada(): void
    {
        Storage::fake('local');
        Storage::fake('public');

        $company  = $this->companyConectada();
        $rascunho = $this->rascunho($company);

        $resposta = $this->actingAs($this->admin())->postJson(
            route('mlb.anuncios.criativo.referencia', ['rascunho' => $rascunho->id]),
            ['referencias' => [UploadedFile::fake()->image('gabinete.jpg')]],
        );

        $resposta->assertStatus(404);
        $this->assertDatabaseCount('ml_anuncio_criativos', 0);
        Storage::disk('local')->assertDirectoryEmpty('creative-referencias');
    }

    // ═══ FOTO-01 / FOTO-02 — upload com a chave ligada ══════════════════

    public function test_upload_com_chave_ligada_devolve_201_e_grava_no_disco_privado(): void
    {
        $this->ligarChave();
        Storage::fake('local');
        Storage::fake('public');

        $company  = $this->companyConectada();
        $rascunho = $this->rascunho($company);

        $resposta = $this->actingAs($this->admin())->postJson(
            route('mlb.anuncios.criativo.referencia', ['rascunho' => $rascunho->id]),
            ['referencias' => [UploadedFile::fake()->image('gabinete.jpg')]],
        );

        $resposta->assertStatus(201)
            ->assertJsonPath('ok', true)
            ->assertJsonCount(1, 'criativo.referencias');

        $token = $resposta->json('criativo.token');
        $this->assertSame(32, strlen($token));

        $this->assertDatabaseCount('ml_anuncio_criativos', 1);
        $this->assertDatabaseHas('ml_anuncio_criativos', [
            'token'  => $token,
            'status' => 'pendente',
        ]);

        // Nenhum campo com base64/bytes no banco.
        $linha = DB::table('ml_anuncio_criativos')->where('token', $token)->first();
        foreach ((array) $linha as $valor) {
            if (is_string($valor)) {
                $this->assertDoesNotMatchRegularExpression('/^[A-Za-z0-9+\/]{200,}={0,2}$/', $valor);
            }
        }

        // Disco privado tem o arquivo; disco público não tem nada.
        Storage::disk('local')->assertExists("creative-referencias/{$token}/0.jpg");
        $this->assertCount(0, Storage::disk('public')->allFiles());
    }

    public function test_duas_fotos_no_mesmo_post_geram_duas_entradas_no_mesmo_criativo(): void
    {
        $this->ligarChave();
        Storage::fake('local');
        Storage::fake('public');

        $company  = $this->companyConectada();
        $rascunho = $this->rascunho($company);

        $resposta = $this->actingAs($this->admin())->postJson(
            route('mlb.anuncios.criativo.referencia', ['rascunho' => $rascunho->id]),
            ['referencias' => [
                UploadedFile::fake()->image('foto1.jpg'),
                UploadedFile::fake()->image('foto2.jpg'),
            ]],
        );

        $resposta->assertStatus(201)->assertJsonCount(2, 'criativo.referencias');
        $this->assertDatabaseCount('ml_anuncio_criativos', 1);

        $token = $resposta->json('criativo.token');
        Storage::disk('local')->assertExists("creative-referencias/{$token}/0.jpg");
        Storage::disk('local')->assertExists("creative-referencias/{$token}/1.jpg");
    }

    // ═══ FOTO-04 — validação de tipo e tamanho ══════════════════════════

    public function test_upload_recusa_arquivo_que_nao_e_imagem(): void
    {
        $this->ligarChave();
        Storage::fake('local');

        $company  = $this->companyConectada();
        $rascunho = $this->rascunho($company);

        $resposta = $this->actingAs($this->admin())->postJson(
            route('mlb.anuncios.criativo.referencia', ['rascunho' => $rascunho->id]),
            ['referencias' => [UploadedFile::fake()->create('nota.txt', 10, 'text/plain')]],
        );

        $resposta->assertStatus(422);
        $mensagens = collect($resposta->json('errors'))->flatten()->implode(' ');
        $this->assertMatchesRegularExpression('/imagem/i', $mensagens);
        $this->assertDatabaseCount('ml_anuncio_criativos', 0);
    }

    public function test_upload_recusa_imagem_acima_de_10mb(): void
    {
        $this->ligarChave();
        Storage::fake('local');

        $company  = $this->companyConectada();
        $rascunho = $this->rascunho($company);

        // 11 MB em kilobytes para o helper do fake.
        $arquivoGrande = UploadedFile::fake()->create('gigante.jpg', 11 * 1024, 'image/jpeg');

        $resposta = $this->actingAs($this->admin())->postJson(
            route('mlb.anuncios.criativo.referencia', ['rascunho' => $rascunho->id]),
            ['referencias' => [$arquivoGrande]],
        );

        $resposta->assertStatus(422);
        $mensagens = collect($resposta->json('errors'))->flatten()->implode(' ');
        $this->assertMatchesRegularExpression('/mb|tamanho/i', $mensagens);
        $this->assertDatabaseCount('ml_anuncio_criativos', 0);
    }

    // ═══ Autorização — role:admin (D-01) ════════════════════════════════

    public function test_usuario_nao_admin_recebe_403(): void
    {
        $this->ligarChave();
        Storage::fake('local');

        $company  = $this->companyConectada();
        $rascunho = $this->rascunho($company);

        $resposta = $this->actingAs($this->naoAdmin())->postJson(
            route('mlb.anuncios.criativo.referencia', ['rascunho' => $rascunho->id]),
            ['referencias' => [UploadedFile::fake()->image('gabinete.jpg')]],
        );

        $resposta->assertStatus(403);
        $this->assertDatabaseCount('ml_anuncio_criativos', 0);
    }

    // ═══ FOTO-05 — escopo por empresa no servidor ═══════════════════════

    public function test_publicador_fora_de_escopo_recebe_403_e_nada_e_gravado_em_disco(): void
    {
        $this->ligarChave();
        Storage::fake('local');

        $company        = $this->companyConectada();
        $outroUsuario   = User::factory()->create(['role' => 'consultor']);
        $rascunho       = $this->rascunho($company, $outroUsuario->id);

        // Publicador autenticado é ADMIN=false e diferente do responsavel_id da empresa do rascunho.
        $publicadorForaDeEscopo = $this->naoAdmin();

        $resposta = $this->actingAs($publicadorForaDeEscopo)->postJson(
            route('mlb.anuncios.criativo.referencia', ['rascunho' => $rascunho->id]),
            ['referencias' => [UploadedFile::fake()->image('gabinete.jpg')]],
        );

        $resposta->assertStatus(403);
        $this->assertDatabaseCount('ml_anuncio_criativos', 0);
        Storage::disk('local')->assertDirectoryEmpty('creative-referencias');
    }

    // ═══ FOTO-02 — leitura da referência por token ══════════════════════

    public function test_get_da_referencia_pelo_token_devolve_o_binario_para_quem_tem_escopo(): void
    {
        $this->ligarChave();
        Storage::fake('local');

        $company  = $this->companyConectada();
        $rascunho = $this->rascunho($company);

        $upload = $this->actingAs($this->admin())->postJson(
            route('mlb.anuncios.criativo.referencia', ['rascunho' => $rascunho->id]),
            ['referencias' => [UploadedFile::fake()->image('gabinete.jpg')]],
        );
        $token = $upload->json('criativo.token');

        $resposta = $this->actingAs($this->admin())->get(
            route('mlb.anuncios.criativo.referencia.ver', ['token' => $token, 'indice' => 0]),
        );

        $resposta->assertOk();
        $this->assertStringStartsWith('image/', $resposta->headers->get('Content-Type'));
    }

    public function test_get_com_token_inexistente_devolve_404(): void
    {
        $this->ligarChave();
        Storage::fake('local');

        $resposta = $this->actingAs($this->admin())->get(
            route('mlb.anuncios.criativo.referencia.ver', ['token' => str_repeat('a', 32), 'indice' => 0]),
        );

        $resposta->assertStatus(404);
    }
}
