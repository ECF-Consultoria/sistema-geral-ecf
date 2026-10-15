<?php

namespace Tests\Unit\Publicador\Aprovacao;

use App\Models\PubAprovacaoComposicao;
use App\Services\Publicador\Aprovacao\ComposicaoDaOferta;
use App\Services\Publicador\Aprovacao\LinhaFinanceira;
use App\Support\Publicador\RegraViolada;
use Tests\TestCase;

/**
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 * Fase 186, plano 07 — OS DOIS VALUE OBJECTS da composição financeira.
 *
 * ⚠️⚠️ DE CADA GATE DESTE ARQUIVO, A PERGUNTA RESPONDIDA ANTES DE ELE EXISTIR:
 * **"o que precisaria estar errado no mundo para ele falhar?"**
 *
 *   · **linha `null` com origem `Confirmado`** — alguém aceitar a origem como PARÂMETRO LIVRE.
 *     A tela mostraria "Confirmado" ao lado de um valor que ninguém leu, e a composição somaria
 *     zero no lugar da ausência. Aqui a origem é **derivada**, e o teste passa
 *     `fonteRespondeu: true` COM `valor: null` de propósito para provar que o objeto **corrige**
 *     em vez de obedecer.
 *   · **nota vazia** — alguém tratar a nota como decoração. A nota **NOMEIA a fonte** (DADO-13 /
 *     VOC-04); vazia, a composição deixa de ser auditável e a auditoria-depois de BLOQ-09 fica
 *     impossível, num fluxo em que **aprovar já publica e não há desfazer**.
 *   · **`0.0` virando `Pendente`** — alguém confundir "medido e é zero" com "não medido". É
 *     literalmente a régua `numeroMedido()` deste projeto, e é o caso REAL do `fixed_fee: 0` do
 *     Mercado Livre: das 17 ocorrências medidas nas fixtures, **16 são zero MEDIDO**.
 *   · **zero onde falta dado** — alguém devolver `0.0` em `total`/`resultado`/`margem` quando a
 *     composição está incompleta. A tela diria "resultado R$ 0,00 por venda" em vez de
 *     "Incompleto" (FIN-05 / D4).
 *   · **a linha informativa entrando na soma** — alguém somar o "Frete pago pelo comprador".
 *     FIN-08 diz que ela **aparece e não entra**; entrando, ela descontaria duas vezes o frete.
 *   · **o `12%` da frase virando literal** — a frase da margem mínima é montada com o valor
 *     RESOLVIDO. Com o número embutido, a tela mentiria para a empresa que configurou outro
 *     mínimo (FIN-09).
 *   · **o teto que virou arredondamento** — `c2` do protótipo arredonda **PARA CIMA**. Com
 *     `round()`, o preço-alvo sairia **abaixo** do necessário e a oferta aprovada ficaria abaixo
 *     do mínimo que o próprio painel pediu.
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 */
class ComposicaoFinanceiraServiceTest extends TestCase
{
    // ═════════════════════════════════════════════════════════════════════════════════════════
    // `LinhaFinanceira` — a origem é DERIVADA, nunca um parâmetro livre
    // ═════════════════════════════════════════════════════════════════════════════════════════

    public function test_linha_nula_com_fonte_que_diz_ter_respondido_e_pendente_por_construcao(): void
    {
        // ⚠️ O caso adverso: quem constrói AFIRMA que a fonte respondeu, e passa `null`.
        $linha = new LinhaFinanceira(
            chave: 'custo',
            rotulo: 'Custo do produto',
            detalhe: 'Sem custo na Precificação',
            valor: null,
            fonteRespondeu: true,
            nota: 'Precificação do Portal',
        );

        $this->assertNull($linha->valor, 'linha nula não pode ganhar valor nenhum');
        $this->assertSame(PubAprovacaoComposicao::PENDENTE, $linha->origem,
            'linha com valor nulo tem de ser Pendente POR CONSTRUÇÃO — origem não é parâmetro livre');
        $this->assertSame('Pendente', $linha->rotuloDaOrigem());
        $this->assertTrue($linha->ehPendente());

        // E o inverso: a fonte que NÃO respondeu também é Pendente, mesmo com valor na mão.
        $semFonte = new LinhaFinanceira(
            chave: 'embalagem', rotulo: 'Embalagem', detalhe: 'Sem fonte',
            valor: 8.0, fonteRespondeu: false, nota: 'Sem fonte de embalagem no sistema',
        );
        $this->assertNull($semFonte->valor, 'fonte que não respondeu não pode carregar valor');
        $this->assertSame(PubAprovacaoComposicao::PENDENTE, $semFonte->origem);

        // ISCA: a mesma construção COM fonte e COM valor nasce Confirmado — senão o objeto
        // devolveria `Pendente` sempre e este gate ficaria verde medindo nada.
        $boa = new LinhaFinanceira(
            chave: 'custo', rotulo: 'Custo do produto', detalhe: '1 un.',
            valor: 10.0, fonteRespondeu: true, nota: 'Precificação do Portal',
        );
        $this->assertSame(PubAprovacaoComposicao::CONFIRMADO, $boa->origem, 'ISCA CEGA: nada nasce Confirmado — o objeto está travado em Pendente.');
    }

