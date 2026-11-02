<?php

namespace Tests\Feature\Trilha;

use App\Jobs\Publicador\CriarCombosDoPlanejamentoJob;
use App\Jobs\Publicador\GerarDescricaoIaJob;
use App\Jobs\Publicador\PrepararProdutoNoPublicadorJob;
use App\Models\Company;
use App\Models\EstruturaAnuncio;
use App\Models\EstruturaOferta;
use App\Models\PubRascunho;
use App\Models\TrilhaMapeamento;
use App\Models\TrilhaOnda;
use App\Models\TrilhaOndaItem;
use App\Models\User;
use App\Services\Portal\Estrutura\EstruturaOfertaService;
use App\Services\Publicador\CombosDoPlanejamentoService;
use App\Services\Publicador\Criativos\CriativosAutomaticosService;
use App\Services\Publicador\DescricaoIaService;
use App\Services\Publicador\EditorRascunhoService;
use App\Services\Publicador\PreparoIaDoRascunhoService;
use App\Services\Trilha\MapeamentoNaTrilha;
use App\Services\Trilha\Ondas\OndasDaTrilha;
use App\Services\Trilha\Ondas\PortaoDaOnda;
use App\Services\Trilha\Ondas\SimplesPronta;
use App\Services\Trilha\Ondas\SoltarOndas;
use App\Services\Trilha\TrilhaDaEmpresa;
use App\Support\Portal\AtorDoPortal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Trilha\Concerns\MontaTrilha;
use Tests\TestCase;

/**
 * As regras da 2ª entrega da trilha que a revisão adversarial de 02/11/2026 conferiu nos cantos. Cada teste afirma o
 * comportamento CORRIGIDO e falha sem a correção que ele guarda:
 *
 * - Planejamento: a conferência Combo × Simples olha só os pares que o pedido toca — e vale também para mudar a data
 *   e apagar a onda; tirar da onda aceita o item que já andou; mudar a data recomeça a contagem dos itens; a linha de
 *   combo órfã (combo apagado, ou apagado e criado de novo) sai no planejar, na soltura e no "Concluir mapeamento".
 * - Soltura: a janela da repescagem e o "desde" contam do começo efetivo do item (o dia da onda ou a entrada, o que
 *   vier depois, no dia de São Paulo); a repescagem é uma vez só e olha o TEXTO, não o "andou"; o Combo é pedido só
 *   para ele; o Kit N segue pedido fora da janela, no máximo uma vez por hora.
 * - Composto feito só de ofertas sem produto: fora das ondas e fora da trava.
 * - Tela: a Simples do combo planejado aparece e o combo avisa quando ela está sem onda; a "Simples pronta" da tela
 *   não conta a publicação que falhou nem a Simples sem texto; o item sem texto (mesmo com a memória das imagens)
 *   segue a régua da onda; a pendência na Lista fica no texto do alarme.
 * - Trava: com a onda já começada, o agente recebe "tente de novo" (não "mude a data"); a descrição automática do
 *   editor espera a onda.
 */
class RegrasDaTrilhaRevisaoTest extends TestCase
{
    use MontaTrilha;
    use RefreshDatabase;

    private Company $empresa;

