<?php

namespace Tests\Feature\Trilha;

use App\Jobs\Publicador\GerarPreparoIaJob;
use App\Jobs\Trilha\TentarPreparoDeNovoJob;
use App\Models\EstruturaProduto;
use App\Models\MlAnuncioCriativoKit;
use App\Models\PubProduto;
use App\Models\PubRascunho;
use App\Models\TrilhaOnda;
use App\Models\User;
use App\Services\Ia\AnaliseAnuncioService;
use App\Services\Publicador\PalavrasChaveService;
use App\Services\Publicador\PreparoIaAgenda;
use App\Services\Trilha\PreparoNaTrilha;
use App\Services\Trilha\TrilhaDaEmpresa;
use App\Support\Publicador\MotivoDaFalhaDaIa;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as FabricaHttp;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Spatie\Activitylog\Models\Activity;
use Tests\Feature\Publicador\Lote\CenarioDoPreparo;
use Tests\Feature\Trilha\Concerns\MontaTrilha;
use Tests\TestCase;

/**
 * R4 do retorno do 1º teste (04/11/2026): "O agente parou: título, descrição" sem dizer como resolver — em produção o
 * Gemini respondia 402 (a conta sem crédito) e a causa só ia para um `Log::warning` que a produção não grava.
 *
 * O cenário é o do preparo de verdade (`CenarioDoPreparo`: a cadeira com o schema guardado, a fila rodada à mão). O que
 * se prova:
 * - o preparo GUARDA a causa curta da etapa em erro (`ia_preparo.etapas.{etapa}.motivo`, aditivo), com a resposta de
 *   mentira do provedor passando pelo `AnaliseAnuncioService` de verdade (402 → `sem_credito`; 401/403 → `chave`; 429 →
 *   `limite`; 5xx, sem conexão → `indisponivel`; o resto → `outro`) — e um erro do Mercado Livre nunca vira "sem crédito";
 * - a linha da trilha leva a `parada` (a causa mais útil, as etapas e se dá para pedir de novo); o cliente não;
 * - o [Tentar de novo] (`POST …/ofertas/{item}/preparo`) enfileira, na fila da IA, o PRÓPRIO preparo, que refaz SÓ as
 *   etapas que pararam (e o Modelo junto do título); a linha diz "Na fila do agente" até o Job rodar; 409 já pedido, 409
 *   sem nada parado no texto (inclusive só as imagens), 404 item de outra loja.
 * Nunca a IA de verdade: o provedor é um `Http::fake` (ou o dublê do cenário).
 */
class PreparoNaTrilhaTest extends TestCase
{
    use CenarioDoPreparo;
    use MontaTrilha;
    use RefreshDatabase;

    private EstruturaProduto $p;

    private PubProduto $pub;

