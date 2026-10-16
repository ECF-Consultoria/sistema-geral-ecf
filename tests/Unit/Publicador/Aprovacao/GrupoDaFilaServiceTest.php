<?php

namespace Tests\Unit\Publicador\Aprovacao;

use App\Models\Company;
use App\Models\PubAprovacaoConjunto;
use App\Models\PubAprovacaoImagem;
use App\Models\PubAprovacaoImagemAlerta;
use App\Models\PubAprovacaoOferta;
use App\Models\PubImagem;
use App\Models\PubProduto;
use App\Models\PubRascunho;
use App\Models\PubRascunhoAlvo;
use App\Models\PubRascunhoAtributo;
use App\Services\Publicador\Aprovacao\AjustesDaOfertaService;
use App\Services\Publicador\Aprovacao\BloqueiosDaOfertaService;
use App\Services\Publicador\Aprovacao\ComposicaoFinanceiraService;
use App\Services\Publicador\Aprovacao\Fila\FilaDeAprovacaoService;
use App\Services\Publicador\Aprovacao\Fila\GaleriaDaOfertaService;
use App\Services\Publicador\Aprovacao\Fila\GrupoDaFilaService;
use App\Support\Publicador\Variacao\ChaveCanonica;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 * Fase 187, plano 07 — A GALERIA ORDENADA e OS TRÊS NÍVEIS da tabela (níveis 1 e 2).
 *
 * ⚠️⚠️ DE CADA GATE DESTE ARQUIVO, A PERGUNTA RESPONDIDA ANTES DE ELE EXISTIR:
 * **"o que precisaria estar errado no mundo para ele falhar?"** — gate cuja resposta é "nada" é
 * decorativo e não entra (`feedback_gate_vazio_por_construcao`).
 *
 *   · **a galeria divergir do bloqueio** — a definição de "galeria final" vive num método
 *     **PRIVADO** e **INTOCÁVEL** (`BloqueiosDaOfertaService::galeriaDoRascunho()`), então são
 *     duas leituras da mesma verdade por construção. A divergência apareceria na tela como
 *     *"5 de 5 fotos aprovadas"* COM um bloqueio de fotos de pé. Há caso comparando os **quatro**
 *     totais em **6 cenários**, com **isca invertida** (galeria de rascunho COM conjuntos é
 *     NÃO-vazia: se viesse vazia, "deu tudo igual" seria a varredura cega).
 *   · **a ordem dos grupos** — alguém ordenar por `id` puro e o `GENERAL` sair do lugar. Há caso
 *     com isca: trocando a ordem dos ids, a saída MUDA.
 *   · **o custo** — ler alerta por `temAlerta()` (uma consulta POR IMAGEM, o N+1 clássico) ou
 *     chamar a galeria uma vez por OFERTA em vez de por RASCUNHO. Há caso de contagem FIXA de
 *     consultas, com isca de que a sonda conta.
 *   · **`quantidade_kit`** — alguém usar `ehKit()` (que exige TAMBÉM `produto_base_id`) para
 *     decidir "Unidade"/"Combo ×N". Um kit de 2 sem base diria "Unidade", afirmando FALSAMENTE o
 *     que o comprador recebe. Há isca nas duas direções.
 *   · **a frase do cadastro** — alguém imprimir *"Cadastro completo · categoria e ficha técnica
 *     organizadas"* como DECORAÇÃO. É afirmação sobre o cadastro e **só pode aparecer quando é
 *     VERDADE** (D4 / EXMP-01, confirmado pelo usuário em 15/10/2026). Há caso para cada uma das
 *     três maneiras de ela ser falsa.
 *   · **`compartilhado`** — alguém pendurá-lo no PRODUTO e não no CONJUNTO: o pill
 *     *"Mesmas fotos em Clássico e Premium"* apareceria num conjunto de uma oferta só.
 *   · **a ordem dos GRUPOS** — alguém ordenar alfabeticamente. O protótipo usa a ordem de
 *     **primeira aparição** na lista já ordenada pela fila (`:1257`, `order.push(p)`), e é isso
 *     que faz o grupo da oferta mais urgente vir primeiro. Há isca mudando o andamento.
 *
 * ⚠️⚠️ **PONTEIROS MEDIDOS NESTA SESSÃO (16/10/2026), e o plano errava em 11 de 17.** O
 * agrupamento do protótipo é `:1256`-`:1262` (o plano escreveu `:1266`-`:1273`):
 *   `order`/`byP` → `:1256`-`:1257` · `setKeys.sort(units)` → `:1258` (plano `:1269`) ·
 *   `reduce` do count → `:1259` (plano `:1270`) · `border: gi ? …` → `:1260` (plano `:1270`) ·
 *   Clássico antes de Premium → `:1261` (plano `:1272`) · `shared: setOffers(sk).length > 1` →
 *   `:1262` (plano `:1272`) · `label` Unidade/Combo e `progress` → `:1262`.
 *
 * ⚠️ **ZERO REDE:** `Http::preventStrayRequests()`. Nenhuma chamada à API real do ML sai daqui.
 *
 * ⚠️ **ESTE ARQUIVO NÃO ALTERA NADA DA FASE 186.** `BloqueiosDaOfertaService`,
 * `PubAprovacaoConjunto`, `PubAprovacaoImagem` e `PubAprovacaoImagemAlerta` são **LIDOS**.
 *
 * ⚠️ A fila **nasce vazia** e isso é honesto: nada cria `PubAprovacaoOferta` em produção hoje —
 * quem passa a criar é a **Fase 194**. Estes casos montam o grafo real à mão, porque é a única
 * forma de medir — e **fixture não é fonte de tela**.
 *
 * ⚠️ **Nenhum executor desta fase viu esta tela renderizada:** o banco local não tem empresa com
 * `ml_token` (a rota dá 404 aqui). A conferência é do usuário, em produção, na conta 459.
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 */
class GrupoDaFilaServiceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * O commit de PARTIDA deste plano.
     *
     * ⚠️⚠️ **REMEDIDO em 16/10/2026, e o motivo importa:** a partida original era `963e55d0`, mas
     * a OUTRA MÁQUINA enviou 8 commits (as quatro quicks `261016-lu1/mgv/mwe/imr` e a aba "Criadas"
     * do Portal) e o orquestrador integrou dois merges — o HEAD virou `07d87a56`. Mantendo a
     * partida velha, o range `PARTIDA..HEAD` **abraçaria o trabalho das outras máquinas** e este
     * gate acusaria a sessão paralela de sair do território desta wave. É literalmente o que
     * aconteceu em 15/10/2026 com um gate da Fase 186 por range aberto, e deixou a suíte com uma
     * falha herdada (`187-BASELINES.md` §2.2).
     */
    private const PARTIDA = '07d87a56';

    /**
     * O ESCOPO DO ASSUNTO deste plano.
     *
     * ⚠️ **O autor NÃO discrimina nada nesta árvore:** as três sessões rodam com o MESMO
     * `user.name` (`MB.ECF-100376`), então `git log --format=%an` devolve um valor só para tudo.
     * O discriminador real é o escopo do assunto — e há isca nas duas direções.
     */
    private const ESCOPO = '/\(187-07\)/';

    /** Clássico e Premium — `EstruturaPublicacao::LISTING_TYPES`, por constante do projeto. */
    private const CLASSICO = 'gold_special';

    private const PREMIUM = 'gold_pro';

    /** Contador: `alvo_id` é UNIQUE, então cada oferta precisa do seu alvo. */
    private int $sequencia = 0;

    protected function setUp(): void
    {
        parent::setUp();

        // ⚠️ A ordenação da fila usa `entrou_na_fila_em` contra o agora: sem o agora PARADO, o
        // caso vira dependente da hora do dia.
        Carbon::setTestNow('2026-10-16 09:00:00');

        // ⚠️ O app token do `ClienteMlPublicador` é FINGIDO no cache: sem ele a primeira chamada
        // pública tenta `/oauth/token` e LANÇA. Mesmo preparo do plano 187-06.
        Cache::put('ml_app_token_coleta', 'fake-app-token', 3600);

        // ⚠️ ZERO REDE: qualquer chamada não fingida EXPLODE, em vez de sair para a internet.
        Http::preventStrayRequests();
        Http::fake(fn () => Http::response(['message' => 'sem stub no teste'], 404));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ─────────────────────────────────────────────────────────────────────────────────────────
    // Fixture: o grafo REAL — produto -> rascunho -> alvo -> oferta, e conjunto -> imagem.
    //
    // ⚠️⚠️ **A FIXTURE TEM DE DISCRIMINAR.** Na Fase 186 duas mutações sobreviveram **não** por
    // falha da asserção, mas porque a fixture tinha UMA oferta só: "a conta" e "as ao vivo" davam
    // o mesmo número. Aqui os casos montam vários produtos, conjuntos com 1 e com N ofertas, e
    // ofertas `preparando`/`publicado` ao lado das ao vivo.
    // ─────────────────────────────────────────────────────────────────────────────────────────

    /** @param array{produto?: string, sku?: string, kit?: int, base?: ?PubProduto, categoria?: ?string} $o */
    private function produto(Company $empresa, array $o = []): PubProduto
    {
        $this->sequencia++;

        return PubProduto::create([
            'company_id' => $empresa->id,
            'sku' => $o['sku'] ?? ('GRP-'.$this->sequencia),
            'nome' => $o['produto'] ?? 'Poltrona Beny',
            'origem' => PubProduto::ORIGEM_PUBLICADOR,
            'quantidade_kit' => $o['kit'] ?? 1,
            'produto_base_id' => isset($o['base']) ? $o['base']->id : null,
            'fase' => $o['kit'] ?? 1,
        ]);
    }

    private function rascunho(PubProduto $produto, ?string $categoria = null): PubRascunho
    {
        return PubRascunho::create(array_filter([
            'produto_id' => $produto->id,
            'categoria_id' => $categoria,
        ], static fn ($v) => $v !== null));
    }

    /** @param array{titulo?: ?string, espera?: ?string, tocado?: bool} $o */
    private function oferta(
        Company $empresa,
        PubProduto $produto,
        PubRascunho $rascunho,
        string $tipo,
        string $andamento,
        array $o = [],
    ): PubAprovacaoOferta {
        $alvo = PubRascunhoAlvo::create([
            'rascunho_id' => $rascunho->id,
            'listing_type_id' => $tipo,
            'titulo' => array_key_exists('titulo', $o) ? $o['titulo'] : ($produto->nome.' Decorativa Veludo'),
            'posicao' => $tipo === self::CLASSICO ? 0 : 1,
        ]);

        return PubAprovacaoOferta::create([
            'alvo_id' => $alvo->id,
            'rascunho_id' => $rascunho->id,
            'produto_id' => $produto->id,
            'company_id' => $empresa->id,
            'andamento' => $andamento,
            'tocado' => $o['tocado'] ?? false,
            'entrou_na_fila_em' => $o['espera'] ?? '2026-10-16 07:00:00',
        ])->fresh();
    }

    private function conjunto(PubRascunho $rascunho, string $grupoChave = PubAprovacaoConjunto::GERAL): PubAprovacaoConjunto
    {
        return PubAprovacaoConjunto::create([
            'rascunho_id' => $rascunho->id,
            'grupo_chave' => $grupoChave,
            'grupo_hash' => ChaveCanonica::hash($grupoChave.'-'.$rascunho->id),
        ]);
    }

    /** @param array{tipo?: string, alerta?: ?string, fora?: bool} $o */
    private function imagem(
        PubAprovacaoConjunto $conjunto,
        string $estado = PubAprovacaoImagem::PENDENTE,
        ?int $posicao = 0,
        array $o = [],
    ): PubAprovacaoImagem {
        $crua = PubImagem::create(['rascunho_id' => $conjunto->rascunho_id]);

        $imagem = PubAprovacaoImagem::create([
            'conjunto_id' => $conjunto->id,
            'imagem_id' => $crua->id,
            'tipo' => $o['tipo'] ?? PubAprovacaoImagem::TIPO_ORIGINAL,
            'estado' => $estado,
            'posicao' => $posicao,
            'fora' => $o['fora'] ?? false,
        ]);

        if (($o['alerta'] ?? null) !== null) {
            PubAprovacaoImagemAlerta::create([
                'aprovacao_imagem_id' => $imagem->id,
                'severidade' => $o['alerta'],
                'texto' => 'A quantidade de peças na foto não confere com a do kit.',
            ]);
        }

        return $imagem;
    }

    private function galeria(): GaleriaDaOfertaService
    {
        return app(GaleriaDaOfertaService::class);
    }

    private function grupos(): GrupoDaFilaService
    {
        return app(GrupoDaFilaService::class);
    }

    private function fila(): FilaDeAprovacaoService
    {
        return app(FilaDeAprovacaoService::class);
    }

    /**
     * `BloqueiosDaOfertaService` DE VERDADE, para a comparação dos totais ser uma MEDIÇÃO.
     *
     * ⚠️ Instanciado à mão (e não `app()`) para ficar explícito que nada aqui o substitui por
     * dublê: o caso da comparação só vale se os dois lados forem o código real.
     */
    private function bloqueios(): BloqueiosDaOfertaService
    {
        return new BloqueiosDaOfertaService(
            app(ComposicaoFinanceiraService::class),
            app(AjustesDaOfertaService::class),
        );
    }

    private function raiz(): string
    {
        return rtrim(str_replace('\\', '/', base_path()), '/');
    }

    private function git(string $comando): string
    {
        $saida = shell_exec('cd '.escapeshellarg($this->raiz()).' && '.$comando.' 2>&1');

        return trim((string) $saida);
    }

    /** O código PHP **sem comentário** — `token_get_all`, nunca regex. */
    private function semComentarios(string $relativo): string
    {
        $caminho = $this->raiz().'/'.$relativo;
        $this->assertFileExists($caminho, "arquivo inexistente: {$relativo} — o gate mediria NADA");

        $limpo = '';

        foreach (token_get_all((string) file_get_contents($caminho)) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $limpo .= is_array($token) ? $token[1] : $token;
        }

        return $limpo;
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // TAREFA 1 — A GALERIA FINAL, ORDENADA, PROVADA CONTRA O BLOQUEIO
    // ═════════════════════════════════════════════════════════════════════════════════════════

    /**
     * Os **5 estados de revisão**, um a um, contra `NO_CONJUNTO_FINAL` — e as DUAS outras
     * condições de `noConjuntoFinal()` (posição nula e `fora`).
     *
     * ⚠️ `regenerating` fica **FORA**, e a divergência está declarada no serviço: o quadrado
     * tracejado existe no componente e é conferido por props diretas. Alargar a galeria para
     * fabricá-lo faria a contagem dos quadrados divergir de `contagemDeFotos()`.
     */
    public function test_a_galeria_final_declara_os_CINCO_estados_um_a_um_187_07(): void
    {
        $empresa = Company::factory()->create();
        $produto = $this->produto($empresa);
        $rascunho = $this->rascunho($produto);
        $conjunto = $this->conjunto($rascunho);

        $dentro = [
            PubAprovacaoImagem::PENDENTE => true,
            PubAprovacaoImagem::APROVADA => true,
            PubAprovacaoImagem::REPROVADA => false,
            PubAprovacaoImagem::SUBSTITUIDA => false,
            PubAprovacaoImagem::REGENERANDO => false,
        ];

        $this->assertCount(5, $dentro, 'os 5 estados de revisão mudaram de número — remeça este caso');

        $fora = [];
        $posicao = 0;
        foreach ($dentro as $estado => $deveriaEntrar) {
            $imagem = $this->imagem($conjunto, $estado, $posicao++);
            $ids = array_column($this->galeria()->doRascunho((int) $rascunho->id)['imagens'], 'id');

            if (in_array((int) $imagem->id, $ids, true) !== $deveriaEntrar) {
                $fora[] = $estado.($deveriaEntrar ? ' deveria ENTRAR' : ' deveria ficar FORA');
            }
        }

        // UMA asserção, coletando os infratores.
        $this->assertSame([], $fora, 'a galeria final divergiu de `NO_CONJUNTO_FINAL`');

        // As OUTRAS DUAS condições de `noConjuntoFinal()`, que não são o estado.
        $semPosicao = $this->imagem($conjunto, PubAprovacaoImagem::APROVADA, null);
        $marcadaFora = $this->imagem($conjunto, PubAprovacaoImagem::APROVADA, 9, ['fora' => true]);

        $ids = array_column($this->galeria()->doRascunho((int) $rascunho->id)['imagens'], 'id');
        $this->assertNotContains((int) $semPosicao->id, $ids, '`posicao` NULL é FORA da publicação');
        $this->assertNotContains((int) $marcadaFora->id, $ids, '`fora = true` tira a foto da publicação');

        // ⚠️ ISCA INVERTIDA: a galeria do rascunho COM conjuntos é NÃO-vazia. Sem ela, todas as
        // asserções de ausência acima passariam com a varredura cega.
        $this->assertNotSame([], $ids, 'ISCA CEGA: a galeria veio VAZIA — as ausências não provam nada');
        $this->assertSame(2, count($ids), 'só a pendente e a aprovada deveriam estar na galeria');
    }

    /**
     * A ORDEM: `GENERAL` primeiro, depois por `id`; dentro do grupo, por `posicao` e `id`.
     *
     * ⚠️ A isca prova que a ordenação MEXE: criando o `GENERAL` DEPOIS do grupo de variação, ele
     * ainda vem primeiro — e um `orderBy('id')` puro reprovaria aqui.
     */
    public function test_a_ordem_e_GENERAL_primeiro_e_depois_por_id_com_isca_187_07(): void
    {
        $empresa = Company::factory()->create();
        $produto = $this->produto($empresa);
        $rascunho = $this->rascunho($produto);

        // ⚠️ O grupo de VARIAÇÃO é criado ANTES — então ele tem o id MENOR.
        $variacao = $this->conjunto($rascunho, 'COR:Azul');
        $geral = $this->conjunto($rascunho, PubAprovacaoConjunto::GERAL);
        $outraVariacao = $this->conjunto($rascunho, 'COR:Verde');

        $this->assertLessThan((int) $geral->id, (int) $variacao->id, 'a fixture não discrimina: o GERAL precisa ter id MAIOR');

        $naVariacao = $this->imagem($variacao, PubAprovacaoImagem::PENDENTE, 0);
        $noGeral1 = $this->imagem($geral, PubAprovacaoImagem::PENDENTE, 1);
        $noGeral0 = $this->imagem($geral, PubAprovacaoImagem::APROVADA, 0);
        $naOutra = $this->imagem($outraVariacao, PubAprovacaoImagem::PENDENTE, 0);

        $ids = array_column($this->galeria()->doRascunho((int) $rascunho->id)['imagens'], 'id');

        $this->assertSame(
            [(int) $noGeral0->id, (int) $noGeral1->id, (int) $naVariacao->id, (int) $naOutra->id],
            $ids,
            'GENERAL primeiro (por `posicao`), depois os grupos de variação por `id`',
        );

        // ⚠️ ISCA: a ordem por id PURO daria outra saída — é o que prova que a ordenação não é
        // decorativa.
        $porIdPuro = [(int) $naVariacao->id, (int) $noGeral1->id, (int) $noGeral0->id, (int) $naOutra->id];
        $this->assertNotSame($porIdPuro, $ids, 'ISCA: a ordem saiu igual à de `id` puro — a regra do GENERAL não está medindo nada');

        // Os rótulos acompanham a POSIÇÃO, não o índice: a capa é a `posicao` 0.
        $rotulos = array_column($this->galeria()->doRascunho((int) $rascunho->id)['imagens'], 'rotulo');
        $this->assertSame(
            [GaleriaDaOfertaService::ROTULO_CAPA, 'Foto 2', GaleriaDaOfertaService::ROTULO_CAPA, GaleriaDaOfertaService::ROTULO_CAPA],
            $rotulos,
        );
    }

    /**
     * ⚠️⚠️ **O GATE CENTRAL DA TAREFA 1:** os quatro totais daqui são IDÊNTICOS aos de
     * `BloqueiosDaOfertaService::contagemDeFotos()` em **6 cenários**.
     *
     * É isto que substitui o compartilhamento de código com um método PRIVADO e INTOCÁVEL. No dia
     * em que os dois divergirem, este caso acusa.
     */
    public function test_os_totais_da_galeria_sao_IDENTICOS_aos_do_bloqueio_187_07(): void
    {
        $empresa = Company::factory()->create();
        $bloqueios = $this->bloqueios();
        $divergencias = [];
        $viuNaoVazio = false;

        // Os 6 cenários do plano, cada um num rascunho próprio.
        $cenarios = [
            'vazio' => function (PubRascunho $r): void {},
            'tudo aprovado' => function (PubRascunho $r): void {
                $c = $this->conjunto($r);
                $this->imagem($c, PubAprovacaoImagem::APROVADA, 0);
                $this->imagem($c, PubAprovacaoImagem::APROVADA, 1);
            },
            'pendente no GENERAL' => function (PubRascunho $r): void {
                $c = $this->conjunto($r);
                $this->imagem($c, PubAprovacaoImagem::APROVADA, 0);
                $this->imagem($c, PubAprovacaoImagem::PENDENTE, 1);
            },
            'pendente em OUTRO grupo' => function (PubRascunho $r): void {
                $geral = $this->conjunto($r);
                $this->imagem($geral, PubAprovacaoImagem::APROVADA, 0);
                $cor = $this->conjunto($r, 'COR:Azul');
                $this->imagem($cor, PubAprovacaoImagem::PENDENTE, 0);
            },
            'com alerta' => function (PubRascunho $r): void {
                $c = $this->conjunto($r);
                $this->imagem($c, PubAprovacaoImagem::APROVADA, 0, ['alerta' => PubAprovacaoImagemAlerta::ALTO]);
                $this->imagem($c, PubAprovacaoImagem::PENDENTE, 1, ['alerta' => PubAprovacaoImagemAlerta::ALTO]);
                $this->imagem($c, PubAprovacaoImagem::PENDENTE, 2, ['alerta' => PubAprovacaoImagemAlerta::OK]);
            },
            'com regenerando' => function (PubRascunho $r): void {
                $c = $this->conjunto($r);
                $this->imagem($c, PubAprovacaoImagem::APROVADA, 0);
                $this->imagem($c, PubAprovacaoImagem::REGENERANDO, 1);
                $this->imagem($c, PubAprovacaoImagem::PENDENTE, 2);
            },
        ];

        $this->assertCount(6, $cenarios, 'o plano pede 6 cenários');

        foreach ($cenarios as $nome => $montar) {
            $produto = $this->produto($empresa, ['produto' => 'Produto '.$nome]);
            $rascunho = $this->rascunho($produto);
            $oferta = $this->oferta($empresa, $produto, $rascunho, self::CLASSICO, PubAprovacaoOferta::AGUARDANDO_APROVACAO);
            $montar($rascunho);

            $doBloqueio = $bloqueios->contagemDeFotos($oferta);
            $daGaleria = $this->galeria()->doRascunho((int) $rascunho->id);

            $meu = [
                'total' => $daGaleria['total'],
                'aprovadas' => $daGaleria['aprovadas'],
                'pendentes' => $daGaleria['pendentes'],
                'com_alerta' => $daGaleria['com_alerta'],
            ];

            if ($meu !== $doBloqueio) {
                $divergencias[] = $nome.': galeria '.json_encode($meu).' × bloqueio '.json_encode($doBloqueio);
            }

            if ($daGaleria['total'] > 0) {
                $viuNaoVazio = true;
            }
        }

        // UMA asserção, coletando os infratores.
        $this->assertSame([], $divergencias, 'a galeria divergiu do bloqueio — duas verdades sobre a mesma contagem');

        // ⚠️ ISCA INVERTIDA: pelo menos um cenário tem foto. Sem ela, "0 === 0" seis vezes
        // passaria medindo NADA — e seria exatamente o tipo de sobrevivente que a Fase 186 mediu.
        $this->assertTrue($viuNaoVazio, 'ISCA CEGA: todos os cenários saíram vazios — a comparação não prova nada');
    }

    /**
     * O CUSTO: **três consultas fixas**, e elas não crescem com o número de grupos, de imagens
     * nem de OFERTAS do mesmo rascunho.
     *
     * ⚠️ `temAlerta()` faria uma consulta POR IMAGEM. ⚠️ E chamar a galeria por OFERTA (em vez de
     * por RASCUNHO) multiplicaria tudo pelas duas ofertas de todo produto.
     */
    public function test_CUSTO_a_galeria_sao_TRES_consultas_que_nao_crescem_187_07(): void
    {
        $empresa = Company::factory()->create();
        $produto = $this->produto($empresa);
        $rascunho = $this->rascunho($produto);

        // DUAS ofertas do mesmo rascunho — o compartilhamento Clássico↔Premium é estrutural.
        $this->oferta($empresa, $produto, $rascunho, self::CLASSICO, PubAprovacaoOferta::AGUARDANDO_APROVACAO);
        $this->oferta($empresa, $produto, $rascunho, self::PREMIUM, PubAprovacaoOferta::AGUARDANDO_APROVACAO);

        $geral = $this->conjunto($rascunho);
        $this->imagem($geral, PubAprovacaoImagem::PENDENTE, 0, ['alerta' => PubAprovacaoImagemAlerta::ALTO]);

        $contar = function () use ($rascunho): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->galeria()->doRascunho((int) $rascunho->id);
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };

        $pequena = $contar();
        $this->assertSame(3, $pequena, 'conjuntos + imagens + alertas = 3 consultas FIXAS');

        // Agora MUITO mais grupo e MUITO mais imagem: o número não pode mexer.
        foreach (['COR:Azul', 'COR:Verde', 'COR:Preto', 'COR:Branco'] as $i => $chave) {
            $c = $this->conjunto($rascunho, $chave);
            $this->imagem($c, PubAprovacaoImagem::PENDENTE, 0, ['alerta' => PubAprovacaoImagemAlerta::MEDIO]);
            $this->imagem($c, PubAprovacaoImagem::APROVADA, 1, ['alerta' => PubAprovacaoImagemAlerta::ALTO]);
        }

        $grande = $contar();
        $this->assertSame($pequena, $grande, 'o custo cresceu com os grupos/imagens — é o N+1 do `temAlerta()`');

        // ⚠️ ISCA: a sonda CONTA. Sem isso, um `getQueryLog()` desligado daria 0 nas duas pontas.
        $this->assertSame(9, $this->galeria()->doRascunho((int) $rascunho->id)['total'], 'a fixture grande não entrou na galeria');
        $this->assertGreaterThan(0, $pequena, 'ISCA CEGA: a sonda de consultas não contou nada');
    }

    /**
     * `de_ia` conta `TIPO_IA` na galeria final — e a isca prova que `original` NÃO entra.
     *
     * E o `titulo` do quadrado, com os **TRÊS** veredictos de `sq()` (`:1117`) e a **D7** medida.
     */
    public function test_de_ia_e_o_titulo_do_quadrado_com_a_D7_187_07(): void
    {
        $empresa = Company::factory()->create();
        $produto = $this->produto($empresa);
        $rascunho = $this->rascunho($produto);
        $conjunto = $this->conjunto($rascunho);

        $this->imagem($conjunto, PubAprovacaoImagem::APROVADA, 0, ['tipo' => PubAprovacaoImagem::TIPO_ORIGINAL]);
        $this->imagem($conjunto, PubAprovacaoImagem::PENDENTE, 1, ['tipo' => PubAprovacaoImagem::TIPO_IA, 'alerta' => PubAprovacaoImagemAlerta::ALTO]);
        $this->imagem($conjunto, PubAprovacaoImagem::PENDENTE, 2, ['tipo' => PubAprovacaoImagem::TIPO_IA]);

        $galeria = $this->galeria()->doRascunho((int) $rascunho->id);

        $this->assertSame(3, $galeria['total']);
        $this->assertSame(2, $galeria['de_ia'], 'só as de IA contam');
        // ⚠️ ISCA: se `de_ia` contasse tudo, seria 3 — e a asserção acima passaria por acidente
        // num cenário só de IA. É por isso que a fixture tem uma `original`.
        $this->assertNotSame($galeria['total'], $galeria['de_ia'], 'ISCA: `de_ia` está contando a `original` também');

        $this->assertSame(
            [
                'Capa'.GaleriaDaOfertaService::SEPARADOR.GaleriaDaOfertaService::ESTADO_APROVADA,
                'Foto 2'.GaleriaDaOfertaService::SEPARADOR.GaleriaDaOfertaService::ESTADO_COM_ALERTA,
                'Foto 3'.GaleriaDaOfertaService::SEPARADOR.GaleriaDaOfertaService::ESTADO_A_CONFERIR,
            ],
            array_column($galeria['imagens'], 'titulo'),
            'o `title` do quadrado é `rótulo · veredicto`, literal de `sq()` (`:1117`)',
        );

        // ⚠️⚠️ A DIVERGÊNCIA **D7**, medida: `regenerating` cai em "a conferir", embora a BORDA
        // do quadrado seja tracejada (`:1119`). **Reproduzida, NÃO "consertada".**
        $this->assertSame(
            'Capa'.GaleriaDaOfertaService::SEPARADOR.GaleriaDaOfertaService::ESTADO_A_CONFERIR,
            $this->galeria()->tituloDoQuadrado('Capa', PubAprovacaoImagem::REGENERANDO, false),
            'D7: o ternário de `sq()` só distingue `approved` e "com alerta"',
        );

        // ⚠️ E o alerta numa imagem já APROVADA **não** conta (definição de `comAlerta()`).
        $this->assertSame(1, $galeria['com_alerta'], 'a aprovada com alerta não pode contar');
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // TAREFA 2 — OS TRÊS NÍVEIS: família -> produto (Unidade/Combo) -> oferta
    // ═════════════════════════════════════════════════════════════════════════════════════════

    /**
     * Duas ofertas (Clássico + Premium) do MESMO produto: UM grupo, UM conjunto, "2 ofertas" e
     * `compartilhado = true` — e a ordem Clássico antes de Premium (`:1261`).
     */
    public function test_duas_ofertas_do_mesmo_produto_sao_um_conjunto_compartilhado_187_07(): void
    {
        $empresa = Company::factory()->create();
        $produto = $this->produto($empresa, ['produto' => 'Poltrona Beny', 'sku' => 'POL-BENY']);
        $rascunho = $this->rascunho($produto);

        // ⚠️ O PREMIUM é criado PRIMEIRO, para a ordem não sair "de graça" pela ordem de inserção.
        $premium = $this->oferta($empresa, $produto, $rascunho, self::PREMIUM, PubAprovacaoOferta::AGUARDANDO_APROVACAO);
        $classico = $this->oferta($empresa, $produto, $rascunho, self::CLASSICO, PubAprovacaoOferta::AGUARDANDO_APROVACAO);

        $arvore = $this->grupos()->montar($this->fila()->ordenar($this->ofertasAoVivo($empresa)));

        $this->assertCount(1, $arvore, 'as duas ofertas do mesmo produto saem num grupo SÓ');
        $this->assertSame('2 ofertas', $arvore[0]['contagem']);
        $this->assertSame('POL-BENY', $arvore[0]['sku']);
        $this->assertSame('Poltrona Beny', $arvore[0]['nome']);
        $this->assertFalse($arvore[0]['borda_superior'], 'o PRIMEIRO grupo não tem borda superior (`:1260`)');

        $this->assertCount(1, $arvore[0]['conjuntos'], 'um rascunho é UM conjunto de fotos');
        $conjunto = $arvore[0]['conjuntos'][0];
        $this->assertTrue($conjunto['compartilhado'], 'duas ofertas no conjunto = aprovação compartilhada');
        $this->assertSame(GrupoDaFilaService::UNIDADE, $conjunto['rotulo']);

        $this->assertSame(
            [(int) $classico->id, (int) $premium->id],
            array_column($conjunto['ofertas'], 'id'),
            'Clássico antes de Premium (`:1261`)',
        );

        // ⚠️ ISCA: a ordem de INSERÇÃO era a inversa — então a ordenação está medindo algo.
        $this->assertNotSame(
            [(int) $premium->id, (int) $classico->id],
            array_column($conjunto['ofertas'], 'id'),
            'ISCA: a ordem saiu igual à de inserção — Clássico/Premium não está ordenando',
        );
    }

    /**
     * Base + kit de 2 do MESMO produto: UM grupo (nível 1), DOIS conjuntos ("Unidade" e
     * "Combo ×2"), na ordem de **unidades crescentes** (`:1258`).
     */
    public function test_base_e_kit_saem_no_mesmo_grupo_em_dois_conjuntos_187_07(): void
    {
        $empresa = Company::factory()->create();
        $base = $this->produto($empresa, ['produto' => 'Poltrona Beny', 'sku' => 'POL-BENY']);
        $kit3 = $this->produto($empresa, ['produto' => 'Poltrona Beny', 'sku' => 'POL-BENY', 'kit' => 3, 'base' => $base]);
        $kit2 = $this->produto($empresa, ['produto' => 'Poltrona Beny', 'sku' => 'POL-BENY', 'kit' => 2, 'base' => $base]);

        // ⚠️ O kit de 3 é criado ANTES do de 2: a ordem não pode vir da ordem de inserção.
        $this->oferta($empresa, $kit3, $this->rascunho($kit3), self::CLASSICO, PubAprovacaoOferta::AGUARDANDO_APROVACAO);
        $this->oferta($empresa, $kit2, $this->rascunho($kit2), self::CLASSICO, PubAprovacaoOferta::AGUARDANDO_APROVACAO);
        $this->oferta($empresa, $base, $this->rascunho($base), self::CLASSICO, PubAprovacaoOferta::AGUARDANDO_APROVACAO);

        $arvore = $this->grupos()->montar($this->fila()->ordenar($this->ofertasAoVivo($empresa)));

        $this->assertCount(1, $arvore, 'a FAMÍLIA é UM grupo: o base e os kits dele');
        $this->assertSame('3 ofertas', $arvore[0]['contagem'], 'o `count` do nível 1 soma os conjuntos (`:1259`)');

        $this->assertSame(
            [GrupoDaFilaService::UNIDADE, GrupoDaFilaService::PREFIXO_COMBO.'2', GrupoDaFilaService::PREFIXO_COMBO.'3'],
            array_column($arvore[0]['conjuntos'], 'rotulo'),
            'unidades CRESCENTES (`:1258`)',
        );

        // ⚠️ ISCA: a ordem de inserção era 3, 2, 1 — a ordenação por unidades está medindo.
        $this->assertNotSame(
            [GrupoDaFilaService::PREFIXO_COMBO.'3', GrupoDaFilaService::PREFIXO_COMBO.'2', GrupoDaFilaService::UNIDADE],
            array_column($arvore[0]['conjuntos'], 'rotulo'),
            'ISCA: a ordem dos conjuntos saiu igual à de inserção',
        );

        // `compartilhado = false` em TODOS: cada conjunto tem UMA oferta só — e o pill não aparece.
        $this->assertSame(
            [false, false, false],
            array_column($arvore[0]['conjuntos'], 'compartilhado'),
            'conjunto com UMA oferta não é compartilhado — o pill não aparece',
        );
        $this->assertSame('1 oferta', $this->grupos()->contagemDeOfertas(1), 'o SINGULAR (`:1259`)');
    }

    /**
     * "Unidade" × "Combo ×N" vem de `quantidade_kit`, e a **isca prova que `produto_base_id`
     * sozinho não faz kit**.
     *
     * ⚠️⚠️ **DIVERGÊNCIA DECLARADA, e é a MESMA que `ProximoARevisarService::formato()` já tomou:**
     * `PubProduto::ehKit()` exige TAMBÉM `produto_base_id`, e aqui ele **não** é consultado. O que
     * o rótulo descreve é *"quantas unidades vão nesta oferta"*, que é `quantidade_kit` e só. Um
     * kit de 2 cadastrado sem base ainda vende 2 unidades por venda, e dizer "Unidade" ali seria
     * afirmação FALSA sobre o que o comprador recebe. É também o que o protótipo mede
     * (`set.units > 1`, `:1262`).
     */
    public function test_Unidade_e_Combo_vem_de_quantidade_kit_com_isca_187_07(): void
    {
        $empresa = Company::factory()->create();

        $casos = [
            // [quantidade_kit, tem base?, rótulo esperado]
            [1, false, GrupoDaFilaService::UNIDADE],
            [2, true, GrupoDaFilaService::PREFIXO_COMBO.'2'],
            [5, true, GrupoDaFilaService::PREFIXO_COMBO.'5'],
            // ⚠️ A ISCA: kit de 2 SEM base. `ehKit()` diria FALSE; o rótulo é "Combo ×2".
            [2, false, GrupoDaFilaService::PREFIXO_COMBO.'2'],
            // ⚠️ E o inverso: `produto_base_id` preenchido com UMA unidade é "Unidade".
            [1, true, GrupoDaFilaService::UNIDADE],
        ];

        $errados = [];
        foreach ($casos as $i => [$kit, $temBase, $esperado]) {
            $base = $temBase ? $this->produto($empresa, ['produto' => 'Base '.$i]) : null;
            $produto = $this->produto($empresa, [
                'produto' => 'Caso '.$i, 'kit' => $kit, 'base' => $base,
            ]);

            $obtido = $this->grupos()->rotuloDoConjunto($produto->quantidade_kit);

            if ($obtido !== $esperado) {
                $errados[] = "kit={$kit} base=".($temBase ? 'sim' : 'nao')." => {$obtido}, esperava {$esperado}";
            }

            // ⚠️ A prova de que `ehKit()` DIVERGE de propósito no caso 4.
            if ($i === 3) {
                $this->assertFalse($produto->ehKit(), 'a fixture do caso 4 não discrimina: `ehKit()` deveria ser FALSE');
            }
        }

        $this->assertSame([], $errados, 'o rótulo do conjunto divergiu de `quantidade_kit`');

        // Quantidade inválida não produz "Combo ×0": cai em "Unidade".
        $this->assertSame(GrupoDaFilaService::UNIDADE, $this->grupos()->rotuloDoConjunto(0));
        $this->assertSame(GrupoDaFilaService::UNIDADE, $this->grupos()->rotuloDoConjunto(-3));
        $this->assertSame(GrupoDaFilaService::UNIDADE, $this->grupos()->rotuloDoConjunto(null));
    }

    /**
     * A ordem dos GRUPOS é a de **primeira aparição** na lista já ordenada pela fila (`:1257`) —
     * nunca alfabética. A isca muda o andamento de uma oferta e o grupo dela SOBE.
     */
    public function test_a_ordem_dos_grupos_e_a_da_fila_e_nao_alfabetica_187_07(): void
    {
        $empresa = Company::factory()->create();

        // "Zebra" vem DEPOIS de "Abacaxi" no alfabeto, mas a oferta dela está em FALHA, que é o
        // degrau 0 de `ORDEM` — o mais urgente da fila.
        $abacaxi = $this->produto($empresa, ['produto' => 'Abacaxi Decorativo', 'sku' => 'ABA-1']);
        $zebra = $this->produto($empresa, ['produto' => 'Zebra de Pelúcia', 'sku' => 'ZEB-1']);

        $this->oferta($empresa, $abacaxi, $this->rascunho($abacaxi), self::CLASSICO, PubAprovacaoOferta::AGUARDANDO_APROVACAO);
        $daZebra = $this->oferta($empresa, $zebra, $this->rascunho($zebra), self::CLASSICO, PubAprovacaoOferta::AGUARDANDO_APROVACAO, [
            'espera' => '2026-10-16 08:50:00',
        ]);

        // Primeiro: as duas em `aguardando`, a Zebra com MENOS espera -> Abacaxi na frente.
        $nomes = array_column($this->grupos()->montar($this->fila()->ordenar($this->ofertasAoVivo($empresa))), 'nome');
        $this->assertSame(['Abacaxi Decorativo', 'Zebra de Pelúcia'], $nomes);

        // ⚠️ ISCA: mudando o andamento da Zebra para `ajustes_solicitados` ela desce; para
        // `aguardando` com MAIS espera ela sobe. O que move é a ORDEM DA FILA.
        $daZebra->forceFill(['entrou_na_fila_em' => '2026-10-15 01:00:00'])->save();
        $nomesDepois = array_column($this->grupos()->montar($this->fila()->ordenar($this->ofertasAoVivo($empresa))), 'nome');
        $this->assertSame(['Zebra de Pelúcia', 'Abacaxi Decorativo'], $nomesDepois, 'ISCA: o grupo não seguiu a ordem da fila');

        // E a borda superior: o primeiro NÃO tem, os seguintes TÊM (`:1260`).
        $bordas = array_column($this->grupos()->montar($this->fila()->ordenar($this->ofertasAoVivo($empresa))), 'borda_superior');
        $this->assertSame([false, true], $bordas, 'o primeiro grupo não tem borda superior; os seguintes sim');
    }

    /**
     * Produto sem família não quebra, e **base APAGADO** (`produto_base_id` SET NULL) cai no
     * próprio grupo.
     *
     * ⚠️ É o cenário da tabela de ameaças (T-187-73 vizinho): a FK é `nullOnDelete`, então um kit
     * órfão é estado ALCANÇÁVEL do banco, não hipótese.
     */
    public function test_base_apagado_deixa_o_kit_no_proprio_grupo_187_07(): void
    {
        $empresa = Company::factory()->create();
        $base = $this->produto($empresa, ['produto' => 'Poltrona Beny', 'sku' => 'POL-BENY']);
        $kit = $this->produto($empresa, ['produto' => 'Kit Poltrona Beny', 'sku' => 'POL-BENY', 'kit' => 2, 'base' => $base]);

        $ofertaDoKit = $this->oferta($empresa, $kit, $this->rascunho($kit), self::CLASSICO, PubAprovacaoOferta::AGUARDANDO_APROVACAO);
        $this->oferta($empresa, $base, $this->rascunho($base), self::CLASSICO, PubAprovacaoOferta::AGUARDANDO_APROVACAO);

        // Juntos, UM grupo.
        $this->assertCount(1, $this->grupos()->montar($this->fila()->ordenar($this->ofertasAoVivo($empresa))));

        // ⚠️ Agora o BASE é apagado: a FK `nullOnDelete` zera `produto_base_id` do kit.
        $base->delete();
        $this->assertNull($kit->fresh()->produto_base_id, 'a FK deveria ser `nullOnDelete` — a fixture não reproduz o cenário');

        $arvore = $this->grupos()->montar($this->fila()->ordenar($this->ofertasAoVivo($empresa)));
        $this->assertCount(1, $arvore, 'o kit órfão cai no PRÓPRIO grupo, e nada derruba');
        $this->assertSame('Kit Poltrona Beny', $arvore[0]['nome']);
        $this->assertSame((int) $ofertaDoKit->id, $arvore[0]['conjuntos'][0]['ofertas'][0]['id']);

        // Produto SOLTO (nunca teve base) também não quebra.
        $solto = $this->produto($empresa, ['produto' => 'Mesa Tora', 'sku' => 'MES-1']);
        $this->oferta($empresa, $solto, $this->rascunho($solto), self::CLASSICO, PubAprovacaoOferta::AGUARDANDO_APROVACAO);
        $this->assertCount(2, $this->grupos()->montar($this->fila()->ordenar($this->ofertasAoVivo($empresa))));
    }

    /**
     * ⚠️⚠️ **A FRASE DO CADASTRO SÓ APARECE QUANDO É VERDADE** (`:120`, D4 / EXMP-01, confirmado
     * pelo usuário em 15/10/2026).
     *
     * As TRÊS maneiras de ela ser falsa, cada uma medida: sem categoria · sem atributo nenhum ·
     * com atributo marcado para revisar. E o caso VERDADEIRO ao lado — sem ele, um serviço que
     * nunca devolvesse a frase passaria.
     */
    public function test_a_frase_do_cadastro_so_aparece_quando_e_VERDADE_187_07(): void
    {
        $empresa = Company::factory()->create();
        $faltas = [];

        // (a) categoria preenchida + atributos todos resolvidos => a frase APARECE.
        $ok = $this->produto($empresa, ['produto' => 'Produto Completo']);
        $rOk = $this->rascunho($ok, 'MLB1234');
        PubRascunhoAtributo::create(['rascunho_id' => $rOk->id, 'attribute_id' => 'BRAND', 'value_name' => 'ECF', 'revisar' => false]);
        $this->oferta($empresa, $ok, $rOk, self::CLASSICO, PubAprovacaoOferta::AGUARDANDO_APROVACAO);

        // (b) SEM categoria => não aparece.
        $semCat = $this->produto($empresa, ['produto' => 'Produto Sem Categoria']);
        $rSemCat = $this->rascunho($semCat, null);
        PubRascunhoAtributo::create(['rascunho_id' => $rSemCat->id, 'attribute_id' => 'BRAND', 'value_name' => 'ECF', 'revisar' => false]);
        $this->oferta($empresa, $semCat, $rSemCat, self::CLASSICO, PubAprovacaoOferta::AGUARDANDO_APROVACAO);

        // (c) SEM atributo nenhum => não aparece (não há de onde afirmar "ficha organizada").
        $semFicha = $this->produto($empresa, ['produto' => 'Produto Sem Ficha']);
        $this->oferta($empresa, $semFicha, $this->rascunho($semFicha, 'MLB1234'), self::CLASSICO, PubAprovacaoOferta::AGUARDANDO_APROVACAO);

        // (d) atributo marcado para REVISAR => não aparece.
        $revisar = $this->produto($empresa, ['produto' => 'Produto A Revisar']);
        $rRevisar = $this->rascunho($revisar, 'MLB1234');
        PubRascunhoAtributo::create(['rascunho_id' => $rRevisar->id, 'attribute_id' => 'BRAND', 'value_name' => 'ECF', 'revisar' => false]);
        PubRascunhoAtributo::create(['rascunho_id' => $rRevisar->id, 'attribute_id' => 'MODEL', 'value_name' => '?', 'revisar' => true]);
        $this->oferta($empresa, $revisar, $rRevisar, self::CLASSICO, PubAprovacaoOferta::AGUARDANDO_APROVACAO);

        $arvore = $this->grupos()->montar($this->fila()->ordenar($this->ofertasAoVivo($empresa)));
        $porNome = [];
        foreach ($arvore as $grupo) {
            $porNome[$grupo['nome']] = $grupo['cadastro'];
        }

        $esperado = [
            'Produto Completo' => GrupoDaFilaService::CADASTRO_COMPLETO,
            'Produto Sem Categoria' => null,
            'Produto Sem Ficha' => null,
            'Produto A Revisar' => null,
        ];

        foreach ($esperado as $nome => $frase) {
            if (! array_key_exists($nome, $porNome)) {
                $faltas[] = "{$nome}: grupo ausente da árvore";

                continue;
            }
            if ($porNome[$nome] !== $frase) {
                $faltas[] = "{$nome}: '".var_export($porNome[$nome], true)."', esperava '".var_export($frase, true)."'";
            }
        }

        $this->assertSame([], $faltas, 'a frase do cadastro afirmou o que não é verdade (ou deixou de afirmar o que é)');

        // ⚠️ ISCA INVERTIDA: o caso VERDADEIRO existe. Sem ele, um serviço que devolvesse SEMPRE
        // `null` passaria em três dos quatro e o gate não mediria nada.
        $this->assertSame(
            GrupoDaFilaService::CADASTRO_COMPLETO,
            $porNome['Produto Completo'],
            'ISCA CEGA: a frase nunca aparece — o gate só mediria ausência',
        );

        // E o literal, byte a byte, com o separador U+00B7 (`:120`).
        $this->assertSame('Cadastro completo · categoria e ficha técnica organizadas', GrupoDaFilaService::CADASTRO_COMPLETO);
    }

    /**
     * A sub-linha: `x/y aprovadas`, `N fotos (M geradas por IA)` e os quadrados — todos LIDOS da
     * `GaleriaDaOfertaService`, nunca recontados.
     *
     * ⚠️ `progress` e `photos` são os literais de `:1262`.
     */
    public function test_a_sublinha_le_a_galeria_e_nao_reconta_187_07(): void
    {
        $empresa = Company::factory()->create();
        $produto = $this->produto($empresa, ['produto' => 'Poltrona Beny']);
        $rascunho = $this->rascunho($produto);
        $this->oferta($empresa, $produto, $rascunho, self::CLASSICO, PubAprovacaoOferta::AGUARDANDO_APROVACAO);

        $conjunto = $this->conjunto($rascunho);
        $this->imagem($conjunto, PubAprovacaoImagem::APROVADA, 0);
        $this->imagem($conjunto, PubAprovacaoImagem::APROVADA, 1, ['tipo' => PubAprovacaoImagem::TIPO_IA]);
        $this->imagem($conjunto, PubAprovacaoImagem::PENDENTE, 2, ['tipo' => PubAprovacaoImagem::TIPO_IA]);

        $conjuntoDaArvore = $this->grupos()->montar($this->fila()->ordenar($this->ofertasAoVivo($empresa)))[0]['conjuntos'][0];

        $this->assertSame('2/3 aprovadas', $conjuntoDaArvore['progresso'], '`ap + "/" + ord.length + " aprovadas"` (`:1262`)');
        $this->assertFalse($conjuntoDaArvore['completo'], 'x < y: a cor é âmbar');
        $this->assertSame('3 fotos (2 geradas por IA)', $conjuntoDaArvore['fotos'], 'o literal de `:1262`');
        $this->assertCount(3, $conjuntoDaArvore['quadrados'], 'os quadrados são a galeria final, agregada de TODOS os grupos');

        // ⚠️ Os mini-quadrados contam o MESMO que o bloqueio conta — a comparação, aqui também.
        $doBloqueio = $this->bloqueios()->contagemDeFotos($this->ofertasAoVivo($empresa)->first());
        $this->assertSame($doBloqueio['total'], count($conjuntoDaArvore['quadrados']));
        $this->assertSame($doBloqueio['aprovadas'], 2);

        // Agora TUDO aprovado: `completo` vira true.
        PubAprovacaoImagem::query()->update(['estado' => PubAprovacaoImagem::APROVADA]);
        $depois = $this->grupos()->montar($this->fila()->ordenar($this->ofertasAoVivo($empresa)))[0]['conjuntos'][0];
        $this->assertSame('3/3 aprovadas', $depois['progresso']);
        $this->assertTrue($depois['completo'], 'x === y: a cor é verde');
    }

    /**
     * O CUSTO do nível 1 e 2: a árvore de N grupos **não** chama
     * `BloqueiosDaOfertaService::para()` nenhuma vez — ela não precisa de composição.
     *
     * ⚠️ É o gate que impede alguém "aproveitar" a varredura para pendurar dinheiro no nível 2:
     * `para()` lê a composição, que consulta `/sites/MLB/listing_prices`. Uma chamada por grupo
     * seria uma requisição ao Mercado Livre por grupo — a classe do incidente de 30/07/2026.
     */
    public function test_CUSTO_a_arvore_nao_chama_para_nenhuma_vez_187_07(): void
    {
        $empresa = Company::factory()->create();

        $espiao = new class(app(ComposicaoFinanceiraService::class), app(AjustesDaOfertaService::class)) extends BloqueiosDaOfertaService
        {
            public int $chamadas = 0;

            public function para(PubAprovacaoOferta $oferta): array
            {
                $this->chamadas++;

                return ['bloqueios' => [], 'aprovavel' => true, 'ao_vivo' => true, 'kinds' => []];
            }
        };
        $this->app->instance(BloqueiosDaOfertaService::class, $espiao);

        foreach (range(1, 4) as $i) {
            $produto = $this->produto($empresa, ['produto' => 'Produto '.$i]);
            $rascunho = $this->rascunho($produto);
            $this->oferta($empresa, $produto, $rascunho, self::CLASSICO, PubAprovacaoOferta::AGUARDANDO_APROVACAO);
            $this->oferta($empresa, $produto, $rascunho, self::PREMIUM, PubAprovacaoOferta::AGUARDANDO_APROVACAO);
            $this->imagem($this->conjunto($rascunho), PubAprovacaoImagem::PENDENTE, 0);
        }

        $arvore = $this->grupos()->montar($this->fila()->ordenar($this->ofertasAoVivo($empresa)));

        // ⚠️ ISCA DO RECORTE: a árvore tem conteúdo. Gate sobre zero grupos não prova nada.
        $this->assertCount(4, $arvore, 'ISCA: a árvore saiu vazia — o gate de custo mediria NADA');
        $this->assertSame(0, $espiao->chamadas, '`para()` foi chamado na montagem da árvore — é uma requisição ao ML por grupo');

        // ⚠️ ISCA DO ESPIÃO: ele CONTA quando chamado. Sem isto, "0" seria o valor de um espião
        // que nunca mede.
        $espiao->para($this->ofertasAoVivo($empresa)->first());
        $this->assertSame(1, $espiao->chamadas, 'ISCA: o espião não conta — o 0 acima não prova nada');
    }

    /**
     * ⚠️⚠️ **A LISTA E A CONTA NÃO PODEM DIVERGIR** — `recortarPelaEtapa()` conferido contra o
     * total do card correspondente **nas 9 chaves da faixa**.
     *
     * ⚠️ **O que precisaria estar errado no mundo para este caso falhar:** alguém escrever o
     * recorte da lista de cabeça. O risco REAL é a etapa `publicado`: o card conta
     * "publicado **HOJE**" (`whereDate('updated_at', hoje)`, `AndamentoDaOfertaService::
     * faixaDeAndamento()`), e uma segunda regra mostraria na tabela publicações de ONTEM com o
     * card dizendo outro número. **O usuário recusou número divergente entre conta e lista DUAS
     * vezes** (decisões 9 e 16).
     *
     * ⚠️ É a MESMA técnica do caso da galeria contra o bloqueio: duas leituras, uma comparação, e
     * o gate acusa no dia em que elas divergirem.
     */
    public function test_a_lista_recortada_pela_etapa_bate_com_o_card_nas_NOVE_chaves_187_07(): void
    {
        $empresa = Company::factory()->create();

        // ⚠️ A fixture DISCRIMINA: um produto por andamento, mais DUAS `publicado` — uma de HOJE e
        // uma de ONTEM. Sem a de ontem, o recorte do dia e o recorte só por andamento dariam o
        // MESMO número e a comparação não mediria nada (foi assim que duas mutações da Fase 186
        // sobreviveram: a fixture é que não discriminava).
        foreach (array_keys(PubAprovacaoOferta::ANDAMENTOS) as $andamento) {
            $produto = $this->produto($empresa, ['produto' => 'Produto '.$andamento]);
            $this->oferta($empresa, $produto, $this->rascunho($produto), self::CLASSICO, $andamento);
        }

        $deOntem = $this->produto($empresa, ['produto' => 'Publicado Ontem']);
        $ofertaDeOntem = $this->oferta($empresa, $deOntem, $this->rascunho($deOntem), self::CLASSICO, PubAprovacaoOferta::PUBLICADO);
        PubAprovacaoOferta::withoutTimestamps(
            fn () => $ofertaDeOntem->forceFill(['updated_at' => '2026-10-15 09:00:00'])->save()
        );

        $faixa = $this->fila()->faixa($this->fila()->paraAConta($empresa->id));
        $this->assertCount(9, $faixa, 'a faixa deveria ter 9 cards');

        $divergencias = [];
        foreach ($faixa as $card) {
            // Flag DENTRO do laço.
            $daLista = $this->grupos()
                ->recortarPelaEtapa($this->fila()->paraAConta($empresa->id), $card['chave'])
                ->count();

            if ($daLista !== $card['total']) {
                $divergencias[] = $card['chave'].": lista {$daLista} × card {$card['total']}";
            }
        }

        // UMA asserção, coletando os infratores.
        $this->assertSame([], $divergencias, 'a lista divergiu do card — conta e lista TÊM de bater');

        // ⚠️⚠️ ISCA INVERTIDA, e ela é o coração do caso: a etapa `publicado` tem DUAS ofertas no
        // banco e o card conta UMA (a de hoje). Sem esta asserção, um recorte que ignorasse o dia
        // passaria nas 9 comparações acima com a fixture errada.
        $noBanco = PubAprovacaoOferta::query()
            ->where('company_id', $empresa->id)
            ->where('andamento', PubAprovacaoOferta::PUBLICADO)
            ->count();
        $this->assertSame(2, $noBanco, 'a fixture não discrimina: precisa de UMA publicada hoje e UMA de ontem');
        $this->assertSame(
            1,
            $this->grupos()->recortarPelaEtapa($this->fila()->paraAConta($empresa->id), PubAprovacaoOferta::PUBLICADO)->count(),
            '`publicado` deveria ser recorte DO DIA — a de ontem entrou na lista',
        );

        // ⚠️ E a chave `todas` (que NÃO está em `ANDAMENTOS`) devolve a base inteira.
        $this->assertSame(
            9,
            $this->grupos()->recortarPelaEtapa($this->fila()->paraAConta($empresa->id), 'todas')->count(),
            '`todas` deveria devolver a base inteira (8 andamentos + a publicada de ontem)',
        );

        // ⚠️ Chave forjada/não-string cai no mesmo lugar que "todas": aqui ela só é COMPARADA
        // contra a lista fechada, nunca interpolada em SQL.
        foreach (['gold_special', '', 'DROP TABLE', 42, null, [], new \stdClass] as $forjada) {
            $this->assertSame(
                9,
                $this->grupos()->recortarPelaEtapa($this->fila()->paraAConta($empresa->id), $forjada)->count(),
                'chave forjada deveria cair na base inteira, sem recortar nada',
            );
        }
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // TRANSVERSAIS
    // ═════════════════════════════════════════════════════════════════════════════════════════

    /**
     * Território e AUSÊNCIAS, lidas do código **SEM COMENTÁRIO**.
     *
     * ⚠️ Ler sem comentário não é zelo: a prosa deste projeto cita os próprios identificadores que
     * ela diz NÃO usar, e um gate cru reprovaria pelo próprio conserto (três mutações da wave 4
     * abortaram por isso). ⚠️ **ESTA PROSA CITA DE PROPÓSITO** `ehKit`, `galeriaDoRascunho`,
     * `temAlerta`, `localStorage` e `lucro líquido` — é a ISCA DO LEITOR.
     */
    public function test_territorio_e_ausencias_do_plano_187_07(): void
    {
        $meus = [
            'app/Services/Publicador/Aprovacao/Fila/GaleriaDaOfertaService.php',
            'app/Services/Publicador/Aprovacao/Fila/GrupoDaFilaService.php',
        ];

        foreach ($meus as $rel) {
            $this->assertFileExists($this->raiz().'/'.$rel, "caminho inexistente: {$rel} — o gate mediria NADA");
        }

        $galeria = $this->semComentarios($meus[0]);
        $grupo = $this->semComentarios($meus[1]);

        // ⚠️ ISCA DO LEITOR: o removedor de comentário FUNCIONA. A prosa acima cita `ehKit`,
        // `galeriaDoRascunho` e `temAlerta`; se eles aparecessem no código limpo, a sonda estaria
        // lendo prosa.
        $this->assertStringNotContainsString('galeriaDoRascunho', $galeria, 'o método PRIVADO e INTOCÁVEL da Fase 186 foi copiado');
        $this->assertStringNotContainsString('galeriaDoRascunho', $grupo);

        // ⚠️⚠️ **A CHAMADA, e não o IDENTIFICADOR — e isto é CORREÇÃO MEDIDA nesta sessão.**
        //
        // A primeira redação proibia a string nua `temAlerta`, e ela reprovou o código CERTO: a
        // variável local `$temAlerta` de `GaleriaDaOfertaService::doRascunho()` **contém** aquela
        // substring. O invariante não é "a palavra não aparece", é **"não se CHAMA `temAlerta()`
        // por imagem"** (uma consulta por imagem, o N+1 clássico) — e o gate largo me empurrava
        // para o conserto errado: renomear a variável para o gate passar
        // (`feedback_gate_que_reprova_pelo_proprio_conserto`). ⚠️ O mesmo valia para `ehKit`.
        // ➡️ Agora a sonda ancora no OPERADOR DE CHAMADA, e há isca nas duas direções logo abaixo.
        $this->assertStringNotContainsString('->temAlerta(', $galeria, '`temAlerta()` é uma consulta POR IMAGEM (N+1)');
        $this->assertStringNotContainsString('->ehKit(', $grupo, '`ehKit()` exige `produto_base_id` — ver a divergência declarada');

        // ⚠️ ISCA DA FORMA ESTREITA, nas DUAS direções: ela ainda PEGA a chamada de verdade, e
        // NÃO pega a variável homônima. Sem estas duas linhas, estreitar a sonda poderia tê-la
        // tornado um gate que nunca reprova nada.
        $this->assertStringContainsString('->temAlerta(', '$x = $i->temAlerta();', 'ISCA: a sonda estreita deixou de pegar a CHAMADA');
        $this->assertStringNotContainsString('->temAlerta(', '$temAlerta = true;', 'ISCA: a sonda estreita ainda casa a VARIÁVEL');
        $this->assertStringContainsString('->ehKit(', '$x = $p->ehKit();', 'ISCA: a sonda estreita deixou de pegar a CHAMADA');

        // ⚠️ E a prova de que o código REALMENTE tem a variável que me fez estreitar a sonda —
        // sem ela, a correção acima seria sobre um caso que não existe mais.
        $this->assertStringContainsString('$temAlerta', $galeria, 'a variável que motivou o estreitamento desapareceu — remeça a sonda');

        $this->assertStringNotContainsString('localStorage', $galeria.$grupo);
        $this->assertStringNotContainsString('lucro', mb_strtolower($galeria.$grupo), 'VOC-03: "lucro líquido" é proibido');

        // ⚠️ E os LITERAIS de estado NÃO são escritos: as constantes dos modelos são LIDAS.
        $this->assertStringNotContainsString("'approved'", $galeria, 'literal de estado — use `PubAprovacaoImagem::APROVADA`');
        $this->assertStringNotContainsString("'pending'", $galeria);
        $this->assertStringNotContainsString("'GENERAL'", $galeria, 'literal de grupo — use `PubAprovacaoConjunto::GERAL`');

        // ⚠️ ISCA DO LEITOR, a outra direção: o que o código REALMENTE usa está lá.
        $this->assertStringContainsString('NO_CONJUNTO_FINAL', $galeria.$grupo.$this->semComentarios('app/Models/PubAprovacaoImagem.php'));
        $this->assertStringContainsString('noConjuntoFinal', $galeria, 'a galeria tem de LER a definição do modelo');
        $this->assertStringContainsString('doRascunho', $galeria);
        $this->assertStringContainsString('quantidade_kit', $grupo);

        // ─── O ESCOPO DO ASSUNTO: os commits deste plano não saíram do território ───
        //
        // ⚠️ **O autor NÃO discrimina** (as três sessões usam o MESMO `user.name`), e o range
        // SOZINHO pegaria a sessão paralela. O discriminador é o ESCOPO, com isca nas duas
        // direções.
        $this->assertSame(1, preg_match(self::ESCOPO, 'feat(187-07): x'), 'o escopo não casa o próprio assunto');
        $this->assertSame(0, preg_match(self::ESCOPO, 'feat(187-08): x'), 'o escopo casa a wave 8 — ele está largo');
        $this->assertSame(0, preg_match(self::ESCOPO, 'feat(196): x'), 'o escopo casa a sessão paralela — ele está largo');

        $log = $this->git('git log --format=%h:%s '.self::PARTIDA.'..HEAD');
        $hashes = [];
        foreach (array_filter(explode("\n", $log)) as $linha) {
            $corte = strpos($linha, ':');
            if ($corte !== false && preg_match(self::ESCOPO, substr($linha, $corte + 1)) === 1) {
                $hashes[] = substr($linha, 0, $corte);
            }
        }

        // ⚠️⚠️ **SEM `return` ANTECIPADO, e isto é CORREÇÃO MEDIDA nesta sessão.** A primeira
        // redação saía do método quando ainda não havia commit no escopo — e com isso a CONTAGEM
        // DE ASSERÇÕES deste caso **dependia de quantos commits existiam**: 26 antes de commitar,
        // **70** depois (medido). O manifesto reprovava, e o conserto barato seria "ajustar o
        // número", que é o treino errado.
        // ⚠️ É a família *"contagem que depende de quantos arquivos/tabelas/commits existem"* —
        // **NOVE** ocorrências nesta milestone, todas consertadas **na raiz**. ➡️ Aqui a raiz é
        // não ramificar: `$arquivos` é `[]` sem commit, e a prova de que o range não está vazio
        // virou **uma asserção coletada** em vez de um `return`.
        $arquivos = $hashes === []
            ? []
            : array_values(array_filter(array_map(
                'trim',
                explode("\n", $this->git('git show --name-only --format= '.implode(' ', $hashes))),
            )));

        $proibidos = [
            // Os 10 da Fase 186 em `Aprovacao/` (fora de `Fila/`) e os das waves 2/5/6.
            'app/Services/Publicador/Aprovacao/BloqueiosDaOfertaService.php',
            'app/Services/Publicador/Aprovacao/AndamentoDaOfertaService.php',
            'app/Services/Publicador/Aprovacao/VocabularioDoPortao.php',
            'app/Services/Publicador/Aprovacao/ComposicaoFinanceiraService.php',
            'app/Services/Publicador/Aprovacao/AprovacaoDaOfertaService.php',
            'app/Services/Publicador/Aprovacao/RevisaoDeImagemService.php',
            'app/Services/Publicador/Aprovacao/Fila/FilaDeAprovacaoService.php',
            'app/Services/Publicador/Aprovacao/Fila/ProximoARevisarService.php',
            'app/Services/Publicador/Aprovacao/Fila/FiltrosDaFilaService.php',
            // Os componentes já NO AR.
            'resources/js/Components/Mlb/Publicador/aprovacao/vocabularioDoPortao.jsx',
            'resources/js/Components/Mlb/Publicador/aprovacao/andamentoDoPortao.jsx',
            'resources/js/Components/Mlb/Publicador/aprovacao/SeloDeAndamentoInterno.jsx',
            'resources/js/Components/Mlb/Publicador/aprovacao/CabecalhoDaFila.jsx',
            'resources/js/Components/Mlb/Publicador/aprovacao/FaixaDeAndamento.jsx',
            'resources/js/Components/Mlb/Publicador/aprovacao/ProximoARevisar.jsx',
            'resources/js/Components/Mlb/Publicador/aprovacao/FiltrosDaFila.jsx',
            'resources/js/Components/Mlb/Publicador/aprovacao/PopoverDeFiltro.jsx',
            'resources/js/Components/Mlb/Publicador/publicacoes',
            'resources/js/Components/Mlb/Publicador/identidade',
            'resources/js/Components/Publicador/Mesa',
            'resources/js/Layouts/AppLayout.jsx',
            'resources/js/Pages/Mlb/Publicador/Editor.jsx',
            // Creative Engine, acervo, MCP, Support e os serviços de outras fases.
            'app/Services/Creative',
            'app/Services/Publicador/Criativos',
            'app/Services/Publicador/ImagemAssetService.php',
            'app/Support/Publicador',
            'app/Mcp',
            'app/Services/Portal',
            // Os gates INTOCÁVEIS e os de outras waves.
            'tests/js/publicador-header-zindex-ordem.test.js',
            'tests/js/estrutura-meus-anuncios.test.js',
            'tests/js/publicador-fila-filtros-187.test.js',
            'tests/js/publicador-fila-proximo-187.test.js',
            'tests/js/publicador-fila-tokens-187.test.js',
            'tests/js/publicador-fila-zindex-187.test.js',
            // Dependências (T-187-SC), migrations (esta fase NÃO tem) e a camada de tokens.
            'package.json', 'package-lock.json', 'composer.json', 'composer.lock',
            'database/migrations', 'tailwind.config.js',
            // Os artefatos de planejamento que este plano não move.
            '.planning/ROADMAP.md', '.planning/STATE.md',
        ];

        // ⚠️ `test -e` em CADA caminho ANTES de acreditar num diff vazio: caminho fantasma
        // "provaria" território intocado medindo NADA (seis vezes nesta milestone).
        // ⚠️⚠️ **UMA asserção, coletando os infratores** — e não uma por caminho: asserção por
        // item de lista é a armadilha que caiu 4 vezes na Fase 186, e aqui ela também fazia a
        // contagem deste caso crescer junto com a lista de proibidos.
        $fantasmas = array_values(array_filter(
            $proibidos,
            fn (string $caminho): bool => ! file_exists($this->raiz().'/'.$caminho),
        ));
        $this->assertSame([], $fantasmas, 'caminho inexistente na lista de proibidos — o gate mediria NADA');

        // ⚠️ ISCA DO `test -e`: a varredura SABE acusar caminho que não existe. Sem ela, uma lista
        // inteira de fantasmas passaria como "território intocado".
        $this->assertFalse(file_exists($this->raiz().'/app/Services/NaoExiste9z7'), 'a isca do fantasma virou caminho de verdade');
        $this->assertTrue(file_exists($this->raiz().'/'.$proibidos[0]), 'o CONTROLE da varredura não existe mais');

        // Prefixo de SEGMENTO, nunca `str_contains`: `app/Mcp` não pode casar `app/McpOutro.php`.
        $tocados = array_values(array_filter($arquivos, static function (string $a) use ($proibidos): bool {
            foreach ($proibidos as $p) {
                if ($a === $p || str_starts_with($a, $p.'/')) {
                    return true;
                }
            }

            return false;
        }));

        $this->assertSame([], $tocados, 'este plano saiu do território declarado');

        // ⚠️⚠️ E a prova de que o range NÃO está vazio por construção: havendo commit no escopo,
        // ele TOCOU os arquivos deste plano. Um `git show` vazio faria o `assertSame([], [])` acima
        // passar medindo NADA.
        //
        // ⚠️ **UMA asserção COLETADA, e não um `assertNotSame` que reprovaria antes do primeiro
        // commit.** É isso que mantém a contagem deste caso FIXA: com ou sem commit no escopo, o
        // número de asserções é o mesmo (ver o bloco do `$arquivos` acima).
        $doPlano = ['Fila/Galeria', 'Fila/Grupo', 'aprovacao/TabelaDaFila', 'aprovacao/LinhaDeProduto',
            'aprovacao/SubLinhaDeConjunto', 'aprovacao/QuadradosDeFoto', 'GrupoDaFilaServiceTest',
            'contagens-187-07', 'publicador-fila-tabela-187'];

        $semProva = $hashes !== [] && ! array_filter(
            $arquivos,
            static fn (string $a): bool => (bool) array_filter($doPlano, static fn (string $p): bool => str_contains($a, $p)),
        )
            ? ['os commits do escopo não tocaram arquivo deste plano: '.json_encode(array_slice($arquivos, 0, 8))]
            : [];

        $this->assertSame([], $semProva, 'o range do escopo está vazio por construção');

        // ⚠️ ISCA DA PROVA, nas duas direções: a varredura SABE reconhecer um arquivo deste plano
        // e SABE recusar um que não é. Sem ela, um `$doPlano` quebrado faria a asserção acima
        // passar sempre.
        $reconhece = static fn (string $a): bool => (bool) array_filter($doPlano, static fn (string $p): bool => str_contains($a, $p));
        $this->assertTrue($reconhece('app/Services/Publicador/Aprovacao/Fila/GaleriaDaOfertaService.php'), 'a prova não reconhece a galeria');
        $this->assertFalse($reconhece('app/Services/Creative/CreativeJuiz.php'), 'a prova reconhece arquivo de OUTRA fase');
    }

    /**
     * ⚠️ A BIJEÇÃO ids ↔ métodos, nos DOIS sentidos: método sem id **NUNCA RODA** (foi o
     * sobrevivente da mutação do plano 186-03) e id órfão é gate sobre **ZERO casos**.
     *
     * ⚠️ **UMA asserção por sentido, de contagem FIXA, coletando os infratores** — nunca uma por
     * linha do manifesto (a armadilha que caiu 4 vezes na Fase 186).
     *
     * ⚠️ E a contagem deste caso **NÃO depende do número de linhas do manifesto**: as asserções
     * são de forma fixa (`>=` para linhas e métodos), então acrescentar id aqui NÃO mexe neste
     * número. Era fragilidade por construção e foi tirada de propósito.
     */
    public function test_bijecao_do_manifesto_da_tabela_187_07(): void
    {
        $manifesto = $this->raiz().'/tests/gates/contagens-187-07.txt';
        $this->assertFileExists($manifesto, 'manifesto ausente — os gates deste plano rodariam sem régua');

        $linhas = array_values(array_filter(
            array_map('trim', explode("\n", (string) file_get_contents($manifesto))),
            static fn (string $l): bool => $l !== '' && ! str_starts_with($l, '#'),
        ));
        $this->assertGreaterThanOrEqual(4, count($linhas), 'o manifesto encolheu — algum id desapareceu');

        $filtrosDosIds = [];
        foreach ($linhas as $linha) {
            $partes = preg_split('/\s+/', $linha);
            if (($partes[1] ?? '') === 'php') {
                $filtrosDosIds[$partes[0]] = $partes[2];
            }
        }
        $this->assertNotSame([], $filtrosDosIds, 'nenhum id `php` no manifesto — a bijeção mediria NADA');

        $metodos = array_values(array_filter(
            get_class_methods($this),
            static fn (string $m): bool => str_starts_with($m, 'test_') && str_ends_with($m, '_187_07'),
        ));
        $this->assertGreaterThanOrEqual(10, count($metodos), 'os métodos deste arquivo encolheram');

        // ─── SENTIDO 1: método SEM id NUNCA RODA ───
        $semId = [];
        foreach ($metodos as $metodo) {
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

        // ─── SENTIDO 2: id ÓRFÃO é gate sobre ZERO casos ───
        $orfaos = [];
        foreach ($filtrosDosIds as $id => $filtro) {
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
        $this->assertSame([], $orfaos, "id órfão no manifesto: o gate dele roda sobre ZERO casos");

        // ⚠️ ISCA: a comparação SABE recusar. Sem ela, um `preg_match` que casasse tudo faria os
        // dois sentidos passarem medindo NADA.
        $this->assertSame(0, preg_match('/^'.reset($filtrosDosIds).'$/', 'test_metodo_que_ninguem_escreveu_187_07'));
    }

    /**
     * As ofertas ao vivo da conta, pelo MESMO caminho que o controller usa.
     *
     * @return \Illuminate\Support\Collection<int, PubAprovacaoOferta>
     */
    private function ofertasAoVivo(Company $empresa): \Illuminate\Support\Collection
    {
        return $this->fila()->paraAConta($empresa->id)
            ->whereIn('andamento', PubAprovacaoOferta::LIVE)
            ->with(['alvo', 'produto.rascunho'])
            ->get();
    }
}
