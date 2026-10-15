<?php

namespace Tests\Feature\Publicador\Aprovacao;

use App\Models\Company;
use App\Models\EstruturaPrecificacaoParametros;
use App\Models\PubAprovacaoAjuste;
use App\Models\PubAprovacaoComposicao;
use App\Models\PubAprovacaoConjunto;
use App\Models\PubAprovacaoEvento;
use App\Models\PubAprovacaoExcecao;
use App\Models\PubAprovacaoFrete;
use App\Models\PubAprovacaoImagem;
use App\Models\PubAprovacaoImagemAlerta;
use App\Models\PubAprovacaoOferta;
use App\Models\PubImagem;
use App\Models\PubProduto;
use App\Models\PubRascunho;
use App\Models\PubRascunhoAlvo;
use App\Models\User;
use App\Services\MercadoLivreService;
use App\Services\MlColetaService;
use App\Services\Portal\Estrutura\EstruturaPrecificacaoService;
use App\Services\Publicador\Aprovacao\AjustesDaOfertaService;
use App\Services\Publicador\Aprovacao\AndamentoDaOfertaService;
use App\Services\Publicador\Aprovacao\AprovacaoDaOfertaService;
use App\Services\Publicador\Aprovacao\BloqueiosDaOfertaService;
use App\Services\Publicador\Aprovacao\ComposicaoFinanceiraService;
use App\Services\Publicador\Aprovacao\HistoricoDaOfertaService;
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
 * Fase 186, plano 08 — APROVAR · REABRIR · CONFERIR A ALTERAÇÃO · LOTE.
 *
 * ⚠️⚠️ **APROVAR JÁ PUBLICA, E NÃO HÁ DESFAZER** (decisão do usuário, 14/10/2026). Toda a
 * reversibilidade mora ANTES do clique. ⚠️ E a transição `aprovado → aguardando_aprovacao` — **o
 * 13º par**, que torna a reabertura possível — entrou por **decisão do usuário de 15/10/2026**,
 * depois de este plano **parar e devolver a pergunta** em vez de acrescentá-la por conta própria.
 *
 * ═══ ⚠️⚠️ DE CADA GATE, A PERGUNTA RESPONDIDA ANTES DE ELE EXISTIR ═════════════════════════════
 *
 *   · **aprovar com bloqueio** — alguém confiar no `disabled=` do botão. O botão é conveniência de
 *     tela; a trava é o servidor. A recusa é provada **em cada um dos 8 bloqueios**, e com ISCA
 *     (o cenário limpo aprova) — senão eu estaria provando que nada nunca aprova.
 *   · **a transação única** — alguém gravar `aprovado` e falhar antes do congelamento. A oferta
 *     ficaria **aprovada sem prova do que foi aprovado**, e a publicação sairia sem rastro da
 *     versão conferida (T-186-34). ⚠️ O teste **força a falha no meio de propósito**, com um
 *     dublê de `ComposicaoFinanceiraService` que lança em `congelar()`.
 *   · **a reabertura em lote** — alguém reabrir só a oferta por onde a ação entrou. A irmã
 *     ficaria `aprovado` com a foto trocada — e a foto é **compartilhada por estrutura**.
 *   · **o incremento duplo da versão** — `PubAprovacaoFrete::informar()` **já** incrementa. Somar
 *     outro aqui faria a confirmação de frete pular duas versões.
 *   · **"conferir alteração" alterando o preço** — o nome engana. O teste afirma o preço **igual**
 *     depois da conferência, por asserção explícita.
 *   · **COL-04** — aprovar com a tela velha. Sem a recusa, aprova-se o que não está mais lá.
 *   · **dois ganchos `aoAprovar`** — a Fase 193 preencheria os dois e publicaria **duas vezes**.
 *     O gate conta: **exatamente UM** em todo o diretório.
 *   · **rede em teste** — `Http::preventStrayRequests()` faz chamada não fingida **explodir**.
 *
 * ⚠️ REND-08: tudo que grava data roda sob `Carbon::setTestNow()`.
 * ⚠️ Os gates deste arquivo rodam **só** por `sh tests/gates/verificar-testes.sh 08 <id>`.
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 */
class AprovarEReabrirTest extends TestCase
{
    use RefreshDatabase;

    private const CLASSICO = 'gold_special';

    private const PREMIUM = 'gold_pro';

    private const CATEGORIA = 'MLB189007';

