<?php

namespace Tests\Feature\Trilha;

use App\Jobs\Publicador\GerarPreparoIaJob;
use App\Jobs\Trilha\AjustarPeloRoboNaTrilhaJob;
use App\Jobs\Trilha\PublicarPelaTrilhaJob;
use App\Models\Company;
use App\Models\EstruturaOferta;
use App\Models\EstruturaProduto;
use App\Models\PubProduto;
use App\Models\PubRascunho;
use App\Models\TrilhaOnda;
use App\Models\User;
use App\Services\EcfDriveService;
use App\Services\Ia\AnaliseAnuncioService;
use App\Services\Publicador\CriarFaseService;
use App\Services\Publicador\EditorRascunhoService;
use App\Services\Publicador\PreparoIaAgenda;
use App\Services\Publicador\RascunhoRepository;
use App\Services\Trilha\AjusteDoRoboNaTrilha as Ajuste;
use App\Services\Trilha\PublicacaoNaTrilha;
use App\Services\Trilha\SituacaoDasOfertas;
use App\Services\Trilha\TrilhaDaEmpresa;
use App\Support\Publicador\EditorEmUso;
use App\Support\TituloDoProduto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Mockery\MockInterface;
use Spatie\Activitylog\Models\Activity;
use Tests\Feature\Publicador\Lote\CenarioDoPreparo;
use Tests\Feature\Trilha\Concerns\MontaTrilha;
use Tests\TestCase;

/**
 * "Pedir ajuste ao agente" na revisão (4ª entrega, fatia 4b, desenho §19.14, D51/D60): `POST /trilha/{empresa}/ofertas/{item}/ajuste`
 * `{revisao, refazer}` põe na fila da IA um Job que refaz o título (com o Modelo) e/ou a descrição com os geradores do
 * preparo e grava pelo `executarEtapa` do Publicador; `GET …/ajuste` acompanha.
 *
 * O cenário é o do preparo de verdade (`CenarioDoPreparo`, como a 4a/4c/4d): a cadeira preparada pelo agente, na revisão, na
 * loja 459, com as chaves da trilha ligadas só depois do preparo. A IA é FALSA e anota cada chamada (método e argumentos):
 * nenhuma IA nem Mercado Livre de verdade. O Job sai da fila de mentira e roda à mão. O que se prova:
 *
 * - título novo diferente dos atuais (o `evitar` chega ao prompt e o servidor garante), com o Modelo junto; a descrição;
 * - parte editada pela equipe → `mantido`, nada sobrescrito e NENHUMA chamada nem cota gasta; a equipe que edita DURANTE
 *   a geração também vence (quem decide é o `executarEtapa`, sob a trava);
 * - editor em uso → adiado: o valor gerado vai no Job adiado do Publicador (sem gerar de novo) e o acompanhamento fecha;
 * - 409: na fila, publicando, publicado, fora da revisão, conferindo para publicar, texto que não é do agente (kit da fase
 *   N), ajuste em andamento, revisão velha (sem `travado`); 422: `refazer` inválido e o 4º pedido do dia;
 * - a cota diária da LOJA (o MESMO contador do preparo) esgotada → `erro` sem chamar a IA;
 * - o Job na fila da IA, nunca na `high`; texto livre no corpo não chega a lugar nenhum (Job, estado, histórico, IA);
 * - D60: "Agente refazendo · …" na linha da Lista enquanto anda, e a régua de sempre depois; o vencido não segura a linha;
 * - o `ajuste` da prévia = o do GET; o histórico com quem pediu e as partes; a 4d espera o ajuste terminar.
 */
class AjusteDoRoboNaTrilhaTest extends TestCase
{
    use CenarioDoPreparo;
    use MontaTrilha;
    use RefreshDatabase;

    /** O objeto `ajuste` do contrato, na ordem. */
    private const CHAVES = ['situacao', 'partes', 'restantes_hoje', 'mensagem'];

    /** Os títulos que o preparo do cenário escreveu (a IA falsa do `CenarioDoPreparo`). */
    private const TITULO_C = 'Cadeira Escritório Giratória Ergonômica';

    private const TITULO_P = 'Cadeira Giratória Escritório Ergonômica';

    /** A marca do "texto livre" que não pode chegar a lugar nenhum. */
    private const MARCA = 'XYZZY-TEXTO-LIVRE-4B';

    private EstruturaProduto $d;

    private PubProduto $pub;

    private User $dev;

    /** @var list<array{0: string, 1: array}> as chamadas à IA falsa: [método, argumentos] */
    private array $chamadas = [];

    /** Chamado DENTRO da geração da descrição (a equipe editando enquanto o agente pensa). */
    private ?\Closure $durante = null;

