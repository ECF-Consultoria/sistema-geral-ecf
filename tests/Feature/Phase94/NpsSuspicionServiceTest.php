<?php

namespace Tests\Feature\Phase94;

use App\Services\Nps\NpsSuspicionService;
use Tests\TestCase;

/**
 * Phase 94 Plan 01 (AB-94-4) — Cobertura das 4 regras de suspeita do
 * `NpsSuspicionService`. Config fixada por teste via `config([...])` para
 * isolar cada cenário (IPs/CIDRs internos, janela de resposta rápida).
 */
class NpsSuspicionServiceTest extends TestCase
{
    private function service(): NpsSuspicionService
    {
        return app(NpsSuspicionService::class);
    }

    /**
     * Regra 1 — IP exato na lista de internal_ips → suspeita, severidade média.
     */
    public function test_regra_1_ip_interno_exato(): void
    {
        config(['nps.anti_burlamento.internal_ips' => ['203.0.113.10']]);
        config(['nps.anti_burlamento.internal_cidrs' => []]);

        $veredito = $this->service()->evaluate('203.0.113.10', 3600, false);

        $this->assertTrue($veredito['is_suspicious']);
        $this->assertContains('Resposta enviada a partir da rede interna da ECF.', $veredito['reasons']);
        $this->assertSame('media', $veredito['severity']);
    }

    /**
     * Regra 1 (CIDR) — IP dentro de um range internal_cidrs → suspeita.
     */
    public function test_regra_1_ip_interno_via_cidr(): void
    {
        config(['nps.anti_burlamento.internal_ips' => []]);
        config(['nps.anti_burlamento.internal_cidrs' => ['10.0.0.0/8']]);

        $veredito = $this->service()->evaluate('10.1.2.3', 3600, false);

        $this->assertTrue($veredito['is_suspicious']);
        $this->assertContains('Resposta enviada a partir da rede interna da ECF.', $veredito['reasons']);
    }

    /**
     * Regra 2 — resposta rápida (janela default 60s) com IP externo →
     * suspeita, texto em MINUTOS ("1 minuto" — 60/60=1).
     */
    public function test_regra_2_resposta_rapida_janela_default_em_minutos(): void
    {
        config(['nps.anti_burlamento.internal_ips' => []]);
        config(['nps.anti_burlamento.internal_cidrs' => []]);
        config(['nps.anti_burlamento.fast_response_window_seconds' => 60]);

        $veredito = $this->service()->evaluate('198.51.100.5', 30, false);

        $this->assertTrue($veredito['is_suspicious']);
        $this->assertContains(
            'Resposta enviada em menos de 1 minuto após geração do link.',
            $veredito['reasons']
        );
        $this->assertSame('media', $veredito['severity']);
    }

    /**
     * Regra 2 (janela custom, não múltipla de 60) — texto em SEGUNDOS.
     */
    public function test_regra_2_janela_custom_nao_multipla_de_60_em_segundos(): void
    {
        config(['nps.anti_burlamento.internal_ips' => []]);
        config(['nps.anti_burlamento.internal_cidrs' => []]);
        config(['nps.anti_burlamento.fast_response_window_seconds' => 90]);

        $veredito = $this->service()->evaluate('198.51.100.5', 80, false);

        $this->assertTrue($veredito['is_suspicious']);
        $this->assertContains(
            'Resposta enviada em menos de 90 segundos após geração do link.',
            $veredito['reasons']
        );
    }

    /**
     * Regra 3 — IP interno E dentro da janela → motivo extra + severidade alta.
     */
    public function test_regra_3_combinacao_ip_interno_e_rapida_severidade_alta(): void
    {
        config(['nps.anti_burlamento.internal_ips' => ['203.0.113.10']]);
        config(['nps.anti_burlamento.internal_cidrs' => []]);
        config(['nps.anti_burlamento.fast_response_window_seconds' => 60]);

        $veredito = $this->service()->evaluate('203.0.113.10', 10, false);

        $this->assertTrue($veredito['is_suspicious']);
        $this->assertContains(
            'Link gerado e respondido rapidamente a partir da rede interna.',
            $veredito['reasons']
        );
        $this->assertSame('alta', $veredito['severity']);
    }

    /**
     * Regra 4 — sessão autenticada marca suspeita (media), SEM lançar
     * exception/abort (service só retorna veredito — bloqueio é Fase 96).
     */
    public function test_regra_4_sessao_autenticada_marca_suspeita_sem_bloquear(): void
    {
        config(['nps.anti_burlamento.internal_ips' => []]);
        config(['nps.anti_burlamento.internal_cidrs' => []]);
        config(['nps.anti_burlamento.fast_response_window_seconds' => 60]);

        $veredito = $this->service()->evaluate('198.51.100.5', 3600, true);

        $this->assertTrue($veredito['is_suspicious']);
        $this->assertContains(
            'Resposta realizada em sessão autenticada de usuário interno.',
            $veredito['reasons']
        );
        $this->assertSame('media', $veredito['severity']);
    }

    /**
     * Caso limpo — IP externo, duração longa, sem sessão → não suspeito.
     */
    public function test_caso_limpo_nao_suspeito(): void
    {
        config(['nps.anti_burlamento.internal_ips' => []]);
        config(['nps.anti_burlamento.internal_cidrs' => []]);
        config(['nps.anti_burlamento.fast_response_window_seconds' => 60]);

        $veredito = $this->service()->evaluate('198.51.100.5', 3600, false);

        $this->assertFalse($veredito['is_suspicious']);
        $this->assertSame([], $veredito['reasons']);
        $this->assertSame('nenhuma', $veredito['severity']);
    }

    /**
     * Config vazia (internal_ips e internal_cidrs []) — isInternalIp nunca
     * dá match e não lança exceção.
     */
    public function test_config_vazia_nao_lanca_excecao(): void
    {
        config(['nps.anti_burlamento.internal_ips' => []]);
        config(['nps.anti_burlamento.internal_cidrs' => []]);
        config(['nps.anti_burlamento.fast_response_window_seconds' => 60]);

        $veredito = $this->service()->evaluate('8.8.8.8', 3600, false);

        $this->assertFalse($veredito['is_suspicious']);
    }

    /**
     * IP null (edge) — Regra 1 não avalia (não suspeita por IP), demais
     * regras seguem normais.
     */
    public function test_ip_null_nao_avalia_regra_1_mas_demais_seguem(): void
    {
        config(['nps.anti_burlamento.internal_ips' => ['203.0.113.10']]);
        config(['nps.anti_burlamento.internal_cidrs' => []]);
        config(['nps.anti_burlamento.fast_response_window_seconds' => 60]);

        $veredito = $this->service()->evaluate(null, 3600, false);

        $this->assertFalse($veredito['is_suspicious']);
        $this->assertSame([], $veredito['reasons']);

        // Regra 2 continua funcionando normalmente com ip null.
        $veredito2 = $this->service()->evaluate(null, 10, false);
        $this->assertTrue($veredito2['is_suspicious']);
        $this->assertContains(
            'Resposta enviada em menos de 1 minuto após geração do link.',
            $veredito2['reasons']
        );
    }
}
