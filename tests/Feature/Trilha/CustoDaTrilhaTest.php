<?php

namespace Tests\Feature\Trilha;

use App\Jobs\Publicador\AvaliarCriativosAutomaticosJob;
use App\Jobs\Publicador\CriarCombosDoPlanejamentoJob;
use App\Jobs\Publicador\GerarDescricaoIaJob;
use App\Jobs\Publicador\GerarPreparoIaJob;
use App\Jobs\Publicador\PrepararProdutoNoPublicadorJob;
use App\Models\Company;
use App\Models\Configuracao;
use App\Models\EstruturaOferta;
use App\Models\EstruturaProduto;
use App\Models\MlCategoriaSchema;
use App\Models\PubProduto;
use App\Models\PubRascunho;
use App\Models\TrilhaMapeamento;
use App\Models\TrilhaOnda;
use App\Models\TrilhaOndaItem;
use App\Models\User;
use App\Services\Creative\CreativeEngineAtivo;
use App\Services\Publicador\Criativos\CriativosAutomaticosService;
use App\Services\Publicador\DescricaoIaService;
use App\Services\Publicador\PreparoIaAgenda;
use App\Services\Publicador\PreparoIaDoRascunhoService;
use App\Services\Trilha\Ondas\OndasDaTrilha;
use App\Services\Trilha\Ondas\PortaoDaOnda;
use App\Services\Trilha\Ondas\SoltarOndas;
use App\Services\Trilha\TrilhaDaEmpresa;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Publicador\Concerns\CenarioPlanejamentoDaFase;
use Tests\Feature\Publicador\Lote\CenarioDoPreparo;
use Tests\Feature\Trilha\Concerns\MontaTrilha;
use Tests\TestCase;

/**
 * O custo da trilha (revisão adversarial de 02/11/2026, lente CUSTO): a data da onda solta a IA paga do Publicador só
 * para o que a onda pediu, e nada fica gastando — nem parado — sem ninguém ver. Os testes nasceram das reproduções do
 * defeito feitas na verificação e afirmam o comportamento corrigido:
 *
 * - o kit (ou o combo) da onda pede o preparo do produto de cada peça, mas a IA roda só para ELE (`soItens`): a
 *   Simples ou o kit do mesmo produto que já andou com uma etapa em erro não é refeito de carona;
 * - o kit feito só de ofertas avulsas (sem produto na Lista) fica fora das ondas: não entra, não é pedido, não é
 *   segurado;
 * - o item que bateu no teto do texto é repescado UMA vez, de outro dia, pelo `sem_texto` (mesmo "andando" pela memória
 *   das imagens); sem texto depois disso, a tela avisa "Ainda não começou" em vez de "Preparando" para sempre;
 * - a descrição automática do editor não dispara para a oferta segurada pela onda (o botão manual, sim);
 * - o Kit N nasce mesmo com a Simples pronta depois da janela de 7 dias (o agendar não tem janela, só o limite de uma
 *   vez por hora); a repescagem, essa sim, respeita a janela;
 * - um preparo lê a trilha da empresa uma vez só, não uma por pub_produto.
 *
 * Três cenários: o do preparo (`CenarioDoPreparo`: a cadeira com a ficha completa, a IA como dublê, a fila rodada à
 * mão), o da trilha montada pelos models (`MontaTrilha`) e o do Planejamento da fase (`CenarioPlanejamentoDaFase`).
 */
