<?php

namespace App\Observers;

use App\Models\Company;
use App\Models\EstruturaOferta;
use App\Services\Trilha\Ondas\OndasDaTrilha;
use App\Services\Trilha\TrilhaDaEmpresa;
use App\Support\Trilha\TrilhaDeTrabalho;
use Illuminate\Support\Facades\Log;

/**
 * Trilha de produtos (02/11/2026): o Combo que DEIXOU DE EXISTIR — a última cor dele apagada — sai da onda NA HORA,
 * no mesmo instante (e na mesma transação) da exclusão. Sem isso, o Combo N aceito de novo depois herdaria a onda
 * antiga, que pode até já ter começado.
 *
 * Observer e não chamada no serviço: o Portal apaga oferta por `EstruturaOfertaService::excluir`, mas qualquer outro
 * caminho Eloquent também passa aqui. A soltura e o planejar seguem limpando as órfãs, como rede de segurança.
 *
 * Antes isso era DEDUZIDO pela idade (`comboRecriado`: todas as ofertas do combo mais novas que a linha da onda) — e
 * errava: o combo que ganhou uma cor nova e depois perdeu a original nunca deixou de existir, mas parecia "recriado"
 * e perdia a onda, até a que já tinha começado (revisão adversarial de 02/11/2026).
 *
 * Só na trilha de trabalho, e nunca derruba a exclusão: um erro aqui vira aviso no log.
 */
class TrilhaComboExcluidoObserver
{
    public function deleted(EstruturaOferta $oferta): void
    {
        if ($oferta->fase !== EstruturaOferta::FASE_COMBO || $oferta->company_id === null
            || ! TrilhaDeTrabalho::ligada((int) $oferta->company_id)) {
            return;
        }

        try {
            $empresa = Company::query()->find($oferta->company_id);
            if ($empresa !== null) {
                OndasDaTrilha::limparCombosOrfaos($empresa, TrilhaDaEmpresa::itens($empresa));
            }
        } catch (\Throwable $e) {
            Log::warning("[Trilha] Combo {$oferta->sku} apagado na empresa {$oferta->company_id}: a linha da onda não foi conferida agora (a soltura confere na passada seguinte). ".$e->getMessage());
        }
    }
}
