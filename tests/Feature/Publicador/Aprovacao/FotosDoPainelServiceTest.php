<?php

namespace Tests\Feature\Publicador\Aprovacao;

use App\Models\Company;
use App\Models\PubAprovacaoConjunto;
use App\Models\PubAprovacaoImagem;
use App\Models\PubAprovacaoImagemAlerta;
use App\Models\PubAprovacaoOferta;
use App\Models\PubImagem;
use App\Models\PubProduto;
use App\Models\PubRascunho;
use App\Models\PubRascunhoAlvo;
use App\Services\Publicador\Aprovacao\BloqueiosDaOfertaService;
use App\Services\Publicador\Aprovacao\Fila\GaleriaDaOfertaService;
use App\Services\Publicador\Aprovacao\Painel\FotosDoPainelService;
use App\Support\Publicador\Variacao\ChaveCanonica;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 * Fase 188, plano 03 — O **SERVIDOR DA COLUNA DE FOTOS** do painel de revisão simples (E2).
 *
 * ⚠️⚠️ DE CADA GATE DESTE ARQUIVO, A PERGUNTA RESPONDIDA ANTES DE ELE EXISTIR:
 * **"o que precisaria estar errado no mundo para ele falhar?"** — gate cuja resposta é "nada" é
 * decorativo e não entra (`feedback_gate_vazio_por_construcao`).
 *
 *   · **O PROGRESSO RECONTADO AQUI** — `contagemDeFotos()` cobre **TODOS os grupos do rascunho**
 *     (decisão do usuário, 14/10/2026: aprovação cor por cor foi RECUSADA). Uma segunda contagem
 *     apareceria na tela como *"5 de 5 aprovadas"* **com um bloqueio de fotos de pé** — o pior tipo
 *     de bug de dado, porque **parece completo**. Há espião provando que o serviço **CHAMA**.
 *   · **AS TRÊS REGRAS UNIFICADAS** — marcador em **DOIS** estados (`:1109`), badge `!` em **UM**
 *     (`:1105`), borda em **CINCO** ramos (`:1113`). Unificar duas delas passaria em qualquer
 *     fixture pobre; aqui cada uma tem caso próprio **e** há um caso de CONFLITO.
 *   · **`estado_rotulo` VAZIO** — guarda que devolve `null` produz **elemento vazio**, que passa em
 *     render e **DESAPARECE na tela**. Foi exatamente o conserto do anel de progresso na 187-08.
 *   · **A CONTAGEM DE "sem alerta" DIVERGINDO DA AÇÃO** — a pessoa clica *"Aprovar 4"* e 2 são
 *     aprovadas. O usuário recusou esse sintoma duas vezes (*"conta e lista TÊM de bater"*), e aqui
 *     a igualdade é medida contra o retorno REAL de `aprovarSemAlerta()`, na mesma fixture.
 *   · **A URL DA FOTO COMO STRING VAZIA** — `<img src="">` faz o navegador **recarregar a própria
 *     página**. O caso `caminho === null` é real (`PubImagem:10`) e sai **`null`**.
 *   · **DUAS NUMERAÇÕES** — se o `ordinal` do texto e o `n` do marcador saíssem de cálculos
 *     diferentes, o texto diria *"ponto 2"* com o marcador 2 em **outro defeito**, e a pessoa
 *     **reprovaria a foto errada**.
 *   · **O EFEITO DO `EditorEmUso` FICANDO ESCONDIDO** — carregar miniatura **suspende o
 *     agendamento** do produto por 3 minutos. É mudança de comportamento que ninguém pediu, e quem
 *     ver o agendamento parado daqui a dois meses tem de achar a causa **pelo `grep`**.
 *
 * ⚠️⚠️ **ESTE ARQUIVO TEM UM GATE QUE LÊ COMENTÁRIO DE PROPÓSITO, E A INVERSÃO É DECLARADA.**
 * Em quase todo gate desta fase o código é lido **SEM comentário** (`188-REGUA.md` §6, regra 2),
 * porque palavra no docblock **reprova pelo próprio conserto**. ⚠️ **No caso
 * `test_a_galeria_final_ordenada_traz_url_do_arquivo_nos_DOIS_casos_188_03` o DOCBLOCK É O ARTEFATO
 * SOB TESTE**: a razão está acima — a causa do agendamento parado tem de ser achável por `grep`,
 * não por bisseção. ➡️ **Não "conserte" aquele bloco para ler sem comentário:** o gate passaria a
 * medir NADA.
 *
 * ⚠️ **ZERO REDE:** `Http::preventStrayRequests()` + `Http::fake` com gravador, e há asserção de
 * que a coluna de fotos **não faz requisição nenhuma** — o painel APROVA, não publica (a escrita no
 * Mercado Livre é da **Fase 193**).
 *
 * ⚠️ **ESTE ARQUIVO NÃO ALTERA NADA DAS FASES 186 E 187.** `BloqueiosDaOfertaService`,
 * `GaleriaDaOfertaService`, `RevisaoDeImagemService`, `PubAprovacaoImagem`, `PubAprovacaoConjunto` e
 * `PubAprovacaoImagemAlerta` são **LIDOS e CHAMADOS**; o espião de custo é **SUBCLASSE no teste**,
 * nunca alteração no disco.
 *
 * ⚠️⚠️ **NENHUM EXECUTOR DESTA FASE VIU ESTA TELA RENDERIZADA.** O banco local não tem empresa com
 * `ml_token` (a rota dá **404** aqui), `pub_aprovacao_ofertas` está **vazia** e a fila nasce vazia
 * até a Fase 194. A conferência é do usuário, em produção — e a **conferência visual da Fase 187
 * continua ABERTA**, nada daqui a substitui.
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 */
class FotosDoPainelServiceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * O commit de PARTIDA desta wave — o HEAD no começo DESTE plano, capturado na primeira ação.
     *
     * ⚠️ **REFERÊNCIA DE LEITURA, nunca extremo de range confiável:** a 187-11 mediu 111 commits no
     * range, **43 da sessão paralela**, e o diff cru acusava **12 dos 60 alvos vetados**. ➡️ O que
     * discrimina território é o **ESCOPO DO ASSUNTO**, abaixo.
     */
    private const PARTIDA = 'f931e5d9';

    /**
     * O ESCOPO DO ASSUNTO deste plano.
     *
     * ⚠️ **O autor NÃO discrimina nada nesta árvore:** as três máquinas rodam com o MESMO
     * `user.name` (`MB.ECF-100376`), então `%an` é vazio por construção.
     */
    private const ESCOPO = '/\(188-03\)/';

    private const CLASSICO = 'gold_special';

    private const PREMIUM = 'gold_pro';

    /** O caminho de arquivo das fotos da fixture — o caso em que a rota serve BYTES. */
    private const CAMINHO = 'publicador/rascunhos/1/foto.jpg';

    private int $sequencia = 0;

    /** @var list<array{caminho: string, query: array}> */
    private array $chamadas = [];

    protected function setUp(): void
    {
        parent::setUp();

        // ⚠️ `Carbon::setTestNow()` com o agora PARADO: as duas falhas herdadas desta suíte
        // (`tests/Feature/Publicador/CockpitDaContaTest.php:966` e `:1318`, Fase 179) são
        // fronteira de dia, e nenhum caso novo desta fase vai repetir o mesmo erro.
        Carbon::setTestNow('2026-10-19 09:00:00');

        // ⚠️ O app token do `ClienteMlPublicador` é FINGIDO no cache. ⚠️ E nunca `Cache::flush()`.
        Cache::put('ml_app_token_coleta', 'fake-app-token', 3600);

        Http::preventStrayRequests();
        Http::fake(function ($req) {
            $caminho = (string) parse_url($req->url(), PHP_URL_PATH);
            parse_str((string) parse_url($req->url(), PHP_URL_QUERY), $query);
            $this->chamadas[] = ['caminho' => $caminho, 'query' => $query];

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

    /**
     * Uma oferta com produto, rascunho e alvo.
     *
     * ⚠️ `pub_produtos.sku` é **NOT NULL** no schema: fixture sem SKU estoura na inserção.
     *
     * @param  array{tipo?: string, rascunho?: PubRascunho, produto?: PubProduto}  $o
     */
    private function oferta(Company $empresa, string $andamento, array $o = []): PubAprovacaoOferta
    {
        $this->sequencia++;

        $produto = $o['produto'] ?? PubProduto::create([
            'company_id' => $empresa->id,
            'sku' => 'FOT-'.$this->sequencia,
            'nome' => 'Poltrona Beny',
            'origem' => PubProduto::ORIGEM_PUBLICADOR,
            'quantidade_kit' => 1,
        ]);

        $rascunho = $o['rascunho'] ?? PubRascunho::create([
            'produto_id' => $produto->id,
            'categoria_id' => 'MLB189007',
        ]);

        $tipo = $o['tipo'] ?? self::CLASSICO;

        $alvo = PubRascunhoAlvo::create([
            'rascunho_id' => $rascunho->id,
            'listing_type_id' => $tipo,
            'titulo' => 'Poltrona Beny Decorativa Veludo',
            'posicao' => $tipo === self::CLASSICO ? 0 : 1,
        ]);

        $oferta = PubAprovacaoOferta::create([
            'alvo_id' => $alvo->id,
            'rascunho_id' => $rascunho->id,
            'produto_id' => $produto->id,
            'company_id' => $empresa->id,
            'andamento' => $andamento,
            'entrou_na_fila_em' => '2026-10-19 06:00:00',
        ]);

        return $oferta->fresh(['alvo', 'produto']);
    }

    /** Um conjunto (grupo de fotos) do rascunho da oferta. */
    private function conjunto(PubAprovacaoOferta $oferta, string $grupo = PubAprovacaoConjunto::GERAL): PubAprovacaoConjunto
    {
        return PubAprovacaoConjunto::create([
            'rascunho_id' => $oferta->rascunho_id,
            'grupo_chave' => $grupo,
            'grupo_hash' => ChaveCanonica::hash($grupo.'-'.$oferta->rascunho_id),
        ]);
    }

    /**
     * Uma linha de revisão de imagem, com a `pub_imagens` crua e os alertas.
     *
     * @param  array{estado?: string, tipo?: string, fora?: bool, caminho?: ?string,
     *   alertas?: list<array{0: string, 1?: ?float, 2?: ?float, 3?: string}>}  $o
     */
    private function imagem(PubAprovacaoConjunto $conjunto, ?int $posicao, array $o = []): PubAprovacaoImagem
    {
        $crua = PubImagem::create([
            'rascunho_id' => $conjunto->rascunho_id,
            'caminho' => array_key_exists('caminho', $o) ? $o['caminho'] : self::CAMINHO,
        ]);

        $imagem = PubAprovacaoImagem::create([
            'conjunto_id' => $conjunto->id,
            'imagem_id' => $crua->id,
            'tipo' => $o['tipo'] ?? PubAprovacaoImagem::TIPO_ORIGINAL,
            'estado' => $o['estado'] ?? PubAprovacaoImagem::APROVADA,
            'posicao' => $posicao,
            'fora' => $o['fora'] ?? false,
        ]);

        foreach ($o['alertas'] ?? [] as $alerta) {
            // ⚠️ Flag DENTRO do laço.
            PubAprovacaoImagemAlerta::create([
                'aprovacao_imagem_id' => $imagem->id,
                'severidade' => $alerta[0],
                'origem' => PubAprovacaoImagemAlerta::ORIGEM_AUTOMATICA,
                'x' => $alerta[1] ?? null,
                'y' => $alerta[2] ?? null,
                'texto' => $alerta[3] ?? 'A quantidade de peças na foto não confere com a do kit.',
            ]);
        }

        return $imagem->fresh(['alertas']);
    }

    /**
     * ⚠️⚠️ **A FIXTURE QUE DISCRIMINA — SETE imagens cobrindo os CINCO estados.**
     *
     * Sem ela as regras deste plano **não se distinguem**: foi assim que as duas sobreviventes da
     * mutação do 187-06 escaparam (uma oferta só não distinguia nada).
     *
     *   · `#0` **aprovada**, de IA, **principal** (`posicao` 0) — a capa;
     *   · `#1` **pendente com DOIS alertas**: um `high` COM ponto e um `ok` SEM ponto;
     *   · `#2` **pendente SEM alerta** — a que "Aprovar N sem alerta" aprova;
     *   · `#3` **aprovada COM alerta `high`** — não tem marcador E não tem badge (regras diferentes);
     *   · `#4` **pendente com alerta `med` de x/y NULOS** — não vira marcador e não derruba a lista;
     *   · **reprovada** e **substituída**, as duas `fora` e sem posição — ⚠️ **elas NÃO entram na
     *     galeria final**, e é isso que prova que a lista é a galeria e não "todas as linhas";
     *   · **regenerando** com posição — também fora de `NO_CONJUNTO_FINAL`.
     *
     * @return array{oferta: PubAprovacaoOferta, conjunto: PubAprovacaoConjunto, imagens: array<string, PubAprovacaoImagem>}
     */
    private function cenarioDeSeteImagens(Company $empresa, string $andamento = PubAprovacaoOferta::AGUARDANDO_APROVACAO): array
    {
        $oferta = $this->oferta($empresa, $andamento);
        $conjunto = $this->conjunto($oferta);

        $imagens = [
            'capa' => $this->imagem($conjunto, 0, [
                'estado' => PubAprovacaoImagem::APROVADA,
                'tipo' => PubAprovacaoImagem::TIPO_IA,
            ]),
            'pendente_com_alerta' => $this->imagem($conjunto, 1, [
                'estado' => PubAprovacaoImagem::PENDENTE,
                'alertas' => [
                    [PubAprovacaoImagemAlerta::ALTO, 31.25, 68.5, 'A medida da foto não confere com a da descrição.'],
                    [PubAprovacaoImagemAlerta::OK, null, null, 'Fundo branco e sem moldura.'],
                ],
            ]),
            'pendente_limpa' => $this->imagem($conjunto, 2, ['estado' => PubAprovacaoImagem::PENDENTE]),
            'aprovada_com_alerta' => $this->imagem($conjunto, 3, [
                'estado' => PubAprovacaoImagem::APROVADA,
                'alertas' => [[PubAprovacaoImagemAlerta::ALTO, 10.0, 10.0]],
            ]),
            'pendente_sem_ponto' => $this->imagem($conjunto, 4, [
                'estado' => PubAprovacaoImagem::PENDENTE,
                'alertas' => [[PubAprovacaoImagemAlerta::MEDIO, null, null]],
            ]),
            'reprovada' => $this->imagem($conjunto, null, [
                'estado' => PubAprovacaoImagem::REPROVADA,
                'fora' => true,
                'alertas' => [[PubAprovacaoImagemAlerta::ALTO, 50.0, 50.0]],
            ]),
            'substituida' => $this->imagem($conjunto, null, [
                'estado' => PubAprovacaoImagem::SUBSTITUIDA,
                'fora' => true,
            ]),
            'regenerando' => $this->imagem($conjunto, 9, ['estado' => PubAprovacaoImagem::REGENERANDO]),
        ];

        return ['oferta' => $oferta->fresh(['alvo', 'produto']), 'conjunto' => $conjunto, 'imagens' => $imagens];
    }

    private function servico(): FotosDoPainelService
    {
        return app(FotosDoPainelService::class);
    }

    private function raiz(): string
    {
        return rtrim(str_replace('\\', '/', base_path()), '/');
    }

    private function git(string $comando): string
    {
        return trim((string) shell_exec('cd '.escapeshellarg($this->raiz()).' && '.$comando.' 2>&1'));
    }

    /** O arquivo CRU, **com** comentário — usado só pelo gate da PERGUNTA-188-C. */
    private function cru(string $relativo): string
    {
        $caminho = $this->raiz().'/'.$relativo;
        $this->assertFileExists($caminho, "arquivo inexistente: {$relativo} — o gate mediria NADA");

        return (string) file_get_contents($caminho);
    }

    /** O código PHP **sem comentário** — `token_get_all`, nunca regex. */
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
    // (1) O PROGRESSO, A COR E A NOTA DE CONJUNTO — tudo de UMA contagem só
    // ═════════════════════════════════════════════════════════════════════════════════════════

    /**
     * ⚠️⚠️ `contagemDeFotos()` é **CHAMADO**, nunca recontado — e o espião prova.
     *
     * Ele é a ÚNICA casa da definição de "galeria final", de "aprovada" e de "com alerta", e cobre
     * **TODOS os grupos do rascunho** (decisão do usuário, 14/10/2026 — aprovação cor por cor foi
     * RECUSADA porque aprovar já publica e não há desfazer).
     */
    public function test_o_progresso_e_a_nota_de_conjunto_saem_de_UMA_contagem_so_188_03(): void
    {
        $empresa = Company::factory()->create();
        $cenario = $this->cenarioDeSeteImagens($empresa);

        // ⚠️ O ESPIÃO é uma SUBCLASSE no teste: o serviço da 186 não é alterado no disco.
        $espiao = new class(app(\App\Services\Publicador\Aprovacao\ComposicaoFinanceiraService::class), app(\App\Services\Publicador\Aprovacao\AjustesDaOfertaService::class)) extends BloqueiosDaOfertaService
        {
            public int $chamadas = 0;

            public function contagemDeFotos(PubAprovacaoOferta $oferta): array
            {
                $this->chamadas++;

                return parent::contagemDeFotos($oferta);
            }
        };

        $servico = new FotosDoPainelService($espiao, app(GaleriaDaOfertaService::class));
        $fotos = $servico->para($cenario['oferta']);

        // ⚠️⚠️ **CHAMADO UMA VEZ**, e a ISCA prova que o espião conta de verdade.
        $this->assertSame(1, $espiao->chamadas, '`contagemDeFotos()` não foi chamado — o progresso foi RECONTADO');
        $espiao->contagemDeFotos($cenario['oferta']);
        $this->assertSame(2, $espiao->chamadas, 'ISCA: o espião não conta');

        // ─── O progresso, e ele BATE com a contagem da 186 ───────────────────────────────────
        $daContagem = app(BloqueiosDaOfertaService::class)->contagemDeFotos($cenario['oferta']);
        $this->assertSame(5, $daContagem['total'], 'a fixture não produziu a galeria de 5 — o gate mediria outra coisa');
        $this->assertSame('2 de 5 aprovadas', $fotos['progresso']);
        $this->assertSame($daContagem['total'], $fotos['total'], 'o total divergiu da contagem da 186');
        $this->assertSame($daContagem['aprovadas'], $fotos['aprovadas'], 'as aprovadas divergiram da contagem da 186');
        $this->assertSame($daContagem['pendentes'], $fotos['pendentes'], 'as pendentes divergiram da contagem da 186');
        $this->assertSame($daContagem['com_alerta'], $fotos['com_alerta'], 'o "com alerta" divergiu da contagem da 186');

        // ⚠️ Decisão 8 do usuário: a tela DIZ quem ficou de fora, e esse número sai da MESMA fonte.
        $this->assertSame($daContagem['com_alerta'], $fotos['com_alerta_restantes'], '`com_alerta_restantes` é uma SEGUNDA contagem');
        $this->assertSame(2, $fotos['com_alerta_restantes'], 'duas pendentes com alerta na fixture (ponto e sem ponto)');

        // ─── A COR do progresso (`:1303`), os TRÊS ramos ─────────────────────────────────────
        //
        // ⚠️ `inf.pend.length ? (inf.alerts.length ? '#F87171' : '#FBBF24') : '#4ADE80'`.
        $this->assertSame(FotosDoPainelService::COR_VERMELHA, $fotos['progresso_cor'], 'com pendente COM alerta a cor é vermelha');

        // âmbar: pendente, mas nenhuma com alerta
        $ambar = $this->oferta($empresa, PubAprovacaoOferta::AGUARDANDO_APROVACAO);
        $conjuntoAmbar = $this->conjunto($ambar);
        $this->imagem($conjuntoAmbar, 0, ['estado' => PubAprovacaoImagem::APROVADA]);
        $this->imagem($conjuntoAmbar, 1, ['estado' => PubAprovacaoImagem::PENDENTE]);
        $this->assertSame(FotosDoPainelService::COR_AMBAR, $this->servico()->para($ambar)['progresso_cor']);
        $this->assertSame('1 de 2 aprovadas', $this->servico()->para($ambar)['progresso']);

        // verde: zero pendentes
        $verde = $this->oferta($empresa, PubAprovacaoOferta::AGUARDANDO_APROVACAO);
        $this->imagem($this->conjunto($verde), 0, ['estado' => PubAprovacaoImagem::APROVADA]);
        $this->assertSame(FotosDoPainelService::COR_VERDE, $this->servico()->para($verde)['progresso_cor']);

        // ⚠️ ISCA DAS TRÊS CORES: elas são TRÊS valores distintos. Sem isto, um serviço que
        // devolvesse sempre a mesma string passaria nos três ramos acima.
        $this->assertCount(3, array_unique([
            FotosDoPainelService::COR_VERDE, FotosDoPainelService::COR_AMBAR, FotosDoPainelService::COR_VERMELHA,
        ]), 'as três cores do progresso colidiram');

        // ─── A NOTA DE CONJUNTO (`:1304`) — DADO-04 ──────────────────────────────────────────
        //
        // ⚠️ Com UMA oferta só no rascunho ela é `null`: nota que fala de "as duas ofertas" com uma
        // oferta só seria a tela afirmando o que não é.
        $this->assertNull($fotos['nota_de_conjunto'], 'nota de conjunto com UMA oferta só no rascunho');

        $premium = $this->oferta($empresa, PubAprovacaoOferta::AGUARDANDO_APROVACAO, [
            'tipo' => self::PREMIUM,
            'rascunho' => $cenario['oferta']->rascunho,
            'produto' => $cenario['oferta']->produto,
        ]);

        // ⚠️ O Clássico nomeia o PREMIUM, e o Premium nomeia o CLÁSSICO — a irmã, não ele mesmo.
        $this->assertSame(
            'Mesmas fotos de Premium · a aprovação vale para as duas ofertas',
            $this->servico()->para($cenario['oferta']->fresh(['alvo', 'produto']))['nota_de_conjunto'],
        );
        $this->assertSame(
            'Mesmas fotos de Clássico · a aprovação vale para as duas ofertas',
            $this->servico()->para($premium)['nota_de_conjunto'],
        );

        // ⚠️⚠️ **DADO-04 na prática:** as duas ofertas leem o MESMO conjunto, então o progresso é
        // IDÊNTICO — e isso é ESTRUTURAL (não existe coluna onde elas pudessem divergir).
        $this->assertSame(
            $this->servico()->para($cenario['oferta']->fresh(['alvo', 'produto']))['progresso'],
            $this->servico()->para($premium)['progresso'],
            'DADO-04: o Clássico e o Premium do mesmo rascunho divergiram no progresso',
        );

        // ⚠️⚠️ **O PAINEL NÃO PUBLICA** (critério 18; a escrita no ML é da Fase 193) — prova por
        // AUSÊNCIA de requisição, com ISCA do gravador nas duas direções.
        $this->assertSame([], $this->chamadas, 'a coluna de fotos fez requisição ao Mercado Livre');
        Http::get('https://api.mercadolibre.com/items/MLB1');
        $this->assertNotSame([], $this->chamadas, 'ISCA: o gravador não registra nada — a prova por ausência é vazia');
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // (2) A GALERIA ORDENADA, A URL DO ARQUIVO E O EFEITO DECLARADO DO `EditorEmUso`
    // ═════════════════════════════════════════════════════════════════════════════════════════

    /**
     * ⚠️⚠️ **ESTE CASO TEM DOIS GATES, E UM DELES LÊ COMENTÁRIO DE PROPÓSITO.**
     *
     * **(a) A galeria e a URL** — lidos do payload.
     * **(b) A PERGUNTA-188-C** — o docblock do serviço tem de **NOMEAR** o efeito colateral do
     * reuso da rota: os **3 minutos** (`config/publicador.php:148`), **o ponto de gravação**
     * (`MlbPublicadorController:405`) e os **CINCO leitores** do sinal.
     *
     * ⚠️ **POR QUE OS CINCO NOMES, E NÃO "vários consumidores":** a próxima pessoa que vir **o
     * agendamento parado** tem de achar a causa **pelo `grep`, não por bisseção**. Um docblock que
     * diga *"isto afeta o agendamento"* não é achável; um que diga `AgendadorDaFila:337` é.
     * ⚠️⚠️ **A INVERSÃO É DECLARADA:** aqui o **docblock É o artefato sob teste**, então o gate lê o
     * arquivo **CRU**. Em todo o resto desta fase o código é lido **SEM** comentário. **Não
     * "conserte" este bloco para `semComentarios()`** — ele passaria a medir NADA.
     */
    public function test_a_galeria_final_ordenada_traz_url_do_arquivo_nos_DOIS_casos_188_03(): void
    {
        $empresa = Company::factory()->create();
        $cenario = $this->cenarioDeSeteImagens($empresa);
        $fotos = $this->servico()->para($cenario['oferta']);

        // ─── (a1) A LISTA É A GALERIA FINAL — as três de fora NÃO entram ─────────────────────
        //
        // ⚠️ `noConjuntoFinal()` exige as TRÊS condições (estado vivo, `posicao` não nula, `fora`
        // falso). Sem as linhas reprovada/substituída/regenerando na fixture, "a lista é a galeria"
        // não teria como ser provado — seria gate sobre zero casos.
        $this->assertCount(5, $fotos['imagens'], 'a lista deixou de ser a galeria final');
        $this->assertSame(
            [
                $cenario['imagens']['capa']->id,
                $cenario['imagens']['pendente_com_alerta']->id,
                $cenario['imagens']['pendente_limpa']->id,
                $cenario['imagens']['aprovada_com_alerta']->id,
                $cenario['imagens']['pendente_sem_ponto']->id,
            ],
            array_column($fotos['imagens'], 'id'),
            'a ordem da galeria mudou — ela é `GENERAL` primeiro, depois `posicao` e `id`',
        );

        $foraDaGaleria = [
            $cenario['imagens']['reprovada']->id,
            $cenario['imagens']['substituida']->id,
            $cenario['imagens']['regenerando']->id,
        ];
        $this->assertSame(
            [],
            array_values(array_intersect($foraDaGaleria, array_column($fotos['imagens'], 'id'))),
            'imagem fora de `NO_CONJUNTO_FINAL` entrou na galeria',
        );
        // ⚠️ ISCA: as três EXISTEM no banco. Sem isto, "nenhuma entrou" seria indistinguível de
        // "a fixture não criou nenhuma".
        $this->assertSame(3, PubAprovacaoImagem::query()->whereIn('id', $foraDaGaleria)->count(), 'ISCA: as três linhas de fora não existem');

        // ─── (a2) O contrato de CADA entrada, com `array_key_exists` ─────────────────────────
        //
        // ⚠️ `array_key_exists`, nunca `??`: **o `??` não distingue NULO de AUSENTE**, e era
        // exatamente essa distinção que um gate da wave 2 media (e mentia).
        $contrato = ['id', 'imagem_id', 'posicao', 'posicao_rotulo', 'rotulo', 'titulo', 'de_ia',
            'tipo_rotulo', 'principal', 'grupo', 'grupo_geral', 'estado', 'estado_rotulo', 'estado_cor',
            'estado_dot', 'com_alerta', 'aprovada', 'aceita_acao', 'borda', 'anel', 'url_arquivo',
            'marcadores', 'verificacoes'];
        $ausentes = [];
        foreach ($fotos['imagens'] as $i => $imagem) {
            // ⚠️ Flag DENTRO do laço.
            foreach ($contrato as $chave) {
                if (! array_key_exists($chave, $imagem)) {
                    $ausentes[] = $i.'.'.$chave;
                }
            }
        }
        $this->assertSame([], $ausentes, 'chave do contrato AUSENTE numa entrada da galeria');

        $capa = $fotos['imagens'][0];
        $this->assertSame(1, $capa['posicao'], 'a `posicao` da coluna é 1-based');
        $this->assertSame('#1', $capa['posicao_rotulo']);
        $this->assertSame(GaleriaDaOfertaService::ROTULO_CAPA, $capa['rotulo'], 'o rótulo é LIDO da galeria da 187');
        $this->assertTrue($capa['principal'], 'IMG-07: a principal é a `posicao` 0');
        $this->assertTrue($capa['de_ia']);
        $this->assertSame(FotosDoPainelService::TIPO_IA_ROTULO, $capa['tipo_rotulo']);
        $this->assertFalse($fotos['imagens'][1]['principal'], 'só a `posicao` 0 é principal');
        $this->assertSame(FotosDoPainelService::TIPO_ORIGINAL_ROTULO, $fotos['imagens'][1]['tipo_rotulo']);
        $this->assertTrue($capa['grupo_geral'], 'o grupo da fixture é o `GENERAL`');
        $this->assertSame(PubAprovacaoConjunto::GERAL, $capa['grupo']);
        // ⚠️ O `titulo` vem da 187 — forma `<rótulo> · <veredicto>`, NUNCA recopiada aqui.
        $this->assertSame(
            app(GaleriaDaOfertaService::class)->tituloDoQuadrado(GaleriaDaOfertaService::ROTULO_CAPA, PubAprovacaoImagem::APROVADA, false),
            $capa['titulo'],
            'o `titulo` foi remontado em vez de LIDO da 187',
        );

        // ─── (a3) ⚠️⚠️ B1.2 — A URL, nos DOIS casos ─────────────────────────────────────────
        $esperada = route('mlb.anuncios.publicador.fotos.arquivo', [
            'produto' => $cenario['oferta']->produto_id,
            'imagem' => $cenario['imagens']['capa']->imagem_id,
        ]);
        $this->assertSame($esperada, $capa['url_arquivo'], 'a URL não é a da rota que já existe');
        $this->assertStringContainsString('/fotos/'.$cenario['imagens']['capa']->imagem_id.'/arquivo', (string) $capa['url_arquivo']);
        $this->assertSame((int) $cenario['imagens']['capa']->imagem_id, $capa['imagem_id'], '`imagem_id` é o da `pub_imagens`');

        // ⚠️ `caminho` nulo (`PubImagem:10`: *"veio do Anunciar"*) → `arquivoFoto` faz `abort(404)`
        // (`:215`-`:217`). ➡️ `url_arquivo` é **`null`**, e a tela mostra o ESTADO declarado.
        $semArquivo = $this->oferta($empresa, PubAprovacaoOferta::AGUARDANDO_APROVACAO);
        $this->imagem($this->conjunto($semArquivo), 0, ['caminho' => null]);
        $nula = $this->servico()->para($semArquivo)['imagens'][0];
        $this->assertNull($nula['url_arquivo'], '`caminho` nulo tem de dar `url_arquivo` NULA');
        // ⚠️⚠️ **E NÃO É STRING VAZIA:** `<img src="">` faz o navegador recarregar a própria página.
        $this->assertNotSame('', $nula['url_arquivo'], 'string vazia num `src` recarrega a própria página');
        $this->assertFalse($nula['url_arquivo'] === '', 'a `url_arquivo` nula virou string vazia');
        // ⚠️ E o ESTADO continua lá: nunca painel vazio, nunca ícone quebrado (IMG-13).
        $this->assertNotSame('', $nula['estado_rotulo'], 'sem arquivo a tela precisa do estado declarado');

        // ─── (b) ⚠️⚠️ A PERGUNTA-188-C NO DOCBLOCK — lida do arquivo CRU, de propósito ───────
        $docblock = $this->cru('app/Services/Publicador/Aprovacao/Painel/FotosDoPainelService.php');

        // ⚠️ **LISTA CONSTANTE**, e **UMA** asserção coletando os ausentes — nunca uma por nome,
        // que faria a contagem de asserções crescer com a lista (learnings §4b.2).
        //
        // ⚠️⚠️ **ÂNCORA POR NOME, NUNCA POR NÚMERO DE LINHA — e eis o que estava aqui:**
        // `'config/publicador.php:148'`, `'MlbPublicadorController:405'`, `'AgendadorDaFila:337'`,
        // `'PreparoIaDoRascunhoService:131'`, `':180'`, `':366'`, `'GarantiaPadrao:192'` e
        // `'ExcluirProdutoService:195'` — OITO números de linha cobrados do docblock.
        //   · ⚠️ A INTENÇÃO estava certa (foi pedida no briefing da wave 188-03: *"quem vir o
        //     agendamento parado acha a causa por grep, não por bissecção"*); a ANCORAGEM é que
        //     estava errada.
        //   · Número de linha muda legitimamente em **toda** edição acima dele, em qualquer fase,
        //     para sempre. ➡️ O docblock seguiria tendo de ser reescrito, e o gate seguiria
        //     reprovando trabalho CORRETO até que alguém subisse o número — que é exatamente como
        //     se ensina o executor seguinte a ajustar réguas quando elas incomodam
        //     (`feedback_gate_que_reprova_pelo_proprio_conserto`).
        //
        // ➡️ **O INVARIANTE VERDADEIRO:** *o docblock NOMEIA o efeito do sinal de forma achável* —
        // o arquivo que grava, os cinco arquivos que leem, os TRÊS pontos do `PreparoIaDoRascunho`
        // (agora por DESCRIÇÃO: *"no segundo laço"*, *"na escrita do rascunho"*), a origem dos 3
        // minutos e a decisão datada. Nenhum desses fatos desloca quando alguém edita uma linha
        // acima deles.
        //
        // ⚠️ O docblock **continua citando** `:148`/`:405`/`:337`/`:131`/`:180`/`:366`/`:192`/`:195`
        // como DOCUMENTAÇÃO, e isso é bom para quem lê — `app/` não é território deste conserto.
        // **Este gate não cobre nenhum desses números**, de propósito: cobri-los é o defeito que
        // acabou de ser removido. (O `:131` do docblock, aliás, já está desatualizado — as três
        // chamadas estão em 132/181/367 desde o commit `cd582f83`, de outra sessão.)
        $deveNomear = [
            'config/publicador.php',                 // de onde vêm os 3 minutos (SEM a linha)
            'editor_em_uso_min',
            '3 minutos',
            'MlbPublicadorController',               // o arquivo onde o sinal é GRAVADO
            'EditorEmUso::marcar',
            'AgendadorDaFila',                       // os CINCO arquivos leitores, por NOME
            'EditorEmUso::emUso',
            'PreparoIaDoRascunhoService',
            'o preparo por IA do lote pula o produto', // e os TRÊS pontos dele, por DESCRIÇÃO
            'no segundo laço',
            'na escrita do rascunho',
            'GarantiaPadrao',
            'ExcluirProdutoService',
            '19/10/2026',                            // a decisão, com a data
            'RECUSADO',                              // e o que foi recusado
        ];
        $naoNomeados = array_values(array_filter(
            $deveNomear,
            static fn (string $alvo): bool => ! str_contains($docblock, $alvo),
        ));
        $this->assertSame([], $naoNomeados, 'PERGUNTA-188-C: o docblock não nomeia o efeito do `EditorEmUso` de forma ACHÁVEL');

        // ⚠️ ISCA DO RECORTE: a lista constante não pode ENCOLHER sem ninguém ver. Tirar um nome
        // daqui só AFROUXA o gate — o `assertSame([], $naoNomeados)` seguiria verde —, então é
        // esta contagem que faz da remoção uma linha morta visível.
        $this->assertCount(15, $deveNomear, 'a lista do que o docblock deve NOMEAR mudou de tamanho — remeça e escreva a razão');

        // ⚠️⚠️ **ISCA NAS DUAS DIREÇÕES, em memória** (nenhum arquivo do repo é tocado):
        //   · o docblock completo PASSA (é a asserção acima);
        //   · tirar UM dos nomes REPROVA — senão o gate seria "lista de nomes" sem prova.
        $mutilado = str_replace('AgendadorDaFila', 'o agendamento', $docblock);
        $this->assertNotSame($docblock, $mutilado, 'ISCA CEGA: o nome que a isca remove não estava no texto');
        $this->assertNotSame(
            [],
            array_values(array_filter($deveNomear, static fn (string $a): bool => ! str_contains($mutilado, $a))),
            'ISCA: tirar um dos CINCO leitores do docblock NÃO reprova — o gate mede nada',
        );

        // ─── (c) ⚠️⚠️ OS CINCO LEITORES EXISTEM NO DISCO E CHAMAM O SINAL — anti-fantasma ────
        //
        // O gate PERENE desta milestone (NOVE caminhos fantasmas, três vindos de briefing).
        //
        // ⚠️⚠️ **O QUE ESTAVA AQUI, E POR QUE SAIU — e não porque incomodou:** uma tabela
        // `arquivo => LINHA` (`AgendadorDaFila.php => 337`, `PreparoIaDoRascunhoService.php => 131`,
        // `GarantiaPadrao.php => 192`, `ExcluirProdutoService.php => 195`,
        // `MlbPublicadorController.php => 405`) que exigia `EditorEmUso::` **naquela linha exata**.
        //   · Ela REPROVOU em 20/10/2026, e **sozinha** (não é corrida de suíte): o commit
        //     `cd582f83`, de outra sessão, mexeu no `PreparoIaDoRascunhoService` **ACIMA** daqueles
        //     pontos e as três chamadas desceram para 132/181/367. O código estava CERTO; o gate,
        //     ancorado no número, morreu.
        //   · E o conserto barato — subir `131` para `132` — deixaria a mina armada para a próxima
        //     edição acima daquelas linhas, em qualquer fase, para sempre. É a família "gate
        //     ancorado em número que muda legitimamente", com DOZE consertos nesta milestone.
        //
        // ➡️ **A ÂNCORA AGORA É O NOME:** arquivo + **método envolvente** + **membro chamado**.
        // Nenhum número de linha entra neste gate. Mesma forma do reaponte do byte da wave 188-09
        // (`a874fc00`, que trocou a ausência GLOBAL pelo byte do PRÓPRIO elemento).
        // ⚠️ E é **mais forte** que a tabela de linhas, não só mais estável:
        //   · a tabela provava *"a linha 131 tem a string `EditorEmUso::`"* — passaria com a chamada
        //     num método COMPLETAMENTE diferente, desde que o deslocamento casasse por acidente;
        //   · a varredura por nome prova QUAL método chama e QUAL membro, nos dois sentidos
        //     (fantasma na lista **e** ponto novo no disco), e cobre os TRÊS pontos do
        //     `PreparoIaDoRascunhoService` — que a tabela só cobria em UM.
        //
        // ⚠️ A varredura é a lista CONSTANTE dos cinco arquivos, **nunca um `grep` em `app/`**:
        // varrer o diretório reprovaria leitor novo e legítimo de outra sessão — foi a 5ª ocorrência
        // de "gate mais largo que o invariante" na Fase 186, e aquela **barrou um deploy**.

        /**
         * Para cada ocorrência de `EditorEmUso::` na fonte **SEM comentário**, o `function` mais
         * próximo ACIMA dela e o membro chamado.
         *
         * ⚠️ SEM comentário porque prosa que explica a chamada casa com o gate que conta a chamada
         * (learnings §4, regra 2) — e a ISCA NEGATIVA abaixo prova que isso importa aqui.
         * ⚠️ `preg_match_all`, nunca `preg_match`: duas chamadas na MESMA linha devem contar duas.
         *
         * @return list<string> `metodo::membro`, na ordem do arquivo
         */
        $chamadasDoSinal = function (string $relativo): array {
            $linhas = explode("\n", str_replace("\r", '', $this->semComentarios($relativo)));
            $metodo = '(fora de metodo)';
            $achadas = [];
            foreach ($linhas as $linha) {
                if (preg_match('/\bfunction\s+([A-Za-z_][A-Za-z0-9_]*)\s*\(/', $linha, $m) === 1) {
                    $metodo = $m[1];
                }
                preg_match_all('/EditorEmUso::([A-Za-z_][A-Za-z0-9_]*)/', $linha, $todos);
                foreach ($todos[1] as $membro) {
                    $achadas[] = $metodo.'::'.$membro;
                }
            }

            return $achadas;
        };

        // ⚠️ MEDIDO por script nesta máquina em 20/10/2026, arquivo por arquivo — nunca estimado.
        // ⚠️ `excluirUm::chave` é o 8º ponto e **NÃO é leitura do sinal**: é o
        // `Cache::forget(EditorEmUso::chave($id))` que o APAGA ao excluir o produto. Está declarado
        // aqui para a varredura ser **COMPLETA** — recortar só o que confirma a tese é como um gate
        // passa pelo motivo errado —, e não porque o docblock tenha de nomeá-lo.
        $sinalPorArquivo = [
            'app/Services/Publicador/Fila/AgendadorDaFila.php' => ['checar::emUso'],
            // ⚠️⚠️ **MUDOU DE `emUso` PARA `editando` EM 23/10/2026, e este é o registro que o
            // próprio gate cobra** (a mensagem dele diz *"renomeado ou acrescentado **sem
            // documentar**"*). O gate acusou e está CERTO; o conserto é documentar, nunca afrouxar.
            //
            // ⚠️ **NÃO É RENAME DO MÉTODO.** `EditorEmUso` tem os DOIS, e são **sinais
            // DIFERENTES**: `marcar()`/`emUso()` usam `chave()`, e `marcarEdicao()`/`editando()`
            // usam `chaveDaEdicao()`. O remoto **ACRESCENTOU** o segundo sinal e trocou **estes
            // três pontos** de propósito — por isso as outras quatro linhas desta lista seguem em
            // `emUso` e **continuam legítimas**.
            //
            // ⚠️ Medido: `git show ed8fdc15:…` já tinha `editando` nos **três** pontos **antes** do
            // merge do E0 (`42c1bd66`) — a mudança é **deles**; a resolução preservou a escolha
            // deles e só acrescentou o gancho `agenteParado` do Agente no terceiro ponto.
            'app/Services/Publicador/PreparoIaDoRascunhoService.php' => ['preparar::editando', 'sincronizarAgora::editando', 'executarEtapa::editando'],
            'app/Support/Publicador/GarantiaPadrao.php' => ['aplicarNaConta::emUso'],
            'app/Services/Publicador/ExcluirProdutoService.php' => ['impedimento::emUso', 'excluirUm::chave'],
            'app/Http/Controllers/MlbPublicadorController.php' => ['produto::marcar'],
        ];
        $divergentes = [];
        foreach ($sinalPorArquivo as $arquivo => $esperadas) {
            // ⚠️ Flag DENTRO do laço.
            $obtidas = $chamadasDoSinal($arquivo);
            if ($obtidas !== $esperadas) {
                $divergentes[] = $arquivo.' => esperado ['.implode(', ', $esperadas).'], obtido ['.implode(', ', $obtidas).']';
            }
        }
        // ⚠️ UMA asserção coletando os infratores: a contagem deste gate não cresce com a lista.
        $this->assertSame([], $divergentes, 'leitor do sinal FANTASMA, renomeado ou acrescentado sem documentar (âncora por NOME, nunca por linha): '.implode(' | ', $divergentes));

        // ⚠️ ISCA DO RECORTE, nas duas dimensões: a lista não pode esvaziar nem encolher sem
        // ninguém ver — com ela vazia o laço acima não roda e o `assertSame([], ...)` passa por
        // construção.
        $this->assertCount(5, $sinalPorArquivo, 'a lista dos CINCO arquivos leitores mudou de tamanho — remeça por script e escreva a razão');
        $this->assertSame(8, array_sum(array_map('count', $sinalPorArquivo)), 'o total de pontos do sinal mudou — remeça por script e escreva a razão');

        // ⚠️⚠️ ISCA POSITIVA: a varredura ACHA os TRÊS pontos do `PreparoIaDoRascunhoService`,
        // cada um no SEU método. ➡️ **São estes três NOMES que substituem os `:131`/`:180`/`:366`
        // do docblock** — nome de método não desloca quando alguém edita acima dele.
        $tresPontos = $chamadasDoSinal('app/Services/Publicador/PreparoIaDoRascunhoService.php');
        $this->assertSame(
            ['preparar', 'sincronizarAgora', 'executarEtapa'],
            array_map(static fn (string $c): string => explode('::', $c)[0], $tresPontos),
            'os TRÊS pontos do PreparoIaDoRascunhoService deixaram de estar em `preparar`/`sincronizarAgora`/`executarEtapa` — se foi de propósito, o docblock da PERGUNTA-188-C precisa ser reescrito',
        );

        // ⚠️⚠️ ISCA NEGATIVA, E ELA MEDE O QUE IMPORTA: o **próprio** `FotosDoPainelService` cita
        // `EditorEmUso::marcar()` e `EditorEmUso::emUso()` em PROSA (`:65`, `:73`, `:595`) e **não
        // chama nenhum dos dois**. ➡️ Se a varredura lesse o arquivo CRU, ela acharia três
        // chamadas fantasmas aqui — então este `[]` é a prova de que a leitura SEM comentário é
        // carga, e não zelo.
        $this->assertSame(
            [],
            $chamadasDoSinal('app/Services/Publicador/Aprovacao/Painel/FotosDoPainelService.php'),
            'a varredura achou chamada de `EditorEmUso` no PRÓPRIO serviço — ela voltou a casar o COMENTÁRIO, que cita o sinal em prosa',
        );

        // ⚠️ E os 3 minutos vêm do VALOR resolvido, não da linha do `config/publicador.php`.
        $this->assertSame(3, (int) config('publicador.preparo_ia.editor_em_uso_min'), 'os 3 minutos do docblock mudaram de valor');
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // (3) OS MARCADORES x/y — DOIS estados, numerados, e saneados
    // ═════════════════════════════════════════════════════════════════════════════════════════

    /**
     * ⚠️⚠️ **`pins` só existe em DOIS estados** (`:1109`, remedido: a prosa herdada dizia `:1112`,
     * que é `variation: this.PROD[...]`).
     *
     * ⚠️ **Os estados `rejected`, `regenerating` e `replaced` NUNCA chegam pela galeria final**
     * (`noConjuntoFinal()` admite só `pending` ∪ `approved`), então a regra dos dois estados é
     * medida pelo método **público e puro** `marcadores()` — exatamente a razão pela qual
     * `GaleriaDaOfertaService::tituloDoQuadrado()` é público. Sem ele, o ramo `rejected` seria
     * fidelidade **afirmada e não medida**.
     */
    public function test_os_marcadores_existem_em_DOIS_estados_numerados_e_saneados_188_03(): void
    {
        $empresa = Company::factory()->create();
        $cenario = $this->cenarioDeSeteImagens($empresa);
        $servico = $this->servico();
        $fotos = $servico->para($cenario['oferta']);

        // ─── A numeração 1..N NA ORDEM DOS ALERTAS, e o `ok` NÃO entra ───────────────────────
        $comAlerta = $fotos['imagens'][1];
        $this->assertSame(PubAprovacaoImagem::PENDENTE, $comAlerta['estado']);
        $this->assertCount(1, $comAlerta['marcadores'], 'o alerta `ok` gerou marcador — ele não tem ponto');
        $this->assertSame(
            [['ordinal' => 1, 'x' => 31.25, 'y' => 68.5, 'cor' => FotosDoPainelService::COR_VERMELHA]],
            $comAlerta['marcadores'],
            'o marcador perdeu o ordinal, o ponto ou a cor por severidade',
        );

        // ⚠️⚠️ **x/y SÃO PERCENTUAL, não pixel** — e o valor atravessa como NÚMERO, não string.
        $this->assertIsFloat($comAlerta['marcadores'][0]['x']);
        $this->assertGreaterThanOrEqual(0.0, $comAlerta['marcadores'][0]['x']);
        $this->assertLessThanOrEqual(100.0, $comAlerta['marcadores'][0]['y']);

        // ─── Foto APROVADA com alerta: sem marcador E sem badge (são regras DIFERENTES) ──────
        $aprovadaComAlerta = $fotos['imagens'][3];
        $this->assertSame(PubAprovacaoImagem::APROVADA, $aprovadaComAlerta['estado']);
        $this->assertSame([], $aprovadaComAlerta['marcadores'], 'foto APROVADA desenhou marcador, mesmo tendo alerta');
        $this->assertFalse($aprovadaComAlerta['com_alerta'], 'o badge `!` apareceu numa APROVADA');
        // ⚠️ ISCA: ela TEM alerta gravado (o alerta não desaparece: ele deixa de CONTAR).
        $this->assertCount(1, $aprovadaComAlerta['verificacoes'], 'ISCA: a aprovada da fixture não tem alerta — o caso mede nada');

        // ─── Alerta de x/y NULOS: não vira marcador, e NÃO derruba a lista ───────────────────
        $semPonto = $fotos['imagens'][4];
        $this->assertSame([], $semPonto['marcadores'], 'alerta sem ponto virou marcador fantasma');
        $this->assertTrue($semPonto['com_alerta'], 'alerta sem ponto ainda é alerta: o badge continua');
        $this->assertCount(1, $semPonto['verificacoes'], 'a verificação sem ponto desapareceu da lista');

        // ─── ⚠️ A REGRA DOS DOIS ESTADOS, pelo método PÚBLICO e PURO (5 estados + inventado) ─
        $alertaComPonto = $cenario['imagens']['reprovada']->alertas->all();
        $this->assertCount(1, $alertaComPonto, 'ISCA: a reprovada da fixture não tem alerta com ponto');

        $porEstado = [];
        foreach ([PubAprovacaoImagem::PENDENTE, PubAprovacaoImagem::APROVADA, PubAprovacaoImagem::REPROVADA,
            PubAprovacaoImagem::REGENERANDO, PubAprovacaoImagem::SUBSTITUIDA, FotosDoPainelService::ESTADO_REMOVIDA,
            'estado_que_ninguem_escreveu', null] as $estado) {
            // ⚠️ Flag DENTRO do laço.
            $porEstado[$estado ?? '(null)'] = count($servico->marcadores($estado, $alertaComPonto));
        }
        $this->assertSame(
            [
                PubAprovacaoImagem::PENDENTE => 1,
                PubAprovacaoImagem::APROVADA => 0,
                PubAprovacaoImagem::REPROVADA => 1,
                PubAprovacaoImagem::REGENERANDO => 0,
                PubAprovacaoImagem::SUBSTITUIDA => 0,
                FotosDoPainelService::ESTADO_REMOVIDA => 0,
                'estado_que_ninguem_escreveu' => 0,
                '(null)' => 0,
            ],
            $porEstado,
            'os marcadores deixaram de existir em EXATAMENTE `pending` e `rejected`',
        );

        // ─── ⚠️ A NUMERAÇÃO com TRÊS alertas, e ela é 1..N na ORDEM ──────────────────────────
        $tres = $this->oferta($empresa, PubAprovacaoOferta::AGUARDANDO_APROVACAO);
        $imagemTres = $this->imagem($this->conjunto($tres), 0, [
            'estado' => PubAprovacaoImagem::PENDENTE,
            'alertas' => [
                [PubAprovacaoImagemAlerta::ALTO, 10.0, 11.0, 'primeiro'],
                [PubAprovacaoImagemAlerta::OK, null, null, 'passou'],
                [PubAprovacaoImagemAlerta::MEDIO, 20.0, 21.0, 'segundo'],
                [PubAprovacaoImagemAlerta::MEDIO, 30.0, 31.0, 'terceiro'],
            ],
        ]);
        $marcadores = $servico->marcadores(PubAprovacaoImagem::PENDENTE, $imagemTres->alertas->all());
        $this->assertSame([1, 2, 3], array_column($marcadores, 'ordinal'), 'a numeração não é 1..N sobre os ALERTAS');
        $this->assertSame([10.0, 20.0, 30.0], array_column($marcadores, 'x'), 'a ordem dos marcadores não é a dos alertas');
        $this->assertSame(
            [FotosDoPainelService::COR_VERMELHA, FotosDoPainelService::COR_AMBAR, FotosDoPainelService::COR_AMBAR],
            array_column($marcadores, 'cor'),
            '`high` é vermelho e `med` é âmbar (`:1109`)',
        );

        // ─── ⚠️⚠️ O SANEAMENTO: percentual fora de 0–100 NÃO vira marcador ──────────────────
        //
        // ⚠️ `decimal(5,2)` aceita até **999,99**: o MariaDB recusa acima disso com **1264**, e o
        // SQLite dos testes **grava truncado em silêncio** — a suíte não pega. O marcador fantasma
        // sairia DESENHADO fora da imagem.
        $this->assertTrue($servico->noQuadro(0.0), 'o limite inferior 0 é válido');
        $this->assertTrue($servico->noQuadro(100.0), 'o limite superior 100 é válido');
        $this->assertFalse($servico->noQuadro(100.01), '100,01 está fora do quadro');
        $this->assertFalse($servico->noQuadro(-0.01), 'percentual negativo está fora do quadro');
        $this->assertFalse($servico->noQuadro(999.99), 'o TETO do `decimal(5,2)` não é a faixa válida');
        $this->assertFalse($servico->noQuadro(null), 'nulo não é ponto');
        $this->assertFalse($servico->noQuadro('abc'), 'texto não é ponto');

        $forto = $this->oferta($empresa, PubAprovacaoOferta::AGUARDANDO_APROVACAO);
        $imagemForaDoQuadro = $this->imagem($this->conjunto($forto), 0, [
            'estado' => PubAprovacaoImagem::PENDENTE,
            'alertas' => [
                [PubAprovacaoImagemAlerta::ALTO, 999.99, 50.0, 'estourou'],
                [PubAprovacaoImagemAlerta::ALTO, 40.0, 41.0, 'dentro'],
            ],
        ]);
        $saneados = $servico->marcadores(PubAprovacaoImagem::PENDENTE, $imagemForaDoQuadro->alertas->all());
        $this->assertSame([40.0], array_column($saneados, 'x'), 'percentual fora de 0–100 virou marcador fantasma');
        // ⚠️⚠️ **E O ORDINAL DO QUE SOBROU NÃO É RENUMERADO:** ele é o número que o ITEM DE TEXTO
        // mostra, e renumerar aqui faria o texto dizer "2" com o marcador escrito "1".
        $this->assertSame([2], array_column($saneados, 'ordinal'), 'o marcador saneado RENUMEROU os demais');
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // (4) A BORDA — CINCO ramos, e a precedência só se prova com CONFLITO
    // ═════════════════════════════════════════════════════════════════════════════════════════

    /**
     * ⚠️⚠️ **AS DUAS OMISSÕES DO REVS-09** (`.planning/REQUIREMENTS-v27.md:596`): o requisito lista
     * **quatro** bordas e não lista (a) o **5º ramo** (`#3F3F46`) nem (b) o **anel**
     * (`'0 0 0 2px #FACC15'`, box-shadow **ADICIONAL** à borda). **Os dois são reproduzidos**, e
     * `188-FID01.md` §C os declara a partir de `:1113`.
     */
    public function test_a_borda_tem_CINCO_ramos_e_a_precedencia_se_prova_com_CONFLITO_188_03(): void
    {
        $servico = $this->servico();

        // ─── Os CINCO ramos, um por um ──────────────────────────────────────────────────────
        $this->assertSame(FotosDoPainelService::BORDA_SELECIONADA, $servico->borda(PubAprovacaoImagem::PENDENTE, true, false));
        $this->assertSame(FotosDoPainelService::BORDA_ALERTA, $servico->borda(PubAprovacaoImagem::PENDENTE, false, true));
        $this->assertSame(FotosDoPainelService::BORDA_APROVADA, $servico->borda(PubAprovacaoImagem::APROVADA, false, false));
        $this->assertSame(FotosDoPainelService::BORDA_PENDENTE, $servico->borda(PubAprovacaoImagem::PENDENTE, false, false));
        // ⚠️ O 5º ramo — o que o REVS-09 OMITE.
        $this->assertSame(FotosDoPainelService::BORDA_NEUTRA, $servico->borda(PubAprovacaoImagem::REGENERANDO, false, false));
        $this->assertSame(FotosDoPainelService::BORDA_NEUTRA, $servico->borda('estado_que_ninguem_escreveu', false, false));
        $this->assertSame(FotosDoPainelService::BORDA_NEUTRA, $servico->borda(null, false, false));

        // ⚠️ ISCA: os cinco são CINCO valores distintos. Sem isto, um serviço que devolvesse sempre
        // a mesma string passaria em todos os ramos acima.
        $this->assertCount(5, array_unique([
            FotosDoPainelService::BORDA_SELECIONADA, FotosDoPainelService::BORDA_ALERTA,
            FotosDoPainelService::BORDA_APROVADA, FotosDoPainelService::BORDA_PENDENTE,
            FotosDoPainelService::BORDA_NEUTRA,
        ]), 'dois ramos da borda colidiram — a precedência deixaria de ser observável');

        // ─── ⚠️⚠️ A PRECEDÊNCIA, COM CONFLITO: só se prova com DOIS ramos ativos ────────────
        //
        // ⚠️ `sel` vence `flag`; `flag` vence `approved`; `approved` vence `pending` não se
        // sobrepõem (são exclusivos), então o conflito que importa é este par e o seguinte.
        $this->assertSame(
            FotosDoPainelService::BORDA_SELECIONADA,
            $servico->borda(PubAprovacaoImagem::PENDENTE, true, true),
            'selecionada E com alerta: a selecionada tem de vencer (`:1113`)',
        );
        $this->assertSame(
            FotosDoPainelService::BORDA_SELECIONADA,
            $servico->borda(PubAprovacaoImagem::APROVADA, true, false),
            'selecionada E aprovada: a selecionada tem de vencer',
        );
        $this->assertSame(
            FotosDoPainelService::BORDA_ALERTA,
            $servico->borda(PubAprovacaoImagem::APROVADA, false, true),
            'com alerta E aprovada: o alerta tem de vencer',
        );

        // ─── O ANEL — a 2ª omissão do REVS-09, e ele é SÓ da selecionada ─────────────────────
        $empresa = Company::factory()->create();
        $cenario = $this->cenarioDeSeteImagens($empresa);

        // ⚠️ A selecionada é a `#2` (índice 1), que é **pendente COM alerta** — ou seja, o payload
        // real também carrega o CONFLITO, não só o método puro.
        $fotos = $this->servico()->para($cenario['oferta'], 1);
        $this->assertSame(FotosDoPainelService::BORDA_SELECIONADA, $fotos['imagens'][1]['borda'], 'o conflito no payload real perdeu a precedência');
        $this->assertTrue($fotos['imagens'][1]['com_alerta'], 'ISCA: a selecionada da fixture não tem alerta — o conflito mede nada');

        $aneis = array_column($fotos['imagens'], 'anel');
        $this->assertSame([false, true, false, false, false], $aneis, 'o anel existe SÓ na selecionada');
        $this->assertSame(1, count(array_filter($aneis)), 'mais de uma imagem com anel');

        // ⚠️ E as bordas das NÃO selecionadas continuam dizendo o estado delas.
        $this->assertSame(FotosDoPainelService::BORDA_APROVADA, $fotos['imagens'][0]['borda']);
        $this->assertSame(FotosDoPainelService::BORDA_PENDENTE, $fotos['imagens'][2]['borda']);
        $this->assertSame(FotosDoPainelService::BORDA_APROVADA, $fotos['imagens'][3]['borda'], 'aprovada COM alerta: o alerta não conta (ela já foi decidida)');
        $this->assertSame(FotosDoPainelService::BORDA_ALERTA, $fotos['imagens'][4]['borda'], 'pendente com alerta sem ponto ainda é borda de ALERTA');
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // (5) `estado_rotulo` NUNCA vazio, e o badge `!` de UM estado só
    // ═════════════════════════════════════════════════════════════════════════════════════════

    /**
     * ⚠️⚠️ **Guarda que devolve `null`/`''` produz ELEMENTO VAZIO, que passa em render e
     * DESAPARECE na tela.** Foi exatamente o conserto do anel de progresso na 187-08.
     *
     * ⚠️ No protótipo `C[r.st]` com chave desconhecida é `undefined` e a linha **EXPLODIRIA**
     * (`:1104`); aqui ela cai no NEUTRO — e o texto **não mente**: não diz *"A conferir"* sobre um
     * estado que ninguém mapeou.
     */
    public function test_o_estado_rotulo_NUNCA_e_vazio_e_o_badge_e_de_UM_estado_so_188_03(): void
    {
        $servico = $this->servico();

        // ─── Os SEIS estados do selo (`:1104`) + o inventado + o nulo ───────────────────────
        $estados = [PubAprovacaoImagem::APROVADA, PubAprovacaoImagem::PENDENTE, PubAprovacaoImagem::REPROVADA,
            PubAprovacaoImagem::REGENERANDO, PubAprovacaoImagem::SUBSTITUIDA, FotosDoPainelService::ESTADO_REMOVIDA,
            'estado_que_ninguem_escreveu', '', null];

        // ⚠️ **UMA** asserção coletando os infratores — nunca uma por estado, que faria a contagem
        // crescer com a lista (learnings §4b.2).
        $vazios = [];
        foreach ($estados as $estado) {
            // ⚠️ Flag DENTRO do laço.
            [$rotulo, $cor, $dot] = $servico->selo($estado);
            if (trim((string) $rotulo) === '' || trim((string) $cor) === '' || trim((string) $dot) === '') {
                $vazios[] = var_export($estado, true);
            }
        }
        $this->assertSame([], $vazios, 'selo com rótulo, cor ou dot VAZIO: o elemento desaparece na tela');

        // ─── Os rótulos pt-BR de `:1104`, byte a byte ───────────────────────────────────────
        $this->assertSame('Aprovada', $servico->selo(PubAprovacaoImagem::APROVADA)[0]);
        $this->assertSame('A conferir', $servico->selo(PubAprovacaoImagem::PENDENTE)[0]);
        $this->assertSame('Reprovada', $servico->selo(PubAprovacaoImagem::REPROVADA)[0]);
        $this->assertSame('Regenerando', $servico->selo(PubAprovacaoImagem::REGENERANDO)[0]);
        $this->assertSame('Substituída', $servico->selo(PubAprovacaoImagem::SUBSTITUIDA)[0]);
        $this->assertSame('Removida', $servico->selo(FotosDoPainelService::ESTADO_REMOVIDA)[0]);

        // ⚠️⚠️ E o NEUTRO **não se disfarça de estado conhecido**: ele não pode dizer "A conferir".
        $neutro = $servico->selo('estado_que_ninguem_escreveu');
        $this->assertSame(FotosDoPainelService::SELO_NEUTRO, $neutro);
        $this->assertNotSame('A conferir', $neutro[0], 'o NEUTRO está mentindo que a foto é pendente');
        $this->assertNotSame('Aprovada', $neutro[0], 'o NEUTRO está mentindo que a foto é aprovada');

        // ⚠️ ISCA DO LEITOR: o selo dos SEIS é LIDO do mapa, e o mapa tem exatamente SEIS chaves.
        $this->assertCount(6, FotosDoPainelService::SELOS, 'o mapa dos selos ganhou ou perdeu estado');
        $this->assertSame(
            [PubAprovacaoImagem::APROVADA, PubAprovacaoImagem::PENDENTE, PubAprovacaoImagem::REPROVADA,
                PubAprovacaoImagem::REGENERANDO, PubAprovacaoImagem::SUBSTITUIDA, FotosDoPainelService::ESTADO_REMOVIDA],
            array_keys(FotosDoPainelService::SELOS),
            'as SEIS chaves do selo, nesta ordem — `:1104`',
        );

        // ─── ⚠️⚠️ O BADGE `!` É DE **UM** ESTADO SÓ (`:1105`) ───────────────────────────────
        $empresa = Company::factory()->create();
        $cenario = $this->cenarioDeSeteImagens($empresa);
        $fotos = $this->servico()->para($cenario['oferta']);

        $badges = array_column($fotos['imagens'], 'com_alerta');
        $this->assertSame([false, true, false, false, true], $badges, 'o badge `!` deixou de ser só de `pending` COM alerta');

        // ─── ⚠️⚠️ A SOBREVIVENTE DA MUTAÇÃO (ii-b), INVESTIGADA E DECLARADA ────────────────
        //
        // A campanha mutou o serviço para dar badge também a `rejected` e a mutação **SOBREVIVEU**.
        // ⚠️ **Não é lacuna de asserção: é INOBSERVABILIDADE por construção.**
        // `PubAprovacaoImagem::noConjuntoFinal()` admite só `pending` ∪ `approved`, então
        // `rejected` **nunca chega** à galeria final — e o ramo `rejected` do badge não tem como
        // ser exercitado por `para()`.
        //
        // ⚠️ **A regra mora um nível ABAIXO** (`PubAprovacaoConjunto::comAlerta()` +
        // `GaleriaDaOfertaService`, Fases 186/187, as duas **EM PRODUÇÃO** e com gates próprios), e
        // este serviço só a **REPASSA**. ➡️ O que **é** meu para provar são as duas coisas abaixo.
        //
        // (a) O badge é **LIDO** da chave da galeria, nunca recomputado aqui:
        $doServico = $this->semComentarios('app/Services/Publicador/Aprovacao/Painel/FotosDoPainelService.php');
        $this->assertStringContainsString("\$daGaleria['com_alerta']", $doServico, 'o badge foi RECOMPUTADO em vez de LIDO da galeria — seria a SEGUNDA definição de "com alerta"');

        // (b) ⚠️⚠️ **E A PREMISSA FICA TRAVADA:** no dia em que `NO_CONJUNTO_FINAL` ganhar
        // `rejected`, a inobservabilidade **deixa de valer** e este caso REPROVA — que é
        // exatamente quando alguém precisa reabrir a questão. Gate que dispara **na hora** em que
        // a premissa muda, em vez de uma nota que ninguém lê.
        $this->assertSame(
            [PubAprovacaoImagem::PENDENTE, PubAprovacaoImagem::APROVADA],
            PubAprovacaoImagem::NO_CONJUNTO_FINAL,
            'a galeria final mudou de estados: o ramo `rejected` do badge passou a ser observável e precisa de caso próprio',
        );

        // ⚠️⚠️ **AS TRÊS REGRAS SÃO TRÊS, E O MESMO DADO AS SEPARA.** A `#4` (aprovada COM alerta
        // `high` COM ponto) tem **verificação**, **sem** badge e **sem** marcador; a `#2` (pendente
        // com alerta) tem os três. Unificar duas quaisquer delas REPROVA aqui.
        $aprovadaComAlerta = $fotos['imagens'][3];
        $this->assertSame(
            [1, false, 0],
            [count($aprovadaComAlerta['verificacoes']), $aprovadaComAlerta['com_alerta'], count($aprovadaComAlerta['marcadores'])],
            'aprovada com alerta: verificação SIM, badge NÃO, marcador NÃO',
        );
        $pendenteComAlerta = $fotos['imagens'][1];
        $this->assertSame(
            [2, true, 1],
            [count($pendenteComAlerta['verificacoes']), $pendenteComAlerta['com_alerta'], count($pendenteComAlerta['marcadores'])],
            'pendente com alerta: verificação SIM (2, com o `ok`), badge SIM, marcador SIM (1, o `ok` não tem ponto)',
        );

        // ─── E `aprovada`/`aceita_acao` dizem o que dizem ────────────────────────────────────
        $this->assertSame([true, false, false, true, false], array_column($fotos['imagens'], 'aprovada'));
        // ⚠️ IMG-09 — `regenerating` não aceita ação; ele não chega pela galeria, então aqui são
        // todos `true`, e a regra é medida pelo modelo (Fase 186), que é a única casa dela.
        $this->assertSame([true, true, true, true, true], array_column($fotos['imagens'], 'aceita_acao'));
        $this->assertFalse($cenario['imagens']['regenerando']->aceitaAcao(), 'IMG-09: `regenerating` não aceita ação');
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // (6) A GALERIA VAZIA E O CLAMP NAS DUAS PONTAS
    // ═════════════════════════════════════════════════════════════════════════════════════════

    /**
     * ⚠️ **Galeria vazia é o bloqueio `sem_fotos` (IMG-12), não tela vazia silenciosa** — e
     * `selecionada = null` é **reservado** para esse caso.
     *
     * ⚠️⚠️ **DIVERGÊNCIA DECLARADA do `:1293`:** o protótipo faz
     * `imgs[Math.min(selIdx, imgs.length - 1)] || null` — clampa só o **teto**, então índice
     * **negativo** devolve `null`. Aqui o clamp é nas **DUAS** pontas, porque usar `null` também
     * para um índice inválido faria a coluna **dizer o vazio com fotos na grade** — a tela
     * afirmando o que não é.
     */
    public function test_a_galeria_vazia_e_o_clamp_nas_DUAS_pontas_188_03(): void
    {
        $empresa = Company::factory()->create();

        // ─── Rascunho SEM conjunto nenhum — o caso `sem_fotos` ──────────────────────────────
        $semFotos = $this->oferta($empresa, PubAprovacaoOferta::AGUARDANDO_APROVACAO);
        $vazio = $this->servico()->para($semFotos);

        $this->assertSame([], $vazio['imagens'], 'galeria vazia tem de sair como LISTA vazia');
        $this->assertNull($vazio['selecionada'], 'galeria vazia: nunca um bloco com buracos');
        $this->assertNull($vazio['selecionada_indice']);
        $this->assertSame('0 de 0 aprovadas', $vazio['progresso'], 'IMG-13: nunca painel vazio, nunca zero escondido');
        $this->assertSame(FotosDoPainelService::COR_VERDE, $vazio['progresso_cor'], 'sem pendente a cor é verde, mesmo sem foto');
        $this->assertNull($vazio['sem_alerta'], 'botão sem destino não é desenhado (D4)');
        $this->assertSame(0, $vazio['com_alerta_restantes']);

        // ⚠️ E o BLOQUEIO continua de pé: a galeria vazia não é "tudo em ordem".
        $leitura = app(BloqueiosDaOfertaService::class)->para($semFotos);
        $chaves = array_column(is_array($leitura['bloqueios'] ?? null) ? $leitura['bloqueios'] : [], 'chave');
        $this->assertContains('sem_fotos', $chaves, 'IMG-12: rascunho sem foto tem de bloquear');

        // ─── Conjunto SEM imagem nenhuma (existe o grupo, não a foto) ───────────────────────
        $conjuntoVazio = $this->oferta($empresa, PubAprovacaoOferta::AGUARDANDO_APROVACAO);
        $this->conjunto($conjuntoVazio);
        $this->assertSame([], $this->servico()->para($conjuntoVazio)['imagens'], 'conjunto sem imagem tem de dar lista vazia');
        $this->assertNull($this->servico()->para($conjuntoVazio)['selecionada']);

        // ─── ⚠️ O CLAMP nas DUAS pontas ─────────────────────────────────────────────────────
        $cenario = $this->cenarioDeSeteImagens($empresa);

        $acima = $this->servico()->para($cenario['oferta'], 99);
        $this->assertSame(4, $acima['selecionada_indice'], 'índice acima do tamanho não foi clampado ao ÚLTIMO');
        $this->assertSame($acima['imagens'][4]['id'], $acima['selecionada']['id']);
        $this->assertTrue($acima['imagens'][4]['anel'], 'o clamp não moveu o anel');

        $negativo = $this->servico()->para($cenario['oferta'], -7);
        $this->assertSame(0, $negativo['selecionada_indice'], 'índice negativo não foi clampado ao PRIMEIRO');
        $this->assertSame($negativo['imagens'][0]['id'], $negativo['selecionada']['id']);
        // ⚠️⚠️ **E ele NÃO virou `null`:** `null` é o vazio, e a galeria tem 5 fotos.
        $this->assertNotNull($negativo['selecionada'], 'índice negativo zerou a selecionada com a galeria CHEIA');

        $dentro = $this->servico()->para($cenario['oferta'], 2);
        $this->assertSame(2, $dentro['selecionada_indice'], 'o índice VÁLIDO foi movido pelo clamp');
        $this->assertSame($dentro['imagens'][2]['id'], $dentro['selecionada']['id']);

        // ⚠️ A `selecionada` é **a MESMA entrada** da lista — não uma segunda montagem dela.
        $this->assertSame($dentro['imagens'][2], $dentro['selecionada'], 'a selecionada foi montada DUAS vezes');
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // (7) "Aprovar N sem alerta" — a contagem TEM de ser igual ao que a AÇÃO aprova
    // ═════════════════════════════════════════════════════════════════════════════════════════

    /**
     * ⚠️⚠️ **CRITÉRIO 5 / IMG-05, literal:** *"Aprovar N sem alerta" aprova **SÓ** as pendentes
     * **SEM** alerta automático — foto com alerta continua para conferência individual.*
     *
     * ⚠️ **Se a contagem e a ação divergirem, a pessoa clica *"Aprovar 4"* e 2 são aprovadas** —
     * exatamente o sintoma que o usuário recusou duas vezes (*"conta e lista TÊM de bater"*). ➡️ A
     * igualdade é medida contra o **retorno REAL** de `RevisaoDeImagemService::aprovarSemAlerta()`,
     * na MESMA fixture.
     *
     * ⚠️⚠️ **E A SOMA É SOBRE TODOS OS CONJUNTOS, porque a ação recebe UM conjunto por chamada.**
     * Isto é **aviso medido ao plano 188-05**: chamá-la para um conjunto só aprovaria **menos** do
     * que o botão promete. Com dois grupos na fixture, uma chamada única dá **menos** que o total —
     * e é essa a **isca do recorte**.
     */
    public function test_a_contagem_sem_alerta_e_IGUAL_ao_que_a_acao_aprova_188_03(): void
    {
        $empresa = Company::factory()->create();
        $quem = \App\Models\User::factory()->create(['name' => 'Rafaela Guedes']);

        $oferta = $this->oferta($empresa, PubAprovacaoOferta::AGUARDANDO_APROVACAO);
        $geral = $this->conjunto($oferta);
        $cor = $this->conjunto($oferta, 'COR:Azul');

        // ⚠️ FIXTURE QUE DISCRIMINA — e ela é de propósito ASSIMÉTRICA entre os dois grupos:
        //   · `GENERAL`: 1 aprovada · 2 pendentes SEM alerta · 1 pendente COM alerta;
        //   · `COR:Azul`: 1 pendente SEM alerta · 1 pendente COM alerta · 1 regenerando.
        // ➡️ "sem alerta" = 3, "com alerta restantes" = 2, pendentes = 5. Com um grupo só, ou com
        // todas as pendentes limpas, os dois números COINCIDIRIAM com o total e a sonda ficaria cega.
        $this->imagem($geral, 0, ['estado' => PubAprovacaoImagem::APROVADA]);
        $this->imagem($geral, 1, ['estado' => PubAprovacaoImagem::PENDENTE]);
        $this->imagem($geral, 2, ['estado' => PubAprovacaoImagem::PENDENTE]);
        $this->imagem($geral, 3, [
            'estado' => PubAprovacaoImagem::PENDENTE,
            'alertas' => [[PubAprovacaoImagemAlerta::ALTO, 20.0, 30.0]],
        ]);
        $this->imagem($cor, 0, ['estado' => PubAprovacaoImagem::PENDENTE]);
        $this->imagem($cor, 1, [
            'estado' => PubAprovacaoImagem::PENDENTE,
            'alertas' => [[PubAprovacaoImagemAlerta::MEDIO, 40.0, 50.0]],
        ]);
        $this->imagem($cor, 2, ['estado' => PubAprovacaoImagem::REGENERANDO]);

        $fotos = $this->servico()->para($oferta);

        // ─── A contagem, e o RÓTULO byte a byte (`:1305`) ────────────────────────────────────
        $this->assertSame(3, $fotos['sem_alerta']['quantidade'], 'a contagem de "sem alerta" mudou');
        $this->assertSame('Aprovar 3 sem alerta', $fotos['sem_alerta']['rotulo']);
        $this->assertSame(['quantidade', 'rotulo'], array_keys($fotos['sem_alerta']));

        // ⚠️ ISCA DO RECORTE: a contagem é **MENOR** que o total de pendentes. Se fossem iguais, a
        // sonda estaria cega — ela não distinguiria "pendentes" de "pendentes sem alerta".
        $this->assertSame(5, $fotos['pendentes'], 'a fixture não produziu 5 pendentes');
        $this->assertLessThan($fotos['pendentes'], $fotos['sem_alerta']['quantidade'], 'ISCA: a contagem é igual ao total de pendentes — ela não filtra alerta');

        // ⚠️ Decisão 8 do usuário: **a tela diz quem ficou de fora e por quê.**
        $this->assertSame(2, $fotos['com_alerta_restantes'], 'as duas com alerta continuam para conferência individual');
        $this->assertSame(
            $fotos['pendentes'],
            $fotos['sem_alerta']['quantidade'] + $fotos['com_alerta_restantes'],
            'a soma não fecha: "aprovar N sem alerta" + "N continuam" tem de dar as pendentes',
        );

        // ─── ⚠️⚠️ A IGUALDADE COM A AÇÃO REAL, somada sobre TODOS os conjuntos ──────────────
        $acao = app(\App\Services\Publicador\Aprovacao\RevisaoDeImagemService::class);

        $porConjunto = [];
        foreach (PubAprovacaoConjunto::doRascunho((int) $oferta->rascunho_id)->orderBy('id')->get() as $conjunto) {
            // ⚠️ Flag DENTRO do laço.
            $porConjunto[(string) $conjunto->grupo_chave] = $acao->aprovarSemAlerta($conjunto, $quem);
        }

        $this->assertSame(
            $fotos['sem_alerta']['quantidade'],
            array_sum($porConjunto),
            'a contagem do painel DIVERGIU do que `aprovarSemAlerta()` aprova — a pessoa clicaria "Aprovar N" e outro número seria aprovado',
        );

        // ⚠️⚠️ **O AVISO AO 188-05, MEDIDO:** uma chamada única (um conjunto) aprova **MENOS** que
        // o botão promete. Sem este caso, o plano seguinte poderia chamar a ação uma vez e o
        // sintoma só apareceria em produção.
        $this->assertSame([PubAprovacaoConjunto::GERAL => 2, 'COR:Azul' => 1], $porConjunto, 'a repartição por grupo mudou');
        $this->assertLessThan(
            array_sum($porConjunto),
            $porConjunto[PubAprovacaoConjunto::GERAL],
            'ISCA: um conjunto só já aprova o total — a cobertura de TODOS os grupos não é observável nesta fixture',
        );

        // ⚠️ E a ação aprovou **SÓ** as sem alerta: as duas com alerta continuam `pending`.
        $aindaPendentes = PubAprovacaoImagem::query()
            ->whereIn('conjunto_id', [$geral->id, $cor->id])
            ->where('estado', PubAprovacaoImagem::PENDENTE)
            ->get();
        $this->assertCount(2, $aindaPendentes, 'a ação aprovou foto COM alerta');
        $comAlertaDepois = $aindaPendentes->filter(
            fn (PubAprovacaoImagem $i): bool => $i->alertas->contains(
                fn (PubAprovacaoImagemAlerta $a): bool => $a->severidade !== PubAprovacaoImagemAlerta::OK
            )
        );
        $this->assertCount(2, $comAlertaDepois, 'quem continuou pendente não é quem tem alerta');

        // ─── E depois da ação o painel RE-LÊ: o botão SOME e o progresso anda ───────────────
        $depois = $this->servico()->para($oferta->fresh(['alvo', 'produto']));
        $this->assertSame(2, $depois['com_alerta_restantes'], 'as com alerta continuam contando depois da ação');
        $this->assertNull($depois['sem_alerta'], 'sem pendente LIMPA o botão não é desenhado (D4)');
        $this->assertSame('4 de 6 aprovadas', $depois['progresso'], 'o progresso não acompanhou a aprovação em lote');
    }

    /**
     * ⚠️ `sem_alerta` é **`null`** com **N = 0** **ou** fora do `live` — `hasSafe = pend.filter(…)
     * .length > 0 && inf.live` (`:1305`, remedido: a prosa herdada apontava `:1311`, que é o
     * `selActions`).
     *
     * ⚠️ **Botão sem destino é D4 violado**, e o usuário já exerceu essa régua três vezes.
     */
    public function test_sem_alerta_e_nulo_com_zero_e_fora_do_live_e_cobre_TODOS_os_grupos_188_03(): void
    {
        $empresa = Company::factory()->create();

        // ─── N = 0: toda pendente TEM alerta ────────────────────────────────────────────────
        $todasComAlerta = $this->oferta($empresa, PubAprovacaoOferta::AGUARDANDO_APROVACAO);
        $conjunto = $this->conjunto($todasComAlerta);
        $this->imagem($conjunto, 0, ['estado' => PubAprovacaoImagem::APROVADA]);
        $this->imagem($conjunto, 1, [
            'estado' => PubAprovacaoImagem::PENDENTE,
            'alertas' => [[PubAprovacaoImagemAlerta::ALTO, 5.0, 6.0]],
        ]);

        $semBotao = $this->servico()->para($todasComAlerta);
        $this->assertNull($semBotao['sem_alerta'], 'N = 0 e o botão foi desenhado');
        // ⚠️ ISCA: há pendente, e ela TEM alerta — então o `null` não vem de "não há pendente".
        $this->assertSame(1, $semBotao['pendentes'], 'ISCA: a fixture não tem pendente — o caso mede nada');
        $this->assertSame(1, $semBotao['com_alerta_restantes'], 'a tela ainda diz que 1 continua para conferência');

        // ─── Fora do `live`: há pendente LIMPA, e o botão continua ausente ───────────────────
        //
        // ⚠️ A ISCA DO RECORTE é esta: a MESMA fixture dentro do `live` TEM botão. Sem comparar as
        // duas, o `null` seria indistinguível de "a fixture não produz N > 0".
        $naoVivos = [PubAprovacaoOferta::PUBLICADO, PubAprovacaoOferta::PUBLICANDO,
            PubAprovacaoOferta::APROVADO, PubAprovacaoOferta::PREPARANDO, PubAprovacaoOferta::FALHA];
        $desenhados = [];
        foreach ($naoVivos as $andamento) {
            // ⚠️ Flag DENTRO do laço.
            $fora = $this->oferta($empresa, $andamento);
            $c = $this->conjunto($fora);
            $this->imagem($c, 0, ['estado' => PubAprovacaoImagem::PENDENTE]);

            $vm = $this->servico()->para($fora);
            if ($vm['sem_alerta'] !== null) {
                $desenhados[] = $andamento;
            }
            // ⚠️ E o progresso CONTINUA: fora do `live` a coluna não fica muda.
            $this->assertSame('0 de 1 aprovadas', $vm['progresso'], "o progresso sumiu em `{$andamento}`");
        }
        // ⚠️ **UMA** asserção coletando os infratores — nunca uma por andamento.
        $this->assertSame([], $desenhados, 'D4: botão "Aprovar N sem alerta" desenhado fora do `live`');
        $this->assertCount(5, $naoVivos, 'a lista CONSTANTE dos andamentos fora do `live` encolheu');

        // ⚠️ ISCA POSITIVA: dentro do `live`, a MESMA fixture TEM botão.
        $vivos = [PubAprovacaoOferta::AGUARDANDO_APROVACAO, PubAprovacaoOferta::EM_REVISAO_ECF,
            PubAprovacaoOferta::AJUSTES_SOLICITADOS];
        $this->assertSame($vivos, PubAprovacaoOferta::LIVE, 'a lista do `live` é LIDA do modelo (AND-05)');
        $ausentes = [];
        foreach ($vivos as $andamento) {
            // ⚠️ Flag DENTRO do laço.
            $vivo = $this->oferta($empresa, $andamento);
            $this->imagem($this->conjunto($vivo), 0, ['estado' => PubAprovacaoImagem::PENDENTE]);

            if ($this->servico()->para($vivo)['sem_alerta'] === null) {
                $ausentes[] = $andamento;
            }
        }
        $this->assertSame([], $ausentes, 'ISCA: dentro do `live` o botão NÃO apareceu — a sonda do `live` está cega');

        // ─── ⚠️⚠️ A COBERTURA SOBRE **TODOS OS GRUPOS** (decisão 5 do usuário) ─────────────
        //
        // ⚠️ `ResolvedorGruposImagem::chaveDoGrupo()` dá um grupo por valor de eixo que define foto
        // **mais o `GENERAL`**. Com QUATRO grupos, a contagem do grupo da selecionada seria 1 —
        // então um serviço que lesse só o grupo da imagem aberta REPROVA aqui.
        $quatro = $this->oferta($empresa, PubAprovacaoOferta::AGUARDANDO_APROVACAO);
        $grupos = [PubAprovacaoConjunto::GERAL, 'COR:Azul', 'COR:Verde', 'COR:Preto'];
        foreach ($grupos as $grupo) {
            // ⚠️ Flag DENTRO do laço.
            $this->imagem($this->conjunto($quatro, $grupo), 0, ['estado' => PubAprovacaoImagem::PENDENTE]);
        }

        $deQuatro = $this->servico()->para($quatro);
        $this->assertCount(4, $grupos, 'a fixture dos grupos encolheu');
        $this->assertSame(4, $deQuatro['sem_alerta']['quantidade'], 'a contagem cobriu só UM grupo, não todos');
        $this->assertSame('Aprovar 4 sem alerta', $deQuatro['sem_alerta']['rotulo']);
        $this->assertSame('0 de 4 aprovadas', $deQuatro['progresso'], 'o progresso cobriu só um grupo');
        $this->assertCount(4, $deQuatro['imagens'], 'a galeria cobriu só um grupo');
        // ⚠️ E o `GENERAL` vem PRIMEIRO (a ordem da 186/187, lida — nunca redefinida).
        $this->assertTrue($deQuatro['imagens'][0]['grupo_geral'], 'o `GENERAL` deixou de vir primeiro');
        $this->assertSame(
            [true, false, false, false],
            array_column($deQuatro['imagens'], 'grupo_geral'),
            'a ordem dos grupos mudou',
        );

        // ⚠️⚠️ **DIVERGÊNCIA ESTRUTURAL DECLARADA:** com 4 grupos há **4** principais (a capa é do
        // CONJUNTO: `pub_aprovacao_conjuntos.principal_imagem_id`), enquanto o protótipo tem UMA
        // (`D.main[set]`). O `#n` da coluna é o índice GLOBAL; o `rotulo` é por grupo.
        $this->assertSame([true, true, true, true], array_column($deQuatro['imagens'], 'principal'), 'a capa é por GRUPO — 4 grupos, 4 principais');
        $this->assertSame([1, 2, 3, 4], array_column($deQuatro['imagens'], 'posicao'), 'a `posicao` da coluna é o índice GLOBAL');
        $this->assertSame(
            [GaleriaDaOfertaService::ROTULO_CAPA, GaleriaDaOfertaService::ROTULO_CAPA,
                GaleriaDaOfertaService::ROTULO_CAPA, GaleriaDaOfertaService::ROTULO_CAPA],
            array_column($deQuatro['imagens'], 'rotulo'),
            'o `rotulo` é o da galeria da 187, derivado da `posicao` DO BANCO (por conjunto)',
        );
    }

    /**
     * ⚠️⚠️ **O CUSTO: FIXO, e não cresce com imagem nem com GRUPO.**
     *
     * ⚠️ `temAlerta()` e `$revisao->imagem` lidos um por um fariam **uma consulta POR IMAGEM** — o
     * N+1 clássico (T-188-23). E uma consulta **por grupo** multiplicaria a coluna pelo número de
     * variações: é a dívida que `BloqueiosDaOfertaService` já declarou e evitou.
     *
     * ⚠️⚠️ **E ESTE CASO TRAVA UM ACHADO DO FRAMEWORK, MEDIDO AO SER ESCRITO.** A primeira redação
     * desta wave deu a `PainelDeRevisaoService::$fotos` o default `null`, para não mexer no gate de
     * custo do 188-02 (que constrói o serviço com **três** argumentos posicionais). ⚠️ **Este gate
     * REPROVOU**, e o motivo é `Container::resolveClass()`
     * (`vendor/laravel/framework/src/Illuminate/Container/Container.php:1327`-`:1331`): **havendo
     * default e não havendo binding explícito, o container devolve o DEFAULT** — *"to avoid
     * overriding any of the developer specified defaults"*. ➡️ A dependência **nunca** seria
     * injetada, e a coluna de fotos sairia de um `app()` **por chamada**: service locator
     * disfarçado de injeção. **O conserto foi o argumento obrigatório + uma linha no gate do
     * 188-02**, sem mexer na contagem de asserções daquele manifesto.
     */
    public function test_o_CUSTO_da_coluna_de_fotos_nao_cresce_com_imagem_nem_grupo_188_03(): void
    {
        $empresa = Company::factory()->create();

        $contar = function (PubAprovacaoOferta $oferta): int {
            \Illuminate\Support\Facades\DB::flushQueryLog();
            \Illuminate\Support\Facades\DB::enableQueryLog();
            $this->servico()->para($oferta);
            $consultas = count(\Illuminate\Support\Facades\DB::getQueryLog());
            \Illuminate\Support\Facades\DB::disableQueryLog();

            return $consultas;
        };

        // ─── Uma imagem, um grupo ───────────────────────────────────────────────────────────
        $pequena = $this->oferta($empresa, PubAprovacaoOferta::AGUARDANDO_APROVACAO);
        $this->imagem($this->conjunto($pequena), 0, ['estado' => PubAprovacaoImagem::PENDENTE]);
        $umaSo = $contar($pequena->fresh(['alvo', 'produto']));

        // ⚠️ ISCA DO CONTADOR: ele conta de verdade. Sem isto, "igual" seria indistinguível de
        // contador cego devolvendo sempre zero.
        $this->assertGreaterThan(0, $umaSo, 'ISCA: o contador de consultas devolveu ZERO — ele está cego');

        // ─── Doze imagens em QUATRO grupos ──────────────────────────────────────────────────
        $grande = $this->oferta($empresa, PubAprovacaoOferta::AGUARDANDO_APROVACAO);
        foreach ([PubAprovacaoConjunto::GERAL, 'COR:Azul', 'COR:Verde', 'COR:Preto'] as $grupo) {
            // ⚠️ Flag DENTRO do laço.
            $conjunto = $this->conjunto($grande, $grupo);
            for ($i = 0; $i < 3; $i++) {
                $this->imagem($conjunto, $i, [
                    'estado' => PubAprovacaoImagem::PENDENTE,
                    'alertas' => [[PubAprovacaoImagemAlerta::ALTO, 10.0 + $i, 20.0 + $i]],
                ]);
            }
        }
        $grandeOferta = $grande->fresh(['alvo', 'produto']);
        $doze = $contar($grandeOferta);

        $this->assertCount(12, $this->servico()->para($grandeOferta)['imagens'], 'a fixture grande não produziu 12 imagens');
        $this->assertSame($umaSo, $doze, 'o custo CRESCEU com o número de imagens/grupos — é o N+1 de T-188-23');

        // ─── ⚠️⚠️ A INJEÇÃO DO PAINEL: OBRIGATÓRIA, E O DEFAULT **NÃO SERVIRIA** ───────────
        //
        // ⚠️⚠️ **CIRURGIA DA WAVE 188-04, e a razão é a régua, não conveniência.** A redação
        // original era `assertCount(4, $parametros)` + `$parametros[3]` — ou seja, uma asserção
        // **de CONTAGEM e de POSIÇÃO**. Mas o invariante não é *"o construtor tem exatamente 4
        // dependências"*: cada wave seguinte do painel acrescenta a SUA coluna como argumento
        // OBRIGATÓRIO (é o conserto que esta própria wave 3 mediu e declarou). ➡️ A contagem
        // **muda quando o trabalho certo é feito**, e número assim faz o executor seguinte
        // *"ajustá-lo para passar"* — é o `feedback_gate_que_reprova_pelo_proprio_conserto`.
        // ⚠️ **MEDIDO:** com a 5ª dependência (`PrecoDoPainelService`, plano 188-04) este caso
        // reprovou com *"o construtor do painel ganhou ou perdeu dependência"*, e o conserto certo
        // era **deste lado**, não do lado da 188-04 (o briefing dela PRESCREVE o argumento
        // obrigatório).
        // ➡️ Agora a âncora é **NOME + TIPO**, que é o invariante de verdade: `fotos` é dependência
        // do construtor, é do tipo certo, **não tem default** e **não é anulável**.
        // ⚠️ **A CONTAGEM DE ASSERÇÕES DESTE CASO NÃO MUDOU** (duas asserções viraram duas), então
        // `contagens-188-03.txt` segue valendo — e isso foi **REMEDIDO**, não presumido.
        $reflexao = new \ReflectionClass(\App\Services\Publicador\Aprovacao\Painel\PainelDeRevisaoService::class);
        $parametros = $reflexao->getConstructor()?->getParameters() ?? [];
        $porNome = [];
        foreach ($parametros as $p) {
            // ⚠️ Flag DENTRO do laço.
            $porNome[$p->getName()] = $p;
        }
        $this->assertArrayHasKey('fotos', $porNome, '`fotos` deixou de ser dependência do construtor do painel');
        $oFotos = $porNome['fotos'];
        $this->assertSame(FotosDoPainelService::class, $oFotos->getType()?->getName(), '`fotos` mudou de tipo');

        // ⚠️⚠️ **O INVARIANTE É "SEM DEFAULT", e ele não é estilo:** com default o container
        // devolveria o DEFAULT (`Container.php:1327`-`:1331`) e a injeção seria uma ILUSÃO.
        $this->assertFalse(
            $oFotos->isDefaultValueAvailable(),
            '`fotos` ganhou default: o container passaria a devolver o DEFAULT e `FotosDoPainelService` nunca seria injetado',
        );
        $this->assertFalse($oFotos->allowsNull(), '`fotos` anulável esconderia a mesma falha');

        // (a) O container injeta a instância REAL.
        $propriedade = $reflexao->getProperty('fotos');
        $propriedade->setAccessible(true);
        $doContainer = app(\App\Services\Publicador\Aprovacao\Painel\PainelDeRevisaoService::class);
        $this->assertInstanceOf(
            FotosDoPainelService::class,
            $propriedade->getValue($doContainer),
            'o container não injetou `FotosDoPainelService` — a coluna de fotos sairia de um `app()` por chamada',
        );

        // (b) ⚠️ **A PROVA DO ACHADO, em memória, sobre uma classe de MENTIRA** — para que o
        // próximo executor não tenha de descobrir de novo por que o default foi recusado. A mesma
        // dependência, com default, **NÃO** é injetada pelo container.
        $comDefault = new class(null)
        {
            public function __construct(public ?FotosDoPainelService $fotos = null) {}
        };
        $resolvida = app(get_class($comDefault));
        $this->assertNull(
            $resolvida->fotos,
            'ISCA DO ACHADO: o container passou a injetar classe com DEFAULT — se isto mudar no framework, o docblock do construtor do painel precisa ser revisto',
        );
        // ⚠️ E a MESMA classe sem default É injetada — é o par que prova que a causa é o default,
        // e não "o container não resolve esta classe".
        $semDefault = new class(app(FotosDoPainelService::class))
        {
            public function __construct(public FotosDoPainelService $fotos) {}
        };
        $this->assertInstanceOf(FotosDoPainelService::class, app(get_class($semDefault))->fotos, 'ISCA: o container não resolve a classe nem SEM default — a prova do achado é vazia');

        // ⚠️ E NADA foi ao Mercado Livre nesta coluna.
        $this->assertSame([], $this->chamadas, 'a coluna de fotos fez requisição ao Mercado Livre');
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // (8) AS VERIFICAÇÕES AUTOMÁTICAS — DADO, com o ordinal DO MARCADOR (PERGUNTA-188-A)
    // ═════════════════════════════════════════════════════════════════════════════════════════

    /**
     * ⚠️⚠️ **PERGUNTA-188-A, RESPONDIDA PELO USUÁRIO em 18/10/2026 — opção (c), HÍBRIDO:** no painel
     * o cabeçalho **`VERIFICAÇÃO AUTOMÁTICA · APOIO`** aparece **uma vez** **e** cada item mantém
     * **o número que o liga ao marcador x/y**.
     *
     * A razão escrita pelo usuário: *o cabeçalho é o que diz **APOIO** — sem ele a verificação
     * automática pode ser lida como **VEREDICTO**, que é o que REVS-10 e a D7 querem impedir; e o
     * ordinal é **funcional** (liga o item ao marcador), não enfeite.*
     *
     * ⚠️⚠️ **O PROTÓTIPO PERDEU AQUI — é a TERCEIRA derrota dele na milestone, e as três foram
     * conflito entre DUAS REGRAS DO PROJETO resolvido PELO USUÁRIO, nunca erro dele.** Os dois
     * lados, medidos por `sed`:
     *   · `design_handoff_publicador/Aprovacao.dc.html:369` **TEM** o literal
     *     `VERIFICAÇÃO AUTOMÁTICA · APOIO` — mas é a **revisão detalhada (E4)**, da Fase 190/191;
     *   · `:633` é o que o **PAINEL** escreve, e ele monta
     *     `<span>Verificação automática{{ a.n }} · </span>{{ a.text }}` — a frase **colada**, sem
     *     cabeçalho e **sem espaço** antes do número.
     * ➡️ Aqui o serviço entrega **`ordinal: 2`**; ele **não** entrega a string `"automática2"` nem
     * o cabeçalho. O cabeçalho é da **tela** (plano 188-08).
     *
     * ⚠️⚠️ **E O ORDINAL SAI DO MESMO CÁLCULO DO MARCADOR**, não de dois: duas numerações da mesma
     * verdade divergiriam, e a divergência apareceria como o texto dizendo *"ponto 2"* com o
     * marcador 2 em **outro defeito** — o pior erro possível aqui, porque **a pessoa reprova a foto
     * errada**. No protótipo os dois também saem do mesmo `al` (`:1108` usa
     * `String(al.indexOf(a) + 1)`; `:1109` usa `n + 1` do `al.map`).
     */
    public function test_as_verificacoes_saem_como_DADO_com_o_ordinal_DO_MARCADOR_188_03(): void
    {
        $empresa = Company::factory()->create();
        $servico = $this->servico();

        $oferta = $this->oferta($empresa, PubAprovacaoOferta::AGUARDANDO_APROVACAO);
        $conjunto = $this->conjunto($oferta);

        // ⚠️ FIXTURE QUE DISCRIMINA: o `ok` está **NO MEIO**, então uma numeração feita sobre a
        // lista INTEIRA daria `[1, 2, 3, 4]` em vez de `[1, null, 2, 3]` — e passaria num cenário
        // em que o `ok` fosse o último.
        $imagem = $this->imagem($conjunto, 0, [
            'estado' => PubAprovacaoImagem::PENDENTE,
            'alertas' => [
                [PubAprovacaoImagemAlerta::ALTO, 31.25, 68.5, 'A medida da foto não confere com a da descrição.'],
                [PubAprovacaoImagemAlerta::OK, null, null, 'Fundo branco e sem moldura.'],
                [PubAprovacaoImagemAlerta::MEDIO, 12.0, 90.0, 'O texto sobre a imagem está pequeno.'],
                // ⚠️ Alerta SEM ponto: ele **mantém** o ordinal (1..N é sobre os alertas) e **não**
                // vira marcador. **DIVERGÊNCIA DECLARADA:** no protótipo `pins` não filtra x/y
                // nulos e desenharia `left: "undefined%"`. Por desenho, alerta acima de `ok`
                // SEMPRE tem ponto (`PubAprovacaoImagemAlerta`, docblock), então este é o caso
                // anômalo — e renumerar aqui faria o texto dizer "3" com o marcador escrito "4".
                [PubAprovacaoImagemAlerta::MEDIO, null, null, 'Sem ponto apontado.'],
            ],
        ]);

        $fotos = $servico->para($oferta);
        $daImagem = $fotos['imagens'][0];

        // ─── O contrato de CADA verificação ─────────────────────────────────────────────────
        $this->assertCount(4, $daImagem['verificacoes'], 'a lista de verificações perdeu ou ganhou item');
        $faltando = [];
        foreach ($daImagem['verificacoes'] as $i => $v) {
            // ⚠️ Flag DENTRO do laço.
            foreach (['severidade', 'texto', 'glifo', 'cor', 'ordinal'] as $chave) {
                if (! array_key_exists($chave, $v)) {
                    $faltando[] = $i.'.'.$chave;
                }
            }
        }
        $this->assertSame([], $faltando, 'chave AUSENTE numa verificação');

        // ─── ⚠️⚠️ OS ORDINAIS: `1..N` SOBRE OS ALERTAS, e `null` no `ok` ───────────────────
        $this->assertSame([1, null, 2, 3], array_column($daImagem['verificacoes'], 'ordinal'), 'a numeração não é 1..N sobre os ALERTAS');

        // ⚠️ **NUNCA `0` nem `''`** — `0` é um número de marcador plausível para quem lê, e `''` é
        // mentira de tipo. ⚠️ **DIVERGÊNCIA DECLARADA do `:1108`**, que usa `''`.
        $doOk = $daImagem['verificacoes'][1];
        $this->assertNull($doOk['ordinal'], 'a verificação `ok` ganhou ordinal');
        $this->assertNotSame(0, $doOk['ordinal'], '`0` é um número de marcador plausível');
        $this->assertNotSame('', $doOk['ordinal'], 'string vazia num campo de número é mentira de tipo');
        $this->assertTrue(array_key_exists('ordinal', $doOk), 'chave nula ≠ chave ausente');

        // ─── ⚠️⚠️ O ORDINAL É O NÚMERO DO MARCADOR — mesmo cálculo, provado pelo PAR ────────
        $ordinaisDeTexto = array_values(array_filter(
            array_column($daImagem['verificacoes'], 'ordinal'),
            static fn (?int $o): bool => $o !== null,
        ));
        $this->assertSame([1, 2, 3], $ordinaisDeTexto, 'os ordinais dos alertas não são 1..N');

        // ⚠️ Os marcadores desenháveis são os DOIS primeiros (o 3º não tem ponto), e os números
        // deles são **os MESMOS** `1` e `2` do texto — nunca uma renumeração própria.
        $this->assertSame([1, 2], array_column($daImagem['marcadores'], 'ordinal'), 'o marcador renumerou por conta própria');

        // ⚠️⚠️ **A PROVA DE QUE É UM CÁLCULO SÓ:** os dois métodos públicos leem a MESMA lista
        // (`alertasOrdenados()`), e o ordinal é a POSIÇÃO nela.
        $alertas = $imagem->alertas->all();
        $somenteAlertas = $servico->alertasOrdenados($alertas);
        $this->assertCount(3, $somenteAlertas, 'a lista de alertas (sem `ok`) mudou de tamanho');
        $esperados = [];
        foreach ($somenteAlertas as $posicao => $alerta) {
            // ⚠️ Flag DENTRO do laço.
            $esperados[(string) $alerta->texto] = $posicao + 1;
        }
        $porTexto = [];
        foreach ($servico->verificacoes($alertas) as $v) {
            // ⚠️ Flag DENTRO do laço.
            if ($v['ordinal'] !== null) {
                $porTexto[$v['texto']] = $v['ordinal'];
            }
        }
        $this->assertSame($esperados, $porTexto, 'o ordinal do texto não é a POSIÇÃO na lista que os marcadores numeram');

        // ⚠️⚠️ **ISCA DO CÁLCULO ÚNICO: tirando o PRIMEIRO alerta, os dois ANDAM JUNTO.**
        //
        // ⚠️ A isca é formulada sobre a lista **SÓ COM ALERTAS QUE TÊM PONTO**, e isso é medido, não
        // conveniência: com um alerta sem ponto na lista, o texto mostra um número que **não** tem
        // marcador, então a igualdade seria FALSA por desenho (é a divergência declarada acima) e a
        // isca reprovaria o comportamento CERTO — a família "gate mais largo que o invariante".
        $comPonto = array_values(array_filter(
            $servico->alertasOrdenados($alertas),
            static fn (PubAprovacaoImagemAlerta $a): bool => $a->temPonto(),
        ));
        $this->assertCount(2, $comPonto, 'ISCA: a fixture não tem DOIS alertas com ponto — a isca mediria um caso trivial');

        $semOPrimeiro = array_values(array_slice($comPonto, 1));
        $this->assertSame(
            array_column($servico->marcadores(PubAprovacaoImagem::PENDENTE, $semOPrimeiro), 'ordinal'),
            array_values(array_filter(
                array_column($servico->verificacoes($semOPrimeiro), 'ordinal'),
                static fn (?int $o): bool => $o !== null,
            )),
            'ISCA: tirando um alerta, o texto e o marcador renumeraram DIFERENTE',
        );
        // ⚠️ E a isca NÃO é trivial: ela reprovaria uma renumeração própria do marcador.
        $this->assertSame([1], array_column($servico->marcadores(PubAprovacaoImagem::PENDENTE, $semOPrimeiro), 'ordinal'));

        // ⚠️⚠️ **A DIVERGÊNCIA DECLARADA, MEDIDA:** o alerta **sem ponto** mantém o ordinal no
        // texto (`3`) e **não** tem marcador — então os marcadores são um **SUBCONJUNTO** dos
        // ordinais do texto, nunca uma lista com número que o texto não mostre.
        $this->assertSame(
            [],
            array_values(array_diff(array_column($daImagem['marcadores'], 'ordinal'), $ordinaisDeTexto)),
            'há marcador cujo número NENHUM item de texto mostra',
        );
        $this->assertSame(
            [3],
            array_values(array_diff($ordinaisDeTexto, array_column($daImagem['marcadores'], 'ordinal'))),
            'o alerta SEM ponto deixou de manter o seu ordinal no texto (renumeração), ou ganhou marcador',
        );

        // ─── O GLIFO e as TRÊS cores distintas (`:1108`) ────────────────────────────────────
        $this->assertSame(
            [FotosDoPainelService::GLIFO_ALERTA, FotosDoPainelService::GLIFO_OK,
                FotosDoPainelService::GLIFO_ALERTA, FotosDoPainelService::GLIFO_ALERTA],
            array_column($daImagem['verificacoes'], 'glifo'),
            'o glifo é `✓` para `ok` e `!` para alerta',
        );
        $this->assertSame('✓', FotosDoPainelService::GLIFO_OK);
        $this->assertSame('!', FotosDoPainelService::GLIFO_ALERTA);

        $cores = array_column($daImagem['verificacoes'], 'cor');
        $this->assertSame(
            [FotosDoPainelService::COR_VERMELHA, FotosDoPainelService::COR_VERDE,
                FotosDoPainelService::COR_AMBAR, FotosDoPainelService::COR_AMBAR],
            $cores,
            '`high` vermelho, `ok` verde, `med` âmbar',
        );
        // ⚠️ **TRÊS valores DISTINTOS** — sem esta isca, um serviço que devolvesse sempre a mesma
        // cor passaria nos três ramos acima.
        $this->assertCount(3, array_unique($cores), 'as cores de `ok`, `med` e `high` colidiram');

        // ─── O TEXTO é o do banco, pronto para a tela — e NÃO vem montado ───────────────────
        $this->assertSame('A medida da foto não confere com a da descrição.', $daImagem['verificacoes'][0]['texto']);
        $infratores = array_values(array_filter(
            array_column($daImagem['verificacoes'], 'texto'),
            static fn (string $t): bool => str_contains(mb_strtolower($t), 'verificação autom') || str_contains($t, 'APOIO'),
        ));
        $this->assertSame([], $infratores, 'o serviço montou a FRASE: o prefixo e o cabeçalho são da TELA (plano 188-08)');

        // ─── ⚠️ `verificacoes` VAZIA é LISTA vazia, nunca ausente ───────────────────────────
        $limpa = $fotos['imagens'][0];
        $this->assertNotSame([], $limpa['verificacoes'], 'ISCA: a imagem da fixture não tem verificação');

        $semNada = $this->oferta($empresa, PubAprovacaoOferta::AGUARDANDO_APROVACAO);
        $this->imagem($this->conjunto($semNada), 0, ['estado' => PubAprovacaoImagem::PENDENTE]);
        $vazia = $this->servico()->para($semNada)['imagens'][0];
        $this->assertSame([], $vazia['verificacoes'], 'sem alerta a chave tem de existir como LISTA vazia');
        $this->assertTrue(array_key_exists('verificacoes', $vazia), 'chave vazia ≠ chave ausente');
        $this->assertSame([], $vazia['marcadores']);

        // ─── ⚠️ OS DOIS LADOS DA TERCEIRA DERROTA DO PROTÓTIPO, lidos do ARQUIVO ────────────
        $prototipo = $this->raiz().'/design_handoff_publicador/Aprovacao.dc.html';

        // ⚠️ `design_handoff_publicador/` NÃO é versionado: sem ele o caso **degrada para uma
        // razão NOMEADA**, nunca para "conferido".
        $degradacoes = [];
        if (! file_exists($prototipo)) {
            $degradacoes[] = 'design_handoff_publicador/Aprovacao.dc.html ausente (pacote de handoff NÃO versionado)';
        } else {
            $linhas = explode("\n", str_replace("\r", '', (string) file_get_contents($prototipo)));
            // `:369` TEM o literal — mas é a revisão detalhada (E4), da Fase 190/191.
            $this->assertStringContainsString('VERIFICAÇÃO AUTOMÁTICA · APOIO', $linhas[368] ?? '', ':369 deixou de ter o literal do cabeçalho');
            // `:633` é o que o PAINEL escreve — a frase COLADA, sem cabeçalho.
            $this->assertStringContainsString('Verificação automática{{ a.n }} · ', $linhas[632] ?? '', ':633 deixou de ter a frase colada do painel');
            $this->assertStringNotContainsString('APOIO', $linhas[632] ?? '', ':633 passou a ter o cabeçalho — a derrota do protótipo mudou de forma');
        }
        $this->assertSame([], $degradacoes, 'degradação NOMEADA: '.implode(' · ', $degradacoes));

        // ⚠️⚠️ E O SERVIÇO **NÃO** REPRODUZ A FRASE COLADA — lido **SEM** comentário, porque a
        // prosa dele cita `VERIFICAÇÃO AUTOMÁTICA · APOIO` de propósito (ISCA DO LEITOR).
        $fonte = $this->semComentarios('app/Services/Publicador/Aprovacao/Painel/FotosDoPainelService.php');
        $this->assertStringNotContainsString('VERIFICAÇÃO AUTOMÁTICA', $fonte, 'o cabeçalho é da TELA (plano 188-08)');
        $this->assertStringNotContainsString('Verificação automática', $fonte, 'o serviço reproduziu o prefixo colado de `:633`');
        $this->assertStringNotContainsString('APOIO', $fonte, 'o rótulo de APOIO é da tela');
        // ⚠️ ISCA DO LEITOR, nas duas direções: o arquivo CRU **TEM** os três termos.
        $cru = $this->cru('app/Services/Publicador/Aprovacao/Painel/FotosDoPainelService.php');
        $this->assertStringContainsString('VERIFICAÇÃO AUTOMÁTICA · APOIO', $cru, 'ISCA: a prosa do serviço não cita o cabeçalho — o leitor sem comentário não está sendo exercitado');
        $this->assertStringNotContainsString('VERIFICAÇÃO AUTOMÁTICA', $fonte, 'o leitor sem comentário não removeu o docblock');
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // TRANSVERSAIS
    // ═════════════════════════════════════════════════════════════════════════════════════════

    /**
     * Território e AUSÊNCIAS, lidas do código **SEM COMENTÁRIO**.
     *
     * ⚠️ Ler sem comentário não é zelo: a prosa deste projeto cita os próprios identificadores que
     * diz NÃO usar, e um gate cru reprovaria **pelo próprio conserto**. ⚠️ **ESTA PROSA CITA DE
     * PROPÓSITO** `#FACC15`, `ArquivosDeImagem`, `ImagemAssetService`, `Acervo`, `gold_pro`,
     * `desfazer`, `Ampliar`, `Gerar novamente` e `lucro líquido` — é a **ISCA DO LEITOR**.
     *
     * ⚠️⚠️ O gate de "lucro líquido" é recortado à **lista CONSTANTE dos arquivos deste plano**:
     * `lucro_liquido` é campo **legítimo** do Portal, e varrer o `app/` inteiro reprovaria trabalho
     * de outra sessão — foi a 5ª ocorrência de "gate mais largo que o invariante" na Fase 186, e
     * ela **BARROU UM DEPLOY**.
     */
    public function test_territorio_e_ausencias_do_plano_188_03(): void
    {
        // ⚠️ A lista CONSTANTE dos arquivos DESTE plano — nunca `app/` inteiro.
        $meus = [
            'app/Services/Publicador/Aprovacao/Painel/FotosDoPainelService.php',
            'app/Services/Publicador/Aprovacao/Painel/PainelDeRevisaoService.php',
        ];

        $fonte = '';
        foreach ($meus as $rel) {
            // ⚠️ Flag DENTRO do laço.
            $fonte .= $this->semComentarios($rel);
        }

        // ⚠️ ISCA DO RECORTE: a lista não está vazia e cada entrada EXISTE no disco.
        $this->assertCount(2, $meus);
        $this->assertNotSame('', trim($fonte), 'o leitor devolveu vazio — o gate mediria NADA');

        // ─── VOC-03, COL-02 e TOK-15 ─────────────────────────────────────────────────────────
        $this->assertStringNotContainsString('lucro', mb_strtolower($fonte), 'VOC-03: "lucro líquido" é proibido');
        $this->assertStringNotContainsString('localStorage', $fonte, 'COL-02: filtro e oferta aberta vivem na URL');
        // ⚠️ TOK-15 — nenhum HEX sai do PHP: o `content` do Tailwind **NÃO** varre `.php`, então a
        // classe não geraria CSS. A cor é CHAVE SEMÂNTICA.
        $this->assertDoesNotMatchRegularExpression('/#[0-9A-Fa-f]{6}\b/', $fonte, 'hex no PHP: a classe não seria varrida pelo Tailwind');

        // ─── ⚠️⚠️ REUSAR NÃO É EDITAR: o ACERVO fica INTOCADO (v28, R2 PRIVADO) ─────────────
        foreach (['ArquivosDeImagem', 'ImagemAssetService', 'Acervo', 'App\\Support\\Publicador'] as $vetado) {
            // ⚠️ Flag DENTRO do laço.
            $this->assertStringNotContainsString($vetado, $fonte, "território VETADO referenciado: {$vetado}");
        }

        // ─── APROVAR JÁ PUBLICA: a ausência de segundo passo, na FORMA ───────────────────────
        foreach (['desfazer', 'confirmar publica', 'cancelar aprova', 'segundo passo'] as $proibido) {
            // ⚠️ Flag DENTRO do laço.
            $this->assertStringNotContainsString($proibido, mb_strtolower($fonte), 'decisão do usuário 14/10/2026: aprovar JÁ PUBLICA');
        }

        // ─── ⚠️ AS FASES 189 E 192 **NÃO** SÃO ANTECIPADAS ──────────────────────────────────
        //
        // ⚠️ "⤢ Ampliar" é o lightbox (Fase 189) e "✦ Gerar novamente com IA" é a Fase 192. D4/D14:
        // nenhuma fase deixa botão sem destino na sua fronteira.
        foreach (['Ampliar', 'Gerar novamente', '⤢', '✦'] as $deOutraFase) {
            // ⚠️ Flag DENTRO do laço.
            $this->assertStringNotContainsString($deOutraFase, $fonte, "a Fase 189/192 foi antecipada: {$deOutraFase}");
        }

        // ⚠️ ISCA DAS SONDAS, nas duas direções.
        $this->assertStringContainsString('lucro', mb_strtolower('O lucro líquido do mês'), 'a sonda de lucro está cega');
        $this->assertMatchesRegularExpression('/#[0-9A-Fa-f]{6}\b/', 'border: #FACC15;', 'a sonda de hex está cega');
        $this->assertDoesNotMatchRegularExpression('/#[0-9A-Fa-f]{6}\b/', "self::COR_VERDE;", 'a sonda de hex acusa a chave semântica — ela está larga');
        $this->assertStringContainsString('desfazer', mb_strtolower('Botão de Desfazer'), 'a sonda de segundo passo está cega');
        $this->assertStringContainsString('Ampliar', '⤢ Ampliar', 'a sonda da Fase 189 está cega');
        $this->assertStringContainsString('Acervo', 'use App\\Services\\Publicador\\AcervoImagemService;', 'a sonda do acervo está cega');

        // ─── ⚠️ ESTE SERVIÇO NÃO ESCREVE NADA ───────────────────────────────────────────────
        $doServico = $this->semComentarios('app/Services/Publicador/Aprovacao/Painel/FotosDoPainelService.php');
        $sondaDeEscrita = "/->estado\s*=(?!=)|->andamento\s*=(?!=)|(?:update|updateOrCreate|create|fill|insert|forceFill|save|delete)\s*\(/";
        $this->assertSame(0, preg_match($sondaDeEscrita, $doServico), 'o serviço da coluna de fotos ESCREVE — ele só pode LER');
        // ⚠️ ISCA: a sonda de escrita PEGA, nas duas direções.
        $this->assertSame(1, preg_match($sondaDeEscrita, '$i->update([1]);'), 'a sonda de escrita está cega');
        $this->assertSame(1, preg_match($sondaDeEscrita, "\$i->estado = 'approved';"), 'a sonda não pega a atribuição de estado');
        $this->assertSame(0, preg_match($sondaDeEscrita, "return ['estado' => (string) \$i->estado];"), 'a sonda acusa a chave de SAÍDA — não discrimina expor de escrever');

        // ─── A 186/187 É CHAMADA, NUNCA RECOPIADA ───────────────────────────────────────────
        $this->assertStringContainsString('contagemDeFotos', $doServico, 'o progresso tem de CHAMAR `contagemDeFotos()`');
        $this->assertStringContainsString('doRascunho', $doServico, 'a galeria tem de vir de `GaleriaDaOfertaService::doRascunho()`');
        $this->assertStringContainsString('ehPrincipal', $doServico, 'IMG-07: a principal é LIDA do modelo');
        $this->assertStringContainsString('ehAlerta', $doServico, 'a definição de alerta é LIDA do modelo');
        $this->assertStringContainsString('temPonto', $doServico, 'o "tem ponto" é LIDO do modelo');
        $this->assertStringContainsString('ehLive', $doServico, 'AND-05: o `live` é LIDO do modelo');
        $this->assertStringContainsString("route('mlb.anuncios.publicador.fotos.arquivo'", $doServico, 'B1.2: a URL tem de sair da rota que já existe');

        // ⚠️ E os literais da 186/187 NÃO foram recopiados.
        $this->assertStringNotContainsString("'Capa'", $doServico, 'o rótulo da capa foi RECOPIADO (ele é `GaleriaDaOfertaService::ROTULO_CAPA`)');
        $this->assertStringNotContainsString("'a conferir'", $doServico, 'o veredicto do `title` foi RECOPIADO');
        $this->assertStringNotContainsString("'gold_pro'", $doServico, 'o listing type foi escrito à mão — seria a TERCEIRA cópia daquele literal');
        $this->assertStringNotContainsString("'pending'", $doServico, 'o estado foi escrito à mão em vez de LIDO da constante');
        $this->assertStringNotContainsString("'approved'", $doServico, 'o estado foi escrito à mão em vez de LIDO da constante');
        $this->assertStringNotContainsString("'high'", $doServico, 'a severidade foi escrita à mão em vez de LIDA da constante');

        // ─── O ESCOPO DO ASSUNTO, com ISCA nas duas direções ────────────────────────────────
        //
        // ⚠️⚠️ **"Prova de território por RANGE" NÃO PASSA nesta árvore:** a 187-11 mediu 111
        // commits no range, **43 da sessão paralela**, e o diff cru acusava **12 dos 60 alvos
        // vetados**. ⚠️ **E NÃO se confere o AUTOR:** as três máquinas usam o MESMO `user.name`.
        $this->assertSame(1, preg_match(self::ESCOPO, 'feat(188-03): x'), 'o escopo não casa o próprio assunto');
        $this->assertSame(0, preg_match(self::ESCOPO, 'feat(188-02): x'), 'o escopo casa a wave 2 — ele está largo');
        $this->assertSame(0, preg_match(self::ESCOPO, 'feat(187-03): x'), 'o escopo casa a Fase 187 — ele está largo');
        $this->assertSame(0, preg_match(self::ESCOPO, 'fix(261016-mwe): x'), 'o escopo casa a outra máquina — ele está largo');

        $log = $this->git('git log --format=%h:%s '.self::PARTIDA.'..HEAD');
        $hashes = [];
        foreach (array_filter(explode("\n", $log)) as $linha) {
            // ⚠️ Flag DENTRO do laço.
            $corte = strpos($linha, ':');
            if ($corte !== false && preg_match(self::ESCOPO, substr($linha, $corte + 1)) === 1) {
                $hashes[] = substr($linha, 0, $corte);
            }
        }

        // ⚠️ **SEM `return` antecipado:** a contagem deste caso NÃO pode depender de quantos
        // commits existem (a lição medida no 187-07, onde ela ia de 26 para 70).
        $arquivos = $hashes === []
            ? []
            : array_values(array_filter(array_map(
                'trim',
                explode("\n", $this->git('git show --name-only --format= '.implode(' ', $hashes))),
            )));

        // ⚠️ A lista dos VETADOS — `188-REGUA.md` §9.
        $proibidos = [
            // Os 10 da Fase 186 em `Aprovacao/` (fora de `Fila/` e de `Painel/`).
            'app/Services/Publicador/Aprovacao/BloqueiosDaOfertaService.php',
            'app/Services/Publicador/Aprovacao/AndamentoDaOfertaService.php',
            'app/Services/Publicador/Aprovacao/VocabularioDoPortao.php',
            'app/Services/Publicador/Aprovacao/ComposicaoFinanceiraService.php',
            'app/Services/Publicador/Aprovacao/ComposicaoDaOferta.php',
            'app/Services/Publicador/Aprovacao/AprovacaoDaOfertaService.php',
            'app/Services/Publicador/Aprovacao/RevisaoDeImagemService.php',
            'app/Services/Publicador/Aprovacao/AjustesDaOfertaService.php',
            'app/Services/Publicador/Aprovacao/HistoricoDaOfertaService.php',
            'app/Services/Publicador/Aprovacao/LinhaFinanceira.php',
            // Os 8 de `Fila/` da Fase 187.
            'app/Services/Publicador/Aprovacao/Fila/FilaDeAprovacaoService.php',
            'app/Services/Publicador/Aprovacao/Fila/ProximoARevisarService.php',
            'app/Services/Publicador/Aprovacao/Fila/FiltrosDaFilaService.php',
            'app/Services/Publicador/Aprovacao/Fila/GrupoDaFilaService.php',
            'app/Services/Publicador/Aprovacao/Fila/GaleriaDaOfertaService.php',
            'app/Services/Publicador/Aprovacao/Fila/LinhaDaOfertaService.php',
            'app/Services/Publicador/Aprovacao/Fila/LoteDaFilaService.php',
            'app/Services/Publicador/Aprovacao/Fila/AquecimentoDeTarifasService.php',
            // Os modelos do portão (Fase 186) e os `.jsx` NO AR.
            'app/Models/PubAprovacaoImagem.php',
            'app/Models/PubAprovacaoImagemAlerta.php',
            'app/Models/PubAprovacaoConjunto.php',
            'resources/js/Components/Mlb/Publicador/aprovacao',
            'resources/js/Components/Mlb/Publicador/publicacoes',
            'resources/js/Components/Mlb/Publicador/identidade',
            'resources/js/Components/Publicador/Mesa',
            'resources/js/Layouts/AppLayout.jsx',
            'resources/js/Pages/Mlb/Publicador/Editor.jsx',
            // ⚠️ O ACERVO (v28, R2 PRIVADO), o Creative Engine, o MCP, o Support e o Portal.
            'app/Services/Creative',
            'app/Services/Publicador/Criativos',
            'app/Services/Publicador/ImagemAssetService.php',
            'app/Support/Publicador',
            'app/Http/Controllers/MlbPublicadorController.php',
            'app/Mcp',
            'app/Services/Portal',
            'app/Services/Mlb/Publicacao/MlPublicacaoService.php',
            'app/Services/Publicador/Fila/ResumoRapidoService.php',
            'app/Services/Publicador/Fila/AgendadorDaFila.php',
            'app/Services/Publicador/PreparoIaDoRascunhoService.php',
            'app/Services/Publicador/ExcluirProdutoService.php',
            'routes/mlb_anuncios.php',
            // Os gates INTOCÁVEIS.
            'tests/js/publicador-header-zindex-ordem.test.js',
            'tests/js/estrutura-meus-anuncios.test.js',
            'tests/js/publicador-fila-tokens-187.test.js',
            'tests/js/publicador-fila-proximo-187.test.js',
            // ⚠️ GATE-07 — nenhum destes pertence a plano nenhum; quem edita é o orquestrador.
            '.planning/ROADMAP.md',
            '.planning/REQUIREMENTS-v27.md',
            '.planning/STATE.md',
        ];

        // ⚠️⚠️ **UMA asserção coletando os infratores**, nunca uma por caminho.
        $tocados = [];
        foreach ($arquivos as $a) {
            // ⚠️ Flag DENTRO do laço.
            foreach ($proibidos as $p) {
                if ($a === $p || str_starts_with($a, rtrim($p, '/').'/')) {
                    $tocados[] = $a;
                }
            }
        }
        $this->assertSame([], array_values(array_unique($tocados)), 'commit do escopo (188-03) tocou alvo VETADO');

        // ⚠️ A prova de que o escopo não está vazio por construção, COLETADA.
        $doPlano = ['Aprovacao/Painel/FotosDoPainel', 'Aprovacao/Painel/PainelDeRevisao',
            'FotosDoPainelServiceTest', 'PainelDeRevisaoServiceTest', 'PainelDaOfertaRotaTest',
            'contagens-188-03', '188-03-SUMMARY', '188-FID01'];
        $reconhece = static fn (string $a): bool => (bool) array_filter($doPlano, static fn (string $p): bool => str_contains($a, $p));

        $semProva = $hashes !== [] && ! array_filter($arquivos, $reconhece)
            ? ['os commits do escopo não tocaram arquivo deste plano: '.json_encode(array_slice($arquivos, 0, 8))]
            : [];
        $this->assertSame([], $semProva, 'o range do escopo está vazio por construção');

        // ⚠️ ISCA DA PROVA, nas duas direções.
        $this->assertTrue($reconhece('app/Services/Publicador/Aprovacao/Painel/FotosDoPainelService.php'), 'a prova não reconhece o serviço deste plano');
        $this->assertFalse($reconhece('app/Services/Creative/CreativeJuiz.php'), 'a prova reconhece arquivo de OUTRA fase');

        // ⚠️ E o ANTI-FANTASMA, que é o gate PERENE (`test -e` em cada alvo citado) — são NOVE
        // caminhos fantasmas nesta milestone, TRÊS vindos de briefing.
        $semDisco = array_values(array_filter(
            $proibidos,
            fn (string $p): bool => ! file_exists($this->raiz().'/'.$p) && ! str_contains($p, 'REQUIREMENTS-v27'),
        ));
        $this->assertSame([], $semDisco, 'caminho FANTASMA na lista de vetados — o gate dele mede NADA');

        // ⚠️ **T-188-SC — este plano NÃO instala nada**, provado por FORMA (nunca por `numstat` de
        // range): nenhum manifesto de dependência aparece entre os arquivos do escopo.
        $this->assertSame(
            [],
            array_values(array_filter($arquivos, static fn (string $a): bool => in_array($a, ['composer.json', 'composer.lock', 'package.json', 'package-lock.json'], true))),
            'T-188-SC: este plano não instala nada',
        );

        // ⚠️ E **NENHUMA MIGRATION**: esta fase não tem migration (o aviso é explícito no briefing).
        $this->assertSame(
            [],
            array_values(array_filter($arquivos, static fn (string $a): bool => str_starts_with($a, 'database/migrations/'))),
            'esta fase NÃO tem migration',
        );
    }

    /**
     * ⚠️ A BIJEÇÃO ids ↔ métodos, nos **DOIS** sentidos.
     *
     * ⚠️⚠️ **O SUFIXO `_188_03` NÃO É ZELO.** O `--filter` do PHPUnit é regex **NÃO ANCORADO**, e
     * todo plano `_0N` desta fase tem homônimo possível nas Fases 186 **E** 187, as duas **EM
     * PRODUÇÃO**. Medido por script em 19/10/2026 (`ARQUIVOS=905 METODOS=7739 HOMONIMOS=0`), e a
     * direção 1 **acusou 6** métodos reais com o nome sem sufixo — prova de que a colisão é real.
     *
     * ⚠️ **E HÁ UMA TERCEIRA BIJEÇÃO: id ↔ `<verify>`.** Um id pode estar no manifesto, com
     * contagem medida, apontando para métodos que existem — e **não ser rodado por `<verify>`
     * nenhum**. Ele não falha: **nunca roda**. Quem a prova sobre os treze pares é o plano
     * **188-13**; aqui provo que os QUATRO ids deste manifesto existem e casam método.
     */
    public function test_bijecao_do_manifesto_das_fotos_188_03(): void
    {
        $manifesto = $this->raiz().'/tests/gates/contagens-188-03.txt';
        $this->assertFileExists($manifesto, 'manifesto ausente — os gates deste plano rodariam sem régua');

        $linhas = array_values(array_filter(
            array_map('trim', explode("\n", (string) file_get_contents($manifesto))),
            static fn (string $l): bool => $l !== '' && ! str_starts_with($l, '#'),
        ));

        $filtrosDosIds = [];
        foreach ($linhas as $linha) {
            // ⚠️ Flag DENTRO do laço.
            $partes = preg_split('/\s+/', $linha);
            if (($partes[1] ?? '') === 'php') {
                $this->assertArrayNotHasKey($partes[0], $filtrosDosIds, 'id REPETIDO no manifesto: o harness pega o PRIMEIRO');
                $filtrosDosIds[$partes[0]] = $partes[2];
            }
        }

        // ⚠️ Lista CONSTANTE dos QUATRO, nunca `count()` — **piso não prova PRESENÇA** (a 187-11
        // mediu uma sobrevivente exatamente aí: com 13 itens, perder um deixava 12 e o piso
        // passava). ⚠️ O conserto é lista constante, NUNCA elevar o piso.
        $this->assertSame(
            ['galeria-e-marcadores', 'borda-e-estados', 'sem-alerta-e-contagem', 'verificacoes-e-ordinal'],
            array_keys($filtrosDosIds),
            'os QUATRO ids deste plano, nesta ordem — id a mais ou a menos muda o que roda',
        );

        // ─── Os métodos, lidos do DISCO ─────────────────────────────────────────────────────
        $rel = 'tests/Feature/Publicador/Aprovacao/FotosDoPainelServiceTest.php';
        preg_match_all('/function (test_[A-Za-z0-9_]*_188_03)\s*\(/', $this->cru($rel), $m);
        $metodos = array_values(array_unique($m[1]));

        $this->assertCount(12, $metodos, 'os métodos `_188_03` deste plano encolheram ou cresceram');

        // ⚠️ SENTIDO 1 — método sem id **NUNCA RODA** em gate nenhum (foi o sobrevivente da
        // mutação do 186-03). UMA asserção coletando.
        $semId = [];
        foreach ($metodos as $metodo) {
            // ⚠️ Flag DENTRO do laço.
            $achou = false;
            foreach ($filtrosDosIds as $filtro) {
                if (preg_match('/^'.$filtro.'$/', $metodo) === 1) {
                    $achou = true;
                    break;
                }
            }
            if (! $achou) {
                $semId[] = $metodo;
            }
        }
        $this->assertSame([], $semId, 'método sem id no manifesto: ele NUNCA RODA em gate nenhum');

        // ⚠️ SENTIDO 2 — id órfão roda sobre **ZERO casos** e é indistinguível de gate verde.
        $orfaos = [];
        foreach ($filtrosDosIds as $id => $filtro) {
            // ⚠️ Flag DENTRO do laço.
            $casou = false;
            foreach ($metodos as $metodo) {
                if (preg_match('/^'.$filtro.'$/', $metodo) === 1) {
                    $casou = true;
                    break;
                }
            }
            if (! $casou) {
                $orfaos[] = $id;
            }
        }
        $this->assertSame([], $orfaos, 'id órfão no manifesto: o gate dele roda sobre ZERO casos');

        // ⚠️ **NENHUM id com mais de TRÊS métodos** (alternância grande equivale a filtro de classe,
        // passa dos 120 s do shell e o helper RECUSA). UMA asserção coletando.
        $gordos = [];
        foreach ($filtrosDosIds as $id => $filtro) {
            // ⚠️ Flag DENTRO do laço.
            $quantos = count(array_filter($metodos, static fn (string $mm): bool => preg_match('/^'.$filtro.'$/', $mm) === 1));
            if ($quantos > 3) {
                $gordos[] = $id.' ('.$quantos.')';
            }
        }
        $this->assertSame([], $gordos, 'id com mais de TRÊS métodos: o harness RECUSA e o shell estoura');

        // ⚠️ E nenhum id com `tests=0` / sem `tests=` (o harness ABORTA, de propósito).
        $semContagem = array_values(array_filter(
            $linhas,
            static fn (string $l): bool => ! str_contains($l, 'tests=') || str_contains($l, 'tests=0'),
        ));
        $this->assertSame([], $semContagem, 'linha sem `tests=` ou com `tests=0`: o harness ABORTA');

        // ⚠️ ISCA DO NOME QUE NINGUÉM ESCREVEU, nas duas direções.
        $casouFantasma = array_values(array_filter(
            $filtrosDosIds,
            static fn (string $f): bool => preg_match('/^'.$f.'$/', 'test_metodo_que_ninguem_escreveu_188_03') === 1,
        ));
        $this->assertSame([], $casouFantasma, 'ISCA: um filtro casa método que ninguém escreveu — ele está largo');
        $this->assertSame(1, preg_match('/^'.$filtrosDosIds['verificacoes-e-ordinal'].'$/', 'test_territorio_e_ausencias_do_plano_188_03'), 'ISCA: o filtro não casa o próprio método');

        // ⚠️⚠️ **E O SUFIXO PROTEGE DE VERDADE:** os métodos homônimos das outras waves **existem**,
        // e o meu filtro **NÃO** os casa. Sem estas linhas, o sufixo seria zelo não medido.
        foreach (['test_territorio_e_ausencias_do_plano_188_02', 'test_territorio_e_ausencias_do_plano_187_08'] as $homonimo) {
            // ⚠️ Flag DENTRO do laço.
            $this->assertSame(0, preg_match('/^'.$filtrosDosIds['verificacoes-e-ordinal'].'$/', $homonimo), "o filtro casa o homônimo {$homonimo} — as contagens SOMARIAM");
        }
        $this->assertStringContainsString(
            'test_territorio_e_ausencias_do_plano_188_02',
            $this->cru('tests/Feature/Publicador/Aprovacao/PainelDeRevisaoServiceTest.php'),
            'o homônimo da wave 2 mudou de nome — a isca perdeu o sujeito',
        );
        $this->assertStringContainsString(
            'test_territorio_e_ausencias_do_plano_187_08',
            $this->cru('tests/Unit/Publicador/Aprovacao/LinhaDaOfertaServiceTest.php'),
            'o homônimo da 187 mudou de nome — a isca perdeu o sujeito',
        );

        // ⚠️ E o 1º ARGUMENTO do harness é `188-03`: o número NU resolveria `contagens-186-03.txt`,
        // a fase ERRADA, em silêncio. O manifesto da 186 com esse número **existe**.
        $this->assertFileExists($this->raiz().'/tests/gates/contagens-186-03.txt', 'a isca do número nu perdeu o sujeito — a confusão deixaria de ser possível');
    }
}
