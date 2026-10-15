<?php

namespace Tests\Feature\Publicador\Aprovacao;

use App\Models\Company;
use App\Models\PubAprovacaoConjunto;
use App\Models\PubAprovacaoEvento;
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
use App\Services\Publicador\Aprovacao\RevisaoDeImagemService;
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
 * Fase 186, plano 08 — AS REGRAS DE IMAGEM (IMG-01 a IMG-10) e a reabertura do conjunto.
 *
 * ⚠️ **ARQUIVO ACRESCENTADO, declarado.** O plano punha as regras de imagem em
 * `BloqueiosContraOPrototipoTest`, que a `<action>` da tarefa 3 reserva para o **teste tabelado
 * dos 10 estados do protótipo**. Juntar as duas coisas num arquivo faria o `--filter` do gate
 * casar os dois assuntos e a contagem medir uma mistura. ➡️ Um arquivo por assunto.
 *
 * ═══ ⚠️⚠️ DE CADA GATE, A PERGUNTA RESPONDIDA ANTES DE ELE EXISTIR ═════════════════════════════
 *
 *   · **reprovar sem motivo** — alguém aceitar string vazia. Foto sai da publicação e ninguém
 *     sabe por quê; o pedido de regeneração depende do motivo.
 *   · **a principal ficando fora do topo** — alguém reprovar a capa e não promover a próxima. A
 *     capa apontaria para foto **fora da publicação**, e o anúncio subiria com a foto errada.
 *   · **substituir zerando a aprovação da original** — a pessoa reconferiria a MESMA foto que
 *     acabou de aprovar.
 *   · **"Aprovar N sem alerta" aprovando as com alerta** — passaria as suspeitas de bonde, que é
 *     o oposto do que o botão existe para fazer. O teste tem as DUAS presentes.
 *   · **ação em `regenerating`** — aprovar uma foto que vai ser substituída em segundos **aprova
 *     a foto errada**, e ela vai ao ar. As **quatro** recusas são provadas.
 *   · **reabrir só a oferta por onde a ação entrou** — a irmã ficaria `aprovado` com a foto
 *     trocada, e aprovar já publicou. ⚠️ **Sem desfazer.**
 *   · **a reabertura duplicada** — se este serviço incrementasse a `versao` por conta própria, as
 *     duas cópias da regra ficariam livres para divergir. O gate afirma que a propagação é
 *     **delegada**, lendo o código.
 *
 * ⚠️ REND-08: tudo que grava data roda sob `Carbon::setTestNow()`.
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 */
class RevisaoDeImagemTest extends TestCase
{
    use RefreshDatabase;

    private const CLASSICO = 'gold_special';

    private const PREMIUM = 'gold_pro';

    private const CATEGORIA = 'MLB189007';

