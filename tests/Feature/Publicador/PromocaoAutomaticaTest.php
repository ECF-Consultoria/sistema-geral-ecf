<?php

namespace Tests\Feature\Publicador;

use App\Jobs\Publicador\CriarPromocaoAutomaticaJob;
use App\Models\Company;
use App\Models\Configuracao;
use App\Models\MlToken;
use App\Models\PubAlavancaEscrita;
use App\Models\PubPromocaoAutomatica;
use App\Models\PubPublicacao;
use App\Models\PubPublicacaoItem;
use App\Models\PubTarefa;
use App\Models\User;
use App\Notifications\TarefaAlavancasNotification;
use App\Services\Publicador\Alavancas\PromocaoAutomaticaService;
use App\Services\Publicador\ConferenciaService;
use App\Services\Publicador\PublicacaoService;
use App\Services\Publicador\Tarefas\TarefasPosPublicacao;
use App\Support\Portal\AtorDoPortal;
use App\Support\Publicador\Payload\Alvo;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Feature\Publicador\Concerns\CenarioCadeira;
use Tests\TestCase;

/**
 * Promoção automática pós-publicação (10/10/2026, decisão do usuário): cada anúncio CRIADO pelo
 * Publicador ganha sozinho o desconto individual de 14 dias com o preço de promoção do Portal, que se
 * renova sozinho enquanto o anúncio estiver ativo e com o mesmo preço. O ML é simulado (a cadeira da
 * conta #459 do `CenarioCadeira`), com UM fake que responde pelo estado do teste (learnings §5). O relógio
 * fica na sexta 09/10/2026 15h: o ciclo 1 vai de 09/10 a 22/10 e a tarefa vence na terça 13/10.
 */
class PromocaoAutomaticaTest extends TestCase
{
    use CenarioCadeira;
    use RefreshDatabase;

    private const PORTAL = [
        'titulos' => ['gold_special' => null, 'gold_pro' => null],
        'precos' => ['gold_special' => 207.19, 'gold_pro' => 223.26],
        'promocoes' => ['gold_special' => 172.66, 'gold_pro' => 186.05],
        'sem_frete' => ['gold_special' => false, 'gold_pro' => false],
        'mlbs' => [],
    ];

    private User $vitoria;

    /** @var array<string, array> MLB → sobrescritas do que o multiget devolve (padrão: ativo, novo, R$ 207,19, desta conta) */
    private array $anuncios = [];

    /** @var array<string, list<array>> MLB → o que `GET /seller-promotions/items/{MLB}` devolve */
    private array $entradas = [];

    /** @var \Closure(Request): mixed o `POST /seller-promotions/items/{MLB}` agora */
    private \Closure $criarPromocao;

    private int $posts = 0;

    /** @var \Closure(array, int): mixed o `POST /items` agora (publicação de verdade) */
    private \Closure $criar;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-09 15:00:00', 'America/Sao_Paulo'));
        Queue::fake();
        Notification::fake();
        $this->montarCenario();
        config(['publicador.alavancas.contas_liberadas' => ['companies' => [$this->empresa->id], 'mlb_empresas' => []]]);
        $this->vitoria = User::factory()->create(['name' => 'Vitória Publicadora', 'role' => 'admin']);
        $this->efetivos = self::PORTAL;
        // Os dois tipos ligados e o preço digitado do cenário (150) fora: vale o da Precificação do Portal.
        $this->repo->gravarAlvos($this->r, [new Alvo('gold_special', 'Cadeira Escritório Executiva ECF Giratória'), new Alvo('gold_pro', 'Cadeira de Escritório ECF Executiva com Giro')]);
        $this->variante(['SELLER_SKU' => ['value_name' => 'CAD-01'], 'GTIN' => ['value_name' => '7896553367645']]);
        $this->r->variantes()->first()->precos()->update(['preco' => null]);
        $this->r = $this->r->fresh();

        $this->criarPromocao = fn () => Http::response(json_decode(file_get_contents(base_path('tests/fixtures-ml/alavancas/doc/acoes/post_item_ok.json')), true)['resposta'], 201);
        $this->criar = fn (array $corpo, int $n) => Http::response(['id' => sprintf('MLB90000000%02d', $n), 'status' => 'active',
            'family_name' => $corpo['family_name'] ?? null, 'listing_type_id' => $corpo['listing_type_id'] ?? null], 201);