    private User $dev;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Queue::fake();
        Cache::flush();
        $this->agora('2026-11-05 10:00:00');
        // O preparo ligado aqui, e não pelo `.env`: com ele desligado a soltura não pediria nada e tudo "passaria".
        config(['trilha.empresas' => [], 'trilha.trabalho.empresas' => [], 'publicador.preparo_ia.ativo' => true]);
        $this->empresa = $this->empresaDaTrilha();
        $this->dev = User::factory()->create(['role' => 'admin', 'active' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ═══ Planejamento: Combo × Simples ══════════════════════════════════════

    public function test_par_quebrado_nao_trava_outro_produto_mas_mudar_a_data_da_onda_do_par_e_recusado(): void
    {
        $a = $this->produtoCompleto($this->empresa, 'Vaso Alfa', 'VA-1');
        $b = $this->produtoCompleto($this->empresa, 'Banqueta Beta', 'BB-1');
        $this->ofertaComposta($this->empresa, EstruturaOferta::FASE_COMBO, 'VA-1-CB2', 'Combo 2 Vaso Alfa', [[$a['simples'][0], 2]]);
        // Um MLB colado no Portal faz a Simples de A "andar": o combo entra sozinho na onda.
        $anuncio = $this->anuncioColado($a['simples'][0]);
        $onda = $this->ondas()->criar($this->empresa, $this->dev, 'Onda 1', '2026-11-08');
        $this->ondas()->planejar($this->empresa, $this->dev, ["c-{$a['produto']->id}-2"], $onda->id);
        // O MLB era engano e sai: a Simples volta a não ter andado e está Sem onda — o par quebrou sem ninguém planejar.
        $anuncio->delete();
        $this->assertFalse(TrilhaDaEmpresa::itens($this->empresa)["s-{$a['produto']->id}"]['andou']);

        // O produto B não tem nada com o par: entra na mesma onda.
        $r = $this->ondas()->planejar($this->empresa, $this->dev, ["s-{$b['produto']->id}"], $onda->id);
        $this->assertSame(["s-{$b['produto']->id}"], $r['mudados']);

        // Mudar a data da onda toca o combo do par: recusado, com o par na mensagem — e a data fica.
        $this->assertRecusado(fn () => $this->ondas()->editar($onda, $this->dev, null, '2026-11-07'),
            'itens', '"Combo 2 Vaso Alfa" está na Onda 1, mas a simples do produto ficaria sem onda');
        $this->assertSame('2026-11-08', $onda->fresh()->dia());
        // Renomear não mexe em data nenhuma: vale.
        $this->ondas()->editar($onda, $this->dev, 'Onda Renomeada', null);
        $this->assertSame('Onda Renomeada', $onda->fresh()->nome);
    }

    public function test_par_quebrado_pelo_rascunho_que_voltou_a_draft_nao_trava_o_planejar_de_outro_produto(): void
    {
        $a = $this->produtoCompleto($this->empresa, 'Vaso Alfa', 'VA-1');
        $b = $this->produtoCompleto($this->empresa, 'Banqueta Beta', 'BB-1');
        $this->ofertaComposta($this->empresa, EstruturaOferta::FASE_COMBO, 'VA-1-CB2', 'Combo 2 Vaso Alfa', [[$a['simples'][0], 2]]);
        $onda = $this->onda($this->empresa, '2026-11-08', 'Onda 2');
        // A Simples de A "andou" (rascunho validado): o combo entra sozinho na Onda 2, sem puxar a Simples.
        $r = $this->rascunhoDoPub($this->grupoNoPublicador($this->empresa, $a), PubRascunho::VALIDATED);
        $this->ondas()->planejar($this->empresa, $this->dev, ["c-{$a['produto']->id}-2"], $onda->id);
        // O rascunho volta a DRAFT: a Simples deixa de "andar" e fica Sem onda — o par está quebrado.
        $r->update(['status' => PubRascunho::DRAFT]);
        $this->assertFalse(TrilhaDaEmpresa::itens($this->empresa)["s-{$a['produto']->id}"]['andou']);

        $res = $this->ondas()->planejar($this->empresa, $this->dev, ["s-{$b['produto']->id}"], $onda->id);

        $this->assertSame(["s-{$b['produto']->id}"], $res['mudados']);
    }

    public function test_apagar_a_onda_da_simples_com_o_combo_noutra_onda_e_recusado(): void
    {
        $a = $this->produtoCompleto($this->empresa, 'Vaso Alfa', 'VA-1');
        $this->ofertaComposta($this->empresa, EstruturaOferta::FASE_COMBO, 'VA-1-CB2', 'Combo 2 Vaso Alfa', [[$a['simples'][0], 2]]);
        $onda1 = $this->ondas()->criar($this->empresa, $this->dev, 'Onda 1', '2026-11-06');
        $onda2 = $this->ondas()->criar($this->empresa, $this->dev, 'Onda 2', '2026-11-08');
        $this->ondas()->planejar($this->empresa, $this->dev, ["s-{$a['produto']->id}"], $onda1->id);
        $this->ondas()->planejar($this->empresa, $this->dev, ["c-{$a['produto']->id}-2"], $onda2->id);

        $this->assertRecusado(fn () => $this->ondas()->excluir($onda1, $this->dev), 'itens', 'ficaria sem onda');

        $this->assertNotNull(TrilhaOnda::find($onda1->id));
        $this->assertSame(2, TrilhaOndaItem::count());
    }

    public function test_tirar_da_onda_aceita_o_item_que_andou_e_por_noutra_onda_nao(): void
    {
        $a = $this->produtoCompleto($this->empresa, 'Vaso Alfa', 'VA-1');
        $s = "s-{$a['produto']->id}";
        $onda = $this->ondas()->criar($this->empresa, $this->dev, 'Onda 1', '2026-11-20');
        $this->ondas()->planejar($this->empresa, $this->dev, [$s], $onda->id);

        // Antes da data, o MLB é colado no Portal: a Simples "andou" — e segue na onda, que nem começou.
        $this->anuncioColado($a['simples'][0]);
        $item = $this->itemDaTrilha($this->trilha(), $s);
        $this->assertSame([(int) $onda->id, false], [$item['onda_id'], $item['planejavel']]);

        // Pôr noutra onda segue recusado (o que andou não entra em onda)...
        $outra = $this->ondas()->criar($this->empresa, $this->dev, 'Onda 2', '2026-11-25');
        $this->assertRecusado(fn () => $this->ondas()->planejar($this->empresa, $this->dev, [$s], $outra->id), 'itens', 'não entra em onda');
        // ... mas tirar vale: ele só sai.
        $r = $this->ondas()->planejar($this->empresa, $this->dev, [$s], null);

        $this->assertSame([$s], $r['mudados']);
        $this->assertSame(0, TrilhaOndaItem::count());
    }

    public function test_mudar_a_data_da_onda_recomeca_a_contagem_e_o_comecar_agora_conta_do_pedido(): void
    {
        $a = $this->produtoCompleto($this->empresa, 'Vaso Alfa', 'VA-1');
        $this->mapeado($a);
        $s = "s-{$a['produto']->id}";
        $onda = $this->ondas()->criar($this->empresa, $this->dev, 'Onda Futura', '2026-11-20');
        $this->ondas()->planejar($this->empresa, $this->dev, [$s], $onda->id);
        $linha = TrilhaOndaItem::first();
        // Marcas de pedido e de repescagem na linha, para ver a data nova zerar as duas.
        $linha->update(['pedido_em' => now(), 'repescado_em' => now()]);

        // 07/11: a data muda para outro dia ainda por vir — a contagem recomeça daqui.
        $this->agora('2026-11-07 09:00:00');
        $this->ondas()->editar($onda, $this->dev, null, '2026-11-21');
        $linha->refresh();
        $this->assertSame('2026-11-07 09:00', $this->noFuso($linha->na_onda_desde));
        $this->assertSame([null, null], [$linha->pedido_em, $linha->repescado_em]);

        // 10/11 às 22:00: "começar agora" (a data vira hoje); a soltura roda no próprio pedido.
        $this->agora('2026-11-10 22:00:00');
        $this->ondas()->editar($onda, $this->dev, null, '2026-11-10');
        $linha->refresh();
        $this->assertSame('2026-11-10 22:00', $this->noFuso($linha->na_onda_desde));
        $this->assertSame('2026-11-10 22:00', $this->noFuso($linha->pedido_em));
        Queue::assertPushed(PrepararProdutoNoPublicadorJob::class, 1);

        // Renomear não recomeça nada.
        $this->agora('2026-11-10 22:05:00');
        $this->ondas()->editar($onda, $this->dev, 'Onda Agora', null);
        $linha->refresh();
        $this->assertSame(['2026-11-10 22:00', '2026-11-10 22:00'], [$this->noFuso($linha->na_onda_desde), $this->noFuso($linha->pedido_em)]);

        // 11/11 às 00:30, duas horas e meia depois de o robô ser chamado: sem alarme (não conta da meia-noite do dia 10).
        $this->agora('2026-11-11 00:30:00');
        $item = $this->itemDaTrilha($this->trilha(), $s);
        $this->assertSame(['Na fila · começou em 10/11', false], [$item['status'], $item['alerta']]);

        // 24 h depois do pedido, sem nada andar: o alarme acende.
        $this->agora('2026-11-11 22:00:00');
        $item = $this->itemDaTrilha($this->trilha(), $s);
        $this->assertSame(['Ainda não começou (desde 10/11)', true], [$item['status'], $item['alerta']]);
    }

    // ═══ Planejamento: a linha de combo órfã ════════════════════════════════

    public function test_planejar_tira_a_linha_de_combo_orfa_de_qualquer_onda(): void
    {
        $a = $this->produtoCompleto($this->empresa, 'Vaso Alfa', 'VA-1');
        $b = $this->produtoCompleto($this->empresa, 'Banqueta Beta', 'BB-1');
        $combo = $this->ofertaComposta($this->empresa, EstruturaOferta::FASE_COMBO, 'VA-1-CB2', 'Combo 2 Vaso Alfa', [[$a['simples'][0], 2]]);
        $futura = $this->onda($this->empresa, '2026-11-20', 'Onda Futura');
        $this->itemNaOnda($futura, "c-{$a['produto']->id}-2");
        // O combo é apagado no Portal: a linha dele fica sem dono.
        $combo->componentes()->delete();
        $combo->delete();

        $this->ondas()->planejar($this->empresa, $this->dev, ["s-{$b['produto']->id}"], $futura->id);

        $this->assertSame(["s-{$b['produto']->id}"], TrilhaOndaItem::all()->map->chave()->all());
    }

    public function test_combo_apagado_e_criado_de_novo_no_portal_antes_da_passada_nao_herda_a_onda(): void
    {
        $a = $this->produtoCompleto($this->empresa, 'Vaso Alfa', 'VA-1');
        $ator = AtorDoPortal::daEquipe($this->dev);
        $portal = app(EstruturaOfertaService::class);
        $portal->criarCombos($a['simples'][0]->fresh(), [2], null, null, $ator);
        $c = "c-{$a['produto']->id}-2";
        $onda = $this->ondas()->criar($this->empresa, $this->dev, 'Onda Futura', '2026-11-20');
        $this->ondas()->planejar($this->empresa, $this->dev, [$c], $onda->id);
        $this->assertSame(2, TrilhaOndaItem::count(), 'o combo e a Simples puxada');

        // O Portal apaga o Combo 2 (a única cor) e, minutos depois, cria de novo — sem passada no meio.
        $combo = EstruturaOferta::query()->where('company_id', $this->empresa->id)->where('fase', EstruturaOferta::FASE_COMBO)->firstOrFail();
        $portal->excluir($combo, $ator);
        $this->agora('2026-11-05 10:05:00');
        $portal->criarCombos($a['simples'][0]->fresh(), [2], null, null, $ator);

        $this->agora('2026-11-05 10:07:00');
        $resumo = $this->soltura()->rodar()[0];

        $this->assertSame([$c], $resumo['orfaos']);
        $this->assertNull($this->itemDaTrilha($this->trilha(), $c)['onda_id'], 'o combo novo nasce Sem onda');
        $this->assertSame(["s-{$a['produto']->id}"], TrilhaOndaItem::all()->map->chave()->all(), 'a Simples segue na onda');
    }

    public function test_a_cor_nova_de_um_combo_que_ja_existia_herda_a_onda_e_o_combo_recriado_nao(): void
    {
        $a = $this->produtoCompleto($this->empresa, 'Vaso Alfa', 'VA', ['Azul', 'Verde']);
        $b = $this->produtoCompleto($this->empresa, 'Banqueta Beta', 'BB-1');
        $azul = $this->ofertaComposta($this->empresa, EstruturaOferta::FASE_COMBO, 'VA-1-CB2', 'Combo 2 Vaso Alfa Azul', [[$a['simples'][0], 2]]);
        $onda = $this->onda($this->empresa, '2026-11-20', 'Onda Futura');
        $this->itemNaOnda($onda, "s-{$a['produto']->id}");
        $this->itemNaOnda($onda, "c-{$a['produto']->id}-2");

        // 10:05: a cor Verde ganha o Combo 2 — o combo já existia (a oferta Azul é da idade da linha): a linha fica.
        $this->agora('2026-11-05 10:05:00');
        $verde = $this->ofertaComposta($this->empresa, EstruturaOferta::FASE_COMBO, 'VA-2-CB2', 'Combo 2 Vaso Alfa Verde', [[$a['simples'][1], 2]]);
        $this->ondas()->planejar($this->empresa, $this->dev, ["s-{$b['produto']->id}"], $onda->id);
        $this->assertSame(1, TrilhaOndaItem::where('quantidade', 2)->count(), 'a cor nova de um combo que já existia herda a onda');

        // 10:10: as duas cores apagadas e criadas de novo — é outro combo: o planejar seguinte tira a linha.
        $this->agora('2026-11-05 10:10:00');
        foreach ([$azul, $verde] as $o) {
            $o->componentes()->delete();
            $o->delete();
        }
        $this->ofertaComposta($this->empresa, EstruturaOferta::FASE_COMBO, 'VA-1-CB2', 'Combo 2 Vaso Alfa Azul', [[$a['simples'][0], 2]]);
        $this->ofertaComposta($this->empresa, EstruturaOferta::FASE_COMBO, 'VA-2-CB2', 'Combo 2 Vaso Alfa Verde', [[$a['simples'][1], 2]]);
        $this->ondas()->planejar($this->empresa, $this->dev, ["s-{$b['produto']->id}"], null);

        $this->assertSame(0, TrilhaOndaItem::where('quantidade', 2)->count());
        $this->assertSame(["s-{$a['produto']->id}"], TrilhaOndaItem::all()->map->chave()->all());
    }

    // ═══ Mapeamento: o "Concluir" não herda a linha órfã ════════════════════

    public function test_concluir_mapeamento_tira_a_linha_orfa_de_combo_de_onda_que_ja_comecou(): void
    {
        $a = $this->produtoCompleto($this->empresa, 'Vaso Alfa', 'VA', ['Azul', 'Verde']);
        $velha = $this->onda($this->empresa, '2026-11-01', 'Onda Velha', '2026-11-01 00:07:00');
        // Uma linha de Combo 2 sem combo nenhum (as cores foram apagadas no Portal) e a Simples na mesma onda.
        $this->itemNaOnda($velha, "c-{$a['produto']->id}-2", '2026-11-01 00:07:00');
        $this->itemNaOnda($velha, "s-{$a['produto']->id}", '2026-11-01 00:07:00');

        app(MapeamentoNaTrilha::class)->concluir($this->empresa, $a['produto'], ['combos' => [2], 'parceiros' => [], 'juntar' => false], $this->dev);

        $this->assertSame(2, EstruturaOferta::where('fase', EstruturaOferta::FASE_COMBO)->count(), 'o Combo 2 de cada cor');
        $combo = $this->itemDaTrilha($this->trilha(), "c-{$a['produto']->id}-2");
        $this->assertSame([null, 'plano'], [$combo['onda_id'], $combo['etapa']], 'o combo novo nasce Sem onda, mesmo com a onda velha já começada');
        $this->assertSame(["s-{$a['produto']->id}"], TrilhaOndaItem::all()->map->chave()->all(), 'só a Simples segue na onda velha');
    }

    public function test_concluir_mapeamento_tira_a_linha_do_combo_apagado_antes_de_criar_o_combo_de_novo(): void
    {
        $a = $this->produtoCompleto($this->empresa, 'Vaso Alfa', 'VA');
        $combo = $this->ofertaComposta($this->empresa, EstruturaOferta::FASE_COMBO, 'VA-CB2', 'Combo 2 Vaso Alfa', [[$a['simples'][0], 2]]);
        $this->itemNaOnda($this->onda($this->empresa, '2026-11-20', 'Onda Futura'), "c-{$a['produto']->id}-2");
        $combo->componentes()->delete();
        $combo->delete();

        app(MapeamentoNaTrilha::class)->concluir($this->empresa->fresh(), $a['produto'], ['combos' => [2], 'parceiros' => [], 'juntar' => false], $this->dev);

        $this->assertSame(1, EstruturaOferta::where('fase', EstruturaOferta::FASE_COMBO)->count(), 'o Combo 2 nasceu de novo');
        $this->assertSame(0, TrilhaOndaItem::where('produto_id', $a['produto']->id)->where('quantidade', 2)->count(), 'e nasceu Sem onda');
    }

    // ═══ Soltura: janela, "desde" e repescagem ══════════════════════════════

    public function test_a_janela_e_o_desde_contam_da_entrada_no_dia_de_sao_paulo_e_a_repescagem_e_uma_so(): void
    {
        $a = $this->produtoCompleto($this->empresa, 'Vaso Alfa', 'VA-1');
        $this->mapeado($a);
        $s = "s-{$a['produto']->id}";
        $velha = $this->onda($this->empresa, '2026-10-01', 'Onda Velha', '2026-10-01 00:07:00');
        // 23:30 em São Paulo já é o dia seguinte em UTC: o "dia" do pedido tem de ser o de São Paulo.
        $this->agora('2026-11-05 23:30:00');
        $this->ondas()->planejar($this->empresa, $this->dev, [$s], $velha->id);
        $linha = TrilhaOndaItem::first();
        $this->assertSame('2026-11-05 23:30', $this->noFuso($linha->comecoEfetivo()), 'o começo efetivo é a entrada, não o dia da onda');
        $this->assertNotNull($linha->pedido_em, 'posto numa onda que já começou: pedido na hora');

        $this->agora('2026-11-05 23:52:00');
        $this->assertSame([], $this->soltura()->rodar()[0]['repescados'], 'mesmo dia em São Paulo: não repesca');
        $this->agora('2026-11-06 00:07:00');
        $this->assertSame([$s], $this->soltura()->rodar()[0]['repescados'], 'a onda começou há 36 dias, mas a janela conta da entrada');
        $this->assertNotNull($linha->fresh()->repescado_em);
        $this->agora('2026-11-07 00:07:00');
        $this->assertSame([], $this->soltura()->rodar()[0]['repescados'], 'uma vez só');

        $item = $this->itemDaTrilha($this->trilha(), $s);
        $this->assertSame(['Ainda não começou (desde 05/11)', true], [$item['status'], $item['alerta']]);
    }

    public function test_item_posto_antes_do_dia_da_onda_conta_do_dia_da_onda(): void
    {
        $a = $this->produtoCompleto($this->empresa, 'Vaso Alfa', 'VA-1');
        $s = "s-{$a['produto']->id}";
        $onda = $this->ondas()->criar($this->empresa, $this->dev, 'Onda 15', '2026-11-15');
        $this->ondas()->planejar($this->empresa, $this->dev, [$s], $onda->id);   // entrou em 05/11 às 10:00

        $this->agora('2026-11-15 00:07:00');
        $this->assertSame([$s], $this->soltura()->rodar()[0]['pedidos']);

        // No dia, pedido há 10 h: sem alarme — o começo efetivo é o dia da onda, não a entrada.
        $this->agora('2026-11-15 10:00:00');
        $item = $this->itemDaTrilha($this->trilha(), $s);
        $this->assertSame(['robo', 'Na fila · começou em 15/11', false], [$item['etapa'], $item['status'], $item['alerta']]);

        // No dia seguinte, ainda sem texto: dentro da janela contada do dia da onda — repescado.
        $this->agora('2026-11-16 00:07:00');
        $this->assertSame([$s], $this->soltura()->rodar()[0]['repescados'], 'a janela conta do dia da onda, não da entrada');
        $item = $this->itemDaTrilha($this->trilha(), $s);
        $this->assertSame(['Ainda não começou (desde 15/11)', true], [$item['status'], $item['alerta']]);
    }

    public function test_a_repescagem_e_o_primeiro_pedido_olham_o_texto_e_nao_o_andou(): void
    {
        // O teto do texto bateu e as imagens saíram assim mesmo: a memória delas faz o item "andar" sem texto.
        $memoria = [CriativosAutomaticosService::MEMORIA => [CriativosAutomaticosService::ADIADO => ['dia' => '2026-11-05', 'motivo' => 'limite']]];
        $onda = $this->onda($this->empresa, '2026-11-05', 'Onda 1', '2026-11-05 00:07:00');
        $pedido = $this->produtoCompleto($this->empresa, 'Vaso Alfa', 'VA-1');
        $novo = $this->produtoCompleto($this->empresa, 'Vaso Beta', 'VB-1');
        foreach ([$pedido, $novo] as $p) {
            $this->rascunhoDoPub($this->grupoNoPublicador($this->empresa, $p), PubRascunho::DRAFT, $memoria);
        }
        // Alfa: pedido no dia da onda. Beta: entrou depois e ainda não foi pedido (alguém adiantou as imagens à mão).
        $this->itemNaOnda($onda, "s-{$pedido['produto']->id}", '2026-11-05 00:07:00');
        $this->itemNaOnda($onda, "s-{$novo['produto']->id}");
        $itens = TrilhaDaEmpresa::itens($this->empresa);
        $this->assertSame([true, true], [$itens["s-{$pedido['produto']->id}"]['andou'], $itens["s-{$pedido['produto']->id}"]['sem_texto']]);

        $this->agora('2026-11-06 00:07:00');
        $resumo = $this->soltura()->rodar()[0];

        $this->assertSame(["s-{$pedido['produto']->id}"], $resumo['repescados'], 'o texto nunca feito é pedido de novo');
        $this->assertSame(["s-{$novo['produto']->id}"], $resumo['pedidos'], 'e o que só tem as imagens é pedido, não só marcado');
        Queue::assertPushed(PrepararProdutoNoPublicadorJob::class, fn ($j) => $j->estruturaProdutoId === (int) $pedido['produto']->id && $j->soItens === ["s-{$pedido['produto']->id}"]);
        Queue::assertPushed(PrepararProdutoNoPublicadorJob::class, fn ($j) => $j->estruturaProdutoId === (int) $novo['produto']->id && $j->soItens === ["s-{$novo['produto']->id}"]);
    }

    // ═══ Soltura: o Combo ═══════════════════════════════════════════════════

    public function test_o_combo_avulso_e_pedido_so_para_ele_e_repescado_uma_vez(): void
    {
        $p = $this->produtoCompleto($this->empresa, 'Vaso Alfa', 'VA-1', ['Azul']);
        // A cor já foi publicada como avulsa (sem o grupo das cores): a Simples andou.
        $this->rascunhoDoPub($this->ofertaNoPublicador($this->empresa, $p['simples'][0]), PubRascunho::PUBLISHED);
        $combo = $this->ofertaComposta($this->empresa, EstruturaOferta::FASE_COMBO, 'VA-1-CB2', 'Combo 2 Vaso Alfa', [[$p['simples'][0], 2]]);
        $pubCombo = $this->ofertaNoPublicador($this->empresa, $combo);
        $rCombo = $this->rascunhoDoPub($pubCombo);
        $c = "c-{$p['produto']->id}-2";
        $onda = $this->ondas()->criar($this->empresa, $this->dev, 'Onda 1', '2026-11-06');
        $this->ondas()->planejar($this->empresa, $this->dev, [$c], $onda->id);
        $this->assertTrue(app(PortaoDaOnda::class)->segura($pubCombo, $rCombo), 'antes do dia: segurado');

        $this->agora('2026-11-06 00:07:00');
        $dia1 = $this->soltura()->rodar()[0];

        $this->assertFalse(app(PortaoDaOnda::class)->segura($pubCombo, $rCombo->fresh()), 'no dia: solto');
        $this->assertSame([$c], $dia1['pedidos'], 'o combo é pedido');
        $this->assertSame([], $dia1['combos'], 'sem o grupo das cores não há Kit N a pedir');
        // O preparo é do produto, mas a IA só para o combo: a Simples publicada não é refeita de carona.
        Queue::assertPushed(PrepararProdutoNoPublicadorJob::class, fn ($j) => $j->estruturaProdutoId === (int) $p['produto']->id && $j->soItens === [$c]);
        Queue::assertNotPushed(CriarCombosDoPlanejamentoJob::class);

        // No dia seguinte ainda sem texto: repescado uma vez, de novo só ele.
        $this->agora('2026-11-07 00:07:00');
        $this->assertSame([$c], $this->soltura()->rodar()[0]['repescados']);
        $this->agora('2026-11-08 00:07:00');
        $this->assertSame([], $this->soltura()->rodar()[0]['repescados'], 'uma vez só');
        Queue::assertPushed(PrepararProdutoNoPublicadorJob::class, 2);
        Queue::assertNotPushed(PrepararProdutoNoPublicadorJob::class, fn ($j) => $j->soItens !== [$c]);
    }

    public function test_o_combo_de_onda_antiga_segue_pedindo_o_kit_n_no_maximo_uma_vez_por_hora(): void
    {
        $a = $this->produtoCompleto($this->empresa, 'Vaso Alfa', 'VA-1');
        $this->ofertaComposta($this->empresa, EstruturaOferta::FASE_COMBO, 'VA-1-CB2', 'Combo 2 Vaso Alfa', [[$a['simples'][0], 2]]);
        $this->rascunhoDoPub($this->grupoNoPublicador($this->empresa, $a), PubRascunho::PUBLISHED);
        $this->itemNaOnda($this->onda($this->empresa, '2026-10-20', 'Onda Velha', '2026-10-20 00:07:00'), "c-{$a['produto']->id}-2", '2026-10-20 00:07:00');

        // A onda começou há 17 dias (fora da janela de 7): o combo não é repescado, mas o Kit N segue pedido — a Simples
        // pode ficar pronta muito depois do começo da onda, e o Job do Kit N não chama IA nenhuma.
        $this->agora('2026-11-06 00:07:00');
        $r = $this->soltura()->rodar()[0];

        $this->assertSame([[], []], [$r['pedidos'], $r['repescados']]);
        $this->assertSame(["s-{$a['produto']->id}"], $r['combos']);
        Queue::assertPushed(CriarCombosDoPlanejamentoJob::class, 1);
        Queue::assertNotPushed(PrepararProdutoNoPublicadorJob::class);

        // No máximo uma vez por hora por produto.
        $this->agora('2026-11-06 00:52:00');
        $this->soltura()->rodar();
        Queue::assertPushed(CriarCombosDoPlanejamentoJob::class, 1);
        $this->agora('2026-11-06 01:22:00');
        $this->soltura()->rodar();
        Queue::assertPushed(CriarCombosDoPlanejamentoJob::class, 2);
    }

    // ═══ Composto feito só de ofertas sem produto ═══════════════════════════

    public function test_kit_feito_so_de_ofertas_sem_produto_fica_fora_das_ondas_e_da_trava(): void
    {
        $av1 = EstruturaOferta::create(['company_id' => $this->empresa->id, 'variacao_id' => null, 'sku' => 'AV-1', 'fase' => 'simples', 'nome' => 'Avulso 1']);
        $av2 = EstruturaOferta::create(['company_id' => $this->empresa->id, 'variacao_id' => null, 'sku' => 'AV-2', 'fase' => 'simples', 'nome' => 'Avulso 2']);
        $kit = $this->ofertaComposta($this->empresa, EstruturaOferta::FASE_KIT, 'KT-AV-1-AV-2', 'Kit Avulsos', [[$av1, 1], [$av2, 1]]);
        $chave = "o-{$kit->id}";

        $item = TrilhaDaEmpresa::itens($this->empresa)[$chave];
        $this->assertSame([true, []], [$item['sem_produto'], $item['produtos']]);
        $naTela = $this->itemDaTrilha($this->trilha(), $chave);
        $this->assertSame(['plano', 'Fora das ondas · sem produto na Lista', false, true, false],
            [$naTela['etapa'], $naTela['status'], $naTela['alerta'], $naTela['sem_produto'], $naTela['planejavel']]);

        // Nem numa onda que já começou: a soltura não teria produto para pedir e ele ficaria "na fila" para sempre.
        $hoje = $this->ondas()->criar($this->empresa, $this->dev, 'Onda Hoje', '2026-11-05');
        $this->assertRecusado(fn () => $this->ondas()->planejar($this->empresa, $this->dev, [$chave], $hoje->id), 'itens', 'não tem produto na Lista');
        $this->assertSame(0, TrilhaOndaItem::count());

        // E a trava não o segura: no Publicador ele segue o caminho de sempre.
        $pub = $this->ofertaNoPublicador($this->empresa, $kit);
        $this->assertFalse(app(PortaoDaOnda::class)->segura($pub, $this->rascunhoDoPub($pub)));
        Queue::assertNotPushed(PrepararProdutoNoPublicadorJob::class);
    }

    public function test_combo_de_oferta_sem_produto_tambem_fica_fora_das_ondas(): void
    {
        $avulsa = EstruturaOferta::create(['company_id' => $this->empresa->id, 'variacao_id' => null, 'sku' => 'AV-9', 'fase' => 'simples', 'nome' => 'Avulso 9']);
        $combo = $this->ofertaComposta($this->empresa, EstruturaOferta::FASE_COMBO, 'AV-9-CB2', 'Combo 2 Avulso', [[$avulsa, 2]]);
        $chave = "o-{$combo->id}";

        $itens = TrilhaDaEmpresa::itens($this->empresa);
        $this->assertSame([true, []], [$itens[$chave]['sem_produto'], $itens[$chave]['produtos']]);
        $naTela = $this->itemDaTrilha($this->trilha(), $chave);
        $this->assertSame(['plano', false, true, false], [$naTela['etapa'], $naTela['alerta'], $naTela['sem_produto'], $naTela['planejavel']]);
        $this->assertStringNotContainsString('Sem onda', $naTela['status'], 'ninguém é chamado a escolher a data dele');

        $hoje = $this->ondas()->criar($this->empresa, $this->dev, 'Onda Hoje', '2026-11-05');
        $this->assertRecusado(fn () => $this->ondas()->planejar($this->empresa, $this->dev, [$chave], $hoje->id), 'itens', 'não tem produto na Lista');
        $this->assertSame(0, TrilhaOndaItem::count());
    }

    // ═══ Tela: a Simples do combo e a régua da onda ═════════════════════════

    public function test_a_simples_do_combo_planejado_aparece_e_o_combo_avisa_que_ela_esta_sem_onda(): void
    {
        $a = $this->produtoCompleto($this->empresa, 'Vaso Alfa', 'VA-1');
        $this->ofertaComposta($this->empresa, EstruturaOferta::FASE_COMBO, 'VA-1-CB2', 'Combo 2 Vaso Alfa', [[$a['simples'][0], 2]]);
        $s = "s-{$a['produto']->id}";
        $c = "c-{$a['produto']->id}-2";
        // Um MLB colado no Portal para a Simples: ela "andou" e o combo entra sozinho na onda.
        $anuncio = $this->anuncioColado($a['simples'][0]);
        $onda = $this->ondas()->criar($this->empresa, $this->dev, 'Onda 1', '2026-11-08');
        $this->ondas()->planejar($this->empresa, $this->dev, [$c], $onda->id);
        $this->assertSame([$c], TrilhaOndaItem::all()->map->chave()->all(), 'a Simples andou: não entra junto');

        // O MLB era engano e sai do Portal: a Simples volta a não ter andado — Sem onda, com o combo já na onda.
        $anuncio->delete();
        $this->agora('2026-11-09 10:00:00');
        $trilha = $this->trilha();
        $this->assertSame('mapa', $this->produtoNaTrilha($trilha, $a['produto']->id)['etapa']);
        $simples = $this->itemDaTrilha($trilha, $s);
        $this->assertNotNull($simples, 'a Simples fica à vista: o combo espera por ela');
        $this->assertSame(['plano', 'Sem onda · falta escolher a data', true, true],
            [$simples['etapa'], $simples['status'], $simples['alerta'], $simples['planejavel']]);
        $combo = $this->itemDaTrilha($trilha, $c);
        $this->assertSame(['robo', 'Na fila · a oferta simples está sem onda', true], [$combo['etapa'], $combo['status'], $combo['alerta']]);

        // A Simples não pode começar depois do combo...
        $nova = $this->ondas()->criar($this->empresa, $this->dev, 'Onda 2', '2026-11-10');
        $this->assertRecusado(fn () => $this->ondas()->planejar($this->empresa, $this->dev, [$s], $nova->id), 'itens', 'começaria em 10/11');
        // ... na onda do combo (que já começou) ela entra, o robô é chamado e o aviso apaga.
        $this->ondas()->planejar($this->empresa, $this->dev, [$s], $onda->id);
        $combo = $this->itemDaTrilha($this->trilha(), $c);
        $this->assertSame(['Na fila · espera a oferta simples ficar pronta', false], [$combo['status'], $combo['alerta']]);
        Queue::assertPushed(PrepararProdutoNoPublicadorJob::class, fn ($j) => $j->estruturaProdutoId === (int) $a['produto']->id);
    }

    public function test_a_simples_pronta_da_tela_nao_conta_a_publicacao_que_falhou_nem_a_simples_sem_texto(): void
    {
        $this->agora('2026-11-07 10:00:00');
        $falhou = $this->produtoCompleto($this->empresa, 'Vaso Alfa', 'VA-1');
        $semTexto = $this->produtoCompleto($this->empresa, 'Vaso Beta', 'VB-1');
        $onda = $this->onda($this->empresa, '2026-11-05', 'Onda 1', '2026-11-05 00:07:00');

        $casos = [];
        foreach ([[$falhou, PubRascunho::FAILED, []], [$semTexto, PubRascunho::DRAFT, [
            // As imagens automáticas entraram e o editor validou com os textos do Portal — o texto do robô nunca saiu.
            CriativosAutomaticosService::MEMORIA => ['grupos' => ['g1' => ['kit_id' => 999, 'aplicado_em' => '2026-11-05T01:00:00-03:00', 'aplicadas' => 3]]],
            'resumo' => ['revisao' => 1, 'validado' => true, 'bloqueios' => 0, 'versao' => EditorRascunhoService::VERSAO_DO_RESUMO],
        ]]] as [$p, $status, $estado]) {
            $this->mapeado($p);
            $grupo = $this->grupoNoPublicador($this->empresa, $p);
            $r = $this->rascunhoDoPub($grupo, $status, $estado);
            $this->ofertaComposta($this->empresa, EstruturaOferta::FASE_COMBO, $p['produto']->codigo.'-CB2', 'Combo 2 '.$p['produto']->nome, [[$p['simples'][0], 2]]);
            $this->itemNaOnda($onda, "c-{$p['produto']->id}-2", '2026-11-05 00:07:00');
            $casos[$status] = [$p, $grupo, $r];
        }

        $trilha = $this->trilha();
        $daSimples = [];
        foreach ($casos as $status => [$p, $grupo, $r]) {
            $simples = $this->itemDaTrilha($trilha, "s-{$p['produto']->id}");
            $combo = $this->itemDaTrilha($trilha, "c-{$p['produto']->id}-2");
            $daSimples[] = [$simples['etapa'], $simples['status']];
            // A tela e o robô concordam: a Simples não está pronta, o Kit N não nasce — o combo espera, sem o alarme
            // de "ainda não começou".
            $this->assertSame(['robo', 'Na fila · espera a oferta simples ficar pronta', false], [$combo['etapa'], $combo['status'], $combo['alerta']], $status);
            $this->assertFalse(SimplesPronta::para($r->fresh()), $status);
            $this->assertFalse(app(CombosDoPlanejamentoService::class)->agendar((int) $grupo->id), $status);
        }
        $this->assertSame([['revisao', 'A publicação falhou'], ['revisao', 'Pré-pronto · esperando a revisão']], $daSimples);
        Queue::assertNotPushed(CriarCombosDoPlanejamentoJob::class);
    }

    public function test_o_item_sem_texto_segue_a_regua_da_onda_mesmo_com_a_memoria_das_imagens(): void
    {
        $this->agora('2026-11-08 10:00:00');
        $comImagens = $this->produtoCompleto($this->empresa, 'Vaso Alfa', 'VA-1');
        $semImagens = $this->produtoCompleto($this->empresa, 'Vaso Beta', 'VB-1');
        $validado = $this->produtoCompleto($this->empresa, 'Vaso Gama', 'VG-1');
        $onda = $this->onda($this->empresa, '2026-11-05', 'Onda 1', '2026-11-05 00:07:00');
        foreach ([$comImagens, $semImagens, $validado] as $p) {
            $this->mapeado($p);
            // Pedido no dia da onda e repescado no seguinte: nada mais vem sozinho.
            $this->itemNaOnda($onda, "s-{$p['produto']->id}", '2026-11-05 00:07:00')
                ->update(['repescado_em' => Carbon::parse('2026-11-06 00:07:00', TrilhaOnda::FUSO)]);
        }
        // O teto do texto bateu e as imagens automáticas saíram assim mesmo (a memória delas existe sem o texto).
        $memoria = [CriativosAutomaticosService::MEMORIA => ['grupos' => ['g1' => [
            'kit_id' => 999, 'aplicado_em' => '2026-11-05T01:00:00-03:00', 'aplicadas' => 3,
        ]]]];
        $this->rascunhoDoPub($this->grupoNoPublicador($this->empresa, $comImagens), PubRascunho::DRAFT, $memoria);
        $this->rascunhoDoPub($this->grupoNoPublicador($this->empresa, $semImagens), PubRascunho::DRAFT);
        // Igual ao primeiro, e o editor validou com os textos do Portal (0 bloqueio): o texto do robô segue por fazer.
        $this->rascunhoDoPub($this->grupoNoPublicador($this->empresa, $validado), PubRascunho::DRAFT, $memoria + [
            'resumo' => ['revisao' => 1, 'validado' => true, 'bloqueios' => 0, 'versao' => EditorRascunhoService::VERSAO_DO_RESUMO],
        ]);

        $itens = TrilhaDaEmpresa::itens($this->empresa);
        $doComImagens = $itens["s-{$comImagens['produto']->id}"];
        $this->assertSame([true, true], [$doComImagens['andou'], $doComImagens['sem_texto']], 'as imagens fazem "andar"; o texto segue por fazer');

        // O mesmo estado do robô, o mesmo alarme — com ou sem as imagens (antes: "Preparando" para sempre, sem alarme).
        $trilha = $this->trilha();
        foreach (['sem imagens' => $semImagens, 'com imagens' => $comImagens, 'validado pelo editor' => $validado] as $caso => $p) {
            $item = $this->itemDaTrilha($trilha, "s-{$p['produto']->id}");
            $this->assertSame(['robo', 'Ainda não começou (desde 05/11)', true], [$item['etapa'], $item['status'], $item['alerta']], $caso);
        }
        $this->assertSame('fila', $this->itemDaTrilha($trilha, "s-{$comImagens['produto']->id}")['preparo']['titulo']);

        // A repescagem já foi gasta: ninguém é pedido de novo.
        $resumo = $this->soltura()->rodar()[0];
        $this->assertSame([[], []], [$resumo['pedidos'], $resumo['repescados']]);
        Queue::assertNothingPushed();

        // Dias depois, o alarme segue aceso: alguém precisa agir.
        $this->agora('2026-11-20 10:00:00');
        $item = $this->itemDaTrilha($this->trilha(), "s-{$comImagens['produto']->id}");
        $this->assertSame(['robo', 'Ainda não começou (desde 05/11)', true], [$item['etapa'], $item['status'], $item['alerta']]);
    }

    public function test_a_pendencia_na_lista_fica_no_texto_do_alarme(): void
    {
        $a = $this->produtoCompleto($this->empresa, 'Vaso Alfa', 'VA-1');
        $b = $this->produtoCompleto($this->empresa, 'Banqueta Beta', 'BB-1');
        $kit = $this->ofertaComposta($this->empresa, EstruturaOferta::FASE_KIT, 'KT-VA-1-BB-1', 'Kit Alfa e Beta', [[$a['simples'][0], 1], [$b['simples'][0], 1]]);
        // A peça Beta perde o título: cadastro incompleto.
        $b['produto']->update(['titulo' => null]);
        $this->itemNaOnda($this->onda($this->empresa, '2026-11-05', 'Onda 1', '2026-11-05 00:07:00'), "o-{$kit->id}", '2026-11-05 00:07:00');

        $hoje = $this->itemDaTrilha($this->trilha(), "o-{$kit->id}");
        $this->assertSame(['robo', 'Na fila · com pendência na Lista', true], [$hoje['etapa'], $hoje['status'], $hoje['alerta']]);

        // 24 h sem nada andar: o alarme de "ainda não começou" — e a pendência (o que segura o robô) segue dita.
        $this->agora('2026-11-06 10:00:00');
        $amanha = $this->itemDaTrilha($this->trilha(), "o-{$kit->id}");
        $this->assertSame(['robo', 'Ainda não começou (desde 05/11) · pendência na Lista', true], [$amanha['etapa'], $amanha['status'], $amanha['alerta']]);
    }

    public function test_fora_da_chave_de_trabalho_a_simples_com_so_a_memoria_das_imagens_aparece(): void
    {
        // Comportamento fixado na revisão: o `andou` conta a memória das imagens automáticas também na 1ª entrega.
        $empresa = $this->empresaDaTrilha(trabalho: false);
        $a = $this->produtoCompleto($empresa, 'Vaso Alfa', 'VA-1');
        $a['produto']->update(['titulo' => null]);   // ainda na Lista
        $this->rascunhoDoPub($this->grupoNoPublicador($empresa, $a), PubRascunho::DRAFT, [
            CriativosAutomaticosService::MEMORIA => [CriativosAutomaticosService::ADIADO => ['dia' => '2026-11-05', 'motivo' => 'limite']],
        ]);

        $trilha = $this->trilha($empresa);

        $this->assertFalse($trilha['trabalho']);
        $this->assertSame('lista', $this->produtoNaTrilha($trilha, $a['produto']->id)['etapa']);
        $simples = $this->itemDaTrilha($trilha, "s-{$a['produto']->id}");
        $this->assertNotNull($simples, 'a memória das imagens conta como andar: a Simples aparece');
        $this->assertSame(['robo', 'Preparando'], [$simples['etapa'], $simples['status']]);
    }

    public function test_o_numero_de_consultas_nao_cresce_com_itens_de_onda_ja_comecada(): void
    {
        $this->agora('2026-11-07 10:00:00');
        $contar = function (int $n): int {
            $empresa = $this->empresaDaTrilha();
            $onda = $this->onda($empresa, '2026-11-05', 'Onda 1', '2026-11-05 00:07:00');
            for ($i = 1; $i <= $n; $i++) {
                $p = $this->produtoCompleto($empresa, "Peça {$i}", "P{$empresa->id}-{$i}", ['Natural', 'Preto']);
                TrilhaMapeamento::create(['company_id' => $empresa->id, 'produto_id' => $p['produto']->id, 'concluido_em' => now()]);
                $this->rascunhoDoPub($this->grupoNoPublicador($empresa, $p));
                // Entrou depois do começo do dia: o "desde" vem da linha, com a onda já lida (sem consulta por item).
                $this->itemNaOnda($onda, "s-{$p['produto']->id}", '2026-11-05 00:07:00')
                    ->update(['na_onda_desde' => Carbon::parse('2026-11-05 08:00:00', TrilhaOnda::FUSO)]);
                $this->ofertaComposta($empresa, EstruturaOferta::FASE_COMBO, "P{$empresa->id}-{$i}-CB2", "Combo {$i}", [[$p['simples'][0], 2]]);
                $this->itemNaOnda($onda, "c-{$p['produto']->id}-2", '2026-11-05 00:07:00');
            }
            DB::enableQueryLog();
            $trilha = app(TrilhaDaEmpresa::class)->montar($empresa->fresh());
            $total = count(DB::getQueryLog());
            DB::flushQueryLog();
            DB::disableQueryLog();
            $this->assertContains('Ainda não começou (desde 05/11)', array_column($trilha['ofertas'], 'status'), 'o caminho do "desde" foi lido');

            return $total;
        };

        $this->assertSame($contar(1), $contar(4));
    }

    // ═══ Trava: o agente e a descrição do editor ════════════════════════════

    public function test_a_recusa_do_agente_com_a_leitura_quebrada_e_a_onda_ja_comecada_pede_para_tentar_de_novo(): void
    {
        $a = $this->produtoCompleto($this->empresa, 'Vaso Alfa', 'VA-1');
        $this->mapeado($a);
        $pub = $this->grupoNoPublicador($this->empresa, $a);
        $r = $this->rascunhoDoPub($pub);
        $onda = $this->onda($this->empresa, '2026-11-03', 'Onda 1', '2026-11-03 00:07:00');
        $this->itemNaOnda($onda, "s-{$a['produto']->id}", '2026-11-03 00:07:00');

        $this->assertNull(app(PortaoDaOnda::class)->recusaParaOAgente($pub->fresh()), 'a onda começou: o agente é admitido');

        // A leitura dos itens quebra (a régua de anúncios do Portal); a da linha e da onda, não. A trava segura no erro —
        // e mandar "mudar a data da onda" seria impossível: a data de uma onda que começou não muda mais.
        Schema::drop('estrutura_anuncios');
        $this->assertTrue(app(PortaoDaOnda::class)->segura($pub->fresh(), $r->fresh()));
        $this->assertSame('A trilha não conseguiu conferir a onda desta oferta agora. Tente de novo em instantes.',
            app(PortaoDaOnda::class)->recusaParaOAgente($pub->fresh()));
    }

    public function test_a_descricao_automatica_do_editor_espera_a_onda_e_o_pedido_manual_vale(): void
    {
        $a = $this->produtoCompleto($this->empresa, 'Vaso Alfa', 'VA-1');
        $pub = $this->grupoNoPublicador($this->empresa, $a);
        $r = $this->rascunhoDoPub($pub, PubRascunho::DRAFT);
        $this->itemNaOnda($this->onda($this->empresa, '2026-11-20', 'Onda Futura'), "s-{$a['produto']->id}");
        $this->assertSame(PortaoDaOnda::AGUARDANDO, app(PreparoIaDoRascunhoService::class)->avaliarIa($pub->fresh()), 'o robô espera a onda');

        // Abrir o editor (descrição vazia, texto do cliente à mão) não gera a descrição paga sozinho...
        $this->assertNull(app(DescricaoIaService::class)->pedir($r->fresh(), automatico: true));
        Queue::assertNotPushed(GerarDescricaoIaJob::class);

        // ... o "Gerar" de uma pessoa segue valendo...
        $this->assertNotNull(app(DescricaoIaService::class)->pedir($r->fresh()));
        Queue::assertPushed(GerarDescricaoIaJob::class, 1);

        // ... e a vez do automático não foi gasta na espera: no dia da onda ele sai.
        $this->agora('2026-11-20 08:00:00');
        $this->assertNotNull(app(DescricaoIaService::class)->pedir($r->fresh(), automatico: true));
        Queue::assertPushed(GerarDescricaoIaJob::class, 2);
    }

    // ─── apoio ───

    private function ondas(): OndasDaTrilha
    {
        return app(OndasDaTrilha::class);
    }

    private function soltura(): SoltarOndas
    {
        return app(SoltarOndas::class);
    }

    private function trilha(?Company $empresa = null): array
    {
        return app(TrilhaDaEmpresa::class)->montar(($empresa ?? $this->empresa)->fresh());
    }

    private function agora(string $quando): void
    {
        Carbon::setTestNow(Carbon::parse($quando, TrilhaOnda::FUSO));
    }

    /** O "Concluir mapeamento" da trilha, direto no banco. */
    private function mapeado(array $p): void
    {
        TrilhaMapeamento::create(['company_id' => $this->empresa->id, 'produto_id' => $p['produto']->id, 'concluido_em' => now()]);
    }

    /** Um MLB colado no Portal para a oferta: ela "andou". */
    private function anuncioColado(EstruturaOferta $oferta): EstruturaAnuncio
    {
        return EstruturaAnuncio::create(['oferta_id' => $oferta->id, 'tipo' => EstruturaAnuncio::TIPO_CLASSICO,
            'status' => EstruturaAnuncio::STATUS_ATIVO, 'codigo_mlb' => 'MLB1234567890', 'titulo' => 'Vaso']);
    }

    private function noFuso(?\DateTimeInterface $quando): ?string
    {
        return $quando !== null ? Carbon::instance($quando)->setTimezone(TrilhaOnda::FUSO)->format('Y-m-d H:i') : null;
    }

    private function assertRecusado(callable $pedido, string $campo, string $trecho): void
    {
        try {
            $pedido();
        } catch (ValidationException $e) {
            $this->assertStringContainsString($trecho, implode(' ', $e->errors()[$campo] ?? []), json_encode($e->errors(), JSON_UNESCAPED_UNICODE));

            return;
        }
        $this->fail("Esperava a recusa (422) em '{$campo}'.");
    }
}
