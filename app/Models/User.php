<?php

namespace App\Models;

use App\Support\Permissions;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
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
            ->logOnly(['name', 'email', 'role', 'active'])
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
        'name', 'email', 'password', 'role', 'created_by', 'active', 'phone',
        // Colunas legacy mantidas pra retrocompat até cleanup pós-validação
        'setor_legacy', 'cargo_legacy',
        'publication_role_legacy', 'publication_meta_legacy', 'publication_permissions_legacy',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at'              => 'datetime',
            'password'                       => 'hashed',
            'active'                         => 'boolean',
            'publication_permissions_legacy' => 'array',
        ];
    }

    /**
     * Permissões agregadas durante uma única request, evitando reconsultar o banco
     * em cada chamada de hasPermission(). Reset implicitamente entre requests.
     *
     * @var array<int,string>|null
     */
    protected ?array $effectivePermissionsCache = null;

    // ─── Role do sistema (admin/consultor/mentor) — MANTIDO ──────────────────
    public function isAdmin(): bool    { return $this->role === 'admin'; }
    public function isConsultor(): bool { return $this->role === 'consultor'; }
    public function isMentor(): bool   { return $this->role === 'mentor'; }

    // ─── Relacionamentos: setores e cargos ───────────────────────────────────

    /** Setores em que o user é MEMBRO (com cargo no pivot). */
    public function setores(): BelongsToMany
    {
        return $this->belongsToMany(Setor::class, 'user_setores')
            ->withPivot('cargo_id', 'is_principal', 'assigned_at')
            ->withTimestamps();
    }

    /** Setores em que o user é LÍDER (não precisa ser membro). */
    public function setoresLiderados(): BelongsToMany
    {
        return $this->belongsToMany(Setor::class, 'setor_lideres')
            ->withPivot('assigned_by', 'assigned_at')
            ->withTimestamps();
    }

    /** Setor marcado como is_principal (1º que aparecer; deveria ser único). */
    public function setorPrincipal(): ?Setor
    {
        return $this->setores()->wherePivot('is_principal', true)->first();
    }

    public function isLider(): bool
    {
        return $this->setoresLiderados()->exists();
    }

    public function isLiderDe(int $setorId): bool
    {
        return $this->setoresLiderados()->where('setores.id', $setorId)->exists();
    }

    // ─── Sistema de permissões ───────────────────────────────────────────────

    /**
     * Permission check único do sistema. Resolve via união das permissões dos
     * setores em que o user é membro + pacote automático de líder (se aplicável).
     */
    public function hasPermission(string $key): bool
    {
        if ($this->isAdmin()) return true; // short-circuit superuser

        return \in_array($key, $this->effectivePermissions(), true);
    }

    /**
     * Lista completa de permissões deste user. Cacheada por request — invalidar
     * via `$user->refresh()` se mudar setores/permissões na mesma request.
     *
     * @return array<int,string>
     */
    public function effectivePermissions(): array
    {
        if ($this->effectivePermissionsCache !== null) {
            return $this->effectivePermissionsCache;
        }

        if ($this->isAdmin()) {
            return $this->effectivePermissionsCache = Permissions::all();
        }

        // União: permissões de todos os setores membros + lideranca.* se for líder
        $keys = \App\Models\SetorPermissao::query()
            ->whereIn('setor_id', $this->setores()->pluck('setores.id'))
            ->pluck('permission_key')
            ->unique()
            ->values()
            ->all();

        if ($this->isLider()) {
            $keys = array_values(array_unique(array_merge($keys, Permissions::AUTO_LIDERANCA)));
        }

        return $this->effectivePermissionsCache = $keys;
    }

    // ─── Carteira (company_users) — sem mudança ──────────────────────────────

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

    /**
     * Empresas em que este user é o Estrategista — antes chamado de "mentor"
     * (renomeado em 2026-05-22). Filtra pivot role='estrategista'.
     */
    public function estrategistaCompanies()
    {
        return $this->belongsToMany(Company::class, 'company_users')
            ->wherePivot('role', 'estrategista')
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

    // ─── Helpers legacy (mantidos pra retrocompat — derivam dos novos dados) ─

    /**
     * Retorna true se o user pertence ao setor "Publicação" (qualquer cargo).
     * Usado por código antigo que checava `hasPublicationRole()`.
     */
    public function hasPublicationRole(): bool
    {
        return $this->setores()->where('slug', 'publicacao')->exists();
    }

    /** Substituto de hasPubPermission() — mapeia old keys (sem prefixo) pras novas. */
    public function hasPubPermission(string $perm): bool
    {
        return $this->hasPermission("mlb.{$perm}");
    }

    public function isGestor(): bool
    {
        return $this->setores()
            ->wherePivot('cargo_id', '!=', null)
            ->whereHas('cargos', fn($q) => $q->where('slug', 'gestor-de-publicacao'))
            ->exists();
    }

    public function isLiderPub(): bool
    {
        return $this->setores()
            ->whereHas('cargos', fn($q) => $q->where('slug', 'lider-de-publicacao'))
            ->exists();
    }

    public function isPublicador(): bool
    {
        return $this->setores()
            ->whereHas('cargos', fn($q) => $q->where('slug', 'publicador'))
            ->exists();
    }
}
