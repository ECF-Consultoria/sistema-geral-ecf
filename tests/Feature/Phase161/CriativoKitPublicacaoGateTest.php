<?php

namespace Tests\Feature\Phase161;

use App\Jobs\PublicarAnuncioMlJob;
use App\Models\Company;
use App\Models\Configuracao;
use App\Models\MlAnuncioCriativo;
use App\Models\MlAnuncioCriativoKit;
use App\Models\MlAnuncioRascunho;
use App\Models\MlToken;
use App\Models\User;
use App\Services\Creative\CreativeKitPublicacao;
use App\Services\Mlb\Publicacao\MlPublicacaoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Gate de PUB-03 (Fase 161, Plano 04) — ninguém publica um anúncio com kit
 * de criativos por IA ainda não aprovado ou abaixo do mínimo, e as imagens
 * aprovadas são re-aplicadas no rascunho no instante da publicação (Decisão
 * 13 do 161-04-PLAN.md), fechando a armadilha do autosave do wizard.
 *
 * Task 1: `CreativeKitPublicacao::conferir()`/`aplicarPictures()` exercitados
 * direto no serviço (sem HTTP) — os dois casos de não-regressão PRIMEIRO,
 * porque são eles que autorizam mexer num serviço compartilhado.
 * Task 2 (acrescentada depois, no mesmo arquivo): o gate plugado no único
 * chokepoint de publicação (`MlPublicacaoService::publicar()`).
 *
 * `Http::preventStrayRequests()` garante que nenhuma chamada real sai para o
 * Mercado Livre nos casos de recusa.
 */
