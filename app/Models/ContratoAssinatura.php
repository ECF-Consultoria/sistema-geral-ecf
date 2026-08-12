<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * ContratoAssinatura — Fase 125 (DADOS-01/DADOS-04). O continente onde o
 * processo de assinatura de cada empresa passa a viver: estado atual e as
 * datas de envio, assinatura e liberação.
 *
 * ⚠️ Pós D-06 (Fase 127-01): um `ContratoAssinatura` representa UM serviço,
 * não vários. `servico_id` é a FONTE; `servicos_snapshot` (D-10 da Fase 125)
 * continua JSON, mas com o recorte de um único serviço. A trava de
 * unicidade "em andamento" deixou de ser por EMPRESA e passou a ser por
 * (EMPRESA + SERVIÇO) — ver `STATUS_EM_ANDAMENTO` e o hook `saving` abaixo.
 *
 * Os 7 estados possíveis (D-04/D-06), STRING + constantes públicas — nunca
 * `enum` de banco:
 *  - rascunho               — contrato gerado, ainda não enviado.
 *  - aguardando_assinaturas — enviado à Clicksign, esperando signatários
 *                              (a Clicksign colapsa "enviado" e "em
 *                              andamento" num único status `running`).
 *  - assinado                — todas as partes assinaram. D-05: `liberado`
 *                              NÃO é estado — a soltura da empresa pro
 *                              operacional é a data `liberado_em` (Fase
 *                              130); um contrato `assinado` com
 *                              `liberado_em` nulo é caso legítimo, é o que
 *                              o alerta da REDE-02 precisa enxergar.
 *  - recusado                 — algum signatário recusou. Próprio, nunca
 *                              colapsa em `cancelado`/`erro`.
 *  - expirado                 — prazo esgotado sem todas as assinaturas.
 *                              Próprio, nunca colapsa em
 *                              `cancelado`/`erro`. D-03: nada nesta fase
 *                              CALCULA expiração — falta a coluna de prazo,
 *                              que é da Fase 127.
 *  - cancelado                — cancelado manualmente (admin ou fluxo).
 *  - erro                     — falha TÉCNICA (integração Clicksign),
 *                              separada de recusa do cliente; detalhe em
 *                              `erro_mensagem`.
 */
class ContratoAssinatura extends Model
{
    use HasFactory, LogsActivity;

    protected $table = 'contrato_assinaturas';

    // $fillable explícito — nunca $guarded = [] (mass assignment, T-125-01).
    protected $fillable = [
        'company_id',
        'status',
        'company_id_em_andamento',
        'clicksign_envelope_id',
        'clicksign_document_id',
        'enviado_em',
        'assinado_em',
        'liberado_em',
        'erro_mensagem',
        'servicos_snapshot',
        // Fase 126 (D-03) — sem isto o mass assignment das duas colunas
        // novas falharia EM SILÊNCIO.
        'pdf_path',
        'pdf_assinado_path',
        // Fase 127-01 (D-06) — sem isto o mass assignment das quatro
        // colunas novas falharia EM SILÊNCIO (T-127-03), exatamente o
        // alerta deixado pela Fase 126.
        'servico_id',
        'servico_id_em_andamento',
        'prazo_dias',
        'lembrete_dias',
    ];

    protected $casts = [
        'enviado_em'        => 'datetime',
        'assinado_em'       => 'datetime',
        'liberado_em'       => 'datetime',
        // D-10 — mesmo cast de ContratoServico::$casts['hubspot_snapshot'].
        'servicos_snapshot' => 'array',
        // Fase 127-01 (DADOS-06).
        'prazo_dias'        => 'integer',
        'lembrete_dias'     => 'integer',
    ];

    public const STATUS_RASCUNHO               = 'rascunho';
    public const STATUS_AGUARDANDO_ASSINATURAS = 'aguardando_assinaturas';
    public const STATUS_ASSINADO               = 'assinado';
    public const STATUS_RECUSADO               = 'recusado';
    public const STATUS_EXPIRADO               = 'expirado';
    public const STATUS_CANCELADO              = 'cancelado';
    public const STATUS_ERRO                   = 'erro';

    /** Os 7 estados, na ordem da D-06. */
    public const STATUS_TODOS = [
        self::STATUS_RASCUNHO,
        self::STATUS_AGUARDANDO_ASSINATURAS,
        self::STATUS_ASSINADO,
        self::STATUS_RECUSADO,
        self::STATUS_EXPIRADO,
        self::STATUS_CANCELADO,
        self::STATUS_ERRO,
    ];

    /**
     * Estados que ocupam o slot do índice único
     * `ca_empresa_servico_andamento_uniq` (D-01, chave composta desde a
     * D-06 da Fase 127-01) — e SÓ estes. Um contrato nestes estados é "em
     * andamento": a empresa não pode ter um segundo do MESMO serviço.
     */
    public const STATUS_EM_ANDAMENTO = [
        self::STATUS_RASCUNHO,
        self::STATUS_AGUARDANDO_ASSINATURAS,
    ];

    /**
     * Sincroniza AS DUAS colunas auxiliares (`company_id_em_andamento` E
     * `servico_id_em_andamento`, desde a D-06 da Fase 127-01) com o
     * `status` a cada save, NA MESMA passagem: espelham `company_id` e
     * `servico_id` enquanto o contrato está em andamento, e zeram AS DUAS
     * juntas fora disso.
     *
     * ⚠️ LEIA ANTES DE CONFIAR NA D-01 (corrigido no code review da Fase 125;
     * estendido para chave composta na Fase 127-01):
     *
     * As duas colunas são **derivadas**, e este hook é o ÚNICO lugar que as
     * preenche. O índice único `ca_empresa_servico_andamento_uniq` não
     * enxerga o `status` — ele só garante unicidade daquilo que este hook
     * escreveu. Ou seja: **o hook não é conveniência sobre a trava do banco,
     * ele é o que alimenta a trava.** Onde o hook não roda, a D-01 desliga
     * em silêncio:
     *
     *   - `updateQuietly()` / `saveQuietly()` / `withoutEvents()`
     *   - `->update()` de query builder (ex.: `ContratoAssinatura::where(...)->update([...])`)
     *   - `insert()` / `upsert()` diretos
     *   - seeders que escrevem via query builder
     *
     * A falha acontece nos dois sentidos: pode **liberar** um segundo contrato
     * em andamento (colunas ficaram NULL), ou **travar a empresa para sempre**
     * (colunas presas a um contrato já encerrado).
     *
     * Consequência prática para quem vier depois: expirar contratos vencidos
     * (Fase 130) é um bulk update por natureza — se for feito por query
     * builder, precisa zerar **as duas** colunas na MESMA query, senão a
     * empresa/serviço fica impedido de gerar contrato novo.
     *
     * O hook também é o guard de obrigatoriedade de `servico_id`: a coluna
     * é `nullable()` no schema só por causa de uma limitação do SQLite dos
     * testes (ver comentário da migration da Fase 127-01) — a obrigação
     * real é aplicada aqui, lançando `\RuntimeException` se `servico_id`
     * estiver vazio ao salvar.
     */
    protected static function booted(): void
    {
        static::saving(function (self $contrato) {
            if (empty($contrato->servico_id)) {
                throw new \RuntimeException('ContratoAssinatura exige servico_id (D-06) — um contrato representa UM serviço.');
            }

            $emAndamento = in_array($contrato->status, self::STATUS_EM_ANDAMENTO, true);

            $contrato->company_id_em_andamento = $emAndamento ? $contrato->company_id : null;
            $contrato->servico_id_em_andamento = $emAndamento ? $contrato->servico_id : null;
        });
    }

    public function estaEmAndamento(): bool
    {
        return in_array($this->status, self::STATUS_EM_ANDAMENTO, true);
    }

    /**
     * Guard de código da D-01: devolve QUALQUER contrato em andamento da
     * empresa (de qualquer serviço), ou `null` se não houver nenhum. Uso
     * histórico da suíte da Fase 125; quem for CRIAR contrato deve preferir
     * `emAndamentoDoServico()` abaixo — desde a D-06 (Fase 127-01) a trava
     * real é por (empresa + serviço), não por empresa isolada.
     *
     * Consulta por `company_id` + `status`, que é a FONTE da verdade, e não
     * por `company_id_em_andamento`, que é o espelho derivado. Assim esta
     * leitura continua correta mesmo se algum bulk update tiver
     * dessincronizado a coluna auxiliar (ver o aviso em `booted()`).
     */
    public static function emAndamentoDaEmpresa(int $companyId): ?self
    {
        return self::where('company_id', $companyId)
            ->whereIn('status', self::STATUS_EM_ANDAMENTO)
            ->first();
    }

    /**
     * Guard de código da D-06 (Fase 127-01): devolve o contrato em
     * andamento da empresa PARA O SERVIÇO pedido, ou `null` se não houver
     * nenhum. Quem for criar contrato deve chamar isto ANTES, para o
     * usuário ver "esta empresa já tem contrato em andamento para este
     * serviço" em vez de um 500 de constraint do banco.
     *
     * Consulta por `company_id` + `servico_id` + `status`, que é a FONTE da
     * verdade, e não pelas colunas espelho `company_id_em_andamento` /
     * `servico_id_em_andamento` — mesma disciplina de `emAndamentoDaEmpresa()`.
     */
    public static function emAndamentoDoServico(int $companyId, int $servicoId): ?self
    {
        return self::where('company_id', $companyId)
            ->where('servico_id', $servicoId)
            ->whereIn('status', self::STATUS_EM_ANDAMENTO)
            ->first();
    }

    /**
     * Plano 127-04 (D-03 / DADOS-06): prazo de assinatura efetivo deste
     * contrato — a coluna `prazo_dias`, quando preenchida, ou o padrão de
     * configuração (`CLICKSIGN_PRAZO_DIAS`, 30 por padrão, valor MEDIDO da
     * própria Clicksign).
     *
     * `null` na coluna significa "usou o padrão vigente na época". O valor
     * escolhido (coluna OU padrão) precisa ser lido por aqui — nunca ler
     * `config()` direto — porque quem persistir o resultado desta chamada
     * na criação do envelope está fixando, para a Fase 130 (alerta de
     * contrato preso) calcular há quanto tempo é aceitável esperar mesmo
     * que o padrão de configuração mude depois.
     */
    public function prazoDiasEfetivo(): int
    {
        return $this->prazo_dias ?? (int) config('services.clicksign.prazo_dias_padrao');
    }

    /**
     * Plano 127-04 (D-03 / DADOS-06): intervalo de lembrete efetivo deste
     * contrato — mesma disciplina de `prazoDiasEfetivo()`, com
     * `lembrete_dias` / `CLICKSIGN_LEMBRETE_DIAS` (padrão 3, valor MEDIDO).
     */
    public function lembreteDiasEfetivo(): int
    {
        return $this->lembrete_dias ?? (int) config('services.clicksign.lembrete_dias_padrao');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            // ⚠️ Não incluir 'servicos_snapshot' aqui: são valores de
            // contrato, o activity_log viraria cópia paralela do
            // congelamento numa tabela com retenção própria (T-125-03).
            ->logOnly(['status', 'enviado_em', 'assinado_em', 'liberado_em'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn (string $eventName) => match ($eventName) {
                'created' => 'Contrato de assinatura criado',
                'updated' => 'Contrato de assinatura atualizado',
                'deleted' => 'Contrato de assinatura excluído',
                default   => $eventName,
            });
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    // Fase 127-01 (D-06) — o serviço que este contrato representa. Pós D-06,
    // um ContratoAssinatura representa UM serviço, nunca vários.
    public function servico(): BelongsTo
    {
        return $this->belongsTo(Servico::class);
    }

    // Fase 125 (Plano 02) — cada pessoa que assina este contrato
    // (DADOS-02). Ver App\Models\ContratoAssinaturaSignatario.
    public function signatarios(): HasMany
    {
        return $this->hasMany(ContratoAssinaturaSignatario::class);
    }
}
