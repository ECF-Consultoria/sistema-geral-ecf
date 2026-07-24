<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
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
        // Link pendente de autorização Shopee (espelha ml_link_*).
        'shopee_link_generated_at', 'shopee_link_url',
        // Phase 34 Plan 34-01 — info do close comercial.
        'nicho', 'dor', 'vende_ml', 'faturamento_mensal',
        'marketplaces_extras', 'email_colaborador',
        // Phase 34 Plan 34-01 — tag "Empresa nova" (D-06).
        'empresa_nova', 'empresa_nova_visto_em', 'empresa_nova_visto_por',
        // v15.5 — Mapeamento Digisac (grupo WhatsApp por empresa).
        'digisac_service_id', 'digisac_group_contact_id', 'digisac_group_name_snapshot',
        'digisac_group_mapped_at', 'digisac_group_verified_at', 'digisac_group_mapping_status',
        // Phase 111 — origem HubSpot (HUB-SCHEMA-01). Colunas nascem sem uso;
        // base de auditoria/proveniência para o handoff comercial (milestone v20.0).
        'hubspot_deal_id', 'hubspot_company_id', 'hubspot_contact_id',
        'nome_contato', 'cargo_contato', 'hubspot_domain',
        'hubspot_observacao', 'hubspot_snapshot',
    ];

    protected $casts = [
        'active'               => 'boolean',
        'status'               => 'string',
        'cust_id_status'       => 'string',
        'ml_link_generated_at' => 'datetime',
        'shopee_link_generated_at' => 'datetime',
        // Phase 34 Plan 34-01 — D-01 + D-09.
        'vende_ml'              => 'boolean',
        'empresa_nova'          => 'boolean',
        'marketplaces_extras'   => 'array',
        'empresa_nova_visto_em' => 'datetime',
        'faturamento_mensal'    => 'decimal:2',
        'empresa_nova_visto_por'=> 'integer',
        // v15.5 — Timestamps do mapeamento Digisac.
        'digisac_group_mapped_at'   => 'datetime',
        'digisac_group_verified_at' => 'datetime',
        // Phase 111 — snapshot bruto das propriedades HubSpot (HUB-SCHEMA-01).
        'hubspot_snapshot' => 'array',
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
            ->withPivot('role', 'assigned_at', 'servico_id')
            ->withTimestamps();
    }

    public function consultor()
    {
        // Leitura CONSOLIDADA do responsável (Analista de Performance).
        // ->distinct() é a apólice para a Phase 78: quando a mesma empresa+role
        // ganhar uma 2ª linha na pivot (servico_id Shopee), o servico_id NÃO está
        // no SELECT (não há withPivot('servico_id') aqui) → distinct colapsa ML+Shopee
        // para 1 responsável. Em Phase 76 (sem dupes) o resultado é idêntico ao de hoje.
        // distinct('users.id') (e não distinct() boolean): garante COUNT(DISTINCT users.id)
        // no ->count() — o distinct boolean+* compila COUNT(*) e NÃO deduplicaria.
        return $this->belongsToMany(User::class, 'company_users')
            ->wherePivot('role', 'consultor')
            ->distinct('users.id');
    }

    /**
     * Estrategista da empresa — antes chamado de "mentor" (renomeado em
     * 2026-05-22 quando o time da ECF mudou a nomenclatura). A pivot
     * company_users guarda role='estrategista' a partir dessa data.
     */
    public function estrategista()
    {
        // Consolidado + dedup defensivo (ver comentário em consultor()).
        return $this->belongsToMany(User::class, 'company_users')
            ->wherePivot('role', 'estrategista')
            ->distinct('users.id');
    }

    /**
     * Variantes SERVICE-AWARE (por servico_id) — habilitam a aba Shopee da Phase 78.
     * Os leitores consolidados atuais (carteira → bônus) NÃO usam estas relações;
     * elas existem para quando a UI precisar do responsável de um serviço específico.
     * Filtram a pivot company_users por (role, servico_id).
     */
    public function consultorDoServico(int $servicoId)
    {
        return $this->belongsToMany(User::class, 'company_users')
            ->wherePivot('role', 'consultor')
            ->wherePivot('servico_id', $servicoId);
    }

    public function estrategistaDoServico(int $servicoId)
    {
        return $this->belongsToMany(User::class, 'company_users')
            ->wherePivot('role', 'estrategista')
            ->wherePivot('servico_id', $servicoId);
    }

    /**
     * Responsável (role) de um serviço específico, incluindo o fallback
     * CONSOLIDADO (`company_users.servico_id = NULL`, que cobre QUALQUER
     * serviço da empresa) — debug `nps-assignment-consolidado` (2026-07-22).
     *
     * Diferente de `consultorDoServico()`/`estrategistaDoServico()` acima
     * (SÓ servico_id exato, nunca NULL — mantidas intactas para os
     * consumidores service-aware puros da aba Shopee/Phase 78): este método
     * é para quem precisa do responsável DE FATO daquele serviço, mesmo
     * quando ele nunca ganhou uma linha específica na pivot (empresa
     * single-service, sem o split da Phase 78 — é a maioria hoje).
     *
     * Régua igual à já usada por `CarteiraContextService::vinculosLegadoNull()`
     * (CTX-05) e pelo fallback de `NpsController::filtroPorPessoa`: linha
     * PREENCHIDA (servico_id = $servicoId) tem prioridade; se existir, a
     * linha NULL da mesma empresa+role é ignorada (nunca soma os dois —
     * evita atribuição/bônus duplicado quando a empresa JÁ tem responsável
     * específico daquele serviço, ex.: Shopee com responsável próprio +
     * consolidado ML). Se NENHUMA linha preenchida existir, cai para a NULL.
     *
     * Consumidor: `NpsSnapshotService::registrar()`/`backfillAssignments()`.
     *
     * @return \Illuminate\Support\Collection<int, User>
     */
    public function responsavelDoServicoOuConsolidado(string $role, int $servicoId): \Illuminate\Support\Collection
    {
        $especificos = $this->belongsToMany(User::class, 'company_users')
            ->wherePivot('role', $role)
            ->wherePivot('servico_id', $servicoId)
            ->distinct('users.id')
            ->get();

        if ($especificos->isNotEmpty()) {
            return $especificos;
        }

        return $this->belongsToMany(User::class, 'company_users')
            ->wherePivot('role', $role)
            ->wherePivotNull('servico_id')
            ->distinct('users.id')
            ->get();
    }

    /**
     * Analista de PERFORMANCE da empresa — nunca retorna o responsável
     * Shopee. Espelha, em granularidade de EMPRESA, as duas fontes que
     * `CarteiraContextService::forUser()` já resolve por usuário (Fase 88,
     * CTX-05): (1) `company_users.servico_id` apontando para um serviço de
     * `servicos.setor='performance'` — cobre Gestão E Mentoria, sem hardcode
     * de id (CTX-03); (2) `servico_id` NULL (ramo legado), só resolvido se a
     * empresa tiver contrato performance ATIVO — nunca promove o slot
     * consolidado a Shopee automaticamente.
     *
     * Consumidor exclusivo desta fase (89-02, CART-08): `CompanyController::
     * index()`/`show()`. Os leitores consolidados (`consultor()`/
     * `estrategista()`) continuam servindo os 9 call-sites de bônus/
     * notificação/NPS — não substituídos aqui.
     *
     * `->distinct('users.id')` preservado pelo mesmo motivo de `consultor()`
     * acima: garante `COUNT(DISTINCT users.id)`, não `COUNT(*)`.
     */
    public function analistaPerformance()
    {
        return $this->belongsToMany(User::class, 'company_users')
            ->withPivot('assigned_at')
            ->wherePivot('role', 'consultor')
            ->where(function ($q) {
                $q->whereIn('company_users.servico_id', function ($sub) {
                    $sub->select('id')->from('servicos')->where('setor', Servico::SETOR_PERFORMANCE);
                })->orWhere(function ($q2) {
                    $q2->whereNull('company_users.servico_id')
                       ->whereExists(function ($sub2) {
                           $sub2->select(DB::raw(1))
                                ->from('contratos_servico as ct')
                                ->join('servicos as s2', 's2.id', '=', 'ct.servico_id')
                                ->whereColumn('ct.company_id', 'company_users.company_id')
                                ->where('ct.ativo', true)
                                ->where('s2.setor', Servico::SETOR_PERFORMANCE);
                       });
                });
            })
            ->distinct('users.id');
    }

    /**
     * Estrategista de PERFORMANCE da empresa — idêntico a
     * `analistaPerformance()`, filtrando pelo papel 'estrategista'.
     */
    public function estrategistaPerformance()
    {
        return $this->belongsToMany(User::class, 'company_users')
            ->withPivot('assigned_at')
            ->wherePivot('role', 'estrategista')
            ->where(function ($q) {
                $q->whereIn('company_users.servico_id', function ($sub) {
                    $sub->select('id')->from('servicos')->where('setor', Servico::SETOR_PERFORMANCE);
                })->orWhere(function ($q2) {
                    $q2->whereNull('company_users.servico_id')
                       ->whereExists(function ($sub2) {
                           $sub2->select(DB::raw(1))
                                ->from('contratos_servico as ct')
                                ->join('servicos as s2', 's2.id', '=', 'ct.servico_id')
                                ->whereColumn('ct.company_id', 'company_users.company_id')
                                ->where('ct.ativo', true)
                                ->where('s2.setor', Servico::SETOR_PERFORMANCE);
                       });
                });
            })
            ->distinct('users.id');
    }

    /** Fase 108 — histórico de entrada/saída de responsáveis (analista/estrategista). */
    public function managerHistory()
    {
        return $this->hasMany(CompanyManagerHistory::class)->latest();
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

    // Token do app ERP (Order/Payment/escrow) — é o "principal" da empresa na
    // Shopee (métrica de faturamento e pivot company_marketplaces vêm daqui).
    public function shopeeToken()
    {
        return $this->hasOne(ShopeeToken::class)->where('app', 'erp');
    }

    // Token do app ADS (performance de anúncios) — mesmo shop_id, app separado.
    public function shopeeAdsToken()
    {
        return $this->hasOne(ShopeeToken::class)->where('app', 'ads');
    }

    // Todos os tokens Shopee da empresa (erp + ads).
    public function shopeeTokens()
    {
        return $this->hasMany(ShopeeToken::class);
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

    // ═══ Helpers Multi-Marketplace (Phase 57 v13.0) ═══════════════════════
    // Formalizacao do modelo N:N Company <-> Marketplace via tabela pivot
    // `company_marketplaces`. Consumidores existentes (AdmanService, Sugadores,
    // comandos de diagnostico) continuam usando `$company->adman_account_id`
    // e `$company->ml_store_id` sem refactor — accessors abaixo fazem sync
    // entre a pivot (fonte-de-verdade) e as colunas flat (backup/fallback).
    //
    // @see .planning/adrs/DATA-01-multi-marketplace-model.md

    /**
     * Relacionamento HasMany com a tabela pivot company_marketplaces.
     */
    public function marketplaces(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(CompanyMarketplace::class);
    }

    /**
     * Verifica se a empresa esta em um marketplace ATIVO.
     */
    public function isInMarketplace(string $slug): bool
    {
        return $this->marketplaces()
            ->where('marketplace', $slug)
            ->where('active', true)
            ->exists();
    }

    /**
     * Retorna colecao dos slugs dos marketplaces ativos da empresa.
     * Use ->toArray() ou ->all() para lista simples.
     */
    public function marketplacesAtivos(): \Illuminate\Support\Collection
    {
        return $this->marketplaces()
            ->where('active', true)
            ->pluck('marketplace');
    }

    /**
     * Retorna o slug do marketplace primario da empresa (ex-companies.marketplace).
     * Fallback pra coluna flat quando pivot vazia (durante migracao gradual).
     */
    public function primaryMarketplace(): ?string
    {
        $primary = $this->marketplaces()
            ->where('is_primary', true)
            ->value('marketplace');

        return $primary ?? ($this->attributes['marketplace'] ?? null);
    }

    /**
     * Retorna o store_id (ID nativo do seller) do marketplace especificado.
     * Retorna null se a empresa nao estiver no marketplace.
     */
    public function storeIdFor(string $slug): ?string
    {
        return $this->marketplaces()
            ->where('marketplace', $slug)
            ->value('store_id');
    }

    // ═══ Accessors legacy com fallback (Phase 57 v13.0) ═══════════════════
    // Consumidores existentes leem `$company->adman_account_id` e
    // `$company->ml_store_id`. Accessor le da pivot (row meli primary);
    // fallback pra coluna flat quando pivot esta vazia (migracao parcial).

    public function getAdmanAccountIdAttribute(): ?string
    {
        if ($this->exists) {
            $row = $this->marketplaces()->where('marketplace', 'meli')->first();
            if ($row && $row->adman_id !== null) {
                return $row->adman_id;
            }
        }
        return $this->attributes['adman_account_id'] ?? null;
    }

    public function getMlStoreIdAttribute(): ?string
    {
        if ($this->exists) {
            $row = $this->marketplaces()->where('marketplace', 'meli')->first();
            if ($row && $row->store_id !== null) {
                return $row->store_id;
            }
        }
        return $this->attributes['ml_store_id'] ?? null;
    }

    /**
     * Mutator: escreve em ambos pivot + coluna flat para consistencia.
     * Guard `$this->exists` evita tentativa de criar pivot antes do save().
     */
    public function setAdmanAccountIdAttribute(?string $value): void
    {
        $this->attributes['adman_account_id'] = $value;
        if ($this->exists) {
            $this->marketplaces()->updateOrCreate(
                ['marketplace' => 'meli'],
                ['adman_id' => $value, 'is_primary' => true, 'active' => true]
            );
        }
    }

    public function setMlStoreIdAttribute(?string $value): void
    {
        $this->attributes['ml_store_id'] = $value;
        if ($this->exists) {
            $this->marketplaces()->updateOrCreate(
                ['marketplace' => 'meli'],
                ['store_id' => $value, 'is_primary' => true, 'active' => true]
            );
        }
    }
}