    private User $dev;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'trilha.empresas' => [], 'trilha.trabalho.empresas' => [],
            'publicador.preparo_ia.ativo' => true, 'publicador.criativos_auto.ativo' => false,
            'publicador.contas_liberadas.todas' => false, 'publicador.contas_liberadas.companies' => [],
        ]);
        Carbon::setTestNow(Carbon::parse('2026-11-05 10:00:00', TrilhaOnda::FUSO));
        $this->montarPreparo();
        $this->p = $this->produtoDoPortal('CAD');
        // O preparo de sempre, com o dublê da IA: título, Modelo e descrição `ok`.
        $this->salvarERodar($this->p);
        $this->pub = $this->pubDo($this->p);
        // A marca de "agendado" do save (80 s) vence; e só agora a trilha liga (com ela o agente esperaria a onda).
        Carbon::setTestNow(now()->addMinutes(5));
        config(['trilha.empresas' => [$this->empresa->id], 'trilha.trabalho.empresas' => [$this->empresa->id]]);
        $this->dev = User::factory()->create(['role' => 'admin', 'active' => true]);
        $this->dev->forceFill(['is_dev' => true])->save();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ═══ (a) A causa guardada pelo preparo ════════════════════════════════════

    /**
     * O Gemini sem crédito (402), pelo serviço de IA de verdade: as três etapas do texto em `erro` com `motivo`
     * `sem_credito`; a linha "O agente parou…" leva a `parada` com a causa e o [Tentar de novo] liberado.
     */
    public function test_o_402_do_provedor_vira_sem_credito_na_etapa_e_na_linha_da_trilha(): void
    {
        $this->provedorDeIa(402);
        $this->refazerOTextoTodo();

        $etapas = $this->etapas();
        foreach (['titulo', 'modelo', 'descricao'] as $e) {
            $this->assertSame('erro', $etapas[$e]['status'], $e);
            $this->assertSame(MotivoDaFalhaDaIa::SEM_CREDITO, $etapas[$e]['motivo'] ?? null, "{$e}: a causa guardada");
        }
        $this->assertSame(['status', 'em', 'motivo'], array_keys($etapas['titulo']), 'aditivo: a forma de sempre mais o motivo');

        $linha = $this->linha();
        $this->assertSame(['robo', 'O agente parou: título, modelo, descrição', true], [$linha['etapa'], $linha['status'], $linha['alerta']]);
        $this->assertSame(['motivo' => 'sem_credito', 'etapas' => ['titulo', 'modelo', 'descricao'], 'tentar' => true], $linha['parada']);
        // A régua das portas (`itens`) diz o mesmo — é o que o [Tentar de novo] confere.
        $this->assertSame($linha['parada'], TrilhaDaEmpresa::itens($this->empresa)["s-{$this->p->id}"]['parada']);
    }

    /**
     * A causa pelo TEXTO que o `AnaliseAnuncioService` monta para cada falha do provedor (o serviço não guarda o status
     * na exceção): a resposta de mentira passa pelo caminho do título do preparo. Mudou a mensagem lá, este teste cai.
     */
    public function test_cada_falha_do_provedor_tem_a_sua_causa_e_o_erro_do_mercado_livre_nao_e_da_ia(): void
    {
        $r = $this->rascunhoDo($this->p);
        foreach ([
            [402, MotivoDaFalhaDaIa::SEM_CREDITO], [401, MotivoDaFalhaDaIa::CHAVE], [403, MotivoDaFalhaDaIa::CHAVE],
            [429, MotivoDaFalhaDaIa::LIMITE], [503, MotivoDaFalhaDaIa::INDISPONIVEL], [500, MotivoDaFalhaDaIa::INDISPONIVEL],
            [408, MotivoDaFalhaDaIa::INDISPONIVEL], ['sem_conexao', MotivoDaFalhaDaIa::INDISPONIVEL], [400, MotivoDaFalhaDaIa::OUTRO],
        ] as [$falha, $causa]) {
            $this->provedorDeIa($falha);
            try {
                app(PalavrasChaveService::class)->gerarTitulos($r, microtime(true) + 120);
                $this->fail("a IA respondeu {$falha} e o título saiu");
            } catch (\Throwable $e) {
                $this->assertSame($causa, MotivoDaFalhaDaIa::de($e), "{$falha}: {$e->getMessage()}");
            }
        }
        // O prazo da geração acabou antes de chamar: indisponível ("tente de novo").
        $this->assertSame(MotivoDaFalhaDaIa::INDISPONIVEL, MotivoDaFalhaDaIa::de(new \RuntimeException('A geração passou do tempo limite. Tente novamente — o que já ficou pronto foi mantido.')));
        // Um erro do Mercado Livre na mesma etapa (os termos da categoria) com um número parecido: nunca "sem crédito".
        $this->assertSame(MotivoDaFalhaDaIa::OUTRO, MotivoDaFalhaDaIa::de(new \RuntimeException('O Mercado Livre respondeu com erro 402 ao ler os termos.')));
        $this->assertSame(MotivoDaFalhaDaIa::OUTRO, MotivoDaFalhaDaIa::de(new \RuntimeException('A IA não devolveu um título aproveitável.')));
    }

    // ═══ (b) A linha da trilha ═══════════════════════════════════════════════

    /** Causas diferentes em etapas diferentes: vence a que pede alguém de fora; a desistência e o Job morto têm a sua. */
    public function test_a_parada_mostra_a_causa_mais_util_e_so_libera_o_tentar_quando_o_texto_parou(): void
    {
        $this->etapasComo([
            'titulo'    => ['status' => 'erro', 'motivo' => 'limite'],
            'descricao' => ['status' => 'erro', 'motivo' => 'sem_credito'],
        ]);
        $this->assertSame(['motivo' => 'sem_credito', 'etapas' => ['titulo', 'descricao'], 'tentar' => true], $this->linha()['parada']);

        // Erro antigo, sem motivo gravado: "outro" (a tela diz só "tente de novo").
        $this->etapasComo(['titulo' => ['status' => 'erro']]);
        $this->assertSame('outro', $this->linha()['parada']['motivo']);
        // Desistiu (o anúncio ficou em uso) e o `rodando` de 4 h (o Job morreu sem avisar).
        $this->etapasComo(['descricao' => ['status' => 'desistiu']]);
        $this->assertSame('desistiu', $this->linha()['parada']['motivo']);
        $this->etapasComo(['descricao' => ['status' => 'rodando', 'em' => now()->subHours(4)->toIso8601String()]]);
        $this->assertSame(['motivo' => 'nao_terminou', 'etapas' => ['descricao'], 'tentar' => true], $this->linha()['parada']);

        // Só as imagens paradas (as duas tentativas falharam): a causa, mas o preparo não as refaz — sem [Tentar de novo].
        $this->etapasComo([]);
        $this->imagensFalharam();
        $linha = $this->linha();
        $this->assertSame('O agente parou: imagens', $linha['status']);
        $this->assertSame(['motivo' => 'imagens', 'etapas' => ['imagens'], 'tentar' => false], $linha['parada']);
        $this->assertSame(['message' => PreparoNaTrilha::MSG_NADA], $this->tentarDeNovo()->assertStatus(409)->json());

        // Andando de novo (o título `rodando` agora): nada parado — sem `parada`.
        $this->etapasComo(['titulo' => ['status' => 'rodando', 'em' => now()->toIso8601String()]]);
        DB::table('pub_rascunhos')->where('produto_id', $this->pub->id)->update(['step_state' => json_encode(array_diff_key(
            (array) $this->rascunhoDo($this->p)->step_state, ['criativos_auto' => true]), JSON_UNESCAPED_UNICODE)]);
        $this->assertNull($this->linha()['parada']);
    }

    /** O cliente não recebe a `parada` (nem o `preparo`): a porta dele corta pela lista de campos. */
    public function test_a_porta_do_cliente_nao_recebe_a_parada(): void
    {
        $this->etapasComo(['titulo' => ['status' => 'erro', 'motivo' => 'sem_credito']]);
        $apresentacao = app(\App\Http\Controllers\Trilha\ApresentacaoDaTrilha::class);
        $trilha = app(TrilhaDaEmpresa::class)->montar($this->empresa);

        $equipe = collect($apresentacao->paraEquipe($this->empresa, $trilha, $this->dev)['ofertas'])->firstWhere('id', "s-{$this->p->id}");
        $cliente = collect($apresentacao->paraCliente($this->empresa, $trilha, false)['ofertas'])->firstWhere('id', "s-{$this->p->id}");
        $this->assertSame('sem_credito', $equipe['parada']['motivo']);
        $this->assertNull($cliente['parada']);
        $this->assertNull($cliente['preparo']);
        $this->assertStringNotContainsString('sem_credito', json_encode($cliente));
    }

    // ═══ (c) O [Tentar de novo] ══════════════════════════════════════════════

    /**
     * O título parou (o Modelo e a descrição deram certo): o pedido enfileira na fila da IA, a linha diz "Na fila do
     * agente", o 2º clique é 409; o Job chama o PRÓPRIO preparo, que refaz só o título e o Modelo — a descrição, que deu
     * certo, não é pedida de novo (nem a IA dela).
     */
    public function test_tentar_de_novo_refaz_so_as_etapas_que_pararam_pelo_proprio_preparo(): void
    {
        $this->etapasComo(['titulo' => ['status' => 'erro', 'motivo' => 'limite']]);
        $antes = $this->etapas();
        $chave = "s-{$this->p->id}";

        $resposta = $this->tentarDeNovo()->assertStatus(202);
        $this->assertSame(['preparo' => ['situacao' => 'fila', 'mensagem' => PreparoNaTrilha::MSG_PEDIDO, 'etapas' => ['titulo']]], $resposta->json());
        Queue::assertPushedOn(PreparoIaAgenda::fila(), TentarPreparoDeNovoJob::class, fn (TentarPreparoDeNovoJob $j) => [$j->companyId, $j->item, $j->pubProdutoId]
            === [(int) $this->empresa->id, $chave, (int) $this->pub->id]);
        $this->assertSame(['robo', 'Na fila do agente', false, null], [($l = $this->linha())['etapa'], $l['status'], $l['alerta'], $l['parada']]);
        // Dois cliques: um Job só.
        $this->assertSame(['message' => PreparoNaTrilha::MSG_JA_PEDIDO], $this->tentarDeNovo()->assertStatus(409)->json());
        $this->assertCount(1, Queue::pushed(TentarPreparoDeNovoJob::class));
        // Quem pediu fica no histórico.
        $registro = Activity::query()->where('properties->evento', PreparoNaTrilha::EVENTO)->sole();
        $this->assertSame([(int) $this->dev->id, $chave, ['titulo'], 'limite'], [(int) $registro->causer_id, $registro->getExtraProperty('item'),
            $registro->getExtraProperty('etapas'), $registro->getExtraProperty('motivo')]);

        // O Job: o preparo decide (a cota, a trava da onda…) e encadeia SÓ título → Modelo.
        $jaNaFila = count(Queue::pushed(GerarPreparoIaJob::class));
        Queue::pushed(TentarPreparoDeNovoJob::class)->first()->handle(app(PreparoNaTrilha::class));
        $novos = Queue::pushed(GerarPreparoIaJob::class)->slice($jaNaFila)->values();
        $this->assertCount(1, $novos, 'uma cadeia só: a da descrição não sai');
        $this->assertSame('titulo', $novos[0]->etapa);
        $this->assertSame(['modelo'], array_map(fn ($s) => unserialize($s)->etapa, $novos[0]->chained));
        $this->assertNull(PreparoNaTrilha::pedido((int) $this->pub->id), 'a marca sai quando o Job roda');
        $this->assertSame('rodando', $this->etapas()['titulo']['status']);
        $this->assertSame($antes['descricao'], $this->etapas()['descricao'], 'a descrição que deu certo nem foi tocada');
        $this->assertSame('O agente está escrevendo título e descrição', $this->linha()['status']);

        // A cadeia roda com a IA (o dublê): o título volta `ok`, sem `motivo`.
        $this->assertSame(['escrito', 'escrito'], array_map(fn ($r) => in_array($r, ['escrito', 'mantido', 'igual'], true) ? 'escrito' : $r, $this->rodar(GerarPreparoIaJob::class)));
        $this->assertSame(['status' => 'ok'], array_intersect_key($this->etapas()['titulo'], ['status' => 1, 'motivo' => 1]));
        $this->assertNull($this->linha()['parada']);
    }

    /** O Job que quebra solta a marca (a linha volta a "parou"); item de outra loja é 404; nada parado é 409. */
    public function test_job_quebrado_solta_a_marca_e_a_guarda_do_item(): void
    {
        $this->assertSame(['message' => PreparoNaTrilha::MSG_NADA], $this->tentarDeNovo()->assertStatus(409)->json(), 'tudo ok: nada a tentar');
        Queue::assertNotPushed(TentarPreparoDeNovoJob::class);

        $this->etapasComo(['descricao' => ['status' => 'erro', 'motivo' => 'indisponivel']]);
        $this->tentarDeNovo()->assertStatus(202);
        (new TentarPreparoDeNovoJob((int) $this->empresa->id, "s-{$this->p->id}", (int) $this->pub->id))->failed(new \RuntimeException('caiu'));
        $this->assertSame('indisponivel', $this->linha()['parada']['motivo'], 'de volta a "parou", com a causa');
        $this->tentarDeNovo()->assertStatus(202);

        // O item de outra loja pelo endereço desta: 404 (a régua só conhece os itens desta).
        $outra = $this->empresaDaTrilha();
        $deFora = $this->produtoCompleto($outra, 'Vaso de Fora', 'VF');
        $this->rascunhoDoPub($this->grupoNoPublicador($outra, $deFora), PubRascunho::DRAFT, ['ia_preparo' => ['hash' => 'x', 'etapas' => ['titulo' => ['status' => 'erro', 'em' => now()->toIso8601String()]]]]);
        $this->tentarDeNovo("s-{$deFora['produto']->id}")->assertNotFound();
        $this->tentarDeNovo('s-999999')->assertNotFound();
    }

    // ─── apoio ───

    private function tentarDeNovo(?string $chave = null): TestResponse
    {
        $chave ??= "s-{$this->p->id}";

        return $this->actingAs($this->dev)->postJson("/trilha/{$this->empresa->id}/ofertas/{$chave}/preparo");
    }

    /** O provedor de IA de mentira respondendo `$falha` (status HTTP, ou `sem_conexao`) ao serviço de IA de VERDADE. */
    private function provedorDeIa(int|string $falha): void
    {
        config(['services.llm' => ['base_url' => 'https://ia.teste/v1', 'key' => 'chave-de-teste', 'model' => 'modelo-teste', 'fallbacks' => '',
            'timeout' => 30, 'quarentena_s' => 0, 'folga_raciocinio' => 0, 'regua' => ['model' => '', 'reasoning_effort' => '']]]);
        $this->app->instance(AnaliseAnuncioService::class, new AnaliseAnuncioService());
        Http::swap(new FabricaHttp(app('events')));
        Http::preventStrayRequests();
        Http::fake(['ia.teste/*' => $falha === 'sem_conexao'
            ? fn () => throw new ConnectionException('cURL error 28: Operation timed out')
            : Http::response(['error' => ['code' => $falha, 'message' => 'billing / quota', 'status' => 'FAILED_PRECONDITION']], $falha)]);
    }

    /** Os fatos "mudaram" (o hash guardado não é o de agora): o preparo refaz as três etapas e roda a cadeia. */
    private function refazerOTextoTodo(): void
    {
        $r = $this->rascunhoDo($this->p);
        $estado = (array) $r->step_state;
        $estado['ia_preparo']['hash'] = 'fatos-de-antes';
        DB::table('pub_rascunhos')->where('id', $r->id)->update(['step_state' => json_encode($estado, JSON_UNESCAPED_UNICODE)]);
        $this->assertStringStartsWith('gerando', $this->preparo()->avaliarIa($this->pub->fresh()));
        $this->rodar(GerarPreparoIaJob::class);
    }

    /** Troca a situação das etapas do texto (o hash fica: são os mesmos fatos); `[]` = todas `ok`. */
    private function etapasComo(array $etapas): void
    {
        $r = $this->rascunhoDo($this->p);
        $estado = (array) $r->step_state;
        foreach (['titulo', 'modelo', 'descricao'] as $e) {
            $estado['ia_preparo']['etapas'][$e] = ['status' => 'ok', 'em' => now()->subMinutes(30)->toIso8601String()];
        }
        foreach ($etapas as $e => $situacao) {
            $estado['ia_preparo']['etapas'][$e] = $situacao + ['em' => now()->subMinutes(20)->toIso8601String()];
        }
        DB::table('pub_rascunhos')->where('id', $r->id)->update(['step_state' => json_encode($estado, JSON_UNESCAPED_UNICODE)]);
    }

    /** As imagens automáticas falharam duas vezes (o agente não tenta mais sozinho). */
    private function imagensFalharam(): void
    {
        $kit = MlAnuncioCriativoKit::create(['token' => Str::random(32), 'status' => MlAnuncioCriativoKit::STATUS_ERRO]);
        $r = $this->rascunhoDo($this->p);
        $estado = (array) $r->step_state;
        $estado['criativos_auto'] = ['grupos' => ['geral' => ['hash' => 'x', 'kit_id' => $kit->id, 'em' => now()->subHours(2)->toIso8601String(), 'tentativas' => 2]]];
        DB::table('pub_rascunhos')->where('id', $r->id)->update(['step_state' => json_encode($estado, JSON_UNESCAPED_UNICODE)]);
    }

    /** @return array<string, array> as etapas do texto gravadas no rascunho */
    private function etapas(): array
    {
        return (array) ($this->rascunhoDo($this->p)->step_state['ia_preparo']['etapas'] ?? []);
    }

    /** A linha da Simples na trilha (a régua da tela). */
    private function linha(): array
    {
        return $this->itemDaTrilha(app(TrilhaDaEmpresa::class)->montar($this->empresa), "s-{$this->p->id}");
    }
}
