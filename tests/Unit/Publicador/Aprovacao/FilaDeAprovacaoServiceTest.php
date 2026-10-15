<?php

namespace Tests\Unit\Publicador\Aprovacao;

use App\Models\Company;
use App\Models\EstruturaPrecificacaoParametros;
use App\Models\PubAprovacaoComposicao;
use App\Models\PubAprovacaoOferta;
use App\Models\PubProduto;
use App\Models\PubRascunho;
use App\Models\PubRascunhoAlvo;
use App\Services\Publicador\Aprovacao\AndamentoDaOfertaService;
use App\Services\Publicador\Aprovacao\Fila\FilaDeAprovacaoService;
use App\Support\Publicador\RegraViolada;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ═══════════════════════════════════════════════════════════════════════════════════════════
 * Fase 187, plano 02 — A LEITURA DA FILA: recorte da conta, contador da aba, faixa de 9 e ORD.
 *
 * ⚠️⚠️ DE CADA GATE DESTE ARQUIVO, A PERGUNTA RESPONDIDA ANTES DE ELE EXISTIR:
 * **"o que precisaria estar errado no mundo para ele falhar?"** — gate cuja resposta é "nada"
 * é decorativo e não entra (`feedback_gate_vazio_por_construcao`).
 *
 *   · **recorte sem conta** — alguém "melhorar" o serviço engolindo a `RegraViolada` de
 *     `filaDaConta()` e devolvendo a fila de **todas** as empresas. O schema abençoaria:
 *     `company_id` e `mlb_empresa_id` são **anuláveis por construção** (T-186-14). Quem não
 *     sabe de que conta fala não pode receber a fila.
 *   · **vazamento entre contas** — a fila de uma conta mostrando oferta de outra. Asserção nas
 *     **duas direções**: a mesma oferta com a conta certa **entra**, senão "não vazou" é
 *     indistinguível de "a consulta não devolve nada".
 *   · **o contador** — alguém escrever os três estados à mão em vez de ler
 *     `PubAprovacaoOferta::LIVE`. A cópia fica livre para divergir no dia em que um **4º**
 *     estado entrar em `LIVE`, e o número da aba passaria a mentir em silêncio. Por isso há
 *     um caso por estado **de fora** — os 5 — e não só a soma dos 3 de dentro.
 *   · **a faixa** — alguém trocar o rótulo "Publicado hoje" pelo do estado ("Publicado") ou
 *     contar o estado inteiro: a faixa **prometeria o histórico e entregaria o do dia**, ou o
 *     contrário. O que prova o recorte DO DIA é "Todas" ser MAIOR que a soma dos oito quando
 *     existe publicada de **outro dia**.
 *   · **a ordem** — alguém "arrumar" o empate de `aguardando_aprovacao` e `em_revisao_ecf` em
 *     1, ou inverter o desempate de espera: a fila mudaria de ordem e **nenhum** teste de
 *     render acusaria.
 *   · **o nulo da espera** — `entrou_na_fila_em` nulo indo para a FRENTE do degrau: oferta que
 *     acabou de nascer passaria na frente de todas. Espera **desconhecida** não é espera
 *     **infinita**.
 *   · **a margem mínima** — o 12% virar literal, ou o **zero desligar** o mínimo (FIN-09). O
 *     zero significa "a empresa não declarou", não "não exija margem".
 *
 * ⚠️ `Carbon::setTestNow()` em todo caso que dependa de "hoje": "Publicado hoje" é recorte do
 * DIA, e teste dependente de `now()` reprova por horário — medido neste projeto, um caso com
 * `subHours(9)` falhava entre 00:00 e 09:00 e passava depois.
 *
 * ⚠️ **ESTE ARQUIVO NÃO ALTERA NADA DA FASE 186.** `AndamentoDaOfertaService`,
 * `ComposicaoFinanceiraService` e `PubAprovacaoOferta` são **LIDOS**. O serviço desta fase mora
 * em `Aprovacao/Fila/`, subdiretório NOVO, para o gate de território medir um diretório que é
 * 100% desta fase.
 *
 * ⚠️ A fila **nasce vazia** e isso é honesto: medido em 15/10/2026, **nada** cria
 * `PubAprovacaoOferta` em produção — quem passa a criar é a **Fase 194**. Estes casos montam o
 * grafo real (produto → rascunho → alvo → oferta) à mão, porque é a única forma de medir hoje —
 * e **fixture não é fonte de tela**: nenhum número de exemplo chega ao payload.
 * ═══════════════════════════════════════════════════════════════════════════════════════════
 */
