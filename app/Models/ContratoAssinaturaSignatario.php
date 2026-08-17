<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ContratoAssinaturaSignatario — Fase 125 (DADOS-02). Cada pessoa que
 * assina um contrato, com papel, contato, situação individual e a
 * evidência de autenticação do Gate #9.
 *
 * Achado do Gate #9 (CLICKSIGN-SANDBOX-EMPIRICO.md §3): a Clicksign NÃO
 * informa se uma pessoa assinou — `GET /envelopes/{id}/signers/{signerId}`
 * comparado antes×depois da assinatura só mudou o campo `modified`. A
 * evidência real vive no evento `sign` do documento
 * (`data.signer`). Logo, a coluna `situacao` abaixo é a ÚNICA fonte
 * consultável dessa informação neste sistema.
 *
 * Papéis (D-08) — vocabulário NOSSO, nunca o `sign`/`party`/`contractor`
 * da Clicksign (mapa é tarefa da Fase 126):
 *  - contratante — quem contrata o serviço.
 *  - contratada  — a ECF.
 *  - testemunha  — parte adicional, sem obrigação contratual direta.
 *
 * Situações (D-09) — lista PRÓPRIA e curta, NUNCA reusa
 * `ContratoAssinatura::STATUS_*` (rascunho/erro/cancelado/expirado não
 * significam nada do lado de uma pessoa):
 *  - pendente — ainda não assinou nem recusou.
 *  - assinou  — assinatura confirmada pelo evento `sign` da Clicksign.
 *  - recusou  — recusou assinar.
 *
 * ⚠️ Este model NÃO usa `LogsActivity` (spatie), de propósito (T-125-10):
 * as colunas aqui são dado pessoal do signatário (nome, e-mail, CPF, IP,
 * evidência com flags de biometria). Logar isso no `activity_log`
 * criaria uma segunda cópia desses dados, com retenção própria e sem
 * necessidade — a auditoria de ESTADO DO PROCESSO já vive em
 * `ContratoAssinatura`, que loga só `status` e as três datas.
 */
class ContratoAssinaturaSignatario extends Model
{
    use HasFactory;

    protected $table = 'contrato_assinatura_signatarios';

    // $fillable explícito — nunca $guarded = [] (mass assignment, T-125-12).
    // A Fase 126/129 deve montar este payload campo a campo a partir da
    // resposta da Clicksign, nunca repassar request bruto.
    protected $fillable = [
        'contrato_assinatura_id',
        'user_id',
        'papel',
        'nome',
        'email',
        'cpf',
        'situacao',
        'assinado_em',
        'ip_address',
        'auths',
        'evidencia_signer',
        'clicksign_signer_key',
    ];

    protected $casts = [
        'assinado_em'      => 'datetime',
        'auths'            => 'array',
        'evidencia_signer' => 'array',
    ];

    public const PAPEL_CONTRATANTE = 'contratante';
    public const PAPEL_CONTRATADA  = 'contratada';
    public const PAPEL_TESTEMUNHA  = 'testemunha';

    /** Os 3 papéis, vocabulário interno (D-08). */
    public const PAPEL_TODOS = [
        self::PAPEL_CONTRATANTE,
        self::PAPEL_CONTRATADA,
        self::PAPEL_TESTEMUNHA,
    ];

    public const SITUACAO_PENDENTE = 'pendente';
    public const SITUACAO_ASSINOU  = 'assinou';
    public const SITUACAO_RECUSOU  = 'recusou';

    /**
     * As 3 situações (D-09) — deliberadamente diferente de
     * `ContratoAssinatura::STATUS_TODOS`. Reusar as constantes do
     * contrato aqui abriria estado impossível do lado de uma pessoa.
     */
    public const SITUACAO_TODAS = [
        self::SITUACAO_PENDENTE,
        self::SITUACAO_ASSINOU,
        self::SITUACAO_RECUSOU,
    ];

    public function contrato(): BelongsTo
    {
        return $this->belongsTo(ContratoAssinatura::class, 'contrato_assinatura_id');
    }

    /**
     * Pode ser NULL — ou porque nunca existiu usuário interno para este
     * signatário (ex.: contratante externo), ou porque o usuário da ECF
     * foi apagado depois (D-07, FK `nullOnDelete`). Em qualquer um dos
     * dois casos, `nome`/`email`/`cpf` continuam válidos neste registro —
     * eles não dependem deste vínculo.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function assinou(): bool
    {
        return $this->situacao === self::SITUACAO_ASSINOU;
    }
}
