<?php

namespace Tests\Feature\Publicador\Aprovacao;

use App\Models\Company;
use App\Models\PubAprovacaoAjuste;
use App\Models\PubAprovacaoConjunto;
use App\Models\PubAprovacaoEvento;
use App\Models\PubAprovacaoOferta;
use App\Models\PubProduto;
use App\Models\PubRascunho;
use App\Models\PubRascunhoAlvo;
use App\Models\User;
use App\Services\Publicador\Aprovacao\AjustesDaOfertaService;
use App\Services\Publicador\Aprovacao\AndamentoDaOfertaService;
use App\Services\Publicador\Aprovacao\HistoricoDaOfertaService;
use App\Support\Publicador\RegraViolada;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 * Fase 186, plano 05 — O **AJUSTE COM ESCOPO** MOVENDO O CONJUNTO CERTO DE OFERTAS, o histórico
 * da ida e da volta, e a disciplina da migration que cria as três tabelas.
 *
 * ⚠️⚠️ DE CADA GATE DESTE ARQUIVO, A PERGUNTA RESPONDIDA ANTES DE ELE EXISTIR:
 * **"o que precisaria estar errado no mundo para ele falhar?"**
 *
 *   · **AND-07, os 3 escopos** — um ajuste de escopo `set` **deixar de alcançar a oferta irmã**.
 *     O conjunto de fotos é compartilhado entre Clássico e Premium; se o pedido de ajuste movesse
 *     só o Clássico, **o Premium seguiria aprovável e iria ao ar com a foto reprovada** — e
 *     **aprovar já publica, sem desfazer** (decisão do usuário de 14/10/2026). ⚠️ Toda negativa
 *     aqui roda com **a oferta irmã PREENCHIDA** (GATE-13).
 *   · **AND-07, a volta** — resolver UM de DOIS ajustes abertos devolver a oferta para a fila. Ela
 *     voltaria a ser aprovável com um ajuste ainda em aberto.
 *   · **MOD-01** — ajuste com texto vazio. Ele contaria como bloqueio e ninguém saberia o que
 *     fazer para resolvê-lo; a oferta travaria para sempre.
 *   · **a única porta** — um `update(['andamento' => …])` direto neste serviço. Escrita de estado
 *     espalhada é elevação de privilégio aqui (T-186-11).
 *   · **T-186-20** — a consulta dos 3 escopos alcançar ajuste de OUTRA empresa. ⚠️ A outra
 *     empresa está **PREENCHIDA** no fixture.
 *   · **a migration** — 1059 (nome acima de 64), 1830 (`nullOnDelete` sem `nullable`), `enum` (o
 *     CHECK é enforçado no SQLite e cega a suíte) e `try/catch` em volta de DDL. Os três primeiros
 *     o SQLite **não pega**, e o deploy quebra com a tabela JÁ criada.
 *   · **MIG-09 / T-186-18** — alguém acrescentar rotina de limpeza no histórico. "Auditoria
 *     depois" deixaria de ser verdade sem nenhum teste acusar.
 *
 * ⚠️ O gate de diff usa **RANGE** (`<partida>..HEAD`): sem range, o diff por caminho depois de
 * commitar compara a ÁRVORE e sai **vazio sempre** (`feedback_gate_vazio_por_construcao`).
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 */
class AjustesEHistoricoTest extends TestCase
{
    use RefreshDatabase;

    private const CLASSICO = 'gold_special';

    private const PREMIUM = 'gold_pro';

    /** A migration deste plano. */
    private const MIGRATION = 'database/migrations/2026_10_15_100300_create_pub_aprovacao_ajustes_tables.php';

    /**
     * ⚠️ ISCA DE CAMINHO. 5 caminhos fantasma em 4 fases deste projeto: migration que não existe
     * faria TODA sonda de texto devolver "nenhuma violação" e o gate ficaria verde medindo NADA.
     */
    private const MIGRATION_FANTASMA = 'database/migrations/2026_10_15_999999_esta_migration_nao_existe.php';

    /** O commit de PARTIDA deste plano (wave 3), medido na PRIMEIRA ação do executor. */
    private const PARTIDA = '1c16d9e9';

    /** O serviço de andamento do plano 186-04 — **ligado** por este plano, não reescrito. */
    private const ANDAMENTO = 'app/Services/Publicador/Aprovacao/AndamentoDaOfertaService.php';

    /** As 3 tabelas deste plano. */
    private const TABELAS = ['pub_aprovacao_ajustes', 'pub_aprovacao_eventos', 'pub_aprovacao_excecoes'];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-15 14:30:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ─────────────────────────────────────────────────────────────────────────────────────────
    // Fixture: produto -> rascunho -> 2 alvos -> 2 ofertas + 1 conjunto de fotos compartilhado.
    // ─────────────────────────────────────────────────────────────────────────────────────────

    /**
     * @return array{ofertas: array{0: PubAprovacaoOferta, 1: PubAprovacaoOferta}, conjunto: PubAprovacaoConjunto, produto: PubProduto}
     */
    private function cenario(?Company $empresa = null, string $sku = 'CAD-01'): array
    {
        $empresa ??= Company::factory()->create();

        $produto = PubProduto::create([
            'company_id' => $empresa->id,
            'sku' => $sku,
            'nome' => 'Cadeira ' . $sku,
            'origem' => PubProduto::ORIGEM_PUBLICADOR,
        ]);

        $rascunho = PubRascunho::create(['produto_id' => $produto->id]);

        $conjunto = PubAprovacaoConjunto::create([
            'rascunho_id' => $rascunho->id,
            'grupo_chave' => PubAprovacaoConjunto::GERAL,
            'grupo_hash' => hash('sha256', $sku . '-GENERAL'),
        ]);

        $ofertas = [];
        foreach ([self::CLASSICO, self::PREMIUM] as $i => $tipo) {
            $alvo = PubRascunhoAlvo::create(['rascunho_id' => $rascunho->id, 'listing_type_id' => $tipo, 'posicao' => $i]);

            $ofertas[] = PubAprovacaoOferta::create([
                'alvo_id' => $alvo->id,
                'rascunho_id' => $rascunho->id,
                'produto_id' => $produto->id,
                'company_id' => $empresa->id,
                'andamento' => PubAprovacaoOferta::AGUARDANDO_APROVACAO,
            ]);
        }

        return ['ofertas' => $ofertas, 'conjunto' => $conjunto, 'produto' => $produto];
    }

