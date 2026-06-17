<?php

namespace App\Notifications;

use App\Models\Company;

/**
 * Phase 35 Plan 35-03 — Notificação disparada quando o webhook do HubSpot
 * cria uma empresa nova MAS o close veio com campos faltando (pendências
 * que o Comercial precisa completar manualmente).
 *
 * Subclasse concreta de `BaseNotification` — mesmo padrão arquitetural de
 * `EmpresaCadastradaNotification` (Phase 14) e `AlertaEcfNotification` (Phase 29).
 *
 * Categoria fixa = MANUAL (não há categoria especifica de hubspot no enum
 * do MVP v3.0; `Categoria::MANUAL` cobre notificações originadas por evento
 * de sistema que pedem ação humana). Adicionar nova categoria está em
 * Deferred Ideas — fora do escopo da Phase 35.
 *
 * Audiência: resolvida em `App\Support\AudienciaComercial::lideresEPermissionados()`
 * (líderes do setor Comercial + users com permission `comercial.cadastrar_empresa`).
 *
 * Conteúdo:
 *   - title: "Empresa nova via HubSpot com pendências: {nome}"
 *   - body:  "Pendências: sem responsável, sem cust ID, ..."
 *   - link:  /companies/{id} (ficha da empresa)
 *   - meta:  ['company_id' => int, 'pendencias' => array<string>, 'fonte' => 'hubspot']
 *
 * NÃO sobrescreve `via()` nem `toArray()` — canal único `database` e payload
 * canônico de 6 chaves vêm 100% do `BaseNotification`.
 */
class EmpresaHubspotPendenteNotification extends BaseNotification
{
    /**
     * Mapa slug→label humanizado pt-BR das pendências. Espelha as chaves
     * usadas em `CompanyController::index` (linhas 134-141) — duplicação
     * consciente para desacoplar texto da listagem do texto da notificação.
     */
    private const LABELS_PENDENCIAS = [
        'sem_responsavel'       => 'sem responsável',
        'sem_cust_id'           => 'sem cust ID',
        'sem_email_colaborador' => 'sem email colaborador',
        'sem_servico'           => 'sem serviço',
    ];

    /**
     * Constrói a notificação de pendência pós-webhook HubSpot.
     *
     * @param  Company        $company     Empresa criada pelo webhook HubSpot
     * @param  array<string>  $pendencias  Lista de slugs (ex: ['sem_responsavel', 'sem_servico'])
     */
    public function __construct(Company $company, array $pendencias)
    {
        $pendenciasHumanizadas = collect($pendencias)
            ->map(fn (string $slug) => self::LABELS_PENDENCIAS[$slug] ?? $slug)
            ->implode(', ');

        parent::__construct(
            titulo:      "Empresa nova via HubSpot com pendências: {$company->name}",
            mensagem:    "Pendências: {$pendenciasHumanizadas}",
            categoria:   Categoria::MANUAL,
            autorUserId: null, // Sistema (webhook) — sem autor humano
            url:         route('companies.show', $company->id),
            meta:        [
                'company_id' => $company->id,
                'pendencias' => array_values($pendencias),
                'fonte'      => 'hubspot',
            ],
        );
    }
}
