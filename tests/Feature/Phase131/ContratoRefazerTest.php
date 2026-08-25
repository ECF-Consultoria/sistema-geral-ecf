<?php

namespace Tests\Feature\Phase131;

use App\Jobs\GerarContratoAssinaturaJob;
use App\Models\Company;
use App\Models\ContratoAssinatura;
use App\Models\ContratoServico;
use App\Models\Servico;
use App\Models\User;
use App\Services\ContratoPdfService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Quick 260825-dap — "Refazer contrato": `ContratoAdminController::refazer()`.
 *
 * Cancela o envelope atual na Clicksign e gera um novo pelo funil único
 * (`GatilhoContratoAdministrativoService::dispararSeElegivel()`), com os
 * dados que estão no cadastro AGORA. Resolve o incidente relatado em
 * 2026-08-25: dado errado (e-mail, razão social, endereço, valor) ficava
 * CONGELADO no envelope e não havia caminho de correção sem apagar a
 * empresa direto no banco.
 *
 * Mesma disciplina do resto da fase: conferência por RECONSULTA ao banco,
 * nunca por stdout nem pela mensagem de sucesso da tela.
 */
class ContratoRefazerTest extends TestCase
{
    use RefreshDatabase;

    private const BASE        = 'https://sandbox.clicksign.com/api/v3';
    private const ENVELOPE_ID = '00000000-0000-4000-8000-000000000030';

    protected function setUp(): void
    {
        parent::setUp();

        // Blindagem por padrão — mesma disciplina de ContratoAdminDetalheTest:
        // sem os signatários fixos da ECF configurados, `iniciarParaEmpresa()`
        // recusa a criação por `faltantesDaConfiguracaoEcf()`. Cada teste que
        // precisa do contrato novo nascer de verdade sobrescreve isto.
        config(['services.clicksign.signatarios_ecf' => []]);
    }

    // ─── Helpers (mesmo molde de ContratoAdminDetalheTest) ──────────────

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    /** @return array<int, array{nome: string, email: string, papel: string}> */
    private function signatariosEcfOk(): array
    {
        return [
            ['nome' => 'Sócio Um', 'email' => 'socio1@example.com', 'papel' => 'contratada'],
            ['nome' => 'Sócio Dois', 'email' => 'socio2@example.com', 'papel' => 'contratada'],
            ['nome' => 'Comercial', 'email' => 'comercial@example.com', 'papel' => 'testemunha'],
        ];
    }

    private function servicoComContrato(string $nome = 'Gestão de Tráfego (refazer)'): Servico
    {
        return Servico::create([
            'nome'           => $nome,
            'valor_padrao'   => 100,
            'tipo_cobranca'  => Servico::TIPO_MENSAL,
            'ativo'          => true,
            'setor'          => Servico::SETOR_PERFORMANCE,
            'exige_contrato' => true,
        ]);
    }

    private function empresaCompleta(array $overrides = []): Company
    {
        return Company::factory()->create(array_merge([
            'active'        => true,
            'cnpj'          => '11.222.333/0001-81',
            'email_cliente' => 'cliente@example.com',
            'nome_contato'  => 'Contato de Teste',
            'razao_social'  => 'Contato de Teste LTDA',
            'endereco'      => 'Rua de Teste, 123',
            'bairro'        => 'Bairro de Teste',
            'cidade'        => 'Cidade de Teste',
            'estado'        => 'TS',
            'cep'           => '00000-000',
        ], $overrides));
    }

    private function vincularServico(Company $c, Servico $s, array $overrides = []): ContratoServico
    {
        return ContratoServico::withoutEvents(fn () => ContratoServico::create(array_merge([
            'company_id'             => $c->id,
            'data_primeira_parcela'  => now()->addMonth()->toDateString(),
            'dia_vencimento'         => 10,
            'servico_id'             => $s->id,
            'valor_contratado'       => 100,
            'data_contratacao'       => now()->toDateString(),
            'ativo'                  => true,
        ], $overrides)));
    }

    private function motivoValido(): string
    {
        return 'E-mail do cliente estava errado — corrigido no cadastro, refazendo o contrato.';
    }

    // ─── Caso 1 — rascunho: cancela envelope, fecha o antigo, cria o novo ───