class CriativoKitPublicacaoGateTest extends TestCase
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

    /** Company + MlToken ativo — o que `MlPublicacaoService` exige para publicar. */
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

    /**
     * Cria rascunho pronto para publicar (categoria com cache semeado) —
     * sem nenhum kit. `$pictures` simula o que o autosave do wizard gravou.
     */
    private function rascunhoSemKit(array $pictures = []): array
    {
        $company = $this->companyConectada();
        $userId  = $this->admin()->id;

        Cache::put('ml_meta_categoria_MLB1574', ['settings' => ['max_pictures_per_item' => 12]], 3600);

        $rascunho = MlAnuncioRascunho::create([
            'company_id'  => $company->id,
            'user_id'     => $userId,
            'category_id' => 'MLB1574',
            'payload'     => [
                'title'           => 'Gabinete de cozinha',
                'category_id'     => 'MLB1574',
                'price'           => 199.9,
                'listing_type_id' => 'gold_special',
                'condition'       => 'new',
                'attributes'      => [],
                'pictures'        => $pictures,
            ],
            'status' => MlAnuncioRascunho::STATUS_RASCUNHO,
        ]);

        return [$rascunho, $company];
    }

    /**
     * Monta um kit com N slots `aprovado` (com `ml_picture_url`) e o resto
     * `pendente`, no estado de kit informado. Molde reduzido do fixture de
     * `CriativoKitAprovacaoTest` — aqui os slots já nascem aprovados porque
     * o upload ao ML é objeto da 161-03, não deste plano.
     *
     * @return array{0: MlAnuncioRascunho, 1: MlAnuncioCriativoKit, 2: Company}
     */
    private function rascunhoComKit(
        string $statusKit,
        int $aprovados,
        int $minimoAprovadas = 3,
        array $pictures = [],
    ): array {
        [$rascunho, $company] = $this->rascunhoSemKit($pictures);

        $kit = MlAnuncioCriativoKit::create([
            'token'            => Str::random(32),
            'company_id'       => $company->id,
            'rascunho_id'      => $rascunho->id,
            'user_id'          => $rascunho->user_id,
            'status'           => $statusKit,
            'total_slots'      => 7,
            'minimo_aprovadas' => $minimoAprovadas,
            'imagens_geradas'  => 7,
        ]);

        $tipos = ['hero', 'white_background', 'angles', 'detail', 'lifestyle', 'benefits', 'specifications'];
        foreach ($tipos as $i => $tipo) {
            $indice   = $i + 1;
            $aprovado = $indice <= $aprovados;

            MlAnuncioCriativo::create([
                'token'          => Str::random(32),
                'company_id'     => $company->id,
                'rascunho_id'    => $rascunho->id,
                'user_id'        => $rascunho->user_id,
                'kit_id'         => $kit->id,
                'slot'           => $tipo,
                'slot_indice'    => $indice,
                'slot_plano'     => ['indice' => $indice, 'tipo' => $tipo, 'objetivo' => "Objetivo {$tipo}."],
                'status'         => $aprovado ? MlAnuncioCriativo::STATUS_APROVADO : MlAnuncioCriativo::STATUS_PENDENTE,
                'ml_picture_id'  => $aprovado ? "MLB-pic-{$indice}" : null,
                'ml_picture_url' => $aprovado ? "https://http2.mlstatic.com/foto-{$indice}.jpg" : null,
            ]);
        }

        return [$rascunho->fresh(), $kit->fresh(), $company];
    }

    private function servico(): CreativeKitPublicacao
    {
        return app(CreativeKitPublicacao::class);
    }

    // ═══ Task 1 — não-regressão PRIMEIRO ════════════════════════════════

    public function test_sem_kit_conferir_nao_lanca_e_aplicar_pictures_devolve_zero(): void
    {
        [$rascunho] = $this->rascunhoSemKit(['source' => 'foto-antiga.jpg']);

        $servico = $this->servico();
        $servico->conferir($rascunho); // não lança

        $this->assertSame(0, $servico->aplicarPictures($rascunho));

        // payload.pictures fica exatamente como estava — aplicarPictures() é no-op sem kit.
        $rascunho->refresh();
        $this->assertSame(['source' => 'foto-antiga.jpg'], $rascunho->payload['pictures']);
    }

    public function test_chave_desligada_conferir_nao_lanca_mesmo_com_kit_nao_aprovado(): void
    {
        Configuracao::set('creative_engine_ativo', '0');
        [$rascunho] = $this->rascunhoComKit(MlAnuncioCriativoKit::STATUS_PRONTO, aprovados: 0);

        // Não lança — a publicação não ganha nenhuma conferência nova (OPS-03).
        $this->servico()->conferir($rascunho);
        $this->addToAssertionCount(1);
    }

    // ═══ Task 1 — recusas ════════════════════════════════════════════════

    public static function kitsNaoAprovadosProvider(): array
    {
        return [
            'planejado' => [MlAnuncioCriativoKit::STATUS_PLANEJADO],
            'gerando'   => [MlAnuncioCriativoKit::STATUS_GERANDO],
            'parcial'   => [MlAnuncioCriativoKit::STATUS_PARCIAL],
            'pronto'    => [MlAnuncioCriativoKit::STATUS_PRONTO],
        ];
    }

    /** @dataProvider kitsNaoAprovadosProvider */
    public function test_kit_nao_aprovado_conferir_lanca_com_mensagem_pt_br(string $statusKit): void
    {
        [$rascunho] = $this->rascunhoComKit($statusKit, aprovados: 2);

        try {
            $this->servico()->conferir($rascunho);
            $this->fail('Deveria ter lançado RuntimeException.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('ainda não aprovado', $e->getMessage());
            $this->assertStringContainsString('2', $e->getMessage());
            $this->assertStringContainsString('Aprove o kit antes de publicar', $e->getMessage());
            // Sem código técnico, sem id interno.
            $this->assertStringNotContainsString('RuntimeException', $e->getMessage());
            $this->assertStringNotContainsString((string) $rascunho->id, $e->getMessage());
        }
    }

    public function test_kit_aprovado_abaixo_do_minimo_conferir_lanca_com_mensagem_do_minimo(): void
    {
        [$rascunho] = $this->rascunhoComKit(MlAnuncioCriativoKit::STATUS_APROVADO, aprovados: 2, minimoAprovadas: 3);

        try {
            $this->servico()->conferir($rascunho);
            $this->fail('Deveria ter lançado RuntimeException.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('2', $e->getMessage());
            $this->assertStringContainsString('3', $e->getMessage());
            $this->assertStringContainsString('faltam 1', $e->getMessage());
        }
    }

    public function test_kit_em_erro_e_ignorado_conferir_nao_lanca(): void
    {
        [$rascunho] = $this->rascunhoComKit(MlAnuncioCriativoKit::STATUS_ERRO, aprovados: 0);

        $this->servico()->conferir($rascunho);
        $this->addToAssertionCount(1);
    }

    // ═══ Task 1 — kit aprovado com o mínimo atingido ════════════════════

    public function test_kit_aprovado_com_minimo_atingido_conferir_nao_lanca_e_aplica_pictures_em_ordem(): void
    {
        [$rascunho, $kit] = $this->rascunhoComKit(MlAnuncioCriativoKit::STATUS_APROVADO, aprovados: 3, minimoAprovadas: 3);

        $servico = $this->servico();
        $servico->conferir($rascunho); // não lança

        $qtd = $servico->aplicarPictures($rascunho);
        $this->assertSame(3, $qtd);

        $rascunho->refresh();
        $slot1 = $kit->slots()->where('slot_indice', 1)->first();
        $slot2 = $kit->slots()->where('slot_indice', 2)->first();
        $slot3 = $kit->slots()->where('slot_indice', 3)->first();

        $this->assertSame([
            ['source' => $slot1->ml_picture_url],
            ['source' => $slot2->ml_picture_url],
            ['source' => $slot3->ml_picture_url],
        ], $rascunho->payload['pictures']);
    }

    // ═══ Task 2 — o gate plugado no chokepoint de publicação ════════════

    /** GET /users/* (detecção de modelo) + POST /items + descrição, tudo ok. */
    private function fakePublicacaoOk(string $itemId = 'MLB999'): void
    {
        Http::fake([
            '*/users/*' => Http::response(['id' => '1489433777', 'tags' => []], 200),
            '*/items'   => Http::response(['id' => $itemId], 200),
            '*'         => Http::response([], 200),
        ]);
    }

    public function test_publicar_rascunho_com_kit_nao_aprovado_e_recusado_sem_nada_criado_no_ml(): void
    {
        $this->fakePublicacaoOk();
        [$rascunho] = $this->rascunhoComKit(MlAnuncioCriativoKit::STATUS_PRONTO, aprovados: 2);

        $resposta = $this->actingAs($this->admin())->postJson(
            route('mlb.anuncios.publicar', ['rascunho' => $rascunho->id]),
        );

        $resposta->assertStatus(422);
        $mensagem = $resposta->json('erros')[0]['mensagem'] ?? '';
        $this->assertStringContainsString('ainda não aprovado', $mensagem);

        $rascunho->refresh();
        $this->assertSame(MlAnuncioRascunho::STATUS_ERRO, $rascunho->status);

        // Nada foi criado no Mercado Livre — nem a detecção de modelo chegou a rodar.
        Http::assertNothingSent();
    }

    public function test_publicar_rascunho_com_kit_aprovado_envia_imagens_aprovadas_na_ordem_mesmo_com_autosave_reduzindo(): void
    {
        $this->fakePublicacaoOk('MLB777');

        // O autosave do wizard reduziu payload.pictures a 1 item ANTES da chamada — a armadilha 2.
        [$rascunho, $kit] = $this->rascunhoComKit(
            MlAnuncioCriativoKit::STATUS_APROVADO,
            aprovados: 3,
            minimoAprovadas: 3,
            pictures: [['source' => 'foto-do-autosave-sozinha.jpg']],
        );

        $resposta = $this->actingAs($this->admin())->postJson(
            route('mlb.anuncios.publicar', ['rascunho' => $rascunho->id]),
        );

        $resposta->assertOk()->assertJsonPath('ok', true)->assertJsonPath('ml_item_id', 'MLB777');

        $rascunho->refresh();
        $this->assertSame(MlAnuncioRascunho::STATUS_PUBLICADO, $rascunho->status);

        $slot1 = $kit->slots()->where('slot_indice', 1)->first();
        $slot2 = $kit->slots()->where('slot_indice', 2)->first();
        $slot3 = $kit->slots()->where('slot_indice', 3)->first();

        Http::assertSent(function ($request) use ($slot1, $slot2, $slot3) {
            if (! str_contains($request->url(), '/items') || str_contains($request->url(), 'description')) {
                return false;
            }

            return $request['pictures'] === [
                ['source' => $slot1->ml_picture_url],
                ['source' => $slot2->ml_picture_url],
                ['source' => $slot3->ml_picture_url],
            ];
        });
    }

    public function test_publicar_rascunho_sem_kit_continua_identico_ao_de_antes_do_creative_engine(): void
    {
        $this->fakePublicacaoOk('MLB555');
        [$rascunho] = $this->rascunhoSemKit([['source' => 'foto-unica-do-wizard.jpg']]);

        $resposta = $this->actingAs($this->admin())->postJson(
            route('mlb.anuncios.publicar', ['rascunho' => $rascunho->id]),
        );

        $resposta->assertOk()->assertJsonPath('ok', true)->assertJsonPath('ml_item_id', 'MLB555');

        $rascunho->refresh();
        $this->assertSame(MlAnuncioRascunho::STATUS_PUBLICADO, $rascunho->status);

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/items') || str_contains($request->url(), 'description')) {
                return false;
            }

            return $request['pictures'] === [['source' => 'foto-unica-do-wizard.jpg']];
        });
    }

    public function test_job_de_lote_com_kit_nao_aprovado_deixa_rascunho_em_erro_com_a_mesma_mensagem(): void
    {
        $this->fakePublicacaoOk();
        [$rascunho] = $this->rascunhoComKit(MlAnuncioCriativoKit::STATUS_PRONTO, aprovados: 2);

        $job = new PublicarAnuncioMlJob($rascunho->id);

        try {
            $job->handle(app(MlPublicacaoService::class));
            $this->fail('O job deveria ter relançado a exceção do gate.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('ainda não aprovado', $e->getMessage());
        }

        $rascunho->refresh();
        $this->assertSame(MlAnuncioRascunho::STATUS_ERRO, $rascunho->status);
        $mensagem = $rascunho->validation_errors[0]['mensagem'] ?? '';
        $this->assertStringContainsString('ainda não aprovado', $mensagem);

        Http::assertNothingSent();
    }
}
