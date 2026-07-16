<?php

namespace Tests\Feature\Phase86;

use App\Models\Company;
use App\Models\MlAnuncioRascunho;
use App\Models\MlbEmpresa;
use App\Models\MlToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Phase 86 — Histórico dos anúncios publicados (base do "Anunciar semelhante").
 *
 * O que blinda:
 *   (a) só `status=publicado` entra — rascunho/erro/publicando ficam de fora
 *       (é o inverso do massa(), que traz tudo MENOS publicado)
 *   (b) escopo por empresa: anúncio de outra empresa nunca aparece
 *   (c) a BUSCA não fura o escopo — o orWhere precisa estar agrupado, senão o
 *       `or` sobe ao topo do WHERE e vaza anúncio de outra empresa/status
 *   (d) ordenação por published_at desc
 *   (e) empresa sem MlToken → 404 (mesma trava do wizard e da grade)
 *   (f) consultor → 403 (middleware role:admin)
 *
 * Estratégia: RefreshDatabase (SQLite in-memory) + Http::fake — nunca ML real.
 *
 * @group phase86
 */
class HistoricoAnunciosTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake(); // nenhuma chamada real ao ML nestes testes
    }

    /** @test */
    public function historico_traz_apenas_os_publicados_da_empresa(): void
    {
        [$company, , $admin] = $this->criarFixture();

        $this->criarAnuncio($company, MlAnuncioRascunho::STATUS_PUBLICADO, 'Camiseta Publicada');
        $this->criarAnuncio($company, MlAnuncioRascunho::STATUS_RASCUNHO, 'Camiseta Rascunho');
        $this->criarAnuncio($company, MlAnuncioRascunho::STATUS_ERRO, 'Camiseta Com Erro');
        $this->criarAnuncio($company, MlAnuncioRascunho::STATUS_PUBLICANDO, 'Camiseta Publicando');

        $titulos = $this->titulosDoHistorico($admin, $company);

        $this->assertContains('Camiseta Publicada', $titulos);
        $this->assertNotContains('Camiseta Rascunho', $titulos, 'rascunho nao e historico');
        $this->assertNotContains('Camiseta Com Erro', $titulos, 'erro nao e historico');
        $this->assertNotContains('Camiseta Publicando', $titulos, 'ainda em voo nao e historico');
    }

    /** @test */
    public function historico_nao_vaza_anuncio_de_outra_empresa(): void
    {
        [$company, , $admin] = $this->criarFixture();
        [$outra] = $this->criarFixture();

        $this->criarAnuncio($company, MlAnuncioRascunho::STATUS_PUBLICADO, 'Minha Camiseta');
        $this->criarAnuncio($outra, MlAnuncioRascunho::STATUS_PUBLICADO, 'Camiseta da Outra Empresa');

        $titulos = $this->titulosDoHistorico($admin, $company);

        $this->assertContains('Minha Camiseta', $titulos);
        $this->assertNotContains('Camiseta da Outra Empresa', $titulos, 'VAZAMENTO entre empresas');
    }

    /** @test */
    public function busca_nao_fura_o_escopo_de_empresa_nem_de_status(): void
    {
        // O teste que tranca o orWhere agrupado: 3 "Camiseta", só 1 pode voltar.
        [$company, , $admin] = $this->criarFixture();
        [$outra] = $this->criarFixture();

        $this->criarAnuncio($company, MlAnuncioRascunho::STATUS_PUBLICADO, 'Camiseta Válida');
        $this->criarAnuncio($outra, MlAnuncioRascunho::STATUS_PUBLICADO, 'Camiseta de Outra Empresa');
        $this->criarAnuncio($company, MlAnuncioRascunho::STATUS_RASCUNHO, 'Camiseta Rascunho');

        $titulos = $this->titulosDoHistorico($admin, $company, ['busca' => 'Camiseta']);

        $this->assertSame(['Camiseta Válida'], $titulos,
            'a busca tem que respeitar company_id E status — orWhere solto vazaria os outros dois');
    }

    /** @test */
    public function historico_ordena_do_mais_recente_para_o_mais_antigo(): void
    {
        [$company, , $admin] = $this->criarFixture();

        $this->criarAnuncio($company, MlAnuncioRascunho::STATUS_PUBLICADO, 'Antigo', now()->subDays(5));
        $this->criarAnuncio($company, MlAnuncioRascunho::STATUS_PUBLICADO, 'Recente', now()->subHour());

        $this->assertSame(['Recente', 'Antigo'], $this->titulosDoHistorico($admin, $company));
    }

    /** @test */
    public function empresa_sem_conta_ml_devolve_404(): void
    {
        $admin   = $this->criarAdmin();
        $company = Company::factory()->create(); // sem MlToken

        $this->actingAs($admin)
            ->get("/mlb/anuncios/historico/{$company->id}")
            ->assertNotFound();
    }

    /** @test */
    public function consultor_nao_acessa_o_historico(): void
    {
        [$company] = $this->criarFixture();
        $consultor = User::factory()->create(['role' => 'consultor']);

        $this->actingAs($consultor)
            ->get("/mlb/anuncios/historico/{$company->id}")
            ->assertForbidden();
    }

    // ─── helpers ───

    private function titulosDoHistorico(User $admin, Company $company, array $query = []): array
    {
        $url = "/mlb/anuncios/historico/{$company->id}";
        if ($query) $url .= '?' . http_build_query($query);

        $response = $this->actingAs($admin)->get($url)->assertOk();

        return collect($response->viewData('page')['props']['anuncios']['data'])
            ->pluck('titulo')->all();
    }

    private function criarAnuncio(Company $company, string $status, string $titulo, $publishedAt = null): MlAnuncioRascunho
    {
        return MlAnuncioRascunho::create([
            'company_id'   => $company->id,
            'user_id'      => User::factory()->create()->id,
            'category_id'  => 'MLB123456',
            'listing_tier' => 'gold_special',
            'status'       => $status,
            'published_at' => $status === MlAnuncioRascunho::STATUS_PUBLICADO ? ($publishedAt ?? now()) : null,
            'ml_item_id'   => $status === MlAnuncioRascunho::STATUS_PUBLICADO ? 'MLB999' : null,
            'payload'      => [
                'title'    => $titulo,
                'price'    => 49.9,
                'pictures' => [['source' => 'https://x/foto.jpg']],
            ],
        ]);
    }

    private function criarAdmin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function criarFixture(): array
    {
        $admin   = $this->criarAdmin();
        $company = Company::factory()->create();

        MlToken::create([
            'company_id'        => $company->id,
            'ml_user_id'        => '1555596317',
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
            'nome'           => 'Empresa Historico ' . $company->id,
            'tipo'           => 'ASSESSORIA',
            'company_id'     => $company->id,
            'responsavel_id' => $admin->id,
        ]);

        return [$company, $empresa, $admin];
    }
}
