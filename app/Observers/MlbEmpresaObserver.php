<?php

namespace App\Observers;

use App\Models\MlbEmpresa;
use App\Models\Publicacao;
use Illuminate\Support\Facades\Log;

/**
 * Propaga o Cust ID da empresa para as publicações que nasceram sem ele.
 *
 * Por que existe: o formulário de registro copia o Cust ID da empresa no ato do
 * cadastro (`cust_id: empresa.cust_id ?? ''` em Pages/Mlb/Publicacoes.jsx). Se a
 * empresa ainda não tinha Cust ID, a publicação nascia com string vazia e ficava
 * assim para sempre — e `VendasSyncService::syncEmpresa` casa vendas por
 * `cust_id`, então aquela linha NUNCA mais puxava venda.
 *
 * O contorno que o time encontrou foi excluir e cadastrar de novo, o que joga a
 * produção para a competência do dia do recadastro e distorce meta e conversão
 * do mês (auditoria de 2026-08-04: 465 publicações sem Cust ID, 93 anúncios
 * recadastrados em competência diferente).
 *
 * Observer e não edição de call-site: `cust_id` é gravado em quatro lugares
 * (updateEmpresa, updateCustIdEmpresa do Painel Polos, storeEmpresa e o comando
 * polos:sync-planilha) e todos passam por Eloquent. Um ponto só cobre os quatro
 * e qualquer caminho novo.
 */
class MlbEmpresaObserver
{
    public function created(MlbEmpresa $empresa): void
    {
        // Empresa nasce com Cust ID: raro, mas pode haver publicação de Registro
        // Livre já apontando para ela.
        $this->propagarCustId($empresa);
    }

    public function updated(MlbEmpresa $empresa): void
    {
        if (! $empresa->wasChanged('cust_id')) {
            return;
        }

        $this->propagarCustId($empresa);
    }

    /**
     * Preenche o cust_id APENAS das publicações que estão com o campo vazio.
     *
     * Nunca sobrescreve valor existente: se a empresa trocou de conta ML, as
     * publicações antigas pertencem à conta antiga e o histórico de vendas delas
     * tem que continuar casando com o cust_id de origem. Reatribuir em massa
     * apagaria faturamento já apurado.
     */
    private function propagarCustId(MlbEmpresa $empresa): void
    {
        $custId = trim((string) $empresa->cust_id);

        if ($custId === '') {
            return;
        }

        $afetadas = Publicacao::where('mlb_empresa_id', $empresa->id)
            ->where(function ($q) {
                $q->whereNull('cust_id')->orWhere('cust_id', '');
            })
            ->update(['cust_id' => $custId]);

        if ($afetadas === 0) {
            return;
        }

        Log::info("[MLB CustID] Propagado para {$afetadas} publicação(ões) da empresa {$empresa->id} ({$empresa->nome}) — cust_id {$custId}");

        // Trilha de auditoria: sem isso o preenchimento em massa fica invisível,
        // que é exatamente o problema que a exclusão de publicação já tinha.
        activity('mlb')
            ->withProperties([
                'mlb_empresa_id' => $empresa->id,
                'empresa'        => $empresa->nome,
                'cust_id'        => $custId,
                'publicacoes'    => $afetadas,
            ])
            ->log("Cust ID propagado para {$afetadas} publicação(ões) da empresa \"{$empresa->nome}\"");
    }
}
