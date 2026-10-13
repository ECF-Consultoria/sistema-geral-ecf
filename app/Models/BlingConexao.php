<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Fase 184 (BLING-01, BLING-03) — conexão OAuth da empresa com o Bling ERP.
 *
 * Molde de token copiado de `MlToken` (`access_token`/`refresh_token` com
 * cast `encrypted` e `$hidden`): o texto em claro nunca fica na linha crua
 * do banco nem em `toArray()`.
 *
 * `estaConfigurado()` existe para que a ausência de `BLING_CLIENT_ID`/
 * `SECRET`/`REDIRECT_URI` no `.env` degrade com uma mensagem clara — nunca
 * um 500 (D-02 do CONTEXT, item 1 da §5).
 */
class BlingConexao extends Model
{
    protected $table = 'bling_conexoes';

    /** D-01: existe um estado intermediário "aguardando o cliente" (link gerado, ainda não autorizado). */
    public const STATUS_A_CONECTAR = 'a_conectar';

    public const STATUS_AGUARDANDO_CLIENTE = 'aguardando_cliente';

    public const STATUS_ATIVA = 'ativa';

    /** CNPJ de `dados-basicos` divergente de `companies.cnpj`: conexão fica aqui, sem token ativo. */
    public const STATUS_DIVERGENTE = 'divergente';

    public const STATUS_REVOGADA = 'revogada';

    public const STATUS_ERRO = 'erro';

    /** D-02: app público aceita até 10 contas sem homologação. A tela declara e conta este teto. */
    public const TETO_CONTAS_SEM_HOMOLOGACAO = 10;

    protected $fillable = [
        'company_id',
        'bling_empresa_id',
        'bling_cnpj',
        'bling_nome',
        'access_token',
        'refresh_token',
        'expires_at',
        'last_refreshed_at',
        'connected_at',
        'status',
        'last_error',
        'last_error_at',
        'link_url',
        'link_gerado_em',
        'depositos',
        'saldo',
        'estoque_ativo',
        'dono_estoque_ml',
        'conectado_por',
        'ultima_importacao_em',
        'ultimo_estoque_em',
    ];

    protected $casts = [
        'access_token' => 'encrypted',
        'refresh_token' => 'encrypted',
        'expires_at' => 'datetime',
        'last_refreshed_at' => 'datetime',
        'connected_at' => 'datetime',
        'last_error_at' => 'datetime',
        'link_gerado_em' => 'datetime',
        'ultima_importacao_em' => 'datetime',
        'ultimo_estoque_em' => 'datetime',
        'estoque_ativo' => 'boolean',
    ];

    protected $hidden = ['access_token', 'refresh_token'];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function conectadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'conectado_por');
    }

    public function vinculos(): HasMany
    {
        return $this->hasMany(BlingVinculo::class, 'company_id', 'company_id');
    }

    /**
     * `true` só quando as três credenciais do app público estão no `.env`.
     * Nunca lança exceção — é esta função que impede o 500 quando o `.env`
     * está vazio (D-02 do CONTEXT).
     */
    public static function estaConfigurado(): bool
    {
        $clientId     = config('services.bling.client_id');
        $clientSecret = config('services.bling.client_secret');
        $redirect     = config('services.bling.redirect');

        return is_string($clientId) && $clientId !== ''
            && is_string($clientSecret) && $clientSecret !== ''
            && is_string($redirect) && $redirect !== '';
    }

    /** No espírito de `MlToken::expiresSoon()`. */
    public function expiraEm(int $minutos = 5): bool
    {
        return $this->expires_at && $this->expires_at->lt(now()->addMinutes($minutos));
    }
}
