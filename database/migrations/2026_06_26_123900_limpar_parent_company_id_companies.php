<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Quick 260626-ddp — desfaz os agrupamentos pai/filhas (parent_company_id).
 *
 * O Fechamento (e os relatórios) passaram a consolidar pelos grupos nomeados do
 * Comercial (CompanyGroup / company_group_id). A hierarquia antiga matriz/filiais
 * (parent_company_id) ficou órfã de UI e não agrupa mais cobrança — limpamos os
 * vínculos para não confundir (autorizado: "desfazer todos"). A COLUNA é
 * preservada (não dropamos o schema), apenas zeramos os dados.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('companies')
            ->whereNotNull('parent_company_id')
            ->update(['parent_company_id' => null]);
    }

    /**
     * Irreversível por design: o vínculo pai/filha original não é recuperável
     * depois de zerado. down() é no-op proposital.
     */
    public function down(): void
    {
        // Sem rollback — os vínculos pai/filhas foram intencionalmente desfeitos.
    }
};
