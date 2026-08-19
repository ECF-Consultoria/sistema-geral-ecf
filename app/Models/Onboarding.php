<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Onboarding — instância ancorada em Company × Servico, um por contrato
 * (D-01/SC-01). `definicao_versao` registra a versão de
 * {@see \App\Support\Onboarding\DefinicaoOnboarding} sob a qual este
 * onboarding nasceu; o congelamento em si mora nas colunas de
 * `onboarding_passos`, que carregam a definição copiada — mudar a receita em
 * código não mexe em quem já está rodando.
 *
 * D-05: nasce em `rascunho` (Observer do Plano 04); SLA só corre e o link do
 * cliente só é exposto depois que a Coordenação confirma `responsavel_id` e o
 * status vira `andamento` (`iniciado_em` carimbado nesse momento).
 */
class Onboarding extends Model
{
    use LogsActivity;

    protected $table = 'onboardings';

    protected $fillable = [
        'company_id',
        'servico_id',
        'contrato_servico_id',
        'definicao_versao',
        'status',
        'responsavel_id',
        'responsavel_estrategista_id',
        'responsavel_analista_id',
        'iniciado_em',
        'concluido_em',
        'reuniao_status',
        'reuniao_solicitada_em',
        'reuniao_agendada_para',
        'reuniao_agendada_por',
    ];

    protected $casts = [
        'iniciado_em'           => 'datetime',
        'concluido_em'          => 'datetime',
        'reuniao_solicitada_em' => 'datetime',
        'reuniao_agendada_para' => 'datetime',
    ];

    // ─── Catálogo fechado de `status` ────────────────────────────────────────
    public const STATUS_RASCUNHO = 'rascunho';
    public const STATUS_ANDAMENTO = 'andamento';
    public const STATUS_CONCLUIDO = 'concluido';

    public const STATUSES = [
        self::STATUS_RASCUNHO,
        self::STATUS_ANDAMENTO,
        self::STATUS_CONCLUIDO,
    ];

    // ─── Catálogo fechado de `reuniao_status` ────────────────────────────────
    /** O cliente pediu a reunião pelo portal; ninguém marcou data ainda. */
    public const REUNIAO_SOLICITADA = 'solicitada';
    /** O responsável marcou data e hora — o cliente já enxerga. */
    public const REUNIAO_AGENDADA = 'agendada';

    /**
     * Dois estados, não três. **Não existe `realizada` aqui de propósito**: o
     * passo `reuniao_realizada` já responde "aconteceu?", com `feito_em` e
     * `feito_por`. Um terceiro estado nesta coluna criaria duas versões da
     * mesma verdade.
     */
    public const REUNIAO_STATUSES = [
        self::REUNIAO_SOLICITADA,
        self::REUNIAO_AGENDADA,
    ];

    /**
     * Ordem de papéis consultados para SUGERIR o responsável do onboarding
     * (D-17), via `Company::responsavelDoServicoOuConsolidado()`. O primeiro
     * papel com vínculo (específico do serviço ou consolidado) vence.
     *
     * ATENÇÃO — isto é uma leitura de discrição (Assumption A2 do Plano 05),
     * não um fato de negócio verificado com o usuário: o operacional de
     * Gestão hoje é tratado como `'consultor'` na pivot `company_users`, com
     * fallback para `'estrategista'`. Se o negócio disser outra coisa, este
     * é o único ponto a mexer. Vínculo vazio nos dois papéis → sugestão
     * `null`, e o onboarding não sai de `rascunho` (D-05).
     */
    public const ROLES_RESPONSAVEL_SUGERIDO = ['consultor', 'estrategista'];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['status', 'responsavel_id', 'responsavel_estrategista_id', 'responsavel_analista_id'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn (string $eventName) => match ($eventName) {
                'created' => 'Onboarding criado',
                'updated' => 'Onboarding atualizado',
                'deleted' => 'Onboarding excluído',
                default   => $eventName,
            });
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function servico(): BelongsTo
    {
        return $this->belongsTo(Servico::class);
    }

    public function contratoServico(): BelongsTo
    {
        return $this->belongsTo(ContratoServico::class, 'contrato_servico_id');
    }

    public function responsavel(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsavel_id');
    }

    /**
     * Estrategista responsável por este onboarding.
     *
     * Slot próprio, e não uma leitura de `company_users`, porque o vínculo da
     * empresa é o estado ATUAL da carteira e muda com o tempo — o responsável
     * do onboarding é um carimbo daquele onboarding. Trocar o estrategista da
     * carteira em outubro não pode reescrever quem atendeu o onboarding de
     * agosto (decisão de schema §2.3).
     */
    public function responsavelEstrategista(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsavel_estrategista_id');
    }

    /** Analista responsável por este onboarding — mesmo espírito do slot acima. */
    public function responsavelAnalista(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsavel_analista_id');
    }

    /**
     * Tem ao menos um dos dois responsáveis definidos?
     *
     * É esta a régua que libera o SLA (R-02): confirmar estrategista OU
     * analista já tira o onboarding de rascunho — a tela cobra o papel que
     * faltar como pendência. Espelha `em_operacao` de `/companies`, que é "tem
     * pelo menos um dos dois papéis", em vez de criar uma segunda verdade
     * sobre quem cuida da empresa.
     */
    public function temAlgumResponsavel(): bool
    {
        return $this->responsavel_estrategista_id !== null
            || $this->responsavel_analista_id !== null;
    }

    /** Quem, do nosso lado, marcou a data da reunião — nunca exposto ao cliente. */
    public function reuniaoAgendadaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reuniao_agendada_por');
    }

    public function passos(): HasMany
    {
        return $this->hasMany(OnboardingPasso::class);
    }

    /** Scope: onboardings com status = andamento. */
    public function scopeEmAndamento($query)
    {
        return $query->where('status', self::STATUS_ANDAMENTO);
    }

    /** Scope: onboardings ainda não concluídos (rascunho ou andamento). */
    public function scopeNaoConcluido($query)
    {
        return $query->whereIn('status', [self::STATUS_RASCUNHO, self::STATUS_ANDAMENTO]);
    }
}
