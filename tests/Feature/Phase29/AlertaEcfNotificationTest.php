<?php

namespace Tests\Feature\Phase29;

use App\Notifications\AlertaEcfNotification;
use App\Notifications\Categoria;
use Tests\TestCase;

/**
 * Suite Feature — AlertaEcfNotification (Phase 29).
 *
 * Smoke da subclasse: instancia com payload real de RELOJOARIA WENUS e
 * verifica o contrato do BaseNotification (6 chaves canônicas + categoria
 * + título + mensagem + meta + via() + link).
 *
 * Testa também os 5 event_types conhecidos + fallback genérico.
 * Usa RefreshDatabase apenas se necessário — testes de unidade pura sem DB.
 */
class AlertaEcfNotificationTest extends TestCase
{
    // ─── Instância canônica (RELOJOARIA WENUS, gmv_queda_mom) ───

    private function criarNotificacaoCanonica(): AlertaEcfNotification
    {
        return new AlertaEcfNotification(
            signalId:        91,
            eventType:       'seller.gmv_queda_mom',
            custId:          '1354156948',
            empresaNome:     'RELOJOARIA WENUS',
            payloadResumido: [
                'delta_pct'    => -76.5,
                'gmv_atual'    => 11135.78,
                'gmv_anterior' => 47388.20,
                'mes_atual'    => 'maio/2026',
            ],
            link: '/alertas-estrategicos',
        );
    }

    // ─── Testes estruturais ───

    /**
     * T01 — toArray retorna as 6 chaves canônicas do BaseNotification.
     */
    public function test_to_array_retorna_6_chaves_canonicas(): void
    {
        $notif  = $this->criarNotificacaoCanonica();
        $array  = $notif->toArray(new \stdClass());

        $this->assertArrayHasKey('titulo',        $array);
        $this->assertArrayHasKey('mensagem',      $array);
        $this->assertArrayHasKey('categoria',     $array);
        $this->assertArrayHasKey('autor_user_id', $array);
        $this->assertArrayHasKey('url',           $array);
        $this->assertArrayHasKey('meta',          $array);
    }

    /**
     * T02 — categoria = 'alerta_ecf' (string do enum Categoria::ALERTA_ECF).
     */
    public function test_categoria_e_alerta_ecf(): void
    {
        $notif = $this->criarNotificacaoCanonica();
        $array = $notif->toArray(new \stdClass());

        $this->assertSame('alerta_ecf', $array['categoria']);
        $this->assertSame(Categoria::ALERTA_ECF->value, $array['categoria']);
    }

    /**
     * T03 — título contém label do TYPE_LABELS + nome da empresa.
     */
    public function test_titulo_contem_label_e_nome_empresa(): void
    {
        $notif = $this->criarNotificacaoCanonica();
        $array = $notif->toArray(new \stdClass());

        $this->assertStringContainsString('Queda crítica de faturamento', $array['titulo']);
        $this->assertStringContainsString('RELOJOARIA WENUS',              $array['titulo']);
    }

    /**
     * T04 — mensagem contém valor de queda formatado pt-BR e mês.
     */
    public function test_mensagem_contem_valores_formatados_ptbr(): void
    {
        $notif = $this->criarNotificacaoCanonica();
        $array = $notif->toArray(new \stdClass());

        $this->assertStringContainsString('GMV caiu 76,5%', $array['mensagem']);
        $this->assertStringContainsString('em maio/2026',   $array['mensagem']);
    }

    /**
     * T05 — meta['signal_id'] é string '91' (não int 91).
     */
    public function test_meta_signal_id_e_string(): void
    {
        $notif = $this->criarNotificacaoCanonica();
        $array = $notif->toArray(new \stdClass());

        $this->assertSame('91', $array['meta']['signal_id']);
        $this->assertIsString($array['meta']['signal_id']);
    }

    /**
     * T06 — url = '/alertas-estrategicos'.
     */
    public function test_url_e_link_alertas_estrategicos(): void
    {
        $notif = $this->criarNotificacaoCanonica();
        $array = $notif->toArray(new \stdClass());

        $this->assertSame('/alertas-estrategicos', $array['url']);
    }

    /**
     * T07 — via() retorna ['database'] (herdado do BaseNotification, sem modificação).
     */
    public function test_via_retorna_somente_database(): void
    {
        $notif = $this->criarNotificacaoCanonica();

        $this->assertSame(['database'], $notif->via(new \stdClass()));
    }

    // ─── Testes por event_type ───

