<?php

namespace Tests\Unit\Publicador\Alavancas;

use App\Services\Publicador\Alavancas\DatasDoMl;
use App\Services\Publicador\Alavancas\TiposDePromocao;
use Tests\TestCase;

/** 166-04: a matriz de regras por tipo de promoção e as datas do ML. */
class TiposDePromocaoTest extends TestCase
{
    public function test_deal_candidato_inscreve_com_preco_e_nao_altera(): void
    {
        $c = TiposDePromocao::capacidades('DEAL', ['status' => 'candidate']);
        $this->assertTrue($c['inscrever']);
        $this->assertTrue($c['preco']);
        $this->assertFalse($c['alterar']);
        $this->assertFalse($c['remover']);
    }

    public function test_deal_e_campanha_do_vendedor_iniciados_alteram_e_removem(): void
    {
        $d = TiposDePromocao::capacidades('DEAL', ['status' => 'started']);
        $this->assertTrue($d['alterar']);
        $this->assertTrue($d['remover']);
        $this->assertFalse($d['inscrever']);
        $this->assertTrue(TiposDePromocao::capacidades('SELLER_CAMPAIGN', ['status' => 'started'])['alterar']);
    }

    public function test_marketplace_campaign_sem_preco_e_sem_alterar_com_motivo(): void
    {
        $c = TiposDePromocao::capacidades('MARKETPLACE_CAMPAIGN', ['status' => 'candidate']);
        $this->assertTrue($c['inscrever']);
        $this->assertFalse($c['preco']);

        $s = TiposDePromocao::capacidades('MARKETPLACE_CAMPAIGN', ['status' => 'started']);
        $this->assertFalse($s['alterar']);
        $this->assertSame('Para mudar o preço: tire o produto, ajuste o preço do anúncio e inscreva de novo.', $s['motivo']);
        $this->assertFalse(TiposDePromocao::capacidades('VOLUME', ['status' => 'started'])['alterar']);
    }

    public function test_dod_ativo_nao_remove_mas_programado_remove(): void
    {
        $a = TiposDePromocao::capacidades('DOD', ['status' => 'started']);
        $this->assertFalse($a['remover']);
        $this->assertSame('Oferta ativa não pode ser retirada; pause o anúncio no Mercado Livre se precisar.', $a['motivo']);
        $this->assertTrue(TiposDePromocao::capacidades('DOD', ['status' => 'pending'])['remover']);
        $this->assertFalse(TiposDePromocao::capacidades('LIGHTNING', ['status' => 'started'])['remover']);
    }

    public function test_smart_e_price_matching_so_inscrevem_com_offer_id_candidate(): void
    {
        $sem = TiposDePromocao::capacidades('SMART', ['status' => 'candidate']);
        $this->assertFalse($sem['inscrever']);
        $this->assertStringContainsString('no Mercado Livre', $sem['motivo']);
        $this->assertStringContainsString('Aceite', $sem['motivo']);

        $outro = TiposDePromocao::capacidades('PRICE_MATCHING', ['status' => 'candidate', 'offer_id' => 'OFFER-1']);
        $this->assertFalse($outro['inscrever']);

        $com = TiposDePromocao::capacidades('SMART', ['status' => 'candidate', 'offer_id' => 'CANDIDATE-MLB1-123']);
        $this->assertTrue($com['inscrever']);
        $this->assertFalse($com['preco']);
        $this->assertNull($com['motivo']);
    }

    public function test_relampago_candidato_pede_preco_e_estoque(): void
    {
        $c = TiposDePromocao::capacidades('LIGHTNING', ['status' => 'candidate']);
        $this->assertTrue($c['inscrever']);
        $this->assertTrue($c['preco']);
        $this->assertTrue($c['pede_estoque']);
    }

    public function test_tipos_do_vendedor_inscrevem_quando_nao_estao_no_ar(): void
    {
        $this->assertTrue(TiposDePromocao::capacidades('SELLER_CAMPAIGN', null)['inscrever']);
        $this->assertFalse(TiposDePromocao::capacidades('SELLER_CAMPAIGN', ['status' => 'started'])['inscrever']);
        $this->assertTrue(TiposDePromocao::capacidades('SELLER_COUPON_CAMPAIGN', ['status' => 'candidate'])['inscrever']);
    }

    public function test_tipo_desconhecido_e_so_leitura(): void
    {
        $c = TiposDePromocao::capacidades('XPTO', ['status' => 'candidate']);
        $this->assertFalse($c['inscrever'] || $c['alterar'] || $c['remover'] || $c['preco']);
        $this->assertSame('Tipo de promoção desconhecido: só leitura.', $c['motivo']);
    }

    public function test_sao_doze_tipos(): void
    {
        $this->assertCount(12, TiposDePromocao::TODOS);
    }

    public function test_datas_tres_formatos_dao_o_mesmo_dia_em_sao_paulo(): void
    {
        foreach (['2026-10-07T23:59:59Z', '2026-10-07T20:59:59-03:00', '2026-10-07T20:59:59'] as $iso) {
            $d = DatasDoMl::ler($iso);
            $this->assertSame('2026-10-07', $d->format('Y-m-d'), $iso);
            $this->assertSame('America/Sao_Paulo', $d->getTimezone()->getName());
        }
        $this->assertNull(DatasDoMl::ler('lixo'));
        $this->assertNull(DatasDoMl::ler(null));
    }

    public function test_dias_ate_e_inclusivos(): void
    {
        $hoje = DatasDoMl::ler('2026-10-04T10:00:00')->startOfDay();
        $this->assertSame(3, DatasDoMl::diasAte('2026-10-07T23:59:59', $hoje));
        $this->assertSame(-1, DatasDoMl::diasAte('2026-10-03T12:00:00', $hoje));
        $this->assertSame(1, DatasDoMl::diasInclusivos('2026-10-07', '2026-10-07'));
        $this->assertSame(14, DatasDoMl::diasInclusivos('2026-10-01', '2026-10-14'));
        $this->assertSame('2026-10-07T00:00:00', DatasDoMl::inicioDoDia('2026-10-07'));
        $this->assertSame('2026-10-07T23:59:59', DatasDoMl::fimDoDia('2026-10-07'));
    }
}
