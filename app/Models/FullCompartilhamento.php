<?php

namespace App\Models;

use App\Support\Portal\UrlDoPortal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Link aberto do Planejador FULL de uma empresa — o cliente entra sem login.
 *
 * Quem tem o link vê e altera o planejador daquela empresa, então ele precisa poder morrer:
 * {@see self::gerar()} troca o token (o antigo para de funcionar) e {@see self::revogar()}
 * desliga. O token nunca fica em texto puro — a rota procura pelo SHA-256 e a cópia
 * cifrada existe só para a equipe copiar o link de novo.
 */
class FullCompartilhamento extends Model
{
    use LogsActivity;

    protected $table = 'full_compartilhamentos';

    /** Tamanho do token: 48 caracteres alfanuméricos (~285 bits) — não se adivinha. */
    public const TAMANHO_TOKEN = 48;

    protected $fillable = ['company_id', 'token_hash', 'token_cifrado', 'criado_por', 'gerado_em', 'revogado_em', 'ultimo_acesso_em', 'acessos'];

    protected $hidden = ['token_hash', 'token_cifrado'];

    protected $casts = [
        'gerado_em'        => 'datetime',
        'revogado_em'      => 'datetime',
        'ultimo_acesso_em' => 'datetime',
        'acessos'          => 'integer',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function criador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'criado_por');
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    /** Gera (ou troca) o link da empresa. Devolve o token em texto — só existe neste instante. */
    public static function gerar(Company $company, ?int $userId): string
    {
        $token = Str::random(self::TAMANHO_TOKEN);

        self::updateOrCreate(['company_id' => $company->id], [
            'token_hash'    => self::hash($token),
            'token_cifrado' => Crypt::encryptString($token),
            'criado_por'    => $userId,
            'gerado_em'     => now(),
            'revogado_em'   => null,
            'acessos'       => 0,
            'ultimo_acesso_em' => null,
        ]);

        return $token;
    }

    public function revogar(): void
    {
        $this->forceFill(['token_hash' => null, 'token_cifrado' => null, 'revogado_em' => now()])->save();
    }

    /** A empresa dona de um token válido, ou null (token errado, trocado ou revogado). */
    public static function acharAtivo(string $token): ?self
    {
        if (strlen($token) !== self::TAMANHO_TOKEN) {
            return null;
        }

        return self::where('token_hash', self::hash($token))->whereNull('revogado_em')->first();
    }

    public function ativo(): bool
    {
        return $this->token_hash !== null && $this->revogado_em === null;
    }

    /** O link no domínio do cliente, ou null se revogado. */
    public function url(): ?string
    {
        if (! $this->ativo() || ! $this->token_cifrado) {
            return null;
        }

        return UrlDoPortal::para('planejador-publico.show', Crypt::decryptString($this->token_cifrado));
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('planejador_full')
            ->logOnly(['gerado_em', 'revogado_em'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn (string $evento) => match ($evento) {
                'created' => 'Link do Planejador FULL gerado para o cliente',
                'updated' => 'Link do Planejador FULL alterado (trocado ou revogado)',
                default   => $evento,
            });
    }
}
