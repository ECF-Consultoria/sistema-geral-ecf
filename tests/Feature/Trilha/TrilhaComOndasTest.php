<?php

namespace Tests\Feature\Trilha;

use App\Models\Company;
use App\Models\EstruturaOferta;
use App\Models\TrilhaMapeamento;
use App\Models\User;
use App\Services\Trilha\TrilhaDaEmpresa;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Trilha\Concerns\MontaTrilha;
use Tests\TestCase;

/**
 * A leitura da trilha com as ondas (2ª entrega, 02/11/2026): na empresa com a trilha de trabalho ligada, a oferta que
 * ainda não andou segue a régua da onda — "Sem onda · falta escolher a data" no Planejamento, "Na fila · começa em"
 * na Preparação automática — e o produto completo só sai do Mapeamento com o "Concluir mapeamento" da trilha (ou
 * com a Simples já andando). Com a chave desligada, tudo como na 1ª entrega.
 */
class TrilhaComOndasTest extends TestCase
{
    use MontaTrilha;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Bus::fake();
        Carbon::setTestNow(Carbon::parse('2026-11-05 10:00:00', 'America/Sao_Paulo'));
        config(['trilha.empresas' => [], 'trilha.trabalho.empresas' => []]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_produto_completo_fica_em_falta_mapear_ate_o_concluir_da_trilha(): void
    {
        $empresa = $this->empresaDaTrilha();
        $a = $this->produtoCompleto($empresa, 'Vaso Alfa', 'VA-1');

        $trilha = $this->trilha($empresa);
        $this->assertSame('mapa', $this->produtoNaTrilha($trilha, $a['produto']->id)['etapa']);
        $this->assertNull($this->itemDaTrilha($trilha, "s-{$a['produto']->id}"), 'a Simples é parte do produto até ele ser mapeado');

        $quem = User::factory()->create(['name' => 'Rafael']);
        TrilhaMapeamento::create(['company_id' => $empresa->id, 'produto_id' => $a['produto']->id, 'concluido_em' => now(), 'concluido_por' => $quem->id]);

        $trilha = $this->trilha($empresa);
        $produto = $this->produtoNaTrilha($trilha, $a['produto']->id);
        $this->assertNull($produto['etapa']);
        $this->assertSame('Rafael', $produto['mapeado_por']);
        $this->assertSame('2026-11-05T10:00:00-03:00', $produto['mapeado_em']);
        $simples = $this->itemDaTrilha($trilha, "s-{$a['produto']->id}");
        $this->assertSame(['plano', 'Sem onda · falta escolher a data', true, null],
            [$simples['etapa'], $simples['status'], $simples['alerta'], $simples['onda_id']]);
    }

    public function test_a_simples_que_ja_andou_tira_o_produto_do_mapeamento_sem_marca(): void
    {
        $empresa = $this->empresaDaTrilha();
        $a = $this->produtoCompleto($empresa, 'Vaso Alfa', 'VA-1');
        $this->rascunhoDoPub($this->grupoNoPublicador($empresa, $a), 'DRAFT', [
            'ia_preparo' => ['hash' => 'h', 'etapas' => ['titulo' => ['status' => 'ok', 'em' => now()->toIso8601String()]]],
        ]);

        $trilha = $this->trilha($empresa);
        $this->assertNull($this->produtoNaTrilha($trilha, $a['produto']->id)['etapa'], 'o que andou antes das ondas não volta à etapa 2');
        $this->assertNotSame('plano', $this->itemDaTrilha($trilha, "s-{$a['produto']->id}")['etapa'], 'e não cai em "Sem onda"');
        $this->assertFalse($this->itemDaTrilha($trilha, "s-{$a['produto']->id}")['planejavel'], 'o que andou não entra em onda');
    }

    public function test_a_regua_da_onda_na_ordem_da_tabela(): void
    {
        $empresa = $this->empresaDaTrilha();
        $mapeado = fn (array $p) => TrilhaMapeamento::create(['company_id' => $empresa->id, 'produto_id' => $p['produto']->id, 'concluido_em' => now()]);

        $futura = $this->produtoCompleto($empresa, 'Vaso Futuro', 'VF-1');
        $hoje = $this->produtoCompleto($empresa, 'Vaso Hoje', 'VH-1');
        $parado = $this->produtoCompleto($empresa, 'Vaso Parado', 'VP-1');
        foreach ([$futura, $hoje, $parado] as $p) {
            $mapeado($p);
        }

        $this->itemNaOnda($this->onda($empresa, '2026-11-06', 'Onda 2'), "s-{$futura['produto']->id}");
        $this->itemNaOnda($this->onda($empresa, '2026-11-05', 'Onda 1'), "s-{$hoje['produto']->id}");
        $this->itemNaOnda($this->onda($empresa, '2026-11-03', 'Onda 0', '2026-11-03 00:07:00'), "s-{$parado['produto']->id}", '2026-11-03 00:07:00');

        $trilha = $this->trilha($empresa);
        $item = fn (array $p) => $this->itemDaTrilha($trilha, "s-{$p['produto']->id}");

        $this->assertSame(['robo', 'Na fila · começa em 06/11', false], [$item($futura)['etapa'], $item($futura)['status'], $item($futura)['alerta']]);
        $this->assertSame(['robo', 'Na fila · começou em 05/11', false], [$item($hoje)['etapa'], $item($hoje)['status'], $item($hoje)['alerta']]);
        $this->assertSame([true, true], [$item($futura)['planejavel'], $item($hoje)['planejavel']], 'quem não andou pode mudar de onda');
        $this->assertSame(['robo', 'Ainda não começou (desde 03/11)', true], [$item($parado)['etapa'], $item($parado)['status'], $item($parado)['alerta']]);
        $this->assertNotNull($item($futura)['onda_id']);

        // As ondas na ordem da data, com o que a equipe lê.
        $this->assertSame(['Onda 0', 'Onda 1', 'Onda 2'], array_column($trilha['ondas'], 'nome'));
        $this->assertSame(['2026-11-03', '2026-11-05', '2026-11-06'], array_column($trilha['ondas'], 'data'));
        $this->assertSame([true, true, false], array_column($trilha['ondas'], 'comecou'));
        $this->assertTrue($trilha['trabalho']);
    }

    public function test_combo_na_onda_espera_a_simples_ficar_pronta_e_kit_avisa_a_pendencia_na_lista(): void
    {
        $empresa = $this->empresaDaTrilha();
        $a = $this->produtoCompleto($empresa, 'Vaso Alfa', 'VA-1');
        $b = $this->produtoCompleto($empresa, 'Banqueta Beta', 'BB-1');
        TrilhaMapeamento::create(['company_id' => $empresa->id, 'produto_id' => $a['produto']->id, 'concluido_em' => now()]);
        $this->ofertaComposta($empresa, EstruturaOferta::FASE_COMBO, 'VA-1-CB2', 'Combo 2 Vaso Alfa', [[$a['simples'][0], 2]]);
        $kit = $this->ofertaComposta($empresa, EstruturaOferta::FASE_KIT, 'KT-VA-1-BB-1', 'Kit Alfa e Beta', [[$a['simples'][0], 1], [$b['simples'][0], 1]]);
        // A peça Beta perde o título: cadastro incompleto.
        $b['produto']->update(['titulo' => null]);

        $hoje = $this->onda($empresa, '2026-11-05', 'Onda 1');
        $this->itemNaOnda($hoje, "s-{$a['produto']->id}");
        $this->itemNaOnda($hoje, "c-{$a['produto']->id}-2");
        $this->itemNaOnda($hoje, "o-{$kit->id}");

        $trilha = $this->trilha($empresa);
        $this->assertSame('Na fila · espera a oferta simples ficar pronta', $this->itemDaTrilha($trilha, "c-{$a['produto']->id}-2")['status']);
        $kitNaTrilha = $this->itemDaTrilha($trilha, "o-{$kit->id}");
        // A pendência é AVISO, não espera: o robô prepara com o que houver (só a ficha incompleta o barra).
        $this->assertSame(['robo', 'Na fila · com pendência na Lista', true], [$kitNaTrilha['etapa'], $kitNaTrilha['status'], $kitNaTrilha['alerta']]);
    }

    public function test_ainda_nao_comecou_conta_da_entrada_do_item_na_onda_e_nao_do_dia_da_onda(): void
    {
        $empresa = $this->empresaDaTrilha();
        $a = $this->produtoCompleto($empresa, 'Vaso Alfa', 'VA-1');
        TrilhaMapeamento::create(['company_id' => $empresa->id, 'produto_id' => $a['produto']->id, 'concluido_em' => now()]);
        // A onda começou há 10 dias; o item entrou nela hoje cedo e já foi pedido.
        $linha = $this->itemNaOnda($this->onda($empresa, '2026-10-26', 'Onda Velha', '2026-10-26 00:07:00'), "s-{$a['produto']->id}", '2026-11-05 08:07:00');
        $linha->update(['na_onda_desde' => Carbon::parse('2026-11-05 08:00:00', 'America/Sao_Paulo')]);

        $this->assertSame('Na fila · começou em 26/10', $this->itemDaTrilha($this->trilha($empresa), "s-{$a['produto']->id}")['status'],
            'pedido há 2 horas: sem alarme falso');

        Carbon::setTestNow(Carbon::parse('2026-11-06 09:00:00', 'America/Sao_Paulo'));
        $item = $this->itemDaTrilha($this->trilha($empresa), "s-{$a['produto']->id}");
        $this->assertSame(['Ainda não começou (desde 05/11)', true], [$item['status'], $item['alerta']]);
    }

    public function test_a_simples_numa_onda_aparece_mesmo_com_o_produto_ainda_no_mapeamento(): void
    {
        $empresa = $this->empresaDaTrilha();
        $a = $this->produtoCompleto($empresa, 'Vaso Alfa', 'VA-1');
        $this->itemNaOnda($this->onda($empresa, '2026-11-06'), "s-{$a['produto']->id}");

        $trilha = $this->trilha($empresa);
        $this->assertSame('mapa', $this->produtoNaTrilha($trilha, $a['produto']->id)['etapa']);
        $this->assertSame('Na fila · começa em 06/11', $this->itemDaTrilha($trilha, "s-{$a['produto']->id}")['status']);
    }

    public function test_oferta_sem_produto_na_lista_fica_fora_das_ondas(): void
    {
        $empresa = $this->empresaDaTrilha();
        $avulsa = EstruturaOferta::create(['company_id' => $empresa->id, 'variacao_id' => null, 'sku' => 'AVULSO-1', 'fase' => 'simples', 'nome' => 'Avulso']);

        $item = $this->itemDaTrilha($this->trilha($empresa), "o-{$avulsa->id}");

        $this->assertSame(['plano', 'Fora das ondas · sem produto na Lista', false, true],
            [$item['etapa'], $item['status'], $item['alerta'], $item['sem_produto']]);
    }

    public function test_com_a_chave_desligada_tudo_como_na_primeira_entrega(): void
    {
        $empresa = $this->empresaDaTrilha(trabalho: false);
        $a = $this->produtoCompleto($empresa, 'Vaso Alfa', 'VA-1');
        $this->itemNaOnda($this->onda($empresa, '2026-11-06'), "s-{$a['produto']->id}");

        DB::enableQueryLog();
        $trilha = $this->trilha($empresa);
        $consultas = array_column(DB::getQueryLog(), 'query');
        DB::disableQueryLog();

        $this->assertNull($this->produtoNaTrilha($trilha, $a['produto']->id)['etapa'], 'sem sugestão pendente = mapeado (1ª entrega)');
        $this->assertSame(['plano', 'Sem onda', null], [$this->itemDaTrilha($trilha, "s-{$a['produto']->id}")['etapa'],
            $this->itemDaTrilha($trilha, "s-{$a['produto']->id}")['status'], $this->itemDaTrilha($trilha, "s-{$a['produto']->id}")['onda_id']]);
        $this->assertSame([], $trilha['ondas']);
        $this->assertFalse($trilha['trabalho']);
        $this->assertSame([], array_values(array_filter($consultas, fn ($q) => str_contains($q, 'trilha_'))), 'nenhuma tabela da trilha é lida');
    }

    public function test_o_numero_de_consultas_nao_cresce_com_o_catalogo(): void
    {
        $contar = function (int $n): int {
            $empresa = $this->empresaDaTrilha();
            $onda = $this->onda($empresa, '2026-11-06');
            for ($i = 1; $i <= $n; $i++) {
                $p = $this->produtoCompleto($empresa, "Peça {$i}", "P{$empresa->id}-{$i}", ['Natural', 'Preto']);
                TrilhaMapeamento::create(['company_id' => $empresa->id, 'produto_id' => $p['produto']->id, 'concluido_em' => now()]);
                $this->rascunhoDoPub($this->grupoNoPublicador($empresa, $p));
                $this->itemNaOnda($onda, "s-{$p['produto']->id}");
            }
            DB::enableQueryLog();
            $this->trilha($empresa);
            $total = count(DB::getQueryLog());
            DB::flushQueryLog();
            DB::disableQueryLog();

            return $total;
        };

        $this->assertSame($contar(1), $contar(4));
    }

    private function trilha(Company $empresa): array
    {
        return app(TrilhaDaEmpresa::class)->montar($empresa->fresh());
    }
}