    public function test_refazer_contrato_em_rascunho_cancela_envelope_fecha_antigo_e_cria_novo(): void
    {
        $admin   = $this->admin();
        $empresa = $this->empresaCompleta(['name' => 'Empresa Refazer Rascunho']);
        $servico = $this->servicoComContrato();
        $this->vincularServico($empresa, $servico);
        config(['services.clicksign.signatarios_ecf' => $this->signatariosEcfOk()]);

        $contratoAntigo = ContratoAssinatura::factory()->create([
            'company_id'            => $empresa->id,
            'servico_id'            => $servico->id,
            'status'                => ContratoAssinatura::STATUS_RASCUNHO,
            'clicksign_envelope_id' => self::ENVELOPE_ID,
        ]);

        Queue::fake();
        Http::fake([
            self::BASE . '/envelopes/' . self::ENVELOPE_ID => Http::response('', 204),
        ]);

        $response = $this->actingAs($admin)->post(route('admin.contratos.refazer', $contratoAntigo), [
            'motivo' => $this->motivoValido(),
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        // DELETE no envelope antigo — nunca PATCH.
        Http::assertSent(fn ($request) => $request->method() === 'DELETE'
            && $request->url() === self::BASE . '/envelopes/' . self::ENVELOPE_ID);

        // Reconsulta ao banco — o contrato antigo fechou como cancelado,
        // com motivo e autor gravados.
        $antigoFresco = $contratoAntigo->fresh();
        $this->assertSame(ContratoAssinatura::STATUS_CANCELADO, $antigoFresco->status);
        $this->assertSame($this->motivoValido(), $antigoFresco->cancelamento_motivo);
        $this->assertSame($admin->id, $antigoFresco->cancelamento_solicitado_por_user_id);
        $this->assertNotNull($antigoFresco->cancelamento_solicitado_em);

        // O novo nasceu — a trava composta (empresa+serviço) não bloqueou,
        // porque o antigo já estava fechado quando o novo foi criado.
        $todos = ContratoAssinatura::where('company_id', $empresa->id)->where('servico_id', $servico->id)->get();
        $this->assertCount(2, $todos);

        $novo = $todos->firstWhere('id', '!=', $contratoAntigo->id);
        $this->assertNotNull($novo, 'um contrato novo deveria ter nascido.');
        $this->assertSame(ContratoAssinatura::STATUS_RASCUNHO, $novo->status);

        // Só o novo ocupa o slot "em andamento" — nunca os dois ao mesmo tempo.
        $emAndamento = $todos->whereIn('status', ContratoAssinatura::STATUS_EM_ANDAMENTO);
        $this->assertCount(1, $emAndamento);
        $this->assertSame($novo->id, $emAndamento->first()->id);

        Queue::assertPushed(GerarContratoAssinaturaJob::class, fn ($job) => $job->contratoAssinatura->id === $novo->id);
    }

    // ─── Caso 2 — aguardando_assinaturas: mesmo comportamento ───

    public function test_refazer_contrato_aguardando_assinaturas_cancela_envelope_fecha_antigo_e_cria_novo(): void
    {
        $admin   = $this->admin();
        $empresa = $this->empresaCompleta(['name' => 'Empresa Refazer Aguardando']);
        $servico = $this->servicoComContrato();
        $this->vincularServico($empresa, $servico);
        config(['services.clicksign.signatarios_ecf' => $this->signatariosEcfOk()]);

        $contratoAntigo = ContratoAssinatura::factory()->emAndamento()->create([
            'company_id'            => $empresa->id,
            'servico_id'            => $servico->id,
            'clicksign_envelope_id' => self::ENVELOPE_ID,
        ]);

        Queue::fake();
        Http::fake([
            self::BASE . '/envelopes/' . self::ENVELOPE_ID => Http::response('', 204),
        ]);

        $response = $this->actingAs($admin)->post(route('admin.contratos.refazer', $contratoAntigo), [
            'motivo' => $this->motivoValido(),
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $this->assertSame(ContratoAssinatura::STATUS_CANCELADO, $contratoAntigo->fresh()->status);

        $novo = ContratoAssinatura::where('company_id', $empresa->id)
            ->where('servico_id', $servico->id)
            ->where('id', '!=', $contratoAntigo->id)
            ->first();
        $this->assertNotNull($novo);
        $this->assertSame(ContratoAssinatura::STATUS_RASCUNHO, $novo->status);
    }

    // ─── Caso 3 — contrato assinado: recusado, nada acontece ───

    public function test_refazer_contrato_assinado_e_recusado_e_nao_altera_nada(): void
    {
        $admin   = $this->admin();
        $empresa = $this->empresaCompleta(['name' => 'Empresa Refazer Assinado']);
        $servico = $this->servicoComContrato();
        $this->vincularServico($empresa, $servico);

        $contrato = ContratoAssinatura::factory()->assinado()->create([
            'company_id'            => $empresa->id,
            'servico_id'            => $servico->id,
            'clicksign_envelope_id' => self::ENVELOPE_ID,
        ]);

        Http::fake();

        $response = $this->actingAs($admin)->post(route('admin.contratos.refazer', $contrato), [
            'motivo' => $this->motivoValido(),
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('error');
        $response->assertSessionMissing('success');

        // Nada mudou — nem status, nem chamada HTTP, nem contrato novo.
        $this->assertSame(ContratoAssinatura::STATUS_ASSINADO, $contrato->fresh()->status);
        $this->assertNull($contrato->fresh()->cancelamento_motivo);
        $this->assertSame(1, ContratoAssinatura::where('company_id', $empresa->id)->where('servico_id', $servico->id)->count());
        Http::assertNothingSent();
    }

    // ─── Caso 4 — o mais importante: cancelarEnvelope() devolve false → PARA ───

    public function test_refazer_quando_cancelar_envelope_falha_para_sem_criar_nada(): void
    {
        $admin   = $this->admin();
        $empresa = $this->empresaCompleta(['name' => 'Empresa Refazer Falha Cancelamento']);
        $servico = $this->servicoComContrato();
        $this->vincularServico($empresa, $servico);
        // Mesmo com a config OK, a criação do novo NUNCA deveria ser tentada
        // — se este teste passar por acidente com um contrato novo criado, a
        // proteção contra "dois contratos válidos circulando" falhou.
        config(['services.clicksign.signatarios_ecf' => $this->signatariosEcfOk()]);

        $contratoAntigo = ContratoAssinatura::factory()->create([
            'company_id'            => $empresa->id,
            'servico_id'            => $servico->id,
            'status'                => ContratoAssinatura::STATUS_RASCUNHO,
            'clicksign_envelope_id' => self::ENVELOPE_ID,
        ]);

        Queue::fake();
        Http::fake([
            self::BASE . '/envelopes/' . self::ENVELOPE_ID => Http::response(
                ['errors' => [['code' => 'server_error', 'status' => 500]]],
                500
            ),
        ]);

        $response = $this->actingAs($admin)->post(route('admin.contratos.refazer', $contratoAntigo), [
            'motivo' => $this->motivoValido(),
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('error');
        $response->assertSessionMissing('success');
        $this->assertStringContainsString('cancele manualmente', session('error'));

        // Reconsulta ao banco — o contrato antigo está INTACTO (status,
        // motivo e autor continuam vazios/originais) e NENHUM contrato novo
        // nasceu.
        $antigoFresco = $contratoAntigo->fresh();
        $this->assertSame(ContratoAssinatura::STATUS_RASCUNHO, $antigoFresco->status);
        $this->assertNull($antigoFresco->cancelamento_motivo);
        $this->assertNull($antigoFresco->cancelamento_solicitado_em);
        $this->assertSame(1, ContratoAssinatura::where('company_id', $empresa->id)->where('servico_id', $servico->id)->count());

        Queue::assertNothingPushed();
    }

    // ─── Caso 5 — sem clicksign_envelope_id: pula o cancelamento, gera o novo ───

    public function test_refazer_contrato_sem_envelope_pula_cancelamento_e_gera_novo(): void
    {
        $admin   = $this->admin();
        $empresa = $this->empresaCompleta(['name' => 'Empresa Refazer Sem Envelope']);
        $servico = $this->servicoComContrato();
        $this->vincularServico($empresa, $servico);
        config(['services.clicksign.signatarios_ecf' => $this->signatariosEcfOk()]);

        $contratoAntigo = ContratoAssinatura::factory()->create([
            'company_id'             => $empresa->id,
            'servico_id'             => $servico->id,
            'status'                 => ContratoAssinatura::STATUS_RASCUNHO,
            'clicksign_envelope_id'  => null,
        ]);

        Queue::fake();
        Http::fake();

        $response = $this->actingAs($admin)->post(route('admin.contratos.refazer', $contratoAntigo), [
            'motivo' => $this->motivoValido(),
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        // Nenhuma chamada à Clicksign — não havia envelope para cancelar.
        Http::assertNothingSent();

        $this->assertSame(ContratoAssinatura::STATUS_CANCELADO, $contratoAntigo->fresh()->status);

        $novo = ContratoAssinatura::where('company_id', $empresa->id)
            ->where('servico_id', $servico->id)
            ->where('id', '!=', $contratoAntigo->id)
            ->first();
        $this->assertNotNull($novo);
    }

    // ─── Caso 6 — o caso que originou o pedido: novo nasce com o e-mail ATUAL ───

    public function test_refazer_gera_contrato_associado_ao_email_atual_do_cadastro_nao_ao_antigo(): void
    {
        $admin   = $this->admin();
        $empresa = $this->empresaCompleta([
            'name'          => 'Empresa Refazer Email Corrigido',
            'email_cliente' => 'email-errado@exemplo.com',
        ]);
        $servico = $this->servicoComContrato();
        $this->vincularServico($empresa, $servico);
        config(['services.clicksign.signatarios_ecf' => $this->signatariosEcfOk()]);

        $contratoAntigo = ContratoAssinatura::factory()->create([
            'company_id'            => $empresa->id,
            'servico_id'            => $servico->id,
            'status'                => ContratoAssinatura::STATUS_RASCUNHO,
            'clicksign_envelope_id' => self::ENVELOPE_ID,
        ]);

        // O Administrativo corrige o e-mail no cadastro — mesmo caminho de
        // ContratoAdminController::atualizarCadastro() — ANTES de refazer.
        $empresa->update(['email_cliente' => 'email-certo@exemplo.com']);

        Queue::fake();
        Http::fake([
            self::BASE . '/envelopes/' . self::ENVELOPE_ID => Http::response('', 204),
        ]);

        $response = $this->actingAs($admin)->post(route('admin.contratos.refazer', $contratoAntigo), [
            'motivo' => $this->motivoValido(),
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        // O job do contrato NOVO lê a empresa AO VIVO (nunca um snapshot de
        // e-mail) — a prova de que "novo nasce com o e-mail atual" é que o
        // job despachado enxerga o e-mail JÁ CORRIGIDO, não o antigo.
        Queue::assertPushed(GerarContratoAssinaturaJob::class, function ($job) use ($contratoAntigo) {
            return $job->contratoAssinatura->id !== $contratoAntigo->id
                && $job->contratoAssinatura->company->email_cliente === 'email-certo@exemplo.com';
        });
    }

    // ─── Caso 7 — motivo ausente ou curto demais: recusado ───

    public function test_refazer_sem_motivo_e_recusado_com_erro_de_validacao(): void
    {
        $admin   = $this->admin();
        $empresa = $this->empresaCompleta(['name' => 'Empresa Refazer Sem Motivo']);
        $servico = $this->servicoComContrato();
        $this->vincularServico($empresa, $servico);

        $contrato = ContratoAssinatura::factory()->create([
            'company_id'            => $empresa->id,
            'servico_id'            => $servico->id,
            'status'                => ContratoAssinatura::STATUS_RASCUNHO,
            'clicksign_envelope_id' => self::ENVELOPE_ID,
        ]);

        Http::fake();

        $response = $this->actingAs($admin)->post(route('admin.contratos.refazer', $contrato), []);

        $response->assertSessionHasErrors('motivo');
        $this->assertSame(ContratoAssinatura::STATUS_RASCUNHO, $contrato->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_refazer_com_motivo_curto_demais_e_recusado_com_erro_de_validacao(): void
    {
        $admin   = $this->admin();
        $empresa = $this->empresaCompleta(['name' => 'Empresa Refazer Motivo Curto']);
        $servico = $this->servicoComContrato();
        $this->vincularServico($empresa, $servico);

        $contrato = ContratoAssinatura::factory()->create([
            'company_id'            => $empresa->id,
            'servico_id'            => $servico->id,
            'status'                => ContratoAssinatura::STATUS_RASCUNHO,
            'clicksign_envelope_id' => self::ENVELOPE_ID,
        ]);

        Http::fake();

        $response = $this->actingAs($admin)->post(route('admin.contratos.refazer', $contrato), [
            'motivo' => 'curto',
        ]);

        $response->assertSessionHasErrors('motivo');
        $this->assertSame(ContratoAssinatura::STATUS_RASCUNHO, $contrato->fresh()->status);
        Http::assertNothingSent();
    }

    // ─── Quick 260825-ixp — transporte de plano_parcelas_texto no refazer ───
    //
    // Incidente em produção (2026-08-25, Maderatto): o Administrativo editou
    // a frase do parcelamento, salvou, clicou em "Refazer contrato" — e a
    // frase editada não apareceu no contrato novo (voltou ao texto composto,
    // `null` na coluna). A edição ficava presa no contrato CANCELADO porque
    // `refazer()` cria um `ContratoAssinatura` novo, que nasce com a coluna
    // vazia.

    // Caso 8 — override preenchido: o novo nasce com o MESMO texto, literal.
    public function test_refazer_transporta_plano_parcelas_texto_do_contrato_antigo_para_o_novo(): void
    {
        $admin   = $this->admin();
        $empresa = $this->empresaCompleta(['name' => 'Empresa Refazer Plano Parcelas']);
        $servico = $this->servicoComContrato();
        $this->vincularServico($empresa, $servico);
        config(['services.clicksign.signatarios_ecf' => $this->signatariosEcfOk()]);

        $textoEditado = 'Parcelamento combinado por e-mail: 3x de R$ 500,00, editado manualmente.';

        $contratoAntigo = ContratoAssinatura::factory()->create([
            'company_id'            => $empresa->id,
            'servico_id'            => $servico->id,
            'status'                => ContratoAssinatura::STATUS_RASCUNHO,
            'clicksign_envelope_id' => self::ENVELOPE_ID,
            'plano_parcelas_texto'  => $textoEditado,
        ]);

        Queue::fake();
        Http::fake([
            self::BASE . '/envelopes/' . self::ENVELOPE_ID => Http::response('', 204),
        ]);

        $response = $this->actingAs($admin)->post(route('admin.contratos.refazer', $contratoAntigo), [
            'motivo' => $this->motivoValido(),
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $novo = ContratoAssinatura::where('company_id', $empresa->id)
            ->where('servico_id', $servico->id)
            ->where('id', '!=', $contratoAntigo->id)
            ->first();

        $this->assertNotNull($novo, 'um contrato novo deveria ter nascido.');
        // Transporte LITERAL — reconsulta ao banco, nunca a mensagem de
        // sucesso da tela.
        $this->assertSame($textoEditado, $novo->fresh()->plano_parcelas_texto);
    }

    // Caso 9 — sem override (null): regressão zero, o novo continua null e
    // usa o composto.
    public function test_refazer_sem_override_o_novo_continua_null_e_usa_o_composto(): void
    {
        $admin   = $this->admin();
        $empresa = $this->empresaCompleta(['name' => 'Empresa Refazer Sem Override']);
        $servico = $this->servicoComContrato();
        $this->vincularServico($empresa, $servico);
        config(['services.clicksign.signatarios_ecf' => $this->signatariosEcfOk()]);

        $contratoAntigo = ContratoAssinatura::factory()->create([
            'company_id'            => $empresa->id,
            'servico_id'            => $servico->id,
            'status'                => ContratoAssinatura::STATUS_RASCUNHO,
            'clicksign_envelope_id' => self::ENVELOPE_ID,
            'plano_parcelas_texto'  => null,
        ]);

        Queue::fake();
        Http::fake([
            self::BASE . '/envelopes/' . self::ENVELOPE_ID => Http::response('', 204),
        ]);

        $response = $this->actingAs($admin)->post(route('admin.contratos.refazer', $contratoAntigo), [
            'motivo' => $this->motivoValido(),
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $novo = ContratoAssinatura::where('company_id', $empresa->id)
            ->where('servico_id', $servico->id)
            ->where('id', '!=', $contratoAntigo->id)
            ->first();

        $this->assertNotNull($novo, 'um contrato novo deveria ter nascido.');
        // Nunca inventar valor — sem override no antigo, o novo continua
        // null (o campo efetivo passa a usar o composto pelas fases).
        $this->assertNull($novo->fresh()->plano_parcelas_texto);
    }

    // Caso 10 — empresa com dois serviços, override só em um: só o contrato
    // do MESMO servico_id herda; o outro nasce null.
    public function test_refazer_com_dois_servicos_so_o_contrato_do_mesmo_servico_herda_o_texto(): void
    {
        $admin    = $this->admin();
        $empresa  = $this->empresaCompleta(['name' => 'Empresa Refazer Dois Servicos']);
        $servicoA = $this->servicoComContrato('Gestão de Tráfego (refazer A)');
        $servicoB = $this->servicoComContrato('Gestão de Tráfego (refazer B)');
        $this->vincularServico($empresa, $servicoA);
        $this->vincularServico($empresa, $servicoB);
        config(['services.clicksign.signatarios_ecf' => $this->signatariosEcfOk()]);

        $textoEditado = 'Texto exclusivo do parcelamento do serviço A.';

        // Só o serviço A tem contrato (antigo, com override) — o serviço B
        // ainda não tem nenhum `ContratoAssinatura`, então o mesmo disparo do
        // refazer cria um contrato NOVO para B também (um por serviço,
        // exatamente o cenário que o PLAN.md alerta).
        $contratoAntigoA = ContratoAssinatura::factory()->create([
            'company_id'            => $empresa->id,
            'servico_id'            => $servicoA->id,
            'status'                => ContratoAssinatura::STATUS_RASCUNHO,
            'clicksign_envelope_id' => self::ENVELOPE_ID,
            'plano_parcelas_texto'  => $textoEditado,
        ]);

        Queue::fake();
        Http::fake([
            self::BASE . '/envelopes/' . self::ENVELOPE_ID => Http::response('', 204),
        ]);

        $response = $this->actingAs($admin)->post(route('admin.contratos.refazer', $contratoAntigoA), [
            'motivo' => $this->motivoValido(),
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $novoA = ContratoAssinatura::where('company_id', $empresa->id)
            ->where('servico_id', $servicoA->id)
            ->where('id', '!=', $contratoAntigoA->id)
            ->first();
        $novoB = ContratoAssinatura::where('company_id', $empresa->id)
            ->where('servico_id', $servicoB->id)
            ->first();

        $this->assertNotNull($novoA, 'o contrato novo do serviço A deveria ter nascido.');
        $this->assertNotNull($novoB, 'o contrato novo do serviço B deveria ter nascido.');
        $this->assertSame($textoEditado, $novoA->fresh()->plano_parcelas_texto);
        // Nunca todos — o serviço B não tinha override nenhum, então nasce null.
        $this->assertNull($novoB->fresh()->plano_parcelas_texto);
    }

    // Caso 11 — o mais importante (a prova de ponta a ponta que falhou para
    // o usuário): o texto transportado é o que sai em {{plano_parcelas}} do
    // contrato NOVO.
    public function test_refazer_texto_transportado_aparece_no_plano_parcelas_do_contrato_novo(): void
    {
        $admin   = $this->admin();
        $empresa = $this->empresaCompleta(['name' => 'Empresa Refazer Ponta a Ponta']);
        $servico = $this->servicoComContrato();
        $this->vincularServico($empresa, $servico);
        config(['services.clicksign.signatarios_ecf' => $this->signatariosEcfOk()]);

        $textoEditado = '3 parcelas de R$ 1.000,00, conforme combinado por e-mail em 25/08.';

        $contratoAntigo = ContratoAssinatura::factory()->create([
            'company_id'            => $empresa->id,
            'servico_id'            => $servico->id,
            'status'                => ContratoAssinatura::STATUS_RASCUNHO,
            'clicksign_envelope_id' => self::ENVELOPE_ID,
            'plano_parcelas_texto'  => $textoEditado,
        ]);

        Queue::fake();
        Http::fake([
            self::BASE . '/envelopes/' . self::ENVELOPE_ID => Http::response('', 204),
        ]);

        $response = $this->actingAs($admin)->post(route('admin.contratos.refazer', $contratoAntigo), [
            'motivo' => $this->motivoValido(),
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $novo = ContratoAssinatura::where('company_id', $empresa->id)
            ->where('servico_id', $servico->id)
            ->where('id', '!=', $contratoAntigo->id)
            ->firstOrFail();

        // A prova de ponta a ponta: o texto que sai em {{plano_parcelas}}
        // (o mesmo método que `ContratoPdfService::montarDados()` usa para
        // o PDF de verdade) é o texto transportado, literal.
        $texto = app(ContratoPdfService::class)->planoParcelas($novo);
        $this->assertSame($textoEditado, $texto);
    }
}
