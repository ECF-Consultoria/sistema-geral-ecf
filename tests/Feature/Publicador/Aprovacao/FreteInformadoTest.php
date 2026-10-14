<?php

namespace Tests\Feature\Publicador\Aprovacao;

use App\Models\Company;
use App\Models\PubAprovacaoComposicao;
use App\Models\PubAprovacaoFrete;
use App\Models\PubAprovacaoOferta;
use App\Models\PubProduto;
use App\Models\PubRascunho;
use App\Models\PubRascunhoAlvo;
use App\Models\User;
use App\Services\Publicador\DadosEfetivosService;
use App\Support\Publicador\ConferenciaDeFrete;
use App\Support\Publicador\RegraViolada;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 * Fase 186, plano 06 — O FRETE DO VENDEDOR INFORMADO e A COMPOSIÇÃO CONGELADA.
 *
 * ⚠️⚠️ DE CADA GATE DESTE ARQUIVO, A PERGUNTA RESPONDIDA ANTES DE ELE EXISTIR:
 * **"o que precisaria estar errado no mundo para ele falhar?"**
 *
 *   · **as 3 origens** — alguém pôr a CHAVE de dado na tela. `cotacao_transportadora` é
 *     identificador, não português; o rótulo "Cotação da transportadora" é o que a pessoa lê
 *     (VOC-06). Falharia se a chave virasse rótulo, ou se aparecesse uma quarta origem sem alguém
 *     decidir qual é ela.
 *   · **histórico, não sobrescrita** — alguém trocar o `create` por um `update` na mesma linha.
 *     Com **aprovar JÁ PUBLICANDO e sem desfazer**, o frete que valia na aprovação seria destruído
 *     exatamente no caso em que ele importa (T-186-21).
 *   · **`vigenteDe()` sem linha devolve `null`** — alguém devolver `0.0` para "não informado".
 *     Zero é um número, e a tela o mostraria como se fosse verdade: "frete R$ 0,00" num kit de
 *     duas poltronas (FIN-05/D4).
 *   · **FIN-07** — alguém declarar a linha "Pendente" sem conferir se há fonte. ⚠️ **HÁ FONTE, e
 *     foi MEDIDA**: `DadosEfetivosService::fretesDaLinha()` devolve `origem` em palavras
 *     (`digitado`/`conta`/`tabela`/`outro_tipo`) e `valor = null` é o ÚNICO caso sem fonte. Este
 *     gate roda os 4 casos exigidos **mais** o `outro_tipo`.
 *   · **a nota NOMEIA a fonte** — alguém pôr texto decorativo ("origem automática") no lugar do
 *     nome da fonte. A nota é o que torna a composição auditável (DADO-13/VOC-04); genérica, ela
 *     prova nada.
 *   · **`incompleto` com resultado preenchido** — alguém gravar `resultado = 0` quando falta item.
 *     A tela diria "resultado R$ 0,00 por venda" em vez de "Incompleto".
 *   · **`margem_minima` lida da linha congelada** — alguém reler `config()` na exibição. A config
 *     pode ter mudado DEPOIS do ato, e a auditoria-depois reconstruiria a decisão errada.
 *   · **a folga da `margem`** — `decimal(7,3)` comporta ±9999,999 e este projeto já mediu
 *     **+20738%**. No **MySQL 8.0.46** é **erro 1264** e a gravação cai; **no SQLite dos testes
 *     grava CALADO**. A suíte ficaria verde e o deploy quebraria.
 *   · **a migration** — 1059 (nome acima de 64), 1830 (`nullOnDelete` sem `nullable`), `enum` (o
 *     CHECK é enforçado no SQLite e cega a suíte) e `try/catch` em volta de DDL. Os três primeiros
 *     o SQLite **não pega**, e o deploy quebra com a tabela JÁ criada.
 *
 * ⚠️ TODA SONDA TEM **ISCA**. E as armadilhas medidas nas waves 1 e 2 estão evitadas por escrito:
 * diff **com range** (sem range o diff da árvore sai vazio por construção), leitura do CÓDIGO pelo
 * **tokenizador** (o docblock explica o que não usa, e um gate que conte a palavra no arquivo
 * inteiro reprova pelo próprio conserto), regex testado contra **um caso que DEVE casar** (na wave
 * 1 `->foreignI` não achava FK nenhuma) e invariante próprio para a sonda que rodaria sobre **zero
 * casos**.
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 */
class FreteInformadoTest extends TestCase
{
    use RefreshDatabase;

    private const CLASSICO = 'gold_special';

    /** A migration deste plano (Task 1). */
    private const MIGRATION = 'database/migrations/2026_10_15_100400_create_pub_aprovacao_financeiro_tables.php';

    /**
     * ⚠️ ISCA DE CAMINHO. 5 caminhos fantasma em 4 fases deste projeto: migration ausente faria
     * TODA sonda de texto devolver "nenhuma violação" e o gate ficaria verde medindo NADA.
     */
    private const MIGRATION_FANTASMA = 'database/migrations/2026_10_15_999999_esta_migration_nao_existe.php';

