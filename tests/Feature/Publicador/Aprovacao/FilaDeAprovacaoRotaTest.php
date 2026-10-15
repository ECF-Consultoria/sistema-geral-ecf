<?php

namespace Tests\Feature\Publicador\Aprovacao;

use App\Http\Controllers\MlbAnuncioController;
use App\Http\Controllers\MlbPublicadorAprovacaoController;
use App\Models\Company;
use App\Models\MlbEmpresa;
use App\Models\MlToken;
use App\Models\PubAprovacaoOferta;
use App\Models\PubProduto;
use App\Models\PubRascunho;
use App\Models\PubRascunhoAlvo;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
// ⚠️ Plano 187-04: a asserção de COMPONENTE RESOLVIDO (a pendência nomeada pelo 187-02) lê o
// componente direto do objeto de página e o bundle direto do resolvedor do Vite. ⚠️ NEM
// `AssertableInertia` (medido: reprova esta resposta com "Not a valid Inertia response") NEM
// `Inertia::render` numa rota sintética (medido: a isca por rota NÃO quebrou) entram aqui.
use Tests\TestCase;

/**
 * ═══════════════════════════════════════════════════════════════════════════════════════════
 * Fase 187, plano 02 — A ROTA DA FILA, O CONTADOR NA TELA QUE JÁ ESTÁ NO AR E O TERRITÓRIO.
 *
 * ⚠️⚠️ **A PÁGINA `Mlb/Publicador/Aprovacao` SÓ EXISTE A PARTIR DO 187-04.** Por isso os casos
 * deste arquivo exercitam a action pelo caminho Inertia **sem resolver o componente** — header
 * `X-Inertia` + asserção sobre as props, que é o que a suíte do projeto já faz e que **não**
 * carrega o `.jsx`. ➡️ **A asserção de componente resolvido é pendência NOMEADA do 187-04**, e
 * está declarado aqui para ninguém achar que faltou gate.
 *
 * ⚠️⚠️ DE CADA GATE DESTE ARQUIVO, A PERGUNTA RESPONDIDA ANTES DE ELE EXISTIR:
 * **"o que precisaria estar errado no mundo para ele falhar?"**
 *
 *   · **a trava de conta** — a rota nova esquecer o `abort_unless` do `mlToken` e passar a
 *     responder para empresa sem conta ML (T-187-11). Ela é **byte a byte** a das outras
 *     actions do módulo, inclusive a mensagem.
 *   · **o middleware** — alguém pôr a rota FORA do grupo `['auth','verified','role:admin']`,
 *     ou acrescentar middleware novo (T-187-12). O caso do consultor é o que acusa.
 *   · **a resolução da rota** — alguém registrar, depois, uma rota curinga de DOIS segmentos
 *     (`/meus/{company}/{aba}`) ANTES da fila: a fila pararia de ser alcançada em silêncio. E o
 *     inverso: `meus.detalhe` parar de casar `MLB…`. As duas direções são resolvidas **pelo
 *     router**, não por `assertOk` — ver a nota de medição naquele caso.
 *   · **o 12% literal** — o número da linha "ⓘ Margem mínima" cravado no código (FIN-09), em
 *     vez de vir do resolvedor com a `origem`.
 *   · **o contador** — a prop da tela que está NO AR somando no cliente, mudando de nome, ou
 *     custando mais de uma consulta (T-187-13 / T-187-14).
 *   · **o território** — este plano tocando os 10 arquivos da Fase 186, o Creative Engine, o
 *     `resources/` ou os 4 arquivos de dependência (T-187-SC).
 *
 * ⚠️ **O GATE DE TERRITÓRIO MEDE OS COMMITS DO PRÓPRIO ESCOPO, NÃO A ÁRVORE.** Nesta árvore há
 * **três** sessões (este plano, a sessão paralela das Fases 196/197 e o outro dev), e `git
 * log --format=%an` devolve **um** valor para todas — o autor **não discrimina nada**. Em
 * 15/10/2026 um gate da Fase 186 por range ABERTO acusou a sessão paralela de mexer no arquivo
 * dela e a suíte saiu com 1 falha herdada; o conserto (commit `a3169a03`) foi trocar o range
 * aberto pelo **ESCOPO DO ASSUNTO**. Este arquivo nasce já com a forma certa.
 *
 * ⚠️ **Nenhuma chamada à API real do Mercado Livre:** `Http::fake()` + `preventStrayRequests()`.
 * ═══════════════════════════════════════════════════════════════════════════════════════════
 */
class FilaDeAprovacaoRotaTest extends TestCase
{
    use RefreshDatabase;

    /**
     * ⚠️ O commit de PARTIDA **deste plano**, capturado na PRIMEIRA ação do executor.
     *
     * Não é o da fase: o range da fase acusa o trabalho legítimo do plano dono e das sessões
     * paralelas. E ele sozinho não basta — ver o filtro por escopo de assunto em
     * {@see self::commitsDoEscopo()}.
     */
    private const PARTIDA = 'a3169a03';

    /** O escopo de assunto deste plano. O que discrimina, já que o autor não discrimina. */
    private const ESCOPO = '/\(187-02\)/';

    /**
     * ⚠️ O commit de PARTIDA do plano **187-04**, capturado na PRIMEIRA ação daquele executor.
     *
     * ⚠️ Ele é OUTRO, e isso é o ponto: o par (partida, escopo) é POR PLANO. Reusar o `PARTIDA`
     * do 187-02 aqui faria o gate de território da wave 4 varrer também os commits da wave 3 — a
     * família *"o gate é mais largo que o invariante"*, que nesta milestone já reprovou trabalho
     * CORRETO sete vezes.
     */
    private const PARTIDA_04 = 'a47a8c39';

    /** O escopo de assunto do plano 187-04. */
    private const ESCOPO_04 = '/\(187-04\)/';

    private const CLASSICO = 'gold_special';

