<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Identidade visual de UMA conta de marketplace (Fase 170, D2, IDENT-02..05).
 *
 * Âncora dual `company_id`/`mlb_empresa_id` — mesmo vocabulário de "conta"
 * usado em `PubProduto::conta()`/`ancoraComToken()` e em
 * `MlAnuncioCriativo::company()`/`mlbEmpresa()`. A identidade é sempre da
 * CONTA, nunca da empresa cliente nem do produto — por isso `paraAncora()`
 * resolve pelas mesmas duas colunas do criativo, nunca reconstrói
 * `ContaMercadoLivre`.
 *
 * ⚠️ Sem coluna de marca aplicada como arquivo (D3 fora do planejamento desta
 * fase, decisão do usuário em 2026-10-07).
 */
class CreativeIdentidade extends Model
{
    protected $table = 'creative_identidades_conta';

    protected $fillable = [
        'company_id', 'mlb_empresa_id', 'texto',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function mlbEmpresa(): BelongsTo
    {
        return $this->belongsTo(MlbEmpresa::class, 'mlb_empresa_id');
    }

    /**
     * Resolve a identidade da conta pela mesma âncora dual usada em
     * `ml_anuncio_criativos` — `company_id` tem prioridade sobre
     * `mlb_empresa_id` (nunca os dois ao mesmo tempo); `null`/`null` nunca
     * consulta o banco com um WHERE vazio, devolve `null` direto.
     */
    public static function paraAncora(?int $companyId, ?int $mlbEmpresaId): ?self
    {
        if ($companyId !== null) {
            return self::where('company_id', $companyId)->first();
        }

        if ($mlbEmpresaId !== null) {
            return self::where('mlb_empresa_id', $mlbEmpresaId)->first();
        }

        return null;
    }
}
