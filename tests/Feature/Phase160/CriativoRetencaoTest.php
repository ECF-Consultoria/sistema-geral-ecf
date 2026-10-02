<?php

namespace Tests\Feature\Phase160;

use App\Models\Company;
use App\Models\Configuracao;
use App\Models\MlAnuncioCriativo;
use App\Models\MlAnuncioRascunho;
use App\Models\MlToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Retenção em DUAS camadas da foto de referência do Creative Engine (Fase
 * 160, Plano 04, FOTO-03): deleção na aprovação (`criativoAprovar()`) e
 * varredura diária (`creative:limpar-referencias`), SEMPRE por idade do
 * REGISTRO — nunca do arquivo.
 *
 * O caso `test_criativo_vivo_nao_e_tocado` é o MAIS IMPORTANTE deste
 * arquivo — é a prova direta contra o incidente já vivido neste projeto
 * (ECF Drive, 2026-09-14, ver cabeçalho do `160-04-PLAN.md`): lá a retenção
 * decidia por idade do ARQUIVO enquanto o consumo decidia pelo BANCO, e a
 * rotina apagou um arquivo que o sync ainda ia usar.
 *
 * Nenhum teste fala com o Mercado Livre nem com a Gemini de verdade:
 * `Http::preventStrayRequests()` + `Http::fake()` do upload ao ML.
 */
class CriativoRetencaoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Http::preventStrayRequests();
        config(['services.creative.retencao_referencias_horas' => 48]);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function company(string $nome = 'Empresa Teste'): Company
    {
        return Company::factory()->create(['name' => $nome]);
    }

    /** Empresa com token ML ativo — exigido só pelo caminho de aprovação (upload real ao ML). */
    private function companyConectada(string $nome = 'Empresa Conectada'): Company
    {
        $company = $this->company($nome);

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

    private function rascunho(Company $company): MlAnuncioRascunho
    {
        return MlAnuncioRascunho::create([
            'company_id'  => $company->id,
            'category_id' => 'MLB1574',
            'payload'     => [
                'title'       => 'Produto Teste',
                'category_id' => 'MLB1574',
                'description' => 'Descrição qualquer.',
                'attributes'  => [],
                'pictures'    => [],
            ],
            'status'  => MlAnuncioRascunho::STATUS_RASCUNHO,
            'user_id' => User::factory()->create()->id,
        ]);
    }

    /**
     * Cria um criativo com UMA referência GRAVADA DE VERDADE no disco fake
     * (mesma forma de `ReferenciaEfemeraService::guardar()`:
     * `creative-referencias/{token}/{indice}.{ext}`) e `created_at`
     * controlado (idade do REGISTRO é o que a varredura usa).
     */
    private function criativoComReferencia(
        MlAnuncioRascunho $rascunho,
        string $status,
        \DateTimeInterface $criadoEm,
    ): MlAnuncioCriativo {
        $token   = Str::random(32);
        $caminho = "creative-referencias/{$token}/0.jpg";

        Storage::disk('local')->put($caminho, 'bytes-da-foto-original');

        $criativo = MlAnuncioCriativo::create([
            'token'       => $token,
            'company_id'  => $rascunho->company_id,
            'rascunho_id' => $rascunho->id,
            'user_id'     => $rascunho->user_id,
            'slot'        => 'hero',
            'status'      => $status,
            'referencias' => [
                ['indice' => 0, 'path' => $caminho, 'mime' => 'image/jpeg', 'bytes' => 23, 'nome' => 'foto.jpg', 'hash' => 'abc123'],
            ],
        ]);

        // `created_at` é o que a trava e a varredura leem — forceFill para
        // simular idade sem depender de viajar no tempo com `Carbon::setTestNow`.
        $criativo->forceFill(['created_at' => $criadoEm])->save();

        return $criativo->fresh();
    }

    /** Fake do upload ao ML na forma MEDIDA (mesma de CriativoAprovacaoTest). */
    private function fakeUploadMl(): void
    {
        Http::fake([
            'api.mercadolibre.com/pictures/items/upload' => Http::response([
                'id'         => 'MLB123456-abc',
                'variations' => [
                    ['secure_url' => 'https://http2.mlstatic.com/D_123-O.jpg'],
                ],
            ], 200),
        ]);
    }

    // ═══ Deleção na aprovação ═══════════════════════════════════════════

    public function test_aprovacao_bem_sucedida_apaga_a_referencia_e_mantem_a_imagem_gerada(): void
    {
        Storage::fake('local');
        $this->fakeUploadMl();
        Configuracao::set('creative_engine_ativo', '1');

        $rascunho = $this->rascunho($this->companyConectada());
        $token    = Str::random(32);

        Storage::disk('local')->put("creative-referencias/{$token}/0.jpg", 'foto-original');
        Storage::disk('local')->put("creative-geradas/{$token}/hero.jpg", 'imagem-gerada');

        $criativo = MlAnuncioCriativo::create([
            'token'       => $token,
            'company_id'  => $rascunho->company_id,
            'rascunho_id' => $rascunho->id,
            'user_id'     => $rascunho->user_id,
            'slot'        => 'hero',
            'status'      => MlAnuncioCriativo::STATUS_PRONTO,
            'imagem_path' => "creative-geradas/{$token}/hero.jpg",
            'imagem_mime' => 'image/jpeg',
            'referencias' => [
                ['indice' => 0, 'path' => "creative-referencias/{$token}/0.jpg", 'mime' => 'image/jpeg', 'bytes' => 13, 'nome' => 'foto.jpg', 'hash' => 'x'],
            ],
        ]);

        $resposta = $this->actingAs($this->admin())->postJson(
            route('mlb.anuncios.criativo.aprovar', ['token' => $criativo->token]),
        );

        $resposta->assertOk();

        $criativo->refresh();
        $this->assertNotNull($criativo->referencias_apagadas_em);
        $this->assertSame([], $criativo->referenciasVivas());
        Storage::disk('local')->assertMissing("creative-referencias/{$token}/0.jpg");
        // A IMAGEM GERADA permanece — ela é peça do anúncio, não foto do cliente.
        Storage::disk('local')->assertExists("creative-geradas/{$token}/hero.jpg");

        // `criativoStatus()` devolve `referencias: []` sem erro.
        $status = $this->actingAs($this->admin())->getJson(
            route('mlb.anuncios.criativo.status', ['token' => $criativo->token]),
        );
        $status->assertOk()->assertJsonPath('referencias', []);
    }

    // ═══ Varredura — o caso mais importante do plano ═════════════════════

    public function test_criativo_vivo_nao_e_tocado(): void
    {
        Storage::fake('local');

        // Criado AGORA, `rodando`, com referência em disco — exatamente o
        // perfil de um job que ainda está no meio da execução. A varredura
        // NÃO pode apagar isto: é a amarra contra o incidente do ECF Drive.
        $rascunho = $this->rascunho($this->company());
        $criativo = $this->criativoComReferencia($rascunho, MlAnuncioCriativo::STATUS_RODANDO, now());

        $this->artisan('creative:limpar-referencias')
            ->expectsOutputToContain('0 registro(s)')
            ->assertExitCode(0);

        $criativo->refresh();
        $this->assertNull($criativo->referencias_apagadas_em);
        $this->assertSame(MlAnuncioCriativo::STATUS_RODANDO, $criativo->status);
        $this->assertNotEmpty($criativo->referenciasVivas());
        Storage::disk('local')->assertExists($criativo->referencias[0]['path']);
    }

    public function test_criativo_antigo_pronto_e_recolhido(): void
    {
        Storage::fake('local');

        $rascunho = $this->rascunho($this->company());
        $criativo = $this->criativoComReferencia(
            $rascunho,
            MlAnuncioCriativo::STATUS_PRONTO,
            now()->subHours(50),
        );

        $this->artisan('creative:limpar-referencias')
            ->expectsOutputToContain('1 registro(s)')
            ->assertExitCode(0);

        $criativo->refresh();
        $this->assertNotNull($criativo->referencias_apagadas_em);
        $this->assertSame(MlAnuncioCriativo::STATUS_PRONTO, $criativo->status);
        Storage::disk('local')->assertMissing($criativo->referencias[0]['path']);
    }

    public function test_criativo_antigo_pendente_e_encerrado_por_tempo_e_a_referencia_e_apagada(): void
    {
        Storage::fake('local');

        $rascunho = $this->rascunho($this->company());
        $criativo = $this->criativoComReferencia(
            $rascunho,
            MlAnuncioCriativo::STATUS_PENDENTE,
            now()->subHours(50),
        );

        $this->artisan('creative:limpar-referencias')->assertExitCode(0);

        $criativo->refresh();
        // encerrarSeTravada() — nada fica "pendente" para sempre.
        $this->assertSame(MlAnuncioCriativo::STATUS_ERRO, $criativo->status);
        $this->assertNotNull($criativo->finished_at);
        $this->assertNotNull($criativo->referencias_apagadas_em);
        Storage::disk('local')->assertMissing($criativo->referencias[0]['path']);
    }

    public function test_dry_run_nao_apaga_nada_e_reporta_a_mesma_contagem(): void
    {
        Storage::fake('local');

        $rascunho = $this->rascunho($this->company());
        $criativo = $this->criativoComReferencia(
            $rascunho,
            MlAnuncioCriativo::STATUS_PRONTO,
            now()->subHours(50),
        );

        $this->artisan('creative:limpar-referencias --dry-run')
            ->expectsOutputToContain('1 registro(s)')
            ->assertExitCode(0);

        $criativo->refresh();
        $this->assertNull($criativo->referencias_apagadas_em);
        $this->assertSame(MlAnuncioCriativo::STATUS_PRONTO, $criativo->status);
        Storage::disk('local')->assertExists($criativo->referencias[0]['path']);

        // A execução REAL, depois, relata a MESMA contagem de registros.
        $this->artisan('creative:limpar-referencias')
            ->expectsOutputToContain('1 registro(s)')
            ->assertExitCode(0);

        Storage::disk('local')->assertMissing($criativo->referencias[0]['path']);
    }

    public function test_rodar_duas_vezes_e_idempotente(): void
    {
        Storage::fake('local');

        $rascunho = $this->rascunho($this->company());
        $criativo = $this->criativoComReferencia(
            $rascunho,
            MlAnuncioCriativo::STATUS_PRONTO,
            now()->subHours(50),
        );

        $this->artisan('creative:limpar-referencias')
            ->expectsOutputToContain('1 registro(s)')
            ->assertExitCode(0);

        // Segunda passada: nada novo para recolher, não lança.
        $this->artisan('creative:limpar-referencias')
            ->expectsOutputToContain('0 registro(s)')
            ->assertExitCode(0);

        $criativo->refresh();
        $this->assertNotNull($criativo->referencias_apagadas_em);
    }

    public function test_arquivo_ja_ausente_com_registro_nao_marcado_conta_como_apagado(): void
    {
        Storage::fake('local');

        $rascunho = $this->rascunho($this->company());
        $criativo = $this->criativoComReferencia(
            $rascunho,
            MlAnuncioCriativo::STATUS_PRONTO,
            now()->subHours(50),
        );

        // O arquivo já não existe (removido por fora), mas o registro ainda
        // não foi marcado — a varredura deve marcar a coluna sem lançar.
        Storage::disk('local')->delete($criativo->referencias[0]['path']);

        $this->artisan('creative:limpar-referencias')
            ->expectsOutputToContain('1 registro(s)')
            ->assertExitCode(0);

        $criativo->refresh();
        $this->assertNotNull($criativo->referencias_apagadas_em);
    }

    // ═══ --orfaos — opt-in, desligado por padrão ═════════════════════════

    public function test_sem_orfaos_diretorio_sem_registro_na_tabela_nao_e_tocado(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('creative-referencias/token-fantasma/0.jpg', 'bytes');
        // Garante que o diretório é "antigo" o bastante para o teste de
        // --orfaos abaixo também valer, sem mudar o comportamento default.
        touch(Storage::disk('local')->path('creative-referencias/token-fantasma'), now()->subHours(50)->getTimestamp());

        $this->artisan('creative:limpar-referencias')->assertExitCode(0);

        Storage::disk('local')->assertExists('creative-referencias/token-fantasma/0.jpg');
    }

    public function test_com_orfaos_remove_so_diretorio_mais_antigo_que_a_janela(): void
    {
        Storage::fake('local');

        Storage::disk('local')->put('creative-referencias/fantasma-antigo/0.jpg', 'bytes');
        touch(Storage::disk('local')->path('creative-referencias/fantasma-antigo'), now()->subHours(50)->getTimestamp());

        Storage::disk('local')->put('creative-referencias/fantasma-recente/0.jpg', 'bytes');
        touch(Storage::disk('local')->path('creative-referencias/fantasma-recente'), now()->getTimestamp());

        $this->artisan('creative:limpar-referencias --orfaos')->assertExitCode(0);

        Storage::disk('local')->assertMissing('creative-referencias/fantasma-antigo/0.jpg');
        Storage::disk('local')->assertExists('creative-referencias/fantasma-recente/0.jpg');
    }
}
