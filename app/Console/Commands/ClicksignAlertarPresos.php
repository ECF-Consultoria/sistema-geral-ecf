<?php

namespace App\Console\Commands;

use App\Models\Configuracao;
use App\Notifications\ContratoPresoNotification;
use App\Services\Contratos\ContratosPresosService;
use App\Support\AudienciaRedeSeguranca;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * Fase 130 Plano 05 (REDE-02, D-02, D-03, D-04) — o "alguém sabe" da rede de
 * segurança.
 *
 * Varre o banco local ATRÁS DE EMPRESAS PARADAS — sem nenhuma chamada HTTP
 * externa (nem à Clicksign, nem a nenhum outro serviço). Usa o recorte
 * largo dos 7 estados de `ContratosPresosService::listar()` (D-05: rascunho
 * parado, aguardando além do prazo, assinado sem liberação, recusado,
 * expirado, cancelado, erro) e avisa admin + comercial pelo sino (D-01/D-02)
 * com a causa e o link para a liberação manual.
 *
 * Cooldown (D-04): o alerta REPETE em intervalo até alguém resolver —
 * avisar uma vez só faria o aviso morrer se ninguém viu na hora, que é
 * exatamente o silêncio que esta fase existe para impedir. A janela é lida
 * de `Configuracao` (`rede_alerta_repeticao_dias`, default 3 dias) e o
 * carimbo mora em `contrato_assinaturas.ultimo_alerta_em` (Fase 130 Plano
 * 01) — SÓ é gravado depois de um envio bem-sucedido, para não "consumir" o
 * alerta sem que ninguém tenha recebido.
 */
class ClicksignAlertarPresos extends Command
{
    protected $signature = 'clicksign:alertar-presos';

    protected $description = 'Avisa admin e comercial sobre empresas que continuam sem liberação há tempo demais';

    /** Chave de `Configuracao` do intervalo de repetição do alerta (D-04). */
    public const CHAVE_REPETICAO_DIAS = 'rede_alerta_repeticao_dias';

    /**
     * Default de 3 dias entre repetições do mesmo contrato. Configurável
     * sem deploy via `Configuracao::set()`.
     */
    public const DEFAULT_REPETICAO_DIAS = 3;

    public function handle(ContratosPresosService $presos): int
    {
        $candidatos = $presos->listar();

        $intervalo = (int) Configuracao::get(self::CHAVE_REPETICAO_DIAS, self::DEFAULT_REPETICAO_DIAS);

        // Cooldown D-04, aplicado em memória: mantém o contrato só quando
        // nunca foi alertado OU o último alerta já passou do intervalo.
        $paraAlertar = $candidatos
            ->filter(fn ($contrato) => $contrato->ultimo_alerta_em === null
                || $contrato->ultimo_alerta_em->lt(now()->subDays($intervalo)))
            ->values();

        if ($paraAlertar->isEmpty()) {
            $this->info('Nenhuma empresa presa fora do cooldown de alerta — nada a enviar.');
            return self::SUCCESS;
        }

        $audiencia = AudienciaRedeSeguranca::adminsEComercial();

        if ($audiencia->isEmpty()) {
            Log::channel('ecf-webhooks')->warning('[Rede de segurança] Audiência vazia — alerta não enviado', [
                'contratos_count' => $paraAlertar->count(),
                'contrato_ids'    => $paraAlertar->pluck('id')->all(),
            ]);
            // Não marca ultimo_alerta_em: sem destinatário, o alerta não foi
            // "consumido" — a próxima execução deve tentar de novo.
            $this->warn('Audiência vazia (nenhum admin/comercial ativo) — nada enviado.');
            return self::SUCCESS;
        }

        $enviados = 0;

        foreach ($paraAlertar as $contrato) {
            try {
                Notification::send($audiencia, new ContratoPresoNotification(
                    $contrato,
                    $presos->causa($contrato),
                    $presos->diasParado($contrato),
                ));

                // SOMENTE após o envio — senão o cooldown "consome" o alerta
                // sem ninguém ter recebido.
                $contrato->update(['ultimo_alerta_em' => now()]);
                $enviados++;
            } catch (\Throwable $e) {
                // Log e continua — falha em um contrato não pode derrubar o
                // alerta dos outros (mesma disciplina de "log e continua" já
                // usada no restante do projeto).
                Log::channel('ecf-webhooks')->warning('[ClicksignAlertarPresos] Falha ao alertar contrato (segue para o próximo)', [
                    'contrato_id' => $contrato->id,
                    'company_id'  => $contrato->company_id,
                    'erro'        => $e->getMessage(),
                ]);
            }
        }

        Log::channel('ecf-webhooks')->info('[Rede de segurança] Alertas enviados', [
            'contratos'           => $enviados,
            'destinatarios_count' => $audiencia->count(),
        ]);

        $this->info("Alertas enviados para {$enviados} contrato(s), {$audiencia->count()} destinatário(s).");

        return self::SUCCESS;
    }
}
