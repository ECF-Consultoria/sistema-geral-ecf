<?php

namespace Tests\Feature\Trilha;

use App\Jobs\Publicador\GerarPreparoIaJob;
use App\Jobs\Publicador\PrepararProdutoNoPublicadorJob;
use App\Models\EstruturaProduto;
use App\Models\MlCategoriaSchema;
use App\Models\PubAgenteExecucao;
use App\Models\PubProduto;
use App\Models\TrilhaOnda;
use App\Models\TrilhaOndaItem;
use App\Services\Publicador\Agente\ConfiguracaoDoAgenteService;
use App\Services\Publicador\Agente\VarreduraDoAgente;
use App\Services\Trilha\Ondas\SoltarOndas;
use App\Services\Trilha\TrilhaDaEmpresa;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Publicador\Agente\CenarioDoAgente;
use Tests\TestCase;

/**
 * A soltura das ondas com o agente da Aprovação ligado na conta. Nasceu da conferência de 02/11/2026, que reproduziu o
 * defeito: com o agente ligado (limite de 1 produto por dia), o preparo pedido pela soltura passa pela admissão dele,
 * que recusa por capacidade — e a repescagem, que era UMA só, era gasta pela recusa. O 3º item da onda nunca começava:
 * a varredura do agente pula a empresa da trilha de trabalho e ninguém mais o pedia.
 *
 * A correção (`SoltarOndas::agenteLigado` + `$comAgente`): com o agente ligado, a repescagem vale uma vez POR DIA dentro
 * da janela (`trilha.ondas.repescagem_dias`), só para quem segue sem texto — a recusa do agente não custa IA. Sem o
 * agente segue UMA vez, o comportamento de sempre: o teto de texto do dia já cobrou a tentativa, e quem sobra espera
 * uma pessoa (a tela avisa).
 *
 * O cenário é o do agente (`CenarioDoAgente`: a cadeira com a ficha completa no Publicador, a IA como dublê e a fila
 * `Queue::fake()` rodada à mão), com três cadeiras na Onda 1 de 05/11.
 */
