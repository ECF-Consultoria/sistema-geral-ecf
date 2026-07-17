<?php

namespace App\Services\Nps;

use App\Models\Configuracao;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * NpsSuspicionService — Phase 94 (AB-94-4).
 *
 * Avalia se uma resposta NPS é suspeita com base em 4 regras. Stateless,
 * resolvido via container (mesmo padrão de NpsSnapshotService/NpsPendingService).
 * NÃO bloqueia nada — apenas retorna o veredito para o controller persistir
 * (`is_suspicious`/`suspicion_reasons` em `nps_responses`, plano 94-02).
 * Bloqueio de sessão interna é Fase 96 (fora de escopo aqui).
 *
 * Regras:
 *  1. IP interno ECF — UNIÃO de config `nps.anti_burlamento.internal_ips`/
 *     `internal_cidrs` (.env, Fase 94) COM `Configuracao::get('nps_internal_ips'/
 *     'nps_internal_cidrs')` (UI, Fase 96 AB-96-2). O .env nunca é substituído,
 *     só somado — editar pela UI não exige deploy.
 *  2. Resposta rápida (duração <= `fast_response_window_seconds`)
 *  3. Combinação 1+2 → severidade 'alta' e motivo extra
 *  4. Sessão autenticada (usuário interno logado) no momento da resposta
 */
class NpsSuspicionService
{
    /**
     * @return array{is_suspicious: bool, reasons: array<int, string>, severity: string}
     */
    public function evaluate(?string $ip, int $durationSeconds, bool $isAuthenticatedSession): array
    {
        $reasons = [];

        $ehIpInterno = $ip !== null && $this->isInternalIp($ip);
        $ehRapida    = $durationSeconds <= $this->windowSeconds();

        if ($ehIpInterno) {
            $reasons[] = 'Resposta enviada a partir da rede interna da ECF.';
        }

        if ($ehRapida) {
            $reasons[] = $this->motivoRespostaRapida();
        }

        if ($ehIpInterno && $ehRapida) {
            $reasons[] = 'Link gerado e respondido rapidamente a partir da rede interna.';
        }

        if ($isAuthenticatedSession) {
            $reasons[] = 'Resposta realizada em sessão autenticada de usuário interno.';
        }

        $severidade = ($ehIpInterno && $ehRapida) ? 'alta' : (empty($reasons) ? 'nenhuma' : 'media');

        return [
            'is_suspicious' => ! empty($reasons),
            'reasons'       => $reasons,
            'severity'      => $severidade,
        ];
    }

    private function isInternalIp(string $ip): bool
    {
        $ranges = array_merge(
            config('nps.anti_burlamento.internal_ips', []),
            config('nps.anti_burlamento.internal_cidrs', []),
            // AB-96-2 — soma (∪) os IPs/CIDRs cadastrados pela UI (tela NPS >
            // Configuração). .env continua valendo — nunca substituído.
            json_decode(Configuracao::get('nps_internal_ips', '[]'), true) ?: [],
            json_decode(Configuracao::get('nps_internal_cidrs', '[]'), true) ?: [],
        );

        if (empty($ranges)) {
            return false;
        }

        return IpUtils::checkIp($ip, $ranges);
    }

    private function windowSeconds(): int
    {
        return (int) config('nps.anti_burlamento.fast_response_window_seconds', 60);
    }

    /**
     * Texto da Regra 2 — reflete a janela configurada (nunca hardcode).
     * Janela múltipla de 60 → texto em minutos (singular "1 minuto",
     * plural "X minutos"); senão → texto em segundos.
     */
    private function motivoRespostaRapida(): string
    {
        $janela = $this->windowSeconds();

        if ($janela % 60 === 0) {
            $minutos = intdiv($janela, 60);
            $unidade = $minutos === 1 ? 'minuto' : 'minutos';

            return sprintf(
                'Resposta enviada em menos de %d %s após geração do link.',
                $minutos,
                $unidade
            );
        }

        return sprintf(
            'Resposta enviada em menos de %d segundos após geração do link.',
            $janela
        );
    }
}