        $this->fakeMl([
            '*/oauth/token' => fn () => Http::response(['access_token' => 'novo-access', 'refresh_token' => 'novo-refresh', 'expires_in' => 21600, 'user_id' => 1555596317]),
            // Antes de `*/items/MLB*`: a mesma URL termina em /items/MLB… e o primeiro stub que casa vence.
            '*/seller-promotions/items/*' => fn (Request $q) => $q->method() === 'POST'
                ? ($this->criarPromocao)($q)
                : Http::response($this->entradas[self::mlbDaUrl($q)] ?? [], 200),
            '*/items?*' => fn (Request $q) => $this->multiget($q),
            '*/items/*/description' => fn () => Http::response(['plain_text' => 'ok'], 200),
            '*/items/MLB*' => fn (Request $q) => Http::response(['id' => self::mlbDaUrl($q), 'status' => 'active', 'sub_status' => [], 'tags' => [],
                'permalink' => 'https://produto.mercadolivre.com.br/'.self::mlbDaUrl($q).'-cadeira-_JM']),
            '*/items' => fn (Request $q) => ($this->criar)($q->data(), ++$this->posts),
        ]);
    }

    // ═══ Apoio ══════════════════════════════════════════════════════════════

    private static function mlbDaUrl(Request $q): string
    {
        return basename((string) parse_url($q->url(), PHP_URL_PATH));
    }

    private function multiget(Request $q): mixed
    {
        parse_str((string) parse_url($q->url(), PHP_URL_QUERY), $query);
        $ids = array_values(array_filter(explode(',', (string) ($query['ids'] ?? ''))));

        return Http::response(array_map(fn (string $id) => ['code' => 200, 'body' => [
            'id' => $id, 'seller_id' => 1555596317, 'title' => "Cadeira {$id}", 'price' => 207.19, 'original_price' => null,
            'status' => 'active', 'condition' => 'new', 'listing_type_id' => 'gold_special', 'available_quantity' => 3, 'attributes' => [],
            ...($this->anuncios[$id] ?? []),
        ]], $ids), 200);
    }

    /**
     * Uma publicação concluída gravada direto no banco (o caminho do ML até aqui já é provado pelo
     * `PublicacaoTest`), com o preço de cada item no payload, como o `PayloadBuilderUserProducts` grava.
     *
     * @param  array<string, array{0: string, 1: float}>  $itens  MLB → [listing_type_id, preço publicado]
     */
    private function publicado(array $itens = ['MLB1001' => ['gold_special', 207.19]], ?User $ator = null): PubPublicacao
    {
        $ator ??= $this->vitoria;
        $p = PubPublicacao::create([
            'rascunho_id' => $this->r->id, 'revisao' => $this->r->revisao, 'modelo_publicacao' => 'user_products', 'plano_hash' => str_repeat('a', 64),
            'status' => PubPublicacao::PUBLISHED, 'chave_idempotencia' => (string) Str::uuid(), 'iniciada_em' => now(), 'concluida_em' => now(),
            'ator' => ['equipe' => true, 'id' => $ator->id, 'nome' => $ator->name,
                'conta' => ['chave' => $this->empresa->chaveContaMl(), 'seller' => '1555596317']],
        ]);
        $indice = 0;
        foreach ($itens as $mlb => [$tipo, $preco]) {
            PubPublicacaoItem::create([
                'publicacao_id' => $p->id, 'indice' => $indice++, 'listing_type_id' => $tipo, 'variante_chave' => '__single__',
                'payload' => ['family_name' => 'Cadeira Executiva', 'price' => $preco, 'listing_type_id' => $tipo],
                'status' => PubPublicacaoItem::CREATED, 'ml_item_id' => $mlb, 'avisos' => ['estado' => ['status' => 'active']], 'criado_em' => now(),
            ]);
        }

        return $p;
    }

    /** O fim de toda publicação: a tarefa e, logo depois, a promoção (o que `PublicacaoService` faz). */
    private function concluir(PubPublicacao $p): array
    {
        app(TarefasPosPublicacao::class)->abrir($p, avisar: false);

        return app(PromocaoAutomaticaService::class)->agendar($p);
    }

    private function rodarJob(int $id): void
    {
        (new CriarPromocaoAutomaticaJob($id))->handle(app(PromocaoAutomaticaService::class));
    }

    /** @return list<Request> */
    private function postsDePromocao(): array
    {
        return array_values(array_map(fn ($par) => $par[0], Http::recorded(fn (Request $q) => $q->method() === 'POST' && str_contains($q->url(), '/seller-promotions/items/'))->all()));
    }

    private function ciclo(string $mlb, int $ciclo = 1): PubPromocaoAutomatica
    {
        return PubPromocaoAutomatica::query()->where('ml_item_id', $mlb)->where('ciclo', $ciclo)->sole();
    }

    private function tarefa(): PubTarefa
    {
        return PubTarefa::query()->alavancas()->sole();
    }

    private function criada(string $mlb = 'MLB1001'): PubPromocaoAutomatica
    {
        $this->concluir($this->publicado([$mlb => ['gold_special', 207.19]]));
        $this->rodarJob($this->ciclo($mlb)->id);

        return $this->ciclo($mlb)->fresh();
    }

    // ═══ O gatilho ══════════════════════════════════════════════════════════

    public function test_publicou_de_verdade_so_o_item_criado_ganha_o_ciclo_agendado_e_o_job(): void
    {
        // O Premium é recusado pelo ML: só o Clássico é criado.
        $criar = $this->criar;
        $this->criar = fn (array $corpo, int $n) => $n === 2
            ? Http::response(['message' => 'Validation error', 'error' => 'validation_error', 'status' => 400, 'cause' => [['cause_id' => 3701, 'type' => 'error', 'code' => 'item.attribute.invalid', 'message' => 'GTIN', 'references' => ['item.attributes']]]], 400)
            : $criar($corpo, $n);

        $v = app(ConferenciaService::class)->conferir($this->r->fresh());
        $this->assertContains($v->resultado, [ConferenciaService::OK, ConferenciaService::AVISOS], json_encode($v->issues, JSON_UNESCAPED_UNICODE));
        $p = app(PublicacaoService::class)->iniciar($this->r->fresh(), AtorDoPortal::daEquipe($this->vitoria), cienteDosAvisos: true);
        $this->assertTrue(app(PublicacaoService::class)->executarFatia($p->fresh(), 45));
        $this->assertSame(PubPublicacao::PARTIALLY_PUBLISHED, $p->fresh()->status);

        $c = PubPromocaoAutomatica::query()->sole();
        $this->assertSame('MLB9000000001', $c->ml_item_id, 'só o item CRIADO');
        $this->assertSame('gold_special', $c->listing_type);
        $this->assertSame($p->id, $c->publicacao_id);
        $this->assertSame($this->r->id, $c->rascunho_id);
        $this->assertSame($this->produto->id, $c->produto_id);
        $this->assertSame('company-'.$this->empresa->id, $c->conta_chave);
        $this->assertSame([207.19, 172.66, 16.67], [$c->preco_publicado, $c->preco_promocao, $c->percentual], 'anuncia 207,19 → promoção 172,66 (−16,67%)');
        $this->assertSame([1, '2026-10-09', '2026-10-22'], [$c->ciclo, (string) $c->inicio, (string) $c->fim], '14 dias contando as duas pontas');
        $this->assertSame(PubPromocaoAutomatica::AGENDADA, $c->status);
        $this->assertSame(0, $c->tentativas);
        $this->assertSame(now()->addMinutes(3)->toIso8601String(), $c->proxima_tentativa_em->toIso8601String());

        Queue::assertPushed(CriarPromocaoAutomaticaJob::class, 1);
        Queue::assertPushed(CriarPromocaoAutomaticaJob::class, fn (CriarPromocaoAutomaticaJob $j) => $j->promocaoId === $c->id
            && $j->queue === 'high' && $j->tries === 1
            && CarbonImmutable::instance($j->delay)->equalTo(now()->addMinutes(3)));
        $this->assertSame([], $this->postsDePromocao(), 'o gatilho não escreve no ML: quem escreve é o Job');
        $this->assertNotNull(PubTarefa::query()->alavancas()->where('publicacao_id', $p->id)->first(), 'a tarefa abriu antes');
    }

    public function test_preco_digitado_aplica_o_mesmo_percentual_e_nunca_abaixo_do_minimo(): void
    {
        $this->concluir($this->publicado(['MLB1001' => ['gold_special', 250.00], 'MLB1002' => ['gold_pro', 200.00]]));

        // Clássico 250: o mesmo percentual do Portal (172,66 ÷ 207,19) → 208,34.
        $this->assertSame([250.0, 208.34, 16.66], [$this->ciclo('MLB1001')->preco_publicado, $this->ciclo('MLB1001')->preco_promocao, $this->ciclo('MLB1001')->percentual]);
        // Premium 200: 200 × (186,05 ÷ 223,26) = 166,67 ficaria abaixo do mínimo — fica no mínimo 186,05.
        $this->assertSame([200.0, 186.05, 6.98], [$this->ciclo('MLB1002')->preco_publicado, $this->ciclo('MLB1002')->preco_promocao, $this->ciclo('MLB1002')->percentual]);
        Queue::assertPushed(CriarPromocaoAutomaticaJob::class, 2);
    }

    public function test_sem_promocao_calculavel_nao_nasce_ciclo(): void
    {
        // Preço publicado no mínimo do Portal (desconto zero) e Portal do Premium calculado sem frete.
        $this->efetivos = [...self::PORTAL, 'sem_frete' => ['gold_special' => false, 'gold_pro' => true]];
        $this->concluir($this->publicado(['MLB1001' => ['gold_special', 172.66], 'MLB1002' => ['gold_pro', 223.26]]));

        $this->assertSame(0, PubPromocaoAutomatica::query()->count());
        Queue::assertNothingPushed();

        // Produto sem preço no Portal: nada também.
        $this->efetivos = ['titulos' => [], 'precos' => [], 'mlbs' => []];
        $this->concluir($this->publicado(['MLB1003' => ['gold_special', 150.00]]));
        $this->assertSame(0, PubPromocaoAutomatica::query()->count());
    }

    public function test_conta_nao_liberada_nao_escreve_no_ml_e_a_tarefa_orienta(): void
    {
        config(['publicador.alavancas.contas_liberadas' => ['companies' => [], 'mlb_empresas' => []]]);

        $this->concluir($this->publicado());

        $c = $this->ciclo('MLB1001');
        $this->assertSame(PubPromocaoAutomatica::RECUSADA, $c->status);
        $this->assertStringContainsString('Conta não liberada', (string) $c->motivo);
        $this->assertSame('Crie a promoção de R$ 207,19 para R$ 172,66 (−16,67%) até 22/10 no Seller Center.', $c->orientacao());
        Queue::assertNothingPushed();
        Http::assertNotSent(fn (Request $q) => str_contains($q->url(), '/seller-promotions/'));
        $this->assertSame(PubTarefa::ITEM_PENDENTE, $this->tarefa()->checklist['central_promocao']['estado']);

        // A fila mostra a orientação no item da Central de Promoções.
        $promocoes = PromocaoAutomaticaService::dasTarefas([$this->tarefa()])[$this->tarefa()->id];
        $this->assertSame('recusada', $promocoes[0]['status']);
        $this->assertSame('Crie a promoção de R$ 207,19 para R$ 172,66 (−16,67%) até 22/10 no Seller Center.', $promocoes[0]['orientacao']);
    }

    // ═══ O Job ══════════════════════════════════════════════════════════════

    public function test_ok_cria_o_desconto_de_14_dias_ativa_o_ciclo_e_da_baixa_na_tarefa(): void
    {
        $c = $this->criada();

        $this->assertSame(PubPromocaoAutomatica::ATIVA, $c->status);
        $escrita = PubAlavancaEscrita::query()->findOrFail($c->escrita_id);
        $this->assertSame(PubAlavancaEscrita::OK, $escrita->resultado, (string) $escrita->mensagem);
        $this->assertSame(['desconto.criar', 'MLB1001', $this->vitoria->id], [$escrita->acao, $escrita->item_id, $escrita->user_id], 'assinada por quem publicou');

        $posts = $this->postsDePromocao();
        $this->assertCount(1, $posts);
        $this->assertStringContainsString('/seller-promotions/items/MLB1001', $posts[0]->url());
        $this->assertStringContainsString('app_version=v2', $posts[0]->url());
        $this->assertSame(['promotion_type' => 'PRICE_DISCOUNT', 'deal_price' => 172.66, 'start_date' => '2026-10-09T00:00:00', 'finish_date' => '2026-10-22T23:59:59'], $posts[0]->data());

        $item = $this->tarefa()->checklist['central_promocao'];
        $this->assertSame(PubTarefa::ITEM_FEITO, $item['estado'], 'a baixa do EscritorAlavancas');
        $this->assertSame($escrita->id, $item['escrita_id']);
        $this->assertSame(PubTarefa::EM_ANDAMENTO, $this->tarefa()->status);
    }

    public function test_anuncio_ainda_nao_ativo_volta_com_espera_crescente_e_por_fim_recusa(): void
    {
        config(['publicador.promocao_automatica.tentativas_max' => 3, 'publicador.promocao_automatica.esperas_min' => [5, 10]]);
        $this->anuncios['MLB1001'] = ['status' => 'under_review'];
        $this->concluir($this->publicado());
        $id = $this->ciclo('MLB1001')->id;

        $this->rodarJob($id);
        $c = $this->ciclo('MLB1001');
        $this->assertSame([PubPromocaoAutomatica::AGENDADA, 1], [$c->status, $c->tentativas]);
        $this->assertSame('Aguardando o anúncio ficar ativo (agora: em revisão).', $c->motivo);
        $this->assertSame(now()->addMinutes(5)->toIso8601String(), $c->proxima_tentativa_em->toIso8601String(), '1ª espera: 5 min');

        $this->rodarJob($id);
        $this->assertSame(now()->addMinutes(10)->toIso8601String(), $this->ciclo('MLB1001')->proxima_tentativa_em->toIso8601String(), '2ª espera: 10 min');
        // Um Job novo por volta (nunca `release()`, `tries = 1`): o do gatilho + dois.
        Queue::assertPushed(CriarPromocaoAutomaticaJob::class, 3);

        $this->rodarJob($id);
        $c = $this->ciclo('MLB1001');
        $this->assertSame(PubPromocaoAutomatica::RECUSADA, $c->status);
        $this->assertSame('O anúncio não ficou ativo a tempo para a promoção automática (situação no Mercado Livre: em revisão).', $c->motivo);
        $this->assertSame([], $this->postsDePromocao(), 'anúncio fora do ar nunca recebe escrita');
        $this->assertSame(PubTarefa::ITEM_PENDENTE, $this->tarefa()->checklist['central_promocao']['estado']);

        // Ficou ativo antes do fim: a volta seguinte cria.
        $this->anuncios = [];
        $this->concluir($this->publicado(['MLB1002' => ['gold_special', 207.19]]));
        $this->anuncios['MLB1002'] = ['status' => 'paused'];
        $this->rodarJob($this->ciclo('MLB1002')->id);
        $this->anuncios['MLB1002'] = [];
        $this->rodarJob($this->ciclo('MLB1002')->id);
        $this->assertSame(PubPromocaoAutomatica::ATIVA, $this->ciclo('MLB1002')->status);
    }

    public function test_recusa_do_ml_fica_recusada_com_o_motivo_e_a_tarefa_orienta(): void
    {
        $this->criarPromocao = fn () => Http::response(['message' => 'Seller reputation is not enough', 'error' => 'bad_request', 'status' => 400,
            'cause' => [['error_code' => 'seller_reputation_not_allowed', 'error_message' => 'Seller reputation is not enough']]], 400);

        $c = $this->criada();

        $this->assertSame(PubPromocaoAutomatica::RECUSADA, $c->status);
        $this->assertSame('A reputação da conta não permite desconto agora. O Mercado Livre recusou: Seller reputation is not enough', $c->motivo);
        $this->assertSame(PubAlavancaEscrita::ERRO, PubAlavancaEscrita::query()->findOrFail($c->escrita_id)->resultado);
        $this->assertCount(1, $this->postsDePromocao(), 'recusa (4xx) nunca se repete');
        $this->assertSame(PubTarefa::ITEM_PENDENTE, $this->tarefa()->checklist['central_promocao']['estado'], 'sem baixa');
        $linha = PromocaoAutomaticaService::dasTarefas([$this->tarefa()])[$this->tarefa()->id][0];
        $this->assertSame($c->motivo, $linha['motivo']);
        $this->assertSame('Crie a promoção de R$ 207,19 para R$ 172,66 (−16,67%) até 22/10 no Seller Center.', $linha['orientacao']);
    }

    public function test_5xx_do_ml_nunca_repete_e_pede_para_conferir(): void
    {
        $this->criarPromocao = fn () => Http::response(['message' => 'internal'], 503);

        $c = $this->criada();

        $this->assertSame(PubPromocaoAutomatica::RECUSADA, $c->status);
        $this->assertStringContainsString('Confira no Seller Center se a promoção existe', (string) $c->motivo);
        $this->assertCount(1, $this->postsDePromocao());
        $this->assertSame(PubAlavancaEscrita::INCERTO, PubAlavancaEscrita::query()->findOrFail($c->escrita_id)->resultado);
    }

    public function test_anuncio_que_ja_tem_desconto_nao_recebe_outro(): void
    {
        $this->anuncios['MLB1001'] = ['price' => 180.0, 'original_price' => 207.19];
        $this->entradas['MLB1001'] = [['type' => 'PRICE_DISCOUNT', 'status' => 'started', 'price' => 180, 'original_price' => 207.19]];

        $c = $this->criada();

        $this->assertSame(PubPromocaoAutomatica::CANCELADA, $c->status, 'ALAV-DESC-08: nunca dois descontos no mesmo item');
        $this->assertStringContainsString('já tinha desconto', (string) $c->motivo);
        $this->assertSame([], $this->postsDePromocao());
    }

    public function test_preco_mudado_antes_do_job_nao_cria(): void
    {
        $this->anuncios['MLB1001'] = ['price' => 199.90];

        $c = $this->criada();

        $this->assertSame(PubPromocaoAutomatica::RECUSADA, $c->status);
        $this->assertSame('O preço do anúncio mudou desde a publicação (era R$ 207,19, agora R$ 199,90): a promoção calculada não vale mais.', $c->motivo);
        $this->assertSame([], $this->postsDePromocao());
    }

    public function test_sem_ator_valido_recusa_e_o_usuario_de_sistema_assina_quando_configurado(): void
    {
        $saiu = User::factory()->create(['name' => 'Saiu da ECF', 'role' => 'admin']);
        $saiu->forceFill(['active' => false])->save();

        $this->concluir($this->publicado(['MLB1001' => ['gold_special', 207.19]], ator: $saiu));
        $this->rodarJob($this->ciclo('MLB1001')->id);
        $this->assertSame(PubPromocaoAutomatica::RECUSADA, $this->ciclo('MLB1001')->status);
        $this->assertStringContainsString('Sem usuário para registrar a escrita', (string) $this->ciclo('MLB1001')->motivo);
        $this->assertSame([], $this->postsDePromocao());

        $sistema = User::factory()->create(['name' => 'Sistema Publicador', 'role' => 'admin']);
        Configuracao::set(PromocaoAutomaticaService::CHAVE_USUARIO_SISTEMA, $sistema->id);
        $this->concluir($this->publicado(['MLB1002' => ['gold_special', 207.19]], ator: $saiu));
        $this->rodarJob($this->ciclo('MLB1002')->id);
        $this->assertSame(PubPromocaoAutomatica::ATIVA, $this->ciclo('MLB1002')->status);
        $this->assertSame($sistema->id, PubAlavancaEscrita::query()->findOrFail($this->ciclo('MLB1002')->escrita_id)->user_id);
    }

    // ═══ Idempotência e segurança ═══════════════════════════════════════════

    public function test_gatilho_e_job_repetidos_nao_duplicam(): void
    {
        $p = $this->publicado();
        $this->concluir($p);
        $this->assertSame([], app(PromocaoAutomaticaService::class)->agendar($p->fresh()), 'o gatilho rodado de novo não cria nada');
        $this->assertSame(1, PubPromocaoAutomatica::query()->count());
        Queue::assertPushed(CriarPromocaoAutomaticaJob::class, 1);

        $id = $this->ciclo('MLB1001')->id;
        $this->rodarJob($id);
        $this->rodarJob($id);
        $this->assertCount(1, $this->postsDePromocao(), 'o ciclo já ativo não escreve de novo');
    }

    public function test_dois_jobs_do_mesmo_anuncio_ao_mesmo_tempo_um_espera(): void
    {
        $this->concluir($this->publicado());
        $trava = \Illuminate\Support\Facades\Cache::lock(PromocaoAutomaticaService::chaveDaTrava('MLB1001'), 60);
        $this->assertTrue($trava->get());

        $this->rodarJob($this->ciclo('MLB1001')->id);

        $this->assertSame(PubPromocaoAutomatica::AGENDADA, $this->ciclo('MLB1001')->status, 'outro processo está no anúncio: não mexe');
        $this->assertSame([], $this->postsDePromocao());
        $trava->release();
    }

    public function test_nunca_escreve_em_anuncio_de_outra_conta(): void
    {
        $this->concluir($this->publicado(['MLB1001' => ['gold_special', 207.19], 'MLB1002' => ['gold_pro', 223.26]]));

        // O MLB2 voltou do multiget como de OUTRO vendedor: não é anúncio desta conta.
        $this->anuncios['MLB1002'] = ['seller_id' => 999];
        $this->rodarJob($this->ciclo('MLB1002')->id);
        $this->assertSame(PubPromocaoAutomatica::RECUSADA, $this->ciclo('MLB1002')->status);
        $this->assertSame('O anúncio não foi encontrado nesta conta do Mercado Livre: nada foi enviado.', $this->ciclo('MLB1002')->motivo);

        // A conexão da empresa virou de outro vendedor depois da publicação: nem lê o anúncio.
        MlToken::query()->where('company_id', $this->empresa->id)->update(['ml_user_id' => '999']);
        Http::fake(); // nada novo é registrado: qualquer chamada nova daria 200 vazio e apareceria na contagem
        $antes = count(Http::recorded());
        $this->rodarJob($this->ciclo('MLB1001')->id);
        $this->assertSame(PubPromocaoAutomatica::RECUSADA, $this->ciclo('MLB1001')->status);
        $this->assertSame('A conexão desta empresa agora é de outra conta do Mercado Livre: nada foi enviado.', $this->ciclo('MLB1001')->motivo);
        $this->assertSame($antes, count(Http::recorded()), 'nenhuma chamada ao ML');
        $this->assertSame([], $this->postsDePromocao());
    }

    public function test_ciclo_com_ancora_de_outra_empresa_nao_escreve(): void
    {
        $this->concluir($this->publicado());
        $outra = Company::factory()->create();
        MlToken::create(['company_id' => $outra->id, 'ml_user_id' => '1555596317', 'access_token' => 'outro', 'refresh_token' => 'outro',
            'token_type' => 'bearer', 'expires_at' => now()->addHours(5), 'last_refreshed_at' => now(), 'status' => 'active', 'connected_at' => now()]);
        config(['publicador.alavancas.contas_liberadas' => ['companies' => [$this->empresa->id, $outra->id], 'mlb_empresas' => []]]);
        // Linha adulterada: âncora de outra empresa com a chave da que publicou.
        $this->ciclo('MLB1001')->update(['company_id' => $outra->id]);

        $this->rodarJob($this->ciclo('MLB1001')->id);

        $this->assertSame(PubPromocaoAutomatica::RECUSADA, $this->ciclo('MLB1001')->status);
        $this->assertSame([], $this->postsDePromocao());
    }

    // ═══ A tarefa ═══════════════════════════════════════════════════════════

    public function test_baixa_de_um_anuncio_nao_esconde_o_outro_que_ficou_sem_promocao(): void
    {
        $this->concluir($this->publicado(['MLB1001' => ['gold_special', 207.19], 'MLB1002' => ['gold_pro', 223.26]]));
        $this->anuncios = ['MLB1002' => ['price' => 223.26, 'listing_type_id' => 'gold_pro'], 'MLB2002' => ['price' => 223.26, 'listing_type_id' => 'gold_pro']];
        $this->criarPromocao = fn (Request $q) => str_contains($q->url(), 'MLB1002')
            ? Http::response(['message' => 'Item has a deal running', 'error' => 'bad_request', 'status' => 400], 400)
            : Http::response(['price' => 172.66, 'original_price' => 207.19], 201);

        // O Premium é recusado primeiro; o Clássico dá certo depois: a Central de Promoções segue pendente.
        $this->rodarJob($this->ciclo('MLB1002')->id);
        $this->rodarJob($this->ciclo('MLB1001')->id);
        $this->assertSame([PubPromocaoAutomatica::ATIVA, PubPromocaoAutomatica::RECUSADA], [$this->ciclo('MLB1001')->status, $this->ciclo('MLB1002')->status]);
        $this->assertStringStartsWith('O anúncio está numa campanha do Mercado Livre.', (string) $this->ciclo('MLB1002')->motivo);
        $this->assertSame(PubTarefa::ITEM_PENDENTE, $this->tarefa()->checklist['central_promocao']['estado'], 'o Premium ainda espera a promoção à mão');

        // Ao contrário (Clássico primeiro, Premium recusado depois): volta a pendente.
        $this->tarefa()->delete();
        PubPromocaoAutomatica::query()->delete();
        $this->concluir($this->publicado(['MLB2001' => ['gold_special', 207.19], 'MLB2002' => ['gold_pro', 223.26]]));
        $this->criarPromocao = fn (Request $q) => str_contains($q->url(), 'MLB2002')
            ? Http::response(['message' => 'Item has a deal running', 'error' => 'bad_request', 'status' => 400], 400)
            : Http::response(['price' => 172.66, 'original_price' => 207.19], 201);
        $this->rodarJob($this->ciclo('MLB2001')->id);
        $this->assertSame(PubTarefa::ITEM_FEITO, $this->tarefa()->checklist['central_promocao']['estado']);
        $this->rodarJob($this->ciclo('MLB2002')->id);
        $this->assertSame(PubTarefa::ITEM_PENDENTE, $this->tarefa()->checklist['central_promocao']['estado']);
    }

    public function test_recusa_do_primeiro_ciclo_nao_desfaz_o_que_a_pessoa_marcou(): void
    {
        $this->criarPromocao = fn () => Http::response(['message' => 'bad', 'error' => 'bad_request', 'status' => 400], 400);
        $this->concluir($this->publicado());
        $caio = User::factory()->create(['name' => 'Caio', 'role' => 'admin']);
        app(TarefasPosPublicacao::class)->marcar($this->tarefa(), 'central_promocao', PubTarefa::ITEM_FEITO, null, $caio);

        $this->rodarJob($this->ciclo('MLB1001')->id);

        $this->assertSame(PubPromocaoAutomatica::RECUSADA, $this->ciclo('MLB1001')->status);
        $item = $this->tarefa()->checklist['central_promocao'];
        $this->assertSame([PubTarefa::ITEM_FEITO, 'Caio'], [$item['estado'], $item['por']['nome']], 'quem marcou à mão já resolveu');
    }

    public function test_a_fila_mostra_o_estado_da_promocao_de_cada_anuncio(): void
    {
        $this->withoutVite();
        $this->concluir($this->publicado(['MLB1001' => ['gold_special', 207.19], 'MLB1002' => ['gold_pro', 223.26]]));
        $this->rodarJob($this->ciclo('MLB1001')->id);

        $page = $this->actingAs($this->vitoria)->get('/mlb/anuncios/publicador/tarefas')->assertOk()->viewData('page');

        $promocoes = collect($page['props']['tarefas'][0]['promocoes'])->keyBy('ml_item_id');
        $this->assertSame(['ativa', 'Clássico', '2026-10-22', 172.66, 16.67, null], [$promocoes['MLB1001']['status'], $promocoes['MLB1001']['tipo'],
            $promocoes['MLB1001']['fim'], $promocoes['MLB1001']['preco_promocao'], $promocoes['MLB1001']['percentual'], $promocoes['MLB1001']['orientacao']]);
        $this->assertSame(['agendada', 'Premium', 186.05], [$promocoes['MLB1002']['status'], $promocoes['MLB1002']['tipo'], $promocoes['MLB1002']['preco_promocao']]);
    }

    // ═══ Renovação ══════════════════════════════════════════════════════════

    public function test_renovacao_no_dia_seguinte_ao_fim_cria_o_ciclo_seguinte_com_o_mesmo_preco(): void
    {
        $this->criada();
        Queue::fake(); // só os Jobs da renovação daqui para frente

        // No último dia (22/10) ainda vale: nada.
        $this->travelTo(CarbonImmutable::parse('2026-10-22 23:00:00', 'America/Sao_Paulo'));
        $this->artisan('publicador:promocoes-renovar')->assertSuccessful();
        $this->assertSame(1, PubPromocaoAutomatica::query()->count());

        $this->travelTo(CarbonImmutable::parse('2026-10-23 00:05:00', 'America/Sao_Paulo'));
        $this->artisan('publicador:promocoes-renovar')->assertSuccessful();

        $antigo = $this->ciclo('MLB1001', 1);
        $novo = $this->ciclo('MLB1001', 2);
        $this->assertSame(PubPromocaoAutomatica::ENCERRADA, $antigo->status);
        $this->assertSame([PubPromocaoAutomatica::AGENDADA, '2026-10-23', '2026-11-05', 207.19, 172.66, 16.67],
            [$novo->status, (string) $novo->inicio, (string) $novo->fim, $novo->preco_publicado, $novo->preco_promocao, $novo->percentual]);
        Queue::assertPushed(CriarPromocaoAutomaticaJob::class, fn (CriarPromocaoAutomaticaJob $j) => $j->promocaoId === $novo->id && $j->queue === 'high');

        $this->rodarJob($novo->id);
        $this->assertSame(PubPromocaoAutomatica::ATIVA, $novo->fresh()->status);
        $ultimo = last($this->postsDePromocao());
        $this->assertSame(['start_date' => '2026-10-23T00:00:00', 'finish_date' => '2026-11-05T23:59:59'], array_intersect_key($ultimo->data(), ['start_date' => 1, 'finish_date' => 1]));

        // Rodar de novo no mesmo dia não cria o ciclo 3.
        $this->artisan('publicador:promocoes-renovar')->assertSuccessful();
        $this->assertSame(2, PubPromocaoAutomatica::query()->count());
    }

    public function test_renovacao_encerra_se_o_preco_mudou_ou_o_anuncio_fechou_e_nada_mais(): void
    {
        $this->concluir($this->publicado(['MLB1001' => ['gold_special', 207.19], 'MLB1002' => ['gold_special', 207.19]]));
        $this->rodarJob($this->ciclo('MLB1001')->id);
        $this->rodarJob($this->ciclo('MLB1002')->id);
        Queue::fake();

        $this->travelTo(CarbonImmutable::parse('2026-10-23 00:05:00', 'America/Sao_Paulo'));
        $this->anuncios = ['MLB1001' => ['price' => 219.90], 'MLB1002' => ['status' => 'closed']];
        $this->artisan('publicador:promocoes-renovar')->assertSuccessful();

        $this->assertSame(PubPromocaoAutomatica::ENCERRADA, $this->ciclo('MLB1001')->status);
        $this->assertSame('O preço do anúncio mudou (era R$ 207,19, agora R$ 219,90): a promoção não foi renovada.', $this->ciclo('MLB1001')->motivo);
        $this->assertSame(PubPromocaoAutomatica::ENCERRADA, $this->ciclo('MLB1002')->status);
        $this->assertSame('O anúncio foi encerrado no Mercado Livre: a promoção não foi renovada.', $this->ciclo('MLB1002')->motivo);
        $this->assertSame(2, PubPromocaoAutomatica::query()->count(), 'nenhum ciclo 2');
        Queue::assertNothingPushed();
        Notification::assertNothingSent();
    }

    public function test_falha_na_renovacao_reabre_a_tarefa_concluida_e_avisa(): void
    {
        $this->criada();
        $t = $this->tarefa();
        foreach (array_keys(PubTarefa::CHECKLIST_ALAVANCAS) as $chave) {
            if ($chave !== 'central_promocao') {
                app(TarefasPosPublicacao::class)->marcar($t->fresh(), $chave, PubTarefa::ITEM_NAO_SE_APLICA, 'teste', $this->vitoria);
            }
        }
        app(TarefasPosPublicacao::class)->concluir($t->fresh(), $this->vitoria);
        $this->assertSame(PubTarefa::FEITA, $t->fresh()->status);

        $this->travelTo(CarbonImmutable::parse('2026-10-23 00:05:00', 'America/Sao_Paulo'));
        $this->artisan('publicador:promocoes-renovar')->assertSuccessful();
        $this->criarPromocao = fn () => Http::response(['message' => 'Seller reputation is not enough', 'error' => 'bad_request', 'status' => 400], 400);
        $this->rodarJob($this->ciclo('MLB1001', 2)->id);

        $this->assertSame(PubPromocaoAutomatica::RECUSADA, $this->ciclo('MLB1001', 2)->status);
        $t->refresh();
        $this->assertSame(PubTarefa::PENDENTE, $t->status, 'a renovação que falhou reabre a tarefa');
        $this->assertNull($t->concluida_em);
        $this->assertSame('2026-10-26', $t->prazo, 'prazo novo: sexta 23/10 → segunda 26/10');
        $this->assertSame(PubTarefa::ITEM_PENDENTE, $t->checklist['central_promocao']['estado']);
        $this->assertSame(PubTarefa::ITEM_NAO_SE_APLICA, $t->checklist['cupom']['estado'], 'só a Central de Promoções volta');
        $this->assertSame('Crie a promoção de R$ 207,19 para R$ 172,66 (−16,67%) até 05/11 no Seller Center.',
            PromocaoAutomaticaService::dasTarefas([$t])[$t->id][0]['orientacao']);
        Notification::assertSentTo($this->vitoria, TarefaAlavancasNotification::class, fn (TarefaAlavancasNotification $n) => $n->toArray($this->vitoria)['titulo'] === 'Promoção não renovada');
    }

    public function test_renovacao_nunca_fica_abaixo_do_minimo_do_portal_de_agora(): void
    {
        $this->criada();
        Queue::fake();
        $this->travelTo(CarbonImmutable::parse('2026-10-23 00:05:00', 'America/Sao_Paulo'));

        // O custo subiu: o mínimo do Portal agora é 180,00 — o ciclo 2 sai por 180, não por 172,66.
        $this->efetivos = [...self::PORTAL, 'promocoes' => ['gold_special' => 180.00, 'gold_pro' => 186.05]];
        $this->artisan('publicador:promocoes-renovar')->assertSuccessful();
        $this->assertSame([180.0, 13.12], [$this->ciclo('MLB1001', 2)->preco_promocao, $this->ciclo('MLB1001', 2)->percentual]);
    }

    public function test_renovacao_sem_desconto_possivel_nao_renova_e_avisa_a_tarefa(): void
    {
        $this->criada();
        Queue::fake();
        $this->travelTo(CarbonImmutable::parse('2026-10-23 00:05:00', 'America/Sao_Paulo'));

        // Mínimo do Portal subiu para 200: o desconto ficaria abaixo de 5%.
        $this->efetivos = [...self::PORTAL, 'promocoes' => ['gold_special' => 200.00, 'gold_pro' => 186.05]];
        $this->artisan('publicador:promocoes-renovar')->assertSuccessful();

        $this->assertSame(1, PubPromocaoAutomatica::query()->count(), 'sem ciclo 2');
        $this->assertSame(PubPromocaoAutomatica::ENCERRADA, $this->ciclo('MLB1001')->status);
        $this->assertStringContainsString('Não renovada: o mínimo do Portal subiu e o desconto ficaria abaixo de 5%', (string) $this->ciclo('MLB1001')->motivo);
        Queue::assertNothingPushed();
        $this->assertSame(PubTarefa::ITEM_PENDENTE, $this->tarefa()->checklist['central_promocao']['estado']);
    }

    // ═══ Varredura e Job morto ══════════════════════════════════════════════

    public function test_varredura_reenvia_agendada_perdida_e_fecha_envio_preso(): void
    {
        $this->concluir($this->publicado(['MLB1001' => ['gold_special', 207.19], 'MLB1002' => ['gold_special', 207.19]]));
        $this->ciclo('MLB1002')->forceFill(['status' => PubPromocaoAutomatica::ENVIANDO])->save();
        Queue::fake();

        $this->travelTo(now()->addHours(2));
        $this->artisan('publicador:promocoes-renovar')->assertSuccessful();

        Queue::assertPushed(CriarPromocaoAutomaticaJob::class, fn (CriarPromocaoAutomaticaJob $j) => $j->promocaoId === $this->ciclo('MLB1001')->id);
        $this->assertSame(PubPromocaoAutomatica::RECUSADA, $this->ciclo('MLB1002')->status);
        $this->assertStringContainsString('interrompida depois de sair para o Mercado Livre', (string) $this->ciclo('MLB1002')->motivo);
    }

    public function test_job_que_quebra_nao_deixa_o_ciclo_preso(): void
    {
        $this->concluir($this->publicado());
        $c = $this->ciclo('MLB1001');

        (new CriarPromocaoAutomaticaJob($c->id))->failed(new \RuntimeException('worker morreu'));

        $this->assertSame(PubPromocaoAutomatica::RECUSADA, $c->fresh()->status);
        $this->assertSame('A criação foi interrompida antes de enviar (erro interno): nada foi enviado.', $c->fresh()->motivo);
    }
}
