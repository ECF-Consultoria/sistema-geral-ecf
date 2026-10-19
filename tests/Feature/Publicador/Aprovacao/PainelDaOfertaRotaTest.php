<?php

namespace Tests\Feature\Publicador\Aprovacao;

use App\Models\Company;
use App\Models\MlbEmpresa;
use App\Models\MlToken;
use App\Models\PubAprovacaoConjunto;
use App\Models\PubAprovacaoImagem;
use App\Models\PubAprovacaoOferta;
use App\Models\PubImagem;
use App\Models\PubProduto;
use App\Models\PubRascunho;
use App\Models\PubRascunhoAlvo;
use App\Models\User;
use App\Services\Publicador\Aprovacao\AndamentoDaOfertaService;
use App\Services\Publicador\Aprovacao\BloqueiosDaOfertaService;
use App\Services\Publicador\Aprovacao\Fila\ProximoARevisarService;
use App\Services\Publicador\Aprovacao\Painel\PainelDeRevisaoService;
use App\Support\Publicador\Variacao\ChaveCanonica;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 * Fase 188, plano 02 — A PROP `painel` NA ROTA, **recortada ao `?oferta=`**.
 *
 * ⚠️⚠️ DE CADA GATE DESTE ARQUIVO, A PERGUNTA RESPONDIDA ANTES DE ELE EXISTIR:
 * **"o que precisaria estar errado no mundo para ele falhar?"**
 *
 *   · **`?oferta=<id de OUTRA conta>` montando o painel** — seria ler oferta alheia (**T-188-10**).
 *     ⚠️ E a recusa tem de ser **`null` em SILÊNCIO**, nunca 403: um 403 **confirma que a oferta
 *     existe**, que é metade do vazamento.
 *   · **`?oferta[]=x` dando 500** — `(int) []` emite `Array to string conversion`, e isso **já foi
 *     500 nesta tela** (T-180-04-T). A guarda é `is_string && ctype_digit && > 0`, **reusada** do
 *     bloco que já existe na action.
 *   · **O painel sendo montado SEM `?oferta=`** — `PainelDeRevisaoService::para()` lê a composição,
 *     que consulta o Mercado Livre. Montá-lo no caso comum (fila aberta, nenhuma oferta) é custo
 *     puro. ⚠️ É a classe do incidente de **30/07/2026**, quando requisições lentas ocuparam os
 *     workers do php-fpm e **até o login parou**.
 *   · **A fila da 187 regredindo** — o `foco` da linha (`?oferta=`, COL-03) é o MESMO parâmetro que
 *     o painel usa agora. Se o foco parasse de funcionar, a linha deixaria de acender.
 *
 * ⚠️ **ZERO REDE:** `Http::fake()` + `preventStrayRequests()`. O `fake()` sem argumento cobre o
 * ruído GLOBAL do `HandleInertiaRequests` (os sinais do `EcfDriveService` em toda página Inertia),
 * que não é o que estes casos travam.
 *
 * ⚠️ **A PÁGINA NÃO É RESOLVIDA AQUI:** os casos exercitam a action pelo caminho Inertia com o
 * header `X-Inertia` + asserção sobre as **props**, que é o que a suíte do projeto já faz e que
 * **não** carrega o `.jsx`. A asserção de componente resolvido já existe, e é da Fase 187
 * (`FilaDeAprovacaoRotaTest::test_rota_da_fila_resolve_o_componente_da_pagina_187_04`).
 *
 * ⚠️⚠️ **NENHUM EXECUTOR DESTA FASE VIU ESTA TELA RENDERIZADA.** Em produção o painel só aparece a
 * partir do plano 188-07; aqui o que se prova é o **payload**. ⚠️ E a **conferência visual da Fase
 * 187 pelo usuário continua ABERTA** — nada deste arquivo a substitui nem a presume feita.
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 */
class PainelDaOfertaRotaTest extends TestCase
{
    use RefreshDatabase;

    private const CLASSICO = 'gold_special';