    private int $sequencia = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-15 09:00:00');
        // ⚠️ `fake()` + `preventStrayRequests()`: nenhuma chamada real sai daqui. O `fake()` sem
        // argumento cobre o ruído GLOBAL do `HandleInertiaRequests` (contagem de sinais via
        // `EcfDriveService` em toda página Inertia do app), que não é o que estes casos travam.
        Http::fake();
        Http::preventStrayRequests();
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
    private function fixture(): array
    {
        $admin = User::factory()->create(['role' => 'admin']);
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
            'nome' => 'Empresa Fila ' . $company->id,
            'tipo' => 'ASSESSORIA',
            'company_id' => $company->id,
            'responsavel_id' => $admin->id,
        ]);

        return [$company, $empresa, $admin];
    }

    private function oferta(Company $empresa, string $andamento, ?string $entrouEm = '2026-10-14 08:00:00'): PubAprovacaoOferta
    {
        $this->sequencia++;
        $sku = 'ROTA-' . $this->sequencia;

        $produto = PubProduto::create([
            'company_id' => $empresa->id,
            'sku' => $sku,
            'nome' => 'Cadeira ' . $sku,
            'origem' => PubProduto::ORIGEM_PUBLICADOR,
        ]);
        $rascunho = PubRascunho::create(['produto_id' => $produto->id]);
        $alvo = PubRascunhoAlvo::create([
            'rascunho_id' => $rascunho->id,
            'listing_type_id' => self::CLASSICO,
            'posicao' => 0,
        ]);

        return PubAprovacaoOferta::create([
            'alvo_id' => $alvo->id,
            'rascunho_id' => $rascunho->id,
            'produto_id' => $produto->id,
            'company_id' => $empresa->id,
            'andamento' => $andamento,
            'entrou_na_fila_em' => $entrouEm,
        ]);
    }

    /** As props da fila, pelo caminho Inertia — SEM resolver o componente (ver o docblock). */
    private function propsDaFila(User $admin, Company $company): array
    {
        return $this->actingAs($admin)
            ->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => $this->versaoInertia()])
            ->get(route('mlb.anuncios.meus.aprovacao', $company))
            ->assertOk()
            ->json('props');
    }

    /** As props da tela de Publicações que JÁ ESTÁ NO AR. */
    private function propsDePublicacoes(User $admin, Company $company): array
    {
        return $this->actingAs($admin)
            ->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => $this->versaoInertia()])
            ->get(route('mlb.anuncios.meus', $company))
            ->assertOk()
            ->json('props');
    }

    private function versaoInertia(): string
    {
        return (string) app(\App\Http\Middleware\HandleInertiaRequests::class)->version(request());
    }

    private function raiz(): string
    {
        return rtrim(str_replace('\\', '/', base_path()), '/');
    }

    /** O código PHP **sem comentário** — `token_get_all`, nunca regex. */
    private function semComentariosPhp(string $conteudo): string
    {
        $fora = '';
        foreach (token_get_all($conteudo) as $t) {
            if (is_array($t)) {
                if ($t[0] === T_COMMENT || $t[0] === T_DOC_COMMENT) {
                    continue;
                }
                $fora .= $t[1];

                continue;
            }
            $fora .= $t;
        }

        return $fora;
    }

    private function git(string $comando): string
    {
        $saida = shell_exec('cd ' . escapeshellarg($this->raiz()) . ' && ' . $comando . ' 2>&1');

        return trim((string) $saida);
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // (1) A ROTA — a trava de conta, o middleware do grupo e a resolução
    // ═════════════════════════════════════════════════════════════════════════════════════════

    /**
     * ⚠️ **O que precisaria estar errado para falhar:** a rota não existir, o nome
     * `mlb.anuncios.meus.aprovacao` mudar, ou a action quebrar. A decisão estrutural da
     * milestone é que **a aba e a rota nascem juntas**, e esta é a metade do servidor: quando o
     * 187-03 montar a aba, a rota **já responde**.
     *
     * @group phase187
     */
    public function test_rota_da_fila_responde_200_com_conta_conectada_187_02(): void
    {
        [$company, , $admin] = $this->fixture();

        $props = $this->propsDaFila($admin, $company);

        $this->assertSame($company->id, $props['empresa']['id']);
        $this->assertSame($company->name, $props['empresa']['nome']);
    }

    /**
     * ⚠️ **O que precisaria estar errado para falhar:** a rota nova esquecer a trava do
     * `mlToken` e passar a responder para empresa sem conta ML (T-187-11). A mensagem é
     * conferida porque ela é **byte a byte** a das outras actions do módulo — divergir aqui
     * faria a tela dizer outra coisa para o mesmo fato.
     *
     * @group phase187
     */
    public function test_rota_da_fila_recusa_empresa_sem_conta_ml_187_02(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $semToken = Company::factory()->create(); // sem MlToken

        $resposta = $this->actingAs($admin)->get(route('mlb.anuncios.meus.aprovacao', $semToken));

        $resposta->assertNotFound();
        $this->assertStringContainsString('Empresa sem conta ML conectada.', $resposta->exception?->getMessage() ?? '');

        // ⚠️ ISCA — a MESMA mensagem na rota que já está no ar. Se as duas divergirem, a trava
        // deixou de ser "a mesma de todas as outras actions".
        $daLista = $this->actingAs($admin)->get(route('mlb.anuncios.meus', $semToken));
        $daLista->assertNotFound();
        $this->assertSame(
            $daLista->exception?->getMessage(),
            $resposta->exception?->getMessage(),
            'T-187-11: a trava da fila divergiu da trava da lista.',
        );
    }

    /**
     * ⚠️ **O que precisaria estar errado para falhar:** a rota ser registrada FORA do grupo
     * `['auth','verified','role:admin']`, ou alguém trocar o gate por middleware novo
     * (T-187-12). O módulo inteiro é admin-only e esta rota **não abre exceção**.
     *
     * @group phase187
     */
    public function test_rota_da_fila_recusa_quem_nao_e_admin_187_02(): void
    {
        [$company, , $admin] = $this->fixture();
        $consultor = User::factory()->create(['role' => 'consultor']);

        $this->actingAs($consultor)
            ->get(route('mlb.anuncios.meus.aprovacao', $company))
            ->assertForbidden();

        // ⚠️ ISCA — o admin passa pela MESMA rota. Sem isto, uma rota inexistente (404) ou
        // quebrada passaria o `assertForbidden` por outro motivo... e não passaria: 404 ≠ 403.
        // A isca fecha o outro lado: o 403 é do papel, não do estado da rota.
        //
        // ⚠️⚠️ A ISCA USA O CAMINHO INERTIA, E O MOTIVO FOI MEDIDO NESTA SESSÃO (vai na SUMMARY):
        // um GET **sem** `X-Inertia` renderiza a view raiz `app.blade.php`, cujo `@vite` procura
        // `resources/js/Pages/Mlb/Publicador/Aprovacao.jsx` no `manifest.json` — e aquele arquivo
        // **só existe a partir do 187-04**. A resposta medida foi *"Unable to locate file in Vite
        // manifest"*, e não é defeito desta rota: é a fronteira de wave declarada no plano.
        // ➡️ Pelo caminho Inertia o Laravel devolve JSON e **não** carrega o `.jsx`. A asserção de
        // componente RESOLVIDO (e o caminho sem `X-Inertia`) são **pendência NOMEADA do 187-04**.
        $this->assertSame($company->id, $this->propsDaFila($admin, $company)['empresa']['id']);

        // ⚠️ **NENHUM MIDDLEWARE NOVO** (T-187-12), provado por IGUALDADE com a rota da lista que
        // já está no ar — não por "contém `role:admin`", que passaria com um middleware extra ao
        // lado. A lista FECHADA é o invariante: a fila entra no grupo que existe, e nada mais.
        //
        // ⚠️ Não há caso de usuário ANÔNIMO aqui de propósito: `actingAs()` do Laravel persiste
        // pelo resto do método, então um `get()` sem ator depois de um `actingAs()` continua
        // AUTENTICADO — medido nesta sessão (veio 200 onde o teste esperava redirect). Um caso
        // "anônimo" escrito ingenuamente seria **verde por construção**, e é a mesma família do
        // gate vazio. O `auth` do grupo está coberto pela igualdade abaixo.
        $daFila = Route::getRoutes()->getByName('mlb.anuncios.meus.aprovacao')->gatherMiddleware();
        $daLista = Route::getRoutes()->getByName('mlb.anuncios.meus')->gatherMiddleware();

        $this->assertSame($daLista, $daFila, 'T-187-12: a rota da fila não tem a MESMA pilha de middleware da lista.');
        $this->assertContains('role:admin', $daFila, 'ISCA: a pilha comparada não é a que contém o gate de papel — a igualdade acima não prova nada.');
        $this->assertContains('auth', $daFila);
        $this->assertContains('verified', $daFila);
    }

    /**
     * ⚠️ **O que precisaria estar errado para falhar:** alguém registrar, depois, uma rota
     * curinga de DOIS segmentos sob `/meus/{company}/` **antes** da fila — `/meus/{company}/
     * {aba}` — e a fila parar de ser alcançada em silêncio. Ou `meus.detalhe` perder o
     * `->where('mlItemId','MLB[0-9]+')` e passar a comer `aprovacao`.
     *
     * ⚠️⚠️ **MEDIÇÃO QUE CORRIGE A PREMISSA, e ela vai na SUMMARY:** o plano trata isto como
     * risco de colisão entre a fila e `meus.detalhe`. Medido: `meus.detalhe` é
     * `/meus/{company}/{mlItemId}/detalhe` — **TRÊS** segmentos depois de `meus` — e a fila é
     * `/meus/{company}/aprovacao`, **DOIS**. As duas **não podem** colidir por estrutura, e um
     * gate que só comparasse as duas de hoje seria **vazio por construção**.
     * ➡️ Por isso a asserção é feita **pelo ROUTER** (`RouteCollection::match()`), não por
     * `assertOk`: ela mede *"qual rota ganha esta URI"*, que é a pergunta que continua podendo
     * ter a resposta errada amanhã.
     *
     * @group phase187
     */
    public function test_rota_da_fila_nao_rouba_o_detalhe_do_anuncio_187_02(): void
    {
        [$company] = $this->fixture();

        $quemGanha = function (string $url): ?string {
            try {
                return Route::getRoutes()->match(Request::create($url, 'GET'))->getActionName();
            } catch (\Symfony\Component\HttpKernel\Exception\NotFoundHttpException) {
                return null;
            }
        };

        $urlDaFila = route('mlb.anuncios.meus.aprovacao', $company, false);
        $urlDoDetalhe = route('mlb.anuncios.meus.detalhe', [$company, 'MLB123456789'], false);

        // Direção 1 — `aprovacao` vai para a FILA.
        $this->assertSame(
            MlbPublicadorAprovacaoController::class . '@index',
            $quemGanha($urlDaFila),
            'A URI da fila foi roubada por outra rota.',
        );

        // Direção 2 — `MLB123456789` continua indo para `meus.detalhe`.
        $this->assertSame(
            MlbAnuncioController::class . '@detalheAnuncio',
            $quemGanha($urlDoDetalhe),
            'TERR-08: a rota nova roubou o detalhe do anúncio.',
        );

        // ⚠️ ISCA 1 — a sonda SABE devolver null: uma URI que não existe não casa nada. Sem
        // isto, um `match()` que devolvesse sempre a mesma rota passaria as duas direções.
        $this->assertNull($quemGanha($urlDaFila . '/nao-existe-9z7'), 'ISCA 1: a sonda casa QUALQUER coisa — ela não discrimina rota.');

        // ⚠️ ISCA 2 — a restrição `MLB[0-9]+` da PRÓPRIA rota continua mordendo: `mlItemId`
        // malformado nunca chega ao controller (T-134-18). É o que impede o detalhe de virar
        // curinga de dois segmentos amanhã.
        $this->assertNull(
            $quemGanha('/mlb/anuncios/meus/' . $company->id . '/NAOEMLB/detalhe'),
            'ISCA 2: o `where(mlItemId, MLB[0-9]+)` sumiu — o detalhe virou curinga.',
        );

        // ⚠️ ISCA 3 — as duas URIs são DIFERENTES. Gate comparando uma URI consigo mesma é
        // vazio por construção.
        $this->assertNotSame($urlDaFila, $urlDoDetalhe);
        $this->assertStringEndsWith('/aprovacao', $urlDaFila);
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // (2) O PAYLOAD MÍNIMO DESTE PLANO
    // ═════════════════════════════════════════════════════════════════════════════════════════

    /**
     * ⚠️ **O que precisaria estar errado para falhar:** a faixa chegar com número diferente de
     * 9 entradas (e a tela desenhar card a menos), a margem mínima chegar sem a `origem` (e a
     * auditoria-depois não reconstruir a decisão), ou o contador não vir.
     *
     * ⚠️ As três props vêm do SERVIDOR e **nenhuma delas é literal no código** — há gate de
     * ausência próprio para o 12%.
     *
     * @group phase187
     */
    public function test_payload_da_fila_traz_faixa_margem_minima_e_contador_187_02(): void
    {
        [$company, , $admin] = $this->fixture();

        $this->oferta($company, PubAprovacaoOferta::AGUARDANDO_APROVACAO);
        $this->oferta($company, PubAprovacaoOferta::EM_REVISAO_ECF);
        $this->oferta($company, PubAprovacaoOferta::APROVADO); // NÃO é live

        $props = $this->propsDaFila($admin, $company);

        // As 6 chaves deste plano — contagem FIXA, coletando as AUSENTES (não uma asserção por
        // chave: asserção por linha de lista é a armadilha que caiu 4 vezes na Fase 186).
        $esperadas = ['cabecalho', 'contador_da_aba', 'conta', 'empresa', 'faixa', 'margem_minima'];
        $ausentes = array_values(array_filter($esperadas, fn (string $k) => ! array_key_exists($k, $props)));
        $this->assertSame([], $ausentes, 'O payload mínimo deste plano perdeu chave.');

        // ⚠️ ISCA — `array_key_exists` sobre este `$props` SABE dizer "não tem". Sem isto, um
        // payload que respondesse a qualquer chave deixaria `$ausentes` vazio para sempre.
        $this->assertFalse(array_key_exists('nao_existe_9z7', $props), 'ISCA: o payload responde a QUALQUER chave — a asserção de ausência é vazia.');

        // ⚠️ E as props que são de OUTRA tela não vazam para cá: este plano não manda a lista.
        foreach (['anuncios', 'abas', 'triagem', 'aguardando_aprovacao'] as $deOutraTela) {
            $this->assertFalse(array_key_exists($deOutraTela, $props), "A prop `{$deOutraTela}` é da tela de Publicações e não do payload da fila.");
        }

        $this->assertCount(9, $props['faixa'], 'AND-09: a faixa tem de ter 9 recortes.');
        $this->assertSame(['chave', 'rotulo', 'total'], array_keys($props['faixa'][0]));

        $this->assertSame(['valor', 'origem'], array_keys($props['margem_minima']));
        $this->assertIsNumeric($props['margem_minima']['valor']);
        $this->assertNotSame('', (string) $props['margem_minima']['origem'], 'FIN-09: cada número diz de onde vem.');

        // O contador é o `LIVE` do servidor: 2 das 3 ofertas.
        $this->assertSame(2, $props['contador_da_aba']);
        $this->assertIsInt($props['contador_da_aba']);

        // ⚠️ ISCA — a 3ª oferta EXISTE e está fora do contador. Sem isto, "2" poderia ser o
        // total de ofertas da conta por coincidência.
        $this->assertSame(3, PubAprovacaoOferta::where('company_id', $company->id)->count());
        $totais = array_column($props['faixa'], 'total', 'chave');
        $this->assertSame(3, $totais['todas'], 'ISCA: "Todas" tem de ver as 3 — o 2 do contador é recorte.');
        $this->assertSame(1, $totais[PubAprovacaoOferta::APROVADO]);
    }

    /**
     * ⚠️ **O que precisaria estar errado para falhar (FIN-09):** alguém cravar o `12` da linha
     * "ⓘ Margem mínima" no código da fila, em vez de ler o resolvedor. O número **é da
     * empresa** quando ela declarou, e o literal faria a tela mentir para toda empresa com
     * parâmetro próprio.
     *
     * ⚠️ **DOIS CONTROLES, porque asserção negativa sem controle casa sempre:** um `0.12`/`12%`
     * sintético **tem de ser acusado**, e um CNPJ que **contém** "12" **não pode** ser.
     *
     * @group phase187
     */
    public function test_sem_literal_de_doze_por_cento_na_fila_187_02(): void
    {
        $alvos = [
            'app/Http/Controllers/MlbPublicadorAprovacaoController.php',
            'app/Services/Publicador/Aprovacao/Fila/FilaDeAprovacaoService.php',
        ];

        // Percentual 12 como NÚMERO: `0.12`, `12%`, `=> 12`, `= 12`. `\b` nas duas pontas, para
        // `120` e `1.12` não casarem (learnings §4 caso 4: substring casa com prefixo).
        $sonda = '/\b0\.12\b|\b12(?:\.0+)?\s*%|(?:=>|=)\s*12(?:\.0+)?\s*[;,)\]]/';

        $infratores = [];
        $varridos = 0;
        foreach ($alvos as $rel) {
            $abs = $this->raiz() . '/' . $rel;
            // ⚠️ `test -e` ANTES: gate cujo caminho não existe mede NADA e sai verde.
            $this->assertFileExists($abs, "gate sobre caminho fantasma: {$rel}");
            $varridos++;
            if (preg_match($sonda, $this->semComentariosPhp((string) file_get_contents($abs))) === 1) {
                $infratores[] = $rel;
            }
        }

        $this->assertSame(2, $varridos, 'ISCA: o gate varreu menos arquivos do que deveria.');
        $this->assertSame([], $infratores, 'FIN-09: o 12% da margem mínima virou literal.');

        // ⚠️ CONTROLE POSITIVO — a sonda ACUSA as três formas do literal.
        foreach ([
            '<?php $m = 0.12;',
            '<?php $frase = "margem de 12%";',
            '<?php $p = [\'margem\' => 12];',
            '<?php $x = 12.0;',
        ] as $sintetico) {
            $this->assertSame(1, preg_match($sonda, $sintetico), "CONTROLE POSITIVO: a sonda não acha o literal em: {$sintetico}");
        }

        // ⚠️ CONTROLE NEGATIVO — valor que CONTÉM "12" e não é percentual 12 NÃO é acusado.
        foreach ([
            '<?php $cnpj = "12.345.678/0001-99";',
            '<?php $p = [\'pagina\' => 120];',
            '<?php $v = 1.12;',
            '<?php $v = 112;',
        ] as $inocente) {
            $this->assertSame(0, preg_match($sonda, $inocente), "CONTROLE NEGATIVO: a sonda acusou um inocente: {$inocente}");
        }
    }

    /**
     * ⚠️ **O que precisaria estar errado para falhar:** a action da fila chamar o Mercado Livre
     * **dentro do request**. É a classe do incidente de 30/07/2026, quando requisições lentas
     * ocuparam os workers do php-fpm e **até o login parou**.
     *
     * ⚠️ **O QUE ESTE GATE NÃO É, e isso vai na SUMMARY:** ele **não** é o gate de *"no máximo 1
     * requisição por trio `(categoria, tipo, preço)` distinto"*. Aquele mede
     * `BloqueiosDaOfertaService::para()` por oferta, e **o payload deste plano não chama
     * `para()`** — escrever o gate do trio agora seria gate sobre **ZERO casos**. Ele nasce no
     * plano que trouxer o payload por oferta. ⚠️ O aquecimento do 187-10 muda **QUANDO** a
     * requisição acontece, não **QUANTAS** — este gate continua valendo depois dele.
     *
     * @group phase187
     */
    public function test_a_fila_nao_chama_o_mercado_livre_187_02(): void
    {
        [$company, , $admin] = $this->fixture();
        $this->oferta($company, PubAprovacaoOferta::AGUARDANDO_APROVACAO);

        $this->propsDaFila($admin, $company);

        $doMl = fn ($request) => str_contains($request->url(), 'mercadolibre.com');

        Http::assertNotSent($doMl);

        // ⚠️ ISCA — o predicado SABE acusar. Sem isto, `assertNotSent` com um predicado que
        // nunca casa é verde permanente (e `preventStrayRequests` não ajudaria, porque o
        // `fake()` cobre tudo).
        Http::get('https://api.mercadolibre.com/sites/MLB/listing_prices');
        $acusou = false;
        try {
            Http::assertNotSent($doMl);
        } catch (\PHPUnit\Framework\AssertionFailedError) {
            $acusou = true;
        }
        $this->assertTrue($acusou, 'ISCA: o predicado do ML não acusa nem uma chamada explícita — ele não mede nada.');
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // (3) O CONTADOR REAL NA TELA QUE JÁ ESTÁ NO AR
    // ═════════════════════════════════════════════════════════════════════════════════════════

    /**
     * ⚠️ **O que precisaria estar errado para falhar (ABA-05):** o contador da aba deixar de vir
     * do servidor. É requisito, não detalhe de implementação: somar no cliente faria o número
     * depender **do que a página carregou**, não do que existe.
     *
     * ⚠️ A asserção é que a prop **IGUALA** o que o serviço devolve para a mesma conta — não que
     * ela seja "um número qualquer". Sem a igualdade, um `0` cravado passaria.
     *
     * @group phase187
     */
    public function test_meus_passa_o_contador_do_servidor_187_02(): void
    {
        [$company, , $admin] = $this->fixture();

        $this->oferta($company, PubAprovacaoOferta::AGUARDANDO_APROVACAO);
        $this->oferta($company, PubAprovacaoOferta::AJUSTES_SOLICITADOS);
        $this->oferta($company, PubAprovacaoOferta::EM_REVISAO_ECF);
        $this->oferta($company, PubAprovacaoOferta::PUBLICADO);   // fora
        $this->oferta($company, PubAprovacaoOferta::PREPARANDO);  // fora

        // E uma de OUTRA conta, que não pode entrar na contagem desta.
        $outra = Company::factory()->create();
        $this->oferta($outra, PubAprovacaoOferta::AGUARDANDO_APROVACAO);

        $props = $this->propsDePublicacoes($admin, $company);

        $doServico = app(\App\Services\Publicador\Aprovacao\Fila\FilaDeAprovacaoService::class)
            ->contadorDaAba($company->id, null);

        $this->assertSame(3, $props['aguardando_aprovacao']);
        $this->assertSame($doServico, $props['aguardando_aprovacao'], 'ABA-05: a prop da tela divergiu do serviço.');

        // ⚠️ ISCA — as 6 ofertas existem e a de outra conta está FORA. Sem isto, "3" poderia ser
        // o total do banco por coincidência.
        $this->assertSame(6, PubAprovacaoOferta::count());
        $this->assertSame(1, app(\App\Services\Publicador\Aprovacao\Fila\FilaDeAprovacaoService::class)->contadorDaAba($outra->id, null));
    }

    /**
     * ⚠️ **O que precisaria estar errado para falhar (T-187-13):** uma das props que `meus()` já
     * mandava mudar de nome ou de tipo. Esta é a action da tela que **está no ar**, e 22 testes
     * de `MeusAnunciosTest` dependem dela; a Visão Geral (Fase 179) e o corpo de Publicações
     * (Fase 180) leem estas chaves.
     *
     * ⚠️⚠️ **MEDIÇÃO QUE CORRIGE A PREMISSA, e vai na SUMMARY:** o plano e o briefing falam de
     * **20** props. Medido por script no `Inertia::render('Mlb/MeusAnuncios', …)`: são **19**.
     * A tabela abaixo é a lista medida, com o tipo de cada uma — e é a **contagem FIXA** que
     * prova "nenhuma renomeada, nenhuma removida". A 20ª é a deste plano
     * (`aguardando_aprovacao`), que entra ao lado.
     *
     * @group phase187
     */
    public function test_meus_mantem_as_dezenove_props_de_hoje_187_02(): void
    {
        [$company, , $admin] = $this->fixture();

        $props = $this->propsDePublicacoes($admin, $company);

        // As 19 de hoje, com o tipo MEDIDO (como o JSON do Inertia as entrega).
        $deHoje = [
            'empresa' => 'array',
            'conta' => 'array',
            'cabecalho' => 'array',
            'sub' => 'string',
            'subTotais' => 'array',
            'anuncios' => 'array',
            'rascunhos' => 'array',
            'triagem' => 'array',
            'filtros' => 'array',
            'abas' => 'array',
            'contagens' => 'array',
            'grupos' => 'array',
            'rodape' => 'array',
            'sincronizacao' => 'array',
            'faixaSucesso' => 'null',
            'skuNaoColetado' => 'bool',
            'defasagem' => 'array',
            'saudeMlDisponivel' => 'bool',
            'rotacaoN' => 'int',
        ];

        $this->assertCount(19, $deHoje, 'A tabela deste gate deixou de ter as 19 props medidas.');

        // UMA asserção de contagem fixa, COLETANDO os infratores — nunca uma por prop (asserção
        // por linha de lista foi a armadilha que caiu 4 vezes na Fase 186).
        $divergentes = [];
        foreach ($deHoje as $chave => $tipo) {
            if (! array_key_exists($chave, $props)) {
                $divergentes[] = "{$chave}: AUSENTE";

                continue;
            }
            $obtido = get_debug_type($props[$chave]);
            if ($obtido !== $tipo) {
                $divergentes[] = "{$chave}: esperava {$tipo}, veio {$obtido}";
            }
        }

        $this->assertSame([], $divergentes, 'T-187-13: prop da tela que está NO AR mudou de nome ou de tipo.');

        // A 20ª — a deste plano, ADITIVA.
        $this->assertArrayHasKey('aguardando_aprovacao', $props);
        $this->assertSame('int', get_debug_type($props['aguardando_aprovacao']));

        // ⚠️ ISCA — a sonda de tipo SABE discordar. Sem isto, um `get_debug_type` que devolvesse
        // sempre o esperado deixaria `$divergentes` vazio para sempre.
        $this->assertNotSame('array', get_debug_type($props['rotacaoN']), 'ISCA: a sonda de tipo não discrimina nada.');
        $this->assertFalse(array_key_exists('nao_existe_9z7', $props), 'ISCA: o payload responde a QUALQUER chave.');
    }

    /**
     * ⚠️ **O que precisaria estar errado para falhar:** o contador chegar `null` ou string vazia
     * na conta sem oferta nenhuma. A fila **nasce vazia** (nada cria `PubAprovacaoOferta` até a
     * Fase 194) e `0` real **não é enfeite** — a aba existe com número honesto e **não se
     * esconde** quando é zero (decisão do usuário).
     *
     * @group phase187
     */
    public function test_contador_na_tela_e_zero_inteiro_sem_oferta_187_02(): void
    {
        [$company, , $admin] = $this->fixture();

        $props = $this->propsDePublicacoes($admin, $company);

        $this->assertSame(0, $props['aguardando_aprovacao']);
        $this->assertNotNull($props['aguardando_aprovacao']);
        $this->assertSame('int', get_debug_type($props['aguardando_aprovacao']), '0 é um NÚMERO — `null` faria a aba desenhar vazio.');

        // ⚠️ ISCA — e a conta tem de estar de fato vazia. Sem isto, o 0 poderia ser recorte.
        $this->assertSame(0, PubAprovacaoOferta::count());
    }

    /**
     * ⚠️ **O que precisaria estar errado para falhar (T-187-14):** o contador custar mais de uma
     * consulta — um `get()` com filtragem em PHP, um eager load, ou sete `count()` como a tela de
     * abas já teve. `meus()` é a action da tela que está no ar, e o custo dela é pago em TODA
     * abertura.
     *
     * ⚠️ A prova é em DOIS níveis, porque "ganhou uma consulta a mais" não é mensurável sem o
     * "antes" (que não existe mais depois da edição):
     *   (a) `contadorDaAba()` isolado executa **exatamente 1** consulta, e o SQL dela é o
     *       `count(*)` com `company_id` + `andamento in (…)`;
     *   (b) no request INTEIRO de `meus()`, as consultas a `pub_aprovacao_ofertas` são
     *       **exatamente 1** — então a única consulta nova é a do contador.
     *
     * @group phase187
     */
    public function test_meus_ganha_exatamente_uma_consulta_187_02(): void
    {
        [$company, , $admin] = $this->fixture();
        $this->oferta($company, PubAprovacaoOferta::AGUARDANDO_APROVACAO);

        // (a) O serviço isolado.
        $doServico = [];
        DB::listen(function ($q) use (&$doServico) {
            $doServico[] = $q->sql;
        });
        app(\App\Services\Publicador\Aprovacao\Fila\FilaDeAprovacaoService::class)->contadorDaAba($company->id, null);

        $this->assertCount(1, $doServico, 'T-187-14: o contador não custa UMA consulta.');
        $this->assertMatchesRegularExpression(
            '/^select count\(\*\) as aggregate from .pub_aprovacao_ofertas. where .company_id. = \? and .andamento. in \(\?, \?, \?\)$/',
            $doServico[0],
            'O contador deixou de ser um `count(*)` por `company_id` + `andamento in (…)` — o índice `pubapof_conta_andamento_ix` deixa de servir.',
        );

        // (b) O request inteiro.
        $doRequest = [];
        DB::listen(function ($q) use (&$doRequest) {
            $doRequest[] = $q->sql;
        });
        $this->propsDePublicacoes($admin, $company);

        $daFila = array_values(array_filter($doRequest, fn (string $sql) => str_contains($sql, 'pub_aprovacao_ofertas')));

        $this->assertCount(1, $daFila, 'T-187-14: `meus()` ganhou mais de UMA consulta à fila: ' . implode(' | ', $daFila));
        $this->assertStringContainsString('count(*)', $daFila[0], 'A tela recebeu LINHAS da fila em vez do número.');

        // ⚠️ ISCA — o `DB::listen` SABE registrar consulta deste request. Sem isto, um listener
        // morto deixaria as duas contagens em 0 e o gate verde por construção.
        $this->assertGreaterThan(1, count($doRequest), 'ISCA: o `DB::listen` não registrou consulta nenhuma do request.');

        // ⚠️ E o índice que torna esse `count(*)` barato EXISTE, com as duas colunas na ordem
        // certa. Lido do schema vivo, não do arquivo da migration.
        $indice = collect(DB::select("PRAGMA index_info('pubapof_conta_andamento_ix')"))
            ->pluck('name')->all();
        $this->assertSame(['company_id', 'andamento'], $indice, 'O índice `pubapof_conta_andamento_ix` mudou de colunas ou de ordem.');
    }

    /**
     * ⚠️ **O que precisaria estar errado para falhar:** a tela de Publicações receber as LINHAS
     * da fila — e aí somar no cliente passaria a ser possível. ABA-05 diz que o número vem do
     * servidor; a forma de garantir isso é a tela **não ter o que somar**.
     *
     * ⚠️ As DUAS direções: o payload da FILA **contém** as chaves de andamento (é ele que
     * desenha a faixa), então a sonda não está cega.
     *
     * ⚠️ **O QUE A SONDA DELIBERADAMENTE NÃO PROCURA, e por quê:** `.length`. A página da fila
     * (plano 187-04) vai legitimamente contar a lista que ela mostra ("N de M ofertas",
     * `Aprovacao.dc.html:1276`), e uma sonda que proibisse `.length` perto de uma chave de
     * andamento reprovaria trabalho CERTO de outra wave — é a família *"o gate é mais largo que
     * o invariante"*, que já barrou um deploy nesta milestone. A sonda do cliente fica em
     * `reduce(`, que é somar, e o resto é coberto pela ausência de dado acima.
     *
     * @group phase187
     */
    public function test_a_tela_nao_recebe_a_fila_para_poder_somar_187_02(): void
    {
        [$company, , $admin] = $this->fixture();

        foreach (array_keys(PubAprovacaoOferta::ANDAMENTOS) as $estado) {
            $this->oferta($company, $estado);
        }

        // ⚠️⚠️ A SONDA É EM **POSIÇÃO DE VALOR** (`:"estado"`) E NA CHAVE `"andamento"`, e isso é
        // correção de DUAS armadilhas medidas nesta rodada, as duas da família "substring casa
        // com prefixo" (learnings §4 caso 4):
        //   · `publicado` casa dentro de `"publicados"` (a chave de `subTotais`) e de
        //     `"publicada_em"` — a sonda crua acusava a tela por duas palavras do idioma;
        //   · `aguardando_aprovacao` casa com **a própria prop deste plano** (`"aguardando_
        //     aprovacao":0`), que está em posição de CHAVE e é justamente o que tem de existir.
        // ➡️ Linha de oferta vazada traria o estado como **valor** (`"andamento":"preparando"`),
        // e é só isso que a sonda procura.
        $marcas = ['"andamento":'];
        foreach (array_keys(PubAprovacaoOferta::ANDAMENTOS) as $estado) {
            $marcas[] = ':"' . $estado . '"';
        }

        $jsonDaLista = (string) json_encode($this->propsDePublicacoes($admin, $company), JSON_UNESCAPED_UNICODE);

        $vazados = array_values(array_filter($marcas, fn (string $m) => str_contains($jsonDaLista, $m)));

        $this->assertSame([], $vazados, 'ABA-05: a tela de Publicações recebeu dado da fila — o cliente passou a ter o que somar.');

        // ⚠️ ISCA — o payload da FILA contém as marcas (a `faixa` manda `"chave":"preparando"`).
        // Sem isto, "não vazou" seria indistinguível de "a sonda não casa nada".
        $jsonDaFila = (string) json_encode($this->propsDaFila($admin, $company), JSON_UNESCAPED_UNICODE);
        $achadas = array_values(array_filter($marcas, fn (string $m) => str_contains($jsonDaFila, $m)));

        $this->assertCount(8, $achadas, 'ISCA: a sonda não acha os 8 estados nem no payload da FILA — ela está cega. Achou: ' . implode(', ', $achadas));

        // ⚠️ CONTROLES da sonda, nas duas direções — os dois casos que a derrubaram nesta rodada.
        $this->assertTrue(str_contains('{"andamento":"preparando"}', '"andamento":'), 'CONTROLE POSITIVO: a sonda não acha a coluna vazada.');
        $this->assertTrue(str_contains('{"andamento":"preparando"}', ':"preparando"'), 'CONTROLE POSITIVO: a sonda não acha o estado em posição de valor.');
        $this->assertFalse(str_contains('{"subTotais":{"publicados":3}}', ':"publicado"'), 'CONTROLE NEGATIVO: a sonda acusa a chave `publicados` da tela.');
        $this->assertFalse(str_contains('{"aguardando_aprovacao":0}', ':"aguardando_aprovacao"'), 'CONTROLE NEGATIVO: a sonda acusa a PRÓPRIA prop deste plano.');

        // ⚠️ E nenhum `.jsx` SOMA andamento (`reduce(`). Universo medido: hoje 2 arquivos citam
        // as chaves (`andamentoDoPortao.jsx` e `SeloDeAndamentoInterno.jsx`, os dois da Fase 186
        // e somente leitura) e nenhum tem `reduce(`.
        $comChave = [];
        $somadores = [];
        foreach ($this->todosOsJsDeResources() as $rel => $conteudo) {
            $limpo = $this->semComentariosJs($conteudo);
            $citaChave = false;
            foreach (array_merge(PubAprovacaoOferta::LIVE, ['contador_da_aba', 'aguardando_aprovacao']) as $chave) {
                if (str_contains($limpo, $chave)) {
                    $citaChave = true;
                    break;
                }
            }
            if (! $citaChave) {
                continue;
            }
            $comChave[] = $rel;
            if (preg_match('/reduce\s*\(/', $limpo) === 1) {
                $somadores[] = $rel;
            }
        }

        $this->assertSame([], $somadores, 'ABA-05: um `.jsx` soma andamento — o contador tem de vir do servidor.');
        $this->assertNotSame([], $comChave, 'ISCA: nenhum `.jsx` cita chave de andamento — a varredura está vazia por construção.');

        // ⚠️ CONTROLES da sonda de soma, nas duas direções.
        $this->assertSame(1, preg_match('/reduce\s*\(/', 'const n = LIVE.reduce((a, k) => a + mapa[k], 0);'), 'CONTROLE POSITIVO: a sonda de soma não acha um `reduce`.');
        $this->assertSame(0, preg_match('/reduce\s*\(/', 'const n = props.aguardando_aprovacao;'), 'CONTROLE NEGATIVO: a sonda acusa o uso CERTO da prop.');
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // (4) O SERVIÇO NÃO TEM LÓGICA PRÓPRIA
    // ═════════════════════════════════════════════════════════════════════════════════════════

    /**
     * ⚠️ **O que precisaria estar errado para falhar:** o serviço da fila reimplementar o que a
     * Fase 186 já entregou — o recorte por conta, a ordenação, os 9 recortes — criando uma
     * **segunda verdade** livre para divergir da primeira sem nenhum teste de render acusar.
     *
     * ⚠️⚠️ **O CÓDIGO É LIDO SEM OS COMENTÁRIOS**, e isso é correção de uma armadilha medida
     * nesta milestone: palavra no docblock que explica *"eu NÃO uso isso"* casa com o gate que
     * conta aquela palavra, o gate reprova **pelo próprio conserto**, e o executor seguinte
     * ajusta o código para o gate passar. Aconteceu em 5 dos 12 planos da Fase 184.
     *
     * @group phase187
     */
    public function test_servico_da_fila_nao_tem_logica_propria_187_02(): void
    {
        $rel = 'app/Services/Publicador/Aprovacao/Fila/FilaDeAprovacaoService.php';
        $abs = $this->raiz() . '/' . $rel;
        $this->assertFileExists($abs, 'gate sobre caminho fantasma');

        $codigo = $this->semComentariosPhp((string) file_get_contents($abs));

        // As formas de reimplementação, uma por uma, com o CONTROLE POSITIVO ao lado.
        $proibidas = [
            'recorte de conta reescrito' => ['/where\s*\(\s*[\'"]company_id[\'"]/', '<?php $q->where(\'company_id\', 1);'],
            'recorte de empresa reescrito' => ['/where\s*\(\s*[\'"]mlb_empresa_id[\'"]/', '<?php $q->where(\'mlb_empresa_id\', 1);'],
            'filtro de andamento reescrito' => ['/where\s*\(\s*[\'"]andamento[\'"]/', '<?php $q->where(\'andamento\', $x);'],
            'ordenacao reescrita' => ['/->sort(?:By|ByDesc)?\s*\(|\busort\s*\(/', '<?php $c->sort(fn ($a, $b) => 0);'],
            'recorte do dia reescrito' => ['/whereDate\s*\(/', '<?php $q->whereDate(\'updated_at\', $d);'],
            'agregacao propria' => ['/groupBy\s*\(|selectRaw\s*\(/', '<?php $q->groupBy(\'andamento\');'],
        ];

        $infratores = [];
        foreach ($proibidas as $nome => [$sonda, $controle]) {
            // ⚠️ Toda sonda é testada contra um caso que DEVE casar — antes de se confiar no
            // silêncio dela sobre o arquivo real.
            $this->assertSame(1, preg_match($sonda, $controle), "CONTROLE POSITIVO: a sonda '{$nome}' não acha nem o caso sintético.");
            if (preg_match($sonda, $codigo) === 1) {
                $infratores[] = $nome;
            }
        }

        $this->assertSame([], $infratores, 'O serviço da fila ganhou lógica própria: ' . implode(', ', $infratores));

        // ⚠️ ZERO literal dos 8 estados: a régua é a CONSTANTE do modelo. Escrever
        // `'aguardando_aprovacao'` aqui criaria a cópia que diverge no dia do 4º estado.
        $literais = [];
        foreach (array_keys(PubAprovacaoOferta::ANDAMENTOS) as $estado) {
            if (str_contains($codigo, "'{$estado}'") || str_contains($codigo, "\"{$estado}\"")) {
                $literais[] = $estado;
            }
        }
        $this->assertSame([], $literais, 'Literal de estado no serviço da fila — leia `PubAprovacaoOferta::LIVE`.');

        // ⚠️ ISCA DO LEITOR SEM COMENTÁRIO — o arquivo COM comentário CITA os estados (o docblock
        // explica a regra). Se `semComentariosPhp` parasse de tirar comentário, a asserção acima
        // reprovaria pelo próprio conserto. Esta isca prova que ela discrimina os dois.
        $comComentario = (string) file_get_contents($abs);
        $this->assertStringContainsString('aguardando_aprovacao', $comComentario, 'ISCA: o docblock deixou de citar o estado — a isca do leitor virou vazia.');
        $this->assertStringNotContainsString('aguardando_aprovacao', $codigo, 'ISCA: `semComentariosPhp` não tirou o comentário — o gate acima mede o docblock.');

        // ⚠️ E as delegações ESTÃO lá: gate de ausência sem o positivo passaria com o arquivo vazio.
        foreach (['filaDaConta', 'faixaDeAndamento', 'ordenarFila', 'margemMinimaComOrigem', 'PubAprovacaoOferta::LIVE'] as $delegacao) {
            $this->assertStringContainsString($delegacao, $codigo, "A delegação `{$delegacao}` sumiu do serviço da fila.");
        }

        // ⚠️ A API pública é EXATAMENTE os 5 métodos: contagem FIXA, para lógica não entrar como
        // 6º método.
        $publicos = array_values(array_map(
            fn (\ReflectionMethod $m) => $m->getName(),
            array_filter(
                (new \ReflectionClass(\App\Services\Publicador\Aprovacao\Fila\FilaDeAprovacaoService::class))->getMethods(\ReflectionMethod::IS_PUBLIC),
                fn (\ReflectionMethod $m) => $m->getName() !== '__construct',
            ),
        ));
        sort($publicos);
        $this->assertSame(
            ['contadorDaAba', 'faixa', 'margemMinima', 'ordenar', 'paraAConta'],
            $publicos,
            'A API pública do serviço da fila mudou — método novo é lógica entrando por outra porta.',
        );
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // (5) TERRITÓRIO — por ESCOPO DE ASSUNTO, nunca pela árvore e nunca pelo autor
    // ═════════════════════════════════════════════════════════════════════════════════════════

    /**
     * Os commits DESTE plano: do `PARTIDA` até `HEAD`, **filtrados pelo escopo do assunto**.
     *
     * ⚠️ O range puro mede o trabalho das TRÊS sessões desta árvore; o autor **não discrimina
     * nada** (`user.name` é o mesmo para todas). O discriminador é `(187-02)`.
     *
     * ⚠️ **PARAMETRIZADO PELO PLANO 187-04, e é ADIÇÃO PURA:** os dois argumentos são opcionais e
     * caem nas constantes do 187-02, então **nenhuma chamada existente mudou**. O que mudou é que
     * a wave 4 passa o próprio par (`PARTIDA_04`/`ESCOPO_04`) em vez de copiar este laço — duas
     * cópias do mesmo medidor divergiriam, e a 2ª seria a que ninguém conferiria.
     *
     * @return array<string, string> hash => assunto
     */
    private function commitsDoEscopo(?string $partida = null, ?string $escopo = null): array
    {
        $partida ??= self::PARTIDA;
        $escopo ??= self::ESCOPO;

        $linhas = array_filter(explode("\n", $this->git('git log --no-merges --format=%H%x09%s ' . $partida . '..HEAD')));

        $meus = [];
        foreach ($linhas as $linha) {
            [$hash, $assunto] = array_pad(explode("\t", $linha, 2), 2, '');
            if (preg_match($escopo, $assunto) === 1) {
                $meus[$hash] = $assunto;
            }
        }

        return $meus;
    }

    /** Os arquivos tocados pelos commits de um plano (ver `commitsDoEscopo`). */
    private function arquivosDoEscopo(?string $partida = null, ?string $escopo = null): array
    {
        $arquivos = [];
        foreach (array_keys($this->commitsDoEscopo($partida, $escopo)) as $hash) {
            foreach (array_filter(explode("\n", $this->git("git show --name-only --format= {$hash}"))) as $nome) {
                $arquivos[trim($nome)] = true;
            }
        }

        return array_keys($arquivos);
    }

    /**
     * Quais dos `$proibidos` foram tocados por um conjunto de arquivos medido no git.
     *
     * ⚠️ Comparação por PREFIXO DE SEGMENTO, não por `str_contains`: a lista mistura arquivos
     * (`app/Mcp` é diretório, `composer.json` é arquivo) e `str_contains` faria
     * `app/Services/Creative` casar com `app/Services/CreativeOutraCoisa.php`.
     *
     * @param  list<string>  $arquivos
     * @param  list<string>  $proibidos
     * @return list<string>
     */
    private function cruzarComProibidos(array $arquivos, array $proibidos): array
    {
        $tocados = [];
        foreach ($proibidos as $proibido) {
            foreach ($arquivos as $arquivo) {
                if ($arquivo === $proibido || str_starts_with($arquivo, $proibido . '/')) {
                    $tocados[] = $arquivo;
                }
            }
        }

        return array_values(array_unique($tocados));
    }

    /**
     * A bijeção ids ↔ métodos de UM plano, nos dois sentidos, colhendo os infratores.
     *
     * ⚠️⚠️ **EXTRAÍDA PELO PLANO 187-04, E O VETO VAI ESCRITO.** Até aqui este laço vivia inteiro
     * dentro de `test_bijecao_do_manifesto_187_02`, varrendo **todos** os métodos `test_*` dos
     * arquivos daquele plano e exigindo o sufixo `_187_02` em todos. ➡️ No momento em que esta
     * wave acrescentou métodos `_187_04` **ao mesmo arquivo**, aquele gate passaria a reprovar
     * trabalho CORRETO — é a família *"o gate é mais largo que o invariante"*, que nesta milestone
     * já reprovou trabalho certo SETE vezes.
     *
     * ⚠️ O que o invariante protege continua IGUAL, e agora vale **por plano**: cada método tem de
     * ter id no manifesto DO SEU plano, e cada id tem de apontar para um método que existe. O
     * universo varrido é recortado pelo SUFIXO — e o buraco que isso abriria (um método com
     * sufixo de plano NENHUM, varrido por gate nenhum) é fechado por
     * `test_todo_metodo_tem_sufixo_de_plano_declarado_187_04`, que varre o arquivo INTEIRO e
     * exige que cada sufixo visto tenha manifesto no disco.
     *
     * @param  list<string>  $arquivosDeTeste
     * @return list<string>  os infratores, já descritos
     */
    private function bijecaoDoManifesto(string $manifestoRel, array $arquivosDeTeste, string $sufixo): array
    {
        $manifesto = $this->raiz() . '/' . $manifestoRel;
        $this->assertFileExists($manifesto, "gate sobre caminho fantasma: {$manifestoRel}");

        // Os métodos DO SUFIXO, lidos do disco.
        $metodos = [];
        foreach ($arquivosDeTeste as $rel) {
            $abs = $this->raiz() . '/' . $rel;
            $this->assertFileExists($abs);
            preg_match_all('/public function (test_\w+)\(/', (string) file_get_contents($abs), $m);
            foreach ($m[1] as $nome) {
                if (str_ends_with($nome, $sufixo)) {
                    $metodos[$nome] = $rel;
                }
            }
        }

        // Os filtros declarados no manifesto, no modo `php`.
        $filtros = [];
        foreach (array_filter(explode("\n", (string) file_get_contents($manifesto))) as $linha) {
            if (preg_match('/^\s*(#|$)/', $linha) === 1) {
                continue;
            }
            $campos = preg_split('/\s+/', trim($linha));
            if (($campos[1] ?? '') !== 'php') {
                continue;
            }
            foreach (explode('|', (string) ($campos[2] ?? '')) as $nome) {
                $filtros[trim($nome)] = ($campos[0] ?? '');
            }
        }

        // ⚠️ ISCA — os dois lados têm conteúdo. Bijeção entre dois conjuntos vazios é verdadeira
        // e não prova nada.
        $this->assertNotSame([], $metodos, "ISCA: nenhum método `{$sufixo}` lido dos arquivos deste plano.");
        $this->assertNotSame([], $filtros, 'ISCA: nenhum filtro `php` lido do manifesto.');

        $infratores = [];
        foreach ($metodos as $nome => $rel) {
            if (! array_key_exists($nome, $filtros)) {
                $infratores[] = "SEM ID (nunca roda): {$nome} em {$rel}";
            }
        }
        foreach ($filtros as $nome => $id) {
            if (! array_key_exists($nome, $metodos)) {
                $infratores[] = "ID ORFAO (gate sobre zero casos): {$nome} no id '{$id}'";
            }
        }

        return $infratores;
    }

    /**
     * ⚠️ **O que precisaria estar errado para falhar:** este plano tocar um arquivo que não é
     * dele — os 10 da Fase 186, o Creative Engine, `resources/`, o `MlPublicacaoService`, o
     * `App\Support\Publicador`, o `app/Mcp/`, os D-11/D-22 ou os 4 arquivos de dependência
     * (T-187-SC).
     *
     * ⚠️⚠️ **MEDE OS COMMITS DO PRÓPRIO ESCOPO, NÃO A ÁRVORE.** Em 15/10/2026 um gate da Fase
     * 186 por range ABERTO acusou a **sessão paralela** de mexer no arquivo dela, e a suíte saiu
     * com 1 falha herdada (ver `187-BASELINES.md` §2.2); o conserto foi o commit `a3169a03`.
     * ⚠️ Nesta mesma sessão a sessão paralela tinha trabalho **NÃO COMMITADO** em
     * `routes/mlb_anuncios.php` (bloco da Fase 198) — por isso nada aqui lê `git status` nem
     * `git diff HEAD` da árvore inteira.
     *
     * ⚠️ `test -e` em CADA alvo ANTES de acreditar num diff vazio: cinco caminhos fantasma já
     * produziram "território intocado" nesta milestone.
     *
     * @group phase187
     */
    public function test_territorio_do_plano_187_02(): void
    {
        $permitidos = [
            'app/Services/Publicador/Aprovacao/Fila/FilaDeAprovacaoService.php',
            'app/Http/Controllers/MlbPublicadorAprovacaoController.php',
            'app/Http/Controllers/MlbAnuncioController.php',
            'routes/mlb_anuncios.php',
            'tests/Unit/Publicador/Aprovacao/FilaDeAprovacaoServiceTest.php',
            'tests/Feature/Publicador/Aprovacao/FilaDeAprovacaoRotaTest.php',
            'tests/gates/contagens-187-02.txt',
            '.planning/phases/187-a-fila-aguardando-aprovacao-e-a-aba-que-leva-a-ela/187-02-SUMMARY.md',
            // ⚠️ ENTROU DEPOIS, E A RAZÃO VAI NA SUMMARY. `PublicacoesDaTelaTest::
            // test_custo_da_action_esta_travado` é o gate de custo da Fase 180 que trava o número
            // de consultas de `meus()` em `CONSULTAS_DA_ACTION`. Ele reprovou a suíte — e
            // CORRETAMENTE: este plano acrescenta UMA consulta àquela action, de propósito. A
            // mensagem do próprio gate manda *"se foi de PROPÓSITO, atualize CONSULTAS_DA_ACTION
            // e escreva no docblock qual consulta entrou/saiu"*, e foi exatamente isso: 33 -> 34,
            // com a 34ª enumerada no docblock. ⚠️ Não é "ajustar gate para passar": é o protocolo
            // que o próprio gate declara para mudança intencional, e o número foi RE-MEDIDO.
            'tests/Feature/Publicador/PublicacoesDaTelaTest.php',
        ];

        $commits = $this->commitsDoEscopo();
        $arquivos = $this->arquivosDoEscopo();

        // ⚠️ ISCA 1 — o escopo existe. Gate sobre ZERO commits não prova nada, e é exatamente o
        // que um `--grep` errado produziria.
        $this->assertNotSame([], $commits, 'ISCA 1: nenhum commit com escopo `(187-02)` no range — o filtro não casa nada.');
        $this->assertContains(
            'app/Services/Publicador/Aprovacao/Fila/FilaDeAprovacaoService.php',
            $arquivos,
            'ISCA 2: o arquivo-âncora deste plano não aparece nos commits do escopo — o `git show` não está lendo nada.',
        );

        // ⚠️ ISCA 3 — o filtro de escopo DISCRIMINA: há commits no range que NÃO são deste plano
        // (a sessão paralela commitou durante a wave 1 e segue commitando).
        $todos = array_filter(explode("\n", $this->git('git log --no-merges --format=%H ' . self::PARTIDA . '..HEAD')));
        $this->assertGreaterThanOrEqual(count($commits), count($todos));
        $this->assertSame(1, preg_match(self::ESCOPO, 'feat(187-02): a rota da fila'), 'ISCA 3a: a sonda de escopo não acha o escopo deste plano.');
        $this->assertSame(0, preg_match(self::ESCOPO, 'feat(197): acervo_imagens por conta do ML'), 'ISCA 3b: a sonda de escopo acusa commit de OUTRA sessão.');
        $this->assertSame(0, preg_match(self::ESCOPO, 'feat(187-01): os 5 tokens da fila'), 'ISCA 3c: a sonda de escopo acusa a wave 1 desta fase.');

        // (a) Nada FORA da lista permitida.
        $forads = array_values(array_diff($arquivos, $permitidos));
        $this->assertSame([], $forads, 'Este plano tocou arquivo que não é dele: ' . implode(', ', $forads));

        // (b) A tabela de territórios PROIBIDOS, com `test -e` antes de cada um.
        $proibidos = [
            'app/Services/Publicador/Aprovacao/AndamentoDaOfertaService.php',
            'app/Services/Publicador/Aprovacao/AjustesDaOfertaService.php',
            'app/Services/Publicador/Aprovacao/AprovacaoDaOfertaService.php',
            'app/Services/Publicador/Aprovacao/BloqueiosDaOfertaService.php',
            'app/Services/Publicador/Aprovacao/ComposicaoDaOferta.php',
            'app/Services/Publicador/Aprovacao/ComposicaoFinanceiraService.php',
            'app/Services/Publicador/Aprovacao/HistoricoDaOfertaService.php',
            'app/Services/Publicador/Aprovacao/LinhaFinanceira.php',
            'app/Services/Publicador/Aprovacao/RevisaoDeImagemService.php',
            'app/Services/Publicador/Aprovacao/VocabularioDoPortao.php',
            'app/Services/Creative',
            'app/Services/Publicador/Criativos',
            'resources/js',
            'app/Services/Mlb/Publicacao/MlPublicacaoService.php',
            'app/Support/Publicador',
            'app/Mcp',
            'app/Services/Bling',
            'app/Services/Portal',
            'resources/js/Components/Publicador/Mesa',
            'resources/js/Pages/Mlb/Publicador/Editor.jsx',
            'resources/js/Layouts/AppLayout.jsx',
            'tests/js/estrutura-meus-anuncios.test.js',
            'package.json',
            'package-lock.json',
            'composer.json',
            'composer.lock',
            'database/migrations',
            '.planning/ROADMAP.md',
            '.planning/STATE.md',
        ];

        $violados = [];
        foreach ($proibidos as $alvo) {
            // ⚠️ Caminho fantasma mede NADA e sai verde. Cinco já aconteceram nesta milestone.
            $this->assertFileExists($this->raiz() . '/' . $alvo, "gate sobre caminho fantasma: {$alvo}");
            foreach ($arquivos as $tocado) {
                if ($tocado === $alvo || str_starts_with($tocado, rtrim($alvo, '/') . '/')) {
                    $violados[] = "{$alvo} <- {$tocado}";
                }
            }
        }

        $this->assertSame([], $violados, 'Território proibido tocado por este plano: ' . implode('; ', $violados));

        // ⚠️ ISCA 4 — a comparação de prefixo SABE casar. Sem isto, um `str_starts_with` com o
        // separador errado deixaria todo diretório proibido passar.
        $this->assertTrue(str_starts_with('resources/js/Pages/X.jsx', 'resources/js/'), 'ISCA 4: a comparação de prefixo de diretório não casa nada.');
        $this->assertFalse(str_starts_with('resources/jsonzinho.txt', 'resources/js/'), 'ISCA 4b: a comparação de prefixo casa um IRMÃO — ela é mais larga que o diretório.');
    }

    /**
     * ⚠️ **O que precisaria estar errado para falhar:** este plano alterar `MlbAnuncioController`
     * em mais de um lugar — e em particular tocar nos endpoints `criativo*`, que são território
     * do **outro dev** (Creative Engine) e vivem no MESMO arquivo que a action `meus()`.
     *
     * ⚠️ A prova é por **SEMÂNTICA**, não por número de linha: os nomes dos métodos `criativo*`
     * são lidos do arquivo e nenhum deles pode aparecer nas linhas do diff.
     *
     * @group phase187
     */
    public function test_o_diff_do_controller_da_lista_e_um_hunk_e_nao_toca_criativos_187_02(): void
    {
        $rel = 'app/Http/Controllers/MlbAnuncioController.php';
        $this->assertFileExists($this->raiz() . '/' . $rel);

        $diff = '';
        $commitsQueTocaram = 0;
        foreach (array_keys($this->commitsDoEscopo()) as $hash) {
            $pedaco = $this->git("git show --format= --unified=0 {$hash} -- " . escapeshellarg($rel));
            if (trim($pedaco) !== '') {
                $commitsQueTocaram++;
                $diff .= $pedaco . "\n";
            }
        }

        // ⚠️ ISCA 1 — o diff NÃO está vazio. Gate sobre diff vazio é a armadilha `git diff --stat`
        // depois de commitar (learnings §4 caso 3): ele sai vazio SEMPRE.
        $this->assertNotSame('', trim($diff), 'ISCA 1: o diff do controller da lista saiu VAZIO — o gate não mede nada.');

        $hunks = preg_match_all('/^@@/m', $diff);
        $this->assertSame(1, $hunks, "T-187-13: o diff do controller da lista tem {$hunks} hunks, e este plano acrescenta UMA prop.");

        $linhasNovas = array_values(array_filter(
            explode("\n", $diff),
            fn (string $l) => str_starts_with($l, '+') && ! str_starts_with($l, '+++'),
        ));

        // ⚠️ A única linha nova de CÓDIGO é a da prop. Comentário NÃO conta, e isso é deliberado:
        // um gate que exigisse "exatamente 1 linha acrescentada" proibiria DOCUMENTAR a linha —
        // e gate que reprova pelo próprio conserto é como o executor seguinte aprende a
        // ajustar o gate em vez do código. O invariante é "uma prop, nenhuma outra mudança".
        $codigoNovo = array_values(array_filter(
            $linhasNovas,
            fn (string $l) => preg_match('#^\+\s*(//|/\*|\*)#', $l) !== 1 && trim(substr($l, 1)) !== '',
        ));

        $this->assertCount(1, $codigoNovo, 'Mais de uma linha de CÓDIGO acrescentada: ' . implode(' | ', $codigoNovo));
        $this->assertStringContainsString('aguardando_aprovacao', $codigoNovo[0]);

        // ⚠️ ISCA — o filtro de comentário DISCRIMINA. Sem isto, um filtro que descartasse tudo
        // deixaria `$codigoNovo` vazio e o `assertCount(1, …)` reprovaria por outro motivo (ou,
        // com `assertLessThan`, passaria sempre).
        $this->assertGreaterThan(
            count($codigoNovo),
            count($linhasNovas),
            'ISCA: o diff não tem comentário nenhum — o filtro de comentário não está sendo exercitado.',
        );

        // ⚠️ Nenhuma linha REMOVIDA: a mudança é ADITIVA.
        $removidas = array_values(array_filter(
            explode("\n", $diff),
            fn (string $l) => str_starts_with($l, '-') && ! str_starts_with($l, '---'),
        ));
        $this->assertSame([], $removidas, 'A mudança no controller da lista removeu linha — ela é ADITIVA.');

        // ⚠️ Os endpoints `criativo*` do MESMO arquivo, por SEMÂNTICA. O universo é LIDO do
        // arquivo, não escrito à mão.
        preg_match_all(
            '/public function (criativo\w+)/',
            (string) file_get_contents($this->raiz() . '/' . $rel),
            $m,
        );
        $criativos = $m[1];
        $this->assertGreaterThan(5, count($criativos), 'ISCA 2: o universo de endpoints `criativo*` saiu vazio — a sonda não lê o arquivo.');

        $tocados = array_values(array_filter($criativos, fn (string $nome) => str_contains($diff, $nome)));
        $this->assertSame([], $tocados, 'Endpoint `criativo*` (território do outro dev) apareceu no diff: ' . implode(', ', $tocados));
    }

    /**
     * ⚠️ **O que precisaria estar errado para falhar:** um método deste plano **não ter id no
     * manifesto** — e então **nunca rodar** por `verificar-testes.sh` (foi o sobrevivente da
     * campanha de mutação do plano 186-03) — ou um id do manifesto estar **órfão**, sendo gate
     * sobre ZERO casos.
     *
     * ⚠️ **UMA asserção, de contagem FIXA, coletando os infratores** — nunca uma por linha do
     * manifesto: asserção por item de lista foi a armadilha que caiu **4 vezes** na Fase 186
     * (range, arquivo, tabela, commit).
     *
     * ⚠️⚠️ **REAPONTE DO PLANO 187-04, COM O VETO ESCRITO — e NADA deixou de ser provado.**
     *
     * Até aqui este método varria **todos** os métodos `test_*` dos dois arquivos e exigia o
     * sufixo `_187_02` em **todos** eles. No instante em que a wave 4 acrescentou métodos
     * `_187_04` **a este mesmo arquivo** (o plano 187-04 manda fechar aqui a pendência da asserção
     * de componente resolvido), o gate passaria a reprovar trabalho CORRETO — a família *"o gate é
     * mais largo que o invariante"*, que nesta milestone já reprovou trabalho certo **sete** vezes.
     *
     * ➡️ O que mudou: o universo varrido é recortado pelo SUFIXO `_187_02`, e a mecânica foi
     * extraída para `bijecaoDoManifesto()` para que a wave 4 use **o mesmo medidor** em vez de
     * copiá-lo. O que NÃO mudou: cada método `_187_02` continua obrigado a ter id em
     * `contagens-187-02.txt`, e cada id daquele manifesto continua obrigado a apontar para um
     * método que existe.
     *
     * ⚠️ **E o buraco que o recorte abriria está FECHADO**: um método com sufixo de plano NENHUM
     * (ou de um plano sem manifesto) ficaria fora dos dois gates. Quem o pega é
     * `test_todo_metodo_tem_sufixo_de_plano_declarado_187_04`, que varre o arquivo INTEIRO e exige
     * que todo sufixo visto tenha manifesto no disco.
     *
     * @group phase187
     */
    public function test_bijecao_do_manifesto_187_02(): void
    {
        $arquivosDeTeste = [
            'tests/Unit/Publicador/Aprovacao/FilaDeAprovacaoServiceTest.php',
            'tests/Feature/Publicador/Aprovacao/FilaDeAprovacaoRotaTest.php',
        ];

        $infratores = $this->bijecaoDoManifesto('tests/gates/contagens-187-02.txt', $arquivosDeTeste, '_187_02');

        $this->assertSame([], $infratores, "A bijeção ids <-> métodos quebrou:\n" . implode("\n", $infratores));

        // ⚠️ E o sufixo próprio: nome de guarda genérico COLIDE com homônimo de outro arquivo e o
        // `--filter` SOMA as contagens dos dois, estourando o número do manifesto em silêncio.
        // ⚠️ Medido sobre o arquivo INTEIRO (não sobre o recorte de `bijecaoDoManifesto`): é aqui
        // que um método sem sufixo nenhum apareceria.
        $todos = [];
        foreach ($arquivosDeTeste as $rel) {
            preg_match_all('/public function (test_\w+)\(/', (string) file_get_contents($this->raiz() . '/' . $rel), $m);
            $todos = array_merge($todos, $m[1]);
        }
        $this->assertNotSame([], $todos, 'ISCA: nenhum método `test_*` lido dos arquivos deste plano.');

        $semSufixoDePlano = array_values(array_filter(
            $todos,
            fn (string $n) => preg_match('/_187_\d{2}$/', $n) !== 1,
        ));
        $this->assertSame(
            [],
            $semSufixoDePlano,
            'Método sem sufixo de plano (`_187_NN`): o `--filter` pode somar homônimos de outro arquivo,'
            . ' e o método fica fora de TODA bijeção. Infratores: ' . implode(', ', $semSufixoDePlano),
        );
    }


    // ═════════════════════════════════════════════════════════════════════════════════════════
    // (6) PLANO 187-04 — A PÁGINA: o componente resolvido, a etapa saneada e o invariante de
    //     FILA-03 (a faixa NÃO se mexe com a etapa)
    // ═════════════════════════════════════════════════════════════════════════════════════════

    /**
     * ⚠️⚠️ **A PENDÊNCIA NOMEADA PELO PLANO 187-02, FECHADA AQUI.**
     *
     * **O que precisaria estar errado para falhar:** a página
     * `resources/js/Pages/Mlb/Publicador/Aprovacao.jsx` não existir, ou sair do manifest do Vite.
     * `resources/views/app.blade.php:38` monta
     * `@vite(['resources/js/app.jsx', "resources/js/Pages/{$page['component']}.jsx"])` — então um
     * GET **SEM** o header `X-Inertia` obriga o Vite a resolver a página no manifest, e sem ela a
     * requisição ERRA.
     *
     * ⚠️ É por isso que este caso NÃO manda `X-Inertia`: **com** o header o Inertia devolve JSON e
     * **nunca** passa pelo blade — que é exatamente o furo que deixou a janela aberta entre as
     * waves 2 e 3 (a rota respondia 200 para uma página que não existia).
     *
     * ⚠️ **ISCA:** o MESMO caminho com um componente que não existe tem de FALHAR. Sem ela, "o GET
     * deu 200" não distinguiria "a página está no manifest" de "o blade não resolve página
     * nenhuma".
     *
     * @group phase187
     */
    public function test_rota_da_fila_resolve_o_componente_da_pagina_187_04(): void
    {
        [$company, , $admin] = $this->fixture();

        // ⚠️ O manifest tem de existir: sem `npm run build` este caso mediria o ambiente, não o
        // código. `assertFileExists` ANTES, para o motivo da falha ser legível.
        $manifesto = $this->raiz() . '/public/build/manifest.json';
        $this->assertFileExists($manifesto, 'gate sobre caminho fantasma: rode `npm run build`');

        $mapa = json_decode((string) file_get_contents($manifesto), true);
        $this->assertIsArray($mapa, 'o manifest do Vite não é JSON válido');
        $this->assertArrayHasKey(
            'resources/js/Pages/Mlb/Publicador/Aprovacao.jsx',
            $mapa,
            'a PÁGINA da fila saiu do manifest do Vite — ela DESAPARECE da aplicação sem erro nenhum',
        );
        // ⚠️ ISCA do manifest: uma entrada inexistente sai ausente, provando que a sonda não
        // responde "sim" para tudo.
        $this->assertArrayNotHasKey(
            'resources/js/Pages/Mlb/NaoExiste9z7.jsx',
            $mapa,
            'a isca do manifest virou entrada de verdade — troque a isca',
        );

        // ⚠️⚠️ SEM `X-Inertia`: é este caminho que passa pelo blade e resolve o componente.
        //
        // ⚠️⚠️ E A PROVA NÃO É O `assertOk`, E ISSO FOI MEDIDO NESTA SESSÃO. A primeira
        // redação deste caso provava "o GET deu 200" e tinha uma ISCA registrando uma rota com
        // componente INEXISTENTE para exigir que ela QUEBRASSE. ⚠️ A isca **NÃO quebrou** — o
        // que significa que, naquele caminho, o 200 **não distinguia nada**: era um gate vazio
        // por construção, exatamente a armadilha nº 4.
        //
        // ➡️ A prova passou a ser DIRETA: o HTML da resposta tem de conter o ARQUIVO CONSTRUÍDO
        // da página, lido do próprio manifest. Se o `@vite` não tivesse resolvido a página, aquele
        // nome de arquivo (com hash) não estaria no HTML — e nenhum "200" explicaria isso.
        $html = $this->actingAs($admin)
            ->get(route('mlb.anuncios.meus.aprovacao', $company))
            ->assertOk()
            ->getContent();

        $arquivoDaPagina = $mapa['resources/js/Pages/Mlb/Publicador/Aprovacao.jsx']['file'] ?? null;
        $this->assertIsString($arquivoDaPagina, 'a entrada da página no manifest não tem `file`');
        $this->assertStringContainsString(
            $arquivoDaPagina,
            (string) $html,
            'o HTML da fila NÃO carrega o bundle da página (' . $arquivoDaPagina . '): o `@vite` de'
            . ' `resources/views/app.blade.php:38` não resolveu `resources/js/Pages/{component}.jsx`.',
        );
        // ⚠️ CONTROLE DA SONDA DE HTML: ela acha o bundle do `app.jsx` também (os dois entram
        // pelo MESMO `@vite`). Sem isto, achar o da página poderia ser coincidência de substring.
        $this->assertStringContainsString((string) ($mapa['resources/js/app.jsx']['file'] ?? 'x'), (string) $html);
        // ⚠️ ISCA: o bundle de uma página que NÃO é esta não entra neste HTML. Prova que o
        // `@vite` monta a página PEDIDA, e não "todas as páginas".
        $daLista = $mapa['resources/js/Pages/Mlb/MeusAnuncios.jsx']['file'] ?? null;
        $this->assertIsString($daLista, 'a página da LISTA saiu do manifest');
        $this->assertStringNotContainsString(
            $daLista,
            (string) $html,
            'ISCA: o HTML da fila carrega o bundle da LISTA — então a sonda não distingue páginas.',
        );

        // E o componente que o Inertia declara é o da fila.
        //
        // ⚠️ LIDO DIRETO DO OBJETO DE PÁGINA (`->json('component')`), e NÃO por
        // `assertInertia`: medido nesta sessão, o `AssertableInertia` deste projeto reprova a
        // resposta com *"Not a valid Inertia response"* (ele exige o envelope completo, com
        // `encryptHistory`/`clearHistory`, que esta resposta não traz). ➡️ Usar o helper aqui
        // faria o gate reprovar o código CERTO; a leitura direta é a MESMA afirmação, sobre o
        // mesmo campo, e é o que o resto deste arquivo já faz com `->json('props')`.
        $componente = $this->actingAs($admin)
            ->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => $this->versaoInertia()])
            ->get(route('mlb.anuncios.meus.aprovacao', $company))
            ->assertOk()
            ->json('component');

        $this->assertSame('Mlb/Publicador/Aprovacao', $componente, 'a rota resolveu OUTRO componente.');

        // ⚠️⚠️ ISCA DO MECANISMO — o resolvedor do Vite LANÇA para uma página fora do manifest.
        //
        // É ela que torna a asserção acima não-vazia: enquanto `Aprovacao.jsx` não existia, o
        // `@vite` de `app.blade.php:38` erraria assim em QUALQUER GET sem `X-Inertia` nesta rota.
        // Medido nesta máquina: `ViteException :: Unable to locate file in Vite manifest:
        // resources/js/Pages/Mlb/Publicador/NaoExiste9z7.jsx.`
        //
        // ⚠️ A isca é sobre o RESOLVEDOR, e não sobre uma rota sintética: a redação por rota foi
        // TENTADA e **não quebrou** (o `withoutExceptionHandling` daquele caminho não propagou), o
        // que a tornaria uma isca que nunca acusa — pior que isca nenhuma, porque pareceria prova.
        $quebrou = false;
        try {
            app(\Illuminate\Foundation\Vite::class)('resources/js/Pages/Mlb/Publicador/NaoExiste9z7.jsx');
        } catch (\Throwable) {
            $quebrou = true;
        }
        $this->assertTrue(
            $quebrou,
            'ISCA: o resolvedor do Vite NÃO lança para página fora do manifest — então estar no'
            . ' manifest não prova nada, e esta asserção inteira é vazia por construção.',
        );

        // ⚠️ CONTROLE: e o MESMO resolvedor NÃO lança para a página da fila.
        $resolveu = null;
        try {
            $resolveu = (string) app(\Illuminate\Foundation\Vite::class)('resources/js/Pages/Mlb/Publicador/Aprovacao.jsx');
        } catch (\Throwable $e) {
            $this->fail('o resolvedor do Vite LANÇOU para a página da fila: ' . $e->getMessage());
        }
        $this->assertStringContainsString($arquivoDaPagina, $resolveu);
    }

    /**
     * ⚠️⚠️ **O INVARIANTE DE FILA-03, NAS DUAS DIREÇÕES.**
     *
     * **O que precisaria estar errado para falhar:** a faixa passar a ser contada sobre o recorte
     * **já filtrado pela etapa**. O sintoma é clássico e devastador: filtrar por "Aguardando
     * aprovação" faria aquele card mostrar o próprio total e **os outros oito zerarem** — a pessoa
     * perderia a navegação da tela inteira. Medido no protótipo: `cnt` sai de `base`
     * (`Aprovacao.dc.html:1213`) e `list` de `base.filter(stage)` (`:1214`).
     *
     * ⚠️⚠️ **E A SEGUNDA DIREÇÃO É O QUE IMPEDE O GATE DE SER VAZIO:** uma faixa CONGELADA (um
     * array fixo, por exemplo) passaria na primeira direção sem reclamar. Então o caso também
     * prova que a faixa **se move** quando um filtro da `$base` muda — e o filtro da `$base` que
     * existe hoje é a CONTA.
     *
     * ⚠️ **O QUE ESTE CASO DELIBERADAMENTE NÃO PROVA, e por quê:** que `?etapa=` recorta A LISTA.
     * **Não há lista no payload deste plano** — a tabela é dos planos 187-07/08 e o filtro de
     * responsável é do 187-06. Escrever agora um gate sobre a lista filtrada seria gate sobre ZERO
     * casos, e um `?responsavel=N` sobre um filtro que não existe seria pior: passaria sempre, e
     * depois viraria falso positivo. ➡️ A metade *"a lista honra a etapa"* nasce no plano que
     * trouxer a lista, e esta ausência está DECLARADA, não esquecida.
     *
     * @group phase187
     */
    public function test_etapa_nao_muda_os_totais_da_faixa_187_04(): void
    {
        [$company, , $admin] = $this->fixture();

        // Uma oferta em cada um dos 8 estados: a faixa tem o que contar.
        foreach (array_keys(PubAprovacaoOferta::ANDAMENTOS) as $estado) {
            $this->oferta($company, $estado);
        }

        $semEtapa = $this->propsDaFila($admin, $company);

        // ⚠️ ÂNCORA POSITIVA: a faixa NÃO está vazia. Sem ela, comparar dois arrays vazios é
        // verdadeiro e não prova nada.
        $this->assertCount(9, $semEtapa['faixa'], 'a faixa deixou de ter 9 entradas.');
        $somaSemEtapa = array_sum(array_column($semEtapa['faixa'], 'total'));
        $this->assertGreaterThan(0, $somaSemEtapa, 'ÂNCORA: a faixa veio toda zerada — o gate mediria NADA.');

        // ─── DIREÇÃO 1: a etapa NÃO move a faixa ───
        foreach (['aprovado', 'aguardando_aprovacao', 'falha', 'todas'] as $etapa) {
            $comEtapa = $this->actingAs($admin)
                ->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => $this->versaoInertia()])
                ->get(route('mlb.anuncios.meus.aprovacao', $company) . '?etapa=' . $etapa)
                ->assertOk()
                ->json('props');

            $this->assertSame(
                $semEtapa['faixa'],
                $comEtapa['faixa'],
                "FILA-03: `?etapa={$etapa}` mexeu nos totais da faixa. Ela é contada sobre os DEMAIS"
                . ' filtros — contar sobre o recorte da etapa zera os outros oito cards.',
            );
            // E a etapa CHEGA à tela (é ela que acende o card).
            $this->assertSame($etapa, $comEtapa['etapa'], "a etapa `{$etapa}` não chegou à tela.");
        }

        // ─── DIREÇÃO 2 (a que impede o gate vazio): um filtro da `$base` MOVE a faixa ───
        // A `$base` é recortada por conta. Outra conta, com outra quantidade de ofertas, tem de
        // produzir outra faixa — senão a faixa está CONGELADA e a direção 1 passaria de graça.
        [$outra, , $outroAdmin] = $this->fixture();
        $this->oferta($outra, PubAprovacaoOferta::AGUARDANDO_APROVACAO);

        $daOutraConta = $this->propsDaFila($outroAdmin, $outra);
        $this->assertCount(9, $daOutraConta['faixa']);
        $somaDaOutra = array_sum(array_column($daOutraConta['faixa'], 'total'));

        $this->assertNotSame(
            $somaSemEtapa,
            $somaDaOutra,
            'ISCA: a faixa deu o MESMO total para duas contas diferentes — ela está CONGELADA, e a'
            . ' direção 1 deste caso estaria passando por construção.',
        );
        // ⚠️ A SOMA da faixa NAO e o numero de ofertas: `todas` soma junto com o recorte do
        // estado, entao 1 oferta em `aguardando_aprovacao` da soma 2. O que se afirma e o card
        // `todas` — e a primeira redacao deste caso afirmava `1` e REPROVOU, o que e a armadilha
        // 3 (toda regra precisa de um caso que DEVE casar) pega pelo proprio controle.
        $this->assertSame('todas', $daOutraConta['faixa'][0]['chave'], 'o 1o card da faixa deixou de ser `todas`.');
        $this->assertSame(1, $daOutraConta['faixa'][0]['total'], 'a conta nova tem 1 oferta; o card `todas` contou outra coisa.');
        $this->assertSame(2, $somaDaOutra, 'a soma da faixa da conta nova mudou (todas=1 + aguardando=1).');
    }

    /**
     * ⚠️ **O que precisaria estar errado para falhar (T-187-41):** a etapa da querystring chegar
     * crua à tela (ou a uma consulta). Os dois modos de falhar medidos:
     *   · valor FORA da lista fechada acendendo um card que não existe;
     *   · `?etapa[]=x` entregando um **ARRAY** e `(string) []` lançando — foi **500** nesta tela
     *     antes (T-180-04-T), e é por isso que a guarda é `is_string` ANTES de `trim`.
     *
     * ⚠️ E a lista fechada é LIDA de `PubAprovacaoOferta::ANDAMENTOS`, a mesma fonte da faixa:
     * duas listas à mão divergiriam no dia em que um 9º estado entrasse.
     *
     * @group phase187
     */
    public function test_etapa_fora_da_lista_fechada_cai_no_default_187_04(): void
    {
        [$company, , $admin] = $this->fixture();

        $urlBase = route('mlb.anuncios.meus.aprovacao', $company);

        $pedir = function (string $querystring) use ($admin, $urlBase) {
            return $this->actingAs($admin)
                ->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => $this->versaoInertia()])
                ->get($urlBase . $querystring)
                ->assertOk()
                ->json('props.etapa');
        };

        // ─── As 9 chaves LEGÍTIMAS passam, e são lidas da MESMA fonte da faixa ───
        $permitidas = array_merge(['todas'], array_keys(PubAprovacaoOferta::ANDAMENTOS));
        $this->assertCount(9, $permitidas, 'a lista fechada deixou de ter 9 chaves.');
        foreach ($permitidas as $chave) {
            $this->assertSame($chave, $pedir('?etapa=' . $chave), "a chave legítima `{$chave}` foi recusada.");
        }

        // ─── O que NÃO está na lista cai no default, sem 500 ───
        foreach ([
            '?etapa=nao_existe',
            '?etapa=',
            '?etapa=TODAS',
            // ⚠️ As chaves INGLESAS do protótipo NÃO são chave de dado: aceitar "as duas formas"
            // num `||` foi achado como defeito GRAVE na verificação da Fase 186.
            '?etapa=all',
            '?etapa=awaiting',
            '?etapa=ecf_review',
            '?etapa=changes',
            '?etapa=published',
            // ⚠️ Tentativa de injeção: o valor nunca é interpolado em SQL, só comparado.
            "?etapa=aprovado'+OR+1=1--",
            // ⚠️ ARRAY na querystring — o 500 de T-180-04-T.
            '?etapa[]=aprovado',
            '?etapa[]=x&etapa[]=y',
            '?etapa[a]=b',
        ] as $querystring) {
            $this->assertSame(
                'todas',
                $pedir($querystring),
                "`{$querystring}` não caiu no default `todas` — ou a guarda `is_string` saiu.",
            );
        }

        // ⚠️ CONTROLE POSITIVO: `?etapa=aprovado` É aceito. Sem este controle, as asserções acima
        // passariam com uma guarda que recusasse TUDO — inclusive o valor certo.
        $this->assertSame('aprovado', $pedir('?etapa=aprovado'), 'CONTROLE: a guarda recusa até o valor legítimo.');

        // ⚠️ E o default também vale SEM querystring nenhuma.
        $this->assertSame('todas', $pedir(''), 'sem `?etapa` a tela não recebeu o default.');

        // ⚠️ E a chave de "sem filtro" do PHP é a MESMA de `FAIXA_DE_ANDAMENTO[0].chave` no
        // `.jsx` — duas verdades aqui acenderiam card nenhum com o default do servidor.
        $jsx = (string) file_get_contents(
            $this->raiz() . '/resources/js/Components/Mlb/Publicador/aprovacao/andamentoDoPortao.jsx',
        );
        $this->assertStringContainsString(
            "{ chave: 'todas', rotulo: 'Todas' }",
            $jsx,
            'a chave de "sem filtro" do .jsx mudou — o default do servidor acenderia card NENHUM.',
        );
        $this->assertSame('todas', MlbPublicadorAprovacaoController::ETAPA_TODAS);
    }

    /**
     * ⚠️ **O que precisaria estar errado para falhar:** a página desenhar casca para o que ainda
     * não existe. A ordem vertical de FILA-01 é **cabeçalho → faixa → PRÓXIMO → filtros → tabela →
     * rodapé**, e este plano entrega os DOIS primeiros. ⚠️ Slot sem nó não desenha casca, não
     * desenha borda e não desenha "nenhum item" — isso é **D4: nada nasce decorativo**.
     *
     * ⚠️⚠️ **E O ESTADO VAZIO É DO PLANO 187-09, de propósito.** Decisão 16 do usuário: sem filtro,
     * *"ainda não há ofertas aguardando aprovação"* e **sem botão**; com filtro, o literal do
     * protótipo com "Limpar filtros". ➡️ Esta página **não escreve vazio nenhum**, e o gate abaixo
     * prova que o literal errado (*"Nenhuma oferta neste filtro"* sem filtro, a mentira que a
     * decisão proíbe) não está lá.
     *
     * ⚠️ A sonda é RECORTADA a esta página, por caminho. Varrer `resources/` acusaria o vazio
     * legítimo de outra tela.
     *
     * @group phase187
     */
    public function test_a_pagina_da_fila_nao_desenha_o_que_nao_existe_187_04(): void
    {
        $rel = 'resources/js/Pages/Mlb/Publicador/Aprovacao.jsx';
        $abs = $this->raiz() . '/' . $rel;
        $this->assertFileExists($abs, "gate sobre caminho fantasma: {$rel}");

        $fonte = $this->semComentariosJs((string) file_get_contents($abs));

        // ─── Os quatro slots são NOMEADOS e passam por `isValidElement` ───
        foreach (['proximo', 'filtros', 'tabela', 'rodape'] as $slot) {
            $this->assertStringContainsString(
                $slot,
                $fonte,
                "o slot `{$slot}` da ordem vertical de FILA-01 não está nomeado na página.",
            );
        }
        $this->assertSame(
            4,
            preg_match_all('/isValidElement\(/', $fonte),
            'os quatro slots têm de passar por `isValidElement`: nó inválido como filho faz o React LANÇAR.',
        );

        // ─── Nenhum literal de estado vazio, de nenhuma forma ───
        $vazios = [
            'Nenhuma oferta neste filtro',
            'ainda não há ofertas aguardando aprovação',
            'Nenhuma oferta',
            'Limpar filtros',
            'Nada por aqui',
        ];
        $escritos = array_values(array_filter($vazios, fn (string $l) => str_contains($fonte, $l)));
        $this->assertSame(
            [],
            $escritos,
            'o estado VAZIO da fila é do plano 187-09 (decisão 16 do usuário) — esta página não o escreve: '
            . implode(' | ', $escritos),
        );

        // ⚠️ CONTROLE POSITIVO da sonda: ela ACHA um literal que a página TEM. Sem isto, as cinco
        // asserções acima poderiam estar passando porque o arquivo veio vazio.
        $this->assertStringContainsString(
            'Aguardando aprovação',
            $fonte,
            'CONTROLE: a sonda não acha nem o título da página — ela está lendo o arquivo errado.',
        );

        // ─── ESTA PÁGINA SÓ LÊ: a escrita começa no 187-09 (o lote) ───
        foreach (['router.put', 'router.patch', 'router.delete', 'router.post', 'axios', 'fetch(', '<form', 'localStorage', 'sessionStorage'] as $proibido) {
            $this->assertStringNotContainsString(
                $proibido,
                $fonte,
                "a página da fila usa `{$proibido}` — neste plano ela só LÊ, e o filtro vive na URL.",
            );
        }
        // ⚠️ E o `router.get` do filtro ESTÁ lá: é ele que põe a etapa na URL (COL-03).
        $this->assertStringContainsString('router.get(', $fonte, 'COL-03: o clique no card tem de filtrar pela URL.');

        // ─── DIVERGÊNCIA DECLARADA: a raiz do protótipo (`:25`) NÃO existe nesta montagem ───
        // A tela vive dentro do `AppLayout`, território PROIBIDO nesta fase, e as duas páginas
        // irmãs que estão NO AR também não declaram `min-h-screen`/`bg-pub-page`. O precedente é
        // MEDIDO abaixo, para ninguém "consertar" de volta sem decisão nova.
        foreach (['min-h-screen', 'bg-pub-page'] as $daRaiz) {
            $this->assertStringNotContainsString(
                $daRaiz,
                $fonte,
                "`{$daRaiz}` entrou na página da fila: ele daria a ELA um fundo/altura que nenhuma outra"
                . ' tela do módulo tem, e isso não é conferível nesta máquina.',
            );
        }
        foreach ([
            'resources/js/Pages/Mlb/Publicador/VisaoGeral.jsx',
            'resources/js/Pages/Mlb/MeusAnuncios.jsx',
        ] as $irma) {
            $absIrma = $this->raiz() . '/' . $irma;
            $this->assertFileExists($absIrma, "gate sobre caminho fantasma: {$irma}");
            $fonteIrma = $this->semComentariosJs((string) file_get_contents($absIrma));
            foreach (['min-h-screen', 'bg-pub-page'] as $daRaiz) {
                $this->assertStringNotContainsString(
                    $daRaiz,
                    $fonteIrma,
                    "a irmã {$irma} declara `{$daRaiz}` — o precedente medido mudou, remeça a divergência.",
                );
            }
            // ⚠️ CONTROLE: as duas irmãs TÊM o par de containers que esta página copiou.
            $this->assertStringContainsString('-mx-6', $fonteIrma, "a irmã {$irma} deixou de usar o `-mx-6` — a montagem mudou.");
        }

        // ─── A GEOMETRIA PRÓPRIA DA FILA (divergência D2) ───
        $this->assertStringContainsString('gap-[16px]', $fonte, 'D2: o `gap:16px` da FILA (`Aprovacao.dc.html:29`).');
        $this->assertStringContainsString('pt-[24px]', $fonte, 'D2: o `padding-top:24px` da FILA.');
        $this->assertStringContainsString('pb-[120px]', $fonte, 'D2: o `padding-bottom:120px` da FILA.');
        $this->assertStringContainsString('max-w-[1600px]', $fonte);
        $this->assertStringContainsString('min-w-[1240px]', $fonte, 'FID-05: 1240px de largura mínima, scroll horizontal em vez de reflow.');
        // ⚠️ E NENHUM dos três da LISTA: "deixei igual à outra tela" é justificativa VETADA.
        foreach (['gap-[18px]', 'pt-[28px]', 'pb-[110px]'] as $daLista) {
            $this->assertStringNotContainsString(
                $daLista,
                $fonte,
                "`{$daLista}` é da LISTA de Publicações (`Publicacoes v2.dc.html:29`), não da FILA — D2 proíbe harmonizar.",
            );
        }
        // ⚠️ CONTROLE: os três da LISTA estão, de verdade, na página da LISTA. Sem isto, as três
        // asserções de ausência acima seriam vazias por construção.
        $fonteDaLista = $this->semComentariosJs(
            (string) file_get_contents($this->raiz() . '/resources/js/Pages/Mlb/MeusAnuncios.jsx'),
        );
        foreach (['gap-[18px]', 'pt-[28px]', 'pb-[110px]'] as $daLista) {
            $this->assertStringContainsString(
                $daLista,
                $fonteDaLista,
                "CONTROLE: `{$daLista}` não está mais na página da LISTA — a divergência D2 precisa ser remedida.",
            );
        }
    }

    /**
     * ⚠️ **O que precisaria estar errado para falhar:** este plano sair do território declarado.
     *
     * ⚠️⚠️ **MEDE OS COMMITS DO PRÓPRIO ESCOPO, NÃO A ÁRVORE.** Três sessões commitam nesta
     * árvore e o `user.name` é o MESMO nas três — **autor não discrimina**. O discriminador é o
     * **escopo do assunto** (`(187-04)`). Range aberto com a isca *"ninguém mais toca aqui"* já
     * acusou a sessão paralela SEIS vezes nesta milestone.
     *
     * @group phase187
     */
    public function test_territorio_do_plano_187_04(): void
    {
        $commits = $this->commitsDoEscopo(self::PARTIDA_04, self::ESCOPO_04);
        $arquivos = $this->arquivosDoEscopo(self::PARTIDA_04, self::ESCOPO_04);

        // ⚠️ ÂNCORA POSITIVA, antes de qualquer negativa: sem ela o gate inteiro passaria sobre
        // ZERO casos (a armadilha nº 4).
        $this->assertNotSame([], $commits, 'nenhum commit com escopo `(187-04)` no range — o gate mediria NADA.');
        $this->assertContains(
            'resources/js/Components/Mlb/Publicador/aprovacao/CabecalhoDaFila.jsx',
            $arquivos,
            'ISCA: o arquivo-âncora deste plano não aparece nos commits do escopo — o `git show` não lê nada.',
        );

        $proibidos = [
            // Os arquivos da Fase 186 em `Aprovacao/` (SEM o `Fila/`, que é da wave 2) e os três
            // `.jsx` do portão: somente leitura para esta fase.
            'app/Services/Publicador/Aprovacao/AndamentoDaOfertaService.php',
            'app/Services/Publicador/Aprovacao/ComposicaoFinanceiraService.php',
            'app/Services/Publicador/Aprovacao/AprovacaoDaOfertaService.php',
            'app/Services/Publicador/Aprovacao/BloqueiosDaOfertaService.php',
            'app/Services/Publicador/Aprovacao/VocabularioDoPortao.php',
            'app/Services/Publicador/Aprovacao/Fila/FilaDeAprovacaoService.php',
            'resources/js/Components/Mlb/Publicador/aprovacao/andamentoDoPortao.jsx',
            'resources/js/Components/Mlb/Publicador/aprovacao/vocabularioDoPortao.jsx',
            'resources/js/Components/Mlb/Publicador/aprovacao/SeloDeAndamentoInterno.jsx',
            // A aba do 187-03: esta página IMPORTA, não altera.
            'resources/js/Components/Mlb/Publicador/publicacoes/AbasDaPagina.jsx',
            // O corpo da LISTA, que está NO AR.
            'resources/js/Pages/Mlb/MeusAnuncios.jsx',
            'app/Http/Controllers/MlbAnuncioController.php',
            // Creative Engine e o editor — território do outro dev.
            'app/Services/Creative',
            'resources/js/Pages/Mlb/AnunciarML.jsx',
            'resources/js/Pages/Mlb/Publicador/Editor.jsx',
            'resources/js/Components/Publicador/Mesa',
            'app/Services/Mlb/Publicacao/MlPublicacaoService.php',
            'app/Support/Publicador',
            'app/Mcp',
            // O header global e os dois gates INTOCÁVEIS.
            'resources/js/Layouts/AppLayout.jsx',
            'resources/js/Components/Mlb/Publicador/header/PublicadorHeader.jsx',
            'tests/js/publicador-header-zindex-ordem.test.js',
            'tests/js/estrutura-meus-anuncios.test.js',
            // Os artefatos de planejamento que este plano não toca.
            '.planning/ROADMAP.md',
            '.planning/STATE.md',
            // Dependências (T-187-SC): este plano não instala nada.
            'package.json',
            'package-lock.json',
            'composer.json',
            'composer.lock',
            // Migrations: esta fase NÃO tem migration.
            'database/migrations',
        ];

        // ⚠️ `test -e` em CADA caminho: caminho fantasma "prova" território intocado medindo NADA.
        foreach ($proibidos as $caminho) {
            $this->assertFileExists($this->raiz() . '/' . $caminho, "gate sobre caminho fantasma: {$caminho}");
        }

        $tocados = $this->cruzarComProibidos($arquivos, $proibidos);
        $this->assertSame([], $tocados, 'Este plano saiu do território declarado: ' . implode(', ', $tocados));

        // ⚠️ ISCA DO CRUZAMENTO: o MESMO medidor ACHA um arquivo que É deste plano, se ele estiver
        // na lista. Sem ela, o `assertSame([])` acima poderia ser vazio porque o cruzamento está
        // cego — a forma nº 1 de gate verde inútil neste projeto.
        $achouOMeu = $this->cruzarComProibidos($arquivos, [
            'resources/js/Components/Mlb/Publicador/aprovacao/CabecalhoDaFila.jsx',
        ]);
        $this->assertNotSame([], $achouOMeu, 'ISCA: o cruzamento não acha nem o arquivo deste plano — ele está cego.');

        // ⚠️ ISCA DO PREFIXO DE SEGMENTO: `app/Mcp` NÃO casa `app/McpOutraCoisa.php`.
        $this->assertSame(
            [],
            $this->cruzarComProibidos(['app/McpOutraCoisa.php'], ['app/Mcp']),
            'ISCA: o cruzamento casa por SUBSTRING — ele acusaria arquivo de nome parecido.',
        );
        $this->assertSame(
            ['app/Mcp/Servidor.php'],
            $this->cruzarComProibidos(['app/Mcp/Servidor.php'], ['app/Mcp']),
            'ISCA: o cruzamento não casa o diretório proibido de verdade.',
        );

        // ⚠️ ISCAS DA SONDA DE ESCOPO, nas três direções que importam.
        $this->assertSame(1, preg_match(self::ESCOPO_04, 'feat(187-04): a pagina da fila'), 'ISCA: a sonda não acha o escopo deste plano.');
        $this->assertSame(0, preg_match(self::ESCOPO_04, 'feat(187-03): as duas abas'), 'ISCA: a sonda acusa a wave anterior desta fase.');
        $this->assertSame(0, preg_match(self::ESCOPO_04, 'feat(197): acervo_imagens por conta do ML'), 'ISCA: a sonda acusa commit de OUTRA sessão.');

        // ⚠️ ESTA FASE NÃO TEM MIGRATION, e o gate é por COMMIT — não por "não rodei o artisan".
        $this->assertSame(
            [],
            $this->cruzarComProibidos($arquivos, ['database/migrations']),
            'Este plano criou migration — a Fase 187 não tem nenhuma.',
        );
    }

    /**
     * ⚠️ **O que precisaria estar errado para falhar:** um método `test_*` deste arquivo ficar com
     * sufixo de um plano que **não tem manifesto no disco** — e aí ele ficaria fora de TODA
     * bijeção, rodando por gate nenhum (foi o sobrevivente da mutação do plano 186-03).
     *
     * ⚠️⚠️ **ESTE CASO É O QUE FECHA O BURACO ABERTO PELO RECORTE POR SUFIXO.** Quando o plano
     * 187-04 recortou `test_bijecao_do_manifesto_187_02` ao sufixo `_187_02` (para não reprovar os
     * métodos `_187_04` do mesmo arquivo), passou a existir uma terceira possibilidade: um método
     * `_187_07` sem manifesto. Sem este gate, ele não reprovaria em lugar nenhum.
     *
     * @group phase187
     */
    public function test_todo_metodo_tem_sufixo_de_plano_declarado_187_04(): void
    {
        $rel = 'tests/Feature/Publicador/Aprovacao/FilaDeAprovacaoRotaTest.php';
        $abs = $this->raiz() . '/' . $rel;
        $this->assertFileExists($abs, "gate sobre caminho fantasma: {$rel}");

        preg_match_all('/public function (test_\w+)\(/', (string) file_get_contents($abs), $m);
        $metodos = $m[1];

        // ⚠️ ÂNCORA: o arquivo tem métodos. Gate sobre lista vazia não prova nada.
        $this->assertGreaterThanOrEqual(10, count($metodos), 'ÂNCORA: achei ' . count($metodos) . ' métodos — a sonda não está lendo o arquivo.');

        $infratores = [];
        foreach ($metodos as $nome) {
            if (preg_match('/_(187_\d{2})$/', $nome, $casou) !== 1) {
                $infratores[] = "SEM SUFIXO DE PLANO: {$nome}";

                continue;
            }
            $manifesto = 'tests/gates/contagens-' . str_replace('_', '-', $casou[1]) . '.txt';
            if (! file_exists($this->raiz() . '/' . $manifesto)) {
                $infratores[] = "SUFIXO SEM MANIFESTO: {$nome} esperaria {$manifesto}";
            }
        }

        $this->assertSame(
            [],
            $infratores,
            "Método fora de toda bijeção — ele rodaria por gate NENHUM:\n" . implode("\n", $infratores),
        );

        // ⚠️ ISCA: a sonda ACUSA as duas formas que ela proíbe, e NÃO acusa a certa.
        $this->assertSame(0, preg_match('/_(187_\d{2})$/', 'test_coisa_sem_sufixo'), 'ISCA: a sonda de sufixo está cega.');
        $this->assertSame(1, preg_match('/_(187_\d{2})$/', 'test_coisa_187_04'), 'ISCA: a sonda recusa o sufixo CERTO.');
        $this->assertFileDoesNotExist(
            $this->raiz() . '/tests/gates/contagens-187-99.txt',
            'a isca do manifesto inexistente virou arquivo de verdade — troque a isca',
        );
    }

    /**
     * ⚠️ **O que precisaria estar errado para falhar:** um método `_187_04` **não ter id** em
     * `contagens-187-04.txt` — e então **nunca rodar** por `verificar-testes.sh` — ou um id estar
     * **órfão**, sendo gate sobre ZERO casos.
     *
     * ⚠️ **UMA asserção, de contagem FIXA, coletando os infratores** — nunca uma por linha do
     * manifesto: asserção por item de lista foi a armadilha que caiu **4 vezes** na Fase 186.
     *
     * @group phase187
     */
    public function test_bijecao_do_manifesto_187_04(): void
    {
        $infratores = $this->bijecaoDoManifesto(
            'tests/gates/contagens-187-04.txt',
            ['tests/Feature/Publicador/Aprovacao/FilaDeAprovacaoRotaTest.php'],
            '_187_04',
        );

        $this->assertSame([], $infratores, "A bijeção ids <-> métodos do 187-04 quebrou:\n" . implode("\n", $infratores));
    }
    // ─────────────────────────────────────────────────────────────────────────────────────────
    // Auxiliares de varredura
    // ─────────────────────────────────────────────────────────────────────────────────────────

    /** @return array<string, string> caminho relativo => conteúdo */
    private function todosOsJsDeResources(): array
    {
        $raiz = $this->raiz();
        $fora = [];
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($raiz . '/resources/js', \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($it as $f) {
            if ($f->isFile() && in_array($f->getExtension(), ['js', 'jsx'], true)) {
                $rel = str_replace('\\', '/', substr($f->getPathname(), strlen($raiz) + 1));
                $fora[$rel] = (string) file_get_contents($f->getPathname());
            }
        }

        return $fora;
    }

    /**
     * O código JS/JSX **sem comentário**. Não há tokenizador de JS no PHP, então é remoção por
     * forma — e por isso os controles do caso que a usa testam as duas direções.
     */
    private function semComentariosJs(string $conteudo): string
    {
        $sem = (string) preg_replace('#/\*.*?\*/#s', '', $conteudo);

        return (string) preg_replace('#(^|[^:\w])//.*$#m', '$1', $sem);
    }
}
