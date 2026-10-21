<?php

namespace Tests\Feature\Publicador\Aprovacao;

use App\Http\Controllers\MlbPublicadorAprovacaoController;
use App\Jobs\Publicador\PublicarRascunhoJob;
use App\Models\Company;
use App\Models\MlbEmpresa;
use App\Models\MlToken;
use App\Models\PubAprovacaoEvento;
use App\Models\PubAprovacaoOferta;
use App\Models\PubFilaPublicacao;
use App\Models\PubFilaPublicacaoItem;
use App\Models\PubProduto;
use App\Models\PubPublicacao;
use App\Models\PubPublicacaoItem;
use App\Models\PubRascunho;
use App\Models\PubRascunhoAlvo;
use App\Models\PubValidacao;
use App\Models\User;
use App\Services\Publicador\Aprovacao\AndamentoDaOfertaService;
use App\Services\Publicador\Aprovacao\Painel\PainelDeRevisaoService;
use App\Services\Publicador\Aprovacao\PublicacaoDoPortaoService;
use App\Services\Publicador\ClienteMlPublicador;
use App\Services\Publicador\ConferenciaService;
use App\Services\Publicador\PublicacaoService;
use App\Support\Publicador\RegraViolada;
use App\Support\Publicador\Validacao\Problema;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 * Fase 193, plano 03 — **DO APROVADO AO AR**: o reconciliador espelha na oferta o que a fila
 * **efetivamente fez**, e nada mais do que isso.
 *
 * ⚠️⚠️ DE CADA CASO, A PERGUNTA RESPONDIDA ANTES DE ELE EXISTIR: **"o que precisaria estar errado
 * no mundo para ele falhar?"**
 *
 *   · **`publicado` carimbado sem o Mercado Livre ter confirmado** — o anúncio existiria só na
 *     nossa cabeça (T-193-21). ➡️ `ml_item_id` gravado **ANTES**, e a **porta recusa** sem ele.
 *   · **O MLB de OUTRA modalidade gravado na oferta** (T-193-22): o Premium exibindo o MLB do
 *     Clássico. ➡️ Filtro por `listing_type_id` do alvo + `first()` por `indice`, a **mesma** regra
 *     de `cadastrarNaRegua()`, com isca de tipo trocado e de índice trocado.
 *   · ⚠️⚠️ **ANÚNCIO DUPLICADO por mexer em item em voo** (T-193-23): item `PENDING`/`SENT`/
 *     `UNKNOWN` significa *"o anúncio pode existir"*; movê-lo para `falha` ou reenviá-lo duplicaria
 *     (RN-93). ➡️ A fixture **TEM** um `UNKNOWN` **ao lado de um `CREATED` do mesmo tipo**, e a
 *     oferta **não se move** — sem o `CREATED` ao lado, "não se moveu" seria verdade por não haver
 *     nada para mover.
 *   · **A revisão aprovada jogada fora na falha** (T-193-25): quem revisou teria de revisar de novo.
 *   · **A frase *"Nenhuma oferta foi criada"* mentindo num PARCIAL** (T-193-24).
 *   · **Edição de oferta em voo** (T-193-26): publicaria coisa diferente da conferida.
 *   · **A disciplina do PUB-06 regredindo** em `PublicacaoService` — e um gate que lesse
 *     COMENTÁRIO passaria com a linha de código apagada (T-193-27).
 *
 * ═══ ⚠️ O QUE SAI PELA REDE ════════════════════════════════════════════════════════════════════
 *
 * ⚠️⚠️ **O RECONCILIADOR NÃO FAZ REQUISIÇÃO NENHUMA — nem leitura.** `Http::preventStrayRequests()`
 * está de pé em **todos** os casos, e os que só reconciliam asseveram `Http::recorded()` **vazio**.
 * A lista `LEITURAS_AUTORIZADAS` existe porque **aprovar** (Fase 188) lê a tarifa real no GET
 * `/sites/MLB/listing_prices` (decisão 9 do usuário) — a régua honesta é **zero ESCRITA**, e a
 * leitura permitida está em **lista CONSTANTE nomeada**, nunca num filtro largo.
 *
 * ⚠️⚠️ **NENHUM EXECUTOR DESTA FASE VIU ESTA TELA RENDERIZADA, nem publicação real.** Não há
 * navegador, nenhuma empresa tem `ml_token` de verdade e `pub_aprovacao_ofertas` está vazia em
 * produção. A prova desta wave é teste com `Http::fake` + `preventStrayRequests()`.
 *
 * ⚠️ **As nove formas proibidas** (*"confirmar publicação"*, *"segundo passo"*, *"desfazer
 * aprovação"*, *"cancelar aprovação"* e suas formas de código) são citadas na prosa desta fase **de
 * propósito**: é a **isca do leitor** do gate de ausência, que lê o código **sem comentário**.
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 */
class ReconciliarPublicacaoDoPortaoTest extends TestCase
{
    use RefreshDatabase;

    private const CLASSICO = 'gold_special';

    private const PREMIUM = 'gold_pro';

    private const CATEGORIA = 'MLB189007';

    /** ⚠️ A única leitura de rede autorizada nesta fase — a tarifa real (decisão 9 do usuário). */
    private const LEITURAS_AUTORIZADAS = ['/sites/MLB/listing_prices'];

    /**
     * ⚠️⚠️ **O LITERAL DO PROTÓTIPO, CONGELADO AQUI — e isto é ANTI-TAUTOLOGIA DE LITERAL.**
     *
     * Ler a constante do módulo que acabou de produzir o texto **passa mesmo trocando o literal pelo
     * sinônimo**: o mesmo furo estava em **sete** lugares desta milestone. ➡️ A expectativa é uma
     * **string literal** escrita aqui, comparada por **IGUALDADE**, com **iscas do quase-igual**.
     * A autoridade é `design_handoff_publicador/Aprovacao.dc.html:956` (a oferta `o7`).
     */
    private const CLAUSULA_CONGELADA = 'Nenhuma oferta foi criada; a revisão foi preservada.';

    private int $sequencia = 0;

    protected function setUp(): void
    {
        parent::setUp();
        // ⚠️ `Carbon::setTestNow()` em todo caso: teste dependente de hora reprova POR HORÁRIO, e é
        // essa a classe das duas falhas que oscilam nesta suíte (`CockpitDaContaTest`).
        Carbon::setTestNow('2026-10-21 11:30:00');

        // ⚠️ De pé em TODOS os casos: é ele que transforma "o reconciliador não fala com o Mercado
        // Livre" de afirmação de docblock em medição.
        Http::preventStrayRequests();

        // ⚠️ A receita MEDIDA do 188-05: sem o app token pré-aquecido, `ComposicaoFinanceiraService`
        // chama `MlColetaService::getAppToken()` e a rota de aprovar devolve **500** — e o caso
        // mediria um erro de fixture em vez do comportamento.
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

        MlbEmpresa::create([
            'nome' => 'Empresa do portão ' . $company->id,
            'tipo' => 'ASSESSORIA',
            'company_id' => $company->id,
            'responsavel_id' => $admin->id,
        ]);

        // ⚠️ `ContasLiberadas` é FAIL-CLOSED: lista vazia = NINGUÉM.
        config(['publicador.contas_liberadas.companies' => [(int) $company->id]]);

        return [$company, MlbEmpresa::query()->where('company_id', $company->id)->first(), $admin];
    }

    /**
     * Um produto com **DUAS** ofertas (Clássico e Premium) e a conferência L3 válida.
     *
     * @param  array<string, mixed>  $o
     *                               `so_classico` (bool) — nasce com UM alvo só
     *                               `avisos` (list<array>) — as `issues` da conferência L3 (decisão 24)
     * @return array<string, mixed>
     */
    private function cenario(Company $company, array $o = []): array
    {
        $this->sequencia++;
        $n = $this->sequencia;

        $produto = PubProduto::create([
            'company_id' => $company->id,
            'sku' => 'RCL-' . $n,
            'nome' => 'Poltrona reconciliada ' . $n,
            'origem' => PubProduto::ORIGEM_PUBLICADOR,
            'quantidade_kit' => 1,
        ]);
        $rascunho = PubRascunho::create(['produto_id' => $produto->id, 'categoria_id' => self::CATEGORIA]);

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
                'ativo' => true,
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

        // ⚠️⚠️ `fresh()` OBRIGATÓRIO: **o Eloquent não lê o default do banco** — `PubRascunho::create()`
        // sem `revisao` devolve `null` em memória enquanto a linha nasce `1`. Sem ele a conferência
        // nasce com `revisao = 0` e a fila recusa por "o produto mudou depois da conferência": o caso
        // mediria a recusa ERRADA (armadilha já paga por este projeto, e de novo pelo plano 193-02).
        $rascunho = $rascunho->fresh();

        $avisos = $o['avisos'] ?? [];

        $validacao = PubValidacao::create([
            'rascunho_id' => $rascunho->id,
            'revisao' => (int) $rascunho->revisao,
            'camada' => 'L3',
            'resultado' => $avisos === [] ? ConferenciaService::OK : ConferenciaService::AVISOS,
            'plano_hash' => str_repeat('a', 64),
            'issues' => $avisos,
        ]);

        return [
            'produto' => $produto->fresh(),
            'rascunho' => $rascunho->fresh(),
            'validacao' => $validacao,
            'alvos' => $alvos,
            'classico' => $ofertas[self::CLASSICO]->fresh(),
            'premium' => isset($ofertas[self::PREMIUM]) ? $ofertas[self::PREMIUM]->fresh() : null,
        ];
    }

    /**
     * A oferta posta **direto** no andamento pedido — pela ÚNICA PORTA sempre que a lista fechada
     * `TRANSICOES` permite, e **nunca** escrevendo a coluna `andamento` no teste.
     *
     * ⚠️ `aprovado` passa por `mover()`, que dispara o gancho `aoAprovar()` do plano 193-02 — é
     * assim que a vida real chega aqui, e um `update()` cru pularia o gancho e mediria outro mundo.
     */
    private function levarPara(PubAprovacaoOferta $oferta, string $destino, User $quem): PubAprovacaoOferta
    {
        $porta = app(AndamentoDaOfertaService::class);
        $atual = $porta->mover($oferta, PubAprovacaoOferta::APROVADO, $quem);

        if ($destino === PubAprovacaoOferta::APROVADO) {
            return $atual;
        }

        return $porta->mover($atual, $destino, $quem);
    }

    /** Uma fila viva da conta, com UM item no status pedido. */
    private function itemDeFila(Company $company, array $c, string $status, array $extra = []): PubFilaPublicacaoItem
    {
        $chave = 'company:' . $company->id;

        $fila = PubFilaPublicacao::query()->where('conta_chave', $chave)->whereIn('status', PubFilaPublicacao::VIVAS)->first()
            ?? PubFilaPublicacao::create([
                'conta_chave' => $chave,
                'conta_ativa' => $chave,
                'company_id' => $company->id,
                'status' => PubFilaPublicacao::ATIVA,
                'iniciada_em' => now(),
            ]);

        return PubFilaPublicacaoItem::create([
            'fila_id' => $fila->id,
            'produto_id' => $c['produto']->id,
            'rascunho_id' => $c['rascunho']->id,
            // ⚠️ `produto_ativo` é a coluna-sombra do unique: ela vale o produto enquanto o item
            // VIVE e é anulada quando ele sai de cena (é o que a própria fila faz em `fechar()`).
            'produto_ativo' => in_array($status, PubFilaPublicacaoItem::VIVOS, true) ? $c['produto']->id : null,
            'posicao' => 1,
            'status' => $status,
            'validacao_id' => $c['validacao']->id,
            'plano_hash' => str_repeat('a', 64),
            'revisao' => (int) $c['rascunho']->revisao,
            'ciente' => false,
            ...$extra,
        ]);
    }

    /**
     * O item que **a própria aprovação** criou (gancho `aoAprovar()` do plano 193-02), posto no
     * status pedido.
     *
     * ⚠️⚠️ **E ISSO NÃO É CONVENIÊNCIA: É O UNIQUE DO BANCO.** `pubfilai_produto_ativo_uq` deixa UM
     * item **vivo** por produto, então criar um segundo item `publicando` para um produto que a
     * aprovação já enfileirou **estoura `UniqueConstraintViolationException`** — medido na primeira
     * rodada deste arquivo. ➡️ Para status VIVO, o caminho honesto é **mover o item que existe**,
     * que é exatamente o que a fila faz na vida real.
     */
    private function itemDaAprovacao(PubAprovacaoOferta $oferta, string $status, array $extra = []): PubFilaPublicacaoItem
    {
        $item = $oferta->fresh()->filaItem;
        $this->assertNotNull($item, 'a aprovação não enfileirou: a fixture deste caso não tem item para mover');

        $item->update([
            'status' => $status,
            'produto_ativo' => in_array($status, PubFilaPublicacaoItem::VIVOS, true) ? $item->produto_id : null,
            ...$extra,
        ]);

        return $item->fresh();
    }

    /**
     * Uma publicação com os itens pedidos.
     *
     * @param  list<array<string, mixed>>  $itens  cada um: `tipo`, `status`, `mlb`, `indice`, `avisos`
     */
    private function publicacao(array $c, string $status, array $itens, ?string $motivoDaConta = null): PubPublicacao
    {
        $p = PubPublicacao::create([
            'rascunho_id' => $c['rascunho']->id,
            'revisao' => (int) $c['rascunho']->revisao,
            'modelo_publicacao' => 'user_products',
            'plano_hash' => str_repeat('a', 64),
            'status' => $status,
            'chave_idempotencia' => (string) Str::uuid(),
            'iniciada_em' => now()->subMinutes(3),
            'conta_snapshot' => $motivoDaConta === null ? null : ['motivo' => $motivoDaConta],
        ]);

        foreach ($itens as $i => $item) {
            PubPublicacaoItem::create([
                'publicacao_id' => $p->id,
                'indice' => $item['indice'] ?? $i,
                'listing_type_id' => $item['tipo'],
                'variante_chave' => $item['variante'] ?? ('cor=' . $i),
                'status' => $item['status'],
                'ml_item_id' => $item['mlb'] ?? null,
                'avisos' => $item['avisos'] ?? null,
            ]);
        }

        return $p->fresh();
    }

    // ─────────────────────────────────────────────────────────────────────────────────────────
    // Apoio
    // ─────────────────────────────────────────────────────────────────────────────────────────

    private function portao(): PublicacaoDoPortaoService
    {
        return app(PublicacaoDoPortaoService::class);
    }

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

    /** @return list<string> as requisições que NÃO são a leitura autorizada */
    private function escritasEDesvios(): array
    {
        return Http::recorded()
            ->reject(static fn ($par) => $par[0]->method() === 'GET'
                && in_array((string) parse_url($par[0]->url(), PHP_URL_PATH), self::LEITURAS_AUTORIZADAS, true))
            ->map(static fn ($par) => $par[0]->method() . ' ' . $par[0]->url())
            ->values()
            ->all();
    }

    /** O código **sem comentário** — `token_get_all`, nunca regex sobre o texto cru. */
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

    /** @return array<string, mixed> os quatro campos da prova da aprovação, crus do banco */
    private function provaDaAprovacao(PubAprovacaoOferta $oferta): array
    {
        $linha = DB::table('pub_aprovacao_ofertas')->where('id', $oferta->id)
            ->first(['versao', 'versao_aprovada', 'aprovado_por', 'aprovado_em']);

        return (array) $linha;
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // (1) `publicando` E `publicado` — PELA ÚNICA PORTA, COM O MLB DA MODALIDADE CERTA
    // ═════════════════════════════════════════════════════════════════════════════════════════

    /**
     * ⚠️ **O item em `publicando` move a oferta, e a 2ª passada é NO-OP.**
     *
     * **Idempotência é requisito, não zelo:** o comando roda **todo minuto**. A lista fechada
     * `TRANSICOES` não tem `publicando → publicando`, então a 2ª passada não pode lançar (o
     * reconciliador engoliria o erro e contaria `erros`), não pode mover de novo e não pode
     * duplicar evento.
     */
    public function test_item_publicando_move_a_oferta_e_a_segunda_passada_e_no_op_193_03(): void
    {
        [$company, , $admin] = $this->conta();
        $c = $this->cenario($company, ['so_classico' => true]);

        $oferta = $this->levarPara($c['classico'], PubAprovacaoOferta::APROVADO, $admin);
        // ⚠️ O item que a APROVAÇÃO criou, movido para `publicando` — é o que a fila faz na vida
        // real, e criar um segundo item vivo estouraria `pubfilai_produto_ativo_uq`.
        $item = $this->itemDaAprovacao($oferta, PubFilaPublicacaoItem::PUBLICANDO);
        $oferta->update(['fila_item_id' => $item->id, 'erro_publicacao' => 'erro de uma tentativa velha']);

        $primeira = $this->portao()->reconciliar();

        $this->assertSame(1, $primeira['publicando'], 'o item `publicando` não levou a oferta a publicando');
        $this->assertSame(0, $primeira['erros']);
        $this->assertSame(PubAprovacaoOferta::PUBLICANDO, $oferta->fresh()->andamento);
        // ⚠️ `mover(PUBLICANDO)` LIMPA o erro da tentativa velha: ele não aparece na nova.
        $this->assertNull($oferta->fresh()->erro_publicacao, 'o erro da tentativa anterior sobreviveu ao `publicando`');
        // ⚠️ E `publicando` BLOQUEIA EDIÇÃO — é `ehLive()`, num lugar só (AND-05).
        $this->assertFalse($oferta->fresh()->podeEditar(), 'oferta em voo ficou editável');

        $eventosDepoisDaPrimeira = $this->eventos($oferta->fresh());

        // ── A 2ª PASSADA: NO-OP COMPLETO ───────────────────────────────────────────────────
        $segunda = $this->portao()->reconciliar();

        $this->assertSame(0, $segunda['publicando'], 'a 2ª passada moveu de novo');
        $this->assertSame(0, $segunda['erros'], 'a 2ª passada lançou (a transição ilegal foi engolida como erro)');
        $this->assertSame(1, $segunda['esperando'], 'a 2ª passada não reconheceu que o item segue em voo');
        $this->assertSame($eventosDepoisDaPrimeira, $this->eventos($oferta->fresh()), 'a 2ª passada duplicou evento de histórico');

        // ⚠️ ZERO requisição: o reconciliador não fala com o Mercado Livre, nem em leitura.
        $this->assertSame([], Http::recorded()->all(), 'o reconciliador tocou a rede');
    }

    /**
     * ⚠️⚠️ **`publicado` SÓ COM MLB CONFIRMADO, E O MLB É O DA MODALIDADE DESTA OFERTA.**
     *
     * Três coisas são medidas aqui, e cada uma tem isca:
     *   1. o `CREATED` existir **de outro `listing_type_id`** não leva a oferta a `publicado`
     *      (T-193-22: seria o MLB do Clássico na linha do Premium);
     *   2. o MLB escolhido é o **`first()` por `indice`** dentro do grupo do meu tipo — a MESMA
     *      regra de `PublicacaoService::cadastrarNaRegua()`, com dois `CREATED` do mesmo tipo e os
     *      índices **trocados** para provar que o escolhido muda;
     *   3. a ordem `ml_item_id` → `mover(PUBLICADO)` — e a trava está no CÓDIGO, não no teste:
     *      `mover()` **recusa** `publicado` sem MLB (`REGRA_SEM_MLB`).
     */
    public function test_publicado_so_com_mlb_confirmado_e_pelo_listing_type_da_oferta_193_03(): void
    {
        [$company, , $admin] = $this->conta();

        // ── ISCA (−): o CREATED é de OUTRO tipo → a oferta NÃO vai a publicado ─────────────
        $c1 = $this->cenario($company);
        $premium = $this->levarPara($c1['premium'], PubAprovacaoOferta::PUBLICANDO, $admin);
        $item1 = $this->itemDeFila($company, $c1, PubFilaPublicacaoItem::PUBLICADO);
        $p1 = $this->publicacao($c1, PubPublicacao::PUBLISHED, [
            ['tipo' => self::CLASSICO, 'status' => PubPublicacaoItem::CREATED, 'mlb' => 'MLB111111111', 'indice' => 0],
        ]);
        $item1->update(['publicacao_id' => $p1->id]);
        $premium->update(['fila_item_id' => $item1->id]);

        $r1 = $this->portao()->reconciliar();

        $this->assertSame(0, $r1['publicado'], 'ISCA: o MLB de OUTRA modalidade levou a oferta a publicado');
        $this->assertNull($premium->fresh()->ml_item_id, 'ISCA: gravou na oferta do Premium o MLB do Clássico');
        $this->assertSame(PubAprovacaoOferta::FALHA, $premium->fresh()->andamento, 'sem anúncio da modalidade dela, a oferta tem de ir a falha');

        // ── ISCA (+): o CREATED do MEU tipo → publicado, com o MLB gravado ────────────────
        $c2 = $this->cenario($company);
        $classico = $this->levarPara($c2['classico'], PubAprovacaoOferta::PUBLICANDO, $admin);
        $item2 = $this->itemDeFila($company, $c2, PubFilaPublicacaoItem::PUBLICADO);
        // ⚠️ DOIS `CREATED` do mesmo tipo com os índices TROCADOS: o esperado é o de `indice` MENOR,
        // e é isso que prova que a regra é `first()` sobre `sortBy('indice')` e não a ordem de `id`.
        $p2 = $this->publicacao($c2, PubPublicacao::PUBLISHED, [
            ['tipo' => self::CLASSICO, 'status' => PubPublicacaoItem::CREATED, 'mlb' => 'MLB222222222', 'indice' => 7],
            ['tipo' => self::CLASSICO, 'status' => PubPublicacaoItem::CREATED, 'mlb' => 'MLB333333333', 'indice' => 2],
            ['tipo' => self::PREMIUM, 'status' => PubPublicacaoItem::CREATED, 'mlb' => 'MLB999999999', 'indice' => 1],
        ]);
        $item2->update(['publicacao_id' => $p2->id]);
        $classico->update(['fila_item_id' => $item2->id]);

        $r2 = $this->portao()->reconciliar();

        $this->assertSame(1, $r2['publicado'], 'o CREATED do tipo da oferta não levou a publicado');
        $this->assertSame(PubAprovacaoOferta::PUBLICADO, $classico->fresh()->andamento);
        $this->assertSame('MLB333333333', $classico->fresh()->ml_item_id, 'o MLB escolhido não é o `first()` por `indice` do grupo do tipo');
        $this->assertContains(
            'Publicado e confirmado pelo Mercado Livre · MLB333333333',
            $this->eventos($classico->fresh()),
            'o histórico não registrou a confirmação do Mercado Livre com o MLB'
        );

        // ── ⚠️⚠️ A TRAVA NO CÓDIGO: a porta recusa `publicado` sem MLB ────────────────────
        $c3 = $this->cenario($company, ['so_classico' => true]);
        $semMlb = $this->levarPara($c3['classico'], PubAprovacaoOferta::PUBLICANDO, $admin);
        $antes = $this->provaDaAprovacao($semMlb);
        $atualizadoEm = (string) DB::table('pub_aprovacao_ofertas')->where('id', $semMlb->id)->value('updated_at');

        try {
            app(AndamentoDaOfertaService::class)->mover($semMlb, PubAprovacaoOferta::PUBLICADO, $admin);
            $this->fail('a porta aceitou `publicado` SEM MLB confirmado — AND-04 caiu');
        } catch (RegraViolada $e) {
            $this->assertSame(AndamentoDaOfertaService::REGRA_SEM_MLB, $e->regra);
        }

        $this->assertSame(PubAprovacaoOferta::PUBLICANDO, $semMlb->fresh()->andamento, 'a recusa moveu a oferta');
        // ⚠️ NO-OP COMPLETO: nem `updated_at` se move. "Recusei" e "mudei a linha" não podem se confundir.
        $this->assertSame($atualizadoEm, (string) DB::table('pub_aprovacao_ofertas')->where('id', $semMlb->id)->value('updated_at'), 'a recusa tocou a linha');
        $this->assertSame($antes, $this->provaDaAprovacao($semMlb));

        $this->assertSame([], Http::recorded()->all(), 'o reconciliador tocou a rede');
    }

    /**
     * ⚠️⚠️ **ITEM `193-G`, DEVOLVIDO PELO PLANO 193-02 E ATRIBUÍDO A ESTE: as DUAS irmãs aprovadas
     * ficam ligadas ao MESMO item de fila depois do reconciliador.**
     *
     * Com a guarda da **decisão 23**, quem agenda é a **ÚLTIMA** aprovação do produto: a irmã
     * aprovada antes fica em `aprovado` **sem `fila_item_id`**, porque o `enfileirar()` do 193-02
     * escreve a ligação **só na oferta que recebeu**. ➡️ É o reconciliador que a liga, pelo caminho
     * do **ANEXO** — e o unique `pubfilai_produto_ativo_uq` garante que o item é **um por produto**.
     *
     * ⚠️ **Sem isto a oferta do Clássico iria ao ar sem nunca apontar para a fila que a publicou**,
     * e o painel leria as 4 colunas de conferência de lugar nenhum (DADO-03).
     */
    public function test_as_irmas_aprovadas_ficam_ligadas_ao_mesmo_item_apos_reconciliar_193_03(): void
    {
        [$company, , $admin] = $this->conta();
        $c = $this->cenario($company);

        // 1) O Clássico é aprovado primeiro: a guarda da decisão 23 recusa (o Premium está ATIVO e
        //    não aprovado), então ele fica em `aprovado` SEM ligação.
        $classico = $this->levarPara($c['classico'], PubAprovacaoOferta::APROVADO, $admin);
        $this->assertNull($classico->fresh()->fila_item_id, 'a guarda da decisão 23 não barrou a entrada na fila');
        $this->assertSame(0, PubFilaPublicacaoItem::query()->where('produto_id', $c['produto']->id)->count());

        // 2) O Premium é aprovado: agora os dois alvos ativos passaram pelo portão, e ELE agenda.
        $premium = $this->levarPara($c['premium'], PubAprovacaoOferta::APROVADO, $admin);
        $itemId = (int) $premium->fresh()->fila_item_id;

        $this->assertNotSame(0, $itemId, 'a última aprovação não agendou');
        $this->assertNull($classico->fresh()->fila_item_id, 'a irmã já aprovada ganhou ligação ANTES do reconciliador — o 193-02 passou a escrever em linha alheia');

        // 3) ⚠️ O RECONCILIADOR: é ele que liga a irmã, pelo ANEXO.
        $resumo = $this->portao()->reconciliar();

        $this->assertSame(1, $resumo['enfileiradas'], 'o reconciliador não enfileirou a irmã sem ligação');
        $this->assertSame(0, $resumo['erros']);
        $this->assertSame($itemId, (int) $classico->fresh()->fila_item_id, 'as duas irmãs não ficaram no MESMO item de fila');
        $this->assertSame($itemId, (int) $premium->fresh()->fila_item_id);
        $this->assertSame(1, PubFilaPublicacaoItem::query()->where('produto_id', $c['produto']->id)->count(), 'o anexo duplicou o item de fila do produto');

        // ⚠️ E as 4 colunas de conferência continuam MORANDO NO ITEM (DADO-03), lidas pela relação.
        $doItem = $classico->fresh()->filaItem;
        $this->assertNotNull($doItem);
        $this->assertSame((int) $c['validacao']->id, (int) $doItem->validacao_id);
        $this->assertSame((int) $c['rascunho']->revisao, (int) $doItem->revisao);

        // ⚠️ IDEMPOTÊNCIA: a 2ª passada não muda nada e não duplica evento.
        $eventos = $this->eventos($classico->fresh());
        $this->portao()->reconciliar();
        $this->assertSame($itemId, (int) $classico->fresh()->fila_item_id);
        $this->assertSame($eventos, $this->eventos($classico->fresh()), 'a 2ª passada duplicou evento na irmã já ligada');

        $this->assertSame([], $this->escritasEDesvios(), 'saiu escrita ao Mercado Livre do reconciliador');
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // (2) A FALHA: O ERRO REAL, A REVISÃO PRESERVADA E A CLÁUSULA CONDICIONAL
    // ═════════════════════════════════════════════════════════════════════════════════════════

    /**
     * ⚠️⚠️ **A FALHA GUARDA O ERRO REAL (PUB-05) E PRESERVA A REVISÃO APROVADA INTEIRA** (T-193-25).
     *
     * A precedência do motivo é a de `AgendadorDaFila::fechar()`: `conta_snapshot['motivo']` **antes**
     * de `avisos['mensagem']` — e com os dois presentes **ganha o `conta_snapshot`**, que é a isca que
     * prova que a ordem não é decorativa.
     *
     * ⚠️ E *"Tentar de novo"* (`falha → publicando`) **limpa** `erro_publicacao` e **não** incrementa
     * a `versao`: incrementar reabriria uma aprovação em que ninguém mexeu.
     */
    public function test_falha_guarda_o_erro_real_e_preserva_a_revisao_aprovada_193_03(): void
    {
        [$company, , $admin] = $this->conta();
        $c = $this->cenario($company, ['so_classico' => true]);

        $oferta = $this->levarPara($c['classico'], PubAprovacaoOferta::PUBLICANDO, $admin);
        $prova = $this->provaDaAprovacao($oferta);
        $this->assertNotNull($prova['versao_aprovada'], 'a fixture não tem prova de aprovação para preservar');

        $item = $this->itemDeFila($company, $c, PubFilaPublicacaoItem::FALHOU, ['motivo' => 'motivo que a fila escreveu']);
        // ⚠️ OS DOIS PRESENTES: o `conta_snapshot` tem de GANHAR do aviso do item.
        $p = $this->publicacao($c, PubPublicacao::FAILED, [
            ['tipo' => self::CLASSICO, 'status' => PubPublicacaoItem::FAILED, 'avisos' => ['mensagem' => 'O título já existe em outro anúncio desta conta.']],
        ], 'A conta do Mercado Livre foi desconectada durante a publicação.');
        $item->update(['publicacao_id' => $p->id]);
        $oferta->update(['fila_item_id' => $item->id]);

        $resumo = $this->portao()->reconciliar();

        $this->assertSame(1, $resumo['falha']);
        $falhada = $oferta->fresh();
        $this->assertSame(PubAprovacaoOferta::FALHA, $falhada->andamento);
        $this->assertStringContainsString('A conta do Mercado Livre foi desconectada durante a publicação.', (string) $falhada->erro_publicacao);
        $this->assertStringNotContainsString('O título já existe', (string) $falhada->erro_publicacao, 'o aviso do item ganhou do `conta_snapshot` — a ordem do precedente inverteu');

        // ⚠️⚠️ A REVISÃO APROVADA, INTACTA — os quatro campos lidos CRUS do banco.
        $this->assertSame($prova, $this->provaDaAprovacao($falhada), 'a falha jogou fora a prova da aprovação');

        // ⚠️ A caixa de falha do painel traz a mensagem REAL gravada, não um texto genérico.
        $painel = app(PainelDeRevisaoService::class)->para(
            $falhada,
            app(AndamentoDaOfertaService::class)->filaDaConta((int) $company->id)
        );
        $this->assertIsArray($painel['falha'] ?? null, 'o painel não montou a caixa de falha');
        $this->assertSame((string) $falhada->erro_publicacao, (string) $painel['falha']['mensagem'], 'a caixa de falha mostrou outro texto');
        $this->assertSame(PainelDeRevisaoService::FALHA_ROTULO_ACAO, (string) $painel['falha']['rotulo_acao']);

        // ── "Tentar de novo": limpa o erro e NÃO mexe na prova da aprovação ───────────────
        $retomada = app(AndamentoDaOfertaService::class)->mover($falhada, PubAprovacaoOferta::PUBLICANDO, $admin);

        $this->assertSame(PubAprovacaoOferta::PUBLICANDO, $retomada->andamento);
        $this->assertNull($retomada->erro_publicacao, '"Tentar de novo" não limpou o erro da tentativa anterior');
        $this->assertSame($prova, $this->provaDaAprovacao($retomada), '"Tentar de novo" mexeu na versão ou na prova da aprovação');

        // ── ⚠️ O 2º degrau do motivo: sem `conta_snapshot`, ganha o aviso do item FAILED **DO MEU
        //    TIPO** — o Clássico não pode receber o erro do Premium.
        $c2 = $this->cenario($company);
        $premium2 = $this->levarPara($c2['premium'], PubAprovacaoOferta::PUBLICANDO, $admin);
        $item2 = $this->itemDeFila($company, $c2, PubFilaPublicacaoItem::FALHOU);
        $p2 = $this->publicacao($c2, PubPublicacao::FAILED, [
            ['tipo' => self::CLASSICO, 'status' => PubPublicacaoItem::FAILED, 'indice' => 0, 'avisos' => ['mensagem' => 'erro do Clássico']],
            ['tipo' => self::PREMIUM, 'status' => PubPublicacaoItem::FAILED, 'indice' => 1, 'avisos' => ['mensagem' => 'A conta não pode publicar como Premium nesta categoria.']],
        ]);
        $item2->update(['publicacao_id' => $p2->id]);
        $premium2->update(['fila_item_id' => $item2->id]);

        $this->portao()->reconciliar();

        $this->assertStringContainsString(
            'A conta não pode publicar como Premium nesta categoria.',
            (string) $premium2->fresh()->erro_publicacao,
            'a oferta do Premium recebeu o erro do Clássico — o erro certo da coisa errada'
        );

        $this->assertSame([], $this->escritasEDesvios(), 'saiu escrita ao Mercado Livre do reconciliador');
    }

    /**
     * ⚠️⚠️ **A CLÁUSULA DO PROTÓTIPO SÓ QUANDO É VERDADE**, com o literal **CONGELADO** aqui
     * (anti-tautologia de literal) e **iscas do quase-igual**.
     *
     * ⚠️ **O CASO PARCIAL É A ISCA INVERTIDA DE VERDADE:** Clássico `CREATED` + Premium `FAILED` na
     * MESMA publicação → a oferta do Clássico vai a **`publicado`** e a do Premium a `falha` **com**
     * a cláusula. ⚠️ **Mas aquela ponta é fraca por construção, e eu declaro:** a oferta do Clássico
     * nem tem `erro_publicacao` para comparar. ➡️ A ponta FORTE é o predicado público
     * {@see PublicacaoDoPortaoService::motivoComClausula()} medido nas **duas** direções, **mais** o
     * único caso real em que a falha acontece com `CREATED` do meu tipo: `CREATED` **sem MLB
     * legível** — o anúncio existe, o código não foi lido, e dizer *"nenhuma oferta foi criada"* ali
     * seria **mentira**.
     */
    public function test_a_clausula_entra_so_quando_nada_do_tipo_da_oferta_foi_criado_193_03(): void
    {
        // ── ⚠️ O LITERAL, por IGUALDADE, contra a expectativa CONGELADA ───────────────────
        $this->assertSame(self::CLAUSULA_CONGELADA, PublicacaoDoPortaoService::CLAUSULA_NADA_CRIADO, 'o literal do protótipo (`Aprovacao.dc.html:956`) foi reescrito no código');

        // ⚠️ ISCAS DO QUASE-IGUAL: nenhuma delas pode ser aceita como a cláusula.
        foreach ([
            'sinônimo' => 'Nenhum anúncio foi criado; a revisão foi preservada.',
            'sem ponto final' => 'Nenhuma oferta foi criada; a revisão foi preservada',
            'truncada' => 'Nenhuma oferta foi criada;',
            'sem cedilha' => 'Nenhuma oferta foi criada; a revisao foi preservada.',
            'com vírgula no lugar do ponto e vírgula' => 'Nenhuma oferta foi criada, a revisão foi preservada.',
        ] as $comoSeChama => $quaseIgual) {
            $this->assertNotSame($quaseIgual, PublicacaoDoPortaoService::CLAUSULA_NADA_CRIADO, "ISCA do quase-igual: `{$comoSeChama}` passou como se fosse a cláusula do protótipo");
        }

        // ── ⚠️⚠️ O PREDICADO, NAS DUAS DIREÇÕES ──────────────────────────────────────────
        $semCriados = PublicacaoDoPortaoService::motivoComClausula('O Mercado Livre não respondeu a tempo.', []);
        $comCriados = PublicacaoDoPortaoService::motivoComClausula('O Mercado Livre não respondeu a tempo.', ['um item CREATED do meu tipo']);

        $this->assertSame('O Mercado Livre não respondeu a tempo. ' . self::CLAUSULA_CONGELADA, $semCriados, 'sem nada criado, a cláusula não entrou');
        $this->assertSame('O Mercado Livre não respondeu a tempo.', $comCriados, 'com anúncio criado do meu tipo, a cláusula entrou e a mensagem MENTIU');

        // ⚠️ E o corte de `string(1000)` RESERVA a cláusula: erro gigante não a engole.
        $gigante = PublicacaoDoPortaoService::motivoComClausula(str_repeat('x', 5000), []);
        $this->assertLessThanOrEqual(PublicacaoDoPortaoService::LIMITE_ERRO_PUBLICACAO, mb_strlen($gigante), 'o texto passou do limite da coluna `erro_publicacao`');
        $this->assertStringEndsWith(self::CLAUSULA_CONGELADA, $gigante, 'o truncamento comeu a cláusula justamente no erro gigante');

        // ── O PARCIAL, de ponta a ponta: a cláusula numa oferta e `publicado` na outra ────
        [$company, , $admin] = $this->conta();
        $c = $this->cenario($company);
        $classico = $this->levarPara($c['classico'], PubAprovacaoOferta::PUBLICANDO, $admin);
        $premium = $this->levarPara($c['premium'], PubAprovacaoOferta::PUBLICANDO, $admin);

        $item = $this->itemDeFila($company, $c, PubFilaPublicacaoItem::PARCIAL);
        $p = $this->publicacao($c, PubPublicacao::PARTIALLY_PUBLISHED, [
            ['tipo' => self::CLASSICO, 'status' => PubPublicacaoItem::CREATED, 'mlb' => 'MLB444444444', 'indice' => 0],
            ['tipo' => self::PREMIUM, 'status' => PubPublicacaoItem::FAILED, 'indice' => 1, 'avisos' => ['mensagem' => 'O Mercado Livre recusou o anúncio Premium: categoria sem essa modalidade.']],
        ]);
        $item->update(['publicacao_id' => $p->id]);
        $classico->update(['fila_item_id' => $item->id]);
        $premium->update(['fila_item_id' => $item->id]);

        $resumo = $this->portao()->reconciliar();

        $this->assertSame(1, $resumo['publicado'], 'no PARCIAL a oferta que FOI criada não chegou a publicado');
        $this->assertSame(1, $resumo['falha'], 'no PARCIAL a oferta que NÃO foi criada não chegou a falha');
        $this->assertSame(PubAprovacaoOferta::PUBLICADO, $classico->fresh()->andamento);
        $this->assertSame('MLB444444444', $classico->fresh()->ml_item_id);
        $this->assertNull($classico->fresh()->erro_publicacao, 'a oferta publicada ficou com erro gravado');

        $erroDoPremium = (string) $premium->fresh()->erro_publicacao;
        $this->assertStringContainsString('categoria sem essa modalidade', $erroDoPremium, 'o erro REAL do Premium não foi guardado');
        $this->assertStringEndsWith(self::CLAUSULA_CONGELADA, $erroDoPremium, 'a cláusula não entrou na oferta que nada criou');

        // ── ⚠️⚠️ A ISCA INVERTIDA FORTE: `CREATED` do MEU tipo SEM MLB legível ───────────
        //
        // A oferta **não pode** ir a `publicado` (AND-04, sem MLB), mas o anúncio **FOI** criado:
        // a cláusula tem de ficar **FORA**. É o único caso real em que a falha convive com
        // `CREATED` do próprio tipo — e é ele que mata a mutação "concatenar a cláusula sempre".
        $c2 = $this->cenario($company, ['so_classico' => true]);
        $semCodigo = $this->levarPara($c2['classico'], PubAprovacaoOferta::PUBLICANDO, $admin);
        $item2 = $this->itemDeFila($company, $c2, PubFilaPublicacaoItem::PARCIAL, ['motivo' => 'O anúncio subiu, mas o Mercado Livre não devolveu o código.']);
        $p2 = $this->publicacao($c2, PubPublicacao::PARTIALLY_PUBLISHED, [
            ['tipo' => self::CLASSICO, 'status' => PubPublicacaoItem::CREATED, 'mlb' => null, 'indice' => 0],
        ]);
        $item2->update(['publicacao_id' => $p2->id]);
        $semCodigo->update(['fila_item_id' => $item2->id]);

        $this->portao()->reconciliar();

        $erro = (string) $semCodigo->fresh()->erro_publicacao;
        $this->assertSame(PubAprovacaoOferta::FALHA, $semCodigo->fresh()->andamento);
        $this->assertStringNotContainsString(self::CLAUSULA_CONGELADA, $erro, 'ISCA INVERTIDA: a cláusula disse que nada foi criado, e o anúncio do tipo da oferta FOI criado');
        $this->assertStringContainsString('não devolveu o código', $erro, 'o motivo REAL deste caso não foi guardado');

        $this->assertSame([], $this->escritasEDesvios(), 'saiu escrita ao Mercado Livre do reconciliador');
    }

    /**
     * ⚠️⚠️ **DECISÃO 24 DO USUÁRIO (21/10/2026): OS AVISOS DO MERCADO LIVRE ACEITOS VÃO PARA O
     * HISTÓRICO.**
     *
     * **O problema medido:** `PublicacaoService::iniciar()` recusa publicar com avisos do ML sem
     * `ciente`; a caixa *"Estou ciente"* **não existe** no portão (ela é da tela de **lote**); e
     * **nada** em `app/Services/Publicador/Aprovacao/` lê a `ConferenciaService`. ➡️ Tratar o clique
     * como ciência era, **para esses avisos**, uma ficção: publicava com aviso que o revisor **nunca
     * viu**. A decisão do usuário tira a ficção gravando **quais** avisos foram aceitos.
     *
     * ⚠️ **Mostrar os avisos no painel foi OFERECIDO e RECUSADO** (seria trabalho novo), e **barrar
     * a aprovação** é o 9º bloqueio, **já recusado** na decisão 21.
     *
     * ⚠️ **ISCA NAS DUAS DIREÇÕES:** conferência **sem** aviso → **nenhum** evento de aviso (senão o
     * histórico encheria de linha vazia); conferência **com** aviso → o evento nomeia **cada** um.
     */
    public function test_avisos_do_ml_aceitos_vao_para_o_historico_193_03(): void
    {
        [$company, , $admin] = $this->conta();

        // ── ISCA (−): sem aviso, NENHUM evento de aviso ───────────────────────────────────
        $limpo = $this->cenario($company, ['so_classico' => true]);
        $semAviso = $this->levarPara($limpo['classico'], PubAprovacaoOferta::APROVADO, $admin);

        $this->assertNotNull($semAviso->fresh()->fila_item_id, 'a fixture sem aviso nem entrou na fila');
        $this->assertFalse((bool) $semAviso->fresh()->filaItem->ciente, '`ciente` ficou verdadeiro sem aviso nenhum — a fila carimbaria ciência de nada');

        $comPrefixo = array_values(array_filter(
            $this->eventos($semAviso->fresh()),
            static fn (string $t) => str_contains($t, PublicacaoDoPortaoService::PREFIXO_AVISOS_ACEITOS)
        ));
        $this->assertSame([], $comPrefixo, 'nasceu evento de "avisos aceitos" numa conferência SEM avisos');

        // ── ISCA (+): com avisos, o evento NOMEIA cada um ─────────────────────────────────
        $c = $this->cenario($company, [
            'so_classico' => true,
            'avisos' => [
                ['regra' => 'V-REM-02', 'severidade' => Problema::AVISO, 'mensagem' => 'O SKU «RCL-9» já está em outro anúncio ativo desta conta (MLB123). Confira se não é o mesmo produto.', 'camada' => 'L3', 'alvo' => [], 'ml_causa' => ['code' => 'item.sku.duplicated']],
                ['regra' => 'V-FRT-01', 'severidade' => Problema::AVISO, 'mensagem' => 'O frete do Mercado Livre ficou acima do frete da Precificação.', 'camada' => 'L3', 'alvo' => [], 'ml_causa' => null],
                // ⚠️ Repetido de propósito: 12 cores com o mesmo aviso NÃO são 12 avisos.
                ['regra' => 'V-FRT-01', 'severidade' => Problema::AVISO, 'mensagem' => 'O frete do Mercado Livre ficou acima do frete da Precificação.', 'camada' => 'L3', 'alvo' => [], 'ml_causa' => null],
                // ⚠️ E o que NÃO é aviso não entra.
                ['regra' => 'V-INF-01', 'severidade' => Problema::INFO, 'mensagem' => 'A descrição foi normalizada.', 'camada' => 'L2', 'alvo' => [], 'ml_causa' => null],
            ],
        ]);

        $oferta = $this->levarPara($c['classico'], PubAprovacaoOferta::APROVADO, $admin);
        $item = $oferta->fresh()->filaItem;

        $this->assertNotNull($item, 'a oferta com avisos não entrou na fila — `ciente = true` deixou de valer e o laço sem saída voltou');
        $this->assertTrue((bool) $item->ciente, '`ciente` não ficou verdadeiro onde HOUVE aviso');
        $this->assertSame((int) $c['validacao']->id, (int) $item->validacao_id, 'o item não guardou a conferência de onde os avisos saem');

        $doAviso = array_values(array_filter(
            $this->eventos($oferta->fresh()),
            static fn (string $t) => str_starts_with($t, PublicacaoDoPortaoService::PREFIXO_AVISOS_ACEITOS)
        ));

        $this->assertCount(1, $doAviso, 'os avisos aceitos não geraram EXATAMENTE um evento no histórico');
        $this->assertStringContainsString('já está em outro anúncio ativo desta conta', $doAviso[0], 'o evento não nomeia o aviso do SKU');
        $this->assertStringContainsString('acima do frete da Precificação', $doAviso[0], 'o evento não nomeia o aviso de frete');
        $this->assertStringNotContainsString('A descrição foi normalizada', $doAviso[0], 'o que não é AVISO entrou no evento');

        // ⚠️⚠️ NENHUMA CHAVE TÉCNICA VAZA PARA A TELA — o histórico é texto de tela (DADO-10).
        //
        // ⚠️ ASSERÇÃO COLETORA, não uma por evento: o número de eventos depende de quantas vezes o
        // enfileiramento foi tentado, e ancorar nela é a família "âncora em número que muda
        // legitimamente" (o plano 193-02 mediu 26 → 27 sem defeito nenhum).
        $comJargao = [];
        foreach ($this->eventos($oferta->fresh()) as $texto) {
            foreach (['V-REM-02', 'V-FRT-01', 'item.sku.duplicated', 'ml_causa', 'severidade', 'WARNING', self::CLASSICO] as $jargao) {
                if (str_contains($texto, $jargao)) {
                    $comJargao[] = $jargao . ' em: ' . $texto;
                }
            }
        }
        $this->assertSame([], $comJargao, 'jargão técnico (id de regra, causa crua do ML ou chave de modalidade) foi para o histórico, que é texto de tela');

        // ⚠️ O predicado isolado, com as duas pontas medidas: a lista sai ÚNICA e só com AVISO.
        $this->assertSame(
            [
                'O SKU «RCL-9» já está em outro anúncio ativo desta conta (MLB123). Confira se não é o mesmo produto.',
                'O frete do Mercado Livre ficou acima do frete da Precificação.',
            ],
            PublicacaoDoPortaoService::avisosAceitos($item),
            'a leitura dos avisos duplicou, perdeu ordem ou deixou passar o que não é aviso'
        );

        // ⚠️ E o degrau do dado adverso: `issues` não-array não lança e não inventa texto.
        DB::table('pub_validacoes')->where('id', $c['validacao']->id)->update(['issues' => 'isto não é um json de lista']);
        $this->assertSame([], PublicacaoDoPortaoService::avisosAceitos($item->fresh()), '`issues` adverso quebrou a leitura dos avisos');

        $this->assertSame([], $this->escritasEDesvios(), 'saiu escrita ao Mercado Livre da aprovação');
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // (3) ⚠️⚠️ O ITEM EM VOO NÃO SE TOCA (RN-93), E O QUE SAIU DA FILA SEM PUBLICAR
    // ═════════════════════════════════════════════════════════════════════════════════════════

    /**
     * ⚠️⚠️ **RN-93: ITEM `PENDING`/`SENT`/`UNKNOWN` NUNCA É TOCADO** (T-193-23).
     *
     * `UNKNOWN` é timeout **depois** do envio: o anúncio **pode existir**. Movê-lo para `falha`
     * diria que nada foi criado quando talvez tenha sido, e reenviar duplicaria o anúncio.
     *
     * ⚠️⚠️ **A ISCA INVERTIDA É O QUE FAZ O CASO VALER:** a fixture tem um `UNKNOWN` **AO LADO DE UM
     * `CREATED` DO MESMO TIPO**. Sem o `CREATED` ao lado, *"a oferta não se moveu"* seria verdade por
     * não haver nada para mover — e o gate seria **vazio por construção**. Com ele, a única razão
     * para a oferta não ir a `publicado` é o `UNKNOWN` aberto. ➡️ Resolvido o `UNKNOWN`, ela vai.
     */
    public function test_item_em_voo_no_mercado_livre_nunca_e_tocado_com_isca_invertida_193_03(): void
    {
        [$company, , $admin] = $this->conta();
        $c = $this->cenario($company, ['so_classico' => true]);

        $oferta = $this->levarPara($c['classico'], PubAprovacaoOferta::PUBLICANDO, $admin);
        $item = $this->itemDeFila($company, $c, PubFilaPublicacaoItem::PARCIAL);
        $p = $this->publicacao($c, PubPublicacao::PARTIALLY_PUBLISHED, [
            ['tipo' => self::CLASSICO, 'status' => PubPublicacaoItem::CREATED, 'mlb' => 'MLB555555555', 'indice' => 0],
            ['tipo' => self::CLASSICO, 'status' => PubPublicacaoItem::UNKNOWN, 'indice' => 1, 'variante' => 'cor=azul'],
        ]);
        $item->update(['publicacao_id' => $p->id]);
        $oferta->update(['fila_item_id' => $item->id]);

        $antesDoItem = DB::table('pub_publicacao_itens')->where('publicacao_id', $p->id)->orderBy('indice')
            ->get(['status', 'ml_item_id', 'tentativas', 'enviado_em'])->map(fn ($l) => (array) $l)->all();
        $eventosAntes = $this->eventos($oferta->fresh());

        $resumo = $this->portao()->reconciliar();

        // ⚠️ NADA se move.
        $this->assertSame(PubAprovacaoOferta::PUBLICANDO, $oferta->fresh()->andamento, 'RN-93: a oferta se moveu com item em voo no Mercado Livre');
        $this->assertNull($oferta->fresh()->ml_item_id, 'RN-93: carimbou o MLB com item ainda em voo');
        $this->assertSame(0, $resumo['publicado'] + $resumo['falha'], 'RN-93: houve desfecho com item em voo');
        $this->assertSame(1, $resumo['esperando']);
        $this->assertSame($eventosAntes, $this->eventos($oferta->fresh()), 'RN-93: nasceu evento de histórico com item em voo');

        // ⚠️ NADA é reenviado: os itens da publicação estão byte a byte como estavam.
        $this->assertSame($antesDoItem, DB::table('pub_publicacao_itens')->where('publicacao_id', $p->id)->orderBy('indice')
            ->get(['status', 'ml_item_id', 'tentativas', 'enviado_em'])->map(fn ($l) => (array) $l)->all(), 'RN-93: o reconciliador MEXEU no item da publicação');
        $this->assertSame([], Http::recorded()->all(), 'RN-93: o reconciliador falou com o Mercado Livre');

        // ⚠️ E os TRÊS estados em voo barram igual — não só o `UNKNOWN`.
        foreach ([PubPublicacaoItem::PENDING, PubPublicacaoItem::SENT] as $emVoo) {
            DB::table('pub_publicacao_itens')->where('publicacao_id', $p->id)->where('indice', 1)->update(['status' => $emVoo]);
            $this->portao()->reconciliar();
            $this->assertSame(PubAprovacaoOferta::PUBLICANDO, $oferta->fresh()->andamento, "RN-93: `{$emVoo}` não barrou o desfecho");
        }

        // ── ⚠️⚠️ ISCA INVERTIDA: resolvido o item em voo, a oferta VAI a publicado ────────
        DB::table('pub_publicacao_itens')->where('publicacao_id', $p->id)->where('indice', 1)
            ->update(['status' => PubPublicacaoItem::FAILED]);

        $this->portao()->reconciliar();

        $this->assertSame(PubAprovacaoOferta::PUBLICADO, $oferta->fresh()->andamento, 'ISCA INVERTIDA: sem item em voo a oferta CONTINUOU parada — o gate estava verde por outro motivo');
        $this->assertSame('MLB555555555', $oferta->fresh()->ml_item_id);

        // ⚠️ `publicado` também não é editável.
        $this->assertFalse($oferta->fresh()->podeEditar(), 'oferta publicada ficou editável');
    }

    /**
     * ⚠️ **O item que saiu da fila SEM publicar** (`falhou`/`precisa_revisar`/`cancelado`/`pulado`)
     * **com a oferta ainda em `aprovado`**: o motivo REAL vai ao histórico, a oferta **não sai** de
     * `aprovado` (que é *"aguardando publicação"*, e é a verdade: ninguém publicou nada) e ela
     * **volta a ser enfileirável**.
     *
     * ⚠️ Passar por `publicando` aqui seria um estado que **mente** — a alternativa (c), recusada no
     * planejamento, numa máquina em que `publicando` bloqueia edição.
     *
     * ⚠️ **E a ligação é DESFEITA**, pelo invariante do plano 193-02: oferta que ainda espera
     * publicação **nunca** aponta para item fora de `VIVOS`, senão o painel leria as 4 colunas de
     * conferência de um item morto.
     */
    public function test_item_que_saiu_da_fila_sem_publicar_mantem_a_oferta_em_aprovado_193_03(): void
    {
        [$company, , $admin] = $this->conta();

        foreach (PubFilaPublicacaoItem::SEM_PUBLICAR as $status) {
            $c = $this->cenario($company, ['so_classico' => true]);
            $oferta = $this->levarPara($c['classico'], PubAprovacaoOferta::APROVADO, $admin);

            // ⚠️ A aprovação já enfileirou (gancho do 193-02): o item vivo dela é cancelado, e no
            // lugar entra o terminado — é exatamente a sequência da vida real.
            PubFilaPublicacaoItem::query()->whereKey($oferta->fresh()->fila_item_id)
                ->update(['status' => PubFilaPublicacaoItem::CANCELADO, 'produto_ativo' => null]);

            $morto = $this->itemDeFila($company, $c, $status, ['motivo' => "motivo REAL do item {$status}"]);
            $oferta->update(['fila_item_id' => $morto->id]);

            $this->portao()->reconciliar();

            $depois = $oferta->fresh();
            $this->assertSame(PubAprovacaoOferta::APROVADO, $depois->andamento, "o item `{$status}` tirou a oferta de aprovado");
            $this->assertNull($depois->fila_item_id, "a oferta continuou ligada ao item `{$status}`, que está fora de VIVOS");
            $this->assertContains(
                PublicacaoDoPortaoService::PREFIXO_SAIU_DA_FILA . "motivo REAL do item {$status}",
                $this->eventos($depois),
                "o motivo real do item `{$status}` não foi registrado no histórico"
            );

            // ⚠️ IDEMPOTÊNCIA: a 2ª passada tenta enfileirar de novo (é a 2ª chance), e **não**
            // duplica a linha do histórico.
            $eventos = $this->eventos($depois);
            $this->portao()->reconciliar();
            $this->assertSame($eventos, $this->eventos($oferta->fresh()), "a 2ª passada duplicou o histórico no item `{$status}`");
        }

        $this->assertSame([], $this->escritasEDesvios(), 'saiu escrita ao Mercado Livre do reconciliador');
    }

    /**
     * ⚠️ **Valor adverso vindo do banco não lança**, e **estado impossível é só observabilidade**.
     *
     * ⚠️ Em PHP, chave que não é string nem int **lança TypeError**, e `conta_snapshot`/`avisos`/
     * `resumo` são colunas JSON: valor adverso (string, nulo, lista) num `$x['k']` cru derrubaria a
     * passada do minuto inteira.
     *
     * ⚠️ **`publicando` com `fila_item_id` nulo é IMPOSSÍVEL POR CONSTRUÇÃO**, e isso é consequência
     * de uma DECISÃO: *"Tentar de novo"* **enfileira ANTES** de mover (plano 193-04), e este
     * reconciliador só move para `publicando` com item ligado. ➡️ Desenhar ramo de produto para ele
     * (re-enfileirar, ir a `falha`) seria dar comportamento a um estado que o desenho proíbe: a saída
     * é `Log::warning` e **nada mais**.
     */
    public function test_valor_adverso_do_banco_e_estado_impossivel_nao_quebram_a_passada_193_03(): void
    {
        [$company, , $admin] = $this->conta();

        // ── Estado impossível: `publicando` sem item ligado ──────────────────────────────
        $c1 = $this->cenario($company, ['so_classico' => true]);
        $orfa = $this->levarPara($c1['classico'], PubAprovacaoOferta::PUBLICANDO, $admin);
        $orfa->update(['fila_item_id' => null]);
        $eventosAntes = $this->eventos($orfa->fresh());

        // ── Valor adverso: `conta_snapshot` e `avisos` fora de forma ─────────────────────
        $c2 = $this->cenario($company, ['so_classico' => true]);
        $adversa = $this->levarPara($c2['classico'], PubAprovacaoOferta::PUBLICANDO, $admin);
        $item2 = $this->itemDeFila($company, $c2, PubFilaPublicacaoItem::FALHOU);
        $p2 = $this->publicacao($c2, PubPublicacao::FAILED, [
            ['tipo' => self::CLASSICO, 'status' => PubPublicacaoItem::FAILED, 'indice' => 0],
        ]);
        $item2->update(['publicacao_id' => $p2->id]);
        $adversa->update(['fila_item_id' => $item2->id]);

        DB::table('pub_publicacoes')->where('id', $p2->id)->update(['conta_snapshot' => 'isto não é objeto']);
        DB::table('pub_publicacao_itens')->where('publicacao_id', $p2->id)->update(['avisos' => '"uma string solta"']);
        DB::table('pub_fila_publicacao_itens')->where('id', $item2->id)->update(['resumo' => '[1,2,3]', 'motivo' => null]);

        // ── Sem modalidade legível: o alvo sumiu ────────────────────────────────────────
        $c3 = $this->cenario($company, ['so_classico' => true]);
        $semAlvo = $this->levarPara($c3['classico'], PubAprovacaoOferta::PUBLICANDO, $admin);
        $item3 = $this->itemDeFila($company, $c3, PubFilaPublicacaoItem::FALHOU, ['motivo' => 'x']);
        $semAlvo->update(['fila_item_id' => $item3->id]);
        // ⚠️ A modalidade fica ILEGÍVEL pelo próprio alvo, e **não** anulando `alvo_id`: aquela
        // coluna é `NOT NULL` + UNIQUE (medido na primeira rodada: `QueryException`), e a oferta é a
        // extensão 1:1 do alvo. O que o mundo real produz é o `listing_type_id` vazio/ilegível.
        DB::table('pub_rascunho_alvos')->where('id', $semAlvo->alvo_id)->update(['listing_type_id' => '']);

        $resumo = $this->portao()->reconciliar();

        // ⚠️ ZERO erros: nenhuma das três quebrou a passada.
        $this->assertSame(0, $resumo['erros'], 'valor adverso do banco (ou estado impossível) derrubou a passada do minuto');

        // O estado impossível: nada se moveu e nada foi registrado.
        $this->assertSame(PubAprovacaoOferta::PUBLICANDO, $orfa->fresh()->andamento);
        $this->assertNull($orfa->fresh()->fila_item_id);
        $this->assertSame($eventosAntes, $this->eventos($orfa->fresh()), 'o estado impossível virou ramo de produto e escreveu no histórico');

        // O adverso: foi a `falha` com o ÚLTIMO degrau do motivo (nada legível em lugar nenhum).
        $this->assertSame(PubAprovacaoOferta::FALHA, $adversa->fresh()->andamento);
        $this->assertStringContainsString(PublicacaoDoPortaoService::MOTIVO_SEM_CAUSA, (string) $adversa->fresh()->erro_publicacao);
        $this->assertStringEndsWith(self::CLAUSULA_CONGELADA, (string) $adversa->fresh()->erro_publicacao);

        // O sem modalidade: esperou, em vez de adivinhar a modalidade.
        $this->assertSame(PubAprovacaoOferta::PUBLICANDO, $semAlvo->fresh()->andamento, 'sem alvo legível a oferta foi movida às cegas');
        $this->assertNull($semAlvo->fresh()->erro_publicacao);

        $this->assertSame([], Http::recorded()->all(), 'o reconciliador tocou a rede');
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // (4) `publicando` E `publicado` BLOQUEIAM EDIÇÃO — PELOS DOIS LADOS
    // ═════════════════════════════════════════════════════════════════════════════════════════

    /**
     * ⚠️⚠️ **A TRAVA REAL SÃO AS ROTAS, e por isso elas são chamadas DIRETO, sem passar pela tela**
     * (T-193-26). `podeEditar()` é a regra; a tela só a obedece. Quem mandasse um POST na mão
     * publicaria coisa diferente da que foi conferida.
     *
     * ⚠️ Ancorado por **SEMÂNTICA** (o nome da rota e o `regra` da recusa), nunca por número de
     * linha: um gate ancorado em `'Servico:131'` quebrou quando outro dev deslocou a linha.
     */
    public function test_publicando_bloqueia_edicao_pelas_rotas_chamadas_direto_193_03(): void
    {
        [$company, , $admin] = $this->conta();
        $c = $this->cenario($company, ['so_classico' => true]);
        $oferta = $this->levarPara($c['classico'], PubAprovacaoOferta::PUBLICANDO, $admin);

        // ── Os DOIS níveis da regra, com isca: os três `live` são verdadeiros ─────────────
        $this->assertFalse($oferta->podeEditar(), '`publicando` ficou editável');
        $this->assertFalse($oferta->abreRevisao(), '`publicando` abre revisão editável');
        $this->assertFalse($oferta->ehLive());

        $vivos = [];
        foreach (PubAprovacaoOferta::LIVE as $live) {
            $sonda = new PubAprovacaoOferta(['andamento' => $live]);
            $vivos[$live] = $sonda->podeEditar() && $sonda->abreRevisao();
        }
        $this->assertSame(
            [PubAprovacaoOferta::AGUARDANDO_APROVACAO => true, PubAprovacaoOferta::EM_REVISAO_ECF => true, PubAprovacaoOferta::AJUSTES_SOLICITADOS => true],
            $vivos,
            'ISCA: a regra recusa TUDO — ela não discrimina nada'
        );

        // ── ⚠️ AS ROTAS DE ESCRITA, CHAMADAS DIRETO ─────────────────────────────────────
        $escritas = [
            'mlb.anuncios.meus.aprovacao.painel.preco' => ['preco' => 199.9],
            'mlb.anuncios.meus.aprovacao.painel.conferir-alteracao' => [],
            // ⚠️ Payload VÁLIDO de propósito: com corpo inválido a rota devolveria 422 da
            // VALIDAÇÃO, e o caso mediria o validador em vez da trava de edição — foi o que eu
            // medi na primeira rodada (`422 (sem regra)` nestas duas).
            'mlb.anuncios.meus.aprovacao.painel.frete' => ['origem' => 'digitado', 'valor' => 29.9],
            'mlb.anuncios.meus.aprovacao.painel.ajuste' => ['escopo' => 'oferta', 'item' => 'titulo', 'texto' => 'troque o título'],
            'mlb.anuncios.meus.aprovacao.painel.salvar-revisao' => [],
        ];

        $recusas = [];
        foreach ($escritas as $rota => $corpo) {
            $resposta = $this->actingAs($admin)
                ->postJson(route($rota, ['company' => $company->id, 'oferta' => $oferta->id]), $corpo);
            $recusas[$rota] = $resposta->status() . ' ' . (string) ($resposta->json('regra') ?? '(sem regra)');
        }

        // ⚠️ IGUALDADE DE CONJUNTO, nunca piso: perder uma rota deixaria as outras passando.
        $this->assertSame([
            'mlb.anuncios.meus.aprovacao.painel.preco' => '422 BLOQ-04-APROVAR-FORA-DO-AO-VIVO',
            'mlb.anuncios.meus.aprovacao.painel.conferir-alteracao' => '422 BLOQ-04-APROVAR-FORA-DO-AO-VIVO',
            'mlb.anuncios.meus.aprovacao.painel.frete' => '422 BLOQ-04-APROVAR-FORA-DO-AO-VIVO',
            'mlb.anuncios.meus.aprovacao.painel.ajuste' => '422 BLOQ-04-APROVAR-FORA-DO-AO-VIVO',
            'mlb.anuncios.meus.aprovacao.painel.salvar-revisao' => '422 BLOQ-04-APROVAR-FORA-DO-AO-VIVO',
        ], $recusas, 'alguma rota de escrita do painel aceitou a oferta EM VOO');

        // ⚠️ ISCA (+): a MESMA rota, com a oferta `live`, NÃO é recusada por estar fora do ao vivo —
        // sem isto, "tudo 422" seria indistinguível de "a rota recusa sempre".
        $viva = $this->cenario($company, ['so_classico' => true])['classico'];
        $aceita = $this->actingAs($admin)->postJson(
            route('mlb.anuncios.meus.aprovacao.painel.conferir-alteracao', ['company' => $company->id, 'oferta' => $viva->id]),
            []
        );
        // ⚠️ MEDIDO: com a oferta ao vivo a action **aceita** e responde `302` (`back()->with(...)`),
        // e `->json('regra')` numa resposta de redirecionamento **falha como asserção** ("a resposta
        // não é JSON válido") — a primeira redação deste caso reprovou por isso, não pelo invariante.
        // ➡️ A isca é o STATUS: recusa é `422`, aceitação é `302`.
        $this->assertSame(302, $aceita->status(), 'ISCA: a rota recusa a escrita até quando a oferta está ao vivo — "tudo 422" era indistinguível de "a rota recusa sempre"');

        $this->assertSame([], $this->escritasEDesvios(), 'as rotas de escrita do painel falaram com o Mercado Livre');
    }

    /**
     * ⚠️⚠️ **`publicado` É TERMINAL POR ESTRUTURA:** `TRANSICOES[PUBLICADO] === []`. Os **8**
     * destinos são enumerados e **todos** recusados, por **IGUALDADE DE CONJUNTO** — piso `>= N`
     * **não prova presença** (com 13 perguntas, perder uma deixava 12 e o piso passava).
     *
     * ⚠️⚠️ **E ESTA É A MEDIÇÃO QUE SUSTENTA A RECOMENDAÇÃO DA `PERGUNTA-193-B`** (editar a FOTO de
     * um anúncio **já no ar**, a decisão 13 do ROADMAP): o estado é terminal **por estrutura** e
     * **não existe NENHUM `PUT /items/{id}` em todo o Publicador**. ➡️ Trocar foto de anúncio no ar
     * é **fluxo novo** (transição saindo de um estado terminal + uma capacidade de escrita que o
     * sistema não tem), e **este plano NÃO o implementa**: a decisão é do **usuário**, e a
     * recomendação do planejamento é **diferir** para fase própria.
     */
    public function test_publicado_e_terminal_nos_oito_destinos_por_igualdade_de_conjunto_193_03(): void
    {
        [$company, , $admin] = $this->conta();
        $c = $this->cenario($company, ['so_classico' => true]);

        $oferta = $this->levarPara($c['classico'], PubAprovacaoOferta::PUBLICANDO, $admin);
        $oferta->update(['ml_item_id' => 'MLB666666666']);
        $oferta = app(AndamentoDaOfertaService::class)->mover($oferta->fresh(), PubAprovacaoOferta::PUBLICADO, $admin);

        $this->assertSame(PubAprovacaoOferta::PUBLICADO, $oferta->andamento);
        $this->assertSame([], PubAprovacaoOferta::TRANSICOES[PubAprovacaoOferta::PUBLICADO], '`publicado` deixou de ser terminal por estrutura');

        $destinos = array_keys(PubAprovacaoOferta::ANDAMENTOS);
        $this->assertCount(8, $destinos, 'a faixa de andamentos deixou de ter 8 estados — a enumeração deste gate ficou incompleta');

        $veredictos = [];
        foreach ($destinos as $destino) {
            try {
                app(AndamentoDaOfertaService::class)->mover($oferta->fresh(), $destino, $admin);
                $veredictos[$destino] = 'ACEITOU';
            } catch (RegraViolada $e) {
                $veredictos[$destino] = $e->regra;
            }
        }

        $this->assertSame(
            array_fill_keys($destinos, AndamentoDaOfertaService::REGRA_TRANSICAO_ILEGAL),
            $veredictos,
            'saiu transição de um estado TERMINAL — é o fluxo que a PERGUNTA-193-B registra como inexistente'
        );

        // ⚠️ E a oferta publicada não se moveu nem um byte.
        $this->assertSame(PubAprovacaoOferta::PUBLICADO, $oferta->fresh()->andamento);
        $this->assertSame('MLB666666666', $oferta->fresh()->ml_item_id);

        // ⚠️⚠️ **NÃO EXISTE `PUT /items/{id}` EM TODO O PUBLICADOR** — a medição que a
        // PERGUNTA-193-B cita, feita AQUI e não copiada de briefing.
        //
        // ⚠️⚠️ **E A MINHA PRIMEIRA SONDA ERA MAIS LARGA QUE O INVARIANTE — MEDIDO, não suposto.**
        // Varrer `'PUT'` acusou **quatro** arquivos legítimos de `Alavancas/` (`AlterarCampanha`,
        // `AlterarCupom`, `AlterarNoConvite` e a constante `METODOS` do `RequisicaoMl`), que fazem
        // `PUT /seller-promotions/*` — promoção, não anúncio. É a família *"gate mais largo que o
        // invariante"*, com **quatorze** ocorrências nesta milestone e **uma que barrou um deploy**.
        // ➡️ O invariante de verdade é **"nenhum PUT cujo CAMINHO começa em `/items`"**.
        //
        // ⚠️ E a âncora é **o começo do caminho**, não o substring: `/seller-promotions/items/`
        // **contém** `/items/`, então uma sonda por substring acusaria a promoção de convite.
        $caminhosPut = [];
        $formasSoltas = [];
        foreach (self::arquivosPhpDe(base_path('app/Services/Publicador')) as $arquivo) {
            $limpo = self::semComentarios((string) file_get_contents($arquivo));

            // ⚠️ O caminho tem de começar em `/`: sem isso a sonda casa a LISTA DE MÉTODOS do
            // `RequisicaoMl` (`['GET', 'POST', 'PUT', 'DELETE']`) e devolve `DELETE` como se fosse
            // um endpoint — medido na segunda rodada deste arquivo.
            if (preg_match_all("/'PUT'\s*,\s*'(\/[^']*)'/", $limpo, $m) > 0) {
                foreach ($m[1] as $caminho) {
                    $caminhosPut[] = $caminho;
                }
            }

            // Qualquer outra forma de PUT (cliente HTTP direto) — aqui não deve existir nenhuma.
            if (preg_match_all('/Http::put\(|->put\(\s*[\'"]/', $limpo) > 0) {
                $formasSoltas[] = str_replace(base_path() . DIRECTORY_SEPARATOR, '', $arquivo);
            }
        }
        $caminhosPut = array_values(array_unique($caminhosPut));
        sort($caminhosPut);

        // ⚠️ ISCA INVERTIDA: a sonda **NÃO** está cega — ela acha os PUT que de fato existem.
        $this->assertNotSame([], $caminhosPut, 'a sonda de PUT não achou NADA: ou a forma do projeto mudou, ou "não existe PUT /items" é verde por cegueira');
        $this->assertSame(['/seller-promotions/items/', '/seller-promotions/promotions/'], $caminhosPut, 'nasceu (ou mudou) um PUT no Publicador: a PERGUNTA-193-B precisa ser remedida');
        $this->assertSame([], array_values(array_filter($caminhosPut, static fn (string $c) => str_starts_with($c, '/items'))), 'nasceu `PUT /items/{id}`: a capacidade de trocar foto de anúncio no ar passou a existir, e a recomendação da PERGUNTA-193-B mudou');
        $this->assertSame([], $formasSoltas, 'apareceu PUT por cliente HTTP direto no Publicador');

        // ⚠️ ISCA (+) da sonda e (−) da âncora, nas duas direções, contra texto sintético.
        $this->assertSame(1, preg_match("/'PUT'\s*,\s*'(\/[^']*)'/", "new RequisicaoMl('PUT', '/items/MLB123', [], [])", $sintetico), 'ISCA (+): a sonda não casaria um PUT /items escrito na forma do projeto');
        // ⚠️ ISCA (−) da barra: a lista de métodos NÃO pode ser lida como endpoint.
        $this->assertSame(0, preg_match("/'PUT'\s*,\s*'(\/[^']*)'/", "private const METODOS = ['GET', 'POST', 'PUT', 'DELETE'];"), 'ISCA (−): a sonda lê a lista de métodos como se fosse caminho');
        $this->assertTrue(str_starts_with($sintetico[1], '/items'), 'ISCA (+): a âncora não classificaria `/items/MLB123` como anúncio');
        $this->assertFalse(str_starts_with('/seller-promotions/items/', '/items'), 'ISCA (−): a âncora acusaria a promoção de convite, que CONTÉM `/items/`');
    }

    /** @return list<string> */
    private static function arquivosPhpDe(string $raiz): array
    {
        $achados = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($raiz, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $arquivo) {
            if ($arquivo->isFile() && $arquivo->getExtension() === 'php') {
                $achados[] = $arquivo->getPathname();
            }
        }
        sort($achados);

        return $achados;
    }

    /**
     * ⚠️ **O toast *"Publicado e confirmado"* é asserção de AUSÊNCIA enquanto não há MLB.**
     *
     * A oferta em `publicando` **não** tem MLB e **não** pode ter o vocabulário de publicada no
     * payload do painel. ⚠️ E o toast **nunca dispara sozinho** nesta fase: a confirmação chega
     * minutos depois, pelo scheduler, e **não há polling** — é a `PERGUNTA-193-C`, de fase futura.
     */
    public function test_mover_para_publicado_sem_mlb_e_no_op_completo_e_sem_toast_193_03(): void
    {
        [$company, , $admin] = $this->conta();
        $c = $this->cenario($company, ['so_classico' => true]);
        $oferta = $this->levarPara($c['classico'], PubAprovacaoOferta::PUBLICANDO, $admin);

        $this->assertFalse($oferta->podeSerPublicado(), '`podeSerPublicado()` disse sim sem MLB');

        // ⚠️ E com MLB em BRANCO também não: `trim()` antes, não `!== null`.
        foreach (['', '   '] as $vazio) {
            $oferta->update(['ml_item_id' => $vazio]);
            $this->assertFalse($oferta->fresh()->podeSerPublicado(), 'MLB em branco passou como confirmado');
        }
        $oferta->update(['ml_item_id' => null]);

        $linhaAntes = (array) DB::table('pub_aprovacao_ofertas')->where('id', $oferta->id)->first();
        $eventosAntes = $this->eventos($oferta->fresh());

        try {
            app(AndamentoDaOfertaService::class)->mover($oferta->fresh(), PubAprovacaoOferta::PUBLICADO, $admin);
            $this->fail('a porta aceitou `publicado` sem MLB');
        } catch (RegraViolada $e) {
            $this->assertSame(AndamentoDaOfertaService::REGRA_SEM_MLB, $e->regra);
        }

        // ⚠️ NO-OP COMPLETO: a linha inteira, byte a byte — inclusive `updated_at`.
        $this->assertSame($linhaAntes, (array) DB::table('pub_aprovacao_ofertas')->where('id', $oferta->id)->first(), 'a recusa sem MLB gravou algo');
        $this->assertSame($eventosAntes, $this->eventos($oferta->fresh()), 'a recusa sem MLB registrou evento');

        // ── ⚠️ O TOAST DE PUBLICADA, AUSENTE do payload da oferta em voo ─────────────────
        $painel = app(PainelDeRevisaoService::class)->para(
            $oferta->fresh(),
            app(AndamentoDaOfertaService::class)->filaDaConta((int) $company->id)
        );
        $comoTexto = json_encode($painel, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $this->assertIsString($comoTexto);
        $this->assertStringNotContainsString('Publicado e confirmado', $comoTexto, 'o payload da oferta EM VOO já fala de publicação confirmada');
        $this->assertStringNotContainsString('MLB', $comoTexto, 'apareceu MLB no payload de uma oferta sem MLB confirmado');

        // ⚠️ ISCA: a sonda do payload NÃO está cega — com a oferta publicada o vocabulário aparece.
        $oferta->update(['ml_item_id' => 'MLB777777777']);
        $publicada = app(AndamentoDaOfertaService::class)->mover($oferta->fresh(), PubAprovacaoOferta::PUBLICADO, $admin);
        $this->assertStringContainsString('MLB777777777', (string) json_encode(
            app(PainelDeRevisaoService::class)->para($publicada, app(AndamentoDaOfertaService::class)->filaDaConta((int) $company->id)),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        ), 'ISCA: o payload não mostra o MLB nem quando a oferta ESTÁ publicada — a sonda de ausência era vazia');
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // (5) ⚠️⚠️ PUB-06 POR NÃO-REGRESSÃO — LIDO SEM COMENTÁRIO, POR CONTAGEM DE OCORRÊNCIAS
    // ═════════════════════════════════════════════════════════════════════════════════════════

    /**
     * ⚠️⚠️ **ESTA FASE NÃO REIMPLEMENTA NADA DA DISCIPLINA DE ESCRITA NO MERCADO LIVRE** — ela já
     * está em `PublicacaoService`/`PublicarRascunhoJob`, **em produção**. Este caso prova
     * **não-regressão**, e a FORMA importa:
     *
     *   · **código SEM COMENTÁRIO** (`token_get_all`), porque os docblocks daqueles arquivos
     *     **citam** `SENT`, `UNKNOWN` e `high` ao explicá-los — um gate que lesse comentário
     *     **reprovaria pelo próprio conserto** (abortou TRÊS mutações da wave 187-04);
     *   · **CONTAGEM DE OCORRÊNCIAS** (`substr_count`), nunca `preg_match(...) === 1` (que passa
     *     **com a linha de código apagada** quando o texto também existe em comentário) e **nunca
     *     `grep -c`**, que conta **LINHAS** (já deu 12 e 724 para uma âncora de uma ocorrência);
     *   · **lista CONSTANTE** de arquivos com `test -e` em cada um (são **dezoito** caminhos
     *     fantasma nesta milestone) e **UMA** asserção coletora — uma por arquivo faria a contagem
     *     subir com a lista.
     */
    public function test_a_disciplina_do_pub06_continua_de_pe_no_codigo_sem_comentario_193_03(): void
    {
        $arquivos = [
            'servico' => 'app/Services/Publicador/PublicacaoService.php',
            'job' => 'app/Jobs/Publicador/PublicarRascunhoJob.php',
            'contas' => 'app/Support/Publicador/ContasLiberadas.php',
        ];

        foreach ($arquivos as $rel) {
            $this->assertFileExists(base_path($rel), "caminho fantasma no gate do PUB-06: {$rel}");
        }

        $limpo = [];
        foreach ($arquivos as $apelido => $rel) {
            $limpo[$apelido] = self::semComentarios((string) file_get_contents(base_path($rel)));
        }

        $this->assertGreaterThan(600, substr_count($limpo['servico'], "\n"), 'gate sobre quase nada: o leitor não leu o serviço de publicação');

        // ⚠️ UMA asserção COLETORA sobre os invariantes, cada um com a contagem MÍNIMA de
        // ocorrências no código sem comentário. ⚠️ Mínima e não exata: o número de chamadas de uma
        // mesma guarda cresce legitimamente quando alguém a aplica num caminho novo, e ancorar no
        // número exato é a família "âncora em número que muda legitimamente".
        $invariantes = [
            // trava de conta, fail-closed, ANTES de qualquer gravação (as duas chamadas medidas)
            'ContasLiberadas::exigir no serviço' => substr_count($limpo['servico'], 'ContasLiberadas::exigir(') >= 2,
            'ContasLiberadas é fail-closed' => substr_count($limpo['contas'], 'exigir(') >= 1,
            // o item gravado SENT ANTES do POST
            'o item vai a SENT no serviço' => substr_count($limpo['servico'], 'PubPublicacaoItem::SENT') >= 3,
            // 4xx → FAILED, 5xx/rede → UNKNOWN
            'FAILED existe no serviço' => substr_count($limpo['servico'], 'PubPublicacaoItem::FAILED') >= 3,
            'UNKNOWN existe no serviço' => substr_count($limpo['servico'], 'PubPublicacaoItem::UNKNOWN') >= 4,
            // RN-93: UNKNOWN só reconciliado depois da janela
            'a janela de reconciliar é consultada' => substr_count($limpo['servico'], 'reconciliar_apos_segundos') >= 1,
            // sem reenvio por conta própria: trava por publicação + retryUntil
            'a trava por publicação existe' => substr_count($limpo['job'], 'publicador:publicacao:') >= 1,
            'retryUntil existe no job' => substr_count($limpo['job'], 'function retryUntil(') >= 1,
            // ⚠️ a fila é `high`, e isso JÁ ERA assim: a razão é a medição de 16/09/2026 (179 jobs
            // de acervo na `default` seguraram um webhook por horas). Declarar, não mudar.
            'o job vai para a fila high' => substr_count($limpo['job'], "onQueue('high')") >= 1,
        ];

        $caidos = array_keys(array_filter($invariantes, static fn (bool $ok) => ! $ok));
        $this->assertSame([], $caidos, 'PUB-06: a disciplina de escrita no Mercado Livre REGREDIU em `PublicacaoService`/`PublicarRascunhoJob`');

        // ⚠️⚠️ A PROVA DE QUE A SONDA LÊ, E NÃO SÓ DIZ QUE LEU: a contagem no texto CRU é MAIOR
        // que no limpo para um termo que os docblocks citam — é o que separa "o programa faz" de
        // "a prosa diz". Sem esta ponta, as contagens acima poderiam estar lendo comentário.
        $cru = (string) file_get_contents(base_path($arquivos['job']));
        $this->assertGreaterThan(
            substr_count($limpo['job'], 'SENT'),
            substr_count($cru, 'SENT'),
            'o leitor sem comentário não removeu nada: ou o docblock deixou de citar `SENT`, ou a limpeza não está limpando'
        );

        // ⚠️ E o reconciliador desta fase NÃO fala com o Mercado Livre nem escreve `andamento`:
        // contagem de ocorrências no código sem comentário do arquivo que ESTE plano escreve.
        $portao = self::semComentarios((string) file_get_contents(
            base_path('app/Services/Publicador/Aprovacao/PublicacaoDoPortaoService.php')
        ));
        $this->assertSame(0, substr_count($portao, "'andamento' =>"), 'o serviço do portão passou a escrever `andamento` — a única porta deixou de ser única');
        // ⚠️⚠️ **A MINHA PRIMEIRA SONDA DE REDE ERA LARGA DEMAIS — MEDIDO: ela deu 7.** `->get(` é
        // Eloquent/Collection neste arquivo (`->get(['id', ...])`, `$porAlvo->get(...)`,
        // `groupBy(...)->get($tipo)`), e não HTTP. Um gate assim reprovaria **pelo próprio
        // trabalho correto**, que é a família de defeito que esta milestone já pagou caro.
        // ➡️ O invariante de verdade: **nem o facade HTTP, nem o cliente do ML, nem `dispatch()`**.
        $rede = ['Http::', 'ClienteMlPublicador', 'dispatch('];
        $achados = [];
        foreach ($rede as $forma) {
            if (substr_count($portao, $forma) > 0) {
                $achados[] = $forma;
            }
        }
        $this->assertSame([], $achados, 'o serviço do portão passou a falar com a rede ou a despachar job');
        // ⚠️ ISCA: a sonda NÃO está cega — ela acha as três formas quando elas existem.
        $sintetica = 'Http::get($u); app(ClienteMlPublicador::class); PublicarRascunhoJob::dispatch(1);';
        $this->assertSame($rede, array_values(array_filter($rede, static fn (string $f) => substr_count($sintetica, $f) > 0)), 'ISCA: a sonda de rede não pega as formas que ela existe para pegar');
        // ⚠️ **IGUALDADE DE CONJUNTO DOS DESTINOS, não contagem de chamadas:** o número de
        // call-sites de `mover()` muda legitimamente (medido: 3, e eu havia escrito um piso de 4 —
        // âncora em número que muda). O invariante é *"as TRÊS transições desta fase passam pela
        // porta"*, e perder uma é o que tem de reprovar.
        preg_match_all('/->mover\(\s*\$[A-Za-z]+(?:->fresh\(\))?\s*,\s*PubAprovacaoOferta::([A-Z_]+)/', $portao, $m);
        $destinos = array_values(array_unique($m[1] ?? []));
        sort($destinos);
        $this->assertSame(['FALHA', 'PUBLICADO', 'PUBLICANDO'], $destinos, 'alguma transição do reconciliador deixou de passar pela única porta (ou nasceu uma transição nova)');

        // ⚠️⚠️ O CICLO DE CONSTRUTOR, MEDIDO: a porta recebe o portão; o portão **não** pode receber
        // a porta, senão o container recursa até estourar a memória (medido: `Container.php:864`,
        // `exit 255`). ⚠️ Com controle POSITIVO, senão isto seria "não achei nada".
        $daPorta = (new \ReflectionClass(AndamentoDaOfertaService::class))->getConstructor()?->getParameters() ?? [];
        $doPortao = (new \ReflectionClass(PublicacaoDoPortaoService::class))->getConstructor()?->getParameters() ?? [];
        $tipos = static fn (array $ps) => array_values(array_map(static fn (\ReflectionParameter $p) => (string) $p->getType(), $ps));

        $this->assertContains(PublicacaoDoPortaoService::class, $tipos($daPorta), 'controle positivo: a única porta deixou de receber o serviço do portão');
        $this->assertNotContains(AndamentoDaOfertaService::class, $tipos($doPortao), 'o construtor do portão passou a receber a única porta — é ciclo, e o container do Laravel NÃO o detecta: ele estoura a memória');
    }

    /**
     * ⚠️ **Conta NÃO liberada não publica — `ContasLiberadas` é FAIL-CLOSED (lista vazia =
     * NINGUÉM)**, e o motivo chega ao histórico em vez de virar silêncio.
     *
     * ⚠️ **Nenhum feature-flag novo nesta fase:** a trava de *"ligar publicar"* é esta lista, que é a
     * mesma que o PUB-06 exige. Um segundo interruptor faria **duas verdades** sobre quem pode
     * publicar.
     */
    public function test_conta_nao_liberada_nao_publica_e_o_motivo_chega_ao_historico_193_03(): void
    {
        [$company, , $admin] = $this->conta();
        $c = $this->cenario($company, ['so_classico' => true]);

        // ⚠️ FAIL-CLOSED: a lista fica VAZIA, e ninguém publica.
        config(['publicador.contas_liberadas.companies' => [], 'publicador.contas_liberadas.mlb_empresas' => []]);

        $oferta = $this->levarPara($c['classico'], PubAprovacaoOferta::APROVADO, $admin);

        $this->assertNull($oferta->fresh()->fila_item_id, 'conta NÃO liberada entrou na fila de publicação');
        $this->assertSame(0, PubFilaPublicacaoItem::query()->where('produto_id', $c['produto']->id)->count());
        $this->assertSame(PubAprovacaoOferta::APROVADO, $oferta->fresh()->andamento);

        $daRecusa = array_values(array_filter(
            $this->eventos($oferta->fresh()),
            static fn (string $t) => str_starts_with($t, PublicacaoDoPortaoService::PREFIXO_NAO_ENFILEIRADA)
        ));
        $this->assertCount(1, $daRecusa, 'a recusa da conta travada foi engolida em silêncio (ou duplicada)');
        $this->assertStringContainsString('liberada', $daRecusa[0], 'o motivo registrado não é o da conta não liberada');

        // ⚠️ E o reconciliador tenta de novo a cada minuto SEM encher o histórico (idempotência).
        $this->portao()->reconciliar();
        $this->portao()->reconciliar();
        $this->assertSame($daRecusa, array_values(array_filter(
            $this->eventos($oferta->fresh()),
            static fn (string $t) => str_starts_with($t, PublicacaoDoPortaoService::PREFIXO_NAO_ENFILEIRADA)
        )), 'o reconciliador duplicou a recusa no histórico — 1.440 linhas iguais por dia');

        // ── ⚠️ ISCA: liberada de novo, ela entra ────────────────────────────────────────
        config(['publicador.contas_liberadas.companies' => [(int) $company->id]]);
        $this->portao()->reconciliar();

        $this->assertNotNull($oferta->fresh()->fila_item_id, 'ISCA: a conta voltou a ser liberada e a oferta NÃO entrou — a recusa não era da trava de conta');

        // ⚠️ Nada disso tocou o Mercado Livre em ESCRITA, e nenhum job de publicar foi despachado
        // pelo reconciliador (quem publica é o `AgendadorDaFila`, na fila `high`).
        $this->assertSame([], $this->escritasEDesvios());
        $this->assertSame('high', (new PublicarRascunhoJob(1))->queue, 'a fila do job de publicar mudou — ver a medição de 16/09/2026');
        $this->assertTrue(class_exists(PublicacaoService::class) && class_exists(MlbPublicadorAprovacaoController::class));
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // (6) A BIJEÇÃO ids ↔ MÉTODOS ↔ `<verify>`, COM EXPANSOR DE ALTERNÂNCIA
    // ═════════════════════════════════════════════════════════════════════════════════════════

    /**
     * ⚠️⚠️ **MÉTODO SEM id NUNCA RODA** (foi o sobrevivente da mutação do plano 186-03), e **id
     * órfão não falha: ele simplesmente nunca roda**.
     *
     * ⚠️⚠️ **O EXPANSOR DE ALTERNÂNCIA, ISCADO NAS DUAS DIREÇÕES ANTES DE VALER:** a regex ingênua
     * `/test_[A-Za-z0-9_]+/` casa **ZERO** em `test_(a|b|c)` — o furo que deu `SEM_ID=14` **acusando
     * trabalho correto**.
     *
     * ⚠️ E o **homônimo**: o `--filter` do PHPUnit é regex **NÃO ANCORADO**, e as Fases 186/187/188
     * estão **em produção**. Método de outra fase que seja superstring de um meu faria o id SOMAR.
     */
    public function test_o_manifesto_tem_bijecao_com_os_metodos_e_nenhum_id_orfao_193_03(): void
    {
        $manifesto = base_path('tests/gates/contagens-193-03.txt');
        $this->assertFileExists($manifesto, 'o manifesto deste plano não existe — o harness ABORTA sem ele');

        $linhas = array_values(array_filter(
            array_map('trim', explode("\n", (string) file_get_contents($manifesto))),
            static fn (string $l) => $l !== '' && ! str_starts_with($l, '#')
        ));
        $this->assertGreaterThanOrEqual(6, count($linhas), 'o manifesto tem menos ids do que este plano declara');

        // ─── O EXPANSOR, iscado NAS DUAS DIREÇÕES antes de valer ────────────────────────
        $expandir = static function (string $filtro): array {
            if (preg_match('/^test_\((.+)\)$/', $filtro, $m) === 1) {
                return array_map(static fn (string $p) => 'test_' . $p, explode('|', $m[1]));
            }

            return [$filtro];
        };
        $this->assertSame(['test_a', 'test_b', 'test_c'], $expandir('test_(a|b|c)'), 'ISCA (+): o expansor não abre a alternância');
        $this->assertSame(['test_abc'], $expandir('test_abc'), 'ISCA (−): o expansor inventou nomes num filtro sem alternância');
        // ⚠️ E a prova de que a regex INGÊNUA é cega — medida, não citada de briefing.
        $this->assertSame(0, preg_match_all('/test_[A-Za-z0-9_]+/', 'test_(a|b|c)', $cegos), 'a regex ingênua passou a casar alternância: a razão do expansor mudou');
        $this->assertSame(1, preg_match_all('/test_[A-Za-z0-9_]+/', 'test_abc'), 'a regex ingênua deixou de casar nome simples: a isca perdeu o sujeito');

        // ─── id → método ────────────────────────────────────────────────────────────────
        $doManifesto = [];
        $ids = [];
        foreach ($linhas as $linha) {
            $partes = preg_split('/\s+::\s+/', $linha);
            $cabeca = preg_split('/\s+/', trim((string) $partes[0]));
            $ids[] = $cabeca[0];

            if (($cabeca[1] ?? '') !== 'php') {
                continue;
            }

            $metodos = $expandir(trim((string) $cabeca[2]));
            $this->assertLessThanOrEqual(3, count($metodos), "o id `{$cabeca[0]}` tem mais de TRÊS métodos: alternância grande equivale a filtro de classe e passa dos 120 s");

            $esperado = (int) (preg_match('/tests=(\d+)/', (string) ($partes[1] ?? ''), $m) === 1 ? $m[1] : 0);
            $this->assertSame(count($metodos), $esperado, "o id `{$cabeca[0]}` declara `tests={$esperado}` com " . count($metodos) . ' método(s) no filtro');

            foreach ($metodos as $metodo) {
                $doManifesto[] = $metodo;
            }
        }

        $this->assertSame(array_values(array_unique($ids)), $ids, 'id repetido no manifesto');

        $daClasse = array_values(array_filter(
            get_class_methods($this),
            static fn (string $m) => str_starts_with($m, 'test_')
        ));
        sort($daClasse);
        $doManifestoOrdenado = array_values(array_unique($doManifesto));
        sort($doManifestoOrdenado);

        // ⚠️ MÉTODO SEM id (nunca roda) e id ÓRFÃO (nunca roda), nos DOIS sentidos.
        $this->assertSame($daClasse, $doManifestoOrdenado, 'bijeção quebrada: há método sem id no manifesto (ele NUNCA roda) ou id apontando para método que não existe (ele também nunca roda)');

        // ⚠️ SUFIXO `_193_03` em TODO método, por asserção COLETORA.
        $semSufixo = array_values(array_filter($daClasse, static fn (string $m) => ! str_ends_with($m, '_193_03')));
        $this->assertSame([], $semSufixo, 'método sem o sufixo `_193_03`: o `--filter` do PHPUnit é regex NÃO ANCORADO e as Fases 186/187/188 estão em produção');

        // ─── HOMÔNIMO em toda a suíte, com isca nas duas direções ──────────────────────
        $homonimos = [];
        foreach (self::arquivosPhpDe(base_path('tests')) as $arquivo) {
            if (! str_ends_with($arquivo, 'Test.php') || str_contains($arquivo, 'ReconciliarPublicacaoDoPortaoTest')) {
                continue;
            }
            $texto = (string) file_get_contents($arquivo);
            foreach ($daClasse as $meu) {
                if (preg_match('/function\s+([A-Za-z0-9_]*' . preg_quote($meu, '/') . '[A-Za-z0-9_]*)\s*\(/', $texto, $m) === 1) {
                    $homonimos[] = basename($arquivo) . '::' . $m[1];
                }
            }
        }
        $this->assertSame([], $homonimos, 'há método de outra suíte que o `--filter` NÃO ANCORADO deste plano também casaria');

        // ⚠️ ISCA (+): o varredor de homônimo PEGA uma superstring de um nome meu.
        $this->assertSame(1, preg_match('/function\s+([A-Za-z0-9_]*' . preg_quote('test_avisos_do_ml_aceitos_vao_para_o_historico_193_03', '/') . '[A-Za-z0-9_]*)\s*\(/', 'public function test_avisos_do_ml_aceitos_vao_para_o_historico_193_03_extra() {}'), 'ISCA (+): o varredor de homônimo não pega superstring');
        // ⚠️ ISCA (−): e NÃO acusa um nome REAL de outra fase.
        $this->assertSame(0, preg_match('/function\s+([A-Za-z0-9_]*' . preg_quote('test_avisos_do_ml_aceitos_vao_para_o_historico_193_03', '/') . '[A-Za-z0-9_]*)\s*\(/', 'public function test_aprovar_e_enfileirar_188_05() {}'), 'ISCA (−): o varredor acusa nome legítimo de outra fase');

        // ─── id → `<verify>` do plano: id órfão de `<verify>` nunca roda ──────────────
        $plano = base_path('.planning/phases/193-do-aprovado-ao-ar-o-portao-fecha-o-ciclo/193-03-PLAN.md');
        $this->assertFileExists($plano);
        $texto = (string) file_get_contents($plano);
        $foraDoVerify = array_values(array_filter(
            $ids,
            static fn (string $id) => ! str_contains($texto, '193-03 ' . $id) && ! str_starts_with($id, 'bijecao-')
        ));
        $this->assertSame([], $foraDoVerify, 'id do manifesto que o `<verify>` do plano nunca invoca: ele não falha, ele simplesmente NUNCA RODA');
    }
}
