<?php

namespace Tests\Feature;

use App\Jobs\GerarAnaliseAnuncioIaJob;
use App\Models\Company;
use App\Models\MlAnuncioIaAnalise;
use App\Models\MlToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * "Anunciar por IA" — análise MAG T8 gerada em background.
 *
 * O provedor NUNCA é chamado de verdade aqui: `Http::fake()` cobre tudo.
 * Cuidado ao mexer: `Http::fake()` ACUMULA e o PRIMEIRO stub que casa vence,
 * então um fake genérico no setUp engoliria os específicos de cada teste.
 */
class AnuncioIaAnaliseTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        config([
            'services.llm.base_url'   => 'http://llm.teste/v1',
            'services.llm.key'        => 'chave-de-teste',
            'services.llm.model'      => 'modelo-de-teste',
            'services.llm.timeout'    => 30,
            'services.llm.max_tokens' => 16000,
        ]);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function companyConectada(string $nome = 'Unity Móveis'): Company
    {
        $company = Company::factory()->create(['name' => $nome]);

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

    /** Resposta do provedor no formato OpenAI, com o JSON da metodologia dentro. */
    private function respostaDoProvedor(array $titulos, string $descricao = 'Olá! Bem-vindo.'): array
    {
        return [
            'model'  => 'modelo-que-respondeu',
            'usage'  => ['prompt_tokens' => 1095, 'completion_tokens' => 8797],
            'choices' => [[
                'message' => [
                    'content' => json_encode([
                        'analise'   => ['puv' => 'Conforto que dura', 'jtbd' => 'Trabalhar sem dor'],
                        'titulos'   => array_map(fn ($t) => ['texto' => $t], $titulos),
                        'descricao' => $descricao,
                    ], JSON_UNESCAPED_UNICODE),
                ],
            ]],
        ];
    }

    public function test_pedido_responde_na_hora_e_joga_a_geracao_para_a_fila(): void
    {
        // 103s de geração não cabem num request — o endpoint tem que devolver
        // 202 imediatamente e deixar o trabalho para o worker.
        Queue::fake();
        $company = $this->companyConectada();

        $r = $this->actingAs($this->admin())->postJson(route('mlb.anuncios.ia.analise.store'), [
            'company_id' => $company->id,
            'produto'    => 'Cadeira Gamer Ergonômica Reclinável 180 graus',
            'specs'      => 'Estrutura em aço carbono; suporta 150kg',
        ]);

        $r->assertStatus(202)->assertJsonPath('status', MlAnuncioIaAnalise::STATUS_PENDENTE);
        Queue::assertPushed(GerarAnaliseAnuncioIaJob::class);
    }

    public function test_loja_vem_da_conta_ml_e_nao_do_que_o_cliente_manda(): void
    {
        Queue::fake();
        $company = $this->companyConectada('Unity Móveis');

        $this->actingAs($this->admin())->postJson(route('mlb.anuncios.ia.analise.store'), [
            'company_id' => $company->id,
            'produto'    => 'Cadeira',
            'loja'       => 'LOJA FORJADA PELO CLIENTE',
        ])->assertStatus(202);

        $this->assertSame('Unity Móveis', MlAnuncioIaAnalise::first()->loja);
    }

    public function test_empresa_sem_conta_ml_conectada_e_recusada(): void
    {
        Queue::fake();
        $company = Company::factory()->create();   // sem token

        $this->actingAs($this->admin())->postJson(route('mlb.anuncios.ia.analise.store'), [
            'company_id' => $company->id,
            'produto'    => 'Cadeira',
        ])->assertStatus(422);

        Queue::assertNothingPushed();
    }

    public function test_job_preenche_titulos_e_descricao_a_partir_da_resposta(): void
    {
        Http::fake(['llm.teste/*' => Http::response($this->respostaDoProvedor([
            'Cadeira Gamer Ergonomica Reclinavel 180 Graus Unity Moveis',
            'Cadeira Gamer Ergonomica Reclinavel Aco Carbono Unity Moveis',
        ], 'Olá! Seja bem-vindo à Unity Móveis!'))]);

        $company = $this->companyConectada();
        $analise = MlAnuncioIaAnalise::create([
            'company_id' => $company->id,
            'produto'    => 'Cadeira Gamer',
            'loja'       => 'Unity Móveis',
            'status'     => MlAnuncioIaAnalise::STATUS_PENDENTE,
        ]);

        (new GerarAnaliseAnuncioIaJob($analise->id))->handle(app(\App\Services\Ia\AnaliseAnuncioService::class));

        $analise->refresh();
        $this->assertSame(MlAnuncioIaAnalise::STATUS_CONCLUIDO, $analise->status);
        $this->assertCount(2, $analise->titulos());
        $this->assertStringContainsString('Unity Móveis', (string) $analise->descricao());
        // O modelo que RESPONDEU, não o que foi pedido — combo troca por baixo.
        $this->assertSame('modelo-que-respondeu', $analise->modelo);
        $this->assertSame(8797, $analise->tokens_saida);
    }

    public function test_contagem_de_caracteres_e_medida_aqui_nao_aceita_do_modelo(): void
    {
        // O modelo erra a conta com frequência. Se a tela exibisse o número
        // dele, o publicador publicaria título fora da regra achando que está
        // dentro. Medimos e marcamos `dentro_da_regra` no servidor.
        $dentro = 'Cadeira Gamer Ergonomica Reclinavel 180 Graus Unity Moveis'; // 58
        $curto  = 'Cadeira Gamer Unity Moveis';                                  // 26

        Http::fake(['llm.teste/*' => Http::response($this->respostaDoProvedor([$dentro, $curto]))]);

        $analise = MlAnuncioIaAnalise::create([
            'company_id' => $this->companyConectada()->id,
            'produto'    => 'Cadeira Gamer',
            'status'     => MlAnuncioIaAnalise::STATUS_PENDENTE,
        ]);

        (new GerarAnaliseAnuncioIaJob($analise->id))->handle(app(\App\Services\Ia\AnaliseAnuncioService::class));

        $titulos = $analise->fresh()->resultado['titulos'];

        $this->assertSame(58, $titulos[0]['caracteres']);
        $this->assertTrue($titulos[0]['dentro_da_regra']);
        $this->assertSame(26, $titulos[1]['caracteres']);
        $this->assertFalse($titulos[1]['dentro_da_regra'], 'Título de 26 chars não pode passar como válido.');
    }

    public function test_titulo_com_preposicao_e_marcado_fora_da_regra(): void
    {
        // Regra 1 do ruleset ECF. Um título de tamanho certo mas com "de" no
        // meio continua sendo título errado.
        Http::fake(['llm.teste/*' => Http::response($this->respostaDoProvedor([
            'Cadeira Gamer de Escritorio Reclinavel Ergonomica Unity Mov',
        ]))]);

        $analise = MlAnuncioIaAnalise::create([
            'company_id' => $this->companyConectada()->id,
            'produto'    => 'Cadeira Gamer',
            'status'     => MlAnuncioIaAnalise::STATUS_PENDENTE,
        ]);

        (new GerarAnaliseAnuncioIaJob($analise->id))->handle(app(\App\Services\Ia\AnaliseAnuncioService::class));

        $this->assertFalse($analise->fresh()->resultado['titulos'][0]['dentro_da_regra']);
    }

    public function test_resposta_embrulhada_em_crases_ainda_e_aproveitada(): void
    {
        // Modelo desobedece e devolve ```json ... ```. Perder 100 segundos de
        // geração por causa de três crases seria desperdício.
        $conteudo = "Segue o resultado:\n```json\n" . json_encode([
            'analise'   => ['puv' => 'x'],
            'titulos'   => [['texto' => 'Cadeira Gamer Ergonomica Reclinavel 180 Graus Unity Moveis']],
            'descricao' => 'Olá!',
        ], JSON_UNESCAPED_UNICODE) . "\n```";

        Http::fake(['llm.teste/*' => Http::response([
            'model'   => 'm',
            'choices' => [['message' => ['content' => $conteudo]]],
        ])]);

        $analise = MlAnuncioIaAnalise::create([
            'company_id' => $this->companyConectada()->id,
            'produto'    => 'Cadeira',
            'status'     => MlAnuncioIaAnalise::STATUS_PENDENTE,
        ]);

        (new GerarAnaliseAnuncioIaJob($analise->id))->handle(app(\App\Services\Ia\AnaliseAnuncioService::class));

        $this->assertSame(MlAnuncioIaAnalise::STATUS_CONCLUIDO, $analise->fresh()->status);
        $this->assertCount(1, $analise->fresh()->titulos());
    }

    public function test_conteudo_vazio_por_raciocinio_vira_erro_explicativo(): void
    {
        // HTTP 200 com content vazio e reasoning cheio = o modelo gastou todo
        // o orçamento pensando. Mensagem tem que dizer isso, não "deu erro".
        Http::fake(['llm.teste/*' => Http::response([
            'model'   => 'm',
            'choices' => [['message' => ['content' => '', 'reasoning_content' => 'pensando muito...']]],
        ])]);

        $svc = app(\App\Services\Ia\AnaliseAnuncioService::class);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/racioc/i');

        $svc->gerar('Cadeira', 'Unity', 'specs');
    }

    public function test_sobrecarga_do_provedor_vira_mensagem_que_o_publicador_entende(): void
    {
        Http::fake(['llm.teste/*' => Http::response(['error' => 'Service temporarily overloaded'], 503)]);

        $svc = app(\App\Services\Ia\AnaliseAnuncioService::class);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/sobrecarregado/i');

        $svc->gerar('Cadeira', 'Unity', 'specs');
    }

    public function test_status_devolve_o_resultado_para_o_polling(): void
    {
        $company = $this->companyConectada();
        $analise = MlAnuncioIaAnalise::create([
            'company_id' => $company->id,
            'produto'    => 'Cadeira Gamer',
            'loja'       => 'Unity Móveis',
            'status'     => MlAnuncioIaAnalise::STATUS_CONCLUIDO,
            'resultado'  => [
                'analise'   => ['puv' => 'Conforto que dura'],
                'titulos'   => [['texto' => 'Cadeira Gamer Ergonomica Reclinavel 180 Graus Unity Moveis']],
                'descricao' => 'Olá!',
            ],
        ]);

        $this->actingAs($this->admin())
            ->getJson(route('mlb.anuncios.ia.analise.status', ['analise' => $analise->id]))
            ->assertOk()
            ->assertJsonPath('status', MlAnuncioIaAnalise::STATUS_CONCLUIDO)
            ->assertJsonPath('em_andamento', false)
            ->assertJsonPath('titulos.0.caracteres', 58)
            ->assertJsonPath('analise.puv', 'Conforto que dura');
    }

    public function test_falha_definitiva_marca_erro_com_a_mensagem(): void
    {
        $analise = MlAnuncioIaAnalise::create([
            'company_id' => $this->companyConectada()->id,
            'produto'    => 'Cadeira',
            'status'     => MlAnuncioIaAnalise::STATUS_RODANDO,
        ]);

        (new GerarAnaliseAnuncioIaJob($analise->id))->failed(new \RuntimeException('provedor fora do ar'));

        $analise->refresh();
        $this->assertSame(MlAnuncioIaAnalise::STATUS_ERRO, $analise->status);
        $this->assertSame('provedor fora do ar', $analise->erro_mensagem);
    }
}
