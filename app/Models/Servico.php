<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Servico — entrada no catálogo de serviços oferecidos pela ECF.
 *
 * Frente A (Módulo Serviços): catálogo mestre que alimenta o modal de
 * contrato em Companies/Show. Activity log via spatie/laravel-activitylog
 * (consistente com User/Company/Goal).
 *
 * Phase 37 / Plan 37-01 (REQ-37-03) — coluna `setor` permite categorização
 * Performance / Publicação / Outros usada por:
 *  - /companies (filtro Performance via whereHas contratos_servico.servico.setor='performance')
 *  - /comercial/empresas/listagem (categorização visual por setor)
 *
 * Sem coluna paralela em `companies`: setor sempre derivado do catálogo.
 */
class Servico extends Model
{
    use LogsActivity;

    protected $table = 'servicos';

    protected $fillable = [
        'nome',
        'valor_padrao',
        'tipo_cobranca',
        'ativo',
        'setor',
        // Fase 127-04 (D-21) — sem isto o mass assignment do modelo .docx
        // por serviço falharia EM SILÊNCIO.
        'clicksign_template_id',
        // Fase 128-01 (D-03 / FLUXO-08) — a tela da Fase 131 vai gravar
        // nesta coluna; sem `fillable` o mass assignment falharia em silêncio.
        'exige_contrato',
    ];

    protected $casts = [
        'valor_padrao'    => 'decimal:2',
        'ativo'           => 'boolean',
        'exige_contrato'  => 'boolean',
    ];

    // ─── Constants de tipo de cobrança ──────────────────────────────────────
    public const TIPO_MENSAL = 'mensal';
    public const TIPO_UNICA  = 'unica';

    // ─── Constants de setor (Phase 37 Plan 37-01 — REQ-37-03) ───────────────
    // 2026-07-03 quick fix: adicionado 'polos' após operador criar o setor Polos
    // na tabela `setores` (id=7 slug=polos). Servico #2 "Polos" ficava com
    // setor='outros' porque o enum não aceitava 'polos' — corrigido via migration
    // add_polos_to_servicos_setor_enum + update Servico#2.
    public const SETOR_PERFORMANCE = 'performance';
    public const SETOR_PUBLICACAO  = 'publicacao';
    public const SETOR_POLOS       = 'polos';
    // Phase 75 Plan 75-01 (DEC-1): setor 'shopee' habilita empresas atendidas
    // apenas na Shopee (sem métricas/API) — gatilho da aba Empresas Shopee + NPS.
    public const SETOR_SHOPEE      = 'shopee';
    public const SETOR_OUTROS      = 'outros';

    /**
     * Lista plana dos setores válidos (espelha o enum do schema).
     */
    public const SETORES = [
        self::SETOR_PERFORMANCE,
        self::SETOR_PUBLICACAO,
        self::SETOR_POLOS,
        self::SETOR_SHOPEE,
        self::SETOR_OUTROS,
    ];

    /**
     * Mapeamento tipo → label para UI/relatórios.
     */
    public static function tiposCobranca(): array
    {
        return [
            self::TIPO_MENSAL => 'Mensal',
            self::TIPO_UNICA  => 'Única',
        ];
    }

    /**
     * Mapeamento setor → label pt-BR para UI/relatórios (Phase 37).
     */
    public static function setoresLabels(): array
    {
        return [
            self::SETOR_PERFORMANCE => 'Performance',
            self::SETOR_PUBLICACAO  => 'Publicação',
            self::SETOR_POLOS       => 'Polos',
            self::SETOR_SHOPEE      => 'Shopee',
            self::SETOR_OUTROS      => 'Outros',
        ];
    }

    /**
     * Canal financeiro ao qual um SETOR de serviço corresponde.
     *
     * Setor 'shopee' lê `shopee_metrics`; todo o resto lê a API Adman (Mercado
     * Livre). Existe como método único porque a mesma tradução é feita no
     * `CompanyScoreService`, no `DesempenhoMetricasManuaisController` e na
     * validação do lançamento manual — e regra de fonte financeira espalhada
     * por vários call-sites foi exatamente o defeito que D-10 teve de corrigir
     * (ver `App\Services\Metrics\FinancialSourceResolver`).
     *
     * Não confundir com o DESEMPATE entre fontes concorrentes: isso continua
     * sendo responsabilidade exclusiva do `FinancialSourceResolver`. Aqui é só
     * a tradução setor -> canal, sem decidir vencedor.
     */
    public static function fonteFinanceiraDoSetor(?string $setor): string
    {
        return $setor === self::SETOR_SHOPEE ? 'shopee' : 'adman';
    }

