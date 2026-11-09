<?php

namespace Tests\Feature\Phase202;

use App\Services\Renovacao\RenovacaoDecisao as D;
use App\Services\Renovacao\RenovacaoDecisaoPolicy;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;

/**
 * Fase 202 (REN-04) — matriz de decisão §4.3.4, isolada (política pura).
 */
class RenovacaoDecisaoPolicyTest extends TestCase
{
    private function ctx(array $over = []): array
    {
        return array_merge([
            'elegivel'               => true,
            'status_comercial'       => 'identificado',
            'status_integracao'      => 'pendente_sincronizacao',
            'tentativas'             => 0,
            'max_tentativas'         => 3,
            'incerto_desde'          => null,
            'tentativa_remota'       => false,
            'agora'                  => Carbon::parse('2026-11-06 12:00:00'),
            'janela_incerto_minutos' => 60,
            'tem_hubspot_company'    => true,
            'liberar_criacao'        => false,
            'negocio_vinculado_id'   => null,
            'negocio_vinculado'      => null,
            'correlacao'             => [],
            'pipeline_renovacao'     => 'P1',
            'etapas_abertas'         => ['E1', 'E2'],
            'etapa_ganho'            => 'EG',
            'etapas_perdido'         => ['EP'],
        ], $over);
    }

    private function decidir(array $over = []): D
    {
        return (new RenovacaoDecisaoPolicy())->decidir($this->ctx($over));
    }

    private function vinculado(string $pipeline = 'P1', ?string $etapa = 'E1', bool $existe = true): array
    {
        return [
            'negocio_vinculado_id' => 'N1',
            'negocio_vinculado'    => ['existe' => $existe, 'pipeline' => $pipeline, 'etapa' => $etapa],
        ];
    }

    public function test_inelegivel_sem_negocio(): void
    {
        $this->assertSame(D::NAO_ELEGIVEL, $this->decidir(['elegivel' => false])->acao);
    }

    public function test_inelegivel_com_negocio_aberto_vai_para_revisao(): void
    {
        $d = $this->decidir(['elegivel' => false] + $this->vinculado());
        $this->assertSame(D::REVISAO_MANUAL, $d->acao);
        $this->assertSame('contrato_inelegivel_com_negocio_aberto', $d->motivo);
    }

    public function test_status_final_nada_a_fazer(): void
    {
        foreach (['renovado', 'nao_renovado', 'dispensado'] as $s) {
            $this->assertSame(D::NADA_A_FAZER, $this->decidir(['status_comercial' => $s])->acao, $s);
        }
    }

    public function test_vinculado_em_etapa_aberta_concilia_sem_criar(): void
    {
        $d = $this->decidir($this->vinculado('P1', 'E2'));
        $this->assertSame(D::CONCILIAR_ABERTO, $d->acao);
        $this->assertSame('N1', $d->negocioId);
    }

    public function test_vinculado_ganho_e_perdido(): void
    {
        $this->assertSame(D::REGISTRAR_GANHO, $this->decidir($this->vinculado('P1', 'EG'))->acao);
        $this->assertSame(D::REGISTRAR_PERDA, $this->decidir($this->vinculado('P1', 'EP'))->acao);
    }

    public function test_vinculado_removido_fora_do_pipeline_e_etapa_desconhecida(): void
    {
        $this->assertSame('vinculado_removido', $this->decidir($this->vinculado('P1', 'E1', false))->motivo);
        $this->assertSame('vinculado_fora_do_pipeline', $this->decidir($this->vinculado('OUTRO', 'E1'))->motivo);
        $this->assertSame('etapa_desconhecida', $this->decidir($this->vinculado('P1', 'XX'))->motivo);
        $this->assertSame(D::REVISAO_MANUAL, $this->decidir($this->vinculado('P1', 'XX'))->acao);
    }

    public function test_correlacao_duplicada(): void
    {
        $d = $this->decidir(['correlacao' => [['id' => 'A'], ['id' => 'B']]]);
        $this->assertSame(D::REVISAO_MANUAL, $d->acao);
        $this->assertSame('correlacao_duplicada', $d->motivo);
    }

    public function test_correlacao_com_negocio_diferente_do_vinculado_e_conflito(): void
    {
        $d = $this->decidir($this->vinculado() + ['correlacao' => [['id' => 'OUTRO', 'pipeline' => 'P1', 'etapa' => 'E1']]]);
        $this->assertSame('conflito_de_vinculo', $d->motivo);
    }

