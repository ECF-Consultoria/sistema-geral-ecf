<?php

namespace Tests\Feature\Trilha;

use App\Http\Controllers\Trilha\ApresentacaoDaTrilha;
use App\Jobs\Publicador\GerarPreparoIaJob;
use App\Jobs\Publicador\PrepararProdutoNoPublicadorJob;
use App\Models\Company;
use App\Models\EstruturaAnuncio;
use App\Models\EstruturaOferta;
use App\Models\EstruturaProduto;
use App\Models\MlCategoriaSchema;
use App\Models\PubProduto;
use App\Models\PubRascunho;
use App\Models\TrilhaMapeamento;
use App\Models\TrilhaOnda;
use App\Models\TrilhaOndaItem;
use App\Models\User;
use App\Services\Publicador\CombosDoPlanejamentoService;
use App\Services\Publicador\PreparoIaAgenda;
use App\Services\Trilha\Ondas\ItemDaTrilha;
use App\Services\Trilha\Ondas\OndasDaTrilha;
use App\Services\Trilha\Ondas\PortaoDaOnda;
use App\Services\Trilha\Ondas\SoltarOndas;
use App\Services\Trilha\TrilhaDaEmpresa;
use App\Support\Publicador\EditorEmUso;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Publicador\Concerns\CenarioPlanejamentoDaFase;
use Tests\Feature\Publicador\Lote\CenarioDoPreparo;
use Tests\Feature\Trilha\Concerns\MontaTrilha;
use Tests\TestCase;

/**
 * A conferência final da 2ª entrega da trilha (02/11/2026). Cada teste nasceu de uma sonda que reproduziu o defeito
 * na conferência e afirma o comportamento CORRIGIDO — com a classe de antes da correção, ele falha:
 *
 * - **Save engolido pela soltura** (`PreparoIaAgenda`): com a chave, o Job mais novo do produto leva a UNIÃO do que o
 *   Job anterior ainda não começado ia preparar — "todos" (o save do Portal) vence, e o preparo que já começou
 *   (`PreparoIaAgenda::comecou`, marcado pelo `preparar` depois da checagem do editor em uso) não entra na soma. Antes,
 *   a soltura que alcançava o save do cliente dentro dos 20 s da espera restringia o preparo ao item da onda, e o que
 *   o save pediu se perdia. Fora da chave, o pedido passa direto.
 * - **Combo apagado e criado de novo** antes da passada da soltura: a trava, o Kit N e a tela não herdam mais a onda
 *   da linha velha; mudar a data e apagar a onda tiram a linha antes de conferir os pares.
 * - **Kit que ficou sem produto dentro de uma onda** pode sair dela (pôr ou mover segue recusado).
 * - **Status da oferta sem produto** com a chave: "Fora das ondas · sem produto na Lista", sem alerta — também o
 *   combo de uma oferta sem produto, que dizia "Espera a oferta simples ir ao ar".
 * - **Preparo pela IA desligado**: a soltura não pede nem marca `pedido_em`; religado, a passada seguinte pede.
 * - **Fora da chave** o kit feito só de ofertas avulsas volta a sair com produto na tela, como na 1ª entrega.
 * - **`pode_tirar`**: a oferta numa onda que ainda não começou pode sair dela (também a que andou e a sem produto);
 *   a porta do cliente nunca recebe o "tirar".
 * - **Combo preso numa onda que já começou**: a recusa manda pôr a simples na onda do combo, e não mais "tire o
 *   combo" — o que não dá mais para fazer.
 *
 * Três cenários: o do preparo (`CenarioDoPreparo`: a cadeira com a ficha completa, a IA como dublê, a fila rodada à
 * mão), o da trilha montada pelos models (`MontaTrilha`) e o do Planejamento da fase (`CenarioPlanejamentoDaFase`).
 */
class RevisaoFinalDaTrilhaTest extends TestCase
{
    use CenarioDoPreparo;
    use CenarioPlanejamentoDaFase;
    use MontaTrilha;
    use RefreshDatabase;

