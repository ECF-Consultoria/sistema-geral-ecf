<?php

namespace App\Console\Commands;

use App\Models\Configuracao;
use App\Notifications\VarreduraParadaNotification;
use App\Support\AudienciaRedeSeguranca;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * `clicksign:verificar-varredura` — Fase 130, plano 130-06 (REDE-04, D-09).
 * A rede de segurança vigiando a si mesma: acusa quando `clicksign:reconciliar`
 * (plano 130-03) parou de rodar, nunca rodou, ou rodou e terminou com erro.
 *
 * **Limitação declarada, não escondida (D-09):** esta checagem cobre "o
 * comando `clicksign:reconciliar` especificamente parou de rodar" — bug,
 * remoção acidental do agendamento, ou exceção antes de gravar o carimbo. Ela
 * **NÃO** cobre o cenário do agendador do sistema operacional (`cron` +
 * `schedule:run`) parar de disparar por inteiro, porque nesse caso este
 * próprio comando de verificação também deixa de rodar — é limitação
 * ESTRUTURAL de qualquer verificação agendada, não um detalhe de
 * implementação. Monitorar o agendador do SO é responsabilidade de
 * infraestrutura, fora do escopo desta fase. Fechar esse cenário exigiria
 * mover o check para fora do cron (ex.: um hook em `HandleInertiaRequests`
 * checando o carimbo a cada requisição autenticada), o que não foi feito
 * aqui.
 */
class ClicksignVerificarVarredura extends Command
{
    protected $signature = 'clicksign:verificar-varredura';

    protected $description = 'Acusa quando a verificação automática de contratos parou de rodar';

    /**
     * Chave de `Configuracao` do limiar de horas sem execução antes de
     * alertar.
     */
    public const CHAVE_LIMIAR_HORAS = 'rede_varredura_limiar_horas';

    /**
     * Default de 26h — a varredura roda 1x/dia (ciclo de 24h); 26h dá 2h de
     * folga sobre esse ciclo antes de considerar "atrasada", evitando falso
     * positivo por variação normal de horário de execução do cron.
     */
    public const DEFAULT_LIMIAR_HORAS = 26;

    /** Chave de `Configuracao` do cooldown de repetição deste próprio alerta. */
    public const CHAVE_ULTIMO_ALERTA = 'rede_varredura_ultimo_alerta_em';

    public function handle(): int
    {
        $limiarHoras = (int) Configuracao::get(self::CHAVE_LIMIAR_HORAS, self::DEFAULT_LIMIAR_HORAS);

        $statusJson = Configuracao::get('clicksign_reconciliacao_status');
        // JSON inválido (corrompido) é tratado como "sem carimbo" — nunca
        // deixar json_decode derrubar o comando (T-130-06-01).
        $status = $statusJson ? json_decode($statusJson, true) : null;
        $status = is_array($status) ? $status : null;

        $executadoEm = isset($status['executado_em']) ? Carbon::parse($status['executado_em']) : null;
        $erro        = $status['erro'] ?? null;

        $deveAlertar = $executadoEm === null
            || $executadoEm->lt(now()->subHours($limiarHoras))
            || filled($erro);

        if (! $deveAlertar) {
            $this->info('Verificação automática de contratos em dia — nada a alertar.');

            return self::SUCCESS;
        }

        // Cooldown simples (T-130-06-03): o comando roda 1x/dia, mas o
        // cooldown protege contra execução manual repetida virando ruído.
        $ultimoAlertaJson = Configuracao::get(self::CHAVE_ULTIMO_ALERTA);
        if ($ultimoAlertaJson !== null && Carbon::parse($ultimoAlertaJson)->gt(now()->subHours($limiarHoras))) {
            $this->info('Alerta de varredura parada já enviado recentemente — dentro do cooldown, nada reenviado.');

            return self::SUCCESS;
        }

        $audiencia = AudienciaRedeSeguranca::adminsEComercial();

        if ($audiencia->isEmpty()) {
            Log::channel('ecf-webhooks')->warning('[ClicksignVerificarVarredura] Audiência vazia — alerta de varredura parada não enviado', [
                'executado_em' => $executadoEm?->toIso8601String(),
                'erro'         => $erro,
            ]);

            $this->warn('Audiência vazia (nenhum admin/comercial ativo) — nada enviado.');

            return self::SUCCESS;
        }

        Notification::send($audiencia, new VarreduraParadaNotification(
            $executadoEm?->toIso8601String(),
            $erro,
        ));

        // Grava o cooldown SOMENTE após o envio — mesma disciplina do
        // ClicksignAlertarPresos (nunca "consumir" o alerta sem destinatário.
        Configuracao::set(self::CHAVE_ULTIMO_ALERTA, now()->toIso8601String());

        Log::channel('ecf-webhooks')->info('[ClicksignVerificarVarredura] Alerta de varredura parada enviado', [
            'executado_em'        => $executadoEm?->toIso8601String(),
            'erro'                => $erro,
            'destinatarios_count' => $audiencia->count(),
        ]);

        $this->info("Alerta de varredura parada enviado para {$audiencia->count()} destinatário(s).");

        return self::SUCCESS;
    }
}
