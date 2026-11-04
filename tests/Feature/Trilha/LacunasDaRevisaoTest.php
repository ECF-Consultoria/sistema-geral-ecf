<?php

namespace Tests\Feature\Trilha;

use App\Jobs\Publicador\CriarCombosDoPlanejamentoJob;
use App\Jobs\Publicador\GerarPreparoIaJob;
use App\Jobs\Publicador\PrepararProdutoNoPublicadorJob;
use App\Models\Company;
use App\Models\EstruturaOferta;
use App\Models\EstruturaProduto;
use App\Models\MlCategoriaSchema;
use App\Models\PubProduto;
use App\Models\PubRascunho;
use App\Models\TrilhaMapeamento;
use App\Models\TrilhaOnda;
use App\Services\Publicador\CombosDoPlanejamentoService;
use App\Services\Publicador\PreparoIaDoRascunhoService;
use App\Services\Trilha\Ondas\SimplesPronta;
use App\Services\Trilha\Ondas\SoltarOndas;
use App\Services\Trilha\TrilhaDaEmpresa;
use App\Support\Publicador\EditorEmUso;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Publicador\Lote\CenarioDoPreparo;
use Tests\Feature\Trilha\Concerns\MontaTrilha;
use Tests\TestCase;

/**
 * Lacunas que a verificação por mutação achou na conferência de 02/11/2026: o código já fazia o certo, mas desfazer a
 * regra não derrubava teste nenhum da trilha. Cada teste aqui falha com a regra desfeita:
 *
 * - o preparo ADIADO pelo editor em uso (`PreparoIaDoRascunhoService::adiarPreparo`) volta à fila com os MESMOS itens
 *   da onda (`soItens`) — perdê-los faria o Job da volta preparar o produto inteiro e refazer, paga e de carona, a
 *   etapa que falhou na Simples;
 * - a Simples "Publicando agora" não está pronta para o Kit N nascer — nem na tela (o combo espera) nem no servidor
 *   (`SimplesPronta`, `CombosDoPlanejamentoService::agendar`), mesmo com o texto do agente completo;
 * - a Simples com a publicação FALHADA também não, mesmo com o texto completo (o teste da revisão usa uma falhada SEM
 *   texto, que o servidor barra pelo texto e não pelo status).
 *
 * Dois cenários: o do preparo (`CenarioDoPreparo`: a cadeira com a ficha completa, a IA como dublê, a fila rodada à
 * mão) e o da trilha montada pelos models (`MontaTrilha`).
 */
class LacunasDaRevisaoTest extends TestCase
{
    use CenarioDoPreparo;
    use MontaTrilha;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Queue::fake();
        Cache::flush();
        // O preparo ligado aqui, e não pelo `.env` (a máquina com `PUBLICADOR_PREPARO_IA_ATIVO=false` faria o teste
        // "passar" sem a IA nunca ser chamada). Sem as imagens automáticas: a Simples pronta não espera por elas.
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

    // ═══ O preparo adiado pelo editor em uso leva os mesmos itens ════════════

