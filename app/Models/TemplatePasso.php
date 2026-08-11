<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * TemplatePasso — um passo dentro de uma versão de OnboardingTemplate.
 *
 * D-19 — `dono` e `auto_fonte` são eixos INDEPENDENTES:
 *  - `dono` responde "de quem é a bola?" — quem precisa AGIR para o passo andar
 *    (é de quem o painel cobra e para quem o SLA aponta).
 *  - `auto_fonte` responde "como o sistema sabe que aconteceu?" — como ele
 *    VERIFICA a conclusão.
 * Um passo pode ter `dono=cliente` e ainda assim se resolver sozinho: o
 * cliente precisa autorizar o OAuth (a bola é dele), mas ninguém digita nada —
 * `ml_tokens.status=active` fecha o passo (é o passo 5 do template de Gestão).
 * Um passo com `auto_fonte` preenchido NUNCA aceita conclusão manual como
 * verdade — só o resolver correspondente fecha o passo.
 *
 * Catálogos fechados (D-09/D-14): `dono`, `auto_fonte` e `condicao` só podem
 * assumir os valores das constantes abaixo — nenhum texto livre vindo do
 * FormRequest do Plano 07 é aceito fora deste catálogo.
 */
class TemplatePasso extends Model
{
    protected $table = 'template_passos';

    protected $fillable = [
        'template_id',
        'ordem',
        'chave',
        'titulo',
        'descricao',
        'dono',
        'setor_id',
        'depende_de',
        'sla_dias',
        'auto_fonte',
        'condicao',
        'obrigatorio',
    ];

    protected $casts = [
        'depende_de'  => 'array',
        'condicao'    => 'array',
        'obrigatorio' => 'boolean',
    ];

    // ─── Catálogo fechado de `dono` (D-14 — três, não quatro) ───────────────
    public const DONO_CLIENTE = 'cliente';
    public const DONO_INTERNO = 'interno';
    public const DONO_SISTEMA = 'sistema';

    public const DONOS = [
        self::DONO_CLIENTE,
        self::DONO_INTERNO,
        self::DONO_SISTEMA,
    ];

    // ─── Catálogo fechado de `auto_fonte` (D-09) ─────────────────────────────
    public const AUTO_FONTE_ADMAN_ACCOUNT_ID = 'adman_account_id_preenchido';
    public const AUTO_FONTE_ADMAN_GRANT = 'adman_grant_ativo';
    public const AUTO_FONTE_ML_TOKEN = 'ml_token_ativo';
    public const AUTO_FONTE_ACERVO = 'acervo_coletado';
    public const AUTO_FONTE_METRICAS = 'metricas_conta';

    public const AUTO_FONTES = [
        self::AUTO_FONTE_ADMAN_ACCOUNT_ID,
        self::AUTO_FONTE_ADMAN_GRANT,
        self::AUTO_FONTE_ML_TOKEN,
        self::AUTO_FONTE_ACERVO,
        self::AUTO_FONTE_METRICAS,
    ];

    // ─── Catálogo fechado de `condicao` (D-12 — passo condicional) ──────────
    public const CONDICAO_ANUNCIOS_INATIVOS = 'anuncios_inativos_maior_que_zero';

    public const CONDICOES = [
        self::CONDICAO_ANUNCIOS_INATIVOS,
    ];

    public function template(): BelongsTo
    {
        return $this->belongsTo(OnboardingTemplate::class, 'template_id');
    }

    public function setor(): BelongsTo
    {
        return $this->belongsTo(Setor::class);
    }
}
