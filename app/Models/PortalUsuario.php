<?php

namespace App\Models;

use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\Access\Authorizable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * PortalUsuario — uma pessoa do lado do CLIENTE, com identidade própria no
 * Portal.
 *
 * **Não é um `User`.** `User` é o time da ECF, e o sistema inteiro trata todo
 * `User` como interno (role, publication_role, carteira, ranking de desempenho,
 * seletor de responsável). Ver o docblock da migration para o porquê da
 * separação.
 *
 * Implementa `Authenticatable` sem senha: quem autentica é o código de 6
 * dígitos enviado ao e-mail ({@see \App\Services\Portal\PortalLoginService}).
 * `getAuthPassword()` devolve string vazia porque o contrato do Laravel a
 * exige, e nenhum caminho deste guard usa verificação de senha.
 */
class PortalUsuario extends Model implements AuthenticatableContract
{
    use Authorizable, LogsActivity, Notifiable;

    protected $table = 'portal_usuarios';

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['nome', 'email', 'cargo', 'ativo'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn (string $evento) => match ($evento) {
                'created' => 'Usuário do portal criado',
                'updated' => 'Usuário do portal atualizado',
                'deleted' => 'Usuário do portal excluído',
                default   => $evento,
            });
    }

    protected $fillable = [
        'nome', 'email', 'telefone', 'cargo', 'ativo',
        'convidado_por', 'convidado_em', 'primeiro_acesso_em', 'ultimo_acesso_em',
    ];

    protected $casts = [
        'ativo'              => 'boolean',
        'convidado_em'       => 'datetime',
        'primeiro_acesso_em' => 'datetime',
        'ultimo_acesso_em'   => 'datetime',
    ];

    /**
     * E-mail sempre em minúsculas. Sem isto, "Joao@empresa.com" e
     * "joao@empresa.com" viram duas pessoas — e a segunda nunca conseguiria
     * entrar, porque a busca do login normaliza.
     */
    public function setEmailAttribute(string $valor): void
    {
        $this->attributes['email'] = Str::lower(trim($valor));
    }

    /** As empresas que esta pessoa pode ver. É a autorização — ver a migration do pivot. */
    public function empresas()
    {
        return $this->belongsToMany(Company::class, 'portal_usuario_empresa', 'portal_usuario_id', 'company_id')
            ->withPivot('principal')
            ->withTimestamps();
    }

    public function codigos()
    {
        return $this->hasMany(PortalCodigoAcesso::class, 'portal_usuario_id');
    }

    public function convidadoPor()
    {
        return $this->belongsTo(User::class, 'convidado_por');
    }

    /**
     * A empresa que abre por padrão: a marcada como principal, ou a primeira.
     * `null` quando a pessoa não tem vínculo nenhum — caso em que ela não pode
     * entrar, e é o `EnsurePortalAutenticado` que barra.
     */
    public function empresaPadrao(): ?Company
    {
        return $this->empresas()->orderByDesc('portal_usuario_empresa.principal')->first();
    }

    /**
     * A pergunta de autorização, e o único jeito certo de respondê-la.
     *
     * Toda rota do Portal precisa passar por aqui antes de mostrar qualquer
     * coisa de uma empresa. Consultar o banco a cada chamada é deliberado:
     * revogar acesso tem de valer na requisição seguinte, não quando a sessão
     * expirar.
     */
    public function podeVer(int $companyId): bool
    {
        return $this->empresas()->whereKey($companyId)->exists();
    }

    public function scopeAtivos($query)
    {
        return $query->where('ativo', true);
    }

    // ─── Authenticatable ────────────────────────────────────────────────────

    public function getAuthIdentifierName(): string
    {
        return 'id';
    }

    public function getAuthIdentifier()
    {
        return $this->getKey();
    }

    /** Sem senha por desenho: quem autentica é o código enviado ao e-mail. */
    public function getAuthPassword(): string
    {
        return '';
    }

    public function getAuthPasswordName(): string
    {
        return 'password';
    }

    public function getRememberToken(): ?string
    {
        return $this->remember_token ?? null;
    }

    public function setRememberToken($value): void
    {
        // Sem "lembrar-me" do Laravel: a permanência vem do tempo de sessão,
        // que é controlado em `config/portal.php`. Um remember_token seria um
        // segundo segredo permanente no cookie — justamente o que este projeto
        // está saindo de ter.
    }

    public function getRememberTokenName(): ?string
    {
        return null;
    }
}
