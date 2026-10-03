<?php

namespace Tests\Feature\Publicador;

use App\Models\Company;
use App\Models\EstruturaAnuncio;
use App\Models\MlbEmpresa;
use App\Models\MlToken;
use App\Models\PubPublicacao;
use App\Models\PubPublicacaoItem;
use App\Models\User;
use App\Services\Publicador\ConferenciaService;
use App\Services\Publicador\EditorRascunhoService;
use App\Services\Publicador\PublicacaoService;
use App\Support\Portal\AtorDoPortal;
use App\Support\Publicador\Payload\MontadorDePlano;
use App\Support\Publicador\RegraViolada;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Publicador\Concerns\CenarioCadeira;
use Tests\TestCase;

/**
 * Critério 4 do ROADMAP da fase 164: uma `MlbEmpresa` (Incubadora) SEM Company
 * abre, confere e publica com o token de `ml_tokens.mlb_empresa_id`, e o MLB
 * criado não vai para a aba Anúncios (não há oferta — D16). O ML é simulado.
 *
 * Mutação documentada: se a conta vier da Company ou da âncora errada, o header
 * `Authorization` deixa de ser `fake-token-mlb-empresa` e os testes que olham
 * `Http::recorded()` quebram — há de propósito uma Company de MESMO id numérico,
 * com outro token, no banco.
 *
 * D21: a trava é sobre a âncora que publica; fora da lista, `CONTA-LIB` e nenhum
 * `POST /items`.
 */
class PublicaMlbEmpresaSemCompanyTest extends TestCase
{
    use CenarioCadeira;
    use RefreshDatabase;

    private int $posts = 0;

    private AtorDoPortal $ator;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->montarCenario('mlb_empresa');
        $this->ator = AtorDoPortal::daEquipe(User::factory()->create(['name' => 'Dev ECF']));

        // Company de MESMO id, com token e produto próprios: a conta errada seria usada se a âncora fosse confundida.
        $outra = Company::factory()->create(['id' => $this->empresa->id]);
        MlToken::create(['company_id' => $outra->id, 'ml_user_id' => '999', 'access_token' => 'token-da-company-errada', 'refresh_token' => 'x',
            'token_type' => 'bearer', 'expires_at' => now()->addHours(5), 'last_refreshed_at' => now(), 'status' => 'active', 'connected_at' => now()]);

