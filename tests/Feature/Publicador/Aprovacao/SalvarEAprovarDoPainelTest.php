<?php

namespace Tests\Feature\Publicador\Aprovacao;

use App\Models\Company;
use App\Models\MlbEmpresa;
use App\Models\MlToken;
use App\Models\PubAprovacaoComposicao;
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
use App\Models\PubValidacao;
use App\Models\User;
use App\Services\Publicador\Aprovacao\AjustesDaOfertaService;
use App\Services\Publicador\Aprovacao\AndamentoDaOfertaService;
use App\Services\Publicador\Aprovacao\AprovacaoDaOfertaService;
use App\Services\Publicador\Aprovacao\BloqueiosDaOfertaService;
use App\Services\Publicador\ConferenciaService;
use App\Services\Publicador\DadosEfetivosService;
use App\Services\Portal\Estrutura\EstruturaPrecificacaoService;
use App\Services\Publicador\ClienteMlPublicador;
use App\Support\Publicador\Variacao\ChaveCanonica;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 * Fase 188, plano 05 — SALVAR REVISÃO (COL-01/COL-02/AND-06), APROVAR PARA PUBLICAÇÃO (BLOQ-04/
 * COL-04) e TENTAR DE NOVO (REVS-05/BLOQ-11), **pelas rotas**.
 *
 * ⚠️⚠️ DE CADA GATE DESTE ARQUIVO, A PERGUNTA RESPONDIDA ANTES DE ELE EXISTIR:
 * **"o que precisaria estar errado no mundo para ele falhar?"**
 *
 *   · **A trava de aprovação ser COSMÉTICA.** O `disabled=` do botão é do plano 188-10; se a
 *     recusa não estivesse no SERVIDOR, um POST com bloqueio de pé publicaria anúncio que ninguém
 *     conferiu — e **aprovar já publica, sem desfazer**. ➡️ Os casos chamam as rotas **DIRETO**,
 *     sem passar pela tela. É o único jeito de provar que a trava não é enfeite (T-188-41).
 *   · **A revisão parcial morar no NAVEGADOR.** COL-02 é explícito: ela fica no servidor, e outra
 *     pessoa continua de onde a primeira parou. Em `localStorage` a revisão existiria só para quem
 *     a começou — e o painel é, por desenho, trabalho que troca de mãos.
 *   · **O `x de y` do registro divergir do bloqueio de fotos.** Duas contagens da mesma verdade
 *     apareceriam como a tela dizendo *"5 de 5 fotos aprovadas"* **com um bloqueio de fotos de
 *     pé** — o pior tipo de bug de dado, porque **parece completo**.
 *   · **Sobrescrever revisão concorrente** (T-188-42): `versao_esperada` divergente tem de
 *     **RECUSAR**, nunca gravar em silêncio.
 *   · **"Tentar de novo" numa oferta que não falhou.** Medido: `TRANSICOES` tem
 *     `aprovado => [publicando, …]`, então `mover(…, publicando)` a partir de `aprovado` é
 *     **legal** e o serviço não a recusaria. Sem a guarda da action, o botão empurraria para
 *     `publicando` uma oferta recém-aprovada — e a Fase 193 publicaria por cima.
 *   · **Uma ESCRITA no Mercado Livre saindo daqui** — a fronteira do critério 18.
 *
 * ═══ ⚠️⚠️ O QUE SAI PELA REDE, MEDIDO — E POR QUE A RÉGUA É "ZERO ESCRITA", NÃO "ZERO" ════════
 *
 * `aprovar()` chama `BloqueiosDaOfertaService::para()`, que chama
 * `ComposicaoFinanceiraService::para()`, que lê a **tarifa REAL do Mercado Livre** no GET público
 * `/sites/MLB/listing_prices` (decisão 9 do usuário; cache de 1 h pré-aquecido por job). ➡️ Afirmar
 * "zero requisição" nesta rota seria um gate que **o próprio desenho contradiz**, e que o executor
 * seguinte "consertaria" pré-aquecendo o cache — transformando-o em gate sobre zero casos.
 * ➡️ A régua honesta, e a que protege o invariante que importa: **nenhuma requisição que não seja
 * GET, e nenhuma URL de `/items`** (o endpoint de publicação). A leitura de tarifa é nomeada numa
 * **lista CONSTANTE**, não tolerada por um filtro largo.
 * ⚠️ As três rotas de IMAGEM **não** leem composição, e lá o zero é literal — provado em
 * {@see EscritaDeImagemDoPainelTest::test_as_tres_rotas_de_imagem_nao_fazem_nenhuma_requisicao_ao_ml_com_isca_188_05}.
 *
 * ⚠️⚠️ **NENHUM EXECUTOR DESTA FASE VIU ESTA TELA RENDERIZADA.** O banco local não tem empresa com
 * `ml_token` e `pub_aprovacao_ofertas` está vazia. ⚠️ A conferência visual da Fase 187 pelo usuário
 * continua **ABERTA**.
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 */
class SalvarEAprovarDoPainelTest extends TestCase
{
    use RefreshDatabase;

    private const CLASSICO = 'gold_special';

    private const PREMIUM = 'gold_pro';

    private const CATEGORIA = 'MLB189007';