    private function servico(): AjustesDaOfertaService
    {
        return app(AjustesDaOfertaService::class);
    }

    private function alguem(string $nome = 'Bruno Lima'): User
    {
        return User::factory()->create(['name' => $nome]);
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // AND-07 — OS 3 ESCOPOS MOVEM O CONJUNTO CERTO DE OFERTAS
    // ═════════════════════════════════════════════════════════════════════════════════════════

    public function test_pedir_ajuste_de_escopo_offer_move_so_aquela_oferta(): void
    {
        ['ofertas' => [$classico, $premium]] = $this->cenario();
        $quem = $this->alguem();

        // ⚠️ ISCA (GATE-13): a oferta IRMÃ existe e está no MESMO andamento. Sem ela, "só uma
        // moveu" e "nenhuma moveu" dariam o mesmo resultado.
        $this->assertSame(PubAprovacaoOferta::AGUARDANDO_APROVACAO, $premium->andamento);

        $ajuste = $this->servico()->pedir($classico, $quem, PubAprovacaoAjuste::ESCOPO_OFERTA, 'Título', 'Incluir a medida (50 cm) e o material no título.');

        $this->assertSame(PubAprovacaoAjuste::ESCOPO_OFERTA, $ajuste->escopo);
        $this->assertSame($classico->id, $ajuste->oferta_id);
        $this->assertNull($ajuste->conjunto_id);
        $this->assertNull($ajuste->produto_id);
        $this->assertTrue($ajuste->ancoraValida());

        // VOC-06 — o destino é legível e nomeia a modalidade.
        $this->assertSame('Este anúncio (Clássico)', $ajuste->destino);

        $this->assertSame(PubAprovacaoOferta::AJUSTES_SOLICITADOS, $classico->fresh()->andamento);
        $this->assertSame(PubAprovacaoOferta::AGUARDANDO_APROVACAO, $premium->fresh()->andamento, 'escopo `offer` moveu a oferta IRMÃ — o ajuste é só deste anúncio');
    }

    public function test_pedir_ajuste_de_escopo_set_move_todas_as_ofertas_do_conjunto(): void
    {
        ['ofertas' => [$classico, $premium], 'conjunto' => $conjunto] = $this->cenario();
        $quem = $this->alguem();

        // ⚠️ ISCA (GATE-13): uma oferta de OUTRO produto, na MESMA empresa e no MESMO andamento.
        // Sem ela, "moveu as duas do conjunto" e "moveu todas do banco" dariam o mesmo resultado.
        $empresa = Company::findOrFail($classico->company_id);
        ['ofertas' => [$deOutroProduto]] = $this->cenario($empresa, 'MESA-02');

        $ajuste = $this->servico()->pedir($classico, $quem, PubAprovacaoAjuste::ESCOPO_CONJUNTO, 'Imagem #3 · Ambientada · sala de estar', 'A IA alterou os pés da poltrona.');

        $this->assertSame($conjunto->id, $ajuste->conjunto_id);
        $this->assertNull($ajuste->oferta_id);
        $this->assertTrue($ajuste->ancoraValida());
        $this->assertSame('Conjunto de fotos (Clássico e Premium)', $ajuste->destino);

        // ⚠️⚠️ AS DUAS OFERTAS DO CONJUNTO. Se o Premium não viesse, ele seguiria aprovável e
        // **iria ao ar com a foto reprovada** — e não há desfazer.
        $this->assertSame(PubAprovacaoOferta::AJUSTES_SOLICITADOS, $classico->fresh()->andamento);
        $this->assertSame(PubAprovacaoOferta::AJUSTES_SOLICITADOS, $premium->fresh()->andamento, 'AND-07: o escopo `set` NÃO alcançou a oferta irmã — o Premium publicaria com a foto reprovada');

        $this->assertSame(PubAprovacaoOferta::AGUARDANDO_APROVACAO, $deOutroProduto->fresh()->andamento, 'o escopo `set` alcançou oferta de OUTRO produto');
    }

    public function test_pedir_ajuste_de_escopo_product_move_todas_as_ofertas_do_produto(): void
    {
        ['ofertas' => [$classico, $premium], 'produto' => $produto] = $this->cenario();
        $quem = $this->alguem();

        $empresa = Company::findOrFail($classico->company_id);
        ['ofertas' => [$deOutroProduto]] = $this->cenario($empresa, 'MESA-02');

        $ajuste = $this->servico()->pedir($classico, $quem, PubAprovacaoAjuste::ESCOPO_PRODUTO, 'Custo do produto', 'Custo desatualizado no ERP.');

        $this->assertSame($produto->id, $ajuste->produto_id);
        $this->assertNull($ajuste->oferta_id);
        $this->assertNull($ajuste->conjunto_id);
        $this->assertSame('Cadastro do produto (todas as ofertas)', $ajuste->destino);

        $this->assertSame(PubAprovacaoOferta::AJUSTES_SOLICITADOS, $classico->fresh()->andamento);
        $this->assertSame(PubAprovacaoOferta::AJUSTES_SOLICITADOS, $premium->fresh()->andamento, 'AND-07: o escopo `product` afeta TODAS as ofertas do produto');

        // ⚠️ ISCA PREENCHIDA: o outro produto da MESMA empresa não é afetado.
        $this->assertSame(PubAprovacaoOferta::AGUARDANDO_APROVACAO, $deOutroProduto->fresh()->andamento, 'o escopo `product` alcançou oferta de OUTRO produto');
    }

    public function test_resolver_o_ultimo_ajuste_devolve_para_aguardando_e_um_de_dois_nao_devolve(): void
    {
        ['ofertas' => [$classico, $premium]] = $this->cenario();
        $quem = $this->alguem();

        $primeiro = $this->servico()->pedir($classico, $quem, PubAprovacaoAjuste::ESCOPO_OFERTA, 'Título', 'Incluir a medida no título.');
        $segundo = $this->servico()->pedir($classico, $quem, PubAprovacaoAjuste::ESCOPO_OFERTA, 'Descrição', 'Descrição sem informações de montagem.');

        $this->assertSame(PubAprovacaoOferta::AJUSTES_SOLICITADOS, $classico->fresh()->andamento);
        $this->assertCount(2, $this->servico()->abertosQueAfetam($classico), 'ISCA: os DOIS ajustes abertos têm de estar lá, senão o caso "um de dois" é vazio por construção');

        // ⚠️ RESOLVER UM DE DOIS **NÃO** DEVOLVE. A oferta voltaria a ser aprovável com um ajuste
        // ainda em aberto.
        $this->servico()->resolver($primeiro, $quem);
        $this->assertSame(PubAprovacaoOferta::AJUSTES_SOLICITADOS, $classico->fresh()->andamento, 'AND-07: resolver 1 de 2 devolveu a oferta para a fila com ajuste ainda aberto');
        $this->assertCount(1, $this->servico()->abertosQueAfetam($classico));

        // Resolver o ÚLTIMO devolve.
        $this->servico()->resolver($segundo, $quem);
        $this->assertSame(PubAprovacaoOferta::AGUARDANDO_APROVACAO, $classico->fresh()->andamento, 'AND-07: resolver o ÚLTIMO ajuste tem de devolver para `aguardando_aprovacao`');
        $this->assertCount(0, $this->servico()->abertosQueAfetam($classico));

        // A resolução grava autoria e carimbo (auditoria-depois).
        $resolvido = $segundo->fresh();
        $this->assertFalse($resolvido->aberto);
        $this->assertSame($quem->id, $resolvido->resolvido_por);
        $this->assertSame('2026-10-15 14:30:00', $resolvido->resolvido_em->format('Y-m-d H:i:s'));

        // ⚠️ ISCA: a oferta IRMÃ nunca entrou (os dois ajustes eram de escopo `offer`).
        $this->assertSame(PubAprovacaoOferta::AGUARDANDO_APROVACAO, $premium->fresh()->andamento);
    }

    public function test_texto_vazio_nao_envia_e_nao_grava_nada(): void
    {
        ['ofertas' => [$classico]] = $this->cenario();
        $quem = $this->alguem();

        // ISCA: o pedido COM texto grava — senão "não gravou" seria verdade pelo motivo errado.
        $this->servico()->pedir($classico, $quem, PubAprovacaoAjuste::ESCOPO_OFERTA, 'Título', 'Texto de verdade do pedido.');
        $this->assertSame(1, PubAprovacaoAjuste::count());
        $eventosAntes = PubAprovacaoEvento::count();

        foreach (['', '   ', "\n\t "] as $vazio) {
            $pegou = null;
            try {
                $this->servico()->pedir($classico, $quem, PubAprovacaoAjuste::ESCOPO_OFERTA, 'Título', $vazio);
            } catch (RegraViolada $e) {
                $pegou = $e;
            }
            $this->assertNotNull($pegou, 'MOD-01: texto vazio tem de LANÇAR — o ajuste contaria como bloqueio e ninguém saberia o que fazer');
            $this->assertSame(AjustesDaOfertaService::REGRA_TEXTO_VAZIO, $pegou->regra);
        }

        // **Não gravou nada**: nem ajuste, nem evento.
        $this->assertSame(1, PubAprovacaoAjuste::count(), 'a recusa gravou ajuste');
        $this->assertSame($eventosAntes, PubAprovacaoEvento::count(), 'a recusa gravou evento no histórico');

        // E o escopo inválido também é recusado, com código PRÓPRIO (a tela diz coisas diferentes).
        $pegou = null;
        try {
            $this->servico()->pedir($classico, $quem, 'prod', 'Título', 'Texto de verdade do pedido.');
        } catch (RegraViolada $e) {
            $pegou = $e;
        }
        $this->assertNotNull($pegou, 'DADO-09: `prod` é a chave do PROTÓTIPO e tem de ser recusada');
        $this->assertSame(AjustesDaOfertaService::REGRA_ESCOPO_INVALIDO, $pegou->regra);
        $this->assertSame(1, PubAprovacaoAjuste::count());
    }

    public function test_cada_pedido_e_cada_resolucao_grava_evento_adjust_com_autor_e_data_reais(): void
    {
        ['ofertas' => [$classico, $premium]] = $this->cenario();
        $quem = $this->alguem('Carla Mendes');

        $historico = app(HistoricoDaOfertaService::class);

        // Escopo `set`: o evento é gravado nas DUAS ofertas — senão o histórico do Premium não
        // explicaria por que ele saiu da fila.
        $ajuste = $this->servico()->pedir($classico, $quem, PubAprovacaoAjuste::ESCOPO_CONJUNTO, 'Imagem #3 · Ambientada', 'A IA alterou os pés da poltrona.');

        foreach ([$classico, $premium] as $oferta) {
            $pedido = PubAprovacaoEvento::where('oferta_id', $oferta->id)->where('tipo', PubAprovacaoEvento::AJUSTE)->get();
            $this->assertCount(1, $pedido, "o pedido de ajuste não entrou no histórico da oferta {$oferta->id}");
            $this->assertStringContainsString('Solicitou ajuste', $pedido[0]->texto);
            $this->assertStringContainsString('Imagem #3 · Ambientada', $pedido[0]->texto, 'a frase do histórico tem de nomear o ITEM');
            // COL-05 — autor e data REAIS.
            $this->assertSame($quem->id, $pedido[0]->autor_id);
            $this->assertFalse($pedido[0]->ator_sistema);
            $this->assertSame('Carla Mendes', $pedido[0]->autorExibido());
            $this->assertSame('2026-10-15 14:30:00', $pedido[0]->ocorrido_em->format('Y-m-d H:i:s'));
        }

        // A RESOLUÇÃO também grava `adjust` nas duas.
        Carbon::setTestNow('2026-10-15 16:45:00');
        $this->servico()->resolver($ajuste, $quem);
        Carbon::setTestNow('2026-10-15 14:30:00');

        foreach ([$classico, $premium] as $oferta) {
            $eventos = PubAprovacaoEvento::where('oferta_id', $oferta->id)->where('tipo', PubAprovacaoEvento::AJUSTE)->orderBy('ocorrido_em')->get();
            $this->assertCount(2, $eventos, "a resolução não entrou no histórico da oferta {$oferta->id}");
            $this->assertStringContainsString('Marcou como resolvido', $eventos[1]->texto);
            $this->assertSame('2026-10-15 16:45:00', $eventos[1]->ocorrido_em->format('Y-m-d H:i:s'));
        }

        // E a linha do tempo do Clássico tem a transição do andamento ENTRE os dois — o gancho
        // `registrar()` do plano 186-04 está LIGADO.
        $linha = $historico->linhaDoTempo($classico);
        $this->assertGreaterThanOrEqual(4, count($linha), 'a ligação do `registrar()` do AndamentoDaOfertaService não gravou as transições');
        $tipos = array_column($linha, 'tipo');
        $this->assertContains(PubAprovacaoEvento::AJUSTE, $tipos);
    }

    public function test_abertos_que_afetam_junta_os_tres_escopos_e_nao_alcanca_outra_empresa(): void
    {
        ['ofertas' => [$classico], 'conjunto' => $conjunto, 'produto' => $produto] = $this->cenario();
        $quem = $this->alguem();

        // Um ajuste de CADA escopo, todos afetando esta oferta.
        PubAprovacaoAjuste::create(['escopo' => 'offer', 'oferta_id' => $classico->id, 'item' => 'Título', 'destino' => 'Este anúncio (Clássico)', 'texto' => 'a', 'autor_id' => $quem->id]);
        PubAprovacaoAjuste::create(['escopo' => 'set', 'conjunto_id' => $conjunto->id, 'item' => 'Imagem #1', 'destino' => 'Conjunto de fotos (Clássico e Premium)', 'texto' => 'b', 'autor_id' => $quem->id]);
        PubAprovacaoAjuste::create(['escopo' => 'product', 'produto_id' => $produto->id, 'item' => 'Custo', 'destino' => 'Cadastro do produto (todas as ofertas)', 'texto' => 'c', 'autor_id' => $quem->id]);
        // Um RESOLVIDO não conta.
        PubAprovacaoAjuste::create(['escopo' => 'offer', 'oferta_id' => $classico->id, 'item' => 'Resolvido', 'destino' => 'Este anúncio (Clássico)', 'texto' => 'd', 'autor_id' => $quem->id, 'aberto' => false, 'resolvido_por' => $quem->id, 'resolvido_em' => now()]);

        // ⚠️⚠️ T-186-20 / DADO-14 — A OUTRA EMPRESA ESTÁ **PREENCHIDA**, com os TRÊS escopos, e no
        // mesmo andamento. Asserção de ausência com fixture vazio é vazia por construção.
        $outra = Company::factory()->create();
        ['ofertas' => [$deOutra], 'conjunto' => $conjOutra, 'produto' => $prodOutra] = $this->cenario($outra, 'ALHEIA-01');
        PubAprovacaoAjuste::create(['escopo' => 'offer', 'oferta_id' => $deOutra->id, 'item' => 'X', 'destino' => 'Este anúncio (Clássico)', 'texto' => 'x', 'autor_id' => $quem->id]);
        PubAprovacaoAjuste::create(['escopo' => 'set', 'conjunto_id' => $conjOutra->id, 'item' => 'Y', 'destino' => 'Conjunto de fotos (Clássico e Premium)', 'texto' => 'y', 'autor_id' => $quem->id]);
        PubAprovacaoAjuste::create(['escopo' => 'product', 'produto_id' => $prodOutra->id, 'item' => 'Z', 'destino' => 'Cadastro do produto (todas as ofertas)', 'texto' => 'z', 'autor_id' => $quem->id]);

        $abertos = $this->servico()->abertosQueAfetam($classico);

        // Os 3 escopos NUMA leitura, o resolvido fora, e nada da outra empresa.
        $this->assertCount(3, $abertos, 'a consulta dos 3 escopos trouxe o número errado de ajustes');
        $this->assertSame(['Custo', 'Imagem #1', 'Título'], collect($abertos)->pluck('item')->sort()->values()->all());
        $this->assertNotContains('Resolvido', collect($abertos)->pluck('item')->all(), 'ajuste resolvido contou como bloqueio');
        foreach (['X', 'Y', 'Z'] as $alheio) {
            $this->assertNotContains($alheio, collect($abertos)->pluck('item')->all(), "T-186-20: a consulta alcançou o ajuste `{$alheio}` de OUTRA empresa");
        }

        // ⚠️ ISCA DO RECORTE (a direção simétrica): a consulta da oferta da OUTRA empresa acha os
        // 3 dela e nenhum dos nossos — senão "não vazou" poderia ser "não achou nada".
        $dela = $this->servico()->abertosQueAfetam($deOutra);
        $this->assertCount(3, $dela, 'ISCA CEGA: a consulta da outra empresa não achou os ajustes DELA');
        $this->assertSame(['X', 'Y', 'Z'], collect($dela)->pluck('item')->sort()->values()->all());
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // A ÚNICA PORTA, E A LIGAÇÃO DO `registrar()`
    // ═════════════════════════════════════════════════════════════════════════════════════════

    public function test_a_movimentacao_passa_sempre_pela_unica_porta_do_andamento_com_isca(): void
    {
        $codigo = $this->semComentarios($this->texto('app/Services/Publicador/Aprovacao/AjustesDaOfertaService.php'));

        // ⚠️ NENHUMA escrita direta de `andamento` neste serviço. Escrita de estado espalhada é
        // elevação de privilégio aqui: aprovar JÁ PUBLICA e não há desfazer (T-186-11).
        $this->assertDoesNotMatchRegularExpression("/['\"]andamento['\"]\s*=>/", $codigo, 'T-186-11: escrita direta de `andamento` — a transição tem de passar pela única porta');

        // ⚠️⚠️ A ATRIBUIÇÃO DIRETA É MEDIDA POR **REGEX COM `(?!=)`**, e isto é conserto de um
        // DEFEITO REAL da sonda, medido em 15/10/2026: a primeira redação usava
        // `assertStringNotContainsString('->andamento =')`, e ela **reprovou uma COMPARAÇÃO
        // legítima** (`$afetada->andamento === PubAprovacaoOferta::AJUSTES_SOLICITADOS` contém
        // `->andamento =` como substring). Uma sonda que acusa `===` como se fosse `=` empurra o
        // executor seguinte a reescrever código correto — ou a apagar o gate. ⚠️ **Não foi
        // afrouxada:** a atribuição de verdade continua sendo pega, e há isca provando os dois
        // lados.
        $atribuicao = '/->andamento\s*=(?!=)/';
        $this->assertDoesNotMatchRegularExpression($atribuicao, $codigo, 'T-186-11: atribuição direta de `andamento`');

        // ⚠️ ISCA DO REGEX nos dois sentidos — a armadilha de `->foreignId?\(` casando `foreignI` e
        // não `foreign(` deixou uma sonda CEGA na wave 1.
        $this->assertMatchesRegularExpression("/['\"]andamento['\"]\s*=>/", "<?php \$o->update(['andamento' => 'aprovado']);", 'ISCA CEGA: o regex não casa nem a escrita canônica.');
        $this->assertDoesNotMatchRegularExpression("/['\"]andamento['\"]\s*=>/", '<?php $o->andamentoRotulo();', 'ISCA: o regex casou algo que não é escrita.');
        $this->assertMatchesRegularExpression($atribuicao, '<?php $o->andamento = "aprovado";', 'ISCA CEGA: o regex não casa nem a atribuição canônica — a sonda estaria cega.');
        $this->assertMatchesRegularExpression($atribuicao, '<?php $o->andamento="aprovado";', 'ISCA CEGA: o regex não casa a atribuição sem espaço.');
        $this->assertDoesNotMatchRegularExpression($atribuicao, '<?php if ($o->andamento === $x) {}', 'ISCA: o regex casou uma COMPARAÇÃO — foi exatamente o falso positivo medido em 15/10/2026.');
        $this->assertDoesNotMatchRegularExpression($atribuicao, '<?php if ($o->andamento !== $x) {}', 'ISCA: o regex casou uma comparação de desigualdade.');

        // E a porta é USADA: o serviço de andamento é a única forma de mover.
        $this->assertStringContainsString('AndamentoDaOfertaService', $codigo, 'ISCA CEGA: o serviço nem referencia a única porta — "não escreve andamento" seria verdade por não mover nada');
        $this->assertStringContainsString('->mover(', $codigo);

        // ⚠️ AND-06 fica para o plano 186-08 — declarado no docblock (que é lido do arquivo CRU).
        $cru = $this->texto('app/Services/Publicador/Aprovacao/AjustesDaOfertaService.php');
        $this->assertStringContainsString('186-08', $cru, 'AND-06 tem de estar declarado como sendo do plano 186-08');
    }

    public function test_o_diff_do_servico_de_andamento_e_so_a_ligacao_do_registrar(): void
    {
        // ⚠️ RANGE obrigatório: `git diff --name-only -- <caminho>` SEM range compara a ÁRVORE e
        // sai **vazio sempre** depois de commitar (`feedback_gate_vazio_por_construcao`).
        $tocado = $this->gitDiff(self::ANDAMENTO);
        $this->assertSame(self::ANDAMENTO, $tocado, 'o serviço de andamento tem de ter sido tocado por este plano (a ligação do `registrar()`)');

        // ⚠️ ISCA DO DIFF: o MESMO comando, num arquivo que este plano NÃO tocou, sai vazio —
        // senão um `git` que falhasse em silêncio devolveria string vazia para tudo (ou o nome
        // para tudo) e o gate seria verde permanente.
        //
        // ⚠️⚠️ O SUJEITO DESTA ISCA FOI TROCADO EM 15/10/2026 PELO ORQUESTRADOR, e a razão é uma
        // CLASSE NOVA de gate frágil que vale mais que o conserto:
        //
        //   **Gate com range ABERTO (`<partida>..HEAD`) cuja isca é "ninguém mais toca neste
        //   arquivo" tem validade de UM PLANO.** `..HEAD` não mede o plano dele — mede tudo o que
        //   vier DEPOIS. Era `app/Models/PubAprovacaoOferta.php`, e a isca caiu no instante em que
        //   o plano 186-08 acrescentou o 13º par de transição — uma mudança **autorizada e
        //   correta**, decidida pelo usuário em 15/10. O gate acusou trabalho legítimo, e as
        //   outras 45 asserções (as que medem comportamento) seguiram verdes.
        //
        // ⚠️ O conserto NÃO é "trocar por outro arquivo qualquer e esperar": é escolher um sujeito
        // cuja estabilidade seja **garantida por regra**. `app/Support/Publicador/` é território
        // VETADO em toda a milestone (TERR-03), e há gate próprio exigindo que o diff dele saia
        // VAZIO. ➡️ Enquanto aquele gate existir, esta isca não pode cair — a mesma regra protege
        // as duas. Medido em 15/10: `git diff --name-only 1c16d9e9..HEAD -- app/Support/Publicador/`
        // devolve **0** linhas.
        //
        // A isca mede o que sempre mediu: que o `git` não devolve vazio para TUDO. O sujeito é
        // indiferente desde que esteja fora do assunto da milestone — e agora está, por regra.
        $this->assertSame('', $this->gitDiff('app/Support/Publicador/RegraViolada.php'), 'ISCA DO DIFF: um arquivo de território VETADO (TERR-03) aparece como tocado — ou o `git` devolve o nome para tudo, ou alguém mexeu em `App\\Support\\Publicador`');
        $this->assertNotSame('', $this->gitDiff(self::MIGRATION), 'ISCA DO DIFF: a migration DESTE plano não aparece como tocada — o `git` está devolvendo vazio para tudo');

        // ⚠️ O DIFF É **SÓ A LIGAÇÃO**: as linhas acrescentadas são o construtor, a chamada ao
        // histórico e o comentário que diz onde a ligação mora. **Nenhuma linha REMOVIDA** além
        // do corpo vazio do gancho, e `mover()` / `TRANSICOES` / a transação intactos.
        $diff = $this->gitDiffCompleto(self::ANDAMENTO);
        $this->assertNotSame('', $diff, 'ISCA CEGA: o diff do serviço de andamento veio vazio — nada seria medido abaixo');

        $linhas = preg_split('/\R/', $diff);
        $removidas = array_values(array_filter($linhas, fn ($l) => str_starts_with($l, '-') && ! str_starts_with($l, '---')));

        foreach ($removidas as $l) {
            $this->assertStringNotContainsString('TRANSICOES', $l, 'a lista fechada de transições do plano 186-04 foi alterada');
            $this->assertStringNotContainsString('DB::transaction', $l, 'a transação única do plano 186-04 foi alterada');
            $this->assertStringNotContainsString('public function mover', $l, '`mover()` foi reescrito — este plano só LIGA o gancho');
            $this->assertStringNotContainsString('aoAprovar', $l, 'o gancho da Fase 193 foi removido');
        }

        // O gancho segue existindo com a MESMA assinatura, e agora chama o histórico.
        $codigo = $this->semComentarios($this->texto(self::ANDAMENTO));
        $this->assertStringContainsString('protected function registrar(', $codigo, 'o gancho `registrar()` mudou de assinatura');
        $this->assertStringContainsString('protected function aoAprovar(', $codigo, 'o gancho `aoAprovar()` da Fase 193 saiu');
        $this->assertStringContainsString('HistoricoDaOfertaService', $codigo, 'a ligação não aconteceu: o gancho segue no-op');
        $this->assertStringContainsString('registrarTransicao', $codigo);
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // A DISCIPLINA DA MIGRATION (MIG-01..09)
    //
    // ⚠️ `MigrationDoPortaoGuardaTest` (plano 186-03) declara SÓ as duas migrations daquele plano,
    // e o guarda do 186-04 declara SÓ a dele. A migration deste plano fica FORA dos dois, e por
    // isso tem guarda PRÓPRIO aqui. Sem isto, a disciplina estaria "coberta" por um gate que
    // nunca a lê: a definição de passar por omissão.
    // ═════════════════════════════════════════════════════════════════════════════════════════

    // ⚠⚠ OS QUATRO GUARDAS ABAIXO LEVAM `_guarda_05_` NO NOME, E ISSO NÃO É ESTILO: medido em
    // 15/10/2026, os nomes genéricos COLIDIAM com os guardas homónimos de `OfertaDoPortaoTest`
    // (plano 186-04) e de `FreteInformadoTest` (plano 186-06, rodando EM PARALELO). O helper de
    // gate só aceita filtro por MÉTODO, então um nome compartilhado faz a contagem do manifesto
    // deste plano **depender de quantos arquivos de OUTROS planos existem** — exatamente a classe
    // de gate frágil por construção que esta fase já teve de consertar na raiz uma vez.
    public function test_guarda_05_nenhum_nome_de_indice_ou_fk_passa_de_64_medido_por_strlen_com_isca(): void
    {
        $texto = $this->texto(self::MIGRATION);

        preg_match_all("/->(?:unique|index|foreign)\(\s*(?:'[^']*'|\[[^\]]*\])\s*,\s*'([^']+)'\s*\)/", $texto, $m);
        $declarados = $m[1];

        // ⚠️ ISCA DA SONDA: ela tem de ACHAR os 18 nomes declarados (9 índices + 9 FKs). Zero
        // achados faria o laço rodar sobre lista vazia — verde, medindo nada.
        $this->assertCount(18, $declarados, 'ISCA CEGA: a sonda não achou os 18 nomes declarados (9 índices + 9 FKs).');
        $this->assertContains('pubapaj_oferta_ix', $declarados);
        $this->assertContains('pubapev_linha_ix', $declarados);
        $this->assertContains('pubapex_liberada_fk', $declarados);

        foreach ($declarados as $nome) {
            $this->assertLessThanOrEqual(64, strlen($nome), "o nome DECLARADO `{$nome}` tem " . strlen($nome) . ' caracteres e estoura o limite do MariaDB (erro 1059)');
        }

        // ⚠️ O MAIOR, TRAVADO: mudar o schema sem reconferir o docblock reprova aqui. O 186-03
        // mediu o plano dele errando em 1, e ESTE plano errou em 1 em QUATRO números dos padrões
        // do Laravel — por isso é `strlen`, nunca número copiado à mão.
        $maior = array_reduce($declarados, fn ($c, $n) => strlen($n) > strlen((string) $c) ? $n : $c, '');
        $this->assertSame(20, strlen($maior), 'o maior nome declarado deixou de ter 20 caracteres — reconferir o docblock §6');

        // Os nomes **PADRÃO DO LARAVEL** (os que ESTOURAM), calculados por `strlen`.
        $padroes = [];
        foreach ([
            'pub_aprovacao_ajustes' => ['oferta_id', 'conjunto_id', 'produto_id', 'autor_id', 'resolvido_por'],
            'pub_aprovacao_eventos' => ['oferta_id', 'autor_id'],
            'pub_aprovacao_excecoes' => ['oferta_id', 'liberada_por'],
        ] as $tabela => $colunas) {
            foreach ($colunas as $coluna) {
                $padroes[] = "{$tabela}_{$coluna}_foreign";
            }
        }
        $this->assertCount(9, $padroes);
        foreach ($padroes as $nome) {
            $this->assertLessThanOrEqual(64, strlen($nome), "o nome PADRÃO `{$nome}` estouraria o limite do MariaDB");
        }
        $maiorPadrao = array_reduce($padroes, fn ($c, $n) => strlen($n) > strlen((string) $c) ? $n : $c, '');
        $this->assertSame(43, strlen($maiorPadrao), 'o maior nome padrão deixou de ter 43 caracteres — reconferir o docblock §6');

        // ⚠️ E **nenhum** nome padrão é USADO: `->constrained()` é quem os gera sem ninguém ver.
        $this->assertStringNotContainsString('->constrained(', $this->semComentarios($texto), 'MIG-02: `->constrained()` gera nome de FK sem ninguém ver');
    }

    public function test_guarda_05_toda_fk_nullondelete_tem_coluna_nullable_explicito_com_isca(): void
    {
        $codigo = $this->semComentarios($this->texto(self::MIGRATION));

        // As FKs `nullOnDelete()` e a coluna de cada uma (aqui coluna e FK são statements
        // SEPARADOS, então o pareamento é pela SEMÂNTICA do nome da coluna).
        $regexFk = "/->foreign\(\s*'([^']+)'\s*,\s*'[^']+'\s*\)((?:(?!;).)*)/s";
        preg_match_all($regexFk, $codigo, $fks, PREG_SET_ORDER);
        $this->assertCount(9, $fks, 'ISCA CEGA: a sonda não achou as 9 FKs.');

        $nullOnDelete = [];
        foreach ($fks as $fk) {
            if (str_contains($fk[2], 'nullOnDelete')) {
                $nullOnDelete[] = $fk[1];
            }
        }
        sort($nullOnDelete);
        $this->assertSame(['autor_id', 'autor_id', 'liberada_por', 'produto_id', 'resolvido_por'], $nullOnDelete, 'mudou o conjunto de FKs `nullOnDelete` — reconferir o docblock §7');

        // ⚠️ Erro **1830** do MariaDB, invisível no SQLite: toda coluna de FK `nullOnDelete` tem
        // `->nullable()` EXPLÍCITO no texto.
        foreach (array_unique($nullOnDelete) as $coluna) {
            $this->assertMatchesRegularExpression(
                "/->unsignedBigInteger\('" . preg_quote($coluna, '/') . "'\)->nullable\(\)/",
                $codigo,
                "MIG-03: a coluna `{$coluna}` tem FK `nullOnDelete` e NÃO declara `->nullable()` — erro 1830 no MariaDB, invisível no SQLite"
            );
        }

        // ⚠️ ISCA DO REGEX nos dois sentidos — na wave 1 um regex de FK casava um prefixo errado e
        // a sonda não achava FK nenhuma.
        preg_match_all($regexFk, "<?php \$t->foreign('quem_id', 'xx_fk')->references('id')->on('users')->nullOnDelete();", $certo, PREG_SET_ORDER);
        $this->assertCount(1, $certo, 'ISCA CEGA: o regex de FK não casa nem o caso canônico — era o bug de `->foreignI`.');
        $this->assertSame('quem_id', $certo[0][1]);
        preg_match_all($regexFk, "<?php \$t->index('quem_id', 'xx_ix');", $errado, PREG_SET_ORDER);
        $this->assertCount(0, $errado, 'ISCA: o regex de FK casou um `index()`.');

        // E no SCHEMA VIVO: as 5 são anuláveis, e as CASCADE de eventos/exceções NÃO são.
        $this->assertTrue($this->colunaEAnulavel('pub_aprovacao_ajustes', 'produto_id'));
        $this->assertTrue($this->colunaEAnulavel('pub_aprovacao_eventos', 'autor_id'));
        $this->assertTrue($this->colunaEAnulavel('pub_aprovacao_excecoes', 'liberada_por'));
        $this->assertFalse($this->colunaEAnulavel('pub_aprovacao_eventos', 'oferta_id'), 'evento sem oferta não é histórico de nada');
        $this->assertFalse($this->colunaEAnulavel('pub_aprovacao_excecoes', 'oferta_id'));
        // ⚠️ As duas CASCADE de `pub_aprovacao_ajustes` SÃO anuláveis, e por outro motivo: só UMA
        // âncora é preenchida por linha (§1.1 da migration).
        $this->assertTrue($this->colunaEAnulavel('pub_aprovacao_ajustes', 'oferta_id'));
        $this->assertTrue($this->colunaEAnulavel('pub_aprovacao_ajustes', 'conjunto_id'));
    }

    public function test_guarda_05_a_migration_nao_usa_enum_nem_try_catch_e_o_up_e_idempotente(): void
    {
        $cru = $this->texto(self::MIGRATION);
        $codigo = $this->semComentarios($cru);

        // ⚠️⚠️ LER O CÓDIGO SEM COMENTÁRIOS NÃO É COSMÉTICO. O docblock desta migration **explica**
        // que não usa `enum` nem `try/catch`. Um gate que conte a palavra no arquivo inteiro casa
        // com a frase que diz que a coisa não é usada e reprova o PRÓPRIO CONSERTO
        // (`feedback_gate_que_reprova_pelo_proprio_conserto`).
        $this->assertStringNotContainsString('enum', $codigo, 'MIG-04: `enum` no CÓDIGO — o CHECK é enforçado no SQLite dos testes e cegaria a suíte');
        $this->assertStringNotContainsString('try', $codigo, 'MIG-05: `try` no CÓDIGO — neste projeto um 1553 ficou engolido 2 meses dentro de um catch');
        $this->assertStringNotContainsString('catch', $codigo);

        // ISCA DO TOKENIZADOR, nos dois sentidos.
        $soComentario = "<?php\n// Sem `enum()` e sem try/catch em volta de DDL.\n\$t->string('escopo', 8);\n";
        $noCodigo = "<?php\n\$t->enum('escopo', ['offer']);\n";
        $this->assertStringNotContainsString('enum', $this->semComentarios($soComentario), 'ISCA CEGA: o tokenizador não tirou a palavra que só aparecia no comentário.');
        $this->assertStringNotContainsString('try', $this->semComentarios($soComentario), 'ISCA CEGA: `try` do comentário sobreviveu — o gate reprovaria pelo próprio conserto.');
        $this->assertStringContainsString("\$t->string('escopo', 8)", $this->semComentarios($soComentario), 'ISCA: o tokenizador comeu o CÓDIGO junto com o comentário.');
        $this->assertStringContainsString('enum', $this->semComentarios($noCodigo), 'ISCA CEGA: a palavra em CÓDIGO tem de sobreviver.');

        // ⚠️ E a prova de que o docblock REALMENTE contém as palavras — senão "não achei no código"
        // seria verdade pelo motivo errado (o arquivo não falar do assunto).
        $this->assertStringContainsString('enum', $cru, 'ISCA: o docblock deixou de explicar por que não há `enum`');
        $this->assertStringContainsString('try/catch', $cru, 'ISCA: o docblock deixou de explicar por que não há try/catch');

        // MIG-05 — `up()` idempotente por `hasTable`, **tabela por tabela**.
        foreach (self::TABELAS as $tabela) {
            $this->assertStringContainsString("Schema::hasTable('{$tabela}')", $codigo, "MIG-05: o `up()` não é idempotente para `{$tabela}`");
        }

        // MIG-07 — a DECISÃO DE SCHEMA vem ANTES do código.
        $this->assertStringContainsString('DECISÃO DE SCHEMA', $cru);
        $this->assertLessThan(strpos($cru, 'Schema::create('), strpos($cru, 'DECISÃO DE SCHEMA'), 'a tabela de decisão de schema tem de vir ANTES do código');

        // MIG-09 — nenhuma coluna renomeada, nenhum ALTER, nenhum backfill.
        foreach (['->change(', 'renameColumn(', 'dropColumn(', '->delete(', 'truncate('] as $destrutivo) {
            $this->assertStringNotContainsString($destrutivo, $codigo, "MIG-09: `{$destrutivo}` numa migration que só CRIA");
        }
    }

    public function test_guarda_05_o_down_derruba_as_tres_tabelas_na_ordem_inversa_da_criacao(): void
    {
        $codigo = $this->semComentarios($this->texto(self::MIGRATION));

        if (! preg_match('/public function down\(\): void\s*\{(.+?)\n    \}/s', $codigo, $down)) {
            $this->fail('ISCA CEGA: não achei o corpo do `down()`.');
        }

        // ⚠️ A ORDEM É O INVERSO EXATO DA CRIAÇÃO — a ordem filha→mãe segura por construção.
        preg_match_all("/dropIfExists\('([^']+)'\)/", $down[1], $derrubadas);
        $this->assertSame(['pub_aprovacao_excecoes', 'pub_aprovacao_eventos', 'pub_aprovacao_ajustes'], $derrubadas[1], 'o `down()` não derruba as 3 tabelas na ordem inversa da criação');

        // ⚠️ E não derruba nenhuma das 4 tabelas-MÃES, que são de OUTROS planos e EXISTEM.
        foreach (['pub_aprovacao_ofertas', 'pub_aprovacao_conjuntos', 'pub_produtos', 'users'] as $mae) {
            $this->assertTrue(Schema::hasTable($mae), "ISCA: a tabela-mãe `{$mae}` não existe — a asserção abaixo seria vazia");
            $this->assertStringNotContainsString("dropIfExists('{$mae}')", $down[1], "o `down()` derruba a tabela-MÃE `{$mae}`, que é de outro plano");
        }
        $this->assertStringNotContainsString('dropColumn', $down[1]);

        // ⚠️⚠️ A SONDA DE ORDEM `dropForeign` ANTES DE `dropIndex` (erro **1553**) roda sobre
        // **ZERO casos** aqui: o `dropIfExists` da tabela inteira subsume o par, que é a receita
        // ADITIVA. **Gate que roda sobre zero casos não prova nada** — então ela fica COM ISCA
        // num texto sintético aditivo, onde TEM de achar os dois e ver a ordem.
        $this->assertStringNotContainsString('dropIndex', $down[1], 'apareceu `dropIndex` sem o par `dropForeign` — erro 1553');
        $sintetico = "<?php \$t->dropForeign(['x_id']); \$t->dropIndex('x_ix');";
        $posFk = strpos($sintetico, 'dropForeign([');
        $posIx = strpos($sintetico, 'dropIndex');
        $this->assertNotFalse($posFk, 'ISCA CEGA: a sonda de `dropForeign([` não acha nem o caso canônico (era o bug de `->foreignI`).');
        $this->assertNotFalse($posIx);
        $this->assertLessThan($posIx, $posFk, 'ISCA: a sonda de ORDEM não distingue `dropForeign` antes de `dropIndex`.');
    }

    public function test_o_historico_e_append_only_e_nenhum_codigo_do_plano_apaga_evento_com_isca(): void
    {
        // ⚠️ MIG-09 / T-186-18 — **nenhuma linha de histórico é apagada por nada**. Sem isto,
        // "auditoria depois" (BLOQ-09) deixa de ser verdade e ninguém percebe: a suíte segue
        // verde, e o que falta é justamente a prova de um ato que já aconteceu.
        $arquivos = [
            self::MIGRATION,
            'app/Models/PubAprovacaoEvento.php',
            'app/Services/Publicador/Aprovacao/HistoricoDaOfertaService.php',
            'app/Services/Publicador/Aprovacao/AjustesDaOfertaService.php',
        ];

        foreach ($arquivos as $arquivo) {
            $codigo = $this->semComentarios($this->texto($arquivo));

            foreach (['->delete(', '::destroy(', 'truncate(', 'forceDelete('] as $apaga) {
                $this->assertStringNotContainsString($apaga, $codigo, "T-186-18: `{$apaga}` em `{$arquivo}` — o histórico é append-only");
            }
        }

        // ⚠️ ISCA DA SONDA nos dois sentidos: ela TEM de achar a forma proibida num texto que a
        // contenha, senão "não achei" é verdade por cegueira.
        $this->assertStringContainsString('->delete(', $this->semComentarios('<?php $evento->delete();'), 'ISCA CEGA: a sonda não acha `->delete(` nem no caso canônico.');
        $this->assertStringNotContainsString('->delete(', $this->semComentarios('<?php // nada de $evento->delete() aqui'), 'ISCA: a forma em COMENTÁRIO tem de desaparecer, senão o gate reprova pelo próprio conserto.');

        // E **não há coluna de "apagado"/"oculto"** que permitisse esconder um evento sem apagá-lo.
        // ⚠️ Esta negativa NÃO é vazia por construção: a tabela é criada POR ESTE PLANO, e a isca
        // prova que a sonda enxerga as colunas que EXISTEM.
        $this->assertTrue(Schema::hasColumn('pub_aprovacao_eventos', 'ocorrido_em'), 'ISCA CEGA: `hasColumn` não acha coluna que EXISTE');
        foreach (['apagado', 'oculto', 'deleted_at', 'removido', 'visivel', 'arquivado'] as $esconderia) {
            $this->assertFalse(Schema::hasColumn('pub_aprovacao_eventos', $esconderia), "T-186-18: a coluna `{$esconderia}` permitiria esconder um evento sem apagá-lo");
        }

        // E o docblock declara o append-only (é a prova escrita da auditoria-depois).
        $this->assertStringContainsString('append-only', $this->texto(self::MIGRATION));
        $this->assertMatchesRegularExpression('/append-only/i', $this->texto('app/Models/PubAprovacaoEvento.php'),
            'o modelo deixou de declarar o append-only');
    }

    // ─────────────────────────────────────────────────────────────────────────────────────────
    // Auxiliares
    // ─────────────────────────────────────────────────────────────────────────────────────────

    private function texto(string $caminho): string
    {
        $abs = base_path($caminho);
        $this->assertFileExists($abs, "arquivo declarado e ausente no disco ({$caminho}) — toda sonda de texto mediria NADA");
        $this->assertFileDoesNotExist(base_path(self::MIGRATION_FANTASMA), 'ISCA DE CAMINHO: `file_exists` passou a devolver `true` para caminho inventado');

        return file_get_contents($abs);
    }

    /** O CÓDIGO sem os comentários, pelo tokenizador do PHP. */
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

    /** `git diff <partida>..HEAD --name-only -- <caminho>`, com RANGE. */
    private function gitDiff(string $caminho): string
    {
        $saida = shell_exec(sprintf(
            'git -C %s diff %s..HEAD --name-only -- %s 2>&1',
            escapeshellarg(base_path()),
            escapeshellarg(self::PARTIDA),
            escapeshellarg($caminho)
        ));

        return trim((string) $saida);
    }

    /** O diff COMPLETO (com as linhas), também com RANGE. */
    private function gitDiffCompleto(string $caminho): string
    {
        $saida = shell_exec(sprintf(
            'git -C %s diff %s..HEAD -- %s 2>&1',
            escapeshellarg(base_path()),
            escapeshellarg(self::PARTIDA),
            escapeshellarg($caminho)
        ));

        return trim((string) $saida);
    }

    /** A coluna é anulável no SCHEMA VIVO (SQLite dos testes)? */
    private function colunaEAnulavel(string $tabela, string $coluna): bool
    {
        foreach (Schema::getColumns($tabela) as $c) {
            if (($c['name'] ?? null) === $coluna) {
                return (bool) ($c['nullable'] ?? false);
            }
        }

        $this->fail("coluna `{$coluna}` não existe em `{$tabela}`");
    }
}
