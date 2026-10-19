<?php

namespace Tests\Feature\Publicador\Aprovacao;

use App\Models\Company;
use App\Models\MlbEmpresa;
use App\Models\MlToken;
use App\Models\PubAprovacaoAjuste;
use App\Models\PubAprovacaoConjunto;
use App\Models\PubAprovacaoEvento;
use App\Models\PubAprovacaoExcecao;
use App\Models\PubAprovacaoImagem;
use App\Models\PubAprovacaoImagemAlerta;
use App\Models\PubAprovacaoOferta;
use App\Models\PubImagem;
use App\Models\PubProduto;
use App\Models\PubRascunho;
use App\Models\PubRascunhoAlvo;
use App\Models\User;
use App\Services\Portal\Estrutura\EstruturaPrecificacaoService;
use App\Services\Publicador\Aprovacao\AjustesDaOfertaService;
use App\Services\Publicador\Aprovacao\AndamentoDaOfertaService;
use App\Services\Publicador\Aprovacao\VocabularioDoPortao;
use App\Services\Publicador\ClienteMlPublicador;
use App\Services\Publicador\DadosEfetivosService;
use App\Support\Publicador\RegraViolada;
use App\Support\Publicador\Variacao\ChaveCanonica;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 * Fase 188, plano 06 (tarefa 2) — O AJUSTE COM **ESCOPO** (critério 12 / AND-07), a sua
 * RESOLUÇÃO, e a **EXCEÇÃO AUTORIZADA** (critério 11 / BLOQ-08 / BLOQ-09 / DADO-11), pelas rotas.
 *
 * ⚠️⚠️ DE CADA GATE DESTE ARQUIVO, A PERGUNTA RESPONDIDA ANTES DELE EXISTIR:
 * **"o que precisaria estar errado no mundo para ele falhar?"**
 *
 *   · **O escopo alcançar menos ofertas do que devia.** É o erro em silêncio do critério 12: o
 *     conjunto de fotos é **compartilhado** entre Clássico e Premium, e um pedido de conjunto que
 *     movesse só o Clássico deixaria **o Premium aprovável com a foto reprovada** — e aprovar já
 *     publica, sem desfazer. ➡️ **FIXTURE DE 4 OFERTAS EM 2 CONJUNTOS:** com uma oferta só, os
 *     três escopos dão o MESMO resultado e **nada se prova** (foi a falha de fixture das duas
 *     sobreviventes da wave 187-06).
 *   · **O escopo alcançar MAIS do que devia.** `publicando`/`publicado` **não** vão para
 *     `changes` (`:1198`): reabrir a revisão de algo que **já está no ar** é o outro lado do mesmo
 *     erro, e é fácil de omitir.
 *   · **Resolver devolver a oferta SEMPRE.** "O último" é por OFERTA, não por ajuste: com dois
 *     abertos, resolver um **não** devolve ninguém. Um gate que só testasse o "último" passaria
 *     com um serviço que devolve sempre. ➡️ **Os dois sentidos, na MESMA fixture.**
 *   · **A exceção liberar o que não é dela.** Elevação de privilégio (T-188-50): exceção que
 *     liberasse FOTOS poria foto não conferida no ar. ➡️ **Isca POR BLOQUEIO**, nos SEIS outros —
 *     testar só a aceitação nos dois deixaria isso passar.
 *   · **A justificativa de 9 caracteres passar.** A trava é `>= 10`, **não `> 10`**. ➡️ Medida em
 *     **9, 10 e 11**: a fronteira é onde o erro mora.
 *   · **A exceção registrar QUEM CLICOU em vez de QUEM LIBEROU.** É o centro do critério 11, e a
 *     auditoria-depois inteira depende dele.
 *   · **Alguém "completar" o fluxo com um estado de espera.** A decisão 6 do usuário é que **a
 *     exceção só REGISTRA quem liberou**, e *"exceção aguardando autorização"* **NÃO EXISTE**.
 *     ➡️ Gate de ausência ancorado no **SUJEITO** (não em palavras soltas: *"Aguardando
 *     aprovação"* é vocabulário CORRETO deste domínio e casa dezenas de linhas legítimas), sobre
 *     a **lista CONSTANTE** dos arquivos deste plano, lendo o código **SEM COMENTÁRIO** — porque
 *     os docblocks citam as formas proibidas de propósito, e um gate que lesse comentários
 *     **reprovaria pelo próprio conserto** (abortou TRÊS mutações da wave 187-04).
 *
 * ═══ ⚠️ A RÉGUA DE REDE, A MESMA DA TAREFA 1 ══════════════════════════════════════════════════
 *
 * Nenhuma destas três rotas lê a composição, então aqui o zero é **LITERAL** — e a isca é uma
 * requisição DELIBERADA, que TEM de aparecer no gravador (senão `assertSame(0, …)` seria verdade
 * por omissão). ⚠️ A régua "zero ESCRITA" da tarefa 1 existe para as rotas que LEEM a tarifa real
 * (decisão 9 do usuário), e **não** se aplica por cima desta medição mais forte.
 *
 * ⚠️⚠️ **NENHUM EXECUTOR DESTA FASE VIU ESTA TELA RENDERIZADA.** A conferência visual da Fase 187
 * pelo usuário continua **ABERTA**.
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 */
class EscritaDeAjusteEExcecaoDoPainelTest extends TestCase
{
    use RefreshDatabase;

    private const CLASSICO = 'gold_special';

    private const PREMIUM = 'gold_pro';

    private const CATEGORIA = 'MLB189007';

    /**
     * ⚠️ **A LISTA CONSTANTE dos arquivos DESTE PLANO** — o recorte do gate de ausência.
     *
     * Cobrar o projeto inteiro é a família "gate mais largo que o invariante", que já **barrou um
     * deploy** nesta milestone. E `test -e` em cada caminho é o gate ANTI-FANTASMA: são nove
     * caminhos fantasmas nesta milestone, três deles vindos de briefing.
     *
     * @var list<string>
     */
    private const ARQUIVOS_DO_PLANO = [
        'routes/mlb_anuncios.php',
        'app/Http/Controllers/MlbPublicadorAprovacaoController.php',
        'app/Services/Publicador/Aprovacao/Painel/PainelDeRevisaoService.php',
        'app/Services/Publicador/Aprovacao/Painel/PrecoDoPainelService.php',
    ];

    private ?DadosEfetivosService $efetivos = null;

    private int $sequencia = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-19 11:30:00');

        Http::preventStrayRequests();
        Cache::put('ml_app_token_coleta', 'fake-app-token', 3600);

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
            'nome' => 'Empresa Ajuste '.$company->id,
            'tipo' => 'ASSESSORIA',
            'company_id' => $company->id,
            'responsavel_id' => $admin->id,
        ]);

        return [$company, $empresa, $admin];
    }

    /**
     * ⚠️⚠️ **A FIXTURE QUE DISCRIMINA OS ESCOPOS — E O QUE FOI MEDIDO AO MONTÁ-LA.**
     *
     * **UM produto · UM rascunho · DOIS conjuntos de fotos · DUAS ofertas (Clássico e Premium).**
     *
     * ⚠️⚠️ **O `<behavior>` DO PLANO PEDIA "4 OFERTAS EM 2 CONJUNTOS" DO MESMO PRODUTO, E ISSO É
     * IMPOSSÍVEL NESTE SCHEMA — MEDIDO, não deduzido:** `pub_rascunhos.produto_id` tem UNIQUE
     * (`pubr_produto_uq`, migration `2026_10_02_100100_add_produto_id_to_pub_rascunhos.php:95`,
     * decisão D15/D27 da Fase 164), e a primeira tentativa de criar o segundo rascunho reprovou
     * com *"UNIQUE constraint failed: pub_rascunhos.produto_id"*. ➡️ **Um produto tem UM rascunho**,
     * logo `set` (todas as ofertas do RASCUNHO) e `product` (todas as ofertas do PRODUTO) alcançam
     * **o MESMO conjunto hoje**. A diferença entre os dois é **inobservável por CONTAGEM**, e a
     * premissa fica **TRAVADA POR ASSERÇÃO** no caso (1) — com a diferença provada onde ela É
     * observável: a **coluna-âncora** (`conjunto_id` × `produto_id`) e o **destino legível**.
     *
     * ⚠️ **OS DOIS CONJUNTOS NÃO SÃO ENFEITE:** `ancorasPara()` ancora no **PRIMEIRO** conjunto do
     * rascunho (`orderBy('id')->first()`), e `ofertasAfetadas()` resolve por `rascunho_id` — com um
     * grupo só, "alcança todos os grupos" seria inobservável (é o achado S2 da Fase 186).
     *
     * ⚠️ **E HÁ UM SEGUNDO PRODUTO na MESMA conta** ({@see self::outroProduto()}), porque a ameaça
     * T-188-51 é o escopo movendo **o que não devia**: sem ele, "não vazou" seria verdade por não
     * haver para onde vazar.
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
            'sku' => 'AJU-'.$this->sequencia,
            'nome' => 'Poltrona do ajuste '.$this->sequencia,
            'origem' => PubProduto::ORIGEM_PUBLICADOR,
            'quantidade_kit' => 1,
        ]);

        $rascunho = PubRascunho::create(['produto_id' => $produto->id, 'categoria_id' => self::CATEGORIA]);

        $ofertas = [];
        foreach ([self::CLASSICO, self::PREMIUM] as $i => $tipo) {
            // ⚠️ Flag DENTRO do laço.
            $alvo = PubRascunhoAlvo::create([
                'rascunho_id' => $rascunho->id,
                'listing_type_id' => $tipo,
                'titulo' => 'Poltrona Beny Decorativa Veludo Cinza',
                'posicao' => $i,
            ]);
            $ofertas[$tipo] = PubAprovacaoOferta::create([
                'alvo_id' => $alvo->id,
                'rascunho_id' => $rascunho->id,
                'produto_id' => $produto->id,
                'company_id' => $company->id,
                'mlb_empresa_id' => $empresa?->id,
                'andamento' => PubAprovacaoOferta::AGUARDANDO_APROVACAO,
                'entrou_na_fila_em' => '2026-10-19 08:00:00',
                'responsavel_id' => $o['responsavel_id'] ?? null,
            ]);
        }

        // ⚠️ **DOIS grupos de foto no MESMO rascunho** — ver o docblock.
        $conjuntos = [];
        foreach ([PubAprovacaoConjunto::GERAL, 'COR:Azul'] as $grupo) {
            // ⚠️ Flag DENTRO do laço.
            $conjunto = PubAprovacaoConjunto::create([
                'rascunho_id' => $rascunho->id,
                'grupo_chave' => $grupo,
                'grupo_hash' => ChaveCanonica::hash($grupo.'-'.$rascunho->id),
            ]);
            $primeira = $this->imagem($conjunto, PubAprovacaoImagem::APROVADA, 0);
            $this->imagem($conjunto, PubAprovacaoImagem::APROVADA, 1);
            $conjunto->update(['principal_imagem_id' => $primeira->imagem_id]);
            $conjuntos[$grupo] = $conjunto->refresh();
        }

        $this->custos($produto, $o);

        return [
            'produto' => $produto->fresh(),
            'rascunho' => $rascunho->fresh(),
            'conjuntos' => $conjuntos,
            'classico' => $ofertas[self::CLASSICO]->fresh(),
            'premium' => $ofertas[self::PREMIUM]->fresh(),
        ];
    }

    /**
     * Um SEGUNDO produto completo na mesma conta — o alvo de "não vazou" (T-188-51).
     *
     * @param  array<string, mixed>  $o
     * @return array<string, mixed>
     */
    private function outroProduto(array $o): array
    {
        return $this->cenario($o);
    }

    /** O dublê de custos/fretes/preços, registrado UMA vez e alimentado POR PRODUTO. */
    private function custos(PubProduto $produto, array $o): void
    {
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

        // ⚠️ `array_key_exists`, NUNCA `??`: `['custo' => null]` é um CENÁRIO, não default ausente.
        // ⚠️ E é **POR PRODUTO** — conserto de um falso negativo medido na Fase 186 em 15/10/2026.
        $this->efetivos->custosPorProduto[(int) $produto->id] = [
            'custo' => array_key_exists('custo', $o) ? $o['custo'] : 20.0,
            'origem' => 'digitado',
            'imposto' => 10.0,
            'por_variante' => [],
        ];
        $frete = array_key_exists('frete', $o) ? $o['frete'] : 10.0;
        $porTipo = [];
        $precos = [];
        foreach ([self::CLASSICO, self::PREMIUM] as $tipo) {
            // ⚠️ Flag DENTRO do laço.
            $porTipo[$tipo] = ['valor' => $frete, 'origem' => $frete === null ? null : 'tabela', 'oferta_id' => null];
            $precos[$tipo] = $o['preco'] ?? 100.0;
        }
        $this->efetivos->fretesPorProduto[(int) $produto->id] = ['por_tipo' => $porTipo, 'por_variante' => []];
        $this->efetivos->precosPorProduto[(int) $produto->id] = ['precos' => $precos];
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

    private function url(string $sufixo, Company $c, PubAprovacaoOferta $o, array $extra = []): string
    {
        return route('mlb.anuncios.meus.aprovacao.painel.'.$sufixo, array_merge(['company' => $c->id, 'oferta' => $o->id], $extra));
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
            PubAprovacaoOferta::PUBLICADO => [PubAprovacaoOferta::APROVADO, PubAprovacaoOferta::PUBLICANDO, PubAprovacaoOferta::PUBLICADO],
            default => throw new \LogicException('andamento sem caminho declarado: '.$destino),
        };

        foreach ($caminho as $passo) {
            if ($passo === PubAprovacaoOferta::PUBLICADO) {
                $oferta->update(['ml_item_id' => 'MLB1234567890']);
                $oferta = $oferta->fresh();
            }
            $oferta = $porta->mover($oferta, $passo, $quem);
        }

        return $oferta;
    }

    /** @param  list<PubAprovacaoOferta>  $ofertas */
    private function andamentos(array $ofertas): array
    {
        $mapa = [];
        foreach ($ofertas as $rotulo => $o) {
            // ⚠️ Flag DENTRO do laço. E a leitura é por RECONSULTA ao banco, nunca por stdout.
            $mapa[$rotulo] = (string) $o->fresh()->andamento;
        }

        return $mapa;
    }

    private function totalDeRequisicoes(): int
    {
        // ⚠️ `Http::recorded()` é **Collection**, não array (medido na wave 188-05).
        return Http::recorded()->count();
    }

    /**
     * ⚠️ **A ISCA DO ESPIÃO.** Nenhuma destas três rotas lê a composição, então o zero é LITERAL —
     * e `assertSame(0, …)` seria verdade **por omissão** se o gravador estivesse desligado. Chamar
     * isto no FIM do caso é de propósito: ele polui o gravador.
     */
    private function provarQueOEspiaoVe(): void
    {
        $antes = $this->totalDeRequisicoes();
        Http::get('https://api.mercadolibre.com/sites/MLB/listing_prices?listing_type_id=isca');
        $this->assertSame($antes + 1, $this->totalDeRequisicoes(), 'o espião de rede está CEGO: nem uma requisição deliberada foi vista');
    }

    private function cru(string $relativo): string
    {
        $caminho = base_path($relativo);
        // ⚠️ ANTI-FANTASMA — `test -e` em cada caminho citado.
        $this->assertFileExists($caminho, "caminho FANTASMA na lista do gate: {$relativo}");

        return (string) file_get_contents($caminho);
    }

    /** O código **SEM COMENTÁRIO** — ver a 2ª das treze regras da régua. */
    private function semComentarios(string $relativo): string
    {
        $limpo = '';
        foreach (token_get_all($this->cru($relativo)) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $limpo .= is_array($token) ? $token[1] : $token;
        }

        return $limpo;
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // (1) OS TRÊS ESCOPOS — 1, 2 e 4 ofertas, com a fixture que os distingue
    // ═════════════════════════════════════════════════════════════════════════════════════════

    public function test_os_tres_escopos_movem_exatamente_as_ofertas_do_seu_alcance_188_06(): void
    {
        [$company, $empresa, $admin] = $this->conta();

        // ⚠️⚠️ **A PREMISSA DO SCHEMA, TRAVADA POR ASSERÇÃO — e é ela que explica por que `set` e
        // `product` alcançam o MESMO conjunto hoje.** `pub_rascunhos.produto_id` é UNIQUE
        // (`pubr_produto_uq`), logo um produto tem UM rascunho. Se essa unicidade cair, os dois
        // escopos passam a ter alcances diferentes e **este caso tem de ser refeito** — é
        // exatamente o que esta asserção faz acontecer.
        $indices = array_keys(\Illuminate\Support\Facades\Schema::getIndexes('pub_rascunhos') === []
            ? []
            : array_column(\Illuminate\Support\Facades\Schema::getIndexes('pub_rascunhos'), null, 'name'));
        $unicoPorProduto = array_values(array_filter(
            \Illuminate\Support\Facades\Schema::getIndexes('pub_rascunhos'),
            static fn (array $i): bool => $i['columns'] === ['produto_id'] && ($i['unique'] ?? false) === true,
        ));
        $this->assertCount(
            1,
            $unicoPorProduto,
            'a UNICIDADE de `pub_rascunhos.produto_id` caiu: `set` e `product` passaram a ter alcances DIFERENTES e este caso tem de ser refeito',
        );
        $this->assertNotEmpty($indices, 'ISCA: a leitura dos índices do schema está cega');

        $c = $this->cenario(['company' => $company, 'empresa' => $empresa]);
        $outro = $this->outroProduto(['company' => $company, 'empresa' => $empresa]);

        // ⚠️ A premissa da fixture: DOIS grupos de foto no mesmo rascunho, e um SEGUNDO produto
        // completo na mesma conta (o alvo de "não vazou" — T-188-51).
        $this->assertCount(2, $c['conjuntos'], 'a fixture perdeu o SEGUNDO grupo de fotos');
        $this->assertNotSame((int) $c['produto']->id, (int) $outro['produto']->id);

        $todas = [
            'classico' => $c['classico'], 'premium' => $c['premium'],
            'outro_classico' => $outro['classico'], 'outro_premium' => $outro['premium'],
        ];

        // ─── ESCOPO `offer`: move SÓ aquela ────────────────────────────────────────────────
        $this->actingAs($admin)
            ->post($this->url('ajuste', $company, $c['classico']), [
                'escopo' => PubAprovacaoAjuste::ESCOPO_OFERTA,
                'item' => 'Preço Clássico',
                'texto' => 'Rever preço para atingir a margem mínima',
            ])
            ->assertRedirect()
            ->assertSessionHas('painel_ajuste_pedido');

        $this->assertSame([
            'classico' => PubAprovacaoOferta::AJUSTES_SOLICITADOS,
            'premium' => PubAprovacaoOferta::AGUARDANDO_APROVACAO,
            'outro_classico' => PubAprovacaoOferta::AGUARDANDO_APROVACAO,
            'outro_premium' => PubAprovacaoOferta::AGUARDANDO_APROVACAO,
        ], $this->andamentos($todas), 'escopo `offer` alcançou mais (ou menos) que UMA oferta');

        // ⚠️ A resposta diz QUAIS ofertas foram alcançadas, e o DESTINO legível (VOC-06: a chave
        // `offer` nunca chega à tela).
        $pedido = session('painel_ajuste_pedido');
        $this->assertSame([(int) $c['classico']->id], $pedido['ofertas_afetadas'], 'a resposta não nomeia as ofertas alcançadas');
        $this->assertSame('Este anúncio (Clássico)', $pedido['destino']);
        // ⚠️ E a ÂNCORA é a coluna do escopo — é aqui que `offer`/`set`/`product` são distintos de
        // forma observável, mesmo quando o ALCANCE coincide.
        $ajusteOffer = PubAprovacaoAjuste::query()->findOrFail($pedido['ajuste_id']);
        $this->assertSame((int) $c['classico']->id, (int) $ajusteOffer->oferta_id);
        $this->assertNull($ajusteOffer->conjunto_id);
        $this->assertNull($ajusteOffer->produto_id);
        $this->assertTrue($ajusteOffer->ancoraValida(), 'a âncora do ajuste não corresponde ao escopo');

        // ─── ESCOPO `set`: move AS DUAS do rascunho (o conjunto é COMPARTILHADO) ───────────
        $d = $this->cenario(['company' => $company, 'empresa' => $empresa]);
        $outroD = $this->outroProduto(['company' => $company, 'empresa' => $empresa]);
        $todasD = [
            'classico' => $d['classico'], 'premium' => $d['premium'],
            'outro_classico' => $outroD['classico'], 'outro_premium' => $outroD['premium'],
        ];

        $this->actingAs($admin)
            ->post($this->url('ajuste', $company, $d['classico']), [
                'escopo' => PubAprovacaoAjuste::ESCOPO_CONJUNTO,
                'item' => 'Imagem #1 · Capa',
                'texto' => 'Cor diferente da original',
            ])
            ->assertRedirect();

        $this->assertSame([
            'classico' => PubAprovacaoOferta::AJUSTES_SOLICITADOS,
            'premium' => PubAprovacaoOferta::AJUSTES_SOLICITADOS,
            'outro_classico' => PubAprovacaoOferta::AGUARDANDO_APROVACAO,
            'outro_premium' => PubAprovacaoOferta::AGUARDANDO_APROVACAO,
        ], $this->andamentos($todasD), 'escopo `set` não alcançou a oferta IRMÃ (ou vazou para o outro produto)');

        $pedidoSet = session('painel_ajuste_pedido');
        $this->assertSame('Conjunto de fotos (Clássico e Premium)', $pedidoSet['destino']);
        $ajusteSet = PubAprovacaoAjuste::query()->findOrFail($pedidoSet['ajuste_id']);
        // ⚠️⚠️ **A ÂNCORA É O PRIMEIRO CONJUNTO DO RASCUNHO — e o ALCANCE é o RASCUNHO INTEIRO**,
        // os dois grupos. Com um grupo só isto seria inobservável (achado S2 da Fase 186).
        $this->assertSame((int) $d['conjuntos'][PubAprovacaoConjunto::GERAL]->id, (int) $ajusteSet->conjunto_id);
        $this->assertNull($ajusteSet->oferta_id);
        $this->assertNull($ajusteSet->produto_id);
        $this->assertSame(
            2,
            PubAprovacaoConjunto::query()->where('rascunho_id', $d['rascunho']->id)->count(),
            'o rascunho da fixture deixou de ter DOIS grupos',
        );
        // ⚠️ E o BLOQUEIO `ajuste_em_aberto` vê o ajuste pelas duas ofertas — é o `openC` de
        // `:1086`, lido por `rascunho_id` (não por um `conjunto_id` só).
        $ajustes = app(AjustesDaOfertaService::class);
        $this->assertCount(1, $ajustes->abertosQueAfetam($d['premium']->fresh()), 'o ajuste de conjunto não bloqueia a oferta irmã');

        // ─── ESCOPO `product`: a MESMA dupla — e a diferença é a ÂNCORA, não a contagem ────
        $e = $this->cenario(['company' => $company, 'empresa' => $empresa]);
        $outroE = $this->outroProduto(['company' => $company, 'empresa' => $empresa]);
        $todasE = [
            'classico' => $e['classico'], 'premium' => $e['premium'],
            'outro_classico' => $outroE['classico'], 'outro_premium' => $outroE['premium'],
        ];

        $this->actingAs($admin)
            ->post($this->url('ajuste', $company, $e['classico']), [
                'escopo' => PubAprovacaoAjuste::ESCOPO_PRODUTO,
                'item' => 'Custo do produto',
                'texto' => 'Custo desatualizado no ERP',
            ])
            ->assertRedirect();

        $this->assertSame([
            'classico' => PubAprovacaoOferta::AJUSTES_SOLICITADOS,
            'premium' => PubAprovacaoOferta::AJUSTES_SOLICITADOS,
            // ⚠️⚠️ **A DIREÇÃO QUE PROTEGE CONTRA T-188-51:** `product` alcança **todas as ofertas
            // DAQUELE produto**, e **nenhuma** do outro. Sem o segundo produto na fixture, isto
            // seria verdade por não haver para onde vazar.
            'outro_classico' => PubAprovacaoOferta::AGUARDANDO_APROVACAO,
            'outro_premium' => PubAprovacaoOferta::AGUARDANDO_APROVACAO,
        ], $this->andamentos($todasE), 'escopo `product` vazou para o OUTRO produto da mesma conta');

        $pedidoProd = session('painel_ajuste_pedido');
        $this->assertSame('Cadastro do produto (todas as ofertas)', $pedidoProd['destino']);
        $ajusteProd = PubAprovacaoAjuste::query()->findOrFail($pedidoProd['ajuste_id']);
        $this->assertSame((int) $e['produto']->id, (int) $ajusteProd->produto_id);
        $this->assertNull($ajusteProd->oferta_id);
        $this->assertNull($ajusteProd->conjunto_id);
        // ⚠️ **AS TRÊS ÂNCORAS SÃO TRÊS COLUNAS DIFERENTES** — é a prova observável de que os três
        // escopos não colapsaram num só, mesmo com `set` e `product` coincidindo em ALCANCE.
        $this->assertSame(
            ['oferta_id', 'conjunto_id', 'produto_id'],
            [
                PubAprovacaoAjuste::ANCORA_DO_ESCOPO[$ajusteOffer->escopo],
                PubAprovacaoAjuste::ANCORA_DO_ESCOPO[$ajusteSet->escopo],
                PubAprovacaoAjuste::ANCORA_DO_ESCOPO[$ajusteProd->escopo],
            ],
            'dois escopos passaram a usar a MESMA coluna-âncora',
        );

        // ⚠️ E TODAS as alcançadas recebem o evento no histórico — sem ele, o histórico do Premium
        // não explicaria por que ele saiu da fila.
        $semEvento = [];
        foreach (['classico' => $e['classico'], 'premium' => $e['premium']] as $rotulo => $oferta) {
            // ⚠️ Flag DENTRO do laço.
            $textos = PubAprovacaoEvento::query()->where('oferta_id', $oferta->id)->pluck('texto')->all();
            if (array_filter($textos, static fn (string $t): bool => str_contains($t, 'Solicitou ajuste em “Custo do produto”')) === []) {
                $semEvento[] = $rotulo;
            }
        }
        $this->assertSame([], $semEvento, 'oferta alcançada pelo escopo ficou sem evento no histórico');

        // ⚠️ O zero de rede é LITERAL nesta rota, e a isca prova que o gravador está vivo.
        $this->assertSame(0, $this->totalDeRequisicoes(), 'pedir ajuste falou com o Mercado Livre');
        $this->provarQueOEspiaoVe();
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // (2) `publicando`/`publicado` NÃO vão para `changes` (`:1198`)
    // ═════════════════════════════════════════════════════════════════════════════════════════

    public function test_publicando_e_publicado_nao_vao_para_changes_188_06(): void
    {
        [$company, $empresa, $admin] = $this->conta();
        $c = $this->cenario(['company' => $company, 'empresa' => $empresa]);
        $d = $this->outroProduto(['company' => $company, 'empresa' => $empresa]);

        // ⚠️⚠️ **O CENÁRIO TEM DE SER POSSÍVEL:** as ofertas são levadas aos estados pela ÚNICA
        // PORTA antes de qualquer medição. Medir "não reabriu" numa oferta que nunca esteve lá é
        // o cenário IMPOSSÍVEL que passa sem provar nada (achado da wave 188-05).
        $publicando = $this->levarAo($c['premium'], PubAprovacaoOferta::PUBLICANDO, $admin);
        $publicado = $this->levarAo($d['premium'], PubAprovacaoOferta::PUBLICADO, $admin);
        $this->assertSame(PubAprovacaoOferta::PUBLICANDO, (string) $publicando->andamento);
        $this->assertSame(PubAprovacaoOferta::PUBLICADO, (string) $publicado->andamento);

        // Escopo `set` no produto de `$c`: ele ALCANÇA as duas ofertas daquele rascunho — uma ao
        // vivo e uma `publicando`.
        $this->actingAs($admin)
            ->post($this->url('ajuste', $company, $c['classico']), [
                'escopo' => PubAprovacaoAjuste::ESCOPO_CONJUNTO,
                'item' => 'Imagem #2 · Foto 2',
                'texto' => 'Quantidade do kit incorreta',
            ])
            ->assertRedirect();

        // Escopo `product` no produto de `$d`: alcança as duas, uma ao vivo e uma `publicado`.
        $this->actingAs($admin)
            ->post($this->url('ajuste', $company, $d['classico']), [
                'escopo' => PubAprovacaoAjuste::ESCOPO_PRODUTO,
                'item' => 'Atributos e ficha técnica',
                'texto' => 'Atributo obrigatório divergente',
            ])
            ->assertRedirect();

        $this->assertSame([
            'ao_vivo_c' => PubAprovacaoOferta::AJUSTES_SOLICITADOS,
            'publicando' => PubAprovacaoOferta::PUBLICANDO,
            'ao_vivo_d' => PubAprovacaoOferta::AJUSTES_SOLICITADOS,
            'publicado' => PubAprovacaoOferta::PUBLICADO,
        ], $this->andamentos([
            'ao_vivo_c' => $c['classico'], 'publicando' => $publicando,
            'ao_vivo_d' => $d['classico'], 'publicado' => $publicado,
        ]), '`:1198`: oferta já no ar foi mandada para `changes`');

        // ⚠️ **MAS O EVENTO VAI PARA TODAS** — o fato aconteceu, e o histórico de quem está no ar
        // tem de registrar que alguém pediu ajuste. É a divergência declarada de
        // `AjustesDaOfertaService`: fora do `live`, recebe o evento e **não** muda de andamento.
        $semEvento = [];
        foreach (['publicando' => [$publicando, 'Imagem #2 · Foto 2'], 'publicado' => [$publicado, 'Atributos e ficha técnica']] as $rotulo => [$oferta, $item]) {
            // ⚠️ Flag DENTRO do laço.
            $textos = PubAprovacaoEvento::query()->where('oferta_id', $oferta->id)->pluck('texto')->all();
            if (array_filter($textos, static fn (string $t): bool => str_contains($t, 'Solicitou ajuste em “'.$item.'”')) === []) {
                $semEvento[] = $rotulo;
            }
        }
        $this->assertSame([], $semEvento, 'a oferta no ar não registrou que houve pedido de ajuste');

        // ⚠️ ISCA: a sonda do evento DEVE achar algo quando ele existe — senão o `assertSame([], …)`
        // acima seria verdade por a frase nunca casar (a família do "silêncio que depende de uma
        // letra", com a cedilha e as aspas curvas dentro).
        $this->assertNotEmpty(
            array_filter(
                PubAprovacaoEvento::query()->where('oferta_id', $c['classico']->id)->pluck('texto')->all(),
                static fn (string $t): bool => str_contains($t, 'Solicitou ajuste em “Imagem #2 · Foto 2”'),
            ),
            'ISCA: a sonda do evento de ajuste está cega',
        );

        $this->assertSame(0, $this->totalDeRequisicoes(), 'pedir ajuste falou com o Mercado Livre');
        $this->provarQueOEspiaoVe();
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // (3) AS RECUSAS DO AJUSTE — escopo, âncora e texto
    // ═════════════════════════════════════════════════════════════════════════════════════════

    public function test_escopo_invalido_ancora_incoerente_e_texto_vazio_recusam_sem_gravar_188_06(): void
    {
        [$company, $empresa, $admin] = $this->conta();
        $c = $this->cenario(['company' => $company, 'empresa' => $empresa]);
        $oferta = $c['classico'];

        $antes = PubAprovacaoAjuste::query()->count();
        $infratores = [];

        // ⚠️ **`prod` DO PROTÓTIPO É RECUSADO DE PROPÓSITO:** `ESCOPO_DO_PROTOTIPO` é mapa de
        // CONFERÊNCIA, não um `||` que aceita as duas formas. E os rótulos pt-BR também caem.
        foreach (['prod', 'oferta', 'conjunto', 'produto', 'OFFER', '', 'inventado'] as $escopo) {
            // ⚠️ Flag DENTRO do laço.
            $r = $this->actingAs($admin)->postJson($this->url('ajuste', $company, $oferta), [
                'escopo' => $escopo, 'item' => 'Título', 'texto' => 'Incluir medida e material no título',
            ]);
            $certo = $r->status() === 422
                && ($escopo === '' || $r->json('regra') === AjustesDaOfertaService::REGRA_ESCOPO_INVALIDO);
            if (! $certo) {
                $infratores[] = 'escopo '.var_export($escopo, true).' => '.$r->status().'/'.(string) $r->json('regra');
            }
        }

        // ⚠️ **ISCA DO RECORTE:** as TRÊS chaves legítimas TÊM de ser aceitas.
        $aceitas = 0;
        foreach (PubAprovacaoAjuste::ESCOPOS as $escopo) {
            // ⚠️ Flag DENTRO do laço, e uma oferta NOVA por escopo.
            $cn = $this->cenario(['company' => $company, 'empresa' => $empresa]);
            $r = $this->actingAs($admin)->post($this->url('ajuste', $company, $cn['classico']), [
                'escopo' => $escopo, 'item' => 'Título', 'texto' => 'Incluir medida e material no título',
            ]);
            $aceitas += $r->status() === 302 ? 1 : 0;
        }
        $this->assertSame(3, $aceitas, 'a isca falhou: escopo LEGÍTIMO foi recusado');

        // ─── ÂNCORA INCOERENTE: escopo `product` numa oferta SEM produto ───────────────────
        // ⚠️ O cenário é construído de verdade (`produto_id` nulo é real: a FK é `SET NULL`), e
        // sem ele a regra `DADO-09-ANCORA-INCOERENTE` nunca seria exercitada.
        $semProduto = $this->cenario(['company' => $company, 'empresa' => $empresa]);
        $orfa = $semProduto['classico'];
        $orfa->update(['produto_id' => null]);
        $r = $this->actingAs($admin)->postJson($this->url('ajuste', $company, $orfa->fresh()), [
            'escopo' => PubAprovacaoAjuste::ESCOPO_PRODUTO, 'item' => 'Custo do produto', 'texto' => 'Custo desatualizado no ERP',
        ]);
        if ($r->status() !== 422 || $r->json('regra') !== AjustesDaOfertaService::REGRA_ANCORA_INVALIDA) {
            $infratores[] = 'ancora => '.$r->status().'/'.(string) $r->json('regra');
        }

        // ─── TEXTO VAZIO / SÓ ESPAÇOS ──────────────────────────────────────────────────────
        // ⚠️⚠️ **PELA ROTA O CAMINHO DO SERVIÇO É INALCANÇÁVEL, E ISSO FOI MEDIDO (wave 188-05):**
        // o middleware `TrimStrings` chega ANTES da validação, `'     '` vira `''` e morre no
        // `required`, com `errors.texto` em vez de `MOD-01-AJUSTE-SEM-TEXTO`. ➡️ A rota recusa (é
        // o que importa para o usuário) e o **backstop do serviço é exercitado DIRETO** abaixo.
        foreach (['', '   ', "\t \n"] as $texto) {
            // ⚠️ Flag DENTRO do laço.
            $r = $this->actingAs($admin)->postJson($this->url('ajuste', $company, $oferta), [
                'escopo' => PubAprovacaoAjuste::ESCOPO_OFERTA, 'item' => 'Título', 'texto' => $texto,
            ]);
            if ($r->status() !== 422) {
                $infratores[] = 'texto '.var_export($texto, true).' => '.$r->status();
            }
        }

        $this->assertSame([], $infratores, 'escopo/âncora/texto inválido foi aceito');

        // ⚠️ O BACKSTOP, EXERCITADO DIRETO — é o que mantém a guarda do serviço viva no dia em que
        // alguém a apagar. Afirmá-la pela rota mediria o caminho errado.
        $servico = app(AjustesDaOfertaService::class);
        try {
            $servico->pedir($oferta->fresh(), $admin, PubAprovacaoAjuste::ESCOPO_OFERTA, 'Título', '     ');
            $this->fail('o serviço aceitou ajuste com texto só de espaços');
        } catch (RegraViolada $e) {
            $this->assertSame(AjustesDaOfertaService::REGRA_TEXTO_VAZIO, $e->regra);
        }

        // ⚠️ Nenhuma das recusas gravou: a contagem é a de ANTES **mais** as 3 iscas legítimas.
        $this->assertSame($antes + 3, PubAprovacaoAjuste::query()->count(), 'uma recusa de ajuste GRAVOU');

        $this->assertSame(0, $this->totalDeRequisicoes(), 'pedir ajuste falou com o Mercado Livre');
        $this->provarQueOEspiaoVe();
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // (4) RESOLVER — os DOIS sentidos, na MESMA fixture (AND-07)
    // ═════════════════════════════════════════════════════════════════════════════════════════

    public function test_resolver_o_ultimo_devolve_para_awaiting_e_havendo_outro_continua_em_changes_188_06(): void
    {
        [$company, $empresa, $admin] = $this->conta();
        $c = $this->cenario(['company' => $company, 'empresa' => $empresa]);
        $oferta = $c['classico'];
        $ajustes = app(AjustesDaOfertaService::class);

        // ⚠️ DOIS ajustes de escopo `offer` na MESMA oferta — é a fixture que distingue
        // "resolver o último" de "resolver qualquer um".
        foreach (['Título', 'Descrição'] as $item) {
            // ⚠️ Flag DENTRO do laço.
            $this->actingAs($admin)->post($this->url('ajuste', $company, $oferta), [
                'escopo' => PubAprovacaoAjuste::ESCOPO_OFERTA, 'item' => $item, 'texto' => 'Pedido sobre '.$item,
            ])->assertRedirect();
        }

        $abertos = $ajustes->abertosQueAfetam($oferta->fresh());
        $this->assertCount(2, $abertos, 'a fixture não tem DOIS ajustes abertos: o caso não distinguiria nada');
        $this->assertSame(PubAprovacaoOferta::AJUSTES_SOLICITADOS, (string) $oferta->fresh()->andamento);

        // ─── (a) RESOLVER UM DE DOIS: a oferta CONTINUA em `changes` ───────────────────────
        $primeiro = $abertos->firstWhere('item', 'Título');
        $this->actingAs($admin)
            ->post($this->url('ajuste.resolver', $company, $oferta, ['ajuste' => $primeiro->id]))
            ->assertRedirect()
            ->assertSessionHas('painel_ajuste_resolvido');

        $this->assertSame(
            PubAprovacaoOferta::AJUSTES_SOLICITADOS,
            (string) $oferta->fresh()->andamento,
            'AND-07: resolver UM de DOIS devolveu a oferta para a fila com um ajuste ainda aberto',
        );
        $this->assertSame(1, session('painel_ajuste_resolvido')['restantes'], 'a contagem de restantes está errada');
        $this->assertFalse((bool) $primeiro->fresh()->aberto, 'o ajuste resolvido continuou aberto');
        $this->assertSame($admin->id, (int) $primeiro->fresh()->resolvido_por);
        $this->assertNotNull($primeiro->fresh()->resolvido_em);

        // ─── (b) RESOLVER O ÚLTIMO: a oferta VOLTA para `awaiting` — pela ÚNICA PORTA ──────
        $ultimo = $ajustes->abertosQueAfetam($oferta->fresh())->first();
        $this->actingAs($admin)
            ->post($this->url('ajuste.resolver', $company, $oferta, ['ajuste' => $ultimo->id]))
            ->assertRedirect();

        $this->assertSame(
            PubAprovacaoOferta::AGUARDANDO_APROVACAO,
            (string) $oferta->fresh()->andamento,
            'AND-07: resolver o ÚLTIMO não devolveu a oferta para a fila de conferência',
        );
        $this->assertSame(0, session('painel_ajuste_resolvido')['restantes']);

        // ⚠️ E o registro de cada resolução, na forma de `:1202`, com o item NOMEADO.
        $textos = PubAprovacaoEvento::query()->where('oferta_id', $oferta->id)->pluck('texto')->all();
        foreach (['Marcou como resolvido: “Título”', 'Marcou como resolvido: “Descrição”'] as $frase) {
            // ⚠️ Flag DENTRO do laço.
            $this->assertContains($frase, $textos, 'a resolução não foi registrada com o item nomeado');
        }

        $this->assertSame(0, $this->totalDeRequisicoes(), 'resolver ajuste falou com o Mercado Livre');
        $this->provarQueOEspiaoVe();
    }

    public function test_ajuste_de_outra_conta_ou_ja_resolvido_da_404_188_06(): void
    {
        [$company, $empresa, $admin] = $this->conta();
        [$outraCompany, $outraEmpresa, $outroAdmin] = $this->conta();

        $meu = $this->cenario(['company' => $company, 'empresa' => $empresa]);
        $alheio = $this->cenario(['company' => $outraCompany, 'empresa' => $outraEmpresa]);

        // Um ajuste de cada lado, pelas rotas de cada conta.
        $this->actingAs($admin)->post($this->url('ajuste', $company, $meu['classico']), [
            'escopo' => PubAprovacaoAjuste::ESCOPO_OFERTA, 'item' => 'Título', 'texto' => 'Pedido meu',
        ])->assertRedirect();
        $this->actingAs($outroAdmin)->post($this->url('ajuste', $outraCompany, $alheio['classico']), [
            'escopo' => PubAprovacaoAjuste::ESCOPO_OFERTA, 'item' => 'Título', 'texto' => 'Pedido alheio',
        ])->assertRedirect();

        $ajustes = app(AjustesDaOfertaService::class);
        $meuAjuste = $ajustes->abertosQueAfetam($meu['classico']->fresh())->first();
        $ajusteAlheio = $ajustes->abertosQueAfetam($alheio['classico']->fresh())->first();
        $this->assertNotNull($ajusteAlheio, 'a fixture da outra conta não criou ajuste: o 404 seria trivial');
        $this->assertNotSame((int) $meuAjuste->id, (int) $ajusteAlheio->id);

        // ⚠️ **404, NUNCA 403** (T-188-58): um 403 confirmaria que o id existe, que é metade do
        // vazamento. E o filtro é a CONSULTA (`abertosQueAfetam()`), não um `in_array` no PHP.
        $this->actingAs($admin)
            ->post($this->url('ajuste.resolver', $company, $meu['classico'], ['ajuste' => $ajusteAlheio->id]))
            ->assertNotFound();
        $this->assertTrue((bool) $ajusteAlheio->fresh()->aberto, 'o ajuste da outra conta foi resolvido');

        // ⚠️ ISCA: o MEU ajuste resolve — senão o 404 acima passaria com uma rota que dá 404 sempre.
        $this->actingAs($admin)
            ->post($this->url('ajuste.resolver', $company, $meu['classico'], ['ajuste' => $meuAjuste->id]))
            ->assertRedirect();
        $this->assertFalse((bool) $meuAjuste->fresh()->aberto, 'a isca falhou: o ajuste da própria conta não resolveu');

        // ⚠️ E resolver DUAS VEZES dá 404: `abertosQueAfetam()` só traz os abertos, e resolver de
        // novo registraria o mesmo fato duas vezes na linha do tempo.
        $this->actingAs($admin)
            ->post($this->url('ajuste.resolver', $company, $meu['classico'], ['ajuste' => $meuAjuste->id]))
            ->assertNotFound();
        $this->assertSame(
            1,
            count(array_filter(
                PubAprovacaoEvento::query()->where('oferta_id', $meu['classico']->id)->pluck('texto')->all(),
                static fn (string $t): bool => str_contains($t, 'Marcou como resolvido: “Título”'),
            )),
            'a resolução foi registrada DUAS vezes para o mesmo fato',
        );

        $this->assertSame(0, $this->totalDeRequisicoes(), 'resolver ajuste falou com o Mercado Livre');
        $this->provarQueOEspiaoVe();
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // (5) A EXCEÇÃO LIBERA SÓ DOIS — e a ISCA é POR BLOQUEIO, nos SEIS outros
    // ═════════════════════════════════════════════════════════════════════════════════════════

    public function test_a_excecao_libera_so_os_dois_financeiros_e_recusa_os_seis_outros_188_06(): void
    {
        [$company, $empresa, $admin] = $this->conta();
        $c = $this->cenario(['company' => $company, 'empresa' => $empresa, 'responsavel_id' => $admin->id]);
        $oferta = $c['classico'];

        // ⚠️ A lista FECHADA de quem a exceção libera é LIDA do modelo — nunca uma segunda lista.
        $this->assertSame(
            [PubAprovacaoExcecao::RESULTADO_NEGATIVO, PubAprovacaoExcecao::MARGEM_ABAIXO_DO_MINIMO],
            PubAprovacaoExcecao::LIBERA,
            'BLOQ-08: a lista do que a exceção libera mudou',
        );

        // ⚠️⚠️ **OS SEIS OUTROS, UM POR UM** — e eles saem de `VocabularioDoPortao::OS_OITO`
        // MENOS os dois de `LIBERA`, nunca de uma lista escrita à mão que poderia ficar velha.
        $osSeis = array_values(array_diff(VocabularioDoPortao::OS_OITO, PubAprovacaoExcecao::LIBERA));
        $this->assertCount(6, $osSeis, 'os oito bloqueios menos os dois liberáveis deixaram de ser SEIS');

        $antes = PubAprovacaoExcecao::query()->count();
        $liberouIndevidamente = [];

        // ⚠️ Além dos seis, as chaves do PROTÓTIPO (`loss`/`margin`) também caem: elas são mapa de
        // conferência, não uma segunda forma aceita.
        foreach (array_merge($osSeis, ['loss', 'margin', 'qualquer_coisa', '']) as $tipo) {
            // ⚠️ Flag DENTRO do laço.
            $r = $this->actingAs($admin)->postJson($this->url('excecao', $company, $oferta), [
                'tipo' => $tipo,
                'justificativa' => 'oferta de entrada para ganhar relevância por 15 dias',
                'responsavel_id' => $admin->id,
            ]);
            $certo = $r->status() === 422
                && ($tipo === '' || $r->json('regra') === AjustesDaOfertaService::REGRA_EXCECAO_NAO_LIBERA);
            if (! $certo) {
                $liberouIndevidamente[] = $tipo.' => '.$r->status().'/'.(string) $r->json('regra');
            }
        }

        // ⚠️ UMA asserção, coletando os infratores — e ela nomeia QUAL bloqueio passou.
        $this->assertSame([], $liberouIndevidamente, 'T-188-50: a exceção liberou bloqueio que não é dela');
        $this->assertSame($antes, PubAprovacaoExcecao::query()->count(), 'uma recusa de exceção GRAVOU');

        // ⚠️⚠️ **ISCA NAS DUAS DIREÇÕES:** os DOIS legítimos TÊM de ser aceitos — senão o laço
        // acima passaria com uma rota que recusa toda exceção, e o gate seria vazio.
        $aceitos = [];
        foreach (PubAprovacaoExcecao::LIBERA as $tipo) {
            // ⚠️ Flag DENTRO do laço, e uma oferta NOVA por tipo (há `unique` por oferta+tipo).
            $cn = $this->cenario(['company' => $company, 'empresa' => $empresa, 'responsavel_id' => $admin->id]);
            $r = $this->actingAs($admin)->post($this->url('excecao', $company, $cn['classico']), [
                'tipo' => $tipo,
                'justificativa' => 'oferta de entrada para ganhar relevância por 15 dias',
                'responsavel_id' => $admin->id,
            ]);
            $aceitos[$tipo] = $r->status();
        }
        $this->assertSame(
            [PubAprovacaoExcecao::RESULTADO_NEGATIVO => 302, PubAprovacaoExcecao::MARGEM_ABAIXO_DO_MINIMO => 302],
            $aceitos,
            'a isca falhou: a exceção recusou um dos DOIS tipos que ela libera',
        );
        $this->assertSame($antes + 2, PubAprovacaoExcecao::query()->count(), 'as duas exceções legítimas não gravaram');

        $this->assertSame(0, $this->totalDeRequisicoes(), 'registrar exceção falou com o Mercado Livre');
        $this->provarQueOEspiaoVe();
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // (6) 9 · 10 · 11 CARACTERES, e o RESPONSÁVEL — que é quem LIBEROU
    // ═════════════════════════════════════════════════════════════════════════════════════════

    public function test_justificativa_em_9_10_e_11_caracteres_e_o_responsavel_da_conta_188_06(): void
    {
        [$company, $empresa, $admin] = $this->conta();
        $gestor = User::factory()->create(['role' => 'admin', 'name' => 'Carla Mendes']);

        // ⚠️ O `gestor` é responsável de ofertas DESTA conta — é isso que o põe na lista FECHADA
        // de autorizadores. Sem isso, o 404 do responsável seria trivial.
        $c = $this->cenario(['company' => $company, 'empresa' => $empresa, 'responsavel_id' => $gestor->id]);

        // ⚠️ **A TRAVA É `>= 10`, NÃO `> 10`** (`:1203`: `length < 10` recusa). O mínimo é LIDO do
        // modelo, nunca o literal `10`.
        $this->assertSame(10, PubAprovacaoExcecao::MINIMO_JUSTIFICATIVA, 'o mínimo da justificativa mudou');

        $fronteira = [];
        foreach ([9 => 422, 10 => 302, 11 => 302] as $tamanho => $esperado) {
            // ⚠️ Flag DENTRO do laço, e uma oferta NOVA por tamanho.
            $cn = $this->cenario(['company' => $company, 'empresa' => $empresa, 'responsavel_id' => $gestor->id]);
            $r = $this->actingAs($admin)->postJson($this->url('excecao', $company, $cn['classico']), [
                'tipo' => PubAprovacaoExcecao::RESULTADO_NEGATIVO,
                'justificativa' => str_repeat('a', $tamanho),
                'responsavel_id' => $gestor->id,
            ]);
            if ($r->status() !== $esperado) {
                $fronteira[] = $tamanho.' => '.$r->status().' (esperado '.$esperado.')';
            }
            if ($tamanho === 9 && $r->json('regra') !== AjustesDaOfertaService::REGRA_JUSTIFICATIVA_CURTA) {
                $fronteira[] = '9 => regra '.(string) $r->json('regra');
            }
        }
        $this->assertSame([], $fronteira, 'a fronteira de 10 caracteres da justificativa está errada');

        // ⚠️⚠️ **QUEM LIBEROU É O RESPONSÁVEL ESCOLHIDO, NÃO QUEM CLICOU** — é o centro do
        // critério 11, e a auditoria-depois inteira depende disso (`:1203` usa `F.by`).
        $this->actingAs($admin)
            ->post($this->url('excecao', $company, $c['classico']), [
                'tipo' => PubAprovacaoExcecao::MARGEM_ABAIXO_DO_MINIMO,
                'justificativa' => 'oferta de entrada para ganhar relevância por 15 dias',
                'responsavel_id' => $gestor->id,
            ])
            ->assertRedirect();

        $excecao = PubAprovacaoExcecao::query()->where('oferta_id', $c['classico']->id)->latest('id')->first();
        $this->assertNotNull($excecao);
        $this->assertSame($gestor->id, (int) $excecao->liberada_por, 'critério 11: gravou quem CLICOU, não quem LIBEROU');
        $this->assertNotSame($admin->id, (int) $excecao->liberada_por, 'quem clicou não pode ser quem liberou nesta fixture');
        $this->assertNotNull($excecao->liberada_em);
        $this->assertSame((int) $c['classico']->fresh()->versao, (int) $excecao->versao, 'BLOQ-05: a exceção não guardou a versão');
        $this->assertTrue((bool) $excecao->ativa);

        // ⚠️ O histórico registra **com quem liberou** como AUTOR do evento.
        $evento = PubAprovacaoEvento::query()
            ->where('oferta_id', $c['classico']->id)
            ->where('tipo', PubAprovacaoEvento::EXCECAO)
            ->latest('id')->first();
        $this->assertNotNull($evento, 'a exceção não entrou no histórico');
        // ⚠️ A coluna é `autor_id` (COL-05 — e há uma SEGUNDA, `ator_sistema`, para desambiguar
        // o nulo do usuário apagado). `quem_id` não existe nesta tabela.
        $this->assertSame($gestor->id, (int) $evento->autor_id, 'o autor do evento não é quem liberou');
        $this->assertFalse((bool) $evento->ator_sistema, 'o ato foi gravado como se fosse da automação');
        $this->assertStringContainsString('Autorizou exceção (Margem abaixo do mínimo)', (string) $evento->texto);

        // ⚠️ **A FRASE DO CRITÉRIO 11 É A DE EXIBIÇÃO**, lida do modelo — nunca remontada.
        // DIVERGÊNCIA DECLARADA (e já declarada pela Fase 186 e pela wave 188-05): o EVENTO é
        // *"Autorizou exceção (…): …"*; o *"§ Exceção autorizada por X · data · justificativa"* é
        // `fraseDeExibicao()`, que o painel entrega em `preco.excecao`.
        $this->assertSame(
            '§ Exceção autorizada por Carla Mendes · 19/10/2026 11:30 · oferta de entrada para ganhar relevância por 15 dias',
            $excecao->load('liberadaPor')->fraseDeExibicao(),
            'a frase de exibição do critério 11 saiu de outra forma',
        );
        $this->assertSame(session('painel_excecao_registrada')['frase'], $excecao->fraseDeExibicao(), 'a resposta remontou a frase');

        // ─── RESPONSÁVEL FORA DA CONTA → 404, nunca 403 ────────────────────────────────────
        $deFora = User::factory()->create(['role' => 'admin', 'name' => 'Alguém de Fora']);
        $antes = PubAprovacaoExcecao::query()->count();
        $this->actingAs($admin)
            ->post($this->url('excecao', $company, $c['premium']), [
                'tipo' => PubAprovacaoExcecao::RESULTADO_NEGATIVO,
                'justificativa' => 'oferta de entrada para ganhar relevância por 15 dias',
                'responsavel_id' => $deFora->id,
            ])
            ->assertNotFound();
        $this->assertSame($antes, PubAprovacaoExcecao::query()->count(), 'exceção com responsável de fora GRAVOU');

        $this->assertSame(0, $this->totalDeRequisicoes(), 'registrar exceção falou com o Mercado Livre');
        $this->provarQueOEspiaoVe();
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // (7) NÃO EXISTE "EXCEÇÃO AGUARDANDO AUTORIZAÇÃO" — gate de ausência, ancorado no SUJEITO
    // ═════════════════════════════════════════════════════════════════════════════════════════

    public function test_nao_existe_excecao_aguardando_autorizacao_188_06(): void
    {
        // ⚠️⚠️ **DECISÃO 6 DO USUÁRIO:** a exceção só REGISTRA quem liberou. Não existe estado de
        // espera, não existe segundo passo, não existe rota de autorização posterior. "Aprovação
        // em dois passos" foi OFERECIDA e RECUSADA em 14/10/2026.
        //
        // ⚠️ **ANCORADO NO SUJEITO, não em palavras soltas:** *"Aguardando aprovação"* é
        // vocabulário CORRETO deste domínio (é o rótulo do primeiro andamento) e casa dezenas de
        // linhas legítimas — cobrá-lo seria a família "gate mais largo que o invariante", que já
        // barrou um deploy nesta milestone. As formas abaixo falam da EXCEÇÃO.
        //
        // ⚠️ **COM OS ACENTOS E A CEDILHA:** `aprovad` **não** casa `aprovação`, e `excecao` não
        // casa `exceção`. Em pt-BR, silêncio que depende de uma letra é sorte.
        $proibidas = [
            'exceção aguardando', 'excecao aguardando',
            'aguardando autorização', 'aguardando autorizacao',
            'exceção pendente', 'excecao pendente',
            'exceção solicitada', 'excecao solicitada',
            'solicitarexcecao', 'pendente de aprovação do gestor', 'pendente de aprovacao do gestor',
            'autorizar exceção depois', 'autorizar excecao depois',
        ];

        $achados = [];
        foreach (self::ARQUIVOS_DO_PLANO as $relativo) {
            // ⚠️ Flag DENTRO do laço. E o código é lido **SEM COMENTÁRIO**: os docblocks citam as
            // formas proibidas de propósito, e um gate que lesse comentários reprovaria pelo
            // PRÓPRIO conserto (abortou TRÊS mutações da wave 187-04).
            $fonte = mb_strtolower($this->semComentarios($relativo));
            foreach ($proibidas as $forma) {
                if (str_contains($fonte, $forma)) {
                    $achados[] = $relativo.' :: '.$forma;
                }
            }
        }
        $this->assertSame([], $achados, 'DADO-11: apareceu um estado de espera para a exceção');

        // ⚠️⚠️ **ISCA DAS DUAS DIREÇÕES.**
        // (a) A sonda DEVE pegar a forma proibida quando ela existe.
        $comProibida = mb_strtolower('O painel mostra Exceção aguardando autorização do gestor.');
        $pegou = array_values(array_filter($proibidas, static fn (string $f): bool => str_contains($comProibida, $f)));
        $this->assertNotEmpty($pegou, 'ISCA (a): a sonda do estado de espera está CEGA');
        // (b) E ela NÃO pode pegar o vocabulário legítimo do domínio.
        $legitimo = mb_strtolower('Aguardando aprovação · a oferta está na fila de conferência e a exceção já foi autorizada.');
        $falsos = array_values(array_filter($proibidas, static fn (string $f): bool => str_contains($legitimo, $f)));
        $this->assertSame([], $falsos, 'ISCA (b): a sonda casa vocabulário LEGÍTIMO do domínio');

        // ⚠️ E o SUJEITO existe mesmo nos arquivos: sem isso o laço acima rodaria sobre texto que
        // nem fala de exceção, e o gate seria vazio por construção.
        $temOSujeito = false;
        foreach (self::ARQUIVOS_DO_PLANO as $relativo) {
            // ⚠️ Flag DENTRO do laço.
            $temOSujeito = $temOSujeito || str_contains(mb_strtolower($this->semComentarios($relativo)), 'excecao');
        }
        $this->assertTrue($temOSujeito, 'nenhum arquivo do plano fala de exceção: o gate está medindo o nada');

        // ⚠️ E não existe ROTA de autorização posterior: igualdade de conjunto das 14 rotas do
        // painel, nunca um piso (piso NÃO prova presença).
        $doPainel = [];
        foreach (app('router')->getRoutes() as $rota) {
            // ⚠️ Flag DENTRO do laço.
            $nome = (string) $rota->getName();
            if (str_starts_with($nome, 'mlb.anuncios.meus.aprovacao.painel.')) {
                $doPainel[] = $nome;
            }
        }
        sort($doPainel);
        $this->assertSame([
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
        ], $doPainel, 'as rotas do painel mudaram — nenhuma delas pode ser "autorizar exceção depois"');

        // ⚠️ ISCA: a sonda de rota DEVE casar algo que existe.
        $this->assertNotEmpty($doPainel, 'ISCA: a sonda não achou NENHUMA rota do painel');

        // ⚠️ E `PubAprovacaoExcecao` **não tem coluna de status**: `ativa` é o único filtro de
        // vigência. Uma coluna de status seria o estado de espera entrando pela porta do banco.
        $this->assertSame(
            [],
            array_values(array_filter(
                \Illuminate\Support\Facades\Schema::getColumnListing('pub_aprovacao_excecoes'),
                static fn (string $c): bool => in_array($c, ['status', 'aguardando', 'autorizada_em', 'solicitada_por'], true),
            )),
            'DADO-11: apareceu coluna de estado de espera em `pub_aprovacao_excecoes`',
        );
        // ⚠️ ISCA: a sonda de coluna DEVE achar as colunas que EXISTEM.
        $this->assertContains('ativa', \Illuminate\Support\Facades\Schema::getColumnListing('pub_aprovacao_excecoes'), 'ISCA: a leitura do schema está cega');
    }
}
