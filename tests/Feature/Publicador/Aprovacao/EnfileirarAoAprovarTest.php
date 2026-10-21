<?php

namespace Tests\Feature\Publicador\Aprovacao;

use App\Models\Company;
use App\Models\MlbEmpresa;
use App\Models\MlToken;
use App\Models\PubAprovacaoConjunto;
use App\Models\PubAprovacaoEvento;
use App\Models\PubAprovacaoImagem;
use App\Models\PubAprovacaoImagemAlerta;
use App\Models\PubAprovacaoOferta;
use App\Models\PubFilaPublicacao;
use App\Models\PubFilaPublicacaoItem;
use App\Models\PubImagem;
use App\Models\PubProduto;
use App\Models\PubRascunho;
use App\Models\PubRascunhoAlvo;
use App\Models\PubValidacao;
use App\Models\User;
use App\Services\Portal\Estrutura\EstruturaPrecificacaoService;
use App\Services\Publicador\Aprovacao\AndamentoDaOfertaService;
use App\Services\Publicador\Aprovacao\HistoricoDaOfertaService;
use App\Services\Publicador\Aprovacao\PublicacaoDoPortaoService;
use App\Services\Publicador\ClienteMlPublicador;
use App\Services\Publicador\ConferenciaService;
use App\Services\Publicador\DadosEfetivosService;
use App\Services\Publicador\Fila\FilaPublicacaoService;
use App\Services\Publicador\ProgramasPublicadorService;
use App\Support\Publicador\Variacao\ChaveCanonica;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 * Fase 193, plano 02 — **APROVAR ENFILEIRA**: o gancho `aoAprovar()` ligado, a fila REUSADA, a
 * granularidade oferta × produto resolvida e a guarda da modalidade irmã (decisão 23).
 *
 * ⚠️⚠️ DE CADA CASO, A PERGUNTA RESPONDIDA ANTES DE ELE EXISTIR: **"o que precisaria estar errado
 * no mundo para ele falhar?"**
 *
 *   · **A segunda oferta do produto DUPLICAR o item de fila.** A fila publica o PRODUTO inteiro, e
 *     um produto está em UMA fila por vez (unique `pubfilai_produto_ativo_uq`). Sem o ANEXO, a
 *     segunda oferta ou perderia a ligação ou bateria no unique. ⚠️ **Com UMA oferta só isso é
 *     inobservável** — por isso toda fixture daqui tem **DUAS**.
 *   · **As 4 colunas de conferência serem COPIADAS para a oferta** (DADO-03), criando duas verdades
 *     livres para divergir. ➡️ Elas são LIDAS pelo item, e a isca invertida prova que a varredura
 *     não está cega: elas **existem** na tabela da fila.
 *   · **Um item que já terminou contar como vivo**, prendendo o produto fora da fila para sempre.
 *   · **A recusa da fila ser engolida em silêncio** (T-193-15), virando pilha de "aprovados que
 *     ninguém publicou". ➡️ O motivo REAL de `prontidaoDoLote()` vai ao histórico.
 *   · ⚠️⚠️ **A MODALIDADE IRMÃ IR AO AR SEM REVISÃO** (decisão 23 do usuário): a fila publica o
 *     produto e `PayloadBuilderUserProducts` monta um anúncio por **alvo ATIVO**, então aprovar só
 *     o Clássico e enfileirar o produto publicaria o Premium que ninguém revisou.
 *   · **Um job despachado DENTRO da transação** (T-193-16), rodando sem ver os dados comitados.
 *   · **Uma ESCRITA no Mercado Livre saindo da aprovação.**
 *
 * ═══ ⚠️ O QUE SAI PELA REDE, E POR QUE A RÉGUA É "ZERO ESCRITA", NÃO "ZERO" ════════════════════
 *
 * `aprovar()` chama `BloqueiosDaOfertaService` → `ComposicaoFinanceiraService`, que lê a tarifa
 * REAL no GET público `/sites/MLB/listing_prices` (decisão 9 do usuário). ➡️ Prometer "zero
 * requisição" seria um gate que **o próprio desenho contradiz**. A leitura permitida está em
 * **lista CONSTANTE nomeada** ({@see self::LEITURAS_AUTORIZADAS}), não num filtro largo.
 * ⚠️ **E o enfileiramento é SÓ BANCO:** medido, `FilaPublicacaoService::agendar()` não faz
 * requisição nenhuma — `ResumoRapidoService` declara isso no docblock, e aqui isso deixa de ser
 * afirmação e passa a ser medição, porque `preventStrayRequests()` está de pé em todos os casos.
 *
 * ⚠️⚠️ **NENHUM EXECUTOR DESTA FASE VIU ESTA TELA RENDERIZADA, nem publicação real.** Não há
 * navegador, nenhuma empresa tem `ml_token` de verdade e `pub_aprovacao_ofertas` está vazia em
 * produção. A prova desta fase é teste com `Http::fake` + `preventStrayRequests()`.
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 */
class EnfileirarAoAprovarTest extends TestCase
{
    use RefreshDatabase;

    private const CLASSICO = 'gold_special';

    private const PREMIUM = 'gold_pro';

    private const CATEGORIA = 'MLB189007';

    /** ⚠️ A única leitura de rede autorizada nesta fase — a tarifa real (decisão 9 do usuário). */
    private const LEITURAS_AUTORIZADAS = ['/sites/MLB/listing_prices'];

    private int $sequencia = 0;

    private ?DadosEfetivosService $efetivos = null;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-21 11:30:00');

        // ⚠️ De pé em TODOS os casos: é ele que transforma "o enfileiramento é só banco" de
        // afirmação de docblock em medição.
        Http::preventStrayRequests();

        // ⚠️ A receita MEDIDA do 188-05: sem o app token pré-aquecido, `ComposicaoFinanceiraService`
        // chama `MlColetaService::getAppToken()` e a rota de aprovar devolve **500** — e o caso mediria
        // um erro de fixture em vez do comportamento.
        Cache::put('ml_app_token_coleta', 'fake-app-token', 3600);

        Http::fake(function (Request $req) {
            $caminho = (string) parse_url($req->url(), PHP_URL_PATH);
            parse_str((string) parse_url($req->url(), PHP_URL_QUERY), $query);

            if ($caminho === '/sites/MLB/listing_prices') {
                $tipo = (string) ($query['listing_type_id'] ?? '');
                $arquivo = base_path('tests/fixtures-ml/sondagem/publico/categorias/' . self::CATEGORIA . "/listing_prices_{$tipo}.json");

                return is_file($arquivo)
                    ? Http::response(json_decode((string) file_get_contents($arquivo), true)['resposta'], 200)
                    : Http::response(['message' => 'sem fixture para ' . $tipo], 404);
            }

            return Http::response(['message' => 'sem stub no teste'], 404);
        });

        $this->app->instance(ClienteMlPublicador::class, new ClienteMlPublicador(
            app(\App\Services\MercadoLivreService::class),
            app(\App\Services\MlColetaService::class),
            function (int $s) {}
        ));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ─────────────────────────────────────────────────────────────────────────────────────────
    // Fixture
    // ─────────────────────────────────────────────────────────────────────────────────────────

    /** @return array{0: Company, 1: MlbEmpresa, 2: User} */
    private function conta(): array
    {
        $admin = User::factory()->create(['role' => 'admin', 'name' => 'Rafael Gusmão']);
        $company = Company::factory()->create();

        MlToken::create([
            'company_id' => $company->id,
            'ml_user_id' => (string) random_int(100000000, 999999999),
            'access_token' => 'fake-access-token',
            'refresh_token' => 'fake-refresh-token',
            'token_type' => 'bearer',
            'scope' => 'read write offline_access',
            'expires_at' => now()->addDays(6),
            'last_refreshed_at' => now(),
            'status' => 'active',
            'connected_at' => now(),
        ]);

        $empresa = MlbEmpresa::create([
            'nome' => 'Empresa do portão ' . $company->id,
            'tipo' => 'ASSESSORIA',
            'company_id' => $company->id,
            'responsavel_id' => $admin->id,
        ]);

        // ⚠️ `ContasLiberadas` é FAIL-CLOSED: lista vazia = NINGUÉM. Sem esta linha a prontidão da
        // fila recusa com "A publicação ainda não foi liberada para esta conta" e todo caso de
        // enfileiramento ficaria verde por não ter exercitado nada.
        config(['publicador.contas_liberadas.companies' => [(int) $company->id]]);

        return [$company, $empresa, $admin];
    }

