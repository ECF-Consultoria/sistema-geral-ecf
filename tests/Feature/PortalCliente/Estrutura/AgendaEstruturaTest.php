<?php

namespace Tests\Feature\PortalCliente\Estrutura;

use App\Models\EstruturaAgendaItem;
use App\Services\Portal\Estrutura\EstruturaAgendaService;
use App\Services\Portal\Estrutura\EstruturaVisaoService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\GabaritoDaPlanilhaEstrutural;
use Tests\TestCase;

/**
 * "Pegue os buracos do mapeamento e agende … 1 publicação por dia até zerar a
 * lista. 7 dias depois, agende a Jardinagem."
 */
class AgendaEstruturaTest extends TestCase
{
    use GabaritoDaPlanilhaEstrutural;
    use RefreshDatabase;

    /**
     * 23/09/2026 é quarta. A proposta segue em DIAS CORRIDOS — o exemplo da
     * planilha agenda sábado 26/09 e domingo 27/09 — e inclui os buracos de um
     * lado só (CB2 falta Premium; MSA-MR falta Clássico), que o exemplo deixou
     * de fora.
     */
    public function test_proposta_um_por_dia_em_dias_corridos_na_ordem_da_lista(): void
    {
        $empresa = $this->empresaDoGabarito();
        $ator = $this->atorCliente($empresa);
        $this->anunciosDoGabarito($this->listaDoGabarito($empresa, $ator), $ator);

        $proposta = app(EstruturaAgendaService::class)->proposta($empresa, null, CarbonImmutable::parse('2026-09-23'));

        $this->assertSame([
            ['CAD-01-CB2', '2026-09-23'],
            ['CAD-01-CB3', '2026-09-24'],
            ['CAD-01-CB4', '2026-09-25'],
            ['CAD-01-CB5', '2026-09-26'],
            ['CAD-01-CB6', '2026-09-27'],
            ['MSA-MR', '2026-09-28'],
            ['MSA-MR+CAD-01-KIT', '2026-09-29'],
            ['MSA-MR+CAD-01-CBT4', '2026-09-30'],
        ], array_map(fn ($p) => [$p['sku'], $p['data']], $proposta));
    }

    public function test_dia_ocupado_e_oferta_ja_agendada_ficam_de_fora_e_desmarcar_nao_deixa_buraco(): void
    {
        $empresa = $this->empresaDoGabarito();
        $ator = $this->atorCliente($empresa);
        $ofertas = $this->listaDoGabarito($empresa, $ator);
        $this->anunciosDoGabarito($ofertas, $ator);
        $svc = app(EstruturaAgendaService::class);
        $hoje = CarbonImmutable::parse('2026-09-23');

        // CB3 já tem publicação marcada para 24/09: sai da proposta e ocupa o dia.
        $svc->agendar($ofertas['CAD-01-CB3'], '2026-09-24', 'publicacao', $ator);

        $proposta = $svc->proposta($empresa, null, $hoje);
        $this->assertNotContains('CAD-01-CB3', array_column($proposta, 'sku'));
        $this->assertSame(['2026-09-23', '2026-09-25'], array_slice(array_column($proposta, 'data'), 0, 2));

        // Só CB5 e o kit escolhidos: as datas são recompactadas.
        $somente = [$ofertas['CAD-01-CB5']->id, $ofertas['MSA-MR+CAD-01-KIT']->id];
        $this->assertSame(['2026-09-23', '2026-09-25'], array_column($svc->proposta($empresa, $somente, $hoje), 'data'));
    }

    /** O navegador manda QUAIS ofertas; as datas são recalculadas no servidor. */
    public function test_aplicar_a_proposta_pelo_portal(): void
    {
        $empresa = $this->empresaDoGabarito();
        $ator = $this->atorCliente($empresa);
        $ofertas = $this->listaDoGabarito($empresa, $ator);

        $this->entrarNoPortal($empresa)
            ->post(route('portal.auth.estrutura.agenda.proposta.aplicar'), [
                'ofertas' => [$ofertas['CAD-01-CB4']->id, $ofertas['CAD-01-CB3']->id],
                'datas'   => ['2030-01-01'], // ignorado
            ])
            ->assertSessionHasNoErrors();

        $itens = EstruturaAgendaItem::orderBy('data')->get();
        $this->assertCount(2, $itens);
        // Na ordem da LISTA (CB3 antes de CB4), a partir de hoje.
        $this->assertSame([$ofertas['CAD-01-CB3']->id, $ofertas['CAD-01-CB4']->id], $itens->pluck('oferta_id')->all());
        $this->assertSame(today()->format('Y-m-d'), $itens[0]->data->format('Y-m-d'));
    }

    /**
     * Publicação feita = a oferta tem Clássico e Premium. Não há "marcar
     * publicação como feita": o caminho é cadastrar os anúncios.
     */
    public function test_publicacao_e_derivada_e_jardinagem_e_marcada(): void
    {
        $empresa = $this->empresaDoGabarito();
        $ator = $this->atorCliente($empresa);
        $ofertas = $this->listaDoGabarito($empresa, $ator);
        $this->anunciosDoGabarito($ofertas, $ator);
        $svc = app(EstruturaAgendaService::class);
        $hoje = CarbonImmutable::parse('2026-09-23');

        $pubCad = $svc->agendar($ofertas['CAD-01'], '2026-09-20', 'publicacao', $ator);    // já OK
        $pubCb3 = $svc->agendar($ofertas['CAD-01-CB3'], '2026-09-21', 'publicacao', $ator); // atrasada
        $jard = $svc->agendar($ofertas['CAD-01'], '2026-09-30', 'jardinagem', $ator);

        try {
            $svc->marcarJardinagem($pubCb3, true, $ator);
            $this->fail('Publicação não se marca: cadastra-se o anúncio.');
        } catch (ValidationException) {
        }

        $svc->marcarJardinagem($jard->fresh(), true, $ator);

        $agenda = app(EstruturaVisaoService::class)->agenda($empresa, $hoje);
        $ids = fn ($s) => array_column($agenda['secoes'][$s]['itens'], 'id');

        $this->assertSame([$pubCb3->id], $ids('atrasadas'));
        $this->assertEqualsCanonicalizing([$pubCad->id, $jard->id], $ids('concluidas'));
    }

    /** Concluir pela agenda exige o MLB — é ali que nasceria o registro sem nome. */
    public function test_concluir_pela_agenda_exige_mlb_e_cadastro_manual_nao(): void
    {
        $empresa = $this->empresaDoGabarito();
        $ator = $this->atorCliente($empresa);
        $ofertas = $this->listaDoGabarito($empresa, $ator);
        $rota = route('portal.auth.estrutura.anuncios.criar', $ofertas['CAD-01-CB3']->id);

        $this->entrarNoPortal($empresa)->post($rota, ['tipo' => 'classico', 'via_agenda' => true])
            ->assertSessionHasErrors('codigo_mlb');

        $this->entrarNoPortal($empresa)->post($rota, ['tipo' => 'classico'])
            ->assertSessionHasNoErrors();
    }

    public function test_data_impossivel_e_recusada(): void
    {
        $empresa = $this->empresaDoGabarito();
        $ator = $this->atorCliente($empresa);
        $ofertas = $this->listaDoGabarito($empresa, $ator);

        $this->expectException(ValidationException::class);
        app(EstruturaAgendaService::class)->agendar($ofertas['CAD-01'], '2026-02-31', 'publicacao', $ator);
    }
}
