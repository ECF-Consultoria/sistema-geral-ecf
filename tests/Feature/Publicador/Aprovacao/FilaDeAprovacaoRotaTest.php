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
     * @return array<string, string> hash => assunto
     */
    private function commitsDoEscopo(): array
    {
        $linhas = array_filter(explode("\n", $this->git('git log --no-merges --format=%H%x09%s ' . self::PARTIDA . '..HEAD')));

        $meus = [];
        foreach ($linhas as $linha) {
            [$hash, $assunto] = array_pad(explode("\t", $linha, 2), 2, '');
            if (preg_match(self::ESCOPO, $assunto) === 1) {
                $meus[$hash] = $assunto;
            }
        }

        return $meus;
    }

    /** Os arquivos tocados pelos commits deste plano. */
    private function arquivosDoEscopo(): array
    {
        $arquivos = [];
        foreach (array_keys($this->commitsDoEscopo()) as $hash) {
            foreach (array_filter(explode("\n", $this->git("git show --name-only --format= {$hash}"))) as $nome) {
                $arquivos[trim($nome)] = true;
            }
        }

        return array_keys($arquivos);
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
     * @group phase187
     */
    public function test_bijecao_do_manifesto_187_02(): void
    {
        $manifesto = $this->raiz() . '/tests/gates/contagens-187-02.txt';
        $this->assertFileExists($manifesto, 'gate sobre caminho fantasma: o manifesto deste plano');

        $arquivosDeTeste = [
            'tests/Unit/Publicador/Aprovacao/FilaDeAprovacaoServiceTest.php',
            'tests/Feature/Publicador/Aprovacao/FilaDeAprovacaoRotaTest.php',
        ];

        // Os métodos DESTE plano, lidos do disco.
        $metodos = [];
        foreach ($arquivosDeTeste as $rel) {
            $abs = $this->raiz() . '/' . $rel;
            $this->assertFileExists($abs);
            preg_match_all('/public function (test_\w+)\(/', (string) file_get_contents($abs), $m);
            foreach ($m[1] as $nome) {
                $metodos[$nome] = $rel;
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
        $this->assertNotSame([], $metodos, 'ISCA: nenhum método `test_*` lido dos arquivos deste plano.');
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

        $this->assertSame([], $infratores, "A bijeção ids <-> métodos quebrou:\n" . implode("\n", $infratores));

        // ⚠️ E o sufixo próprio: nome de guarda genérico COLIDE com homônimo de outro arquivo e o
        // `--filter` SOMA as contagens dos dois, estourando o número do manifesto em silêncio.
        $semSufixo = array_values(array_filter(array_keys($metodos), fn (string $n) => ! str_ends_with($n, '_187_02')));
        $this->assertSame([], $semSufixo, 'Método sem o sufixo `_187_02`: o `--filter` pode somar homônimos de outro arquivo.');
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