class FilaDeAprovacaoServiceTest extends TestCase
{
    use RefreshDatabase;

    /** Clássico e Premium — `EstruturaPublicacao::LISTING_TYPES`. */
    private const CLASSICO = 'gold_special';

    /** Contador de SKU: `alvo_id` é UNIQUE, então cada oferta precisa do seu alvo. */
    private int $sequencia = 0;

    protected function setUp(): void
    {
        parent::setUp();
        // ⚠️ "Publicado hoje" é recorte do DIA e "maior espera primeiro" só é mensurável com o
        // agora PARADO. Sem isto o caso da faixa reprova por horário de execução.
        Carbon::setTestNow('2026-10-15 09:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ─────────────────────────────────────────────────────────────────────────────────────────
    // Fixture: o grafo REAL — produto -> rascunho -> alvo -> oferta do portão.
    // ─────────────────────────────────────────────────────────────────────────────────────────

    private function oferta(Company $empresa, string $andamento, ?string $entrouEm = null): PubAprovacaoOferta
    {
        $this->sequencia++;
        $sku = 'FILA-' . $this->sequencia;

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

    private function servico(): FilaDeAprovacaoService
    {
        return app(FilaDeAprovacaoService::class);
    }

    /** As 8 chaves de andamento, lidas do modelo — nunca escritas à mão aqui. */
    private function osOitoEstados(): array
    {
        return array_keys(PubAprovacaoOferta::ANDAMENTOS);
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // (1) O RECORTE DA CONTA — a trava T-186-14 propagada, não engolida
    // ═════════════════════════════════════════════════════════════════════════════════════════

    /**
     * ⚠️ **O que precisaria estar errado para falhar:** o serviço da fila engolir a
     * `RegraViolada` de `filaDaConta()` (um `try`/`catch` "defensivo", um `?? PubAprovacaoOferta
     * ::query()`) e devolver a fila **de todas as empresas** quando ninguém disse de que conta
     * se fala. É o vazamento que o schema abençoaria.
     *
     * ⚠️ Os dois métodos que recebem âncora são testados, porque engolir em UM deles basta.
     *
     * @group phase187
     */
    public function test_fila_sem_conta_propaga_a_regra_violada_187_02(): void
    {
        $servico = $this->servico();

        $lancou = [];

        try {
            $servico->paraAConta(null, null);
        } catch (RegraViolada $e) {
            $lancou['paraAConta'] = $e->regra;
        }

        try {
            $servico->contadorDaAba(null, null);
        } catch (RegraViolada $e) {
            $lancou['contadorDaAba'] = $e->regra;
        }

        $this->assertSame(
            [
                'paraAConta' => AndamentoDaOfertaService::REGRA_FILA_SEM_CONTA,
                'contadorDaAba' => AndamentoDaOfertaService::REGRA_FILA_SEM_CONTA,
            ],
            $lancou,
            'T-186-14: a fila foi lida SEM recorte de conta — ou a RegraViolada foi engolida.',
        );

        // ⚠️ ISCA — a recusa tem de ser da AUSÊNCIA de âncora, não de tudo. Com âncora, os dois
        // métodos funcionam. Sem esta isca, um serviço que lançasse SEMPRE passaria acima.
        $empresa = Company::factory()->create();
        $this->assertInstanceOf(Builder::class, $servico->paraAConta($empresa->id, null));
        $this->assertSame(0, $servico->contadorDaAba($empresa->id, null));
        // E a segunda âncora sozinha também basta: `mlb_empresa_id` sem `company_id`.
        $this->assertInstanceOf(Builder::class, $servico->paraAConta(null, 777));
        $this->assertSame(0, $servico->contadorDaAba(null, 777));
    }

    /**
     * ⚠️ **O que precisaria estar errado para falhar:** a fila de uma conta mostrar oferta de
     * outra — o `where('company_id')` sumir do caminho, ou o serviço reimplementar o recorte com
     * um `orWhere` desagrupado (defeito REAL deste projeto, D-02/T-134-01 da Fase 134).
     *
     * ⚠️ As DUAS direções: a mesma oferta com a conta certa **entra**. "Não vazou" sozinho é
     * indistinguível de "a consulta não devolve nada".
     *
     * @group phase187
     */
    public function test_fila_nao_mostra_oferta_de_outra_conta_187_02(): void
    {
        $minha = Company::factory()->create();
        $outra = Company::factory()->create();

        $daMinha = $this->oferta($minha, PubAprovacaoOferta::AGUARDANDO_APROVACAO, '2026-10-14 08:00:00');
        $daOutra = $this->oferta($outra, PubAprovacaoOferta::AGUARDANDO_APROVACAO, '2026-10-14 08:00:00');

        $ids = $this->servico()->paraAConta($minha->id, null)->pluck('id')->all();

        $this->assertSame([$daMinha->id], $ids, 'A fila da conta trouxe oferta que não é dela (ou não trouxe a dela).');

        // ⚠️ ISCA — a oferta da OUTRA conta existe e é alcançável pelo recorte DELA. Sem isto,
        // o `assertSame` acima passaria com um `whereRaw('1 = 0')` no lugar do recorte.
        $this->assertSame([$daOutra->id], $this->servico()->paraAConta($outra->id, null)->pluck('id')->all());
        $this->assertSame(2, PubAprovacaoOferta::count(), 'ISCA: as duas ofertas têm de existir no banco.');
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // (2) O CONTADOR DA ABA — exatamente `LIVE`, lido do modelo
    // ═════════════════════════════════════════════════════════════════════════════════════════

    /**
     * ⚠️ **O que precisaria estar errado para falhar:** o contador da aba contar um estado que
     * **não** está `LIVE` (e a aba prometer trabalho que não existe) ou deixar um de fora (e a
     * aba esconder trabalho que existe). A régua é `PubAprovacaoOferta::LIVE`, e há um caso por
     * cada um dos **8** estados — os 3 de dentro e os 5 de fora.
     *
     * ⚠️ A lista de estados é LIDA de `ANDAMENTOS`; escrevê-la aqui faria o teste e o modelo
     * divergirem exatamente como o código que ele guarda.
     *
     * @group phase187
     */
    public function test_contador_da_aba_conta_exatamente_os_tres_live_187_02(): void
    {
        $servico = $this->servico();

        $porEstado = [];
        foreach ($this->osOitoEstados() as $estado) {
            // Uma CONTA por estado: assim o contador de cada caso mede só aquele estado.
            $conta = Company::factory()->create();
            $this->oferta($conta, $estado, '2026-10-14 08:00:00');
            $porEstado[$estado] = $servico->contadorDaAba($conta->id, null);
        }

        $esperado = [];
        foreach ($this->osOitoEstados() as $estado) {
            $esperado[$estado] = in_array($estado, PubAprovacaoOferta::LIVE, true) ? 1 : 0;
        }

        $this->assertSame($esperado, $porEstado, 'AND-05: o contador da aba não é exatamente `LIVE`.');

        // ⚠️ ISCA DE FORMA — os 8 estados foram de fato medidos, e `LIVE` tem de ter 3 deles.
        // Sem isto, `$esperado === $porEstado` com os dois vazios passaria.
        $this->assertCount(8, $porEstado, 'ISCA: nem os 8 estados foram medidos.');
        $this->assertCount(3, PubAprovacaoOferta::LIVE, 'ISCA: `LIVE` deixou de ter 3 estados — se isso é intencional, o contador entra sozinho e este número sobe.');
        $this->assertSame(3, array_sum($porEstado), 'ISCA: a soma dos 8 casos tem de ser 3 — um por estado LIVE.');

        // E numa conta SÓ, com uma oferta em cada um dos 8 estados, o contador é 3.
        $umaConta = Company::factory()->create();
        foreach ($this->osOitoEstados() as $estado) {
            $this->oferta($umaConta, $estado, '2026-10-14 08:00:00');
        }
        $this->assertSame(3, $servico->contadorDaAba($umaConta->id, null));
        $this->assertSame(8, $servico->paraAConta($umaConta->id, null)->count(), 'ISCA: as 8 ofertas da conta existem — o 3 acima é recorte, não escassez.');
    }

    /**
     * ⚠️ **O que precisaria estar errado para falhar:** a fila nascer vazia e alguém "resolver"
     * isso com dado de exemplo. O usuário decidiu que **a fila existe com número honesto**: `0`
     * real não é enfeite, e a aba **não se esconde** quando for zero.
     *
     * ⚠️ `0` é **int**, nunca `null` nem string vazia — "nunca medimos" ≠ "medimos e é zero"
     * (EXMP-01), e aqui medimos: é zero.
     *
     * @group phase187
     */
    public function test_contador_da_aba_e_zero_inteiro_em_conta_sem_oferta_187_02(): void
    {
        $vazia = Company::factory()->create();

        $contador = $this->servico()->contadorDaAba($vazia->id, null);

        $this->assertSame(0, $contador);
        $this->assertIsInt($contador, 'ABA-05: o contador tem de ser número — `null` faria a aba desenhar vazio.');

        // ⚠️ ISCA — e com UMA oferta LIVE o mesmo caminho devolve 1. Sem isto, um método que
        // devolvesse `0` sempre passaria.
        $this->oferta($vazia, PubAprovacaoOferta::EM_REVISAO_ECF, '2026-10-14 08:00:00');
        $this->assertSame(1, $this->servico()->contadorDaAba($vazia->id, null));
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // (3) A FAIXA DE 9 — e a prova de que o último recorte é DO DIA
    // ═════════════════════════════════════════════════════════════════════════════════════════

    /**
     * ⚠️ **O que precisaria estar errado para falhar:** a faixa perder uma entrada, mudar de
     * ordem (a conferência FID-01 compararia pares trocados), ou o último recorte deixar de ser
     * **do dia** — trocando o rótulo para "Publicado" ou contando o estado inteiro.
     *
     * ⚠️ O que PROVA o recorte do dia não é o rótulo: é **"Todas" ser MAIOR que a soma dos
     * oito** quando existe publicada de OUTRO dia. Asserção só no rótulo seria satisfeita por
     * uma faixa que contasse o estado inteiro com o nome certo.
     *
     * @group phase187
     */
    public function test_faixa_tem_nove_entradas_na_ordem_de_st_e_publicado_e_do_dia_187_02(): void
    {
        $conta = Company::factory()->create();

        // Uma em cada um dos 8 estados — a publicada é de HOJE.
        foreach ($this->osOitoEstados() as $estado) {
            $this->oferta($conta, $estado, '2026-10-14 08:00:00');
        }

        // E uma publicada de ONTEM: ela conta em "Todas" e NÃO em "Publicado hoje".
        $deOntem = $this->oferta($conta, PubAprovacaoOferta::PUBLICADO, '2026-10-10 08:00:00');
        PubAprovacaoOferta::whereKey($deOntem->id)->update(['updated_at' => '2026-10-14 10:00:00']);

        $faixa = $this->servico()->faixa($this->servico()->paraAConta($conta->id, null));

        $this->assertSame(
            [
                ['chave' => 'todas', 'rotulo' => 'Todas'],
                ['chave' => PubAprovacaoOferta::PREPARANDO, 'rotulo' => 'Preparando'],
                ['chave' => PubAprovacaoOferta::AGUARDANDO_APROVACAO, 'rotulo' => 'Aguardando aprovação'],
                ['chave' => PubAprovacaoOferta::EM_REVISAO_ECF, 'rotulo' => 'Em revisão pela ECF'],
                ['chave' => PubAprovacaoOferta::AJUSTES_SOLICITADOS, 'rotulo' => 'Ajustes solicitados'],
                ['chave' => PubAprovacaoOferta::APROVADO, 'rotulo' => 'Aprovado'],
                ['chave' => PubAprovacaoOferta::PUBLICANDO, 'rotulo' => 'Publicando'],
                ['chave' => PubAprovacaoOferta::FALHA, 'rotulo' => 'Falha na publicação'],
                ['chave' => PubAprovacaoOferta::PUBLICADO, 'rotulo' => 'Publicado hoje'],
            ],
            array_map(fn (array $e) => ['chave' => $e['chave'], 'rotulo' => $e['rotulo']], $faixa),
            'AND-09: a faixa de 9 mudou de ordem, de chave ou de rótulo.',
        );

        $totais = array_column($faixa, 'total', 'chave');

        $this->assertSame(9, $totais['todas'], '"Todas" é SEM FILTRO: as 9 ofertas da conta.');
        $this->assertSame(1, $totais[PubAprovacaoOferta::PUBLICADO], '"Publicado hoje" contou a publicada de OUTRO dia — o recorte deixou de ser do DIA.');

        $somaDosOito = 0;
        foreach ($this->osOitoEstados() as $estado) {
            $somaDosOito += $totais[$estado];
        }

        $this->assertSame(8, $somaDosOito);
        $this->assertGreaterThan(
            $somaDosOito,
            $totais['todas'],
            'É ESTA desigualdade que prova o recorte DO DIA: com publicada de outro dia, "Todas" > soma dos oito.',
        );

        // ⚠️ ISCA — sem publicada de outro dia a desigualdade VIRA igualdade. É o controle que
        // prova que a desigualdade acima mede a publicada de ontem, e não um erro de contagem.
        $semOntem = Company::factory()->create();
        foreach ($this->osOitoEstados() as $estado) {
            $this->oferta($semOntem, $estado, '2026-10-14 08:00:00');
        }
        $outra = $this->servico()->faixa($this->servico()->paraAConta($semOntem->id, null));
        $totaisOutra = array_column($outra, 'total', 'chave');
        $somaOutra = 0;
        foreach ($this->osOitoEstados() as $estado) {
            $somaOutra += $totaisOutra[$estado];
        }
        $this->assertSame($totaisOutra['todas'], $somaOutra, 'ISCA: sem publicada de outro dia, "Todas" tem de IGUALAR a soma dos oito.');
    }

    /**
     * ⚠️ **O que precisaria estar errado para falhar:** a faixa contar a conta INTEIRA em vez do
     * recorte que recebeu — e os cards dirão quantas existem na conta, não quantas existem
     * **dentro do recorte atual** (AND-09: *"calculadas sobre os DEMAIS filtros"*).
     *
     * @group phase187
     */
    public function test_faixa_conta_sobre_o_recorte_que_recebe_187_02(): void
    {
        $minha = Company::factory()->create();
        $outra = Company::factory()->create();

        $this->oferta($minha, PubAprovacaoOferta::AGUARDANDO_APROVACAO, '2026-10-14 08:00:00');
        $this->oferta($outra, PubAprovacaoOferta::AGUARDANDO_APROVACAO, '2026-10-14 08:00:00');
        $this->oferta($outra, PubAprovacaoOferta::AGUARDANDO_APROVACAO, '2026-10-14 09:00:00');

        $totais = array_column($this->servico()->faixa($this->servico()->paraAConta($minha->id, null)), 'total', 'chave');

        $this->assertSame(1, $totais['todas']);
        $this->assertSame(1, $totais[PubAprovacaoOferta::AGUARDANDO_APROVACAO]);

        // ⚠️ ISCA — as 3 ofertas existem; o 1 acima é recorte, não banco vazio.
        $this->assertSame(3, PubAprovacaoOferta::count());
        $totaisOutra = array_column($this->servico()->faixa($this->servico()->paraAConta($outra->id, null)), 'total', 'chave');
        $this->assertSame(2, $totaisOutra['todas'], 'ISCA: o recorte da outra conta tem de ver as 2 dela.');
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // (4) A ORDEM — a literal de `ORD`, com os DOIS desempates
    // ═════════════════════════════════════════════════════════════════════════════════════════

    /**
     * ⚠️ **O que precisaria estar errado para falhar:** alguém "arrumar" o empate de
     * `aguardando_aprovacao` e `em_revisao_ecf` em 1, ou ordenar por `id`/`created_at` em vez de
     * `ORDEM`. A fila mudaria de ordem e **nenhum** teste de render acusaria.
     *
     * ⚠️ A asserção é sobre a sequência de `ordem()`, **não** sobre a sequência de chaves: as
     * duas do degrau 1 empatam de propósito, e exigir uma delas antes da outra cravaria no teste
     * uma ordem que o desenho NÃO prescreve.
     *
     * @group phase187
     */
    public function test_ordem_da_fila_e_a_literal_de_ord_187_02(): void
    {
        $conta = Company::factory()->create();

        // Em ordem EMBARALHADA de propósito — se a ordenação não fizer nada, a sequência sai
        // nesta ordem e a asserção reprova.
        $embaralhado = [
            PubAprovacaoOferta::PREPARANDO,
            PubAprovacaoOferta::PUBLICADO,
            PubAprovacaoOferta::AGUARDANDO_APROVACAO,
            PubAprovacaoOferta::FALHA,
            PubAprovacaoOferta::PUBLICANDO,
            PubAprovacaoOferta::AJUSTES_SOLICITADOS,
            PubAprovacaoOferta::APROVADO,
            PubAprovacaoOferta::EM_REVISAO_ECF,
        ];
        $this->assertSame(
            [],
            array_diff($embaralhado, $this->osOitoEstados()),
            'ISCA: a lista embaralhada tem de ser exatamente os 8 estados do modelo.',
        );

        foreach ($embaralhado as $i => $estado) {
            // Esperas DISTINTAS para o degrau 1 não empatar por completo.
            $this->oferta($conta, $estado, Carbon::parse('2026-10-14 08:00:00')->addMinutes($i)->toDateTimeString());
        }

        $fila = $this->servico()->ordenar($this->servico()->paraAConta($conta->id, null)->get());

        $degraus = $fila->map(fn (PubAprovacaoOferta $o) => $o->ordem())->all();

        $this->assertSame([0, 1, 1, 2, 3, 4, 5, 6], $degraus, 'AND-08: a sequência de degraus não é a de `ORDEM`.');
        $this->assertSame(PubAprovacaoOferta::FALHA, $fila->first()->andamento, '`falha` é o degrau 0 — primeiro da fila.');
        $this->assertSame(PubAprovacaoOferta::PREPARANDO, $fila->last()->andamento, '`preparando` é o degrau 6 — não há nada a fazer nele.');

        // ⚠️ ISCA — a sequência EMBARALHADA de entrada tem degraus FORA de ordem. Sem isto, um
        // `ordenar()` que devolvesse a coleção intacta poderia passar por coincidência.
        $semOrdenar = $this->servico()->paraAConta($conta->id, null)->get()
            ->map(fn (PubAprovacaoOferta $o) => $o->ordem())->all();
        $this->assertNotSame($degraus, $semOrdenar, 'ISCA: a entrada já estava ordenada — este caso não prova a ordenação.');
    }

    /**
     * ⚠️ **O que precisaria estar errado para falhar:** o desempate de espera invertido (a oferta
     * que acabou de entrar na frente da que espera há mais tempo) ou a espera atravessando
     * degraus. "Maior espera primeiro" é FILA-13, e é a base do âmbar de 24 h.
     *
     * @group phase187
     */
    public function test_dentro_do_degrau_a_maior_espera_vem_primeiro_187_02(): void
    {
        $conta = Company::factory()->create();

        $nova = $this->oferta($conta, PubAprovacaoOferta::AGUARDANDO_APROVACAO, '2026-10-15 08:00:00');
        $antiga = $this->oferta($conta, PubAprovacaoOferta::AGUARDANDO_APROVACAO, '2026-10-01 08:00:00');
        $doMeio = $this->oferta($conta, PubAprovacaoOferta::AGUARDANDO_APROVACAO, '2026-10-10 08:00:00');

        $ids = $this->servico()->ordenar($this->servico()->paraAConta($conta->id, null)->get())
            ->pluck('id')->all();

        $this->assertSame([$antiga->id, $doMeio->id, $nova->id], $ids, 'FILA-13: maior espera primeiro, dentro do degrau.');

        // ⚠️ ISCA — a ordem de criação é OUTRA (nova, antiga, meio). Sem esta isca, uma
        // ordenação por `id` passaria o `assertSame` acima por acidente.
        $this->assertNotSame([$nova->id, $antiga->id, $doMeio->id], $ids, 'ISCA: a ordem saiu igual à de criação — não houve desempate por espera.');

        // ⚠️ E a espera NUNCA atravessa degraus: `preparando` desde o mês passado continua por
        // último, porque não há nada a fazer nela.
        $preparandoVelha = $this->oferta($conta, PubAprovacaoOferta::PREPARANDO, '2026-09-01 08:00:00');
        $comPreparando = $this->servico()->ordenar($this->servico()->paraAConta($conta->id, null)->get())
            ->pluck('id')->all();
        $this->assertSame($preparandoVelha->id, end($comPreparando), 'A espera atravessou o degrau — `preparando` foi para a frente.');
    }

    /**
     * ⚠️ **O que precisaria estar errado para falhar:** `entrou_na_fila_em` nulo indo para a
     * FRENTE do degrau. Em PHP, `null <=> timestamp` devolve `-1` — então a ordenação ingênua
     * põe a oferta que **acabou de nascer** na frente de todas que esperam há dias. Espera
     * **desconhecida** não é espera **infinita**.
     *
     * @group phase187
     */
    public function test_entrou_na_fila_em_nulo_vai_para_o_fim_do_degrau_187_02(): void
    {
        $conta = Company::factory()->create();

        $semEspera = $this->oferta($conta, PubAprovacaoOferta::AGUARDANDO_APROVACAO, null);
        $antiga = $this->oferta($conta, PubAprovacaoOferta::AGUARDANDO_APROVACAO, '2026-10-01 08:00:00');
        $recente = $this->oferta($conta, PubAprovacaoOferta::AGUARDANDO_APROVACAO, '2026-10-14 08:00:00');

        $ids = $this->servico()->ordenar($this->servico()->paraAConta($conta->id, null)->get())
            ->pluck('id')->all();

        $this->assertSame([$antiga->id, $recente->id, $semEspera->id], $ids, 'O nulo foi para a FRENTE do degrau.');
        $this->assertNull($semEspera->fresh()->entrou_na_fila_em, 'ISCA: a oferta sem espera precisa estar de fato NULA no banco.');

        // ⚠️ ISCA — com DOIS nulos no mesmo degrau o desempate é por `id`, para a ordem ser
        // estável entre duas chamadas. Sem isto, a ordem dos nulos seria indefinida.
        $outroNulo = $this->oferta($conta, PubAprovacaoOferta::AGUARDANDO_APROVACAO, null);
        $comDoisNulos = $this->servico()->ordenar($this->servico()->paraAConta($conta->id, null)->get())
            ->pluck('id')->all();
        $this->assertSame(
            [$antiga->id, $recente->id, $semEspera->id, $outroNulo->id],
            $comDoisNulos,
            'Dois nulos no mesmo degrau: desempate estável por `id`, e os dois no FIM.',
        );
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // (5) A MARGEM MÍNIMA — o resolvedor, e o zero que NÃO desliga
    // ═════════════════════════════════════════════════════════════════════════════════════════

    /**
     * ⚠️ **O que precisaria estar errado para falhar:** o 12% virar literal na fila, ou o **zero
     * do parâmetro desligar** o mínimo (FIN-09). O zero significa *"a empresa não declarou"*, não
     * *"não exija margem"* — e desligar liberaria aprovação de oferta sem margem, num fluxo em
     * que **aprovar já publica e não há desfazer**.
     *
     * ⚠️ A `origem` viaja junto porque **cada número diz de onde vem**: sem ela a auditoria-depois
     * não reconstrói a decisão, porque a config pode ter mudado.
     *
     * @group phase187
     */
    public function test_margem_minima_vem_do_resolvedor_e_o_zero_nao_desliga_187_02(): void
    {
        $semParametro = Company::factory()->create();

        $daConfig = $this->servico()->margemMinima($semParametro->id);

        $this->assertSame(
            ['valor', 'origem'],
            array_keys($daConfig),
            'FIN-09: a margem mínima viaja com `valor` E `origem`.',
        );
        $this->assertSame((float) config('publicador.aprovacao.margem_minima_percentual'), $daConfig['valor']);
        $this->assertSame(PubAprovacaoComposicao::MINIMO_CONFIG, $daConfig['origem']);

        // ⚠️ O ZERO NÃO DESLIGA: parâmetro gravado em 0 cai na config, com origem de config.
        $comZero = Company::factory()->create();
        EstruturaPrecificacaoParametros::create(['company_id' => $comZero->id, 'margem_contribuicao' => 0.0]);
        $doZero = $this->servico()->margemMinima($comZero->id);
        $this->assertSame((float) config('publicador.aprovacao.margem_minima_percentual'), $doZero['valor'], 'O zero do parâmetro DESLIGOU o mínimo.');
        $this->assertSame(PubAprovacaoComposicao::MINIMO_CONFIG, $doZero['origem']);

        // ⚠️ ISCA — parâmetro MAIOR que zero manda, e a origem muda. Sem isto, um método que
        // devolvesse sempre a config passaria em tudo acima.
        $comParametro = Company::factory()->create();
        EstruturaPrecificacaoParametros::create(['company_id' => $comParametro->id, 'margem_contribuicao' => 15.0]);
        $doParametro = $this->servico()->margemMinima($comParametro->id);
        $this->assertSame(15.0, $doParametro['valor'], 'ISCA: o parâmetro da empresa não mandou.');
        $this->assertSame(PubAprovacaoComposicao::MINIMO_PARAMETRO, $doParametro['origem']);
        $this->assertNotSame($daConfig['origem'], $doParametro['origem'], 'ISCA: a origem não discrimina nada — os dois caminhos devolvem a mesma.');
    }
}