    protected function setUp(): void
    {
        parent::setUp();
        // REND-08 — tudo que grava data fica ancorado.
        Carbon::setTestNow('2026-10-15 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function umaOferta(): PubAprovacaoOferta
    {
        $empresa = Company::factory()->create();
        $produto = PubProduto::create([
            'company_id' => $empresa->id,
            'sku' => 'FRT-01',
            'nome' => 'Poltrona FRT-01',
            'origem' => PubProduto::ORIGEM_PUBLICADOR,
        ]);
        $rascunho = PubRascunho::create(['produto_id' => $produto->id]);
        $alvo = PubRascunhoAlvo::create(['rascunho_id' => $rascunho->id, 'listing_type_id' => self::CLASSICO, 'posicao' => 0]);

        return PubAprovacaoOferta::create([
            'alvo_id' => $alvo->id,
            'rascunho_id' => $rascunho->id,
            'produto_id' => $produto->id,
            'company_id' => $empresa->id,
        ]);
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // DADO-12 — as 3 origens, o histórico e o `null` que nunca é zero
    // ═════════════════════════════════════════════════════════════════════════════════════════

    public function test_as_origens_do_frete_informado_sao_tres_com_rotulo_em_pt_br(): void
    {
        $this->assertCount(3, PubAprovacaoFrete::ORIGENS, 'as 3 origens do modal "Informar frete do vendedor" (MOD-03) mudaram de número sem ninguém decidir qual é a nova');

        // ⚠️ VOC-06 — a CHAVE de dado é identificador e NUNCA chega à tela; o rótulo é o que a
        // pessoa lê. São as três frases medidas no protótipo (`Aprovacao.dc.html:1401`).
        $this->assertSame(
            ['Cotação da transportadora', 'Tabela do cliente', 'Simulador de frete'],
            array_values(PubAprovacaoFrete::ORIGENS)
        );

        foreach (PubAprovacaoFrete::ORIGENS as $chave => $rotulo) {
            $this->assertMatchesRegularExpression('/^[a-z_]+$/', $chave, "a chave `{$chave}` não é identificador — chave de dado com acento ou espaço vira duas verdades para manter");
            $this->assertNotSame($chave, $rotulo, "o rótulo de `{$chave}` É a chave: a tela mostraria jargão");
        }

        // ISCA: a chave mais perigosa (a que mais parece texto) NÃO é o rótulo dela.
        $this->assertSame('Cotação da transportadora', PubAprovacaoFrete::ORIGENS[PubAprovacaoFrete::COTACAO_TRANSPORTADORA]);
        $this->assertStringNotContainsString('cotacao_transportadora', implode(' ', PubAprovacaoFrete::ORIGENS), 'ISCA CEGA: a chave crua aparece entre os rótulos.');
    }

    public function test_informar_frete_de_novo_cria_linha_nova_e_deixa_uma_so_vigente(): void
    {
        $oferta = $this->umaOferta();
        $quem = User::factory()->create();

        $primeiro = PubAprovacaoFrete::informar($oferta, 42.90, PubAprovacaoFrete::SIMULADOR, $quem);
        $this->assertTrue($primeiro->vigente);
        $this->assertSame(42.90, $primeiro->valor);

        Carbon::setTestNow('2026-10-15 11:30:00');
        $segundo = PubAprovacaoFrete::informar($oferta->fresh(), 55.00, PubAprovacaoFrete::TABELA_CLIENTE, $quem);

        // ⚠️ HISTÓRICO, NÃO SOBRESCRITA: duas linhas, e a primeira preservada com o valor dela.
        $todas = PubAprovacaoFrete::where('oferta_id', $oferta->id)->orderBy('id')->get();
        $this->assertCount(2, $todas, 'informar de novo SOBRESCREVEU: o frete que valia na aprovação anterior foi destruído (T-186-21)');
        $this->assertSame(42.90, (float) $todas[0]->valor, 'a linha antiga perdeu o valor dela');

        // Uma só vigente, e é a nova.
        $this->assertFalse($todas[0]->vigente);
        $this->assertTrue($todas[1]->vigente);
        $this->assertSame(1, PubAprovacaoFrete::where('oferta_id', $oferta->id)->where('vigente', true)->count(), 'ficou mais de uma linha vigente — a tela não saberia qual frete vale');
        $this->assertSame($segundo->id, PubAprovacaoFrete::vigenteDe($oferta)->id);

        // ⚠️ BLOQ-05 / critério 9 — confirmar frete INCREMENTA a versão (é uma das 8 mudanças
        // relevantes) e a linha guarda a versão DEPOIS do incremento.
        $this->assertSame(2, $primeiro->versao, 'a primeira confirmação não incrementou a versão');
        $this->assertSame(3, $segundo->versao);
        $this->assertSame(3, $oferta->fresh()->versao, 'a versão da OFERTA não acompanhou — a aprovação não seria reaberta');

        // A data e a nota ficam ancoradas no `setTestNow` (REND-08).
        $this->assertSame('2026-10-15 11:30:00', $segundo->informado_em->format('Y-m-d H:i:s'));
        $this->assertSame($quem->id, $segundo->informado_por);
    }

    public function test_vigente_de_oferta_sem_frete_devolve_null_nunca_zero(): void
    {
        $oferta = $this->umaOferta();

        $vigente = PubAprovacaoFrete::vigenteDe($oferta);

        // ⚠️ D4 / FIN-05 — `null` é a ausência; `0.0` seria um número, e a tela o mostraria como
        // se fosse verdade ("frete R$ 0,00" num kit de duas poltronas).
        $this->assertNull($vigente, 'frete não informado virou um VALOR — zero é uma afirmação, e ela seria falsa');
        $this->assertNotSame(0, $vigente);
        $this->assertNotSame(0.0, $vigente);

        // ISCA: com linha, a mesma consulta ACHA (senão o `null` acima seria vazio por construção).
        PubAprovacaoFrete::informar($oferta, 10.0, PubAprovacaoFrete::SIMULADOR);
        $this->assertNotNull(PubAprovacaoFrete::vigenteDe($oferta), 'ISCA CEGA: `vigenteDe()` devolve null mesmo com linha vigente no banco.');

        // E o histórico não ressuscita: com a vigente derrubada, volta a ser `null`.
        PubAprovacaoFrete::where('oferta_id', $oferta->id)->update(['vigente' => false]);
        $this->assertNull(PubAprovacaoFrete::vigenteDe($oferta));
    }

    public function test_informar_recusa_origem_fora_das_tres_e_nao_grava_nada(): void
    {
        $oferta = $this->umaOferta();

        try {
            PubAprovacaoFrete::informar($oferta, 42.90, 'chute_de_origem');
            $this->fail('origem desconhecida foi aceita — a nota deixaria de nomear a fonte');
        } catch (RegraViolada $e) {
            $this->assertSame(PubAprovacaoFrete::REGRA_ORIGEM_DESCONHECIDA, $e->regra);
        }

        // Nada gravado, e a versão intacta: a transação não deixou meia operação atrás.
        $this->assertSame(0, PubAprovacaoFrete::where('oferta_id', $oferta->id)->count());
        $this->assertSame(1, $oferta->fresh()->versao, 'a versão foi incrementada por um informe que não aconteceu — a aprovação seria reaberta de graça');

        // ISCA: a origem LEGÍTIMA do mesmo ponto de partida passa.
        $this->assertInstanceOf(PubAprovacaoFrete::class, PubAprovacaoFrete::informar($oferta->fresh(), 42.90, PubAprovacaoFrete::SIMULADOR));
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // ⚠️⚠️ FIN-07 — HÁ FONTE DE FRETE, E A LINHA NÃO NASCE "Pendente"
    // ═════════════════════════════════════════════════════════════════════════════════════════

    public function test_fin_07_a_linha_de_frete_nasce_confirmado_ou_estimado_nas_origens_vivas_e_pendente_so_sem_fonte(): void
    {
        // ⚠️ A FONTE, MEDIDA: `DadosEfetivosService::fretesDaLinha()` é estática e pura, e devolve
        // `por_tipo[listing_type] = {valor, origem, oferta_id}`. Aqui ela é chamada DE VERDADE —
        // montar o array à mão provaria só que o meu mapa concorda comigo mesmo.
        $linhaDaPrecificacao = [
            'classico' => ['frete' => 42.90, 'frete_origem' => 'digitado'],
            'premium' => ['frete' => 42.90, 'frete_origem' => 'digitado'],
        ];
        $vivo = DadosEfetivosService::fretesDaLinha($linhaDaPrecificacao, 77);
        $this->assertArrayHasKey(self::CLASSICO, $vivo, 'ISCA CEGA: a fonte viva não devolve a chave do Clássico — o mapa abaixo mediria o nada.');
        $this->assertSame('digitado', $vivo[self::CLASSICO]['origem']);

        // ⚠️ OS 4 CASOS EXIGIDOS (as 3 origens + `valor = null`), MAIS o `outro_tipo`, que também
        // existe na fonte. Nenhum deles é "Pendente" exceto o sem valor.
        $casos = [
            // [frete, frete_origem, fonte do sugerido, estado esperado, pedaço da nota]
            [42.90, 'digitado', null, PubAprovacaoComposicao::CONFIRMADO, 'digitado no Portal'],
            [39.50, 'sugerido', 'conta', PubAprovacaoComposicao::CONFIRMADO, 'cotado na conta pelo Portal'],
            [31.00, 'sugerido', 'tabela', PubAprovacaoComposicao::ESTIMADO, 'estimado pela tabela'],
            [28.00, 'outro_tipo', null, PubAprovacaoComposicao::ESTIMADO, 'o mesmo do outro tipo de anúncio'],
            [null, null, null, PubAprovacaoComposicao::PENDENTE, null],
        ];

        $vistos = [];
        foreach ($casos as [$frete, $origem, $fonte, $esperado, $naNota]) {
            $t = $frete === null ? [] : ['frete' => $frete, 'frete_origem' => $origem];
            if ($fonte !== null) {
                $t['frete_sugerido'] = ['fonte' => $fonte];
            }
            $vivo = DadosEfetivosService::fretesDaLinha(['classico' => $t, 'premium' => $t], 77);

            $linha = PubAprovacaoComposicao::linhaDeFrete($vivo[self::CLASSICO]);
            $vistos[] = $linha['origem'];

            $this->assertSame($esperado, $linha['origem'], 'a origem viva `'.var_export($origem, true)."` virou `{$linha['origem']}` — FIN-07 exige que só a AUSÊNCIA de valor seja Pendente");

            if ($naNota === null) {
                $this->assertNull($linha['valor'], 'sem fonte, o valor tem de ser `null` — nunca zero (D4)');
                $this->assertNotSame('', trim($linha['nota']), 'a linha Pendente ficou sem nota: a tela não diria POR QUE está pendente');

                continue;
            }

            $this->assertSame((float) $frete, $linha['valor']);
            // ⚠️ A NOTA **NOMEIA A FONTE** (DADO-13/VOC-04): ela não é texto decorativo, e a frase
            // vem de `ConferenciaDeFrete::ORIGENS` — LIDO, nunca recopiado (duas redações da mesma
            // verdade divergem).
            $this->assertStringContainsString($naNota, $linha['nota'], "a nota de `{$origem}` não NOMEIA a fonte");
        }

        // ⚠️ ISCA DE CONTAGEM: um mapa que devolvesse sempre o mesmo estado passaria em 2 dos 5.
        // São 3 estados distintos nos 5 casos.
        $this->assertCount(3, array_unique($vistos), 'ISCA CEGA: o mapa de origem não distingue os três estados.');
        $this->assertSame(1, count(array_filter($vistos, fn ($o) => $o === PubAprovacaoComposicao::PENDENTE)), 'mais de um caso virou Pendente — FIN-07 reprova: há fonte nos outros quatro');

        // ⚠️ E a prova de que a nota sai do Support LIDO, não de uma cópia: as 4 frases da fonte
        // são exatamente as de `ConferenciaDeFrete::ORIGENS`.
        $this->assertCount(4, ConferenciaDeFrete::ORIGENS, 'ISCA CEGA: `ConferenciaDeFrete::ORIGENS` mudou de tamanho — o mapa precisa ser reavaliado, não ajustado');
        $this->assertSame('estimado pela tabela', ConferenciaDeFrete::ORIGENS['tabela']);
    }

    public function test_fin_07_o_frete_informado_a_mao_vence_a_precificacao_e_nasce_confirmado(): void
    {
        $oferta = $this->umaOferta();
        $vivo = DadosEfetivosService::fretesDaLinha(['classico' => ['frete' => 31.00, 'frete_origem' => 'sugerido', 'frete_sugerido' => ['fonte' => 'tabela']]], 77);

        // Sem informe, a linha é a da Precificação: Estimado pela tabela.
        $semInforme = PubAprovacaoComposicao::linhaDeFrete($vivo[self::CLASSICO]);
        $this->assertSame(PubAprovacaoComposicao::ESTIMADO, $semInforme['origem']);
        $this->assertSame(31.00, $semInforme['valor']);

        // ⚠️ MOD-03 — o informado à mão VENCE e nasce **Confirmado**, com a nota nomeando a origem
        // escolhida no modal E a data (é o `fNote = origem + ' · ' + agora` do protótipo).
        $informado = PubAprovacaoFrete::informar($oferta, 55.00, PubAprovacaoFrete::TABELA_CLIENTE);
        $comInforme = PubAprovacaoComposicao::linhaDeFrete($vivo[self::CLASSICO], $informado);

        $this->assertSame(PubAprovacaoComposicao::CONFIRMADO, $comInforme['origem'], 'o frete informado à mão não venceu o da Precificação (MOD-03)');
        $this->assertSame(55.00, $comInforme['valor'], 'o valor da Precificação sobrepôs o informado à mão');
        $this->assertStringContainsString('Tabela do cliente', $comInforme['nota'], 'a nota do informado não NOMEIA a origem escolhida no modal');
        $this->assertStringContainsString('15/10/2026', $comInforme['nota'], 'a nota do informado não traz a data — sem ela não se sabe de quando é a confirmação');

        // ISCA: a nota do informado é DIFERENTE da nota da Precificação (senão a sonda acima
        // passaria com as duas iguais e nada estaria sendo distinguido).
        $this->assertNotSame($semInforme['nota'], $comInforme['nota'], 'ISCA CEGA: as duas notas são iguais — a origem da linha não está sendo distinguida.');
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // DADO-13 — a composição congelada no ato
    // ═════════════════════════════════════════════════════════════════════════════════════════

    public function test_composicao_incompleta_tem_resultado_margem_e_total_nulos_e_recusa_resultado_preenchido(): void
    {
        $oferta = $this->umaOferta();

        $incompleta = PubAprovacaoComposicao::create([
            'oferta_id' => $oferta->id,
            'versao' => 1,
            'momento' => PubAprovacaoComposicao::APROVACAO,
            'preco' => 489.90,
            'linhas' => [['chave' => 'frete', 'valor' => null, 'origem' => PubAprovacaoComposicao::PENDENTE, 'nota' => 'A Precificação está sem frete']],
            'incompleto' => true,
            'margem_minima' => 12.00,
            'margem_minima_origem' => PubAprovacaoComposicao::MINIMO_CONFIG,
        ]);

        // ⚠️ FIN-05/D4 — os três são `null`, NUNCA zero. Zero é um número e a tela o mostraria
        // como se fosse verdade.
        foreach (['total', 'resultado', 'margem'] as $campo) {
            $this->assertNull($incompleta->$campo, "`{$campo}` virou um valor numa composição incompleta — a tela diria 'R$ 0,00' em vez de 'Incompleto'");
            $this->assertNull($incompleta->fresh()->$campo, "`{$campo}` nasceu zero NO BANCO (o default do Eloquent e o da coluna divergiram)");
        }

        // ⚠️ E o modelo RECUSA a combinação impossível. Sem esta recusa, `incompleto = true` com
        // `resultado = 0` seria gravável, e a auditoria-depois leria um resultado que ninguém
        // calculou.
        try {
            PubAprovacaoComposicao::create([
                'oferta_id' => $oferta->id,
                'versao' => 2,
                'momento' => PubAprovacaoComposicao::APROVACAO,
                'preco' => 489.90,
                'linhas' => [],
                'incompleto' => true,
                'resultado' => 0,
                'margem_minima' => 12.00,
                'margem_minima_origem' => PubAprovacaoComposicao::MINIMO_CONFIG,
            ]);
            $this->fail('`incompleto = true` com `resultado` preenchido foi aceito');
        } catch (RegraViolada $e) {
            $this->assertSame(PubAprovacaoComposicao::REGRA_INCOMPLETO_COM_RESULTADO, $e->regra);
        }

        $this->assertSame(1, PubAprovacaoComposicao::where('oferta_id', $oferta->id)->count(), 'a linha recusada foi gravada de qualquer jeito');

        // ISCA: a composição COMPLETA do mesmo ponto de partida passa, com os três preenchidos.
        $completa = PubAprovacaoComposicao::create([
            'oferta_id' => $oferta->id,
            'versao' => 2,
            'momento' => PubAprovacaoComposicao::APROVACAO,
            'preco' => 489.90,
            'linhas' => [],
            'total' => 300.00,
            'resultado' => 189.90,
            'margem' => 38.763,
            'incompleto' => false,
            'margem_minima' => 12.00,
            'margem_minima_origem' => PubAprovacaoComposicao::MINIMO_PARAMETRO,
        ]);
        $this->assertSame(189.90, (float) $completa->fresh()->resultado, 'ISCA CEGA: a composição completa também não grava resultado.');
    }

    public function test_a_excecao_deliberada_da_embalagem_e_gravavel_e_esta_escrita_com_data_e_razao(): void
    {
        $oferta = $this->umaOferta();

        // ⚠️⚠️ A ÚNICA EXCEÇÃO À REGRA "faltando item o resultado é null" (decisão do usuário,
        // 14/10/2026): embalagem sem fonte **NÃO** torna a composição incompleta. O resultado
        // existe e vem marcado `estimado_por_baixo` — o real é MENOR.
        $linha = PubAprovacaoComposicao::create([
            'oferta_id' => $oferta->id,
            'versao' => 1,
            'momento' => PubAprovacaoComposicao::APROVACAO,
            'preco' => 489.90,
            'linhas' => [
                ['chave' => 'embalagem', 'valor' => null, 'origem' => PubAprovacaoComposicao::PENDENTE, 'nota' => 'Informado pelo cliente'],
                ['chave' => 'outros', 'valor' => 0.0, 'origem' => PubAprovacaoComposicao::CONFIRMADO, 'nota' => 'Cadastro do produto'],
            ],
            'total' => 300.00,
            'resultado' => 189.90,
            'margem' => 38.763,
            'incompleto' => false,
            'estimado_por_baixo' => true,
            'margem_minima' => 12.00,
            'margem_minima_origem' => PubAprovacaoComposicao::MINIMO_CONFIG,
        ])->fresh();

        $this->assertFalse($linha->incompleto, 'embalagem sem fonte anulou o resultado — sem a exceção o painel diria "Incompleto" em 100% das ofertas');
        $this->assertTrue($linha->estimado_por_baixo);
        $this->assertSame(189.90, (float) $linha->resultado);

        // ⚠️ QUEM ANULA O RESULTADO são só CINCO, e embalagem/outros NÃO estão entre eles.
        $this->assertSame(['custo', 'comissao', 'tarifa_fixa', 'frete', 'imposto'], PubAprovacaoComposicao::ANULAM_O_RESULTADO);
        $this->assertNotContains('embalagem', PubAprovacaoComposicao::ANULAM_O_RESULTADO, 'a embalagem voltou a anular o resultado — a exceção de 14/10 foi "uniformizada"');
        $this->assertNotContains('outros', PubAprovacaoComposicao::ANULAM_O_RESULTADO);

        // As 7 linhas existem e as 5 que anulam são um SUBCONJUNTO delas (isca: se alguém
        // renomear uma chave, os dois lados divergem em silêncio).
        $this->assertCount(7, PubAprovacaoComposicao::LINHAS, 'as 7 linhas da composição mudaram de número');
        foreach (PubAprovacaoComposicao::ANULAM_O_RESULTADO as $chave) {
            $this->assertArrayHasKey($chave, PubAprovacaoComposicao::LINHAS, "`{$chave}` anula o resultado e não é uma das 7 linhas");
        }

        // ⚠️ A EXCEÇÃO ESTÁ ESCRITA COM **DATA E RAZÃO** no docblock da migration — é isso que
        // impede alguém de "uniformizar" a regra depois. Sem a razão escrita, a exceção parece
        // descuido.
        $texto = $this->textoDaMigration();
        $this->assertStringContainsString('14/10/2026', $texto, 'a exceção da embalagem está sem DATA no docblock');
        $this->assertStringContainsString('estimado_por_baixo', $texto);
        $this->assertMatchesRegularExpression('/100%/', $texto, 'a RAZÃO da exceção (o painel diria "Incompleto" em 100% das ofertas) não está escrita');
        $this->assertStringContainsString('EXCEÇÃO DELIBERADA', mb_strtoupper($texto), 'o docblock não declara a exceção como deliberada');
    }

    public function test_margem_minima_e_lida_da_linha_congelada_com_a_origem_do_fallback(): void
    {
        $oferta = $this->umaOferta();

        $congelada = PubAprovacaoComposicao::create([
            'oferta_id' => $oferta->id,
            'versao' => 1,
            'momento' => PubAprovacaoComposicao::APROVACAO,
            'preco' => 489.90,
            'linhas' => [],
            'total' => 300.00,
            'resultado' => 189.90,
            'margem' => 38.763,
            'margem_minima' => 15.50,
            'margem_minima_origem' => PubAprovacaoComposicao::MINIMO_PARAMETRO,
        ])->fresh();

        // ⚠️ A config MUDA DEPOIS do ato. A linha congelada não acompanha — é essa a diferença
        // entre auditoria e recálculo.
        config(['publicador.aprovacao.margem_minima' => 99.0]);

        $this->assertSame(15.50, $congelada->margemMinimaCongelada(), 'a margem mínima foi relida de config — a auditoria-depois reconstruiria a decisão ERRADA');
        $this->assertSame(PubAprovacaoComposicao::MINIMO_PARAMETRO, $congelada->margem_minima_origem);

        // ⚠️ E a ORIGEM do mínimo é gravável nos DOIS lados do fallback (decisão do usuário: vem
        // do parâmetro da empresa quando > 0, senão da config — a regra é do plano 186-07; aqui
        // guarda-se de QUAL LADO veio). Sem esta coluna a auditoria-depois não reconstrói a decisão.
        $doConfig = PubAprovacaoComposicao::create([
            'oferta_id' => $oferta->id,
            'versao' => 2,
            'momento' => PubAprovacaoComposicao::APROVACAO,
            'preco' => 489.90,
            'linhas' => [],
            'margem_minima' => 12.00,
            'margem_minima_origem' => PubAprovacaoComposicao::MINIMO_CONFIG,
            'incompleto' => true,
        ])->fresh();

        $this->assertSame(PubAprovacaoComposicao::MINIMO_CONFIG, $doConfig->margem_minima_origem);
        $this->assertCount(2, PubAprovacaoComposicao::ORIGENS_DO_MINIMO, 'os dois lados do fallback mudaram de número');
        $this->assertNotSame($congelada->margem_minima_origem, $doConfig->margem_minima_origem, 'ISCA CEGA: os dois lados do fallback gravam o mesmo valor — a coluna não distingue nada.');

        // ⚠️ VOCABULÁRIO TRAVADO (VOC-03): os dois nomes, e nunca o terceiro — nada aqui desconta
        // despesa fixa nem devolução.
        $this->assertSame('Resultado estimado por venda', PubAprovacaoComposicao::ROTULO_RESULTADO);
        $this->assertSame('Margem de contribuição estimada', PubAprovacaoComposicao::ROTULO_MARGEM);
    }

    public function test_o_congelamento_e_unico_por_versao_e_momento(): void
    {
        $oferta = $this->umaOferta();

        $base = [
            'oferta_id' => $oferta->id,
            'versao' => 7,
            'momento' => PubAprovacaoComposicao::APROVACAO,
            'preco' => 489.90,
            'linhas' => [],
            'incompleto' => true,
            'margem_minima' => 12.00,
            'margem_minima_origem' => PubAprovacaoComposicao::MINIMO_CONFIG,
        ];

        PubAprovacaoComposicao::create($base);

        // ⚠️ T-186-22 — sem o unique, duas linhas do MESMO ato contariam histórias diferentes, e a
        // auditoria-depois de BLOQ-09 não teria como escolher (e **não existe desfazer**).
        try {
            PubAprovacaoComposicao::create($base);
            $this->fail('o unique (oferta_id, versao, momento) não existe — dois congelamentos do mesmo ato');
        } catch (\Illuminate\Database\QueryException $e) {
            $this->assertStringContainsStringIgnoringCase('unique', $e->getMessage().' unique');
        }

        // ISCA: o OUTRO momento da MESMA versão passa (exceção é ato diferente, não duplicata).
        PubAprovacaoComposicao::create(array_merge($base, ['momento' => PubAprovacaoComposicao::EXCECAO]));
        $this->assertSame(2, PubAprovacaoComposicao::where('oferta_id', $oferta->id)->count(), 'ISCA CEGA: o unique barrou o momento `excecao`, que é ato diferente.');

        $this->assertCount(2, PubAprovacaoComposicao::MOMENTOS, 'os momentos de congelamento mudaram de número');
        $this->assertSame(['aprovacao', 'excecao'], array_keys(PubAprovacaoComposicao::MOMENTOS));
    }

    public function test_apagar_a_oferta_leva_o_frete_e_a_composicao_por_cascade(): void
    {
        $oferta = $this->umaOferta();
        $outra = $this->umaOferta();

        PubAprovacaoFrete::informar($oferta, 42.90, PubAprovacaoFrete::SIMULADOR);
        PubAprovacaoFrete::informar($outra, 11.00, PubAprovacaoFrete::SIMULADOR);
        foreach ([$oferta, $outra] as $o) {
            PubAprovacaoComposicao::create([
                'oferta_id' => $o->id, 'versao' => 1, 'momento' => PubAprovacaoComposicao::APROVACAO,
                'preco' => 489.90, 'linhas' => [], 'incompleto' => true,
                'margem_minima' => 12.00, 'margem_minima_origem' => PubAprovacaoComposicao::MINIMO_CONFIG,
            ]);
        }

        $oferta->delete();

        $this->assertSame(0, PubAprovacaoFrete::where('oferta_id', $oferta->id)->count());
        $this->assertSame(0, PubAprovacaoComposicao::where('oferta_id', $oferta->id)->count());

        // ⚠️ ISCA DO CASCADE: a oferta VIZINHA está PREENCHIDA e NÃO foi levada. Asserção de
        // ausência com fixture vazio é vazia por construção (GATE-13).
        $this->assertSame(1, PubAprovacaoFrete::where('oferta_id', $outra->id)->count(), 'ISCA CEGA: o CASCADE levou a oferta vizinha junto.');
        $this->assertSame(1, PubAprovacaoComposicao::where('oferta_id', $outra->id)->count());
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // ⚠️⚠️ A FOLGA DA `margem` — O BUG CORRIGIDO, COM O MODO DE FALHAR ASSIMÉTRICO
    // ═════════════════════════════════════════════════════════════════════════════════════════

    public function test_a_margem_tem_folga_para_o_percentual_que_este_projeto_ja_mediu(): void
    {
        // ⚠️⚠️ `decimal(7,3)` comporta no máximo **±9999,999**, e este projeto já mediu
        // **+20738%** numa carteira (`project_gate_roll_delta_aceito_ressalvas`). O modo de falhar
        // é ASSIMÉTRICO: no MySQL 8.0.46 é **erro 1264** e a gravação cai; no SQLite dos testes
        // **grava CALADO**, truncado. A suíte ficaria verde e o deploy quebraria.
        //
        // ⚠️ Por isso este gate NÃO tenta gravar e conferir (no SQLite isso passaria com 7,3
        // também): ele lê a PRECISÃO DECLARADA — no texto da migration E no schema vivo.
        $codigo = $this->semComentarios($this->textoDaMigration());

        $this->assertMatchesRegularExpression(
            "/->decimal\('margem',\s*12,\s*3\)/",
            $codigo,
            '`margem` não é `decimal(12,3)` — com 7,3 o teto é ±9999,999 e este projeto já mediu +20738% (erro 1264 no MySQL, gravação CALADA no SQLite)'
        );
        $this->assertDoesNotMatchRegularExpression("/->decimal\('margem',\s*7,/", $codigo);

        // E no SCHEMA VIVO: a precisão tem de ser suficiente para o número já medido.
        [$precisao, $escala] = $this->precisaoDe('pub_aprovacao_composicoes', 'margem');
        $this->assertGreaterThanOrEqual(3, $escala, 'a escala de `margem` caiu abaixo de 3 casas');
        $inteiras = $precisao - $escala;
        $this->assertGreaterThanOrEqual(9, $inteiras, "`margem` comporta só {$inteiras} dígitos inteiros — o +20738% já medido precisa de 5");

        // ⚠️ ISCA DA RÉGUA, nos dois sentidos: ela acusa 7,3 e aceita 12,3 contra o caso real.
        $jaMedido = 20738.0;
        $this->assertGreaterThan(10 ** (7 - 3) - 1, $jaMedido, 'ISCA CEGA: a régua não acusa que 7,3 estoura com o +20738% já medido.');
        $this->assertLessThan(10 ** $inteiras, $jaMedido, 'ISCA CEGA: a régua diria que nem 12,3 comporta o número medido — ela está errada.');

        // E o modo de falhar está ESCRITO no docblock, com os dois bancos nomeados.
        $cru = $this->textoDaMigration();
        foreach (['1264', '20738', 'MySQL 8.0.46', 'SQLite'] as $pedaco) {
            $this->assertStringContainsString($pedaco, $cru, "o docblock não registra `{$pedaco}` — o modo de falhar assimétrico precisa estar escrito, senão alguém 'economiza' a precisão depois");
        }

        // ⚠️ `margem_minima` é `decimal(5,2)` DE PROPÓSITO (é limite configurado por gente), e o
        // contraste com a `margem` é o que prova que a folga da outra não foi descuido.
        [, $escalaMin] = $this->precisaoDe('pub_aprovacao_composicoes', 'margem_minima');
        $this->assertSame(2, $escalaMin);
    }

    /** @return array{0: int, 1: int} [precisão, escala] da coluna no SCHEMA VIVO. */
    private function precisaoDe(string $tabela, string $coluna): array
    {
        foreach (Schema::getColumns($tabela) as $c) {
            if (($c['name'] ?? null) !== $coluna) {
                continue;
            }
            if (preg_match('/\((\d+)\s*,\s*(\d+)\)/', (string) ($c['type'] ?? ''), $m)) {
                return [(int) $m[1], (int) $m[2]];
            }
            $this->fail("não consegui ler a precisão de `{$tabela}.{$coluna}` (tipo: ".($c['type'] ?? '?').')');
        }

        $this->fail("coluna `{$coluna}` não existe em `{$tabela}`");
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // O DEFAULT EM MEMÓRIA × O DEFAULT DA COLUNA
    // ═════════════════════════════════════════════════════════════════════════════════════════

    public function test_os_defaults_do_modelo_e_os_defaults_das_colunas_sao_os_mesmos_com_isca(): void
    {
        // ⚠️⚠️ O Eloquent **NÃO lê o default do banco**. Foi defeito REAL no plano 186-04: a linha
        // nascia com o default no banco e **null em memória**, e "a oferta nasce em preparando" só
        // era verdade depois de um `fresh()`. O conserto repete o default em dois lugares, e por
        // isso este gate compara contra o default lido do **SCHEMA VIVO** — nunca contra outra
        // constante do PHP (comparar PHP com PHP não acusaria divergência nenhuma).
        $oferta = $this->umaOferta();

        $frete = PubAprovacaoFrete::informar($oferta, 42.90, PubAprovacaoFrete::SIMULADOR);
        // EM MEMÓRIA, sem `fresh()` — é esta leitura que estava errada no 186-04.
        $this->assertTrue($frete->vigente, '`vigente` nasceu null em memória — quem informasse o frete leria "não vigente" com a linha vigente no banco');

        $comp = PubAprovacaoComposicao::create([
            'oferta_id' => $oferta->id, 'versao' => 1, 'momento' => PubAprovacaoComposicao::APROVACAO,
            'preco' => 489.90, 'linhas' => [], 'margem_minima' => 12.00,
            'margem_minima_origem' => PubAprovacaoComposicao::MINIMO_CONFIG,
        ]);
        $this->assertFalse($comp->incompleto, '`incompleto` nasceu null em memória');
        $this->assertFalse($comp->estimado_por_baixo, '`estimado_por_baixo` nasceu null em memória');

        // E NO BANCO, pela reconsulta: os dois concordam.
        $this->assertTrue($frete->fresh()->vigente);
        $this->assertFalse($comp->fresh()->incompleto);
        $this->assertFalse($comp->fresh()->estimado_por_baixo);

        // ⚠️ A comparação DIRETA contra o default declarado na COLUNA, lido do schema vivo.
        $esperados = [
            'pub_aprovacao_fretes' => ['vigente' => '1'],
            'pub_aprovacao_composicoes' => ['incompleto' => '0', 'estimado_por_baixo' => '0'],
        ];
        $lidos = 0;
        foreach ($esperados as $tabela => $mapa) {
            foreach (Schema::getColumns($tabela) as $coluna) {
                if (! array_key_exists($coluna['name'], $mapa)) {
                    continue;
                }
                $lidos++;
                $default = trim((string) ($coluna['default'] ?? ''), "'\"");
                $this->assertSame($mapa[$coluna['name']], $default, "o default da COLUNA `{$tabela}.{$coluna['name']}` divergiu do default do modelo");
            }
        }

        // ISCA: a sonda leu os 3 defaults de verdade. Zero lidos faria o laço rodar sobre nada.
        $this->assertSame(3, $lidos, 'ISCA CEGA: a sonda não leu os 3 defaults com default declarado.');

        // ISCA do outro lado: coluna SEM default continua sem default (a sonda não inventa).
        foreach (Schema::getColumns('pub_aprovacao_fretes') as $coluna) {
            if ($coluna['name'] === 'origem') {
                $this->assertSame('', trim((string) ($coluna['default'] ?? ''), "'\""), 'ISCA CEGA: a sonda devolve default para coluna que não tem.');
            }
        }
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // A DISCIPLINA DA MIGRATION (MIG-01..09)
    //
    // ⚠️ `MigrationDoPortaoGuardaTest` (plano 186-03) declara SÓ as duas migrations daquele plano,
    // e o guarda do 186-04 declara só a dele. A migration deste plano fica FORA dos dois; sem
    // guarda próprio, a disciplina estaria "coberta" por gates que nunca a leem — a definição de
    // passar por omissão.
    // ═════════════════════════════════════════════════════════════════════════════════════════

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

    private function textoDaMigration(): string
    {
        $abs = base_path(self::MIGRATION);
        $this->assertFileExists($abs, 'migration declarada e ausente no disco — toda sonda de texto mediria NADA');
        $this->assertFileDoesNotExist(base_path(self::MIGRATION_FANTASMA), 'ISCA DE CAMINHO: `file_exists` passou a devolver `true` para caminho inventado');

        return file_get_contents($abs);
    }

    public function test_guarda_nenhum_nome_de_indice_ou_fk_passa_de_64_medido_por_strlen_com_isca(): void
    {
        $texto = $this->textoDaMigration();

        // (1) Os nomes DECLARADOS no texto. É o 1059 de verdade: nome acima de 64 deixa a
        // migration `Pending` com a tabela JÁ criada — o pior estado possível.
        preg_match_all("/->(?:unique|index|foreign)\(\s*(?:'[^']*'|\[[^\]]*\])\s*,\s*'([^']+)'\s*\)/", $texto, $m);
        $declarados = $m[1];

        // ⚠️ ISCA DA SONDA: ela tem de ACHAR os 6 nomes declarados (3 índices + 3 FKs). Zero
        // achados faria o laço rodar sobre lista vazia — verde, medindo nada (foi assim que
        // `->foreignI` não achou FK nenhuma na wave 1).
        $this->assertCount(6, $declarados, 'ISCA CEGA: a sonda não achou os 6 nomes declarados (3 índices + 3 FKs).');
        $this->assertContains('pubapfr_oferta_ix', $declarados);
        $this->assertContains('pubapcp_versao_uq', $declarados);
        $this->assertContains('pubapfr_autor_fk', $declarados);

        foreach ($declarados as $nome) {
            $this->assertLessThanOrEqual(64, strlen($nome), "o nome DECLARADO `{$nome}` tem ".strlen($nome).' caracteres e estoura o limite do MariaDB (erro 1059)');
        }

        // ⚠️ O MAIOR, TRAVADO: mudar o schema sem reconferir o docblock reprova aqui. É `strlen`,
        // nunca número copiado à mão — o plano 186-03 mediu o plano dele errando em 1 num destes.
        $maior = array_reduce($declarados, fn ($c, $n) => strlen($n) > strlen((string) $c) ? $n : $c, '');
        $this->assertSame(17, strlen($maior), 'o maior nome declarado mudou de tamanho — reconferir o §5 do docblock');

        // (2) Os nomes **PADRÃO DO LARAVEL** — contados porque são os que ESTOURAM. Nenhum é
        // usado (toda FK é nomeada à mão), mas o cálculo trava o crescimento dos nomes.
        $padrao = [];
        foreach ([['pub_aprovacao_fretes', 'oferta_id'], ['pub_aprovacao_fretes', 'informado_por'], ['pub_aprovacao_composicoes', 'oferta_id']] as [$tabela, $coluna]) {
            $this->assertTrue(Schema::hasColumn($tabela, $coluna), "a coluna `{$tabela}.{$coluna}` sumiu — o cálculo do nome padrão mediria o nada");
            $nome = "{$tabela}_{$coluna}_foreign";
            $padrao[$nome] = strlen($nome);
            $this->assertLessThanOrEqual(64, $padrao[$nome]);
        }
        $this->assertSame(43, max($padrao), 'o maior nome PADRÃO do Laravel mudou — reconferir o §5 do docblock');
        $this->assertSame(43, $padrao['pub_aprovacao_composicoes_oferta_id_foreign']);

        // (3) ⚠️ E O QUE O `information_schema` DIZ: nenhum índice caiu em nome PADRÃO. O docblock
        // pode estar certo e o banco errado — é a diferença entre ler o texto e medir o schema.
        foreach (['pub_aprovacao_fretes', 'pub_aprovacao_composicoes'] as $tabela) {
            $nomes = array_map(fn ($i) => $i['name'], Schema::getIndexes($tabela));
            $this->assertNotEmpty($nomes, "ISCA CEGA: a varredura não achou índice nenhum em `{$tabela}`.");
            foreach ($nomes as $nome) {
                $this->assertLessThanOrEqual(64, strlen($nome), "o índice VIVO `{$nome}` passa de 64");
                $this->assertStringNotContainsString('_foreign', $nome, "o índice VIVO `{$nome}` caiu em nome PADRÃO do Laravel — alguém usou `->constrained()`");
            }
        }

        // (4) `->constrained()` não é usado: ele é quem gera o nome padrão sem ninguém ver.
        $this->assertStringNotContainsString('constrained(', $this->semComentarios($texto), 'apareceu `->constrained()`: o nome da FK passaria a ser gerado, e é ele que estoura 64');

        // ISCA do limite: um nome de 65 TEM de ser acusado pela mesma régua.
        $this->assertGreaterThan(64, strlen(str_repeat('x', 65)), 'ISCA CEGA: a régua de 64 não acusa nem 65 caracteres.');
    }

    public function test_guarda_toda_fk_nullondelete_tem_coluna_nullable_explicito_com_isca(): void
    {
        $codigo = $this->semComentarios($this->textoDaMigration());

        // ⚠️⚠️ A ARMADILHA MEDIDA NA WAVE 1: `->foreignId?\(` casa `foreignI`, **não** `foreign(`,
        // e a sonda não achava FK nenhuma. O regex abaixo é testado contra um caso que DEVE casar.
        $regexFk = "/->foreign\(\s*'([^']+)'\s*,\s*'[^']+'\s*\)(.*?);/s";
        preg_match_all($regexFk, $codigo, $m, PREG_SET_ORDER);

        // ISCA DA SONDA: as 3 FKs têm de ser achadas.
        $this->assertCount(3, $m, 'ISCA CEGA: a sonda não achou as 3 FKs — o pareamento rodaria sobre lista vazia.');

        $comNullOnDelete = [];
        foreach ($m as $fk) {
            if (str_contains($fk[2], 'nullOnDelete')) {
                $comNullOnDelete[] = $fk[1];
            }
        }

        // Aqui a única `nullOnDelete` é `informado_por`.
        $this->assertSame(['informado_por'], $comNullOnDelete, 'ISCA CEGA: a sonda não separou a FK `nullOnDelete` das CASCADE.');

        foreach ($comNullOnDelete as $coluna) {
            // ⚠️ O 1830 do MariaDB: `nullOnDelete` em coluna NOT NULL quebra o deploy, e o SQLite
            // **não pega**. O `nullable()` tem de estar EXPLÍCITO no texto.
            $this->assertMatchesRegularExpression(
                "/->unsignedBigInteger\('".preg_quote($coluna, '/')."'\)->nullable\(\)/",
                $codigo,
                "a FK `{$coluna}` é `nullOnDelete` e a coluna não tem `->nullable()` explícito (erro 1830, invisível no SQLite)"
            );
            $this->assertTrue($this->colunaEAnulavel('pub_aprovacao_fretes', $coluna), "a coluna `{$coluna}` não é anulável no schema vivo");
        }

        // As 2 CASCADE são NOT NULL de propósito: frete ou composição sem oferta não é nada.
        $this->assertFalse($this->colunaEAnulavel('pub_aprovacao_fretes', 'oferta_id'));
        $this->assertFalse($this->colunaEAnulavel('pub_aprovacao_composicoes', 'oferta_id'));

        // ⚠️ ISCA DO REGEX, nas duas direções: um caso sintético que DEVE casar e um que NÃO.
        $deveCasar = "<?php \$t->foreign('quem_id', 'xx_fk')->references('id')->on('users')->nullOnDelete();";
        preg_match_all($regexFk, $deveCasar, $certo, PREG_SET_ORDER);
        $this->assertCount(1, $certo, 'ISCA CEGA: o regex de FK não casa nem o caso canônico — era o bug de `->foreignI`.');
        $this->assertSame('quem_id', $certo[0][1]);
        $this->assertStringContainsString('nullOnDelete', $certo[0][2]);

        $naoDeveCasar = "<?php \$t->index('quem_id', 'xx_ix');";
        preg_match_all($regexFk, $naoDeveCasar, $errado, PREG_SET_ORDER);
        $this->assertCount(0, $errado, 'ISCA: o regex de FK casou um `index()` — ele mediria a coisa errada.');
    }

    private function colunaEAnulavel(string $tabela, string $coluna): bool
    {
        foreach (Schema::getColumns($tabela) as $c) {
            if (($c['name'] ?? null) === $coluna) {
                return (bool) ($c['nullable'] ?? false);
            }
        }

        $this->fail("coluna `{$coluna}` não existe em `{$tabela}`");
    }

    public function test_guarda_a_migration_nao_usa_enum_nem_try_catch_e_o_up_e_idempotente_por_tabela(): void
    {
        $cru = $this->textoDaMigration();
        $codigo = $this->semComentarios($cru);

        // ⚠️⚠️ LER O CÓDIGO SEM COMENTÁRIOS NÃO É COSMÉTICO. O docblock **explica** que não usa
        // `enum` nem `try/catch`, e um gate que conte a palavra no arquivo inteiro casa com a
        // frase que diz que a coisa não é usada e reprova o PRÓPRIO CONSERTO.
        $this->assertStringNotContainsString('enum', $codigo, 'MIG-04: `enum` no CÓDIGO — o CHECK é enforçado no SQLite dos testes e cegaria a suíte');
        $this->assertStringNotContainsString('try', $codigo, 'MIG-05: `try` no CÓDIGO — neste projeto um 1553 ficou engolido 2 meses dentro de um catch');
        $this->assertStringNotContainsString('catch', $codigo);

        // ISCA DO TOKENIZADOR, nos dois sentidos: a palavra em COMENTÁRIO desaparece e a em
        // CÓDIGO sobrevive — e o CÓDIGO não é comido junto.
        $soComentario = "<?php\n// Sem `enum()` e sem try/catch em volta de DDL.\n\$t->string('origem', 28);\n";
        $noCodigo = "<?php\n\$t->enum('origem', ['a']);\n";
        $this->assertStringNotContainsString('enum', $this->semComentarios($soComentario), 'ISCA CEGA: o tokenizador não tirou a palavra que só aparecia no comentário.');
        $this->assertStringNotContainsString('try', $this->semComentarios($soComentario), 'ISCA CEGA: `try` do comentário sobreviveu — o gate reprovaria pelo próprio conserto.');
        $this->assertStringContainsString("\$t->string('origem', 28)", $this->semComentarios($soComentario), 'ISCA: o tokenizador comeu o CÓDIGO junto com o comentário.');
        $this->assertStringContainsString('enum', $this->semComentarios($noCodigo), 'ISCA CEGA: a palavra em CÓDIGO tem de sobreviver, senão a sonda nunca acha nada.');

        // ⚠️ E a prova de que o docblock REALMENTE contém as palavras — senão "não achei no
        // código" seria verdade pelo motivo errado (o arquivo não falar do assunto).
        $this->assertStringContainsString('enum', $cru, 'ISCA: o docblock deixou de explicar por que não há `enum`');
        $this->assertStringContainsString('try/catch', $cru, 'ISCA: o docblock deixou de explicar por que não há try/catch');

        // MIG-05 — `up()` idempotente, **uma guarda por tabela**: se o deploy morrer ENTRE as
        // duas, a re-execução completa só o que faltou.
        foreach (['pub_aprovacao_fretes', 'pub_aprovacao_composicoes'] as $tabela) {
            $this->assertStringContainsString("Schema::hasTable('{$tabela}')", $codigo, "MIG-05: `{$tabela}` não tem guarda própria — o deploy que morrer entre as duas não re-executa");
        }
        $this->assertSame(2, substr_count($codigo, 'Schema::hasTable('), 'as duas tabelas precisam de UMA guarda cada');

        // MIG-07 — o docblock vem ANTES do código e TRAZ a decisão de schema.
        $this->assertStringContainsString('DECISÃO DE SCHEMA', $cru);
        $this->assertLessThan(strpos($cru, 'Schema::create('), strpos($cru, 'DECISÃO DE SCHEMA'), 'a tabela de decisão de schema tem de vir ANTES do código');

        // ⚠️ E a DECISÃO DE DADO-13 está escrita: derivada da fonte viva + congelada no ato, com a
        // razão (não há desfazer). Sem a razão, alguém "otimiza" para uma cópia contínua.
        $this->assertStringContainsString('DERIVADA da fonte viva', $cru, 'a decisão de DADO-13 não está no docblock');
        $this->assertStringContainsString('186-07', $cru, 'o docblock não nomeia o plano dono da derivação');
        $this->assertMatchesRegularExpression('/não existe desfazer|sem desfazer|não há desfazer/u', $cru, 'a RAZÃO da decisão (não há desfazer) não está escrita');

        // MIG-09 — nenhuma coluna renomeada, nenhum ALTER, nenhum backfill.
        foreach (['->change(', 'renameColumn(', 'dropColumn(', '->delete(', 'truncate('] as $destrutivo) {
            $this->assertStringNotContainsString($destrutivo, $codigo, "MIG-09: `{$destrutivo}` numa migration que só CRIA");
        }
    }

    public function test_guarda_o_down_derruba_as_duas_tabelas_filha_antes_de_mae(): void
    {
        $codigo = $this->semComentarios($this->textoDaMigration());

        $inicio = strpos($codigo, 'function down()');
        $this->assertNotFalse($inicio, 'ISCA CEGA: não achei o `down()` no código.');
        $down = substr($codigo, $inicio);

        // ⚠️⚠️ A SONDA QUE **NÃO** ENTRA SOZINHA: "`dropForeign([` antes de cada `dropIndex`" roda
        // sobre **ZERO casos** numa migration de `create` — o `dropIfExists` da tabela inteira
        // subsume o par, que é a receita ADITIVA. **Gate que roda sobre zero casos não prova
        // nada**, então ela fica COM ISCA e o invariante que VALE tem asserção própria.
        $this->assertSame(0, substr_count($down, 'dropIndex('), 'apareceu `dropIndex` num `down()` de `create` — então o par com `dropForeign` passa a ser obrigatório (erro 1553)');
        $this->assertSame(0, substr_count($down, 'dropForeign('), 'apareceu `dropForeign` sem `dropIndex`: a receita aditiva entrou numa migration de `create`');

        // ISCA DA SONDA DE ORDEM: num texto sintético ADITIVO ela TEM de achar os dois e ver a
        // ordem. Sem ela, os dois `assertSame(0, ...)` acima seriam verdade por construção.
        $aditivo = "function down() { \$t->dropForeign(['x_id']); \$t->dropIndex('x_ix'); }";
        $this->assertSame(1, substr_count($aditivo, 'dropForeign(['), 'ISCA CEGA: a sonda não acha `dropForeign([` nem no caso aditivo.');
        $this->assertSame(1, substr_count($aditivo, 'dropIndex('), 'ISCA CEGA: a sonda não acha `dropIndex(` nem no caso aditivo.');
        $this->assertLessThan(strpos($aditivo, 'dropIndex('), strpos($aditivo, 'dropForeign(['), 'ISCA CEGA: a sonda de ORDEM não distingue antes de depois.');

        // ⚠️ O INVARIANTE QUE VALE: o `down()` derruba as DUAS tabelas por `dropIfExists`.
        $this->assertSame(2, substr_count($down, 'dropIfExists('));
        foreach (['pub_aprovacao_composicoes', 'pub_aprovacao_fretes'] as $minha) {
            $this->assertStringContainsString("Schema::dropIfExists('{$minha}')", $down);
        }
        $this->assertStringNotContainsString('dropColumn(', $down);

        // ⚠️ E NENHUMA DAS MÃES é derrubada — elas são de outros planos e EXISTEM (conferido).
        foreach (['pub_aprovacao_ofertas', 'users'] as $mae) {
            $this->assertStringNotContainsString("dropIfExists('{$mae}')", $down, "o `down()` derrubaria `{$mae}`, que é tabela de OUTRO plano");
            $this->assertTrue(Schema::hasTable($mae), "a tabela-mãe `{$mae}` não existe — a FK aponta para o vazio");
        }

        // ⚠️ Nenhuma estrutura deste plano encosta em `ml_acervo_itens` (verificação da fase).
        $this->assertStringNotContainsString('ml_acervo_itens', $this->textoDaMigration(), 'esta migration encosta em `ml_acervo_itens` — PARAR e ler `.planning/debug/resolved/acervo-deadlock-upsert.md` antes');
    }
}
