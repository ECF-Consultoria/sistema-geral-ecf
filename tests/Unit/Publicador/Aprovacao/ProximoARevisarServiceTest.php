<?php

namespace Tests\Unit\Publicador\Aprovacao;

use App\Models\Company;
use App\Models\EstruturaAnuncio;
use App\Models\EstruturaPublicacao;
use App\Models\PubAprovacaoOferta;
use App\Models\PubProduto;
use App\Models\PubRascunho;
use App\Models\PubRascunhoAlvo;
use App\Models\User;
use App\Services\Publicador\Aprovacao\AjustesDaOfertaService;
use App\Services\Publicador\Aprovacao\BloqueiosDaOfertaService;
use App\Services\Publicador\Aprovacao\ComposicaoFinanceiraService;
use App\Services\Publicador\Aprovacao\Fila\FilaDeAprovacaoService;
use App\Services\Publicador\Aprovacao\Fila\ProximoARevisarService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * ═══════════════════════════════════════════════════════════════════════════════════════════
 * Fase 187, plano 05 — "PRÓXIMO A REVISAR": a regra literal de TRÊS DEGRAUS e a frase.
 *
 * ⚠️⚠️ DE CADA GATE DESTE ARQUIVO, A PERGUNTA RESPONDIDA ANTES DE ELE EXISTIR:
 * **"o que precisaria estar errado no mundo para ele falhar?"** — gate cuja resposta é "nada"
 * é decorativo e não entra (`feedback_gate_vazio_por_construcao`).
 *
 *   · **os dois fallbacks** — alguém implementar só o 1º degrau, que é o que o
 *     `design_handoff_publicador/README-v2.md:170` escreveu. A faixa DESAPARECERIA exatamente
 *     quando toda a fila já foi tocada, que é o estado normal a partir do segundo dia de uso.
 *     Por isso há um caso por degrau, cada um com fixture que FORÇA a queda para ele.
 *   · **o `tocado`** — alguém ler `!tocado` ao contrário (ou esquecer o filtro): a faixa
 *     passaria a oferecer justamente a oferta em que alguém já está mexendo. A isca prova o
 *     sentido: DESTOCANDO a mesma oferta, ela passa a vencer.
 *   · **os cinco estados de FORA** — `aprovado`, `publicando`, `publicado`, `falha` e
 *     `preparando` virarem candidatos. `falha` é o caso perigoso: ele é o degrau **0** de
 *     `ORDEM` (o mais urgente da fila) e seria o primeiro a ser escolhido por quem lesse
 *     `ordenarFila()` sem o recorte de `LIVE`. Um caso por estado, os cinco.
 *   · **a ordem dos degraus 2 e 3** — alguém reordenar aqui em vez de delegar a
 *     `ordenarFila()`. Duas ordens da mesma fila divergiriam na primeira correção feita de um
 *     lado só, e a faixa passaria a apontar para uma oferta que a tabela mostra noutro lugar.
 *   · **o singular** — "1 pendências". O protótipo tem os dois
 *     (`Aprovacao.dc.html:1222`) e o critério 6 da fase escreveu só "N pendências".
 *   · **o custo** — `BloqueiosDaOfertaService::para()` chamado POR LINHA da fila em vez de só
 *     na próxima. `para()` lê a composição, que consulta a tarifa do ML (cache de 1 h por
 *     categoria+tipo+preço): uma chamada por linha seria uma requisição por linha.
 *   · **a conta** — a "próxima" sair de outra empresa. O recorte vem de
 *     `FilaDeAprovacaoService::paraAConta()`, que propaga a `RegraViolada` das duas âncoras
 *     nulas; aqui a asserção é nas DUAS direções.
 *
 * ⚠️ `Carbon::setTestNow()` em TODO caso que dependa de espera: teste dependente de `now()`
 * reprova por horário — medido neste projeto, um caso com `subHours(9)` falhava entre 00:00 e
 * 09:00 e passava depois.
 *
 * ⚠️ **ZERO REDE:** `Http::fake()` + `Http::preventStrayRequests()`. Nenhuma chamada à API real
 * do Mercado Livre sai daqui, e há caso provando que o payload não a chama.
 *
 * ⚠️ **ESTE ARQUIVO NÃO ALTERA NADA DAS FASES 186 E 187-02/04.** `BloqueiosDaOfertaService`,
 * `FilaDeAprovacaoService`, `AndamentoDaOfertaService` e `PubAprovacaoOferta` são **LIDOS** (e,
 * nos casos de custo, SUBCLASSADOS no teste — nunca alterados no disco).
 *
 * ⚠️ A fila **nasce vazia** e isso é honesto: medido em 15/10/2026, **nada** cria
 * `PubAprovacaoOferta` em produção — quem passa a criar é a **Fase 194**. Estes casos montam o
 * grafo real (produto → rascunho → alvo → oferta) à mão, porque é a única forma de medir hoje —
 * e **fixture não é fonte de tela**: nenhum número de exemplo chega ao payload.
 * ═══════════════════════════════════════════════════════════════════════════════════════════
 */
class ProximoARevisarServiceTest extends TestCase
{
    use RefreshDatabase;

    /** O commit de PARTIDA deste plano, capturado na PRIMEIRA ação do executor. */
    private const PARTIDA = '1dceb28f';

    /**
     * O ESCOPO DO ASSUNTO deste plano.
     *
     * ⚠️ **O autor NÃO discrimina nada nesta árvore:** as sessões rodam com o MESMO
     * `user.name` (`MB.ECF-100376`), então `git log --format=%an` devolve um valor só para
     * tudo. O discriminador real é o escopo do assunto — e há isca nas duas direções.
     */
    private const ESCOPO = '/\(187-05\)/';

    /** Clássico — `EstruturaPublicacao::LISTING_TYPES`, lido, nunca escrito à mão. */
    private const CLASSICO = 'gold_special';

    /** Contador de SKU: `alvo_id` é UNIQUE, então cada oferta precisa do seu alvo. */
    private int $sequencia = 0;

