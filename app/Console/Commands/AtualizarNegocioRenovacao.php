<?php

namespace App\Console\Commands;

use App\Models\ContratoRenovacao;
use App\Services\Hubspot\HubspotErroApi;
use App\Services\Renovacao\RenovacaoOrquestrador;
use Illuminate\Console\Command;

/**
 * Renovação (09/11/2026) — reaplica o conteúdo legível (nome, descrição, data
 * de fechamento, dados do cliente) e acrescenta a observação num negócio de
 * renovação que o PRÓPRIO sistema criou. Nunca mexe em etapa, pipeline ou
 * dono, e recusa negócio que não nasceu aqui (sem evento `negocio_criado`).
 */
class AtualizarNegocioRenovacao extends Command
{
    protected $signature = 'renovacao:atualizar-negocio {ciclo : id do ciclo (contrato_renovacoes.id)}';

    protected $description = 'Reaplica nome/descrição/observação legíveis num negócio de renovação criado pelo sistema';

    public function handle(RenovacaoOrquestrador $orquestrador): int
    {
        $ciclo = ContratoRenovacao::with('company', 'servico')->find((int) $this->argument('ciclo'));

        if (! $ciclo || ! $ciclo->negocio_renovacao_id) {
            $this->error('Ciclo não encontrado ou sem negócio de renovação.');

            return self::FAILURE;
        }

        $criadoAqui = $ciclo->eventos()
            ->where('tipo', 'negocio_criado')
            ->get()
            ->contains(fn ($ev) => (string) ($ev->dados['negocio_renovacao_id'] ?? '') === (string) $ciclo->negocio_renovacao_id);

        if (! $criadoAqui) {
            $this->error("O negócio {$ciclo->negocio_renovacao_id} não foi criado pelo ECF Admin — não mexo.");

            return self::FAILURE;
        }

        try {
            $nota = $orquestrador->reaplicarConteudo($ciclo);
        } catch (HubspotErroApi $e) {
            $this->error('HubSpot recusou a atualização: ' . mb_substr($e->getMessage(), 0, 300));

            return self::FAILURE;
        }

        $this->info("Negócio {$ciclo->negocio_renovacao_id} atualizado" . ($nota ? "; observação {$nota} criada." : '; a observação falhou (ver log).'));

        return $nota ? self::SUCCESS : self::FAILURE;
    }
}
