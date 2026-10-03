<?php

namespace Tests\Feature\Phase160;

use App\Jobs\GerarCriativoIaJob;
use App\Models\Company;
use App\Models\Configuracao;
use App\Models\MlAnuncioCriativo;
use App\Models\MlAnuncioRascunho;
use App\Models\User;
use App\Services\Creative\ReferenciaEfemeraService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Endpoints de disparo/status/imagem do Creative Engine (Fase 160, Plano 02,
 * Task 3) — GEN-01 (202 antes de qualquer geração), GEN-06 (idempotência),
 * T-160-10 (whitelist da resposta de status) e OPS-03 (chave desligada).
 *
 * Regressão coberta aqui: `status=pendente` é o estado de REPOUSO logo após
 * o upload da referência (160-01) — tratá-lo como "já em andamento" fazia o
 * PRIMEIRO clique em "Gerar" nunca despachar o job. Pego por
 * `test_gerar_devolve_202_e_enfileira_na_fila_creative` nesta mesma task.
 *
 * `fila_creative` (não mais `high`): migração de fila decidida em 2026-10-02
 * (Fase 161, Plano 02) — ver docblock de `GerarCriativoIaJob`.
 */
class CriativoGeracaoEndpointsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Http::preventStrayRequests();
        Configuracao::set('creative_engine_ativo', '1');
    }

    private function criativo(): MlAnuncioCriativo
    {
        Storage::fake('local');
        $company = Company::factory()->create(['name' => 'Empresa Teste']);
        $rascunho = MlAnuncioRascunho::create([
            'company_id' => $company->id,
            'category_id' => 'MLB1574',
            'payload' => ['title' => 'Produto', 'attributes' => []],
            'status' => MlAnuncioRascunho::STATUS_RASCUNHO,
            'user_id' => User::factory()->create()->id,
        ]);
        $criativo = MlAnuncioCriativo::create([
            'token' => Str::random(32),
            'company_id' => $company->id,
            'rascunho_id' => $rascunho->id,
            'user_id' => $rascunho->user_id,
            'slot' => 'hero',
            'status' => MlAnuncioCriativo::STATUS_PENDENTE,
        ]);
        $refs = app(ReferenciaEfemeraService::class)->guardar($criativo, [UploadedFile::fake()->image('a.jpg')]);
        $criativo->update(['referencias' => $refs]);

        return $criativo->fresh();
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    public function test_gerar_devolve_202_e_enfileira_na_fila_creative(): void
    {
        Queue::fake();
        $criativo = $this->criativo();

        $resp = $this->actingAs($this->admin())->postJson(route('mlb.anuncios.criativo.gerar', ['token' => $criativo->token]));

        $resp->assertStatus(202);
        Queue::assertPushedOn('creative', GerarCriativoIaJob::class);
        $this->assertSame(MlAnuncioCriativo::STATUS_PENDENTE, $criativo->fresh()->status);
    }

    public function test_gerar_duplo_clique_nao_enfileira_duas_vezes(): void
    {
        Queue::fake();
        $criativo = $this->criativo();
        $criativo->update(['status' => MlAnuncioCriativo::STATUS_RODANDO]);

        $resp = $this->actingAs($this->admin())->postJson(route('mlb.anuncios.criativo.gerar', ['token' => $criativo->token]));

        $resp->assertStatus(202);
        Queue::assertNotPushed(GerarCriativoIaJob::class);
    }

    public function test_gerar_recusa_quando_ja_aprovado(): void
    {
        Queue::fake();
        $criativo = $this->criativo();
        $criativo->update(['status' => MlAnuncioCriativo::STATUS_APROVADO]);

        $resp = $this->actingAs($this->admin())->postJson(route('mlb.anuncios.criativo.gerar', ['token' => $criativo->token]));

        $resp->assertStatus(422);
        Queue::assertNotPushed(GerarCriativoIaJob::class);
    }

    public function test_status_devolve_campos_da_whitelist_sem_prompt_nem_contexto(): void
    {
        $criativo = $this->criativo();
        $criativo->update(['prompt' => 'segredo', 'contexto' => ['x' => 1], 'truth' => ['y' => 2]]);

        $resp = $this->actingAs($this->admin())->getJson(route('mlb.anuncios.criativo.status', ['token' => $criativo->token]));

        $resp->assertOk();
        $resp->assertJsonMissingPath('prompt');
        $resp->assertJsonMissingPath('contexto');
        $resp->assertJsonMissingPath('truth');
        $this->assertStringNotContainsString('segredo', $resp->getContent());
    }

    public function test_imagem_404_quando_ainda_nao_gerada(): void
    {
        $criativo = $this->criativo();

        $resp = $this->actingAs($this->admin())->get(route('mlb.anuncios.criativo.imagem', ['token' => $criativo->token]));

        $resp->assertStatus(404);
    }

    public function test_imagem_devolve_binario_quando_pronta(): void
    {
        Storage::fake('local');
        $criativo = $this->criativo();
        Storage::disk('local')->put("creative-geradas/{$criativo->token}/hero.jpg", 'bytes-da-imagem');
        $criativo->update(['imagem_path' => "creative-geradas/{$criativo->token}/hero.jpg", 'imagem_mime' => 'image/jpeg']);

        $resp = $this->actingAs($this->admin())->get(route('mlb.anuncios.criativo.imagem', ['token' => $criativo->token]));

        $resp->assertOk();
        $this->assertSame('bytes-da-imagem', $resp->getContent());
    }

    public function test_chave_desligada_devolve_404_em_todos_os_tres(): void
    {
        Configuracao::set('creative_engine_ativo', '0');
        $criativo = $this->criativo();

        $this->actingAs($this->admin())->postJson(route('mlb.anuncios.criativo.gerar', ['token' => $criativo->token]))->assertStatus(404);
        $this->actingAs($this->admin())->getJson(route('mlb.anuncios.criativo.status', ['token' => $criativo->token]))->assertStatus(404);
        $this->actingAs($this->admin())->get(route('mlb.anuncios.criativo.imagem', ['token' => $criativo->token]))->assertStatus(404);
    }

    public function test_usuario_fora_de_escopo_recebe_403(): void
    {
        $criativo = $this->criativo();
        $outro = User::factory()->create(['role' => 'consultor']);

        $this->actingAs($outro)->postJson(route('mlb.anuncios.criativo.gerar', ['token' => $criativo->token]))->assertStatus(403);
    }
}
