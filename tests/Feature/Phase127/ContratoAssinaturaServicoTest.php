<?php

namespace Tests\Feature\Phase127;

use App\Models\Company;
use App\Models\ContratoAssinatura;
use App\Models\Servico;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Prova de comportamento da D-06 (Fase 127-01): a trava de unicidade de
 * `contrato_assinaturas` em andamento deixa de ser por EMPRESA e passa a
 * ser por (EMPRESA + SERVIÇO). Antes desta migration, o segundo serviço de
 * qualquer empresa batia sempre na constraint `ca_company_andamento_uniq`
 * da Fase 125 — determinístico, não corrida (Pitfall 1 da pesquisa).
 *
 * Usa `ContratoAssinatura::create()` (não `DB::table()`) porque o alvo aqui
 * é o comportamento do MODEL (hook `saving` das duas colunas derivadas) —
 * o teste de schema cru que prova a trava do BANCO vive em
 * `tests/Feature/Phase125/ContratoAssinaturaSchemaTest.php` (Task 4).
 */
class ContratoAssinaturaServicoTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Devolve os IDs de dois serviços distintos do catálogo (semeado por
     * migration, `Servico` não tem `HasFactory`). Se o catálogo tiver menos
     * de 2 registros, completa copiando o `setor` de um serviço existente —
     * nunca inventar um valor novo: a coluna `setor` é enum legado e o
     * CHECK do SQLite derruba a suíte inteira.
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
    public function empresa_com_dois_servicos_diferentes_tem_dois_contratos_em_rascunho_ao_mesmo_tempo(): void
    {
        $company = Company::factory()->create();
        [$servicoA, $servicoB] = $this->doisServicos();

        $contratoA = ContratoAssinatura::create([
            'company_id' => $company->id,
            'servico_id' => $servicoA,
            'status'     => ContratoAssinatura::STATUS_RASCUNHO,
        ]);

        $contratoB = ContratoAssinatura::create([
            'company_id' => $company->id,
            'servico_id' => $servicoB,
            'status'     => ContratoAssinatura::STATUS_RASCUNHO,
        ]);

        $this->assertTrue($contratoA->exists);
        $this->assertTrue($contratoB->exists);
        $this->assertSame(2, ContratoAssinatura::where('company_id', $company->id)->count());
    }

    #[Test]
    public function empresa_com_dois_contratos_do_mesmo_servico_em_andamento_estoura_query_exception(): void
    {
        $company = Company::factory()->create();
        [$servico] = $this->doisServicos();

        ContratoAssinatura::create([
            'company_id' => $company->id,
            'servico_id' => $servico,
            'status'     => ContratoAssinatura::STATUS_RASCUNHO,
        ]);

        $this->expectException(QueryException::class);

        ContratoAssinatura::create([
            'company_id' => $company->id,
            'servico_id' => $servico,
            'status'     => ContratoAssinatura::STATUS_AGUARDANDO_ASSINATURAS,
        ]);
    }

    #[Test]
    public function hook_preenche_as_duas_colunas_derivadas_juntas_e_zera_as_duas_juntas(): void
    {
        $company = Company::factory()->create();
        [$servico] = $this->doisServicos();

        $contrato = ContratoAssinatura::create([
            'company_id' => $company->id,
            'servico_id' => $servico,
            'status'     => ContratoAssinatura::STATUS_RASCUNHO,
        ]);

        $contrato = $contrato->fresh();
        $this->assertSame($company->id, $contrato->company_id_em_andamento);
        $this->assertSame($servico, $contrato->servico_id_em_andamento);

        $contrato->status = ContratoAssinatura::STATUS_ASSINADO;
        $contrato->save();

        $contrato = $contrato->fresh();
        $this->assertNull($contrato->company_id_em_andamento);
        $this->assertNull($contrato->servico_id_em_andamento);
    }

    #[Test]
    public function sair_de_andamento_libera_o_slot_para_um_contrato_novo_do_mesmo_servico(): void
    {
        $company = Company::factory()->create();
        [$servico] = $this->doisServicos();

        $contratoA = ContratoAssinatura::create([
            'company_id' => $company->id,
            'servico_id' => $servico,
            'status'     => ContratoAssinatura::STATUS_RASCUNHO,
        ]);

        $contratoA->status = ContratoAssinatura::STATUS_CANCELADO;
        $contratoA->save();

        $contratoB = ContratoAssinatura::create([
            'company_id' => $company->id,
            'servico_id' => $servico,
            'status'     => ContratoAssinatura::STATUS_RASCUNHO,
        ]);

        $this->assertTrue($contratoB->exists);
    }

    #[Test]
    public function em_andamento_do_servico_devolve_o_contrato_do_servico_pedido_e_null_para_outro(): void
    {
        $company = Company::factory()->create();
        [$servicoA, $servicoB] = $this->doisServicos();

        $contrato = ContratoAssinatura::create([
            'company_id' => $company->id,
            'servico_id' => $servicoA,
            'status'     => ContratoAssinatura::STATUS_RASCUNHO,
        ]);

        $encontrado = ContratoAssinatura::emAndamentoDoServico($company->id, $servicoA);
        $this->assertNotNull($encontrado);
        $this->assertSame($contrato->id, $encontrado->id);

        $this->assertNull(ContratoAssinatura::emAndamentoDoServico($company->id, $servicoB));
    }

    #[Test]
    public function em_andamento_da_empresa_continua_devolvendo_qualquer_contrato_em_andamento(): void
    {
        $company = Company::factory()->create();
        [$servico] = $this->doisServicos();

        ContratoAssinatura::create([
            'company_id' => $company->id,
            'servico_id' => $servico,
            'status'     => ContratoAssinatura::STATUS_RASCUNHO,
        ]);

        $encontrado = ContratoAssinatura::emAndamentoDaEmpresa($company->id);
        $this->assertNotNull($encontrado);
        $this->assertSame($company->id, $encontrado->company_id);
    }
}