    /**
     * T08 — seller.queda_visitas: mensagem com "Visitas caíram".
     */
    public function test_queda_visitas_formata_mensagem_corretamente(): void
    {
        $notif = new AlertaEcfNotification(
            signalId:        92,
            eventType:       'seller.queda_visitas',
            custId:          '1354156948',
            empresaNome:     'CAMILLO PARTS',
            payloadResumido: [
                'delta_pct'        => -65.0,
                'visitas_atual'    => 4000,
                'visitas_anterior' => 12000,
            ],
        );
        $array = $notif->toArray(new \stdClass());

        $this->assertStringContainsString('Visitas caíram 65,0%',      $array['mensagem']);
        $this->assertStringContainsString('Queda crítica de visitas',   $array['titulo']);
    }

    /**
     * T09 — seller.medalha_rebaixada: mensagem com "Medalha rebaixada de X para Y".
     */
    public function test_medalha_rebaixada_formata_mensagem_corretamente(): void
    {
        $notif = new AlertaEcfNotification(
            signalId:        93,
            eventType:       'seller.medalha_rebaixada',
            custId:          '999',
            empresaNome:     'LYAMDECOR',
            payloadResumido: [
                'medalha_anterior' => 'PLATINUM',
                'medalha_atual'    => 'GOLD',
            ],
        );
        $array = $notif->toArray(new \stdClass());

        $this->assertStringContainsString('Medalha rebaixada de PLATINUM para GOLD', $array['mensagem']);
        $this->assertStringContainsString('Medalha rebaixada',                        $array['titulo']);
    }

    /**
     * T10 — seller.score_critico: mensagem com "Score Full N · Score Qualidade N".
     */
    public function test_score_critico_formata_mensagem_corretamente(): void
    {
        $notif = new AlertaEcfNotification(
            signalId:        94,
            eventType:       'seller.score_critico',
            custId:          '888',
            empresaNome:     'IMPERIALECOMMERCEOFICIAL',
            payloadResumido: [
                'score_full'       => 72,
                'score_qualidade'  => 68,
            ],
        );
        $array = $notif->toArray(new \stdClass());

        $this->assertStringContainsString('Score Full 72', $array['mensagem']);
        $this->assertStringContainsString('Score Qualidade 68', $array['mensagem']);
    }

    /**
     * T11 — seller.oportunidade_pads: mensagem com "GMV mensal R$ X sem ADS".
     */
    public function test_oportunidade_pads_formata_mensagem_corretamente(): void
    {
        $notif = new AlertaEcfNotification(
            signalId:        95,
            eventType:       'seller.oportunidade_pads',
            custId:          '777',
            empresaNome:     'PREMIER INDUSTRIA',
            payloadResumido: [
                'gmv_mensal' => 150000,
            ],
        );
        $array = $notif->toArray(new \stdClass());

        $this->assertStringContainsString('GMV mensal R$ 150.000 sem ADS', $array['mensagem']);
        $this->assertStringContainsString('Oportunidade de ADS detectada', $array['titulo']);
    }

    /**
     * T12 — event_type desconhecido: fallback com eventType no título e payload genérico na mensagem.
     */
    public function test_event_type_desconhecido_usa_fallback(): void
    {
        $notif = new AlertaEcfNotification(
            signalId:        99,
            eventType:       'seller.evento_novo_desconhecido',
            custId:          '111',
            empresaNome:     'EMPRESA TESTE',
            payloadResumido: [
                'chave_a' => 'valor_a',
                'chave_b' => 'valor_b',
            ],
        );
        $array = $notif->toArray(new \stdClass());

        // Título usa o eventType como fallback
        $this->assertStringContainsString('seller.evento_novo_desconhecido', $array['titulo']);
        // Mensagem usa formato k: v · k: v
        $this->assertStringContainsString('chave_a: valor_a', $array['mensagem']);
    }

    /**
     * T13 — meta contém todas as chaves canônicas: signal_id, event_type, cust_id, link, severity.
     */
    public function test_meta_contem_chaves_canonicas(): void
    {
        $notif = $this->criarNotificacaoCanonica();
        $array = $notif->toArray(new \stdClass());
        $meta  = $array['meta'];

        $this->assertArrayHasKey('signal_id',  $meta);
        $this->assertArrayHasKey('event_type', $meta);
        $this->assertArrayHasKey('cust_id',    $meta);
        $this->assertArrayHasKey('link',       $meta);
        $this->assertArrayHasKey('severity',   $meta);

        $this->assertSame('critical', $meta['severity']);
        $this->assertSame('1354156948', $meta['cust_id']);
        $this->assertSame('/alertas-estrategicos', $meta['link']);
    }
}