    private \SplObjectStorage $ajustesRodados;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'trilha.empresas' => [], 'trilha.trabalho.empresas' => [],
            'publicador.preparo_ia.ativo' => true, 'publicador.criativos_auto.ativo' => false,
            'publicador.contas_liberadas.todas' => false, 'publicador.contas_liberadas.companies' => [],
        ]);
        Carbon::setTestNow(Carbon::parse('2026-11-05 10:00:00', TrilhaOnda::FUSO));
        $this->montarPreparo();
        // A loja da trilha é a 459 (a 4d só publica nela: o teste de "publicar espera o ajuste" usa a mesma cadeira).
        $this->empresa = Company::factory()->create(['id' => PublicacaoNaTrilha::LOJA]);
        // A página da equipe (a linha da Lista e a `escrita`) monta o menu interno, cujo badge pergunta ao ECF Drive.
        $this->mock(EcfDriveService::class, fn (MockInterface $m) => $m->shouldReceive('listSignals')->andReturn(['total' => 0]));
        $this->ajustesRodados = new \SplObjectStorage();

        $this->d = $this->produtoDoPortal('CAD');
        $this->salvarERodar($this->d);
        $this->pub = $this->pubDo($this->d);
        $this->ligarATrilha();
        $this->dev = User::factory()->create(['role' => 'admin', 'active' => true, 'name' => 'Rafael']);
        $this->dev->forceFill(['is_dev' => true])->save();
        // Daqui em diante, a IA falsa que ANOTA (a do preparo já fez o trabalho dela).
        $this->iaQueAnota();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ═══ Refazer ══════════════════════════════════════════════════════════════

    /**
     * A IA falsa devolve os MESMOS títulos de antes: o `evitar` (os títulos atuais dos dois tipos) vai ao prompt e o
     * servidor garante dois títulos novos. O Modelo é refeito junto; a descrição não é tocada.
     */
    public function test_refaz_o_titulo_diferente_dos_atuais_com_o_modelo_junto_e_a_descricao_fica(): void
    {
        $r = $this->rascunho();
        $this->assertSame(['gold_special' => self::TITULO_C, 'gold_pro' => self::TITULO_P], $this->titulos(), 'controle: os títulos do preparo');
        $descricao = (string) $r->descricao;
        $revisao = (int) $r->revisao;

        $j = $this->pedir(['revisao' => $revisao, 'refazer' => ['titulo']])->assertStatus(202)->json();

        $this->assertSame(['ajuste' => ['situacao' => 'fila', 'partes' => ['titulo' => ['situacao' => 'fila', 'mensagem' => Ajuste::MSG_PARTE_FILA]],
            'restantes_hoje' => 2, 'mensagem' => Ajuste::MSG_FILA]], $j);
        $this->assertSame([], $this->chamadas, 'o pedido não chama a IA: quem chama é o Job');

        $this->assertSame(['feito'], $this->rodarAjustes());

        $novos = $this->titulos();
        foreach ($novos as $tipo => $titulo) {
            $this->assertNotContains($titulo, [self::TITULO_C, self::TITULO_P], "{$tipo}: o título novo repete um dos atuais");
            $this->assertNotSame('', trim((string) $titulo));
        }
        $this->assertNotSame($novos['gold_special'], $novos['gold_pro'], 'nunca dois iguais');
        $titulosNaIa = $this->chamadasDe('titulosPorTermos');
        $this->assertCount(1, $titulosNaIa);
        // O 8º argumento de `titulosPorTermos` é o "não repita" do prompt.
        $this->assertStringContainsString(self::TITULO_C, $titulosNaIa[0][7]);
        $this->assertStringContainsString(self::TITULO_P, $titulosNaIa[0][7]);
        $this->assertCount(1, $this->chamadasDe('modeloPorTermos'), 'o título vem com o Modelo');
        $this->assertSame('cadeira home office, cadeira presidente', $this->modelo(), 'o Modelo novo foi gravado');
        $this->assertSame([], $this->chamadasDe('descricao'));
        $this->assertSame($descricao, (string) $r->fresh()->descricao);

        // O agente continua dono do título (a memória acompanhou) e a revisão subiu (a conferência antiga perde a validade).
        $escrito = $r->fresh()->step_state['ia_escrito'];
        $this->assertSame($novos['gold_special'], TituloDoProduto::capitalizar($escrito['titulo_gold_special']));
        $this->assertGreaterThan($revisao, (int) $r->fresh()->revisao);
        $this->assertSame(['situacao' => 'feito', 'partes' => ['titulo' => ['situacao' => 'refeito', 'mensagem' => Ajuste::MSG_TITULO_REFEITO]],
            'restantes_hoje' => 2, 'mensagem' => Ajuste::MSG_FEITO], $this->estado()->assertOk()->json('ajuste'));
        $this->assertSame('revisao', $this->linha()['etapa'], 'segue na revisão');
    }

    public function test_refaz_a_descricao_e_os_titulos_ficam(): void
    {
        $antes = $this->titulos();
        $descricao = (string) $this->rascunho()->descricao;

        $this->pedir(['revisao' => $this->revisao(), 'refazer' => ['descricao']])->assertStatus(202);
        $this->assertSame(['feito'], $this->rodarAjustes());

        $nova = (string) $this->rascunho()->descricao;
        $this->assertNotSame($descricao, $nova);
        $this->assertStringContainsString('feita para o dia inteiro', $nova);
        $this->assertSame($nova, $this->rascunho()->step_state['ia_escrito']['descricao'], 'a memória do agente acompanhou');
        $this->assertSame($antes, $this->titulos());
        $this->assertSame([], $this->chamadasDe('titulosPorTermos'));
        $this->assertSame([], $this->chamadasDe('modeloPorTermos'));
        $this->assertCount(1, $this->chamadasDe('descricao'));
        $this->assertSame(['descricao' => ['situacao' => 'refeito', 'mensagem' => Ajuste::MSG_DESCRICAO_REFEITA]], $this->estado()->json('ajuste.partes'));

        // O mesmo Job entregue de novo pela fila (o `retry_after` da conexão): não gera nem grava outra vez.
        Queue::pushed(AjustarPeloRoboNaTrilhaJob::class)->first()->handle(app(Ajuste::class));
        $this->assertCount(1, $this->chamadasDe('descricao'), 'o Job repetido pagou a IA de novo');
        $this->assertSame('feito', $this->estado()->json('ajuste.situacao'));
    }

    // ═══ O que a equipe escreveu fica ═════════════════════════════════════════

    /** Título e descrição editados à mão: `mantido`, nada sobrescrito, nenhuma chamada à IA e a cota da loja intacta. */
    public function test_a_parte_editada_pela_equipe_fica_mantida_sem_chamar_a_ia_nem_gastar_a_cota(): void
    {
        $r = $this->rascunho();
        app(RascunhoRepository::class)->gravarTitulos($r, ['gold_special' => 'Cadeira Da Equipe Clássico', 'gold_pro' => 'Cadeira Da Equipe Premium']);
        $r->update(['descricao' => 'Descrição escrita pela equipe.']);
        $cota = $this->cota();

        $this->pedir(['revisao' => $this->revisao(), 'refazer' => ['titulo', 'descricao']])->assertStatus(202);
        $this->assertSame(['feito'], $this->rodarAjustes());

        $this->assertSame(['gold_special' => 'Cadeira Da Equipe Clássico', 'gold_pro' => 'Cadeira Da Equipe Premium'], $this->titulos());
        $this->assertSame('Descrição escrita pela equipe.', (string) $this->rascunho()->descricao);
        $this->assertSame([], $this->chamadas, 'a IA foi chamada para um campo da equipe');
        $this->assertSame($cota, $this->cota(), 'a cota da loja foi gasta à toa');
        $this->assertSame([
            'titulo'    => ['situacao' => 'mantido', 'mensagem' => Ajuste::MSG_TITULO_MANTIDO],
            'descricao' => ['situacao' => 'mantido', 'mensagem' => Ajuste::MSG_DESCRICAO_MANTIDA],
        ], $this->estado()->json('ajuste.partes'));
        $this->assertSame('feito', $this->estado()->json('ajuste.situacao'));
    }

    /** Só o título do Clássico é da equipe: o do Premium é refeito, o do Clássico fica — e a mensagem diz qual. */
    public function test_com_um_tipo_editado_pela_equipe_so_o_outro_e_refeito(): void
    {
        app(RascunhoRepository::class)->gravarTitulos($this->rascunho(), ['gold_special' => 'Cadeira Da Equipe Clássico']);

        $this->pedir(['revisao' => $this->revisao(), 'refazer' => ['titulo']])->assertStatus(202);
        $this->rodarAjustes();

        $titulos = $this->titulos();
        $this->assertSame('Cadeira Da Equipe Clássico', $titulos['gold_special']);
        $this->assertNotContains($titulos['gold_pro'], [self::TITULO_P, 'Cadeira Da Equipe Clássico']);
        $this->assertSame(['situacao' => 'refeito', 'mensagem' => 'Título do Premium refeito; o do Clássico é da equipe e ficou.'],
            $this->estado()->json('ajuste.partes.titulo'));
    }

    /**
     * A equipe edita a descrição ENQUANTO a IA gera: quem decide a escrita é o `executarEtapa` do Publicador, sob a trava
     * do rascunho — o texto da equipe fica e a parte sai `mantido`.
     */
    public function test_a_equipe_que_edita_durante_a_geracao_vence(): void
    {
        $id = (int) $this->rascunho()->id;
        $this->durante = fn () => DB::table('pub_rascunhos')->where('id', $id)->update(['descricao' => 'A equipe escreveu enquanto o agente pensava.']);

        $this->pedir(['revisao' => $this->revisao(), 'refazer' => ['descricao']])->assertStatus(202);
        $this->rodarAjustes();

        $this->assertCount(1, $this->chamadasDe('descricao'), 'controle: a IA gerou');
        $this->assertSame('A equipe escreveu enquanto o agente pensava.', (string) $this->rascunho()->descricao);
        $this->assertSame(['situacao' => 'mantido', 'mensagem' => Ajuste::MSG_DESCRICAO_MANTIDA], $this->estado()->json('ajuste.partes.descricao'));
    }

    // ═══ Adiado: editor em uso ════════════════════════════════════════════════

    public function test_editor_em_uso_adia_a_escrita_sem_perder_o_pedido_nem_gerar_de_novo(): void
    {
        $descricao = (string) $this->rascunho()->descricao;
        $this->pedir(['revisao' => $this->revisao(), 'refazer' => ['descricao']])->assertStatus(202);
        EditorEmUso::marcarEdicao((int) $this->pub->id);

        $this->assertSame(['fazendo'], $this->rodarAjustes());

        $this->assertSame($descricao, (string) $this->rascunho()->descricao, 'gravou por trás de quem edita');
        $this->assertSame(['situacao' => 'fazendo', 'partes' => ['descricao' => ['situacao' => 'fila', 'mensagem' => Ajuste::MSG_ADIADO]],
            'restantes_hoje' => 2, 'mensagem' => Ajuste::MSG_FAZENDO], $this->estado()->json('ajuste'));
        $this->assertSame(['revisao', SituacaoDasOfertas::REFAZENDO.' · descrição'], [$this->linha()['etapa'], $this->linha()['status']], 'D60 enquanto espera');
        // O valor gerado foi junto no Job adiado do Publicador: o pedido não se perde e a IA não é chamada de novo.
        $adiado = collect(Queue::pushed(GerarPreparoIaJob::class))->last();
        $this->assertSame('descricao', $adiado->etapa);
        $this->assertStringContainsString('feita para o dia inteiro', (string) $adiado->valorPronto);
        // O acompanhamento (fila da IA, zero IA) segue esperando enquanto o editor está em uso.
        $acompanhamento = collect(Queue::pushed(AjustarPeloRoboNaTrilhaJob::class))->last();
        $this->assertSame([AjustarPeloRoboNaTrilhaJob::ACOMPANHAR, PreparoIaAgenda::fila()], [$acompanhamento->modo, $acompanhamento->queue]);
        $this->assertSame(['fazendo'], $this->rodarAjustes());

        // O editor fecha: o Job adiado do Publicador grava o MESMO texto, e o acompanhamento fecha o ajuste.
        Carbon::setTestNow(now()->addMinutes(6));
        $this->assertContains('escrito', $this->rodar(GerarPreparoIaJob::class));
        $this->assertStringContainsString('feita para o dia inteiro', (string) $this->rascunho()->descricao);
        $this->assertSame(['feito'], $this->rodarAjustes());
        $this->assertCount(1, $this->chamadasDe('descricao'), 'gerou de novo');
        $this->assertSame(['descricao' => ['situacao' => 'refeito', 'mensagem' => Ajuste::MSG_DESCRICAO_REFEITA]], $this->estado()->json('ajuste.partes'));
        $this->assertNotSame(SituacaoDasOfertas::REFAZENDO.' · descrição', $this->linha()['status']);
    }

    // ═══ 409 ═════════════════════════════════════════════════════════════════

    public function test_na_fila_publicando_publicado_conferindo_ou_fora_da_revisao_e_409_sem_travado(): void
    {
        $r = $this->rascunho();
        $revisao = $this->revisao();
        $pedir = fn (?string $item = null, ?int $rev = null) => $this->pedir(['revisao' => $rev ?? $revisao, 'refazer' => ['titulo']], $item);

        // Na fila de publicação: o estado vem antes da revisão — com a revisão velha, a mensagem é a da fila.
        $this->agendarNaFila($this->pub);
        $this->assertSame(['message' => Ajuste::MSG_NA_FILA_409], $pedir()->assertStatus(409)->json());
        $this->assertSame(['message' => Ajuste::MSG_NA_FILA_409], $pedir(null, $revisao - 1)->assertStatus(409)->json());
        DB::table('pub_fila_publicacao_itens')->delete();

        foreach ([PubRascunho::PUBLISHING => Ajuste::MSG_PUBLICANDO_409, PubRascunho::PUBLISHED => Ajuste::MSG_PUBLICADO_409,
            PubRascunho::PARTIALLY_PUBLISHED => Ajuste::MSG_PUBLICADO_409] as $status => $mensagem) {
            DB::table('pub_rascunhos')->where('id', $r->id)->update(['status' => $status]);
            $this->assertSame(['message' => $mensagem], $pedir()->assertStatus(409)->json(), $status);
        }
        DB::table('pub_rascunhos')->where('id', $r->id)->update(['status' => PubRascunho::DRAFT]);

        // O "Aprovar e publicar" (4d) conferindo agora: o agente gravaria depois da conferência.
        Cache::put(PublicacaoNaTrilha::chave((int) $this->pub->id), ['situacao' => 'conferindo', 'mensagem' => '', 'avisos' => [], 'mlbs' => [],
            'em' => now()->toIso8601String()], 3600);
        $this->assertSame(['message' => Ajuste::MSG_CONFERINDO_409], $pedir()->assertStatus(409)->json());
        Cache::forget(PublicacaoNaTrilha::chave((int) $this->pub->id));

        // Fora da revisão: o produto sem anúncio ainda, e o que não andou e espera a onda (a etapa da TELA).
        $semPub = $this->produtoCompleto($this->empresa, 'Vaso Novo', 'VN');
        $naoAndou = $this->produtoCompleto($this->empresa, 'Vaso Parado', 'VP');
        $this->rascunhoDoPub($this->grupoNoPublicador($this->empresa, $naoAndou), PubRascunho::DRAFT, ['resumo' => [
            'revisao' => 1, 'validado' => true, 'bloqueios' => 0, 'versao' => EditorRascunhoService::VERSAO_DO_RESUMO,
        ]]);
        $this->itemNaOnda($this->onda($this->empresa, '2026-11-20', 'Onda 20'), "s-{$naoAndou['produto']->id}");
        foreach (["s-{$semPub['produto']->id}", "s-{$naoAndou['produto']->id}"] as $fora) {
            $this->assertSame(['message' => Ajuste::MSG_FORA_DA_REVISAO], $pedir($fora, 1)->assertStatus(409)->json(), $fora);
        }

        $this->assertNadaPedido();
        // E, saindo de tudo isso, o mesmo pedido passa.
        $pedir()->assertStatus(202);
    }

    public function test_revisao_velha_e_409_sem_travado_antes_do_422_e_sem_revisao_e_422(): void
    {
        $atual = $this->revisao();

        foreach ([['titulo'], [], ['imagens']] as $refazer) {
            $resposta = $this->pedir(['revisao' => $atual - 1, 'refazer' => $refazer])->assertStatus(409);
            $this->assertSame(['revisao_atual' => $atual, 'message' => \App\Services\Trilha\PrecoNaTrilha::MSG_REVISAO], $resposta->json(), 'sem `travado`');
        }
        $this->pedir(['revisao' => (string) ($atual + 2), 'refazer' => ['titulo']])->assertStatus(409)->assertJsonMissingPath('travado');
        $this->assertErro($this->pedir(['refazer' => ['titulo']]), 'revisao', \App\Services\Trilha\PrecoNaTrilha::MSG_RECARREGUE);
        $this->assertErro($this->pedir(['revisao' => 'abc', 'refazer' => ['titulo']]), 'revisao', \App\Services\Trilha\PrecoNaTrilha::MSG_RECARREGUE);

        $this->assertNadaPedido();
    }

    /** Dois cliques: o 2º pedido com o 1º andando é 409 (sem Job nem histórico a mais) — e a 4d espera o ajuste terminar. */
    public function test_ajuste_em_andamento_e_409_e_o_publicar_espera_terminar(): void
    {
        $this->pedir(['revisao' => $this->revisao(), 'refazer' => ['titulo']])->assertStatus(202);

        $this->assertSame(['message' => Ajuste::MSG_EM_ANDAMENTO_409],
            $this->pedir(['revisao' => $this->revisao(), 'refazer' => ['descricao']])->assertStatus(409)->json());
        Queue::assertPushed(AjustarPeloRoboNaTrilhaJob::class, 1);
        $this->assertSame(1, $this->historico()->count());
        // O "Aprovar e publicar" (4d) enquanto o agente refaz: a conferência validaria o texto velho.
        $this->assertSame(['message' => Ajuste::MSG_PUBLICAR_COM_AJUSTE],
            $this->publicar(['revisao' => $this->revisao(), 'ciente' => false])->assertStatus(409)->json());
        Queue::assertNotPushed(PublicarPelaTrilhaJob::class);

        $this->rodarAjustes();
        // Terminou: publicar segue a regra da 4d (conferindo) — e aí é o ajuste que espera a conferência.
        $this->assertSame('conferindo', $this->publicar(['revisao' => $this->revisao(), 'ciente' => false])->assertStatus(202)->json('publicacao.situacao'));
        $this->assertSame(['message' => Ajuste::MSG_CONFERINDO_409],
            $this->pedir(['revisao' => $this->revisao(), 'refazer' => ['descricao']])->assertStatus(409)->json());
    }

    /** O kit da fase N (o Combo N, cópia do rascunho da Simples) tem texto de fluxo próprio: o agente não o refaz. */
    public function test_o_kit_da_fase_n_nao_tem_ajuste(): void
    {
        config(['trilha.empresas' => [], 'trilha.trabalho.empresas' => []]);
        $this->ofertaComposta($this->empresa, EstruturaOferta::FASE_COMBO, 'CAD-AZ-CB2', 'Combo 2 Cadeira', [[EstruturaOferta::query()->where('sku', 'CAD-AZ')->firstOrFail(), 2]]);
        $kitN = app(CriarFaseService::class)->criar($this->pub->fresh(), ['quantidade' => 2]);
        $this->ligarATrilha();
        $chave = "c-{$this->d->id}-2";
        $doKit = PubRascunho::query()->where('produto_id', $kitN->id)->firstOrFail();
        $this->assertSame('revisao', TrilhaDaEmpresa::itens($this->empresa, comoNaTela: true)[$chave]['etapa'], 'controle: o Combo 2 na revisão');

        $this->assertSame(['message' => Ajuste::MSG_SEM_ROBO], $this->pedir(['revisao' => (int) $doKit->revisao, 'refazer' => ['titulo']], $chave)->assertStatus(409)->json());
        $previa = $this->previa($chave)->assertOk()->json();
        $this->assertSame([null, 0], [$previa['ajuste'], $previa['ajustes_restantes_hoje']], 'sem saldo: a tela não oferece o botão');
        $this->assertSame(3, $this->previa()->json('ajustes_restantes_hoje'), 'a Simples tem');
        $this->assertNadaPedido();
    }

    // ═══ 422 ═════════════════════════════════════════════════════════════════

    public function test_refazer_vazio_fora_da_forma_ou_com_parte_desconhecida_e_422(): void
    {
        $revisao = $this->revisao();
        foreach ([null, [], 'titulo', ['imagens'], ['titulo', 'fotos'], [['titulo']], ['titulo' => true], ['Título']] as $refazer) {
            $corpo = ['revisao' => $revisao] + ($refazer === null ? [] : ['refazer' => $refazer]);
            $this->assertErro($this->pedir($corpo), 'refazer', Ajuste::MSG_REFAZER);
        }
        $this->assertNadaPedido();

        // A parte repetida conta uma vez.
        $this->assertSame(['titulo'], array_keys($this->pedir(['revisao' => $revisao, 'refazer' => ['titulo', 'titulo']])->assertStatus(202)->json('ajuste.partes')));
    }

    /** 3 pedidos por anúncio por dia; o 4º é 422 (nada vai à fila); no dia seguinte (São Paulo) libera de novo. */
    public function test_o_quarto_pedido_do_dia_e_422_e_no_dia_seguinte_libera(): void
    {
        $this->assertSame(3, $this->previa()->json('ajustes_restantes_hoje'));
        foreach ([2, 1, 0] as $restam) {
            $this->assertSame($restam, $this->pedir(['revisao' => $this->revisao(), 'refazer' => ['descricao']])->assertStatus(202)->json('ajuste.restantes_hoje'));
            $this->rodarAjustes();
            $this->assertSame($restam, $this->previa()->json('ajustes_restantes_hoje'));
        }

        $this->assertErro($this->pedir(['revisao' => $this->revisao(), 'refazer' => ['titulo']]), 'refazer', Ajuste::MSG_LIMITE);
        Queue::assertPushed(AjustarPeloRoboNaTrilhaJob::class, 3);
        $this->assertSame(3, $this->historico()->count());

        // Meia-noite e um minuto em São Paulo: outro dia.
        Carbon::setTestNow(Carbon::parse('2026-11-06 00:01:00', TrilhaOnda::FUSO));
        $this->assertSame(3, $this->previa()->json('ajustes_restantes_hoje'));
        $this->assertSame(2, $this->pedir(['revisao' => $this->revisao(), 'refazer' => ['titulo']])->assertStatus(202)->json('ajuste.restantes_hoje'));
    }

    // ═══ O custo ══════════════════════════════════════════════════════════════

    /**
     * A cota diária da LOJA é o MESMO contador do preparo: o preparo do `setUp` já gastou 1 hoje; com o teto em 1, o
     * ajuste sai `erro` sem chamar a IA e sem mexer no texto. O preparo desligado também não gasta.
     */
    public function test_cota_da_loja_esgotada_e_erro_sem_chamar_a_ia(): void
    {
        config(['publicador.preparo_ia.limite_diario_por_empresa' => 1]);
        $titulos = $this->titulos();
        $descricao = (string) $this->rascunho()->descricao;

        $this->pedir(['revisao' => $this->revisao(), 'refazer' => ['titulo', 'descricao']])->assertStatus(202);
        $this->assertSame(['erro'], $this->rodarAjustes());

        $this->assertSame([], $this->chamadas, 'chamou a IA sem cota');
        $this->assertSame([$titulos, $descricao], [$this->titulos(), (string) $this->rascunho()->descricao]);
        $this->assertSame(['situacao' => 'erro', 'partes' => [
            'titulo'    => ['situacao' => 'erro', 'mensagem' => Ajuste::MSG_COTA],
            'descricao' => ['situacao' => 'erro', 'mensagem' => Ajuste::MSG_COTA],
        ], 'restantes_hoje' => 2, 'mensagem' => Ajuste::MSG_COTA], $this->estado()->json('ajuste'));
        $this->assertNotSame(SituacaoDasOfertas::REFAZENDO, substr($this->linha()['status'], 0, strlen(SituacaoDasOfertas::REFAZENDO)), 'D60 some no erro');

        // O preparo desligado (a chave de segurança do Publicador): erro também, sem IA.
        config(['publicador.preparo_ia.limite_diario_por_empresa' => 60, 'publicador.preparo_ia.ativo' => false]);
        $this->pedir(['revisao' => $this->revisao(), 'refazer' => ['descricao']])->assertStatus(202);
        $this->assertSame(['erro'], $this->rodarAjustes());
        $this->assertSame(Ajuste::MSG_DESLIGADO, $this->estado()->json('ajuste.partes.descricao.mensagem'));
        $this->assertSame([], $this->chamadas);
    }

    /** O anúncio que entrou na fila de publicação entre o pedido e o Job: erro, sem gastar a IA nem a cota. */
    public function test_o_anuncio_que_entrou_na_fila_depois_do_pedido_nao_gasta(): void
    {
        $this->pedir(['revisao' => $this->revisao(), 'refazer' => ['titulo', 'descricao']])->assertStatus(202);
        $this->agendarNaFila($this->pub);
        $cota = $this->cota();

        $this->assertSame(['erro'], $this->rodarAjustes());

        $this->assertSame([], $this->chamadas);
        $this->assertSame($cota, $this->cota());
        $this->assertSame(Ajuste::MSG_NA_FILA_DEPOIS, $this->estado()->json('ajuste.mensagem'));
    }

    public function test_o_job_vai_para_a_fila_da_ia_e_nunca_para_a_high(): void
    {
        $this->assertSame('publicador-ia', PreparoIaAgenda::fila(), 'controle');

        $this->pedir(['revisao' => $this->revisao(), 'refazer' => ['titulo', 'descricao']])->assertStatus(202);

        Queue::assertPushedOn(PreparoIaAgenda::fila(), AjustarPeloRoboNaTrilhaJob::class);
        $job = Queue::pushed(AjustarPeloRoboNaTrilhaJob::class)->first();
        $this->assertSame('publicador-ia', $job->queue);
        $this->assertSame([(int) $this->empresa->id, $this->item(), (int) $this->pub->id, ['titulo', 'descricao'], AjustarPeloRoboNaTrilhaJob::REFAZER],
            [$job->companyId, $job->item, $job->pubProdutoId, $job->partes, $job->modo]);
        $this->assertSame(0, collect(Queue::pushedJobs())->flatten(1)->filter(fn ($j) => ($j->queue ?? null) === 'high')->count(), 'algo foi para a `high`');
        $this->assertSame([], $this->chamadas);
    }

    /**
     * Texto livre no corpo (chaves a mais e texto dentro de `refazer`): ignorado. A marca não aparece no Job, no estado,
     * no histórico nem em NENHUM argumento de NENHUMA chamada à IA — e a IA foi chamada (controle).
     */
    public function test_texto_livre_no_corpo_e_ignorado_e_nao_chega_a_prompt_nenhum(): void
    {
        $corpo = ['revisao' => $this->revisao(), 'refazer' => ['titulo', 'descricao'], 'pedido' => 'Escreva '.self::MARCA,
            'texto' => self::MARCA, 'prompt' => self::MARCA, 'instrucoes' => ['titulo' => self::MARCA], 'evitar' => [self::MARCA],
            'partes' => [self::MARCA], 'descricao' => self::MARCA, 'titulo' => self::MARCA];

        $this->pedir($corpo)->assertStatus(202);
        $this->assertStringNotContainsString(self::MARCA, serialize(Queue::pushed(AjustarPeloRoboNaTrilhaJob::class)->first()));
        $this->assertStringNotContainsString(self::MARCA, (string) json_encode(Cache::get(Ajuste::chave((int) $this->pub->id))));
        $this->rodarAjustes();

        $this->assertNotEmpty($this->chamadasDe('titulosPorTermos'), 'controle: a IA do título foi chamada');
        $this->assertNotEmpty($this->chamadasDe('descricao'), 'controle: a IA da descrição foi chamada');
        $this->assertStringNotContainsString(self::MARCA, (string) json_encode($this->chamadas), 'o texto livre chegou à IA');
        $this->assertStringNotContainsString(self::MARCA, Activity::query()->get()->toJson());
        $this->assertStringNotContainsString(self::MARCA, json_encode($this->titulos()).$this->rascunho()->descricao);

        // Texto dentro de `refazer` não é parte: 422.
        $this->assertErro($this->pedir(['revisao' => $this->revisao(), 'refazer' => ['titulo '.self::MARCA]]), 'refazer', Ajuste::MSG_REFAZER);
    }

    // ═══ D60, a prévia e o histórico ═════════════════════════════════════════

    /** "Agente refazendo · …" na linha da Lista (a página da equipe) enquanto anda; depois, a régua de sempre. */
    public function test_d60_a_linha_fica_na_revisao_com_robo_refazendo_e_volta_ao_fim(): void
    {
        $antes = $this->linha();
        $this->assertSame(['revisao', 'Pré-pronto · a conferir'], [$antes['etapa'], $antes['status']], 'controle');

        $this->pedir(['revisao' => $this->revisao(), 'refazer' => ['titulo', 'descricao']])->assertStatus(202);

        $naLista = $this->itemDaTrilha($this->paginaDaEquipe(), $this->item());
        $this->assertSame(['revisao', 'Agente refazendo · título, descrição', false], [$naLista['etapa'], $naLista['status'], $naLista['alerta']]);
        $this->assertSame('Agente refazendo · título, descrição', $this->previa()->json('item.status'), 'a prévia lê a mesma régua');

        $this->rodarAjustes();
        $depois = $this->itemDaTrilha($this->paginaDaEquipe(), $this->item());
        $this->assertSame(['revisao', 'Pré-pronto · a conferir'], [$depois['etapa'], $depois['status']], 'a régua de sempre');

        // O ajuste que ficou parado (o Job morreu) não segura a linha nem o próximo pedido.
        $this->pedir(['revisao' => $this->revisao(), 'refazer' => ['descricao']])->assertStatus(202);
        $this->assertSame('Agente refazendo · descrição', $this->linha()['status']);
        Carbon::setTestNow(now()->addMinutes(Ajuste::ANDAMENTO_VALE_MIN + 1));
        $this->assertSame('Pré-pronto · a conferir', $this->linha()['status']);
        $this->assertSame(['situacao' => 'erro', 'mensagem' => Ajuste::MSG_NAO_TERMINOU], array_intersect_key($this->estado()->json('ajuste'), ['situacao' => 1, 'mensagem' => 1]));
        $this->assertSame(['situacao' => 'erro', 'mensagem' => Ajuste::MSG_NAO_TERMINOU], $this->estado()->json('ajuste.partes.descricao'));
        $this->pedir(['revisao' => $this->revisao(), 'refazer' => ['descricao']])->assertStatus(202);
    }

    /** O `ajuste` da prévia é o MESMO do GET, antes, durante e depois; `ajustes_restantes_hoje` = o `restantes_hoje`. */
    public function test_o_ajuste_da_previa_e_o_mesmo_do_get(): void
    {
        $p = $this->previa()->assertOk()->json();
        $this->assertSame([null, 3], [$p['ajuste'], $p['ajustes_restantes_hoje']]);
        $this->assertSame(['ajuste' => null], $this->estado()->assertOk()->json());

        $this->pedir(['revisao' => $this->revisao(), 'refazer' => ['titulo', 'descricao']])->assertStatus(202);
        foreach (['fila', 'feito'] as $situacao) {
            $p = $this->previa()->assertOk()->json();
            $this->assertSame(self::CHAVES, array_keys($p['ajuste']));
            $this->assertSame($situacao, $p['ajuste']['situacao']);
            $this->assertSame($p['ajuste'], $this->estado()->json('ajuste'));
            $this->assertSame([2, 2], [$p['ajustes_restantes_hoje'], $p['ajuste']['restantes_hoje']]);
            $this->rodarAjustes();
        }
    }

    public function test_o_historico_registra_quem_pediu_e_as_partes(): void
    {
        $r = $this->rascunho();
        $revisao = (int) $r->revisao;
        $this->pedir(['revisao' => $revisao, 'refazer' => ['descricao', 'titulo']])->assertStatus(202);

        $a = $this->historico()->sole();
        $this->assertSame([$this->dev->id, User::class], [(int) $a->causer_id, $a->causer_type], 'o ator da equipe');
        $this->assertSame([PubRascunho::class, (int) $r->id], [$a->subject_type, (int) $a->subject_id]);
        $this->assertSame(['portal', 'interno', (int) $this->empresa->id, $this->item(), (int) $this->pub->id, $revisao, ['titulo', 'descricao']],
            [$a->log_name, $a->getExtraProperty('origem'), (int) $a->getExtraProperty('company_id'), $a->getExtraProperty('item'),
                (int) $a->getExtraProperty('pub_produto_id'), (int) $a->getExtraProperty('revisao'), $a->getExtraProperty('partes')]);
        $this->assertSame('Ajuste pedido ao agente em "Cadeira Executiva": título e descrição', $a->description);
        $this->assertSame(Queue::pushed(AjustarPeloRoboNaTrilhaJob::class)->first()->pedido, $a->getExtraProperty('pedido'));
    }

    /** O Job que quebra (ou estoura o tempo) não deixa a tela em "refazendo": erro, e o pedido seguinte passa. */
    public function test_o_job_que_quebra_vira_erro_e_libera(): void
    {
        $this->pedir(['revisao' => $this->revisao(), 'refazer' => ['titulo', 'descricao']])->assertStatus(202);

        Queue::pushed(AjustarPeloRoboNaTrilhaJob::class)->first()->failed(new \RuntimeException('o worker caiu'));

        $this->assertSame(['situacao' => 'erro', 'partes' => [
            'titulo'    => ['situacao' => 'erro', 'mensagem' => Ajuste::MSG_NAO_TERMINOU],
            'descricao' => ['situacao' => 'erro', 'mensagem' => Ajuste::MSG_NAO_TERMINOU],
        ], 'restantes_hoje' => 2, 'mensagem' => Ajuste::MSG_NAO_TERMINOU], $this->estado()->json('ajuste'));
        $this->pedir(['revisao' => $this->revisao(), 'refazer' => ['titulo']])->assertStatus(202);
    }

    // ═══ O endereço ═══════════════════════════════════════════════════════════

    public function test_o_endereco_da_escrita_leva_ao_ajuste_e_o_de_outra_loja_ou_que_nao_existe_e_404_antes_de_validar(): void
    {
        $props = $this->paginaDaEquipe();
        $this->assertSame("/trilha/{$this->empresa->id}/ofertas/__ITEM__/ajuste", $props['escrita']['revisao']['ajuste']);
        $url = str_replace('__ITEM__', $this->item(), $props['escrita']['revisao']['ajuste']);
        $this->actingAs($this->dev)->postJson($url, ['revisao' => $this->revisao(), 'refazer' => ['titulo']])->assertStatus(202);
        $this->assertSame('fila', $this->actingAs($this->dev)->getJson($url)->assertOk()->json('ajuste.situacao'));

        // De outra loja (pelo endereço desta) e o que não existe: 404, com o corpo inválido de propósito.
        $outra = $this->empresaDaTrilha();
        $deFora = $this->produtoCompleto($outra, 'Vaso de Fora', 'VF');
        $this->rascunhoDoPub($this->grupoNoPublicador($outra, $deFora));
        foreach (["s-{$deFora['produto']->id}", 's-999999', "c-{$this->d->id}-3", 'o-999999'] as $chave) {
            $this->pedir(['refazer' => 'x'], $chave)->assertNotFound();
            $this->estado($chave)->assertNotFound();
        }
        $this->actingAs($this->dev)->postJson("/trilha/{$outra->id}/ofertas/{$this->item()}/ajuste", ['refazer' => 'x'])->assertNotFound();
        Queue::assertPushed(AjustarPeloRoboNaTrilhaJob::class, 1);
    }

    // ─── apoio ───

    /**
     * A IA falsa que ANOTA cada chamada. Os títulos e o Modelo de sempre (os do preparo — o servidor é que precisa fazer
     * os títulos diferentes); a descrição, uma nova.
     */
    private function iaQueAnota(): void
    {
        $this->chamadas = [];
        $this->mock(AnaliseAnuncioService::class, function (MockInterface $m) {
            $m->shouldReceive('comPrazo')->andReturnSelf();
            $m->shouldReceive('titulosPorTermos')->andReturnUsing(function (...$a) {
                $this->chamadas[] = ['titulosPorTermos', $a];

                return ['dados' => ['classico' => self::TITULO_C, 'premium' => self::TITULO_P], 'meta' => []];
            });
            $m->shouldReceive('modeloPorTermos')->andReturnUsing(function (...$a) {
                $this->chamadas[] = ['modeloPorTermos', $a];

                return ['dados' => 'cadeira home office, cadeira presidente', 'meta' => []];
            });
            $m->shouldReceive('analise')->andReturnUsing(function (...$a) {
                $this->chamadas[] = ['analise', $a];

                return ['dados' => ['jtbd' => 'trabalhar sentado', 'puv' => 'conforto'], 'meta' => []];
            });
            $m->shouldReceive('descricao')->andReturnUsing(function (...$a) {
                $this->chamadas[] = ['descricao', $a];
                if ($this->durante !== null) {
                    ($this->durante)();
                }

                return ['dados' => '<p>Cadeira nova, feita para o dia inteiro.</p>', 'meta' => []];
            });
        });
    }

    /** @return list<array> os argumentos das chamadas de um método da IA falsa */
    private function chamadasDe(string $metodo): array
    {
        return array_values(array_map(fn ($c) => $c[1], array_filter($this->chamadas, fn ($c) => $c[0] === $metodo)));
    }

    /**
     * Roda (pelo `handle` do Job) os ajustes que a fila de mentira recebeu e ainda não rodaram, na ordem; devolve a
     * situação do ajuste depois de cada um.
     *
     * @return list<string>
     */
    private function rodarAjustes(): array
    {
        $saida = [];
        foreach (Queue::pushed(AjustarPeloRoboNaTrilhaJob::class) as $job) {
            if ($this->ajustesRodados->contains($job)) {
                continue;
            }
            $this->ajustesRodados->attach($job);
            $job->handle(app(Ajuste::class));
            $saida[] = (string) (Cache::get(Ajuste::chave($job->pubProdutoId))['situacao'] ?? 'sem estado');
        }

        return $saida;
    }

    private function ligarATrilha(): void
    {
        // A marca de "agendado" do save (80 s) vence; e só agora a trilha liga (com ela o agente esperaria a onda).
        Carbon::setTestNow(now()->addMinutes(5));
        config(['trilha.empresas' => [$this->empresa->id], 'trilha.trabalho.empresas' => [$this->empresa->id]]);
    }

    private function item(): string
    {
        return "s-{$this->d->id}";
    }

    private function rascunho(): PubRascunho
    {
        return $this->rascunhoDo($this->d);
    }

    private function revisao(): int
    {
        return (int) $this->rascunho()->revisao;
    }

    private function pedir(array $corpo, ?string $chave = null): TestResponse
    {
        return $this->actingAs($this->dev)->postJson("/trilha/{$this->empresa->id}/ofertas/".($chave ?? $this->item()).'/ajuste', $corpo);
    }

    private function estado(?string $chave = null): TestResponse
    {
        return $this->actingAs($this->dev)->getJson("/trilha/{$this->empresa->id}/ofertas/".($chave ?? $this->item()).'/ajuste');
    }

    private function previa(?string $chave = null): TestResponse
    {
        return $this->actingAs($this->dev)->getJson("/trilha/{$this->empresa->id}/ofertas/".($chave ?? $this->item()).'/previa');
    }

    private function publicar(array $corpo): TestResponse
    {
        return $this->actingAs($this->dev)->postJson("/trilha/{$this->empresa->id}/ofertas/{$this->item()}/publicar", $corpo);
    }

    /** A linha do item pela régua da tela. */
    private function linha(): array
    {
        return TrilhaDaEmpresa::itens($this->empresa, comoNaTela: true)[$this->item()];
    }

    /** As props da página da equipe (`ofertas` são as linhas da Lista). */
    private function paginaDaEquipe(): array
    {
        $this->withoutVite();

        return json_decode(json_encode($this->actingAs($this->dev)->get("/trilha/{$this->empresa->id}")->assertOk()->viewData('page')), true)['props'];
    }

    /** @return array{gold_special: ?string, gold_pro: ?string} os títulos gravados no rascunho */
    private function titulos(): array
    {
        $porTipo = DB::table('pub_rascunho_alvos')->where('rascunho_id', $this->rascunho()->id)->pluck('titulo', 'listing_type_id')->all();

        return ['gold_special' => $porTipo['gold_special'] ?? null, 'gold_pro' => $porTipo['gold_pro'] ?? null];
    }

    private function modelo(): ?string
    {
        return DB::table('pub_rascunho_atributos')->where('rascunho_id', $this->rascunho()->id)->where('attribute_id', 'MODEL')->value('value_name');
    }

    /** O contador da cota diária da loja (o do preparo do Publicador). */
    private function cota(): int
    {
        return (int) Cache::get("publicador:preparo:cota:{$this->empresa->id}:".now()->format('Y-m-d'));
    }

    private function historico()
    {
        return Activity::query()->where('properties->evento', Ajuste::EVENTO);
    }

    /** Nenhum Job de ajuste, nenhum histórico, nenhum estado. */
    private function assertNadaPedido(): void
    {
        Queue::assertNotPushed(AjustarPeloRoboNaTrilhaJob::class);
        $this->assertSame(0, $this->historico()->count());
        $this->assertNull(Cache::get(Ajuste::chave((int) $this->pub->id)));
    }

    /** 422 com a mensagem no campo (`assertJsonPath` não alcança chave com ponto — learnings §31). */
    private function assertErro(TestResponse $resposta, string $campo, string $mensagem): void
    {
        $resposta->assertUnprocessable();
        $this->assertSame($mensagem, $resposta->json('errors')[$campo][0] ?? null, (string) $resposta->getContent());
    }
}
