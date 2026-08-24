<?php

namespace Tests\Feature\Phase123;

use App\Models\User;
use App\Services\DesempenhoScoreService;
use Carbon\Carbon;
use Illuminate\Foundation\Console\QueuedCommand;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;

/**
 * Regressão de 2026-08-24 — "Empresas da carteira" aparecia para uns
 * profissionais e não para outros na MESMA competência, e chegou a sumir de
 * quem já tinha a lista.
 *
 * Duas causas encadeadas, cobertas aqui:
 *
 *  1. ENVENENAMENTO DO CACHE. A chave de `computeCached()` não distingue
 *     `incluirEmpresasScore`. Toda leitura interativa (esta tela, o ranking, o
 *     Portfolio) chama sem o flag e grava um payload em que `empresas_score` é
 *     `[]` — a chave SEMPRE existe, `compute()` a define vazia quando o shadow
 *     está desligado. Competência fechada tem TTL de 7 dias, então esse payload
 *     passava a ser servido a quem PEDIA o shadow. Como
 *     `CompanyScoreSnapshotWriter::sync()` com coleção vazia apaga todas as
 *     linhas do par (D-122-03), o warm seguinte DELETAVA o detalhe já gravado:
 *     abrir a tela era o que apagava a lista.
 *
 *  2. BECO SEM SAÍDA NA TELA. Sem linhas gravadas, a seção só sabia dizer
 *     "indisponível" e esperar que o ciclo de 8min do warm agendado um dia
 *     passasse por aquele profissional.
 *
 * A régua que estes testes fixam: competência fechada + carteira não-vazia
 * SEMPRE resulta em lista ou em cálculo em andamento — nunca em "indisponível".
 */
class EmpresasCarteiraSempreApareceTest extends Phase123TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        Cache::put('alertas.criticos_nao_ackeados.count', 0, 300);
    }

    #[Test]
    public function competencia_fechada_com_carteira_e_sem_linhas_aquece_em_vez_de_dizer_indisponivel(): void
    {
        Queue::fake();

        $alvo = $this->criarUserElegivel('Com Carteira Sem Linha');
        $this->darCarteira($alvo);
        $this->adminLogado();

        $this->seedMensal($alvo->id, '2026-06-01');

        $this->get(route('performance.show', $alvo) . '?mes=2026-06')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Performance/Show')
                ->where('tem_detalhe_empresas', false)
                ->where('empresas_aquecendo', true)
            );

        // O warm foi realmente enfileirado para ESTE profissional e ESTA
        // competência — sem isso a tela ficaria em "calculando…" para sempre.
        Queue::assertPushed(QueuedCommand::class, function (QueuedCommand $job) use ($alvo) {
            [$comando, $params] = $this->dadosDoJob($job) + [null, []];

            return $comando === 'desempenho:warm-cache'
                && ($params['--mes'] ?? null) === '2026-06'
                && in_array($alvo->id, $params['--user'] ?? [], true);
        });
    }

    #[Test]
    public function profissional_sem_carteira_nao_promete_calculo(): void
    {
        Queue::fake();

        $alvo = $this->criarUserElegivel('Sem Carteira Nenhuma');
        $this->adminLogado();

        $this->seedMensal($alvo->id, '2026-06-01');

        $this->get(route('performance.show', $alvo) . '?mes=2026-06')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Performance/Show')
                ->where('tem_detalhe_empresas', false)
                ->where('empresas_aquecendo', false)
            );

        Queue::assertNotPushed(QueuedCommand::class);
    }

    #[Test]
    public function dois_profissionais_seguidos_aquecem_os_dois(): void
    {
        // O lock de `agendarWarm()` é por MÊS: com ele, o segundo profissional
        // aberto dentro da mesma janela de 3min não teria job nenhum em voo e
        // ficaria em "calculando…" indefinidamente. Por isso o detalhe por
        // empresa tem lock próprio, por (user, competência).
        Queue::fake();

        $primeiro = $this->criarUserElegivel('Primeiro');
        $segundo  = $this->criarUserElegivel('Segundo');
        $this->darCarteira($primeiro);
        $this->darCarteira($segundo);
        $this->adminLogado();

        $this->seedMensal($primeiro->id, '2026-06-01');
        $this->seedMensal($segundo->id, '2026-06-01');

        $this->get(route('performance.show', $primeiro) . '?mes=2026-06')->assertOk();

        $this->get(route('performance.show', $segundo) . '?mes=2026-06')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('empresas_aquecendo', true));

        Queue::assertPushed(QueuedCommand::class, function (QueuedCommand $job) use ($segundo) {
            [$comando, $params] = $this->dadosDoJob($job) + [null, []];

            return $comando === 'desempenho:warm-cache'
                && in_array($segundo->id, $params['--user'] ?? [], true);
        });
    }

    #[Test]
    public function pedir_o_shadow_descarta_payload_cacheado_com_lista_vazia(): void
    {
        $user = User::factory()->create();
        $mes  = Carbon::parse('2026-06-01');

        /** @var DesempenhoScoreService $service */
        $service  = app(DesempenhoScoreService::class);
        $cacheKey = $service->cacheKey($user->id, $mes);

        // Exatamente o payload que uma leitura interativa grava: a chave
        // existe, vazia, com carteira não-vazia.
        Cache::put($cacheKey, [
            'user_id'           => $user->id,
            'empresas_carteira' => 7,
            'nota_final'        => 4.2,
            'empresas_score'    => [],
        ], now()->addDays(7));

        // Quem NÃO pede o shadow segue servido pelo cache — o guard não pode
        // custar um recompute de ~70s a quem só quer a nota.
        $semShadow = $service->computeCached($user, $mes);
        $this->assertSame(4.2, $semShadow['nota_final']);

        // Quem PEDE o shadow não aceita esse payload: a chave é descartada.
        $service->computeCached($user, $mes, null, incluirEmpresasScore: true);

        $recomputado = Cache::get($cacheKey);
        $this->assertNotSame(4.2, $recomputado['nota_final'] ?? null,
            'O payload sem shadow deveria ter sido descartado e recomputado.');
    }

    #[Test]
    public function carteira_vazia_no_cache_nao_dispara_recompute(): void
    {
        // Espelho do teste acima: `empresas_score` vazio COM
        // `empresas_carteira = 0` é a resposta correta para quem não tem
        // carteira (DESEMP-10), não degradação — e não pode custar recompute.
        $user = User::factory()->create();
        $mes  = Carbon::parse('2026-06-01');

        /** @var DesempenhoScoreService $service */
        $service  = app(DesempenhoScoreService::class);
        $cacheKey = $service->cacheKey($user->id, $mes);

        Cache::put($cacheKey, [
            'user_id'           => $user->id,
            'empresas_carteira' => 0,
            'sem_carteira'      => true,
            'nota_final'        => null,
            'marcador_do_teste' => 'intacto',
            'empresas_score'    => [],
        ], now()->addDays(7));

        $service->computeCached($user, $mes, null, incluirEmpresasScore: true);

        $this->assertSame('intacto', Cache::get($cacheKey)['marcador_do_teste'] ?? null);
    }

    /**
     * `Kernel::queue()` despacha `QueuedCommand::dispatch(func_get_args())`,
     * então `$data[0]` é o nome do comando e `$data[1]` os parâmetros. Mesmo
     * helper de `Phase106\PerformanceControllerWarmDegradationTest`.
     */
    private function dadosDoJob(QueuedCommand $job): array
    {
        $ref = new \ReflectionProperty(QueuedCommand::class, 'data');
        $ref->setAccessible(true);

        return $ref->getValue($job);
    }
}
