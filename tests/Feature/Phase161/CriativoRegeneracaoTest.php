<?php

namespace Tests\Feature\Phase161;

use App\Jobs\GerarCriativoIaJob;
use App\Models\Company;
use App\Models\Configuracao;
use App\Models\MlAnuncioCriativo;
use App\Models\MlAnuncioCriativoKit;
use App\Models\MlAnuncioRascunho;
use App\Models\MlbEmpresa;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Regeneração de UM slot do kit (Fase 161, Plano 03, Task 1) — APROV-02.
 *
 * O caso central é o que afirma que os outros 6 slots ficam BYTE A BYTE
 * iguais depois de regenerar um (status/imagem_path/tentativas inalterados)
 * — é essa prova que diferencia "regenerar um slot" de "redisparar o kit".
 *
 * `regeneracoes` (do criativo e do kit) conta CLIQUE do operador, nunca
 * `tentativas` (que também sobe em retentativa automática do Laravel) — ver
 * docblock da migration `..._add_regeneracoes_...`.
 *
 * `Http::preventStrayRequests()` garante que nenhum teste aqui chama a API
 * real da Gemini nem do Mercado Livre.
 */
class CriativoRegeneracaoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Http::preventStrayRequests();
        Configuracao::set('creative_engine_ativo', '1');

        config([
            'services.creative.kit.max_imagens'           => 14,
            'services.creative.kit.max_regeneracoes_asset' => 3,
            'services.creative.kit.max_regeneracoes_kit'   => 7,
        ]);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function naoAdmin(): User
    {
        return User::factory()->create(['role' => 'consultor']);
    }

    private function companyComResponsavel(?int $responsavelId = null): array
    {
        $company = Company::factory()->create(['name' => 'Empresa Teste']);
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

        return [$company, $userId, $mlbEmpresaId];
    }

    /**
     * Monta um kit com portador + 7 slots `pronto` (já geraram alguma
     * imagem), cada um com `imagem_path`/`tentativas` preenchidos — é o
     * estado mais comum antes de uma regeneração.
     *
     * @return array{0: MlAnuncioCriativoKit, 1: \Illuminate\Support\Collection<int, MlAnuncioCriativo>}
     */
    private function kitComSlotsProntos(?int $responsavelId = null): array
    {
        Storage::fake('local');

        [$company, $userId, $mlbEmpresaId] = $this->companyComResponsavel($responsavelId);

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

        $portador = MlAnuncioCriativo::create([
            'token'       => Str::random(32),
            'company_id'  => $company->id,
            'rascunho_id' => $rascunho->id,
            'user_id'     => $userId,
            'slot'        => 'referencia',
            'status'      => MlAnuncioCriativo::STATUS_PRONTO,
            'referencias' => [
                ['indice' => 0, 'nome' => 'foto.jpg', 'path' => 'creative-referencias/x/0.jpg', 'mime' => 'image/jpeg'],
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
            'minimo_aprovadas'       => 3,
            'imagens_geradas'        => 7,
        ]);
        $portador->update(['kit_id' => $kit->id]);

        $tipos = ['hero', 'white_background', 'angles', 'detail', 'lifestyle', 'benefits', 'specifications'];
        $slots = collect();
        foreach ($tipos as $i => $tipo) {
            $token = Str::random(32);
            Storage::disk('local')->put("creative-geradas/{$token}/{$tipo}.jpg", 'bytes-da-imagem-gerada');

            $slots->push(MlAnuncioCriativo::create([
                'token'        => $token,
                'company_id'   => $company->id,
                'rascunho_id'  => $rascunho->id,
                'user_id'      => $userId,
                'kit_id'       => $kit->id,
                'slot'         => $tipo,
                'slot_indice'  => $i + 1,
                'slot_plano'   => ['indice' => $i + 1, 'tipo' => $tipo, 'objetivo' => "Objetivo {$tipo}."],
                'status'       => MlAnuncioCriativo::STATUS_PRONTO,
                'imagem_path'  => "creative-geradas/{$token}/{$tipo}.jpg",
                'imagem_mime'  => 'image/jpeg',
                'tentativas'   => 1,
                'modelo'       => 'modelo-imagem-teste',
            ]));
        }

        return [$kit->fresh(), $slots];
    }

    // ═══ Caminho feliz — reabre o slot, não toca nos outros 6 ═══════════

    public function test_regenerar_reabre_so_o_slot_pedido_e_enfileira_um_job(): void
    {
        Queue::fake();
        [$kit, $slots] = $this->kitComSlotsProntos();
        $slotAlvo = $slots->firstWhere('slot', 'benefits');
        $planoOriginal = $slotAlvo->slot_plano;

        $resposta = $this->actingAs($this->admin())->postJson(
            route('mlb.anuncios.criativo.regenerar', ['token' => $slotAlvo->token]),
        );

        $resposta->assertStatus(202)
            ->assertJsonPath('status', MlAnuncioCriativo::STATUS_PENDENTE);

        $slotAlvo->refresh();
        $this->assertSame(MlAnuncioCriativo::STATUS_PENDENTE, $slotAlvo->status);
        $this->assertSame(1, $slotAlvo->regeneracoes);
        // slot_plano é EXATAMENTE o mesmo — regenerar nunca replaneja (Decisão 10).
        $this->assertSame($planoOriginal, $slotAlvo->slot_plano);

        Queue::assertPushedOn('creative', GerarCriativoIaJob::class);
        Queue::assertPushed(GerarCriativoIaJob::class, function (GerarCriativoIaJob $job) use ($slotAlvo) {
            return $job->criativoId === $slotAlvo->id;
        });
        Queue::assertPushed(GerarCriativoIaJob::class, 1);

        $kit->refresh();
        $this->assertSame(1, $kit->regeneracoes);

        // Os outros 6 ficam byte a byte iguais.
        foreach ($slots as $slot) {
            if ($slot->id === $slotAlvo->id) {
                continue;
            }

            $slot->refresh();
            $this->assertSame(MlAnuncioCriativo::STATUS_PRONTO, $slot->status, "slot {$slot->slot} não deveria ter mudado de status");
            $this->assertNotNull($slot->imagem_path, "slot {$slot->slot} não deveria ter perdido a imagem");
            $this->assertSame(1, $slot->tentativas, "slot {$slot->slot} não deveria ter mudado tentativas");
            $this->assertSame(0, $slot->regeneracoes, "slot {$slot->slot} não deveria ter sido contado como regenerado");
        }
    }

    public function test_slot_em_erro_tambem_pode_ser_regenerado(): void
    {
        Queue::fake();
        [, $slots] = $this->kitComSlotsProntos();
        $slotAlvo = $slots->first();
        $slotAlvo->update([
            'status'        => MlAnuncioCriativo::STATUS_ERRO,
            'erro_mensagem' => 'Falha anterior qualquer.',
        ]);

        $resposta = $this->actingAs($this->admin())->postJson(
            route('mlb.anuncios.criativo.regenerar', ['token' => $slotAlvo->token]),
        );

        $resposta->assertStatus(202);

        $slotAlvo->refresh();
        $this->assertSame(MlAnuncioCriativo::STATUS_PENDENTE, $slotAlvo->status);
        $this->assertNull($slotAlvo->erro_mensagem);
        Queue::assertPushed(GerarCriativoIaJob::class, 1);
    }

    // ═══ Estado é o guarda ═══════════════════════════════════════════════

    public function test_regenerar_recusa_slot_aprovado(): void
    {
        Queue::fake();
        [, $slots] = $this->kitComSlotsProntos();
        $slotAlvo = $slots->first();
        $slotAlvo->update(['status' => MlAnuncioCriativo::STATUS_APROVADO]);

        $resposta = $this->actingAs($this->admin())->postJson(
            route('mlb.anuncios.criativo.regenerar', ['token' => $slotAlvo->token]),
        );

        $resposta->assertStatus(422);
        $mensagem = $resposta->json('erros')[0]['mensagem'] ?? null;
        $this->assertNotEmpty($mensagem);

        Queue::assertNothingPushed();
        $this->assertSame(MlAnuncioCriativo::STATUS_APROVADO, $slotAlvo->fresh()->status);
    }

    public function test_regenerar_recusa_slot_pendente_ou_rodando(): void
    {
        Queue::fake();

        foreach ([MlAnuncioCriativo::STATUS_PENDENTE, MlAnuncioCriativo::STATUS_RODANDO] as $status) {
            [, $slots] = $this->kitComSlotsProntos();
            $slotAlvo = $slots->first();
            $slotAlvo->update(['status' => $status]);

            $resposta = $this->actingAs($this->admin())->postJson(
                route('mlb.anuncios.criativo.regenerar', ['token' => $slotAlvo->token]),
            );

            $resposta->assertStatus(422);
            $this->assertSame($status, $slotAlvo->fresh()->status);
        }

        Queue::assertNothingPushed();
    }

    public function test_regenerar_recusa_criativo_sem_kit(): void
    {
        Queue::fake();
        Storage::fake('local');

        $company  = Company::factory()->create();
        $rascunho = MlAnuncioRascunho::create([
            'company_id'  => $company->id,
            'user_id'     => $this->admin()->id,
            'category_id' => 'MLB1574',
            'payload'     => ['title' => 'x', 'category_id' => 'MLB1574', 'description' => 'x', 'attributes' => [], 'pictures' => []],
            'status'      => MlAnuncioRascunho::STATUS_RASCUNHO,
        ]);
        $token = Str::random(32);
        Storage::disk('local')->put("creative-geradas/{$token}/hero.jpg", 'bytes');
        $criativoSemKit = MlAnuncioCriativo::create([
            'token'       => $token,
            'company_id'  => $company->id,
            'rascunho_id' => $rascunho->id,
            'user_id'     => $rascunho->user_id,
            'slot'        => 'hero',
            'status'      => MlAnuncioCriativo::STATUS_PRONTO,
            'imagem_path' => "creative-geradas/{$token}/hero.jpg",
        ]);

        $resposta = $this->actingAs($this->admin())->postJson(
            route('mlb.anuncios.criativo.regenerar', ['token' => $criativoSemKit->token]),
        );

        $resposta->assertStatus(422);
        $mensagem = $resposta->json('erros')[0]['mensagem'] ?? null;
        $this->assertStringContainsString('kit', mb_strtolower($mensagem));

        Queue::assertNothingPushed();
    }

    // ═══ Tetos — asset e kit ═════════════════════════════════════════════

    public function test_regenerar_recusa_quando_asset_atinge_teto_de_regeneracoes(): void
    {
        config(['services.creative.kit.max_regeneracoes_asset' => 3]);
        Queue::fake();
        [, $slots] = $this->kitComSlotsProntos();
        $slotAlvo = $slots->first();
        $slotAlvo->update(['regeneracoes' => 3]);

        $resposta = $this->actingAs($this->admin())->postJson(
            route('mlb.anuncios.criativo.regenerar', ['token' => $slotAlvo->token]),
        );

        $resposta->assertStatus(422);
        $mensagem = $resposta->json('erros')[0]['mensagem'] ?? '';
        $this->assertStringContainsString('3', $mensagem);

        Queue::assertNothingPushed();
        $this->assertSame(3, $slotAlvo->fresh()->regeneracoes);
    }

    public function test_regenerar_recusa_quando_kit_atinge_teto_de_regeneracoes(): void
    {
        config(['services.creative.kit.max_regeneracoes_kit' => 7]);
        Queue::fake();
        [$kit, $slots] = $this->kitComSlotsProntos();
        $kit->update(['regeneracoes' => 7]);
        $slotAlvo = $slots->first();

        $resposta = $this->actingAs($this->admin())->postJson(
            route('mlb.anuncios.criativo.regenerar', ['token' => $slotAlvo->token]),
        );

        $resposta->assertStatus(422);
        Queue::assertNothingPushed();
    }

    public function test_regenerar_recusa_quando_kit_atinge_teto_de_imagens(): void
    {
        config(['services.creative.kit.max_imagens' => 14]);
        Queue::fake();
        [$kit, $slots] = $this->kitComSlotsProntos();
        $kit->update(['imagens_geradas' => 14]);
        $slotAlvo = $slots->first();

        $resposta = $this->actingAs($this->admin())->postJson(
            route('mlb.anuncios.criativo.regenerar', ['token' => $slotAlvo->token]),
        );

        $resposta->assertStatus(422);
        Queue::assertNothingPushed();
    }

    // ═══ OPS-03 — chave desligada ════════════════════════════════════════

    public function test_chave_desligada_devolve_404(): void
    {
        Configuracao::set('creative_engine_ativo', '0');
        Queue::fake();
        [, $slots] = $this->kitComSlotsProntos();

        $resposta = $this->actingAs($this->admin())->postJson(
            route('mlb.anuncios.criativo.regenerar', ['token' => $slots->first()->token]),
        );

        $resposta->assertStatus(404);
        Queue::assertNothingPushed();
    }

    // ═══ Autorização / escopo ════════════════════════════════════════════

    public function test_publicador_fora_de_escopo_recebe_403_sem_enfileirar(): void
    {
        Queue::fake();
        $outroUsuario = User::factory()->create(['role' => 'consultor']);
        [, $slots] = $this->kitComSlotsProntos($outroUsuario->id);

        $publicadorForaDeEscopo = $this->naoAdmin();

        $resposta = $this->actingAs($publicadorForaDeEscopo)->postJson(
            route('mlb.anuncios.criativo.regenerar', ['token' => $slots->first()->token]),
        );

        $resposta->assertStatus(403);
        Queue::assertNothingPushed();
    }

    public function test_sem_permissao_explicita_recebe_403(): void
    {
        Queue::fake();
        Configuracao::set('creative_engine_usuarios', '999999');
        [, $slots] = $this->kitComSlotsProntos();

        $resposta = $this->actingAs($this->admin())->postJson(
            route('mlb.anuncios.criativo.regenerar', ['token' => $slots->first()->token]),
        );

        $resposta->assertStatus(403);
        Queue::assertNothingPushed();
    }

    // ═══ Duplo clique não enfileira dois jobs para o mesmo slot ═════════

    public function test_duplo_clique_na_mesma_janela_nao_enfileira_duas_vezes(): void
    {
        Queue::fake();
        [, $slots] = $this->kitComSlotsProntos();
        $slotAlvo = $slots->first();

        $primeira = $this->actingAs($this->admin())->postJson(
            route('mlb.anuncios.criativo.regenerar', ['token' => $slotAlvo->token]),
        );
        $primeira->assertStatus(202);

        // 2ª chamada encontra o slot já `pendente` — recusada antes de
        // enfileirar de novo (a unicidade do job é a 2ª camada).
        $segunda = $this->actingAs($this->admin())->postJson(
            route('mlb.anuncios.criativo.regenerar', ['token' => $slotAlvo->token]),
        );
        $segunda->assertStatus(422);

        Queue::assertPushed(GerarCriativoIaJob::class, 1);
    }

    // ═══ GET kit.status devolve regeneracoes/regeneracoes_restantes ════

    public function test_status_do_kit_devolve_regeneracoes_e_restantes_por_slot(): void
    {
        config(['services.creative.kit.max_regeneracoes_asset' => 3]);
        [, $slots] = $this->kitComSlotsProntos();
        $slotAlvo = $slots->first();
        $slotAlvo->update(['regeneracoes' => 1]);
        $kit = $slotAlvo->kit;

        $resposta = $this->actingAs($this->admin())->getJson(
            route('mlb.anuncios.criativo.kit.status', ['kit' => $kit->token]),
        );

        $resposta->assertOk();

        $slotJson = collect($resposta->json('slots'))->firstWhere('indice', $slotAlvo->slot_indice);
        $this->assertSame(1, $slotJson['regeneracoes']);
        $this->assertSame(2, $slotJson['regeneracoes_restantes']);
    }
}
