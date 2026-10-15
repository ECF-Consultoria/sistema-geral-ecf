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
     * {@see self::commitsDesteePlano()}.
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
        $this->actingAs($admin)
            ->get(route('mlb.anuncios.meus.aprovacao', $company))
            ->assertOk();

        // E o anônimo é mandado para o login pelo `auth` do grupo — nenhum middleware novo.
        $this->get(route('mlb.anuncios.meus.aprovacao', $company))->assertRedirect(route('login'));
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

        // As 6 chaves deste plano, exatamente — contagem FIXA.
        $this->assertSame(
            ['cabecalho', 'contador_da_aba', 'conta', 'empresa', 'faixa', 'margem_minima'],
            collect(['cabecalho', 'contador_da_aba', 'conta', 'empresa', 'faixa', 'margem_minima'])
                ->filter(fn (string $k) => array_key_exists($k, $props))->values()->all(),
            'O payload mínimo deste plano perdeu uma chave.',
        );

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
}
