<?php

namespace Tests\Feature\Publicador\Aprovacao;

use App\Models\Company;
use App\Models\PubAprovacaoAjuste;
use App\Models\PubAprovacaoEvento;
use App\Models\PubAprovacaoExcecao;
use App\Models\PubAprovacaoOferta;
use App\Models\PubProduto;
use App\Models\PubRascunho;
use App\Models\PubRascunhoAlvo;
use App\Models\User;
use App\Services\Publicador\Aprovacao\HistoricoDaOfertaService;
use App\Support\Publicador\RegraViolada;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 * Fase 186, plano 05 — OS 3 MODELOS, O HISTÓRICO E **A EXCEÇÃO AUTORIZADA**.
 *
 * ⚠️⚠️ DE CADA GATE DESTE ARQUIVO, A PERGUNTA RESPONDIDA ANTES DE ELE EXISTIR:
 * **"o que precisaria estar errado no mundo para ele falhar?"**
 *
 *   · **os 8 tipos de evento** — alguém acrescentar um nono tipo sem rótulo, ou deixar um tipo só
 *     com cor/ícone (VOC-05). A linha do tempo mostraria um pontinho colorido que ninguém sabe ler,
 *     e o histórico é a ÚNICA prova da auditoria-depois.
 *   · **autor "Sistema"** — `autor_id` nulo virar nome VAZIO na tela, ou alguém criar um usuário
 *     de banco chamado "Sistema" (COL-05). Nos dois casos a linha do histórico deixa de dizer
 *     quem fez — e é por isso que o registro existe.
 *   · **tipo fora dos 8** — gravar `tipo` livre. O filtro por tipo passaria a mentir, e um evento
 *     de exceção poderia entrar como `ok`.
 *   · **a data** — `ocorrido_em` vir do CLIENTE (COL-05) ou sair CRUA para a tela (COL-06).
 *     `Invalid Date` e o deslocamento de fuso de data-only em BRT já morderam este projeto.
 *   · **`escopoValido` / âncora** — ajuste de escopo `set` sem conjunto, ou escopo `prod` do
 *     protótipo sendo aceito ao lado de `product`. Ajuste com âncora errada **não entra na
 *     consulta de bloqueio** e a oferta fica aprovável com ajuste em aberto.
 *   · **`MINIMO_JUSTIFICATIVA` / `LIBERA`** — exceção sem motivo escrito (T-186-16, repúdio) ou
 *     exceção liberando bloqueio que ela não deve liberar (T-186-17, elevação de privilégio).
 *     ⚠️ **Aprovar JÁ PUBLICA e não há desfazer**: exceção larga demais publica prejuízo.
 *   · **o estado de espera** — alguém acrescentar um segundo passo na exceção, que foi
 *     explicitamente RECUSADO pelo usuário em 14/10/2026 (BLOQ-09).
 *
 * ⚠️ TODA SONDA TEM **ISCA**, e as iscas aqui são de três tipos: (1) de CAMINHO (arquivo lido tem
 * de existir, e um caminho inventado tem de NÃO existir); (2) de REGEX (um caso que DEVE casar e
 * um que NÃO deve); (3) de CONTEÚDO (a asserção negativa roda com a fixture PREENCHIDA — GATE-13).
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 */
class ExcecaoAutorizadaTest extends TestCase
{
    use RefreshDatabase;

    /** Clássico e Premium — `EstruturaPublicacao::LISTING_TYPES`. */
    private const CLASSICO = 'gold_special';

    private const PREMIUM = 'gold_pro';

    /** O protótipo, fonte da FORMA e dos RÓTULOS (`HK` em `:1386`, `EMP.SYS` em `:915`). */
    private const PROTOTIPO = 'design_handoff_publicador/Aprovacao.dc.html';

    /** O módulo pt-BR do plano 186-02, de onde as chaves de BLOQUEIO são ESPELHADAS. */
    private const VOCABULARIO = 'resources/js/Components/Mlb/Publicador/aprovacao/vocabularioDoPortao.jsx';

