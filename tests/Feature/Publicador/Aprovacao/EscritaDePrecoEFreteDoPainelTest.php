<?php

namespace Tests\Feature\Publicador\Aprovacao;

use App\Models\Company;
use App\Models\MlbEmpresa;
use App\Models\MlToken;
use App\Models\PubAprovacaoAjuste;
use App\Models\PubAprovacaoComposicao;
use App\Models\PubAprovacaoConjunto;
use App\Models\PubAprovacaoDefeito;
use App\Models\PubAprovacaoEvento;
use App\Models\PubAprovacaoFrete;
use App\Models\PubAprovacaoImagem;
use App\Models\PubAprovacaoImagemAlerta;
use App\Models\PubAprovacaoOferta;
use App\Models\PubImagem;
use App\Models\PubProduto;
use App\Models\PubRascunho;
use App\Models\PubRascunhoAlvo;
use App\Models\User;
use App\Services\Portal\Estrutura\EstruturaPrecificacaoService;
use App\Services\Publicador\Aprovacao\AndamentoDaOfertaService;
use App\Services\Publicador\Aprovacao\AprovacaoDaOfertaService;
use App\Services\Publicador\Aprovacao\ComposicaoFinanceiraService;
use App\Services\Publicador\Aprovacao\Painel\PainelDeRevisaoService;
use App\Services\Publicador\Aprovacao\Painel\PrecoDoPainelService;
use App\Services\Publicador\ClienteMlPublicador;
use App\Services\Publicador\DadosEfetivosService;
use App\Support\Publicador\Variacao\ChaveCanonica;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 * Fase 188, plano 06 — SALVAR O PREÇO SIMULADO (FIN-11/COL-04/BLOQ-06), CONFERIR A ALTERAÇÃO
 * (BLOQ-07), CONFIRMAR O FRETE (MOD-03/DADO-13), O **TRANSPORTE** DA SIMULAÇÃO e os **TRÊS
 * PAYLOADS DOS MODAIS** — tudo **pelas rotas**.
 *
 * ⚠️⚠️ DE CADA GATE DESTE ARQUIVO, A PERGUNTA RESPONDIDA ANTES DELE EXISTIR:
 * **"o que precisaria estar errado no mundo para ele falhar?"**
 *
 *   · **O aviso de alteração ficar de pé depois de salvar o preço.** Um gate que só olhasse a
 *     `versao` passaria com `preco_alterado` esquecido — e a oferta ficaria **bloqueada por uma
 *     alteração que a pessoa acabou de fazer**. ➡️ Os TRÊS efeitos são medidos **separadamente**.
 *   · **O histórico ficar VAZIO numa oferta ao vivo.** Medido:
 *     `AprovacaoDaOfertaService::registrarPrecoSalvo()` registra a CONSEQUÊNCIA (*"Aprovação
 *     reaberta…"*) **só quando a oferta estava `aprovado`** — quem narra o ATO é a action, como
 *     `HistoricoDaOfertaService:160`-`:162` escreve. Sem a narração, salvar preço numa oferta
 *     `aguardando_aprovacao` não deixaria **nenhum** rastro.
 *   · **"Conferir alteração" REAPLICAR o preço.** O nome engana (`:1170` só limpa o aviso), e
 *     "completar" a função trocaria o preço de verdade, sem desfazer. ➡️ Há asserção de que o
 *     preço ficou **IGUAL**.
 *   · **A simulação ser PERSISTIDA.** Critério 7, e é o mais fácil de errar: ela nunca é gravada
 *     nem enviada ao ML, e **só pode ser salva no `live`** (mais `aprovado`, que REABRE).
 *   · **A prévia do frete GRAVAR.** Ela é leitura pura; gravar ali trocaria o frete de quem só
 *     estava conferindo quanto daria.
 *   · **A prévia MENTIR.** Ela não é `ComposicaoFinanceiraService::para()` com o frete
 *     substituído (aquela assinatura **não existe** — ver o docblock de
 *     {@see PrecoDoPainelService::previaDoFrete()}), então a premissa fica travada por **PONTO
 *     FIXO**: com o valor igual ao frete vigente, ela devolve **exatamente** o que a composição
 *     real devolve.
 *   · **Uma ESCRITA no Mercado Livre saindo daqui** — a fronteira do critério 18.
 *   · **Chave de modal AUSENTE.** Chave ausente e lista vazia são casos **diferentes** no
 *     cliente, e foi chave ausente que derrubou esta página em 07/10/2026.
 *
 * ═══ ⚠️⚠️ O QUE SAI PELA REDE, E POR QUE A RÉGUA É "ZERO ESCRITA", NÃO "ZERO" ═════════════════
 *
 * As actions que leem a composição (`preco/simular`, `frete/previa`, `preco`) disparam o GET
 * público `/sites/MLB/listing_prices` — a **tarifa REAL** da decisão 9 do usuário, com cache de
 * 1 h pré-aquecido por job. ➡️ Afirmar "zero requisição" seria um gate que **o próprio desenho
 * contradiz**, e que o executor seguinte "consertaria" pré-aquecendo o cache. ➡️ A régua honesta:
 * **nenhuma requisição que não seja GET, e nenhuma URL de `/items`** (o endpoint de publicação),
 * com a leitura de tarifa numa **lista CONSTANTE** nomeada — nunca um filtro largo.
 *
 * ⚠️⚠️ **NENHUM EXECUTOR DESTA FASE VIU ESTA TELA RENDERIZADA.** O banco local não tem empresa
 * com `ml_token` e `pub_aprovacao_ofertas` está vazia. ⚠️ A conferência visual da Fase 187 pelo
 * usuário continua **ABERTA**.
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 */
class EscritaDePrecoEFreteDoPainelTest extends TestCase
{
    use RefreshDatabase;

    private const CLASSICO = 'gold_special';

    private const PREMIUM = 'gold_pro';

    private const CATEGORIA = 'MLB189007';

    /** A única leitura de rede que esta fase autoriza — a tarifa real (decisão 9 do usuário). */
    private const LEITURAS_AUTORIZADAS = ['/sites/MLB/listing_prices'];

    private ?DadosEfetivosService $efetivos = null;

    private int $sequencia = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-19 11:30:00');

        Http::preventStrayRequests();
        Cache::put('ml_app_token_coleta', 'fake-app-token', 3600);

        // ⚠️ O stub da tarifa é a fixture REAL capturada do Mercado Livre em 01/10/2026 — é o que
        // faz a composição fechar com número de verdade em vez de `Pendente`.
        Http::fake(function (Request $req) {
            $caminho = (string) parse_url($req->url(), PHP_URL_PATH);
            parse_str((string) parse_url($req->url(), PHP_URL_QUERY), $query);

            if ($caminho === '/sites/MLB/listing_prices') {
                $tipo = (string) ($query['listing_type_id'] ?? '');
                $arquivo = base_path('tests/fixtures-ml/sondagem/publico/categorias/'.self::CATEGORIA."/listing_prices_{$tipo}.json");

                return is_file($arquivo)
                    ? Http::response(json_decode((string) file_get_contents($arquivo), true)['resposta'], 200)
                    : Http::response(['message' => 'sem fixture para '.$tipo], 404);
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
            'nome' => 'Empresa Preço '.$company->id,
            'tipo' => 'ASSESSORIA',
            'company_id' => $company->id,
            'responsavel_id' => $admin->id,
        ]);

        return [$company, $empresa, $admin];
    }

    /**
     * ⚠️ **FIXTURE QUE DISCRIMINA.** Duas ofertas no MESMO rascunho (Clássico e Premium), porque
     * com uma só a reabertura do conjunto e o escopo do ajuste são inobserváveis. O dublê de
     * `DadosEfetivosService` é **por produto**, conserto de um falso negativo medido em 15/10/2026
     * na Fase 186: com um conjunto único de custos, o ÚLTIMO `cenario()` montado sobrescrevia os
     * anteriores e a oferta "com bloqueio" era avaliada com a conta da oferta limpa.
     *
     * @param  array<string, mixed>  $o
     * @return array<string, mixed>
     */
    private function cenario(array $o = []): array
    {
        $this->sequencia++;
        /** @var Company $company */
        $company = $o['company'];
        $empresa = $o['empresa'] ?? null;

        $produto = PubProduto::create([
            'company_id' => $company->id,
            'sku' => 'PRC-'.$this->sequencia,
            'nome' => 'Poltrona do preço',
            'origem' => PubProduto::ORIGEM_PUBLICADOR,
            'quantidade_kit' => $o['unidades'] ?? 1,
        ]);
        $rascunho = PubRascunho::create(['produto_id' => $produto->id, 'categoria_id' => self::CATEGORIA]);

        $ofertas = [];
        foreach ([self::CLASSICO, self::PREMIUM] as $i => $tipo) {
            $alvo = PubRascunhoAlvo::create([
                'rascunho_id' => $rascunho->id,
                'listing_type_id' => $tipo,
                'titulo' => $o['titulo'] ?? 'Poltrona Beny Decorativa Veludo Cinza',
                'posicao' => $i,
            ]);
            $ofertas[$tipo] = PubAprovacaoOferta::create([
                'alvo_id' => $alvo->id,
                'rascunho_id' => $rascunho->id,
                'produto_id' => $produto->id,
                'company_id' => $company->id,
                // ⚠️ A segunda âncora, de propósito: `paraAConta()` recorta com as DUAS em `AND`.
                'mlb_empresa_id' => $empresa?->id,
                'andamento' => PubAprovacaoOferta::AGUARDANDO_APROVACAO,
                'entrou_na_fila_em' => '2026-10-19 08:00:00',
                'responsavel_id' => $o['responsavel_id'] ?? null,
                'preco_alterado' => $o['preco_alterado'] ?? null,
            ]);
        }

        $conjunto = PubAprovacaoConjunto::create([
            'rascunho_id' => $rascunho->id,
            'grupo_chave' => PubAprovacaoConjunto::GERAL,
            'grupo_hash' => ChaveCanonica::hash(PubAprovacaoConjunto::GERAL.'-'.$rascunho->id),
        ]);
        $primeira = null;
        for ($i = 0; $i < ($o['fotos_n'] ?? 3); $i++) {
            $img = $this->imagem($conjunto, PubAprovacaoImagem::APROVADA, $i);
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

        // ⚠️ `array_key_exists`, NUNCA `??`: `['custo' => null]` significa "a Precificação está sem
        // custo", que é um CENÁRIO, não um default ausente.
        $this->efetivos->custosPorProduto[(int) $produto->id] = [
            'custo' => array_key_exists('custo', $o) ? $o['custo'] : 20.0,
            'origem' => 'digitado',
            'imposto' => array_key_exists('imposto', $o) ? $o['imposto'] : 10.0,
            'por_variante' => [],
        ];
        $frete = array_key_exists('frete', $o) ? $o['frete'] : 10.0;
        $preco = $o['preco'] ?? 100.0;
        $porTipo = [];
        $precos = [];
        foreach ([self::CLASSICO, self::PREMIUM] as $tipo) {
            $porTipo[$tipo] = ['valor' => $frete, 'origem' => $frete === null ? null : 'tabela', 'oferta_id' => null];
            $precos[$tipo] = $preco;
        }
        $this->efetivos->fretesPorProduto[(int) $produto->id] = ['por_tipo' => $porTipo, 'por_variante' => []];
        $this->efetivos->precosPorProduto[(int) $produto->id] = ['precos' => $precos];

        return [
            'produto' => $produto->fresh(),
            'rascunho' => $rascunho,
            'conjunto' => $conjunto->refresh(),
            'classico' => $ofertas[self::CLASSICO]->fresh(),
            'premium' => $ofertas[self::PREMIUM]->fresh(),
        ];
    }

    private function imagem(PubAprovacaoConjunto $c, string $estado, ?int $posicao): PubAprovacaoImagem
    {
        $crua = PubImagem::create([
            'rascunho_id' => $c->rascunho_id,
            'caminho' => 'fotos/foto-'.(PubImagem::count() + 1).'.jpg',
        ]);
        $img = PubAprovacaoImagem::create([
            'conjunto_id' => $c->id,
            'imagem_id' => $crua->id,
            'tipo' => PubAprovacaoImagem::TIPO_ORIGINAL,
            'estado' => $estado,
            'posicao' => $posicao,
        ]);
        PubAprovacaoImagemAlerta::create([
            'aprovacao_imagem_id' => $img->id,
            'severidade' => PubAprovacaoImagemAlerta::OK,
            'texto' => 'Formato e cor compatíveis',
        ]);

        return $img->fresh();
    }

    // ─────────────────────────────────────────────────────────────────────────────────────────
    // Apoio
    // ─────────────────────────────────────────────────────────────────────────────────────────

    private function url(string $sufixo, Company $c, PubAprovacaoOferta $o): string
    {
        return route('mlb.anuncios.meus.aprovacao.painel.'.$sufixo, ['company' => $c->id, 'oferta' => $o->id]);
    }

    /** O andamento é movido pela ÚNICA PORTA — este teste **nunca** escreve a coluna. */
    private function levarAo(PubAprovacaoOferta $oferta, string $destino, ?User $quem = null): PubAprovacaoOferta
    {
        $porta = app(AndamentoDaOfertaService::class);
        $caminho = match ($destino) {
            PubAprovacaoOferta::AGUARDANDO_APROVACAO => [],
            PubAprovacaoOferta::EM_REVISAO_ECF => [PubAprovacaoOferta::EM_REVISAO_ECF],
            PubAprovacaoOferta::AJUSTES_SOLICITADOS => [PubAprovacaoOferta::AJUSTES_SOLICITADOS],
            PubAprovacaoOferta::APROVADO => [PubAprovacaoOferta::APROVADO],
            PubAprovacaoOferta::PUBLICANDO => [PubAprovacaoOferta::APROVADO, PubAprovacaoOferta::PUBLICANDO],
            PubAprovacaoOferta::FALHA => [PubAprovacaoOferta::APROVADO, PubAprovacaoOferta::PUBLICANDO, PubAprovacaoOferta::FALHA],
            PubAprovacaoOferta::PUBLICADO => [PubAprovacaoOferta::APROVADO, PubAprovacaoOferta::PUBLICANDO, PubAprovacaoOferta::PUBLICADO],
            default => throw new \LogicException('andamento sem caminho declarado: '.$destino),
        };

        foreach ($caminho as $passo) {
            if ($passo === PubAprovacaoOferta::PUBLICADO) {
                $oferta->update(['ml_item_id' => 'MLB1234567890']);
                $oferta = $oferta->fresh();
            }
            $motivo = $passo === PubAprovacaoOferta::FALHA ? 'O Mercado Livre não respondeu a tempo.' : null;
            $oferta = $porta->mover($oferta, $passo, $quem, $motivo);
        }

        return $oferta;
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

    /**
     * As requisições que NÃO são leitura autorizada: tudo que não for GET, e todo GET para fora
     * da lista CONSTANTE. ⚠️ Devolve `METODO URL` para a falha não exigir adivinhação.
     *
     * @return list<string>
     */
    private function escritasEDesvios(): array
    {
        $fora = [];
        foreach (Http::recorded() as [$req]) {
            // ⚠️ `Http::recorded()` é **Collection**, não array (medido na wave 188-05):
            // `array_map` sobre ela LANÇA. O `foreach` é o caminho.
            $metodo = $req->method();
            $caminho = (string) parse_url($req->url(), PHP_URL_PATH);
            if ($metodo !== 'GET' || ! in_array($caminho, self::LEITURAS_AUTORIZADAS, true)) {
                $fora[] = $metodo.' '.$req->url();
            }
        }

        return $fora;
    }

    private function requisicoesDeTarifa(): int
    {
        $n = 0;
        foreach (Http::recorded() as [$req]) {
            $n += (string) parse_url($req->url(), PHP_URL_PATH) === '/sites/MLB/listing_prices' ? 1 : 0;
        }

        return $n;
    }

    private function totalDeRequisicoes(): int
    {
        // ⚠️ `Http::recorded()` é **Collection**, não array (medido na wave 188-05).
        return Http::recorded()->count();
    }

    /**
     * ⚠️⚠️ **A ISCA DO ESPIÃO, para as rotas que fazem ZERO requisição.**
     *
     * `salvarPreco()` e `conferirAlteracao()` **não leem a composição** (o preço de origem sai de
     * `precoDoAlvo()`, que lê a Precificação), então nelas o zero é LITERAL — e aí
     * `assertSame(0, …)` seria verdade **por omissão** se o gravador estivesse desligado. ➡️ Uma
     * requisição DELIBERADA tem de aparecer. Chamar isto no FIM do caso é de propósito: ele
     * polui o gravador.
     */
    private function provarQueOEspiaoVe(): void
    {
        $antes = $this->totalDeRequisicoes();
        Http::get('https://api.mercadolibre.com/sites/MLB/listing_prices?listing_type_id=isca');
        $this->assertSame($antes + 1, $this->totalDeRequisicoes(), 'o espião de rede está CEGO: nem uma requisição deliberada foi vista');
    }

    /**
     * Os contadores de ESCRITA do portão, todos de uma vez. ⚠️ Nenhum deles pode mexer numa
     * leitura — e a **ISCA** de cada caso de leitura é provar que eles MEXEM numa escrita (senão
     * o "nada mudou" seria verdade por o contador estar cego).
     *
     * @return array<string, int>
     */
    private function contadoresDeEscrita(PubAprovacaoOferta $oferta): array
    {
        $fresca = $oferta->fresh();

        return [
            'versao' => (int) $fresca->versao,
            'eventos' => PubAprovacaoEvento::query()->count(),
            'fretes' => PubAprovacaoFrete::query()->count(),
            'composicoes' => PubAprovacaoComposicao::query()->count(),
            'ajustes' => PubAprovacaoAjuste::query()->count(),
            'aviso_de_alteracao' => $fresca->preco_alterado === null ? 0 : 1,
        ];
    }

    /** As props do painel, pelo caminho Inertia — SEM resolver o componente. */
    private function painel(User $admin, Company $company, PubAprovacaoOferta $oferta): array
    {
        $versao = (string) app(\App\Http\Middleware\HandleInertiaRequests::class)->version(request());

        return $this->actingAs($admin)
            ->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => $versao])
            ->get(route('mlb.anuncios.meus.aprovacao', $company).'?oferta='.$oferta->id)
            ->assertOk()
            ->json('props.painel');
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // (1) SALVAR O PREÇO — os TRÊS efeitos, separadamente, e a reabertura da APROVADA
    // ═════════════════════════════════════════════════════════════════════════════════════════

    public function test_salvar_preco_tem_os_tres_efeitos_e_reabre_a_aprovada_pela_unica_porta_188_06(): void
    {
        [$company, $empresa, $admin] = $this->conta();

        // ⚠️ O aviso de alteração DE PÉ na partida: sem ele, "o aviso foi limpo" seria verdade
        // por ele nunca ter existido — asserção vazia por construção.
        $c = $this->cenario([
            'company' => $company,
            'empresa' => $empresa,
            'preco_alterado' => ['de' => 90.0, 'para' => 100.0, 'quem' => 'Importação', 'quando' => '2026-10-18 09:00:00'],
        ]);
        $oferta = $c['classico'];

        $this->assertNotNull($oferta->preco_alterado, 'a fixture não tem aviso de alteração: o caso (2) seria vazio');
        $versaoAntes = (int) $oferta->versao;

        $this->actingAs($admin)
            ->post($this->url('preco', $company, $oferta), ['preco' => '129,90'])
            ->assertRedirect();

        $salva = $oferta->fresh();

        // (1) A VERSÃO SUBIU.
        $this->assertSame($versaoAntes + 1, (int) $salva->versao, 'efeito 1: a versão não subiu');

        // (2) O AVISO DE ALTERAÇÃO FOI LIMPO. ⚠️ Medido SEPARADAMENTE de propósito: um gate que
        // só olhasse a versão passaria com o aviso de pé, e a oferta ficaria bloqueada por uma
        // alteração que a pessoa acabou de fazer.
        $this->assertNull($salva->preco_alterado, 'efeito 2: o aviso de alteração ficou de pé');

        // (3) O ATO ESTÁ NO HISTÓRICO, na frase do protótipo (`:1169`) e com a versão RESULTANTE.
        $esperada = app(PrecoDoPainelService::class)->registroDePrecoSalvo(100.0, 129.9, (int) $salva->versao);
        $this->assertSame(
            'Alterou o preço de R$ 100,00 para R$ 129,90 na revisão · versão '.$salva->versao,
            $esperada,
            'a frase do histórico saiu da forma de `:1169`',
        );
        $this->assertContains($esperada, $this->eventos($salva), 'efeito 3: o ato não foi registrado');

        // ⚠️ E o TIPO do evento é `change` (`:1169`), não `ok`: o filtro por tipo tem de distinguir
        // "alterou o preço" de "aprovou".
        $this->assertSame(
            PubAprovacaoEvento::ALTERACAO,
            (string) PubAprovacaoEvento::query()->where('oferta_id', $salva->id)->latest('id')->first()->tipo,
            'o evento do preço entrou com o tipo errado',
        );

        // ⚠️ A IRMÃ **NÃO** é alcançada: preço é escopo de OFERTA (`MUDANCAS[preco_salvo]`), e só
        // foto alcança o conjunto. Com uma oferta só isto seria inobservável.
        $this->assertSame(1, (int) $c['premium']->fresh()->versao, 'preço vazou para a oferta irmã');
        $this->assertSame([], $this->eventos($c['premium']->fresh()), 'a irmã recebeu evento de preço');

        // ─── A APROVADA VOLTA PARA `awaiting` — PELA ÚNICA PORTA ───────────────────────────
        $c2 = $this->cenario(['company' => $company, 'empresa' => $empresa]);
        $aprovada = $this->levarAo($c2['classico'], PubAprovacaoOferta::APROVADO, $admin);
        $this->assertSame(PubAprovacaoOferta::APROVADO, $aprovada->andamento, 'a fixture não chegou a `aprovado`');

        $this->actingAs($admin)
            ->post($this->url('preco', $company, $aprovada), ['preco' => '149,90'])
            ->assertRedirect();

        $this->assertSame(
            PubAprovacaoOferta::AGUARDANDO_APROVACAO,
            (string) $aprovada->fresh()->andamento,
            'BLOQ-06: salvar preço numa oferta APROVADA tem de reabrir a aprovação',
        );
        // ⚠️ E a reabertura tem o seu PRÓPRIO evento — o da rotina única de reabertura, que é um
        // fato diferente do ato. Os dois convivem (é o padrão de `RevisaoDeImagemService`).
        $textos = $this->eventos($aprovada->fresh());
        $this->assertNotEmpty(
            array_filter($textos, static fn (string $t): bool => str_contains($t, AprovacaoDaOfertaService::REABERTA_PREFIXO)),
            'a reabertura não deixou rastro no histórico',
        );

        // ⚠️⚠️ **MEDIDO, e é mais forte que a régua geral desta fase:** `salvarPreco()` faz
        // **ZERO** requisição de qualquer tipo — o preço de origem sai de `precoDoAlvo()`, que lê
        // a Precificação, e `registrarPrecoSalvo()` não toca na composição. ➡️ Aqui o zero é
        // LITERAL, como nas três rotas de imagem do plano 188-05 (a régua "zero ESCRITA" existe
        // para as rotas que LEEM a tarifa real — `preco/simular`, `frete/previa` e `frete`).
        $this->assertSame([], $this->escritasEDesvios(), 'saiu ESCRITA para o Mercado Livre ao salvar preço');
        $this->assertSame(0, $this->totalDeRequisicoes(), 'salvar preço falou com o Mercado Livre');
        $this->provarQueOEspiaoVe();
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // (2) CONFERIR ALTERAÇÃO — **sem mexer no preço** (BLOQ-07)
    // ═════════════════════════════════════════════════════════════════════════════════════════

    public function test_conferir_alteracao_confere_sem_mexer_no_preco_188_06(): void
    {
        [$company, $empresa, $admin] = $this->conta();
        $c = $this->cenario([
            'company' => $company,
            'empresa' => $empresa,
            'preco_alterado' => ['de' => 90.0, 'para' => 100.0, 'quem' => 'Importação', 'quando' => '2026-10-18 09:00:00'],
        ]);
        $oferta = $c['classico'];

        $financeira = app(ComposicaoFinanceiraService::class);
        $precoAntes = $financeira->para($oferta)->preco;
        $this->assertSame(100.0, $precoAntes, 'a fixture não tem o preço esperado');
        $versaoAntes = (int) $oferta->versao;

        $this->actingAs($admin)
            ->post($this->url('conferir-alteracao', $company, $oferta))
            ->assertRedirect();

        $conferida = $oferta->fresh();

        // ⚠️⚠️ **A ASSERÇÃO QUE IMPEDE ALGUÉM DE "COMPLETAR" A FUNÇÃO:** o preço ficou IGUAL.
        $this->assertSame($precoAntes, $financeira->para($conferida)->preco, 'BLOQ-07: conferir ALTEROU o preço');

        // ⚠️ E a versão NÃO sobe: conferir não é mudança relevante (se subisse, a aprovação
        // reabriria por um ato que não mudou nada).
        $this->assertSame($versaoAntes, (int) $conferida->versao, 'conferir alteração incrementou a versão');

        $this->assertNull($conferida->preco_alterado, 'o aviso de alteração não foi limpo');
        $this->assertNotNull($conferida->preco_conferido_em, 'a data da conferência não foi gravada');
        $this->assertContains(
            'Conferiu a alteração de preço da versão '.$versaoAntes,
            $this->eventos($conferida),
            'a conferência não foi registrada (`:1170`)',
        );

        // ⚠️ O zero também é LITERAL para `conferirAlteracao()` — **descontando** as leituras de
        // tarifa que ESTE TESTE fez ao pedir a composição duas vezes para conferir o preço.
        $this->assertSame([], $this->escritasEDesvios(), 'saiu ESCRITA para o Mercado Livre ao conferir alteração');
        $this->provarQueOEspiaoVe();
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // (3) AS RECUSAS DO PREÇO — valor inválido, versão divergente, fora da revisão
    // ═════════════════════════════════════════════════════════════════════════════════════════

    public function test_preco_invalido_versao_divergente_e_fora_da_revisao_recusam_sem_gravar_188_06(): void
    {
        [$company, $empresa, $admin] = $this->conta();
        $c = $this->cenario(['company' => $company, 'empresa' => $empresa]);
        $oferta = $c['classico'];

        // ⚠️ **14 ENTRADAS QUE NÃO SÃO PREÇO**, cada uma com a asserção de que NADA mudou.
        // `(float) []` é a família do `(int) []` que já foi **500** nesta tela, e `-10,00` tem de
        // ser RECUSADO (o `num()` do protótipo o engoliria e viraria `10.0` — divergência (3)
        // declarada pela wave 188-04, por segurança: salvar troca o preço de verdade).
        $invalidos = [
            'zero' => 0, 'zero_texto' => '0', 'zero_brl' => 'R$ 0,00', 'negativo' => -10.0,
            'negativo_texto' => '-10,00', 'menos_unicode' => "\u{2212} 10,00", 'vazio' => '',
            'espacos' => '   ', 'palavra' => 'de graça', 'array' => [], 'array_com_numero' => ['100'],
            'nulo' => null, 'booleano' => true, 'nan' => 'NaN',
        ];

        $antes = $this->contadoresDeEscrita($oferta);
        $recusados = [];

        foreach ($invalidos as $rotulo => $bruto) {
            // ⚠️ Flag DENTRO do laço.
            $resposta = $this->actingAs($admin)->postJson($this->url('preco', $company, $oferta), ['preco' => $bruto]);

            if ($resposta->status() !== 422 || $resposta->json('regra') !== PrecoDoPainelService::REGRA_PRECO_INVALIDO) {
                $recusados[] = $rotulo.' => '.$resposta->status().'/'.(string) $resposta->json('regra');
            }
        }

        // ⚠️ UMA asserção, coletando os infratores — nunca uma por entrada (a contagem cresceria
        // com a lista, e contagem que depende de quantos itens existem é frágil).
        $this->assertSame([], $recusados, 'entrada que não é preço foi aceita (ou recusada com o id errado)');
        $this->assertSame($antes, $this->contadoresDeEscrita($oferta), 'as 14 entradas inválidas GRAVARAM algo');

        // ⚠️ **ISCA DO RECORTE:** o saneador TEM de aceitar o que é preço — senão o laço acima
        // passaria com um saneador que recusa tudo.
        $this->actingAs($admin)
            ->post($this->url('preco', $company, $oferta), ['preco' => 'R$ 1.299,90'])
            ->assertRedirect();
        $this->assertSame(2, (int) $oferta->fresh()->versao, 'a isca falhou: preço VÁLIDO foi recusado');

        // ─── VERSÃO DIVERGENTE (COL-04 / critério 17) ──────────────────────────────────────
        $atual = (int) $oferta->fresh()->versao;
        $antesDoConflito = $this->contadoresDeEscrita($oferta);

        $conflito = $this->actingAs($admin)->postJson(
            $this->url('preco', $company, $oferta),
            ['preco' => '199,90', 'versao_esperada' => $atual - 1],
        );
        $conflito->assertStatus(422)->assertJsonPath('regra', AprovacaoDaOfertaService::REGRA_VERSAO_DIVERGENTE);
        $this->assertSame($antesDoConflito, $this->contadoresDeEscrita($oferta), 'versão divergente GRAVOU');
        // ⚠️ A mensagem diz as DUAS versões: sem elas a pessoa não tem o que conferir.
        $this->assertStringContainsString((string) $atual, (string) $conflito->json('message'));

        // ⚠️ ISCA: com a versão CERTA, grava. Sem ela, "divergente recusa" passaria com uma rota
        // que recusa sempre.
        $this->actingAs($admin)
            ->post($this->url('preco', $company, $oferta), ['preco' => '199,90', 'versao_esperada' => $atual])
            ->assertRedirect();
        $this->assertSame($atual + 1, (int) $oferta->fresh()->versao, 'a isca da versão falhou');

        // ─── FORA DA REVISÃO — os três andamentos em que não há revisão a fazer ────────────
        $foraDaRevisao = [];
        foreach ([PubAprovacaoOferta::PUBLICANDO, PubAprovacaoOferta::PUBLICADO, PubAprovacaoOferta::FALHA] as $andamento) {
            // ⚠️ Flag DENTRO do laço. A oferta é levada ao estado pela ÚNICA PORTA.
            $cn = $this->cenario(['company' => $company, 'empresa' => $empresa]);
            $alvo = $this->levarAo($cn['classico'], $andamento, $admin);
            $antesDela = $this->contadoresDeEscrita($alvo);

            $r = $this->actingAs($admin)->postJson($this->url('preco', $company, $alvo), ['preco' => '77,00']);

            if ($r->status() !== 422 || $r->json('regra') !== AprovacaoDaOfertaService::REGRA_FORA_DO_AO_VIVO) {
                $foraDaRevisao[] = $andamento.' => '.$r->status().'/'.(string) $r->json('regra');
            }
            if ($this->contadoresDeEscrita($alvo) !== $antesDela) {
                $foraDaRevisao[] = $andamento.' => GRAVOU';
            }
        }
        $this->assertSame([], $foraDaRevisao, 'salvar preço foi aceito fora da revisão');

        // ⚠️ E `preparando` também não revisa — mas ele nem entra na navegação do painel, então a
        // oferta precisa existir para o 404 NÃO mascarar a recusa. Aqui a fixture nasce
        // `aguardando_aprovacao` e `preparando` não é alcançável pela única porta de volta: a
        // cobertura dele é do payload (`rodape.botoes`), plano 188-02.
        $this->assertContains(PubAprovacaoOferta::PREPARANDO, PainelDeRevisaoService::FORA_DA_NAVEGACAO);

        $this->assertSame([], $this->escritasEDesvios(), 'saiu ESCRITA para o Mercado Livre nas recusas');
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // (4) CONFIRMAR FRETE — valor + origem `Confirmado` + nota com a FONTE e a DATA + versão++
    // ═════════════════════════════════════════════════════════════════════════════════════════

    public function test_confirmar_frete_grava_valor_origem_nota_e_versao_188_06(): void
    {
        [$company, $empresa, $admin] = $this->conta();
        // ⚠️ Frete AUSENTE na partida: é o cenário real do modal, e é o que torna "o frete passou
        // a valer" observável.
        $c = $this->cenario(['company' => $company, 'empresa' => $empresa, 'frete' => null]);
        $oferta = $c['classico'];

        $this->assertNull(PubAprovacaoFrete::vigenteDe($oferta), 'a fixture já tem frete informado');
        $versaoAntes = (int) $oferta->versao;

        $this->actingAs($admin)
            ->post($this->url('frete', $company, $oferta), [
                'valor' => 'R$ 18,50',
                'origem' => PubAprovacaoFrete::TABELA_CLIENTE,
            ])
            ->assertRedirect();

        $confirmada = $oferta->fresh();
        $vigente = PubAprovacaoFrete::vigenteDe($confirmada);

        $this->assertNotNull($vigente, 'o frete não foi gravado');
        $this->assertSame(18.5, (float) $vigente->valor, 'o valor saneado não é o digitado');
        $this->assertSame(PubAprovacaoFrete::TABELA_CLIENTE, (string) $vigente->origem);
        $this->assertTrue((bool) $vigente->vigente);

        // ⚠️ A VERSÃO SOBE UMA VEZ, não duas: `informar()` já incrementa e `confirmarFrete()`
        // passa `versaoJaIncrementada: true`. Duas faria `versao_aprovada` ficar duas atrás.
        $this->assertSame($versaoAntes + 1, (int) $confirmada->versao, 'a versão não subiu exatamente uma vez');
        $this->assertSame((int) $confirmada->versao, (int) $vigente->versao, 'a linha guardou a versão errada');

        // ⚠️ DADO-13 — a nota NOMEIA A FONTE **e** A DATA (`:1204`: `F.src + ' · ' + now()`), e
        // nota vazia é ERRO, não decoração.
        $this->assertSame('Tabela do cliente · 19/10/2026 11:30', (string) $vigente->nota, 'a nota não nomeia fonte e data');
        $this->assertNotSame('', trim((string) $vigente->nota));

        // ⚠️ A linha da composição passou a ser `Confirmado` — e é por isso que a origem na tela
        // deixa de ser estimada. O rótulo é LIDO do DTO.
        $linha = app(ComposicaoFinanceiraService::class)->para($confirmada)->linha('frete');
        $this->assertNotNull($linha);
        $this->assertSame(PubAprovacaoComposicao::CONFIRMADO, (string) $linha->origem, 'o frete informado não venceu');
        $this->assertSame(18.5, (float) $linha->valor);

        // ⚠️ O ATO no histórico, na forma de `:1204` e com o RÓTULO da origem (nunca a chave).
        $frase = app(PrecoDoPainelService::class)->registroDeFreteConfirmado(18.5, 'Tabela do cliente', (int) $confirmada->versao);
        $this->assertSame(
            'Confirmou o frete do vendedor: R$ 18,50 (Tabela do cliente) · versão '.$confirmada->versao,
            $frase,
        );
        $this->assertContains($frase, $this->eventos($confirmada), 'o frete confirmado não foi registrado');
        // ⚠️ VOC-06 — a CHAVE não chega à linha do tempo.
        $this->assertStringNotContainsString(PubAprovacaoFrete::TABELA_CLIENTE, implode(' | ', $this->eventos($confirmada)));

        $this->assertSame([], $this->escritasEDesvios(), 'saiu ESCRITA para o Mercado Livre ao confirmar frete');
        $this->assertGreaterThan(0, $this->requisicoesDeTarifa(), 'o espião de rede está CEGO');
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // (5) AS RECUSAS DO FRETE — origem fora das três, valor que não é valor
    // ═════════════════════════════════════════════════════════════════════════════════════════

    public function test_origem_fora_das_tres_e_valor_invalido_recusam_sem_gravar_188_06(): void
    {
        [$company, $empresa, $admin] = $this->conta();
        $c = $this->cenario(['company' => $company, 'empresa' => $empresa, 'frete' => null]);
        $oferta = $c['classico'];

        // ⚠️ **AS TRÊS ORIGENS SÃO LISTA FECHADA**, e a lista é a do MODELO — nunca uma segunda.
        $this->assertSame(
            ['cotacao_transportadora' => 'Cotação da transportadora', 'tabela_cliente' => 'Tabela do cliente', 'simulador' => 'Simulador de frete'],
            PubAprovacaoFrete::ORIGENS,
            'as três origens de `:1401` mudaram',
        );

        $antes = $this->contadoresDeEscrita($oferta);
        $infratores = [];

        // ⚠️ Inclui os RÓTULOS e as chaves do PROTÓTIPO: quem mandar o rótulo pt-BR tem de ser
        // recusado também, senão existiriam duas formas de dizer a mesma origem.
        foreach (['Tabela do cliente', 'tabela', 'Cotação da transportadora', 'freight', 'inventada', ''] as $origem) {
            // ⚠️ Flag DENTRO do laço.
            $r = $this->actingAs($admin)->postJson($this->url('frete', $company, $oferta), ['valor' => '10,00', 'origem' => $origem]);
            $aceitou = $r->status() !== 422;

            if ($aceitou) {
                $infratores[] = 'origem aceita: '.$origem;
            }
        }

        // ⚠️ E o valor que não é valor, com o id próprio.
        foreach ([0, '0', '', '   ', 'de graça', [], null, -5.0, '-5,00'] as $valor) {
            // ⚠️ Flag DENTRO do laço.
            $r = $this->actingAs($admin)->postJson($this->url('frete', $company, $oferta), ['valor' => $valor, 'origem' => PubAprovacaoFrete::SIMULADOR]);

            if ($r->status() !== 422 || $r->json('regra') !== PrecoDoPainelService::REGRA_FRETE_INVALIDO) {
                $infratores[] = 'valor aceito: '.var_export($valor, true).' => '.$r->status().'/'.(string) $r->json('regra');
            }
        }

        $this->assertSame([], $infratores, 'origem ou valor inválido foi aceito');
        $this->assertSame($antes, $this->contadoresDeEscrita($oferta), 'as recusas de frete GRAVARAM algo');

        // ⚠️ **ISCA NAS DUAS DIREÇÕES:** as TRÊS chaves legítimas TÊM de ser aceitas — senão o
        // laço acima passaria com uma rota que recusa toda origem.
        $aceitas = 0;
        foreach (array_keys(PubAprovacaoFrete::ORIGENS) as $chave) {
            // ⚠️ Flag DENTRO do laço, e uma oferta NOVA por chave (confirmar frete reabre).
            $cn = $this->cenario(['company' => $company, 'empresa' => $empresa, 'frete' => null]);
            $r = $this->actingAs($admin)->post($this->url('frete', $company, $cn['classico']), ['valor' => '12,00', 'origem' => $chave]);
            $aceitas += $r->status() === 302 ? 1 : 0;
        }
        $this->assertSame(3, $aceitas, 'a isca falhou: origem LEGÍTIMA foi recusada');

        $this->assertSame([], $this->escritasEDesvios(), 'saiu ESCRITA para o Mercado Livre nas recusas de frete');
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // (6) A PRÉVIA DO FRETE — **sem gravar** e amarrada à composição real pelo PONTO FIXO
    // ═════════════════════════════════════════════════════════════════════════════════════════

    public function test_previa_do_frete_nao_grava_e_bate_com_a_composicao_no_ponto_fixo_188_06(): void
    {
        [$company, $empresa, $admin] = $this->conta();

        // ─── (a) PONTO FIXO: com o valor IGUAL ao frete que já vale, a prévia é a composição ──
        // ⚠️⚠️ É esta asserção que torna a soma de `previaDoFrete()` uma EQUIVALÊNCIA MEDIDA em
        // vez de uma segunda verdade: se a fórmula do DTO da Fase 186 mudar, ela QUEBRA.
        $comFrete = $this->cenario(['company' => $company, 'empresa' => $empresa, 'frete' => 10.0]);

        // ⚠️⚠️ **OS DOIS SERVIÇOS SÃO RESOLVIDOS DEPOIS DA FIXTURE, E ISSO FOI MEDIDO:**
        // `cenario()` registra o dublê de `DadosEfetivosService` no container, e
        // `ComposicaoFinanceiraService` o recebe **no construtor**. Resolvê-lo antes entrega o
        // serviço REAL (sem custo, sem frete, sem preço) e a composição sai `incompleta` — o caso
        // reprovou exatamente assim na primeira execução. É a mesma armadilha que o
        // `PainelDeRevisaoServiceTest:774` já declara.
        $financeira = app(ComposicaoFinanceiraService::class);
        $preco = app(PrecoDoPainelService::class);
        $ofertaComFrete = $comFrete['classico'];
        $real = $financeira->para($ofertaComFrete);
        $this->assertFalse($real->incompleto, 'a fixture com frete deveria estar completa');

        $pontoFixo = $preco->previaDoFrete($ofertaComFrete, '10,00');
        $this->assertSame(
            'Resultado '.$this->brl((float) $real->resultado).' · margem '.$this->pct((float) $real->margem),
            $pontoFixo['texto'],
            'PONTO FIXO: a prévia divergiu da composição real com o MESMO frete',
        );

        // ─── (b) O CASO PRINCIPAL: sem frete a composição é INCOMPLETA e a prévia dá número ──
        // ⚠️ E é por isso que "subtrair o delta do resultado atual" **não funciona**: aqui
        // `resultado` é `null`. A asserção prova que a saída descartada falharia.
        $semFrete = $this->cenario(['company' => $company, 'empresa' => $empresa, 'frete' => null]);
        $ofertaSemFrete = $semFrete['classico'];
        $atual = $financeira->para($ofertaSemFrete);
        $this->assertTrue($atual->incompleto, 'a fixture sem frete deveria estar incompleta');
        $this->assertNull($atual->resultado, 'sem frete o resultado tem de ser NULO, nunca zero');

        $previa = $preco->previaDoFrete($ofertaSemFrete, '10,00');
        $this->assertTrue($previa['preenchido']);
        $this->assertFalse($previa['incompleto'], 'a prévia disse Incompleto com só o frete faltando');
        // ⚠️ E o número é o MESMO da oferta que JÁ TEM aquele frete — comparação contra a
        // composição real, nunca contra um literal recalculado no teste.
        $this->assertSame($this->brl((float) $real->resultado), $previa['resultado'], 'a prévia não é a composição com o frete trocado');
        $this->assertSame($this->pct((float) $real->margem), $previa['margem']);

        // ─── (c) SEM VALOR: a frase do protótipo e a cor NEUTRA, nunca "R$ 0,00" ──────────────
        $vazios = [];
        foreach ([null, '', '   ', 0, '0', 'R$ 0,00', -3.0, '-3,00', [], true, 'de graça'] as $bruto) {
            // ⚠️ Flag DENTRO do laço.
            $p = $preco->previaDoFrete($ofertaSemFrete, $bruto);
            if ($p['texto'] !== PrecoDoPainelService::PREVIA_SEM_VALOR || $p['preenchido'] !== false) {
                $vazios[] = var_export($bruto, true);
            }
        }
        $this->assertSame([], $vazios, 'valor que não é valor produziu prévia com número');
        $this->assertSame('Informe o valor para ver o resultado', PrecoDoPainelService::PREVIA_SEM_VALOR, '`:1401` literal');

        // ─── (d) FALTA OUTRO DADO: a prévia diz `Incompleto`, não um número plausível ─────────
        $semCusto = $this->cenario(['company' => $company, 'empresa' => $empresa, 'frete' => null, 'custo' => null]);
        $pIncompleta = $preco->previaDoFrete($semCusto['classico'], '10,00');
        $this->assertTrue($pIncompleta['incompleto'], 'faltando o CUSTO a prévia inventou um resultado');
        $this->assertNull($pIncompleta['resultado']);
        $this->assertSame(\App\Services\Publicador\Aprovacao\VocabularioDoPortao::INCOMPLETO, $pIncompleta['texto']);

        // ─── (e) ⚠️ A PRÉVIA **NÃO GRAVA** — pela ROTA, com ISCA provando o contador ──────────
        $antes = $this->contadoresDeEscrita($ofertaSemFrete);

        $this->actingAs($admin)
            ->post($this->url('frete.previa', $company, $ofertaSemFrete), ['valor' => '77,00'])
            ->assertRedirect();
        $this->actingAs($admin)
            ->postJson($this->url('frete.previa', $company, $ofertaSemFrete), ['valor' => '77,00'])
            ->assertOk()
            ->assertJsonPath('preenchido', true);

        $this->assertSame($antes, $this->contadoresDeEscrita($ofertaSemFrete), 'a PRÉVIA do frete GRAVOU');
        $this->assertNull(PubAprovacaoFrete::vigenteDe($ofertaSemFrete), 'a prévia criou linha de frete');

        // ⚠️⚠️ **ISCA DO CONTADOR:** confirmar o frete MEXE nos mesmos números. Sem ela, "nada
        // mudou" seria verdade por o contador estar cego.
        $this->actingAs($admin)
            ->post($this->url('frete', $company, $ofertaSemFrete), ['valor' => '77,00', 'origem' => PubAprovacaoFrete::SIMULADOR])
            ->assertRedirect();
        $this->assertNotSame($antes, $this->contadoresDeEscrita($ofertaSemFrete), 'o contador de escrita está CEGO');

        $this->assertSame([], $this->escritasEDesvios(), 'saiu ESCRITA para o Mercado Livre na prévia');
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // (7) O TRANSPORTE DA SIMULAÇÃO — as 9 chaves, e **nada persistido**
    // ═════════════════════════════════════════════════════════════════════════════════════════

    public function test_simular_devolve_as_nove_chaves_e_nao_persiste_nada_188_06(): void
    {
        [$company, $empresa, $admin] = $this->conta();
        $c = $this->cenario(['company' => $company, 'empresa' => $empresa]);
        $oferta = $c['classico'];

        $antes = $this->contadoresDeEscrita($oferta);

        $json = $this->actingAs($admin)
            ->postJson($this->url('preco.simular', $company, $oferta), ['preco' => '149,90'])
            ->assertOk()
            ->json();

        // ⚠️ **AS 9 CHAVES QUE O PLANO 188-06 DECLAROU LER DA WAVE 188-04**, com UMA asserção
        // coletando as ausentes — nunca uma por chave (a contagem cresceria com o contrato).
        $exigidas = ['resultado', 'resultado_cor', 'margem', 'margem_cor', 'delta', 'delta_cor', 'simulando', 'pode_salvar', 'versao'];
        $faltando = array_values(array_filter($exigidas, static fn (string $k): bool => ! array_key_exists($k, $json)));
        $this->assertSame([], $faltando, 'o transporte da simulação não entrega as 9 chaves');

        $this->assertTrue($json['simulando'], 'o preço digitado difere do salvo e o selo não acendeu');
        $this->assertSame(PrecoDoPainelService::SIMULACAO_SELO, $json['selo']);
        $this->assertSame('SIMULAÇÃO · NÃO SALVA', PrecoDoPainelService::SIMULACAO_SELO, 'o selo é literal');
        $this->assertTrue($json['pode_salvar'], 'oferta `live` com simulação ativa tem de poder salvar');

        // ⚠️ **NADA FOI PERSISTIDO** — critério 7, e é o mais fácil de errar.
        $this->assertSame($antes, $this->contadoresDeEscrita($oferta), 'a SIMULAÇÃO gravou algo');

        // ⚠️ As MESMAS 9 chaves no estado NEUTRO: forma diferente quebraria o cliente em metade
        // das respostas.
        $neutro = $this->actingAs($admin)
            ->postJson($this->url('preco.simular', $company, $oferta), ['preco' => []])
            ->assertOk()
            ->json();
        $faltandoNoNeutro = array_values(array_filter($exigidas, static fn (string $k): bool => ! array_key_exists($k, $neutro)));
        $this->assertSame([], $faltandoNoNeutro, 'o estado neutro tem forma diferente');
        $this->assertFalse($neutro['simulando'], 'entrada inválida acendeu o selo');
        $this->assertFalse($neutro['pode_salvar'], 'sem simulação ativa não há o que salvar');

        // ⚠️ **SÓ SALVÁVEL NO `live`:** fora dele `pode_salvar` é falso, nos TRÊS andamentos.
        $naoSalvaveis = [];
        foreach ([PubAprovacaoOferta::APROVADO, PubAprovacaoOferta::PUBLICANDO, PubAprovacaoOferta::PUBLICADO] as $andamento) {
            // ⚠️ Flag DENTRO do laço, e pela ÚNICA PORTA.
            $cn = $this->cenario(['company' => $company, 'empresa' => $empresa]);
            $alvo = $this->levarAo($cn['classico'], $andamento, $admin);
            $r = $this->actingAs($admin)->postJson($this->url('preco.simular', $company, $alvo), ['preco' => '149,90'])->assertOk();
            if ($r->json('pode_salvar') !== false) {
                $naoSalvaveis[] = $andamento;
            }
        }
        $this->assertSame([], $naoSalvaveis, '`pode_salvar` ficou verdadeiro fora do `live`');

        // ⚠️ E a OUTRA forma de resposta (Inertia) carrega a MESMA array.
        $this->actingAs($admin)
            ->post($this->url('preco.simular', $company, $oferta), ['preco' => '149,90'])
            ->assertRedirect()
            ->assertSessionHas('painel_simulacao');

        // ⚠️⚠️ **ISCA DO CONTADOR:** salvar o preço MEXE nos mesmos números.
        $this->actingAs($admin)->post($this->url('preco', $company, $oferta), ['preco' => '149,90'])->assertRedirect();
        $this->assertNotSame($antes, $this->contadoresDeEscrita($oferta), 'o contador de escrita está CEGO');
    }

    public function test_nem_simular_nem_a_previa_escrevem_no_mercado_livre_com_isca_188_06(): void
    {
        [$company, $empresa, $admin] = $this->conta();
        $c = $this->cenario(['company' => $company, 'empresa' => $empresa]);
        $oferta = $c['classico'];

        $this->actingAs($admin)->postJson($this->url('preco.simular', $company, $oferta), ['preco' => '149,90'])->assertOk();
        $this->actingAs($admin)->postJson($this->url('frete.previa', $company, $oferta), ['valor' => '19,90'])->assertOk();

        // ⚠️ A régua HONESTA: **zero requisição de ESCRITA**, não zero requisição. `para()` LÊ a
        // tarifa real no GET `/sites/MLB/listing_prices` (decisão 9), e prometer zero absoluto
        // seria gate que o próprio desenho contradiz.
        $this->assertSame([], $this->escritasEDesvios(), 'saiu requisição de ESCRITA (ou GET fora da lista) ao simular');

        // ⚠️⚠️ **ISCA: O ESPIÃO NÃO ESTÁ CEGO.** Se nenhuma requisição tivesse sido vista, o
        // `assertSame([], …)` acima seria verdade por omissão.
        $this->assertGreaterThan(0, $this->requisicoesDeTarifa(), 'o espião de rede está CEGO: nem a tarifa foi vista');

        // ⚠️ E a lista autorizada é CONSTANTE e NOMEADA — nunca um filtro largo.
        $this->assertSame(['/sites/MLB/listing_prices'], self::LEITURAS_AUTORIZADAS);

        // ⚠️ **ISCA DA SONDA:** uma requisição de escrita TEM de ser acusada. Sem isto,
        // `escritasEDesvios()` poderia estar devolvendo `[]` sempre.
        Http::post('https://api.mercadolibre.com/items', ['title' => 'isca']);
        $this->assertSame(
            ['POST https://api.mercadolibre.com/items'],
            $this->escritasEDesvios(),
            'a sonda de ESCRITA não acusa um POST em /items',
        );
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // (8) OS TRÊS PAYLOADS DOS MODAIS (B1.4 · B1.5 · B1.6)
    // ═════════════════════════════════════════════════════════════════════════════════════════

    public function test_os_tres_payloads_dos_modais_existem_sempre_com_assercao_por_campo_188_06(): void
    {
        [$company, $empresa, $admin] = $this->conta();
        $c = $this->cenario(['company' => $company, 'empresa' => $empresa, 'responsavel_id' => $admin->id]);
        $oferta = $c['classico'];

        $painel = $this->painel($admin, $company, $oferta);

        // ⚠️ **AS TRÊS CHAVES EXISTEM SEMPRE** — chave ausente e lista vazia são casos DIFERENTES
        // no cliente, e foi chave ausente que derrubou esta página em 07/10/2026. UMA asserção,
        // coletando as ausentes.
        $caminhos = ['modais', 'modais.ajuste', 'modais.ajuste.itens', 'modais.excecao', 'modais.excecao.autorizadores', 'modais.reprovar', 'modais.reprovar.defeitos'];
        $ausentes = [];
        foreach ($caminhos as $caminho) {
            // ⚠️ Flag DENTRO do laço.
            $no = $painel;
            foreach (explode('.', $caminho) as $parte) {
                $no = is_array($no) && array_key_exists($parte, $no) ? $no[$parte] : '__ausente__';
            }
            if ($no === '__ausente__') {
                $ausentes[] = $caminho;
            }
        }
        $this->assertSame([], $ausentes, 'chave de modal AUSENTE no payload do painel');

        // ─── B1.4 — os autorizadores: id, nome e descrição, da CONTA ──────────────────────
        $autorizadores = $painel['modais']['excecao']['autorizadores'];
        $this->assertNotEmpty($autorizadores, 'a lista de autorizadores veio VAZIA com responsável na conta');
        $this->assertSame(
            [['id' => $admin->id, 'nome' => 'Rafael Gusmão', 'descricao' => '2 ofertas nesta conta']],
            $autorizadores,
            'o autorizador não traz id, nome e a descrição derivada do número REAL da conta',
        );

        // ⚠️ **ISCA DO RECORTE:** sem responsável na conta a lista é VAZIA (e aí o modal diz e não
        // envia — D4). Sem esta ponta, "não vazia" acima passaria com uma lista global.
        [$outraCompany, $outraEmpresa, $outroAdmin] = $this->conta();
        $semResponsavel = $this->cenario(['company' => $outraCompany, 'empresa' => $outraEmpresa]);
        $this->assertSame(
            [],
            $this->painel($outroAdmin, $outraCompany, $semResponsavel['classico'])['modais']['excecao']['autorizadores'],
            'a lista de autorizadores não é recortada pela conta',
        );

        // ─── B1.5 — os chips de defeito, LIDOS do modelo canônico da Fase 186 ─────────────
        $esperados = [];
        foreach (PubAprovacaoDefeito::DEFEITOS as $chave => $rotulo) {
            $esperados[] = ['chave' => $chave, 'rotulo' => $rotulo];
        }
        $this->assertSame($esperados, $painel['modais']['reprovar']['defeitos'], 'os chips não vêm de `PubAprovacaoDefeito::DEFEITOS`');
        $this->assertCount(6, $painel['modais']['reprovar']['defeitos']);

        // ─── B1.6 — cada item de ajuste com os CINCO campos, e o destino de UM lugar só ───
        $itens = $painel['modais']['ajuste']['itens'];
        $semCampo = [];
        foreach ($itens as $i => $item) {
            // ⚠️ Flag DENTRO do laço.
            foreach (['rotulo', 'escopo', 'destino', 'nota_do_escopo', 'sugestoes'] as $campo) {
                if (! array_key_exists($campo, $item) || $item[$campo] === null || $item[$campo] === '') {
                    $semCampo[] = $i.'.'.$campo;
                }
            }
            if (! in_array($item['escopo'] ?? null, PubAprovacaoAjuste::ESCOPOS, true)) {
                $semCampo[] = $i.'.escopo fora da lista fechada';
            }
            if (($item['destino'] ?? null) !== PubAprovacaoAjuste::destinoLegivel((string) $item['escopo'], self::CLASSICO)) {
                $semCampo[] = $i.'.destino não é `destinoLegivel()`';
            }
            if (($item['nota_do_escopo'] ?? null) !== (PainelDeRevisaoService::AJUSTE_NOTA_DO_ESCOPO[$item['escopo']] ?? null)) {
                $semCampo[] = $i.'.nota_do_escopo fora da constante';
            }
        }
        $this->assertSame([], $semCampo, 'item de ajuste sem campo (ou com destino/nota de outra fonte)');

        // ⚠️ VOC-06 — as chaves `offer`/`set`/`product` NUNCA chegam ao texto que a pessoa lê.
        $textos = [];
        foreach ($itens as $item) {
            $textos[] = $item['rotulo'].' '.$item['destino'].' '.$item['nota_do_escopo'];
        }
        $juntos = implode(' | ', $textos);
        foreach (PubAprovacaoAjuste::ESCOPOS as $chave) {
            $this->assertStringNotContainsString($chave, $juntos, "a chave de escopo {$chave} chegou ao texto da tela");
        }
        // ⚠️ ISCA: a sonda acima vê a chave quando ela ESTÁ presente.
        $this->assertStringContainsString('product', 'destino: product', 'a sonda da chave está cega');
    }

    public function test_a_ordem_dos_sete_itens_fixos_e_a_alternancia_do_setimo_188_06(): void
    {
        [$company, $empresa, $admin] = $this->conta();

        // ⚠️ **UNIDADE × COMBO**, porque o SÉTIMO item alterna por `units > 1` (`:1189`) — com um
        // cenário só a alternância é inobservável.
        $unidade = $this->cenario(['company' => $company, 'empresa' => $empresa, 'unidades' => 1, 'fotos_n' => 2]);
        $combo = $this->cenario(['company' => $company, 'empresa' => $empresa, 'unidades' => 2, 'fotos_n' => 2]);

        $rotulos = fn (PubAprovacaoOferta $o): array => array_column(
            $this->painel($admin, $company, $o)['modais']['ajuste']['itens'],
            'rotulo',
        );

        // ⚠️⚠️ **A LISTA INTEIRA, NÃO UM PISO.** Ordem só se prova com a lista completa, e piso
        // (`count >= 9`) NÃO prova presença: com um item perdido o piso ainda passaria.
        $this->assertSame(
            [
                // ⚠️ Os rótulos saem de `GaleriaDaOfertaService::rotuloDaFoto()` — `Capa` na posição
                // 0 e `Foto N+1` nas outras. Escrevê-los à mão aqui é de propósito: é o que acusa
                // se a galeria do modal deixar de ser a MESMA da coluna de fotos.
                'Imagem #1 · Capa',
                'Imagem #2 · Foto 2',
                'Preço Clássico',
                'Frete do vendedor',
                'Título',
                'Descrição',
                'Custo do produto',
                'Atributos e ficha técnica',
                'Categoria',
            ],
            $rotulos($unidade['classico']),
            'a ordem dos itens de ajuste (ou a alternância do sétimo) saiu errada na UNIDADE',
        );

        $doCombo = $rotulos($combo['classico']);
        $this->assertSame('Composição do kit', end($doCombo), 'o SÉTIMO item não alternou para o combo');
        $this->assertSame(
            array_slice($rotulos($unidade['classico']), 0, -1),
            array_slice($doCombo, 0, -1),
            'a alternância do sétimo mexeu nos outros itens',
        );

        // ⚠️ E no PREMIUM o item de preço nomeia a OUTRA modalidade (`modName`, `:1059`).
        $doPremium = $rotulos($unidade['premium']);
        $this->assertSame('Preço Premium', $doPremium[2], 'o item de preço não nomeia a modalidade da oferta');

        // ⚠️⚠️ **SÃO SETE FIXOS, E ISSO FOI MEDIDO:** a prosa do `188-06-PLAN.md` diz "os 8 fixos";
        // o protótipo (`:1183`-`:1189`) e a `188-FID01.md` §F.1 têm **SETE** (4 de escopo `offer`
        // + 3 de escopo `product`). ➡️ **O FID01 tem razão.** A premissa fica TRAVADA aqui.
        $this->assertCount(7, PainelDeRevisaoService::AJUSTE_ITENS_FIXOS, 'os itens fixos de `:1183`-`:1189` deixaram de ser SETE');
        $escopos = array_column(PainelDeRevisaoService::AJUSTE_ITENS_FIXOS, 'escopo');
        $this->assertSame(
            [
                PubAprovacaoAjuste::ESCOPO_OFERTA, PubAprovacaoAjuste::ESCOPO_OFERTA,
                PubAprovacaoAjuste::ESCOPO_OFERTA, PubAprovacaoAjuste::ESCOPO_OFERTA,
                PubAprovacaoAjuste::ESCOPO_PRODUTO, PubAprovacaoAjuste::ESCOPO_PRODUTO,
                PubAprovacaoAjuste::ESCOPO_PRODUTO,
            ],
            $escopos,
            'os escopos dos sete fixos mudaram de ordem',
        );

        // ⚠️ As sugestões do item de IMAGEM são as QUATRO de `:1181`, na ordem.
        $itens = $this->painel($admin, $company, $unidade['classico'])['modais']['ajuste']['itens'];
        $this->assertSame(
            ['IA alterou os pés da poltrona', 'Cor diferente da original', 'Quantidade do kit incorreta', 'Texto ilegível na imagem'],
            $itens[0]['sugestoes'],
            'as quatro sugestões de `:1181` mudaram',
        );
        // ⚠️ E todo item tem ao menos UMA sugestão: chip vazio é caixa vazia na tela.
        $semSugestao = array_values(array_filter($itens, static fn (array $i): bool => $i['sugestoes'] === []));
        $this->assertSame([], $semSugestao, 'item de ajuste sem nenhuma sugestão');
    }

    // ─────────────────────────────────────────────────────────────────────────────────────────
    // Formatação (a MESMA do serviço — aqui só para montar a frase esperada)
    // ─────────────────────────────────────────────────────────────────────────────────────────

    private function brl(float $n): string
    {
        return ($n < 0 ? "\u{2212} " : '').'R$ '.number_format(abs($n), 2, ',', '.');
    }

    private function pct(float $n): string
    {
        return number_format(round($n * 10) / 10, 1, ',', '.').'%';
    }
}
