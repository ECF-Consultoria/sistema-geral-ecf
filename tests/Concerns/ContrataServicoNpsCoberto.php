<?php

namespace Tests\Concerns;

use App\Models\Company;
use App\Models\Servico;
use Illuminate\Support\Facades\DB;

/**
 * Helper de teste da milestone v16.0 (Phase 79).
 *
 * A partir do disparo ESTRITO por serviços cobertos (DEC-79-A, Plano 79-03), o
 * comando `nps:disparar-mensal` só gera survey para empresa que tenha um
 * contrato ATIVO de um serviço coberto por algum modelo com envio automático.
 *
 * As suites de disparo anteriores (Phase 31/69/73) montavam empresas elegíveis
 * SEM contrato — o que, no modo estrito, resulta em 0 surveys. Este trait dá à
 * empresa um contrato performance ativo E garante o scope no template alvo
 * (NPS Padrão por default), tornando-a elegível no novo contrato do comando.
 *
 * Comentários em pt-BR conforme convenção do projeto.
 */
trait ContrataServicoNpsCoberto
{
    /**
     * Garante que a empresa fica elegível ao disparo estrito:
     *  - resolve (ou cria) um serviço performance ATIVO;
     *  - cria um contrato ATIVO desse serviço para a empresa;
     *  - vincula o serviço ao template alvo em nps_template_service_scopes
     *    (default: o modelo principal is_default — "NPS Padrão").
     *
     * @param  int|null  $templateId  Template a cobrir; null → is_default.
     * @return int  Id do serviço contratado/coberto.
     */
    protected function contratarServicoNpsCoberto(Company $empresa, ?int $templateId = null): int
    {
        $templateId ??= (int) DB::table('nps_templates')->where('is_default', true)->value('id');

        $servicoId = DB::table('servicos')
            ->where('setor', Servico::SETOR_PERFORMANCE)
            ->where('ativo', true)
            ->value('id');

        if ($servicoId === null) {
            $servicoId = DB::table('servicos')->insertGetId([
                'nome'          => 'Serviço Performance ' . uniqid(),
                'valor_padrao'  => 0,
                'tipo_cobranca' => Servico::TIPO_MENSAL,
                'ativo'         => true,
                'setor'         => Servico::SETOR_PERFORMANCE,
                'created_at'    => now(),
                'updated_at'    => now(),
            ]);
        }

        DB::table('contratos_servico')->insert([
            'company_id'       => $empresa->id,
            'servico_id'       => $servicoId,
            'valor_contratado' => 0,
            'data_contratacao' => now()->toDateString(),
            'ativo'            => true,
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);

        if ($templateId) {
            DB::table('nps_template_service_scopes')->updateOrInsert(
                ['template_id' => $templateId, 'servico_id' => (int) $servicoId],
                ['created_at' => now(), 'updated_at' => now()]
            );
        }

        return (int) $servicoId;
    }
}