        $this->fakeMl([
            '*/items/*/description' => fn () => Http::response(['text' => '', 'plain_text' => 'ok']),
            '*/items/MLB*' => fn (Request $q) => Http::response(['id' => basename(parse_url($q->url(), PHP_URL_PATH)), 'status' => 'active', 'sub_status' => [],
                'tags' => [], 'permalink' => 'https://produto.mercadolivre.com.br/x']),
            '*/items' => fn (Request $q) => Http::response(['id' => sprintf('MLB90000000%02d', ++$this->posts), 'user_product_id' => 'MLBU'.$this->posts, 'status' => 'active',
                'family_name' => $q->data()['family_name'] ?? null, 'listing_type_id' => $q->data()['listing_type_id'] ?? null], 201),
        ]);
    }

    private function postsDeItem(): int
    {
        return count(Http::recorded(fn (Request $q) => $q->method() === 'POST' && str_ends_with($q->url(), '/items')));
    }

    /** @return list<string> o Authorization de cada requisição cujo caminho contém o trecho */
    private function bearers(string $trecho): array
    {
        return Http::recorded(fn (Request $q) => str_contains($q->url(), $trecho))
            ->map(fn ($par) => $par[0]->header('Authorization')[0] ?? '')->unique()->values()->all();
    }

    private function tenta(): ?string
    {
        try {
            app(PublicacaoService::class)->iniciar($this->r->fresh(), $this->ator, cienteDosAvisos: true);

            return null;
        } catch (RegraViolada $e) {
            return $e->regra;
        }
    }

    public function test_produto_sem_oferta_tem_a_conta_da_mlb_empresa(): void
    {
        $this->assertNull($this->produto->oferta_id);
        $this->assertNull($this->produto->company_id);
        $conta = $this->r->fresh()->conta();

        $this->assertInstanceOf(MlbEmpresa::class, $conta);
        $this->assertSame($this->empresa->id, $conta->id);
    }

    public function test_abre_le_a_conta_com_o_token_da_mlb_empresa(): void
    {
        $r = app(EditorRascunhoService::class)->abrir($this->produto);

        $this->assertArrayNotHasKey('erro', (array) $r->step_state['conta']);
        $this->assertSame(MontadorDePlano::UP, $r->modelo_publicacao);
        $this->assertSame(['Bearer fake-token-mlb-empresa'], $this->bearers('/users/me'));
    }

    public function test_confere_e_publica_com_o_token_da_mlb_empresa_sem_tocar_na_aba_anuncios(): void
    {
        $v = app(ConferenciaService::class)->conferir($this->r->fresh());
        $this->assertContains($v->resultado, [ConferenciaService::OK, ConferenciaService::AVISOS], json_encode($v->issues, JSON_UNESCAPED_UNICODE));
        $this->assertSame(['Bearer fake-token-mlb-empresa'], $this->bearers('/items/validate'));

        $p = app(PublicacaoService::class)->iniciar($this->r->fresh(), $this->ator, cienteDosAvisos: true);
        $this->assertTrue(app(PublicacaoService::class)->executarFatia($p->fresh(), 45));

        $item = PubPublicacaoItem::where('publicacao_id', $p->id)->firstOrFail();
        $this->assertSame(PubPublicacaoItem::CREATED, $item->status);
        $this->assertSame('MLB9000000001', $item->ml_item_id);
        $this->assertSame(PubPublicacao::PUBLISHED, $p->fresh()->status);
        $this->assertSame(1, $this->postsDeItem());
        $this->assertSame(['Bearer fake-token-mlb-empresa'], $this->bearers('/items'), 'todas as chamadas do motor usam o token da MlbEmpresa');
        $this->assertStringNotContainsString('token-da-company-errada', json_encode(Http::recorded()->map(fn ($par) => $par[0]->headers())->all()));
        $this->assertSame(0, EstruturaAnuncio::count(), 'D16: sem oferta, nada vai para a aba Anúncios');
    }

    public function test_sem_token_abrir_grava_o_erro_conferir_e_local_e_publicar_falha_com_v_acc_01(): void
    {
        MlToken::where('mlb_empresa_id', $this->empresa->id)->delete();
        $antes = count(Http::recorded());

        $r = app(EditorRascunhoService::class)->abrir($this->produto);
        $this->assertArrayHasKey('erro', $r->step_state['conta']);

        // WR-B04: sem token = não liberada — a conferência é a local (D26); o V-ACC-01 é da publicação.
        $v = app(ConferenciaService::class)->conferir($this->r->fresh());
        $this->assertSame('L2', $v->camada);
        $this->assertSame('V-ACC-01', $v->respostas_ml['motivo']);

        $this->assertSame('V-ACC-01', $this->tenta());
        $this->assertSame(0, $this->postsDeItem());
        $this->assertSame($antes, count(Http::recorded()), 'sem token, nenhuma chamada ao ML (nem com o token da Company de mesmo id)');
    }

    public function test_conta_nao_liberada_nao_publica_e_nao_faz_post(): void
    {
        $v = app(ConferenciaService::class)->conferir($this->r->fresh());
        $this->assertContains($v->resultado, [ConferenciaService::OK, ConferenciaService::AVISOS]);

        config(['publicador.contas_liberadas' => ['companies' => [], 'mlb_empresas' => []]]);
        $this->assertSame('CONTA-LIB', $this->tenta());
        $this->assertSame(0, PubPublicacao::count(), 'recusa antes de gravar a publicação');
        $this->assertSame(0, $this->postsDeItem());
    }

    /** O que vale é a âncora que PUBLICA: Company liberada não destrava produto cujo token é da MlbEmpresa. */
    public function test_company_liberada_nao_libera_produto_cuja_ancora_com_token_e_a_mlb_empresa(): void
    {
        $company = Company::find($this->empresa->id);
        $this->produto->update(['company_id' => $company->id]);
        $v = app(ConferenciaService::class)->conferir($this->r->fresh());
        $this->assertContains($v->resultado, [ConferenciaService::OK, ConferenciaService::AVISOS]);

        config(['publicador.contas_liberadas' => ['companies' => [$company->id], 'mlb_empresas' => []]]);

        $this->assertInstanceOf(MlbEmpresa::class, $this->r->fresh()->conta(), 'MlbEmpresa com token vence a Company');
        $this->assertSame('CONTA-LIB', $this->tenta());
        $this->assertSame(0, $this->postsDeItem());

        config(['publicador.contas_liberadas' => ['companies' => [], 'mlb_empresas' => [$this->empresa->id]]]);
        $this->assertNull($this->tenta());
    }
}
