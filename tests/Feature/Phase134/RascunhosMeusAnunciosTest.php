<?php

namespace Tests\Feature\Phase134;

use App\Jobs\PublicarAnuncioMlJob;
use App\Models\Company;
use App\Models\MlAnuncioRascunho;
use App\Models\MlbEmpresa;
use App\Models\MlToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Fase 134 (Plano 09) — sub-aba Rascunhos + o que a movimentação do bloco
 * do wizard não pode quebrar:
 *
 *   (a) meus(?sub=rascunhos) lista TODOS os rascunhos da empresa, com
 *       `categoria`/`foto` preenchidos e SEM `payload` (T-134-23 — o campo
 *       mais pesado do registro não paga por uma listagem que só mostra)
 *   (b) T-134-01 — o mesmo escopo por company_id de sempre, também aqui
 *   (c) T-134-22 — "Publicar lote" (BULK-01) continua funcionando depois de
 *       migrar da aside do wizard para a sub-aba Rascunhos: o endpoint não
 *       muda, só quem o chama
 *   (d) a correção da Task 1 — o deep-link `?rascunho=N` do wizard funciona
 *       para um rascunho de qualquer idade, não só os 50 mais recentes
 *
 * Estratégia: RefreshDatabase (SQLite in-memory) + Http::fake() — nunca ML real.
 *
 * @group phase134
 */
class RascunhosMeusAnunciosTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake(); // nenhuma chamada real ao ML nestes testes
    }

    /** @test */
    public function sub_aba_rascunhos_lista_os_rascunhos_da_empresa(): void
    {
        [$company, , $admin] = $this->criarFixture();

        MlAnuncioRascunho::create([
            'company_id'   => $company->id,
            'user_id'      => $admin->id,
            'category_id'  => 'MLB179762',
            'payload'      => [
                'title'    => 'Camiseta Polo Azul',
                'pictures' => [['source' => 'https://http2.mlstatic.com/foto.jpg']],
            ],
            'status'       => MlAnuncioRascunho::STATUS_RASCUNHO,
            'listing_tier' => 'gold_special',
        ]);

        $props = $this->propsDaTela($admin, $company, ['sub' => 'rascunhos']);

        $this->assertCount(1, $props['rascunhos'], 'a sub-aba Rascunhos tem que listar o rascunho da empresa');

        $rascunho = $props['rascunhos'][0];
        $this->assertSame('Camiseta Polo Azul', $rascunho['titulo']);
        $this->assertNotNull($rascunho['categoria'], 'categoria precisa estar preenchida (nomeCategoria() ou fallback)');
        $this->assertSame('https://http2.mlstatic.com/foto.jpg', $rascunho['foto']);
        $this->assertArrayNotHasKey('payload', $rascunho, 'T-134-23: payload completo não pode ir para a listagem — é o campo mais pesado do registro');
    }

    /** @test */
    public function sub_aba_rascunhos_nao_vaza_rascunho_de_outra_empresa(): void
    {
        [$company, , $admin] = $this->criarFixture();
        [$outra, , $outraAdmin] = $this->criarFixture();

        MlAnuncioRascunho::create([
            'company_id' => $company->id,
            'user_id'    => $admin->id,
            'payload'    => ['title' => 'Rascunho Da Minha Empresa'],
            'status'     => MlAnuncioRascunho::STATUS_RASCUNHO,
        ]);
        MlAnuncioRascunho::create([
            'company_id' => $outra->id,
            'user_id'    => $outraAdmin->id,
            'payload'    => ['title' => 'Rascunho Da Outra Empresa'],
            'status'     => MlAnuncioRascunho::STATUS_RASCUNHO,
        ]);

        $props = $this->propsDaTela($admin, $company, ['sub' => 'rascunhos']);

        $titulos = collect($props['rascunhos'])->pluck('titulo')->all();
        $this->assertSame(['Rascunho Da Minha Empresa'], $titulos, 'VAZAMENTO entre empresas na sub-aba Rascunhos');
    }

    /** @test */
    public function publicar_lote_continua_funcionando_a_partir_da_tela_nova(): void
    {
        // T-134-22: o endpoint BULK-01 não muda — só a tela que o chama.
        // Mesmo contrato já coberto por tests/Feature/Phase80/PublicarLoteTest.php.
        Queue::fake();

        [$company, , $admin] = $this->criarFixture();

        $rascunhos = collect([1, 2])->map(fn ($i) => MlAnuncioRascunho::create([
            'company_id'   => $company->id,
            'user_id'      => $admin->id,
            'category_id'  => 'MLB179762',
            'payload'      => ['title' => "Produto {$i}", 'listing_type_id' => 'gold_special'],
            'status'       => MlAnuncioRascunho::STATUS_RASCUNHO,
            'listing_tier' => 'gold_special',
        ]));

        $response = $this->actingAs($admin)->postJson(
            "/mlb/anuncios/empresa/{$company->id}/publicar-lote",
            ['company_id' => $company->id, 'rascunho_ids' => $rascunhos->pluck('id')->all()],
        );

        $response->assertOk()->assertJsonPath('ok', true);
        Queue::assertPushed(PublicarAnuncioMlJob::class, 2);

        foreach ($rascunhos as $r) {
            $this->assertDatabaseHas('ml_anuncio_rascunhos', [
                'id'     => $r->id,
                'status' => MlAnuncioRascunho::STATUS_PUBLICANDO,
            ]);
        }
    }

    /** @test */
    public function deep_link_do_wizard_abre_rascunho_antigo(): void
    {
        // Task 1: a sub-aba Rascunhos agora lista o acervo inteiro, então o
        // publicador clica em rascunhos fora dos 50 mais recentes com
        // frequência — o wizard precisa achar o alvo mesmo assim.
        [$company, , $admin] = $this->criarFixture();

        // 60 rascunhos com created_at espaçado — os 10 primeiros (mais
        // antigos) ficam de fora do latest()->limit(50) por definição.
        $rascunhoAntigo = null;
        for ($i = 1; $i <= 60; $i++) {
            $r = MlAnuncioRascunho::create([
                'company_id' => $company->id,
                'user_id'    => $admin->id,
                'payload'    => ['title' => "Rascunho {$i}"],
                'status'     => MlAnuncioRascunho::STATUS_RASCUNHO,
                'created_at' => now()->subMinutes(120 - $i),
                'updated_at' => now()->subMinutes(120 - $i),
            ]);
            if ($i === 1) {
                $rascunhoAntigo = $r; // o mais antigo de todos — bem fora dos 50 mais recentes
            }
        }

        $response = $this->actingAs($admin)
            ->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => $this->inertiaVersion()])
            ->get(route('mlb.anuncios.wizard', ['company' => $company->id, 'rascunho' => $rascunhoAntigo->id]))
            ->assertOk();

        $props = $response->json('props');

        $this->assertSame($rascunhoAntigo->id, $props['abrirRascunhoId']);

        $idsRecebidos = collect($props['rascunhos'])->pluck('id')->all();
        $this->assertContains(
            $rascunhoAntigo->id,
            $idsRecebidos,
            'o rascunho mais antigo precisa estar na prop rascunhos do wizard mesmo fora dos 50 mais recentes — senão o deep-link abre em branco',
        );
    }

    // ─── Helpers de fixture (mesmo padrão de MeusAnunciosTest/PublicarLoteTest) ───

    private function propsDaTela(User $admin, Company $company, array $query = []): array
    {
        $url = route('mlb.anuncios.meus', $company);
        if ($query) {
            $url .= '?' . http_build_query($query);
        }

        $response = $this->actingAs($admin)
            ->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => $this->inertiaVersion()])
            ->get($url)
            ->assertOk();

        return $response->json('props');
    }

    private function inertiaVersion(): string
    {
        return (string) app(\App\Http\Middleware\HandleInertiaRequests::class)->version(request());
    }

    private function criarAdmin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    /** Cria Company + MlToken ativo + MlbEmpresa (mesmo padrão de MeusAnunciosTest). */
    private function criarFixture(): array
    {
        $admin   = $this->criarAdmin();
        $company = Company::factory()->create();

        MlToken::create([
            'company_id'        => $company->id,
            'ml_user_id'        => (string) random_int(100000000, 999999999),
            'access_token'      => 'fake-access-token',
            'refresh_token'     => 'fake-refresh-token',
            'token_type'        => 'bearer',
            'scope'             => 'read write offline_access',
            'expires_at'        => now()->addDays(6),
            'last_refreshed_at' => now(),
            'status'            => 'active',
            'connected_at'      => now(),
        ]);

        $empresa = MlbEmpresa::create([
            'nome'           => 'Empresa Rascunhos Teste ' . $company->id,
            'tipo'           => 'ASSESSORIA',
            'company_id'     => $company->id,
            'responsavel_id' => $admin->id,
        ]);

        return [$company, $empresa, $admin];
    }
}
