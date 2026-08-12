<?php

namespace Tests\Feature\Phase127;

use App\Models\ContratoAssinatura;
use App\Models\Servico;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Plano 127-04 (D-21 + D-03/DADOS-06): dois comportamentos que a montagem
 * do envelope da Fase 127 precisa — qual modelo `.docx` usar POR SERVIÇO
 * (com fallback pro `CLICKSIGN_TEMPLATE_ID` global) e qual prazo/lembrete
 * aplicar POR CONTRATO (com fallback pro padrão de configuração).
 *
 * `config()->set(...)` no corpo do teste em vez de mexer em `.env` — mesma
 * disciplina recomendada pela action do plano.
 */
class ModeloPorServicoTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Devolve os IDs de dois serviços distintos do catálogo (semeado por
     * migration, `Servico` não tem `HasFactory`). Mesma regra dos planos
     * anteriores da fase (127-01): nunca inventar valor novo para `setor`.
     *
     * @return array<int, int>
     */
    private function doisServicos(): array
    {
        $ids = Servico::query()->orderBy('id')->take(2)->pluck('id')->all();

        if (count($ids) >= 2) {
            return array_slice($ids, 0, 2);
        }

        $setorExistente = Servico::query()->value('setor') ?? Servico::SETOR_OUTROS;

        while (count($ids) < 2) {
            $novo = Servico::create([
                'nome'          => 'Serviço de teste '.uniqid(),
                'valor_padrao'  => 0,
                'tipo_cobranca' => Servico::TIPO_MENSAL,
                'ativo'         => true,
                'setor'         => $setorExistente,
            ]);

            $ids[] = $novo->id;
        }

        return $ids;
    }

    #[Test]
    public function servico_com_modelo_proprio_devolve_o_uuid_do_servico(): void
    {
        [$servicoId] = $this->doisServicos();
        $servico = Servico::find($servicoId);
        $servico->update(['clicksign_template_id' => 'uuid-do-servico-1234']);

        $this->assertSame('uuid-do-servico-1234', $servico->fresh()->clicksignTemplateId());
    }

    #[Test]
    public function servico_sem_modelo_proprio_cai_no_fallback_da_config(): void
    {
        [$servicoId] = $this->doisServicos();
        $servico = Servico::find($servicoId);
        $servico->update(['clicksign_template_id' => null]);

        config()->set('services.clicksign.template_id', 'uuid-padrao-da-config');

        $this->assertSame('uuid-padrao-da-config', $servico->fresh()->clicksignTemplateId());
    }

    #[Test]
    public function servico_sem_modelo_e_config_vazia_devolve_null(): void
    {
        [$servicoId] = $this->doisServicos();
        $servico = Servico::find($servicoId);
        $servico->update(['clicksign_template_id' => null]);

        config()->set('services.clicksign.template_id', null);

        $this->assertNull($servico->fresh()->clicksignTemplateId());
    }

    #[Test]
    public function dois_servicos_com_modelos_diferentes_devolvem_uuids_diferentes(): void
    {
        [$servicoAId, $servicoBId] = $this->doisServicos();

        $servicoA = Servico::find($servicoAId);
        $servicoB = Servico::find($servicoBId);

        $servicoA->update(['clicksign_template_id' => 'uuid-servico-a']);
        $servicoB->update(['clicksign_template_id' => 'uuid-servico-b']);

        $this->assertSame('uuid-servico-a', $servicoA->fresh()->clicksignTemplateId());
        $this->assertSame('uuid-servico-b', $servicoB->fresh()->clicksignTemplateId());
        $this->assertNotSame(
            $servicoA->fresh()->clicksignTemplateId(),
            $servicoB->fresh()->clicksignTemplateId()
        );
    }

    #[Test]
    public function prazo_dias_efetivo_usa_a_coluna_do_contrato_quando_preenchida_e_o_padrao_quando_nula(): void
    {
        [$servicoId] = $this->doisServicos();
        $company = \App\Models\Company::factory()->create();

        config()->set('services.clicksign.prazo_dias_padrao', 15);

        $comPrazo = ContratoAssinatura::create([
            'company_id' => $company->id,
            'servico_id' => $servicoId,
            'status'     => ContratoAssinatura::STATUS_RASCUNHO,
            'prazo_dias' => 10,
        ]);
        $this->assertSame(10, $comPrazo->prazoDiasEfetivo());

        $comPrazo->update(['status' => ContratoAssinatura::STATUS_CANCELADO]);

        $semPrazo = ContratoAssinatura::create([
            'company_id' => $company->id,
            'servico_id' => $servicoId,
            'status'     => ContratoAssinatura::STATUS_RASCUNHO,
            'prazo_dias' => null,
        ]);
        $this->assertSame(15, $semPrazo->prazoDiasEfetivo());
    }

    #[Test]
    public function lembrete_dias_efetivo_usa_a_coluna_do_contrato_quando_preenchida_e_o_padrao_quando_nula(): void
    {
        [$servicoId] = $this->doisServicos();
        $company = \App\Models\Company::factory()->create();

        config()->set('services.clicksign.lembrete_dias_padrao', 5);

        $comLembrete = ContratoAssinatura::create([
            'company_id'    => $company->id,
            'servico_id'    => $servicoId,
            'status'        => ContratoAssinatura::STATUS_RASCUNHO,
            'lembrete_dias' => 2,
        ]);
        $this->assertSame(2, $comLembrete->lembreteDiasEfetivo());

        $comLembrete->update(['status' => ContratoAssinatura::STATUS_CANCELADO]);

        $semLembrete = ContratoAssinatura::create([
            'company_id'    => $company->id,
            'servico_id'    => $servicoId,
            'status'        => ContratoAssinatura::STATUS_RASCUNHO,
            'lembrete_dias' => null,
        ]);
        $this->assertSame(5, $semLembrete->lembreteDiasEfetivo());
    }

    #[Test]
    public function defaults_de_configuracao_sem_env_valem_30_e_3(): void
    {
        $this->assertSame(30, (int) config('services.clicksign.prazo_dias_padrao'));
        $this->assertSame(3, (int) config('services.clicksign.lembrete_dias_padrao'));
    }
}
