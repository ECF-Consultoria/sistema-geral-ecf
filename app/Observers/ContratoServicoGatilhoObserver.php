<?php

namespace App\Observers;

use App\Models\ContratoServico;
use App\Services\Contratos\GatilhoContratoAdministrativoService;
use Illuminate\Support\Facades\DB;

/**
 * ContratoServicoGatilhoObserver — Fase 128 (plano 05), complementa a D-04.
 *
 * Por que existe: criar/editar um `ContratoServico` NÃO é um `save()` de
 * `Company` — o `CompanyGatilhoContratoObserver` não cobre "o Comercial
 * adicionou o serviço que faltava" nem "corrigiu o valor zerado". Este
 * segundo gancho fecha esse buraco num ponto só, em vez de instrumentar os
 * dois call sites que criam `ContratoServico` hoje (`persistirContratos()`
 * no webhook e o `foreach` de `ComercialController::store()`).
 *
 * Lista FIXA de campos-gatilho (`CAMPOS_GATILHO`), derivada do que
 * `ContratoDadosMinimosService::faltantes()` e `PendenciasComerciaisService::
 * calcularUniversais()` realmente consultam em cada `ContratoServico` ativo.
 *
 * ⚠️ Interação com o plano 04 (T-128-13): ao cadastrar uma empresa pelo
 * Comercial, `ContratoServico::create()` acontece DENTRO da transação de
 * `ComercialController::store()`. Sem cuidado, o `created()` deste Observer
 * rodaria o gate DENTRO da transação — reintroduzindo o Pitfall 1 (I/O
 * síncrono à Clicksign atrás de um lock de linha aberto). Por isso o corpo
 * de `created()` roda via `DB::afterCommit()`: dentro de transação, só
 * reavalia depois do commit; fora de transação, roda na hora (comportamento
 * nativo do `DB::afterCommit()` quando não há transação ativa).
 *
 * Como o `afterCommit()` roda DEPOIS da chamada explícita que o próprio
 * controller já faz (plano 04), o guard estático de reentrância do
 * orquestrador NÃO impede as duas chamadas nesse cenário (elas não são
 * concorrentes na mesma execução — a segunda chega depois que a primeira já
 * terminou e limpou o guard no `finally`). Quem garante que não nasce um
 * segundo `ContratoAssinatura` é `ContratoAssinatura::emAndamentoDaEmpresa()`
 * (camada 3) — a suíte de testes desta fase prova isso por contagem.
 */
class ContratoServicoGatilhoObserver
{
    /**
     * Únicos campos de `ContratoServico` que os dois portões do gate
     * administrativo consultam.
     */
    public const CAMPOS_GATILHO = ['ativo', 'valor_contratado', 'data_contratacao', 'servico_id'];

    public function created(ContratoServico $contrato): void
    {
        DB::afterCommit(function () use ($contrato) {
            $this->reavaliar($contrato);
        });
    }

    public function updated(ContratoServico $contrato): void
    {
        if (! $contrato->wasChanged(self::CAMPOS_GATILHO)) {
            return;
        }

        $this->reavaliar($contrato);
    }

    private function reavaliar(ContratoServico $contrato): void
    {
        $company = $contrato->company;

        if ($company === null) {
            return;
        }

        // Recarrega o estado recém-gravado (não uma coleção em cache) —
        // o gate precisa ler os ContratoServico atuais da empresa, não o
        // snapshot que existia antes deste create/update.
        $company->load('contratosServico.servico');

        app(GatilhoContratoAdministrativoService::class)->dispararSeElegivel($company);
    }
}
