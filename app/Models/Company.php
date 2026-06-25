<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Company extends Model
{
    use HasFactory, LogsActivity;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'cnpj', 'segment', 'active', 'status', 'notes', 'adman_account_id', 'ml_store_id', 'marketplace'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn(string $eventName) => match($eventName) {
                'created' => 'Empresa criada',
                'updated' => 'Empresa atualizada',
                'deleted' => 'Empresa excluída',
                default   => $eventName,
            });
    }

    protected $fillable = [
        'name', 'cnpj', 'adman_account_id', 'adman_store_id', 'ml_store_id',
        'cust_id_status', 'marketplace',
        'segment', 'active', 'status', 'notes', 'email_cliente', 'telefone',
        'parent_company_id', 'company_group_id', 'ml_link_generated_at', 'ml_link_url',
        // Phase 34 Plan 34-01 — info do close comercial.
        'nicho', 'dor', 'vende_ml', 'faturamento_mensal',
        'marketplaces_extras', 'email_colaborador',
        // Phase 34 Plan 34-01 — tag "Empresa nova" (D-06).
        'empresa_nova', 'empresa_nova_visto_em', 'empresa_nova_visto_por',
    ];

    protected $casts = [
        'active'               => 'boolean',
        'status'               => 'string',
        'cust_id_status'       => 'string',
        'ml_link_generated_at' => 'datetime',
        // Phase 34 Plan 34-01 — D-01 + D-09.
        'vende_ml'              => 'boolean',
        'empresa_nova'          => 'boolean',
        'marketplaces_extras'   => 'array',
        'empresa_nova_visto_em' => 'datetime',
        'faturamento_mensal'    => 'decimal:2',
        'empresa_nova_visto_por'=> 'integer',
    ];

    /**
     * ID canônico de cliente para chamadas Adman e chave de cache de faturamento.
     *
     * Prioriza `adman_account_id` sobre `ml_store_id` porque a Adman API espera
     * o ID Adman da conta. Para 99% das empresas (167/170 em 2026-06-09) os dois
     * IDs são iguais — a Adman trata o seller_id do ML como ID interno para
     * contas meli. Mas onde divergem (ex: ADHARAPRINTSHOP id=189, AVF_2K id=243),
     * passar o ml_store_id retorna HTTP 500 enquanto o adman_account_id devolve
     * os dados corretamente. O fallback para `ml_store_id` continua atendendo
     * as poucas empresas cadastradas só com ID ML.
     *
     * Histórico: a ordem original era `ml_store_id ?: adman_account_id`, criada
     * para cobrir 3 empresas que só tinham ml_store_id setado. Invertida em
     * 2026-06-09 (quick task 260609-mom) após bug em ADHARA / AVF_2K mostrar
     * que a prioridade correta é a Adman.
     *
     * Acessor único `$company->cust_id` para todos os call-sites (sync, cache
     * key, dashboards e análise de sugadores). Retorna null quando a empresa
     * não tem integração Adman/ML configurada.
     */
    public function getCustIdAttribute(): ?string
    {
        $custId = $this->adman_account_id ?: $this->ml_store_id;
        return $custId !== '' ? $custId : null;
    }

    /**
     * Empresa "ML-driven": possui token Mercado Livre ativo.
     *
     * Cutover de migração Adman → ML (Opção A): quando true, o sistema usa o
     * caminho ML (sync direto + KPIs agregados de adman_metrics) e PARA de
     * chamar a Adman para esta empresa — mesmo que ela ainda tenha
     * adman_account_id. Sem token ativo, segue 100% Adman como antes.
     *
     * Requer a relação mlToken carregada (eager) para evitar N+1.
     */
    public function getIsMlDrivenAttribute(): bool
    {
        return optional($this->mlToken)->status === 'active';
    }


    /**
     * Converte uma coleção (ou array) de Servicos em label legível, separados por vírgula.
     * verdade são os contratos ativos da empresa, não os slugs legacy.
     *
     * Aceita qualquer iterável cujos itens exponham a propriedade `nome` (típico:
     * `Servico` Eloquent ou objeto anônimo nos testes).
     *
     * Per CONTEXT.md D-09. Joiner ', ' (não mais ' + ').
     *
     * Ex: [Servico{nome:'Polos'}, Servico{nome:'Gestão'}] → 'Polos, Gestão'
     */
    public static function labelFromServicos(iterable $servicos): string
    {
        return collect($servicos)->pluck('nome')->filter()->implode(', ') ?: '—';
    }

    /**
     *
     * Phase 14 (Frente B): API estática preservada para os callers (Blades e JSX
     * fonte de verdade agora é a coleção `contratosServico` (eager-loaded ou
     * lazy via `loadMissing`).
     */
    public function getServiceTypeLabelAttribute(): string
    {
        // Garante eager loading dos contratos + servico para evitar N+1
        // quando o accessor é invocado dentro de loops (ex: Blade view de relatório).
        $this->loadMissing('contratosServico.servico');

        $servicosAtivos = $this->contratosServico
            ->where('ativo', true)
            ->pluck('servico')
            ->filter();

        return static::labelFromServicos($servicosAtivos);
    }

    public function filhas()
    {
        return $this->hasMany(Company::class, 'parent_company_id');
    }

    public function pai()
    {
        return $this->belongsTo(Company::class, 'parent_company_id');
    }

    /**
     * Grupo nomeado (tipo carteira) ao qual a empresa pertence (0 ou 1).
     * Distinto de pai()/filhas() (hierarquia matriz/filiais).
     */
    public function grupo()
    {
        return $this->belongsTo(CompanyGroup::class, 'company_group_id');
    }

    public function users()
    {
        return $this->belongsToMany(User::class, 'company_users')
            ->withPivot('role', 'assigned_at')
            ->withTimestamps();
    }

    public function consultor()
    {
        return $this->belongsToMany(User::class, 'company_users')
            ->wherePivot('role', 'consultor');
    }

    /**
     * Estrategista da empresa — antes chamado de "mentor" (renomeado em
     * 2026-05-22 quando o time da ECF mudou a nomenclatura). A pivot
     * company_users guarda role='estrategista' a partir dessa data.
     */
    public function estrategista()
    {
        return $this->belongsToMany(User::class, 'company_users')
            ->wherePivot('role', 'estrategista');
    }

    public function meetings()
    {
        return $this->hasMany(Meeting::class);
    }

    public function npsSurveys()
    {
        return $this->hasMany(NpsSurvey::class);
    }

    public function goals()
    {
        return $this->hasMany(Goal::class);
    }

    public function admanMetrics()
    {
        return $this->hasMany(AdmanMetric::class)->orderBy('reference_date', 'desc');
    }

    public function latestMetrics()
    {
        return $this->hasOne(AdmanMetric::class)->latestOfMany('reference_date');
    }

    // Todos os logs de sync Adman desta empresa
    public function admanSyncLogs()
    {
        return $this->hasMany(AdmanSyncLog::class)->orderBy('created_at', 'desc');
    }

    // Último log de sync Adman desta empresa
    public function latestAdmanSyncLog()
    {
        return $this->hasOne(AdmanSyncLog::class)->latestOfMany('created_at');
    }

    public function ppas()
    {
        return $this->hasMany(Ppa::class);
    }

    public function grants()
    {
        return $this->hasMany(CompanyGrant::class)->orderBy('created_at', 'desc');
    }

    public function sugadorConfig()
    {
        return $this->hasOne(SugadorConfig::class);
    }

    public function sugadores()
    {
        return $this->hasMany(Sugador::class);
    }

    /**
     * Contratos de serviço da empresa (Módulo Serviços — Frente A).
     *
     */
    public function contratosServico()
    {
        return $this->hasMany(ContratoServico::class);
    }

    public function mlToken()
    {
        return $this->hasOne(MlToken::class);
    }

    /**
     * Phase 41 Plan 41-01 — Cache de advertiser ML (advertiser_id/seller_id/site_id).
     * Populado pelo MercadoLivreAdsService::discoverAdvertiser (Plan 41-02).
     */
    public function mlAdvertiser()
    {
        return $this->hasOne(MlAdvertiser::class);
    }

    /**
     * Phase 41 Plan 41-01 — Config shadow/primary do path ML por empresa.
     * UI admin do Plan 41-05 escreve aqui; comando sugadores:shadow-ml
     * do Plan 41-03 le esta tabela (com fallback pro env CSV legacy).
     */
    public function sugadorMlConfig()
    {
        return $this->hasOne(SugadorMlCompanyConfig::class);
    }

    /**
     * Empresa MLB associada (Polos/Assessoria/Incubadora/Publicacao).
     *
     * Phase 35 Plan 35-01 (D-03) — usada pelo CompanyController::index para
     * excluir empresas que ja vivem em /mlb/empresas e evitar dupla contagem
     * com /companies. Empresas "puras" (Publicidade/Gestao sem mlb_empresas)
     * continuam aparecendo em /companies normalmente.
     *
     * Embora a tabela permita 1 company ter varios MlbEmpresa em teoria,
     * o fluxo atual (ComercialController::store + HubspotWebhookController)
     * cria no maximo 1 registro mlb_empresas por company — `hasOne` reflete
     * essa intencao operacional.
     */
    public function mlbEmpresa()
    {
        return $this->hasOne(MlbEmpresa::class);
    }

    /**
     * Phase 37 Plan 37-05 (REQ-37-10) — relação para flag is_origem_hubspot
     * via withExists. Empresas SEM registro em hubspot_eventos.company_id_criada
     * são legacy e NÃO geram pendência comercial na listagem Comercial.
     *
     * Usado por ComercialController::listagem para classificar a origem da
     * empresa (HubSpot vs Legacy) e gatear o cálculo das 5 pendências comerciais
     * (sem_servico, sem_valor, servico_nao_reconhecido, sem_setor, dados_close_incompletos).
     */
    public function hubspotEventoOrigem()
    {
        return $this->hasOne(HubspotEvento::class, 'company_id_criada');
    }

    /**
     * Phase 37 Plan 37-05 — todos os HubspotEventos que apontam para esta empresa.
     *
     * Usado para detectar pendência "servico_nao_reconhecido" via payload->
     * line_items_nao_mapeados (gravado pelo Plan 37-04). Eager-load opcional
     * para evitar N+1 quando a listagem itera sobre N empresas.
     */
    public function hubspotEventos()
    {
        return $this->hasMany(HubspotEvento::class, 'company_id_criada');
    }

    public function getActiveGrantAttribute(): ?CompanyGrant
    {
        return $this->grants()->where('status', 'active')->first();
    }

    public function getHasActiveGrantAttribute(): bool
    {
        return $this->grants()->where('status', 'active')->exists();
    }

    public function getAbsenteeismRateAttribute(): float
    {
        $total = $this->meetings()->where('status', 'completed')->count();
        if ($total === 0) return 0;
        $absences = $this->meetings()->where('status', 'completed')
            ->where(function ($q) {
                $q->where('consultant_present', false)->orWhere('mentor_present', false);
            })->count();
        return round(($absences / $total) * 100, 2);
    }
}
