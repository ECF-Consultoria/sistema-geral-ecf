<?php

namespace Tests\Feature\Quick261003L8o;

use App\Models\Company;
use App\Models\Configuracao;
use App\Models\MlAnuncioCriativoKit;
use App\Models\MlAnuncioRascunho;
use App\Models\User;
use Illuminate\Cache\RateLimiter as CacheRateLimiter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Teto de custo do Creative Engine que fala português (Quick 261003-l8o,
 * Task 3, correção 4) — o 429 que o operador viu era o padrão do Laravel
 * ("Too many attempts."), em inglês e sem prazo. O LIMITE continua sendo
 * TETO DE CUSTO (não sai); só a RESPOSTA muda.
 *
 * `CACHE_STORE=array` do `phpunit.xml` já isola o bucket de rate limit por
 * teste — não precisa limpar cache no `setUp()`.
 */
class LimiteDeCustoEmPtBrTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Configuracao::set('creative_engine_ativo', '1');

        config(['services.creative.kit.max_imagens' => 14]);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    /** Kit `planejado` sem slots — basta para exercitar o throttle da rota (a lógica de negócio do despacho não é o que este teste prova). */
    private function kitPlanejado(): MlAnuncioCriativoKit
    {
        $company  = Company::factory()->create();
        $rascunho = MlAnuncioRascunho::create([
            'company_id'  => $company->id,
            'user_id'     => User::factory()->create()->id,
            'category_id' => 'MLB1574',
            'payload'     => ['title' => 'x', 'category_id' => 'MLB1574', 'description' => 'x', 'attributes' => [], 'pictures' => []],
            'status'      => MlAnuncioRascunho::STATUS_RASCUNHO,
        ]);

        return MlAnuncioCriativoKit::create([
            'token'       => Str::random(32),
            'company_id'  => $company->id,
            'rascunho_id' => $rascunho->id,
            'user_id'     => $rascunho->user_id,
            'status'      => MlAnuncioCriativoKit::STATUS_PLANEJADO,
            'total_slots' => 0,
        ]);
    }

    // ═══ 5ª chamada no mesmo minuto → 429 em pt-BR com Retry-After ══════

    public function test_quinta_chamada_no_mesmo_minuto_devolve_429_em_pt_br_com_retry_after(): void
    {
        Queue::fake();
        $kit   = $this->kitPlanejado();
        $admin = $this->admin();

        $ultima = null;
        for ($i = 1; $i <= 5; $i++) {
            $ultima = $this->actingAs($admin)->postJson(
                route('mlb.anuncios.criativo.kit.gerar', ['kit' => $kit->token]),
            );
        }

        $ultima->assertStatus(429);

        $retryAfter = (int) $ultima->headers->get('Retry-After');
        $this->assertGreaterThanOrEqual(1, $retryAfter);

        $mensagem = $ultima->json('erros.0.mensagem');
        $this->assertStringContainsString('Tente de novo em', $mensagem);
        $this->assertStringContainsString('segundos', $mensagem);
        $this->assertStringNotContainsString('Too many', $mensagem);
    }

    // ═══ Limite é por USUÁRIO, não por IP ════════════════════════════════

    public function test_limite_e_por_usuario_nao_por_ip(): void
    {
        Queue::fake();
        $kit      = $this->kitPlanejado();
        $usuarioA = $this->admin();
        $usuarioB = $this->admin();

        for ($i = 1; $i <= 4; $i++) {
            $this->actingAs($usuarioA)->postJson(
                route('mlb.anuncios.criativo.kit.gerar', ['kit' => $kit->token]),
            );
        }

        $estourouA = $this->actingAs($usuarioA)->postJson(
            route('mlb.anuncios.criativo.kit.gerar', ['kit' => $kit->token]),
        );
        $estourouA->assertStatus(429);

        // Usuário B (mesmo escopo, mesma rota) ainda recebe 202 — o estouro
        // de A não contaminou o bucket de B.
        $respostaB = $this->actingAs($usuarioB)->postJson(
            route('mlb.anuncios.criativo.kit.gerar', ['kit' => $kit->token]),
        );
        $respostaB->assertStatus(202);
    }

    // ═══ Sem termo em inglês nem detalhe interno ════════════════════════

    public function test_mensagem_nao_tem_termo_em_ingles_nem_detalhe_interno(): void
    {
        Queue::fake();
        $kit   = $this->kitPlanejado();
        $admin = $this->admin();

        for ($i = 1; $i <= 4; $i++) {
            $this->actingAs($admin)->postJson(
                route('mlb.anuncios.criativo.kit.gerar', ['kit' => $kit->token]),
            );
        }

        $resposta = $this->actingAs($admin)->postJson(
            route('mlb.anuncios.criativo.kit.gerar', ['kit' => $kit->token]),
        );

        $resposta->assertStatus(429);

        // Conferido na MENSAGEM crua (não no JSON re-codificado): `json_encode`
        // escapa acentuação em sequências `\uXXXX` que colidem por acidente
        // com dígitos de teste (ex.: "—" vira "—", que CONTÉM o dígito
        // "1") — um falso-positivo de fuga de detalhe interno que não é real.
        $mensagem = $resposta->json('erros.0.mensagem');

        foreach (['Too many', 'Attempts', 'RateLimiter', 'throttle'] as $termo) {
            $this->assertStringNotContainsString($termo, $mensagem);
        }

        // id do kit: nunca nomeado na mensagem (nem como "kit {id}" nem cru).
        $this->assertStringNotContainsString("kit {$kit->id}", $mensagem);
        $this->assertStringNotContainsString((string) $kit->token, $mensagem);
    }

    // ═══ creative-regenerar e creative-kit-planejar — mesmo formato ═════
    // Chama o callback registrado direto (evita 13 requisições HTTP só
    // para provar o formato).

    public function test_creative_regenerar_e_creative_kit_planejar_respondem_no_mesmo_formato(): void
    {
        $admin = $this->admin();

        $request = Request::create('/qualquer', 'POST');
        $request->setUserResolver(fn () => $admin);

        foreach (['creative-regenerar', 'creative-kit-planejar'] as $nome) {
            $limiter = app(CacheRateLimiter::class)->limiter($nome);
            $limit   = $limiter($request);

            $resposta = ($limit->responseCallback)($request, ['Retry-After' => 30]);

            $this->assertSame(429, $resposta->getStatusCode());

            $dados = json_decode($resposta->getContent(), true);
            $this->assertSame(30, $dados['retry_after']);
            $this->assertFalse($dados['ok']);
            $this->assertStringContainsString('30 segundos', $dados['erros'][0]['mensagem']);
            $this->assertStringNotContainsString('Too many', $dados['erros'][0]['mensagem']);
        }
    }
}
