<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Um mês de Jardinagem de UM anúncio publicado pelo Publicador (23/10/2026), pela árvore do MAG (Turma 8,
 * aula de 27/11/2025): visitas e vendas da janela → diagnóstico → o checklist do que fazer.
 *
 * Nasce da análise mensal (`JardinagemService::analisar`, comando `publicador:jardinagem`). O ciclo
 * seguinte do mesmo MLB marca a linha aberta anterior como `substituida`: só o mês mais novo fica na fila.
 * `janela_*` são texto `Y-m-d` de propósito (sem cast `date`, ver a migration `2026_10_23_120000`).
 *
 * `proposta` (só `sem_tracao`): o título novo para a nova oferta, gerado pela IA com os termos mais
 * buscados da SEMANA — `{estado: gerando|pronta|erro, titulo_atual, titulo_novo, termos_novos: list,
 * em, erro}`. Nunca é escrito no rascunho nem no Mercado Livre: é a sugestão que a equipe usa ao
 * criar a nova oferta.
 */
class PubJardinagem extends Model
{
    protected $table = 'pub_jardinagens';

    // ═══ Diagnósticos (os quatro ramos da árvore do MAG) ═══
    public const SEM_TRACAO = 'sem_tracao';
    public const VISITA_SEM_VENDA = 'visita_sem_venda';
    public const CONVERSAO_BAIXA = 'conversao_baixa';
    public const CONVERSAO_BOA = 'conversao_boa';

    public const DIAGNOSTICOS = [self::SEM_TRACAO, self::VISITA_SEM_VENDA, self::CONVERSAO_BAIXA, self::CONVERSAO_BOA];

    // ═══ Status ═══
    public const PENDENTE = 'pendente';
    public const EM_ANDAMENTO = 'em_andamento';
    public const FEITA = 'feita';
    /** O mês seguinte do mesmo anúncio chegou com a linha ainda aberta: ela sai da fila, e o registro fica. */
    public const SUBSTITUIDA = 'substituida';

    public const ABERTAS = [self::PENDENTE, self::EM_ANDAMENTO];
    public const STATUS = [self::PENDENTE, self::EM_ANDAMENTO, self::FEITA, self::SUBSTITUIDA];

    // ═══ Estado de cada item do checklist ═══
    public const ITEM_PENDENTE = 'pendente';
    public const ITEM_FEITO = 'feito';
    public const ITEM_NAO_SE_APLICA = 'nao_se_aplica';
    public const ESTADOS_DO_ITEM = [self::ITEM_PENDENTE, self::ITEM_FEITO, self::ITEM_NAO_SE_APLICA];

    /**
     * O que a árvore manda fazer em cada ramo, na ordem dela. As chaves são o contrato com a tela.
     * No ramo bom a árvore é condicional ("vendas recorrentes → curva A → Full…"): o item que não vale
     * para o anúncio sai com "não se aplica".
     */
    public const CHECKLISTS = [
        self::SEM_TRACAO => [
            'nova_oferta' => 'Excluir e criar nova oferta',
        ],
        self::VISITA_SEM_VENDA => [
            'criativo' => 'Reavaliar o criativo',
            'elasticidade' => 'Teste de elasticidade de preço',
        ],
        self::CONVERSAO_BAIXA => [
            'criativo' => 'Reavaliar o criativo',
            'elasticidade' => 'Teste de elasticidade de preço',
        ],
        self::CONVERSAO_BOA => [
            'curva_a' => 'Conferir se é curva A (vendas recorrentes)',
            'full' => 'Avaliar o Full',
            'elasticidade' => 'Teste de elasticidade de preço',
            'escalar' => 'Escalar: nova oferta',
        ],
    ];

    /** Todas as chaves de checklist que existem (a rota só aceita estas). */
    public const CHAVES_DO_CHECKLIST = ['nova_oferta', 'criativo', 'elasticidade', 'curva_a', 'full', 'escalar'];

    protected $guarded = ['id'];

    protected $casts = [
        'ciclo' => 'integer',
        'visitas' => 'integer',
        'vendas' => 'integer',
        'vendidos_total' => 'integer',
        'conversao' => 'float',
        'checklist' => 'array',
        'proposta' => 'array',
        'iniciada_em' => 'datetime',
        'concluida_em' => 'datetime',
    ];

    public function publicacaoItem(): BelongsTo
    {
        return $this->belongsTo(PubPublicacaoItem::class, 'publicacao_item_id');
    }

    public function produto(): BelongsTo
    {
        return $this->belongsTo(PubProduto::class, 'produto_id');
    }

    public function responsavel(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsavel_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function mlbEmpresa(): BelongsTo
    {
        return $this->belongsTo(MlbEmpresa::class);
    }

    // ═══ Escopos ═══

    public function scopeAbertas(Builder $q): Builder
    {
        return $q->whereIn('status', self::ABERTAS);
    }

    /** Linhas da conta por qualquer das âncoras; sem nenhuma, não devolve nada (nunca tudo). */
    public function scopeDaConta(Builder $q, ?MlbEmpresa $e, ?Company $c): Builder
    {
        if (! $e && ! $c) {
            return $q->whereRaw('1 = 0');
        }

        return $q->where(function ($w) use ($e, $c) {
            if ($e) {
                $w->orWhere('mlb_empresa_id', $e->id);
            }
            if ($c) {
                $w->orWhere('company_id', $c->id);
            }
        });
    }

    // ═══ Checklist ═══

    /** O checklist novo do diagnóstico: todo item pendente, sem autor. */
    public static function checklistInicial(string $diagnostico): array
    {
        $vazio = ['estado' => self::ITEM_PENDENTE, 'motivo' => null, 'por' => null, 'em' => null];

        return array_map(fn () => $vazio, self::CHECKLISTS[$diagnostico] ?? []);
    }

    /** O checklist gravado, completo e na ordem da árvore (chave nova entra pendente; chave estranha sai). */
    public function checklistCompleto(): array
    {
        $gravado = (array) ($this->checklist ?? []);
        $saida = [];
        foreach (self::checklistInicial((string) $this->diagnostico) as $chave => $vazio) {
            $saida[$chave] = [...$vazio, ...array_intersect_key((array) ($gravado[$chave] ?? []), $vazio)];
        }

        return $saida;
    }

    /** Todos os itens resolvidos (feito ou não se aplica): só então a linha pode ser concluída. */
    public function checklistResolvido(): bool
    {
        foreach ($this->checklistCompleto() as $item) {
            if (! in_array($item['estado'], [self::ITEM_FEITO, self::ITEM_NAO_SE_APLICA], true)) {
                return false;
            }
        }

        return true;
    }

    public function aberta(): bool
    {
        return in_array($this->status, self::ABERTAS, true);
    }
}
