<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Log;

/**
 * Tarefa pós-publicação (09/10/2026): o produto foi publicado pelo Publicador e outro colaborador
 * precisa usar as alavancas (Central de Promoções, ADS de lançamento, atacado, cupom, afiliados,
 * lista de transmissão). Uma por produto publicado — Clássico e Premium juntos em `itens`.
 *
 * Nasce no fim da publicação (`TarefasPosPublicacao::abrir`). `prazo` é texto `Y-m-d` de propósito
 * (sem cast `date`, ver a migration `2026_10_09_180000`).
 */
class PubTarefa extends Model
{
    protected $table = 'pub_tarefas';

    public const TIPO_ALAVANCAS = 'alavancas';

    public const PENDENTE = 'pendente';
    public const EM_ANDAMENTO = 'em_andamento';
    public const FEITA = 'feita';
    public const CANCELADA = 'cancelada';

    public const ABERTAS = [self::PENDENTE, self::EM_ANDAMENTO];
    public const STATUS = [self::PENDENTE, self::EM_ANDAMENTO, self::FEITA, self::CANCELADA];

    // Estado de cada item do checklist.
    public const ITEM_PENDENTE = 'pendente';
    public const ITEM_FEITO = 'feito';
    public const ITEM_NAO_SE_APLICA = 'nao_se_aplica';
    public const ESTADOS_DO_ITEM = [self::ITEM_PENDENTE, self::ITEM_FEITO, self::ITEM_NAO_SE_APLICA];

    /**
     * O checklist pós-publicação da planilha da ECF (aba Cronograma), na ordem dela. As chaves são o
     * contrato com a tela e com a baixa automática (promoção → central_promocao, cupom → cupom,
     * atacado → atacado); afiliados e lista de transmissão não têm API para o vendedor.
     */
    public const CHECKLIST_ALAVANCAS = [
        'central_promocao' => 'Central de Promoções',
        'ads_lancamento' => 'ADS de lançamento',
        'atacado' => 'Atacado',
        'cupom' => 'Cupom de desconto',
        'afiliados' => 'Afiliados',
        'lista_transmissao' => 'Lista de transmissão',
    ];

    protected $guarded = ['id'];

    protected $casts = [
        'itens' => 'array',
        'checklist' => 'array',
        'publicado_em' => 'datetime',
        'iniciada_em' => 'datetime',
        'concluida_em' => 'datetime',
    ];

    public function publicacao(): BelongsTo
    {
        return $this->belongsTo(PubPublicacao::class, 'publicacao_id');
    }

    public function produto(): BelongsTo
    {
        return $this->belongsTo(PubProduto::class, 'produto_id');
    }

    public function responsavel(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsavel_id');
    }

    public function publicadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'publicado_por');
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

    public function scopeAlavancas(Builder $q): Builder
    {
        return $q->where('tipo', self::TIPO_ALAVANCAS);
    }

    public function scopeAbertas(Builder $q): Builder
    {
        return $q->whereIn('status', self::ABERTAS);
    }

    /** Tarefas da conta por qualquer das âncoras; sem nenhuma, não devolve nada (nunca tudo). */
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

    /**
     * Quantos publicados da conta ainda aguardam as alavancas. Nunca derruba a tela que só mostra o
     * número: entre o deploy do código e o `migrate` a tabela ainda não existe.
     */
    public static function abertasDaConta(?MlbEmpresa $e, ?Company $c): int
    {
        if (! $e && ! $c) {
            return 0;
        }
        try {
            return self::query()->alavancas()->abertas()->daConta($e, $c)->count();
        } catch (\Throwable $ex) {
            Log::warning('[Publicador] contagem de tarefas pós-publicação indisponível: '.$ex->getMessage());

            return 0;
        }
    }

    // ═══ Checklist e itens ═══

    /** O checklist novo: todo item pendente, sem autor. */
    public static function checklistInicial(): array
    {
        $vazio = ['estado' => self::ITEM_PENDENTE, 'motivo' => null, 'por' => null, 'em' => null, 'escrita_id' => null];

        return array_map(fn () => $vazio, self::CHECKLIST_ALAVANCAS);
    }

    /** O checklist gravado, completo e na ordem da planilha (chave nova entra pendente; chave estranha sai). */
    public function checklistCompleto(): array
    {
        $gravado = (array) ($this->checklist ?? []);
        $saida = [];
        foreach (self::checklistInicial() as $chave => $vazio) {
            $saida[$chave] = [...$vazio, ...array_intersect_key((array) ($gravado[$chave] ?? []), $vazio)];
        }

        return $saida;
    }

    /** Todos os itens resolvidos (feito ou não se aplica): só então a tarefa pode ser concluída. */
    public function checklistResolvido(): bool
    {
        foreach ($this->checklistCompleto() as $item) {
            if (! in_array($item['estado'], [self::ITEM_FEITO, self::ITEM_NAO_SE_APLICA], true)) {
                return false;
            }
        }

        return true;
    }

    /** @return list<string> os MLBs da tarefa */
    public function idsDosItens(): array
    {
        return array_values(array_filter(array_map(
            fn ($i) => is_array($i) && isset($i['ml_item_id']) ? (string) $i['ml_item_id'] : null,
            (array) ($this->itens ?? []),
        )));
    }

    public function aberta(): bool
    {
        return in_array($this->status, self::ABERTAS, true);
    }

    /** O prazo como data (o texto `Y-m-d` gravado). */
    public function prazoData(): ?CarbonImmutable
    {
        $texto = substr((string) $this->prazo, 0, 10);

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $texto) === 1
            ? CarbonImmutable::createFromFormat('!Y-m-d', $texto, config('app.timezone'))
            : null;
    }
}