    /**
     * Setores que RENDEM métrica financeira. Só estes viram canal.
     *
     * Vínculo de 'publicacao' ou 'polos' não é fonte financeira nenhuma —
     * `fonteFinanceiraDoSetor()` sozinha devolveria 'adman' para eles (é o
     * ramo default), então quem for derivar canais de uma empresa precisa
     * filtrar por esta lista ANTES. Ignorar isso faz uma empresa que só tem
     * publicação parecer atendida no Mercado Livre.
     */
    public const SETORES_FINANCEIROS = [
        self::SETOR_PERFORMANCE,
        self::SETOR_SHOPEE,
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            // Fase 127-04 (D-21) — trocar o modelo .docx de um serviço é
            // mudança auditável (T-127-10): decide QUAL contrato o cliente
            // assina.
            // Fase 128-01 (T-128-01) — trocar exige_contrato é mudança
            // auditável: decide se o serviço entra no gate administrativo.
            ->logOnly(['nome', 'valor_padrao', 'tipo_cobranca', 'ativo', 'setor', 'clicksign_template_id', 'exige_contrato'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn(string $eventName) => match ($eventName) {
                'created' => 'Serviço criado',
                'updated' => 'Serviço atualizado',
                'deleted' => 'Serviço excluído',
                default   => $eventName,
            });
    }

    /**
     * Contratos vinculados a este serviço (ativos + inativos).
     */
    public function contratos(): HasMany
    {
        return $this->hasMany(ContratoServico::class);
    }

    /**
     * Scope: apenas serviços ativos.
     */
    public function scopeActive($query)
    {
        return $query->where('ativo', true);
    }

    /**
     * Scope: filtra serviços por setor (Phase 37).
     *
     * Exemplo: Servico::porSetor('performance')->get()
     */
    public function scopePorSetor($query, string $setor)
    {
        return $query->where('setor', $setor);
    }

    /**
     * Scope: apenas serviços que exigem contrato (Fase 128-01, D-03).
     *
     * Exemplo: Servico::query()->exigeContrato()->pluck('nome')
     */
    public function scopeExigeContrato($query)
    {
        return $query->where('exige_contrato', true);
    }

    /**
     * Helper: este serviço pertence ao setor Performance? (Phase 37)
     */
    public function isPerformance(): bool
    {
        return $this->setor === self::SETOR_PERFORMANCE;
    }

    /**
     * Helper: este serviço pertence ao setor Publicação? (Phase 37)
     */
    public function isPublicacao(): bool
    {
        return $this->setor === self::SETOR_PUBLICACAO;
    }

    /**
     * Helper: este serviço pertence ao setor Shopee? (Phase 75)
     */
    public function isShopee(): bool
    {
        return $this->setor === self::SETOR_SHOPEE;
    }

    /**
     * Fase 127-04 (D-21) — qual modelo `.docx` da Clicksign usar para
     * contratos deste serviço. Se o serviço tiver `clicksign_template_id`
     * preenchido, usa o dele; senão cai no padrão global
     * `CLICKSIGN_TEMPLATE_ID` (`config('services.clicksign.template_id')`).
     * Devolve `null` se nenhum dos dois estiver configurado — quem decide o
     * que fazer com isso é o job de montagem do envelope (plano 127-05),
     * que precisa falhar com mensagem clara, nunca gerar contrato errado.
     *
     * ⚠️ Sandbox e produção têm UUIDs DIFERENTES; o valor de produção NUNCA
     * entra no repositório (nem no `.env.example`). Toda vez que o `.docx`
     * for recadastrado na Clicksign, rodar `clicksign:sondar-modelo` ANTES
     * de gerar contrato de cliente — variável faltando vira campo em branco
     * silencioso (§10.5 do CLICKSIGN-SANDBOX-EMPIRICO.md), não existe
     * resposta HTTP que denuncie isso; o confronto do comando é a única
     * rede de segurança.
     */
    public function clicksignTemplateId(): ?string
    {
        if (filled($this->clicksign_template_id)) {
            return $this->clicksign_template_id;
        }

        $padrao = config('services.clicksign.template_id');

        return filled($padrao) ? $padrao : null;
    }

    /**
     * Fase 128-01 (D-03 / FLUXO-08) — este serviço exige contrato assinado
     * antes de liberar o operacional?
     *
     * Este é o ÚNICO ponto de leitura autorizado. Nenhum `if ($servico->nome
     * === 'Polos')` (ou qualquer outro nome) em lugar nenhum do código —
     * quem decide é o dado (`servicos.exige_contrato`), não o nome. A
     * migration `2026_08_13_100001_add_exige_contrato_to_servicos_table`
     * já nasce com Polos isento e os demais serviços exigindo (default
     * seguro), sem precisar enumerar nomes fora dela.
     */
    public function exigeContrato(): bool
    {
        return (bool) $this->exige_contrato;
    }
}