    public function test_o_preparo_adiado_pelo_editor_em_uso_volta_com_os_mesmos_itens_da_onda(): void
    {
        $this->comPreparo();
        $p = $this->produtoDoPortal('CAD');
        $q = $this->produtoDoPortal('CDB');
        $kit = $this->ofertaComposta($this->empresa, EstruturaOferta::FASE_KIT, 'KT-CAD-CDB', 'Kit KT-CAD-CDB',
            [[$this->simplesDe($p), 1], [$this->simplesDe($q), 1]]);
        $this->itemNaOnda($this->onda($this->empresa, '2026-11-05', 'Onda 1'), "s-{$p->id}");
        $this->itemNaOnda($this->onda($this->empresa, '2026-11-06', 'Onda Kit'), "o-{$kit->id}");

        // Dia 05: a Simples de P é preparada e o título dela FALHA — o que começou e falhou não volta sozinho.
        $this->assertSame(["s-{$p->id}"], $this->soltura()['pedidos']);
        $this->rodar(PrepararProdutoNoPublicadorJob::class);
        $this->rodar(GerarPreparoIaJob::class);
        $simples = $this->rascunhoDo($p);
        $this->falharEtapa($simples, 'titulo');
        $this->assertSame(1, $this->pedidosDaIa($simples->id, 'titulo'));

        // Dia 06: começa a onda do kit, pedido para P e Q com a IA só para ele. A publicadora está com o editor de P
        // aberto: o preparo de P é adiado — e o Job novo leva os MESMOS itens.
        $this->agora('2026-11-06 00:07:00');
        $this->assertSame(["o-{$kit->id}"], $this->soltura()['pedidos']);
        $grupo = $this->pubDo($p);
        EditorEmUso::marcarEdicao((int) $grupo->id);
        $this->assertContains(PreparoIaDoRascunhoService::ADIADO, $this->rodar(PrepararProdutoNoPublicadorJob::class));
        Queue::assertPushed(PrepararProdutoNoPublicadorJob::class, fn ($j) => $j->estruturaProdutoId === (int) $p->id
            && $j->adiamentos === 1 && $j->soItens === ["o-{$kit->id}"]);
        Queue::assertNotPushed(PrepararProdutoNoPublicadorJob::class, fn ($j) => $j->adiamentos > 0 && $j->soItens === null);

        // A publicadora fecha o editor; o Job adiado roda e prepara só o kit: o título pago da Simples não é refeito.
        Cache::forget(EditorEmUso::chaveDaEdicao((int) $grupo->id));
        $this->assertSame(['pronto'], $this->rodar(PrepararProdutoNoPublicadorJob::class));
        $this->rodar(GerarPreparoIaJob::class);
        $this->assertSame(1, $this->pedidosDaIa($simples->id, 'titulo'), 'o título da Simples foi refeito de carona');
        $this->assertSame('erro', ((array) $simples->fresh()->step_state)['ia_preparo']['etapas']['titulo']['status']);
        $this->assertGreaterThan(0, $this->pedidosDaIa($this->rascunhoDaOferta($kit)->id), 'o kit não foi preparado no dia da onda dele');
    }

    // ═══ A Simples publicando ou com a publicação falhada não é pronta ═══════

    public function test_a_simples_publicando_agora_nao_e_pronta_para_o_kit_n_na_tela_nem_no_servidor(): void
    {
        [$empresa, $s, $c, $grupo, $r] = $this->simplesComTextoCompleto();
        $this->assertProntaParaOKitN($empresa, $s, $c, $grupo, $r);

        // A publicação começou: a cópia do Kit N pegaria o rascunho no meio do envio ao Mercado Livre.
        PubRascunho::whereKey($r->id)->update(['status' => PubRascunho::PUBLISHING]);

        $this->assertNaoProntaParaOKitN($empresa, $s, $c, $grupo, $r, 'Publicando agora');
    }

    public function test_a_simples_com_a_publicacao_falhada_e_o_texto_completo_nao_e_pronta_para_o_kit_n(): void
    {
        [$empresa, $s, $c, $grupo, $r] = $this->simplesComTextoCompleto();
        $this->assertProntaParaOKitN($empresa, $s, $c, $grupo, $r);

        // A publicação falhou: o texto do agente está lá, completo — é o status que barra (uma pessoa precisa olhar).
        PubRascunho::whereKey($r->id)->update(['status' => PubRascunho::FAILED]);
        $this->assertSame('completo', app(PreparoIaDoRascunhoService::class)->situacaoDoTexto($r->fresh()));

        $this->assertNaoProntaParaOKitN($empresa, $s, $c, $grupo, $r, 'A publicação falhou');
    }

    // ─── apoio ───