    private int $sequencia = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-19 09:00:00');
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
    private function conta(): array
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
            'nome' => 'Empresa Painel '.$company->id,
            'tipo' => 'ASSESSORIA',
            'company_id' => $company->id,
            'responsavel_id' => $admin->id,
        ]);

        return [$company, $empresa, $admin];
    }

    /**
     * ⚠️ `mlb_empresa_id` é gravado **de propósito**: o recorte da fila usa as DUAS âncoras com
     * `AND` (`AndamentoDaOfertaService::filaDaConta()`), e oferta sem a segunda âncora sairia do
     * recorte quando a conta tem `MlbEmpresa` ligada — o painel viria `null` e o caso passaria
     * **medindo o caso errado**. (É a PERGUNTA-17, aberta e com dono na Fase 194: hoje não é bug
     * porque nada cria oferta em produção.)
     */
    private function oferta(Company $company, ?MlbEmpresa $empresa, string $andamento, ?string $entrouEm = '2026-10-19 06:00:00'): PubAprovacaoOferta
    {
        $this->sequencia++;
        $sku = 'PNL-'.$this->sequencia;

        $produto = PubProduto::create([
            'company_id' => $company->id,
            'sku' => $sku,
            'nome' => 'Cadeira '.$sku,
            'origem' => PubProduto::ORIGEM_PUBLICADOR,
        ]);
        $rascunho = PubRascunho::create(['produto_id' => $produto->id]);
        $alvo = PubRascunhoAlvo::create([
            'rascunho_id' => $rascunho->id,
            'listing_type_id' => self::CLASSICO,
            'titulo' => 'Cadeira Gamer '.$sku,
            'posicao' => 0,
        ]);

        $oferta = PubAprovacaoOferta::create([
            'alvo_id' => $alvo->id,
            'rascunho_id' => $rascunho->id,
            'produto_id' => $produto->id,
            'company_id' => $company->id,
            'mlb_empresa_id' => $empresa?->id,
            'andamento' => $andamento,
            'entrou_na_fila_em' => $entrouEm,
        ]);

        // Uma foto aprovada, para a galeria não nascer vazia (IMG-12 daria `sem_fotos`).
        $conjunto = PubAprovacaoConjunto::create([
            'rascunho_id' => $rascunho->id,
            'grupo_chave' => PubAprovacaoConjunto::GERAL,
            'grupo_hash' => ChaveCanonica::hash('GERAL-'.$rascunho->id),
        ]);
        PubAprovacaoImagem::create([
            'conjunto_id' => $conjunto->id,
            'imagem_id' => PubImagem::create(['rascunho_id' => $rascunho->id])->id,
            'tipo' => PubAprovacaoImagem::TIPO_ORIGINAL,
            'estado' => PubAprovacaoImagem::APROVADA,
            'posicao' => 0,
        ]);

        return $oferta->fresh();
    }

    private function versaoInertia(): string
    {
        return (string) app(\App\Http\Middleware\HandleInertiaRequests::class)->version(request());
    }

    /** As props da fila, pelo caminho Inertia — SEM resolver o componente (ver o docblock). */
    private function props(User $admin, Company $company, ?string $query = null): array
    {
        $url = route('mlb.anuncios.meus.aprovacao', $company).($query === null ? '' : '?'.$query);

        return $this->actingAs($admin)
            ->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => $this->versaoInertia()])
            ->get($url)
            ->assertOk()
            ->json('props');
    }

    /**
     * O espião de `PainelDeRevisaoService`, instalado no container.
     *
     * ⚠️ **SUBCLASSE no teste**: o serviço **não é alterado no disco**. É assim que o custo se mede
     * sem tocar no que está sendo medido.
     *
     * ⚠️ **SEM tipo de retorno de propósito:** declarar `PainelDeRevisaoService` esconderia a
     * propriedade `$chamadas` da classe anônima do analisador estático, que passa a reportar
     * *"Undefined property"* em toda leitura do contador — seis erros que não são erros.
     */
    private function espiao()
    {
        $espiao = new class(
            app(BloqueiosDaOfertaService::class),
            app(ProximoARevisarService::class),
            app(AndamentoDaOfertaService::class),
        ) extends PainelDeRevisaoService
        {
            public int $chamadas = 0;

            public function para(PubAprovacaoOferta $oferta, Builder $base): array
            {
                $this->chamadas++;

                return parent::para($oferta, $base);
            }
        };

        $this->app->instance(PainelDeRevisaoService::class, $espiao);

        return $espiao;
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // (1) A ROTA MONTA O PAINEL — e só para a oferta pedida
    // ═════════════════════════════════════════════════════════════════════════════════════════

    public function test_a_rota_monta_o_painel_so_com_oferta_valida_da_conta_188_02(): void
    {
        [$company, $empresa, $admin] = $this->conta();

        $aberta = $this->oferta($company, $empresa, PubAprovacaoOferta::AGUARDANDO_APROVACAO, '2026-10-19 05:00:00');
        $outra = $this->oferta($company, $empresa, PubAprovacaoOferta::AGUARDANDO_APROVACAO, '2026-10-19 07:00:00');
        $preparando = $this->oferta($company, $empresa, PubAprovacaoOferta::PREPARANDO, '2026-10-19 04:00:00');

        // ─── Com `?oferta=<id válido>`: 200, e o painel é **daquela** oferta ──────────────────
        $props = $this->props($admin, $company, 'oferta='.$aberta->id);

        $this->assertArrayHasKey('painel', $props, 'a prop `painel` nem existe no payload');
        $this->assertIsArray($props['painel']);
        $this->assertSame($aberta->id, $props['painel']['oferta_id'], 'o painel veio de OUTRA oferta');
        $this->assertSame('Cadeira Gamer PNL-1', $props['painel']['identificacao']['titulo']);
        $this->assertSame(1, $props['painel']['versao'], 'COL-04 depende da `versao` viajando');

        // ⚠️ E a NAVEGAÇÃO chega como DADO, não como string: é ela que o ↑/↓ do plano 188-07
        // percorre. ⚠️ `preparando` está FORA dela.
        $this->assertSame([$aberta->id, $outra->id], $props['painel']['navegacao']['ids']);
        $this->assertNotContains($preparando->id, $props['painel']['navegacao']['ids'], '`preparando` entrou na navegação');
        $this->assertSame(1, $props['painel']['navegacao']['posicao']);
        $this->assertSame('1 de 2 na fila', $props['painel']['navegacao']['pos']);
        $this->assertNull($props['painel']['navegacao']['anterior_id']);
        $this->assertSame($outra->id, $props['painel']['navegacao']['proxima_id']);

        // ⚠️⚠️ **A FILA DA 187 NÃO REGREDIU:** o `?oferta=` continua dando **foco** à linha
        // (COL-03), e isso é o MESMO parâmetro que o painel passou a usar. ⚠️ E ele **não** conta
        // como filtro — se contasse, abrir uma oferta faria "Limpar filtros" aparecer sem filtro.
        $this->assertFalse($props['algum_filtro'], '`?oferta=` passou a contar como filtro');
        $this->assertSame(3, $props['contagem']['total'], 'a contagem da fila mudou');
        $this->assertIsArray($props['grupos'], 'a árvore da fila sumiu');
        $this->assertIsArray($props['faixa']);
        $this->assertSame(2, $props['contador_da_aba'], 'o contador da aba mudou');

        // ⚠️ O FOCO na linha certa, lido da árvore — é a prova de que o parâmetro ainda serve aos
        // DOIS consumidores (a linha e o painel), e não só ao novo.
        // ⚠️ A ÁRVORE É UMA LISTA PLANA de grupos, e cada nível tem o nome que
        // `GrupoDaFilaService::montar()` dá: `grupos[] → conjuntos[] → ofertas[]`. ⚠️ **MEDIDO:** a
        // primeira redação deste caso andou por `grupos.produtos` e `conjuntos.linhas` — **os dois
        // nomes NÃO existem** —, e o laço saía VAZIO. ➡️ Sem o `assertSame` com a lista esperada
        // (em vez de um `assertNotEmpty`), isso teria passado como gate verde sobre ZERO casos.
        $comFoco = [];
        $linhasVistas = 0;
        foreach ($props['grupos'] as $grupo) {
            // ⚠️ Flag DENTRO do laço.
            foreach ($grupo['conjuntos'] ?? [] as $conjunto) {
                foreach ($conjunto['ofertas'] ?? [] as $linha) {
                    $linhasVistas++;
                    if (($linha['fundo'] ?? null) === 'foco') {
                        $comFoco[] = $linha['id'];
                    }
                }
            }
        }
        // ⚠️ ISCA DO PASSEIO: ele de fato ANDOU pela árvore. Sem esta linha, `$comFoco` vazio
        // seria indistinguível de "os nomes dos níveis estão errados" — que foi o que aconteceu.
        $this->assertSame(3, $linhasVistas, 'o passeio pela árvore não achou as 3 linhas — os nomes dos níveis mudaram');
        $this->assertSame([$aberta->id], $comFoco, 'o `foco` da linha (COL-03, Fase 187) regrediu');

        // ─── A oferta em `preparando`: `pos` null e os três botões SOMEM ─────────────────────
        $doPreparo = $this->props($admin, $company, 'oferta='.$preparando->id);

        $this->assertSame($preparando->id, $doPreparo['painel']['oferta_id']);
        $this->assertNull($doPreparo['painel']['navegacao']['pos'], 'a tela não inventa posição para quem não está na fila');
        $this->assertNull($doPreparo['painel']['navegacao']['posicao']);
        $this->assertSame(
            ['salvar' => false, 'ajustes' => false, 'aprovar' => false, 'proximo' => true],
            $doPreparo['painel']['rodape']['botoes'],
            'fora do `live` os três somem e "Próximo ↓" fica',
        );

        // ⚠️ ISCA DA DISCRIMINAÇÃO: os dois pedidos deram painéis DIFERENTES. Sem esta linha, os
        // dois casos poderiam estar lendo a mesma resposta.
        $this->assertNotSame($props['painel']['oferta_id'], $doPreparo['painel']['oferta_id'], 'o `?oferta=` não discrimina nada');
        $this->assertNotSame($props['painel']['rodape']['botoes'], $doPreparo['painel']['rodape']['botoes']);
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // (2) OS QUATRO CASOS ADVERSOS DE `?oferta=`
    // ═════════════════════════════════════════════════════════════════════════════════════════

    /**
     * ⚠️⚠️ Os quatro caem em **`null`**, com **200** — nunca 500, e nunca 403.
     *
     * ⚠️ **403 CONFIRMA QUE A OFERTA EXISTE**, e isso é metade do vazamento (T-188-10). A recusa é
     * silenciosa: o painel simplesmente não é montado.
     */
    public function test_os_quatro_casos_adversos_de_oferta_caem_em_nulo_sem_500_188_02(): void
    {
        [$company, $empresa, $admin] = $this->conta();
        $minha = $this->oferta($company, $empresa, PubAprovacaoOferta::AGUARDANDO_APROVACAO);

        // ⚠️ A oferta ALHEIA é de OUTRA conta — e ela EXISTE, que é o que torna o caso real.
        [$outraEmpresa, $outraMlb, ] = $this->conta();
        $alheia = $this->oferta($outraEmpresa, $outraMlb, PubAprovacaoOferta::AGUARDANDO_APROVACAO);

        $casos = [
            // ⚠️ `?oferta[]=x` entrega ARRAY, e `(int) []` emite `Array to string conversion` —
            // **já foi 500 nesta tela** (T-180-04-T).
            'array' => 'oferta[]=x',
            // ⚠️ `(int) '3abc'` é `3` em silêncio, e `(int) ''` é `0`: a guarda é `ctype_digit`.
            'nao numerico' => 'oferta=abc',
            'zero' => 'oferta=0',
            'de outra conta' => 'oferta='.$alheia->id,
            'inexistente' => 'oferta=99999999',
            'negativo' => 'oferta=-5',
            'misto' => 'oferta=3abc',
        ];

        // ⚠️ **UMA asserção coletando os infratores**, nunca uma por caso: uma por caso faria a
        // contagem crescer junto com a lista (learnings §4b.2).
        //
        // ⚠️⚠️ **E A SONDA NÃO USA `??` — MEDIDO, porque a primeira redação dela usava e MENTIU.**
        // `['painel' => null]['painel'] ?? 'AUSENTE'` devolve **`'AUSENTE'`**: o `??` dispara no
        // `null` exatamente igual à chave ausente, então ele **não distingue os dois casos** — que
        // é a distinção que este bloco existe para medir. ➡️ `array_key_exists` para a presença e
        // acesso direto para o valor, cada um medindo uma coisa. ⚠️ Os sete casos saíram
        // `=> "AUSENTE"` com a chave **presente e nula**, e isso é falso positivo de gate, não
        // defeito de produto.
        $vazaram = [];
        $semChave = [];
        foreach ($casos as $rotulo => $query) {
            // ⚠️ Flag DENTRO do laço.
            $props = $this->props($admin, $company, $query);

            if (! array_key_exists('painel', $props)) {
                $semChave[] = $rotulo;

                continue;
            }

            if ($props['painel'] !== null) {
                $vazaram[] = $rotulo.' => '.json_encode($props['painel']);
            }
        }
        $this->assertSame([], $vazaram, 'T-188-10 / T-188-12: `?oferta=` adverso montou painel');

        // ⚠️ E a chave **EXISTE** com valor `null` em todos: chave ausente e chave nula são casos
        // DIFERENTES no cliente, e foi chave ausente que derrubou a página em 07/10/2026.
        $this->assertSame([], $semChave, 'a chave `painel` ficou AUSENTE em vez de nula');

        // ⚠️⚠️ ISCA DA PONTA POSITIVA — gate de ausência rodado só com dado adverso é **vazio por
        // construção**: o caminho válido TEM de montar o painel. Sem esta linha, um `painel` sempre
        // `null` passaria em todos os sete casos acima.
        $valido = $this->props($admin, $company, 'oferta='.$minha->id);
        $this->assertIsArray($valido['painel'], 'ISCA: o caminho VÁLIDO não monta painel — os 7 casos acima são vazios');
        $this->assertSame($minha->id, $valido['painel']['oferta_id']);

        // ⚠️ ISCA DA ALHEIA — ela EXISTE de verdade, e é por isso que o `null` dela prova recorte e
        // não "id que não existe".
        $this->assertNotNull(PubAprovacaoOferta::find($alheia->id), 'ISCA: a oferta alheia não existe — o caso de vazamento mede NADA');
        $this->assertNotSame($company->id, $alheia->company_id);

        // ⚠️ E **nunca 403**: a resposta é **200** com `painel` null. 403 confirmaria a existência.
        $this->actingAs($admin)
            ->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => $this->versaoInertia()])
            ->get(route('mlb.anuncios.meus.aprovacao', $company).'?oferta='.$alheia->id)
            ->assertOk();
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // (3) O CUSTO NA ROTA
    // ═════════════════════════════════════════════════════════════════════════════════════════

    /**
     * ⚠️⚠️ **SEM `?oferta=` o painel NÃO é montado** — zero chamada, e é o caso COMUM (abrir a fila).
     *
     * `PainelDeRevisaoService::para()` lê a composição, que consulta `/sites/MLB/listing_prices`.
     * Montá-lo "para já deixar pronto" seria custo puro na abertura de toda fila. ⚠️ É a classe do
     * incidente de **30/07/2026**.
     */
    public function test_sem_oferta_nenhuma_chamada_extra_ao_mercado_livre_188_02(): void
    {
        [$company, $empresa, $admin] = $this->conta();

        // ⚠️ **TRÊS** ofertas ao vivo: com uma só, "zero chamada" não se distingue de "uma por
        // oferta" — a fixture precisa discriminar.
        $primeira = $this->oferta($company, $empresa, PubAprovacaoOferta::AGUARDANDO_APROVACAO, '2026-10-19 04:00:00');
        $this->oferta($company, $empresa, PubAprovacaoOferta::AGUARDANDO_APROVACAO, '2026-10-19 05:00:00');
        $this->oferta($company, $empresa, PubAprovacaoOferta::AGUARDANDO_APROVACAO, '2026-10-19 06:00:00');

        // ─── SEM `?oferta=`: **ZERO** ────────────────────────────────────────────────────────
        $espiao = $this->espiao();
        $semOferta = $this->props($admin, $company);

        $this->assertSame(0, $espiao->chamadas, 'o painel foi montado SEM `?oferta=` — custo puro em toda abertura de fila');
        $this->assertNull($semOferta['painel'], 'sem `?oferta=` a prop é null');
        $this->assertTrue(array_key_exists('painel', $semOferta), 'a chave `painel` tem de EXISTIR, com valor null');
        // ⚠️ E a fila inteira continua montada: "zero chamada do painel" não pode ser "página vazia".
        $this->assertSame(3, $semOferta['contador_da_aba'], 'a fila não foi montada — o caso mediria NADA');

        // ─── COM `?oferta=`: **EXATAMENTE UMA** ──────────────────────────────────────────────
        $comEspiao = $this->espiao();
        $comOferta = $this->props($admin, $company, 'oferta='.$primeira->id);

        $this->assertSame(1, $comEspiao->chamadas, '`para()` tem de ser chamado UMA vez, nem zero nem por oferta da fila');
        $this->assertIsArray($comOferta['painel']);
        $this->assertSame($primeira->id, $comOferta['painel']['oferta_id']);

        // ⚠️ ISCA DO ESPIÃO, nas duas direções: ele **CONTA**. Sem isto, "0" e "1" poderiam ser o
        // valor de um espião cego, e o gate inteiro seria vazio por construção.
        $this->assertSame(0, $espiao->chamadas, 'o espião da PRIMEIRA requisição foi reusado — os dois casos se contaminaram');
        $antes = $comEspiao->chamadas;
        $comEspiao->para($primeira->fresh(), app(AndamentoDaOfertaService::class)->filaDaConta($company->id, $empresa->id));
        $this->assertSame($antes + 1, $comEspiao->chamadas, 'ISCA: o espião não conta');

        // ⚠️⚠️ E a prova de que o espião está **DE FATO no caminho da action** (e não num ramo
        // morto): o `painel` da resposta é o que ELE devolveu. Se o container estivesse resolvendo
        // o serviço real, `chamadas` seria 0 **com** painel montado — o falso verde mais fácil
        // deste gate.
        $naoFoiPeloEspiao = $comOferta['painel'] !== null && $comEspiao->chamadas === 0;
        $this->assertFalse($naoFoiPeloEspiao, 'o painel foi montado por FORA do espião — o container não está injetando o dublê');

        // ⚠️⚠️ **E NADA VAI AO MERCADO LIVRE PELA APROVAÇÃO** — o painel APROVA (e aprovar
        // ENFILEIRA), **NÃO PUBLICA**: a escrita no ML é da **Fase 193** (critério 18). Provado por
        // **AUSÊNCIA de requisição de escrita**, não por leitura de código.
        $escritas = [];
        foreach (Http::recorded() as [$requisicao, ]) {
            // ⚠️ Flag DENTRO do laço.
            if (! in_array(strtoupper($requisicao->method()), ['GET', 'HEAD'], true)) {
                $escritas[] = strtoupper($requisicao->method()).' '.$requisicao->url();
            }
        }
        $this->assertSame([], $escritas, 'critério 18: saiu requisição de ESCRITA — o painel não publica nada');

        // ⚠️ ISCA DO GRAVADOR, nas duas direções: ele registra um POST. Sem isto, "[]" seria
        // indistinguível de gravador cego — e esta é a prova por ausência mais importante do plano.
        Http::post('https://api.mercadolibre.com/items', ['title' => 'x']);
        $depois = [];
        foreach (Http::recorded() as [$requisicao, ]) {
            if (! in_array(strtoupper($requisicao->method()), ['GET', 'HEAD'], true)) {
                $depois[] = strtoupper($requisicao->method()).' '.$requisicao->url();
            }
        }
        $this->assertSame(['POST https://api.mercadolibre.com/items'], $depois, 'ISCA: o gravador não registra escrita — a prova por ausência é vazia');
    }
}
