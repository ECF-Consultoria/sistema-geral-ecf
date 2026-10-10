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

        // Desde a etapa "ficha" o job fala com o Mercado Livre (preditor de
        // categoria, atributos). Pedido sem stub vira exceção em vez de ir à
        // internet — com fakes por padrão de URL, o Laravel deixaria passar.
        Http::preventStrayRequests();

        config([
            'services.llm.base_url'   => 'http://llm.teste/v1',
            'services.llm.key'        => 'chave-de-teste',
            'services.llm.model'      => 'modelo-de-teste',
            // Sem reserva por padrão: cada teste que quer a troca de modelo
            // liga a sua. Senão o reserva do config/services.php entraria
            // escondido em todo teste de falha.
            'services.llm.fallbacks'  => '',
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

    /** Uma resposta do provedor, formato OpenAI, com o JSON pedido dentro. */
    private function resposta(array $payload): array
    {
        return [
            'model'   => 'modelo-que-respondeu',
            'usage'   => ['prompt_tokens' => 1095, 'completion_tokens' => 8797],
            'choices' => [['message' => ['content' => json_encode($payload, JSON_UNESCAPED_UNICODE)]]],
        ];
    }

    /**
     * Encena as TRÊS chamadas da geração, na ordem: análise, títulos, descrição.
     *
     * `Http::sequence()` e não três `Http::fake()`: fakes acumulam e o PRIMEIRO
     * stub que casa vence, então três fakes para a mesma URL fariam a resposta
     * da análise atender também aos títulos.
     */
    private function fakeDasTresEtapas(array $titulos, string $descricao = 'Olá! Bem-vindo à loja.'): void
    {
        Http::fake(['llm.teste/*' => Http::sequence()
            ->push($this->resposta(['analise' => [
                'puv'  => 'Conforto que dura',
                'jtbd' => 'Trabalhar sem dor',
            ]]))
            ->push($this->resposta(['titulos' => array_map(fn ($t) => ['texto' => $t], $titulos)]))
            ->push($this->resposta(['descricao' => $descricao])),
        ]);
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

    public function test_job_vai_para_a_fila_high_e_nao_para_a_default(): void
    {
        // Medido em produção em 21/09/2026: a `default` tinha 170 jobs
        // represados do sync da Adman e a análise ficou 395s sem ninguém
        // pegar — o publicador viu "Gerando…" e achou que tinha travado.
        // A `high` tem worker dedicado e vive ociosa.
        Queue::fake();

        $this->actingAs($this->admin())->postJson(route('mlb.anuncios.ia.analise.store'), [
            'company_id' => $this->companyConectada()->id,
            'produto'    => 'Cadeira Gamer',
        ])->assertStatus(202);

        Queue::assertPushedOn('high', GerarAnaliseAnuncioIaJob::class);
    }

    public function test_wizard_devolve_a_analise_recente_para_sobreviver_ao_f5(): void
    {
        // A geração leva minutos e mora no banco: recarregar a página nunca
        // cancelou nada, só escondia. O wizard reentrega a análise para a tela
        // retomar o acompanhamento.
        $company = $this->companyConectada();

        $analise = MlAnuncioIaAnalise::create([
            'company_id' => $company->id,
            'produto'    => 'Cadeira Gamer',
            'status'     => MlAnuncioIaAnalise::STATUS_RODANDO,
        ]);

        $props = $this->actingAs($this->admin())
            ->get(route('mlb.anuncios.wizard', ['company' => $company->id]))
            ->assertOk()
            ->viewData('page')['props'];

        $this->assertSame($analise->id, $props['iaAnalise']['id']);
        $this->assertTrue($props['iaAnalise']['em_andamento']);
    }

    public function test_analise_velha_nao_reabre_no_wizard(): void
    {
        // Análise de ontem é de outro anúncio. Reabrir confundiria mais do que
        // ajudaria — a janela é de 2 horas.
        $company = $this->companyConectada();

        $velha = MlAnuncioIaAnalise::create([
            'company_id' => $company->id,
            'produto'    => 'Anúncio de ontem',
            'status'     => MlAnuncioIaAnalise::STATUS_CONCLUIDO,
        ]);

        // `created_at` no create() é sobrescrito pelos timestamps do Eloquent —
        // envelhecer a linha exige escrever direto na tabela.
        MlAnuncioIaAnalise::where('id', $velha->id)->update(['created_at' => now()->subHours(5)]);

        $props = $this->actingAs($this->admin())
            ->get(route('mlb.anuncios.wizard', ['company' => $company->id]))
            ->assertOk()
            ->viewData('page')['props'];

        $this->assertNull($props['iaAnalise']);
    }

    public function test_etapa_que_falha_nao_apaga_as_anteriores(): void
    {
        // O ponto de dividir em três. Se a descrição falhar, análise e títulos
        // FICAM — refazer 3 chamadas por causa da última desperdiça minutos e
        // gasta cota de um provedor que já está sobrecarregado.
        Http::fake(['llm.teste/*' => Http::sequence()
            ->push($this->resposta(['analise' => ['puv' => 'Conforto que dura']]))
            ->push($this->resposta(['titulos' => [['texto' => 'Cadeira Gamer Ergonomica Reclinavel Aco Carbono 150Kg 4D']]]))
            ->push(['error' => 'Service temporarily overloaded'], 503),
        ]);

        $analise = MlAnuncioIaAnalise::create([
            'company_id' => $this->companyConectada()->id,
            'produto'    => 'Cadeira Gamer',
            'loja'       => 'Unity Móveis',
            'status'     => MlAnuncioIaAnalise::STATUS_PENDENTE,
        ]);

        try {
            (new GerarAnaliseAnuncioIaJob($analise->id))->handle(app(\App\Services\Ia\AnaliseAnuncioService::class));
        } catch (\RuntimeException) {
            // esperado: a 3ª etapa estourou
        }

        $r = $analise->fresh()->resultado;

        $this->assertSame('Conforto que dura', $r['analise']['puv'], 'A análise da etapa 1 tem que sobreviver.');
        $this->assertCount(1, $r['titulos'], 'Os títulos da etapa 2 têm que sobreviver.');
        $this->assertArrayNotHasKey('descricao', $r);
    }

    public function test_etapa_ja_pronta_nao_e_refeita_na_retentativa(): void
    {
        // Retentar não pode regerar o que já deu certo: além do desperdício, o
        // publicador veria a análise trocar de conteúdo sozinha entre uma
        // tentativa e outra.
        Http::fake(['llm.teste/*' => Http::sequence()
            ->push($this->resposta(['titulos' => [['texto' => 'Cadeira Gamer Ergonomica Reclinavel Aco Carbono 150Kg 4D']]]))
            ->push($this->resposta(['descricao' => 'Olá!'])),
        ]);

        $analise = MlAnuncioIaAnalise::create([
            'company_id' => $this->companyConectada()->id,
            'produto'    => 'Cadeira Gamer',
            'loja'       => 'Unity Móveis',
            'status'     => MlAnuncioIaAnalise::STATUS_RODANDO,
            // Etapa 1 já tinha saído numa tentativa anterior.
            'resultado'  => ['analise' => ['puv' => 'PUV DA PRIMEIRA TENTATIVA']],
        ]);

        (new GerarAnaliseAnuncioIaJob($analise->id))->handle(app(\App\Services\Ia\AnaliseAnuncioService::class));

        $analise->refresh();
        $this->assertSame(MlAnuncioIaAnalise::STATUS_CONCLUIDO, $analise->status);
        $this->assertSame('PUV DA PRIMEIRA TENTATIVA', $analise->analise()['puv']);
        $this->assertCount(1, $analise->titulos());
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
        $this->fakeDasTresEtapas([
            'Cadeira Gamer Ergonomica Reclinavel 180 Graus Unity Moveis',
            'Cadeira Gamer Ergonomica Reclinavel Aco Carbono Unity Moveis',
        ], 'Olá! Seja bem-vindo à Unity Móveis!');

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

        $this->fakeDasTresEtapas([$dentro, $curto]);

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
        $this->fakeDasTresEtapas([
            'Cadeira Gamer de Escritorio Reclinavel Ergonomica Unity Mov',
        ]);

        $analise = MlAnuncioIaAnalise::create([
            'company_id' => $this->companyConectada()->id,
            'produto'    => 'Cadeira Gamer',
            'status'     => MlAnuncioIaAnalise::STATUS_PENDENTE,
        ]);

        (new GerarAnaliseAnuncioIaJob($analise->id))->handle(app(\App\Services\Ia\AnaliseAnuncioService::class));

        $this->assertFalse($analise->fresh()->resultado['titulos'][0]['dentro_da_regra']);
    }

    public function test_titulo_com_nome_da_loja_e_marcado_fora_da_regra(): void
    {
        // A regra da ECF é "marca no final" e fala da marca do PRODUTO. O
        // modelo confunde com o nome do vendedor e enfia a loja no fim para
        // fechar os 58-60 caracteres — queimando espaço que deveria ser termo
        // de busca. Conferimos aqui porque pedir no prompt não basta.
        $this->fakeDasTresEtapas([
            'Cadeira Gamer Ergonomica Reclinavel 180 Graus Unity Moveis',   // tem a loja
            'Cadeira Gamer Ergonomica Reclinavel Aco Carbono 150Kg 4D Alt', // limpa
        ]);

        $analise = MlAnuncioIaAnalise::create([
            'company_id' => $this->companyConectada('Unity Móveis')->id,
            'produto'    => 'Cadeira Gamer Ergonômica',
            'loja'       => 'Unity Móveis',
            'status'     => MlAnuncioIaAnalise::STATUS_PENDENTE,
        ]);

        (new GerarAnaliseAnuncioIaJob($analise->id))->handle(app(\App\Services\Ia\AnaliseAnuncioService::class));

        $titulos = $analise->fresh()->resultado['titulos'];

        // Casa mesmo sem acento: a loja é "Móveis" e o modelo escreveu "Moveis".
        $this->assertTrue($titulos[0]['tem_loja']);
        $this->assertFalse($titulos[0]['dentro_da_regra'], 'Título com nome da loja não pode passar.');

        $this->assertFalse($titulos[1]['tem_loja']);
        $this->assertTrue($titulos[1]['dentro_da_regra']);
    }

    public function test_palavra_da_loja_que_tambem_e_do_produto_nao_reprova(): void
    {
        // Loja "Cadeiras Brasil" não pode fazer todo título de cadeira ser
        // reprovado por conter "cadeira" — a palavra é do produto, não da loja.
        $this->fakeDasTresEtapas([
            'Cadeira Gamer Ergonomica Reclinavel Aco Carbono 150Kg 4D Alt',
        ]);

        $analise = MlAnuncioIaAnalise::create([
            'company_id' => $this->companyConectada('Cadeiras Brasil')->id,
            'produto'    => 'Cadeira Gamer Ergonômica',
            'loja'       => 'Cadeiras Brasil',
            'status'     => MlAnuncioIaAnalise::STATUS_PENDENTE,
        ]);

        (new GerarAnaliseAnuncioIaJob($analise->id))->handle(app(\App\Services\Ia\AnaliseAnuncioService::class));

        $this->assertFalse($analise->fresh()->resultado['titulos'][0]['tem_loja']);
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

        $svc->analise('Cadeira', 'Unity', 'specs');
    }

    public function test_sobrecarga_do_provedor_vira_mensagem_que_o_publicador_entende(): void
    {
        Http::fake(['llm.teste/*' => Http::response(['error' => 'Service temporarily overloaded'], 503)]);

        $svc = app(\App\Services\Ia\AnaliseAnuncioService::class);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/sobrecarregado/i');

        $svc->analise('Cadeira', 'Unity', 'specs');
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

    // ═══ Troca de modelo e fim garantido (29/09/2026) ═══════════════════════

    public function test_modelo_principal_sobrecarregado_passa_para_o_reserva(): void
    {
        // Na NVIDIA é rotina: um modelo fica na fila deles e outro responde.
        config(['services.llm.fallbacks' => 'modelo-reserva']);

        Http::fake(fn ($req) => $req['model'] === 'modelo-de-teste'
            ? Http::response(['error' => 'Service temporarily overloaded'], 503)
            : Http::response([
                'model'   => 'modelo-reserva',
                'choices' => [['message' => ['content' => '{"analise":{"puv":"Conforto"}}']]],
            ]));

        $r = app(\App\Services\Ia\AnaliseAnuncioService::class)->analise('Cadeira', 'Unity', '');

        $this->assertSame('Conforto', $r['dados']['puv']);
        $this->assertSame('modelo-reserva', $r['meta']['modelo']);
        Http::assertSentCount(2);
    }

    public function test_modelo_mudo_nao_e_retentado_e_passa_para_o_reserva(): void
    {
        // A causa do "loop infinito": o mesmo modelo mudo era retentado 3x com
        // 300s cada. Agora: uma chamada, e o reserva assume.
        config(['services.llm.fallbacks' => 'modelo-reserva']);

        Http::fake(fn ($req) => $req['model'] === 'modelo-de-teste'
            ? Http::failedConnection()
            : Http::response(['choices' => [['message' => ['content' => '{"descricao":"Olá!"}']]]]));

        $r = app(\App\Services\Ia\AnaliseAnuncioService::class)->descricao('Cadeira', 'Unity', '', []);

        $this->assertSame('Olá!', $r['dados']);
        Http::assertSentCount(2);
    }

    public function test_json_quebrado_do_principal_passa_para_o_reserva(): void
    {
        // Medido em 29/09/2026: o nemotron-3-super quebrou o JSON dos títulos.
        config(['services.llm.fallbacks' => 'modelo-reserva']);

        Http::fake(fn ($req) => Http::response(['choices' => [['message' => ['content' => $req['model'] === 'modelo-de-teste'
            ? 'Aqui estão os títulos: 1. Cadeira'
            : '{"titulos":[{"texto":"Cadeira Gamer"}]}']]]]));

        $r = app(\App\Services\Ia\AnaliseAnuncioService::class)->titulos('Cadeira', 'Unity', '', []);

        $this->assertSame('Cadeira Gamer', $r['dados'][0]['texto']);
    }

    // ═══ Quarentena do modelo que acabou de falhar (10/10/2026) ═════════════
    //
    // Medido em produção: o principal respondia vazio depois de 60 s+ e o reserva resolvia em 30 s. A descrição
    // do Publicador são DUAS chamadas no mesmo prazo de 240 s; perdendo tempo com o modelo ruim nas duas, ela
    // estourava ("gerando" sem fim, nenhuma descrição) enquanto título e Modelo, de uma chamada só, saíam.

    /** O principal falha sempre; o reserva responde o que for pedido. Devolve a ordem dos modelos chamados. */
    private function principalRuimReservaBom(array &$chamados, ?array &$timeouts = null): void
    {
        Http::fake(function ($req, $opcoes) use (&$chamados, &$timeouts) {
            $chamados[] = $req['model'];
            $timeouts[] = $opcoes['timeout'] ?? null;

            return $req['model'] === 'modelo-de-teste'
                ? Http::response(['choices' => [['message' => ['content' => '', 'reasoning_content' => 'pensando sem parar…']]]])
                : Http::response(['model' => 'modelo-reserva', 'choices' => [['message' => ['content' => '{"analise":{"puv":"Conforto"},"descricao":"Olá!"}']]]]);
        });
    }

    public function test_modelo_que_falhou_vai_para_o_fim_da_fila_e_a_chamada_seguinte_comeca_pelo_reserva(): void
    {
        config(['services.llm.fallbacks' => 'modelo-reserva']);
        $chamados = [];
        $this->principalRuimReservaBom($chamados);
        $ia = app(\App\Services\Ia\AnaliseAnuncioService::class);

        // As duas chamadas da descrição do Publicador (`DescricaoIaService::gerar`): análise e depois descrição.
        $analise = $ia->analise('Poltrona', 'Unity', '');
        $descricao = $ia->descricao('Poltrona', 'Unity', '', $analise['dados']);

        $this->assertSame('Conforto', $analise['dados']['puv']);
        $this->assertSame('Olá!', $descricao['dados']);
        $this->assertSame(['modelo-de-teste', 'modelo-reserva', 'modelo-reserva'], $chamados, 'o modelo ruim só custa tempo UMA vez');
        $this->assertTrue(\Illuminate\Support\Facades\Cache::has(\App\Services\Ia\AnaliseAnuncioService::chaveDaQuarentena('modelo-de-teste')));

        // A geração de OUTRO produto, noutro Job, também já começa por quem responde.
        app(\App\Services\Ia\AnaliseAnuncioService::class)->analise('Cadeira', 'Unity', '');
        $this->assertSame('modelo-reserva', end($chamados));
        $this->assertCount(4, $chamados);
    }

    public function test_passada_a_quarentena_o_principal_ganha_outra_chance(): void
    {
        config(['services.llm.fallbacks' => 'modelo-reserva', 'services.llm.quarentena_s' => 600]);
        $chamados = [];
        $this->principalRuimReservaBom($chamados);
        $ia = app(\App\Services\Ia\AnaliseAnuncioService::class);

        $ia->analise('Poltrona', 'Unity', '');
        $this->travel(9)->minutes();
        $ia->analise('Poltrona', 'Unity', '');
        $this->assertSame(['modelo-de-teste', 'modelo-reserva', 'modelo-reserva'], $chamados, 'ainda de castigo');

        $this->travel(2)->minutes();
        $ia->analise('Poltrona', 'Unity', '');
        $this->assertSame(['modelo-de-teste', 'modelo-reserva'], array_slice($chamados, 3), 'volta a ser o primeiro');
    }

    public function test_se_o_reserva_tambem_falhar_o_modelo_de_castigo_ainda_e_tentado(): void
    {
        config(['services.llm.fallbacks' => 'modelo-reserva']);
        \Illuminate\Support\Facades\Cache::put(\App\Services\Ia\AnaliseAnuncioService::chaveDaQuarentena('modelo-de-teste'), true, 600);
        $chamados = [];
        Http::fake(function ($req) use (&$chamados) {
            $chamados[] = $req['model'];

            return $req['model'] === 'modelo-reserva'
                ? Http::response(['error' => 'Service temporarily overloaded'], 503)
                : Http::response(['choices' => [['message' => ['content' => '{"analise":{"puv":"Voltou"}}']]]]);
        });

        $r = app(\App\Services\Ia\AnaliseAnuncioService::class)->analise('Poltrona', 'Unity', '');

        $this->assertSame('Voltou', $r['dados']['puv']);
        $this->assertSame(['modelo-reserva', 'modelo-de-teste'], $chamados, 'castigo é ir para o fim, não sair da fila');
    }

    public function test_sem_reserva_nao_ha_quarentena(): void
    {
        // Um modelo só: não há para quem passar, então não há castigo.
        Http::fake(['llm.teste/*' => Http::response(['error' => 'Service temporarily overloaded'], 503)]);
        try {
            app(\App\Services\Ia\AnaliseAnuncioService::class)->analise('Poltrona', 'Unity', '');
            $this->fail('Deveria ter lançado.');
        } catch (\RuntimeException) {
            // esperado
        }
        $this->assertFalse(\Illuminate\Support\Facades\Cache::has(\App\Services\Ia\AnaliseAnuncioService::chaveDaQuarentena('modelo-de-teste')));
    }

    public function test_quarentena_desligada_por_config_deixa_o_principal_sempre_primeiro(): void
    {
        // `LLM_QUARENTENA_S=0`: como era antes. (Teste à parte: `Http::fake()` acumula e o 1º stub vence.)
        config(['services.llm.fallbacks' => 'modelo-reserva', 'services.llm.quarentena_s' => 0]);
        $chamados = [];
        $this->principalRuimReservaBom($chamados);
        $ia = app(\App\Services\Ia\AnaliseAnuncioService::class);
        $ia->analise('Poltrona', 'Unity', '');
        $ia->analise('Poltrona', 'Unity', '');
        $this->assertSame(['modelo-de-teste', 'modelo-reserva', 'modelo-de-teste', 'modelo-reserva'], $chamados);
    }

    public function test_com_reserva_o_principal_nao_leva_o_prazo_inteiro(): void
    {
        // Prazo de 100 s e timeout de configuração folgado: o principal recebe 60 % do que resta, e o último da fila
        // fica com tudo o que sobrar.
        config(['services.llm.fallbacks' => 'modelo-reserva', 'services.llm.timeout' => 500]);
        $chamados = [];
        $timeouts = [];
        $this->principalRuimReservaBom($chamados, $timeouts);

        app(\App\Services\Ia\AnaliseAnuncioService::class)->comPrazo(microtime(true) + 100)->analise('Poltrona', 'Unity', '');

        $this->assertSame(['modelo-de-teste', 'modelo-reserva'], $chamados);
        $this->assertGreaterThanOrEqual(58, $timeouts[0]);
        $this->assertLessThanOrEqual(60, $timeouts[0], 'o principal não passa de 60 % do prazo');
        $this->assertGreaterThanOrEqual(97, $timeouts[1], 'o último usa o que sobrou');
    }

    public function test_chave_recusada_nao_tenta_o_reserva(): void
    {
        // Chave errada é errada para todos os modelos: trocar só atrasaria o erro.
        config(['services.llm.fallbacks' => 'modelo-reserva']);
        Http::fake(['llm.teste/*' => Http::response(['error' => 'unauthorized'], 401)]);

        try {
            app(\App\Services\Ia\AnaliseAnuncioService::class)->analise('Cadeira', 'Unity', '');
            $this->fail('Deveria ter lançado.');
        } catch (\RuntimeException $e) {
            $this->assertMatchesRegularExpression('/chave/i', $e->getMessage());
        }

        Http::assertSentCount(1);
    }

    public function test_prazo_esgotado_falha_sem_chamar_o_provedor(): void
    {
        Http::fake();

        $svc = app(\App\Services\Ia\AnaliseAnuncioService::class)->comPrazo(microtime(true) + 5);

        try {
            $svc->analise('Cadeira', 'Unity', '');
            $this->fail('Deveria ter lançado.');
        } catch (\RuntimeException $e) {
            $this->assertMatchesRegularExpression('/tempo limite/i', $e->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_analise_rodando_ha_mais_de_15_min_vira_erro_no_polling(): void
    {
        // Worker morto no meio: sem isto a tela perguntava para sempre.
        $company = $this->companyConectada();
        $analise = MlAnuncioIaAnalise::create([
            'company_id' => $company->id,
            'produto'    => 'Cadeira',
            'status'     => MlAnuncioIaAnalise::STATUS_RODANDO,
            'resultado'  => ['analise' => ['puv' => 'Conforto']],
        ]);
        $analise->forceFill(['created_at' => now()->subMinutes(16)])->save();

        $this->actingAs($this->admin())
            ->getJson(route('mlb.anuncios.ia.analise.status', ['analise' => $analise->id]))
            ->assertOk()
            ->assertJsonPath('status', MlAnuncioIaAnalise::STATUS_ERRO)
            ->assertJsonPath('em_andamento', false)
            // O parcial fica: meia análise vale mais que nenhuma.
            ->assertJsonPath('analise.puv', 'Conforto');

        $this->assertStringContainsString('15 minutos', $analise->fresh()->erro_mensagem);
    }

    public function test_analise_que_a_fila_nunca_pegou_diz_isso(): void
    {
        $company = $this->companyConectada();
        $analise = MlAnuncioIaAnalise::create([
            'company_id' => $company->id,
            'produto'    => 'Cadeira',
            'status'     => MlAnuncioIaAnalise::STATUS_PENDENTE,
        ]);
        $analise->forceFill(['created_at' => now()->subMinutes(20)])->save();

        $this->actingAs($this->admin())
            ->getJson(route('mlb.anuncios.ia.analise.status', ['analise' => $analise->id]))
            ->assertJsonPath('status', MlAnuncioIaAnalise::STATUS_ERRO);

        $this->assertStringContainsString('fila', $analise->fresh()->erro_mensagem);
    }

    public function test_analise_recente_em_andamento_nao_e_encerrada(): void
    {
        $company = $this->companyConectada();
        $analise = MlAnuncioIaAnalise::create([
            'company_id' => $company->id,
            'produto'    => 'Cadeira',
            'status'     => MlAnuncioIaAnalise::STATUS_RODANDO,
        ]);

        $this->actingAs($this->admin())
            ->getJson(route('mlb.anuncios.ia.analise.status', ['analise' => $analise->id]))
            ->assertJsonPath('status', MlAnuncioIaAnalise::STATUS_RODANDO)
            ->assertJsonPath('em_andamento', true);
    }

    public function test_job_de_analise_vencida_nao_gasta_cota(): void
    {
        // A retentativa chegou depois que a tela já mostrou "encerrada".
        Http::fake();
        $analise = MlAnuncioIaAnalise::create([
            'company_id' => $this->companyConectada()->id,
            'produto'    => 'Cadeira',
            'status'     => MlAnuncioIaAnalise::STATUS_PENDENTE,
        ]);
        $analise->forceFill(['created_at' => now()->subMinutes(16)])->save();

        (new GerarAnaliseAnuncioIaJob($analise->id))->handle(app(\App\Services\Ia\AnaliseAnuncioService::class));

        Http::assertNothingSent();
        $this->assertSame(MlAnuncioIaAnalise::STATUS_ERRO, $analise->fresh()->status);
    }

    public function test_job_falha_de_vez_se_o_worker_matar_por_timeout(): void
    {
        // Morte por timeout não roda o fim do handle; sem failOnTimeout a
        // análise ficava em "rodando" até as tentativas acabarem.
        $job = new GerarAnaliseAnuncioIaJob(1);

        $this->assertTrue($job->failOnTimeout);
        $this->assertLessThan($job->timeout, GerarAnaliseAnuncioIaJob::PRAZO_S);
    }
}