    /**
     * Um produto completo e mapeado, com a Simples no Publicador em DRAFT e o texto do agente COMPLETO para os fatos de
     * agora, e o Combo 2 dele numa onda de 05/11 já solta e pedida. Hoje é 07/11.
     *
     * @return array{0: Company, 1: string, 2: string, 3: PubProduto, 4: PubRascunho}
     */
    private function simplesComTextoCompleto(): array
    {
        $this->agora('2026-11-07 10:00:00');
        $empresa = $this->empresaDaTrilha();
        $a = $this->produtoCompleto($empresa, 'Vaso Alfa', 'VA-1');
        TrilhaMapeamento::create(['company_id' => $empresa->id, 'produto_id' => $a['produto']->id, 'concluido_em' => now()]);
        $grupo = $this->grupoNoPublicador($empresa, $a);
        $r = $this->rascunhoDoPub($grupo, PubRascunho::DRAFT);
        $this->textoCompleto($r);
        $this->ofertaComposta($empresa, EstruturaOferta::FASE_COMBO, 'VA-1-CB2', 'Combo 2 Vaso Alfa', [[$a['simples'][0], 2]]);
        $c = "c-{$a['produto']->id}-2";
        $this->itemNaOnda($this->onda($empresa, '2026-11-05', 'Onda 1', '2026-11-05 00:07:00'), $c, '2026-11-05 00:07:00');

        return [$empresa, "s-{$a['produto']->id}", $c, $grupo, $r];
    }

    /** O controle: em DRAFT, com o texto completo, a Simples está pronta — na tela e no servidor (o Kit N é agendado). */
    private function assertProntaParaOKitN(Company $empresa, string $s, string $c, PubProduto $grupo, PubRascunho $r): void
    {
        $trilha = app(TrilhaDaEmpresa::class)->montar($empresa->fresh());
        $simples = $this->itemDaTrilha($trilha, $s);
        $combo = $this->itemDaTrilha($trilha, $c);
        $this->assertSame(['revisao', 'Pré-pronto · a conferir'], [$simples['etapa'], $simples['status']], 'controle: a Simples na tela');
        $this->assertSame(['robo', 'Ainda não começou (desde 05/11)', true], [$combo['etapa'], $combo['status'], $combo['alerta']],
            'controle: o combo já não espera a Simples, espera o Kit N');
        $this->assertTrue(SimplesPronta::para($r->fresh()), 'controle: DRAFT com o texto completo está pronta');
        $this->assertTrue(app(CombosDoPlanejamentoService::class)->agendar((int) $grupo->id), 'controle: o Kit N é agendado');
        Queue::assertPushed(CriarCombosDoPlanejamentoJob::class, 1);
    }

    /** A Simples no status da publicação: o combo volta a esperar por ela, e o Kit N não é agendado de novo. */
    private function assertNaoProntaParaOKitN(Company $empresa, string $s, string $c, PubProduto $grupo, PubRascunho $r, string $status): void
    {
        $trilha = app(TrilhaDaEmpresa::class)->montar($empresa->fresh());
        $simples = $this->itemDaTrilha($trilha, $s);
        $combo = $this->itemDaTrilha($trilha, $c);
        $this->assertSame(['revisao', $status], [$simples['etapa'], $simples['status']]);
        $this->assertSame(['robo', 'Na fila · espera a oferta simples ficar pronta', false], [$combo['etapa'], $combo['status'], $combo['alerta']],
            "a tela conta a Simples \"{$status}\" como pronta");
        $this->assertFalse(SimplesPronta::para($r->fresh()), "o servidor conta a Simples \"{$status}\" como pronta");
        $this->assertFalse(app(CombosDoPlanejamentoService::class)->agendar((int) $grupo->id));
        Queue::assertPushed(CriarCombosDoPlanejamentoJob::class, 1);
    }

    /** O preparo do texto completo para os fatos de agora, como a cadeia da IA deixa. */
    private function textoCompleto(PubRascunho $r): void
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

    /** O cenário do preparo na empresa da trilha de trabalho. O relógio vem ANTES: o schema da categoria é guardado "agora". */
    private function comPreparo(string $agora = '2026-11-05 10:00:00'): void
    {
        $this->agora($agora);
        $this->montarPreparo();
        config(['trilha.empresas' => [$this->empresa->id], 'trilha.trabalho.empresas' => [$this->empresa->id]]);
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

    /** Uma passada da soltura na empresa do cenário do preparo. */
    private function soltura(): array
    {
        return app(SoltarOndas::class)->daEmpresa($this->empresa->fresh());
    }

    /** Muda o relógio (São Paulo). O schema guardado acompanha: senão ele "venceria" e o preparo iria ao ML. */
    private function agora(string $quando): void
    {
        Carbon::setTestNow(Carbon::parse($quando, TrilhaOnda::FUSO));
        MlCategoriaSchema::query()->update(['fetched_at' => now()]);
    }
}