    public function test_sem_vinculo_correlacao_com_um_vincula(): void
    {
        $d = $this->decidir(['correlacao' => [['id' => 'N9', 'pipeline' => 'P1', 'etapa' => 'E1']]]);
        $this->assertSame(D::VINCULAR_POR_CORRELACAO, $d->acao);
        $this->assertSame('N9', $d->negocioId);
    }

    public function test_criando_dentro_da_janela_aguarda_e_depois_vai_para_revisao(): void
    {
        $recente = Carbon::parse('2026-11-06 11:30:00');
        $antigo  = Carbon::parse('2026-11-06 10:30:00');

        foreach (['criando', 'resultado_incerto'] as $status) {
            $this->assertSame(D::AGUARDAR_CONCILIACAO, $this->decidir(['status_integracao' => $status, 'incerto_desde' => $recente])->acao, $status);

            $d = $this->decidir(['status_integracao' => $status, 'incerto_desde' => $antigo]);
            $this->assertSame(D::REVISAO_MANUAL, $d->acao, $status);
            $this->assertSame('resultado_incerto_sem_negocio', $d->motivo, $status);
        }
    }

    public function test_tentativa_remota_duravel_nunca_cria_qualquer_status(): void
    {
        $recente = Carbon::parse('2026-11-06 11:30:00');
        $antigo  = Carbon::parse('2026-11-06 10:30:00');

        foreach (['revisao_manual', 'erro', 'pendente_sincronizacao'] as $status) {
            $base = ['tentativa_remota' => true, 'status_integracao' => $status];

            $this->assertSame(D::AGUARDAR_CONCILIACAO, $this->decidir($base + ['incerto_desde' => $recente])->acao, $status);

            $d = $this->decidir($base + ['incerto_desde' => $antigo]);
            $this->assertSame(D::REVISAO_MANUAL, $d->acao, $status);
            $this->assertSame('tentativa_remota_sem_negocio', $d->motivo, $status);
        }
    }

    public function test_tentativa_remota_liberada_cria_so_depois_da_janela(): void
    {
        $base = ['tentativa_remota' => true, 'liberar_criacao' => true, 'status_integracao' => 'revisao_manual'];

        $this->assertSame(D::CRIAR, $this->decidir($base + ['incerto_desde' => Carbon::parse('2026-11-06 10:30:00')])->acao);
        $this->assertSame(D::AGUARDAR_CONCILIACAO, $this->decidir($base + ['incerto_desde' => Carbon::parse('2026-11-06 11:30:00')])->acao);
    }

    public function test_revisao_manual_sem_tentativa_remota_nao_cria(): void
    {
        $this->assertSame(D::REVISAO_MANUAL, $this->decidir(['status_integracao' => 'revisao_manual'])->acao);
        $this->assertSame(D::CRIAR, $this->decidir(['status_integracao' => 'revisao_manual', 'liberar_criacao' => true])->acao);
    }

    public function test_empresa_sem_hubspot_cria_mesmo_assim(): void
    {
        // 09/11/2026 — decisão do usuário: sem empresa no HubSpot o negócio de
        // renovação sai associado ao contato ou sem associação; não bloqueia.
        $d = $this->decidir(['tem_hubspot_company' => false]);
        $this->assertSame(D::CRIAR, $d->acao);
    }

    public function test_tentativas_esgotadas_e_liberacao_ignora_contador(): void
    {
        $this->assertSame('tentativas_esgotadas', $this->decidir(['tentativas' => 3])->motivo);
        $this->assertSame(D::CRIAR, $this->decidir(['tentativas' => 3, 'liberar_criacao' => true])->acao);
    }

    public function test_sem_vinculo_correlacao_vazia_cria(): void
    {
        $this->assertSame(D::CRIAR, $this->decidir()->acao);
    }

    public function test_busca_nao_executada_nunca_cria(): void
    {
        $d = $this->decidir(['correlacao' => null]);
        $this->assertSame(D::ERRO_LEITURA, $d->acao);
    }

    public function test_politica_nao_depende_de_banco_http_ou_config(): void
    {
        $fonte = file_get_contents(__DIR__ . '/../../../app/Services/Renovacao/RenovacaoDecisaoPolicy.php');
        $linhas = array_filter(explode("\n", $fonte), fn ($l) => ! preg_match('/^\s*(\/\/|\*|\/\*)/', $l));

        $this->assertSame(0, preg_match('/Http::|DB::|config\(|Configuracao::/', implode("\n", $linhas)));
    }
}
