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
use App\Services\Publicador\Aprovacao\AjustesDaOfertaService;
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

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // BLOQ-09 — A EXCEÇÃO APLICADA: MOTIVO OBRIGATÓRIO, EFEITO IMEDIATO, SEM SEGUNDO PASSO
    // ═════════════════════════════════════════════════════════════════════════════════════════

    public function test_aplicar_excecao_com_nove_caracteres_e_recusada_e_com_dez_e_imediata(): void
    {
        [$classico] = $this->asDuasOfertas();
        $gestor = User::factory()->create(['name' => 'Ricardo Gomes']);
        $servico = app(AjustesDaOfertaService::class);

        // ⚠️ 9 CARACTERES — **RECUSADA**, e nada é gravado (T-186-16, repúdio: exceção sem motivo
        // rastreável). A fronteira é medida nos dois lados porque fronteira já tirou o bônus de
        // alguém neste projeto SEM mudança de código.
        $pegou = null;
        try {
            $servico->aplicarExcecao($classico, $gestor, PubAprovacaoExcecao::RESULTADO_NEGATIVO, '123456789');
        } catch (RegraViolada $e) {
            $pegou = $e;
        }
        $this->assertNotNull($pegou, 'BLOQ-09: exceção com 9 caracteres de motivo tem de ser RECUSADA');
        $this->assertSame(AjustesDaOfertaService::REGRA_JUSTIFICATIVA_CURTA, $pegou->regra);
        $this->assertSame(0, PubAprovacaoExcecao::count(), 'a recusa gravou a exceção');
        $this->assertSame(0, PubAprovacaoEvento::where('tipo', PubAprovacaoEvento::EXCECAO)->count(), 'a recusa gravou evento no histórico');

        // "Só espaço" também é recusado — `trim()` antes de contar.
        $pegou = null;
        try {
            $servico->aplicarExcecao($classico, $gestor, PubAprovacaoExcecao::RESULTADO_NEGATIVO, '                              ');
        } catch (RegraViolada $e) {
            $pegou = $e;
        }
        $this->assertNotNull($pegou, '"só espaço" não é motivo escrito');
        $this->assertSame(0, PubAprovacaoExcecao::count());

        // ⚠️ 10 CARACTERES — ACEITA, e **O EFEITO É IMEDIATO**: não há passo intermediário, não há
        // "aguardando autorização", não há nada a confirmar depois.
        $excecao = $servico->aplicarExcecao($classico, $gestor, PubAprovacaoExcecao::RESULTADO_NEGATIVO, '1234567890');

        $naLinha = PubAprovacaoExcecao::findOrFail($excecao->id);
        $this->assertTrue($naLinha->ativa, 'BLOQ-09: a exceção tem de nascer VALENDO — não existe estado de espera');
        $this->assertSame($gestor->id, $naLinha->liberada_por, 'T-186-16: QUEM liberou tem de ficar gravado');
        $this->assertSame('2026-10-15 14:30:00', $naLinha->liberada_em->format('Y-m-d H:i:s'), 'T-186-16: QUANDO tem de ficar gravado, com a data do SERVIDOR');
        $this->assertSame((int) $classico->versao, $naLinha->versao, 'BLOQ-05: a exceção guarda em QUE versão foi liberada');

        // ⚠️ IMEDIATO, medido pela consulta de vigência: sem nenhum passo adicional, ela já vale.
        $this->assertTrue(PubAprovacaoExcecao::vigentesDa($classico->id)->where('tipo', PubAprovacaoExcecao::RESULTADO_NEGATIVO)->exists());

        // ⚠️⚠️ E **NÃO EXISTE COLUNA DE SEGUNDO PASSO** na tabela. Esta negativa **NÃO é vazia por
        // construção**: a tabela é criada POR ESTE PLANO, e a isca prova que a sonda enxerga as
        // colunas que EXISTEM.
        foreach (['oferta_id', 'tipo', 'justificativa', 'liberada_por', 'liberada_em', 'versao', 'ativa'] as $existe) {
            $this->assertTrue(Schema::hasColumn('pub_aprovacao_excecoes', $existe), "ISCA CEGA: `hasColumn` não acha a coluna `{$existe}`, que EXISTE");
        }
        foreach (['status', 'aprovada_por', 'autorizada_por', 'solicitada_em', 'solicitada_por', 'aguardando', 'pendente', 'em_analise'] as $segundoPasso) {
            $this->assertFalse(Schema::hasColumn('pub_aprovacao_excecoes', $segundoPasso), "BLOQ-09: a coluna `{$segundoPasso}` é um SEGUNDO PASSO, e o usuário recusou o segundo passo em 14/10/2026");
        }
    }

    public function test_excecao_de_resultado_negativo_nao_libera_margem_e_vice_versa(): void
    {
        [$classico, $premium] = $this->asDuasOfertas();
        $gestor = User::factory()->create(['name' => 'Ricardo Gomes']);
        $servico = app(AjustesDaOfertaService::class);

        // ⚠️⚠️ É AQUI QUE O NOSSO COMPORTAMENTO DIFERE DO PROTÓTIPO. Em
        // `Aprovacao.dc.html:1093-1094` há **um único `!o.exc`** suprimindo `loss` **e** `margin`
        // de uma vez: lá a exceção é um FLAG. Aqui ela é TIPADA, e autorizar margem **não** libera
        // prejuízo de graça (BLOQ-08 + BLOQ-09: são dois atos, com justificativas diferentes, e
        // não há desfazer).
        $servico->aplicarExcecao($classico, $gestor, PubAprovacaoExcecao::RESULTADO_NEGATIVO, 'Venda no custo autorizada para girar estoque parado');

        $vigentes = PubAprovacaoExcecao::vigentesDa($classico->id);
        $this->assertTrue((clone $vigentes)->where('tipo', PubAprovacaoExcecao::RESULTADO_NEGATIVO)->exists());
        $this->assertFalse((clone $vigentes)->where('tipo', PubAprovacaoExcecao::MARGEM_ABAIXO_DO_MINIMO)->exists(), 'BLOQ-08: a exceção de prejuízo liberou a de margem — é o `!o.exc` único do protótipo, que NÃO é o nosso comportamento');

        // A direção simétrica, com a OUTRA oferta PREENCHIDA (GATE-13).
        $servico->aplicarExcecao($premium, $gestor, PubAprovacaoExcecao::MARGEM_ABAIXO_DO_MINIMO, 'Margem menor aceita pelo cliente nesta campanha');
        $doPremium = PubAprovacaoExcecao::vigentesDa($premium->id);
        $this->assertTrue((clone $doPremium)->where('tipo', PubAprovacaoExcecao::MARGEM_ABAIXO_DO_MINIMO)->exists());
        $this->assertFalse((clone $doPremium)->where('tipo', PubAprovacaoExcecao::RESULTADO_NEGATIVO)->exists(), 'BLOQ-08: a exceção de margem liberou a de prejuízo');

        // ⚠️ E a exceção de UMA oferta não atravessa para a IRMÃ: ela é por OFERTA, e as duas têm
        // preço e resultado SEPARADOS (a granularidade resolvida no plano 186-04).
        $this->assertFalse(PubAprovacaoExcecao::vigentesDa($premium->id)->where('tipo', PubAprovacaoExcecao::RESULTADO_NEGATIVO)->exists());

        // ⚠️ BLOQ-08 — tipo FORA dos dois é recusado com código PRÓPRIO, e com as chaves pt-BR dos
        // outros bloqueios (do MESMO namespace): com chave inglesa a asserção seria vazia.
        foreach (['fotos_a_conferir', 'frete_ausente', 'alteracao_nao_conferida', 'ajuste_em_aberto', 'titulo_longo', 'loss', 'margin'] as $naoLibera) {
            $pegou = null;
            try {
                $servico->aplicarExcecao($classico, $gestor, $naoLibera, 'Justificativa longa o suficiente para passar do mínimo');
            } catch (RegraViolada $e) {
                $pegou = $e;
            }
            $this->assertNotNull($pegou, "T-186-17: a exceção aceitou liberar `{$naoLibera}`, que ela NÃO pode liberar");
            $this->assertSame(AjustesDaOfertaService::REGRA_EXCECAO_NAO_LIBERA, $pegou->regra);
        }
    }

    public function test_a_excecao_aplicada_aparece_na_linha_do_tempo_com_tipo_exc_autor_e_carimbo(): void
    {
        [$classico] = $this->asDuasOfertas();
        $gestor = User::factory()->create(['name' => 'Ricardo Gomes']);
        $servico = app(AjustesDaOfertaService::class);

        Carbon::setTestNow('2026-10-15 17:05:00');
        $servico->aplicarExcecao($classico, $gestor, PubAprovacaoExcecao::MARGEM_ABAIXO_DO_MINIMO, 'Margem menor aceita pelo cliente nesta campanha de lançamento');
        Carbon::setTestNow('2026-10-15 14:30:00');

        $linha = $this->historico()->linhaDoTempo($classico);
        $excecoes = array_values(array_filter($linha, fn ($i) => $i['tipo'] === PubAprovacaoEvento::EXCECAO));

        // ⚠️ É ESTE EVENTO QUE TORNA "AUDITORIA DEPOIS" VERDADE (BLOQ-09). Sem ele, a exceção
        // existiria e ninguém a veria ao abrir a oferta.
        $this->assertCount(1, $excecoes, 'BLOQ-09: a exceção aplicada NÃO entrou no histórico — "auditoria depois" ficaria sendo só uma frase');
        $evento = $excecoes[0];

        $this->assertSame('§', $evento['icone'], 'o glifo é o `§` de `HK.exc` do protótipo');
        $this->assertSame('Exceção autorizada', $evento['rotulo']);
        // AUTOR e CARIMBO DE TEMPO, os dois.
        $this->assertSame('Ricardo Gomes', $evento['autor'], 'T-186-16: a exceção no histórico sem autor rastreável é repúdio');
        $this->assertFalse($evento['ator_sistema'], 'exceção é ato de PESSOA, nunca do Sistema');
        $this->assertSame('15/10/2026 17:05', $evento['ocorrido_formatado']);
        $this->assertSame((int) $classico->versao, $evento['versao'], 'BLOQ-05: o histórico diz em QUE versão a exceção foi dada');

        // A frase nomeia o bloqueio liberado e traz a justificativa (é a prova do motivo escrito).
        $this->assertStringContainsString('Margem abaixo do mínimo', $evento['texto']);
        $this->assertStringContainsString('Margem menor aceita pelo cliente', $evento['texto']);

        // ⚠️ ISCA (GATE-13): um evento de OUTRO tipo está PREENCHIDO na mesma linha do tempo —
        // senão "achei 1 do tipo exc" e "achei 1 evento" dariam o mesmo número.
        $this->assertGreaterThan(1, count($linha), 'ISCA CEGA: a linha do tempo tem só o evento da exceção — o filtro por tipo não estaria sendo medido');
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // ⚠️⚠️ BLOQ-09 — A AUSÊNCIA DELIBERADA DO ESTADO "EXCEÇÃO AGUARDANDO AUTORIZAÇÃO"
    // ═════════════════════════════════════════════════════════════════════════════════════════

    public function test_nao_existe_estado_de_espera_de_autorizacao_na_area_nova_com_isca(): void
    {
        // ⚠️⚠️ A SONDA ANCORA NA **FORMA**, NÃO NUMA LISTA DE NOMES (achado G8): a lista de três
        // nomes da primeira redação (`aguardando_autorizacao`, `pendente_aprovacao`,
        // `solicitar_autorizacao`) **não era exaustiva** — `excecaoPendente`, `aguardandoGestor`,
        // `excecao_em_analise` e `exc_status` passariam todos.
        //
        // ⚠️⚠️ MAS A FORMA SOZINHA NÃO SERVE, E ISTO FOI **MEDIDO** EM 15/10/2026. O par
        // sugerido — `(aguardand|pendent|em_analise|solicit)` × `(autoriza|gestor|aprova)` —
        // produz **10 ocorrências LEGÍTIMAS** na área, todas do vocabulário de ANDAMENTO e de
        // FOTO das waves 1-2: `'Aguardando aprovação'` (o rótulo VOC-02, em 3 arquivos),
        // `AJUSTES_SOLICITADOS => [..., self::APROVADO]` (as transições do 186-04),
        // `PubAprovacaoImagem::PENDENTE` ao lado de `APROVADA`, e o toast
        // `'Aprovado — aguardando publicação'`. **O domínio fala "aguardando aprovação" por toda
        // parte, e isso é correto** — é a OFERTA esperando a ECF conferir.
        //
        // ⚠️⚠️ E o par escrito no plano (`…|aprovad`) ficava calado **por ACIDENTE DE GRAFIA**: o
        // radical `aprovad` **não casa a palavra pt-BR `aprovação`/`aprovacao`** (c, não d). Uma
        // sonda cujo silêncio depende de uma letra não é ancorada na forma — é sorte, e é
        // exatamente a classe "gate vazio por construção" com outra roupa.
        //
        // ➡️ **O DISCRIMINADOR REAL É O SUJEITO:** o que não pode existir é **A EXCEÇÃO**
        // esperando alguém autorizar. Daí as TRÊS camadas abaixo, cada uma com isca própria.

        // ─── CAMADA A — a ÁREA TODA, por glob, ancorada no SUJEITO `exceção` ─────────────────
        // É esta que vale para os arquivos que as Fases 187-194 ainda vão acrescentar.
        $formas = [
            '/(exce[cç][aã]o|\bexc\b|excecoes).{0,40}(aguardand|aguardar|pendent|em[_ ]?analise|a[_ ]?autorizar|autorizacao|solicitar)/i',
            '/(aguardand|aguardar|pendent|em[_ ]?analise|a[_ ]?autorizar|autorizacao|solicitar).{0,40}(exce[cç][aã]o|\bexc\b|excecoes)/i',
        ];

        // ⚠️ ISCA DO REGEX, nos dois sentidos: ele TEM de casar o que deveria e NÃO casar o que
        // não deveria. Sem isto, um regex quebrado devolveria "nenhuma violação" para sempre.
        foreach ([
            'excecaoPendenteDeAprovacao', 'excecao_aguardando_autorizacao', 'exc_pendente',
            'excecao_em_analise', 'excecaoAguardandoGestor', 'solicitarExcecao', 'aguardando_excecao',
        ] as $deveCasar) {
            $casou = false;
            foreach ($formas as $forma) {
                $casou = $casou || (bool) preg_match($forma, $deveCasar);
            }
            $this->assertTrue($casou, "ISCA CEGA: a sonda de forma NÃO casa `{$deveCasar}` — ela estaria cega para o estado de espera");
        }
        // ⚠️ E NÃO casa o vocabulário LEGÍTIMO do andamento/foto, que é o que a medição de
        // 15/10/2026 mostrou ser o problema do par sem sujeito.
        foreach ([
            'aplicarExcecao', 'liberada_por', 'justificativaValida', 'aguardando_aprovacao',
            'Aguardando aprovação', 'AJUSTES_SOLICITADOS', 'PubAprovacaoImagem::PENDENTE',
            'Exceção autorizada por', 'excecao->ativa',
        ] as $naoDeveCasar) {
            foreach ($formas as $forma) {
                $this->assertSame(0, preg_match($forma, $naoDeveCasar), "ISCA: a sonda casou `{$naoDeveCasar}`, que é LEGÍTIMO — ela reprovaria o próprio conserto");
            }
        }

        // ⚠️ A contagem **não** depende de quantos arquivos existem (contagem por glob é frágil
        // por construção): cada glob só precisa achar **ao menos um** arquivo, e os infratores são
        // coletados numa asserção ÚNICA.
        $globs = [
            'app/Services/Publicador/Aprovacao/*.php',
            'app/Models/PubAprovacao*.php',
            'resources/js/Components/Mlb/Publicador/aprovacao/*.jsx',
            'database/migrations/2026_10_15_10*.php',
        ];

        $infratores = [];
        $lidos = 0;

        foreach ($globs as $glob) {
            $arquivos = glob(base_path($glob));
            $this->assertNotEmpty($arquivos, "ISCA CEGA: o glob `{$glob}` não achou arquivo nenhum — a varredura rodaria sobre lista vazia");

            foreach ($arquivos as $abs) {
                $bruto = file_get_contents($abs);

                // ⚠️⚠️ LER O **CÓDIGO SEM COMENTÁRIOS** É OBRIGATÓRIO AQUI, e não é cosmético: os
                // docblocks deste plano **explicam** que o estado de espera não existe, e a frase
                // "EXCEÇÃO AGUARDANDO AUTORIZAÇÃO" casa a primeira forma. Um gate que lesse o
                // arquivo inteiro reprovaria pelo PRÓPRIO CONSERTO
                // (`feedback_gate_que_reprova_pelo_proprio_conserto`).
                $codigo = str_ends_with($abs, '.php') ? $this->semComentarios($bruto) : $this->semComentariosJs($bruto);
                $lidos++;

                foreach ($formas as $forma) {
                    if (preg_match($forma, $codigo, $achado)) {
                        $infratores[] = str_replace(base_path() . DIRECTORY_SEPARATOR, '', $abs) . ': ' . $achado[0];
                    }
                }
            }
        }

        $this->assertGreaterThan(10, $lidos, 'ISCA: a varredura leu pouquíssimos arquivos — algo no glob mudou');

        // ⚠️ ISCA DE CONTEÚDO (a que prova que o leitor devolveu CÓDIGO de verdade): o nome do
        // método da exceção TEM de ser achado por uma busca simples na mesma área. Sem isto,
        // "nenhum infrator" poderia significar "li string vazia de tudo".
        $achouAplicar = false;
        foreach (glob(base_path('app/Services/Publicador/Aprovacao/*.php')) as $abs) {
            $achouAplicar = $achouAplicar || str_contains($this->semComentarios(file_get_contents($abs)), 'aplicarExcecao');
        }
        $this->assertTrue($achouAplicar, 'ISCA CEGA: a varredura não acha `aplicarExcecao` no CÓDIGO da área — ela está lendo nada');

        // ⚠️⚠️ A ASSERÇÃO DA CAMADA A. O usuário OFERECEU e RECUSOU a aprovação em dois passos em
        // 14/10/2026 (BLOQ-09): o controle da exceção é **auditoria DEPOIS, não barreira ANTES**,
        // porque aprovar já publica e não há desfazer.
        $this->assertSame([], $infratores, "BLOQ-09: apareceu a FORMA de um estado de espera da EXCEÇÃO na área nova:\n- " . implode("\n- ", $infratores));

        // ─── CAMADA B — a CASA da exceção: ali o vocabulário de espera é proibido INTEIRO ────
        // Nestes arquivos não existe assunto "andamento da oferta" que justifique uma palavra de
        // espera; qualquer uma é o estado recusado. Por isso aqui a sonda **não** precisa de
        // sujeito — e é o que pega `aguardando_autorizacao`, `pendente_aprovacao`,
        // `solicitar_autorizacao` e `aguardandoGestor`, que a Camada A não casaria sozinha.
        $vocabularioDeEspera = '/(status|aguardand|aguardar|pendent|em[_ ]?analise|a[_ ]?autorizar|autorizacao|solicitar[_ ]?autoriza|aprovada_por|autorizada_por)/i';

        // ISCA DO REGEX nos dois sentidos.
        foreach (['aguardando_autorizacao', 'pendente_aprovacao', 'solicitar_autorizacao', 'aguardandoGestor', 'exc_status', 'excecao_em_analise', 'aprovada_por'] as $deveCasar) {
            $this->assertSame(1, preg_match($vocabularioDeEspera, $deveCasar), "ISCA CEGA: o vocabulário de espera não casa `{$deveCasar}`");
        }
        foreach (['liberada_por', 'liberada_em', 'justificativa', 'aplicarExcecao', 'Exceção autorizada por', 'ativa'] as $naoDeveCasar) {
            $this->assertSame(0, preg_match($vocabularioDeEspera, $naoDeveCasar), "ISCA: o vocabulário de espera casou `{$naoDeveCasar}`, que é legítimo");
        }

        $casa = [
            'app/Models/PubAprovacaoExcecao.php',
            'app/Services/Publicador/Aprovacao/AjustesDaOfertaService.php',
        ];
        $stripBateu = false;

        foreach ($casa as $arquivo) {
            $codigo = $this->semComentarios($this->texto($arquivo));

            // ⚠️ **A ÚNICA EXEMPÇÃO NOMEADA**, e ela é GATEADA: `AGUARDANDO_APROVACAO` é a
            // constante de ANDAMENTO do plano 186-04 (outro namespace — é a OFERTA esperando a
            // ECF, não a exceção esperando alguém). Ela é recortada antes da varredura, e a isca
            // abaixo exige que o recorte tenha batido em ALGUM dos arquivos: exempção que não
            // encontra nada é exempção que esconde a sonda.
            $limpo = str_replace('AGUARDANDO_APROVACAO', '', $codigo);
            $stripBateu = $stripBateu || $limpo !== $codigo;

            preg_match_all($vocabularioDeEspera, $limpo, $achados);
            $this->assertSame([], $achados[0], "BLOQ-09: vocabulário de espera em `{$arquivo}`, que é a CASA da exceção: " . implode(', ', $achados[0]));
        }

        $this->assertTrue($stripBateu, 'ISCA CEGA: a exempção de `AGUARDANDO_APROVACAO` não encontrou nada — ou o serviço deixou de usar a única porta, ou a leitura veio vazia');

        // ─── CAMADA C — O SCHEMA: nenhuma COLUNA com a forma de um segundo passo ─────────────
        // É a camada que de fato BINDA: sem coluna, o estado não tem onde morar.
        // ⚠️ Medida sobre **TODAS** as colunas da tabela, não sobre lista escrita à mão — e a
        // tabela é criada POR ESTE PLANO, então a negativa **não** é vazia por construção.
        $colunas = array_column(Schema::getColumns('pub_aprovacao_excecoes'), 'name');
        $this->assertNotEmpty($colunas, 'ISCA CEGA: o leitor de colunas devolveu lista vazia');
        $this->assertContains('liberada_por', $colunas, 'ISCA: a sonda enxerga as colunas que EXISTEM');
        $this->assertContains('justificativa', $colunas);

        $deSegundoPasso = array_values(array_filter($colunas, fn ($c) => (bool) preg_match($vocabularioDeEspera, $c)));
        $this->assertSame([], $deSegundoPasso, 'BLOQ-09: a tabela da exceção ganhou coluna com a forma de um segundo passo: ' . implode(', ', $deSegundoPasso));

        // ⚠️ E a ausência está DECLARADA por escrito, com a data e a razão — senão a próxima sessão
        // "completa" o fluxo achando que faltou. (Lido do arquivo CRU, de propósito: é comentário.)
        foreach (['database/migrations/2026_10_15_100300_create_pub_aprovacao_ajustes_tables.php', 'app/Models/PubAprovacaoExcecao.php'] as $declara) {
            $cru = $this->texto($declara);
            $this->assertStringContainsString('14/10/2026', $cru, "a DATA da decisão não está declarada em `{$declara}`");
            $this->assertStringContainsString('RECUSADA', $cru, "a recusa do segundo passo não está declarada em `{$declara}`");
            $this->assertStringContainsString('NÃO EXISTE', $cru, "a ausência não está declarada em `{$declara}`");
        }

        // ⚠️ ESTE GATE **NÃO SUBSTITUI** o do plano 186-09, e o 186-09 não substitui este: aquele
        // mede a área inteira no fim da fase (inclusive telas e rotas); este roda junto com o
        // código que criou a exceção, e falha na hora em que alguém a "completar".
        $this->assertStringContainsString('186-09', $this->texto('app/Models/PubAprovacaoExcecao.php'), 'a relação com o gate transversal do 186-09 tem de ficar declarada');
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

    /**
     * O mesmo para `.jsx`, onde não há tokenizador do PHP.
     *
     * ⚠️ É um recorte de blocos `/* … *\/` e linhas `//`, e **é deliberadamente conservador**: ele
     * pode deixar passar comentário dentro de string, o que só tornaria a sonda MAIS rígida
     * (acharia infrator a mais), nunca mais frouxa.
     */
    private function semComentariosJs(string $texto): string
    {
        $sem = preg_replace('#/\*.*?\*/#s', '', $texto);

        return (string) preg_replace('#^\s*//.*$#m', '', (string) $sem);
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