    private User $dev;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Queue::fake();
        Cache::flush();
        // O relógio antes de montar qualquer coisa: as datas de criação contam (combo recriado, onda de hoje).
        $this->agora('2026-11-05 10:00:00');
        // O preparo ligado aqui, e não pelo `.env` (o worktree tem `PUBLICADOR_PREPARO_IA_ATIVO=false`): com ele
        // desligado a soltura não pediria nada e tudo "passaria". As imagens automáticas ficam desligadas.
        config([
            'trilha.empresas' => [], 'trilha.trabalho.empresas' => [],
            'publicador.preparo_ia.ativo' => true, 'publicador.criativos_auto.ativo' => false,
        ]);
        $this->dev = User::factory()->create(['role' => 'admin', 'active' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ═══ A. O save do cliente não é engolido pela soltura ═══════════════════

    public function test_o_save_do_cliente_alcancado_pela_soltura_em_10_s_ainda_prepara_a_simples(): void
    {
        $this->comPreparo();
        $p = $this->produtoDoPortal('CAD');
        $q = $this->produtoDoPortal('CDB');
        $kit = $this->ofertaComposta($this->empresa, EstruturaOferta::FASE_KIT, 'KT-CAD-CDB', 'Kit KT-CAD-CDB',
            [[$this->simplesDe($p), 1], [$this->simplesDe($q), 1]]);
        $this->itemNaOnda($this->onda($this->empresa, '2026-11-05', 'Onda 1'), "s-{$p->id}");
        $this->itemNaOnda($this->onda($this->empresa, '2026-11-06', 'Onda Kit'), "o-{$kit->id}");

        // 05/11: a Simples de P é preparada (texto completo).
        $this->assertSame(["s-{$p->id}"], $this->soltura()->rodar()[0]['pedidos']);
        $this->rodar(PrepararProdutoNoPublicadorJob::class);
        $this->rodar(GerarPreparoIaJob::class);
        $simples = $this->rascunhoDo($p);
        $antes = $this->pedidosDaIa($simples->id);
        $this->assertGreaterThan(0, $antes);

        // 06/11 às 00:06:50: o cliente muda a descrição de P e salva no Portal — o preparo do produto INTEIRO.
        $this->agora('2026-11-06 00:06:50');
        $p->update(['descricao' => 'Cadeira com encosto em tela e apoio de braço regulável, ótima para home office.']);
        app(PreparoIaAgenda::class)->aoSalvar((int) $this->empresa->id, [$p->id]);
        // 10 s depois, dentro dos 20 s da espera, começa a onda do kit, que tem a peça P: o Job da soltura supera o do save.
        $this->agora('2026-11-06 00:07:00');
        $this->assertSame(["o-{$kit->id}"], $this->soltura()->rodar()[0]['pedidos']);

        $this->agora('2026-11-06 00:08:00');
        $saidas = $this->rodar(PrepararProdutoNoPublicadorJob::class);
        $this->rodar(GerarPreparoIaJob::class);

        // O que o save pediu não se perdeu: a Simples ganha as etapas pagas do texto novo.
        $this->assertGreaterThan($antes, $this->pedidosDaIa($simples->id), 'o preparo que o save do cliente pediu se perdeu (a marca da soltura o superou)');
        $this->assertGreaterThan(0, $this->pedidosDaIa($this->rascunhoDaOferta($kit)->id), 'o kit da onda também é preparado');
        // O Job do save sai superado; o da soltura leva a união — "todos" (null) vence o item da onda.
        $this->assertSame(['superado', 'pronto', 'pronto'], $saidas);
        $this->assertSame([["s-{$p->id}"], null, null], $this->soItensDosJobs((int) $p->id));
        $this->assertSame([["o-{$kit->id}"]], $this->soItensDosJobs((int) $q->id));
    }

    public function test_com_a_chave_o_job_novo_do_produto_leva_a_uniao_do_que_o_anterior_nao_comecado_ia_preparar(): void
    {
        $empresa = $this->empresaDaTrilha();
        $agenda = app(PreparoIaAgenda::class);

        // O save do Portal (todos) e, dentro da espera, a soltura de um item do mesmo produto: o Job novo leva todos.
        $x = $this->produtoCompleto($empresa, 'Vaso Alfa', 'VA-1')['produto'];
        $agenda->aoSalvar((int) $empresa->id, [$x->id]);
        $agenda->aoSalvar((int) $empresa->id, [$x->id], [$x->id => ["s-{$x->id}"]]);
        $this->assertSame([null, null], $this->soItensDosJobs((int) $x->id));
        $this->assertSame(Queue::pushed(PrepararProdutoNoPublicadorJob::class)->last()->marca, PreparoIaAgenda::marcaAtual((int) $x->id),
            'o Job novo é o que vale (debounce)');

        // Duas solturas seguidas de itens do mesmo produto: o segundo Job leva os dois.
        $y = $this->produtoCompleto($empresa, 'Vaso Beta', 'VB-1')['produto'];
        $agenda->aoSalvar((int) $empresa->id, [$y->id], [$y->id => ["s-{$y->id}"]]);
        $agenda->aoSalvar((int) $empresa->id, [$y->id], [$y->id => ["c-{$y->id}-2"]]);
        $this->assertSame([["s-{$y->id}"], ["s-{$y->id}", "c-{$y->id}-2"]], $this->soItensDosJobs((int) $y->id));

        // Fora da chave de trabalho (a trilha aberta, sem as ondas), nada muda: o pedido passa direto.
        $fora = $this->empresaDaTrilha(trabalho: false);
        $z = $this->produtoCompleto($fora, 'Vaso Gama', 'VG-1')['produto'];
        $agenda->aoSalvar((int) $fora->id, [$z->id]);
        $agenda->aoSalvar((int) $fora->id, [$z->id], [$z->id => ["s-{$z->id}"]]);
        $this->assertSame([null, ["s-{$z->id}"]], $this->soItensDosJobs((int) $z->id));
    }

    public function test_o_job_que_ja_comecou_nao_entra_na_soma_do_pedido_seguinte(): void
    {
        $empresa = $this->empresaDaTrilha();
        $x = $this->produtoCompleto($empresa, 'Vaso Alfa', 'VA-1')['produto'];
        $agenda = app(PreparoIaAgenda::class);
        [$s, $c, $o] = ["s-{$x->id}", "c-{$x->id}-2", 'o-999'];

        $agenda->aoSalvar((int) $empresa->id, [$x->id], [$x->id => [$s]]);
        // O preparo dele passou da espera e começou a rodar: os itens dele já estão sendo preparados.
        PreparoIaAgenda::comecou((int) $x->id, (string) PreparoIaAgenda::marcaAtual((int) $x->id));
        $agenda->aoSalvar((int) $empresa->id, [$x->id], [$x->id => [$c]]);
        // Este ainda não começou: o pedido seguinte volta a somar.
        $agenda->aoSalvar((int) $empresa->id, [$x->id], [$x->id => [$o]]);

        $this->assertSame([[$s], [$c], [$c, $o]], $this->soItensDosJobs((int) $x->id));
    }

    public function test_o_preparo_adiado_pelo_editor_se_soma_ao_pedido_seguinte_e_o_que_rodou_nao(): void
    {
        $this->comPreparo();
        $p = $this->produtoDoPortal('CAD');
        $q = $this->produtoDoPortal('CDB');
        $kit = $this->ofertaComposta($this->empresa, EstruturaOferta::FASE_KIT, 'KT-CAD-CDB', 'Kit KT-CAD-CDB',
            [[$this->simplesDe($p), 1], [$this->simplesDe($q), 1]]);
        $this->ofertaComposta($this->empresa, EstruturaOferta::FASE_COMBO, 'CAD-AZ-CB2', 'Combo 2 Cadeira', [[$this->simplesDe($p), 2]]);
        // Um save antes, sem onda nenhuma (a trava segura tudo), só para os pub_produtos existirem.
        app(PreparoIaAgenda::class)->aoSalvar((int) $this->empresa->id, [$p->id]);
        $this->assertSame(['pronto'], $this->rodar(PrepararProdutoNoPublicadorJob::class));
        Queue::assertNotPushed(GerarPreparoIaJob::class);

        // 10:00: a onda começa e pede a Simples de P — mas alguém está gravando no editor do grupo: o preparo espera.
        $onda = $this->onda($this->empresa, '2026-11-05', 'Onda 1');
        $this->itemNaOnda($onda, "s-{$p->id}");
        $this->assertSame(["s-{$p->id}"], $this->soltura()->rodar()[0]['pedidos']);
        // O save de antes já rodou (o `preparar` o marcou como começado): o "todos" dele não se soma — senão toda soltura
        // do produto prepararia o produto inteiro, refazendo de carona a etapa paga que deu erro.
        $this->assertSame(["s-{$p->id}"], $this->ultimoSoItens((int) $p->id), 'o save que já rodou entrou na soma');
        $grupo = (int) $this->pubDo($p)->id;
        EditorEmUso::marcarEdicao($grupo);
        $this->assertSame(['adiado'], $this->rodar(PrepararProdutoNoPublicadorJob::class));

        // 10:01: o kit entra na mesma onda e é pedido. O adiado não começou: o Job novo de P o supera e leva os dois.
        $this->agora('2026-11-05 10:01:00');
        $this->itemNaOnda($onda, "o-{$kit->id}");
        $this->assertSame(["o-{$kit->id}"], $this->soltura()->rodar()[0]['pedidos']);
        $this->assertSame(["s-{$p->id}", "o-{$kit->id}"], $this->ultimoSoItens((int) $p->id), 'a Simples adiada se perderia');

        // 10:05: o editor fechou. O adiado sai superado; o novo prepara a Simples e o kit.
        $this->agora('2026-11-05 10:05:00');
        Cache::forget(EditorEmUso::chaveDaEdicao($grupo));
        $this->assertSame(['superado', 'pronto', 'pronto'], $this->rodar(PrepararProdutoNoPublicadorJob::class));
        $this->rodar(GerarPreparoIaJob::class);
        $this->assertGreaterThan(0, $this->pedidosDaIa($this->rascunhoDo($p)->id, 'titulo'), 'a Simples pedida com o editor aberto não foi preparada');
        $this->assertGreaterThan(0, $this->pedidosDaIa($this->rascunhoDaOferta($kit)->id), 'o kit não foi preparado');

        // 10:06: o Combo 2 de P entra na onda. O preparo anterior já rodou (o `preparar` o marcou como começado): o Job
        // novo leva só o combo — somar refaria, de carona, o que acabou de ser pedido.
        $this->agora('2026-11-05 10:06:00');
        $this->itemNaOnda($onda, "c-{$p->id}-2");
        $this->assertSame(["c-{$p->id}-2"], $this->soltura()->rodar()[0]['pedidos']);
        $this->assertSame(["c-{$p->id}-2"], $this->ultimoSoItens((int) $p->id));
    }

    // ═══ B. Combo apagado e criado de novo antes da passada ═════════════════

    public function test_combo_recriado_antes_da_passada_e_segurado_pela_trava_e_aparece_sem_onda(): void
    {
        $this->agora('2026-11-05 09:00:00');
        $empresa = $this->empresaDaTrilha();
        $a = $this->produtoCompleto($empresa, 'Vaso Alfa', 'VA-1');
        $combo = $this->ofertaComposta($empresa, EstruturaOferta::FASE_COMBO, 'VA-1-CB2', 'Combo 2 Vaso Alfa', [[$a['simples'][0], 2]]);
        $p = (int) $a['produto']->id;
        $c = "c-{$p}-2";
        $onda = $this->onda($empresa, '2026-11-05', 'Onda 1', '2026-11-05 00:07:00');
        $this->itemNaOnda($onda, "s-{$p}", '2026-11-05 00:07:00');
        $this->itemNaOnda($onda, $c, '2026-11-05 00:07:00');
        $segura = fn () => app(PortaoDaOnda::class)->seguraOCombo($p, 2, (int) $empresa->id);

        // Controle: o combo da onda que já começou passa, e a tela o mostra nela.
        $this->assertFalse($segura());
        $this->assertSame((int) $onda->id, $this->itemDaTrilha($this->trilha($empresa), $c)['onda_id']);

        // 10:05: o Portal apaga o Combo 2 e o cria de novo — outro combo: todas as ofertas mais novas que a linha.
        $this->agora('2026-11-05 10:05:00');
        $combo->componentes()->delete();
        $combo->delete();
        $this->ofertaComposta($empresa, EstruturaOferta::FASE_COMBO, 'VA-1-CB2', 'Combo 2 Vaso Alfa', [[$a['simples'][0], 2]]);

        // Antes da passada (a linha velha segue no banco): a trava segura o combo novo e a tela já o mostra Sem onda.
        $this->assertSame(1, TrilhaOndaItem::query()->where('quantidade', 2)->count(), 'a linha velha só sai na passada');
        $this->assertTrue($segura(), 'o combo recriado herdou a onda que já começou');
        $naTela = $this->itemDaTrilha($this->trilha($empresa), $c);
        $this->assertSame([null, 'plano', 'Sem onda · falta escolher a data', true],
            [$naTela['onda_id'], $naTela['etapa'], $naTela['status'], $naTela['planejavel']]);

        // A passada apaga a linha; a trava e a tela seguem iguais.
        $this->agora('2026-11-05 10:07:00');
        $this->assertSame([$c], $this->soltura()->rodar()[0]['orfaos']);
        $this->assertTrue($segura());
        $this->assertNull($this->itemDaTrilha($this->trilha($empresa), $c)['onda_id']);
    }

    public function test_combo_recriado_antes_da_passada_nao_faz_nascer_o_kit_n(): void
    {
        Http::fake();
        $this->montarPlanejamento();
        config(['trilha.empresas' => [$this->empresaP->id], 'trilha.trabalho.empresas' => [$this->empresaP->id]]);
        // A Simples já publicada: com o combo numa onda que começou, o Kit N poderia nascer a qualquer hora.
        $grupo = $this->grupoComRascunho();
        $this->aceitarCombos(2);
        $onda = TrilhaOnda::create(['company_id' => $this->empresaP->id, 'nome' => 'Onda Velha', 'data' => '2026-11-05', 'liberada_em' => now()]);
        TrilhaOndaItem::create(['onda_id' => $onda->id, 'company_id' => $this->empresaP->id, 'produto_id' => $this->produtoP->id,
            'quantidade' => 2, 'pedido_em' => now(), 'na_onda_desde' => now()]);

        // 10:05: todas as cores do Combo 2 apagadas e aceitas de novo — é outro combo, que ninguém pôs em onda.
        $this->agora('2026-11-05 10:05:00');
        foreach (EstruturaOferta::query()->where('company_id', $this->empresaP->id)->where('fase', EstruturaOferta::FASE_COMBO)->get() as $o) {
            $o->componentes()->delete();
            $o->delete();
        }
        $this->aceitarCombos(2);

        // Antes da passada: o Kit 2 não nasce (é o caminho que o Sincronizar do próprio save do Portal alcança).
        $r = app(CombosDoPlanejamentoService::class)->criarOsQueFaltam($grupo->fresh());
        $this->assertSame([], $r['criados'], 'o Kit 2 nasceu pela onda da linha velha');
        $this->assertSame([['quantidade' => 2, 'motivo' => 'espera a onda da trilha']], $r['pulados']);
        $this->assertSame(0, PubProduto::query()->where('produto_base_id', $grupo->id)->count());

        // Depois da passada (a linha sai), também não.
        $this->agora('2026-11-05 10:07:00');
        $this->assertSame(["c-{$this->produtoP->id}-2"], $this->soltura()->rodar()[0]['orfaos']);
        $this->assertSame([], app(CombosDoPlanejamentoService::class)->criarOsQueFaltam($grupo->fresh())['criados']);
    }

    public function test_mudar_a_data_da_onda_da_simples_tira_antes_a_linha_do_combo_recriado(): void
    {
        [$empresa, $daSimples, $s, $c] = $this->simplesComOComboRecriadoNoutraOnda();

        // A data nova põe a Simples depois da onda da linha velha: sem a limpeza, a conferência recusaria por um combo
        // que já não existe.
        $this->ondas()->editar($daSimples, $this->dev, null, '2026-11-28');

        $this->assertSame('2026-11-28', $daSimples->fresh()->dia());
        $this->assertSame([$s], $this->chavesNasOndas($empresa));
        $this->assertNull($this->itemDaTrilha($this->trilha($empresa), $c)['onda_id']);
    }

    public function test_apagar_a_onda_da_simples_tira_antes_a_linha_do_combo_recriado(): void
    {
        [$empresa, $daSimples, , $c] = $this->simplesComOComboRecriadoNoutraOnda();

        // A Simples sai da onda: sem a limpeza, a linha velha do combo noutra onda faria a recusa "ficaria sem onda".
        $this->assertSame(1, $this->ondas()->excluir($daSimples, $this->dev));

        $this->assertNull(TrilhaOnda::query()->find($daSimples->id));
        $this->assertSame([], $this->chavesNasOndas($empresa));
        $this->assertNull($this->itemDaTrilha($this->trilha($empresa), $c)['onda_id']);
    }

    // ═══ C. O kit que ficou sem produto dentro da onda pode sair ═════════════

    public function test_o_kit_que_ficou_sem_produto_dentro_da_onda_pode_sair_dela_mas_nao_entrar_noutra(): void
    {
        $empresa = $this->empresaDaTrilha();
        $a = $this->produtoCompleto($empresa, 'Vaso Alfa', 'VA-1');
        $b = $this->produtoCompleto($empresa, 'Banqueta Beta', 'BB-1');
        $kit = $this->ofertaComposta($empresa, EstruturaOferta::FASE_KIT, 'KT-VA-1-BB-1', 'Kit Alfa e Beta', [[$a['simples'][0], 1], [$b['simples'][0], 1]]);
        $k = "o-{$kit->id}";
        $onda = $this->ondas()->criar($empresa, $this->dev, 'Onda Futura', '2026-11-20');
        $outra = $this->ondas()->criar($empresa, $this->dev, 'Onda Outra', '2026-11-25');
        $this->ondas()->planejar($empresa, $this->dev, [$k], $onda->id);

        // Os produtos das peças saem da Lista: a variação apagada deixa a oferta simples sem variação (o `nullOnDelete`
        // da coluna) — o kit fica só com ofertas sem produto, já dentro da onda.
        EstruturaOferta::query()->whereKey([$a['simples'][0]->id, $b['simples'][0]->id])->update(['variacao_id' => null]);
        $this->assertTrue(TrilhaDaEmpresa::itens($empresa)[$k]['sem_produto']);

        // Pôr noutra onda segue recusado...
        $this->assertRecusado(fn () => $this->ondas()->planejar($empresa, $this->dev, [$k], $outra->id), 'itens', 'não tem produto na Lista');
        $this->assertSame([$k], $this->chavesNasOndas($empresa));
        // ... mas tirar vale: sem isso ele ficaria preso na onda, com o status "Fora das ondas".
        $r = $this->ondas()->planejar($empresa, $this->dev, [$k], null);

        $this->assertSame([$k], $r['mudados']);
        $this->assertSame([], $this->chavesNasOndas($empresa));
    }

    // ═══ D. O status da oferta sem produto ═══════════════════════════════════

    public function test_com_a_chave_a_oferta_sem_produto_parada_sai_fora_das_ondas_sem_alerta_inclusive_o_combo_dela(): void
    {
        $empresa = $this->empresaDaTrilha();
        $avulsa = EstruturaOferta::create(['company_id' => $empresa->id, 'variacao_id' => null, 'sku' => 'AV-9', 'fase' => 'simples', 'nome' => 'Avulso 9']);
        $combo = $this->ofertaComposta($empresa, EstruturaOferta::FASE_COMBO, 'AV-9-CB2', 'Combo 2 Avulso', [[$avulsa, 2]]);

        $trilha = $this->trilha($empresa);
        foreach (['a oferta avulsa' => "o-{$avulsa->id}", 'o combo dela' => "o-{$combo->id}"] as $caso => $chave) {
            $item = $this->itemDaTrilha($trilha, $chave);
            $this->assertSame(['plano', 'Fora das ondas · sem produto na Lista', false, true, false],
                [$item['etapa'], $item['status'], $item['alerta'], $item['sem_produto'], $item['planejavel']], $caso);
        }

        // Fora da chave de trabalho a tela da 1ª entrega não muda: o combo espera a simples ir ao ar.
        $fora = $this->empresaDaTrilha(trabalho: false);
        $avulsaFora = EstruturaOferta::create(['company_id' => $fora->id, 'variacao_id' => null, 'sku' => 'AV-8', 'fase' => 'simples', 'nome' => 'Avulso 8']);
        $comboFora = $this->ofertaComposta($fora, EstruturaOferta::FASE_COMBO, 'AV-8-CB2', 'Combo 2 Avulso Fora', [[$avulsaFora, 2]]);
        $item = $this->itemDaTrilha($this->trilha($fora), "o-{$comboFora->id}");
        $this->assertSame(['plano', 'Espera a oferta simples ir ao ar', false], [$item['etapa'], $item['status'], $item['alerta']]);
    }

    // ═══ F. Com o preparo pela IA desligado, a soltura não pede ═════════════

    public function test_com_o_preparo_desligado_a_soltura_nao_pede_nem_marca_e_religado_a_passada_seguinte_pede(): void
    {
        $empresa = $this->empresaDaTrilha();
        $a = $this->produtoCompleto($empresa, 'Vaso Alfa', 'VA-1');
        $s = "s-{$a['produto']->id}";
        $linha = $this->itemNaOnda($this->onda($empresa, '2026-11-05', 'Onda 1'), $s);
        config(['publicador.preparo_ia.ativo' => false]);

        $resumo = $this->soltura()->rodar()[0];

        $this->assertSame(['Onda 1'], $resumo['soltas'], 'a onda começa mesmo assim');
        $this->assertSame([[], []], [$resumo['pedidos'], $resumo['repescados']]);
        $this->assertNull($linha->fresh()->pedido_em, 'marcar o pedido mentiria e gastaria a repescagem');
        Queue::assertNotPushed(PrepararProdutoNoPublicadorJob::class);

        // Religado no mesmo dia, a passada seguinte pede o robô — só para o item da onda.
        config(['publicador.preparo_ia.ativo' => true]);
        $this->agora('2026-11-05 10:15:00');
        $resumo = $this->soltura()->rodar()[0];

        $this->assertSame([$s], $resumo['pedidos']);
        $this->assertNotNull($linha->fresh()->pedido_em);
        Queue::assertPushed(PrepararProdutoNoPublicadorJob::class, 1);
        Queue::assertPushed(PrepararProdutoNoPublicadorJob::class, fn ($j) => $j->estruturaProdutoId === (int) $a['produto']->id && $j->soItens === [$s]);
    }

    // ═══ H. Fora da chave, o kit só de avulsas segue como na 1ª entrega ══════

    public function test_fora_da_chave_o_kit_so_de_ofertas_avulsas_sai_com_produto_na_tela_como_na_1a_entrega(): void
    {
        $fora = $this->empresaDaTrilha(trabalho: false);
        [$kit, $pub] = $this->kitDeAvulsas($fora);
        $k = "o-{$kit->id}";

        $naTela = $this->itemDaTrilha($this->trilha($fora), $k);
        $this->assertFalse($naTela['sem_produto'], 'fora da chave, a tela da 1ª entrega passou a marcar o kit de avulsas como sem produto');
        $this->assertFalse($naTela['planejavel']);
        $this->assertFalse(ItemDaTrilha::doPub($pub)['sem_produto'], 'a régua inversa concorda com a tela');
        // A régua da trilha de trabalho (trava, soltura, ondas) é a mesma com ou sem a chave: sem produto.
        $this->assertTrue(TrilhaDaEmpresa::itens($fora)[$k]['sem_produto']);

        // Com a chave, a tela também diz sem produto.
        $dentro = $this->empresaDaTrilha();
        [$kitDentro] = $this->kitDeAvulsas($dentro);
        $this->assertTrue($this->itemDaTrilha($this->trilha($dentro), "o-{$kitDentro->id}")['sem_produto']);
        $this->assertTrue(TrilhaDaEmpresa::itens($dentro)["o-{$kitDentro->id}"]['sem_produto']);
    }

    // ═══ I. `pode_tirar` ═════════════════════════════════════════════════════

    public function test_pode_tirar_vale_para_quem_esta_numa_onda_que_nao_comecou_e_nunca_chega_ao_cliente(): void
    {
        $empresa = $this->empresaDaTrilha();
        $futura = $this->onda($empresa, '2026-11-20', 'Onda Futura');
        $comecada = $this->onda($empresa, '2026-11-05', 'Onda Hoje', '2026-11-05 00:07:00');

        $naFila = $this->produtoCompleto($empresa, 'Vaso Alfa', 'VA-1');
        $this->itemNaOnda($futura, "s-{$naFila['produto']->id}");
        // Andou antes da data: um MLB colado no Portal (alguém publicou à mão).
        $andou = $this->produtoCompleto($empresa, 'Vaso Beta', 'VB-1');
        $this->itemNaOnda($futura, "s-{$andou['produto']->id}");
        $this->anuncioColado($andou['simples'][0]);
        // Ficou sem produto já dentro da onda (a linha estava no banco antes de as peças saírem da Lista).
        [$semProduto] = $this->kitDeAvulsas($empresa);
        $this->itemNaOnda($futura, "o-{$semProduto->id}");
        // Numa onda que já começou: não sai mais.
        $jaComecou = $this->produtoCompleto($empresa, 'Vaso Gama', 'VG-1');
        $this->itemNaOnda($comecada, "s-{$jaComecou['produto']->id}", '2026-11-05 00:07:00');
        // Sem onda (mapeado, à espera da data): não há de onde tirar.
        $semOnda = $this->produtoCompleto($empresa, 'Vaso Delta', 'VD-1');
        $this->mapeado($empresa, $semOnda);

        $trilha = $this->trilha($empresa);
        $esperado = [
            "s-{$naFila['produto']->id}"  => [(int) $futura->id, true, true],
            "s-{$andou['produto']->id}"   => [(int) $futura->id, false, true],
            "o-{$semProduto->id}"         => [(int) $futura->id, false, true],
            "s-{$jaComecou['produto']->id}" => [(int) $comecada->id, true, false],
            "s-{$semOnda['produto']->id}" => [null, true, false],
        ];
        foreach ($esperado as $chave => $valores) {
            $item = $this->itemDaTrilha($trilha, $chave);
            $this->assertNotNull($item, $chave);
            $this->assertSame($valores, [$item['onda_id'], $item['planejavel'], $item['pode_tirar'] ?? null], $chave);
        }

        // A porta da equipe leva o campo; a do cliente, nunca (ele acompanha, não tira ninguém da onda).
        $apresentacao = app(ApresentacaoDaTrilha::class);
        $equipe = array_column($apresentacao->paraEquipe($empresa, $trilha, $this->dev)['ofertas'], null, 'id');
        foreach ($esperado as $chave => [, , $podeTirar]) {
            $this->assertSame($podeTirar, $equipe[$chave]['pode_tirar'] ?? null, "equipe: {$chave}");
        }
        URL::defaults(['portalEmpresa' => $empresa->id]);
        $cliente = $apresentacao->paraCliente($empresa, $trilha, false)['ofertas'];
        $this->assertNotEmpty($cliente);
        foreach ($cliente as $oferta) {
            $this->assertFalse($oferta['pode_tirar'] ?? null, "cliente: {$oferta['id']}");
        }

        // Fora da chave de trabalho não há onda: ninguém tira nada.
        $fora = $this->empresaDaTrilha(trabalho: false);
        [$kitFora] = $this->kitDeAvulsas($fora);
        $this->assertFalse($this->itemDaTrilha($this->trilha($fora), "o-{$kitFora->id}")['pode_tirar'] ?? null);
    }

    // ═══ J. O combo preso numa onda que já começou ══════════════════════════

    public function test_com_o_combo_numa_onda_que_ja_comecou_a_recusa_manda_por_a_simples_na_onda_dele(): void
    {
        $empresa = $this->empresaDaTrilha();
        $a = $this->produtoCompleto($empresa, 'Vaso Alfa', 'VA-1');
        $s = "s-{$a['produto']->id}";
        $c = "c-{$a['produto']->id}-2";
        $this->ofertaComposta($empresa, EstruturaOferta::FASE_COMBO, 'VA-1-CB2', 'Combo 2 Vaso Alfa', [[$a['simples'][0], 2]]);
        $futura = $this->ondas()->criar($empresa, $this->dev, 'Onda Futura', '2026-11-20');
        $this->ondas()->planejar($empresa, $this->dev, [$c], $futura->id);
        $this->assertEqualsCanonicalizing([$s, $c], $this->chavesNasOndas($empresa), 'a Simples entrou junto');

        // Um MLB colado no Portal faz a Simples "andar": sem par a conferir, o combo vai para a onda de hoje (que começa
        // na hora). O MLB era engano e sai: a Simples volta a não ter andado — na Onda Futura, depois do combo dela.
        $anuncio = $this->anuncioColado($a['simples'][0]);
        $hoje = $this->ondas()->criar($empresa, $this->dev, 'Onda Hoje', '2026-11-05');
        $this->ondas()->planejar($empresa, $this->dev, [$c], $hoje->id);
        $anuncio->delete();
        $this->assertFalse(TrilhaDaEmpresa::itens($empresa)[$s]['andou']);

        // O combo não sai nem muda mais de onda: "tire o combo" não dá para fazer. Ao tirar a simples, ou ao apagar a
        // onda dela, a recusa manda pôr a simples na onda do combo.
        foreach ([
            'tirar a simples'    => fn () => $this->ondas()->planejar($empresa, $this->dev, [$s], null),
            'apagar a onda dela' => fn () => $this->ondas()->excluir($futura, $this->dev),
        ] as $caso => $pedido) {
            $erro = $this->recusa($pedido, 'itens', $caso);
            $this->assertStringContainsString('que já começou', $erro, $caso);
            $this->assertStringContainsString('Ponha a simples na Onda Hoje', $erro, $caso);
        }
        // Mover a simples para uma onda ainda mais tarde: a recusa também aponta a onda do combo, não "tire o combo".
        $depois = $this->ondas()->criar($empresa, $this->dev, 'Onda Depois', '2026-11-25');
        $erro = $this->recusa(fn () => $this->ondas()->planejar($empresa, $this->dev, [$s], $depois->id), 'itens', 'onda posterior');
        $this->assertStringContainsString('Ponha a simples na Onda Hoje', $erro);
        $this->assertStringNotContainsString('Ponha o combo', $erro);
        $this->assertSame(2, TrilhaOndaItem::query()->where('company_id', $empresa->id)->count(), 'nada mudou');

        // A saída que a mensagem aponta funciona.
        $this->assertSame([$s], $this->ondas()->planejar($empresa, $this->dev, [$s], $hoje->id)['mudados']);
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
     * A Simples na "Onda Simples" (20/11) e o Combo 2 dela na "Onda Combo" (25/11), planejados às 10:00; às 10:05 o
     * Portal apaga o Combo 2 e o cria de novo — a linha velha fica no banco até a próxima passada da soltura.
     *
     * @return array{0: Company, 1: TrilhaOnda, 2: string, 3: string}
     */
    private function simplesComOComboRecriadoNoutraOnda(): array
    {
        $this->agora('2026-11-05 10:00:00');
        $empresa = $this->empresaDaTrilha();
        $a = $this->produtoCompleto($empresa, 'Vaso Alfa', 'VA-1');
        $s = "s-{$a['produto']->id}";
        $c = "c-{$a['produto']->id}-2";
        $combo = $this->ofertaComposta($empresa, EstruturaOferta::FASE_COMBO, 'VA-1-CB2', 'Combo 2 Vaso Alfa', [[$a['simples'][0], 2]]);
        $daSimples = $this->ondas()->criar($empresa, $this->dev, 'Onda Simples', '2026-11-20');
        $doCombo = $this->ondas()->criar($empresa, $this->dev, 'Onda Combo', '2026-11-25');
        $this->ondas()->planejar($empresa, $this->dev, [$s], $daSimples->id);
        $this->ondas()->planejar($empresa, $this->dev, [$c], $doCombo->id);
        $this->assertEqualsCanonicalizing([$s, $c], $this->chavesNasOndas($empresa));

        $this->agora('2026-11-05 10:05:00');
        $combo->componentes()->delete();
        $combo->delete();
        $this->ofertaComposta($empresa, EstruturaOferta::FASE_COMBO, 'VA-1-CB2', 'Combo 2 Vaso Alfa', [[$a['simples'][0], 2]]);

        return [$empresa, $daSimples, $s, $c];
    }

    /**
     * Um Kit de duas ofertas avulsas (sem produto na Lista: Lista SKUs, importação) e o pub_produto dele.
     *
     * @return array{0: EstruturaOferta, 1: PubProduto}
     */
    private function kitDeAvulsas(Company $empresa): array
    {
        $av1 = EstruturaOferta::create(['company_id' => $empresa->id, 'variacao_id' => null, 'sku' => "AV-{$empresa->id}-1", 'fase' => 'simples', 'nome' => 'Avulso 1']);
        $av2 = EstruturaOferta::create(['company_id' => $empresa->id, 'variacao_id' => null, 'sku' => "AV-{$empresa->id}-2", 'fase' => 'simples', 'nome' => 'Avulso 2']);
        $kit = $this->ofertaComposta($empresa, EstruturaOferta::FASE_KIT, "KT-AV-{$empresa->id}", 'Kit Avulsos', [[$av1, 1], [$av2, 1]]);

        return [$kit, $this->ofertaNoPublicador($empresa, $kit)];
    }

    /** A oferta simples (a cor única) da cadeira do cenário do preparo. */
    private function simplesDe(EstruturaProduto $p): EstruturaOferta
    {
        return EstruturaOferta::query()->where('company_id', $p->company_id)->where('sku', "{$p->codigo}-AZ")->firstOrFail();
    }

    /** O rascunho do pub_produto do composto (o Sincronizar das peças o cria). */
    private function rascunhoDaOferta(EstruturaOferta $oferta): PubRascunho
    {
        return PubRascunho::query()->where('produto_id', PubProduto::query()->where('oferta_id', $oferta->id)->value('id'))->firstOrFail();
    }

    /** Quantas vezes a IA paga do preparo foi pedida para o rascunho (numa etapa, ou em qualquer uma). */
    private function pedidosDaIa(int $rascunhoId, ?string $etapa = null): int
    {
        return Queue::pushed(GerarPreparoIaJob::class, fn ($j) => $j->rascunhoId === $rascunhoId && ($etapa === null || $j->etapa === $etapa))->count();
    }

    /** O `soItens` de cada Job de preparo do produto que entrou na fila, na ordem (null = todos). @return list<?list<string>> */
    private function soItensDosJobs(int $produtoId): array
    {
        return Queue::pushed(PrepararProdutoNoPublicadorJob::class, fn ($j) => $j->estruturaProdutoId === $produtoId)
            ->map(fn ($j) => $j->soItens)->values()->all();
    }

    /** O `soItens` do Job de preparo mais novo do produto. */
    private function ultimoSoItens(int $produtoId): ?array
    {
        return Queue::pushed(PrepararProdutoNoPublicadorJob::class, fn ($j) => $j->estruturaProdutoId === $produtoId)->last()?->soItens;
    }

    /** @return list<string> as chaves dos itens que estão em alguma onda da empresa */
    private function chavesNasOndas(Company $empresa): array
    {
        return TrilhaOndaItem::query()->where('company_id', $empresa->id)->orderBy('id')->get()->map->chave()->all();
    }

    /** O "Concluir mapeamento" da trilha, direto no banco. */
    private function mapeado(Company $empresa, array $p): void
    {
        TrilhaMapeamento::create(['company_id' => $empresa->id, 'produto_id' => $p['produto']->id, 'concluido_em' => now()]);
    }

    /** Um MLB colado no Portal para a oferta: ela "andou". */
    private function anuncioColado(EstruturaOferta $oferta): EstruturaAnuncio
    {
        return EstruturaAnuncio::create(['oferta_id' => $oferta->id, 'tipo' => EstruturaAnuncio::TIPO_CLASSICO,
            'status' => EstruturaAnuncio::STATUS_ATIVO, 'codigo_mlb' => 'MLB1234567890', 'titulo' => 'Vaso']);
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

    private function ondas(): OndasDaTrilha
    {
        return app(OndasDaTrilha::class);
    }

    private function trilha(Company $empresa): array
    {
        return app(TrilhaDaEmpresa::class)->montar($empresa->fresh());
    }

    private function assertRecusado(callable $pedido, string $campo, string $trecho): void
    {
        $this->assertStringContainsString($trecho, $this->recusa($pedido, $campo, $trecho));
    }

    /** A mensagem da recusa (422) no campo; falha se o pedido passar. */
    private function recusa(callable $pedido, string $campo, string $caso): string
    {
        try {
            $pedido();
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($campo, $e->errors(), json_encode($e->errors(), JSON_UNESCAPED_UNICODE));

            return implode(' ', $e->errors()[$campo]);
        }
        $this->fail("Esperava a recusa (422) em '{$campo}' ({$caso}).");
    }
}
