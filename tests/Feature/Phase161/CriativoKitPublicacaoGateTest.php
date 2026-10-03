<?php

namespace Tests\Feature\Phase161;

use App\Models\Company;
use App\Models\Configuracao;
use App\Models\MlAnuncioCriativo;
use App\Models\MlAnuncioCriativoKit;
use App\Models\MlAnuncioRascunho;
use App\Models\MlToken;
use App\Models\User;
use App\Services\Creative\CreativeKitPublicacao;
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
}