    private ?DadosEfetivosService $efetivos = null;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-15 14:00:00');

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
            app(MercadoLivreService::class), app(MlColetaService::class), function (int $s) {}
        ));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // O CENÁRIO — rascunho com os DOIS alvos, galeria aprovada e conta financeira folgada
    // ═════════════════════════════════════════════════════════════════════════════════════════

    /**
     * @param  array{preco?: float, custo?: ?float, imposto?: float, frete?: ?float, titulo?: string, fotos?: string}  $o
     * @return array{rascunho: PubRascunho, conjunto: PubAprovacaoConjunto, classico: PubAprovacaoOferta, premium: PubAprovacaoOferta, empresa: Company}
     */
    private function cenario(array $o = []): array
    {
        $empresa = Company::factory()->create();

        $produto = PubProduto::create([
            'company_id' => $empresa->id,
            'sku' => 'APR-'.(PubProduto::count() + 1),
            'nome' => 'Poltrona da aprovação',
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
                'company_id' => $empresa->id,
            ]);
        }

        // A galeria: por padrão TODAS aprovadas (foto não é o assunto da maioria destes testes).
        $conjunto = PubAprovacaoConjunto::create([
            'rascunho_id' => $rascunho->id,
            'grupo_chave' => PubAprovacaoConjunto::GERAL,
            'grupo_hash' => ChaveCanonica::hash(PubAprovacaoConjunto::GERAL),
        ]);
        $estadoDasFotos = $o['fotos'] ?? PubAprovacaoImagem::APROVADA;
        for ($i = 0; $i < 2; $i++) {
            $this->imagem($conjunto, $estadoDasFotos, $i);
        }

        // ⚠️⚠️ O DUBLÊ É **POR PRODUTO**, E ISSO É CONSERTO DE UM FALSO NEGATIVO MEDIDO em
        // 15/10/2026. A primeira redação guardava um conjunto único de custos no dublê, e o
        // **último** `cenario()` montado sobrescrevia os anteriores. No teste do **lote** (que
        // monta três cenários e só então avalia os três) a oferta "com bloqueio" passou a ser
        // avaliada com a conta da oferta limpa — e **virou apta**. O gate ficou vermelho e estava
        // CERTO: era o teste que media o cenário errado.
        // ➡️ Agora cada produto tem a sua conta, e o dublê é o MESMO objeto para toda a classe.
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

        // ⚠️ `array_key_exists`, NUNCA `??`: `['custo' => null]` é "a Precificação está sem custo".
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
            'conjunto' => $conjunto,
            'classico' => $ofertas[self::CLASSICO]->fresh(),
            'premium' => $ofertas[self::PREMIUM]->fresh(),
            'empresa' => $empresa,
        ];
    }

    private function imagem(PubAprovacaoConjunto $c, string $estado, ?int $posicao, bool $comAlerta = false): PubAprovacaoImagem
    {
        $crua = PubImagem::create(['rascunho_id' => $c->rascunho_id, 'caminho' => 'fotos/foto-'.PubImagem::count().'.jpg']);
        $img = PubAprovacaoImagem::create([
            'conjunto_id' => $c->id,
            'imagem_id' => $crua->id,
            'tipo' => PubAprovacaoImagem::TIPO_ORIGINAL,
            'estado' => $estado,
            'posicao' => $posicao,
        ]);

        PubAprovacaoImagemAlerta::create([
            'aprovacao_imagem_id' => $img->id,
            'severidade' => $comAlerta ? PubAprovacaoImagemAlerta::ALTO : PubAprovacaoImagemAlerta::OK,
            'texto' => $comAlerta ? 'Pés diferentes da original' : 'Formato e cor compatíveis',
        ]);

        return $img;
    }

    private function bloqueios(): BloqueiosDaOfertaService
    {
        return new BloqueiosDaOfertaService($this->composicaoReal(), app(AjustesDaOfertaService::class));
    }

    private function composicaoReal(): ComposicaoFinanceiraService
    {
        return new ComposicaoFinanceiraService(
            $this->efetivos ?? app(DadosEfetivosService::class),
            app(ClienteMlPublicador::class),
        );
    }

    /**
     * ⚠️ O cenário monta o dublê de `DadosEfetivosService` e **só então** o serviço pode nascer —
     * o PHP avalia o receptor ANTES do argumento, e construir antes dava **falso negativo
     * silencioso** no plano 186-07.
     */
    private function servico(?ComposicaoFinanceiraService $composicao = null): AprovacaoDaOfertaService
    {
        $composicao ??= $this->composicaoReal();

        return new AprovacaoDaOfertaService(
            app(AndamentoDaOfertaService::class),
            app(HistoricoDaOfertaService::class),
            new BloqueiosDaOfertaService($composicao, app(AjustesDaOfertaService::class)),
            $composicao,
        );
    }

    /** O andamento é movido pela ÚNICA PORTA — este teste nunca escreve `andamento` direto. */
    private function levarAo(PubAprovacaoOferta $oferta, string $destino, ?User $quem = null): PubAprovacaoOferta
    {
        $porta = app(AndamentoDaOfertaService::class);

        if ($destino === PubAprovacaoOferta::PREPARANDO) {
            return $oferta;
        }

        $oferta = $porta->mover($oferta, PubAprovacaoOferta::AGUARDANDO_APROVACAO, $quem);

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

    /** Os textos dos eventos de uma oferta, na ordem do fato. */
    private function eventos(PubAprovacaoOferta $oferta): array
    {
        return PubAprovacaoEvento::query()
            ->where('oferta_id', $oferta->id)
            ->orderBy('ocorrido_em')
            ->orderBy('id')
            ->pluck('texto')
            ->all();
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // ⚠️⚠️ BLOQ-04 — APROVAR COM BLOQUEIO É RECUSADO, NOS OITO
    // ═════════════════════════════════════════════════════════════════════════════════════════

    public function test_bloq04_aprovar_com_qualquer_um_dos_oito_bloqueios_e_recusado_e_nada_e_gravado(): void
    {
        $quem = User::factory()->create();

        // Um cenário por bloqueio, cada um com o bloqueio de fato presente (fixture preenchida —
        // negativa com cenário vazio é vazia por construção).
        $montadores = [
            VocabularioDoPortao::FOTOS_A_CONFERIR => function (): PubAprovacaoOferta {
                $c = $this->cenario(['fotos' => PubAprovacaoImagem::PENDENTE]);

                return $this->levarAo($c['classico'], PubAprovacaoOferta::AGUARDANDO_APROVACAO);
            },
            VocabularioDoPortao::SEM_FOTOS => function (): PubAprovacaoOferta {
                $c = $this->cenario();
                PubAprovacaoImagem::query()->where('conjunto_id', $c['conjunto']->id)->delete();

                return $this->levarAo($c['classico'], PubAprovacaoOferta::AGUARDANDO_APROVACAO);
            },
            VocabularioDoPortao::FRETE_AUSENTE => function (): PubAprovacaoOferta {
                $c = $this->cenario(['frete' => null]);

                return $this->levarAo($c['classico'], PubAprovacaoOferta::AGUARDANDO_APROVACAO);
            },
            VocabularioDoPortao::RESULTADO_NEGATIVO => function (): PubAprovacaoOferta {
                $c = $this->cenario(['custo' => 80.0]);

                return $this->levarAo($c['classico'], PubAprovacaoOferta::AGUARDANDO_APROVACAO);
            },
            VocabularioDoPortao::MARGEM_ABAIXO_DO_MINIMO => function (): PubAprovacaoOferta {
                $c = $this->cenario(['custo' => 65.0]);

                return $this->levarAo($c['classico'], PubAprovacaoOferta::AGUARDANDO_APROVACAO);
            },
            VocabularioDoPortao::ALTERACAO_NAO_CONFERIDA => function (): PubAprovacaoOferta {
                $c = $this->cenario();
                $o = $this->levarAo($c['classico'], PubAprovacaoOferta::AGUARDANDO_APROVACAO);
                $o->update(['preco_alterado' => ['de' => 120.0, 'para' => 100.0]]);

                return $o->fresh();
            },
            VocabularioDoPortao::AJUSTE_EM_ABERTO => function (): PubAprovacaoOferta {
                $c = $this->cenario();
                $o = $this->levarAo($c['classico'], PubAprovacaoOferta::AGUARDANDO_APROVACAO);
                PubAprovacaoAjuste::create([
                    'escopo' => PubAprovacaoAjuste::ESCOPO_OFERTA,
                    'oferta_id' => $o->id,
                    'item' => 'Título',
                    'destino' => 'Este anúncio',
                    'texto' => 'Incluir a medida no título.',
                    'autor_id' => User::factory()->create()->id,
                    'aberto' => true,
                ]);

                return $o->fresh();
            },
            VocabularioDoPortao::TITULO_LONGO => function (): PubAprovacaoOferta {
                $c = $this->cenario(['titulo' => str_repeat('A', 61)]);

                return $this->levarAo($c['classico'], PubAprovacaoOferta::AGUARDANDO_APROVACAO);
            },
        ];

        // ⚠️ A COBERTURA: os OITO bloqueios da fase, nem um a menos.
        $this->assertSame(VocabularioDoPortao::OS_OITO, array_keys($montadores), 'o laço deixou de cobrir os 8 bloqueios — bloqueio sem cenário aqui é bloqueio que nunca foi provado como recusa');

        foreach ($montadores as $chave => $montar) {
            $oferta = $montar();

            // O cenário TEM de ter o bloqueio pretendido, senão a recusa abaixo seria por outro
            // motivo e o caso não mediria nada.
            $this->assertContains($chave, $this->bloqueios()->para($oferta)['chaves'], "o cenário de `{$chave}` não produziu esse bloqueio");

            try {
                $this->servico()->aprovar($oferta, $quem);
                $this->fail("aprovar com `{$chave}` em aberto NÃO foi recusado — e aprovar já publica, sem desfazer");
            } catch (RegraViolada $e) {
                $this->assertSame(AprovacaoDaOfertaService::REGRA_COM_BLOQUEIO, $e->regra, "a recusa de `{$chave}` veio com outro código de regra");
            }

            // ⚠️ E **NADA** foi gravado: a recusa é no-op completo.
            $depois = $oferta->fresh();
            $this->assertNotSame(PubAprovacaoOferta::APROVADO, $depois->andamento, "`{$chave}`: a oferta foi aprovada mesmo com o bloqueio");
            $this->assertNull($depois->aprovado_por, "`{$chave}`: `aprovado_por` foi gravado numa recusa");
            $this->assertNull($depois->aprovado_em);
            $this->assertNull($depois->versao_aprovada);
            $this->assertSame(0, PubAprovacaoComposicao::query()->where('oferta_id', $depois->id)->count(), "`{$chave}`: a composição foi congelada numa recusa");
            $this->assertNotContains('Aprovou para publicação (versão 1)', $this->eventos($depois), "`{$chave}`: o evento de aprovação foi gravado numa recusa");
        }

        // ⚠️⚠️ ISCA — o cenário LIMPO aprova. Sem ela eu estaria provando apenas que nada nunca é
        // aprovado, e um `aprovar()` que lançasse sempre passaria no laço inteiro.
        $limpo = $this->cenario();
        $aprovada = $this->servico()->aprovar($this->levarAo($limpo['classico'], PubAprovacaoOferta::AGUARDANDO_APROVACAO), $quem);
        $this->assertSame(PubAprovacaoOferta::APROVADO, $aprovada->andamento, 'ISCA CEGA: nem o cenário sem bloqueio nenhum consegue ser aprovado.');
    }

    public function test_bloq04_aprovar_fora_dos_tres_ao_vivo_e_recusado_nos_cinco_estados(): void
    {
        $quem = User::factory()->create();

        foreach ([
            PubAprovacaoOferta::PREPARANDO,
            PubAprovacaoOferta::APROVADO,
            PubAprovacaoOferta::PUBLICANDO,
            PubAprovacaoOferta::PUBLICADO,
            PubAprovacaoOferta::FALHA,
        ] as $estado) {
            $c = $this->cenario();
            $oferta = $this->levarAo($c['classico'], $estado, $quem);
            $this->assertSame($estado, $oferta->andamento, "o cenário não chegou a `{$estado}`");

            try {
                $this->servico()->aprovar($oferta, $quem);
                $this->fail("aprovar em `{$estado}` NÃO foi recusado");
            } catch (RegraViolada $e) {
                $this->assertSame(AprovacaoDaOfertaService::REGRA_FORA_DO_AO_VIVO, $e->regra, "a recusa em `{$estado}` veio com outro código");
            }
        }

        // ⚠️ ISCA — nos TRÊS ao vivo, aprova.
        foreach (PubAprovacaoOferta::LIVE as $vivo) {
            $c = $this->cenario();
            $oferta = $this->levarAo($c['classico'], $vivo, $quem);
            $this->assertSame(
                PubAprovacaoOferta::APROVADO,
                $this->servico()->aprovar($oferta, $quem)->andamento,
                "ISCA CEGA: `{$vivo}` é `live` e ainda assim não aprova — a recusa pode estar valendo para todo mundo."
            );
        }
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // ⚠️⚠️ BLOQ-05 + T-186-34 — A APROVAÇÃO E A **TRANSAÇÃO ÚNICA**
    // ═════════════════════════════════════════════════════════════════════════════════════════

    public function test_bloq05_aprovar_grava_quem_quando_em_que_versao_marca_tocado_e_congela(): void
    {
        $quem = User::factory()->create(['name' => 'Ana Ribeiro']);
        $c = $this->cenario();
        $oferta = $this->levarAo($c['classico'], PubAprovacaoOferta::AGUARDANDO_APROVACAO);
        $this->assertFalse((bool) $oferta->tocado);

        $aprovada = $this->servico()->aprovar($oferta, $quem, (int) $oferta->versao);

        // (1) o andamento, (2) os três campos de aprovação, (4) `tocado`.
        $this->assertSame(PubAprovacaoOferta::APROVADO, $aprovada->andamento);
        $this->assertSame($quem->id, (int) $aprovada->aprovado_por);
        $this->assertSame('2026-10-15 14:00:00', $aprovada->aprovado_em->format('Y-m-d H:i:s'));
        $this->assertSame(1, (int) $aprovada->versao_aprovada);
        $this->assertSame((int) $aprovada->versao, (int) $aprovada->versao_aprovada);
        $this->assertTrue($aprovada->foiAprovada());
        $this->assertFalse($aprovada->aprovacaoReaberta(), 'a aprovação nasceu reaberta — `versao_aprovada` não casou a `versao`');
        $this->assertTrue((bool) $aprovada->tocado);

        // (3) o evento `ok` com a FRASE do protótipo (`:1166`).
        $this->assertContains('Aprovou para publicação (versão 1)', $this->eventos($aprovada));
        $this->assertSame(
            PubAprovacaoEvento::APROVACAO,
            PubAprovacaoEvento::query()->where('oferta_id', $aprovada->id)->where('texto', 'Aprovou para publicação (versão 1)')->value('tipo'),
            'o evento da aprovação não entrou como `ok` — o filtro por tipo da linha do tempo mentiria'
        );

        // (5) ⚠️ O CONGELAMENTO da composição no momento `aprovacao`, com o mínimo DA ÉPOCA e de
        // qual lado do fallback ele veio. Sem isso não há auditoria-depois: a config pode mudar.
        $congelada = PubAprovacaoComposicao::query()
            ->where('oferta_id', $aprovada->id)
            ->where('momento', PubAprovacaoComposicao::APROVACAO)
            ->first();
        $this->assertNotNull($congelada, 'a composição NÃO foi congelada na aprovação — a oferta publicaria sem prova do que foi aprovado');
        $this->assertSame(1, (int) $congelada->versao, 'a composição foi congelada em outra versão');
        $this->assertSame(12.0, (float) $congelada->margem_minima);
        $this->assertSame(PubAprovacaoComposicao::MINIMO_CONFIG, $congelada->margem_minima_origem);
        $this->assertNotNull($congelada->resultado);

        // ⚠️ AND-04 — este serviço **não** leva a oferta a `publicado`.
        $this->assertNotSame(PubAprovacaoOferta::PUBLICADO, $aprovada->andamento);
        $this->assertNull($aprovada->ml_item_id, 'apareceu MLB sem o Mercado Livre ter confirmado nada (T-186-36)');
    }

    public function test_t186_34_a_falha_no_meio_da_aprovacao_nao_deixa_oferta_aprovada_sem_prova(): void
    {
        $quem = User::factory()->create();
        $c = $this->cenario();
        $oferta = $this->levarAo($c['classico'], PubAprovacaoOferta::AGUARDANDO_APROVACAO);

        // ⚠️ O dublê FALHA no ÚLTIMO passo (o congelamento), que é exatamente o cenário temido:
        // andamento e `aprovado_*` já gravados, prova do que foi aprovado ainda não.
        $quebrada = new class($this->efetivos, app(ClienteMlPublicador::class)) extends ComposicaoFinanceiraService
        {
            public function congelar(PubAprovacaoOferta $oferta, string $momento): PubAprovacaoComposicao
            {
                throw new \RuntimeException('falha de propósito no congelamento');
            }
        };

        try {
            $this->servico($quebrada)->aprovar($oferta, $quem);
            $this->fail('ISCA CEGA: o dublê não quebrou nada — o teste da transação não está medindo falha no meio.');
        } catch (\RuntimeException $e) {
            $this->assertSame('falha de propósito no congelamento', $e->getMessage());
        }

        // ⚠️⚠️ E **NADA** ficou: a transação é UMA SÓ, e ela voltou inteira.
        $depois = $oferta->fresh();
        $this->assertSame(PubAprovacaoOferta::AGUARDANDO_APROVACAO, $depois->andamento, 'a oferta ficou APROVADA com o congelamento falhado — ela publicaria sem prova do que foi aprovado (T-186-34)');
        $this->assertNull($depois->aprovado_por);
        $this->assertNull($depois->aprovado_em);
        $this->assertNull($depois->versao_aprovada);
        $this->assertFalse((bool) $depois->tocado);
        $this->assertNotContains('Aprovou para publicação (versão 1)', $this->eventos($depois), 'o evento `ok` sobreviveu ao rollback — o histórico contaria uma aprovação que não houve');
        $this->assertSame(0, PubAprovacaoComposicao::query()->where('oferta_id', $depois->id)->count());

        // ⚠️ ISCA — com a composição REAL, o mesmo cenário aprova e grava as cinco coisas.
        $ok = $this->servico()->aprovar($oferta->fresh(), $quem);
        $this->assertSame(PubAprovacaoOferta::APROVADO, $ok->andamento, 'ISCA CEGA: o cenário não aprova nem com a composição boa.');
        $this->assertSame(1, PubAprovacaoComposicao::query()->where('oferta_id', $ok->id)->count());
    }

    public function test_aprovar_com_excecao_ativa_deixa_a_autorizacao_na_linha_do_tempo(): void
    {
        // ⚠️⚠️ **DIVERGÊNCIA DECLARADA.** O plano pedia o sufixo *" com exceção autorizada"* no
        // evento da aprovação. ⚠️ A frase do evento é montada por
        // `HistoricoDaOfertaService::registrarTransicao()`, que é **wave 3 e SOMENTE LEITURA** para
        // este plano, e `mover()` sempre a registra. As alternativas eram **reabrir um serviço de
        // outra wave** ou **gravar um SEGUNDO evento para o mesmo fato** — e o segundo evento é
        // exatamente o defeito que o plano 186-05 consertou (dois `adjust` por pedido de ajuste).
        //
        // ➡️ A informação **não se perde, e é MAIOR**: o evento `exc` fica na linha do tempo
        // IMEDIATAMENTE antes do `ok`, com quem liberou, quando, a justificativa e a versão — o
        // sufixo diria só "houve exceção".
        $quem = User::factory()->create(['name' => 'Rafael Gusmão']);
        $c = $this->cenario(['custo' => 80.0]);
        $oferta = $this->levarAo($c['classico'], PubAprovacaoOferta::AGUARDANDO_APROVACAO);

        // Com prejuízo, a oferta NÃO é aprovável...
        $this->assertSame([VocabularioDoPortao::RESULTADO_NEGATIVO], $this->bloqueios()->para($oferta)['chaves']);

        // ...até a exceção ser autorizada, com motivo (BLOQ-09).
        app(AjustesDaOfertaService::class)->aplicarExcecao(
            $oferta, $quem, PubAprovacaoExcecao::RESULTADO_NEGATIVO, 'Cliente aceitou a perda nesta campanha.'
        );
        $this->assertSame([], $this->bloqueios()->para($oferta->fresh())['chaves']);

        $aprovada = $this->servico()->aprovar($oferta->fresh(), $quem);
        $this->assertSame(PubAprovacaoOferta::APROVADO, $aprovada->andamento);

        $textos = $this->eventos($aprovada);
        $iExc = array_search('Autorizou exceção (Resultado negativo): Cliente aceitou a perda nesta campanha.', $textos, true);
        $iOk = array_search('Aprovou para publicação (versão 1)', $textos, true);

        $this->assertNotFalse($iExc, 'a autorização da exceção não está na linha do tempo — "auditoria DEPOIS" viraria uma frase');
        $this->assertNotFalse($iOk);
        $this->assertLessThan($iOk, $iExc, 'a exceção tem de aparecer ANTES da aprovação: é ela que explica por que a aprovação foi possível');

        // E o registro da exceção tem quem, quando, a justificativa e a versão — na mesma escrita.
        $excecao = PubAprovacaoExcecao::vigentesDa((int) $aprovada->id)->firstOrFail();
        $this->assertSame($quem->id, (int) $excecao->liberada_por);
        $this->assertSame(1, (int) $excecao->versao);
        $this->assertSame((int) $aprovada->versao_aprovada, (int) $excecao->versao, 'a exceção foi autorizada numa versão diferente da aprovada');
        $this->assertStringContainsString('Rafael Gusmão', $excecao->fraseDeExibicao());

        // ⚠️ E a composição congelada guarda a decisão com prejuízo — é o que a auditoria lê.
        $congelada = PubAprovacaoComposicao::query()->where('oferta_id', $aprovada->id)->firstOrFail();
        $this->assertSame(-11.0, (float) $congelada->resultado);
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // ⚠️⚠️ BLOQ-06 — AS 8 MUDANÇAS E A REABERTURA (o 13º par)
    // ═════════════════════════════════════════════════════════════════════════════════════════

    public function test_bloq06_as_oito_mudancas_incrementam_a_versao_em_um_e_reabrem_com_registro(): void
    {
        $quem = User::factory()->create();

        $this->assertCount(8, AprovacaoDaOfertaService::MUDANCAS, 'a lista fechada das 8 mudanças relevantes mudou de tamanho');

        foreach (AprovacaoDaOfertaService::MUDANCAS as $mudanca => $spec) {
            $c = $this->cenario();
            $oferta = $this->servico()->aprovar($this->levarAo($c['classico'], PubAprovacaoOferta::AGUARDANDO_APROVACAO), $quem);
            $this->assertSame(PubAprovacaoOferta::APROVADO, $oferta->andamento);
            $this->assertSame(1, (int) $oferta->versao);

            // ⚠️ `frete_confirmado` vem de `PubAprovacaoFrete::informar()`, que **já** incrementa.
            if ($mudanca === AprovacaoDaOfertaService::FRETE_CONFIRMADO) {
                $depois = $this->servico()->confirmarFrete($oferta, 12.5, PubAprovacaoFrete::TABELA_CLIENTE, $quem);
            } else {
                $this->servico()->registrarMudanca($oferta, $mudanca, $quem);
                $depois = $oferta->fresh();
            }

            // (a) a versão subiu **em 1** — nunca em 2.
            $this->assertSame(2, (int) $depois->versao, "a mudança `{$mudanca}` não incrementou a versão em exatamente 1");

            // (b) a oferta VOLTOU para "Aguardando aprovação" — o 13º par.
            $this->assertSame(PubAprovacaoOferta::AGUARDANDO_APROVACAO, $depois->andamento, "a mudança `{$mudanca}` não reabriu a aprovação");
            $this->assertTrue($depois->ehLive(), "depois de `{$mudanca}` a oferta tem de voltar a ser EDITÁVEL, senão ninguém conserta a foto que mudou");

            // (c) `versao_aprovada` ficou ATRÁS da versão: a aprovação não vale mais.
            $this->assertSame(1, (int) $depois->versao_aprovada);
            $this->assertTrue($depois->aprovacaoReaberta(), "`aprovacaoReaberta()` não acusou a reabertura de `{$mudanca}`");

            // (d) e o REGISTRO, com a frase do protótipo.
            $esperado = AprovacaoDaOfertaService::REABERTA_PREFIXO.$spec['frase'];
            $achou = array_filter($this->eventos($depois), fn (string $t) => str_starts_with($t, $esperado));
            $this->assertNotEmpty($achou, "a mudança `{$mudanca}` reabriu sem registrar “{$esperado} …”");
            $this->assertStringContainsString('(versão 2)', implode(' | ', $achou), "o registro de `{$mudanca}` não diz em que versão a reabertura aconteceu");
        }

        // ⚠️ ISCA — chave fora das 8 é RECUSADA, e nada muda.
        $c = $this->cenario();
        $oferta = $this->servico()->aprovar($this->levarAo($c['classico'], PubAprovacaoOferta::AGUARDANDO_APROVACAO), $quem);
        try {
            $this->servico()->registrarMudanca($oferta, 'alguem_mexeu_em_algo', $quem);
            $this->fail('mudança fora da lista fechada NÃO foi recusada — o default tem de ser recusa');
        } catch (RegraViolada $e) {
            $this->assertSame(AprovacaoDaOfertaService::REGRA_MUDANCA_DESCONHECIDA, $e->regra);
        }
        $this->assertSame(1, (int) $oferta->fresh()->versao, 'a mudança desconhecida mexeu na versão');
        $this->assertSame(PubAprovacaoOferta::APROVADO, $oferta->fresh()->andamento);
    }

    public function test_bloq06_mudanca_de_foto_reabre_as_duas_ofertas_do_conjunto(): void
    {
        $quem = User::factory()->create();
        $c = $this->cenario();

        $classico = $this->servico()->aprovar($this->levarAo($c['classico'], PubAprovacaoOferta::AGUARDANDO_APROVACAO), $quem);
        $premium = $this->servico()->aprovar($this->levarAo($c['premium'], PubAprovacaoOferta::AGUARDANDO_APROVACAO), $quem);

        $this->assertSame(PubAprovacaoOferta::APROVADO, $classico->andamento);
        $this->assertSame(PubAprovacaoOferta::APROVADO, $premium->andamento);

        // ⚠️⚠️ **UMA** mudança de foto, entrando pelo CLÁSSICO.
        $alcancadas = $this->servico()->registrarMudanca($classico, AprovacaoDaOfertaService::FOTO_REPROVADA, $quem, 'Imagem #1');

        $this->assertCount(2, $alcancadas, 'a mudança de foto alcançou uma oferta só — a outra ficaria APROVADA com a foto trocada');

        foreach (['Clássico' => $classico, 'Premium' => $premium] as $nome => $oferta) {
            $depois = $oferta->fresh();
            $this->assertSame(PubAprovacaoOferta::AGUARDANDO_APROVACAO, $depois->andamento, "o {$nome} não foi reaberto — a foto é compartilhada por ESTRUTURA");
            $this->assertSame(2, (int) $depois->versao);
            $this->assertTrue($depois->aprovacaoReaberta());
            $this->assertNotEmpty(
                array_filter($this->eventos($depois), fn (string $t) => str_starts_with($t, 'Aprovação reaberta: foto reprovada')),
                "o histórico do {$nome} não explica por que ele saiu de Aprovado"
            );
        }

        // ⚠️ ISCA — e a mudança da IRMÃ também alcança as duas (a propagação não depende de por
        // onde a ação entrou).
        $classico = $classico->fresh();
        $premium = $premium->fresh();
        $this->servico()->aprovar($classico, $quem, (int) $classico->versao);
        $this->servico()->aprovar($premium, $quem, (int) $premium->versao);
        $this->servico()->registrarMudanca($premium->fresh(), AprovacaoDaOfertaService::PRINCIPAL_ALTERADA, $quem);
        $this->assertSame(PubAprovacaoOferta::AGUARDANDO_APROVACAO, $classico->fresh()->andamento, 'ISCA CEGA: entrando pelo Premium a propagação não alcança o Clássico.');
    }

    public function test_bloq06_preco_e_frete_reabrem_so_a_oferta_deles(): void
    {
        $quem = User::factory()->create();
        $c = $this->cenario();

        $classico = $this->servico()->aprovar($this->levarAo($c['classico'], PubAprovacaoOferta::AGUARDANDO_APROVACAO), $quem);
        $premium = $this->servico()->aprovar($this->levarAo($c['premium'], PubAprovacaoOferta::AGUARDANDO_APROVACAO), $quem);

        // Preço: escopo de OFERTA — o preço é por anúncio, não do conjunto de fotos.
        $this->servico()->registrarPrecoSalvo($classico, 100.0, 119.9, $quem);

        $this->assertSame(PubAprovacaoOferta::AGUARDANDO_APROVACAO, $classico->fresh()->andamento);
        $this->assertSame(2, (int) $classico->fresh()->versao);
        $this->assertSame(PubAprovacaoOferta::APROVADO, $premium->fresh()->andamento, 'o preço do Clássico reabriu o Premium — eles têm preço SEPARADO');
        $this->assertSame(1, (int) $premium->fresh()->versao, 'a versão do Premium subiu por uma mudança que não é dele');

        // E a frase nomeia os dois valores.
        $this->assertNotEmpty(array_filter($this->eventos($classico->fresh()), fn (string $t) => str_contains($t, 'de R$ 100,00 para R$ 119,90')));

        // Frete: também de OFERTA — e o incremento vem de `PubAprovacaoFrete::informar()`.
        $this->servico()->confirmarFrete($premium->fresh(), 33.9, PubAprovacaoFrete::COTACAO_TRANSPORTADORA, $quem);

        $this->assertSame(PubAprovacaoOferta::AGUARDANDO_APROVACAO, $premium->fresh()->andamento);
        $this->assertSame(2, (int) $premium->fresh()->versao, 'a confirmação de frete pulou DUAS versões — `informar()` já incrementa');
        $this->assertSame(2, (int) $classico->fresh()->versao, 'o frete do Premium mexeu na versão do Clássico');

        // E o frete ficou vigente, com a origem e a nota que nomeia a fonte.
        $vigente = PubAprovacaoFrete::vigenteDe($premium->fresh());
        $this->assertNotNull($vigente);
        $this->assertSame(33.9, (float) $vigente->valor);
        $this->assertSame(2, (int) $vigente->versao, 'a linha de frete guardou outra versão que não a de depois do incremento');
    }

    public function test_o_decimo_terceiro_par_e_o_unico_caminho_de_volta_e_a_reabertura_o_usa(): void
    {
        $quem = User::factory()->create();

        // ⚠️ O par existe porque o usuário decidiu em 15/10/2026. Antes disso, este plano PAROU e
        // devolveu a pergunta em vez de acrescentá-lo por conta própria.
        $this->assertTrue(PubAprovacaoOferta::transicaoPermitida(PubAprovacaoOferta::APROVADO, PubAprovacaoOferta::AGUARDANDO_APROVACAO), 'o 13º par (a REABERTURA) saiu da lista fechada');

        // ⚠️ E ele é o ÚNICO caminho de volta a partir de `aprovado`: os outros dois estados ao
        // vivo continuam RECUSADOS, porque a reabertura devolve a oferta ao começo da fila, não ao
        // meio da revisão de outra pessoa.
        $this->assertFalse(PubAprovacaoOferta::transicaoPermitida(PubAprovacaoOferta::APROVADO, PubAprovacaoOferta::EM_REVISAO_ECF));
        $this->assertFalse(PubAprovacaoOferta::transicaoPermitida(PubAprovacaoOferta::APROVADO, PubAprovacaoOferta::AJUSTES_SOLICITADOS));
        $this->assertSame(
            [PubAprovacaoOferta::PUBLICANDO, PubAprovacaoOferta::AGUARDANDO_APROVACAO],
            PubAprovacaoOferta::TRANSICOES[PubAprovacaoOferta::APROVADO],
            'os destinos de `aprovado` mudaram — ele tem exatamente dois: seguir para o ar, ou voltar à fila'
        );

        // ⚠️ FORA DE ESCOPO DECLARADO: `publicado` continua TERMINAL. Mexer na foto de anúncio que
        // **já está no ar** é outro fluxo, e quem decide é a **Fase 193** (decisão 13 do ROADMAP,
        // EM ABERTO). A decisão de 15/10 cobre só a janela entre aprovar e publicar.
        $this->assertSame([], PubAprovacaoOferta::TRANSICOES[PubAprovacaoOferta::PUBLICADO], 'nasceu transição saindo de `publicado` — isso é a Fase 193, não esta fase');

        // E a reabertura de uma oferta JÁ PUBLICADA: a mudança relevante **incrementa a versão** e
        // **não move** o andamento — o registro fica, e a decisão do que fazer é da Fase 193.
        $c = $this->cenario();
        $publicada = $this->levarAo($c['classico'], PubAprovacaoOferta::PUBLICADO, $quem);
        $this->assertSame(PubAprovacaoOferta::PUBLICADO, $publicada->andamento);

        $this->servico()->registrarMudanca($publicada, AprovacaoDaOfertaService::FOTO_REPROVADA, $quem);

        $depois = $publicada->fresh();
        $this->assertSame(PubAprovacaoOferta::PUBLICADO, $depois->andamento, 'a mudança de foto tirou do ar um anúncio publicado — isso é a Fase 193 e não foi decidido');
        $this->assertSame(2, (int) $depois->versao, 'a versão da publicada não subiu: o fato aconteceu e tem de ficar registrado');
        $this->assertTrue($depois->aprovacaoReaberta(), '`aprovacaoReaberta()` é o que a Fase 193 vai ler para saber que a foto mudou depois do ar');
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // BLOQ-07 · BLOQ-10 · BLOQ-11 · COL-01/04 · AND-06
    // ═════════════════════════════════════════════════════════════════════════════════════════

    public function test_bloq07_conferir_alteracao_nao_altera_o_preco_e_tira_o_bloqueio(): void
    {
        $quem = User::factory()->create();
        $c = $this->cenario();
        $oferta = $this->levarAo($c['classico'], PubAprovacaoOferta::AGUARDANDO_APROVACAO);
        $oferta->update(['preco_alterado' => ['de' => 120.0, 'para' => 100.0]]);
        $oferta = $oferta->fresh();

        $precoAntes = $this->composicaoReal()->precoDoAlvo($oferta)['preco'];
        $this->assertSame([VocabularioDoPortao::ALTERACAO_NAO_CONFERIDA], $this->bloqueios()->para($oferta)['chaves']);

        $depois = $this->servico()->conferirAlteracao($oferta, $quem);

        // ⚠️⚠️ O PREÇO FICOU IGUAL — asserção explícita, porque o nome da ação engana.
        $this->assertSame($precoAntes, $this->composicaoReal()->precoDoAlvo($depois)['preco'], 'conferir a alteração MEXEU no preço — ela só diz "eu vi que mudou"');
        $this->assertSame(100.0, $precoAntes);

        // O aviso saiu, a data da conferência entrou, e o bloqueio desapareceu.
        $this->assertNull($depois->preco_alterado);
        $this->assertSame('2026-10-15 14:00:00', $depois->preco_conferido_em->format('Y-m-d H:i:s'));
        $this->assertSame([], $this->bloqueios()->para($depois)['chaves']);
        $this->assertContains('Conferiu a alteração de preço da versão 1', $this->eventos($depois));

        // ⚠️ E a versão **não** mudou: conferir não é mudança relevante.
        $this->assertSame(1, (int) $depois->versao, 'conferir a alteração incrementou a versão — ela reabriria a aprovação sem nada ter mudado');
    }

    public function test_bloq10_o_lote_devolve_o_veredicto_de_todas_e_aprova_so_as_aptas(): void
    {
        $quem = User::factory()->create();

        $apta = $this->levarAo($this->cenario()['classico'], PubAprovacaoOferta::AGUARDANDO_APROVACAO);
        $comBloqueio = $this->levarAo($this->cenario(['custo' => 80.0])['classico'], PubAprovacaoOferta::AGUARDANDO_APROVACAO);
        $foraDoAoVivo = $this->levarAo($this->cenario()['classico'], PubAprovacaoOferta::PUBLICANDO, $quem);

        // ⚠️ O dublê financeiro é o do ÚLTIMO cenário montado; para o lote medir cada oferta com a
        // sua própria conta, as três são reconstruídas a partir do banco e o serviço usa a
        // composição corrente. O que se afirma aqui é a FORMA do veredicto e o efeito.
        $veredicto = $this->servico()->aprovarEmLote([$apta->fresh(), $comBloqueio->fresh(), $foraDoAoVivo->fresh()], $quem);

        // (1) o veredicto cobre **TODAS** as selecionadas, com `versao` e motivo.
        $this->assertCount(3, $veredicto, 'o lote escondeu alguma das selecionadas — o modal lista ✓ e × para todas');
        foreach ($veredicto as $linha) {
            $this->assertArrayHasKey('apta', $linha);
            $this->assertArrayHasKey('versao', $linha);
            $this->assertArrayHasKey('motivo', $linha);
            $this->assertSame(1, $linha['versao'], 'o lote tem de dizer em QUE versão cada uma foi julgada');
        }

        $porId = collect($veredicto)->keyBy('oferta_id');

        // (2) a apta foi aprovada.
        $this->assertTrue($porId[$apta->id]['apta']);
        $this->assertTrue($porId[$apta->id]['aprovada']);
        $this->assertNull($porId[$apta->id]['motivo']);
        $this->assertSame(PubAprovacaoOferta::APROVADO, $apta->fresh()->andamento);

        // (3) a com bloqueio ficou de FORA, **com o motivo**.
        $this->assertFalse($porId[$comBloqueio->id]['apta']);
        $this->assertFalse($porId[$comBloqueio->id]['aprovada']);
        $this->assertNotNull($porId[$comBloqueio->id]['motivo']);
        $this->assertNotSame(PubAprovacaoOferta::APROVADO, $comBloqueio->fresh()->andamento, 'o lote aprovou uma oferta com bloqueio em aberto');
        $this->assertStringStartsWith(VocabularioDoPortao::BLOQUEADO_PREFIXO, (string) $porId[$comBloqueio->id]['motivo']);

        // (4) a fora do ao vivo também, com o motivo dizendo o estado.
        $this->assertFalse($porId[$foraDoAoVivo->id]['apta']);
        $this->assertFalse($porId[$foraDoAoVivo->id]['aprovada']);
        $this->assertStringContainsString('Publicando', (string) $porId[$foraDoAoVivo->id]['motivo']);
        $this->assertSame(PubAprovacaoOferta::PUBLICANDO, $foraDoAoVivo->fresh()->andamento);

        // ⚠️ ISCA — o lote NÃO é um no-op: exatamente uma foi aprovada.
        $this->assertSame(1, count(array_filter($veredicto, fn (array $v) => $v['aprovada'])), 'ISCA CEGA: o lote não aprovou nada — ele poderia estar recusando todas.');
    }

    public function test_col04_aprovar_informando_uma_versao_velha_e_recusado_com_erro_proprio(): void
    {
        $quem = User::factory()->create();
        $c = $this->cenario();
        $oferta = $this->levarAo($c['classico'], PubAprovacaoOferta::AGUARDANDO_APROVACAO);

        $versaoQueATelaViu = (int) $oferta->versao;

        // Outra pessoa mexe numa foto: a versão sobe.
        $this->servico()->registrarMudanca($oferta, AprovacaoDaOfertaService::FOTOS_REORDENADAS, User::factory()->create());
        $this->assertSame(2, (int) $oferta->fresh()->versao);

        // ⚠️ O primeiro clica em "Aprovar para publicação" com a tela VELHA.
        try {
            $this->servico()->aprovar($oferta->fresh(), $quem, $versaoQueATelaViu);
            $this->fail('aprovar com a versão velha NÃO foi recusado — aprovaria o que não está mais lá (T-186-33)');
        } catch (RegraViolada $e) {
            $this->assertSame(AprovacaoDaOfertaService::REGRA_VERSAO_DIVERGENTE, $e->regra);
            $this->assertNotSame(AprovacaoDaOfertaService::REGRA_COM_BLOQUEIO, $e->regra, 'a recusa por versão divergente tem de ter código PRÓPRIO: a tela diz coisas diferentes nos dois casos');
            $this->assertSame(1, $e->contexto['versao_vista']);
            $this->assertSame(2, $e->contexto['versao_atual']);
        }

        // ⚠️ **NUNCA sobrescreve em silêncio**: nada foi gravado.
        $this->assertNotSame(PubAprovacaoOferta::APROVADO, $oferta->fresh()->andamento);
        $this->assertNull($oferta->fresh()->versao_aprovada);

        // ⚠️ ISCA — com a versão ATUAL, aprova.
        $atual = $oferta->fresh();
        $this->assertSame(
            PubAprovacaoOferta::APROVADO,
            $this->servico()->aprovar($atual, $quem, (int) $atual->versao)->andamento,
            'ISCA CEGA: nem com a versão certa aprova — a conferência pode estar recusando sempre.'
        );
    }

    public function test_bloq11_falha_preserva_a_revisao_e_tentar_de_novo_nao_mexe_na_versao(): void
    {
        $quem = User::factory()->create();
        $c = $this->cenario();
        $oferta = $this->levarAo($c['classico'], PubAprovacaoOferta::FALHA, $quem);

        $this->assertSame(PubAprovacaoOferta::FALHA, $oferta->andamento);
        $this->assertSame(1, (int) $oferta->versao);
        $this->assertSame(1, (int) $oferta->versao_aprovada, 'a falha apagou a versão aprovada — a revisão tem de ser PRESERVADA (BLOQ-11)');
        $this->assertNotNull($oferta->aprovado_por);
        $this->assertNotNull($oferta->aprovado_em);
        $this->assertFalse($oferta->aprovacaoReaberta(), 'a falha "reabriu" uma aprovação em que ninguém mexeu');

        $retentada = $this->servico()->tentarDeNovo($oferta, $quem);

        $this->assertSame(PubAprovacaoOferta::PUBLICANDO, $retentada->andamento);
        $this->assertSame(1, (int) $retentada->versao, '"Tentar de novo" incrementou a versão — reabriria uma aprovação intocada');
        $this->assertSame(1, (int) $retentada->versao_aprovada);
        $this->assertNull($retentada->erro_publicacao, 'o erro da tentativa anterior ficou na tela da nova');
    }

    public function test_and06_e_col01_salvar_revisao_move_registra_x_de_y_e_fica_no_banco(): void
    {
        $quem = User::factory()->create();
        $c = $this->cenario(['fotos' => PubAprovacaoImagem::PENDENTE]);

        // 3 fotos na galeria, 1 aprovada: a frase tem de dizer "1 de 3".
        $this->imagem($c['conjunto'], PubAprovacaoImagem::APROVADA, 2);
        $oferta = $this->levarAo($c['classico'], PubAprovacaoOferta::AGUARDANDO_APROVACAO);

        $depois = $this->servico()->salvarRevisao($oferta, $quem);

        // AND-06 — `aguardando_aprovacao → em_revisao_ecf`, e o evento `save`.
        $this->assertSame(PubAprovacaoOferta::EM_REVISAO_ECF, $depois->andamento);
        $this->assertTrue((bool) $depois->tocado);
        $this->assertContains('Salvou revisão · 1 de 3 fotos aprovadas', $this->eventos($depois));
        $this->assertSame(
            PubAprovacaoEvento::REVISAO_PARCIAL,
            PubAprovacaoEvento::query()->where('oferta_id', $depois->id)->where('texto', 'Salvou revisão · 1 de 3 fotos aprovadas')->value('tipo'),
            'o evento de revisão parcial não entrou como `save`'
        );

        // ⚠️ A contagem é a MESMA do bloqueio de fotos — duas definições divergiriam e a tela
        // diria "3 de 3" com bloqueio de pé.
        $this->assertSame(['total' => 3, 'aprovadas' => 1, 'pendentes' => 2, 'com_alerta' => 0], $this->bloqueios()->contagemDeFotos($depois));

        // ⚠️ COL-01/COL-02 — a revisão parcial está no BANCO. Reler por OUTRA instância devolve o
        // mesmo estado: nada de `localStorage` como fonte.
        $outraInstancia = PubAprovacaoOferta::query()->findOrFail($depois->id);
        $this->assertSame(PubAprovacaoOferta::EM_REVISAO_ECF, $outraInstancia->andamento);
        $this->assertTrue((bool) $outraInstancia->tocado);
        $this->assertSame(1, PubAprovacaoEvento::query()->where('oferta_id', $depois->id)->where('tipo', PubAprovacaoEvento::REVISAO_PARCIAL)->count());

        // Salvar de novo (já em `em_revisao_ecf`) **não** move e **registra** — é fato novo.
        $outra = $this->servico()->salvarRevisao($depois, $quem);
        $this->assertSame(PubAprovacaoOferta::EM_REVISAO_ECF, $outra->andamento);
        $this->assertSame(2, PubAprovacaoEvento::query()->where('oferta_id', $depois->id)->where('tipo', PubAprovacaoEvento::REVISAO_PARCIAL)->count());
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // ⚠️⚠️ PUB-09 — O PONTO DE EXTENSÃO DA FASE 193 É **UM SÓ**, E ESTÁ VAZIO
    // ═════════════════════════════════════════════════════════════════════════════════════════

    public function test_pub09_existe_exatamente_um_aoaprovar_no_portao_e_ele_esta_vazio(): void
    {
        // ⚠️⚠️ A PERGUNTA: o que precisaria estar errado no mundo para este gate falhar?
        //   **A Fase 193 encontrar DOIS ganchos com o mesmo nome e preencher os dois** — e o mesmo
        //   anúncio ser publicado **duas vezes**, sem desfazer. O plano pedia um `aoAprovar()`
        //   NESTE serviço; `AndamentoDaOfertaService` já tinha um, **dentro da transação** que
        //   grava os três campos de aprovação e impossível de contornar (ele é a única porta).
        //   ➡️ **DIVERGÊNCIA DECLARADA: o gancho é UM, e é o da porta.**
        $arquivos = glob(base_path('app/Services/Publicador/Aprovacao/*.php')) ?: [];
        $this->assertGreaterThanOrEqual(8, count($arquivos), 'ISCA CEGA: a varredura do diretório do portão não achou os arquivos — o gate rodaria sobre zero casos.');

        $ondeEsta = [];
        foreach ($arquivos as $arquivo) {
            $codigo = $this->semComentarios((string) file_get_contents($arquivo));
            $quantos = preg_match_all('/function\s+aoAprovar\s*\(/', $codigo);
            if ($quantos > 0) {
                $ondeEsta[basename($arquivo)] = $quantos;
            }
        }

        $this->assertSame(['AndamentoDaOfertaService.php' => 1], $ondeEsta, 'o número (ou o lugar) dos ganchos `aoAprovar` mudou — dois ganchos fazem a Fase 193 publicar duas vezes');

        // ISCA da sonda: ela acha a forma quando ela existe.
        $this->assertSame(1, preg_match_all('/function\s+aoAprovar\s*\(/', 'protected function aoAprovar(PubAprovacaoOferta $o): void {}'), 'ISCA CEGA: a sonda não acha nem a declaração sintética.');

        // ⚠️ E ELE ESTÁ **VAZIO**: este plano não enfileira, não publica e não fala com o ML.
        $porta = $this->semComentarios((string) file_get_contents(base_path('app/Services/Publicador/Aprovacao/AndamentoDaOfertaService.php')));
        $corpo = substr($porta, (int) strpos($porta, 'function aoAprovar'));
        $corpo = substr($corpo, 0, (int) strpos($corpo, '}') + 1);
        foreach (['dispatch', 'Queue', 'MlPublicacaoService', 'FilaPublicacao', 'Http::'] as $proibido) {
            $this->assertStringNotContainsString($proibido, $corpo, "o gancho `aoAprovar()` já publica/enfileira (`{$proibido}`) — isso é a Fase 193");
        }

        // E os dois serviços deste plano não falam com o Mercado Livre em lugar nenhum.
        foreach (['AprovacaoDaOfertaService.php', 'RevisaoDeImagemService.php'] as $meu) {
            $codigo = $this->semComentarios((string) file_get_contents(base_path('app/Services/Publicador/Aprovacao/'.$meu)));
            foreach (['MlPublicacaoService', 'MercadoLivreService', 'Http::', 'App\\Services\\Creative'] as $proibido) {
                $this->assertStringNotContainsString($proibido, $codigo, "`{$meu}` fala com o Mercado Livre ou com o Creative Engine — fora do território deste plano");
            }
        }
    }

    /**
     * O CÓDIGO sem os comentários, pelo tokenizador do PHP.
     *
     * ⚠️ Molde de `MigrationDoPortaoGuardaTest::semComentarios` (plano 186-03): **palavra no
     * DOCBLOCK que explica "eu não uso isso" faz o gate reprovar pelo próprio conserto** — e os
     * docblocks deste plano citam `MlPublicacaoService` e a Fase 193 o tempo todo.
     */
    private function semComentarios(string $texto): string
    {
        $saida = '';

        foreach (token_get_all($texto) as $token) {
            if (! is_array($token)) {
                $saida .= $token;

                continue;
            }

            if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                $saida .= str_repeat("\n", substr_count($token[1], "\n"));

                continue;
            }

            $saida .= $token[1];
        }

        return $saida;
    }

    public function test_o_leitor_sem_comentarios_do_plano_08_preserva_o_codigo_e_tira_o_docblock(): void
    {
        // ⚠️ Sem esta prova, o gate acima poderia estar lendo o arquivo INTEIRO (e reprovando pelo
        // docblock que explica a decisão) ou lendo NADA (e passando sempre).
        $amostra = <<<'PHP'
        <?php
        // nao toca MlPublicacaoService
        /** @see MlPublicacaoService — e da Fase 193 */
        $x = 'codigo preservado';
        PHP;

        $limpo = $this->semComentarios($amostra);
        $this->assertStringNotContainsString('MlPublicacaoService', $limpo, 'o leitor deixou passar o docblock — o gate reprovaria pelo próprio conserto');
        $this->assertStringContainsString("\$x = 'codigo preservado';", $limpo, 'o leitor comeu o CÓDIGO junto do comentário — o gate ficaria vazio por construção');

        // E num arquivo REAL deste plano: o docblock cita a Fase 193, o código não.
        $real = (string) file_get_contents(base_path('app/Services/Publicador/Aprovacao/AprovacaoDaOfertaService.php'));
        $this->assertStringContainsString('Fase 193', $real);
        $this->assertStringNotContainsString('Fase 193', $this->semComentarios($real), 'a menção à Fase 193 está no CÓDIGO, não no comentário');
        $this->assertStringContainsString('REGRA_COM_BLOQUEIO', $this->semComentarios($real));
    }
}