    private ?DadosEfetivosService $efetivos = null;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-15 15:00:00');

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
                    : Http::response(['message' => 'sem fixture'], 404);
            }

            return Http::response(['message' => 'sem stub'], 404);
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
    // O CENÁRIO
    // ═════════════════════════════════════════════════════════════════════════════════════════

    /**
     * @return array{rascunho: PubRascunho, conjunto: PubAprovacaoConjunto, classico: PubAprovacaoOferta, premium: PubAprovacaoOferta}
     */
    private function cenario(int $fotos = 3, string $estado = PubAprovacaoImagem::APROVADA): array
    {
        $empresa = Company::factory()->create();
        $produto = PubProduto::create([
            'company_id' => $empresa->id,
            'sku' => 'IMG-'.(PubProduto::count() + 1),
            'nome' => 'Poltrona da revisão de imagem',
            'origem' => PubProduto::ORIGEM_PUBLICADOR,
            'quantidade_kit' => 1,
        ]);
        $rascunho = PubRascunho::create(['produto_id' => $produto->id, 'categoria_id' => self::CATEGORIA]);

        $ofertas = [];
        foreach ([self::CLASSICO, self::PREMIUM] as $i => $tipo) {
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
                'company_id' => $empresa->id,
            ]);
        }

        $conjunto = PubAprovacaoConjunto::create([
            'rascunho_id' => $rascunho->id,
            'grupo_chave' => PubAprovacaoConjunto::GERAL,
            'grupo_hash' => ChaveCanonica::hash(PubAprovacaoConjunto::GERAL),
        ]);

        for ($i = 0; $i < $fotos; $i++) {
            $this->imagem($conjunto, $estado, $i);
        }
        $conjunto->update(['principal_imagem_id' => $conjunto->imagens()->orderBy('posicao')->first()?->imagem_id]);

        if ($this->efetivos === null) {
            $this->efetivos = new class(app(EstruturaPrecificacaoService::class)) extends DadosEfetivosService
            {
                public array $custosPorProduto = [];

                public array $fretesPorProduto = [];

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

        $this->efetivos->custosPorProduto[(int) $produto->id] = ['custo' => 20.0, 'origem' => 'digitado', 'imposto' => 10.0, 'por_variante' => []];
        $porTipo = [];
        $precos = [];
        foreach ([self::CLASSICO, self::PREMIUM] as $tipo) {
            $porTipo[$tipo] = ['valor' => 10.0, 'origem' => 'tabela', 'oferta_id' => null];
            $precos[$tipo] = 100.0;
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

    private function imagem(PubAprovacaoConjunto $c, string $estado, ?int $posicao, bool $comAlerta = false, string $tipo = PubAprovacaoImagem::TIPO_ORIGINAL): PubAprovacaoImagem
    {
        $n = PubImagem::count();
        $crua = PubImagem::create(['rascunho_id' => $c->rascunho_id, 'caminho' => "fotos/foto-{$n}.jpg"]);
        $img = PubAprovacaoImagem::create([
            'conjunto_id' => $c->id,
            'imagem_id' => $crua->id,
            'tipo' => $tipo,
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

    private function composicao(): ComposicaoFinanceiraService
    {
        return new ComposicaoFinanceiraService($this->efetivos ?? app(DadosEfetivosService::class), app(ClienteMlPublicador::class));
    }

    private function aprovacao(): AprovacaoDaOfertaService
    {
        $composicao = $this->composicao();

        return new AprovacaoDaOfertaService(
            app(AndamentoDaOfertaService::class),
            app(HistoricoDaOfertaService::class),
            new BloqueiosDaOfertaService($composicao, app(AjustesDaOfertaService::class)),
            $composicao,
        );
    }

    private function servico(): RevisaoDeImagemService
    {
        return new RevisaoDeImagemService($this->aprovacao(), app(HistoricoDaOfertaService::class));
    }

    /** Leva as DUAS ofertas do rascunho a `aprovado`, para a reabertura ser observável. */
    private function aprovarAsDuas(array $c, User $quem): array
    {
        $porta = app(AndamentoDaOfertaService::class);
        $servico = $this->aprovacao();

        $classico = $servico->aprovar($porta->mover($c['classico'], PubAprovacaoOferta::AGUARDANDO_APROVACAO), $quem);
        $premium = $servico->aprovar($porta->mover($c['premium'], PubAprovacaoOferta::AGUARDANDO_APROVACAO), $quem);

        return [$classico, $premium];
    }

    /** @return list<int> os ids na galeria final, na ordem */
    private function galeria(PubAprovacaoConjunto $c): array
    {
        return $c->imagens()
            ->orderBy('posicao')
            ->orderBy('id')
            ->get()
            ->filter(fn (PubAprovacaoImagem $i) => $i->noConjuntoFinal())
            ->map(fn (PubAprovacaoImagem $i) => (int) $i->id)
            ->values()
            ->all();
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

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // IMG-04 / IMG-05 — APROVAR
    // ═════════════════════════════════════════════════════════════════════════════════════════

    public function test_img04_aprovar_foto_grava_quem_quando_marca_tocado_nas_duas_e_nao_reabre(): void
    {
        $quem = User::factory()->create(['name' => 'Ana Ribeiro']);
        $c = $this->cenario(2, PubAprovacaoImagem::PENDENTE);
        [$classico, $premium] = $this->aprovarAsDuasComFotoPendente($c, $quem);

        $primeira = $c['conjunto']->imagens()->orderBy('posicao')->firstOrFail();
        $aprovada = $this->servico()->aprovar($primeira, $quem);

        // quem + quando.
        $this->assertSame(PubAprovacaoImagem::APROVADA, $aprovada->estado);
        $this->assertSame($quem->id, (int) $aprovada->quem_id);
        $this->assertSame('2026-10-15 15:00:00', $aprovada->quando->format('Y-m-d H:i:s'));

        // ⚠️ `tocado` no conjunto **e nas DUAS ofertas**: sem isso o Premium pareceria "ainda não
        // analisado" depois de alguém aprovar as fotos compartilhadas.
        $this->assertTrue((bool) $c['conjunto']->refresh()->tocado);
        $this->assertTrue((bool) $classico->fresh()->tocado);
        $this->assertTrue((bool) $premium->fresh()->tocado);

        // O registro, nas DUAS linhas do tempo (divergência declarada do protótipo, que registra
        // só na primeira oferta).
        foreach ([$classico, $premium] as $oferta) {
            $this->assertNotEmpty(
                array_filter($this->eventos($oferta->fresh()), fn (string $t) => str_starts_with($t, 'Aprovou a foto “')),
                'a linha do tempo de uma das ofertas não explica por que a foto compartilhada ficou aprovada'
            );
        }

        // ⚠️⚠️ E **NÃO REABRE NADA**: aprovar é progresso, não mudança do conjunto.
        $this->assertSame(1, (int) $classico->fresh()->versao, 'aprovar foto incrementou a versão — reabriria a aprovação a cada foto conferida');
        $this->assertSame(1, (int) $premium->fresh()->versao);
    }

    /** As duas ofertas em `aguardando_aprovacao` (não dá para aprovar com foto pendente). */
    private function aprovarAsDuasComFotoPendente(array $c, User $quem): array
    {
        $porta = app(AndamentoDaOfertaService::class);

        return [
            $porta->mover($c['classico'], PubAprovacaoOferta::AGUARDANDO_APROVACAO, $quem),
            $porta->mover($c['premium'], PubAprovacaoOferta::AGUARDANDO_APROVACAO, $quem),
        ];
    }

    public function test_img05_aprovar_sem_alerta_nao_toca_na_pendente_com_alerta(): void
    {
        $quem = User::factory()->create();
        $c = $this->cenario(0);

        $limpa1 = $this->imagem($c['conjunto'], PubAprovacaoImagem::PENDENTE, 0, false);
        $comAlerta = $this->imagem($c['conjunto'], PubAprovacaoImagem::PENDENTE, 1, true);
        $limpa2 = $this->imagem($c['conjunto'], PubAprovacaoImagem::PENDENTE, 2, false);
        $jaAprovada = $this->imagem($c['conjunto'], PubAprovacaoImagem::APROVADA, 3, false);

        $quantas = $this->servico()->aprovarSemAlerta($c['conjunto'], $quem);

        // ⚠️ As DUAS presentes no mesmo cenário: a limpa é aprovada, a com alerta **não**.
        $this->assertSame(2, $quantas);
        $this->assertSame(PubAprovacaoImagem::APROVADA, $limpa1->refresh()->estado);
        $this->assertSame(PubAprovacaoImagem::APROVADA, $limpa2->refresh()->estado);
        $this->assertSame(PubAprovacaoImagem::PENDENTE, $comAlerta->refresh()->estado, 'a foto COM alerta foi aprovada de bonde — é o oposto do que o botão existe para fazer');
        $this->assertSame(PubAprovacaoImagem::APROVADA, $jaAprovada->refresh()->estado);

        $this->assertNotEmpty(array_filter(
            $this->eventos($c['classico']->fresh()),
            fn (string $t) => $t === 'Aprovou 2 fotos sem alerta do conjunto compartilhado'
        ), 'a frase do protótipo não foi registrada');

        // ⚠️ ISCA — tirando o alerta, a terceira entra na conta.
        PubAprovacaoImagemAlerta::query()
            ->where('aprovacao_imagem_id', $comAlerta->id)
            ->update(['severidade' => PubAprovacaoImagemAlerta::OK]);
        $this->assertSame(1, $this->servico()->aprovarSemAlerta($c['conjunto']->refresh(), $quem), 'ISCA CEGA: sem alerta nenhum ela continua de fora — o filtro pode estar recusando todas.');
        $this->assertSame(PubAprovacaoImagem::APROVADA, $comAlerta->refresh()->estado);

        // Conjunto sem nenhuma pendente: zero, e nada é gravado.
        $this->assertSame(0, $this->servico()->aprovarSemAlerta($c['conjunto']->refresh(), $quem));
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // IMG-01 / IMG-10 — REPROVAR
    // ═════════════════════════════════════════════════════════════════════════════════════════

    public function test_img01_e_img10_reprovar_exige_motivo_tira_da_galeria_e_promove_a_proxima(): void
    {
        $quem = User::factory()->create();
        $c = $this->cenario(3);
        [$classico, $premium] = $this->aprovarAsDuas($c, $quem);

        $galeriaAntes = $this->galeria($c['conjunto']);
        $this->assertCount(3, $galeriaAntes);
        $principal = PubAprovacaoImagem::query()->findOrFail($galeriaAntes[0]);
        $this->assertTrue($principal->ehPrincipal(), 'o cenário precisa começar com a principal na posição 0');
        $this->assertSame((int) $principal->imagem_id, (int) $c['conjunto']->refresh()->principal_imagem_id);

        // ⚠️ IMG-10 — sem motivo, RECUSA e **nada** é gravado.
        foreach (['', '   '] as $vazio) {
            try {
                $this->servico()->reprovar($principal, $quem, $vazio);
                $this->fail('reprovar sem motivo NÃO foi recusado');
            } catch (RegraViolada $e) {
                $this->assertSame(RevisaoDeImagemService::REGRA_SEM_MOTIVO, $e->regra);
            }
        }
        $this->assertSame(PubAprovacaoImagem::APROVADA, $principal->refresh()->estado, 'a recusa por falta de motivo mexeu no estado da foto');
        $this->assertSame($galeriaAntes, $this->galeria($c['conjunto']->refresh()));
        $this->assertSame(1, (int) $classico->fresh()->versao, 'a recusa por falta de motivo reabriu a aprovação');

        // ⚠️ IMG-01 — com motivo: sai da galeria NA HORA, com as três marcas.
        $this->servico()->reprovar($principal, $quem, 'IA alterou os pés da poltrona');

        $reprovada = $principal->refresh();
        $this->assertSame(PubAprovacaoImagem::REPROVADA, $reprovada->estado);
        $this->assertNull($reprovada->posicao, '`posicao` NULA é o que significa "fora da publicação"');
        $this->assertTrue((bool) $reprovada->fora);
        $this->assertSame('IA alterou os pés da poltrona', $reprovada->motivo);
        $this->assertSame($quem->id, (int) $reprovada->quem_id);
        $this->assertFalse($reprovada->noConjuntoFinal());

        // ⚠️ E **A PRÓXIMA VIROU PRINCIPAL** — por construção, não por regra a lembrar.
        $galeriaDepois = $this->galeria($c['conjunto']->refresh());
        $this->assertCount(2, $galeriaDepois);
        $this->assertSame($galeriaAntes[1], $galeriaDepois[0], 'a segunda foto não assumiu o topo');
        $novaPrincipal = PubAprovacaoImagem::query()->findOrFail($galeriaDepois[0]);
        $this->assertSame(0, (int) $novaPrincipal->posicao);
        $this->assertTrue($novaPrincipal->ehPrincipal());
        $this->assertSame((int) $novaPrincipal->imagem_id, (int) $c['conjunto']->refresh()->principal_imagem_id, 'a capa do conjunto continuou apontando para a foto que saiu da publicação');

        // As posições ficaram compactas, `0..n-1`.
        $this->assertSame([0, 1], PubAprovacaoImagem::query()->whereIn('id', $galeriaDepois)->orderBy('posicao')->pluck('posicao')->map(fn ($p) => (int) $p)->all());

        // O registro, com o motivo, e a REABERTURA das duas.
        $this->assertNotEmpty(array_filter($this->eventos($classico->fresh()), fn (string $t) => str_contains($t, 'Reprovou a foto') && str_contains($t, 'IA alterou os pés')));
        foreach ([$classico, $premium] as $oferta) {
            $this->assertSame(PubAprovacaoOferta::AGUARDANDO_APROVACAO, $oferta->fresh()->andamento);
            $this->assertSame(2, (int) $oferta->fresh()->versao);
        }
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // IMG-02 / IMG-03 / IMG-08 — SUBSTITUIR, RECOLOCAR
    // ═════════════════════════════════════════════════════════════════════════════════════════

    public function test_img03_substituir_por_original_mantem_a_posicao_e_preserva_a_aprovacao(): void
    {
        $quem = User::factory()->create();
        $c = $this->cenario(3);
        [$classico] = $this->aprovarAsDuas($c, $quem);

        $galeria = $this->galeria($c['conjunto']);
        $doMeio = PubAprovacaoImagem::query()->findOrFail($galeria[1]);
        $this->assertSame(1, (int) $doMeio->posicao);

        // (a) original SEM linha de revisão ainda ⇒ entra `pending` (IMG-02), na MESMA posição.
        $crua = PubImagem::create(['rascunho_id' => $c['rascunho']->id, 'caminho' => 'fotos/original-nova.jpg']);
        $entrante = $this->servico()->substituirPorOriginal($doMeio, $crua, $quem);

        $this->assertSame(PubAprovacaoImagem::PENDENTE, $entrante->estado, 'original que nunca foi conferida entrou aprovada');
        $this->assertSame(1, (int) $entrante->posicao, 'a original não entrou na MESMA posição da substituída');
        $this->assertFalse((bool) $entrante->fora);
        $this->assertSame(PubAprovacaoImagem::SUBSTITUIDA, $doMeio->refresh()->estado, 'a substituída virou `rejected` — ela não foi reprovada por ninguém');
        $this->assertNull($doMeio->refresh()->posicao);
        $this->assertTrue((bool) $doMeio->refresh()->fora);

        // A galeria continua com 3, e a ordem preservou o lugar.
        $depois = $this->galeria($c['conjunto']->refresh());
        $this->assertCount(3, $depois);
        $this->assertSame((int) $entrante->id, $depois[1]);

        // E reabriu.
        $this->assertSame(PubAprovacaoOferta::AGUARDANDO_APROVACAO, $classico->fresh()->andamento);
        $this->assertNotEmpty(array_filter($this->eventos($classico->fresh()), fn (string $t) => str_contains($t, 'Substituiu')));

        // (b) ⚠️ ORIGINAL QUE JÁ ESTAVA APROVADA ⇒ a aprovação é **MANTIDA**.
        $c2 = $this->cenario(2);
        $alvo = PubAprovacaoImagem::query()->findOrFail($this->galeria($c2['conjunto'])[0]);
        $jaAprovada = $this->imagem($c2['conjunto'], PubAprovacaoImagem::APROVADA, null);
        $jaAprovada->update(['fora' => true]);

        $voltou = $this->servico()->substituirPorOriginal($alvo, $jaAprovada->imagem, $quem);
        $this->assertSame(PubAprovacaoImagem::APROVADA, $voltou->estado, 'a aprovação da original foi zerada — a pessoa reconferiria a MESMA foto que acabou de aprovar');
        $this->assertSame((int) $jaAprovada->id, (int) $voltou->id, 'nasceu uma SEGUNDA linha de revisão para a mesma imagem no mesmo conjunto');
        $this->assertSame(0, (int) $voltou->posicao);

        // (c) original de OUTRO rascunho é recusada.
        $outro = $this->cenario(1);
        try {
            $this->servico()->substituirPorOriginal(
                PubAprovacaoImagem::query()->findOrFail($this->galeria($c2['conjunto']->refresh())[1] ?? $this->galeria($c2['conjunto']->refresh())[0]),
                PubImagem::query()->where('rascunho_id', $outro['rascunho']->id)->firstOrFail(),
                $quem
            );
            $this->fail('original de outro anúncio NÃO foi recusada');
        } catch (RegraViolada $e) {
            $this->assertSame(RevisaoDeImagemService::REGRA_ORIGINAL_DE_OUTRO_RASCUNHO, $e->regra);
        }
    }

    public function test_img08_recolocar_original_volta_para_pendente_e_sai_de_fora_da_publicacao(): void
    {
        $quem = User::factory()->create();
        $c = $this->cenario(2);
        [$classico, $premium] = $this->aprovarAsDuas($c, $quem);

        $principal = PubAprovacaoImagem::query()->findOrFail($this->galeria($c['conjunto'])[0]);
        $this->servico()->reprovar($principal, $quem, 'Cor diferente da original');
        $this->assertFalse($principal->refresh()->noConjuntoFinal());

        // ⚠️ As duas voltaram para a fila com a reprovação. Reaprovo **de propósito**: a
        // reabertura só REGISTRA quem estava `aprovado` (é o `if (o.status === 'approved')` do
        // `reopen()`, `:1149`), e sem reaprovar o registro do passo seguinte seria inobservável e
        // eu estaria medindo um evento que nunca sai.
        $classico = $this->aprovacao()->aprovar($classico->fresh(), $quem);
        $premium = $this->aprovacao()->aprovar($premium->fresh(), $quem);
        $versaoAntes = (int) $classico->fresh()->versao;

        // ⚠️ IMG-08 / IMG-02 — ela volta `pending` e sai de "Fora da publicação", no FIM.
        $recolocada = $this->servico()->recolocarOriginal($principal->refresh(), $quem);

        $this->assertSame(PubAprovacaoImagem::PENDENTE, $recolocada->estado, 'recolocar trouxe a foto já aprovada — quem a traz de volta está tomando decisão NOVA');
        $this->assertFalse((bool) $recolocada->fora);
        $this->assertTrue($recolocada->noConjuntoFinal());

        $galeria = $this->galeria($c['conjunto']->refresh());
        $this->assertCount(2, $galeria);
        $this->assertSame((int) $recolocada->id, $galeria[1], 'a recolocada tem de entrar no FIM da galeria (`addOrig`, `:1156`)');

        // E reabriu de novo, nas duas.
        foreach ([$classico, $premium] as $oferta) {
            $this->assertSame($versaoAntes + 1, (int) $oferta->fresh()->versao, 'recolocar não reabriu a aprovação');
        }
        $this->assertNotEmpty(array_filter($this->eventos($classico->fresh()), fn (string $t) => str_starts_with($t, 'Aprovação reaberta: foto adicionada')));
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // IMG-07 — ORDEM E PRINCIPAL
    // ═════════════════════════════════════════════════════════════════════════════════════════

    public function test_img07_reordenar_e_definir_principal_mantem_a_galeria_consistente(): void
    {
        $quem = User::factory()->create();
        $c = $this->cenario(3);
        [$classico] = $this->aprovarAsDuas($c, $quem);

        $g = $this->galeria($c['conjunto']);
        [$a, $b, $d] = $g;

        // (1) reordenar: a ordem nova vale, as posições ficam `0..n-1` e a capa segue o topo.
        $this->servico()->reordenar($c['conjunto'], [$d, $a, $b], $quem);

        $this->assertSame([$d, $a, $b], $this->galeria($c['conjunto']->refresh()));
        $this->assertSame([0, 1, 2], PubAprovacaoImagem::query()->whereIn('id', [$d, $a, $b])->orderBy('posicao')->pluck('posicao')->map(fn ($p) => (int) $p)->all());
        $this->assertSame(
            (int) PubAprovacaoImagem::query()->findOrFail($d)->imagem_id,
            (int) $c['conjunto']->refresh()->principal_imagem_id,
            'a capa não acompanhou o topo da ordem'
        );

        // ⚠️ Reaprovo entre as duas ações **de propósito**: a reabertura só REGISTRA quem estava
        // `aprovado` (`:1149`), e sem isto o evento da ação seguinte seria inobservável.
        $classico = $this->aprovacao()->aprovar($classico->fresh(), $quem);
        $this->aprovacao()->aprovar($c['premium']->fresh(), $quem);

        // (2) definir principal: a escolhida vai para a posição 0 e as outras escorregam.
        $this->servico()->definirPrincipal(PubAprovacaoImagem::query()->findOrFail($b), $quem);

        $this->assertSame([$b, $d, $a], $this->galeria($c['conjunto']->refresh()));
        $this->assertTrue(PubAprovacaoImagem::query()->findOrFail($b)->ehPrincipal());
        $this->assertFalse(PubAprovacaoImagem::query()->findOrFail($d)->ehPrincipal());
        $this->assertSame(
            (int) PubAprovacaoImagem::query()->findOrFail($b)->imagem_id,
            (int) $c['conjunto']->refresh()->principal_imagem_id
        );

        // (3) ⚠️ ordem INCOMPLETA é recusada: `posicao` nula é "fora da publicação", então o
        // silêncio aqui **apagaria** uma foto da galeria.
        try {
            $this->servico()->reordenar($c['conjunto']->refresh(), [$b, $d], $quem);
            $this->fail('ordem com uma foto de menos NÃO foi recusada — ela sairia da publicação em silêncio');
        } catch (RegraViolada $e) {
            $this->assertSame(RevisaoDeImagemService::REGRA_ORDEM_INCOMPLETA, $e->regra);
        }

        // E id de fora também.
        $deFora = $this->imagem($c['conjunto'], PubAprovacaoImagem::REPROVADA, null);
        $deFora->update(['fora' => true]);
        try {
            $this->servico()->reordenar($c['conjunto']->refresh(), [$b, $d, $a, (int) $deFora->id], $quem);
            $this->fail('ordem com foto de FORA da publicação NÃO foi recusada');
        } catch (RegraViolada $e) {
            $this->assertSame(RevisaoDeImagemService::REGRA_ORDEM_INCOMPLETA, $e->regra);
        }

        // ⚠️ ISCA — a ordem CERTA passa (senão eu estaria provando que nada nunca reordena).
        $this->servico()->reordenar($c['conjunto']->refresh(), [$a, $b, $d], $quem);
        $this->assertSame([$a, $b, $d], $this->galeria($c['conjunto']->refresh()), 'ISCA CEGA: nem a ordem correta é aceita.');

        // As duas ações reabriram a aprovação.
        $this->assertSame(PubAprovacaoOferta::AGUARDANDO_APROVACAO, $classico->fresh()->andamento);
        $this->assertNotEmpty(array_filter($this->eventos($classico->fresh()), fn (string $t) => str_starts_with($t, 'Aprovação reaberta: ordem da galeria alterada')));
        $this->assertNotEmpty(array_filter($this->eventos($classico->fresh()), fn (string $t) => str_starts_with($t, 'Aprovação reaberta: foto principal alterada')));
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // IMG-09 — AS QUATRO RECUSAS EM `regenerating`
    // ═════════════════════════════════════════════════════════════════════════════════════════

    public function test_img09_em_regenerando_as_quatro_acoes_sao_recusadas(): void
    {
        $quem = User::factory()->create();
        $c = $this->cenario(2);

        $galeriaAntes = $this->galeria($c['conjunto']);
        $img = PubAprovacaoImagem::query()->findOrFail($galeriaAntes[0]);
        $outraId = $galeriaAntes[1];

        $this->servico()->marcarRegenerando($img, $quem, 'IA alterou os pés');
        $this->assertSame(PubAprovacaoImagem::REGENERANDO, $img->refresh()->estado);
        $this->assertFalse($img->refresh()->aceitaAcao());

        // ⚠️ MEDIDO, e é regra do MODELO (plano 186-03, SOMENTE LEITURA aqui):
        // `NO_CONJUNTO_FINAL` são **só** `pending` e `approved`, então a imagem em `regenerating`
        // **SAI do conjunto final** enquanto é refeita. ➡️ A galeria cai de 2 para 1 — e o
        // **slot dela é MANTIDO** (`posicao` não é zerada), que é o que faz a nova versão voltar
        // ao MESMO lugar. Recompactar aqui perderia a posição e a foto voltaria no fim.
        $this->assertCount(1, $this->galeria($c['conjunto']->refresh()), 'imagem em `regenerating` continuou no conjunto final — `NO_CONJUNTO_FINAL` são só `pending` e `approved`');
        $this->assertFalse($img->refresh()->noConjuntoFinal());
        $this->assertSame(0, (int) $img->refresh()->posicao, 'o slot da imagem em regeneração foi perdido — a nova versão voltaria no fim da galeria');

        $acoes = [
            'aprovar' => fn () => $this->servico()->aprovar($img->refresh(), $quem),
            'reprovar' => fn () => $this->servico()->reprovar($img->refresh(), $quem, 'motivo suficiente'),
            'substituir' => fn () => $this->servico()->substituirPorOriginal(
                $img->refresh(),
                PubImagem::create(['rascunho_id' => $c['rascunho']->id, 'caminho' => 'fotos/x.jpg']),
                $quem
            ),
            'regenerar de novo' => fn () => $this->servico()->marcarRegenerando($img->refresh(), $quem),
        ];

        $this->assertCount(4, $acoes, 'as QUATRO recusas de IMG-09 — uma a menos é uma porta aberta para aprovar a foto errada');

        foreach ($acoes as $nome => $acao) {
            try {
                $acao();
                $this->fail("`{$nome}` em imagem `regenerating` NÃO foi recusado — aprovaria/mexeria na foto que vai ser substituída em segundos");
            } catch (RegraViolada $e) {
                $this->assertSame(RevisaoDeImagemService::REGRA_REGENERANDO, $e->regra, "a recusa de `{$nome}` veio com outro código");
            }
        }

        // ⚠️ E nada mudou na foto nem na galeria com as quatro recusas.
        $this->assertSame(PubAprovacaoImagem::REGENERANDO, $img->refresh()->estado);
        $this->assertSame([$outraId], $this->galeria($c['conjunto']->refresh()), 'uma das recusas mexeu na galeria apesar de ter sido recusada');

        // ⚠️ ISCA — fora de `regenerating`, as quatro passam a ser possíveis. Prova com a mais
        // restritiva (aprovar), senão eu estaria provando que tudo sempre falha.
        $outra = PubAprovacaoImagem::query()->findOrFail($outraId);
        $this->assertTrue($outra->aceitaAcao());
        $this->assertSame(PubAprovacaoImagem::APROVADA, $this->servico()->aprovar($outra, $quem)->estado, 'ISCA CEGA: a guarda de `regenerating` está recusando foto que não está regenerando.');
    }

    public function test_img02_a_regeneracao_traz_a_nova_como_pendente_na_mesma_posicao(): void
    {
        $quem = User::factory()->create();
        $c = $this->cenario(2);
        [$classico, $premium] = $this->aprovarAsDuas($c, $quem);

        $antiga = PubAprovacaoImagem::query()->findOrFail($this->galeria($c['conjunto'])[0]);
        $posicao = (int) $antiga->posicao;
        $this->servico()->marcarRegenerando($antiga, $quem, 'Pés diferentes da original');

        // ⚠️ `regenerating` **não** reabre: a foto antiga continua no lugar até a nova chegar.
        $this->assertSame(1, (int) $classico->fresh()->versao, 'levar a foto para regeneração já reabriu — a galeria final não mudou ainda');

        $nova = PubImagem::create(['rascunho_id' => $c['rascunho']->id, 'caminho' => 'fotos/regenerada.jpg']);
        $entrante = $this->servico()->concluirRegeneracao($antiga->refresh(), $nova, $quem);

        // IMG-02 — a nova entra `pending`, na MESMA posição, marcada como nova e como de IA.
        $this->assertSame(PubAprovacaoImagem::PENDENTE, $entrante->estado, 'imagem regenerada entrou aprovada — ela nunca foi conferida');
        $this->assertSame($posicao, (int) $entrante->posicao);
        $this->assertTrue((bool) $entrante->nova);
        $this->assertTrue($entrante->ehDeIa());
        $this->assertSame(PubAprovacaoImagem::SUBSTITUIDA, $antiga->refresh()->estado);
        $this->assertTrue((bool) $antiga->refresh()->fora);

        // ⚠️ E agora REABRE — as duas ofertas.
        foreach ([$classico, $premium] as $oferta) {
            $this->assertSame(PubAprovacaoOferta::AGUARDANDO_APROVACAO, $oferta->fresh()->andamento);
            $this->assertSame(2, (int) $oferta->fresh()->versao);
            $this->assertNotEmpty(array_filter($this->eventos($oferta->fresh()), fn (string $t) => str_starts_with($t, 'Aprovação reaberta: imagem regenerada')));
        }

        // ⚠️ A chamada ao Creative Engine é da **Fase 192**: nada de IA sai daqui.
        $codigo = (string) file_get_contents(base_path('app/Services/Publicador/Aprovacao/RevisaoDeImagemService.php'));
        $this->assertStringContainsString('Fase 192', $codigo, 'a fronteira com a Fase 192 deixou de estar declarada');
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // ⚠️⚠️ IMG-06 — AS SEIS AÇÕES REABREM TODAS AS OFERTAS DO CONJUNTO
    // ═════════════════════════════════════════════════════════════════════════════════════════

    public function test_img06_as_seis_acoes_reabrem_a_aprovacao_de_todas_as_ofertas_do_conjunto(): void
    {
        $quem = User::factory()->create();

        $acoes = [
            AprovacaoDaOfertaService::FOTO_REPROVADA => function (array $c, User $quem): void {
                $this->servico()->reprovar(PubAprovacaoImagem::query()->findOrFail($this->galeria($c['conjunto'])[0]), $quem, 'Cor diferente da original');
            },
            AprovacaoDaOfertaService::FOTO_SUBSTITUIDA => function (array $c, User $quem): void {
                $crua = PubImagem::create(['rascunho_id' => $c['rascunho']->id, 'caminho' => 'fotos/sub.jpg']);
                $this->servico()->substituirPorOriginal(PubAprovacaoImagem::query()->findOrFail($this->galeria($c['conjunto'])[0]), $crua, $quem);
            },
            AprovacaoDaOfertaService::FOTO_REGENERADA => function (array $c, User $quem): void {
                $antiga = PubAprovacaoImagem::query()->findOrFail($this->galeria($c['conjunto'])[0]);
                $this->servico()->marcarRegenerando($antiga, $quem);
                $nova = PubImagem::create(['rascunho_id' => $c['rascunho']->id, 'caminho' => 'fotos/reg.jpg']);
                $this->servico()->concluirRegeneracao($antiga->refresh(), $nova, $quem);
            },
            AprovacaoDaOfertaService::FOTO_ADICIONADA => function (array $c, User $quem): void {
                $fora = $this->imagem($c['conjunto'], PubAprovacaoImagem::REPROVADA, null);
                $fora->update(['fora' => true]);
                $this->servico()->recolocarOriginal($fora->refresh(), $quem);
            },
            AprovacaoDaOfertaService::FOTOS_REORDENADAS => function (array $c, User $quem): void {
                $g = $this->galeria($c['conjunto']);
                $this->servico()->reordenar($c['conjunto'], array_reverse($g), $quem);
            },
            AprovacaoDaOfertaService::PRINCIPAL_ALTERADA => function (array $c, User $quem): void {
                $g = $this->galeria($c['conjunto']);
                $this->servico()->definirPrincipal(PubAprovacaoImagem::query()->findOrFail($g[1]), $quem);
            },
        ];

        // ⚠️ A COBERTURA: as SEIS de foto, que é o que IMG-06 lista.
        $this->assertSame(AprovacaoDaOfertaService::MUDANCAS_DE_FOTO, array_keys($acoes), 'o laço deixou de cobrir as 6 ações de foto de IMG-06');

        foreach ($acoes as $mudanca => $agir) {
            $c = $this->cenario(3);
            [$classico, $premium] = $this->aprovarAsDuas($c, $quem);
            $this->assertSame(PubAprovacaoOferta::APROVADO, $classico->andamento);
            $this->assertSame(PubAprovacaoOferta::APROVADO, $premium->andamento);

            $agir($c, $quem);

            foreach (['Clássico' => $classico, 'Premium' => $premium] as $nome => $oferta) {
                $depois = $oferta->fresh();
                $this->assertSame(PubAprovacaoOferta::AGUARDANDO_APROVACAO, $depois->andamento, "`{$mudanca}` não reabriu o {$nome} — a foto é compartilhada por ESTRUTURA, e aprovar já publica");
                $this->assertTrue($depois->ehLive(), "`{$mudanca}`: o {$nome} tem de voltar a ser EDITÁVEL");
                $this->assertTrue($depois->aprovacaoReaberta(), "`{$mudanca}`: `aprovacaoReaberta()` não acusou no {$nome}");
                $this->assertNotEmpty(
                    array_filter($this->eventos($depois), fn (string $t) => str_starts_with($t, AprovacaoDaOfertaService::REABERTA_PREFIXO)),
                    "`{$mudanca}`: o histórico do {$nome} não explica por que ele saiu de Aprovado"
                );
            }
        }
    }

    public function test_a_reabertura_mora_num_lugar_so_e_este_servico_a_delega(): void
    {
        // ⚠️⚠️ A PERGUNTA: o que precisaria estar errado no mundo para este gate falhar?
        //   **Alguém reimplementar a reabertura aqui.** As duas cópias ficariam livres para
        //   divergir, e a que divergisse deixaria uma oferta **aprovada com a foto trocada** — e
        //   aprovar já publica, sem desfazer.
        $codigo = $this->semComentarios((string) file_get_contents(base_path('app/Services/Publicador/Aprovacao/RevisaoDeImagemService.php')));

        // (a) este serviço **não** incrementa `versao` e **não** chama a única porta.
        $this->assertSame(0, preg_match_all("/increment\s*\(\s*'versao'/", $codigo), 'a revisão de imagem incrementa a `versao` por conta própria — a propagação tem de morar num lugar só');
        $this->assertSame(0, preg_match_all('/->mover\s*\(/', $codigo), 'a revisão de imagem move o andamento direto — a transição é do `AprovacaoDaOfertaService`, que é quem reabre');
        $this->assertSame(0, preg_match_all("/'andamento'\s*=>|->andamento\s*=[^=]/", $codigo), 'a revisão de imagem escreve `andamento` — a única porta é o `AndamentoDaOfertaService`');

        // (b) e ela DELEGA: a chamada a `registrarMudanca` existe, num lugar só.
        $this->assertSame(1, preg_match_all('/registrarMudanca\s*\(/', $codigo), 'a delegação da reabertura deixou de existir (ou foi espalhada em mais de um lugar)');

        // ⚠️ ISCAS das sondas — cada uma pega a forma que ela existe para pegar.
        $this->assertSame(1, preg_match_all("/increment\s*\(\s*'versao'/", "\$o->increment('versao');"), 'ISCA CEGA: a sonda do incremento não acha nem a amostra.');
        $this->assertSame(1, preg_match_all('/->mover\s*\(/', '$this->andamento->mover($o, $d);'), 'ISCA CEGA: a sonda do `mover` não acha nem a amostra.');
        $this->assertSame(1, preg_match_all('/registrarMudanca\s*\(/', '$this->aprovacao->registrarMudanca($o, $m);'), 'ISCA CEGA: a sonda da delegação não acha nem a amostra.');

        // (c) ⚠️ E O OUTRO LADO: quem incrementa e reabre é o `AprovacaoDaOfertaService`, e lá o
        // incremento e a transição de volta existem **de verdade** — senão o item (a) estaria
        // vazio por construção.
        $daAprovacao = $this->semComentarios((string) file_get_contents(base_path('app/Services/Publicador/Aprovacao/AprovacaoDaOfertaService.php')));
        $this->assertSame(1, preg_match_all("/increment\s*\(\s*'versao'\s*\)/", $daAprovacao), 'o incremento da versão deixou de existir no serviço que deveria tê-lo — o gate acima ficaria vazio');
        $this->assertStringContainsString('PubAprovacaoOferta::AGUARDANDO_APROVACAO', $daAprovacao);
    }

    /** O CÓDIGO sem comentários — os docblocks deste plano falam de `andamento` e `mover` o tempo todo. */
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
}
