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
            ->logOnly(['name', 'cnpj', 'segment', 'active', 'status', 'notes', 'adman_account_id', 'ml_store_id'])
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
        'segment', 'active', 'status', 'notes',
        'parent_company_id',
    ];

    protected $casts = [
        'active'               => 'boolean',
        'status'               => 'string',
        'ml_link_generated_at' => 'datetime',
    ];

    /**
     * ID canônico de cliente para chamadas Adman e chave de cache de faturamento.
     *
     * Por que existir: o codebase usava `ml_store_id ?: adman_account_id` em alguns
     * call-sites (AdmanService::syncCompany, AdminController::fechamento) e apenas
     * `adman_account_id` em outros (DashboardController, RefreshGrossBillingCacheJob,
     * CompanyController::show). Esse desalinhamento produzia:
     *  - empresas com apenas `ml_store_id` saindo zeradas do dashboard;
     *  - cache miss perpétuo no Fechamento (job warm-a por adman_account_id,
     *    controller lê por ml_store_id) → mistura cache hit + DB SUM → oscilação.
     *
     * Acessor único `$company->cust_id` para todos os call-sites. Retorna null
     * quando a empresa não tem integração Adman/ML configurada.
     */
    public function getCustIdAttribute(): ?string
    {
        $custId = $this->ml_store_id ?: $this->adman_account_id;
        return $custId !== '' ? $custId : null;
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