    /**
     * ⚠️ ISCA DE CAMINHO. 5 caminhos fantasma em 4 fases deste projeto: arquivo que não existe
     * faria TODA sonda de texto devolver "nenhuma violação" e o gate ficaria verde medindo NADA.
     */
    private const CAMINHO_FANTASMA = 'design_handoff_publicador/Aprovacao.NAO-EXISTE.html';

    protected function setUp(): void
    {
        parent::setUp();
        // REND-08 — tudo que grava data é ancorado. Um caso com `subHours(9)` já falhava entre
        // 00:00 e 09:00 e passava depois, neste mesmo projeto.
        Carbon::setTestNow('2026-10-15 14:30:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ─────────────────────────────────────────────────────────────────────────────────────────
    // Fixture
    // ─────────────────────────────────────────────────────────────────────────────────────────

    /** @return array{0: PubAprovacaoOferta, 1: PubAprovacaoOferta} [Clássico, Premium] */
    private function asDuasOfertas(?Company $empresa = null, string $sku = 'CAD-01'): array
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

    private function historico(): HistoricoDaOfertaService
    {
        return app(HistoricoDaOfertaService::class);
    }

    private function texto(string $caminho): string
    {
        $abs = base_path($caminho);
        $this->assertFileExists($abs, "arquivo declarado e ausente no disco ({$caminho}) — toda sonda de texto mediria NADA");
        $this->assertFileDoesNotExist(base_path(self::CAMINHO_FANTASMA), 'ISCA DE CAMINHO: `file_exists` passou a devolver `true` para caminho inventado');

        return file_get_contents($abs);
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // DADO-10 / VOC-05 — OS 8 TIPOS DE EVENTO
    // ═════════════════════════════════════════════════════════════════════════════════════════

    public function test_os_oito_tipos_de_evento_tem_icone_e_rotulo_pt_br_e_espelham_o_hk_do_prototipo(): void
    {
        // (1) São exatamente 8 — a contagem travada, para um nono tipo sem rótulo reprovar aqui.
        $this->assertCount(8, PubAprovacaoEvento::TIPOS);

        // (2) ⚠️ VOC-05 — **nunca só cor/ícone**. O protótipo tem SÓ cor e glifo (`HK`); o rótulo
        // pt-BR é o que este plano acrescenta, e é o que torna a linha do tempo legível.
        foreach (PubAprovacaoEvento::TIPOS as $chave => $entrada) {
            $this->assertArrayHasKey('icone', $entrada, "o tipo `{$chave}` está sem ícone");
            $this->assertArrayHasKey('rotulo', $entrada, "o tipo `{$chave}` está sem rótulo — seria um pontinho colorido que ninguém sabe ler (VOC-05)");
            $this->assertArrayHasKey('cor', $entrada, "o tipo `{$chave}` está sem cor");
            $this->assertNotSame('', trim((string) $entrada['rotulo']), "o rótulo de `{$chave}` está vazio");
        }

        // (3) ⚠️ ESPELHO DO PROTÓTIPO, LENDO O ARQUIVO — não cópia à mão. `HK` em `:1386`.
        $hk = $this->hkDoPrototipo();

        // ISCA DA SONDA: ela TEM de achar os 8 pares. Zero achados faria o laço abaixo rodar
        // sobre lista vazia — verde, medindo nada (foi assim que `->foreignI` não achou FK
        // nenhuma na wave 1).
        $this->assertCount(8, $hk, 'ISCA CEGA: a sonda não achou os 8 pares de `HK` no protótipo.');
        $this->assertSame('§', $hk['exc'][1] ?? null, 'ISCA: o glifo `§` de `HK.exc` é o da frase "§ Exceção autorizada por…"');

        // Bijeção nas DUAS direções: tipo só no PHP e tipo só no protótipo.
        $this->assertSame(array_keys($hk), array_keys(PubAprovacaoEvento::TIPOS), 'as 8 chaves (e a ORDEM) têm de ser as do `HK` do protótipo');

        foreach ($hk as $chave => [$cor, $glifo]) {
            $this->assertSame($cor, PubAprovacaoEvento::TIPOS[$chave]['cor'], "a cor de `{$chave}` divergiu do protótipo");
            $this->assertSame($glifo, PubAprovacaoEvento::TIPOS[$chave]['icone'], "o ícone de `{$chave}` divergiu do protótipo");
        }

        // (4) As 8 constantes nomeadas existem e apontam para as 8 chaves — ninguém escreve o
        // literal à mão no serviço.
        $constantes = [
            PubAprovacaoEvento::SISTEMA, PubAprovacaoEvento::APROVACAO, PubAprovacaoEvento::ALTERACAO,
            PubAprovacaoEvento::AJUSTE, PubAprovacaoEvento::REPROVACAO, PubAprovacaoEvento::ERRO,
            PubAprovacaoEvento::EXCECAO, PubAprovacaoEvento::REVISAO_PARCIAL,
        ];
        $this->assertSame([], array_diff($constantes, array_keys(PubAprovacaoEvento::TIPOS)));
        $this->assertCount(8, array_unique($constantes));
    }

    /**
     * O `HK` do protótipo (`Aprovacao.dc.html:1386`), lido do arquivo.
     *
     * @return array<string, array{0: string, 1: string}> chave => [cor, glifo]
     */
    private function hkDoPrototipo(): array
    {
        $texto = $this->texto(self::PROTOTIPO);

        if (! preg_match('/const HK = \{(.+?)\};/u', $texto, $bloco)) {
            $this->fail('ISCA CEGA: não achei o bloco `const HK = {…}` no protótipo.');
        }

        // ⚠️ ISCA DO REGEX, nos dois sentidos — a armadilha de `->foreignId?\(` casando
        // `foreignI` e não `foreign(` custou uma sonda cega inteira na wave 1.
        $regex = "/(\w+):\s*\['(#[0-9A-Fa-f]{6})',\s*'(.)'\]/u";
        preg_match_all($regex, 'ok: [\'#22C55E\', \'✓\']', $certo, PREG_SET_ORDER);
        $this->assertCount(1, $certo, 'ISCA CEGA: o regex do `HK` não casa nem o caso canônico.');
        preg_match_all($regex, "ok: ['#22C55E']", $errado, PREG_SET_ORDER);
        $this->assertCount(0, $errado, 'ISCA: o regex casou um par SEM glifo — mediria a coisa errada.');

        preg_match_all($regex, $bloco[1], $m, PREG_SET_ORDER);

        $hk = [];
        foreach ($m as $par) {
            $hk[$par[1]] = [strtoupper($par[2]), $par[3]];
        }

        return $hk;
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // COL-05 / COL-06 — O HISTÓRICO: AUTOR E DATA REAIS
    // ═════════════════════════════════════════════════════════════════════════════════════════

    public function test_registrar_sem_autor_grava_ator_sistema_e_o_autor_exibido_e_sistema(): void
    {
        [$classico] = $this->asDuasOfertas();

        $evento = $this->historico()->registrar($classico, null, PubAprovacaoEvento::SISTEMA, 'Anúncio preparado automaticamente a partir do cadastro completo', null);

        // Reconsulta ao banco: a verdade é a linha, não o objeto em memória.
        $naLinha = PubAprovacaoEvento::findOrFail($evento->id);
        $this->assertNull($naLinha->autor_id, 'COL-05: o Sistema NÃO é um usuário de banco');
        $this->assertTrue($naLinha->ator_sistema, '`autor_id` nulo sozinho é ambíguo (SET NULL também deixa nulo) — `ator_sistema` é quem desambigua');

        // ⚠️ O autor exibido nunca é vazio. Nome em branco na linha do tempo é exatamente o
        // buraco que "auditoria depois" não pode ter.
        $this->assertSame('Sistema', $naLinha->autorExibido());
        $this->assertSame('Sistema', PubAprovacaoEvento::AUTOR_SISTEMA);

        // ⚠️ ISCA (GATE-13): com autor DE VERDADE, o nome é o dele e `ator_sistema` é falso —
        // senão um `autorExibido()` que devolvesse sempre "Sistema" passaria acima.
        $quem = User::factory()->create(['name' => 'Ana Ribeiro']);
        $comAutor = $this->historico()->registrar($classico, $quem, PubAprovacaoEvento::APROVACAO, 'Aprovou as 6 fotos do conjunto (compartilhado com Premium)', 1);
        $comAutorNaLinha = PubAprovacaoEvento::findOrFail($comAutor->id);
        $this->assertSame($quem->id, $comAutorNaLinha->autor_id);
        $this->assertFalse($comAutorNaLinha->ator_sistema);
        $this->assertSame('Ana Ribeiro', $comAutorNaLinha->autorExibido());

        // ⚠️ COL-05: `ocorrido_em` é do SERVIDOR (`now()`), nunca data vinda do cliente.
        $this->assertSame('2026-10-15 14:30:00', $naLinha->ocorrido_em->format('Y-m-d H:i:s'));
    }

    public function test_registrar_com_tipo_fora_dos_oito_lanca_e_nao_grava(): void
    {
        [$classico] = $this->asDuasOfertas();

        // ISCA: um tipo VÁLIDO grava — senão "não gravou" seria verdade pelo motivo errado.
        $this->historico()->registrar($classico, null, PubAprovacaoEvento::SISTEMA, 'Publicação iniciada no Mercado Livre', null);
        $this->assertSame(1, PubAprovacaoEvento::where('oferta_id', $classico->id)->count());

        $pegou = null;
        try {
            $this->historico()->registrar($classico, null, 'aprovadoNaMarra', 'tipo inventado', null);
        } catch (RegraViolada $e) {
            $pegou = $e;
        }

        $this->assertNotNull($pegou, 'tipo fora dos 8 tem de LANÇAR — `tipo` livre faria o filtro por tipo mentir');
        $this->assertSame(HistoricoDaOfertaService::REGRA_TIPO_DESCONHECIDO, $pegou->regra);

        // E **não gravou nada**: a contagem é a de antes.
        $this->assertSame(1, PubAprovacaoEvento::where('oferta_id', $classico->id)->count(), 'a recusa gravou linha — "recusei" e "gravei" se confundiriam');
    }

    public function test_linha_do_tempo_vem_em_ordem_e_com_a_data_formatada_pelo_servidor(): void
    {
        [$classico] = $this->asDuasOfertas();

        // Gravados FORA DE ORDEM de propósito: o terceiro a ser escrito é o mais ANTIGO.
        Carbon::setTestNow('2026-10-15 10:00:00');
        $this->historico()->registrar($classico, null, PubAprovacaoEvento::SISTEMA, 'segundo no tempo', 1);
        Carbon::setTestNow('2026-10-15 11:00:00');
        $this->historico()->registrar($classico, null, PubAprovacaoEvento::ALTERACAO, 'terceiro no tempo', 2);
        Carbon::setTestNow('2026-10-15 09:00:00');
        $this->historico()->registrar($classico, null, PubAprovacaoEvento::SISTEMA, 'primeiro no tempo', 1);
        Carbon::setTestNow('2026-10-15 14:30:00');

        // ⚠️ ISCA (GATE-13): o histórico de OUTRA oferta está PREENCHIDO. Sem ele, "a linha do
        // tempo tem 3" e "a consulta não filtra por oferta" dariam o mesmo número.
        [$outro] = $this->asDuasOfertas(null, 'OUTRA-01');
        $this->historico()->registrar($outro, null, PubAprovacaoEvento::SISTEMA, 'evento de outra oferta', 1);

        $linha = $this->historico()->linhaDoTempo($classico);

        $this->assertCount(3, $linha, 'a linha do tempo vazou (ou perdeu) eventos de outra oferta');
        $this->assertSame(['primeiro no tempo', 'segundo no tempo', 'terceiro no tempo'], array_column($linha, 'texto'), 'a ordem é por `ocorrido_em`, não por `id`');

        // ⚠️⚠️ COL-06 — A DATA SAI **FORMATADA DO SERVIDOR**. Nenhum valor devolvido pode ser um
        // objeto de data: `new Date()` no cliente já produziu `Invalid Date` e deslocamento de
        // fuso de data-only em BRT neste projeto.
        foreach ($linha as $item) {
            foreach ($item as $campo => $valor) {
                $this->assertFalse($valor instanceof \DateTimeInterface, "COL-06: o campo `{$campo}` saiu como objeto de data — data crua para o cliente");
                $this->assertTrue(is_scalar($valor) || $valor === null, "COL-06: o campo `{$campo}` não é escalar");
            }
        }

        $this->assertSame('15/10/2026 09:00', $linha[0]['ocorrido_formatado']);
        $this->assertSame('Sistema', $linha[0]['autor']);
        $this->assertSame('↻', $linha[2]['icone']);
        $this->assertSame('Alteração', $linha[2]['rotulo']);
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // DADO-09 — O ESCOPO DO AJUSTE, E A ÂNCORA COERENTE
    // ═════════════════════════════════════════════════════════════════════════════════════════

    public function test_escopo_valido_recusa_escopo_desconhecido_e_ancora_errada(): void
    {
        // (1) São 3 escopos, e `product` é a chave — não `prod`.
        $this->assertSame(['offer', 'set', 'product'], PubAprovacaoAjuste::ESCOPOS);
        $this->assertTrue(PubAprovacaoAjuste::escopoValido('offer'));
        $this->assertTrue(PubAprovacaoAjuste::escopoValido('set'));
        $this->assertTrue(PubAprovacaoAjuste::escopoValido('product'));

        // ⚠️ `prod` do protótipo (`Aprovacao.dc.html:1086`, `:1187`) é **RECUSADO**: aceitar as
        // duas formas seria o bug dos dois idiomas com outra roupa — duas chaves para a mesma
        // verdade, livres para divergir.
        $this->assertFalse(PubAprovacaoAjuste::escopoValido('prod'), 'DADO-09: `prod` é a chave do PROTÓTIPO e não pode ser aceita ao lado de `product`');

        // REND-03 no PHP: chave adversa não lança e devolve `false`.
        foreach ([null, '', '  ', 0, 1, [], 3.5, true, 'OFFER', 'produto'] as $adversa) {
            $this->assertFalse(PubAprovacaoAjuste::escopoValido($adversa), 'chave adversa foi aceita como escopo');
        }

        // (2) A âncora de CADA escopo, e a linha INCOERENTE recusada.
        $this->assertSame(['offer' => 'oferta_id', 'set' => 'conjunto_id', 'product' => 'produto_id'], PubAprovacaoAjuste::ANCORA_DO_ESCOPO);

        [$classico] = $this->asDuasOfertas();

        // ISCA (GATE-13): a linha COERENTE passa. Sem ela, um `ancoraValida()` que devolvesse
        // sempre `false` passaria em todas as negativas abaixo.
        $coerente = new PubAprovacaoAjuste(['escopo' => 'offer', 'oferta_id' => $classico->id]);
        $this->assertTrue($coerente->ancoraValida());

        // escopo `set` SEM `conjunto_id`
        $this->assertFalse((new PubAprovacaoAjuste(['escopo' => 'set', 'oferta_id' => $classico->id]))->ancoraValida(), 'ajuste de escopo `set` sem conjunto não entra na consulta de bloqueio — a oferta ficaria aprovável com ajuste em aberto');
        // escopo `product` SEM `produto_id`
        $this->assertFalse((new PubAprovacaoAjuste(['escopo' => 'product', 'conjunto_id' => 7]))->ancoraValida());
        // escopo `offer` SEM `oferta_id`
        $this->assertFalse((new PubAprovacaoAjuste(['escopo' => 'offer', 'produto_id' => 9]))->ancoraValida());
        // escopo desconhecido nunca é coerente
        $this->assertFalse((new PubAprovacaoAjuste(['escopo' => 'prod', 'produto_id' => 9]))->ancoraValida());

        // (3) VOC-06 — o `destino` é LEGÍVEL e as chaves NUNCA chegam à tela.
        $this->assertSame('Conjunto de fotos (Clássico e Premium)', PubAprovacaoAjuste::destinoLegivel('set'));
        $this->assertSame('Cadastro do produto (todas as ofertas)', PubAprovacaoAjuste::destinoLegivel('product'));
        $this->assertSame('Este anúncio (Premium)', PubAprovacaoAjuste::destinoLegivel('offer', self::PREMIUM));
        $this->assertSame('Este anúncio (Clássico)', PubAprovacaoAjuste::destinoLegivel('offer', self::CLASSICO));

        foreach (PubAprovacaoAjuste::ESCOPOS as $escopo) {
            $destino = PubAprovacaoAjuste::destinoLegivel($escopo, self::CLASSICO);
            $this->assertStringNotContainsString($escopo, $destino, "VOC-06: a chave `{$escopo}` chegou ao destino legível");
        }
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // DADO-11 / BLOQ-08 — A EXCEÇÃO: MOTIVO OBRIGATÓRIO E O QUE ELA LIBERA
    // ═════════════════════════════════════════════════════════════════════════════════════════

    public function test_minimo_justificativa_e_dez_e_justificativa_curta_e_recusada(): void
    {
        // O número do protótipo (`submitException`, `Aprovacao.dc.html:1203`:
        // `if ((F.reason || '').trim().length < 10) return;`).
        $this->assertSame(10, PubAprovacaoExcecao::MINIMO_JUSTIFICATIVA);
        $this->assertStringContainsString('.length < 10', $this->texto(self::PROTOTIPO), 'ISCA: o mínimo de 10 saiu do protótipo — o número daqui ficaria sem fonte');

        $this->assertFalse(PubAprovacaoExcecao::justificativaValida('curta'));
        // ⚠️ A FRONTEIRA, nos dois lados: 9 recusa, 10 aceita. Fronteira já tirou o bônus de
        // alguém neste projeto sem mudança de código.
        $this->assertFalse(PubAprovacaoExcecao::justificativaValida('123456789'), '9 caracteres tem de ser RECUSADO');
        $this->assertTrue(PubAprovacaoExcecao::justificativaValida('1234567890'), '10 caracteres tem de ser ACEITO');

        // ⚠️ `trim()` — o protótipo apara antes de contar. "Só espaço" não é motivo escrito.
        $this->assertFalse(PubAprovacaoExcecao::justificativaValida('                    '));
        $this->assertFalse(PubAprovacaoExcecao::justificativaValida('  curto  '));

        // REND-03: chave adversa não lança.
        foreach ([null, 0, 1, [], 3.5, true] as $adversa) {
            $this->assertFalse(PubAprovacaoExcecao::justificativaValida($adversa));
        }
    }

    public function test_libera_e_fechada_em_resultado_negativo_e_margem_e_espelha_o_modulo_js(): void
    {
        // BLOQ-08 — LISTA FECHADA, exatamente 2.
        $this->assertSame(['resultado_negativo', 'margem_abaixo_do_minimo'], PubAprovacaoExcecao::LIBERA);

        // ⚠️⚠️ ESPELHO LIDO DO `.jsx` do plano 186-02 — não cópia à mão. `BLOQUEIO_DO_PROTOTIPO`
        // é quem diz que `loss` → `resultado_negativo` e `margin` → `margem_abaixo_do_minimo`.
        $mapa = $this->bloqueioDoPrototipo();
        $this->assertCount(11, $mapa, 'ISCA CEGA: a sonda não achou as 11 chaves de `BLOQUEIO_DO_PROTOTIPO`.');
        $this->assertSame([$mapa['loss'], $mapa['margin']], PubAprovacaoExcecao::LIBERA, 'BLOQ-08: `LIBERA` tem de ser exatamente o par pt-BR de `loss`/`margin` do módulo do 186-02');
        $this->assertSame(['loss' => 'resultado_negativo', 'margin' => 'margem_abaixo_do_minimo'], PubAprovacaoExcecao::TIPO_DO_PROTOTIPO, 'a tradução mora em UM lugar só');

        // ISCA: os dois liberados SÃO liberados.
        $this->assertTrue(PubAprovacaoExcecao::libera('resultado_negativo'));
        $this->assertTrue(PubAprovacaoExcecao::libera('margem_abaixo_do_minimo'));

        // ⚠️⚠️ O NEGATIVO QUE **VALE** — BLOQ-08 com os outros bloqueios em **pt-BR**, que são
        // chaves do MESMO namespace. Com `LIBERA = ['loss','margin']` e negativo em `'photos'` o
        // gate seria **vazio por construção**: aquela chave inglesa não existe no nosso
        // namespace e seria `false` por não existir, não por ser recusada.
        $outros = array_values(array_diff($mapa, PubAprovacaoExcecao::LIBERA));
        $this->assertCount(9, $outros, 'ISCA: os outros 9 bloqueios pt-BR têm de existir, senão o laço abaixo roda sobre lista vazia');
        foreach ($outros as $bloqueio) {
            $this->assertFalse(PubAprovacaoExcecao::libera($bloqueio), "T-186-17: a exceção liberou `{$bloqueio}`, que ela NÃO pode liberar");
        }
        // Os 5 que o plano nomeia, explicitamente, em pt-BR.
        foreach (['fotos_a_conferir', 'frete_ausente', 'alteracao_nao_conferida', 'ajuste_em_aberto', 'titulo_longo'] as $vetado) {
            $this->assertFalse(PubAprovacaoExcecao::libera($vetado));
        }

        // ⚠️⚠️ E **NENHUM `||` ACEITANDO AS DUAS FORMAS**: as chaves INGLESAS do protótipo também
        // são recusadas. É o defeito grave achado na verificação da wave 1 — a mesma verdade em
        // dois idiomas, com um `||` em algum lugar aceitando as duas.
        foreach (array_keys($mapa) as $ingles) {
            $this->assertFalse(PubAprovacaoExcecao::libera($ingles), "a chave INGLESA `{$ingles}` do protótipo foi aceita — é o bug dos dois idiomas");
        }

        // REND-03: chave adversa não lança.
        foreach ([null, '', 0, 1, [], 3.5, true] as $adversa) {
            $this->assertFalse(PubAprovacaoExcecao::libera($adversa));
        }
    }

    /**
     * O `BLOQUEIO_DO_PROTOTIPO` do módulo pt-BR do plano 186-02, lido do arquivo.
     *
     * @return array<string, string> chave inglesa do protótipo => chave pt-BR nossa
     */
    private function bloqueioDoPrototipo(): array
    {
        $texto = $this->texto(self::VOCABULARIO);

        if (! preg_match('/export const BLOQUEIO_DO_PROTOTIPO = \{(.+?)\};/su', $texto, $bloco)) {
            $this->fail('ISCA CEGA: não achei `BLOQUEIO_DO_PROTOTIPO` no módulo do plano 186-02.');
        }

        // ISCA DO REGEX nos dois sentidos.
        $regex = "/(\w+):\s*'([a-z_]+)'/u";
        preg_match_all($regex, "loss: 'resultado_negativo',", $certo, PREG_SET_ORDER);
        $this->assertCount(1, $certo, 'ISCA CEGA: o regex do mapa não casa nem o caso canônico.');
        preg_match_all($regex, 'loss: resultado_negativo,', $errado, PREG_SET_ORDER);
        $this->assertCount(0, $errado, 'ISCA: o regex casou valor SEM aspas.');

        preg_match_all($regex, $bloco[1], $m, PREG_SET_ORDER);

        $mapa = [];
        foreach ($m as $par) {
            $mapa[$par[1]] = $par[2];
        }

        return $mapa;
    }

    public function test_a_frase_de_exibicao_da_excecao_e_o_literal_do_prototipo(): void
    {
        [$classico] = $this->asDuasOfertas();
        $gestor = User::factory()->create(['name' => 'Ricardo Gomes']);

        $excecao = PubAprovacaoExcecao::create([
            'oferta_id' => $classico->id,
            'tipo' => PubAprovacaoExcecao::RESULTADO_NEGATIVO,
            'justificativa' => 'Cliente autorizou vender no custo para girar estoque parado',
            'liberada_por' => $gestor->id,
            'liberada_em' => now(),
            'versao' => 1,
        ]);

        // A FORMA é do protótipo (`:1142`: `'Exceção autorizada por ' + nome + ' · ' + at`), com o
        // glifo `§` de `HK.exc` (`:1386`) na frente.
        $this->assertSame(
            '§ Exceção autorizada por Ricardo Gomes · 15/10/2026 14:30 · Cliente autorizou vender no custo para girar estoque parado',
            $excecao->fraseDeExibicao()
        );
        $this->assertStringContainsString("'Exceção autorizada por '", $this->texto(self::PROTOTIPO), 'ISCA: a frase saiu do protótipo — a daqui ficaria sem fonte');

        // ⚠️ Com `liberada_por` nulo (usuário apagado pelo SET NULL) a frase **não quebra** e não
        // mente: ela diz que o responsável saiu, nunca "por " seguido de nada.
        $excecao->update(['liberada_por' => null]);
        $frase = $excecao->fresh()->fraseDeExibicao();
        $this->assertStringContainsString('§ Exceção autorizada', $frase);
        $this->assertStringNotContainsString('por  ·', $frase, 'a frase ficou com o nome VAZIO — o registro é a prova da auditoria-depois');
        $this->assertStringContainsString('15/10/2026 14:30', $frase);
    }

    public function test_a_excecao_nasce_valendo_e_o_default_do_modelo_e_o_do_schema_vivo(): void
    {
        [$classico] = $this->asDuasOfertas();

        // ⚠️⚠️ DEFEITO REAL DO PLANO 186-04: o Eloquent **não lê o default do banco**. Sem o
        // `$attributes`, a exceção nasceria com `ativa` **null em memória** e o bloqueio voltaria
        // sem explicação — e como **aprovar já publica**, isso é visível em produção.
        $excecao = new PubAprovacaoExcecao;
        $this->assertTrue($excecao->ativa, 'a exceção nasce VALENDO em memória (§0: não há estado de espera)');

        $ajuste = new PubAprovacaoAjuste;
        $this->assertTrue($ajuste->aberto, 'o ajuste nasce ABERTO em memória, senão ele não conta como bloqueio');

        $evento = new PubAprovacaoEvento;
        $this->assertFalse($evento->ator_sistema, 'o evento nasce com ator humano em memória');

        // ⚠️ A comparação é contra o default lido do **SCHEMA VIVO**, nunca contra outra constante
        // do PHP — comparar PHP com PHP não acusaria divergência nenhuma.
        $this->assertSame('1', $this->defaultDaColuna('pub_aprovacao_excecoes', 'ativa'));
        $this->assertSame('1', $this->defaultDaColuna('pub_aprovacao_ajustes', 'aberto'));
        $this->assertSame('0', $this->defaultDaColuna('pub_aprovacao_eventos', 'ator_sistema'));

        // ISCA: coluna que EXISTE e **não** tem default devolve `null` — senão o leitor estaria
        // devolvendo o mesmo valor para tudo.
        $this->assertNull($this->defaultDaColuna('pub_aprovacao_excecoes', 'justificativa'), 'ISCA CEGA: o leitor de default devolve valor para coluna sem default');

        // E a linha nasce valendo NO BANCO também (reconsulta).
        $criada = PubAprovacaoExcecao::create([
            'oferta_id' => $classico->id,
            'tipo' => PubAprovacaoExcecao::MARGEM_ABAIXO_DO_MINIMO,
            'justificativa' => 'Margem menor aceita pelo cliente nesta campanha de lançamento',
            'liberada_por' => null,
            'liberada_em' => now(),
            'versao' => 2,
        ]);
        $this->assertTrue(PubAprovacaoExcecao::findOrFail($criada->id)->ativa);
    }

    /** O default de uma coluna no SCHEMA VIVO (SQLite dos testes), normalizado para string. */
    private function defaultDaColuna(string $tabela, string $coluna): ?string
    {
        foreach (Schema::getColumns($tabela) as $c) {
            if (($c['name'] ?? null) === $coluna) {
                $bruto = $c['default'] ?? null;

                return $bruto === null ? null : trim((string) $bruto, "'\"");
            }
        }

        $this->fail("coluna `{$coluna}` não existe em `{$tabela}`");
    }
}
