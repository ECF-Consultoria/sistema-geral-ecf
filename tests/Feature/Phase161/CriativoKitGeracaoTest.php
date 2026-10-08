<?php

namespace Tests\Feature\Phase161;

use App\Jobs\GerarCriativoIaJob;
use App\Models\Company;
use App\Models\Configuracao;
use App\Models\MlAnuncioCriativo;
use App\Models\MlAnuncioCriativoKit;
use App\Models\MlAnuncioRascunho;
use App\Models\User;
use App\Services\Creative\Contracts\ImageGenerationProvider;
use App\Services\Creative\CreativeContextBuilder;
use App\Services\Creative\CreativeIdentidadeService;
use App\Services\Creative\CreativeKitDespachante;
use App\Services\Creative\CreativePromptBuilder;
use App\Services\Creative\ProductTruthBuilder;
use App\Services\Creative\ReferenciaEfemeraService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * `CreativeKitDespachante` + `GerarCriativoIaJob` adaptado ao slot (Fase 161,
 * Plano 02, Task 2) — a unicidade por CRIATIVO (não mais por rascunho) e o
 * despacho em ondas com teto de custo.
 *
 * NENHUM teste aqui chama a API real da Gemini: `Http::preventStrayRequests()`
 * garante isso (mesma disciplina de `tests/Feature/Phase160/CriativoGeracaoTest.php`).
 */
class CriativoKitGeracaoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Http::preventStrayRequests();
        Configuracao::set('creative_engine_ativo', '1');

        config([
            'services.creative.gemini.base_url'       => 'https://gemini.teste/v1beta',
            'services.creative.gemini.key'             => 'chave-de-teste',
            'services.creative.gemini.text_model'      => 'modelo-texto-teste',
            'services.creative.gemini.image_model'     => 'modelo-imagem-teste',
            'services.creative.gemini.image_fallbacks' => '',
            'services.creative.gemini.text_fallbacks'  => '',
            'services.creative.gemini.aspect_ratio'    => '1:1',
            'services.creative.gemini.image_size'      => '2K',
            'services.creative.gemini.mime'            => 'image/jpeg',
            'services.creative.gemini.timeout'         => 30,
            'services.creative.gemini.connect_timeout' => 10,
            'services.creative.kit.paralelo'           => 3,
            'services.creative.kit.intervalo_s'        => 15,
            'services.creative.kit.max_imagens'        => 14,
        ]);
    }

    /** Forma MEDIDA da Interactions API — ver docblock de GeminiImageProvider. */
    private function respostaImagemOk(string $modelo = 'modelo-imagem-teste'): array
    {
        return [
            'status' => 'completed',
            'model'  => $modelo,
            'steps'  => [
                ['type' => 'thought', 'signature' => 'abc123'],
                ['type' => 'model_output', 'content' => [[
                    'type'      => 'image',
                    'mime_type' => 'image/jpeg',
                    'data'      => base64_encode('bytes-falsos-da-imagem-gerada'),
                ]]],
            ],
        ];
    }

    /**
     * Monta um kit com portador (fotos de referência) + 7 slots `pendente`,
     * cada um com `slot_plano` preenchido — o portador NUNCA tem fotos
     * próprias nos slots (Decisão 1b do 161-01), só ele mesmo.
     *
     * @return array{0: MlAnuncioCriativoKit, 1: MlAnuncioCriativo}
     */
    private function kitComPortadorE7Slots(): array
    {
        Storage::fake('local');

        $company  = Company::factory()->create(['name' => 'Empresa Teste']);
        $rascunho = MlAnuncioRascunho::create([
            'company_id'  => $company->id,
            'category_id' => 'MLB1574',
            'payload'     => [
                'title'       => 'Gabinete de cozinha',
                'category_id' => 'MLB1574',
                'description' => 'Descrição qualquer.',
                'attributes'  => [['id' => 'MATERIAL', 'value_name' => 'MDF']],
            ],
            'status'  => MlAnuncioRascunho::STATUS_RASCUNHO,
            'user_id' => User::factory()->create()->id,
        ]);

        $portador = MlAnuncioCriativo::create([
            'token'       => Str::random(32),
            'company_id'  => $company->id,
            'rascunho_id' => $rascunho->id,
            'user_id'     => $rascunho->user_id,
            'slot'        => 'referencia',
            'status'      => MlAnuncioCriativo::STATUS_PRONTO,
        ]);
        $refs = app(ReferenciaEfemeraService::class)->guardar($portador, [UploadedFile::fake()->image('gabinete.jpg')]);
        $portador->update(['referencias' => $refs]);

        $kit = MlAnuncioCriativoKit::create([
            'token'                  => Str::random(32),
            'company_id'             => $company->id,
            'rascunho_id'            => $rascunho->id,
            'user_id'                => $rascunho->user_id,
            'criativo_referencia_id' => $portador->id,
            'status'                 => MlAnuncioCriativoKit::STATUS_PLANEJADO,
            'total_slots'            => 7,
            'minimo_aprovadas'       => 3,
        ]);
        $portador->update(['kit_id' => $kit->id]);

        $tipos = ['hero', 'white_background', 'angles', 'detail', 'lifestyle', 'benefits', 'specifications'];
        foreach ($tipos as $i => $tipo) {
            MlAnuncioCriativo::create([
                'token'       => Str::random(32),
                'company_id'  => $company->id,
                'rascunho_id' => $rascunho->id,
                'user_id'     => $rascunho->user_id,
                'kit_id'      => $kit->id,
                'slot'        => $tipo,
                'slot_indice' => $i + 1,
                'slot_plano'  => [
                    'indice'       => $i + 1,
                    'tipo'         => $tipo,
                    'objetivo'     => "Objetivo do slot {$tipo}.",
                    'cena'         => "Cena do slot {$tipo}.",
                    'headline'     => null,
                    'badges'       => [],
                    'fatos_usados' => [],
                    'proibicoes'   => [],
                ],
                'status' => MlAnuncioCriativo::STATUS_PENDENTE,
            ]);
        }

        return [$kit->fresh(), $portador->fresh()];
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function naoAdmin(): User
    {
        return User::factory()->create(['role' => 'consultor']);
    }

    private function rodar(MlAnuncioCriativo $criativo): void
    {
        (new GerarCriativoIaJob($criativo->id))->handle(
            app(ImageGenerationProvider::class),
            app(CreativeContextBuilder::class),
            app(ProductTruthBuilder::class),
            app(CreativePromptBuilder::class),
            app(CreativeIdentidadeService::class),
        );
    }

    // ═══ A ARMADILHA — os 7 do mesmo rascunho não colapsam em 1 ════════

    public function test_os_7_slots_do_mesmo_rascunho_nao_colapsam_em_1_job(): void
    {
        Queue::fake();
        [$kit] = $this->kitComPortadorE7Slots();

        app(CreativeKitDespachante::class)->despachar($kit);

        // Se a unicidade ainda fosse por rascunho, só 1 destes 7 sobreviveria.
        Queue::assertPushedOn('creative', GerarCriativoIaJob::class);
        Queue::assertPushed(GerarCriativoIaJob::class, 7);
    }

    // ═══ Ondas — delays escalonados por paralelo/intervalo_s ════════════

    public function test_despacho_sai_em_3_ondas_com_paralelo_3_e_intervalo_15(): void
    {
        Queue::fake();
        [$kit] = $this->kitComPortadorE7Slots();

        app(CreativeKitDespachante::class)->despachar($kit);

        $slots = $kit->slots()->orderBy('slot_indice')->get();
        // índices 1-3: onda 0 (sem delay); 4-6: onda 1 (+15s); 7: onda 2 (+30s).
        $ondaEsperada = [0 => 0, 1 => 0, 2 => 0, 3 => 15, 4 => 15, 5 => 15, 6 => 30];

        foreach ($slots as $i => $slot) {
            $segundosEsperados = $ondaEsperada[$i];

            Queue::assertPushed(GerarCriativoIaJob::class, function (GerarCriativoIaJob $job) use ($slot, $segundosEsperados) {
                if ($job->criativoId !== $slot->id) {
                    return false;
                }

                $delaySegundos = $job->delay !== null
                    ? $job->delay->getTimestamp() - now()->getTimestamp()
                    : 0;

                return abs($delaySegundos - $segundosEsperados) <= 2;
            });
        }
    }

    // ═══ Idempotência — ignora rodando/pronto/aprovado ══════════════════

    public function test_despachante_ignora_slots_rodando_pronto_ou_aprovado(): void
    {
        Queue::fake();
        [$kit] = $this->kitComPortadorE7Slots();

        $slots = $kit->slots()->orderBy('slot_indice')->get();
        $slots[0]->update(['status' => MlAnuncioCriativo::STATUS_RODANDO]);
        $slots[1]->update(['status' => MlAnuncioCriativo::STATUS_PRONTO]);
        $slots[2]->update(['status' => MlAnuncioCriativo::STATUS_APROVADO]);

        $resultado = app(CreativeKitDespachante::class)->despachar($kit);

        $this->assertSame(4, $resultado['enfileirados']);
        $this->assertSame(3, $resultado['ignorados']);
        Queue::assertPushed(GerarCriativoIaJob::class, 4);
    }

    // ═══ Decisão 6 — teto de custo recusa a onda inteira ═══════════════

    public function test_despachante_recusa_a_onda_quando_teto_de_imagens_atingido(): void
    {
        Queue::fake();
        [$kit] = $this->kitComPortadorE7Slots();
        $kit->update(['imagens_geradas' => 14]);

        $resultado = app(CreativeKitDespachante::class)->despachar($kit);

        $this->assertSame(0, $resultado['enfileirados']);
        $this->assertNotEmpty($resultado['motivo']);
        Queue::assertNothingPushed();
    }

    // ═══ Execução de um slot — grava imagem, conta, recalcula o kit ════

    public function test_handle_de_um_slot_grava_imagem_incrementa_contador_e_recalcula_status_do_kit(): void
    {
        Http::fake(['gemini.teste/*' => Http::response($this->respostaImagemOk())]);
        [$kit] = $this->kitComPortadorE7Slots();

        $slot = $kit->slots()->where('slot', 'benefits')->first();

        // Prova de que o slot NÃO tem fotos próprias — se a geração funcionar,
        // é porque leu do portador (ver teste abaixo, mais explícito).
        $this->assertEmpty($slot->referenciasVivas());

        $this->rodar($slot);

        $slot->refresh();
        $kit->refresh();

        $this->assertSame(MlAnuncioCriativo::STATUS_PRONTO, $slot->status);
        $this->assertSame('modelo-imagem-teste', $slot->modelo);
        $this->assertStringContainsString("creative-geradas/{$slot->token}/benefits.jpg", $slot->imagem_path);
        Storage::disk('local')->assertExists($slot->imagem_path);

        $this->assertSame(1, $kit->imagens_geradas);
        // 1 pronto + 6 pendentes ainda em curso -> o kit continua "gerando".
        $this->assertSame(MlAnuncioCriativoKit::STATUS_GERANDO, $kit->status);
    }

    // ═══ O prompt do slot usa paraSlot(), não paraSlotHero() ════════════

    public function test_prompt_do_slot_benefits_escreve_badge_exata_e_nao_contem_claim_fixo_de_sem_texto(): void
    {
        Http::fake(['gemini.teste/*' => Http::response($this->respostaImagemOk())]);
        [$kit] = $this->kitComPortadorE7Slots();

        $slot = $kit->slots()->where('slot', 'benefits')->first();
        $slot->update(['slot_plano' => array_merge($slot->slot_plano, [
            'headline' => 'Feito em MDF resistente',
            'badges'   => ['Feito em MDF'],
        ])]);

        $this->rodar($slot);
        $slot->refresh();

        $this->assertStringContainsString('Feito em MDF', $slot->prompt);
        $this->assertStringContainsString('escreva EXATAMENTE', $slot->prompt);
        $this->assertStringNotContainsString('Não escreva texto na imagem.', $slot->prompt);
    }

    // ═══ Todos os 7 leem a MESMA foto do portador ═══════════════════════

    public function test_todos_os_slots_leem_as_mesmas_fotos_do_portador(): void
    {
        Http::fake(['gemini.teste/*' => Http::response($this->respostaImagemOk())]);
        [$kit, $portador] = $this->kitComPortadorE7Slots();

        $slots = $kit->slots()->orderBy('slot_indice')->get();

        foreach ($slots as $slot) {
            $this->assertEmpty($slot->referenciasVivas(), "slot {$slot->slot} não deveria ter fotos próprias");
            $this->rodar($slot);
            $slot->refresh();
            $this->assertSame(MlAnuncioCriativo::STATUS_PRONTO, $slot->status, "slot {$slot->slot} deveria ter gerado com sucesso lendo do portador");
        }

        $this->assertNotEmpty($portador->referenciasVivas());
    }

    // ═══ Falha de um slot não trava os outros — kit termina parcial ════

    public function test_um_slot_com_falha_nao_impede_os_outros_e_o_kit_termina_parcial(): void
    {
        [$kit] = $this->kitComPortadorE7Slots();
        $slots = $kit->slots()->orderBy('slot_indice')->get();
        $slotComFalha = $slots->first();
        $outros = $slots->skip(1);

        // UM único Http::fake(): registrar dois (503 depois sucesso) faria o
        // PRIMEIRO stub continuar casando para as chamadas seguintes — o
        // matching de `Http::fake()` é por ORDEM DE REGISTRO, não substitui
        // o anterior. Um contador dentro do mesmo fake modela a ordem real
        // de execução: a 1ª chamada (o slot que falha) recebe 503; as
        // seguintes (os outros 6) recebem sucesso.
        $chamadas = 0;
        Http::fake(function () use (&$chamadas) {
            $chamadas++;

            return $chamadas === 1
                ? Http::response(['error' => 'overloaded'], 503)
                : Http::response($this->respostaImagemOk());
        });

        $job = new GerarCriativoIaJob($slotComFalha->id);
        try {
            $job->handle(
                app(ImageGenerationProvider::class),
                app(CreativeContextBuilder::class),
                app(ProductTruthBuilder::class),
                app(CreativePromptBuilder::class),
                app(CreativeIdentidadeService::class),
            );
            $this->fail('Deveria ter lançado RuntimeException (503 em todos os modelos).');
        } catch (\RuntimeException $e) {
            $job->failed($e);
        }

        foreach ($outros as $slot) {
            $this->rodar($slot);
        }

        $kit->refresh();

        $this->assertSame(MlAnuncioCriativo::STATUS_ERRO, $slotComFalha->fresh()->status);
        $this->assertSame(6, $kit->slots()->where('status', MlAnuncioCriativo::STATUS_PRONTO)->count());
        $this->assertSame(MlAnuncioCriativoKit::STATUS_PARCIAL, $kit->status);
    }

    // ═══ Decisão 6 — job recusa chamar o provedor quando o teto já bateu ═

    public function test_job_encerra_com_erro_sem_chamar_o_provedor_quando_teto_ja_atingido(): void
    {
        Http::preventStrayRequests(); // qualquer chamada HTTP aqui estoura

        [$kit] = $this->kitComPortadorE7Slots();
        $kit->update(['imagens_geradas' => 14]);
        $slot = $kit->slots()->where('slot', 'hero')->first();

        $this->rodar($slot);
        $slot->refresh();

        $this->assertSame(MlAnuncioCriativo::STATUS_ERRO, $slot->status);
        $this->assertNotEmpty($slot->erro_mensagem);
        Http::assertNothingSent();
    }

    // ═══ GEN-02/06 — fila creative e unicidade por CRIATIVO ═════════════

    public function test_job_e_despachado_na_fila_creative(): void
    {
        Queue::fake();
        [$kit] = $this->kitComPortadorE7Slots();
        $slot = $kit->slots()->first();

        GerarCriativoIaJob::dispatch($slot->id);

        Queue::assertPushedOn('creative', GerarCriativoIaJob::class);
    }

    public function test_dois_dispatches_do_mesmo_criativo_geram_um_unico_job(): void
    {
        Queue::fake();
        [$kit] = $this->kitComPortadorE7Slots();
        $slot = $kit->slots()->first();

        GerarCriativoIaJob::dispatch($slot->id);
        GerarCriativoIaJob::dispatch($slot->id);

        Queue::assertPushed(GerarCriativoIaJob::class, 1);
    }

    // ═══ Task 3 — endpoint de disparo (criativo.kit.gerar) ══════════════

    public function test_gerar_devolve_202_antes_de_chamar_o_provedor_e_enfileira_os_7(): void
    {
        Queue::fake();
        [$kit] = $this->kitComPortadorE7Slots();

        $resp = $this->actingAs($this->admin())->postJson(
            route('mlb.anuncios.criativo.kit.gerar', ['kit' => $kit->token])
        );

        $resp->assertStatus(202);
        $resp->assertJsonStructure(['kit_token', 'status', 'enfileirados']);
        $resp->assertJsonPath('enfileirados', 7);
        Queue::assertPushed(GerarCriativoIaJob::class, 7);

        $this->assertSame(MlAnuncioCriativoKit::STATUS_GERANDO, $kit->fresh()->status);
    }

    public function test_gerar_com_kit_ja_gerando_devolve_202_sem_redespachar(): void
    {
        Queue::fake();
        [$kit] = $this->kitComPortadorE7Slots();
        $kit->update(['status' => MlAnuncioCriativoKit::STATUS_GERANDO]);

        $resp = $this->actingAs($this->admin())->postJson(
            route('mlb.anuncios.criativo.kit.gerar', ['kit' => $kit->token])
        );

        $resp->assertStatus(202);
        $resp->assertJsonPath('enfileirados', 0);
        Queue::assertNothingPushed();
    }

    public function test_gerar_com_kit_ainda_planejando_devolve_422_em_pt_br(): void
    {
        Queue::fake();
        [$kit] = $this->kitComPortadorE7Slots();
        $kit->update(['status' => MlAnuncioCriativoKit::STATUS_PLANEJANDO]);

        $resp = $this->actingAs($this->admin())->postJson(
            route('mlb.anuncios.criativo.kit.gerar', ['kit' => $kit->token])
        );

        $resp->assertStatus(422);
        $this->assertNotEmpty($resp->json('erros.0.mensagem'));
        Queue::assertNothingPushed();
    }

    public function test_gerar_com_teto_de_imagens_atingido_devolve_422_com_motivo(): void
    {
        Queue::fake();
        [$kit] = $this->kitComPortadorE7Slots();
        $kit->update(['imagens_geradas' => 14]);

        $resp = $this->actingAs($this->admin())->postJson(
            route('mlb.anuncios.criativo.kit.gerar', ['kit' => $kit->token])
        );

        $resp->assertStatus(422);
        $this->assertStringContainsString('máximo', $resp->json('erros.0.mensagem'));
        Queue::assertNothingPushed();
    }

    public function test_gerar_com_chave_desligada_devolve_404_sem_enfileirar(): void
    {
        Queue::fake();
        Configuracao::set('creative_engine_ativo', '0');
        [$kit] = $this->kitComPortadorE7Slots();

        $this->actingAs($this->admin())
            ->postJson(route('mlb.anuncios.criativo.kit.gerar', ['kit' => $kit->token]))
            ->assertStatus(404);

        Queue::assertNothingPushed();
    }

    public function test_gerar_sem_permissao_devolve_403_sem_enfileirar(): void
    {
        Queue::fake();
        [$kit] = $this->kitComPortadorE7Slots();

        $resp = $this->actingAs($this->naoAdmin())->postJson(
            route('mlb.anuncios.criativo.kit.gerar', ['kit' => $kit->token])
        );

        $resp->assertStatus(403);
        Queue::assertNothingPushed();
    }

    // ═══ Task 3 — whitelist do status por slot (T-161-05/T-161-12) ═════

    public function test_status_do_kit_inclui_etapa_erro_imagem_url_modelo_latencia_por_slot_sem_dados_sensiveis(): void
    {
        Http::fake(['gemini.teste/*' => Http::response($this->respostaImagemOk())]);
        [$kit] = $this->kitComPortadorE7Slots();

        $slotPronto = $kit->slots()->where('slot', 'hero')->first();
        $this->rodar($slotPronto);

        $resp = $this->actingAs($this->admin())->getJson(
            route('mlb.anuncios.criativo.kit.status', ['kit' => $kit->token])
        );

        $resp->assertOk();
        $resp->assertJsonStructure([
            'slots' => [['indice', 'tipo', 'rotulo', 'objetivo', 'status', 'etapa', 'erro', 'token', 'imagem_url', 'modelo', 'latencia_ms']],
        ]);

        $corpo = $resp->getContent();
        $this->assertStringNotContainsString('"prompt"', $corpo);
        $this->assertStringNotContainsString('"contexto"', $corpo);
        $this->assertStringNotContainsString('"truth"', $corpo);
        $this->assertStringNotContainsString('imagem_path', $corpo);

        $slotRespondido = collect($resp->json('slots'))->firstWhere('tipo', 'hero');
        $this->assertNotNull($slotRespondido['imagem_url']);
        $this->assertSame('modelo-imagem-teste', $slotRespondido['modelo']);
    }
}
