<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Snapshot durável do último faturamento/ADS Adman por mês/empresa do /polos
 * (quick 260622-cq0).
 *
 * Dá persistência além do cache da Adman: o /polos lê o mês corrente SÓ do cache,
 * cuja chave inclui a data de hoje (AdmanService::cacheDay). À meia-noite BRT a chave
 * rotaciona e o cache do novo dia fica vazio — e em produção um flush/restart do Redis
 * zera tudo. Este snapshot guarda o último valor bom para o controller servir enquanto
 * o cache do dia ainda não foi aquecido, evitando o R$0.
 *
 * Gravado pelo SyncPolosFaturamentoJob (só quando a Adman responde com sucesso — nunca
 * sobrescreve um valor bom com erro/timeout) e lido como fallback pelo PolosController.
 *
 * Tabela técnica de snapshot, não entidade de domínio → sem LogsActivity.
 */
class PoloFaturamentoSnapshot extends Model
{
    // O plural automático do Eloquent geraria 'polo_faturamento_snapshots' — a tabela
    // (e o restante do módulo) usa 'polos_*'. Fixar explicitamente.
    protected $table = 'polos_faturamento_snapshots';

    protected $fillable = [
        'mes',
        'cust_id',
        'faturamento',
        'faturamento_moveis',
        'ads',
        'synced_at',
    ];

    protected $casts = [
        'faturamento'        => 'float',
        'faturamento_moveis' => 'float',
        'ads'                => 'float',
        'synced_at'          => 'datetime',
    ];
}
