<?php

namespace App\Console\Commands;

use App\Models\ContratoAssinaturaEvento;
use App\Support\Clicksign\ClicksignHmacVarredura;
use Illuminate\Console\Command;

/**
 * `clicksign:verificar-assinatura` — Fase 129 (D-09).
 *
 * A rota-sonda que recebe o webhook do túnel é TEMPORÁRIA e some depois da
 * medição (plano 129-02). Como a DADOS-03 obriga gravar todo evento bruto,
 * a capacidade de verificação vira este comando: pega um
 * `ContratoAssinaturaEvento` já gravado (com `raw_body` verbatim) e diz, por
 * qual das 4 fórmulas candidatas, a assinatura confere — sem superfície
 * pública, reaproveitável no cutover de produção (o secret de produção é
 * OUTRO), e testável contra evento real em vez de fixture inventado.
 *
 * Nunca imprime o secret nem o hash calculado por inteiro (T-129-05) — só
 * os 8 primeiros caracteres de cada hash como pista de diagnóstico, e o
 * veredito booleano.
 */
class ClicksignVerificarAssinatura extends Command
{
    protected $signature = 'clicksign:verificar-assinatura
                            {--evento= : ID do ContratoAssinaturaEvento a verificar}
                            {--ultimo : Usa o evento mais recente que tenha raw_body}
                            {--header= : Valor do Content-Hmac recebido, quando não estiver gravado no payload}';

    protected $description = 'Verifica, contra um evento já gravado, qual das 4 fórmulas candidatas de HMAC da Clicksign confere (gate A1, D-09)';

    public function handle(): int
    {
        $secret = (string) config('services.clicksign.webhook_secret');

        if ($secret === '') {
            $this->error('A variável de ambiente CLICKSIGN_WEBHOOK_SECRET não está configurada. Configure-a no .env antes de rodar este comando.');

            return self::FAILURE;
        }

        $evento = $this->resolverEvento();

        if ($evento === null) {
            $this->error('Nenhum evento encontrado. Use --evento=<id> ou --ultimo.');

            return self::FAILURE;
        }

        if ($evento->raw_truncado) {
            $this->error("O evento #{$evento->id} teve o corpo truncado ao ser gravado — a assinatura NÃO é reverificável a partir dele (o HMAC precisa dos bytes exatos). Este comando recusa responder para não dar um diagnóstico errado.");

            return self::FAILURE;
        }

        if ($evento->raw_body === null || $evento->raw_body === '') {
            $this->error("O evento #{$evento->id} não tem raw_body gravado — nada para verificar.");

            return self::FAILURE;
        }

        $headerRecebido = (string) ($this->option('header') ?: $evento->payload['_headers']['content-hmac'] ?? '');

        if ($headerRecebido === '') {
            $this->error("Nenhum Content-Hmac disponível — informe --header=<valor> ou verifique um evento cujo payload._headers.content-hmac tenha sido gravado pela sonda.");

            return self::FAILURE;
        }

        $calculadas = ClicksignHmacVarredura::calcularTodas($evento->raw_body, $secret);
        $veredito   = ClicksignHmacVarredura::identificarTodas($evento->raw_body, $secret, $headerRecebido);

        $linhas = [];

        foreach (ClicksignHmacVarredura::CANDIDATAS as $chave => $descricao) {
            $linhas[] = [
                $chave,
                $descricao,
                // Só os 8 primeiros caracteres do hash calculado — nunca o
                // hash inteiro nem o secret (T-129-05).
                mb_substr($calculadas[$chave], 0, 8 + strlen(ClicksignHmacVarredura::PREFIXO)) . '…',
                $veredito[$chave] ? 'SIM' : 'não',
            ];
        }

        $this->table(['Candidata', 'Descrição', 'Início do hash', 'Bate?'], $linhas);

        $vencedoras = array_keys(array_filter($veredito));

        if (count($vencedoras) === 1) {
            $chave = $vencedoras[0];
            $this->line('');
            $this->info("Candidata vencedora: \"{$chave}\".");
            $this->info("Grave esta chave em ClicksignHmacVarredura::FORMULA_CONFIRMADA (plano 129-02).");

            return self::SUCCESS;
        }

        if (count($vencedoras) > 1) {
            $this->line('');
            $this->warn('Mais de uma candidata bateu — isso não deveria acontecer (colisão inesperada). Revisar antes de fechar o gate A1.');

            return self::FAILURE;
        }

        $this->line('');
        $this->error('Nenhuma candidata bateu. Pela D-08, isso PARA a fase — abrir investigação dedicada em vez de improvisar sobre um gate bloqueante.');

        return self::FAILURE;
    }

    private function resolverEvento(): ?ContratoAssinaturaEvento
    {
        $id = $this->option('evento');

        if ($id !== null) {
            return ContratoAssinaturaEvento::find($id);
        }

        if ($this->option('ultimo')) {
            return ContratoAssinaturaEvento::whereNotNull('raw_body')
                ->orderByDesc('id')
                ->first();
        }

        return null;
    }
}
