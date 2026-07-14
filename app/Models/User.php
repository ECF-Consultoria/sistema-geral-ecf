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

    /**
     * Slug do cargo de Desempenho (`estrategista` | `analista`) via
     * `user_setores → cargos` — fonte CANÔNICA desde a quick 260610-f69,
     * a mesma usada pelo ranking em PerformanceController.
     *
     * 2026-07-13 — criado para corrigir a escolha da DIMENSÃO do NPS no
     * desempenho: antes usava-se `isMentor()` (role do sistema), mas
     * estrategistas NÃO têm role='mentor' → caíam na dimensão 'analista'
     * (bug: estrategista e analista da mesma empresa recebiam a mesma nota).
     * As notas de NPS são POR DIMENSÃO/cargo, individuais.
     *
     * Se o user tem os 2 cargos, prefere o marcado is_principal. Retorna null
     * quando não há cargo analista/estrategista atribuído — o consumidor cai
     * no fallback histórico (isMentor()).
     */
    public function cargoDesempenhoSlug(): ?string
    {
        return \Illuminate\Support\Facades\DB::table('user_setores as us')
            ->join('cargos as c', 'c.id', '=', 'us.cargo_id')
            ->where('us.user_id', $this->id)
            ->whereIn('c.slug', ['analista', 'estrategista'])
            ->orderByDesc('us.is_principal')
            ->value('c.slug');
    }

    /**
     * Dimensão de NPS do user no módulo Desempenho: 'estrategista' ou
     * 'analista'. Deriva do cargo canônico; fallback para isMentor() quando
     * não há cargo atribuído (espelha PerformanceController linha 99).
     */
    public function dimensaoNpsDesempenho(): string
    {
        $cargo = $this->cargoDesempenhoSlug() ?? ($this->isMentor() ? 'estrategista' : 'analista');
        return $cargo === 'estrategista' ? 'estrategista' : 'analista';
    }

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

        // União: permissões de todos os setores membros + lideranca.* se for líder.
        // Filtramos lideranca.* das permissions derivadas de SetorPermissao —
        // essas devem vir EXCLUSIVAMENTE via isLider() abaixo. Sem filtro, um
        // setor podia conceder lideranca.dashboard_setor a membros não-líderes
        // e o menu "Meu Setor" apareceria pra eles indevidamente.
        $keys = \App\Models\SetorPermissao::query()
            ->whereIn('setor_id', $this->setores()->pluck('setores.id'))
            ->pluck('permission_key')
            ->unique()
            ->reject(fn($k) => str_starts_with($k, 'lideranca.'))
            ->values()
            ->all();

        if ($this->isLider()) {
            $keys = array_values(array_unique(array_merge($keys, Permissions::AUTO_LIDERANCA)));

            // Quick 260623 — pacote extra pra líder do setor Performance:
            // dashboard ECF + empresas + metas + desempenho + carteira (visão
            // consolidada da equipe). Detecta pelo slug do setor liderado.
            $lideraPerformance = $this->setoresLiderados()
                ->where('setores.slug', 'performance')
                ->exists();
            if ($lideraPerformance) {
                $keys = array_values(array_unique(array_merge($keys, Permissions::AUTO_LIDERANCA_PERFORMANCE)));
            }

            // Phase 78 (v16.0): /shopee/empresas é EXCLUSIVO do líder do Setor Shopee
            // (+ admin). A permission shopee.empresas NÃO é concedida aos membros do
            // setor (removida de setor_permissoes por migration) — só o líder a recebe
            // aqui. É o líder quem atribui a empresa a um analista/estrategista do setor.
            $lideraShopee = $this->setoresLiderados()
                ->where('setores.slug', 'shopee')
                ->exists();
            if ($lideraShopee) {
                $keys = array_values(array_unique(array_merge($keys, [Permissions::SHOPEE_EMPRESAS])));
            }
        }

        return $this->effectivePermissionsCache = $keys;
    }

    // ─── Carteira (company_users) — sem mudança ──────────────────────────────

    public function companies()
    {
        // Carteira CONSOLIDADA — alimenta o bônus. Blindagem contra a Phase 78
        // (linha ML + linha Shopee do mesmo par): usamos ->select('companies.*')->distinct().
        //
        // Por que NÃO basta ->distinct() sozinho e por que removemos withPivot/withTimestamps:
        // withPivot('assigned_at') e withTimestamps() reinjetam assigned_at/created_at/updated_at
        // aliasados no SELECT (aliasedPivotColumns). Se a linha ML e a linha Shopee tiverem esses
        // valores divergentes, o distinct NÃO colapsa → carteira dobrada → bônus dobrado (Pitfall 4).
        // Restringir o SELECT a companies.* garante o dedup mesmo com pivot divergente.
        // Assumption A2 confirmada por grep: nenhum consumidor lê ->pivot->role/->pivot->assigned_at
        // desta relação (só pluck('companies.id')/get/exists). Call-sites que precisam do papel
        // (ex.: PortfolioController) re-declaram ->withPivot('role') por conta própria.
        return $this->belongsToMany(Company::class, 'company_users')
            ->select('companies.*')
            ->distinct('companies.id');
    }

    public function consultorCompanies()
    {
        // Carteira do Analista (consolidada) — mesmo dedup defensivo do companies().
        // role é fixado pelo wherePivot, não precisa vir no SELECT.
        return $this->belongsToMany(Company::class, 'company_users')
            ->wherePivot('role', 'consultor')
            ->select('companies.*')
            ->distinct('companies.id');
    }

    /**
     * Empresas em que este user é o Estrategista — antes chamado de "mentor"
     * (renomeado em 2026-05-22). Filtra pivot role='estrategista'.
     */
    public function estrategistaCompanies()
    {
        // Carteira do Estrategista (consolidada) — mesmo dedup defensivo do companies().
        return $this->belongsToMany(Company::class, 'company_users')
            ->wherePivot('role', 'estrategista')
            ->select('companies.*')
            ->distinct('companies.id');
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
