<?php

namespace Tests\Feature\Phase130;

use App\Models\Company;
use App\Models\ContratoAssinatura;
use App\Models\ContratoLiberacao;
use App\Models\Servico;
use App\Models\User;
use App\Services\Contratos\ContratosPresosService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Fase 130 Plano 04 (D-11) — prova que a tela recebe o dado necessário para
 * mostrar o estado real do contrato (recusado/erro/etc.) em destaque ANTES
 * de confirmar, e que a liberação manual sucede mesmo nesses estados —
 * exatamente o cenário para o qual ela foi criada.
 *
 * ⚠️ Reapontado pelo plano 131-06 (D-10): o GET que antes ia para a
 * listagem própria da Fase 130 agora vai para o detalhe da empresa
 * (`admin.contratos.show`), componente `Admin/ContratoDetalhe`. O POST vai
 * para `admin.contratos.liberacao-manual`. A prop `contratos` continua com
 * o mesmo NOME, mas cada item agora pertence a UMA empresa (a da URL) em
 * vez de listar várias — o campo `causa` que a D-11 depende continua vindo
 * de `ContratosPresosService::causa()`.
 */
class LiberacaoManualEstadoRealTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function servico(string $nome = 'Assessoria (estado real)'): Servico
    {
        return Servico::create([
            'nome'          => $nome,
            'valor_padrao'  => 0,
            'tipo_cobranca' => Servico::TIPO_MENSAL,
            'ativo'         => true,
        ]);
    }

    public function test_get_com_contrato_recusado_traz_status_e_causa_certos(): void
    {
        $admin   = $this->admin();
        $company = Company::factory()->create(['name' => 'Empresa Recusada']);
        $servico = $this->servico();

        // dataBase() de um estado "default" (recusado/expirado/cancelado/erro)
        // é `enviado_em ?? created_at` (correção 260814-cro — NUNCA
        // `updated_at`, que zeraria o contador a cada escrita no contrato).
        // Caso realista: o contrato foi enviado e o cliente recusou.
        $contrato = ContratoAssinatura::factory()->create([
            'company_id' => $company->id,
            'servico_id' => $servico->id,
            'status'     => ContratoAssinatura::STATUS_RECUSADO,
            'enviado_em' => now()->subDays(10),
        ]);

        $response = $this->actingAs($admin)->get(route('admin.contratos.show', $company));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/ContratoDetalhe')
            ->where('contratos.0.id', $contrato->id)
            ->where('contratos.0.status', ContratoAssinatura::STATUS_RECUSADO)
            ->where('contratos.0.causa', ContratosPresosService::CAUSA_RECUSADO)
        );
    }

    public function test_get_com_contrato_em_erro_distingue_de_recusado(): void
    {
        $admin   = $this->admin();
        $company = Company::factory()->create(['name' => 'Empresa Erro Tecnico']);
        $servico = $this->servico();

        // Caso realista: a integração falhou antes do envio — sem
        // `enviado_em`, a data base cai no fallback `created_at` (o outro
        // ramo da nova dataBase(), cobrindo os dois caminhos).
        $contrato = ContratoAssinatura::factory()->create([
            'company_id' => $company->id,
            'servico_id' => $servico->id,
            'status'     => ContratoAssinatura::STATUS_ERRO,
        ]);
        $contrato->forceFill(['created_at' => now()->subDays(10)])->save();

        $response = $this->actingAs($admin)->get(route('admin.contratos.show', $company));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/ContratoDetalhe')
            ->where('contratos.0.id', $contrato->id)
            ->where('contratos.0.status', ContratoAssinatura::STATUS_ERRO)
            ->where('contratos.0.causa', ContratosPresosService::CAUSA_ERRO)
        );
    }

    public function test_prop_motivos_manuais_traz_as_quatro_chaves_com_rotulos_nao_vazios(): void
    {
        $admin   = $this->admin();
        $company = Company::factory()->create();

        $response = $this->actingAs($admin)->get(route('admin.contratos.show', $company));

        $response->assertOk();
        $response->assertInertia(function ($page) {
            $page->component('Admin/ContratoDetalhe');

            // Plano 131-06 (D-10) — prop renomeada de `motivos` para
            // `motivos_manuais` na tela de detalhe (mesmo array de rótulos).
            $motivos = $page->toArray()['props']['motivos_manuais'];

            foreach (ContratoLiberacao::MOTIVOS_MANUAIS as $slug) {
                $this->assertArrayHasKey($slug, $motivos);
                $this->assertNotEmpty($motivos[$slug]);
            }
        });
    }

    public function test_liberacao_manual_de_contrato_recusado_sucede_ignorando_o_gate(): void
    {
        $admin   = $this->admin();
        $company = Company::factory()->create(['name' => 'Empresa Recusada Libera']);
        $servico = $this->servico();

        $contrato = ContratoAssinatura::factory()->create([
            'company_id' => $company->id,
            'servico_id' => $servico->id,
            'status'     => ContratoAssinatura::STATUS_RECUSADO,
        ]);

        $response = $this->actingAs($admin)->post(route('admin.contratos.liberacao-manual'), [
            'company_id'             => $company->id,
            'servico_id'             => $servico->id,
            'contrato_assinatura_id' => $contrato->id,
            'motivo_slug'            => ContratoLiberacao::MOTIVO_DECISAO_COMERCIAL,
            'motivo_detalhe'         => 'Cliente recusou na Clicksign mas fechamos por fora, decisão comercial.',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $this->assertSame(
            1,
            ContratoLiberacao::where('company_id', $company->id)->where('via', ContratoLiberacao::VIA_MANUAL)->count(),
            'D-11: liberacao manual tem que suceder mesmo com o contrato recusado, ignorando o gate automatico'
        );
    }

    public function test_nenhum_prop_de_contrato_expoe_dado_de_signatario(): void
    {
        $admin   = $this->admin();
        $company = Company::factory()->create();
        $servico = $this->servico();

        ContratoAssinatura::factory()->emAndamento()->create([
            'company_id' => $company->id,
            'servico_id' => $servico->id,
            'enviado_em' => now()->subDays(10),
        ]);

        $response = $this->actingAs($admin)->get(route('admin.contratos.show', $company));

        $response->assertOk();
        $response->assertInertia(function ($page) {
            $primeiroContrato = $page->toArray()['props']['contratos'][0];

            // Plano 131-06 (D-10) — a prop `contratos` do DETALHE (por
            // empresa) legitimamente carrega mais campos que a antiga
            // listagem (liberado_em/cancelamento_*/ja_liberado/signatarios,
            // ver ContratoAdminController::show()). A garantia de PII
            // (T-130-04-05/T-131-04-04) continua sendo a lista EXATA de
            // chaves — email/cpf/clicksign_signer_key NUNCA entram aqui.
            $chavesEsperadas = [
                'id', 'servico_id', 'servico_nome', 'status', 'dias_parado',
                'causa', 'enviado_em', 'assinado_em', 'liberado_em',
                'cancelamento_solicitado_em', 'cancelamento_motivo',
                'cancelamento_solicitado_por_nome', 'ja_liberado', 'signatarios',
                // `preparando` entrou pela quick 260816-d72 (estado "Preparando"
                // em âmbar na lista e no detalhe). É booleano derivado, sem PII —
                // mas a lista aqui é EXATA de propósito, então precisa constar.
                'preparando',
            ];

            $this->assertEqualsCanonicalizing($chavesEsperadas, array_keys($primeiroContrato));

            // Sub-array de signatários — achatado, sem nenhum dado de PII.
            $chavesSignatarioEsperadas = ['id', 'nome', 'papel', 'situacao'];
            foreach ($primeiroContrato['signatarios'] as $signatario) {
                $this->assertEqualsCanonicalizing($chavesSignatarioEsperadas, array_keys($signatario));
            }
        });
    }
}
