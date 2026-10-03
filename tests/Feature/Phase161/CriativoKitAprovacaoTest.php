<?php

namespace Tests\Feature\Phase161;

use App\Models\Company;
use App\Models\Configuracao;
use App\Models\MlAnuncioCriativo;
use App\Models\MlAnuncioCriativoKit;
use App\Models\MlAnuncioRascunho;
use App\Models\MlbEmpresa;
use App\Models\MlToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Aprovação do kit (Fase 161, Plano 03, Task 2) — APROV-03/PUB-01/PUB-02/
 * PUB-04, e a prova direta da armadilha central do plano: aprovar o slot 1
 * NÃO apaga a referência (só aprovar o KIT INTEIRO apaga).
 *
 * Molde literal de `tests/Feature/Phase160/CriativoAprovacaoTest.php` (o
 * fake do upload ao ML na forma MEDIDA da resposta real), somando os casos
 * do kit. `Http::preventStrayRequests()` garante que nenhuma chamada real
 * sai para o Mercado Livre.
 */
class CriativoKitAprovacaoTest extends TestCase
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

    /** Fake do upload ao ML que devolve picture_id/url DIFERENTES por chamada (índice por ordem). */
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

    /**
     * Monta um kit com portador (referência viva em disco) + N slots
     * `pronto` (imagem já gerada no disco).
     *
     * @return array{0: MlAnuncioCriativoKit, 1: \Illuminate\Support\Collection<int, MlAnuncioCriativo>, 2: MlAnuncioRascunho}
     */
    private function kitComSlotsProntos(int $minimoAprovadas = 3, int $qtdProntos = 7, ?int $responsavelId = null): array
    {
        Storage::fake('local');

        $company = $this->companyConectada();
        $userId  = $responsavelId ?? User::factory()->create(['role' => 'admin'])->id;

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

        $rascunho = MlAnuncioRascunho::create([
            'company_id'     => $company->id,
            'mlb_empresa_id' => $mlbEmpresaId,
            'user_id'        => $userId,
            'category_id'    => 'MLB1574',
            'payload'        => [
                'title'       => 'Gabinete de cozinha',
                'category_id' => 'MLB1574',
                'description' => 'Descrição qualquer.',
                'attributes'  => [],
                'pictures'    => [],
            ],
            'status' => MlAnuncioRascunho::STATUS_RASCUNHO,
        ]);

        // Semeia o cache de categoria (molde da Decisão 9) com um limite
        // alto por padrão — nenhum teste aqui chama a API real do ML para
        // metadados; `Http::preventStrayRequests()` fica ligado. O teste de
        // PUB-04 sobrescreve com um limite baixo (6) depois de montar o kit.
        Cache::put('ml_meta_categoria_MLB1574', ['settings' => ['max_pictures_per_item' => 12]], 3600);

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
            'mlb_empresa_id'         => $mlbEmpresaId,
            'rascunho_id'            => $rascunho->id,
            'user_id'                => $userId,
            'criativo_referencia_id' => $portador->id,
            'status'                 => MlAnuncioCriativoKit::STATUS_PRONTO,
            'total_slots'            => 7,
            'minimo_aprovadas'       => $minimoAprovadas,
            'imagens_geradas'        => 7,
        ]);
        $portador->update(['kit_id' => $kit->id]);

        $tipos = ['hero', 'white_background', 'angles', 'detail', 'lifestyle', 'benefits', 'specifications'];
        $slots = collect();
        foreach ($tipos as $i => $tipo) {
            $token  = Str::random(32);
            $pronto = $i < $qtdProntos;

            if ($pronto) {
                Storage::disk('local')->put("creative-geradas/{$token}/{$tipo}.jpg", 'bytes-da-imagem-gerada');
            }

            $slots->push(MlAnuncioCriativo::create([
                'token'       => $token,
                'company_id'  => $company->id,
                'rascunho_id' => $rascunho->id,
                'user_id'     => $userId,
                'kit_id'      => $kit->id,
                'slot'        => $tipo,
                'slot_indice' => $i + 1,
                'slot_plano'  => ['indice' => $i + 1, 'tipo' => $tipo, 'objetivo' => "Objetivo {$tipo}."],
                'status'      => $pronto ? MlAnuncioCriativo::STATUS_PRONTO : MlAnuncioCriativo::STATUS_PENDENTE,
                'imagem_path' => $pronto ? "creative-geradas/{$token}/{$tipo}.jpg" : null,
                'imagem_mime' => $pronto ? 'image/jpeg' : null,
            ]));
        }

        return [$kit->fresh(), $slots, $rascunho->fresh()];
    }

    // ═══ Aprovar slot individual — ordem dos slots, nunca a posição errada ═══

    public function test_aprovar_slot_fora_de_ordem_reconstroi_pictures_em_ordem_de_slot_indice(): void
    {
        $this->fakeUploadMlComContador();
        [$kit, $slots, $rascunho] = $this->kitComSlotsProntos();

        $slot3 = $slots->firstWhere('slot_indice', 3); // 'angles'

        $resposta = $this->actingAs($this->admin())->postJson(
            route('mlb.anuncios.criativo.aprovar', ['token' => $slot3->token]),
        );

        $resposta->assertOk()->assertJsonPath('ok', true);

        $slot3->refresh();
        $this->assertSame(MlAnuncioCriativo::STATUS_APROVADO, $slot3->status);
        $this->assertNotNull($slot3->ml_picture_id);
        $this->assertNotNull($slot3->ml_picture_url);

        // Só o slot 3 está aprovado — payload.pictures tem SÓ a foto dele,
        // na posição 0 (é o único aprovado até agora).
        $rascunho->refresh();
        $this->assertSame(
            [['source' => $slot3->ml_picture_url]],
            $rascunho->payload['pictures'],
        );

        // O callback do front recebe a URL do SLOT 1 (hero) — que ainda não
        // foi aprovado — não a do slot 3 recém-aprovado.
        $this->assertNull($resposta->json('url'));

        Http::assertSentCount(1);
    }

    public function test_aprovar_slot_1_e_depois_slot_3_mantem_slot_1_na_posicao_principal(): void
    {
        $this->fakeUploadMlComContador();
        [$kit, $slots, $rascunho] = $this->kitComSlotsProntos();

        $slot1 = $slots->firstWhere('slot_indice', 1);
        $slot3 = $slots->firstWhere('slot_indice', 3);

        $this->actingAs($this->admin())->postJson(route('mlb.anuncios.criativo.aprovar', ['token' => $slot3->token]))->assertOk();
        $respostaSlot1 = $this->actingAs($this->admin())->postJson(route('mlb.anuncios.criativo.aprovar', ['token' => $slot1->token]));
        $respostaSlot1->assertOk();

        $slot1->refresh();
        $slot3->refresh();

        // Agora o slot 1 está aprovado — a URL devolvida ao front é a dele.
        $this->assertSame($slot1->ml_picture_url, $respostaSlot1->json('url'));

        $rascunho->refresh();
        $this->assertSame(
            [
                ['source' => $slot1->ml_picture_url],
                ['source' => $slot3->ml_picture_url],
            ],
            $rascunho->payload['pictures'],
        );
    }

    // ═══ A ARMADILHA — aprovar 1 slot não apaga a referência ════════════

    public function test_aprovar_um_slot_nao_apaga_a_referencia_do_portador(): void
    {
        $this->fakeUploadMlComContador();
        [$kit, $slots] = $this->kitComSlotsProntos();
        $portador = $kit->criativoReferencia;

        $this->actingAs($this->admin())->postJson(
            route('mlb.anuncios.criativo.aprovar', ['token' => $slots->first()->token]),
        )->assertOk();

        $portador->refresh();
        $this->assertNull($portador->referencias_apagadas_em);
        Storage::disk('local')->assertExists("creative-referencias/{$portador->token}/0.jpg");
    }

    // ═══ Aprovar o kit inteiro ════════════════════════════════════════

    public function test_aprovar_kit_com_minimo_atingido_sobe_todos_os_prontos_e_fecha_o_kit(): void
    {
        $this->fakeUploadMlComContador();
        [$kit, $slots, $rascunho] = $this->kitComSlotsProntos(minimoAprovadas: 3, qtdProntos: 5);
        $admin = $this->admin();

        $resposta = $this->actingAs($admin)->postJson(
            route('mlb.anuncios.criativo.kit.aprovar', ['kit' => $kit->token]),
        );

        $resposta->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('aprovadas', 5)
            ->assertJsonPath('falharam', []);

        $kit->refresh();
        $this->assertSame(MlAnuncioCriativoKit::STATUS_APROVADO, $kit->status);
        $this->assertSame($admin->id, $kit->aprovado_por);
        $this->assertNotNull($kit->aprovado_em);

        // Os 5 prontos foram aprovados; os 2 ainda pendentes continuam pendentes.
        $this->assertSame(5, $kit->aprovadas());
        $this->assertSame(0, $kit->prontas());

        $rascunho->refresh();
        $this->assertCount(5, $rascunho->payload['pictures']);

        // `url` da resposta é a do SLOT 1 (hero) — mesmo contrato de
        // `criativoAprovar()`, para `onImagemAprovada(url)` continuar
        // apontando o wizard para a imagem principal.
        $slot1 = $kit->slots()->where('slot_indice', 1)->first();
        $this->assertSame($slot1->ml_picture_url, $resposta->json('url'));

        // SÓ ENTÃO a referência do portador foi apagada.
        $portador = $kit->criativoReferencia;
        $this->assertNotNull($portador->fresh()->referencias_apagadas_em);
    }

    public function test_aprovar_kit_abaixo_do_minimo_recusa_e_nao_sobe_nada(): void
    {
        $this->fakeUploadMlComContador();
        [$kit] = $this->kitComSlotsProntos(minimoAprovadas: 3, qtdProntos: 2);

        $resposta = $this->actingAs($this->admin())->postJson(
            route('mlb.anuncios.criativo.kit.aprovar', ['kit' => $kit->token]),
        );

        $resposta->assertStatus(422);
        $mensagem = $resposta->json('erros')[0]['mensagem'] ?? '';
        $this->assertStringContainsString('1', $mensagem); // faltam 3-2=1

        Http::assertNothingSent();

        $kit->refresh();
        $this->assertNotSame(MlAnuncioCriativoKit::STATUS_APROVADO, $kit->status);
    }

    // ═══ PUB-04 — limite de fotos da categoria ══════════════════════════

    public function test_aprovar_kit_corta_no_limite_da_categoria_mantendo_o_slot_1(): void
    {
        $this->fakeUploadMlComContador();
        [$kit, , $rascunho] = $this->kitComSlotsProntos(minimoAprovadas: 3, qtdProntos: 7);

        Cache::put('ml_meta_categoria_MLB1574', ['settings' => ['max_pictures_per_item' => 6]], 3600);

        $resposta = $this->actingAs($this->admin())->postJson(
            route('mlb.anuncios.criativo.kit.aprovar', ['kit' => $kit->token]),
        );

        $resposta->assertOk()->assertJsonPath('aprovadas', 7);

        $rascunho->refresh();
        $this->assertCount(6, $rascunho->payload['pictures']);

        // O slot 1 (hero) nunca é o cortado — é o primeiro item da lista.
        $slot1 = $kit->slots()->where('slot_indice', 1)->first();
        $this->assertSame(['source' => $slot1->ml_picture_url], $rascunho->payload['pictures'][0]);
    }

    // ═══ Falha parcial — meia aprovação não existe por slot ════════════

    public function test_falha_no_upload_de_um_slot_mantem_ele_pronto_e_kit_nao_fecha(): void
    {
        $chamada = 0;
        Http::fake(function () use (&$chamada) {
            $chamada++;

            if ($chamada === 1) {
                return Http::response('erro simulado', 500);
            }

            return Http::response([
                'id'         => "MLB-pic-{$chamada}",
                'variations' => [['secure_url' => "https://http2.mlstatic.com/foto-{$chamada}.jpg"]],
            ], 200);
        });

        [$kit, $slots, $rascunho] = $this->kitComSlotsProntos(minimoAprovadas: 3, qtdProntos: 5);
        $slotQueVaiFalhar = $slots->firstWhere('slot_indice', 1); // 1ª chamada, 1ª em ordem

        $resposta = $this->actingAs($this->admin())->postJson(
            route('mlb.anuncios.criativo.kit.aprovar', ['kit' => $kit->token]),
        );

        $resposta->assertOk()
            ->assertJsonPath('ok', false)
            ->assertJsonPath('aprovadas', 4)
            ->assertJsonPath('falharam', [1]);

        $slotQueVaiFalhar->refresh();
        $this->assertSame(MlAnuncioCriativo::STATUS_PRONTO, $slotQueVaiFalhar->status);
        $this->assertNull($slotQueVaiFalhar->ml_picture_id);

        $kit->refresh();
        $this->assertNotSame(MlAnuncioCriativoKit::STATUS_APROVADO, $kit->status);
        $this->assertSame(4, $kit->aprovadas());
        $this->assertSame(1, $kit->prontas());

        // A referência do portador NÃO foi apagada — o kit não fechou.
        $portador = $kit->criativoReferencia;
        $this->assertNull($portador->fresh()->referencias_apagadas_em);
    }

    // ═══ Segunda aprovação não gera segundo upload ══════════════════════

    public function test_segunda_aprovacao_do_mesmo_kit_nao_sobe_de_novo(): void
    {
        $this->fakeUploadMlComContador();
        [$kit] = $this->kitComSlotsProntos(minimoAprovadas: 3, qtdProntos: 5);

        $this->actingAs($this->admin())->postJson(
            route('mlb.anuncios.criativo.kit.aprovar', ['kit' => $kit->token]),
        )->assertOk();

        Http::assertSentCount(5);

        $segunda = $this->actingAs($this->admin())->postJson(
            route('mlb.anuncios.criativo.kit.aprovar', ['kit' => $kit->token]),
        );
        $segunda->assertStatus(422);

        Http::assertSentCount(5);
    }

    public function test_segunda_aprovacao_do_mesmo_slot_nao_sobe_de_novo(): void
    {
        $this->fakeUploadMlComContador();
        [, $slots] = $this->kitComSlotsProntos();
        $slot = $slots->first();

        $this->actingAs($this->admin())->postJson(
            route('mlb.anuncios.criativo.aprovar', ['token' => $slot->token]),
        )->assertOk();

        Http::assertSentCount(1);

        $this->actingAs($this->admin())->postJson(
            route('mlb.anuncios.criativo.aprovar', ['token' => $slot->token]),
        )->assertStatus(422);

        Http::assertSentCount(1);
    }

    // ═══ OPS-03 — chave desligada ════════════════════════════════════════

    public function test_chave_desligada_devolve_404_para_aprovar_kit(): void
    {
        Configuracao::set('creative_engine_ativo', '0');
        $this->fakeUploadMlComContador();
        [$kit] = $this->kitComSlotsProntos();

        $resposta = $this->actingAs($this->admin())->postJson(
            route('mlb.anuncios.criativo.kit.aprovar', ['kit' => $kit->token]),
        );

        $resposta->assertStatus(404);
        Http::assertNothingSent();
    }

    // ═══ Autorização / escopo ═══════════════════════════════════════════

    public function test_publicador_fora_de_escopo_recebe_403_ao_aprovar_kit(): void
    {
        $this->fakeUploadMlComContador();
        $outroUsuario = User::factory()->create(['role' => 'consultor']);
        [$kit] = $this->kitComSlotsProntos(responsavelId: $outroUsuario->id);

        $resposta = $this->actingAs($this->naoAdmin())->postJson(
            route('mlb.anuncios.criativo.kit.aprovar', ['kit' => $kit->token]),
        );

        $resposta->assertStatus(403);
        Http::assertNothingSent();
    }

    public function test_sem_permissao_explicita_recebe_403_ao_aprovar_kit(): void
    {
        Configuracao::set('creative_engine_usuarios', '999999');
        $this->fakeUploadMlComContador();
        [$kit] = $this->kitComSlotsProntos();

        $resposta = $this->actingAs($this->admin())->postJson(
            route('mlb.anuncios.criativo.kit.aprovar', ['kit' => $kit->token]),
        );

        $resposta->assertStatus(403);
        Http::assertNothingSent();
    }

    // ═══ O fluxo de 1 imagem (Fase 160) continua idêntico ═══════════════

    public function test_criativo_sem_kit_aprova_como_sempre_e_apaga_a_referencia(): void
    {
        $this->fakeUploadMlComContador();
        Storage::fake('local');

        $company  = $this->companyConectada();
        $userId   = User::factory()->create(['role' => 'admin'])->id;
        $rascunho = MlAnuncioRascunho::create([
            'company_id'  => $company->id,
            'user_id'     => $userId,
            'category_id' => 'MLB1574',
            'payload'     => ['title' => 'x', 'category_id' => 'MLB1574', 'description' => 'x', 'attributes' => [], 'pictures' => []],
            'status'      => MlAnuncioRascunho::STATUS_RASCUNHO,
        ]);

        $token = Str::random(32);
        Storage::disk('local')->put("creative-geradas/{$token}/hero.jpg", 'bytes-da-imagem-gerada');
        $criativo = MlAnuncioCriativo::create([
            'token'       => $token,
            'company_id'  => $company->id,
            'rascunho_id' => $rascunho->id,
            'user_id'     => $userId,
            'slot'        => 'hero',
            'status'      => MlAnuncioCriativo::STATUS_PRONTO,
            'imagem_path' => "creative-geradas/{$token}/hero.jpg",
            'imagem_mime' => 'image/jpeg',
            'referencias' => [
                ['indice' => 0, 'nome' => 'foto.jpg', 'path' => "creative-referencias/{$token}/0.jpg", 'mime' => 'image/jpeg'],
            ],
        ]);
        Storage::disk('local')->put("creative-referencias/{$token}/0.jpg", 'bytes-da-foto-original');

        $resposta = $this->actingAs($this->admin())->postJson(
            route('mlb.anuncios.criativo.aprovar', ['token' => $criativo->token]),
        );

        $resposta->assertOk()->assertJsonPath('ok', true);

        $criativo->refresh();
        $this->assertSame(MlAnuncioCriativo::STATUS_APROVADO, $criativo->status);
        // Sem kit: a referência É apagada na hora (comportamento da 160-04, intocado).
        $this->assertNotNull($criativo->referencias_apagadas_em);

        $rascunho->refresh();
        $this->assertSame([['source' => $criativo->ml_picture_url]], $rascunho->payload['pictures']);
        $this->assertSame($criativo->ml_picture_url, $resposta->json('url'));
    }
}
