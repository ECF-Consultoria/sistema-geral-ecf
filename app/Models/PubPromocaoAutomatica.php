<?php

namespace App\Models;

use App\Support\Publicador\PrecoDaPromocao;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Um ciclo de 14 dias da promoção automática de UM anúncio publicado pelo Publicador (10/10/2026). A
 * linha nasce no gatilho pós-publicação (`PromocaoAutomaticaService::agendar`) ou na renovação diária
 * (`publicador:promocoes-renovar`), e o `CriarPromocaoAutomaticaJob` escreve pelo `EscritorAlavancas`.
 *
 * Status (texto livre, sem `enum`, ver a migration `2026_10_10_090000`):
 *  - `agendada`  — esperando o Job (ou o anúncio ficar ativo);
 *  - `enviando`  — o Job está escrevendo agora (preso aqui = interrompido: nunca reenvia sozinho);
 *  - `ativa`     — o desconto foi criado no Mercado Livre; renova no dia seguinte ao `fim`;
 *  - `recusada`  — o sistema não criou: a tarefa pós-publicação orienta a criar à mão;
 *  - `encerrada` — o ciclo acabou (renovado, ou não renovado porque o preço mudou ou o anúncio fechou);
 *  - `cancelada` — não era preciso ou não era seguro (o anúncio já tinha desconto, ou foi encerrado).
 *
 * `inicio`/`fim` ficam SEM cast `date` (texto `Y-m-d`), como o `prazo` de `pub_tarefas`.
 */
class PubPromocaoAutomatica extends Model
{
    protected $table = 'pub_promocoes_automaticas';

    public const AGENDADA = 'agendada';
    public const ENVIANDO = 'enviando';
    public const ATIVA = 'ativa';
    public const RECUSADA = 'recusada';
    public const ENCERRADA = 'encerrada';
    public const CANCELADA = 'cancelada';

    public const STATUS = [self::AGENDADA, self::ENVIANDO, self::ATIVA, self::RECUSADA, self::ENCERRADA, self::CANCELADA];

    public const MOTIVO_MAX = 500;

    protected $guarded = ['id'];

    protected $casts = [
        'preco_publicado' => 'float',
        'preco_promocao' => 'float',
        'percentual' => 'float',
        'ciclo' => 'integer',
        'tentativas' => 'integer',
        'proxima_tentativa_em' => 'datetime',
    ];

    public function publicacao(): BelongsTo
    {
        return $this->belongsTo(PubPublicacao::class, 'publicacao_id');
    }

    public function escrita(): BelongsTo
    {
        return $this->belongsTo(PubAlavancaEscrita::class, 'escrita_id');
    }

    /** O último ciclo de cada anúncio da lista (o que a fila de tarefas mostra). */
    public function scopeUltimoCicloDe(Builder $q, array $mlbs): Builder
    {
        return $q->whereIn('ml_item_id', $mlbs)
            ->whereRaw('ciclo = (select max(p2.ciclo) from pub_promocoes_automaticas p2 where p2.ml_item_id = pub_promocoes_automaticas.ml_item_id)');
    }

    public function tipo(): string
    {
        return ['gold_special' => 'Clássico', 'gold_pro' => 'Premium'][$this->listing_type] ?? (string) $this->listing_type;
    }

    /** O fim como data (`dd/mm` nas mensagens). */
    public function fimData(): ?CarbonImmutable
    {
        $texto = substr((string) $this->fim, 0, 10);

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $texto) === 1
            ? CarbonImmutable::createFromFormat('!Y-m-d', $texto, 'America/Sao_Paulo')
            : null;
    }

    /**
     * O que o colaborador faz à mão quando o sistema não criou (a frase vai para a tarefa):
     * "Crie a promoção de R$ 207,19 para R$ 172,66 (−16,67%) até 22/10 no Seller Center."
     */
    public function orientacao(): string
    {
        $ate = $this->fimData()?->format('d/m') ?? '—';

        return 'Crie a promoção de '.PrecoDaPromocao::reais((float) $this->preco_publicado).' para '.PrecoDaPromocao::reais((float) $this->preco_promocao)
            .' (−'.PrecoDaPromocao::pct((float) $this->percentual).'%) até '.$ate.' no Seller Center.';
    }
}
