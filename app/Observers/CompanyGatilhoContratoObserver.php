<?php

namespace App\Observers;

use App\Models\Company;
use App\Services\Contratos\GatilhoContratoAdministrativoService;

/**
 * CompanyGatilhoContratoObserver — Fase 128 (plano 05), fecha a D-04.
 *
 * Por que existe: até a Fase 131 não existe botão manual para reavaliar
 * contrato. Sem este Observer, uma empresa que ficou `aguardando_comercial`
 * (pendência `sem_contato`, `sem_setor` etc. — ver `PendenciasComerciaisService`)
 * fica presa PARA SEMPRE assim que o Comercial corrige o dado que faltava,
 * porque nada volta a chamar `GatilhoContratoAdministrativoService::dispararSeElegivel()`.
 *
 * Lista FIXA de campos-gatilho (`CAMPOS_GATILHO`), derivada do que os dois
 * portões do gate realmente consultam em `Company`
 * (`ContratoDadosMinimosService::faltantes()` + `PendenciasComerciaisService::
 * calcularUniversais()`): `email_cliente`, `cnpj`, `nome_contato`.
 *
 * ⚠️ `hubspot_notas`/`hubspot_snapshot` estão DELIBERADAMENTE fora da lista:
 * o webhook grava esses dois campos a cada replay de evento
 * (`HubspotWebhookController::criarEmpresa()`), e sem `wasChanged()`
 * restrito à lista fixa, todo replay tentaria reavaliar o gate de novo —
 * ruído puro e superfície de laço desnecessária (T-128-12).
 *
 * Proteção contra laço é redundante DE PROPÓSITO, em 4 camadas:
 * 1. Aqui — `wasChanged()` restrito à lista fixa (primeira linha do método).
 * 2. `GatilhoContratoAdministrativoService::dispararSeElegivel()` — guard
 *    estático de reentrância por `company_id`, indexado por request.
 * 3. `GatilhoContratoAdministrativoService::avaliar()` —
 *    `ContratoAssinatura::emAndamentoDaEmpresa()`.
 * 4. Trava composta no banco (Fase 127) — última linha de defesa contra
 *    concorrência real entre workers.
 *
 * **Não** implementa `created()`: no `created()` a empresa ainda não tem
 * nenhum `ContratoServico` (contratos nascem depois, na mesma transação),
 * então o gate sempre devolveria `sem_servico` — ruído puro. O caminho de
 * criação já é coberto pela chamada explícita dos controllers (plano 04).
 */
class CompanyGatilhoContratoObserver
{
    /**
     * Únicos campos de `Company` que os dois portões do gate administrativo
     * consultam. Editar qualquer OUTRO campo (status, notas internas,
     * hubspot_notas/hubspot_snapshot etc.) não dispara reavaliação nenhuma.
     */
    public const CAMPOS_GATILHO = ['email_cliente', 'cnpj', 'nome_contato'];

    public function updated(Company $company): void
    {
        if (! $company->wasChanged(self::CAMPOS_GATILHO)) {
            return;
        }

        app(GatilhoContratoAdministrativoService::class)->dispararSeElegivel($company);
    }
}