    public function test_zero_medido_e_confirmado_mostrando_zero_e_nunca_pendente(): void
    {
        // ⚠️ O caso REAL: `sale_fee_details.fixed_fee: 0` da fixture de 01/10/2026 (price 150).
        $linha = LinhaFinanceira::confirmada(
            'tarifa_fixa', 'Tarifa fixa por venda', 'Não se aplica a este preço',
            0.0, 'Mercado Livre · /sites/MLB/listing_prices',
        );

        $this->assertSame(0.0, $linha->valor, 'zero MEDIDO é zero, não ausência');
        $this->assertSame(PubAprovacaoComposicao::CONFIRMADO, $linha->origem);
        $this->assertFalse($linha->ehPendente(), 'zero medido NUNCA é Pendente (régua `numeroMedido()`)');
        $this->assertSame('Confirmado', $linha->rotuloDaOrigem());

        // ISCA do outro lado: a linha de fato pendente é Pendente.
        $this->assertTrue(LinhaFinanceira::pendente('embalagem', 'Embalagem', '—', 'sem fonte')->ehPendente());
    }

    public function test_linha_exige_nota_que_nomeia_a_fonte(): void
    {
        $this->expectException(RegraViolada::class);
        new LinhaFinanceira(
            chave: 'imposto', rotulo: 'Impostos', detalhe: '—',
            valor: 1.0, fonteRespondeu: true, nota: '   ',
        );
    }

