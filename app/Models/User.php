<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class User extends Authenticatable
{
    use HasFactory, Notifiable, LogsActivity, SoftDeletes;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'email', 'role', 'setor', 'cargo', 'active', 'publication_role'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn(string $eventName) => match($eventName) {
                'created' => 'Usuário criado',
                'updated' => 'Usuário atualizado',
                'deleted' => 'Usuário excluído',
                default   => $eventName,
            });
    }

    protected $fillable = [
        'name', 'email', 'password', 'role', 'setor', 'cargo', 'created_by', 'active', 'phone',
        'publication_role', 'publication_meta', 'publication_permissions',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at'      => 'datetime',
            'password'               => 'hashed',
            'active'                 => 'boolean',
            'publication_permissions' => 'array',
        ];
    }

    // Permissões padrão por papel (quando publication_permissions é null)
    private const DEFAULT_PUB_PERMISSIONS = [
        'gestor'     => ['dashboard', 'meu_painel', 'empresas', 'historico', 'treinamento', 'projetos', 'sugadores'],
        'lider'      => ['dashboard', 'meu_painel', 'publicacoes', 'vendas', 'historico', 'revisao', 'empresas', 'treinamento', 'projetos', 'sugadores'],
        'publicador' => ['meu_painel', 'publicacoes', 'vendas', 'historico', 'projetos'],
        'analista'   => ['empresas', 'historico', 'projetos', 'sugadores'],
    ];

    public const ALL_PUB_PERMISSIONS = [
        'treinamento', 'meu_painel', 'publicacoes', 'vendas',
        'historico', 'revisao', 'empresas', 'dashboard', 'projetos', 'sugadores',
    ];

    public function isAdmin(): bool { return $this->role === 'admin'; }
    public function isConsultor(): bool { return $this->role === 'consultor'; }
    public function isMentor(): bool { return $this->role === 'mentor'; }

    public function isGestor(): bool { return $this->publication_role === 'gestor'; }
    public function isLiderPub(): bool { return $this->publication_role === 'lider'; }
    public function isPublicador(): bool { return $this->publication_role === 'publicador'; }
    public function hasPublicationRole(): bool { return !is_null($this->publication_role); }

    public function hasPubPermission(string $perm): bool
    {
        if ($this->isAdmin()) return true;
        if (!$this->publication_role) return false;

        $perms = $this->publication_permissions;
        if ($perms !== null) {
            return in_array($perm, $perms);
        }

        return in_array($perm, self::DEFAULT_PUB_PERMISSIONS[$this->publication_role] ?? []);
    }

    public function companies()
    {
        return $this->belongsToMany(Company::class, 'company_users')
            ->withPivot('role', 'assigned_at')
            ->withTimestamps();
    }

    public function consultorCompanies()
    {
        return $this->belongsToMany(Company::class, 'company_users')
            ->wherePivot('role', 'consultor')
            ->withPivot('role', 'assigned_at');
    }

    public function mentorCompanies()
    {
        return $this->belongsToMany(Company::class, 'company_users')
            ->wherePivot('role', 'mentor')
            ->withPivot('role', 'assigned_at');
    }

    public function generatedSurveys()
    {
        return $this->hasMany(NpsSurvey::class, 'generated_by');
    }

    public function ppas()
    {
        return $this->hasMany(Ppa::class, 'mentor_id');
    }

    public function portfolioGoals()
    {
        return $this->hasMany(PortfolioGoal::class);
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
