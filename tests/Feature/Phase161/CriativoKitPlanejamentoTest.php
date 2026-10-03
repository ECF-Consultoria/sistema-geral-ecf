<?php

namespace Tests\Feature\Phase161;

use App\Jobs\PlanejarKitCriativosJob;
use App\Models\Company;
use App\Models\Configuracao;
use App\Models\MlAnuncioCriativo;
use App\Models\MlAnuncioCriativoKit;
use App\Models\MlAnuncioRascunho;
use App\Models\User;
use App\Services\Creative\Contracts\ImageGenerationProvider;
use App\Services\Creative\Dto\CreativeGenerationRequest;
use App\Services\Creative\Dto\CreativeGenerationResult;
use App\Services\Creative\ReferenciaEfemeraService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Planejamento do kit de 7 (Fase 161, Plano 01, Task 3) — PLAN-01/02/03/04,
 * OPS-04, GEN-06 no nível do kit e a prova do lock sob corrida (apontada
 * pelo gsd-plan-checker em 2026-10-02).
 *
 * `QUEUE_CONNECTION=sync` no ambiente de teste (`phpunit.xml`): chamar a
 * rota SEM `Queue::fake()` executa `PlanejarKitCriativosJob::handle()` de
 * verdade, na mesma request — é assim que os testes 2/3/7/8 verificam o
 * resultado do job sem precisar instanciá-lo à mão.
 *
 * `Http::preventStrayRequests()` garante que nenhum teste aqui chama a API
 * real do Gemini — o dublê de `ImageGenerationProvider` registrado em
 * `setUp()` responde `gerarTexto()` com um JSON fixo.
 */
class CriativoKitPlanejamentoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Http::preventStrayRequests();
        Configuracao::set('creative_engine_ativo', '1');

        $this->app->instance(ImageGenerationProvider::class, new class implements ImageGenerationProvider
        {
            public function gerarImagem(CreativeGenerationRequest $request): CreativeGenerationResult
            {
                throw new \RuntimeException('gerarImagem() não é usado pelo planejamento do kit (só 161-02).');
            }

            public function gerarTexto(string $prompt): string
            {
                return json_encode([
                    'estrategia' => ['publico' => 'quem compra o produto', 'proposta_de_valor' => 'fiel ao cadastro', 'direcao_visual' => 'fundo neutro'],
                    'slots' => [
                        ['tipo' => 'hero', 'objetivo' => 'capa', 'cena' => 'fundo branco'],
                        ['tipo' => 'white_background', 'objetivo' => 'ângulo 2', 'cena' => 'fundo branco'],
                    ],
                ]);
            }
        });
    }

    private function criativo(array $attrs = []): MlAnuncioCriativo
    {
        Storage::fake('local');

        $company = Company::factory()->create(['name' => 'Empresa Teste']);
        $rascunho = MlAnuncioRascunho::create([
            'company_id'  => $company->id,
            'category_id' => 'MLB1574',
            'payload'     => ['title' => 'Produto de teste', 'attributes' => $attrs],
            'status'      => MlAnuncioRascunho::STATUS_RASCUNHO,
            'user_id'     => User::factory()->create()->id,
        ]);
        $criativo = MlAnuncioCriativo::create([
            'token'       => Str::random(32),
            'company_id'  => $company->id,
            'rascunho_id' => $rascunho->id,
            'user_id'     => $rascunho->user_id,
            'slot'        => 'hero',
            'status'      => MlAnuncioCriativo::STATUS_PENDENTE,
        ]);
        $refs = app(ReferenciaEfemeraService::class)->guardar($criativo, [UploadedFile::fake()->image('a.jpg')]);
        $criativo->update(['referencias' => $refs]);

        return $criativo->fresh();
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function naoAdmin(): User
    {
        return User::factory()->create(['role' => 'consultor']);
    }

    // ═══ GEN-01 — 202 antes de qualquer trabalho, job na fila `creative` ═══

    public function test_planejar_devolve_202_e_enfileira_na_fila_creative(): void
    {
        Queue::fake();
        $criativo = $this->criativo();

        $resp = $this->actingAs($this->admin())->postJson(
            route('mlb.anuncios.criativo.kit.planejar', ['token' => $criativo->token])
        );

        $resp->assertStatus(202);
        $resp->assertJsonStructure(['kit_token', 'status']);
        Queue::assertPushedOn('creative', PlanejarKitCriativosJob::class);

        $kit = MlAnuncioCriativoKit::where('rascunho_id', $criativo->rascunho_id)->first();
        $this->assertNotNull($kit);
        $this->assertSame(MlAnuncioCriativoKit::STATUS_PLANEJANDO, $kit->status);
        $this->assertSame($criativo->id, $kit->criativo_referencia_id);
    }

    // ═══ O job cria 1 kit + 7 criativos; o portador vira 'referencia' ═══

    public function test_job_cria_kit_com_7_slots_e_portador_vira_referencia(): void
    {
        $criativo = $this->criativo(['MATERIAL' => 'MDF']);

        $resp = $this->actingAs($this->admin())->postJson(
            route('mlb.anuncios.criativo.kit.planejar', ['token' => $criativo->token])
        );
        $resp->assertStatus(202);

        $kit = MlAnuncioCriativoKit::where('rascunho_id', $criativo->rascunho_id)->first();
        $kit->refresh();
        $criativo->refresh();

        $this->assertSame(MlAnuncioCriativoKit::STATUS_PLANEJADO, $kit->status);
        $this->assertSame(7, $kit->total_slots);
        $this->assertSame(3, $kit->minimo_aprovadas);
        $this->assertNotEmpty($kit->plano);
        $this->assertContains($kit->plano_origem, ['llm', 'deterministico']);

        $slots = MlAnuncioCriativo::where('kit_id', $kit->id)
            ->whereNotNull('slot_indice')
            ->orderBy('slot_indice')
            ->get();

        $this->assertCount(7, $slots);
        $this->assertSame(range(1, 7), $slots->pluck('slot_indice')->all());
        $this->assertSame('hero', $slots->first()->slot);
        $this->assertTrue($slots->every(fn ($s) => $s->status === MlAnuncioCriativo::STATUS_PENDENTE));
        $this->assertTrue($slots->every(fn ($s) => $s->slot_plano !== null));

        // O portador (o próprio $criativo do upload) NUNCA é reciclado como
        // um dos 7 slots (Decisão 1b) — ganha kit_id, slot='referencia',
        // slot_indice continua nulo.
        $this->assertSame($kit->id, $criativo->kit_id);
        $this->assertSame('referencia', $criativo->slot);
        $this->assertNull($criativo->slot_indice);
    }

    // ═══ T-161-05 — whitelist da resposta de status ═════════════════════

    public function test_status_devolve_whitelist_sem_prompt_contexto_truth_plano_cru_ou_imagem_path(): void
    {
        $criativo = $this->criativo();
        $this->actingAs($this->admin())->postJson(
            route('mlb.anuncios.criativo.kit.planejar', ['token' => $criativo->token])
        )->assertStatus(202);

        $kit = MlAnuncioCriativoKit::where('rascunho_id', $criativo->rascunho_id)->first();

        $resp = $this->actingAs($this->admin())->getJson(
            route('mlb.anuncios.criativo.kit.status', ['kit' => $kit->token])
        );

        $resp->assertOk();
        $resp->assertJsonStructure([
            'kit_token', 'status', 'etapa', 'em_andamento', 'estrategia', 'minimo_aprovadas',
            'referencias', 'slots' => [['indice', 'tipo', 'rotulo', 'objetivo', 'status', 'token']],
        ]);
        $resp->assertJsonMissingPath('prompt');
        $resp->assertJsonMissingPath('contexto');
        $resp->assertJsonMissingPath('truth');
        $resp->assertJsonMissingPath('plano');
        $resp->assertJsonMissingPath('imagem_path');

        $corpo = $resp->getContent();
        $this->assertStringNotContainsString('claims_proibidas', $corpo);
        $this->assertStringNotContainsString('fatos_usados', $corpo);
    }

    // ═══ OPS-03 — chave desligada, 404 puro, sem tocar em banco ═════════

    public function test_chave_desligada_devolve_404_nas_duas_rotas_sem_tocar_em_banco(): void
    {
        Configuracao::set('creative_engine_ativo', '0');
        $criativo = $this->criativo();

        $this->actingAs($this->admin())
            ->postJson(route('mlb.anuncios.criativo.kit.planejar', ['token' => $criativo->token]))
            ->assertStatus(404);

        $this->actingAs($this->admin())
            ->getJson(route('mlb.anuncios.criativo.kit.status', ['kit' => Str::random(32)]))
            ->assertStatus(404);

        $this->assertSame(0, MlAnuncioCriativoKit::count());
    }

    // ═══ OPS-04 — permissão explícita, além do role:admin do grupo ══════

    public function test_usuario_sem_permissao_recebe_403_e_nenhum_kit_e_criado(): void
    {
        $criativo = $this->criativo();

        $resp = $this->actingAs($this->naoAdmin())->postJson(
            route('mlb.anuncios.criativo.kit.planejar', ['token' => $criativo->token])
        );

        $resp->assertStatus(403);
        $this->assertSame(0, MlAnuncioCriativoKit::count());
    }

    // ═══ GEN-06 no nível do kit — duplo clique não cria dois kits ══════

    public function test_duplo_clique_devolve_o_mesmo_kit_e_nao_enfileira_outro_job(): void
    {
        Queue::fake();
        $criativo = $this->criativo();

        $primeira = $this->actingAs($this->admin())->postJson(
            route('mlb.anuncios.criativo.kit.planejar', ['token' => $criativo->token])
        );
        $segunda = $this->actingAs($this->admin())->postJson(
            route('mlb.anuncios.criativo.kit.planejar', ['token' => $criativo->token])
        );

        $primeira->assertStatus(202);
        $segunda->assertStatus(202);
        $this->assertSame($primeira->json('kit_token'), $segunda->json('kit_token'));

        $this->assertSame(1, MlAnuncioCriativoKit::where('rascunho_id', $criativo->rascunho_id)->count());
        Queue::assertPushed(PlanejarKitCriativosJob::class, 1);
    }

    // ═══ Sem referência viva — 422 em pt-BR, sem criar kit ══════════════

    public function test_sem_referencia_viva_devolve_422_e_nao_cria_kit(): void
    {
        $criativo = $this->criativo();
        $criativo->update(['referencias_apagadas_em' => now()]);

        $resp = $this->actingAs($this->admin())->postJson(
            route('mlb.anuncios.criativo.kit.planejar', ['token' => $criativo->token])
        );

        $resp->assertStatus(422);
        $this->assertSame(0, MlAnuncioCriativoKit::count());
    }

    // ═══ T-161-08 — kit travado é encerrado, polling para ═══════════════

    public function test_kit_travado_e_encerrado_como_erro_e_o_polling_para(): void
    {
        $criativo = $this->criativo();
        $kit = MlAnuncioCriativoKit::create([
            'token'                  => Str::random(32),
            'company_id'             => $criativo->company_id,
            'rascunho_id'            => $criativo->rascunho_id,
            'user_id'                => $criativo->user_id,
            'criativo_referencia_id' => $criativo->id,
            'status'                 => MlAnuncioCriativoKit::STATUS_PLANEJANDO,
        ]);
        $kit->forceFill(['created_at' => now()->subMinutes(30)])->save();

        $resp = $this->actingAs($this->admin())->getJson(
            route('mlb.anuncios.criativo.kit.status', ['kit' => $kit->token])
        );

        $resp->assertOk();
        $resp->assertJsonPath('status', MlAnuncioCriativoKit::STATUS_ERRO);
        $resp->assertJsonPath('em_andamento', false);
        $this->assertNotEmpty($resp->json('erro'));
    }

    // ═══ A prova do lock — check-then-act sob corrida real ══════════════

    /**
     * ⚠️ Este é O teste do ajuste crítico (gsd-plan-checker, 2026-10-02):
     * duas chamadas HTTP SEQUENCIAIS no mesmo processo passam mesmo com o
     * bug presente (nenhuma delas vê o kit da outra a tempo). Por isso o
     * teste segura o lock da chave DIRETAMENTE — simulando a 2ª requisição
     * chegando enquanto a 1ª ainda está nos passos (5)-(7) — e prova que a
     * tentativa concorrente devolve o kit já existente em vez de inserir
     * uma segunda linha.
     */
    public function test_lock_impede_dois_kits_para_o_mesmo_rascunho_sob_corrida(): void
    {
        $criativo = $this->criativo();

        $kitExistente = MlAnuncioCriativoKit::create([
            'token'                  => Str::random(32),
            'company_id'             => $criativo->company_id,
            'rascunho_id'            => $criativo->rascunho_id,
            'user_id'                => $criativo->user_id,
            'criativo_referencia_id' => $criativo->id,
            'status'                 => MlAnuncioCriativoKit::STATUS_PLANEJANDO,
        ]);

        $lock = Cache::lock("criativo-kit-planejar:{$criativo->rascunho_id}", 5);
        $this->assertTrue($lock->get(), 'Pré-condição: o teste precisa conseguir segurar o lock.');

        try {
            $resp = $this->actingAs($this->admin())->postJson(
                route('mlb.anuncios.criativo.kit.planejar', ['token' => $criativo->token])
            );

            $resp->assertStatus(202);
            $this->assertSame($kitExistente->token, $resp->json('kit_token'));
        } finally {
            $lock->release();
        }

        $this->assertSame(1, MlAnuncioCriativoKit::where('rascunho_id', $criativo->rascunho_id)->count());
    }
}
