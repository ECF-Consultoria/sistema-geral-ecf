<?php

namespace Tests\Unit\Publicador\Aprovacao;

use App\Models\Company;
use App\Models\PubAprovacaoOferta;
use App\Models\PubImagem;
use App\Models\PubProduto;
use App\Models\PubRascunho;
use App\Models\PubRascunhoAlvo;
use App\Models\User;
use App\Services\Publicador\Aprovacao\AndamentoDaOfertaService;
use App\Support\Publicador\RegraViolada;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ═══════════════════════════════════════════════════════════════════════════════════════════
 * Fase 186, plano 04 — OS 8 ESTADOS DE ANDAMENTO INTERNO E A ÚNICA PORTA DE TRANSIÇÃO.
 *
 * ⚠️⚠️ DE CADA GATE DESTE ARQUIVO, A PERGUNTA RESPONDIDA ANTES DE ELE EXISTIR:
 * **"o que precisaria estar errado no mundo para ele falhar?"** — gate cuja resposta é
 * "nada" é decorativo e não entra (`feedback_gate_vazio_por_construcao`).
 *
 *   · **vocabulário espelhado** — alguém editar o rótulo pt-BR num dos dois lados (PHP ou
 *     `andamentoDoPortao.jsx`) e os dois vocabulários divergirem. É O bug que o plano 186-02
 *     resolveu: chave de dado num idioma e rótulo noutro são **duas verdades para manter**, e
 *     o selo renderizaria vazio em produção com a suíte inteira verde.
 *   · **transições** — alguém acrescentar `aprovado → publicado` (pulando `publicando`) ou
 *     `preparando → aprovado`. Como **aprovar JÁ PUBLICA e não há desfazer** (decisão do
 *     usuário de 14/10), transição a mais aqui é elevação de privilégio: publica sem ninguém
 *     ter conferido (T-186-11).
 *   · **`LIVE` / `ehLive`** — a regra "é editável e aprovável" duplicada em dois lugares, e
 *     os dois livres para divergir: a divergência liberaria edição de oferta já publicada.
 *   · **`ORDEM`** — alguém "arrumar" o empate de `aguardando_aprovacao` e `em_revisao_ecf` em
 *     1: a fila mudaria de ordem sem nenhum teste de render acusar.
 *   · **`podeSerPublicado`** — chegar a `publicado` sem `ml_item_id` (T-186-12): a tela diria
 *     "Publicado e confirmado no Mercado Livre" sem MLB nenhum.
 *   · **chave adversa** — `$MAPA[$chave]` cru com chave vinda do banco. Em PHP uma chave que
 *     não é string nem int **lança** `TypeError`, e `'constructor'`/`'toString'` em `::class`
 *     não existem mas a classe de falha é a mesma do REND-03 do JS.
 *   · **os dois eixos** — alguém reusar o estado de PUBLICAÇÃO de `pub_rascunhos` ou o de
 *     UPLOAD de `pub_imagens` como andamento interno. O README é explícito: *"o andamento
 *     interno nunca aparece na lista de Publicadas, e o status do ML nunca aparece na fila"*,
 *     e por isso a asserção é nas **DUAS DIREÇÕES**.
 *   · **"desfazer"/"confirmar publicação"** — alguém acrescentar um segundo clique ou um
 *     cancelamento de aprovação, que a decisão do usuário de 14/10 **recusou**.
 *
 * ⚠️ TODA SONDA TEM **ISCA** — um controle que a sonda tem de ACUSAR. Sem isca, "não achei
 * violação" é indistinguível de "a sonda não casa nada", e um regex quebrado vira gate verde
 * permanente. Na wave 1 desta fase uma isca pegou `->foreignId?\(` casando `foreignI` e não
 * `foreign(` — a sonda não achava a coluna de FK nenhuma.
 * ═══════════════════════════════════════════════════════════════════════════════════════════
 */
class AndamentoDaOfertaServiceTest extends TestCase
{
    use RefreshDatabase;

    /** O módulo de vocabulário do plano 186-02 — SOMENTE LEITURA para este plano. */
    private const MODULO_JS = 'resources/js/Components/Mlb/Publicador/aprovacao/andamentoDoPortao.jsx';

    /** Clássico e Premium — `EstruturaPublicacao::LISTING_TYPES`. */
    private const CLASSICO = 'gold_special';

    private const PREMIUM = 'gold_pro';

