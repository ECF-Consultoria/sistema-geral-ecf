<?php

namespace Tests\Feature\Phase162;

use App\Models\Company;
use App\Models\Configuracao;
use App\Models\MlAnuncioCriativo;
use App\Models\MlAnuncioCriativoKit;
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
 * Gate de validação nos dois endpoints de aprovação (Fase 162, Plano 02,
 * Task 2 e Task 3) — VAL-01 (pendente recusa), VAL-04 (reprovada só sobe com
 * `confirmar_risco`, nunca em lote) e VAL-06 (pendente travado libera).
 *
 * Molde de `tests/Feature/Phase160/CriativoAprovacaoTest.php` e
 * `tests/Feature/Phase161/CriativoKitAprovacaoTest.php`. Nenhum teste fala
 * com o Mercado Livre de verdade: `Http::preventStrayRequests()` + fake.
 */
class AprovacaoComValidacaoTest extends TestCase
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

    private function companyConectada(): Company
    {
        $company = Company::factory()->create(['name' => 'Unity Móveis']);

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

    /** Fake do upload ao ML que devolve picture_id/url DIFERENTES por chamada. */
    private function fakeUploadMlComContador(): void
    {
        $chamada = 0;
        Http::fake(function () use (&$chamada) {
            $chamada++;

            return Http::response([
                'id'         => "MLB-pic-{$chamada}",
                'variations' => [['secure_url' => "https://http2.mlstatic.com/foto-{$chamada}.jpg"]],
            ], 200);
        });
    }

    /** Criativo `pronto` (fluxo sem kit, Fase 160) com `validacao_status` dado. */
    private function criativoPronto(?string $validacaoStatus, array $validacaoExtra = []): MlAnuncioCriativo
    {
        Storage::fake('local');

        $company  = $this->companyConectada();
        $rascunho = MlAnuncioRascunho::create([
            'company_id'  => $company->id,
            'user_id'     => User::factory()->create(['role' => 'admin'])->id,
            'category_id' => 'MLB1574',
            'payload'     => ['title' => 'x', 'category_id' => 'MLB1574', 'description' => 'x', 'attributes' => [], 'pictures' => []],
            'status'      => MlAnuncioRascunho::STATUS_RASCUNHO,
        ]);

        $token = Str::random(32);
        Storage::disk('local')->put("creative-geradas/{$token}/hero.jpg", 'bytes-da-imagem-gerada');

        $validacao = $validacaoStatus !== null
            ? array_merge(['status' => $validacaoStatus, 'mensagem' => 'Risco: produto alterado. Confira antes de aprovar.'], $validacaoExtra)
            : null;

        return MlAnuncioCriativo::create([
            'token'               => $token,
            'company_id'          => $company->id,
            'rascunho_id'         => $rascunho->id,
            'user_id'             => $rascunho->user_id,
            'slot'                => 'hero',
            'status'              => MlAnuncioCriativo::STATUS_PRONTO,
            'imagem_path'         => "creative-geradas/{$token}/hero.jpg",
            'imagem_mime'         => 'image/jpeg',
            'validacao_status'    => $validacaoStatus,
            'validacao'           => $validacao,
            'validacao_pedida_em' => $validacaoStatus === MlAnuncioCriativo::VALIDACAO_PENDENTE ? now() : null,
        ]);
    }

    // ═══ (a) pendente recusa, nada é tentado ════════════════════════════

    public function test_aprovar_com_validacao_pendente_recusa_e_nada_e_tentado(): void
    {
        $this->fakeUploadMlComContador();
        $criativo = $this->criativoPronto(MlAnuncioCriativo::VALIDACAO_PENDENTE);

        $resposta = $this->actingAs($this->admin())->postJson(
            route('mlb.anuncios.criativo.aprovar', ['token' => $criativo->token]),
        );

        $resposta->assertStatus(422);
        $this->assertNotEmpty($resposta->json('erros.0.mensagem'));
        Http::assertNothingSent();

        $criativo->refresh();
        $this->assertSame(MlAnuncioCriativo::STATUS_PRONTO, $criativo->status);
    }

    // ═══ (b) reprovada sem confirmar_risco recusa com o motivo ══════════

    public function test_aprovar_reprovada_sem_confirmar_risco_recusa_com_o_motivo(): void
    {
        $this->fakeUploadMlComContador();
        $criativo = $this->criativoPronto(MlAnuncioCriativo::VALIDACAO_REPROVADA);

        $resposta = $this->actingAs($this->admin())->postJson(
            route('mlb.anuncios.criativo.aprovar', ['token' => $criativo->token]),
        );

        $resposta->assertStatus(422);
        $this->assertStringContainsString('Risco', $resposta->json('erros.0.mensagem'));
        Http::assertNothingSent();

        $criativo->refresh();
        $this->assertSame(MlAnuncioCriativo::STATUS_PRONTO, $criativo->status);
    }

    // ═══ (c) reprovada COM confirmar_risco sobe e grava o override ═════

    public function test_aprovar_reprovada_com_confirmar_risco_sobe_e_grava_override(): void
    {
        $this->fakeUploadMlComContador();
        $criativo = $this->criativoPronto(MlAnuncioCriativo::VALIDACAO_REPROVADA);
        $admin = $this->admin();

        $resposta = $this->actingAs($admin)->postJson(
            route('mlb.anuncios.criativo.aprovar', ['token' => $criativo->token]),
            ['confirmar_risco' => true],
        );

        $resposta->assertOk()->assertJsonPath('ok', true);
        Http::assertSentCount(1);

        $criativo->refresh();
        $this->assertSame(MlAnuncioCriativo::STATUS_APROVADO, $criativo->status);
        $this->assertSame($admin->id, $criativo->validacao['override']['user_id']);
        $this->assertNotEmpty($criativo->validacao['override']['em']);
        // O veredito original não foi sobrescrito pelo merge do override.
        $this->assertSame(MlAnuncioCriativo::VALIDACAO_REPROVADA, $criativo->validacao['status']);
    }

    // ═══ (d) aprovada segue o caminho normal ════════════════════════════

    public function test_aprovar_com_validacao_aprovada_segue_o_caminho_normal(): void
    {
        $this->fakeUploadMlComContador();
        $criativo = $this->criativoPronto(MlAnuncioCriativo::VALIDACAO_APROVADA);

        $resposta = $this->actingAs($this->admin())->postJson(
            route('mlb.anuncios.criativo.aprovar', ['token' => $criativo->token]),
        );

        $resposta->assertOk()->assertJsonPath('ok', true);
        Http::assertSentCount(1);

        $criativo->refresh();
        $this->assertSame(MlAnuncioCriativo::STATUS_APROVADO, $criativo->status);
    }

    // ═══ (e) indisponivel segue o caminho normal (fail-open) ════════════

    public function test_aprovar_com_validacao_indisponivel_segue_o_caminho_normal(): void
    {
        $this->fakeUploadMlComContador();
        $criativo = $this->criativoPronto(MlAnuncioCriativo::VALIDACAO_INDISPONIVEL);

        $resposta = $this->actingAs($this->admin())->postJson(
            route('mlb.anuncios.criativo.aprovar', ['token' => $criativo->token]),
        );

        $resposta->assertOk()->assertJsonPath('ok', true);
        Http::assertSentCount(1);

        $criativo->refresh();
        $this->assertSame(MlAnuncioCriativo::STATUS_APROVADO, $criativo->status);
    }

    // ═══ (f) validacao_status NULL (12 criativos legados) — não-regressão ══

    public function test_aprovar_com_validacao_status_nulo_comporta_se_como_antes(): void
    {
        $this->fakeUploadMlComContador();
        $criativo = $this->criativoPronto(null);

        $resposta = $this->actingAs($this->admin())->postJson(
            route('mlb.anuncios.criativo.aprovar', ['token' => $criativo->token]),
        );

        $resposta->assertOk()->assertJsonPath('ok', true);
        Http::assertSentCount(1);

        $criativo->refresh();
        $this->assertSame(MlAnuncioCriativo::STATUS_APROVADO, $criativo->status);
        $this->assertNull($criativo->validacao);
    }

    // ═══ (g) pendente há 11 minutos vira indisponivel e aprova ══════════

    public function test_pendente_travado_ha_11_minutos_vira_indisponivel_e_aprova(): void
    {
        $this->fakeUploadMlComContador();
        $criativo = $this->criativoPronto(MlAnuncioCriativo::VALIDACAO_PENDENTE);
        $criativo->update(['validacao_pedida_em' => now()->subMinutes(11)]);

        $resposta = $this->actingAs($this->admin())->postJson(
            route('mlb.anuncios.criativo.aprovar', ['token' => $criativo->token]),
        );

        $resposta->assertOk()->assertJsonPath('ok', true);

        $criativo->refresh();
        $this->assertSame(MlAnuncioCriativo::VALIDACAO_INDISPONIVEL, $criativo->validacao_status);
        $this->assertSame(MlAnuncioCriativo::STATUS_APROVADO, $criativo->status);
    }

    // ═══ (h) mensagem de recusa não contém id interno nem nome de classe ═

    public function test_mensagem_de_recusa_nao_contem_id_interno_nem_nome_de_classe(): void
    {
        $this->fakeUploadMlComContador();
        $criativo = $this->criativoPronto(MlAnuncioCriativo::VALIDACAO_REPROVADA);

        $resposta = $this->actingAs($this->admin())->postJson(
            route('mlb.anuncios.criativo.aprovar', ['token' => $criativo->token]),
        );

        $mensagem = $resposta->json('erros.0.mensagem');
        $this->assertStringNotContainsString((string) $criativo->id, $mensagem);
        $this->assertStringNotContainsString('Exception', $mensagem);
        $this->assertStringNotContainsString('App\\', $mensagem);
    }

    // ═══ Task 3 — aprovar o kit nunca sobe reprovada em silêncio (VAL-04) ═

    /**
     * Monta um kit com portador (referência viva em disco) + N slots
     * `pronto`, cada um com `validacao_status` dado em `$statusPorSlot`
     * (índice 0-based na ordem de `slot_indice`).
     *
     * @param  array<int, ?string>  $statusPorSlot
     * @return array{0: MlAnuncioCriativoKit, 1: \Illuminate\Support\Collection<int, MlAnuncioCriativo>, 2: MlAnuncioRascunho}
     */
    private function kitComSlotsProntos(int $minimoAprovadas, array $statusPorSlot): array
    {
        Storage::fake('local');

        $company = $this->companyConectada();
        $userId  = User::factory()->create(['role' => 'admin'])->id;

        $rascunho = MlAnuncioRascunho::create([
            'company_id'  => $company->id,
            'user_id'     => $userId,
            'category_id' => 'MLB1574',
            'payload'     => ['title' => 'Gabinete de cozinha', 'category_id' => 'MLB1574', 'description' => 'x', 'attributes' => [], 'pictures' => []],
            'status'      => MlAnuncioRascunho::STATUS_RASCUNHO,
        ]);

        // Semeia o cache de categoria (molde de CriativoKitAprovacaoTest) —
        // sem isto, `aplicarPictures()` bateria na API real do ML para
        // metadados e o `Http::fake()` genérico do upload devolveria a
        // forma errada (sem `access_token`), estourando no refresh de token.
        \Illuminate\Support\Facades\Cache::put('ml_meta_categoria_MLB1574', ['settings' => ['max_pictures_per_item' => 12]], 3600);

        $portadorToken = Str::random(32);
        Storage::disk('local')->put("creative-referencias/{$portadorToken}/0.jpg", 'bytes-da-foto-original');
        $portador = MlAnuncioCriativo::create([
            'token'       => $portadorToken,
            'company_id'  => $company->id,
            'rascunho_id' => $rascunho->id,
            'user_id'     => $userId,
            'slot'        => 'referencia',
            'status'      => MlAnuncioCriativo::STATUS_PRONTO,
            'referencias' => [
                ['indice' => 0, 'nome' => 'foto.jpg', 'path' => "creative-referencias/{$portadorToken}/0.jpg", 'mime' => 'image/jpeg'],
            ],
        ]);

        $kit = MlAnuncioCriativoKit::create([
            'token'                  => Str::random(32),
            'company_id'             => $company->id,
            'rascunho_id'            => $rascunho->id,
            'user_id'                => $userId,
            'criativo_referencia_id' => $portador->id,
            'status'                 => MlAnuncioCriativoKit::STATUS_PRONTO,
            'total_slots'            => count($statusPorSlot),
            'minimo_aprovadas'       => $minimoAprovadas,
            'imagens_geradas'        => count($statusPorSlot),
        ]);
        $portador->update(['kit_id' => $kit->id]);

        $tipos = ['hero', 'white_background', 'angles', 'detail', 'lifestyle', 'benefits', 'specifications'];
        $slots = collect();
        foreach ($statusPorSlot as $i => $validacaoStatus) {
            $token = Str::random(32);
            $tipo  = $tipos[$i] ?? "slot-{$i}";
            Storage::disk('local')->put("creative-geradas/{$token}/{$tipo}.jpg", 'bytes-da-imagem-gerada');

            $slots->push(MlAnuncioCriativo::create([
                'token'            => $token,
                'company_id'       => $company->id,
                'rascunho_id'      => $rascunho->id,
                'user_id'          => $userId,
                'kit_id'           => $kit->id,
                'slot'             => $tipo,
                'slot_indice'      => $i + 1,
                'slot_plano'       => ['indice' => $i + 1, 'tipo' => $tipo, 'objetivo' => "Objetivo {$tipo}."],
                'status'           => MlAnuncioCriativo::STATUS_PRONTO,
                'imagem_path'      => "creative-geradas/{$token}/{$tipo}.jpg",
                'imagem_mime'      => 'image/jpeg',
                'validacao_status' => $validacaoStatus,
            ]));
        }

        return [$kit->fresh(), $slots, $rascunho->fresh()];
    }

    public function test_aprovar_kit_com_1_reprovada_abaixo_do_minimo_recusa_citando_a_reprovada(): void
    {
        $this->fakeUploadMlComContador();
        // 3 prontas, 1 delas reprovada: só 2 "sem risco" -> abaixo do mínimo de 3.
        [$kit] = $this->kitComSlotsProntos(3, [
            MlAnuncioCriativo::VALIDACAO_APROVADA,
            MlAnuncioCriativo::VALIDACAO_APROVADA,
            MlAnuncioCriativo::VALIDACAO_REPROVADA,
        ]);

        $resposta = $this->actingAs($this->admin())->postJson(
            route('mlb.anuncios.criativo.kit.aprovar', ['kit' => $kit->token]),
        );

        $resposta->assertStatus(422);
        $this->assertStringContainsString('reprovada', $resposta->json('erros.0.mensagem'));
        Http::assertNothingSent();
    }

    public function test_aprovar_kit_com_4_prontas_e_1_reprovada_sobe_3_e_nao_fecha_o_kit(): void
    {
        $this->fakeUploadMlComContador();
        [$kit, , $rascunho] = $this->kitComSlotsProntos(3, [
            MlAnuncioCriativo::VALIDACAO_APROVADA,
            MlAnuncioCriativo::VALIDACAO_APROVADA,
            MlAnuncioCriativo::VALIDACAO_REPROVADA,
            null,
        ]);

        $resposta = $this->actingAs($this->admin())->postJson(
            route('mlb.anuncios.criativo.kit.aprovar', ['kit' => $kit->token]),
        );

        $resposta->assertOk()->assertJsonPath('aprovadas', 3);
        $this->assertSame([3], $resposta->json('reprovadas'));
        Http::assertSentCount(3);

        $kit->refresh();
        $this->assertNotSame(MlAnuncioCriativoKit::STATUS_APROVADO, $kit->status);

        // A referência do portador NÃO foi apagada — o reprovado ainda
        // pode ser regenerado.
        $portador = $kit->criativoReferencia;
        $this->assertNull($portador->fresh()->referencias_apagadas_em);
    }

    public function test_aprovar_kit_todo_aprovado_na_validacao_fecha_como_antes(): void
    {
        $this->fakeUploadMlComContador();
        [$kit, , $rascunho] = $this->kitComSlotsProntos(3, [
            MlAnuncioCriativo::VALIDACAO_APROVADA,
            MlAnuncioCriativo::VALIDACAO_APROVADA,
            MlAnuncioCriativo::VALIDACAO_APROVADA,
        ]);

        $resposta = $this->actingAs($this->admin())->postJson(
            route('mlb.anuncios.criativo.kit.aprovar', ['kit' => $kit->token]),
        );

        $resposta->assertOk()->assertJsonPath('aprovadas', 3);

        $kit->refresh();
        $this->assertSame(MlAnuncioCriativoKit::STATUS_APROVADO, $kit->status);

        $portador = $kit->criativoReferencia;
        $this->assertNotNull($portador->fresh()->referencias_apagadas_em);
    }

    public function test_aprovar_kit_com_validacao_status_nulo_fecha_como_antes_nao_regressao(): void
    {
        $this->fakeUploadMlComContador();
        [$kit] = $this->kitComSlotsProntos(3, [null, null, null]);

        $resposta = $this->actingAs($this->admin())->postJson(
            route('mlb.anuncios.criativo.kit.aprovar', ['kit' => $kit->token]),
        );

        $resposta->assertOk()->assertJsonPath('aprovadas', 3);

        $kit->refresh();
        $this->assertSame(MlAnuncioCriativoKit::STATUS_APROVADO, $kit->status);
    }

    // ═══ T-162-09 — escopo/permissão continuam ANTES do gate novo ═══════

    public function test_publicador_fora_de_escopo_recebe_403_mesmo_com_validacao_reprovada(): void
    {
        $this->fakeUploadMlComContador();
        $outroUsuario = User::factory()->create(['role' => 'consultor']);

        Storage::fake('local');
        $company = $this->companyConectada();
        MlbEmpresa::create([
            'nome'           => $company->name,
            'tipo'           => 'ecommerce',
            'cust_id'        => 'cust-' . $company->id,
            'company_id'     => $company->id,
            'responsavel_id' => $outroUsuario->id,
        ]);

        $rascunho = MlAnuncioRascunho::create([
            'company_id'     => $company->id,
            'mlb_empresa_id' => MlbEmpresa::where('company_id', $company->id)->first()->id,
            'user_id'        => $outroUsuario->id,
            'category_id'    => 'MLB1574',
            'payload'        => ['title' => 'x', 'category_id' => 'MLB1574', 'description' => 'x', 'attributes' => [], 'pictures' => []],
            'status'         => MlAnuncioRascunho::STATUS_RASCUNHO,
        ]);

        $token = Str::random(32);
        Storage::disk('local')->put("creative-geradas/{$token}/hero.jpg", 'bytes-da-imagem-gerada');
        $criativo = MlAnuncioCriativo::create([
            'token'            => $token,
            'company_id'       => $company->id,
            'rascunho_id'      => $rascunho->id,
            'user_id'          => $outroUsuario->id,
            'slot'             => 'hero',
            'status'           => MlAnuncioCriativo::STATUS_PRONTO,
            'imagem_path'      => "creative-geradas/{$token}/hero.jpg",
            'imagem_mime'      => 'image/jpeg',
            'validacao_status' => MlAnuncioCriativo::VALIDACAO_REPROVADA,
        ]);

        $naoAdmin = User::factory()->create(['role' => 'consultor']);

        $resposta = $this->actingAs($naoAdmin)->postJson(
            route('mlb.anuncios.criativo.aprovar', ['token' => $criativo->token]),
            ['confirmar_risco' => true],
        );

        $resposta->assertStatus(403);
        Http::assertNothingSent();
    }
}
