<?php

namespace Tests\Feature\Publicador\Aprovacao;

use App\Models\Company;
use App\Models\MlbEmpresa;
use App\Models\MlToken;
use App\Models\PubAprovacaoEvento;
use App\Models\PubAprovacaoOferta;
use App\Models\PubFilaPublicacao;
use App\Models\PubFilaPublicacaoItem;
use App\Models\PubProduto;
use App\Models\PubRascunho;
use App\Models\PubRascunhoAlvo;
use App\Models\PubValidacao;
use App\Models\User;
use App\Services\Publicador\Aprovacao\AndamentoDaOfertaService;
use App\Services\Publicador\Aprovacao\PublicacaoDoPortaoService;
use App\Services\Publicador\ConferenciaService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
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

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-21 11:30:00');

        // ⚠️ De pé em TODOS os casos: é ele que transforma "o enfileiramento é só banco" de
        // afirmação de docblock em medição.
        Http::preventStrayRequests();

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
        foreach ($this->eventos($classico) as $texto) {
            $this->assertStringNotContainsString(self::PREMIUM, $texto, 'o jargão `gold_pro` foi para o histórico, que é texto de tela');
        }

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

    // ─────────────────────────────────────────────────────────────────────────────────────────
    // Apoio dos casos
    // ─────────────────────────────────────────────────────────────────────────────────────────

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
