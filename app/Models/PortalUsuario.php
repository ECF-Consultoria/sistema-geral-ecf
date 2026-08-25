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
 * ### Duas portas, e uma delas é opcional
 * O caminho principal é o código de 6 dígitos por e-mail
 * ({@see \App\Services\Portal\PortalLoginService}). Desde 25/08/2026 a
 * pessoa também pode DEFINIR uma senha, se quiser entrar sem esperar
 * e-mail. `password` nulo é o estado normal, não um cadastro incompleto —
 * a maioria nunca vai definir uma.
 *
 * Não há "esqueci minha senha", e não é omissão: pedir um código por
 * e-mail JÁ é o caminho de recuperação, e já existe.
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
        'password', 'senha_definida_em',
    ];

    /** O hash nunca sai do servidor — nem por acidente, num `toArray()`. */
    protected $hidden = ['password'];

    protected $casts = [
        'ativo'              => 'boolean',
        // `hashed` faz o Laravel aplicar bcrypt ao ATRIBUIR. Sem isto,
        // um `update(['password' => $senha])` distraído gravaria a senha
        // em claro e nada avisaria.
        'password'           => 'hashed',
        'senha_definida_em'  => 'datetime',
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

    /**
     * O hash da senha, ou string vazia para quem não definiu nenhuma.
     *
     * String vazia — e não `null` — porque `Hash::check()` recusa vazio sem
     * reclamar, enquanto `null` dispara deprecation em PHP 8.1+. O efeito é
     * o mesmo: quem não tem senha não entra por senha.
     */
    public function getAuthPassword(): string
    {
        return $this->attributes['password'] ?? '';
    }

    /** Definiu uma senha? É o que decide se a porta de senha existe para ela. */
    public function temSenha(): bool
    {
        return ! empty($this->attributes['password']);
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