    /** A única leitura de rede que esta fase autoriza — a tarifa real (decisão 9). */
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
            'nome' => 'Empresa Rodapé '.$company->id,
            'tipo' => 'ASSESSORIA',
            'company_id' => $company->id,
            'responsavel_id' => $admin->id,
        ]);

        return [$company, $empresa, $admin];
    }

    /**
     * O cenário: **duas ofertas** no mesmo rascunho, **aprovável por padrão** (zero bloqueios).
     *
     * ⚠️ O dublê de `DadosEfetivosService` é **por produto**, e isso é conserto de um falso
     * negativo medido em 15/10/2026 na Fase 186: com um conjunto único de custos, o ÚLTIMO
     * `cenario()` montado sobrescrevia os anteriores, e a oferta "com bloqueio" era avaliada com a
     * conta da oferta limpa — **virava apta**, e o gate ficava vermelho estando certo.
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
            'sku' => 'RDP-'.$this->sequencia,
            'nome' => 'Poltrona do rodapé',
            'origem' => PubProduto::ORIGEM_PUBLICADOR,
            'quantidade_kit' => 1,
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
            ]);
        }

        // ⚠️ A galeria: 3 fotos, por padrão TODAS aprovadas → `fotos_a_conferir` zerado. O `x de y`
        // do registro de AND-06 é 3 de 3 — e é esse número que o caso (3) confere contra
        // `contagemDeFotos()`, nunca contra um literal.
        $conjunto = PubAprovacaoConjunto::create([
            'rascunho_id' => $rascunho->id,
            'grupo_chave' => PubAprovacaoConjunto::GERAL,
            'grupo_hash' => ChaveCanonica::hash(PubAprovacaoConjunto::GERAL.'-'.$rascunho->id),
        ]);
        $estado = $o['fotos'] ?? PubAprovacaoImagem::APROVADA;
        $primeira = null;
        for ($i = 0; $i < 3; $i++) {
            $img = $this->imagem($conjunto, $estado, $i);
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
        // custo", que é um cenário, não um default ausente.
        $this->efetivos->custosPorProduto[(int) $produto->id] = [
            'custo' => array_key_exists('custo', $o) ? $o['custo'] : 20.0,
            'origem' => 'digitado',
            'imposto' => $o['imposto'] ?? 10.0,
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

    private function urlSalvar(Company $c, PubAprovacaoOferta $o): string
    {
        return route('mlb.anuncios.meus.aprovacao.painel.salvar-revisao', ['company' => $c->id, 'oferta' => $o->id]);
    }

    private function urlAprovar(Company $c, PubAprovacaoOferta $o): string
    {
        return route('mlb.anuncios.meus.aprovacao.painel.aprovar', ['company' => $c->id, 'oferta' => $o->id]);
    }

    private function urlRetry(Company $c, PubAprovacaoOferta $o): string
    {
        return route('mlb.anuncios.meus.aprovacao.painel.tentar-de-novo', ['company' => $c->id, 'oferta' => $o->id]);
    }

    /** O andamento é movido pela ÚNICA PORTA — este teste nunca escreve a coluna. */
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

    /**
     * ═══ ⚠️⚠️ CONSERTO DA QUICK `261021-fix`: TORNAR **ESTA** FIXTURE PUBLICÁVEL ═══════════════
     *
     * ⚠️⚠️ **POR QUE ISTO EXISTE, E POR QUE ELE NÃO ESTÁ NO `cenario()`.**
     *
     * O `cenario()` deste arquivo é **compartilhado por 9 métodos** de um teste de fase **EM
     * PRODUÇÃO**, e ele nunca foi publicável: nasce com as **DUAS** modalidades **ATIVAS**, com o
     * **mesmo título** nos dois alvos, **sem** conferência L3 e **sem** a conta em
     * `publicador.contas_liberadas` (que é **fail-closed**: lista vazia = NINGUÉM). Isso era
     * invisível enquanto o *"Tentar de novo"* era só uma transição de estado — a rota
     * **redirecionava**. ⚠️ Depois de a Fase 193 (plano 04) fazer o retry **enfileirar de verdade**,
     * a mesma fixture passou a **recusar (422)** onde os dois casos esperavam **302**, e os dois
     * ficaram **VERMELHOS**.
     *
     * ⚠️⚠️ **RECUSAR É O COMPORTAMENTO CERTO**, e por isso o conserto é da fixture e não do código:
     * mover sem enfileirar deixaria a oferta `publicando` com a ligação no item **terminado**, e o
     * reconciliador do portão a fecharia como `falha` no minuto seguinte — `publicando → falha` a
     * cada minuto, com a edição bloqueada. ➡️ **O que ficou obsoleto foi o `assertRedirect()`, não
     * as asserções de invariante** (*"zero escrita no Mercado Livre"*, *"sem MLB"*), que seguem
     * valendo inteiras e **não foram tocadas**.
     *
     * ⚠️ **Mexer no `cenario()` moveria os outros 7 métodos de uma só vez.** ➡️ Este auxiliar é
     * chamado **só** pelos **dois** métodos de *"Tentar de novo"*.
     *
     * **Os quatro ingredientes, cada um com a razão MEDIDA:**
     *
     *  1. `publicador.contas_liberadas.companies` — `ContasLiberadas` é **fail-closed**; sem isto a
     *     prontidão da fila recusa com *"A publicação ainda não foi liberada para esta conta"*;
     *  2. a **irmã sai do caminho** desativando o **alvo** (`pub_rascunho_alvos.ativo = false`) —
     *     que é **a própria âncora** da guarda da decisão 23 (`alvosAtivos()` é a lista que o
     *     payload percorre, logo alvo inativo **não vai ao ar** e não há o que revisar). ⚠️ A
     *     alternativa (aprovar a irmã) dispararia o gancho `aoAprovar()` dela e meteria uma segunda
     *     aprovação na fixture — mais efeito colateral para o mesmo fim;
     *  3. **títulos distintos** — título igual nos dois tipos é o bloqueio **V-TIT-04** da
     *     prontidão, e a fila recusaria pelo motivo **errado**;
     *  4. a conferência **L3** `OK` — sem ela a prontidão recusa com *"Confira no Mercado Livre
     *     antes de agendar."* ⚠️ `revisao` lida do rascunho **RELIDO**: o Eloquent **não** lê o
     *     default do banco, e `revisao` nula em memória criaria a validação com `revisao = 0`,
     *     fazendo a prontidão recusar com *"O produto mudou depois da conferência"*.
     *
     * @param  array<string, mixed>  $c  o retorno do `cenario()`
     */
    private function tornarPublicavel_261021_fix(Company $company, array $c): void
    {
        config(['publicador.contas_liberadas.companies' => [(int) $company->id]]);

        PubRascunhoAlvo::query()
            ->where('id', (int) $c['premium']->alvo_id)
            ->update(['ativo' => false]);

        PubRascunhoAlvo::query()
            ->where('id', (int) $c['classico']->alvo_id)
            ->update(['titulo' => 'Poltrona Beny clássica veludo cinza '.$this->sequencia]);

        $rascunho = $c['rascunho']->fresh();

        PubValidacao::create([
            'rascunho_id' => $rascunho->id,
            'revisao' => (int) $rascunho->revisao,
            'camada' => 'L3',
            'resultado' => ConferenciaService::OK,
            'plano_hash' => str_repeat('a', 64),
            'issues' => [],
        ]);
    }

    private function eventos(PubAprovacaoOferta $oferta): array
    {
        return PubAprovacaoEvento::query()
            ->where('oferta_id', $oferta->id)
            ->orderBy('ocorrido_em')
            ->orderBy('id')
            ->pluck('texto')
            ->all();
    }

    private function totalDeEventos(): int
    {
        return PubAprovacaoEvento::query()->count();
    }

    /**
     * As requisições que NÃO são leitura autorizada: tudo que não for GET, e todo GET para fora da
     * lista CONSTANTE. ⚠️ Devolve `METODO URL` para a mensagem de falha não exigir adivinhação.
     *
     * @return list<string>
     */
    private function escritasEDesvios(): array
    {
        $fora = [];
        foreach (Http::recorded() as [$req]) {
            $metodo = $req->method();
            $caminho = (string) parse_url($req->url(), PHP_URL_PATH);
            if ($metodo !== 'GET' || ! in_array($caminho, self::LEITURAS_AUTORIZADAS, true)) {
                $fora[] = $metodo.' '.$req->url();
            }
        }

        return $fora;
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // (1) SALVAR REVISÃO — `awaiting → ecf_review` pela ÚNICA PORTA (AND-06 / COL-01 / COL-02)
    // ═════════════════════════════════════════════════════════════════════════════════════════

    public function test_salvar_revisao_move_awaiting_para_ecf_review_pela_unica_porta_188_05(): void
    {
        [$company, $empresa, $admin] = $this->conta();
        $c = $this->cenario(['company' => $company, 'empresa' => $empresa]);
        $oferta = $c['classico'];

        // ⚠️ ISCA DO PONTO DE PARTIDA: ela tem de estar em `aguardando_aprovacao`, senão a
        // asserção da transição seria verdadeira antes da rota.
        $this->assertSame(PubAprovacaoOferta::AGUARDANDO_APROVACAO, $oferta->andamento);

        $resposta = $this->actingAs($admin)->post($this->urlSalvar($company, $oferta))->assertRedirect();

        $depois = $oferta->fresh();

        // (a) A TRANSIÇÃO — e `em_revisao_ecf` só é alcançável pela única porta, porque
        //     `TRANSICOES` a valida. Nada aqui escreve a coluna.
        $this->assertSame(PubAprovacaoOferta::EM_REVISAO_ECF, $depois->andamento, 'AND-06: a oferta não saiu de `aguardando_aprovacao`');
        $this->assertTrue((bool) $depois->tocado);

        // (b) ⚠️⚠️ O REGISTRO LITERAL — 3 fotos, todas aprovadas na fixture.
        $this->assertContains('Salvou revisão · 3 de 3 fotos aprovadas', $this->eventos($depois));

        // (c) O evento é do tipo `save` (REVISAO_PARCIAL), com o autor REAL (COL-05 / T-188-43).
        $evento = PubAprovacaoEvento::query()
            ->where('oferta_id', $depois->id)
            ->where('tipo', PubAprovacaoEvento::REVISAO_PARCIAL)
            ->firstOrFail();
        $this->assertSame($admin->id, (int) $evento->autor_id);
        $this->assertSame('2026-10-19 11:30:00', $evento->ocorrido_em->format('Y-m-d H:i:s'));
        $this->assertSame(1, (int) $evento->versao);

        // (d) ⚠️⚠️ **COL-02 — A REVISÃO PARCIAL ESTÁ NO SERVIDOR, NÃO EM `localStorage`.** A prova
        //     é: outra pessoa, em outra sessão, lê o MESMO estado do banco.
        $outraPessoa = User::factory()->create(['role' => 'admin', 'name' => 'Ana Continuação']);
        $lidoPorOutra = PubAprovacaoOferta::query()->findOrFail($depois->id);
        $this->assertSame(PubAprovacaoOferta::EM_REVISAO_ECF, $lidoPorOutra->andamento);
        $this->assertContains('Salvou revisão · 3 de 3 fotos aprovadas', $this->eventos($lidoPorOutra));

        // ...e ela continua de onde a primeira parou — o segundo salvar registra o fato novo com o
        // AUTOR dela, não com o da primeira.
        $this->actingAs($outraPessoa)->post($this->urlSalvar($company, $lidoPorOutra))->assertRedirect();
        $autores = PubAprovacaoEvento::query()
            ->where('oferta_id', $depois->id)
            ->where('tipo', PubAprovacaoEvento::REVISAO_PARCIAL)
            ->orderBy('id')
            ->pluck('autor_id')
            ->map(static fn ($v) => (int) $v)
            ->all();
        $this->assertSame([$admin->id, $outraPessoa->id], $autores, 'COL-02: a revisão não trocou de mãos — o segundo autor não ficou registrado');

        // (e) A resposta leva a versão, que é o que COL-04 precisa viajar.
        $this->assertSame(1, (int) $resposta->getSession()->get('painel_revisao_salva')['versao']);

        // (f) Oferta de outra conta: 404, e nada movido.
        [$outroCompany, $outroEmpresa] = $this->conta();
        $alheio = $this->cenario(['company' => $outroCompany, 'empresa' => $outroEmpresa]);
        $this->actingAs($admin)->post($this->urlSalvar($company, $alheio['classico']))->assertNotFound();
        $this->assertSame(PubAprovacaoOferta::AGUARDANDO_APROVACAO, $alheio['classico']->fresh()->andamento);
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // (2) SALVAR DE NOVO não move; e FORA DO `live` é recusado SEM GRAVAR
    // ═════════════════════════════════════════════════════════════════════════════════════════

    public function test_salvar_de_novo_em_ecf_review_registra_sem_mover_e_fora_do_live_recusa_188_05(): void
    {
        [$company, $empresa, $admin] = $this->conta();

        // ─── (a) Já em `em_revisao_ecf`: NÃO muda o andamento, e AINDA ASSIM registra ────────
        $c = $this->cenario(['company' => $company, 'empresa' => $empresa]);
        $jaEmRevisao = $this->levarAo($c['classico'], PubAprovacaoOferta::EM_REVISAO_ECF, $admin);
        $eventosAntes = count($this->eventos($jaEmRevisao));

        $this->actingAs($admin)->post($this->urlSalvar($company, $jaEmRevisao))->assertRedirect();

        $this->assertSame(PubAprovacaoOferta::EM_REVISAO_ECF, $jaEmRevisao->fresh()->andamento, 'o protótipo só promove a partir de `awaiting`');
        $this->assertGreaterThan($eventosAntes, count($this->eventos($jaEmRevisao->fresh())), 'salvar de novo é fato novo e tem de ser registrado');

        // ─── (b) ⚠️ `ajustes_solicitados` TAMBÉM é `live`: salvar é permitido e não move ─────
        $cAjustes = $this->cenario(['company' => $company, 'empresa' => $empresa]);
        $emAjustes = $this->levarAo($cAjustes['classico'], PubAprovacaoOferta::AJUSTES_SOLICITADOS, $admin);
        $this->actingAs($admin)->post($this->urlSalvar($company, $emAjustes))->assertRedirect();
        $this->assertSame(PubAprovacaoOferta::AJUSTES_SOLICITADOS, $emAjustes->fresh()->andamento);

        // ─── (c) ⚠️⚠️ FORA DO `live`: 422 com `REGRA_FORA_DO_AO_VIVO`, e NADA gravado ────────
        //
        // ⚠️ Esta guarda é da ACTION, e isso está medido: `AprovacaoDaOfertaService::
        // salvarRevisao()` NÃO confere `live` (ele só promove a partir de `aguardando_aprovacao` e
        // registra sempre). Em `publicado` "salvar revisão" registraria um fato que não aconteceu,
        // numa oferta que ninguém pode mais revisar.
        foreach ([PubAprovacaoOferta::APROVADO, PubAprovacaoOferta::PUBLICANDO, PubAprovacaoOferta::PUBLICADO, PubAprovacaoOferta::FALHA] as $fora) {
            $cada = $this->cenario(['company' => $company, 'empresa' => $empresa]);
            $oferta = $this->levarAo($cada['classico'], $fora, $admin);

            $antesDoPost = $this->totalDeEventos();
            $tocadoAntes = (bool) $oferta->fresh()->tocado;

            $this->actingAs($admin)
                ->postJson($this->urlSalvar($company, $oferta))
                ->assertStatus(422)
                ->assertJsonPath('regra', AprovacaoDaOfertaService::REGRA_FORA_DO_AO_VIVO);

            $this->assertSame($fora, $oferta->fresh()->andamento, "a recusa em `{$fora}` mexeu no andamento");
            $this->assertSame($antesDoPost, $this->totalDeEventos(), "a recusa em `{$fora}` gravou evento");
            $this->assertSame($tocadoAntes, (bool) $oferta->fresh()->tocado, "a recusa em `{$fora}` marcou `tocado`");
        }

        // ⚠️ ISCA DO RECORTE: os três `live` NÃO podem ser recusados pela mesma guarda — sem isto,
        // uma guarda invertida (ou sempre-verdadeira) passaria o laço acima e barraria o trabalho
        // legítimo. É a família "gate mais largo que o invariante", do lado da action.
        $this->assertSame(
            [PubAprovacaoOferta::AGUARDANDO_APROVACAO, PubAprovacaoOferta::EM_REVISAO_ECF, PubAprovacaoOferta::AJUSTES_SOLICITADOS],
            PubAprovacaoOferta::LIVE
        );
        $cLive = $this->cenario(['company' => $company, 'empresa' => $empresa]);
        $this->actingAs($admin)->post($this->urlSalvar($company, $cLive['classico']))->assertRedirect();
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // (3) O `x de y` VEM DE `contagemDeFotos()` — a MESMA definição do bloqueio de fotos
    // ═════════════════════════════════════════════════════════════════════════════════════════

    /**
     * ⚠️⚠️ **O QUE PRECISARIA ESTAR ERRADO:** duas contagens da mesma verdade. A divergência
     * apareceria como a tela dizendo *"5 de 5 fotos aprovadas"* **com um bloqueio de fotos de pé**
     * — e esse é o pior tipo de bug de dado, porque **parece completo**.
     *
     * ⚠️ A asserção monta a frase a partir de `contagemDeFotos()`, **nunca** de um literal: um
     * `'2 de 5'` cravado passaria mesmo se as duas pontas tivessem divergido juntas.
     */
    public function test_o_x_de_y_do_registro_vem_de_contagem_de_fotos_188_05(): void
    {
        [$company, $empresa, $admin] = $this->conta();

        // ⚠️ FIXTURE QUE DISCRIMINA: 3 fotos `pending`, e depois DUAS aprovadas — assim `x` e `y`
        // são DIFERENTES entre si e diferentes do total de grupos. Com `x == y` a asserção não
        // distinguiria "aprovadas" de "total".
        $c = $this->cenario(['company' => $company, 'empresa' => $empresa, 'fotos' => PubAprovacaoImagem::PENDENTE]);
        $oferta = $c['classico'];

        $imagens = $c['conjunto']->imagens()->orderBy('posicao')->get();
        $imagens[0]->update(['estado' => PubAprovacaoImagem::APROVADA]);
        $imagens[1]->update(['estado' => PubAprovacaoImagem::APROVADA]);

        $contagem = app(BloqueiosDaOfertaService::class)->contagemDeFotos($oferta->fresh());
        $this->assertSame(3, $contagem['total']);
        $this->assertSame(2, $contagem['aprovadas']);
        $this->assertNotSame($contagem['total'], $contagem['aprovadas'], 'ISCA: x e y empataram — a asserção não distingue os dois');

        $this->actingAs($admin)->post($this->urlSalvar($company, $oferta))->assertRedirect();

        $esperado = sprintf('Salvou revisão · %d de %d fotos aprovadas', $contagem['aprovadas'], $contagem['total']);
        $this->assertContains($esperado, $this->eventos($oferta->fresh()));

        // ⚠️⚠️ E A OUTRA PONTA: com `x < y` o bloqueio de fotos TEM de estar de pé. É esta
        // asserção que pega as duas contagens divergindo — a frase e o bloqueio têm de concordar.
        $info = app(BloqueiosDaOfertaService::class)->para($oferta->fresh());
        $this->assertContains('fotos_a_conferir', $info['chaves'], 'a tela diria "2 de 3 aprovadas" e o bloqueio de fotos teria sumido');
        $this->assertFalse($info['aprovavel']);

        // ⚠️ ISCA DA OUTRA PONTA: com TODAS aprovadas, o bloqueio SOME e a frase fica `3 de 3`.
        // Sem isto, `assertContains` poderia estar sempre verdadeiro por outro motivo.
        $imagens[2]->update(['estado' => PubAprovacaoImagem::APROVADA]);
        $limpa = app(BloqueiosDaOfertaService::class)->para($oferta->fresh());
        $this->assertNotContains('fotos_a_conferir', $limpa['chaves'], 'ISCA: o bloqueio de fotos não sai nem com todas aprovadas');

        $this->actingAs($admin)->post($this->urlSalvar($company, $oferta->fresh()))->assertRedirect();
        $this->assertContains('Salvou revisão · 3 de 3 fotos aprovadas', $this->eventos($oferta->fresh()));
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // (4) ⚠️⚠️ APROVAR É RECUSADO NOS TRÊS EIXOS — CHAMANDO A ROTA DIRETO (T-188-41/42)
    // ═════════════════════════════════════════════════════════════════════════════════════════

    /**
     * ⚠️⚠️ **O TESTE CHAMA A ROTA DIRETO, SEM PASSAR PELA TELA.** É o único jeito de provar que a
     * trava **não é cosmética**: o `disabled=` do botão é do plano 188-10, e com aprovar-já-publica
     * e sem desfazer, confiar no cliente seria elevação de privilégio.
     */
    public function test_aprovar_recusa_nos_tres_eixos_chamando_a_rota_direto_188_05(): void
    {
        [$company, $empresa, $admin] = $this->conta();

        // ─── EIXO 1: QUALQUER BLOQUEIO DE PÉ → `REGRA_COM_BLOQUEIO` ─────────────────────────
        // ⚠️ Dois bloqueios de famílias diferentes, para a asserção não valer só para fotos: um da
        // galeria (`fotos_a_conferir`) e um da conta (`resultado_negativo`, com custo alto).
        $casos = [
            'fotos pendentes' => ['fotos' => PubAprovacaoImagem::PENDENTE],
            'resultado negativo' => ['custo' => 400.0],
            'frete ausente' => ['frete' => null],
        ];

        foreach ($casos as $rotulo => $extra) {
            $c = $this->cenario(array_merge(['company' => $company, 'empresa' => $empresa], $extra));
            $oferta = $c['classico'];

            // ⚠️ ISCA DE CADA CASO: o bloqueio de fato EXISTE no cenário. Sem isto, um cenário que
            // por acidente ficasse limpo faria o `assertStatus(422)` vermelho por outro motivo —
            // ou, pior, um cenário sempre-bloqueado faria o eixo 2 passar sem medir nada.
            $info = app(BloqueiosDaOfertaService::class)->para($oferta);
            $this->assertNotEmpty($info['chaves'], "ISCA: o cenário `{$rotulo}` não tem bloqueio nenhum");
            $this->assertFalse($info['aprovavel']);

            $antes = $this->totalDeEventos();

            $this->actingAs($admin)
                ->postJson($this->urlAprovar($company, $oferta))
                ->assertStatus(422)
                ->assertJsonPath('regra', AprovacaoDaOfertaService::REGRA_COM_BLOQUEIO);

            $depois = $oferta->fresh();
            $this->assertSame(PubAprovacaoOferta::AGUARDANDO_APROVACAO, $depois->andamento, "`{$rotulo}`: a oferta foi aprovada com bloqueio de pé");
            $this->assertNull($depois->aprovado_por, "`{$rotulo}`: gravou `aprovado_por`");
            $this->assertNull($depois->aprovado_em);
            $this->assertNull($depois->versao_aprovada);
            $this->assertSame($antes, $this->totalDeEventos(), "`{$rotulo}`: a recusa gravou evento");
            $this->assertSame(0, PubAprovacaoComposicao::query()->where('oferta_id', $depois->id)->count(), "`{$rotulo}`: congelou composição");
        }

        // ─── EIXO 2: FORA DOS TRÊS `live` → `REGRA_FORA_DO_AO_VIVO` ─────────────────────────
        foreach ([PubAprovacaoOferta::APROVADO, PubAprovacaoOferta::PUBLICANDO, PubAprovacaoOferta::PUBLICADO, PubAprovacaoOferta::FALHA] as $fora) {
            $c = $this->cenario(['company' => $company, 'empresa' => $empresa]);
            $oferta = $this->levarAo($c['classico'], $fora, $admin);
            $antes = $this->totalDeEventos();

            $this->actingAs($admin)
                ->postJson($this->urlAprovar($company, $oferta))
                ->assertStatus(422)
                ->assertJsonPath('regra', AprovacaoDaOfertaService::REGRA_FORA_DO_AO_VIVO);

            $this->assertSame($fora, $oferta->fresh()->andamento, "a recusa em `{$fora}` mexeu no andamento");
            $this->assertSame($antes, $this->totalDeEventos(), "a recusa em `{$fora}` gravou evento");
        }

        // ─── EIXO 3: ⚠️ `versao_esperada` DIVERGENTE → `REGRA_VERSAO_DIVERGENTE` (COL-04) ───
        $c = $this->cenario(['company' => $company, 'empresa' => $empresa]);
        $oferta = $c['classico'];
        $this->assertSame(1, (int) $oferta->versao);
        $antes = $this->totalDeEventos();

        $this->actingAs($admin)
            ->postJson($this->urlAprovar($company, $oferta), ['versao_esperada' => 7])
            ->assertStatus(422)
            ->assertJsonPath('regra', AprovacaoDaOfertaService::REGRA_VERSAO_DIVERGENTE);

        $this->assertSame(PubAprovacaoOferta::AGUARDANDO_APROVACAO, $oferta->fresh()->andamento, 'T-188-42: sobrescreveu revisão concorrente em silêncio');
        $this->assertSame($antes, $this->totalDeEventos());

        // ⚠️ ISCA DO EIXO 3: a versão CERTA passa, e ausente também (a aprovação pela linha da fila
        // não afirma versão). Sem isto, uma guarda que recusasse SEMPRE passaria o caso acima.
        $this->actingAs($admin)
            ->post($this->urlAprovar($company, $oferta->fresh()), ['versao_esperada' => 1])
            ->assertRedirect();
        $this->assertSame(PubAprovacaoOferta::APROVADO, $oferta->fresh()->andamento, 'ISCA: a versão CERTA também foi recusada — a guarda recusa sempre');

        $semVersao = $this->cenario(['company' => $company, 'empresa' => $empresa]);
        $this->actingAs($admin)->post($this->urlAprovar($company, $semVersao['classico']))->assertRedirect();
        $this->assertSame(PubAprovacaoOferta::APROVADO, $semVersao['classico']->fresh()->andamento);
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // (5) APROVAR com zero bloqueios: grava os três campos, registra e congela
    // ═════════════════════════════════════════════════════════════════════════════════════════

    public function test_aprovar_com_zero_bloqueios_grava_os_tres_campos_e_registra_188_05(): void
    {
        [$company, $empresa, $admin] = $this->conta();
        $c = $this->cenario(['company' => $company, 'empresa' => $empresa]);
        $oferta = $c['classico'];

        // ⚠️ ISCA DO PONTO DE PARTIDA: zero bloqueios E `live`. Sem ela, um cenário acidentalmente
        // bloqueado faria o caso vermelho por outro motivo.
        $info = app(BloqueiosDaOfertaService::class)->para($oferta);
        $this->assertSame([], $info['chaves'], 'ISCA: o cenário "aprovável" tem bloqueio de pé');
        $this->assertTrue($info['aprovavel']);
        $this->assertTrue($info['ao_vivo']);

        $resposta = $this->actingAs($admin)->post($this->urlAprovar($company, $oferta))->assertRedirect();
        $depois = $oferta->fresh();

        // (a) O andamento, pela ÚNICA PORTA.
        $this->assertSame(PubAprovacaoOferta::APROVADO, $depois->andamento);

        // (b) Os TRÊS campos de aprovação (BLOQ-05 / COL-05).
        $this->assertSame($admin->id, (int) $depois->aprovado_por);
        $this->assertSame('2026-10-19 11:30:00', $depois->aprovado_em->format('Y-m-d H:i:s'));
        $this->assertSame(1, (int) $depois->versao_aprovada);
        $this->assertTrue((bool) $depois->tocado);

        // (c) O registro literal.
        $this->assertContains('Aprovou para publicação (versão 1)', $this->eventos($depois));

        // (d) ⚠️ O CONGELAMENTO (DADO-13) — é ele que torna "auditoria depois" verdade.
        $this->assertSame(1, PubAprovacaoComposicao::query()->where('oferta_id', $depois->id)->count());

        // (e) A resposta leva a versão aprovada.
        $this->assertSame(1, (int) $resposta->getSession()->get('painel_oferta_aprovada')['versao_aprovada']);

        // (f) ⚠️⚠️ NENHUM `MLB…` FOI GRAVADO. AND-04 exige MLB confirmado pelo Mercado Livre, e
        //     aprovar ENFILEIRA — a publicação é da Fase 193 (T-188-46).
        $this->assertNull($depois->ml_item_id, 'T-188-46: publicação simulada — gravou MLB sem o ML ter confirmado nada');
        $this->assertNotSame(PubAprovacaoOferta::PUBLICADO, $depois->andamento);

        // (g) ⚠️ A EXCEÇÃO AUTORIZADA tem **evento próprio**, e é por isso que o registro da
        //     aprovação NÃO leva sufixo.
        //
        // ⚠️⚠️ **DIVERGÊNCIA DECLARADA, e ela NÃO é nova:** o `<behavior>` deste plano pede que
        // "com exceção vigente, o registro acrescenta `com exceção autorizada`". A frase do evento
        // é montada por `HistoricoDaOfertaService:169`, que é **somente leitura** para este plano
        // (Fase 186, EM PRODUÇÃO), e emendá-la exigiria alterar aquele serviço **ou** gravar um
        // SEGUNDO evento para o mesmo fato — que é exatamente o defeito que o docblock daquele
        // arquivo registra como consertado em 15/10/2026 (dois `adjust` por pedido de ajuste).
        // ➡️ A informação **não se perde, e é MAIOR**: o evento `exc` fica imediatamente antes do
        // `ok`, com **quem liberou**, quando e a justificativa (decisão 6 do usuário: *"a exceção
        // só REGISTRA quem liberou"*). O sufixo diria apenas "houve exceção".
        // ⚠️ A Fase 186 já declarou e resolveu esta MESMA divergência em
        // `AprovarEReabrirTest::test_aprovar_com_excecao_ativa_deixa_a_autorizacao_na_linha_do_tempo`.
        $comPrejuizo = $this->cenario(['company' => $company, 'empresa' => $empresa, 'custo' => 400.0]);
        $outra = $comPrejuizo['classico'];
        $this->assertNotEmpty(app(BloqueiosDaOfertaService::class)->para($outra)['chaves']);

        app(AjustesDaOfertaService::class)->aplicarExcecao(
            $outra, $admin, PubAprovacaoExcecao::RESULTADO_NEGATIVO, 'Cliente aceitou a perda nesta campanha.'
        );
        $this->assertSame([], app(BloqueiosDaOfertaService::class)->para($outra->fresh())['chaves']);

        $this->actingAs($admin)->post($this->urlAprovar($company, $outra->fresh()))->assertRedirect();

        $textos = $this->eventos($outra->fresh());
        $iExc = array_search('Autorizou exceção (Resultado negativo): Cliente aceitou a perda nesta campanha.', $textos, true);
        $iOk = array_search('Aprovou para publicação (versão 1)', $textos, true);
        $this->assertNotFalse($iExc, 'a autorização da exceção não está na linha do tempo');
        $this->assertNotFalse($iOk);
        $this->assertLessThan($iOk, $iExc, 'a exceção tem de aparecer ANTES da aprovação: é ela que explica por que a aprovação foi possível');

        // ⚠️ E quem liberou fica gravado — é o conteúdo da decisão 6.
        $excecao = PubAprovacaoExcecao::vigentesDa((int) $outra->id)->firstOrFail();
        $this->assertSame($admin->id, (int) $excecao->liberada_por);
        $this->assertStringContainsString('Rafael Gusmão', $excecao->fraseDeExibicao());
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // (6) ⚠️⚠️ APROVAR ENFILEIRA — ZERO ESCRITA NO MERCADO LIVRE, COM ISCA (critério 18)
    // ═════════════════════════════════════════════════════════════════════════════════════════

    /**
     * ⚠️⚠️ **A FRONTEIRA: aprovar ENFILEIRA; a escrita no Mercado Livre é da Fase 193.** O gancho
     * `aoAprovar` da Fase 186 está **VAZIO** e é daquela fase.
     *
     * ⚠️ **PROVADO POR AUSÊNCIA DE REQUISIÇÃO, não por leitura de código** — e com **ISCA**
     * provando que o espião não está cego. Sem a isca, "nenhuma escrita" é indistinguível de "o
     * `Http::fake()` não está registrando nada", e o gate ficaria verde para sempre.
     *
     * ⚠️ **E o "zero" aqui é ZERO ESCRITA**, não zero requisição: ver o bloco de rede no docblock
     * da classe. A leitura de tarifa está numa **lista CONSTANTE** nomeada, não num filtro largo.
     */
    public function test_aprovar_enfileira_e_nao_escreve_no_mercado_livre_com_isca_188_05(): void
    {
        [$company, $empresa, $admin] = $this->conta();
        $c = $this->cenario(['company' => $company, 'empresa' => $empresa]);
        $oferta = $c['classico'];

        // ⚠️⚠️ **A MEDIÇÃO VEM PRIMEIRO E AS ISCAS DEPOIS, E ISSO É MEDIDO, NÃO ESTILO.** A
        // primeira redação rodava as iscas antes e chamava `$this->setUp()` para "limpar o
        // espião" — e `setUp()` no meio do caso **rebota a aplicação e derruba a transação do
        // `RefreshDatabase`**, com `PDOException` nos quatro casos que o faziam. ⚠️ E não dá para
        // estreitar o fake no meio: `Http::fake([...])` **MERGEIA** com o curinga do `setUp()`.
        // ➡️ Invertendo a ordem, nada precisa ser resetado — e a isca prova a MESMA sonda.
        $this->actingAs($admin)->post($this->urlAprovar($company, $oferta))->assertRedirect();
        $this->assertSame(PubAprovacaoOferta::APROVADO, $oferta->fresh()->andamento, 'gate sobre zero casos: a aprovação nem aconteceu');

        // ⚠️⚠️ ZERO ESCRITA. A mensagem leva método e URL para não exigir adivinhação.
        $this->assertSame([], $this->escritasEDesvios(), 'critério 18: saiu escrita ao Mercado Livre — a publicação é da Fase 193');

        // ⚠️ E a prova de que a rede FOI tocada em LEITURA (senão o `assertSame([])` acima seria
        // verdadeiro por o fake nem ter sido consultado — a outra face do gate vazio).
        $lidas = Http::recorded()
            ->map(static fn ($par) => (string) parse_url($par[0]->url(), PHP_URL_PATH))
            ->values()
            ->all();
        $this->assertContains('/sites/MLB/listing_prices', $lidas, 'a tarifa real nem foi consultada — o cenário não exercitou a composição');
        $this->assertSame(['/sites/MLB/listing_prices'], array_values(array_unique($lidas)), 'apareceu endpoint do ML fora da lista autorizada');

        // ⚠️ E nenhum `MLB…` gravado (AND-04 / T-188-46).
        $this->assertNull($oferta->fresh()->ml_item_id);

        // ⚠️ ISCA 1 — a sonda NÃO está cega: um POST ao ML é PEGO por ela.
        Http::post('https://api.mercadolibre.com/items', ['title' => 'x']);
        $this->assertSame(
            ['POST https://api.mercadolibre.com/items'],
            $this->escritasEDesvios(),
            'ISCA 1: a sonda não pega um POST ao ML — ela não está medindo nada'
        );

        // ⚠️ ISCA 2 — e um GET para FORA da lista CONSTANTE também é pego: a autorização é por
        // caminho nomeado, não "qualquer GET passa".
        Http::get('https://api.mercadolibre.com/users/me');
        $this->assertContains('GET https://api.mercadolibre.com/users/me', $this->escritasEDesvios(), 'ISCA 2: GET fora da lista autorizada passou');
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // (7) ⚠️⚠️ NÃO EXISTE SEGUNDO PASSO NA APROVAÇÃO — gate de AUSÊNCIA, com ISCA
    // ═════════════════════════════════════════════════════════════════════════════════════════

    /**
     * ⚠️⚠️ **DECISÃO 1 DO USUÁRIO: APROVAR JÁ PUBLICA, SEM SEGUNDO CLIQUE**, com o rótulo
     * "Aprovar para publicação" inalterado. ➡️ Não existe rota, action nem rótulo de *confirmar
     * publicação*, *desfazer*, *segundo passo* ou *cancelar aprovação* — válido para as Fases 187 a
     * 194.
     *
     * ⚠️⚠️ **O GATE LÊ O CÓDIGO SEM COMENTÁRIO** (`semComentarios()`), e isso **não** é zelo: a
     * prosa do plano e os docblocks deste arquivo e do controller citam os quatro de propósito,
     * para explicar que não existem. Um gate que lesse os comentários **reprovaria pelo próprio
     * conserto** — foi o que abortou TRÊS mutações da wave 187-04.
     *
     * ⚠️ E o recorte é a **lista CONSTANTE dos arquivos desta fase**, nunca o projeto inteiro:
     * "desfazer" e "cancelar" são palavras comuns do português, e varrer `app/` é a família de gate
     * que já **barrou um deploy** nesta milestone.
     */
    public function test_nao_existe_rota_action_nem_rotulo_de_segundo_passo_188_05(): void
    {
        // ⚠️ LISTA CONSTANTE — os arquivos que ESTE plano escreve, mais as rotas do módulo.
        $arquivos = [
            'app/Http/Controllers/MlbPublicadorAprovacaoController.php',
            'routes/mlb_anuncios.php',
        ];

        // ⚠️ ANTI-FANTASMA: `test -e` em cada caminho ANTES de medir. Caminho inexistente faria o
        // laço rodar sobre nada e o gate ficaria verde sem ler uma linha — são NOVE fantasmas nesta
        // milestone, três vindos de briefing.
        foreach ($arquivos as $rel) {
            $this->assertFileExists(base_path($rel), "caminho fantasma no gate de ausência: {$rel}");
        }

        // ⚠️ As SONDAS. Cada uma é testada contra um caso que ela DEVE casar (abaixo), e em pt-BR
        // conta-se com a cedilha e os acentos: `aprovad` NÃO casa `aprovação`.
        $proibidas = [
            'confirmar publicação' => '/confirmar[\s_\-]*publica(?:ção|cao|tion)/iu',
            'desfazer aprovação' => '/desfazer[\s_\-]*(?:a[\s_\-]*)?aprova(?:ção|cao)/iu',
            'segundo passo' => '/segundo[\s_\-]*passo|second[\s_\-]*step/iu',
            'cancelar aprovação' => '/cancelar[\s_\-]*(?:a[\s_\-]*)?aprova(?:ção|cao)/iu',
        ];

        // ⚠️⚠️ ISCA DAS SONDAS, NAS DUAS DIREÇÕES — e ela vem ANTES da varredura, porque sonda
        // furada torna o `assertSame([], ...)` vazio por construção.
        $deveCasar = [
            'confirmar publicação' => "Route::post('/confirmar-publicacao', …) // confirmar publicação",
            'desfazer aprovação' => 'public function desfazerAprovacao() {}',
            'segundo passo' => "'rotulo' => 'Segundo passo da aprovação'",
            'cancelar aprovação' => "Route::post('/cancelar-aprovacao')->name('cancelar.aprovacao')",
        ];
        foreach ($proibidas as $rotulo => $regex) {
            $this->assertSame(1, preg_match($regex, $deveCasar[$rotulo]), "ISCA (+): a sonda `{$rotulo}` não casa a forma que ela existe para pegar");
        }
        // ⚠️ E o que ela NÃO pode pegar: o rótulo LEGÍTIMO, que fica inalterado por decisão.
        foreach ($proibidas as $rotulo => $regex) {
            $this->assertSame(0, preg_match($regex, 'Aprovar para publicação'), "ISCA (−): a sonda `{$rotulo}` reprovaria o rótulo legítimo");
        }

        // ─── A VARREDURA, no código SEM COMENTÁRIO ──────────────────────────────────────────
        $infratores = [];
        $linhasLidas = 0;
        foreach ($arquivos as $rel) {
            $limpo = $this->semComentarios((string) file_get_contents(base_path($rel)));
            $linhasLidas += substr_count($limpo, "\n");
            foreach ($proibidas as $rotulo => $regex) {
                if (preg_match($regex, $limpo) === 1) {
                    $infratores[] = $rel.' :: '.$rotulo;
                }
            }
        }

        // ⚠️ UMA asserção coletando os infratores — nunca uma por arquivo, que faria a contagem
        // crescer junto com a lista (a lição do 187-07).
        $this->assertSame([], $infratores, 'decisão 1 do usuário: aprovar já publica, sem segundo clique');

        // ⚠️⚠️ ISCA DO `semComentarios()`: o código limpo tem de ser MENOR que o cru (os dois
        // arquivos são fortemente comentados) **e** tem de sobrar código de verdade. Sem isto, um
        // `semComentarios()` que devolvesse string vazia faria o gate acima verde para sempre.
        $cru = (string) file_get_contents(base_path($arquivos[0]));
        $limpo = $this->semComentarios($cru);
        $this->assertLessThan(strlen($cru), strlen($limpo), 'ISCA: `semComentarios()` não tirou nada — o gate leria a prosa e reprovaria pelo próprio conserto');
        $this->assertStringContainsString('public function aprovar(', $limpo, 'ISCA: `semComentarios()` comeu o código — o gate ficou vazio');
        $this->assertGreaterThan(400, $linhasLidas, 'o gate está lendo poucas linhas');

        // ⚠️ E as palavras DE FATO estão nos comentários — se não estivessem, a leitura "sem
        // comentário" não seria o que protege este gate, e a regra 2 da §6 perderia o sujeito.
        $this->assertSame(1, preg_match($proibidas['confirmar publicação'], $cru), 'a prosa deixou de citar os rótulos proibidos — a isca do `semComentarios()` perde o sujeito');

        // ─── E NENHUMA ROTA com esses nomes, em TODO o router ───────────────────────────────
        $nomes = [];
        foreach (app('router')->getRoutes() as $rota) {
            $nomes[] = (string) $rota->getName().' '.$rota->uri();
        }
        $tudo = implode("\n", $nomes);
        $rotasInfratoras = [];
        foreach ($proibidas as $rotulo => $regex) {
            if (preg_match($regex, $tudo) === 1) {
                $rotasInfratoras[] = $rotulo;
            }
        }
        $this->assertSame([], $rotasInfratoras, 'nasceu rota de segundo passo da aprovação');
        $this->assertGreaterThan(100, count($nomes), 'gate sobre poucas rotas');
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // (8) "TENTAR DE NOVO" — só a partir de `falha`, e a versão aprovada é PRESERVADA
    // ═════════════════════════════════════════════════════════════════════════════════════════

    public function test_tentar_de_novo_so_a_partir_de_falha_e_preserva_a_versao_aprovada_188_05(): void
    {
        [$company, $empresa, $admin] = $this->conta();
        $c = $this->cenario(['company' => $company, 'empresa' => $empresa]);
        // ⚠️ Quick `261021-fix`: o retry da Fase 193 ENFILEIRA de verdade, então a fixture tem de
        // ser publicável — ver {@see self::tornarPublicavel_261021_fix()}. ⚠️ Chamado AQUI, por
        // método: o `cenario()` é compartilhado por 9 métodos desta classe, em produção.
        $this->tornarPublicavel_261021_fix($company, $c);
        $oferta = $this->levarAo($c['classico'], PubAprovacaoOferta::FALHA, $admin);

        $versaoAntes = (int) $oferta->versao;
        $versaoAprovadaAntes = (int) $oferta->versao_aprovada;
        $aprovadoPorAntes = (int) $oferta->aprovado_por;

        $resposta = $this->actingAs($admin)->post($this->urlRetry($company, $oferta))->assertRedirect();
        $depois = $oferta->fresh();

        // (a) `falha → publicando`, pela ÚNICA PORTA.
        $this->assertSame(PubAprovacaoOferta::PUBLICANDO, $depois->andamento);

        // (b) ⚠️ A `versao` NÃO muda e a aprovação é PRESERVADA — incrementar reabriria uma
        //     aprovação em que ninguém mexeu, e a revisão retentada é exatamente a aprovada.
        $this->assertSame($versaoAntes, (int) $depois->versao, 'BLOQ-11: a versão foi incrementada e a aprovação foi reaberta sem ninguém mexer');
        $this->assertSame($versaoAprovadaAntes, (int) $depois->versao_aprovada, 'BLOQ-11: `versao_aprovada` não foi preservada');
        $this->assertSame($aprovadoPorAntes, (int) $depois->aprovado_por);
        $this->assertNotNull($depois->aprovado_em);
        $this->assertSame($versaoAprovadaAntes, (int) $resposta->getSession()->get('painel_retry')['versao_aprovada']);

        // (c) O registro da transição.
        $this->assertContains('Publicação iniciada no Mercado Livre', $this->eventos($depois));

        // ─── ⚠️⚠️ A GUARDA: FORA DE `falha` É RECUSADO, SEM GRAVAR ──────────────────────────
        //
        // ⚠️ MEDIDO: `TRANSICOES` tem `aprovado => [publicando, aguardando_aprovacao]`, então
        // `mover(…, publicando)` a partir de `aprovado` é transição **LEGAL** — `tentarDeNovo()`
        // não a recusaria, e sem esta guarda o botão empurraria para `publicando` uma oferta
        // recém-aprovada, que a Fase 193 publicaria por cima.
        $this->assertContains(
            PubAprovacaoOferta::PUBLICANDO,
            PubAprovacaoOferta::TRANSICOES[PubAprovacaoOferta::APROVADO],
            'ISCA: `aprovado → publicando` deixou de ser transição legal — esta guarda perdeu a razão de existir e pode sair'
        );

        foreach ([PubAprovacaoOferta::AGUARDANDO_APROVACAO, PubAprovacaoOferta::EM_REVISAO_ECF, PubAprovacaoOferta::AJUSTES_SOLICITADOS, PubAprovacaoOferta::APROVADO] as $fora) {
            $cada = $this->cenario(['company' => $company, 'empresa' => $empresa]);
            $outra = $this->levarAo($cada['classico'], $fora, $admin);
            $antes = $this->totalDeEventos();

            $this->actingAs($admin)
                ->postJson($this->urlRetry($company, $outra))
                ->assertStatus(422)
                ->assertJsonPath('regra', AndamentoDaOfertaService::REGRA_TRANSICAO_ILEGAL);

            $this->assertSame($fora, $outra->fresh()->andamento, "`{$fora}`: a oferta foi empurrada para `publicando` sem ter falhado");
            $this->assertSame($antes, $this->totalDeEventos(), "`{$fora}`: a recusa gravou evento");
        }

        // (d) Oferta de outra conta: 404.
        [$outroCompany, $outroEmpresa] = $this->conta();
        $alheio = $this->cenario(['company' => $outroCompany, 'empresa' => $outroEmpresa]);
        $comFalha = $this->levarAo($alheio['classico'], PubAprovacaoOferta::FALHA, $admin);
        $this->actingAs($admin)->post($this->urlRetry($company, $comFalha))->assertNotFound();
        $this->assertSame(PubAprovacaoOferta::FALHA, $comFalha->fresh()->andamento);
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // (9) "TENTAR DE NOVO" NÃO PUBLICA — zero escrita ao ML, com isca
    // ═════════════════════════════════════════════════════════════════════════════════════════

    public function test_tentar_de_novo_nao_publica_no_mercado_livre_com_isca_188_05(): void
    {
        [$company, $empresa, $admin] = $this->conta();
        $c = $this->cenario(['company' => $company, 'empresa' => $empresa]);
        // ⚠️ Quick `261021-fix`: idem ao caso (8) — a fixture tem de ser publicável para o retry
        // chegar ao `302`, e isso é POR MÉTODO, nunca no `cenario()` compartilhado.
        $this->tornarPublicavel_261021_fix($company, $c);
        $comFalha = $this->levarAo($c['classico'], PubAprovacaoOferta::FALHA, $admin);

        // ⚠️ A MEDIÇÃO VEM PRIMEIRO, A ISCA DEPOIS — ver a razão medida no caso (6).
        $this->actingAs($admin)->post($this->urlRetry($company, $comFalha))->assertRedirect();

        $this->assertSame(PubAprovacaoOferta::PUBLICANDO, $comFalha->fresh()->andamento, 'gate sobre zero casos: o retry nem aconteceu');
        $this->assertSame([], $this->escritasEDesvios(), 'critério 18: "tentar de novo" escreveu no Mercado Livre — a publicação é da Fase 193');
        $this->assertNull($comFalha->fresh()->ml_item_id, 'AND-04: gravou MLB sem confirmação do Mercado Livre');

        // ⚠️ ISCA — a sonda não está cega: um PUT ao ML é pego por ela.
        Http::put('https://api.mercadolibre.com/items/MLB1', ['price' => 1]);
        $this->assertSame(['PUT https://api.mercadolibre.com/items/MLB1'], $this->escritasEDesvios(), 'ISCA: a sonda não pega um PUT ao ML');
    }

    // ─────────────────────────────────────────────────────────────────────────────────────────

    /**
     * Tira comentários de linha e de bloco do PHP, **sem** tocar o conteúdo das strings.
     *
     * ⚠️ `token_get_all()`, nunca regex: um regex de comentário come `//` dentro de `'https://…'` e
     * apagaria código de verdade — e aí o gate de ausência ficaria verde por ter lido menos.
     */
    private function semComentarios(string $codigo): string
    {
        $saida = '';
        foreach (token_get_all($codigo) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                $saida .= "\n";

                continue;
            }
            $saida .= is_array($token) ? $token[1] : $token;
        }

        return $saida;
    }
}
