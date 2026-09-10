<?php

namespace Tests\Feature\Phase151;

use App\Models\Company;
use App\Models\ContratoServico;
use App\Models\Servico;
use App\Models\User;
use App\Services\FluxoEntrada\EtapaTransicaoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Fase 151 Plano 05 (COMERC-03, D-05/D-07/D-14) — fronteira de visibilidade
 * da listagem Entrada nas etapas do §10.
 *
 * A etapa só se move pelo ponto único `EtapaTransicaoService::transicionar()`
 * (Fase 150, D-12) — nunca por um `update()` direto na coluna, nem aqui nem
 * em produção. Cada estado inicial de fixture usa a FACTORY (`create`), e
 * toda MUDANÇA de etapa depois de criada passa pelo serviço.
 */
class ComercVisibilidadeAteEtapa5Test extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function empresaNaEtapa(?string $etapa, array $overrides = []): Company
    {
        return Company::factory()->create(array_merge(['active' => true, 'etapa' => $etapa], $overrides));
    }

    private function nomesNaListagemEntrada(): array
    {
        $response = $this->actingAs($this->admin())->get(route('comercial.entrada.index'));
        $response->assertOk();

        return collect($response->viewData('page')['props']['companies']['data'])->pluck('name')->all();
    }

    /**
     * D-07 — empresa em `administrativo_concluido` (a 4ª etapa) APARECE. Sair
     * na 4 deixaria a empresa órfã entre o Comercial e a Coordenação, sem
     * ninguém capaz de agir sobre ela.
     */
    public function test_empresa_em_administrativo_concluido_aparece(): void
    {
        $empresa = $this->empresaNaEtapa(Company::ETAPA_ADMINISTRATIVO_CONCLUIDO, ['name' => 'Empresa Concluído Etapa 4']);

        $this->assertContains('Empresa Concluído Etapa 4', $this->nomesNaListagemEntrada());
    }

    /**
     * D-07 — a MESMA empresa, transicionada para `aguardando_distribuicao`
     * (a 5ª) PELO SERVIÇO (nunca por update() direto), SOME da listagem.
     * Relida do banco antes de assertar — o objeto em memória não decide nada.
     */
    public function test_empresa_transicionada_para_aguardando_distribuicao_pelo_servico_some(): void
    {
        $empresa = $this->empresaNaEtapa(Company::ETAPA_ADMINISTRATIVO_CONCLUIDO, ['name' => 'Empresa Que Vai Sumir']);

        $this->assertContains('Empresa Que Vai Sumir', $this->nomesNaListagemEntrada());

        $resultado = app(EtapaTransicaoService::class)->transicionar(
            $empresa,
            Company::ETAPA_AGUARDANDO_DISTRIBUICAO,
            $this->admin(),
        );
        $this->assertSame('transicionado', $resultado['status']);

        // Releitura do banco — nunca confia só no objeto em memória.
        $empresa->refresh();
        $this->assertSame(Company::ETAPA_AGUARDANDO_DISTRIBUICAO, $empresa->etapa);

        $this->assertNotContains('Empresa Que Vai Sumir', $this->nomesNaListagemEntrada());
    }

    /** Empresa em cada uma das etapas 1, 2 e 3 aparece na listagem Entrada. */
    public function test_empresas_nas_etapas_1_2_e_3_aparecem(): void
    {
        $etapa1 = $this->empresaNaEtapa(Company::ETAPA_AGUARDANDO_ADMINISTRATIVO, ['name' => 'Empresa Etapa 1 Fronteira']);
        $etapa2 = $this->empresaNaEtapa(Company::ETAPA_ADMINISTRATIVO_ANDAMENTO, ['name' => 'Empresa Etapa 2 Fronteira']);
        $etapa3 = $this->empresaNaEtapa(Company::ETAPA_AGUARDANDO_ASSINATURA, ['name' => 'Empresa Etapa 3 Fronteira']);

        $nomes = $this->nomesNaListagemEntrada();

        $this->assertContains('Empresa Etapa 1 Fronteira', $nomes);
        $this->assertContains('Empresa Etapa 2 Fronteira', $nomes);
        $this->assertContains('Empresa Etapa 3 Fronteira', $nomes);
    }

    /** D-07 — qualquer etapa a partir da 5ª (distribuição em diante) não aparece. */
    public function test_empresas_da_etapa_5_em_diante_nao_aparecem(): void
    {
        $etapasForaDoUniverso = [
            Company::ETAPA_AGUARDANDO_DISTRIBUICAO,
            Company::ETAPA_AGUARDANDO_ONBOARDING,
            Company::ETAPA_ONBOARDING_ANDAMENTO,
            Company::ETAPA_ONBOARDING_CONCLUIDO,
            Company::ETAPA_EM_OPERACAO,
        ];

        $nomesEsperadosFora = [];
        foreach ($etapasForaDoUniverso as $i => $etapa) {
            $nome = "Empresa Fora Do Universo {$i}";
            $this->empresaNaEtapa($etapa, ['name' => $nome]);
            $nomesEsperadosFora[] = $nome;
        }

        $nomes = $this->nomesNaListagemEntrada();

        foreach ($nomesEsperadosFora as $nome) {
            $this->assertNotContains($nome, $nomes);
        }
    }

    /** D-14 — empresa legado com `etapa` NULL nunca aparece na listagem Entrada. */
    public function test_empresa_com_etapa_null_nao_aparece(): void
    {
        $this->empresaNaEtapa(null, ['name' => 'Empresa Legado Fronteira']);

        $this->assertNotContains('Empresa Legado Fronteira', $this->nomesNaListagemEntrada());
    }

    /** Empresa inativa (`active = false`) não aparece mesmo estando em etapa do universo. */
    public function test_empresa_inativa_nao_aparece(): void
    {
        $this->empresaNaEtapa(Company::ETAPA_ADMINISTRATIVO_ANDAMENTO, [
            'name'   => 'Empresa Inativa Fronteira',
            'active' => false,
        ]);

        $this->assertNotContains('Empresa Inativa Fronteira', $this->nomesNaListagemEntrada());
    }

    /**
     * D-05 — a mesma empresa pode aparecer nas listagens Contrato E Entrada
     * ao mesmo tempo: a separação é por PROCESSO PENDENTE, não por etapa.
     * Este teste é a trava contra "otimizar" as duas listas para serem
     * mutuamente exclusivas por etapa numa sessão futura — ele assere sobre
     * as DUAS rotas.
     */
    public function test_empresa_em_fluxo_de_entrada_com_contrato_ativo_aparece_nas_duas_listagens(): void
    {
        $servico = Servico::create([
            'nome'           => 'Gestão de Tráfego (fronteira D-05)',
            'valor_padrao'   => 100,
            'tipo_cobranca'  => Servico::TIPO_MENSAL,
            'ativo'          => true,
            'setor'          => Servico::SETOR_PERFORMANCE,
            'exige_contrato' => true,
        ]);

        $empresa = $this->empresaNaEtapa(Company::ETAPA_ADMINISTRATIVO_ANDAMENTO, ['name' => 'Empresa Nas Duas Listagens']);
        ContratoServico::create([
            'company_id'       => $empresa->id,
            'servico_id'       => $servico->id,
            'valor_contratado' => 100,
            'data_contratacao' => now()->toDateString(),
            'ativo'            => true,
        ]);

        $admin = $this->admin();

        $respEntrada = $this->actingAs($admin)->get(route('comercial.entrada.index'));
        $respEntrada->assertOk();
        $nomesEntrada = collect($respEntrada->viewData('page')['props']['companies']['data'])->pluck('name')->all();

        $respContrato = $this->actingAs($admin)->get(route('admin.contratos.index'));
        $respContrato->assertOk();
        $nomesContrato = collect($respContrato->viewData('page')['props']['linhas']['data'])->pluck('company_nome')->all();

        $this->assertContains('Empresa Nas Duas Listagens', $nomesEntrada, 'a empresa precisa aparecer na listagem Entrada');
        $this->assertContains('Empresa Nas Duas Listagens', $nomesContrato, 'a empresa precisa aparecer TAMBÉM na listagem Contrato — D-05');
    }
}
