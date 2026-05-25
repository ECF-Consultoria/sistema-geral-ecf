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
            ->logOnly(['name', 'cnpj', 'segment', 'active', 'status', 'notes', 'adman_account_id', 'ml_store_id', 'service_type', 'contract_start', 'contract_end'])
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
        'service_type', 'contract_type', 'contract_start', 'contract_end',
        'additional_service', 'additional_service_price',
        'parent_company_id',
    ];

    protected $casts = [
        'active'         => 'boolean',
        'status'         => 'string',
        'contract_start' => 'date:Y-m-d',
        'contract_end'   => 'date:Y-m-d',
    ];

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