class CustoDaTrilhaTest extends TestCase
{
    use CenarioDoPreparo;
    use CenarioPlanejamentoDaFase;
    use MontaTrilha;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Queue::fake();
        Cache::flush();
        // O preparo ligado aqui, e não pelo `.env` (a máquina com `PUBLICADOR_PREPARO_IA_ATIVO=false` faria todo teste
        // "passar" sem a IA nunca ser chamada). As imagens automáticas só onde o teste liga.
        config([
            'trilha.empresas' => [], 'trilha.trabalho.empresas' => [],
            'publicador.preparo_ia.ativo' => true, 'publicador.criativos_auto.ativo' => false,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ═══ A etapa paga que falhou não volta de carona ═════════════════════════

    public function test_a_simples_que_falhou_nao_e_refeita_quando_comeca_a_onda_de_um_kit_ou_do_combo_dela(): void
    {
        $this->comPreparo();
        $p = $this->produtoDoPortal('CAD');
        $q = $this->produtoDoPortal('CDB');
        $r = $this->produtoDoPortal('CDC');
        $kit1 = $this->kit('KT-CAD-CDB', [$p, $q]);
        $kit2 = $this->kit('KT-CAD-CDC', [$p, $r]);
        $this->ofertaComposta($this->empresa, EstruturaOferta::FASE_COMBO, 'CAD-AZ-CB2', 'Combo 2 Cadeira', [[$this->simplesDe($p), 2]]);
        $this->itemNaOnda($this->onda($this->empresa, '2026-11-05', 'Onda 1'), "s-{$p->id}");
        $this->itemNaOnda($this->onda($this->empresa, '2026-11-06', 'Onda Kits A'), "o-{$kit1->id}");
        $this->itemNaOnda($this->onda($this->empresa, '2026-11-07', 'Onda Kits B'), "o-{$kit2->id}");
        $this->itemNaOnda($this->onda($this->empresa, '2026-11-08', 'Onda Combo'), "c-{$p->id}-2");

        // Dia 05: a Simples de P é pedida e roda; o título dela FALHA — o que começou e falhou não volta sozinho.
        $this->assertSame(["s-{$p->id}"], $this->soltura()->rodar()[0]['pedidos']);
        $this->rodar(PrepararProdutoNoPublicadorJob::class);
        $this->rodar(GerarPreparoIaJob::class);
        $simples = $this->rascunhoDo($p);
        $this->falharEtapa($simples, 'titulo');
        $item = TrilhaDaEmpresa::itens($this->empresa->fresh())["s-{$p->id}"];
        $this->assertSame([true, false], [$item['andou'], $item['sem_texto']]);
        $titulos = $this->pedidosDaIa($simples->id, 'titulo');
        $this->assertSame(1, $titulos);

        // Dias 06, 07 e 08: começam as ondas dos dois kits com P e a do combo de P. Cada uma pede o preparo dos produtos
        // dela, com a IA restrita ao item da onda: o título pago da Simples não é pedido de novo.
        foreach (['2026-11-06 00:07:00' => "o-{$kit1->id}", '2026-11-07 00:07:00' => "o-{$kit2->id}", '2026-11-08 00:07:00' => "c-{$p->id}-2"] as $quando => $chave) {
            $this->agora($quando);
            $resumo = $this->soltura()->rodar()[0];
            $this->assertSame([$chave], $resumo['pedidos'], $quando);
            $this->assertSame([], $resumo['repescados'], "{$quando}: a Simples começou, não é repescada");
            Queue::assertPushed(PrepararProdutoNoPublicadorJob::class, fn ($j) => $j->estruturaProdutoId === (int) $p->id && $j->soItens === [$chave]);
            $this->rodar(PrepararProdutoNoPublicadorJob::class);
            $this->rodar(GerarPreparoIaJob::class);

            $this->assertSame($titulos, $this->pedidosDaIa($simples->id, 'titulo'), "{$quando}: o título da Simples foi refeito de carona");
        }

        // Os kits, sim, foram preparados no dia da onda de cada um.
        foreach ([$kit1, $kit2] as $kit) {
            $this->assertGreaterThan(0, $this->pedidosDaIa($this->rascunhoDaOferta($kit)->id), "o kit {$kit->sku} não foi preparado");
        }
        // A soltura nunca pede o produto inteiro.
        Queue::assertNotPushed(PrepararProdutoNoPublicadorJob::class, fn ($j) => $j->soItens === null);
        $this->assertSame('erro', ((array) $simples->fresh()->step_state)['ia_preparo']['etapas']['titulo']['status']);
    }

    public function test_o_kit_que_falhou_nao_e_refeito_quando_comeca_a_onda_da_simples_de_uma_peca(): void
    {
        $this->comPreparo();
        $p = $this->produtoDoPortal('CAD');
        $q = $this->produtoDoPortal('CDB');
        $kit = $this->kit('KT-CAD-CDB', [$p, $q]);
        $this->itemNaOnda($this->onda($this->empresa, '2026-11-05', 'Onda Kit'), "o-{$kit->id}");
        $this->itemNaOnda($this->onda($this->empresa, '2026-11-06', 'Onda Q'), "s-{$q->id}");

        // Dia 05: o kit roda (o preparo das duas peças, a IA só para ele); a descrição dele FALHA.
        $this->assertSame(["o-{$kit->id}"], $this->soltura()->rodar()[0]['pedidos']);
        $this->rodar(PrepararProdutoNoPublicadorJob::class);
        $this->rodar(GerarPreparoIaJob::class);
        $doKit = $this->rascunhoDaOferta($kit);
        $this->falharEtapa($doKit, 'descricao');
        $pedidosDoKit = $this->pedidosDaIa($doKit->id);
        $this->assertGreaterThan(0, $pedidosDoKit);
        $this->assertSame(0, $this->pedidosDaIa($this->rascunhoDo($q)->id), 'a Simples de Q espera a onda dela');

        // Dia 06: começa a onda da Simples de Q. O kit não é pedido nem repescado — e o preparo de Q não o refaz.
        $this->agora('2026-11-06 00:07:00');
        $resumo = $this->soltura()->rodar()[0];
        $this->assertSame(["s-{$q->id}"], $resumo['pedidos']);
        $this->assertSame([], $resumo['repescados']);
        Queue::assertPushed(PrepararProdutoNoPublicadorJob::class, fn ($j) => $j->estruturaProdutoId === (int) $q->id && $j->soItens === ["s-{$q->id}"]);
        $this->rodar(PrepararProdutoNoPublicadorJob::class);

        $this->assertSame($pedidosDoKit, $this->pedidosDaIa($doKit->id), 'a descrição do kit voltou paga, de carona');
        $this->assertGreaterThan(0, $this->pedidosDaIa($this->rascunhoDo($q)->id, 'titulo'), 'a Simples de Q é preparada no dia dela');
    }

    // ═══ O kit só de avulsas fica fora das ondas ═════════════════════════════

    public function test_o_kit_feito_so_de_ofertas_avulsas_fica_fora_das_ondas(): void
    {
        $this->agora('2026-11-05 10:00:00');
        $empresa = $this->empresaDaTrilha();
        $a = EstruturaOferta::create(['company_id' => $empresa->id, 'variacao_id' => null, 'sku' => 'AV-1', 'fase' => 'simples', 'nome' => 'Avulso 1']);
        $b = EstruturaOferta::create(['company_id' => $empresa->id, 'variacao_id' => null, 'sku' => 'AV-2', 'fase' => 'simples', 'nome' => 'Avulso 2']);
        $kit = $this->ofertaComposta($empresa, EstruturaOferta::FASE_KIT, 'KT-AV-1-AV-2', 'Kit Avulsos', [[$a, 1], [$b, 1]]);
        $chave = "o-{$kit->id}";

        // Sem produto na Lista a soltura não teria o que pedir: o kit é `sem_produto`, como a oferta avulsa.
        $item = TrilhaDaEmpresa::itens($empresa)[$chave];
        $this->assertSame([true, []], [$item['sem_produto'], $item['produtos']]);

        // Planejar recusa (422 na rota): ele ficaria "na fila" para sempre, sem aviso.
        $quem = User::factory()->create();
        $ondas = app(OndasDaTrilha::class);
        $onda = $ondas->criar($empresa, $quem, 'Onda 1', '2026-11-05');
        try {
            $ondas->planejar($empresa, $quem, [$chave], $onda->id);
            $this->fail('o kit de avulsas não devia entrar na onda');
        } catch (ValidationException $e) {
            $this->assertSame('"Kit Avulsos" não tem produto na Lista: por enquanto fica fora das ondas.', $e->errors()['itens'][0]);
        }
        $this->assertSame(0, TrilhaOndaItem::count());

        // Na tela: fora das ondas, sem alarme e sem o "planejar".
        $naTela = $this->itemDaTrilha($this->trilha($empresa), $chave);
        $this->assertSame(['plano', 'Fora das ondas · sem produto na Lista', false, true, false],
            [$naTela['etapa'], $naTela['status'], $naTela['alerta'], $naTela['sem_produto'], $naTela['planejavel']]);

        // A trava não segura o kit no Publicador: não há onda que ele possa esperar.
        $pub = $this->ofertaNoPublicador($empresa, $kit);
        $rascunho = $this->rascunhoDoPub($pub);
        $this->assertFalse(app(PortaoDaOnda::class)->segura($pub, $rascunho));
        $this->assertNotSame(PortaoDaOnda::AGUARDANDO, app(PreparoIaDoRascunhoService::class)->avaliarIa($pub->fresh()));

        // Uma linha que já estivesse no banco também não é pedida, nem nos dias seguintes.
        $this->itemNaOnda($onda, $chave);
        foreach (['2026-11-06 00:07:00', '2026-11-12 00:07:00'] as $quando) {
            $this->agora($quando);
            $resumo = $this->soltura()->rodar()[0];
            $this->assertSame([[], []], [$resumo['pedidos'], $resumo['repescados']], $quando);
        }
        Queue::assertNotPushed(PrepararProdutoNoPublicadorJob::class);
    }

    // ═══ O item sem texto avisa "Ainda não começou" ══════════════════════════

    public function test_com_as_imagens_ligadas_o_item_que_bateu_no_teto_do_texto_e_repescado_uma_vez_e_depois_avisa(): void
    {
        $this->comPreparo();
        $this->ligarImagens();
        config(['publicador.preparo_ia.limite_diario_por_empresa' => 0]);
        $p = $this->produtoDoPortal();
        TrilhaMapeamento::create(['company_id' => $this->empresa->id, 'produto_id' => $p->id, 'concluido_em' => now()]);
        $linha = $this->itemNaOnda($this->onda($this->empresa, '2026-11-05', 'Onda 1'), "s-{$p->id}");

        // Dia 05: o teto do texto bate ('limite'); as imagens saem sem cadeia e deixam memória (aqui, barradas pelo
        // teto delas): o item "andou", mas sem texto nenhum.
        $this->assertSame(["s-{$p->id}"], $this->soltura()->rodar()[0]['pedidos']);
        $this->assertSame(['pronto'], $this->rodar(PrepararProdutoNoPublicadorJob::class));
        Queue::assertNotPushed(GerarPreparoIaJob::class);
        Queue::assertPushed(AvaliarCriativosAutomaticosJob::class, 1);
        $this->memoriaDasImagens($p, '2026-11-05');
        $item = TrilhaDaEmpresa::itens($this->empresa->fresh())["s-{$p->id}"];
        $this->assertSame([true, true], [$item['andou'], $item['sem_texto']]);

        // A passada seguinte do MESMO dia não repesca: a repescagem é de outro dia.
        $this->agora('2026-11-05 15:07:00');
        $this->assertSame([], $this->soltura()->rodar()[0]['repescados']);

        // Dia 06: a repescagem (pelo `sem_texto`, não pelo "andou") bate no teto de novo.
        $this->agora('2026-11-06 00:07:00');
        $this->assertSame(["s-{$p->id}"], $this->soltura()->rodar()[0]['repescados']);
        $this->rodar(PrepararProdutoNoPublicadorJob::class);
        Queue::assertNotPushed(GerarPreparoIaJob::class);
        $this->assertNotNull($linha->fresh()->repescado_em);

        // Dia 07, com o teto ainda em zero, e dia 09, com cota sobrando: ninguém pede de novo (uma vez só) — e a tela
        // AVISA, pela régua da onda, em vez de "Preparando" sem alerta para sempre. A cadeira do cenário do preparo não
        // tem título de venda, família, ambiente nem fotos na Lista: o alarme leva a pendência junto.
        foreach (['2026-11-07 10:00:00' => 0, '2026-11-09 10:00:00' => 60] as $quando => $teto) {
            config(['publicador.preparo_ia.limite_diario_por_empresa' => $teto]);
            $this->agora($quando);
            $resumo = $this->soltura()->rodar()[0];
            $this->assertSame([[], []], [$resumo['pedidos'], $resumo['repescados']], $quando);

            $naTela = $this->itemDaTrilha($this->trilha($this->empresa), "s-{$p->id}");
            $this->assertSame(['robo', 'Ainda não começou (desde 05/11) · pendência na Lista', true],
                [$naTela['etapa'], $naTela['status'], $naTela['alerta']], $quando);
        }
        Queue::assertNotPushed(GerarPreparoIaJob::class);
        Queue::assertPushed(PrepararProdutoNoPublicadorJob::class, 2);
    }

    public function test_sem_as_imagens_o_mesmo_estado_tambem_avisa(): void
    {
        $this->comPreparo();
        config(['publicador.preparo_ia.limite_diario_por_empresa' => 0]);
        $p = $this->produtoDoPortal();
        $this->itemNaOnda($this->onda($this->empresa, '2026-11-05', 'Onda 1'), "s-{$p->id}");

        $this->soltura()->rodar();
        $this->rodar(PrepararProdutoNoPublicadorJob::class);
        $this->agora('2026-11-06 00:07:00');
        $this->soltura()->rodar();
        $this->rodar(PrepararProdutoNoPublicadorJob::class);
        Queue::assertNotPushed(GerarPreparoIaJob::class);

        $this->agora('2026-11-09 10:00:00');
        $item = TrilhaDaEmpresa::itens($this->empresa->fresh())["s-{$p->id}"];
        $this->assertSame([false, true], [$item['andou'], $item['sem_texto']]);
        $naTela = $this->itemDaTrilha($this->trilha($this->empresa), "s-{$p->id}");
        $this->assertSame(['robo', 'Ainda não começou (desde 05/11) · pendência na Lista', true],
            [$naTela['etapa'], $naTela['status'], $naTela['alerta']]);
    }

    // ═══ A descrição automática do editor espera a onda ══════════════════════

    public function test_a_descricao_automatica_do_editor_espera_a_onda_e_o_botao_manual_nao(): void
    {
        Http::fake();
        $this->agora('2026-11-05 10:00:00');
        $empresa = $this->empresaDaTrilha();
        $a = $this->produtoCompleto($empresa, 'Vaso Alfa', 'VA-1');
        $pub = $this->grupoNoPublicador($empresa, $a);
        $r = $this->rascunhoDoPub($pub, PubRascunho::DRAFT);
        $this->itemNaOnda($this->onda($empresa, '2026-11-20', 'Onda futura'), "s-{$a['produto']->id}");
        $this->assertSame(PortaoDaOnda::AGUARDANDO, app(PreparoIaDoRascunhoService::class)->avaliarIa($pub->fresh()), 'o robô espera a onda');

        // Abrir o editor (descrição vazia, texto do cliente) não gera a descrição paga sozinho antes da data.
        $this->assertNull(app(DescricaoIaService::class)->pedir($r->fresh(), automatico: true));
        Queue::assertNotPushed(GerarDescricaoIaJob::class);

        // O botão é uma pessoa pedindo: vale.
        $this->assertNotNull(app(DescricaoIaService::class)->pedir($r->fresh()));
        Queue::assertPushed(GerarDescricaoIaJob::class, fn ($j) => $j->rascunhoId === $r->id);
        Queue::assertPushed(GerarDescricaoIaJob::class, 1);

        // No dia da onda o editor volta a pedir sozinho.
        $this->agora('2026-11-20 08:00:00');
        $this->assertNotNull(app(DescricaoIaService::class)->pedir($r->fresh(), automatico: true));
        Queue::assertPushed(GerarDescricaoIaJob::class, 2);
    }

    // ═══ O Kit N nasce depois da janela; a repescagem respeita a janela ══════

    public function test_o_combo_repescado_pede_so_ele_e_o_kit_n_segue_agendado_depois_da_janela_uma_vez_por_hora(): void
    {
        [, $c, $linha] = $this->comboDeOndaPassada();

        // Último dia da janela da repescagem (7 dias do começo da onda): o combo, que nunca começou, é pedido de novo
        // UMA vez — com a IA só para ele, nunca o produto inteiro.
        $this->agora('2026-11-13 23:52:00');
        $resumo = $this->soltura()->rodar()[0];
        $this->assertSame([$c], $resumo['repescados']);
        $this->assertNotNull($linha->fresh()->repescado_em);
        Queue::assertPushed(PrepararProdutoNoPublicadorJob::class, fn ($j) => $j->soItens === [$c]);
        Queue::assertPushed(CriarCombosDoPlanejamentoJob::class, 1);

        // Fora da janela: a repescagem acabou, mas o Kit N continua sendo agendado (a Simples pode ficar pronta muito
        // depois do começo da onda, e o Job dele não chama IA nenhuma) — no máximo uma vez por hora.
        $this->agora('2026-11-14 00:07:00');
        $this->assertSame([], $this->soltura()->rodar()[0]['repescados']);
        Queue::assertPushed(CriarCombosDoPlanejamentoJob::class, 1);

        $this->agora('2026-11-14 00:53:00');
        $this->soltura()->rodar();
        Queue::assertPushed(CriarCombosDoPlanejamentoJob::class, 2);

        Queue::assertPushed(PrepararProdutoNoPublicadorJob::class, 1);
    }

    public function test_quem_nunca_comecou_nao_e_repescado_fora_da_janela_mas_o_kit_n_ainda_e_agendado(): void
    {
        [$empresa, $c, $linha] = $this->comboDeOndaPassada();

        // A passada só volta a rodar uma semana depois (worker parado): a janela de 7 dias, contada do começo do item
        // na onda, passou — ninguém é pedido sozinho, a tela fica com o aviso para uma pessoa.
        $this->agora('2026-11-14 00:07:00');
        $resumo = $this->soltura()->rodar()[0];

        $this->assertSame([[], []], [$resumo['pedidos'], $resumo['repescados']]);
        $this->assertNull($linha->fresh()->repescado_em);
        Queue::assertNotPushed(PrepararProdutoNoPublicadorJob::class);
        Queue::assertPushed(CriarCombosDoPlanejamentoJob::class, 1);
        $naTela = $this->itemDaTrilha($this->trilha($empresa), $c);
        $this->assertSame(['robo', 'Ainda não começou (desde 06/11)', true], [$naTela['etapa'], $naTela['status'], $naTela['alerta']]);
    }

    public function test_a_simples_pronta_8_dias_depois_da_onda_ainda_ganha_o_kit_n_pela_soltura(): void
    {
        $grupo = $this->comboNoPlanejamento();
        $s = "s-{$this->produtoP->id}";
        $c = "c-{$this->produtoP->id}-2";

        // Último dia da janela: a Simples e o combo nunca começaram — os dois são repescados, num pedido só, com a IA
        // só para eles. O Kit N ainda não nasce: a Simples não está pronta.
        $this->agora('2026-11-12 00:07:00');
        $resumo = $this->soltura()->daEmpresa($this->empresaP);
        $this->assertEqualsCanonicalizing([$s, $c], $resumo['repescados']);
        Queue::assertPushed(PrepararProdutoNoPublicadorJob::class, fn ($j) => $j->soItens !== null && $this->mesmos([$s, $c], $j->soItens));
        Queue::assertNotPushed(CriarCombosDoPlanejamentoJob::class);

        // O robô termina a Simples no dia seguinte (o teto do dia), já fora da janela: o Kit N nasce mesmo assim.
        $this->agora('2026-11-13 00:07:00');
        $this->textoPronto($grupo->rascunho);
        $this->soltura()->daEmpresa($this->empresaP);
        Queue::assertPushed(CriarCombosDoPlanejamentoJob::class, 1);

        // No máximo uma vez por hora, enquanto o Kit N não existe.
        $this->agora('2026-11-13 00:22:00');
        $this->soltura()->daEmpresa($this->empresaP);
        Queue::assertPushed(CriarCombosDoPlanejamentoJob::class, 1);
        $this->agora('2026-11-13 02:07:00');
        $this->soltura()->daEmpresa($this->empresaP);
        Queue::assertPushed(CriarCombosDoPlanejamentoJob::class, 2);

        // Fora da janela ninguém é repescado: o único preparo pedido foi o do último dia dela.
        Queue::assertPushed(PrepararProdutoNoPublicadorJob::class, 1);
    }

    // ═══ Um preparo lê a trilha uma vez ══════════════════════════════════════

    public function test_um_preparo_le_a_trilha_da_empresa_uma_vez_so_e_nao_uma_por_pub_produto(): void
    {
        $this->comPreparo();
        $cadeira = $this->produtoDoPortal('CAD');
        $outra = $this->produtoDoPortal('OUT');
        foreach ([1, 2, 3] as $i) {
            $this->ofertaComposta($this->empresa, EstruturaOferta::FASE_KIT, "KT-{$i}", "Kit {$i}", [[$this->simplesDe($cadeira), 1], [$this->simplesDe($outra), $i]]);
        }
        $this->itemNaOnda($this->onda($this->empresa, '2026-11-06', 'Onda 1'), "s-{$cadeira->id}");

        // O save do Portal (sem `soItens`): o preparo passa pelo grupo e pelos três kits, e a trava pergunta por cada um.
        app(PreparoIaAgenda::class)->aoSalvar((int) $cadeira->company_id, [$cadeira->id]);
        Log::spy();
        DB::enableQueryLog();
        $saida = $this->rodar(PrepararProdutoNoPublicadorJob::class);
        $consultas = array_column(DB::getQueryLog(), 'query');
        DB::disableQueryLog();

        $this->assertSame(['pronto'], $saida);
        Log::shouldHaveReceived('info')->withArgs(fn ($m) => is_string($m) && str_contains($m, '— '.PortaoDaOnda::AGUARDANDO.'.'))->times(4);
        Queue::assertNotPushed(GerarPreparoIaJob::class);
        // `TrilhaDaEmpresa::itens()` lê as ofertas da empresa inteira: uma vez por preparo, não uma por pub_produto.
        $leituras = array_filter($consultas, fn ($q) => str_contains(str_replace(['"', '`'], '', $q),
            'select id, variacao_id, sku, fase, nome from estrutura_ofertas where company_id = ?'));
        $this->assertCount(1, $leituras);
    }

    // ─── apoio ───

    /** O cenário do preparo na empresa da trilha de trabalho. O relógio vem ANTES: o schema da categoria é guardado "agora". */
    private function comPreparo(string $agora = '2026-11-05 10:00:00'): void
    {
        $this->agora($agora);
        $this->montarPreparo();
        config(['trilha.empresas' => [$this->empresa->id], 'trilha.trabalho.empresas' => [$this->empresa->id]]);
    }

    /**
     * O Combo 2 de um produto completo, com a Simples PUBLICADA (o Kit N pode nascer a qualquer hora) e a linha do combo
     * numa onda de 06/11 que já foi solta e pedida — sem o Kit N ter nascido.
     *
     * @return array{0: Company, 1: string, 2: TrilhaOndaItem}
     */
    private function comboDeOndaPassada(): array
    {
        $empresa = $this->empresaDaTrilha();
        $a = $this->produtoCompleto($empresa, 'Vaso Alfa', 'VA-1');
        $this->ofertaComposta($empresa, EstruturaOferta::FASE_COMBO, 'VA-1-CB2', 'Combo 2 Vaso Alfa', [[$a['simples'][0], 2]]);
        $this->rascunhoDoPub($this->grupoNoPublicador($empresa, $a), PubRascunho::PUBLISHED);
        $c = "c-{$a['produto']->id}-2";
        $linha = $this->itemNaOnda($this->onda($empresa, '2026-11-06', 'Onda', '2026-11-06 00:07:00'), $c, '2026-11-06 00:07:00');

        return [$empresa, $c, $linha];
    }

    /**
     * O produto de três cores do Planejamento com a Simples em DRAFT (o robô ainda não fez o texto) e o Combo 2 aceito,
     * os dois numa onda de 05/11 que já foi solta e pedida (entraram na véspera).
     */
    private function comboNoPlanejamento(): PubProduto
    {
        $this->agora('2026-11-05 00:07:00');
        Http::fake();
        $this->montarPlanejamento();
        config(['trilha.empresas' => [$this->empresaP->id], 'trilha.trabalho.empresas' => [$this->empresaP->id]]);
        $grupo = $this->grupoComRascunho(PubRascunho::DRAFT);
        $this->aceitarCombos(2);
        Queue::fake();
        $onda = TrilhaOnda::create(['company_id' => $this->empresaP->id, 'nome' => 'Onda 1', 'data' => '2026-11-05', 'liberada_em' => now()]);
        foreach ([1, 2] as $n) {
            TrilhaOndaItem::create(['onda_id' => $onda->id, 'company_id' => $this->empresaP->id, 'produto_id' => $this->produtoP->id,
                'quantidade' => $n, 'pedido_em' => now(), 'na_onda_desde' => Carbon::parse('2026-11-04 15:00:00', TrilhaOnda::FUSO)]);
        }

        return $grupo;
    }

    /** O preparo do texto completo para os fatos de agora, como a cadeia da IA deixa (o de `CombosNaOndaTest`). */
    private function textoPronto(PubRascunho $r): void
    {
        $preparo = app(PreparoIaDoRascunhoService::class);
        $r = $r->fresh();
        $em = now()->subMinutes(5)->toIso8601String();
        $estado = (array) $r->step_state;
        $estado['ia_preparo'] = ['hash' => $preparo->hashDosFatos($r), 'em' => $em, 'etapas' => [
            'titulo' => ['status' => 'ok', 'em' => $em], 'modelo' => ['status' => 'ok', 'em' => $em], 'descricao' => ['status' => 'ok', 'em' => $em],
        ]];
        $r->forceFill(['step_state' => $estado])->save();
    }

    /** A oferta simples (a cor única) da cadeira do cenário do preparo. */
    private function simplesDe(EstruturaProduto $p): EstruturaOferta
    {
        return EstruturaOferta::query()->where('company_id', $p->company_id)->where('sku', "{$p->codigo}-AZ")->firstOrFail();
    }

    /** Um Kit com uma unidade de cada cadeira. @param  list<EstruturaProduto>  $produtos */
    private function kit(string $sku, array $produtos): EstruturaOferta
    {
        return $this->ofertaComposta($this->empresa, EstruturaOferta::FASE_KIT, $sku, "Kit {$sku}",
            array_map(fn (EstruturaProduto $p) => [$this->simplesDe($p), 1], $produtos));
    }

    /** O rascunho do pub_produto do composto (o Sincronizar das peças o cria). */
    private function rascunhoDaOferta(EstruturaOferta $oferta): PubRascunho
    {
        return PubRascunho::query()->where('produto_id', PubProduto::query()->where('oferta_id', $oferta->id)->value('id'))->firstOrFail();
    }

    /** A etapa do texto terminou em erro (o que a cadeia deixa quando a IA falha de vez). */
    private function falharEtapa(PubRascunho $r, string $etapa): void
    {
        $estado = json_decode((string) DB::table('pub_rascunhos')->where('id', $r->id)->value('step_state'), true) ?: [];
        $estado['ia_preparo']['etapas'][$etapa]['status'] = 'erro';
        DB::table('pub_rascunhos')->where('id', $r->id)->update(['step_state' => json_encode($estado)]);
    }

    /** Quantas vezes a IA paga do preparo foi pedida para o rascunho (numa etapa, ou em qualquer uma). */
    private function pedidosDaIa(int $rascunhoId, ?string $etapa = null): int
    {
        return Queue::pushed(GerarPreparoIaJob::class, fn ($j) => $j->rascunhoId === $rascunhoId && ($etapa === null || $j->etapa === $etapa))->count();
    }

    /** As imagens automáticas rodaram e deixaram memória (barradas pelo teto de imagens do dia). */
    private function memoriaDasImagens(EstruturaProduto $p, string $dia): void
    {
        $r = $this->rascunhoDo($p);
        $estado = (array) $r->step_state;
        $estado[CriativosAutomaticosService::MEMORIA][CriativosAutomaticosService::ADIADO] = ['dia' => $dia, 'motivo' => 'limite'];
        DB::table('pub_rascunhos')->where('id', $r->id)->update(['step_state' => json_encode($estado)]);
    }

    private function ligarImagens(): void
    {
        config(['publicador.criativos_auto.ativo' => true]);
        Configuracao::set(CriativosAutomaticosService::CHAVE_EMPRESAS, '*');
        $this->mock(CreativeEngineAtivo::class, fn ($m) => $m->shouldReceive('ativa')->andReturn(true));
    }

    /** Muda o relógio (São Paulo). O schema guardado acompanha: senão ele "venceria" e o preparo iria ao ML. */
    private function agora(string $quando): void
    {
        Carbon::setTestNow(Carbon::parse($quando, TrilhaOnda::FUSO));
        MlCategoriaSchema::query()->update(['fetched_at' => now()]);
    }

    private function soltura(): SoltarOndas
    {
        return app(SoltarOndas::class);
    }

    private function trilha(Company $empresa): array
    {
        return app(TrilhaDaEmpresa::class)->montar($empresa->fresh());
    }

    /** @param  list<string>  $esperado */
    private function mesmos(array $esperado, array $veio): bool
    {
        sort($esperado);
        sort($veio);

        return $esperado === $veio;
    }
}