    public function test_estimada_e_informativa_tem_as_origens_e_o_papel_declarados(): void
    {
        $estimada = LinhaFinanceira::estimada('frete', 'Frete pago pelo vendedor', '1 volume', 42.9, 'Precificação do Portal: estimado pela tabela');
        $this->assertSame(PubAprovacaoComposicao::ESTIMADO, $estimada->origem);
        $this->assertSame('Estimado', $estimada->rotuloDaOrigem());
        $this->assertFalse($estimada->informativa);

        $info = LinhaFinanceira::informativa('frete_comprador', 'Frete pago pelo comprador', 'Não entra na conta', null, 'O Mercado Livre cobra do comprador');
        $this->assertTrue($info->informativa, 'FIN-08: a linha do frete do comprador é informativa');
        $this->assertSame(PubAprovacaoComposicao::PENDENTE, $info->origem);

        // A forma congelada leva valor, origem e nota — é ela que vai para `linhas` (json).
        $congelada = $estimada->paraLinhaCongelada();
        $this->assertSame(['chave', 'rotulo', 'detalhe', 'valor', 'origem', 'nota', 'informativa'], array_keys($congelada));
        $this->assertSame(42.9, $congelada['valor']);
        $this->assertSame(PubAprovacaoComposicao::ESTIMADO, $congelada['origem']);
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // `ComposicaoDaOferta` — `null` onde falta dado, e NUNCA `0.0`
    // ═════════════════════════════════════════════════════════════════════════════════════════

    /** As 7 linhas completas, com os números que o teste declara (não são taxas do código). */
    private function seteLinhas(float $preco, float $comissaoPct, float $impostoPct, ?float $frete = 42.9, ?float $embalagem = 8.0): array
    {
        $linhas = [
            LinhaFinanceira::confirmada('custo', 'Custo do produto', '1 un. × R$ 200,00', 200.0, 'Precificação do Portal'),
            LinhaFinanceira::confirmada('comissao', 'Comissão Clássico', 'percentual respondido pelo ML', round($preco * $comissaoPct / 100, 2), 'Mercado Livre · /sites/MLB/listing_prices'),
            LinhaFinanceira::confirmada('tarifa_fixa', 'Tarifa fixa por venda', 'Não se aplica a este preço', 0.0, 'Mercado Livre · /sites/MLB/listing_prices'),
            $frete === null
                ? LinhaFinanceira::pendente('frete', 'Frete pago pelo vendedor', 'Valor ainda não confirmado', 'A Precificação está sem frete para este anúncio.')
                : LinhaFinanceira::estimada('frete', 'Frete pago pelo vendedor', '1 volume', $frete, 'Precificação do Portal: estimado pela tabela'),
            LinhaFinanceira::confirmada('imposto', 'Impostos', 'percentual da empresa', round($preco * $impostoPct / 100, 2), 'Parâmetros de preço da empresa'),
            $embalagem === null
                ? LinhaFinanceira::pendente('embalagem', 'Embalagem', 'Sem fonte no sistema', 'Nenhuma fonte de custo de embalagem no sistema.')
                : LinhaFinanceira::confirmada('embalagem', 'Embalagem', '1 un.', $embalagem, 'Informado pelo cliente'),
            LinhaFinanceira::confirmada('outros', 'Outros custos', 'Nenhum informado', 0.0, 'Cadastro do produto'),
        ];

        return $linhas;
    }

    public function test_as_sete_linhas_na_ordem_do_prototipo_e_o_total_sem_a_informativa(): void
    {
        $preco = 489.90;
        $linhas = $this->seteLinhas($preco, 11.0, 19.0);
        $comInfo = [...$linhas, LinhaFinanceira::informativa('frete_comprador', 'Frete pago pelo comprador', 'Não entra na conta', null, 'O Mercado Livre cobra do comprador')];

        $semInfo = ComposicaoDaOferta::montar(preco: $preco, linhas: $linhas, comissaoPercentual: 11.0, impostoPercentual: 19.0,
            margemMinima: 12.0, margemMinimaOrigem: PubAprovacaoComposicao::MINIMO_CONFIG);
        $comInfoC = ComposicaoDaOferta::montar(preco: $preco, linhas: $comInfo, comissaoPercentual: 11.0, impostoPercentual: 19.0,
            margemMinima: 12.0, margemMinimaOrigem: PubAprovacaoComposicao::MINIMO_CONFIG);

        // A ORDEM das 7 — lida do `fin()` do protótipo (`Aprovacao.dc.html:1061-1079`).
        $this->assertSame(['custo', 'comissao', 'tarifa_fixa', 'frete', 'imposto', 'embalagem', 'outros'],
            array_map(fn (LinhaFinanceira $l) => $l->chave, $comInfoC->linhasDaConta()));
        $this->assertSame(array_keys(PubAprovacaoComposicao::LINHAS),
            array_map(fn (LinhaFinanceira $l) => $l->chave, $comInfoC->linhasDaConta()),
            'as 7 chaves da composição são as 7 do modelo — duas listas livres para divergir seriam dois vocabulários');

        // ⚠️ FIN-08 PROVADO POR REMOÇÃO: tirar a informativa não muda o total.
        $this->assertSame($semInfo->total, $comInfoC->total, 'FIN-08: a linha informativa NÃO entra no total');
        $this->assertSame($semInfo->resultado, $comInfoC->resultado);
        $this->assertCount(8, $comInfoC->linhas);
        $this->assertCount(7, $comInfoC->linhasDaConta());
        $this->assertCount(1, $comInfoC->linhasInformativas());

        // O total é a soma das 7 (a mesma conta do protótipo), conferida aqui à parte.
        $esperado = round(200.0 + round($preco * 0.11, 2) + 0.0 + 42.9 + round($preco * 0.19, 2) + 8.0 + 0.0, 2);
        $this->assertSame($esperado, $semInfo->total);
        $this->assertSame(round($preco - $esperado, 2), $semInfo->resultado);
        $this->assertSame(round($semInfo->resultado / $preco * 100, 4), round($semInfo->margem, 4));
    }

    public function test_frete_ausente_anula_resultado_margem_total_equilibrio_e_preco_alvo_sem_nenhum_zero(): void
    {
        $c = ComposicaoDaOferta::montar(preco: 849.90, linhas: $this->seteLinhas(849.90, 17.0, 19.0, frete: null),
            comissaoPercentual: 17.0, impostoPercentual: 19.0, margemMinima: 12.0, margemMinimaOrigem: PubAprovacaoComposicao::MINIMO_CONFIG);

        $this->assertTrue($c->incompleto);
        foreach (['total', 'resultado', 'margem', 'equilibrio', 'precoAlvo'] as $campo) {
            $this->assertNull($c->$campo, "`{$campo}` tem de ser null quando falta dado — zero seria lido como verdade (FIN-05/D4)");
            $this->assertNotSame(0.0, $c->$campo, "`{$campo}` virou 0.0 no lugar da ausência");
        }
        $this->assertSame('dados incompletos', $c->notaDaMargem, 'FIN-10: a nota acompanha a barra sempre — cor nunca é o único sinal');

        // ISCA: a MESMA composição com frete presente tem os cinco campos preenchidos.
        $cheia = ComposicaoDaOferta::montar(preco: 849.90, linhas: $this->seteLinhas(849.90, 17.0, 19.0),
            comissaoPercentual: 17.0, impostoPercentual: 19.0, margemMinima: 12.0, margemMinimaOrigem: PubAprovacaoComposicao::MINIMO_CONFIG);
        foreach (['total', 'resultado', 'margem', 'equilibrio', 'precoAlvo'] as $campo) {
            $this->assertNotNull($cheia->$campo, "ISCA CEGA: `{$campo}` é null até com todos os dados — o gate do null não mede nada.");
        }
        $this->assertFalse($cheia->incompleto);
    }

    public function test_equilibrio_e_preco_alvo_sao_teto_pela_formula_do_prototipo(): void
    {
        $preco = 489.90;
        $comissao = 11.0;
        $imposto = 19.0;
        $minimo = 12.0;
        $c = ComposicaoDaOferta::montar(preco: $preco, linhas: $this->seteLinhas($preco, $comissao, $imposto),
            comissaoPercentual: $comissao, impostoPercentual: $imposto, margemMinima: $minimo, margemMinimaOrigem: PubAprovacaoComposicao::MINIMO_CONFIG);

        // `fixedSum` = custo + frete + embalagem + outros + tarifa fixa (a composição do protótipo).
        $fixedSum = 200.0 + 42.9 + 8.0 + 0.0 + 0.0;
        $vr = ($comissao + $imposto) / 100;
        $teto = fn (float $n) => ceil($n * 100 - 1e-6) / 100;

        $this->assertSame($fixedSum, $c->somaFixa);
        $this->assertSame($teto($fixedSum / (1 - $vr)), $c->equilibrio);
        $this->assertSame($teto($fixedSum / (1 - $vr - $minimo / 100)), $c->precoAlvo);

        // ⚠️ É TETO, não arredondamento: o `round()` daria MENOS num caso de borda.
        $this->assertGreaterThanOrEqual(round($fixedSum / (1 - $vr), 2), $c->equilibrio);
        $this->assertSame(0.01, round($teto(1.0001) - 1.0, 4), 'ISCA CEGA: `c2` não está arredondando para CIMA — o preço-alvo sairia abaixo do mínimo pedido.');
        $this->assertSame(1.0, $teto(1.0), 'ISCA CEGA: `c2` sobe um centavo até no valor exato — o protótipo subtrai 1e-6 justamente para isso.');
    }

    public function test_a_nota_da_margem_tem_as_quatro_frases_e_o_numero_vem_do_minimo_resolvido(): void
    {
        $linhas = fn (float $p, float $custo) => [
            LinhaFinanceira::confirmada('custo', 'Custo do produto', '1 un.', $custo, 'Precificação do Portal'),
            LinhaFinanceira::confirmada('comissao', 'Comissão Clássico', '—', round($p * 0.11, 2), 'Mercado Livre'),
            LinhaFinanceira::confirmada('tarifa_fixa', 'Tarifa fixa por venda', '—', 0.0, 'Mercado Livre'),
            LinhaFinanceira::confirmada('frete', 'Frete pago pelo vendedor', '—', 0.0, 'Precificação do Portal'),
            LinhaFinanceira::confirmada('imposto', 'Impostos', '—', round($p * 0.19, 2), 'Parâmetros da empresa'),
            LinhaFinanceira::confirmada('embalagem', 'Embalagem', '—', 0.0, 'Informado pelo cliente'),
            LinhaFinanceira::confirmada('outros', 'Outros custos', '—', 0.0, 'Cadastro do produto'),
        ];
        $monta = fn (float $p, float $custo, float $min) => ComposicaoDaOferta::montar(
            preco: $p, linhas: $linhas($p, $custo), comissaoPercentual: 11.0, impostoPercentual: 19.0,
            margemMinima: $min, margemMinimaOrigem: PubAprovacaoComposicao::MINIMO_CONFIG);

        $acima = $monta(100.0, 10.0, 12.0);   // resultado 60,00 → 60%
        $abaixo = $monta(100.0, 65.0, 12.0);  // resultado 5,00 → 5%
        $prejuizo = $monta(100.0, 90.0, 12.0); // resultado −20,00

        $this->assertStringContainsString('acima do mínimo de 12%', $acima->notaDaMargem);
        $this->assertStringContainsString('abaixo do mínimo de 12%', $abaixo->notaDaMargem);
        $this->assertStringContainsString('prejuízo por venda', $prejuizo->notaDaMargem);

        // ⚠️ O NÚMERO DA FRASE VEM DO MÍNIMO RESOLVIDO — e não de um `12` embutido (FIN-09).
        $outroMinimo = $monta(100.0, 65.0, 25.0);
        $this->assertStringContainsString('abaixo do mínimo de 25%', $outroMinimo->notaDaMargem,
            'a frase tem de carregar o mínimo RESOLVIDO; com o número embutido a tela mentiria para quem configurou outro');
        $this->assertStringNotContainsString('12%', $outroMinimo->notaDaMargem);

        // Decimal: o mínimo com casa aparece com casa, em pt-BR.
        $this->assertStringContainsString('mínimo de 12,5%', $monta(100.0, 65.0, 12.5)->notaDaMargem);

        // ⚠️ DECISÃO (9): a nota do portão NOMEIA a fonte da tarifa.
        $this->assertStringContainsString('com a tarifa real do Mercado Livre', $acima->notaDaMargem);

        // ⚠️ VOC-03 / FIN-14: os dois nomes travados, LIDOS do modelo e nunca recopiados. O outro
        // nome — o que promete descontar despesa fixa e devolução — é PROIBIDO: nada nesta conta
        // as desconta, e usá-lo seria afirmação falsa.
        $this->assertSame('Resultado estimado por venda', $acima->rotuloDoResultado());
        $this->assertSame('Margem de contribuição estimada', $acima->rotuloDaMargem());
        $this->assertSame(PubAprovacaoComposicao::ROTULO_RESULTADO, $acima->rotuloDoResultado());
        $this->assertSame(PubAprovacaoComposicao::ROTULO_MARGEM, $acima->rotuloDaMargem());
    }

    public function test_a_excecao_da_embalagem_mantem_o_resultado_e_marca_estimado_por_baixo(): void
    {
        // ⚠️ DECISÃO (7) DO USUÁRIO, 14/10/2026 — a ÚNICA exceção à regra do `null`.
        $preco = 489.90;
        $c = ComposicaoDaOferta::montar(preco: $preco, linhas: $this->seteLinhas($preco, 11.0, 19.0, embalagem: null),
            comissaoPercentual: 11.0, impostoPercentual: 19.0, margemMinima: 12.0, margemMinimaOrigem: PubAprovacaoComposicao::MINIMO_CONFIG);

        $this->assertFalse($c->incompleto, 'embalagem sem fonte NÃO anula o resultado (decisão do usuário, 14/10/2026)');
        $this->assertNotNull($c->resultado);
        $this->assertNotNull($c->equilibrio, 'o atalho do preço-alvo ficaria SEMPRE vazio se o estimado suprimisse o equilíbrio');
        $this->assertNotNull($c->precoAlvo);
        $this->assertTrue($c->estimadoPorBaixo);
        $this->assertStringContainsString('sem o custo de embalagem', $c->notaDaMargem,
            'o resultado é OTIMISTA: a tela tem de dizer que falta um custo');

        // A linha continua VISÍVEL e Pendente — a lacuna não é escondida.
        $embalagem = $c->linha('embalagem');
        $this->assertSame(PubAprovacaoComposicao::PENDENTE, $embalagem->origem);
        $this->assertNull($embalagem->valor);

        // ⚠️ E A REGRA, no MESMO teste: `frete` ausente ANULA. É a diferença entre a exceção e a
        // regra que alguém vai querer uniformizar.
        $regra = ComposicaoDaOferta::montar(preco: $preco, linhas: $this->seteLinhas($preco, 11.0, 19.0, frete: null),
            comissaoPercentual: 11.0, impostoPercentual: 19.0, margemMinima: 12.0, margemMinimaOrigem: PubAprovacaoComposicao::MINIMO_CONFIG);
        $this->assertTrue($regra->incompleto, 'frete ausente ANULA — ele é uma das CINCO');
        $this->assertNull($regra->resultado);

        // As CINCO que anulam, uma a uma — e as DUAS que não.
        foreach (PubAprovacaoComposicao::ANULAM_O_RESULTADO as $chave) {
            $faltando = array_map(
                fn (LinhaFinanceira $l) => $l->chave === $chave
                    ? LinhaFinanceira::pendente($l->chave, $l->rotulo, 'sem fonte', 'fonte não respondeu')
                    : $l,
                $this->seteLinhas($preco, 11.0, 19.0));
            $comp = ComposicaoDaOferta::montar(preco: $preco, linhas: $faltando, comissaoPercentual: 11.0, impostoPercentual: 19.0,
                margemMinima: 12.0, margemMinimaOrigem: PubAprovacaoComposicao::MINIMO_CONFIG);
            $this->assertTrue($comp->incompleto, "`{$chave}` está na lista das cinco e tem de anular o resultado");
        }
        foreach (['embalagem', 'outros'] as $chave) {
            $faltando = array_map(
                fn (LinhaFinanceira $l) => $l->chave === $chave
                    ? LinhaFinanceira::pendente($l->chave, $l->rotulo, 'sem fonte', 'fonte não respondeu')
                    : $l,
                $this->seteLinhas($preco, 11.0, 19.0));
            $comp = ComposicaoDaOferta::montar(preco: $preco, linhas: $faltando, comissaoPercentual: 11.0, impostoPercentual: 19.0,
                margemMinima: 12.0, margemMinimaOrigem: PubAprovacaoComposicao::MINIMO_CONFIG);
            $this->assertFalse($comp->incompleto, "`{$chave}` NÃO está nas cinco — ela marca estimado por baixo, não anula");
            $this->assertTrue($comp->estimadoPorBaixo);
        }
    }

    public function test_outros_custos_nasce_zero_confirmado_e_nunca_pendente(): void
    {
        $c = ComposicaoDaOferta::montar(preco: 489.90, linhas: $this->seteLinhas(489.90, 11.0, 19.0),
            comissaoPercentual: 11.0, impostoPercentual: 19.0, margemMinima: 12.0, margemMinimaOrigem: PubAprovacaoComposicao::MINIMO_CONFIG);

        $outros = $c->linha('outros');
        $this->assertSame(0.0, $outros->valor, '"Outros custos" é R$ 0,00 MEDIDO, não ausência');
        $this->assertNotNull($outros->valor);
        $this->assertSame(PubAprovacaoComposicao::CONFIRMADO, $outros->origem);
        $this->assertFalse($outros->ehPendente());
        $this->assertFalse($c->estimadoPorBaixo, 'zero confirmado em "Outros custos" não marca o resultado como estimado');
    }

    public function test_preco_por_variante_manda_o_pior_caso_e_a_nota_nomeia_quantas_variantes(): void
    {
        $preco = 489.90;
        $linhas = $this->seteLinhas($preco, 11.0, 19.0);

        $varias = ComposicaoDaOferta::montar(preco: $preco, linhas: $linhas, comissaoPercentual: 11.0, impostoPercentual: 19.0,
            margemMinima: 12.0, margemMinimaOrigem: PubAprovacaoComposicao::MINIMO_CONFIG,
            precoDeVariante: 'Cor: Cinza', precosAtivos: 3);
        $uma = ComposicaoDaOferta::montar(preco: $preco, linhas: $linhas, comissaoPercentual: 11.0, impostoPercentual: 19.0,
            margemMinima: 12.0, margemMinimaOrigem: PubAprovacaoComposicao::MINIMO_CONFIG,
            precoDeVariante: null, precosAtivos: 1);

        $this->assertSame('Cor: Cinza', $varias->precoDeVariante);
        $this->assertStringContainsString('pior caso entre 3 variantes', (string) $varias->notaDoPreco);
        $this->assertStringContainsString('Cor: Cinza', (string) $varias->notaDoPreco);
        $this->assertNull($uma->notaDoPreco, 'com um preço só não há nota — o caso comum não ganha ruído');
        $this->assertNull($uma->precoDeVariante);
    }

    public function test_a_composicao_leva_o_minimo_e_de_qual_lado_do_fallback_ele_veio(): void
    {
        $preco = 489.90;
        $doParametro = ComposicaoDaOferta::montar(preco: $preco, linhas: $this->seteLinhas($preco, 11.0, 19.0),
            comissaoPercentual: 11.0, impostoPercentual: 19.0, margemMinima: 15.0,
            margemMinimaOrigem: PubAprovacaoComposicao::MINIMO_PARAMETRO);
        $daConfig = ComposicaoDaOferta::montar(preco: $preco, linhas: $this->seteLinhas($preco, 11.0, 19.0),
            comissaoPercentual: 11.0, impostoPercentual: 19.0, margemMinima: 12.0,
            margemMinimaOrigem: PubAprovacaoComposicao::MINIMO_CONFIG);

        $this->assertSame(15.0, $doParametro->margemMinima);
        $this->assertSame(PubAprovacaoComposicao::MINIMO_PARAMETRO, $doParametro->margemMinimaOrigem);
        $this->assertSame('Parâmetro da empresa', $doParametro->rotuloDoMinimo());
        $this->assertSame(PubAprovacaoComposicao::MINIMO_CONFIG, $daConfig->margemMinimaOrigem);
        $this->assertSame('Padrão do sistema', $daConfig->rotuloDoMinimo());

        // ⚠️ E o mínimo MUDA o preço-alvo de forma OBSERVÁVEL — senão gravar a origem não serviria.
        $this->assertGreaterThan($daConfig->precoAlvo, $doParametro->precoAlvo,
            'mínimo maior tem de pedir preço-alvo maior; iguais, o resolvedor não está sendo usado');
    }

    public function test_comissao_ou_imposto_pendente_nao_produz_equilibrio_com_percentual_zero(): void
    {
        // ⚠️ Sem o percentual, `vr` seria 0 e o equilíbrio sairia IGUAL à soma fixa — um número
        // plausível e errado. O certo é não existir.
        $preco = 489.90;
        $linhas = array_map(
            fn (LinhaFinanceira $l) => $l->chave === 'comissao'
                ? LinhaFinanceira::pendente('comissao', 'Comissão Clássico', 'O Mercado Livre não respondeu a tarifa', 'Mercado Livre · /sites/MLB/listing_prices')
                : $l,
            $this->seteLinhas($preco, 11.0, 19.0));

        $c = ComposicaoDaOferta::montar(preco: $preco, linhas: $linhas, comissaoPercentual: null, impostoPercentual: 19.0,
            margemMinima: 12.0, margemMinimaOrigem: PubAprovacaoComposicao::MINIMO_CONFIG);

        $this->assertTrue($c->incompleto);
        $this->assertNull($c->equilibrio);
        $this->assertNull($c->precoAlvo);

        // E o caso aritmético: percentuais que somam 100% ou mais não têm equilíbrio.
        $cem = ComposicaoDaOferta::montar(preco: $preco, linhas: $this->seteLinhas($preco, 81.0, 19.0),
            comissaoPercentual: 81.0, impostoPercentual: 19.0, margemMinima: 12.0, margemMinimaOrigem: PubAprovacaoComposicao::MINIMO_CONFIG);
        $this->assertNull($cem->equilibrio, 'comissão + imposto = 100% não tem ponto de equilíbrio — dividir por zero daria INF');
        $this->assertNull($cem->precoAlvo);
        $this->assertNotNull($cem->resultado, 'ISCA: o resultado continua existindo — só o equilíbrio é que não');
    }
}
