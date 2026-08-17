<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * OnboardingPasso — um passo dentro de um Onboarding, com a definição
 * CONGELADA no nascimento.
 *
 * O passo carrega a própria definição (`titulo`, `dono`, `setor_id`,
 * `depende_de`, `sla_dias`, `auto_fonte`, `condicao`), copiada de
 * {@see \App\Support\Onboarding\DefinicaoOnboarding} por
 * `OnboardingEngineService::montarPassos()`. É isso que faz o onboarding em
 * andamento NÃO mudar debaixo do cliente quando a definição em código muda:
 * deployar uma receita nova só afeta quem nascer depois.
 *
 * D-11 — o estado tem SEIS valores, nunca dois. `aguardando_coleta` e
 * `indeterminado` existem para que "tabela vazia" nunca seja lida como "zero
 * real" — mesma armadilha que já custou caro no Shopee (conta nova fica vazia
 * até o backfill; o cron só cobre quem já existe). Um resolver automático que
 * devolva um `bool` está errado por construção: ele precisa distinguir
 * "concluído" de "ainda não coletado" de "429/timeout, indeterminado — não
 * conclua, retente depois".
 *
 * D-19 — `dono` e `auto_fonte` são eixos INDEPENDENTES:
 *  - `dono` responde "de quem é a bola?" — quem precisa AGIR para o passo
 *    andar (é de quem o painel cobra e para quem o SLA aponta).
 *  - `auto_fonte` responde "como o sistema sabe que aconteceu?" — como ele
 *    VERIFICA a conclusão.
 * Um passo pode ter `dono=cliente` e ainda assim se resolver sozinho: o
 * cliente precisa autorizar o OAuth (a bola é dele), mas ninguém digita nada —
 * `ml_tokens.status=active` fecha o passo. Um passo com `auto_fonte` preenchido
 * NUNCA aceita conclusão manual como verdade — só o resolver fecha o passo.
 *
 * `chave` é o identificador estável do passo: é por ela que outro passo declara
 * dependência e é a chave de agregação do link único por empresa (D-06).
 */
class OnboardingPasso extends Model
{
    protected $table = 'onboarding_passos';

    protected $fillable = [
        'onboarding_id',
        'ordem',
        'etapa',
        'chave',
        'titulo',
        'dono',
        'setor_id',
        'depende_de',
        'sla_dias',
        'auto_fonte',
        'condicao',
        'status',
        'valor',
        'feito_por',
        'feito_em',
        'auto_em',
        'disponivel_em',
        'coleta_iniciada_em',
        'tentativas',
        'ultimo_erro',
    ];

    protected $casts = [
        'depende_de'         => 'array',
        'condicao'           => 'array',
        'valor'              => 'array',
        'feito_em'           => 'datetime',
        'auto_em'            => 'datetime',
        'disponivel_em'      => 'datetime',
        'coleta_iniciada_em' => 'datetime',
    ];

    // ─── Catálogo fechado de `status` (D-11 — seis estados) ──────────────────
    /** Depende de passo não concluído — ainda não pode ser trabalhado. */
    public const STATUS_BLOQUEADO = 'bloqueado';
    /** Acionável, esperando o dono agir (ou o resolver automático rodar). */
    public const STATUS_ABERTO = 'aberto';
    /** O resolver disparou uma coleta assíncrona (ex.: mlb:sync-acervo). */
    public const STATUS_AGUARDANDO_COLETA = 'aguardando_coleta';
    /** A sonda devolveu erro sem resposta definitiva (429/timeout) — retenta. */
    public const STATUS_INDETERMINADO = 'indeterminado';
    public const STATUS_CONCLUIDO = 'concluido';
    /** A `condicao` do passo foi avaliada como falsa (D-12). */
    public const STATUS_NAO_APLICAVEL = 'nao_aplicavel';

    public const STATUSES = [
        self::STATUS_BLOQUEADO,
        self::STATUS_ABERTO,
        self::STATUS_AGUARDANDO_COLETA,
        self::STATUS_INDETERMINADO,
        self::STATUS_CONCLUIDO,
        self::STATUS_NAO_APLICAVEL,
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
    public const AUTO_FONTE_RELATORIO_INICIAL = 'relatorio_inicial_escrito';

    public const AUTO_FONTES = [
        self::AUTO_FONTE_ADMAN_ACCOUNT_ID,
        self::AUTO_FONTE_ADMAN_GRANT,
        self::AUTO_FONTE_ML_TOKEN,
        self::AUTO_FONTE_ACERVO,
        self::AUTO_FONTE_METRICAS,
        self::AUTO_FONTE_RELATORIO_INICIAL,
    ];

    // ─── Catálogo fechado de `etapa` (bloco em que o passo aparece) ─────────
    /**
     * A `etapa` é ESTRUTURAL, como `dono` e `sla_dias`: decide em que bloco o
     * passo aparece e em que ordem o cliente encontra as coisas. Por isso é
     * COPIADA da definição no nascimento — deployar uma receita nova não
     * reorganiza a tela debaixo de quem já está no meio do onboarding.
     *
     * Diferente da INSTRUÇÃO, que é texto e vive só em
     * {@see \App\Support\Onboarding\DefinicaoOnboarding::instrucaoDe()}:
     * corrigir uma frase confusa precisa alcançar quem já está travado por
     * não tê-la entendido.
     */
    public const ETAPA_ACESSOS = 'acessos';
    public const ETAPA_MAPEAMENTO = 'mapeamento';
    public const ETAPA_AGENDAMENTO = 'agendamento';
    public const ETAPA_ADMINISTRATIVO = 'administrativo';

    public const ETAPAS = [
        self::ETAPA_ACESSOS,
        self::ETAPA_MAPEAMENTO,
        self::ETAPA_AGENDAMENTO,
        self::ETAPA_ADMINISTRATIVO,
    ];

    // ─── Catálogo fechado de `condicao` (D-12 — passo condicional) ──────────
    public const CONDICAO_ANUNCIOS_INATIVOS = 'anuncios_inativos_maior_que_zero';

    public const CONDICOES = [
        self::CONDICAO_ANUNCIOS_INATIVOS,
    ];

    public function onboarding(): BelongsTo
    {
        return $this->belongsTo(Onboarding::class);
    }

    public function setor(): BelongsTo
    {
        return $this->belongsTo(Setor::class);
    }

    public function feitoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'feito_por');
    }

    /**
     * Scope: passos que o comando de reavaliação periódica precisa revisitar —
     * coleta em curso ou sonda indeterminada.
     */
    public function scopePendentesDeReavaliacao($query)
    {
        return $query->whereIn('status', [
            self::STATUS_AGUARDANDO_COLETA,
            self::STATUS_INDETERMINADO,
        ]);
    }
}