    protected function setUp(): void
    {
        parent::setUp();

        // ⚠️ A espera é medida contra o `now()`: sem o agora PARADO, "2 h" vira "3 h" às 10:00.
        Carbon::setTestNow('2026-10-15 09:00:00');

        // ⚠️ `fake()` + `preventStrayRequests()`: nenhuma chamada real sai daqui.
        Http::fake();
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ─────────────────────────────────────────────────────────────────────────────────────────
    // Fixture: o grafo REAL — produto -> rascunho -> alvo -> oferta do portão.
    // ─────────────────────────────────────────────────────────────────────────────────────────

    /**
     * @param  array{tocado?: bool, entrou?: ?string, kit?: int, titulo?: ?string, responsavel?: ?User, tipo?: string, categoria?: ?string}  $o
     */
    private function oferta(Company $empresa, string $andamento, array $o = []): PubAprovacaoOferta
    {
        $this->sequencia++;
        $sku = 'PROX-'.$this->sequencia;

        $produto = PubProduto::create([
            'company_id' => $empresa->id,
            'sku' => $sku,
            'nome' => $o['produto'] ?? 'Poltrona Beny',
            'origem' => PubProduto::ORIGEM_PUBLICADOR,
            'quantidade_kit' => $o['kit'] ?? 1,
        ]);

        $rascunho = PubRascunho::create(array_filter([
            'produto_id' => $produto->id,
            'categoria_id' => $o['categoria'] ?? null,
        ], fn ($v) => $v !== null));

        $alvo = PubRascunhoAlvo::create([
            'rascunho_id' => $rascunho->id,
            'listing_type_id' => $o['tipo'] ?? self::CLASSICO,
            'titulo' => $o['titulo'] ?? 'Poltrona Beny Decorativa Veludo Cinza',
            'posicao' => 0,
        ]);

        return PubAprovacaoOferta::create([
            'alvo_id' => $alvo->id,
            'rascunho_id' => $rascunho->id,
            'produto_id' => $produto->id,
            'company_id' => $empresa->id,
            'andamento' => $andamento,
            'tocado' => $o['tocado'] ?? false,
            // ⚠️ `array_key_exists` e NÃO `??`: `['entrou' => null]` é o caso da espera
            // DESCONHECIDA, e o `??` o devolveria para o default — o caso passaria medindo
            // outra coisa.
            'entrou_na_fila_em' => array_key_exists('entrou', $o) ? $o['entrou'] : '2026-10-15 07:00:00',
            'responsavel_id' => isset($o['responsavel']) ? $o['responsavel']->id : null,
        ]);
    }

    private function fila(): FilaDeAprovacaoService
    {
        return app(FilaDeAprovacaoService::class);
    }

    private function servico(): ProximoARevisarService
    {
        return app(ProximoARevisarService::class);
    }

    /**
     * Troca `BloqueiosDaOfertaService` por um ESPIÃO que conta chamadas e devolve o que o teste
     * declara.
     *
     * ⚠️ É SUBCLASSE, não mock de string: o espião herda a assinatura real, então renomear
     * `para()` no serviço da Fase 186 quebra este teste em vez de deixá-lo passar calado.
     *
     * ⚠️ E ele NÃO substitui a prova de integração: `test_a_integracao_real_...` exercita o
     * `para()` DE VERDADE, para que as chaves `aprovavel`/`bloqueios` não sejam uma invenção
     * coerente entre o espião e o serviço.
     */
    private function espiaoDeBloqueios(bool $aprovavel = true, int $quantos = 0): object
    {
        $espiao = new class(app(ComposicaoFinanceiraService::class), app(AjustesDaOfertaService::class)) extends BloqueiosDaOfertaService
        {
            public int $chamadas = 0;

            /** @var list<int> */
            public array $ofertasVistas = [];

            public bool $oAprovavel = true;

            public int $quantosBloqueios = 0;

            public function para(PubAprovacaoOferta $oferta): array
            {
                $this->chamadas++;
                $this->ofertasVistas[] = (int) $oferta->id;

                $bloqueios = [];
                for ($i = 0; $i < $this->quantosBloqueios; $i++) {
                    $bloqueios[] = ['chave' => 'fotos_a_conferir', 'texto' => 'bloqueio '.$i];
                }

                return [
                    'bloqueios' => $bloqueios,
                    'aprovavel' => $this->oAprovavel,
                    'ao_vivo' => true,
                ];
            }
        };

        $espiao->oAprovavel = $aprovavel;
        $espiao->quantosBloqueios = $quantos;

        $this->app->instance(BloqueiosDaOfertaService::class, $espiao);

        return $espiao;
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

    /**
     * Os commits DESTE plano: o range `PARTIDA..HEAD` FILTRADO pelo escopo do assunto.
     *
     * @return array<string, string> hash => assunto
     */
    private function commitsDoEscopo(): array
    {
        $linhas = array_filter(explode("\n", $this->git('git log --no-merges --format=%H%x09%s '.self::PARTIDA.'..HEAD')));

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

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // (1) OS TRÊS DEGRAUS — um caso por degrau, com fixture que FORÇA a queda
    // ═════════════════════════════════════════════════════════════════════════════════════════

    /**
     * ⚠️ **O que precisaria estar errado para falhar:** o 1º degrau ignorar a espera e pegar
     * qualquer `aguardando_aprovacao` não tocada. Quem espera mais é quem o operador tem de
     * abrir primeiro — é a única razão de a faixa existir.
     *
     * @group phase187
     */
    public function test_degrau_1_vence_a_aguardando_nao_tocada_de_maior_espera_187_05(): void
    {
        $this->espiaoDeBloqueios();
        $empresa = Company::factory()->create();

        $recente = $this->oferta($empresa, PubAprovacaoOferta::AGUARDANDO_APROVACAO, ['entrou' => '2026-10-15 08:30:00']);
        $antiga = $this->oferta($empresa, PubAprovacaoOferta::AGUARDANDO_APROVACAO, ['entrou' => '2026-10-13 08:00:00']);
        $meio = $this->oferta($empresa, PubAprovacaoOferta::AGUARDANDO_APROVACAO, ['entrou' => '2026-10-14 08:00:00']);

        $base = $this->fila()->paraAConta($empresa->id, null);
        $escolhida = $this->servico()->escolher($this->servico()->aoVivo($base));

        $this->assertNotNull($escolhida);
        $this->assertSame($antiga->id, $escolhida->id, 'degrau 1: venceu quem NÃO espera mais.');

        // ⚠️ ISCA — as três existem e as outras duas são candidatas legítimas. Sem isto, "a
        // antiga venceu" não distinguiria escolha correta de "só existe uma oferta".
        $this->assertSame(3, PubAprovacaoOferta::where('company_id', $empresa->id)->count());
        $this->assertNotSame($antiga->id, $recente->id);
        $this->assertNotSame($antiga->id, $meio->id);
    }

    /**
     * ⚠️ **O que precisaria estar errado para falhar:** o 2º degrau NÃO EXISTIR — que é
     * exatamente o que o `README-v2.md:170` descreve. Sem ele, uma fila sem nenhuma
     * `aguardando_aprovacao` não tocada (mas com `em_revisao_ecf`/`ajustes_solicitados` novas)
     * não teria próxima NENHUMA, e a faixa sumiria com trabalho esperando.
     *
     * @group phase187
     */
    public function test_degrau_2_vence_a_primeira_ao_vivo_nao_tocada_187_05(): void
    {
        $this->espiaoDeBloqueios();
        $empresa = Company::factory()->create();

        // Nenhuma `aguardando_aprovacao` NÃO TOCADA: a única está tocada.
        $this->oferta($empresa, PubAprovacaoOferta::AGUARDANDO_APROVACAO, ['tocado' => true, 'entrou' => '2026-10-10 08:00:00']);
        // Duas candidatas do degrau 2, uma tocada e uma não.
        $tocadaDoisDiasAntes = $this->oferta($empresa, PubAprovacaoOferta::AJUSTES_SOLICITADOS, ['tocado' => true, 'entrou' => '2026-10-11 08:00:00']);
        $naoTocada = $this->oferta($empresa, PubAprovacaoOferta::EM_REVISAO_ECF, ['tocado' => false, 'entrou' => '2026-10-14 08:00:00']);

        $base = $this->fila()->paraAConta($empresa->id, null);
        $escolhida = $this->servico()->escolher($this->servico()->aoVivo($base));

        $this->assertNotNull($escolhida, 'degrau 2: a faixa sumiu com trabalho esperando.');
        $this->assertSame($naoTocada->id, $escolhida->id);

        // ⚠️ ISCA — a tocada do degrau 2 espera MAIS e perdeu de propósito: `!tocado` vem antes
        // da espera nos degraus 1 e 2. Sem isto o caso passaria por maior-espera, não por
        // não-tocada.
        $this->assertTrue($tocadaDoisDiasAntes->entrou_na_fila_em->lt($naoTocada->entrou_na_fila_em));
    }

    /**
     * ⚠️ **O que precisaria estar errado para falhar:** o 3º degrau não existir. É o estado
     * NORMAL a partir do segundo dia de uso — toda a fila já foi tocada —, e sem ele a faixa
     * desapareceria justamente quando há mais trabalho em aberto.
     *
     * @group phase187
     */
    public function test_degrau_3_vence_a_primeira_ao_vivo_quando_todas_foram_tocadas_187_05(): void
    {
        $this->espiaoDeBloqueios();
        $empresa = Company::factory()->create();

        // TODAS tocadas: os degraus 1 e 2 ficam vazios.
        $ajustes = $this->oferta($empresa, PubAprovacaoOferta::AJUSTES_SOLICITADOS, ['tocado' => true, 'entrou' => '2026-10-09 08:00:00']);
        $aguardando = $this->oferta($empresa, PubAprovacaoOferta::AGUARDANDO_APROVACAO, ['tocado' => true, 'entrou' => '2026-10-10 08:00:00']);

        $base = $this->fila()->paraAConta($empresa->id, null);
        $aoVivo = $this->servico()->aoVivo($base);
        $escolhida = $this->servico()->escolher($aoVivo);

        $this->assertNotNull($escolhida, 'degrau 3: a fila inteira tocada deixou a faixa sem próxima.');

        // ⚠️ "A PRIMEIRA" é a primeira de `ordenarFila()`, e NÃO a mais antiga: `ORDEM` põe
        // `aguardando_aprovacao` no degrau 1 e `ajustes_solicitados` no 2, então a de ajustes
        // perde mesmo esperando um dia MAIS.
        $this->assertSame($aguardando->id, $escolhida->id);
        $this->assertSame($aguardando->id, $aoVivo->first()->id, 'a escolha do degrau 3 divergiu da ordem da fila.');
        $this->assertTrue($ajustes->entrou_na_fila_em->lt($aguardando->entrou_na_fila_em), 'ISCA: a de ajustes não espera mais — o caso não prova o degrau.');
    }

    /**
     * ⚠️ **O que precisaria estar errado para falhar:** alguém escolher sobre `ordenarFila()`
     * SEM o recorte de `LIVE`. `falha` é o degrau **0** de `ORDEM` — o mais urgente da fila —,
     * então ela seria a PRIMEIRA escolhida, e a faixa ofereceria "Revisar agora" numa oferta que
     * não é revisável nem aprovável.
     *
     * ⚠️ Um caso por estado de fora (os 5), e a asserção é de contagem FIXA coletando os
     * infratores — nunca uma asserção por estado (a armadilha que caiu 4 vezes na Fase 186).
     *
     * @group phase187
     */
    public function test_sem_oferta_ao_vivo_devolve_nulo_e_os_cinco_estados_de_fora_nunca_vencem_187_05(): void
    {
        $this->espiaoDeBloqueios();

        $deFora = array_values(array_diff(array_keys(PubAprovacaoOferta::ANDAMENTOS), PubAprovacaoOferta::LIVE));
        $this->assertSame(5, count($deFora), 'ISCA: `ANDAMENTOS` menos `LIVE` deixou de ser 5 — remeça o caso.');

        $infratores = [];
        foreach ($deFora as $estado) {
            $empresa = Company::factory()->create();
            $this->oferta($empresa, $estado);

            $base = $this->fila()->paraAConta($empresa->id, null);
            $escolhida = $this->servico()->escolher($this->servico()->aoVivo($base));

            if ($escolhida !== null) {
                $infratores[] = $estado;
            }
            if ($this->servico()->para($base) !== null) {
                $infratores[] = $estado.' (payload)';
            }
        }

        $this->assertSame([], $infratores, 'Estado FORA de `LIVE` virou a próxima a revisar: '.implode(', ', $infratores));

        // ⚠️ ISCA INVERTIDA — a mesma montagem COM um estado de `LIVE` ACHA a próxima. Sem isto,
        // "nunca vence" não distinguiria o recorte correto de uma consulta que não devolve nada.
        $viva = Company::factory()->create();
        $aVez = $this->oferta($viva, PubAprovacaoOferta::AGUARDANDO_APROVACAO);
        $baseViva = $this->fila()->paraAConta($viva->id, null);
        $this->assertSame($aVez->id, $this->servico()->escolher($this->servico()->aoVivo($baseViva))?->id);
        $this->assertNotNull($this->servico()->para($baseViva));
    }

    /**
     * ⚠️⚠️ **ESTE CASO NASCEU DE UMA MUTAÇÃO SOBREVIVENTE, e a sobrevivente era lacuna de
     * cobertura REAL — não equivalência.** Na campanha de 16/10/2026, tirar o
     * `->filter(ehLive())` de dentro de `escolher()` passou com 13 de 13 verdes: todos os casos
     * chegavam ali por `aoVivo()`, que já recorta `LIVE` **no SQL**, então a guarda de dentro
     * nunca era exercitada.
     *
     * ⚠️ **O que precisaria estar errado para falhar:** uma wave futura chamar `escolher()` com
     * a fila INTEIRA (é um método público, e `ordenarFila()` devolve a fila inteira ordenada).
     * `falha` é o degrau **0** de `ORDEM` — o mais urgente —, então ela seria a PRIMEIRA
     * escolhida, e a faixa ofereceria "Revisar agora" numa oferta que não é revisável nem
     * aprovável. A guarda de dentro é o que impede isso, e agora ela TEM teste.
     *
     * @group phase187
     */
    public function test_escolher_recusa_oferta_fora_de_live_mesmo_recebendo_a_fila_inteira_187_05(): void
    {
        $this->espiaoDeBloqueios();
        $empresa = Company::factory()->create();

        $falha = $this->oferta($empresa, PubAprovacaoOferta::FALHA, ['entrou' => '2026-10-01 08:00:00']);
        $viva = $this->oferta($empresa, PubAprovacaoOferta::AGUARDANDO_APROVACAO, ['entrou' => '2026-10-14 08:00:00']);
        $this->oferta($empresa, PubAprovacaoOferta::PREPARANDO);
        $this->oferta($empresa, PubAprovacaoOferta::PUBLICADO);

        // ⚠️ A fila INTEIRA, sem recorte de `LIVE` — o que um chamador distraído passaria.
        $inteira = $this->fila()->ordenar(
            PubAprovacaoOferta::where('company_id', $empresa->id)->with(['alvo', 'produto', 'responsavel'])->get()
        );

        // ⚠️ A ARMADILHA, medida: a PRIMEIRA da fila inteira é a `falha` (degrau 0 de `ORDEM`).
        // Sem esta asserção o caso não provaria que havia armadilha nenhuma.
        $this->assertSame($falha->id, $inteira->first()->id, 'ISCA: `falha` não é a primeira da fila ordenada — a armadilha não existe neste fixture.');

        $escolhida = $this->servico()->escolher($inteira);

        $this->assertSame($viva->id, $escolhida?->id, 'a escolha caiu numa oferta FORA de `LIVE` — a guarda de `ehLive()` saiu.');
        $this->assertTrue($escolhida->ehLive());

        // ⚠️ ISCA INVERTIDA — a MESMA coleção SEM nenhuma ao vivo devolve `null`, e não a
        // `falha`. Sem isto, "escolheu a viva" não distinguiria o recorte de uma ordem favorável.
        $soMortas = $inteira->filter(fn (PubAprovacaoOferta $o) => ! $o->ehLive())->values();
        $this->assertCount(3, $soMortas);
        $this->assertNull($this->servico()->escolher($soMortas), 'coleção sem nenhuma ao vivo devolveu oferta.');
    }

    /**
     * ⚠️ **O que precisaria estar errado para falhar:** o `!tocado` do 1º degrau ser lido ao
     * contrário, ou esquecido. A faixa passaria a oferecer justamente a oferta em que alguém já
     * está mexendo — e a isca prova o SENTIDO, não só o silêncio: destocando a MESMA oferta, ela
     * passa a vencer.
     *
     * @group phase187
     */
    public function test_aguardando_tocada_nao_vence_o_degrau_1_e_destocada_passa_a_vencer_187_05(): void
    {
        $this->espiaoDeBloqueios();
        $empresa = Company::factory()->create();

        $tocadaEAntiga = $this->oferta($empresa, PubAprovacaoOferta::AGUARDANDO_APROVACAO, ['tocado' => true, 'entrou' => '2026-10-08 08:00:00']);
        $novaENaoTocada = $this->oferta($empresa, PubAprovacaoOferta::AGUARDANDO_APROVACAO, ['tocado' => false, 'entrou' => '2026-10-14 08:00:00']);

        $base = fn () => $this->fila()->paraAConta($empresa->id, null);

        $this->assertSame(
            $novaENaoTocada->id,
            $this->servico()->escolher($this->servico()->aoVivo($base()))?->id,
            'a tocada venceu o degrau 1 — `!tocado` está invertido ou ausente.',
        );

        // ⚠️ A ISCA: destocando a antiga, ela passa a vencer (ela espera MAIS).
        $tocadaEAntiga->update(['tocado' => false]);

        $this->assertSame(
            $tocadaEAntiga->id,
            $this->servico()->escolher($this->servico()->aoVivo($base()))?->id,
            'ISCA: destocar a de maior espera não mudou a escolha — o filtro de `tocado` não mede nada.',
        );
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // (2) A FRASE E O ESTADO — os literais do protótipo, byte a byte
    // ═════════════════════════════════════════════════════════════════════════════════════════

    /**
     * ⚠️ **O que precisaria estar errado para falhar:** a frase sair fora de ordem, com hífen no
     * lugar do travessão (U+2014), com `x` no lugar do `×` (U+00D7), com separador sem espaço, ou
     * inventando responsável quando não há nenhum.
     *
     * ⚠️ O literal medido está em `design_handoff_publicador/Aprovacao.dc.html:1221`-`:1222`
     * (`descr` e `next.name`) — ⚠️ o plano cita `:1223`/`:1224`, DESLOCADOS em 2.
     *
     * @group phase187
     */
    public function test_a_frase_sai_na_ordem_literal_do_prototipo_187_05(): void
    {
        $this->espiaoDeBloqueios();
        $empresa = Company::factory()->create();
        $ana = User::factory()->create(['name' => 'Ana Ribeiro']);

        $this->oferta($empresa, PubAprovacaoOferta::AGUARDANDO_APROVACAO, [
            'entrou' => '2026-10-15 07:00:00',   // 120 min = "2 h"
            'responsavel' => $ana,
            'produto' => 'Poltrona Beny',
            'titulo' => 'Poltrona Beny Decorativa Veludo Cinza',
        ]);

        $faixa = $this->servico()->para($this->fila()->paraAConta($empresa->id, null));

        $this->assertNotNull($faixa);
        $this->assertSame('Clássico · Unidade · aguardando há 2 h · responsável Ana Ribeiro', $faixa['sub']);
        $this->assertSame('Poltrona Beny — Poltrona Beny Decorativa Veludo Cinza', $faixa['nome']);
        $this->assertSame(120, $faixa['espera_minutos']);

        // ⚠️ O travessão é U+2014 e NÃO hífen; o separador é U+00B7 COM espaços. Medido por
        // codepoint, não por aparência: os dois pares são indistinguíveis a olho.
        $this->assertStringContainsString(" \u{2014} ", $faixa['nome'], 'o travessão virou hífen (U+2014 x U+002D).');
        $this->assertStringNotContainsString(' - ', $faixa['nome']);
        $this->assertStringContainsString(" \u{00B7} ", $faixa['sub']);

        // ─── O KIT: "Combo ×N" com o sinal de multiplicação (U+00D7), não a letra x ───
        $comKit = Company::factory()->create();
        $this->oferta($comKit, PubAprovacaoOferta::AGUARDANDO_APROVACAO, [
            'kit' => 2,
            'tipo' => 'gold_pro',
            'responsavel' => $ana,
        ]);
        $doKit = $this->servico()->para($this->fila()->paraAConta($comKit->id, null));

        $this->assertNotNull($doKit);
        $this->assertSame('Premium · Combo ×2 · aguardando há 2 h · responsável Ana Ribeiro', $doKit['sub']);
        $this->assertStringContainsString("Combo \u{00D7}2", $doKit['sub'], 'o × virou a letra x (U+00D7 x U+0078).');

        // ─── SEM responsável: a frase NÃO INVENTA — o trecho sai fora ───
        $semResp = Company::factory()->create();
        $this->oferta($semResp, PubAprovacaoOferta::AGUARDANDO_APROVACAO, ['responsavel' => null]);
        $doSemResp = $this->servico()->para($this->fila()->paraAConta($semResp->id, null));

        $this->assertNotNull($doSemResp);
        $this->assertSame('Clássico · Unidade · aguardando há 2 h', $doSemResp['sub']);
        $this->assertStringNotContainsString('responsável', $doSemResp['sub'], 'a frase inventou um responsável que não existe.');

        // ⚠️ CONTROLE: os literais estão NO PROTÓTIPO. Sem isto, os quatro acima poderiam ser
        // invenção coerente — iguais no código e no teste, errados na tela.
        $prototipo = (string) file_get_contents($this->raiz().'/design_handoff_publicador/Aprovacao.dc.html');
        foreach ([
            "' \u{00B7} ' + (set.units > 1 ? 'Combo \u{00D7}' + set.units : 'Unidade')",
            "' \u{00B7} aguardando há '",
            "' \u{00B7} responsável '",
            ".name + ' \u{2014} ' + nextO.title",
        ] as $literal) {
            $this->assertStringContainsString($literal, $prototipo, "o protótipo não tem \"{$literal}\" — a referência mudou.");
        }
    }

    /**
     * ⚠️ **O que precisaria estar errado para falhar:** o singular desaparecer ("1 pendências"),
     * ou o plural ser usado no 1. O protótipo tem os DOIS (`Aprovacao.dc.html:1222`) e o
     * critério 6 da fase escreveu só "N pendências".
     *
     * ⚠️ **E o ZERO:** `0 pendência` NUNCA pode aparecer. Para oferta ao vivo,
     * `aprovavel = ao_vivo && bloqueios === []`, então zero bloqueios ⟺ "Pronto para aprovar" —
     * a equivalência é ASSERTADA, não presumida, porque é ela que fecha o caso do zero.
     *
     * @group phase187
     */
    public function test_o_estado_tem_singular_plural_e_o_pronto_para_aprovar_187_05(): void
    {
        $casos = [
            [true, 0, 'Pronto para aprovar'],
            [false, 1, '1 pendência'],
            [false, 2, '2 pendências'],
            [false, 7, '7 pendências'],
        ];

        $infratores = [];
        foreach ($casos as [$aprovavel, $quantos, $esperado]) {
            $this->espiaoDeBloqueios($aprovavel, $quantos);
            $empresa = Company::factory()->create();
            $this->oferta($empresa, PubAprovacaoOferta::AGUARDANDO_APROVACAO);

            $faixa = $this->servico()->para($this->fila()->paraAConta($empresa->id, null));
            $this->assertNotNull($faixa);

            if ($faixa['estado'] !== $esperado) {
                $infratores[] = "esperava \"{$esperado}\" e veio \"{$faixa['estado']}\"";
            }
            if ($faixa['pendencias'] !== $quantos || $faixa['aprovavel'] !== $aprovavel) {
                $infratores[] = "contagem/flag divergente em \"{$esperado}\"";
            }
            $this->assertStringNotContainsString('0 pendência', $faixa['estado'], 'o estado afirmou "0 pendência" — zero é "Pronto para aprovar".');
        }

        $this->assertSame([], $infratores, 'O estado da faixa divergiu: '.implode(' | ', $infratores));

        // ⚠️ ISCA — a sonda do singular DISTINGUE as duas formas. Sem ela, um `str_contains` de
        // "pendência" casaria dentro de "pendências" e o caso do singular seria vazio.
        $this->assertStringContainsString('1 pendência', '1 pendências', 'ISCA: a montagem do caso não distingue singular de plural.');
        $this->assertNotSame('1 pendência', '1 pendências');
    }

    /**
     * ⚠️ **O que precisaria estar errado para falhar:** o rótulo da espera ser montado no
     * CLIENTE (`new Date()` no JSX → `Invalid Date` e o deslocamento de fuso de data-only em
     * BRT, que já morderam este projeto duas vezes), ou a fórmula divergir do protótipo.
     *
     * ⚠️⚠️ **DIVERGÊNCIA REGISTRADA:** o protótipo escreve `' d'` (`Aprovacao.dc.html:1054`), e
     * o `<action>` do plano escreveu *"3 dias"*. **O protótipo ganha nos RÓTULOS** — e a razão
     * prática é mais forte que a regra: o MESMO `wait()` é consumido pela coluna de espera da
     * linha da tabela (`:1247`), que é do plano 187-08. Dois rótulos para a mesma medida na
     * mesma tela seriam duas verdades.
     *
     * @group phase187
     */
    public function test_o_rotulo_da_espera_e_o_do_prototipo_187_05(): void
    {
        $servico = $this->servico();

        $casos = [
            [0, '0 min'],
            [1, '1 min'],
            [59, '59 min'],
            [60, '1 h'],
            [120, '2 h'],
            [1439, '24 h'],
            // ⚠⚠ REAPONTADO EM 16/10/2026 — DECISÃO DO USUÁRIO: "dia"/"dias" POR EXTENSO.
            // O protótipo escreve `' d'` e a régua desta milestone é que ele ganha nos
            // RÓTULOS — **esta é a exceção**, e ela nasce de CONFLITO entre duas regras do
            // projeto, resolvido pelo usuário: a régua permanente *"evitar jargão e
            // abreviatura não explicada na tela"* (07/07/2026) vale mais que a fidelidade
            // literal aqui, porque *"aguardando há 3 d"* é exatamente o caso que ela existe
            // para impedir.
            // ⚠️ `min` e `h` FICAM abreviados, de propósito: são convenção corrente em
            // português e se leem sem explicação; `d` para dias não é. **Não uniformize.**
            // ⚠️ E o SINGULAR entrou: "1 dia", nunca "1 dias".
            [1440, '1 dia'],
            [2880, '2 dias'],
            [4320, '3 dias'],
        ];

        $infratores = [];
        foreach ($casos as [$minutos, $esperado]) {
            $obtido = $servico->rotuloDaEspera($minutos);
            if ($obtido !== $esperado) {
                $infratores[] = "{$minutos} min -> esperava \"{$esperado}\" e veio \"{$obtido}\"";
            }
        }
        $this->assertSame([], $infratores, 'O rótulo da espera divergiu do protótipo: '.implode(' | ', $infratores));

        // ⚠️ CONTROLE no protótipo: a fórmula é a dele, lida do arquivo.
        $prototipo = (string) file_get_contents($this->raiz().'/design_handoff_publicador/Aprovacao.dc.html');
        $this->assertStringContainsString(
            "wait(m) { return m < 60 ? m + ' min' : m < 1440 ? Math.round(m / 60) + ' h' : Math.round(m / 1440) + ' d'; }",
            $prototipo,
            'a fórmula de `wait()` mudou no protótipo — remeça a divergência. ⚠️ Este controle '
            .'segue valendo DEPOIS da decisão do usuário de 16/10: ele prova que o protótipo '
            .'continua escrevendo a abreviatura de um caractere, e portanto que a nossa '
            .'divergência é DELIBERADA e '
            .'nomeada — não um desvio que ninguém percebeu.',
        );

        // ⚠️ Espera DESCONHECIDA não vira espera zero nem espera infinita: o trecho sai fora.
        $this->espiaoDeBloqueios();
        $empresa = Company::factory()->create();
        $this->oferta($empresa, PubAprovacaoOferta::AGUARDANDO_APROVACAO, ['entrou' => null]);
        $faixa = $this->servico()->para($this->fila()->paraAConta($empresa->id, null));

        $this->assertNotNull($faixa);
        $this->assertNull($faixa['espera_minutos']);
        $this->assertStringNotContainsString('aguardando há', $faixa['sub'], 'espera nula virou rótulo inventado.');
        $this->assertSame('Clássico · Unidade', $faixa['sub']);
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // (3) O CUSTO, A DELEGAÇÃO E A CONTA
    // ═════════════════════════════════════════════════════════════════════════════════════════

    /**
     * ⚠️ **O que precisaria estar errado para falhar:** `BloqueiosDaOfertaService::para()` ser
     * chamado por LINHA da fila. Ele lê a composição, que consulta a tarifa do ML (cache de 1 h
     * por categoria+tipo+preço): uma chamada por linha seria **uma requisição por linha**, que é
     * a classe do incidente de 30/07/2026 (requisições lentas ocupando os workers do php-fpm).
     *
     * @group phase187
     */
    public function test_bloqueios_para_e_chamado_uma_unica_vez_187_05(): void
    {
        $espiao = $this->espiaoDeBloqueios();
        $empresa = Company::factory()->create();

        // 6 ao vivo + 2 de fora: a fila tem tamanho de verdade.
        $esperada = $this->oferta($empresa, PubAprovacaoOferta::AGUARDANDO_APROVACAO, ['entrou' => '2026-10-10 08:00:00']);
        for ($i = 0; $i < 5; $i++) {
            $this->oferta($empresa, PubAprovacaoOferta::EM_REVISAO_ECF, ['entrou' => '2026-10-11 08:00:00']);
        }
        $this->oferta($empresa, PubAprovacaoOferta::APROVADO);
        $this->oferta($empresa, PubAprovacaoOferta::PUBLICADO);

        $faixa = $this->servico()->para($this->fila()->paraAConta($empresa->id, null));

        $this->assertNotNull($faixa);
        $this->assertSame(1, $espiao->chamadas, 'T-187-51: `para()` foi chamado mais de uma vez — é uma requisição ao ML por chamada.');
        $this->assertSame([$esperada->id], $espiao->ofertasVistas, '`para()` foi chamado para outra oferta que não a próxima.');

        // ⚠️ ISCA — a fila TEM 8 linhas e 6 ao vivo. Sem isto, "1 chamada" não distinguiria o
        // recorte correto de uma fila com uma linha só.
        $this->assertSame(8, PubAprovacaoOferta::where('company_id', $empresa->id)->count());
        $this->assertCount(6, $this->servico()->aoVivo($this->fila()->paraAConta($empresa->id, null)));
    }

    /**
     * ⚠️ **O que precisaria estar errado para falhar:** o serviço reordenar a fila por conta
     * própria, ou escrever os estados de `LIVE` à mão. Duas ordens (ou duas listas de estados)
     * da mesma fila divergiriam na primeira correção feita de um lado só — e a faixa passaria a
     * apontar para uma oferta que a tabela mostra noutro lugar.
     *
     * ⚠️ O código é lido **SEM COMENTÁRIO**: palavra no docblock que explica *"não uso isso"*
     * casa com o gate que conta aquela palavra, e o conserto barato é apagar a explicação em vez
     * de consertar o código. Três mutações da wave 4 abortaram exatamente por isso.
     *
     * @group phase187
     */
    public function test_a_ordem_e_os_estados_sao_delegados_e_nao_reescritos_187_05(): void
    {
        $rel = 'app/Services/Publicador/Aprovacao/Fila/ProximoARevisarService.php';
        $abs = $this->raiz().'/'.$rel;
        $this->assertFileExists($abs, "gate sobre caminho fantasma: {$rel}");

        $codigo = $this->semComentariosPhp((string) file_get_contents($abs));

        // ⚠️ ÂNCORA: a leitura não está vazia nem cega. Sem ela as negativas abaixo seriam
        // vazias por construção.
        $this->assertStringContainsString('class ProximoARevisarService', $codigo);
        $this->assertStringContainsString('PubAprovacaoOferta::LIVE', $codigo, 'o serviço não LÊ `LIVE` do modelo.');

        $proibidos = [
            "->sort(" => 'reordena a fila em vez de delegar a `ordenarFila()`',
            "->sortBy(" => 'reordena a fila em vez de delegar a `ordenarFila()`',
            "->sortByDesc(" => 'reordena a fila em vez de delegar a `ordenarFila()`',
            "orderBy(" => 'ordena no SQL em vez de delegar a `ordenarFila()`',
            "'aguardando_aprovacao'" => 'escreveu o estado à mão em vez de ler a constante',
            '"aguardando_aprovacao"' => 'escreveu o estado à mão em vez de ler a constante',
            "'em_revisao_ecf'" => 'escreveu o estado à mão em vez de ler a constante',
            "'ajustes_solicitados'" => 'escreveu o estado à mão em vez de ler a constante',
            // ⚠️ As chaves INGLESAS do protótipo: aceitar as duas formas foi achado como defeito
            // GRAVE na verificação da Fase 186.
            "'awaiting'" => 'aceitou a chave INGLESA do protótipo como chave de dado',
            "'ecf_review'" => 'aceitou a chave INGLESA do protótipo como chave de dado',
            "'changes'" => 'aceitou a chave INGLESA do protótipo como chave de dado',
        ];

        $infratores = [];
        foreach ($proibidos as $literal => $porque) {
            if (str_contains($codigo, $literal)) {
                $infratores[] = "{$literal} — {$porque}";
            }
        }
        $this->assertSame([], $infratores, "O serviço criou uma segunda verdade:\n".implode("\n", $infratores));

        // ⚠️ CONTROLE POSITIVO da sonda: ela ACHA os mesmos literais quando eles existem. Sem
        // isto, "zero infratores" não distinguiria o código limpo de uma sonda cega.
        $sujo = $codigo."\n\$x = ['aguardando_aprovacao', 'awaiting']; \$y = collect()->sortBy('a');";
        $achados = array_values(array_filter(array_keys($proibidos), fn (string $l) => str_contains($sujo, $l)));
        $this->assertGreaterThanOrEqual(3, count($achados), 'CONTROLE: a sonda não acha nem os literais plantados — ela está cega.');

        // ⚠️ E a DELEGAÇÃO é positiva, não só a ausência: o serviço CHAMA quem ordena.
        $this->assertStringContainsString('ordenar(', $codigo, 'o serviço não delega a ordenação a `FilaDeAprovacaoService::ordenar()`.');
        $this->assertStringContainsString('->para(', $codigo, 'o serviço não chama `BloqueiosDaOfertaService::para()`.');
    }

    /**
     * ⚠️ **O que precisaria estar errado para falhar:** a "próxima" sair de OUTRA conta. O
     * recorte vem de `FilaDeAprovacaoService::paraAConta()`, e a asserção é nas DUAS direções:
     * a mesma oferta com a conta certa ENTRA, senão "não vazou" é indistinguível de "a consulta
     * não devolve nada".
     *
     * @group phase187
     */
    public function test_a_proxima_e_da_conta_e_nunca_de_outra_187_05(): void
    {
        $this->espiaoDeBloqueios();
        $minha = Company::factory()->create();
        $alheia = Company::factory()->create();

        // A da OUTRA conta espera MUITO mais: se o recorte faltasse, ela venceria.
        $daOutra = $this->oferta($alheia, PubAprovacaoOferta::AGUARDANDO_APROVACAO, ['entrou' => '2026-09-01 08:00:00']);
        $aMinha = $this->oferta($minha, PubAprovacaoOferta::AGUARDANDO_APROVACAO, ['entrou' => '2026-10-14 08:00:00']);

        $escolhida = $this->servico()->escolher($this->servico()->aoVivo($this->fila()->paraAConta($minha->id, null)));
        $this->assertSame($aMinha->id, $escolhida?->id, 'DADO-14: a faixa mostrou oferta de outra conta.');

        // ⚠️ A outra direção: na conta dela, ela É a próxima. Sem isto o caso passaria por
        // "a consulta não devolve nada".
        $daDela = $this->servico()->escolher($this->servico()->aoVivo($this->fila()->paraAConta($alheia->id, null)));
        $this->assertSame($daOutra->id, $daDela?->id, 'ISCA: a oferta da outra conta não aparece nem na conta dela.');
    }

    /**
     * ⚠️ **O que precisaria estar errado para falhar:** o payload inventar as chaves de
     * `BloqueiosDaOfertaService::para()`. O espião dos casos acima é uma SUBCLASSE, então ele
     * garante a ASSINATURA — mas não garante que `aprovavel` e `bloqueios` sejam as chaves REAIS
     * do serviço da Fase 186. Este caso exercita o `para()` DE VERDADE.
     *
     * ⚠️ E ele é, ao mesmo tempo, a prova de que o payload **não chama a API do Mercado Livre**
     * quando não há categoria: `ComposicaoFinanceiraService::tarifaDoMl()` devolve "sem fonte"
     * sem sair para a rede (`:462`-`:471`).
     *
     * @group phase187
     */
    public function test_a_integracao_real_com_bloqueios_entrega_aprovavel_e_bloqueios_187_05(): void
    {
        $empresa = Company::factory()->create();
        $oferta = $this->oferta($empresa, PubAprovacaoOferta::AGUARDANDO_APROVACAO);

        // O `para()` REAL — nada de espião aqui.
        $leitura = app(BloqueiosDaOfertaService::class)->para($oferta->fresh());
        $this->assertArrayHasKey('aprovavel', $leitura);
        $this->assertArrayHasKey('bloqueios', $leitura);
        $this->assertIsBool($leitura['aprovavel']);
        $this->assertIsArray($leitura['bloqueios']);

        $faixa = $this->servico()->para($this->fila()->paraAConta($empresa->id, null));
        $this->assertNotNull($faixa);
        $this->assertSame($leitura['aprovavel'], $faixa['aprovavel'], 'o payload divergiu do `aprovavel` do serviço real.');
        $this->assertSame(count($leitura['bloqueios']), $faixa['pendencias'], 'o payload divergiu da contagem de bloqueios real.');

        // ⚠️ A equivalência que fecha o caso do ZERO: para oferta AO VIVO,
        // `aprovavel = ao_vivo && bloqueios === []`.
        $this->assertSame($faixa['aprovavel'], $faixa['pendencias'] === 0, 'a equivalência aprovavel <-> zero bloqueios quebrou.');

        // ⚠️ ZERO REDE, e com ISCA: o predicado SABE acusar.
        $doMl = fn ($request) => str_contains($request->url(), 'mercadolibre.com');
        Http::assertNotSent($doMl);

        Http::get('https://api.mercadolibre.com/sites/MLB/listing_prices');
        $acusou = false;
        try {
            Http::assertNotSent($doMl);
        } catch (\PHPUnit\Framework\AssertionFailedError) {
            $acusou = true;
        }
        $this->assertTrue($acusou, 'ISCA: o predicado do ML não acusa nem uma chamada explícita — ele não mede nada.');
    }

    /**
     * ⚠️ **O que precisaria estar errado para falhar:** a modalidade virar "Clássico" por
     * default quando o `listing_type_id` não é nenhum dos dois (`free`, `silver`, `bronze`…).
     * Seria afirmar uma modalidade que o anúncio não tem — e o precedente do projeto é o
     * contrário (`CockpitDaContaService:458`: *"tipo nulo é listing desconhecido: não vira
     * 'Clássico' por…"*).
     *
     * ⚠️ E o mapa é LIDO: `EstruturaPublicacao::LISTING_TYPES` invertido + `EstruturaAnuncio::
     * TIPOS`, que é o que `FamiliaDeFasesService:530`-`:531` manda usar. Mapa novo seria uma
     * terceira cópia da mesma verdade.
     *
     * @group phase187
     */
    public function test_modalidade_desconhecida_sai_da_frase_em_vez_de_virar_classico_187_05(): void
    {
        $this->espiaoDeBloqueios();
        $empresa = Company::factory()->create();
        $this->oferta($empresa, PubAprovacaoOferta::AGUARDANDO_APROVACAO, ['tipo' => 'free']);

        $faixa = $this->servico()->para($this->fila()->paraAConta($empresa->id, null));

        $this->assertNotNull($faixa);
        $this->assertSame('Unidade · aguardando há 2 h', $faixa['sub']);
        $this->assertStringNotContainsString('Clássico', $faixa['sub'], 'listing desconhecido virou Clássico — modalidade inventada.');
        $this->assertStringNotContainsString('Premium', $faixa['sub']);

        // ⚠️ CONTROLE: os dois tipos CONHECIDOS saem nomeados. Sem isto, "não virou Clássico"
        // não distinguiria o recorte certo de um mapa que não devolve nada nunca.
        $nomes = [];
        foreach (EstruturaPublicacao::LISTING_TYPES as $tipo => $listing) {
            $outra = Company::factory()->create();
            $this->oferta($outra, PubAprovacaoOferta::AGUARDANDO_APROVACAO, ['tipo' => $listing]);
            $nomes[] = explode(' · ', (string) $this->servico()->para($this->fila()->paraAConta($outra->id, null))['sub'])[0];
            $this->assertArrayHasKey($tipo, EstruturaAnuncio::TIPOS);
        }
        $this->assertSame(['Clássico', 'Premium'], $nomes, 'CONTROLE: o mapa lido não nomeia as duas modalidades.');
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // (4) TERRITÓRIO E BIJEÇÃO
    // ═════════════════════════════════════════════════════════════════════════════════════════

    /**
     * ⚠️ **O que precisaria estar errado para falhar:** este plano tocar um arquivo que não é
     * dele — os 10 da Fase 186, o Creative Engine, o acervo que a v28 acabou de virar para o R2,
     * o `MlPublicacaoService`, o `App\Support\Publicador`, o `app/Mcp/`, os D-11/D-22, os 4
     * arquivos de dependência (T-187-SC) ou `database/migrations` (esta fase NÃO tem migration).
     *
     * ⚠️⚠️ **MEDE OS COMMITS DO PRÓPRIO ESCOPO, NÃO A ÁRVORE.** Range aberto com a isca
     * "ninguém mais toca aqui" vale UM plano, e o autor não discrimina nada nesta árvore.
     *
     * @group phase187
     */
    public function test_territorio_do_plano_187_05(): void
    {
        $permitidos = [
            'app/Services/Publicador/Aprovacao/Fila/ProximoARevisarService.php',
            'app/Http/Controllers/MlbPublicadorAprovacaoController.php',
            'resources/js/Components/Mlb/Publicador/aprovacao/ProximoARevisar.jsx',
            'resources/js/Pages/Mlb/Publicador/Aprovacao.jsx',
            'tests/Unit/Publicador/Aprovacao/ProximoARevisarServiceTest.php',
            'tests/js/publicador-fila-proximo-187.test.js',
            'tests/js/publicador-vocabulario-novo.test.js',
            'tests/gates/contagens-187-05.txt',
            '.planning/phases/187-a-fila-aguardando-aprovacao-e-a-aba-que-leva-a-ela/187-05-SUMMARY.md',
        ];

        $commits = $this->commitsDoEscopo();
        $arquivos = $this->arquivosDoEscopo();

        // ⚠️ ISCA 1 — o escopo existe. Gate sobre ZERO commits não prova nada.
        $this->assertNotSame([], $commits, 'ISCA 1: nenhum commit com escopo `(187-05)` no range — o filtro não casa nada.');
        $this->assertContains(
            'app/Services/Publicador/Aprovacao/Fila/ProximoARevisarService.php',
            $arquivos,
            'ISCA 2: o arquivo-âncora deste plano não aparece nos commits do escopo — o `git show` não lê nada.',
        );

        // ⚠️ ISCA 3 — o filtro DISCRIMINA: há commits no range que NÃO são deste plano.
        $todos = array_filter(explode("\n", $this->git('git log --no-merges --format=%H '.self::PARTIDA.'..HEAD')));
        $this->assertGreaterThanOrEqual(count($commits), count($todos));
        $this->assertSame(1, preg_match(self::ESCOPO, 'feat(187-05): a faixa PROXIMO A REVISAR'), 'ISCA 3a: a sonda não acha o escopo deste plano.');
        $this->assertSame(0, preg_match(self::ESCOPO, 'feat(187-04): a pagina da fila'), 'ISCA 3b: a sonda acusa a wave anterior.');
        $this->assertSame(0, preg_match(self::ESCOPO, 'feat(200): acervo no R2'), 'ISCA 3c: a sonda acusa commit de OUTRA sessão.');

        // (a) Nada FORA da lista permitida.
        $forads = array_values(array_diff($arquivos, $permitidos));
        $this->assertSame([], $forads, 'Este plano tocou arquivo que não é dele: '.implode(', ', $forads));

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
            'app/Services/Publicador/Aprovacao/Fila/FilaDeAprovacaoService.php',
            'resources/js/Components/Mlb/Publicador/aprovacao/andamentoDoPortao.jsx',
            'resources/js/Components/Mlb/Publicador/aprovacao/vocabularioDoPortao.jsx',
            'resources/js/Components/Mlb/Publicador/aprovacao/SeloDeAndamentoInterno.jsx',
            'resources/js/Components/Mlb/Publicador/aprovacao/CabecalhoDaFila.jsx',
            'resources/js/Components/Mlb/Publicador/aprovacao/FaixaDeAndamento.jsx',
            'resources/js/Components/Mlb/Publicador/publicacoes/AbasDaPagina.jsx',
            'resources/js/Pages/Mlb/MeusAnuncios.jsx',
            'resources/js/Components/Mlb/Publicador/header/PublicadorHeader.jsx',
            'resources/js/Layouts/AppLayout.jsx',
            'app/Services/Creative',
            'app/Services/Publicador/Criativos',
            'app/Services/Publicador/ImagemAssetService.php',
            'app/Services/Mlb/Publicacao/MlPublicacaoService.php',
            'app/Support/Publicador',
            'app/Mcp',
            'app/Services/Bling',
            'app/Services/Portal',
            'resources/js/Components/Publicador/Mesa',
            'resources/js/Pages/Mlb/Publicador/Editor.jsx',
            'resources/js/Pages/Mlb/AnunciarML.jsx',
            'tests/js/estrutura-meus-anuncios.test.js',
            'tests/js/publicador-header-zindex-ordem.test.js',
            'tests/js/publicador-fila-cabecalho-187.test.js',
            'tests/Feature/Publicador/Aprovacao/FilaDeAprovacaoRotaTest.php',
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
            // ⚠️ Caminho fantasma mede NADA e sai verde. Seis já aconteceram nesta milestone.
            $this->assertFileExists($this->raiz().'/'.$alvo, "gate sobre caminho fantasma: {$alvo}");
            foreach ($arquivos as $tocado) {
                if ($tocado === $alvo || str_starts_with($tocado, rtrim($alvo, '/').'/')) {
                    $violados[] = "{$alvo} <- {$tocado}";
                }
            }
        }
        $this->assertSame([], $violados, 'Território proibido tocado por este plano: '.implode('; ', $violados));

        // ⚠️ ISCA 4 — a comparação de prefixo SABE casar, e NÃO casa o irmão.
        $this->assertTrue(str_starts_with('app/Mcp/Servidor.php', 'app/Mcp/'), 'ISCA 4: a comparação de prefixo não casa nada.');
        $this->assertFalse(str_starts_with('app/McpOutraCoisa.php', 'app/Mcp/'), 'ISCA 4b: a comparação casa um IRMÃO.');
    }

    /**
     * ⚠️ **O que precisaria estar errado para falhar:** um método `_187_05` sem id no manifesto
     * (ele **NUNCA RODA** — foi o sobrevivente da mutação do plano 186-03) ou um id órfão (gate
     * sobre ZERO casos).
     *
     * ⚠️ O universo varrido é recortado pelo SUFIXO, não pelo arquivo: outra wave pode
     * acrescentar métodos `_187_NN` a este arquivo, e um gate que exigisse `_187_05` em TODOS
     * reprovaria trabalho CORRETO — é a família *"o gate é mais largo que o invariante"*, que
     * nesta milestone já reprovou trabalho certo OITO vezes.
     *
     * ⚠️ E o buraco que o recorte abriria está FECHADO logo abaixo: todo método deste arquivo
     * tem de ter sufixo `_187_NN` com manifesto no disco.
     *
     * ⚠️ A asserção é **UMA**, de contagem fixa, coletando os infratores — nunca uma por linha
     * do manifesto (a armadilha que caiu 4 vezes na Fase 186).
     *
     * @group phase187
     */
    public function test_bijecao_do_manifesto_187_05(): void
    {
        $manifestoRel = 'tests/gates/contagens-187-05.txt';
        $manifesto = $this->raiz().'/'.$manifestoRel;
        $this->assertFileExists($manifesto, "gate sobre caminho fantasma: {$manifestoRel}");

        $meuArquivo = 'tests/Unit/Publicador/Aprovacao/ProximoARevisarServiceTest.php';
        $abs = $this->raiz().'/'.$meuArquivo;
        $this->assertFileExists($abs);

        $fonte = (string) file_get_contents($abs);
        preg_match_all('/public function (test_\w+)\(/', $fonte, $m);

        $metodos = [];
        $todosOsMetodos = $m[1];
        foreach ($todosOsMetodos as $nome) {
            if (str_ends_with($nome, '_187_05')) {
                $metodos[$nome] = $meuArquivo;
            }
        }

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

        // ⚠️ ISCA — os dois lados têm conteúdo. Bijeção entre conjuntos vazios é verdadeira e
        // não prova nada.
        $this->assertNotSame([], $metodos, 'ISCA: nenhum método `_187_05` lido do arquivo deste plano.');
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

        // ⚠️ O BURACO DO RECORTE, fechado: todo método deste arquivo tem sufixo de plano com
        // manifesto no disco. Sem isto, um método `test_qualquer_coisa()` ficaria fora dos dois
        // gates e nunca rodaria em gate nenhum.
        foreach ($todosOsMetodos as $nome) {
            if (preg_match('/_(187_\d\d)$/', $nome, $qual) !== 1) {
                $infratores[] = "SEM SUFIXO DE PLANO: {$nome}";

                continue;
            }
            $doPlano = 'tests/gates/contagens-'.str_replace('_', '-', $qual[1]).'.txt';
            if (! is_file($this->raiz().'/'.$doPlano)) {
                $infratores[] = "SUFIXO SEM MANIFESTO: {$nome} (esperava {$doPlano})";
            }
        }

        $this->assertSame([], $infratores, "A bijeção ids <-> métodos quebrou:\n".implode("\n", $infratores));

        // ⚠️ ISCA DO SUFIXO — a sonda SABE acusar o que não tem sufixo, e aceita o que tem.
        $this->assertSame(0, preg_match('/_(187_\d\d)$/', 'test_sem_sufixo'), 'ISCA: a sonda do sufixo aceita método sem sufixo.');
        $this->assertSame(1, preg_match('/_(187_\d\d)$/', 'test_com_sufixo_187_05'), 'ISCA: a sonda do sufixo recusa o sufixo válido.');
    }
}