class AgenteNaSolturaTest extends TestCase
{
    use CenarioDoAgente;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // O relógio vem ANTES do cenário: o schema da categoria é guardado "agora".
        Carbon::setTestNow(Carbon::parse('2026-11-05 10:00:00', TrilhaOnda::FUSO));
        $this->montarAgente();
        config(['publicador.preparo_ia.ativo' => true, 'publicador.criativos_auto.ativo' => false]);
        config(['trilha.empresas' => [$this->empresa->id], 'trilha.trabalho.empresas' => [$this->empresa->id]]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_com_o_agente_ligado_a_repescagem_vale_uma_vez_por_dia_ate_o_ultimo_item_comecar(): void
    {
        $this->ligar(['limite_diario' => 1]);
        [$a, $b, $c] = $produtos = $this->tresNaOnda();
        [$sa, $sb, $sc] = ["s-{$a->id}", "s-{$b->id}", "s-{$c->id}"];

        // Dia 05: a soltura pede os três; o agente admite UM (limite 1/dia) e recusa os outros por capacidade — a
        // recusa não chama a IA.
        $dia = $this->soltura();
        $this->assertEqualsCanonicalizing([$sa, $sb, $sc], $dia['pedidos']);
        $this->assertSame(['pronto', 'pronto', 'pronto'], $this->rodar(PrepararProdutoNoPublicadorJob::class));
        $this->assertSame([$a->id], $this->admitidos());
        $this->assertSame([$sa => false, $sb => true, $sc => true], $this->semTexto($produtos));
        $this->assertCount(2, $this->cabecasDasCadeias(), 'só o admitido abre as cadeias pagas do texto');

        // Dia 06: os dois que seguem sem texto são repescados; o agente admite mais um.
        $this->agora('2026-11-06 00:07:00');
        $dia = $this->soltura();
        $this->assertSame([], $dia['pedidos']);
        $this->assertEqualsCanonicalizing([$sb, $sc], $dia['repescados']);
        $this->rodar(PrepararProdutoNoPublicadorJob::class);
        $this->assertSame([$a->id, $b->id], $this->admitidos());
        $this->assertSame([$sa => false, $sb => false, $sc => true], $this->semTexto($produtos));

        // Uma vez POR DIA: a passada seguinte do mesmo dia não pede de novo.
        $this->agora('2026-11-06 00:22:00');
        $this->assertSame([[], []], $this->pedidosERepescados($this->soltura()));

        // Dia 07: o último, recusado ontem, é repescado de novo — e desta vez admitido. Antes da correção a repescagem
        // única tinha sido gasta no dia 06 e ele nunca começava.
        $this->agora('2026-11-07 00:07:00');
        $this->assertSame([[], [$sc]], $this->pedidosERepescados($this->soltura()));
        $this->rodar(PrepararProdutoNoPublicadorJob::class);
        $this->assertSame([$a->id, $b->id, $c->id], $this->admitidos());
        $this->assertSame([$sa => false, $sb => false, $sc => false], $this->semTexto($produtos));
        $this->assertSame([PubAgenteExecucao::PREPARANDO], PubAgenteExecucao::query()->distinct()->pluck('estado')->all());

        // Depois disso ninguém é pedido de novo: todos têm texto. A varredura do agente segue pulando a empresa da
        // trilha (é por isso que a soltura repesca por ele).
        foreach (['2026-11-08 00:07:00', '2026-11-09 00:07:00', '2026-11-12 10:07:00'] as $quando) {
            $this->agora($quando);
            $this->assertSame([[], []], $this->pedidosERepescados($this->soltura()), $quando);
            $this->assertSame(0, app(VarreduraDoAgente::class)->rodar()['lojas'], $quando);
            $this->rodar(PrepararProdutoNoPublicadorJob::class);
        }
        $this->assertSame([$a->id, $b->id, $c->id], $this->admitidos());
        Queue::assertPushed(PrepararProdutoNoPublicadorJob::class, 6);
        $this->assertCount(6, $this->cabecasDasCadeias(), 'duas cadeias por produto admitido, nenhuma a mais');
    }

    public function test_sem_o_agente_a_repescagem_continua_sendo_uma_so(): void
    {
        // A conta tem a configuração do agente, DESLIGADA (como todas as contas em 02/11/2026). O teto de texto da
        // empresa (1 por dia) faz o papel do limite do agente: o mesmo 1-por-dia, mas pago na tentativa.
        app(ConfiguracaoDoAgenteService::class)->salvar($this->conta(), ['habilitado' => false, 'modalidade' => 'gold_special', 'limite_diario' => 1], 0, $this->admin);
        config(['publicador.preparo_ia.limite_diario_por_empresa' => 1]);
        [$a, $b, $c] = $produtos = $this->tresNaOnda();
        [$sa, $sb, $sc] = ["s-{$a->id}", "s-{$b->id}", "s-{$c->id}"];

        // Dia 05: os três pedidos; o teto do texto deixa passar só o primeiro.
        $this->assertEqualsCanonicalizing([$sa, $sb, $sc], $this->soltura()['pedidos']);
        $this->rodar(PrepararProdutoNoPublicadorJob::class);
        $this->assertSame([$sa => false, $sb => true, $sc => true], $this->semTexto($produtos));

        // Dia 06: a repescagem (única) dos dois sem texto; o teto deixa passar mais um.
        $this->agora('2026-11-06 00:07:00');
        $dia = $this->soltura();
        $this->assertSame([], $dia['pedidos']);
        $this->assertEqualsCanonicalizing([$sb, $sc], $dia['repescados']);
        $this->rodar(PrepararProdutoNoPublicadorJob::class);
        $this->assertSame([$sa => false, $sb => false, $sc => true], $this->semTexto($produtos));

        // Dia 07 em diante: o último segue sem texto, mas ninguém o pede de novo — espera uma pessoa, e a tela avisa.
        foreach (['2026-11-07 00:07:00', '2026-11-09 00:07:00'] as $quando) {
            $this->agora($quando);
            $this->assertSame([[], []], $this->pedidosERepescados($this->soltura()), $quando);
        }
        $this->assertSame([$sa => false, $sb => false, $sc => true], $this->semTexto($produtos));
        $naTela = $this->naTela($sc);
        $this->assertSame(['robo', 'Ainda não começou (desde 05/11) · pendência na Lista', true], [$naTela['etapa'], $naTela['status'], $naTela['alerta']]);

        Queue::assertPushed(PrepararProdutoNoPublicadorJob::class, 5);
        $this->assertCount(4, $this->cabecasDasCadeias());
        $this->assertSame(0, PubAgenteExecucao::query()->count());
    }

    public function test_com_o_agente_recusando_sempre_a_repescagem_diaria_para_no_fim_da_janela(): void
    {
        // O agente ligado e sem vaga nenhuma (limite 0): toda repescagem é recusada, de graça — mas ela não vira um
        // pedido por dia para sempre. Janela de 2 dias contados do começo do item na onda (05/11).
        $this->ligar(['limite_diario' => 0]);
        config(['trilha.ondas.repescagem_dias' => 2]);
        $p = $this->produtoDoPortal();
        $this->noPublicador($p);
        $onda = TrilhaOnda::create(['company_id' => $this->empresa->id, 'nome' => 'Onda 1', 'data' => '2026-11-05']);
        TrilhaOndaItem::create(['onda_id' => $onda->id, 'company_id' => $this->empresa->id, 'produto_id' => $p->id, 'quantidade' => 1,
            'na_onda_desde' => now()->subDay()]);
        $s = "s-{$p->id}";

        $this->assertSame([[$s], []], $this->pedidosERepescados($this->soltura()));
        $this->rodar(PrepararProdutoNoPublicadorJob::class);
        foreach (['2026-11-06 00:07:00', '2026-11-07 00:07:00'] as $quando) {
            $this->agora($quando);
            $this->assertSame([[], [$s]], $this->pedidosERepescados($this->soltura()), $quando);
            $this->rodar(PrepararProdutoNoPublicadorJob::class);
        }

        // Fora da janela: acabou, mesmo sem texto e com o agente ligado.
        foreach (['2026-11-08 00:07:00', '2026-11-10 00:07:00'] as $quando) {
            $this->agora($quando);
            $this->assertSame([[], []], $this->pedidosERepescados($this->soltura()), $quando);
        }
        $this->assertTrue(TrilhaDaEmpresa::itens($this->empresa->fresh())[$s]['sem_texto']);
        Queue::assertPushed(PrepararProdutoNoPublicadorJob::class, 3);
        Queue::assertNotPushed(GerarPreparoIaJob::class);
        $this->assertSame(0, PubAgenteExecucao::query()->count());
    }

    // ─── apoio ───

    /**
     * Três cadeiras no Publicador (no recorte do piloto do agente), as três na Onda 1 de 05/11 — entraram na véspera.
     *
     * @return list<EstruturaProduto>
     */
    private function tresNaOnda(): array
    {
        $produtos = [];
        foreach (['CADA', 'CADB', 'CADC'] as $codigo) {
            $p = $this->produtoDoPortal($codigo);
            $this->noPublicador($p);
            $produtos[] = $p;
        }
        $onda = TrilhaOnda::create(['company_id' => $this->empresa->id, 'nome' => 'Onda 1', 'data' => '2026-11-05']);
        foreach ($produtos as $p) {
            TrilhaOndaItem::create(['onda_id' => $onda->id, 'company_id' => $this->empresa->id, 'produto_id' => $p->id, 'quantidade' => 1,
                'na_onda_desde' => now()->subDay()]);
        }

        return $produtos;
    }

    /** Uma passada da soltura na empresa do cenário. */
    private function soltura(): array
    {
        return app(SoltarOndas::class)->daEmpresa($this->empresa->fresh());
    }

    /** @return array{0: list<string>, 1: list<string>} */
    private function pedidosERepescados(array $resumo): array
    {
        return [$resumo['pedidos'], $resumo['repescados']];
    }

    /** Os produtos do Portal que o agente admitiu, na ordem da admissão. @return list<int> */
    private function admitidos(): array
    {
        return PubAgenteExecucao::query()->orderBy('id')->pluck('produto_id')
            ->map(fn ($pub) => (int) PubProduto::query()->whereKey($pub)->value('estrutura_produto_id'))->all();
    }

    /**
     * @param  list<EstruturaProduto>  $produtos
     * @return array<string, bool> a Simples de cada produto → o robô ainda não escreveu NADA do texto
     */
    private function semTexto(array $produtos): array
    {
        $itens = TrilhaDaEmpresa::itens($this->empresa->fresh());
        $saida = [];
        foreach ($produtos as $p) {
            $saida["s-{$p->id}"] = $itens["s-{$p->id}"]['sem_texto'];
        }

        return $saida;
    }

    private function naTela(string $chave): ?array
    {
        foreach (app(TrilhaDaEmpresa::class)->montar($this->empresa->fresh())['ofertas'] as $o) {
            if ($o['id'] === $chave) {
                return $o;
            }
        }

        return null;
    }

    /** Muda o relógio (São Paulo). O schema guardado acompanha: senão ele "venceria" e o preparo iria ao ML. */
    private function agora(string $quando): void
    {
        Carbon::setTestNow(Carbon::parse($quando, TrilhaOnda::FUSO));
        MlCategoriaSchema::query()->update(['fetched_at' => now()]);
    }
}