    /**
     * Um produto com **DUAS** ofertas (Clássico e Premium) e a conferência L3 válida.
     *
     * @param  array<string, mixed>  $o
     *                               `premium_ativo`  (bool, default true) — `pub_rascunho_alvos.ativo` do Premium
     *                               `so_classico`    (bool, default false) — nasce com UM alvo só
     *                               `conferida`      (bool, default true) — cria a `PubValidacao` L3
     * @return array<string, mixed>
     */
    private function cenario(Company $company, array $o = []): array
    {
        $this->sequencia++;
        $n = $this->sequencia;

        $produto = PubProduto::create([
            'company_id' => $company->id,
            'sku' => 'PRT-' . $n,
            'nome' => 'Poltrona do portão ' . $n,
            'origem' => PubProduto::ORIGEM_PUBLICADOR,
            'quantidade_kit' => 1,
        ]);
        $rascunho = PubRascunho::create(['produto_id' => $produto->id, 'categoria_id' => self::CATEGORIA]);

        // ⚠️ TÍTULOS DIFERENTES de propósito: título igual nos dois tipos é o bloqueio V-TIT-04 de
        // `bloqueiosSemSchema()`, e ele faria a prontidão recusar por outro motivo — o caso mediria
        // a recusa errada.
        $titulos = [
            self::CLASSICO => "Poltrona Beny decorativa veludo cinza {$n}",
            self::PREMIUM => "Poltrona Beny premium veludo cinza {$n}",
        ];

        $tipos = ($o['so_classico'] ?? false) ? [self::CLASSICO] : [self::CLASSICO, self::PREMIUM];

        $alvos = [];
        $ofertas = [];

        foreach ($tipos as $i => $tipo) {
            $alvos[$tipo] = PubRascunhoAlvo::create([
                'rascunho_id' => $rascunho->id,
                'listing_type_id' => $tipo,
                'titulo' => $titulos[$tipo],
                'posicao' => $i,
                'ativo' => $tipo === self::PREMIUM ? (bool) ($o['premium_ativo'] ?? true) : true,
            ]);
            $ofertas[$tipo] = PubAprovacaoOferta::create([
                'alvo_id' => $alvos[$tipo]->id,
                'rascunho_id' => $rascunho->id,
                'produto_id' => $produto->id,
                'company_id' => $company->id,
                'andamento' => PubAprovacaoOferta::AGUARDANDO_APROVACAO,
                'entrou_na_fila_em' => '2026-10-21 08:00:00',
            ]);
        }

        // ⚠️⚠️ `fresh()` OBRIGATÓRIO, e isto é a armadilha JÁ DOCUMENTADA deste projeto, que eu
        // acabei de pisar: **o Eloquent não lê o default do banco.** `PubRascunho::create()` sem
        // `revisao` devolve um objeto com `revisao` **null** em memória enquanto a linha nasce com
        // `1`. Sem este `fresh()`, a conferência nascia com `revisao = 0` e a prontidão da fila
        // recusava com *"O produto mudou depois da conferência: confira de novo"* — o caso mediria
        // a recusa ERRADA e eu teria "consertado" afrouxando a asserção.
        $rascunho = $rascunho->fresh();

        if ($o['conferida'] ?? true) {
            PubValidacao::create([
                'rascunho_id' => $rascunho->id,
                'revisao' => (int) $rascunho->revisao,
                'camada' => 'L3',
                'resultado' => ConferenciaService::OK,
                'plano_hash' => str_repeat('a', 64),
                'issues' => [],
            ]);
        }

        return [
            'produto' => $produto->fresh(),
            'rascunho' => $rascunho->fresh(),
            'alvos' => $alvos,
            'classico' => $ofertas[self::CLASSICO]->fresh(),
            'premium' => isset($ofertas[self::PREMIUM]) ? $ofertas[self::PREMIUM]->fresh() : null,
        ];
    }

    /**
     * O mesmo cenário, mas **APROVÁVEL pela rota** (zero bloqueios de `BloqueiosDaOfertaService`):
     * galeria com 3 fotos **todas aprovadas** e a conta do dinheiro fechando com custo, frete e a
     * tarifa REAL da fixture.
     *
     * ⚠️ O dublê de `DadosEfetivosService` é **POR PRODUTO**, e isso é conserto de um falso negativo
     * medido na Fase 186: com um conjunto único de custos, o ÚLTIMO cenário montado sobrescrevia os
     * anteriores e a oferta "com bloqueio" era avaliada com a conta da oferta limpa — virava apta, e
     * o gate ficava vermelho estando certo.
     *
     * @param  array<string, mixed>  $o
     * @return array<string, mixed>
     */
    private function cenarioAprovavel(Company $company, array $o = []): array
    {
        $c = $this->cenario($company, $o);

        $conjunto = PubAprovacaoConjunto::create([
            'rascunho_id' => $c['rascunho']->id,
            'grupo_chave' => PubAprovacaoConjunto::GERAL,
            'grupo_hash' => ChaveCanonica::hash(PubAprovacaoConjunto::GERAL . '-' . $c['rascunho']->id),
        ]);

        $primeira = null;
        for ($i = 0; $i < 3; $i++) {
            $crua = PubImagem::create([
                'rascunho_id' => $c['rascunho']->id,
                'caminho' => 'fotos/foto-' . (PubImagem::count() + 1) . '.jpg',
            ]);
            $img = PubAprovacaoImagem::create([
                'conjunto_id' => $conjunto->id,
                'imagem_id' => $crua->id,
                'tipo' => PubAprovacaoImagem::TIPO_ORIGINAL,
                'estado' => PubAprovacaoImagem::APROVADA,
                'posicao' => $i,
            ]);
            PubAprovacaoImagemAlerta::create([
                'aprovacao_imagem_id' => $img->id,
                'severidade' => PubAprovacaoImagemAlerta::OK,
                'texto' => 'Formato e cor compatíveis',
            ]);
            $primeira ??= $img;
        }
        $conjunto->update(['principal_imagem_id' => $primeira?->imagem_id]);

        if ($this->efetivos === null) {
            $this->efetivos = new class(app(EstruturaPrecificacaoService::class)) extends DadosEfetivosService
            {
                /** @var array<int, array<string, mixed>> */
                public array $custosPorProduto = [];

                /** @var array<int, array<string, mixed>> */
                public array $fretesPorProduto = [];

                /** @var array<int, array<string, mixed>> */
                public array $precosPorProduto = [];

                public function custosDoProduto(PubProduto $produto): array
                {
                    return $this->custosPorProduto[(int) $produto->id] ?? ['custo' => null, 'origem' => null, 'imposto' => null, 'por_variante' => []];
                }

                public function fretesDoProduto(PubProduto $produto): array
                {
                    return $this->fretesPorProduto[(int) $produto->id] ?? ['por_tipo' => [], 'por_variante' => []];
                }

                public function daProduto(PubProduto $produto): array
                {
                    return $this->precosPorProduto[(int) $produto->id] ?? ['precos' => []];
                }
            };
            $this->app->instance(DadosEfetivosService::class, $this->efetivos);
        }

        $id = (int) $c['produto']->id;
        $this->efetivos->custosPorProduto[$id] = ['custo' => 20.0, 'origem' => 'digitado', 'imposto' => 10.0, 'por_variante' => []];
        $porTipo = [];
        $precos = [];
        foreach ([self::CLASSICO, self::PREMIUM] as $tipo) {
            $porTipo[$tipo] = ['valor' => 10.0, 'origem' => 'tabela', 'oferta_id' => null];
            $precos[$tipo] = 100.0;
        }
        $this->efetivos->fretesPorProduto[$id] = ['por_tipo' => $porTipo, 'por_variante' => []];
        $this->efetivos->precosPorProduto[$id] = ['precos' => $precos];

        return $c + ['conjunto' => $conjunto->refresh()];
    }

    // ─────────────────────────────────────────────────────────────────────────────────────────
    // Apoio
    // ─────────────────────────────────────────────────────────────────────────────────────────

    private function portao(): PublicacaoDoPortaoService
    {
        return app(PublicacaoDoPortaoService::class);
    }

    /**
     * Aprova pela **ÚNICA PORTA** (este teste nunca escreve a coluna `andamento`) e garante que o
     * enfileiramento foi tentado.
     *
     * ⚠️⚠️ **A CHAMADA EXTRA É DELIBERADA, E A RAZÃO É QUE ESTE ARQUIVO ATRAVESSA AS DUAS TAREFAS
     * DO PLANO.** Na tarefa 1 o gancho `aoAprovar()` ainda está vazio, e esta é a **única** chamada;
     * na tarefa 2, que liga o gancho, ela passa a ser a **segunda** — e aí ela é idempotente de
     * propósito (o produto já tem item vivo, então ela ANEXA ao mesmo item).
     *
     * ⚠️ **É por isso que os casos deste arquivo asseveram ESTADO — quantos itens existem, qual é o
     * `fila_item_id`, o que está no histórico — e não o flag `anexada` de uma chamada cujo número de
     * ordem muda entre as duas tarefas.** Um caso que lesse o flag ficaria verde na tarefa 1 e
     * vermelho na tarefa 2 **sem nenhum defeito ter aparecido**, que é a pior forma de gate: o
     * executor seguinte o "conserta" ajustando a expectativa. O flag é asseverado **só** onde o
     * estado o torna inequívoco (item vivo existindo, ou não existindo, no instante da chamada).
     */
    private function aprovar(PubAprovacaoOferta $oferta, User $quem): PubAprovacaoOferta
    {
        $movida = app(AndamentoDaOfertaService::class)->mover($oferta, PubAprovacaoOferta::APROVADO, $quem);

        // ⚠️ O resultado fica guardado para as mensagens de falha carregarem o MOTIVO REAL da fila
        // em vez de exigir adivinhação — "0 não é 1" sem o motivo manda o leitor caçar.
        $this->ultimoMotivo = (string) ($this->portao()->enfileirar($movida->fresh(), $quem)['motivo'] ?? '(enfileirou)');

        return $movida->fresh();
    }

    /** O último motivo de recusa da fila, para as mensagens de falha. */
    private string $ultimoMotivo = '(nenhuma tentativa)';

    /** @return list<string> */
    private function eventos(PubAprovacaoOferta $oferta): array
    {
        return PubAprovacaoEvento::query()
            ->where('oferta_id', $oferta->id)
            ->orderBy('ocorrido_em')
            ->orderBy('id')
            ->pluck('texto')
            ->all();
    }

    private function itensDoProduto(PubProduto $produto): int
    {
        return PubFilaPublicacaoItem::query()->where('produto_id', $produto->id)->count();
    }

