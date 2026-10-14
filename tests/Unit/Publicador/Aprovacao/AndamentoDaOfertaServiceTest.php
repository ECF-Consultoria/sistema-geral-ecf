<?php

namespace Tests\Unit\Publicador\Aprovacao;

use App\Models\PubAprovacaoOferta;
use App\Models\PubImagem;
use App\Models\PubRascunho;
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
    /** O módulo de vocabulário do plano 186-02 — SOMENTE LEITURA para este plano. */
    private const MODULO_JS = 'resources/js/Components/Mlb/Publicador/aprovacao/andamentoDoPortao.jsx';

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