    protected function setUp(): void
    {
        parent::setUp();
        // ⚠️ AND-08 ancorado: "maior espera primeiro" só é mensurável com o agora parado.
        Carbon::setTestNow('2026-10-15 09:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ─────────────────────────────────────────────────────────────────────────────────────────
    // Fixture: o grafo REAL — produto -> rascunho -> 2 alvos -> 2 ofertas do portão.
    // ─────────────────────────────────────────────────────────────────────────────────────────

    /** @return array{0: PubAprovacaoOferta, 1: PubAprovacaoOferta} [Clássico, Premium] */
    private function asDuasOfertas(string $sku = 'CAD-01', ?Company $empresa = null): array
    {
        $empresa ??= Company::factory()->create();

        $produto = PubProduto::create([
            'company_id' => $empresa->id,
            'sku' => $sku,
            'nome' => 'Cadeira ' . $sku,
            'origem' => PubProduto::ORIGEM_PUBLICADOR,
        ]);

        $rascunho = PubRascunho::create(['produto_id' => $produto->id]);

        $ofertas = [];
        foreach ([self::CLASSICO, self::PREMIUM] as $i => $tipo) {
            $alvo = PubRascunhoAlvo::create(['rascunho_id' => $rascunho->id, 'listing_type_id' => $tipo, 'posicao' => $i]);

            $ofertas[] = PubAprovacaoOferta::create([
                'alvo_id' => $alvo->id,
                'rascunho_id' => $rascunho->id,
                'produto_id' => $produto->id,
                'company_id' => $empresa->id,
            ]);
        }

        return $ofertas;
    }

    private function servico(): AndamentoDaOfertaService
    {
        return app(AndamentoDaOfertaService::class);
    }

    /** Leva a oferta até um andamento por transições LEGAIS, uma a uma. */
    private function levarAte(PubAprovacaoOferta $oferta, array $caminho): PubAprovacaoOferta
    {
        foreach ($caminho as $passo) {
            $oferta = $this->servico()->mover($oferta, $passo);
        }

        return $oferta;
    }

    // ─────────────────────────────────────────────────────────────────────────────────────────
    // A sonda do módulo JS, e a isca dela.
    // ─────────────────────────────────────────────────────────────────────────────────────────

    /**
     * As entradas de `ANDAMENTOS` do módulo `.jsx`, lidas do TEXTO.
     *
     * ⚠️ Lê o arquivo em vez de repetir os 8 rótulos aqui, e a diferença não é estilo: rótulo
     * repetido à mão é rótulo que pode divergir sem ninguém ver — exatamente o que este gate
     * existe para pegar.
     *
     * @return array<string, array{rotulo: string, cta: string, abreRevisao: bool}>
     */
    private function entradasDoModuloJs(): array
    {
        $caminho = base_path(self::MODULO_JS);

        // ⚠️ ISCA DE CAMINHO. 5 caminhos fantasma em 4 fases deste projeto: arquivo ausente
        // faria a sonda devolver lista VAZIA e o laço de comparação rodaria sobre zero pares,
        // verde e medindo nada.
        $this->assertFileExists($caminho, 'o módulo de vocabulário do plano 186-02 sumiu do disco');

        $texto = file_get_contents($caminho);

        $inicio = strpos($texto, 'export const ANDAMENTOS = {');
        $this->assertNotFalse($inicio, 'ISCA CEGA: não achei o bloco `export const ANDAMENTOS = {` no módulo.');

        $fim = strpos($texto, "\n};", $inicio);
        $this->assertNotFalse($fim, 'ISCA CEGA: não achei o fim do bloco de ANDAMENTOS.');

        $bloco = substr($texto, $inicio, $fim - $inicio) . "\n";

        preg_match_all('/^    ([a-z_]+): \{(.*?)^    \},/ms', $bloco, $pares, PREG_SET_ORDER);

        $saida = [];
        foreach ($pares as $par) {
            preg_match("/rotulo: '(.*?)',/", $par[2], $r);
            preg_match("/cta: '(.*?)',/", $par[2], $c);
            preg_match('/abreRevisao: (true|false),/', $par[2], $a);
            $saida[$par[1]] = [
                'rotulo' => $r[1] ?? null,
                'cta' => $c[1] ?? null,
                'abreRevisao' => ($a[1] ?? null) === 'true',
            ];
        }

        return $saida;
    }

    public function test_isca_a_sonda_do_modulo_js_acha_os_oito_estados_e_um_rotulo_conhecido(): void
    {
        $lidas = $this->entradasDoModuloJs();

        // Se a sonda estiver cega, estas três asserções caem e TODO gate que depende dela fica
        // sob suspeita — em vez de passar por omissão.
        $this->assertCount(8, $lidas, 'ISCA CEGA: a sonda não leu 8 entradas de ANDAMENTOS no .jsx.');
        $this->assertSame('Preparando', $lidas['preparando']['rotulo'] ?? null, 'ISCA CEGA: a sonda não leu nem o rótulo de `preparando`.');
        $this->assertSame('Tentar de novo', $lidas['falha']['cta'] ?? null, 'ISCA CEGA: a sonda não leu nem o cta de `falha`.');

        // E o contrapositivo: um bloco sintético com rótulo DIFERENTE tem de ser lido como
        // diferente, senão a comparação byte a byte abaixo não compara nada.
        preg_match("/rotulo: '(.*?)',/", "        rotulo: 'Qualquer Outra Coisa',\n", $forjado);
        $this->assertSame('Qualquer Outra Coisa', $forjado[1], 'ISCA: o regex de rótulo não lê o que está escrito.');
    }

    // ─────────────────────────────────────────────────────────────────────────────────────────
    // Os 8 estados, com o rótulo pt-BR ESPELHADO byte a byte (VOC-02 / VOC-06)
    // ─────────────────────────────────────────────────────────────────────────────────────────

    public function test_sao_exatamente_oito_andamentos_com_rotulo_espelhado_do_modulo_js(): void
    {
        $this->assertCount(8, PubAprovacaoOferta::ANDAMENTOS, 'o número de estados de andamento interno mudou');

        // ⚠️ As chaves de DADO são pt-BR nos dois lados (decisão do plano 186-02). Nenhuma
        // chave inglesa do protótipo (`awaiting`, `ecf_review`, `changes`) é chave aqui.
        $this->assertSame(
            ['preparando', 'aguardando_aprovacao', 'em_revisao_ecf', 'ajustes_solicitados', 'aprovado', 'publicando', 'falha', 'publicado'],
            array_keys(PubAprovacaoOferta::ANDAMENTOS),
            'as 8 chaves de andamento, ou a ordem delas, mudaram'
        );

        $doJs = $this->entradasDoModuloJs();

        foreach (PubAprovacaoOferta::ANDAMENTOS as $chave => $rotulo) {
            $this->assertArrayHasKey($chave, $doJs, "o estado `{$chave}` existe no PHP e NÃO existe no módulo .jsx — os dois vocabulários divergiram");
            $this->assertSame($doJs[$chave]['rotulo'], $rotulo, "o rótulo de `{$chave}` divergiu entre o PHP e o .jsx — são duas verdades para manter");
        }

        // E no SENTIDO INVERSO: estado no .jsx que não existe no PHP. Sem esta direção, o laço
        // acima passaria com o PHP tendo 8 de 20.
        foreach ($doJs as $chave => $_) {
            $this->assertArrayHasKey($chave, PubAprovacaoOferta::ANDAMENTOS, "o estado `{$chave}` existe no .jsx e NÃO existe no PHP");
        }

        // ⚠️ "Em revisão pela ECF" diz DE QUEM é a revisão, e é por isso que ele é legítimo: o
        // literal CRU "Em revisão" foi aposentado pelo plano 186-01, que deixou este rótulo na
        // lista de QUALIFICADOS. Encurtar aqui reprovaria o gate daquele plano.
        $this->assertSame('Em revisão pela ECF', PubAprovacaoOferta::ANDAMENTOS['em_revisao_ecf']);
    }

    // ─────────────────────────────────────────────────────────────────────────────────────────
    // AND-02 — as transições DECLARADAS: 10 regras / 12 pares aceitos, e as recusadas
    // ─────────────────────────────────────────────────────────────────────────────────────────

    public function test_as_transicoes_aceitas_e_as_recusadas_estao_declaradas(): void
    {
        $aceitos = [
            ['preparando', 'aguardando_aprovacao'],
            ['aguardando_aprovacao', 'em_revisao_ecf'],
            ['aguardando_aprovacao', 'ajustes_solicitados'],
            ['em_revisao_ecf', 'ajustes_solicitados'],
            ['ajustes_solicitados', 'aguardando_aprovacao'],
            // "qualquer live → aprovado" — 3 pares, um por estado ao vivo.
            ['aguardando_aprovacao', 'aprovado'],
            ['em_revisao_ecf', 'aprovado'],
            ['ajustes_solicitados', 'aprovado'],
            ['aprovado', 'publicando'],
            ['publicando', 'publicado'],
            ['publicando', 'falha'],
            // "Tentar de novo" (AND-10).
            ['falha', 'publicando'],
        ];

        foreach ($aceitos as [$de, $para]) {
            $this->assertTrue(PubAprovacaoOferta::transicaoPermitida($de, $para), "a transição `{$de}` -> `{$para}` deveria ser aceita e NÃO é");
        }

        // ⚠️ A CONTAGEM: 12 pares e nada além deles. Sem isto, acrescentar uma transição nova
        // (= publicar sem conferência, T-186-11) não moveria número nenhum.
        $pares = 0;
        foreach (PubAprovacaoOferta::TRANSICOES as $destinos) {
            $pares += count($destinos);
        }
        $this->assertSame(12, $pares, 'o número de transições aceitas mudou — transição a mais aqui publica sem ninguém ter conferido');

        $recusados = [
            // Pular a conferência inteira.
            ['preparando', 'aprovado'],
            // ⚠️ `aprovado -> publicado` TEM de passar por `publicando`: é em `publicando` que
            // o `ml_item_id` chega, e `publicado` sem MLB é o T-186-12.
            ['aprovado', 'publicado'],
            // Terminal: `publicado` não vai para lugar nenhum.
            ['publicado', 'preparando'],
            ['publicado', 'aguardando_aprovacao'],
            ['publicado', 'em_revisao_ecf'],
            ['publicado', 'ajustes_solicitados'],
            ['publicado', 'aprovado'],
            ['publicado', 'publicando'],
            ['publicado', 'falha'],
            ['publicado', 'publicado'],
        ];

        foreach ($recusados as [$de, $para]) {
            $this->assertFalse(PubAprovacaoOferta::transicaoPermitida($de, $para), "a transição `{$de}` -> `{$para}` deveria ser RECUSADA e é aceita");
        }

        // `publicado` é terminal POR ESTRUTURA (lista vazia), não por laço de exceção.
        $this->assertSame([], PubAprovacaoOferta::TRANSICOES['publicado']);

        // ISCA: a sonda de transição distingue par aceito de par recusado. Sem isto, um
        // `transicaoPermitida()` que devolvesse sempre `true` passaria no primeiro laço e um
        // que devolvesse sempre `false` passaria no segundo.
        $this->assertNotSame(
            PubAprovacaoOferta::transicaoPermitida('preparando', 'aguardando_aprovacao'),
            PubAprovacaoOferta::transicaoPermitida('preparando', 'aprovado'),
            'ISCA CEGA: a sonda devolve o mesmo veredicto para par aceito e par recusado.'
        );

        // Todas as 8 chaves são origem declarada: origem que falta no mapa viraria "nenhuma
        // transição possível" em silêncio, e a oferta travaria sem erro.
        $this->assertSame(array_keys(PubAprovacaoOferta::ANDAMENTOS), array_keys(PubAprovacaoOferta::TRANSICOES));
    }

    // ─────────────────────────────────────────────────────────────────────────────────────────
    // AND-05 — `LIVE` e `ehLive()`: o ÚNICO lugar que decide editável/aprovável
    // ─────────────────────────────────────────────────────────────────────────────────────────

    public function test_live_sao_os_tres_ao_vivo_e_ehlive_e_o_unico_decisor(): void
    {
        $this->assertSame(
            ['aguardando_aprovacao', 'em_revisao_ecf', 'ajustes_solicitados'],
            PubAprovacaoOferta::LIVE,
            '`live` = awaiting ∪ ecf_review ∪ changes é o único conjunto editável e aprovável'
        );

        foreach (PubAprovacaoOferta::LIVE as $vivo) {
            $oferta = new PubAprovacaoOferta(['andamento' => $vivo]);
            $this->assertTrue($oferta->ehLive(), "`{$vivo}` deveria ser live");
            $this->assertTrue($oferta->podeEditar(), "`{$vivo}` deveria ser editável");
            $this->assertTrue($oferta->abreRevisao(), "`{$vivo}` deveria abrir revisão editável");
        }

        // ISCA pelo complemento: os 5 que NÃO são live. Sem esta metade, um `ehLive()` que
        // devolvesse sempre `true` passaria.
        foreach (['preparando', 'aprovado', 'publicando', 'falha', 'publicado'] as $fora) {
            $oferta = new PubAprovacaoOferta(['andamento' => $fora]);
            $this->assertFalse($oferta->ehLive(), "`{$fora}` NÃO é live e está sendo tratado como tal");
            $this->assertFalse($oferta->podeEditar(), "`{$fora}` NÃO pode ser editado");
        }

        // ⚠️ PUB-04, dito por extenso: `publicando` é não editável.
        $this->assertFalse((new PubAprovacaoOferta(['andamento' => 'publicando']))->podeEditar());

        // `abreRevisao()` É `ehLive()` — a regra num lugar só (duplicada, as duas ficariam
        // livres para divergir, e a divergência liberaria edição de oferta já publicada).
        foreach (array_keys(PubAprovacaoOferta::ANDAMENTOS) as $chave) {
            $oferta = new PubAprovacaoOferta(['andamento' => $chave]);
            $this->assertSame($oferta->ehLive(), $oferta->abreRevisao(), "`abreRevisao()` divergiu de `ehLive()` em `{$chave}`");
        }
    }

    public function test_abre_revisao_e_falso_em_preparando_e_espelha_o_modulo_js(): void
    {
        // AND-03, literal: `preparando` **não abre revisão** (a oferta ainda está sendo
        // montada; "Aguarde").
        $this->assertFalse((new PubAprovacaoOferta(['andamento' => 'preparando']))->abreRevisao());

        // E o espelho byte a byte do `.jsx`: se um lado mudar, os dois divergem.
        $doJs = $this->entradasDoModuloJs();
        foreach ($doJs as $chave => $entrada) {
            $oferta = new PubAprovacaoOferta(['andamento' => $chave]);
            $this->assertSame($entrada['abreRevisao'], $oferta->abreRevisao(), "`abreRevisao` de `{$chave}` divergiu entre o PHP e o .jsx");
        }
    }

    // ─────────────────────────────────────────────────────────────────────────────────────────
    // AND-08 — `ORDEM`: 8 chaves, 7 valores, o empate em 1 e `preparando` por último
    // ─────────────────────────────────────────────────────────────────────────────────────────

    public function test_ordem_tem_sete_valores_com_o_empate_em_um_e_preparando_por_ultimo(): void
    {
        $ordem = PubAprovacaoOferta::ORDEM;

        $this->assertCount(8, $ordem, 'falta estado em ORDEM — estado sem ordem cai para o fim da fila sem ninguém ver');
        $this->assertSame([0, 1, 2, 3, 4, 5, 6], array_values(array_unique(array_values($ordem))), 'ORDEM deveria ter exatamente os 7 valores de `ORD` (0 a 6)');

        // ⚠️ O EMPATE É DE PROPÓSITO: o protótipo trata os dois como o mesmo degrau de
        // urgência ("ninguém terminou de conferir"). "Arrumar" o empate muda a ordem da fila
        // sem nenhum teste de render acusar.
        $this->assertSame(1, $ordem['aguardando_aprovacao']);
        $this->assertSame(1, $ordem['em_revisao_ecf']);

        $this->assertSame(0, $ordem['falha'], 'a falha vem primeiro: é o que precisa de "Tentar de novo"');
        $this->assertSame(6, $ordem['preparando'], '`preparando` é o ÚLTIMO: não há nada a fazer nele');
        $this->assertSame(6, max($ordem));

        // Espelho do `.jsx`: `ORDEM_DA_FILA` do módulo do plano 186-02, lido do TEXTO.
        $caminho = base_path(self::MODULO_JS);
        $texto = file_get_contents($caminho);
        $inicio = strpos($texto, 'export const ORDEM_DA_FILA = {');
        $this->assertNotFalse($inicio, 'ISCA CEGA: não achei `ORDEM_DA_FILA` no módulo .jsx.');
        $bloco = substr($texto, $inicio, strpos($texto, '};', $inicio) - $inicio);
        preg_match_all('/([a-z_]+): (\d+),/', $bloco, $m, PREG_SET_ORDER);

        $doJs = [];
        foreach ($m as $par) {
            $doJs[$par[1]] = (int) $par[2];
        }

        $this->assertCount(8, $doJs, 'ISCA CEGA: a sonda não leu 8 pares de ORDEM_DA_FILA no .jsx.');
        $this->assertSame($doJs, $ordem, 'ORDEM divergiu de `ORDEM_DA_FILA` do .jsx — a fila ordenaria diferente no servidor e no navegador');
    }

    // ─────────────────────────────────────────────────────────────────────────────────────────
    // AND-10 — `cta()`: o rótulo da ação por estado
    // ─────────────────────────────────────────────────────────────────────────────────────────

    public function test_cta_por_estado_e_espelhado_do_modulo_js(): void
    {
        $esperado = [
            'preparando' => 'Aguarde',
            'aguardando_aprovacao' => 'Revisar',
            'em_revisao_ecf' => 'Revisar',
            'ajustes_solicitados' => 'Revisar',
            'aprovado' => 'Ver',
            'publicando' => 'Ver',
            'falha' => 'Tentar de novo',
            'publicado' => 'Ver',
        ];

        foreach ($esperado as $chave => $rotulo) {
            $this->assertSame($rotulo, (new PubAprovacaoOferta(['andamento' => $chave]))->cta(), "o cta de `{$chave}` mudou");
        }

        // Espelho byte a byte do `.jsx` — o `cta` do protótipo (`:1240`) vive nos dois lados.
        $doJs = $this->entradasDoModuloJs();
        foreach ($doJs as $chave => $entrada) {
            $this->assertSame($entrada['cta'], (new PubAprovacaoOferta(['andamento' => $chave]))->cta(), "o cta de `{$chave}` divergiu entre o PHP e o .jsx");
        }

        // ISCA: os ctas NÃO são todos iguais. Sem isto, um `cta()` que devolvesse sempre
        // 'Revisar' passaria em 3 dos 8 e ninguém veria os outros 5.
        $this->assertCount(4, array_unique(array_values($esperado)), 'ISCA CEGA: os 4 ctas distintos viraram menos — a sonda compara valor constante.');
    }

    // ─────────────────────────────────────────────────────────────────────────────────────────
    // AND-04 — `podeSerPublicado()` exige `ml_item_id`
    // ─────────────────────────────────────────────────────────────────────────────────────────

    public function test_pode_ser_publicado_exige_ml_item_id(): void
    {
        $semMlb = new PubAprovacaoOferta(['andamento' => 'publicando']);
        $this->assertFalse($semMlb->podeSerPublicado(), 'chegou a `publicado` sem MLB: a tela diria "Publicado e confirmado no Mercado Livre" sem anúncio nenhum');

        $comMlb = new PubAprovacaoOferta(['andamento' => 'publicando', 'ml_item_id' => 'MLB1234567890']);
        $this->assertTrue($comMlb->podeSerPublicado(), 'com MLB confirmado, `publicado` tem de ser alcançável');

        // ISCA do "só espaço": string em branco não é MLB.
        $this->assertFalse((new PubAprovacaoOferta(['andamento' => 'publicando', 'ml_item_id' => '   ']))->podeSerPublicado());

        // E o estado também manda: com MLB mas fora de `publicando`, `publicado` não é
        // alcançável (é `TRANSICOES` quem diz, num lugar só).
        $this->assertFalse((new PubAprovacaoOferta(['andamento' => 'aprovado', 'ml_item_id' => 'MLB1234567890']))->podeSerPublicado());
    }

    // ─────────────────────────────────────────────────────────────────────────────────────────
    // REND-03 no PHP — chave adversa não lança
    // ─────────────────────────────────────────────────────────────────────────────────────────

    public function test_chave_adversa_em_qualquer_acessor_nao_lanca_e_devolve_null(): void
    {
        // ⚠️ Em PHP, `$MAPA[$chave]` com chave que não é string/int LANÇA `TypeError`, e com
        // string desconhecida emite warning e devolve null — o acessor tem de fazer a guarda
        // de tipo ANTES de indexar (REND-03, pelo motivo gêmeo do JS).
        $adversas = [null, 42, 3.14, true, false, [], '', '   ', 'toString', 'constructor', '__proto__', 'AWAITING', 'awaiting'];

        foreach ($adversas as $chave) {
            $this->assertNull(PubAprovacaoOferta::rotuloDe($chave), 'rotuloDe lançou ou inventou rótulo para chave adversa');
            $this->assertNull(PubAprovacaoOferta::ordemDe($chave), 'ordemDe lançou ou inventou ordem para chave adversa');
            $this->assertNull(PubAprovacaoOferta::ctaDe($chave), 'ctaDe lançou ou inventou cta para chave adversa');
        }

        // ⚠️ `awaiting` está na lista acima de propósito: a chave INGLESA do protótipo **não é
        // chave de dado** e tem de cair no desconhecido. Aceitar as duas formas num `||` é o
        // bug dos dois idiomas com outra roupa.
        $this->assertNull(PubAprovacaoOferta::rotuloDe('awaiting'));
        $this->assertNull(PubAprovacaoOferta::rotuloDe('ecf_review'));
        $this->assertNull(PubAprovacaoOferta::rotuloDe('changes'));

        // ISCA: a chave CONHECIDA tem de ser achada. Sem isto, um acessor que devolvesse
        // sempre `null` passaria em tudo acima.
        $this->assertSame('Preparando', PubAprovacaoOferta::rotuloDe('preparando'), 'ISCA CEGA: o acessor não acha nem a chave conhecida.');
        $this->assertSame(6, PubAprovacaoOferta::ordemDe('preparando'), 'ISCA CEGA: ordemDe não acha nem a chave conhecida.');
        $this->assertSame('Aguarde', PubAprovacaoOferta::ctaDe('preparando'), 'ISCA CEGA: ctaDe não acha nem a chave conhecida.');

        // E a oferta com andamento adverso no banco também não derruba nada.
        $this->assertFalse((new PubAprovacaoOferta(['andamento' => 'vindo_do_banco_errado']))->ehLive());
        $this->assertSame('Revisar', (new PubAprovacaoOferta(['andamento' => 'vindo_do_banco_errado']))->cta());
    }

    // ─────────────────────────────────────────────────────────────────────────────────────────
    // DADO-02 / critério 3 — OS DOIS EIXOS NÃO SE MISTURAM, NAS DUAS DIREÇÕES
    // ─────────────────────────────────────────────────────────────────────────────────────────

    public function test_o_andamento_interno_nao_se_mistura_com_o_estado_de_publicacao_nem_com_o_de_upload(): void
    {
        $andamento = array_keys(PubAprovacaoOferta::ANDAMENTOS);

        // `pub_rascunhos`: 6 estados DE PUBLICAÇÃO — eixo diferente.
        $publicacao = [
            PubRascunho::DRAFT, PubRascunho::VALIDATED, PubRascunho::PUBLISHING,
            PubRascunho::PUBLISHED, PubRascunho::PARTIALLY_PUBLISHED, PubRascunho::FAILED,
        ];

        // `pub_imagens.upload_status`: 3 estados DE UPLOAD — outra coisa ainda.
        $upload = [PubImagem::PENDENTE, PubImagem::ENVIADA, PubImagem::FALHOU];

        // ⚠️ DIREÇÃO 1 — nenhum estado de publicação/upload é andamento interno: *"o status do
        // ML nunca aparece na fila"*.
        foreach (array_merge($publicacao, $upload) as $outro) {
            $this->assertNotContains($outro, $andamento, "`{$outro}` é de OUTRO eixo e virou andamento interno");
        }

        // ⚠️ DIREÇÃO 2 — nenhum andamento interno é estado de publicação/upload: *"o andamento
        // interno nunca aparece na lista de Publicadas"*. Sem esta direção a asserção mediria
        // metade do invariante.
        foreach ($andamento as $nosso) {
            $this->assertNotContains($nosso, $publicacao, "`{$nosso}` é andamento interno e virou estado de PUBLICAÇÃO");
            $this->assertNotContains($nosso, $upload, "`{$nosso}` é andamento interno e virou estado de UPLOAD");
        }

        // ⚠️ A DISJUNÇÃO POR CONTAGEM, que é o que pega colisão nova: 8 + 6 + 3 = 17 literais
        // distintos. Se um dia alguém reusar um literal, o `array_unique` encolhe.
        $todos = array_merge($andamento, $publicacao, $upload);
        $this->assertCount(17, $todos);
        $this->assertCount(17, array_unique($todos), 'um literal é usado em DOIS eixos — é por aqui que o estado do ML aparece na fila');

        // ISCA: a sonda de colisão ACUSA quando há colisão de verdade.
        $this->assertCount(17, array_unique(array_merge($todos, ['publicado'])), 'ISCA CEGA: a sonda não vê repetição — `array_unique` não está medindo nada.');
        $this->assertNotContains('publicando', $publicacao, 'o nosso `publicando` é minúsculo e em pt-BR; o `PUBLISHING` do rascunho é outro eixo');
    }

    // ─────────────────────────────────────────────────────────────────────────────────────────
    // A decisão do usuário de 14/10 — APROVAR JÁ PUBLICA, e não há desfazer
    // ─────────────────────────────────────────────────────────────────────────────────────────

    public function test_nao_existe_estado_nem_metodo_de_desfazer_confirmar_ou_cancelar_aprovacao(): void
    {
        $texto = file_get_contents(base_path('app/Models/PubAprovacaoOferta.php'));
        $this->assertNotSame('', $texto, 'ISCA CEGA: o arquivo do modelo veio vazio.');

        // ⚠️ Lê o CÓDIGO sem comentários, pelo tokenizador do PHP: o docblock deste modelo
        // EXPLICA que "desfazer" não existe, e um gate que contasse a palavra no arquivo
        // inteiro reprovaria pelo PRÓPRIO CONSERTO
        // (`feedback_gate_que_reprova_pelo_proprio_conserto`).
        $codigo = $this->semComentarios($texto);

        foreach (['desfazer', 'confirmarPublicacao', 'confirmar_publicacao', 'cancelarAprovacao', 'cancelar_aprovacao', 'segundo_passo', 'aguardando_autorizacao'] as $proibido) {
            $this->assertStringNotContainsString($proibido, $codigo, "`{$proibido}` apareceu no CÓDIGO do modelo — a decisão do usuário de 14/10 recusou o segundo clique e o desfazer");
        }

        // Nenhum dos 8 estados é de confirmação/desfazer.
        $this->assertArrayNotHasKey('aguardando_confirmacao', PubAprovacaoOferta::ANDAMENTOS);
        $this->assertArrayNotHasKey('aprovacao_cancelada', PubAprovacaoOferta::ANDAMENTOS);

        // ISCA do tokenizador: a palavra em COMENTÁRIO desaparece e a em CÓDIGO sobrevive. Sem
        // esta isca, "não achei a palavra" poderia significar "o tokenizador comeu o arquivo".
        $soComentario = "<?php\n// Nao existe desfazer nem confirmarPublicacao aqui.\n\$x = 1;\n";
        $noCodigo = "<?php\nfunction desfazer() {}\n";
        $this->assertStringNotContainsString('desfazer', $this->semComentarios($soComentario), 'ISCA CEGA: `desfazer` do comentário sobreviveu — o gate reprovaria pelo próprio conserto.');
        $this->assertStringContainsString('$x = 1', $this->semComentarios($soComentario), 'ISCA: o tokenizador comeu o CÓDIGO junto com o comentário.');
        $this->assertStringContainsString('desfazer', $this->semComentarios($noCodigo), 'ISCA CEGA: a palavra em CÓDIGO tem de sobreviver, senão a sonda nunca acha nada.');

        // ⚠️ E o ponto de extensão que FICA: `aoAprovar()` é o gancho da Fase 193 — a prova de
        // que a ausência acima é decisão, e não esquecimento.
        $this->assertTrue(method_exists(PubAprovacaoOferta::class, 'aoAprovar') || method_exists(\App\Services\Publicador\Aprovacao\AndamentoDaOfertaService::class, 'aoAprovar'), 'o ponto de extensão `aoAprovar()` da Fase 193 desapareceu');
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // Task 3 — `AndamentoDaOfertaService`: A ÚNICA PORTA DE TRANSIÇÃO
    // ═════════════════════════════════════════════════════════════════════════════════════════

    public function test_mover_grava_a_transicao_e_devolve_a_oferta_nova(): void
    {
        [$classico] = $this->asDuasOfertas();
        $quem = User::factory()->create();

        $this->assertSame(PubAprovacaoOferta::PREPARANDO, $classico->andamento, 'a oferta nasce em `preparando` (o default da coluna)');

        $devolvida = $this->servico()->mover($classico, PubAprovacaoOferta::AGUARDANDO_APROVACAO, $quem, 'preparo concluído');

        // GRAVOU: a verdade é a RECONSULTA ao banco, nunca o objeto em memória.
        $this->assertSame(PubAprovacaoOferta::AGUARDANDO_APROVACAO, $classico->fresh()->andamento);

        // E DEVOLVEU a oferta nova, já com o estado novo — quem chama não precisa de `fresh()`.
        $this->assertInstanceOf(PubAprovacaoOferta::class, $devolvida);
        $this->assertSame(PubAprovacaoOferta::AGUARDANDO_APROVACAO, $devolvida->andamento);
        $this->assertSame($classico->id, $devolvida->id);

        // `entrou_na_fila_em` é a BASE DO TEMPO DE ESPERA (FILA-13) e é carimbada na entrada na
        // fila — sem ela o "há X" da tela não tem de onde sair.
        $this->assertEquals(Carbon::parse('2026-10-15 09:00:00'), $devolvida->entrou_na_fila_em);
    }

    public function test_transicao_fora_de_transicoes_lanca_regra_violada_e_nao_grava_nada(): void
    {
        [$classico] = $this->asDuasOfertas();

        $antes = $classico->fresh()->toArray();

        try {
            // ⚠️ `preparando -> aprovado` pula a conferência inteira. Como aprovar JÁ PUBLICA e
            // não há desfazer, isto é elevação de privilégio (T-186-11).
            $this->servico()->mover($classico, PubAprovacaoOferta::APROVADO);
            $this->fail('a transição ilegal `preparando -> aprovado` foi ACEITA');
        } catch (RegraViolada $e) {
            $this->assertSame(AndamentoDaOfertaService::REGRA_TRANSICAO_ILEGAL, $e->regra, 'a regra violada tem de ter código próprio, para a tela saber o que dizer');
            $this->assertStringContainsString('preparando', $e->getMessage());
            $this->assertStringContainsString('aprovado', $e->getMessage());
        }

        // NÃO GRAVOU NADA: nem o andamento, nem `aprovado_*`, nem `updated_at`.
        $depois = $classico->fresh()->toArray();
        $this->assertSame($antes, $depois, 'a transição recusada mexeu na linha — recusa tem de ser um no-op completo');
        $this->assertNull($classico->fresh()->aprovado_em);
        $this->assertNull($classico->fresh()->versao_aprovada);

        // ISCA: a transição LEGAL do mesmo ponto de partida passa. Sem isto, um `mover()` que
        // lançasse sempre passaria neste teste.
        $this->servico()->mover($classico, PubAprovacaoOferta::AGUARDANDO_APROVACAO);
        $this->assertSame(PubAprovacaoOferta::AGUARDANDO_APROVACAO, $classico->fresh()->andamento, 'ISCA CEGA: o serviço recusa até a transição legal.');
    }

    public function test_publicado_sem_ml_item_id_e_recusado_e_o_toast_nao_sai_deste_servico(): void
    {
        [$classico] = $this->asDuasOfertas();
        $quem = User::factory()->create();

        $oferta = $this->levarAte($classico, [
            PubAprovacaoOferta::AGUARDANDO_APROVACAO,
        ]);
        $oferta = $this->servico()->mover($oferta, PubAprovacaoOferta::APROVADO, $quem);
        $oferta = $this->servico()->mover($oferta, PubAprovacaoOferta::PUBLICANDO);

        try {
            $this->servico()->mover($oferta, PubAprovacaoOferta::PUBLICADO);
            $this->fail('chegou a `publicado` SEM `ml_item_id` — a tela diria "Publicado e confirmado no Mercado Livre" sem anúncio nenhum');
        } catch (RegraViolada $e) {
            $this->assertSame(AndamentoDaOfertaService::REGRA_SEM_MLB, $e->regra);
        }

        $this->assertSame(PubAprovacaoOferta::PUBLICANDO, $oferta->fresh()->andamento, 'a recusa deixou a oferta em outro estado');

        // ISCA: COM o MLB confirmado pelo ML, a mesma transição passa.
        $oferta->update(['ml_item_id' => 'MLB1234567890']);
        $this->servico()->mover($oferta->fresh(), PubAprovacaoOferta::PUBLICADO);
        $this->assertSame(PubAprovacaoOferta::PUBLICADO, $oferta->fresh()->andamento, 'ISCA CEGA: nem com MLB confirmado a transição passa.');

        // ⚠️ O TOAST "Publicado e confirmado" **não é disparado por este serviço**: quem avisa a
        // tela é a camada de apresentação, e o serviço não conhece toast. Lido do CÓDIGO sem
        // comentários, senão a própria frase deste parágrafo reprovaria o gate.
        $codigo = $this->semComentarios(file_get_contents(base_path('app/Services/Publicador/Aprovacao/AndamentoDaOfertaService.php')));
        $this->assertStringNotContainsString('Publicado e confirmado', $codigo);
        $this->assertStringNotContainsString('toast', strtolower($codigo));
    }

    public function test_falha_preserva_versao_aprovada_aprovado_por_e_aprovado_em(): void
    {
        [$classico] = $this->asDuasOfertas();
        $quem = User::factory()->create();

        $oferta = $this->servico()->mover($classico, PubAprovacaoOferta::AGUARDANDO_APROVACAO);
        $oferta = $this->servico()->mover($oferta, PubAprovacaoOferta::APROVADO, $quem);
        $oferta = $this->servico()->mover($oferta, PubAprovacaoOferta::PUBLICANDO);

        $aprovadoPor = $oferta->fresh()->aprovado_por;
        $aprovadoEm = $oferta->fresh()->aprovado_em;
        $versaoAprovada = $oferta->fresh()->versao_aprovada;

        $this->assertSame($quem->id, $aprovadoPor);
        $this->assertNotNull($aprovadoEm);
        $this->assertSame(1, $versaoAprovada);

        Carbon::setTestNow('2026-10-15 11:30:00');
        $oferta = $this->servico()->mover($oferta, PubAprovacaoOferta::FALHA, null, 'item.price: invalid price');

        // ⚠️ BLOQ-11 — a REVISÃO É PRESERVADA. Sem isto, quem clicar "Tentar de novo" teria de
        // reaprovar tudo, e a conferência que já foi feita viraria trabalho jogado fora.
        $viva = $oferta->fresh();
        $this->assertSame($aprovadoPor, $viva->aprovado_por, '`falha` apagou quem aprovou');
        $this->assertEquals($aprovadoEm, $viva->aprovado_em, '`falha` apagou quando foi aprovado');
        $this->assertSame($versaoAprovada, $viva->versao_aprovada, '`falha` apagou a versão aprovada — a aprovação passaria a parecer reaberta');
        $this->assertFalse($viva->aprovacaoReaberta(), '`falha` não reabre a aprovação');

        // E o erro REAL do ML fica gravado (PUB-05), não um "algo deu errado".
        $this->assertSame('item.price: invalid price', $viva->erro_publicacao);
    }

    public function test_falha_para_publicando_zera_erro_publicacao_e_nao_mexe_na_versao(): void
    {
        [$classico] = $this->asDuasOfertas();
        $quem = User::factory()->create();

        $oferta = $this->servico()->mover($classico, PubAprovacaoOferta::AGUARDANDO_APROVACAO);
        $oferta = $this->servico()->mover($oferta, PubAprovacaoOferta::APROVADO, $quem);
        $oferta = $this->servico()->mover($oferta, PubAprovacaoOferta::PUBLICANDO);
        $oferta = $this->servico()->mover($oferta, PubAprovacaoOferta::FALHA, null, 'item.price: invalid price');

        $versaoAntes = $oferta->fresh()->versao;
        $this->assertNotNull($oferta->fresh()->erro_publicacao);

        // "Tentar de novo" (AND-10).
        $oferta = $this->servico()->mover($oferta, PubAprovacaoOferta::PUBLICANDO);

        $viva = $oferta->fresh();
        $this->assertSame(PubAprovacaoOferta::PUBLICANDO, $viva->andamento);
        $this->assertNull($viva->erro_publicacao, 'o erro anterior ficou na tela de uma tentativa que já passou');

        // ⚠️ NÃO MEXE NA VERSÃO: "Tentar de novo" não é mudança relevante, e incrementar aqui
        // REABRIRIA uma aprovação que ninguém mexeu (`versao_aprovada < versao`).
        $this->assertSame($versaoAntes, $viva->versao, '"Tentar de novo" incrementou a versão e reabriu a aprovação sozinho');
        $this->assertFalse($viva->aprovacaoReaberta());
        $this->assertSame(1, $viva->versao_aprovada);
    }

    public function test_publicando_marca_a_oferta_como_nao_editavel(): void
    {
        [$classico] = $this->asDuasOfertas();
        $quem = User::factory()->create();

        $oferta = $this->servico()->mover($classico, PubAprovacaoOferta::AGUARDANDO_APROVACAO);
        $this->assertTrue($oferta->podeEditar(), 'em `aguardando_aprovacao` a oferta É editável');

        $oferta = $this->servico()->mover($oferta, PubAprovacaoOferta::APROVADO, $quem);
        $this->assertFalse($oferta->podeEditar(), 'em `aprovado` a oferta já não é editável');

        $oferta = $this->servico()->mover($oferta, PubAprovacaoOferta::PUBLICANDO);

        // ⚠️ PUB-04 — editar enquanto sobe ao ML mandaria ao Mercado Livre uma coisa e gravaria
        // outra aqui.
        $this->assertFalse($oferta->podeEditar());
        $this->assertFalse($oferta->fresh()->podeEditar());
        $this->assertFalse($oferta->abreRevisao());
    }

    public function test_ordenar_fila_por_ordem_e_dentro_do_grupo_maior_espera_primeiro(): void
    {
        [$a] = $this->asDuasOfertas('A');
        [$b] = $this->asDuasOfertas('B');
        [$c] = $this->asDuasOfertas('C');
        [$d] = $this->asDuasOfertas('D');

        // Dois no MESMO degrau de urgência (ORDEM 1), com esperas diferentes.
        $a->update(['andamento' => PubAprovacaoOferta::AGUARDANDO_APROVACAO, 'entrou_na_fila_em' => '2026-10-15 08:00:00']);
        $b->update(['andamento' => PubAprovacaoOferta::EM_REVISAO_ECF, 'entrou_na_fila_em' => '2026-10-13 08:00:00']);
        // Um na frente de todos (ORDEM 0) e um no fim (ORDEM 6).
        $c->update(['andamento' => PubAprovacaoOferta::FALHA, 'entrou_na_fila_em' => '2026-10-15 08:59:00']);
        $d->update(['andamento' => PubAprovacaoOferta::PREPARANDO, 'entrou_na_fila_em' => '2026-10-01 08:00:00']);

        $ordenada = $this->servico()->ordenarFila(PubAprovacaoOferta::whereIn('id', [$a->id, $b->id, $c->id, $d->id])->get());

        $this->assertSame(
            [$c->id, $b->id, $a->id, $d->id],
            $ordenada->pluck('id')->all(),
            'a fila não ordenou por ORDEM e, dentro do grupo, pela maior espera'
        );

        // ⚠️ O EMPATE IMPORTA: `b` (em_revisao_ecf) vem antes de `a` (aguardando_aprovacao) NÃO
        // por estado, mas porque espera 2 dias contra 1 hora. Os dois estão no degrau 1.
        $this->assertSame(PubAprovacaoOferta::ORDEM[$a->andamento], PubAprovacaoOferta::ORDEM[$b->andamento], 'ISCA: os dois deveriam estar no MESMO degrau');

        // ⚠️ E `d` (preparando, esperando desde 01/10 — a MAIOR espera de todas) vem por ÚLTIMO:
        // a espera só desempata DENTRO do degrau, nunca atravessa degraus.
        $this->assertSame($d->id, $ordenada->last()->id);

        // Oferta sem `entrou_na_fila_em` vai para o fim DO SEU degrau (espera desconhecida não
        // vira espera infinita).
        $e = $this->asDuasOfertas('E')[0];
        $e->update(['andamento' => PubAprovacaoOferta::AGUARDANDO_APROVACAO, 'entrou_na_fila_em' => null]);
        $comNulo = $this->servico()->ordenarFila(PubAprovacaoOferta::whereIn('id', [$a->id, $e->id])->get());
        $this->assertSame([$a->id, $e->id], $comNulo->pluck('id')->all());
    }

    public function test_faixa_de_andamento_tem_nove_recortes_e_o_ultimo_e_recorte_do_dia(): void
    {
        $empresa = Company::factory()->create();

        [$hoje] = $this->asDuasOfertas('HOJE', $empresa);
        [$ontem] = $this->asDuasOfertas('ONTEM', $empresa);
        [$esperando] = $this->asDuasOfertas('ESP', $empresa);

        $hoje->update(['andamento' => PubAprovacaoOferta::PUBLICADO, 'updated_at' => '2026-10-15 07:10:00']);
        $ontem->update(['andamento' => PubAprovacaoOferta::PUBLICADO, 'updated_at' => '2026-10-14 23:59:00']);
        $esperando->update(['andamento' => PubAprovacaoOferta::AGUARDANDO_APROVACAO]);

        $faixa = $this->servico()->faixaDeAndamento($this->servico()->filaDaConta($empresa->id));

        // AND-09 — **9** recortes: Todas + as 7 etapas internas + "Publicado hoje".
        $this->assertCount(9, $faixa);
        $this->assertSame(
            ['todas', 'preparando', 'aguardando_aprovacao', 'em_revisao_ecf', 'ajustes_solicitados', 'aprovado', 'publicando', 'falha', 'publicado'],
            array_column($faixa, 'chave')
        );

        // ⚠️ O ÚLTIMO RÓTULO É "Publicado hoje", e **não** "Publicado": trocar pelo rótulo do
        // estado faria a faixa PROMETER o histórico completo e ENTREGAR o do dia.
        $this->assertSame('Publicado hoje', $faixa[8]['rotulo']);
        $this->assertNotSame(PubAprovacaoOferta::ANDAMENTOS[PubAprovacaoOferta::PUBLICADO], $faixa[8]['rotulo']);

        $porChave = array_column($faixa, 'total', 'chave');

        // ⚠️ O RECORTE É **DO DIA**: a publicada ONTEM não conta, a de HOJE conta. Esta é a
        // asserção que distingue "recorte do dia" de "estado inteiro" — sem a de ontem
        // PREENCHIDA, as duas leituras dariam o mesmo número e o gate seria vazio.
        $this->assertSame(1, $porChave['publicado'], '"Publicado hoje" contou a de ontem — virou o estado inteiro');
        $this->assertSame(1, $porChave['aguardando_aprovacao']);

        // As 3 ofertas PREMIUM nunca foram movidas e seguem no default da coluna.
        $this->assertSame(3, $porChave['preparando']);

        // "Todas" é SEM FILTRO: as 6 ofertas da empresa (3 produtos × Clássico e Premium). Ela é
        // MAIOR que a soma dos 8 recortes de estado (3 + 1 + 1 = 5) exatamente porque o último é
        // recorte DO DIA e deixa a publicada de ontem fora — e é essa diferença que prova que o
        // recorte não é o estado inteiro.
        $this->assertSame(6, $porChave['todas']);
        $this->assertSame(5, array_sum(array_diff_key($porChave, ['todas' => null])));
    }

    public function test_aprovar_grava_os_tres_campos_de_aprovacao_na_mesma_transacao(): void
    {
        [$classico] = $this->asDuasOfertas();
        $quem = User::factory()->create();

        $oferta = $this->servico()->mover($classico, PubAprovacaoOferta::AGUARDANDO_APROVACAO);
        $oferta->update(['versao' => 4]);

        $oferta = $this->servico()->mover($oferta->fresh(), PubAprovacaoOferta::APROVADO, $quem);

        // ⚠️ BLOQ-05 — os três juntos. Aprovação sem versão rastreável é repúdio (T-186-13): não
        // se sabe O QUE foi aprovado.
        $viva = $oferta->fresh();
        $this->assertSame(PubAprovacaoOferta::APROVADO, $viva->andamento);
        $this->assertSame($quem->id, $viva->aprovado_por);
        $this->assertEquals(Carbon::parse('2026-10-15 09:00:00'), $viva->aprovado_em);
        $this->assertSame(4, $viva->versao_aprovada, '`versao_aprovada` tem de ser a versão VIGENTE no momento do clique');
        $this->assertFalse($viva->aprovacaoReaberta());

        // E a versão em si não muda ao aprovar (aprovar não é mudança relevante).
        $this->assertSame(4, $viva->versao);

        // ⚠️ UMA transação só, e isso é estrutural: como **não há desfazer**, falhar no meio
        // deixaria oferta aprovada sem prova do que foi aprovado. Lido do CÓDIGO sem
        // comentários, para o gate não casar com a frase que explica a regra.
        $codigo = $this->semComentarios(file_get_contents(base_path('app/Services/Publicador/Aprovacao/AndamentoDaOfertaService.php')));
        $this->assertSame(1, substr_count($codigo, 'DB::transaction('), 'a transição tem de acontecer dentro de UMA `DB::transaction` só');
    }

    public function test_o_servico_e_a_unica_porta_e_expoe_os_pontos_de_extensao_declarados(): void
    {
        $caminho = base_path('app/Services/Publicador/Aprovacao/AndamentoDaOfertaService.php');
        $this->assertFileExists($caminho);

        // Os dois pontos de extensão NOMEADOS, para ninguém achar que o histórico e a Fase 193
        // ficaram de fora.
        $this->assertTrue(method_exists(AndamentoDaOfertaService::class, 'registrar'), 'o ponto de extensão do histórico (plano 186-05) desapareceu');
        $this->assertTrue(method_exists(AndamentoDaOfertaService::class, 'aoAprovar'), 'o ponto de extensão da Fase 193 desapareceu');

        $codigo = $this->semComentarios(file_get_contents($caminho));

        // O prefixo de módulo do log (convenção do projeto) e o rastreio por id + nome.
        $this->assertStringContainsString('[Publicador Aprovação]', file_get_contents($caminho), 'o log perdeu o prefixo de módulo');

        // ⚠️ `RegraViolada` é USADA, nunca alterada (TERR-03 proíbe mexer em
        // `App\Support\Publicador`), e o código da regra mora em constante DESTE serviço.
        $this->assertStringContainsString('App\\Support\\Publicador\\RegraViolada', $codigo);
        $this->assertNotSame('', AndamentoDaOfertaService::REGRA_TRANSICAO_ILEGAL);
        $this->assertNotSame('', AndamentoDaOfertaService::REGRA_SEM_MLB);
        $this->assertNotSame(AndamentoDaOfertaService::REGRA_TRANSICAO_ILEGAL, AndamentoDaOfertaService::REGRA_SEM_MLB, 'os dois códigos de regra são iguais — a tela não distinguiria os dois casos');

        // ⚠️ E O INVARIANTE DA ÚNICA PORTA, medido aqui no que é MEU: o único lugar que escreve
        // `andamento` é este serviço. O gate amplo (com isca) é do plano 186-09; esta é a parte
        // que já pode ser medida.
        //
        // ⚠️⚠️ A SONDA É AMPLA DE PROPÓSITO (`'andamento' =>` **ou** `->andamento =`), e NÃO foi
        // afrouxada quando reprovou. Ela reprovou de verdade em 14/10/2026 e por um motivo
        // legítimo: o conserto do default em memória acrescentou `'andamento' => self::PREPARANDO`
        // no `$attributes` do modelo — que é DECLARAÇÃO DE DEFAULT, não escrita de estado. Estreitar
        // a sonda para `->update([...])` seria pior: o serviço monta as mudanças numa VARIÁVEL
        // (`$mudancas`), então a forma estreita não casaria nem a escrita de verdade e o gate
        // ficaria vazio por construção. ➡️ A sonda ficou ampla e o que entrou foi um CONTRATO
        // EXATO: o modelo tem direito a UMA ocorrência, e ela tem de estar DENTRO do
        // `$attributes`.
        $escrevem = [];
        $arquivos = array_merge(
            glob(base_path('app/Services/Publicador/Aprovacao/*.php')) ?: [],
            glob(base_path('app/Models/PubAprovacaoOferta.php')) ?: [],
        );
        foreach ($arquivos as $arquivo) {
            $texto = $this->semComentarios(file_get_contents($arquivo));
            if (preg_match("/'andamento'\s*=>|->andamento\s*=[^=]/", $texto)) {
                $escrevem[] = basename($arquivo);
            }
        }
        sort($escrevem);
        $this->assertSame(['AndamentoDaOfertaService.php', 'PubAprovacaoOferta.php'], $escrevem, 'alguém além do serviço e do default do modelo escreve `andamento` — a porta deixou de ser única');

        // ISCA da sonda de escrita: o padrão que ela procura EXISTE no serviço (senão a lista
        // acima estaria vazia por construção e a asserção passaria sem medir).
        $this->assertSame(1, preg_match_all("/'andamento'\s*=>/", $codigo), 'ISCA CEGA: a sonda não acha a escrita nem no serviço que a faz — ou o serviço escreve `andamento` em mais de um lugar.');

        // ⚠️ O CONTRATO EXATO DO MODELO: UMA ocorrência, e DENTRO do `$attributes`.
        $codigoModelo = $this->semComentarios(file_get_contents(base_path('app/Models/PubAprovacaoOferta.php')));
        $this->assertSame(1, preg_match_all("/'andamento'\s*=>/", $codigoModelo), 'o modelo passou a mencionar `andamento` como chave em mais de um lugar');
        $this->assertSame(0, preg_match_all('/->andamento\s*=[^=]/', $codigoModelo), 'o modelo atribui `andamento` — só o serviço move estado');

        $inicio = strpos($codigoModelo, 'protected $attributes = [');
        $this->assertNotFalse($inicio, 'ISCA CEGA: não achei o bloco `$attributes` no modelo.');
        $bloco = substr($codigoModelo, $inicio, strpos($codigoModelo, '];', $inicio) - $inicio);
        $this->assertStringContainsString("'andamento' => self::PREPARANDO", $bloco, 'a única ocorrência de `andamento` no modelo tem de ser o default do `$attributes`');

        // ISCA do recorte: FORA do bloco não sobra ocorrência nenhuma. Sem esta asserção, o
        // recorte poderia estar pegando o arquivo inteiro e "dentro do bloco" não significaria
        // nada.
        $this->assertSame(0, preg_match_all("/'andamento'\s*=>/", str_replace($bloco, '', $codigoModelo)), 'ISCA CEGA: há ocorrência de `andamento` FORA do `$attributes` — ou o recorte do bloco está pegando o arquivo todo.');
    }

    /**
     * O CÓDIGO sem os comentários, pelo tokenizador do PHP.
     *
     * ⚠️ Molde de `MigrationDoPortaoGuardaTest::semComentarios` (plano 186-03), onde a isca
     * pegou o caso de verdade. As quebras de linha do comentário são preservadas para não
     * colar dois statements.
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
}
