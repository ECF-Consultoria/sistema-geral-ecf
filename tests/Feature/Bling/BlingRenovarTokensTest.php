<?php

namespace Tests\Feature\Bling;

use App\Models\BlingConexao;
use App\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\Support\BlingFixtures;
use Tests\TestCase;

/**
 * Fase 184-04 Task 3 (BLING-13) — `bling:renovar-tokens` com keep-alive
 * ESPALHADO: no máximo 10 renovações por minuto, nunca a rajada do
 * `ml:refresh-tokens` (20 chamadas a `/oauth/token` em 60s bloqueiam o IP da
 * VPS por 60 minutos, para TODOS os clientes do Bling).
 */
class BlingRenovarTokensTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.bling.client_id' => 'client-id-de-teste',
            'services.bling.client_secret' => 'client-secret-de-teste',
            'services.bling.redirect' => 'https://portal.exemplo.com.br/bling/callback',
        ]);
    }

    private function conexaoQuaseExpirando(array $extra = []): BlingConexao
    {
        $empresa = Company::factory()->create();

        return BlingConexao::create(array_merge([
            'company_id' => $empresa->id,
            'access_token' => 'access-antigo',
            'refresh_token' => 'refresh-antigo',
            'status' => BlingConexao::STATUS_ATIVA,
            'expires_at' => now()->addMinutes(30),
            'last_refreshed_at' => now()->subHours(1),
        ], $extra));
    }

    public function test_renova_as_tres_conexoes_na_janela_e_sai_com_sucesso(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->conexaoQuaseExpirando(['expires_at' => now()->addMinutes(10 + $i)]);
        }

        Http::fake([
            'https://api.bling.com.br/Api/v3/oauth/token' => Http::response(BlingFixtures::ler('oauth-token'), 200),
        ]);

        $this->artisan('bling:renovar-tokens')->assertExitCode(0);

        Http::assertSentCount(3);
        // access_token é `encrypted`: a comparação precisa ler pelo model
        // (que decifra), nunca pela query builder contra a coluna crua.
        $renovadas = BlingConexao::all()->filter(fn ($c) => $c->access_token !== 'access-antigo');
        $this->assertCount(3, $renovadas);
    }

    public function test_vinte_e_cinco_conexoes_respeitam_o_teto_de_dez_por_minuto(): void
    {
        Sleep::fake();

        for ($i = 0; $i < 25; $i++) {
            $this->conexaoQuaseExpirando(['expires_at' => now()->addMinutes(10 + $i)]);
        }

        Http::fake([
            'https://api.bling.com.br/Api/v3/oauth/token' => Http::response(BlingFixtures::ler('oauth-token'), 200),
        ]);

        $this->artisan('bling:renovar-tokens')->assertExitCode(0);

        // Todas as 25 são processadas (no fim da execução), mas em lotes de
        // no máximo 10 por minuto: 25 conexões → pausa depois da 10ª e da 20ª.
        Http::assertSentCount(25);
        Sleep::assertSleptTimes(2);
    }

    public function test_conexao_revogada_divergente_ou_sem_refresh_token_nao_e_tocada(): void
    {
        $this->conexaoQuaseExpirando(['status' => BlingConexao::STATUS_REVOGADA]);
        $this->conexaoQuaseExpirando(['status' => BlingConexao::STATUS_DIVERGENTE]);
        $this->conexaoQuaseExpirando(['refresh_token' => null]);

        Http::fake([
            'https://api.bling.com.br/Api/v3/oauth/token' => Http::response(BlingFixtures::ler('oauth-token'), 200),
        ]);

        $this->artisan('bling:renovar-tokens')->assertExitCode(0);

        // Asserção NEGATIVA: renovar uma conexão divergente gravaria token numa
        // conexão que não deveria ter nenhum.
        Http::assertSentCount(0);
    }

    public function test_uma_conexao_com_erro_500_nao_interrompe_as_demais(): void
    {
        $this->conexaoQuaseExpirando(['expires_at' => now()->addMinutes(10)]);
        $this->conexaoQuaseExpirando(['expires_at' => now()->addMinutes(11)]);
        $this->conexaoQuaseExpirando(['expires_at' => now()->addMinutes(12)]);

        Http::fakeSequence('https://api.bling.com.br/Api/v3/oauth/token')
            ->push(BlingFixtures::ler('oauth-token'), 200)
            ->push(['error' => 'internal'], 500)
            ->push(BlingFixtures::ler('oauth-token'), 200);

        $this->artisan('bling:renovar-tokens')->assertExitCode(0);

        // O loop engole o erro por item e segue — a 1ª e a 3ª renovaram mesmo
        // com a 2ª falhando no meio. (access_token é `encrypted`: a comparação
        // precisa ler pelo model, nunca pela query builder contra a coluna crua.)
        Http::assertSentCount(3);
        $renovadas = BlingConexao::all()->filter(fn ($c) => $c->access_token !== 'access-antigo');
        $this->assertCount(2, $renovadas);
    }

    public function test_dry_run_lista_e_nao_faz_nenhuma_requisicao(): void
    {
        $this->conexaoQuaseExpirando();

        Http::fake();

        $this->artisan('bling:renovar-tokens --dry-run')->assertExitCode(0);

        Http::assertNothingSent();
    }
}