    /** As requisições que NÃO são leitura autorizada: método + URL, para não exigir adivinhação. */
    private function escritasEDesvios(): array
    {
        return Http::recorded()
            ->reject(static fn ($par) => $par[0]->method() === 'GET'
                && in_array((string) parse_url($par[0]->url(), PHP_URL_PATH), self::LEITURAS_AUTORIZADAS, true))
            ->map(static fn ($par) => $par[0]->method() . ' ' . $par[0]->url())
            ->values()
            ->all();
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // (1) AGENDA UMA VEZ, ANEXA NA SEGUNDA — **UM** ITEM DE FILA POR PRODUTO
    // ═════════════════════════════════════════════════════════════════════════════════════════

    /**
     * ⚠️⚠️ **O caso central da granularidade (DADO-03 / PUB-02), e ele percorre o CICLO INTEIRO**,
     * porque com a guarda da decisão 23 as três situações acontecem em sequência na vida real:
     *
     *   1. aprovar o Clássico com o Premium ativo e não aprovado → **ZERO** itens (a guarda);
     *   2. aprovar o Premium → `agendar()` é chamado **UMA** vez → **UM** item;
     *   3. chamar `enfileirar()` no Clássico de novo (é o que o reconciliador da 193-03 fará) →
     *      **ANEXA** ao mesmo item → continua **UM**, e as DUAS ofertas apontam para ele.
     *
     * ⚠️ **Com uma oferta só isso é inobservável** — é por isso que a fixture tem duas.
     * ⚠️ E a prova de que o passo 3 é ANEXO e não agendamento novo é o próprio `anexada => true`
     * **mais** o total de itens do produto continuar 1: se `agendar()` tivesse sido chamado, a
     * prontidão o teria recusado com *"Já está na fila de publicação."* e a ligação se perderia.
     */
    public function test_enfileirar_agenda_uma_vez_e_anexa_na_segunda_oferta_do_produto_193_02(): void
    {
        [$company, , $admin] = $this->conta();
        $c = $this->cenario($company);

        // ── 1. O Clássico sozinho não entra: o Premium está ATIVO e ninguém o revisou ────────
        $classico = $this->aprovar($c['classico'], $admin);

        $this->assertSame(PubAprovacaoOferta::APROVADO, $classico->andamento, 'gate sobre zero casos: a aprovação nem aconteceu');
        $this->assertSame(0, $this->itensDoProduto($c['produto']), 'decisão 23: nasceu item de fila com a modalidade irmã sem revisão');
        $this->assertNull($classico->fila_item_id);

        // ── 2. Com o Premium aprovado, a guarda sai e o produto entra UMA vez ───────────────
        $premium = $this->aprovar($c['premium'], $admin);

        $this->assertSame(1, $this->itensDoProduto($c['produto']), 'a fila publica o PRODUTO: tem de nascer UM item, nunca um por oferta. Motivo da recusa: '.$this->ultimoMotivo);
        $item = PubFilaPublicacaoItem::query()->where('produto_id', $c['produto']->id)->sole();
        $this->assertSame((int) $item->id, (int) $premium->fila_item_id, 'a oferta que agendou não ficou ligada ao item');
        $this->assertSame(PubFilaPublicacaoItem::AGENDADO, $item->status);

        // ⚠️ E UMA fila viva por conta — não nasceu uma fila por oferta.
        $this->assertSame(1, PubFilaPublicacao::query()->count());

        // ── 3. O ANEXO: a oferta que já estava aprovada ganha o MESMO item ───────────────────
        $r = $this->portao()->enfileirar($classico->fresh(), $admin);

        $this->assertTrue($r['enfileirada']);
        $this->assertTrue($r['anexada'], 'a segunda oferta AGENDOU de novo em vez de anexar');
        $this->assertSame((int) $item->id, (int) $r['fila_item_id']);
        $this->assertSame(1, $this->itensDoProduto($c['produto']), 'o anexo duplicou o item do produto');
        $this->assertSame((int) $item->id, (int) $classico->fresh()->fila_item_id);

        // ⚠️ AS DUAS ofertas no MESMO item — o que o docblock de `filaItem()` já declarava.
        $this->assertSame(
            [(int) $item->id, (int) $item->id],
            [(int) $classico->fresh()->fila_item_id, (int) $premium->fresh()->fila_item_id],
            'as duas ofertas do produto têm de apontar para o MESMO item de fila'
        );

        // ⚠️ ISCA INVERTIDA do anexo: sem item VIVO, `enfileirar()` NÃO anexa — ele agenda. O
        // mesmo produto, com o item fora de cena, volta a ser agendável.
        $item->update(['status' => PubFilaPublicacaoItem::CANCELADO, 'produto_ativo' => null, 'concluido_em' => now()]);
        $rNovo = $this->portao()->enfileirar($classico->fresh(), $admin);
        $this->assertTrue($rNovo['enfileirada']);
        $this->assertFalse($rNovo['anexada'], 'ISCA: com o item morto ele continuou "anexando" — a sonda de item vivo não discrimina nada');
        $this->assertNotSame((int) $item->id, (int) $rNovo['fila_item_id']);

        // ⚠️ E NADA saiu para o Mercado Livre: enfileirar é só banco.
        $this->assertSame([], $this->escritasEDesvios(), 'o enfileiramento tocou o Mercado Livre');
        $this->assertSame([], Http::recorded()->all(), 'enfileirar não faz requisição NENHUMA — nem leitura');
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // (2) ITEM QUE TERMINOU SEM PUBLICAR NÃO É VIVO — nas duas direções
    // ═════════════════════════════════════════════════════════════════════════════════════════

    /**
     * ⚠️ **O que precisaria estar errado:** um item já terminado contar como vivo. O produto ficaria
     * preso **fora** da fila para sempre, e a oferta aprovada nunca publicaria — com a aparência de
     * estar ligada à fila.
     *
     * ⚠️ **ISCA NAS DUAS DIREÇÕES, e ela é o caso:** os **4** estados de `SEM_PUBLICAR` têm de
     * deixar o produto agendável, e os **2** de `VIVOS` têm de prendê-lo. Só com um lado o caso
     * passaria com uma sonda que respondesse sempre a mesma coisa.
     */
    public function test_item_terminado_sem_publicar_nao_conta_como_vivo_com_isca_nos_dois_lados_193_02(): void
    {
        [$company, , $admin] = $this->conta();

        // A lista vem das CONSTANTES do modelo, não de literais: estado novo entra sozinho no caso.
        $this->assertSame(['agendado', 'publicando'], PubFilaPublicacaoItem::VIVOS);
        $this->assertSame(['falhou', 'precisa_revisar', 'cancelado', 'pulado'], PubFilaPublicacaoItem::SEM_PUBLICAR);

        // ⚠️ A asserção é sobre ESTADO (quantos itens, qual `fila_item_id`), não sobre o flag
        // `anexada` — ver o docblock de `aprovar()`.
        foreach (PubFilaPublicacaoItem::SEM_PUBLICAR as $terminado) {
            $c = $this->cenario($company);
            $morto = $this->itemManual($c, $terminado, viva: false);

            $oferta = $this->aprovarAsDuas($c, $admin);

            $this->assertSame(2, $this->itensDoProduto($c['produto']), "item `{$terminado}` impediu o reagendamento: o produto ficaria preso fora da fila");
            $this->assertNotNull($oferta->fila_item_id);
            $this->assertNotSame((int) $morto->id, (int) $oferta->fila_item_id, "a oferta foi ligada ao item `{$terminado}`, que já terminou");
            $this->assertSame(PubFilaPublicacaoItem::AGENDADO, $oferta->filaItem->status);
        }

        foreach (PubFilaPublicacaoItem::VIVOS as $vivo) {
            $c = $this->cenario($company);
            $item = $this->itemManual($c, $vivo, viva: true);

            $oferta = $this->aprovarAsDuas($c, $admin);

            // ⚠️ ISCA: se `{$vivo}` NÃO fosse tratado como vivo, nasceria um SEGUNDO item.
            $this->assertSame(1, $this->itensDoProduto($c['produto']), "ISCA: item `{$vivo}` não foi tratado como vivo — o produto foi duplicado na fila");
            $this->assertSame((int) $item->id, (int) $oferta->fila_item_id, "a oferta não foi anexada ao item `{$vivo}` que já existia");
        }

        // ═══ ⚠⚠ O CASO QUE A MUTAÇÃO M1 ME OBRIGOU A ESCREVER ══════════════════════
        //
        // ⚠⚠ **A mutação que alarga a sonda de item vivo para TAMBÉM aceitar os 4 estados terminados
        // SOBREVIVEU** aos dois laços acima — e a causa é medida: naquelas fixtures o item terminado
        // tem `produto_ativo` **NULO**, então quem o descarta é o `where('produto_ativo')` e **não** o
        // filtro de `status`. ➡ **Os dois laços provavam o `produto_ativo`, não o `status`** — para o
        // teste, o filtro de `status` era invisível.
        //
        // ⚠ O estado em que ele É o único discriminador é o **INCONSISTENTE**: item terminado que
        // ainda OCUPA o produto (`produto_ativo` preenchido). Ele é possível — escrita parcial,
        // defeito em outro lugar — e o dano é preciso: sem o filtro de `status` a oferta seria ligada
        // a um item **que nunca vai publicar**, o painel leria as 4 colunas de um item morto e o
        // reconciliador acharia que há publicação em voo. ➡ O invariante de verdade é **a oferta
        // NUNCA ser ligada a item fora de `VIVOS`**, e é isso que se assevera.
        $incoerente = $this->cenario($company);
        $zumbi = $this->itemManual($incoerente, PubFilaPublicacaoItem::FALHOU, viva: true);
        $this->assertSame((int) $incoerente['produto']->id, (int) $zumbi->produto_ativo, 'a fixture do estado incoerente não ficou incoerente: o caso mediria outra coisa');
        $this->assertSame(PubFilaPublicacaoItem::FALHOU, $zumbi->status);

        $daOferta = $this->aprovarAsDuas($incoerente, $admin, enfileirar: false);

        $this->assertNotSame((int) $zumbi->id, (int) $daOferta->fila_item_id, 'a oferta foi ligada a um item FORA de `VIVOS`: ele nunca vai publicar, e o painel leria a conferência de um item morto');

        // ⚠ SEM `if`, de propósito: asserção dentro de ramo faz a CONTAGEM do manifesto depender do
        // comportamento medido, e aí o número muda quando o comportamento muda — a mesma família que
        // já me custou um 26 → 27 nesta wave. `null` (nenhuma ligação) também satisfaz a negativa, e
        // está certo: não ligar é melhor que ligar a item morto.
        $statusLigado = $daOferta->fila_item_id === null ? null : $daOferta->filaItem?->status;
        $this->assertNotContains($statusLigado, PubFilaPublicacaoItem::SEM_PUBLICAR, 'a oferta ficou ligada a item que já terminou sem publicar');

        $this->assertSame([], Http::recorded()->all(), 'enfileirar não faz requisição nenhuma');
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // (3) AS 4 COLUNAS SÃO LIDAS PELO ITEM, E A RECUSA REGISTRA O MOTIVO REAL
    // ═════════════════════════════════════════════════════════════════════════════════════════

    /**
     * ⚠️ **DADO-03:** `validacao_id`, `plano_hash`, `revisao` e `ciente` moram no ITEM e são LIDAS
     * por `fila_item_id`. Copiá-las para a oferta criaria duas verdades livres para divergir
     * (T-193-14).
     *
     * ⚠️ **ISCA INVERTIDA, sem a qual "não foram copiadas" é verde numa varredura cega:** as quatro
     * **existem** na tabela da fila, e é de lá que vêm.
     *
     * ⚠️ E a outra metade do caso: **a recusa da fila não é engolida** (T-193-15). O motivo REAL —
     * uma das 11 frases de `prontidaoDoLote()` — vai ao histórico, a oferta **continua em
     * `aprovado`** e `fila_item_id` **continua nulo**.
     */
    public function test_as_quatro_colunas_vem_do_item_e_a_recusa_registra_o_motivo_real_193_02(): void
    {
        [$company, , $admin] = $this->conta();
        $c = $this->cenario($company);
        $oferta = $this->aprovarAsDuas($c, $admin);
        $item = PubFilaPublicacaoItem::query()->where('produto_id', $c['produto']->id)->sole();

        // ── As 4 colunas: AUSENTES da oferta, PRESENTES no item (a isca invertida) ───────────
        $quatro = ['validacao_id', 'plano_hash', 'revisao', 'ciente'];
        $naOferta = [];
        $noItem = [];
        foreach ($quatro as $coluna) {
            if (\Illuminate\Support\Facades\Schema::hasColumn('pub_aprovacao_ofertas', $coluna)) {
                $naOferta[] = $coluna;
            }
            if (\Illuminate\Support\Facades\Schema::hasColumn('pub_fila_publicacao_itens', $coluna)) {
                $noItem[] = $coluna;
            }
        }
        $this->assertSame([], $naOferta, 'DADO-03: coluna de conferência COPIADA para a oferta — são duas verdades');
        $this->assertSame($quatro, $noItem, 'ISCA INVERTIDA: as 4 não estão na tabela da fila — a varredura está cega e o `assertSame([])` acima não prova nada');

        // ── E são LIDAS pelo item, pela relação `filaItem` ───────────────────────────────────
        $lido = $oferta->fresh()->filaItem;
        $this->assertNotNull($lido, 'a oferta não chegou ao item: as 4 colunas não teriam de onde ser lidas');
        $this->assertSame((int) $item->validacao_id, (int) $lido->validacao_id);
        $this->assertSame((string) $item->plano_hash, (string) $lido->plano_hash);
        $this->assertSame((int) $c['rascunho']->revisao, (int) $lido->revisao, 'a revisão do item não é a do rascunho conferido');

        // ⚠️ `precisa_revisar` NÃO é coluna em lugar nenhum — é VALOR do `status`.
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasColumn('pub_aprovacao_ofertas', 'precisa_revisar'));
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasColumn('pub_fila_publicacao_itens', 'precisa_revisar'));
        $this->assertContains(PubFilaPublicacaoItem::PRECISA_REVISAR, PubFilaPublicacaoItem::SEM_PUBLICAR);

        // ── A RECUSA: produto sem conferência L3 ────────────────────────────────────────────
        $semConferencia = $this->cenario($company, ['conferida' => false]);
        $recusada = $this->aprovarAsDuas($semConferencia, $admin, enfileirar: false);

        $r = $this->portao()->enfileirar($recusada, $admin);

        $this->assertFalse($r['enfileirada']);
        $this->assertNull($r['fila_item_id']);
        $this->assertSame(0, $this->itensDoProduto($semConferencia['produto']));
        // ⚠️ O estado NÃO muda: a oferta fica em `aprovado`, cujo rótulo é "aguardando publicação".
        $this->assertSame(PubAprovacaoOferta::APROVADO, $recusada->fresh()->andamento);
        $this->assertNull($recusada->fresh()->fila_item_id);

        // ⚠️ O MOTIVO REAL, vindo de `prontidaoDoLote()` — nunca "algo deu errado".
        $this->assertSame('Confira no Mercado Livre antes de agendar.', $r['motivo'], 'o motivo não é o REAL da fila');

        // ⚠️ PRESENÇA do registro, por igualdade de STRING (não por contagem: o número de eventos
        // depende de quantas vezes o enfileiramento foi tentado, e isso muda entre as duas tarefas
        // do plano — ver o docblock de `aprovar()`).
        $this->assertContains(
            PublicacaoDoPortaoService::PREFIXO_NAO_ENFILEIRADA . 'Confira no Mercado Livre antes de agendar.',
            $this->eventos($recusada->fresh()),
            'a recusa não foi registrada no histórico — é a pilha de aprovados que ninguém publicou'
        );

        // ⚠️ E o evento é do ator SISTEMA, não da pessoa: quem recusou foi a fila.
        $ultimo = PubAprovacaoEvento::query()->where('oferta_id', $recusada->id)->orderByDesc('id')->first();
        $this->assertTrue((bool) $ultimo->ator_sistema);
        $this->assertNull($ultimo->autor_id);
        $this->assertSame(PubAprovacaoEvento::SISTEMA, $ultimo->tipo, 'a recusa entrou como `error` — o rótulo dele é "Falha na publicação", e nada falhou ao publicar');
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // (4) ⚠️⚠️ A GUARDA DA MODALIDADE IRMÃ — DECISÃO 23 DO USUÁRIO
    // ═════════════════════════════════════════════════════════════════════════════════════════

    /**
     * ⚠️⚠️ **NÃO SE PUBLICA O QUE NINGUÉM REVISOU** (decisão 23, 21/10/2026).
     *
     * **O defeito que este caso fecha, medido em três pontos:** a fila publica o PRODUTO;
     * `PayloadBuilderUserProducts:60` monta um anúncio por **alvo ATIVO**; e
     * `pub_rascunho_alvos.ativo` é boolean **default true**. ➡️ Aprovar só o Clássico e enfileirar
     * o produto levaria o **Premium ao ar sem revisão**.
     *
     * ⚠️ **ISCA NAS DUAS DIREÇÕES, e é o que faz o caso valer:** com um só aprovado **não nasce**
     * item; com os dois aprovados **nasce**. Só com a primeira metade, um `enfileirar()` que
     * recusasse sempre passaria.
     */
    public function test_modalidade_irma_ativa_nao_aprovada_barra_a_entrada_na_fila_193_02(): void
    {
        [$company, , $admin] = $this->conta();
        $c = $this->cenario($company);

        // ── Um só aprovado → ZERO itens ─────────────────────────────────────────────────────
        $classico = $this->aprovar($c['classico'], $admin);

        $this->assertSame(0, $this->itensDoProduto($c['produto']), 'decisão 23: o produto entrou na fila com o Premium sem revisão');
        $this->assertNull($classico->fila_item_id);

        // ⚠️ O predicado, lido direto: ele nomeia a modalidade que falta, em pt-BR, e **não** o
        // `listing_type_id` cru (jargão não vai para a tela).
        $pendentes = $this->portao()->modalidadesAtivasNaoAprovadas($classico);
        $this->assertCount(1, $pendentes);
        $this->assertSame(self::PREMIUM, $pendentes[0]['listing_type_id']);
        $this->assertSame('Premium', $pendentes[0]['modalidade']);
        $this->assertSame((int) $c['premium']->id, (int) $pendentes[0]['oferta_id']);

        // ⚠️ O MOTIVO REAL no histórico NOMEIA a modalidade que falta.
        // ⚠️ Por IGUALDADE DE STRING contra a frase montada do próprio serviço, e por
        // PRESENÇA na lista — nunca `end()`, porque a ordem e o número de eventos dependem de
        // quantas vezes o enfileiramento foi tentado (ver o docblock de `aprovar()`).
        $esperado = PublicacaoDoPortaoService::MOTIVO_IRMA_NAO_APROVADA.'Premium (Aguardando aprovação).';
        $this->assertContains(
            PublicacaoDoPortaoService::PREFIXO_NAO_ENFILEIRADA.$esperado,
            $this->eventos($classico),
            'o histórico não registra a recusa da decisão 23 nomeando a modalidade que falta'
        );
        // ⚠️ E o JARGÃO NÃO vai para a tela: `gold_pro` em nenhum texto de evento.
        //
        // ⚠⚠ **ASSERÇÃO COLETORA, E ISSO É CONSERTO DE UM FURO QUE EU MEDI NA MINHA PRÓPRIA
        // SONDA, não zelo.** A primeira redação fazia UMA asserção POR EVENTO, dentro de um
        // `foreach` sobre a lista de eventos — e o número de eventos depende de **quantas vezes o
        // enfileiramento foi tentado**, que muda entre a tarefa 1 e a tarefa 2 deste plano (o
        // gancho passa a tentar antes da chamada explícita do teste). **MEDIDO: a contagem do
        // manifesto foi de 26 para 27 asserções sem nenhum defeito ter aparecido.** É a família
        // *"âncora em número que muda legitimamente"*, e o conserto errado seria **elevar o
        // número** — o hábito que esta milestone já pagou dezesseis vezes. ➡ Coletar e asseverar
        // **uma vez** prova exatamente o mesmo e não depende de quantos eventos existem.
        $comJargao = [];
        foreach ($this->eventos($classico) as $texto) {
            if (str_contains($texto, self::PREMIUM)) {
                $comJargao[] = $texto;
            }
        }
        $this->assertSame([], $comJargao, 'o jargão `gold_pro` foi para o histórico, que é texto de tela');

        // ── ISCA (+): com os DOIS aprovados, nasce UM item ──────────────────────────────────
        $premium = $this->aprovar($c['premium'], $admin);

        $this->assertSame([], $this->portao()->modalidadesAtivasNaoAprovadas($premium->fresh()));
        $this->assertSame(1, $this->itensDoProduto($c['produto']), 'ISCA: com as duas modalidades aprovadas o produto NÃO entrou — a guarda recusa sempre e não discrimina nada');
        $this->assertNotNull($premium->fresh()->fila_item_id);

        $this->assertSame([], Http::recorded()->all(), 'a guarda tocou a rede');
    }

    /**
     * ⚠️⚠️ **A GUARDA NÃO PODE QUEBRAR O CAMINHO QUE EXISTE HOJE.** Ancorá-la numa contagem fixa de
     * alvos (*"tem de haver dois aprovados"*) prenderia para sempre o produto de **UM** alvo ativo —
     * que é **exatamente** o único que o agente encaminha (`ElegibilidadeDoAgente`). ➡️ A âncora é
     * `pub_rascunho_alvos.ativo`, e as duas pontas estão medidas aqui:
     *
     *   · produto com **UM** alvo só → enfileira normalmente;
     *   · produto com Premium **INATIVO** → o Clássico sozinho enfileira, porque alvo inativo **não
     *     vai ao ar** (`alvosAtivos()` o filtra, e é essa a lista que o payload percorre).
     */
    public function test_um_alvo_ativo_so_e_alvo_inativo_nao_barram_o_enfileiramento_193_02(): void
    {
        [$company, , $admin] = $this->conta();

        // ── UM alvo só ──────────────────────────────────────────────────────────────────────
        $umSo = $this->cenario($company, ['so_classico' => true]);
        $this->assertNull($umSo['premium']);
        $this->assertSame([], $this->portao()->modalidadesAtivasNaoAprovadas($umSo['classico']));

        $soClassico = $this->aprovar($umSo['classico'], $admin);
        $this->assertSame(1, $this->itensDoProduto($umSo['produto']), 'a guarda prendeu produto de UM alvo — o caminho do agente quebrou');
        $this->assertNotNull($soClassico->fila_item_id);

        // ── Premium existente, mas INATIVO ──────────────────────────────────────────────────
        $inativo = $this->cenario($company, ['premium_ativo' => false]);
        $this->assertFalse((bool) $inativo['alvos'][self::PREMIUM]->ativo);
        $this->assertSame(PubAprovacaoOferta::AGUARDANDO_APROVACAO, $inativo['premium']->andamento, 'gate sobre zero casos: a irmã já estaria aprovada');
        $this->assertSame([], $this->portao()->modalidadesAtivasNaoAprovadas($inativo['classico']));

        $comIrmaInativa = $this->aprovar($inativo['classico'], $admin);
        $this->assertSame(1, $this->itensDoProduto($inativo['produto']), 'alvo INATIVO barrou o enfileiramento — ele não vai ao ar, logo não há o que revisar');
        $this->assertNotNull($comIrmaInativa->fila_item_id);

        // ── ISCA (−): ATIVANDO o Premium, o MESMO cenário passa a barrar ────────────────────
        $outro = $this->cenario($company, ['premium_ativo' => false]);
        $outro['alvos'][self::PREMIUM]->update(['ativo' => true]);
        $this->assertCount(1, $this->portao()->modalidadesAtivasNaoAprovadas($outro['classico']->fresh()), 'ISCA: ativar o alvo não mudou o veredicto — a guarda não lê `ativo`');

        $barrado = $this->aprovar($outro['classico']->fresh(), $admin);
        $this->assertSame(0, $this->itensDoProduto($outro['produto']));
        $this->assertNull($barrado->fila_item_id);

        $this->assertSame([], Http::recorded()->all());
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // (6) O GANCHO LIGADO: ATÔMICO, DENTRO DA TRANSAÇÃO, E DEPOIS DOS TRÊS CAMPOS
    // ═════════════════════════════════════════════════════════════════════════════════════════

    /**
     * ⚠️⚠️ **O que precisaria estar errado:** o enfileiramento acontecer **FORA** da transação que
     * gravou `aprovado_por`/`aprovado_em`/`versao_aprovada`. Aprovar publica e **não há desfazer** —
     * uma publicação enfileirada sem a prova de quem aprovou e em que versão é repúdio puro
     * (T-193-12).
     *
     * ⚠️ **PROVADO POR OBSERVAÇÃO DE DENTRO, não por leitura de código:** um dublê do serviço do
     * portão registra, **no instante em que é chamado**, o `DB::transactionLevel()` e o que já
     * estava gravado na oferta. Ler o diff e concluir "está dentro" seria afirmação; isto é medição.
     */
    public function test_o_gancho_enfileira_dentro_da_transacao_e_depois_dos_tres_campos_193_02(): void
    {
        [$company, , $admin] = $this->conta();
        $c = $this->cenario($company, ['so_classico' => true]);

        $espiao = new class(app(FilaPublicacaoService::class), app(ProgramasPublicadorService::class), app(HistoricoDaOfertaService::class)) extends PublicacaoDoPortaoService
        {
            public int $chamadas = 0;

            public int $nivelDeTransacao = -1;

            /** @var array<string, mixed> */
            public array $jaGravado = [];

            public function enfileirar(PubAprovacaoOferta $oferta, ?User $quem = null): array
            {
                $this->chamadas++;
                $this->nivelDeTransacao = \Illuminate\Support\Facades\DB::transactionLevel();
                // ⚠️ RELIDO DO BANCO, não do objeto em memória: a pergunta é se o COMMIT ainda não
                // aconteceu e os campos já estão na linha — ler do objeto não distinguiria nada.
                $this->jaGravado = (array) \Illuminate\Support\Facades\DB::table('pub_aprovacao_ofertas')
                    ->where('id', $oferta->id)
                    ->first(['andamento', 'aprovado_por', 'aprovado_em', 'versao_aprovada', 'fila_item_id']);

                return parent::enfileirar($oferta, $quem);
            }
        };
        $this->app->instance(PublicacaoDoPortaoService::class, $espiao);

        // ⚠⚠ O NÍVEL DE REFERÊNCIA, MEDIDO FORA DE `mover()` — CONSERTO DE UM FURO QUE A CAMPANHA DE
        // MUTAÇÃO ACHOU NA MINHA PRÓPRIA SONDA, não zelo. A primeira redação asseverava
        // `assertGreaterThan(0, $nivel)`, e a mutação que troca a `DB::transaction` de `mover()` por
        // uma função anónima executada na hora **SOBREVIVEU**: o `RefreshDatabase` abre uma transação
        // própria em volta de **cada caso**, então `transactionLevel()` já vem **1** sem `mover()` abrir
        // nada — a asserção era **vazia por construção**, satisfeita pelo próprio arcabouço de teste.
        // ➡ O que discrimina é **estritamente maior que a referência**.
        $referencia = \Illuminate\Support\Facades\DB::transactionLevel();

        $movida = app(AndamentoDaOfertaService::class)->mover($c['classico'], PubAprovacaoOferta::APROVADO, $admin);

        // ⚠️ UM CAMINHO SÓ (PUB-09): exatamente UMA chamada ao enfileiramento por aprovação.
        $this->assertSame(1, $espiao->chamadas, 'o gancho chamou o enfileiramento mais de uma vez — ou nenhuma');

        // ⚠⚠ DENTRO da transação QUE `mover()` ABRIU: nível **estritamente maior** que o de fora.
        $this->assertGreaterThan(
            $referencia,
            $espiao->nivelDeTransacao,
            "o gancho roda FORA da transação que `mover()` abre (nível {$espiao->nivelDeTransacao} contra referência {$referencia}): enfileirar sobreviveria a um rollback da aprovação"
        );

        // ⚠️ E DEPOIS dos três campos: a prova do que foi aprovado já estava na linha.
        $this->assertSame(PubAprovacaoOferta::APROVADO, $espiao->jaGravado['andamento']);
        $this->assertSame((int) $admin->id, (int) $espiao->jaGravado['aprovado_por'], 'enfileirou ANTES de gravar quem aprovou');
        $this->assertNotNull($espiao->jaGravado['aprovado_em']);
        $this->assertSame(1, (int) $espiao->jaGravado['versao_aprovada']);
        $this->assertNull($espiao->jaGravado['fila_item_id'], 'a ligação já existia antes de enfileirar — o caso não mede nada');

        // E o resultado final: os três campos E a ligação, tudo de pé.
        $this->assertNotNull($movida->fresh()->fila_item_id);
        $this->assertSame((int) $admin->id, (int) $movida->fresh()->aprovado_por);
    }

    /**
     * ⚠️⚠️ **A FALHA DO GANCHO NÃO DERRUBA A APROVAÇÃO — e o custo está declarado.** Falha de
     * INFRAESTRUTURA no caminho da fila deixa a oferta em `aprovado` (*"aguardando publicação"*,
     * que é a verdade) para o reconciliador tentar de novo. ⚠️ **A aprovação NÃO fica
     * meio-gravada:** os três campos **e** o evento do histórico estão todos lá.
     *
     * ⚠️ E a outra ponta, que é o que impede o `catch` de virar silêncio: **nenhum job é
     * despachado** durante a aprovação (T-193-16) — job despachado antes do commit rodaria sem ver
     * os dados.
     */
    public function test_falha_do_gancho_nao_derruba_a_aprovacao_e_nenhum_job_e_despachado_193_02(): void
    {
        [$company, , $admin] = $this->conta();
        $c = $this->cenario($company, ['so_classico' => true]);

        Queue::fake();

        $explode = new class(app(FilaPublicacaoService::class), app(ProgramasPublicadorService::class), app(HistoricoDaOfertaService::class)) extends PublicacaoDoPortaoService
        {
            public function enfileirar(PubAprovacaoOferta $oferta, ?User $quem = null): array
            {
                throw new \RuntimeException('banco indisponível no caminho da fila');
            }
        };
        $this->app->instance(PublicacaoDoPortaoService::class, $explode);

        $movida = app(AndamentoDaOfertaService::class)->mover($c['classico'], PubAprovacaoOferta::APROVADO, $admin);

        // ⚠️ A aprovação FICOU, inteira.
        $this->assertSame(PubAprovacaoOferta::APROVADO, $movida->fresh()->andamento);
        $this->assertSame((int) $admin->id, (int) $movida->fresh()->aprovado_por, 'a aprovação ficou meio-gravada: sem quem aprovou');
        $this->assertNotNull($movida->fresh()->aprovado_em);
        $this->assertSame(1, (int) $movida->fresh()->versao_aprovada, 'a aprovação ficou meio-gravada: sem a versão');
        $this->assertContains('Aprovou para publicação (versão 1)', $this->eventos($movida), 'a aprovação ficou sem prova no histórico');

        // ⚠️ E o que falta é SÓ a ligação da fila — o reconciliador tenta de novo.
        $this->assertNull($movida->fresh()->fila_item_id);
        $this->assertSame(0, $this->itensDoProduto($c['produto']));

        // ⚠️ ZERO job despachado, e a ISCA prova que o espião não está cego.
        Queue::assertNothingPushed();

        // ⚠️ ISCA: o espão da fila NÃO está cego — um job despachado agora É visto por ele. Sem
        // ela, `assertNothingPushed()` seria indistinguível de "o `Queue::fake()` não registra nada".
        Queue::push('IscaDeJobDoGate19302');
        Queue::assertPushed('IscaDeJobDoGate19302');

        $this->assertSame([], Http::recorded()->all(), 'a aprovação tocou a rede');
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // (7) PELA ROTA: APROVAR ENFILEIRA, EM LOTE TAMBÉM, E ZERO ESCRITA NO MERCADO LIVRE
    // ═════════════════════════════════════════════════════════════════════════════════════════

    /**
     * ⚠️⚠️ **O caminho COMPLETO, pela rota, com o gancho ligado** — é o único jeito de provar que a
     * ligação não depende de o teste chamar o serviço na mão.
     *
     * ⚠️ **ZERO ESCRITA ao Mercado Livre, não zero requisição**, e isso é medido: `aprovar()` LÊ a
     * tarifa real no GET `/sites/MLB/listing_prices` (decisão 9 do usuário). A leitura permitida
     * está em **lista CONSTANTE nomeada**, e há **isca nas duas pontas** — um POST é pego, e um GET
     * **fora** da lista também.
     *
     * ⚠️ E o lote (BLOQ-10): a apta aprova **e enfileira**; a inapta sai com o **motivo** e **não**
     * enfileira. Com uma só no lote isso seria inobservável.
     */
    public function test_aprovar_pela_rota_e_em_lote_enfileira_sem_escrever_no_mercado_livre_193_02(): void
    {
        [$company, , $admin] = $this->conta();
        $c = $this->cenarioAprovavel($company, ['so_classico' => true]);

        $resposta = $this->actingAs($admin)->post(route('mlb.anuncios.meus.aprovacao.painel.aprovar', [
            'company' => $company->id,
            'oferta' => $c['classico']->id,
        ]));
        $resposta->assertRedirect();

        $aprovada = $c['classico']->fresh();
        $this->assertSame(PubAprovacaoOferta::APROVADO, $aprovada->andamento, 'gate sobre zero casos: a aprovação pela rota nem aconteceu');
        $this->assertNotNull($aprovada->fila_item_id, 'aprovar pela rota NÃO enfileirou — o gancho não está ligado no caminho real');
        $this->assertSame(1, $this->itensDoProduto($c['produto']));
        // ⚠️ E nenhum `MLB…` gravado: quem confirma é o ML, depois (AND-04).
        $this->assertNull($aprovada->ml_item_id);

        // ── O LOTE: uma apta e uma INAPTA (sem conferência L3 → a fila recusa) ──────────────
        $apta = $this->cenarioAprovavel($company, ['so_classico' => true]);
        $inapta = $this->cenarioAprovavel($company, ['so_classico' => true, 'conferida' => false]);

        $this->actingAs($admin)->post(route('mlb.anuncios.meus.aprovacao.lote', ['company' => $company->id]), [
            'ofertas' => [$apta['classico']->id, $inapta['classico']->id],
        ])->assertRedirect();

        $this->assertSame(PubAprovacaoOferta::APROVADO, $apta['classico']->fresh()->andamento);
        $this->assertNotNull($apta['classico']->fresh()->fila_item_id, 'a apta do lote não enfileirou');
        $this->assertSame(1, $this->itensDoProduto($apta['produto']));

        // ⚠️ A outra foi APROVADA (ela não tem bloqueio do portão) mas a FILA a recusou — e é esse
        // o mecanismo da decisão 21: fica em `aprovado`, com o motivo real no histórico.
        $this->assertSame(PubAprovacaoOferta::APROVADO, $inapta['classico']->fresh()->andamento);
        $this->assertNull($inapta['classico']->fresh()->fila_item_id);
        $this->assertSame(0, $this->itensDoProduto($inapta['produto']));
        $this->assertContains(
            PublicacaoDoPortaoService::PREFIXO_NAO_ENFILEIRADA . 'Confira no Mercado Livre antes de agendar.',
            $this->eventos($inapta['classico']->fresh()),
            'a recusa da fila no LOTE foi engolida em silêncio'
        );

        // ── ⚠️⚠️ ZERO ESCRITA, com a leitura autorizada nomeada ────────────────────────────
        $this->assertSame([], $this->escritasEDesvios(), 'saiu escrita ao Mercado Livre da aprovação — enfileirar é só banco');

        // ⚠️ E a prova de que a rede FOI tocada em LEITURA (senão o `assertSame([])` acima é
        // verdadeiro por o fake nem ter sido consultado — a outra face do gate vazio).
        $lidas = Http::recorded()
            ->map(static fn ($par) => (string) parse_url($par[0]->url(), PHP_URL_PATH))
            ->unique()
            ->values()
            ->all();
        $this->assertSame(self::LEITURAS_AUTORIZADAS, $lidas, 'apareceu endpoint do ML fora da lista autorizada, ou a tarifa nem foi consultada');

        // ⚠️ ISCA 1 — a sonda não está cega: um POST ao ML é PEGO.
        Http::post('https://api.mercadolibre.com/items', ['title' => 'x']);
        $this->assertSame(['POST https://api.mercadolibre.com/items'], $this->escritasEDesvios(), 'ISCA 1: a sonda não pega um POST ao ML');

        // ⚠️ ISCA 2 — e um GET FORA da lista constante também: a autorização é por caminho nomeado.
        Http::get('https://api.mercadolibre.com/users/me');
        $this->assertContains('GET https://api.mercadolibre.com/users/me', $this->escritasEDesvios(), 'ISCA 2: GET fora da lista autorizada passou');
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // (8) ⚠️⚠️ NÃO EXISTE SEGUNDO PASSO, E O GANCHO TEM **UM CAMINHO SÓ** (PUB-09)
    // ═════════════════════════════════════════════════════════════════════════════════════════

    /**
     * ⚠️⚠️ **DECISÃO 1 DO USUÁRIO: APROVAR JÁ PUBLICA, SEM SEGUNDO CLIQUE**, com o rótulo
     * *"Aprovar para publicação"* inalterado. As alternativas — publicar em dois atos, publicar com
     * janela para voltar atrás, botão separado de publicar — foram **oferecidas e RECUSADAS**.
     *
     * ⚠️⚠️ **O GATE LÊ O CÓDIGO SEM COMENTÁRIO** (`token_get_all`, nunca regex sobre o texto cru), e
     * isso **não** é zelo: os docblocks dos DOIS arquivos deste plano citam as nove formas
     * proibidas **de propósito**, para explicar que não existem. Um gate que lesse os comentários
     * **reprovaria pelo próprio conserto** — foi o que abortou TRÊS mutações da wave 187-04. ⚠️ E a
     * **isca do leitor** está medida nas duas pontas: no texto CRU a sonda acha; no código limpo,
     * zero.
     *
     * ⚠️ **A SONDA É A UNIÃO DAS DUAS FORMAS QUE JÁ EXISTEM, e isso foi MEDIDO:** a `sondaPub09` do
     * gate 186-09 **não** casa a forma pt-BR acentuada com espaço (*"confirmar publicação"*), e a
     * sonda do 188-05 **não** casa `undo` nem `publicar_aprovado`. A união é estritamente mais forte
     * que qualquer uma das duas.
     *
     * ⚠️ **BORDA À ESQUERDA SÓ** em `desfazer`/`undo`, e é medição, não gosto: `undo` é substring de
     * *"segundo"*, *"fundo"* e *"profundo"* (três arquivos corretos já foram acusados por isso), e
     * borda nos DOIS lados cega a sonda para `desfazerAprovacao`, que é a forma que o defeito teria.
     *
     * ⚠️ **O recorte é a LISTA CONSTANTE dos arquivos deste plano**, nunca o projeto inteiro:
     * "cancelar" e a forma de voltar atrás são palavras comuns do português, e varrer `app/` é a
     * família de gate que já **barrou um deploy** nesta milestone.
     */
    public function test_nao_existe_segundo_passo_e_o_gancho_tem_um_caminho_so_193_02(): void
    {
        // ⚠️ LISTA CONSTANTE — os arquivos de produção que ESTE plano escreve.
        $arquivos = [
            'app/Services/Publicador/Aprovacao/PublicacaoDoPortaoService.php',
            'app/Services/Publicador/Aprovacao/AndamentoDaOfertaService.php',
        ];

        // ⚠️ ANTI-FANTASMA: `test -e` em CADA caminho ANTES de medir. Caminho inexistente faria o
        // laço rodar sobre nada e o gate ficaria verde sem ler uma linha — são DEZOITO fantasmas
        // nesta milestone, vários vindos de briefing.
        foreach ($arquivos as $rel) {
            $this->assertFileExists(base_path($rel), "caminho fantasma no gate de ausência: {$rel}");
        }

        $sonda = '/(confirmar[\s_\-]*publica(?:ção|cao|tion)|confirmarPublicacao'
            . '|segundo[\s_\-]*passo|segundoPasso|second[\s_\-]*step'
            . '|(?<![\p{L}\p{N}])desfazer|(?<![\p{L}\p{N}])undo'
            . '|publicar[\s_\-]*aprovado|publicarAprovado'
            . '|cancelar[\s_\-]*(?:a[\s_\-]*)?aprova(?:ção|cao)|cancelarAprovacao)/ui';

        // ⚠️⚠️ ISCA (+) — a sonda TEM de pegar cada uma das nove formas, e ela vem ANTES da
        // varredura, porque sonda furada torna o `assertSame([], …)` vazio por construção.
        $devemCasar = [
            'confirmar publicação (prosa acentuada)' => "'rotulo' => 'Confirmar publicação'",
            'confirmarPublicacao (camelCase)' => 'public function confirmarPublicacao() {}',
            'segundo_passo (snake_case)' => "const SEGUNDO_PASSO = 'x';",
            'segundoPasso (camelCase)' => 'private bool $segundoPasso = true;',
            'a forma de voltar atrás (camelCase)' => 'function desfazerAprovacao() {}',
            'undo com _ em volta' => 'const JANELA_DE_UNDO = 5;',
            'publicar_aprovado' => "Route::post('/publicar_aprovado');",
            'publicarAprovado' => 'public function publicarAprovado() {}',
            'cancelar aprovação (prosa acentuada)' => "'rotulo' => 'Cancelar aprovação'",
            'cancelarAprovacao (camelCase)' => 'public function cancelarAprovacao() {}',
        ];
        foreach ($devemCasar as $comoSeChama => $texto) {
            $this->assertSame(1, preg_match($sonda, $texto), "ISCA (+): a sonda não casa `{$comoSeChama}`, que é a forma que ela existe para pegar");
        }

        // ⚠️⚠️ ISCA (−) — e o que ela NÃO pode pegar: o rótulo LEGÍTIMO (inalterado por decisão) e
        // as palavras pt-BR comuns que contêm `undo`.
        foreach (['Aprovar para publicação', 'o segundo passo da conta', 'o fundo do card', 'de forma mais profunda'] as $legitimo) {
            $naoPode = $legitimo === 'o segundo passo da conta' ? '/(?<![\p{L}\p{N}])undo/ui' : $sonda;
            $this->assertSame(0, preg_match($naoPode, $legitimo), "ISCA (−): a sonda reprovaria `{$legitimo}`, que é legítimo");
        }

        // ─── A VARREDURA, no código SEM COMENTÁRIO ──────────────────────────────────────────
        $infratores = [];
        $iscaDoLeitor = 0;
        $linhasLidas = 0;
        foreach ($arquivos as $rel) {
            $cru = (string) file_get_contents(base_path($rel));
            $limpo = self::semComentarios($cru);
            $linhasLidas += substr_count($limpo, "\n");

            // ⚠️ CONTAGEM DE OCORRÊNCIAS, nunca `preg_match(...) === 1`: ele PASSA com a linha de
            // código apagada quando o texto também existe em comentário.
            if (preg_match_all($sonda, $limpo) > 0) {
                $infratores[] = $rel;
            }
            $iscaDoLeitor += preg_match_all($sonda, $cru);
        }
        $this->assertGreaterThan(400, $linhasLidas, 'gate sobre quase nada: os dois leitores não leram código');
        $this->assertSame([], $infratores, 'PUB-09 / decisão 1: o segundo passo da aprovação reapareceu em código');

        // ⚠️⚠️ A PROVA DE QUE O GATE LÊ SEM COMENTÁRIO: no texto CRU a sonda acha (a prosa cita as
        // nove formas de propósito), e no limpo ela não acha nada. Sem esta ponta, "zero
        // infratores" seria indistinguível de "a sonda não lê nada".
        $this->assertGreaterThan(0, $iscaDoLeitor, 'a prosa dos dois arquivos deixou de citar as formas proibidas — a isca do leitor perdeu o sujeito e o gate não prova mais que lê sem comentário');

        // ─── ⚠️⚠️ UM CAMINHO SÓ (PUB-09): nunca os dois ao mesmo tempo ─────────────────────
        $gancho = self::semComentarios((string) file_get_contents(
            base_path('app/Services/Publicador/Aprovacao/AndamentoDaOfertaService.php')
        ));
        $this->assertSame(1, substr_count($gancho, '->enfileirar('), 'a única porta chama o enfileiramento mais de uma vez (ou nenhuma) — "nunca os dois caminhos ao mesmo tempo"');
        $this->assertTrue(method_exists(AndamentoDaOfertaService::class, 'aoAprovar'), 'o ponto de extensão da Fase 193 desapareceu — a asserção viva da 186-04 morreria');

        // ⚠️ E o gancho segue sendo chamado de UM lugar só dentro de `mover()`.
        $this->assertSame(1, substr_count($gancho, '$this->aoAprovar('));

        // ⚠️⚠️ NENHUMA ROTA NOVA, por IGUALDADE DE CONJUNTO (piso `>= N` NÃO prova presença): são
        // as 16 rotas de `aprovacao` das Fases 187/188, e **mais nenhuma**. Esta fase não cria rota.
        $esperadas = [
            'mlb.anuncios.meus.aprovacao',
            'mlb.anuncios.meus.aprovacao.lote',
            'mlb.anuncios.meus.aprovacao.painel.ajuste',
            'mlb.anuncios.meus.aprovacao.painel.ajuste.resolver',
            'mlb.anuncios.meus.aprovacao.painel.aprovar',
            'mlb.anuncios.meus.aprovacao.painel.conferir-alteracao',
            'mlb.anuncios.meus.aprovacao.painel.excecao',
            'mlb.anuncios.meus.aprovacao.painel.fotos.sem-alerta',
            'mlb.anuncios.meus.aprovacao.painel.frete',
            'mlb.anuncios.meus.aprovacao.painel.frete.previa',
            'mlb.anuncios.meus.aprovacao.painel.imagem.aprovar',
            'mlb.anuncios.meus.aprovacao.painel.imagem.reprovar',
            'mlb.anuncios.meus.aprovacao.painel.preco',
            'mlb.anuncios.meus.aprovacao.painel.preco.simular',
            'mlb.anuncios.meus.aprovacao.painel.salvar-revisao',
            'mlb.anuncios.meus.aprovacao.painel.tentar-de-novo',
        ];
        $medidas = [];
        foreach (\Illuminate\Support\Facades\Route::getRoutes() as $rota) {
            $nome = (string) $rota->getName();
            if ($nome !== '' && str_starts_with($nome, 'mlb.anuncios.meus.aprovacao')) {
                $medidas[] = $nome;
            }
        }
        $medidas = array_values(array_unique($medidas));
        sort($medidas);
        $this->assertSame($esperadas, $medidas, 'PUB-09/PUB-10: nasceu (ou morreu) rota de aprovação nesta fase — o caminho tem de ser UM só');
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // (9) A BIJEÇÃO ids ↔ MÉTODOS ↔ `<verify>`, COM EXPANSOR DE ALTERNÂNCIA
    // ═════════════════════════════════════════════════════════════════════════════════════════

    /**
     * ⚠️⚠️ **MÉTODO SEM id NUNCA RODA** (foi o sobrevivente da mutação do plano 186-03), e **id
     * órfão não falha: ele simplesmente nunca roda**. As duas formas de "passar por omissão" são
     * conferidas aqui, por script durável e não por rascunho de scratchpad.
     *
     * ⚠️⚠️ **O EXPANSOR DE ALTERNÂNCIA, ISCADO NAS DUAS DIREÇÕES ANTES DE VALER:** a regex ingênua
     * `/test_[A-Za-z0-9_]+/` casa **ZERO** em `test_(a|b|c)` — o furo que deu `SEM_ID=14`
     * **acusando trabalho correto**.
     *
     * ⚠️ E o **homônimo**: o `--filter` do PHPUnit é regex **NÃO ANCORADO**, e as Fases 186/187/188
     * estão **em produção**. Método de outra fase que seja superstring de um meu faria o id SOMAR e
     * reprovar.
     */
    public function test_o_manifesto_tem_bijecao_com_os_metodos_e_nenhum_id_orfao_193_02(): void
    {
        $manifesto = base_path('tests/gates/contagens-193-02.txt');
        $meuArquivo = base_path('tests/Feature/Publicador/Aprovacao/EnfileirarAoAprovarTest.php');
        $this->assertFileExists($manifesto);
        $this->assertFileExists($meuArquivo);

        // ─── Os ids e os filtros do manifesto ───────────────────────────────────────────────
        $ids = [];
        foreach (preg_split('/\R/', (string) file_get_contents($manifesto)) ?: [] as $linha) {
            if (trim($linha) === '' || str_starts_with(ltrim($linha), '#')) {
                continue;
            }
            if (preg_match('/^(\S+)\s+php\s+(\S+)\s+::\s+tests=(\d+)\s+assertions=(\d+)/', $linha, $m) === 1) {
                $ids[$m[1]] = ['filtro' => $m[2], 'tests' => (int) $m[3], 'assertions' => (int) $m[4]];
            }
        }
        $this->assertNotEmpty($ids, 'o manifesto não tem nenhum id php — a varredura está cega');
        $this->assertSame(count($ids), count(array_unique(array_keys($ids))), 'IDS_REPETIDOS: id duplicado no manifesto');

        // ─── ⚠️ O EXPANSOR DE ALTERNÂNCIA, ISCADO ANTES DE VALER ───────────────────────────
        $this->assertSame(['test_a', 'test_b', 'test_c'], self::expandir('test_(a|b|c)'), 'ISCA (+): o expansor não abre a alternância — a bijeção acusaria trabalho correto');
        $this->assertSame(['test_sozinho'], self::expandir('test_sozinho'), 'ISCA (−): o expansor quebra o filtro sem alternância');
        // ⚠⚠ E A PROVA DE QUE O FURO QUE O EXPANSOR CONSERTA É REAL, MEDIDA nas duas pontas em
        // 21/10/2026: a regex INGÊNUA casa **ZERO** num filtro com alternância e casa normalmente
        // num filtro sem ela. Sem estas duas linhas, o expansor seria zelo sem sujeito.
        preg_match_all('/test_[A-Za-z0-9_]+/', 'test_(a|b|c)', $cega);
        $this->assertSame([], $cega[0], 'a regex ingênua deixou de ser cega para a alternância — o furo que o expansor conserta desapareceu, e o expansor virou zelo sem sujeito');
        preg_match_all('/test_[A-Za-z0-9_]+/', 'test_abc', $enxerga);
        $this->assertSame(['test_abc'], $enxerga[0], 'a regex ingênua não casa nem o caso simples — a comparação acima não prova nada');

        // ─── Os métodos do meu arquivo, lidos SEM COMENTÁRIO ───────────────────────────────
        $limpo = self::semComentarios((string) file_get_contents($meuArquivo));
        preg_match_all('/function\s+(test_[A-Za-z0-9_]+)\s*\(/', $limpo, $m);
        $metodos = $m[1];
        sort($metodos);
        $this->assertGreaterThanOrEqual(5, count($metodos), 'gate sobre quase nada: os métodos não foram lidos');

        // ⚠️ SUFIXO `_193_02` em TODOS, por asserção COLETORA (nunca `grep -c`, que conta LINHAS).
        $semSufixo = array_values(array_filter($metodos, static fn (string $x) => ! str_ends_with($x, '_193_02')));
        $this->assertSame([], $semSufixo, 'método sem o sufixo `_193_02`: o `--filter` do PHPUnit não é ancorado e as Fases 186/187/188 estão em produção');

        // ─── Bijeção nos DOIS sentidos ──────────────────────────────────────────────────────
        $cobertos = [];
        $orfaos = [];
        foreach ($ids as $id => $dados) {
            $nomes = self::expandir($dados['filtro']);
            $this->assertLessThanOrEqual(3, count($nomes), "o id `{$id}` tem mais de TRÊS métodos: alternância grande equivale a filtro de classe e passa dos 120 s");
            $this->assertSame(count($nomes), $dados['tests'], "o id `{$id}` declara `tests=` diferente do número de métodos do filtro");
            foreach ($nomes as $nome) {
                if (! in_array($nome, $metodos, true)) {
                    $orfaos[] = $id . ' -> ' . $nome;

                    continue;
                }
                $cobertos[] = $nome;
            }
        }
        $this->assertSame([], $orfaos, 'ORFAOS: id apontando para método que não existe — id órfão não falha, ele simplesmente NUNCA RODA');

        $semId = array_values(array_diff($metodos, $cobertos));
        $this->assertSame([], $semId, 'SEM_ID: método sem id no manifesto — ele NUNCA RODA em gate nenhum (o sobrevivente da mutação do 186-03)');

        // ─── ⚠️ HOMÔNIMO em toda a suíte, com isca nas duas direções ───────────────────────
        $homonimos = [];
        foreach (self::todosOsTestes() as $arquivo => $conteudo) {
            if (realpath($arquivo) === realpath($meuArquivo)) {
                continue;
            }
            foreach ($metodos as $meu) {
                if (str_contains($conteudo, $meu)) {
                    $homonimos[] = basename($arquivo) . ' :: ' . $meu;
                }
            }
        }
        $this->assertSame([], $homonimos, 'HOMONIMOS: outro arquivo tem método que o meu `--filter` casaria — o id somaria e reprovaria');

        // ⚠️ ISCA (+) do varredor: um nome SUPERSTRING do meu TEM de ser detectado.
        $sintetico = 'public function ' . $metodos[0] . '_extra(): void {}';
        $this->assertTrue(str_contains($sintetico, $metodos[0]), 'ISCA (+): o varredor de homônimo não acha um superstring do meu nome');

        // ⚠️ ISCA (−): um nome REAL de outra fase NÃO pode ser acusado.
        $this->assertFalse(in_array('test_aprovar_e_enfileirar_188_05', $metodos, true));
        $alheio = 'test_aprovar_enfileira_e_nao_escreve_no_mercado_livre_com_isca_188_05';
        $acusaria = array_values(array_filter($metodos, static fn (string $x) => str_contains($alheio, $x)));
        $this->assertSame([], $acusaria, 'ISCA (−): o varredor acusaria um método real de outra fase');
    }

    // ─────────────────────────────────────────────────────────────────────────────────────────
    // Apoio dos casos
    // ─────────────────────────────────────────────────────────────────────────────────────────

    /** O código PHP **sem comentário**, por `token_get_all` — nunca por regex (armadilha 7). */
    private static function semComentarios(string $codigo): string
    {
        $saida = '';
        foreach (token_get_all($codigo) as $t) {
            if (is_array($t)) {
                if (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    // Preserva as quebras de linha: a contagem de linhas continua honesta.
                    $saida .= str_repeat("\n", substr_count($t[1], "\n"));

                    continue;
                }
                $saida .= $t[1];

                continue;
            }
            $saida .= $t;
        }

        return $saida;
    }

    /**
     * Abre `test_(a|b|c)` em `['test_a','test_b','test_c']` — o EXPANSOR DE ALTERNÂNCIA.
     *
     * @return list<string>
     */
    private static function expandir(string $filtro): array
    {
        if (preg_match('/^(.*)\(([^)]*)\)(.*)$/', $filtro, $m) !== 1) {
            return [$filtro];
        }

        return array_map(static fn (string $meio) => $m[1] . $meio . $m[3], explode('|', $m[2]));
    }

    /** @return array<string, string> caminho => conteúdo de cada `*Test.php` da suíte */
    private static function todosOsTestes(): array
    {
        $saida = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path('tests'), \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $arquivo) {
            if ($arquivo->isFile() && str_ends_with($arquivo->getFilename(), 'Test.php')) {
                $saida[$arquivo->getPathname()] = (string) file_get_contents($arquivo->getPathname());
            }
        }

        return $saida;
    }

    /**
     * Aprova as DUAS ofertas do cenário pela única porta e devolve a que agendou (a última).
     *
     * @param  array<string, mixed>  $c
     */
    private function aprovarAsDuas(array $c, User $admin, bool $enfileirar = true): PubAprovacaoOferta
    {
        $this->aprovar($c['classico'], $admin);

        if ($c['premium'] === null) {
            return $c['classico']->fresh();
        }

        $premium = $this->aprovar($c['premium'], $admin);

        if ($enfileirar) {
            $this->assertNotNull($premium->fila_item_id, 'o cenário não enfileirou: o caso mediria outra coisa');
        }

        return $premium->fresh();
    }

    /**
     * Um item de fila montado À MÃO num estado escolhido — é o único jeito de exercitar os 6
     * estados sem depender de a fila chegar até eles.
     *
     * @param  array<string, mixed>  $c
     */
    private function itemManual(array $c, string $status, bool $viva): PubFilaPublicacaoItem
    {
        $admin = User::query()->where('role', 'admin')->firstOrFail();
        $fila = PubFilaPublicacao::create([
            'conta_chave' => 'company-' . $c['produto']->company_id,
            'conta_ativa' => null,
            'company_id' => $c['produto']->company_id,
            'status' => $viva ? PubFilaPublicacao::ATIVA : PubFilaPublicacao::CONCLUIDA,
            'intervalo_minutos' => 20,
            'produtos_por_rodada' => 5,
            'criada_por' => $admin->id,
        ]);

        return PubFilaPublicacaoItem::create([
            'fila_id' => $fila->id,
            'produto_id' => $c['produto']->id,
            'rascunho_id' => $c['rascunho']->id,
            // ⚠️ `produto_ativo` é o que o unique protege, e ele é NULO quando o item sai de cena.
            'produto_ativo' => $viva ? $c['produto']->id : null,
            'posicao' => 1,
            'status' => $status,
            'validacao_id' => PubValidacao::query()->where('rascunho_id', $c['rascunho']->id)->value('id'),
            'plano_hash' => str_repeat('a', 64),
            'revisao' => (int) $c['rascunho']->revisao,
            'ciente' => false,
            'resumo' => ['nome' => $c['produto']->nome, 'sku' => $c['produto']->sku, 'anuncios' => 1],
        ]);
    }
}
