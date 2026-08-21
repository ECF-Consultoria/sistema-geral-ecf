<?php

namespace Tests\Feature\Phase131;

use App\Models\Company;
use App\Models\ContratoAssinatura;
use App\Models\ContratoAssinaturaSignatario;
use App\Models\ContratoServico;
use App\Models\Servico;
use App\Models\User;
use App\Services\Contratos\ContratoDadosMinimosService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Fase 131 Plano 04 (ADM-01/ADM-02/UI-02, D-01/D-03/D-11) —
 * ContratoAdminController::show()/atualizarCadastro()/gerarContrato().
 *
 * Nasceu na Task 1 com os casos 1, 2, 8 e 11 (o núcleo daquela task —
 * incluindo o BLOCKER da correção: `gerarContrato()` não pode anunciar
 * sucesso quando `faltantesDaConfiguracaoEcf()` bloqueia por dentro de
 * `iniciarParaEmpresa()`) e é COMPLETADO nesta Task 3 com os casos 3, 4, 5,
 * 6, 7, 9 e 10, no MESMO arquivo — regra do "teste nasce na mesma task do
 * código que ele prova" (armadilha do `--filter` sem match que sai 0 e varre
 * a suíte).
 *
 * Mesma disciplina do resto da fase: conferência por RECONSULTA ao banco,
 * nunca por stdout nem pela mensagem de sucesso da tela.
 */
class ContratoAdminDetalheTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        Queue::fake();

        // Blindagem por padrão: a reavaliação automática do Observer (Fase
        // 128) roda SÍNCRONA quando Company/ContratoServico são salvos com
        // campos-gatilho alterados — inclusive dentro desta suíte, que só
        // faz `atualizarCadastro()`/`show()`. Sem isto, um teste que deixa a
        // empresa completa e elegível poderia disparar um contrato de
        // verdade como efeito colateral do Observer, fora do que o próprio
        // teste está medindo. Cada teste que precisa do caminho feliz
        // (`gerarContrato()`) sobrescreve este config explicitamente.
        config(['services.clicksign.signatarios_ecf' => []]);
    }

    // ─── Helpers ───────────────────────────────────────────────────────────

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    /** Os 3 signatários da D-08, preenchidos — o estado "configurado". */
    private function signatariosEcfOk(): array
    {
        return [
            ['nome' => 'Sócio Um', 'email' => 'socio1@example.com', 'papel' => 'contratada'],
            ['nome' => 'Sócio Dois', 'email' => 'socio2@example.com', 'papel' => 'contratada'],
            ['nome' => 'Comercial', 'email' => 'comercial@example.com', 'papel' => 'testemunha'],
        ];
    }

    private function servicoComContrato(string $nome = 'Gestão de Tráfego (detalhe admin)'): Servico
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

    private function empresaIncompleta(array $overrides = []): Company
    {
        return Company::factory()->create(array_merge([
            'active'        => true,
            'cnpj'          => null,
            'email_cliente' => null,
            'nome_contato'  => null,
        ], $overrides));
    }

    private function empresaCompleta(array $overrides = []): Company
    {
        return Company::factory()->create(array_merge([
            'active'        => true,
            'cnpj'          => '11.222.333/0001-81',
            'email_cliente' => 'cliente@example.com',
            'nome_contato'  => 'Contato de Teste',
            // Quick 260819-guy — obrigatórios desde 2026-08-19.
            'razao_social'  => 'Contato de Teste LTDA',
            // Quick 260821-cq0 — endereço em 5 campos, todos obrigatórios.
            'endereco'      => 'Rua de Teste, 123',
            'bairro'        => 'Bairro de Teste',
            'cidade'        => 'Cidade de Teste',
            'estado'        => 'TS',
            'cep'           => '00000-000',
        ], $overrides));
    }

    /**
     * `withoutEvents`: sem isto, `ContratoServico::create()` dispara o
     * `ContratoServicoGatilhoObserver` como efeito colateral do SETUP, antes
     * da chamada explícita que cada teste está medindo — mesmo cuidado do
     * `ContratoClicksignServiceTest` da Fase 127.
     *
     * Quick 260819-guy — `data_primeira_parcela`/`dia_vencimento` no default
     * também: quem chama `vincularServico()` esperando uma empresa "pronta"
     * (via `empresaCompleta()`) só fica de fato pronta com os 4 campos
     * novos completos nos dois lados (empresa + serviço).
     */
    private function vincularServico(Company $c, Servico $s, array $overrides = []): ContratoServico
    {
        return ContratoServico::withoutEvents(fn () => ContratoServico::create(array_merge([
            'company_id'       => $c->id,
            'data_primeira_parcela' => now()->addMonth()->toDateString(),
            'dia_vencimento'        => 10,
            'servico_id'       => $s->id,
            'valor_contratado' => 100,
            'data_contratacao' => now()->toDateString(),
            'ativo'            => true,
        ], $overrides)));
    }

    // ─── Caso 1 — empresa incompleta: 200 + componente + faltantes bate + pode_gerar_contrato false ───

    public function test_show_de_empresa_incompleta_devolve_200_componente_e_faltantes_batendo_com_o_service(): void
    {
        $admin   = $this->admin();
        $empresa = $this->empresaIncompleta(['name' => 'Empresa Incompleta Detalhe']);
        $servico = $this->servicoComContrato();
        $this->vincularServico($empresa, $servico);

        $response = $this->actingAs($admin)->get(route('admin.contratos.show', $empresa));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->component('Admin/ContratoDetalhe'));

        $props = $response->viewData('page')['props'];

        $esperado = app(ContratoDadosMinimosService::class)->faltantes($empresa->fresh());
        $this->assertSame($esperado, $props['faltantes']);
        $this->assertNotEmpty($props['faltantes'], 'a fixture precisa ficar incompleta de propósito para este caso.');
        $this->assertFalse($props['pode_gerar_contrato']);
    }

    // ─── Caso 2 — empresa completa, sem contrato em andamento: pode_gerar_contrato true e faltantes vazio ───

    public function test_show_de_empresa_completa_sem_contrato_em_andamento_permite_gerar(): void
    {
        $admin   = $this->admin();
        $empresa = $this->empresaCompleta(['name' => 'Empresa Completa Detalhe']);
        $servico = $this->servicoComContrato();
        $this->vincularServico($empresa, $servico);

        $response = $this->actingAs($admin)->get(route('admin.contratos.show', $empresa));

        $response->assertOk();
        $props = $response->viewData('page')['props'];

        $this->assertSame([], $props['faltantes']);
        $this->assertTrue($props['pode_gerar_contrato']);
        $this->assertNull($props['motivo_bloqueio']);
    }

    // ─── Caso 3 — empresa completa mas COM contrato em andamento: bloqueia com motivo_bloqueio='ja_em_andamento' ───

    public function test_show_de_empresa_completa_com_contrato_em_andamento_bloqueia_com_motivo_ja_em_andamento(): void
    {
        $admin   = $this->admin();
        $empresa = $this->empresaCompleta(['name' => 'Empresa Com Contrato Em Andamento']);
        $servico = $this->servicoComContrato();
        $this->vincularServico($empresa, $servico);

        ContratoAssinatura::factory()->emAndamento()->create([
            'company_id' => $empresa->id,
            'servico_id' => $servico->id,
        ]);

        $response = $this->actingAs($admin)->get(route('admin.contratos.show', $empresa));

        $response->assertOk();
        $props = $response->viewData('page')['props'];

        $this->assertSame([], $props['faltantes'], 'a empresa está completa — o bloqueio é por contrato em andamento, não por dado faltando.');
        $this->assertFalse($props['pode_gerar_contrato']);
        $this->assertSame('ja_em_andamento', $props['motivo_bloqueio']);
    }

    // ─── Quick 260821-l8n — empresa completa mas com serviço duplicado: bloqueia com motivo_bloqueio='servicos_duplicados' ───

    public function test_show_de_empresa_com_servico_duplicado_bloqueia_com_motivo_servicos_duplicados(): void
    {
        $admin   = $this->admin();
        $empresa = $this->empresaCompleta(['name' => 'Empresa Com Serviço Duplicado (Mons Bike)']);
        $servico = $this->servicoComContrato('Gestão de Ads Duplicado');
        // Cenário real (deal HubSpot 63836845208): dois itens de linha do
        // MESMO serviço, pagamento escalonado.
        $this->vincularServico($empresa, $servico, ['valor_contratado' => 5500]);
        $this->vincularServico($empresa, $servico, ['valor_contratado' => 6000]);

        $response = $this->actingAs($admin)->get(route('admin.contratos.show', $empresa));

        $response->assertOk();
        $props = $response->viewData('page')['props'];

        $this->assertSame([], $props['faltantes'], 'a empresa está completa — o bloqueio é por serviço duplicado, não por dado faltando.');
        $this->assertFalse($props['pode_gerar_contrato']);
        $this->assertSame('servicos_duplicados', $props['motivo_bloqueio']);
    }

    // ─── Caso 4 — Quick 260817-d6h: email_colaborador saiu da tela de contrato ───

    public function test_email_colaborador_nao_aparece_mais_na_tela_de_contrato(): void
    {
        $admin   = $this->admin();
        $empresa = $this->empresaCompleta(['name' => 'Empresa Sem Email Colaborador', 'email_colaborador' => null]);
        $servico = $this->servicoComContrato();
        $this->vincularServico($empresa, $servico);

        $response = $this->actingAs($admin)->get(route('admin.contratos.show', $empresa));

        $response->assertOk();
        $props = $response->viewData('page')['props'];

        // O campo saiu da prop `company` e a prop de pendência foi removida
        // — segue vivo só em /companies (CompanyController).
        $this->assertArrayNotHasKey('email_colaborador', $props['company']);
        $this->assertArrayNotHasKey('email_colaborador_pendente', $props);

        // Este é o teste que fica vermelho se alguém acrescentar
        // email_colaborador a ContratoDadosMinimosService::faltantes() —
        // violaria a D-11 diretamente (o campo continua fora do fluxo de
        // contrato, só que agora nem aparece mais na tela).
        $campos = collect($props['faltantes'])->pluck('campo')->all();
        $this->assertNotContains('email_colaborador', $campos);

        // PATCH com email_colaborador no payload não grava mais nada — o
        // controller nem valida nem faz mass-assignment do campo.
        $response = $this->actingAs($admin)->patch(route('admin.contratos.cadastro', $empresa), [
            'email_colaborador' => 'colaborador@example.com',
        ]);
        $response->assertRedirect();
        $this->assertNull($empresa->fresh()->email_colaborador);
    }

    // ─── Quick 260821-odj — PATCH com CNPJ de dígito verificador trocado GRAVA os demais campos ───
    //
    // SUPERSEDE o teste antigo (Quick 260819-guy) que exigia recusa da
    // requisição inteira. Era exatamente a causa raiz do incidente em
    // produção (empresa 430 Mons Bike, 2026-08-21): `new CnpjValido()` no
    // salvar reprovava a REQUISIÇÃO INTEIRA por causa de um campo com
    // problema, perdendo razão social/endereço digitados junto, sem
    // problema nenhum. `CnpjValido`/`Cnpj::valido()` seguem em uso — só
    // migraram para o gate da GERAÇÃO (`ContratoDadosMinimosService::
    // faltantes()`), que roda antes de qualquer chamada à Clicksign.
    public function test_atualizar_cadastro_com_cnpj_de_digito_trocado_grava_os_demais_campos(): void
    {
        $admin   = $this->admin();
        $empresa = $this->empresaIncompleta(['name' => 'Empresa CNPJ Digito Trocado']);

        $response = $this->actingAs($admin)->patch(route('admin.contratos.cadastro', $empresa), [
            // Mesmo CNPJ válido do plano ('26.754.383/0001-87'), com o
            // último dígito trocado.
            'cnpj'          => '26.754.383/0001-88',
            'razao_social'  => 'Empresa CNPJ Digito Trocado LTDA',
            'endereco'      => 'Rua de Teste, 123',
            'bairro'        => 'Bairro de Teste',
            'cidade'        => 'Cidade de Teste',
            'estado'        => 'TS',
            'cep'           => '00000-000',
        ]);

        $response->assertSessionDoesntHaveErrors('cnpj');
        $response->assertSessionHas('success');

        // RECONSULTA ao banco — nunca por stdout nem pela mensagem de
        // sucesso da tela. O cnpj com dígito trocado É gravado (o salvar não
        // valida mais dígito verificador); os demais campos também.
        $fresca = $empresa->fresh();
        $this->assertSame('26.754.383/0001-88', $fresca->cnpj);
        $this->assertSame('Empresa CNPJ Digito Trocado LTDA', $fresca->razao_social);
        $this->assertSame('Rua de Teste, 123', $fresca->endereco);

        // O gate da GERAÇÃO não afrouxou: a mesma empresa, com o cnpj
        // inválido gravado, continua bloqueada em faltantes() com
        // motivo 'formato' — prova que o save() e o faltantes() concordam
        // sobre o mesmo dado, só o efeito (recusar vs. registrar pendência)
        // mudou de lugar.
        $response2 = $this->actingAs($admin)->get(route('admin.contratos.show', $empresa));
        $props = $response2->viewData('page')['props'];
        $item = collect($props['faltantes'])->firstWhere('campo', 'cnpj');
        $this->assertNotNull($item, 'cnpj com dígito trocado deveria seguir bloqueando a geração.');
        $this->assertSame('formato', $item['motivo']);
    }

    // ─── Caso 5 — PATCH grava CNPJ/e-mails/nome_contato/datas — RECONSULTA ao banco ───

    public function test_atualizar_cadastro_grava_todos_os_campos_conferido_por_reconsulta_ao_banco(): void
    {
        $admin           = $this->admin();
        $empresa         = $this->empresaIncompleta(['name' => 'Empresa Para Atualizar Cadastro']);
        $servico         = $this->servicoComContrato();
        $contratoServico = $this->vincularServico($empresa, $servico);

        $response = $this->actingAs($admin)->patch(route('admin.contratos.cadastro', $empresa), [
            'cnpj'              => '22.333.444/0001-81',
            'email_cliente'     => 'novo-cliente@example.com',
            'nome_contato'      => 'Fulano Atualizado',
            'contratos_servico' => [
                [
                    'id'               => $contratoServico->id,
                    'data_contratacao' => '2026-01-10',
                    'data_vencimento'  => '2027-01-10',
                ],
            ],
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        // Reconsulta ao banco — nunca confia na mensagem de sucesso.
        $empresaFresca = $empresa->fresh();
        $this->assertSame('22.333.444/0001-81', $empresaFresca->cnpj);
        $this->assertSame('novo-cliente@example.com', $empresaFresca->email_cliente);
        $this->assertSame('Fulano Atualizado', $empresaFresca->nome_contato);

        $contratoServicoFresco = $contratoServico->fresh();
        $this->assertSame('2026-01-10', $contratoServicoFresco->data_contratacao->format('Y-m-d'));
        $this->assertSame('2027-01-10', $contratoServicoFresco->data_vencimento->format('Y-m-d'));
    }

    // ─── Quick 260819-guy — PATCH grava razao_social/endereco/data_primeira_parcela/dia_vencimento ───

    public function test_atualizar_cadastro_grava_razao_social_endereco_e_datas_de_pagamento_por_servico(): void
    {
        $admin           = $this->admin();
        $empresa         = $this->empresaIncompleta(['name' => 'Empresa Para Dados De Pagamento']);
        $servico         = $this->servicoComContrato();
        $contratoServico = $this->vincularServico($empresa, $servico);

        $response = $this->actingAs($admin)->patch(route('admin.contratos.cadastro', $empresa), [
            'razao_social'      => 'Empresa Para Dados De Pagamento LTDA',
            'endereco'          => 'Rua das Empresas, 100 — Centro',
            'contratos_servico' => [
                [
                    // atualizarCadastro() sobrescreve TODOS os campos do
                    // item, sempre — reenvia data_contratacao já existente
                    // para não nulificá-la (NOT NULL na coluna).
                    'id'                     => $contratoServico->id,
                    'data_contratacao'       => $contratoServico->data_contratacao->format('Y-m-d'),
                    'data_primeira_parcela'  => '2026-09-05',
                    'dia_vencimento'         => 10,
                ],
            ],
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        // Reconsulta ao banco — nunca confia na mensagem de sucesso.
        $empresaFresca = $empresa->fresh();
        $this->assertSame('Empresa Para Dados De Pagamento LTDA', $empresaFresca->razao_social);
        $this->assertSame('Rua das Empresas, 100 — Centro', $empresaFresca->endereco);

        $contratoServicoFresco = $contratoServico->fresh();
        $this->assertSame('2026-09-05', $contratoServicoFresco->data_primeira_parcela->format('Y-m-d'));
        $this->assertSame(10, $contratoServicoFresco->dia_vencimento);
    }

    // ─── Quick 260819-guy — show() devolve os 4 campos novos e eles sobrevivem a um recarregamento ───

    public function test_show_devolve_razao_social_endereco_e_datas_de_pagamento_apos_recarregar(): void
    {
        $admin           = $this->admin();
        $empresa         = $this->empresaCompleta([
            'name'         => 'Empresa Com Dados De Pagamento',
            'razao_social' => 'Empresa Com Dados De Pagamento LTDA',
            'endereco'     => 'Av. Principal, 500',
        ]);
        $servico         = $this->servicoComContrato();
        $contratoServico = $this->vincularServico($empresa, $servico, [
            'data_primeira_parcela' => '2026-09-05',
            'dia_vencimento'        => 15,
        ]);

        $response = $this->actingAs($admin)->get(route('admin.contratos.show', $empresa));

        $response->assertOk();
        $props = $response->viewData('page')['props'];

        $this->assertSame('Empresa Com Dados De Pagamento LTDA', $props['company']['razao_social']);
        $this->assertSame('Av. Principal, 500', $props['company']['endereco']);

        $itemServico = collect($props['contratos_servico'])->firstWhere('id', $contratoServico->id);
        $this->assertNotNull($itemServico);
        $this->assertSame('2026-09-05', $itemServico['data_primeira_parcela']);
        $this->assertSame(15, $itemServico['dia_vencimento']);
    }

    // ─── Caso 6 — IDOR: contratos_servico[0][id] de OUTRA empresa devolve 422 e não grava nada ───

    public function test_atualizar_cadastro_com_contrato_servico_de_outra_empresa_devolve_422_e_nao_grava_nada(): void
    {
        $admin = $this->admin();

        $empresaAlvo = $this->empresaIncompleta(['name' => 'Empresa Alvo IDOR']);
        $servico     = $this->servicoComContrato();

        $empresaOutra                   = $this->empresaIncompleta(['name' => 'Empresa Outra IDOR']);
        $contratoServicoDeOutraEmpresa  = $this->vincularServico($empresaOutra, $servico);

        $response = $this->actingAs($admin)->patch(route('admin.contratos.cadastro', $empresaAlvo), [
            // Quick 260819-guy — precisa ser um CNPJ com dígito verificador
            // VÁLIDO: senão a validação (CnpjValido) barra antes do 422 de
            // IDOR que este teste está medindo.
            'cnpj'              => '11.111.111/0001-91',
            'contratos_servico' => [
                ['id' => $contratoServicoDeOutraEmpresa->id, 'data_contratacao' => '2026-02-01'],
            ],
        ]);

        $response->assertStatus(422);

        // Reconsulta ao banco — nem a empresa alvo nem o contrato de outra
        // empresa foram alterados (T-131-04-01).
        $this->assertNull($empresaAlvo->fresh()->cnpj);
        $this->assertNotSame(
            '2026-02-01',
            optional($contratoServicoDeOutraEmpresa->fresh()->data_contratacao)->format('Y-m-d'),
        );
    }

    // ─── Caso 7 — Quick 260817-d6h: email_colaborador com formato inválido é ignorado, sem erro de validação ───

    public function test_atualizar_cadastro_com_email_colaborador_invalido_e_ignorado_sem_erro_de_validacao(): void
    {
        $admin   = $this->admin();
        $empresa = $this->empresaIncompleta(['name' => 'Empresa Email Colaborador Inválido']);

        $response = $this->actingAs($admin)->patch(route('admin.contratos.cadastro', $empresa), [
            'email_colaborador' => 'nao-e-um-email',
        ]);

        // O campo saiu da regra de validação desta tela — não gera erro de
        // sessão nem bloqueia o PATCH, simplesmente é ignorado.
        $response->assertRedirect();
        $response->assertSessionDoesntHaveErrors('email_colaborador');
        $this->assertNull($empresa->fresh()->email_colaborador);
    }

    // ─── Caso 8 — POST gerar para empresa incompleta: flash de erro (não mais 422 cru) e ZERO ContratoAssinatura ───
    //
    // Quick 260819-guy (Tarefa 7 item 2) — antes era `abort(422, ...)`, que
    // renderizava a página branca do Symfony, fora da aplicação. Agora é
    // `back()->with('error', ...)`, igual ao ramo de emissão congelada e ao
    // do Caso 11 abaixo — a checagem no servidor continua a mesma, só a
    // apresentação mudou.

    public function test_gerar_contrato_para_empresa_incompleta_devolve_flash_de_erro_e_nao_cria_nada(): void
    {
        $admin   = $this->admin();
        $empresa = $this->empresaIncompleta(['name' => 'Empresa Incompleta Gerar']);
        $servico = $this->servicoComContrato();
        $this->vincularServico($empresa, $servico);

        $response = $this->actingAs($admin)->post(route('admin.contratos.gerar', $empresa));

        $response->assertRedirect();
        $response->assertSessionHas('error');
        $response->assertSessionMissing('success');
        // Reconsulta ao banco — nunca confia só no flash da tela.
        $this->assertSame(0, ContratoAssinatura::where('company_id', $empresa->id)->count());
    }

    // ─── Caso 11 — o BLOCKER: elegível, mas configuração da ECF faltando ───
    // `disparado` NÃO é sucesso quando `resultado.ok` é falso — a tela nunca
    // pode dizer "Contrato gerado" com zero ContratoAssinatura criado.

    public function test_gerar_contrato_com_empresa_elegivel_mas_configuracao_da_ecf_faltando_devolve_erro_sem_criar_nada(): void
    {
        $admin   = $this->admin();
        $empresa = $this->empresaCompleta(['name' => 'Empresa Elegível Sem Config ECF']);
        $servico = $this->servicoComContrato();
        $this->vincularServico($empresa, $servico);

        // Estado padrão de qualquer ambiente recém-configurado (ver docblock
        // de ContratoDadosMinimosService::faltantesDaConfiguracaoEcf()) —
        // reforçado explicitamente aqui, não deixado ao acaso do setUp.
        config(['services.clicksign.signatarios_ecf' => [
            ['nome' => '', 'email' => '', 'papel' => 'contratada'],
        ]]);

        $response = $this->actingAs($admin)->post(route('admin.contratos.gerar', $empresa));

        $response->assertRedirect();
        $response->assertSessionHas('error');
        $response->assertSessionMissing('success');

        $mensagem = session('error');
        $this->assertStringContainsString('configuração interna da ECF', $mensagem);
        $this->assertStringContainsString('time técnico', $mensagem);

        // Reconsulta ao banco — a prova real de que nada foi criado, nunca a
        // mensagem de sucesso/erro da tela.
        $this->assertSame(0, ContratoAssinatura::where('company_id', $empresa->id)->count());

        Http::assertNothingSent();
    }

    // ─── Caso 9 — POST gerar para empresa completa cria contrato em rascunho ───

    public function test_gerar_contrato_para_empresa_completa_cria_contrato_em_rascunho(): void
    {
        $admin   = $this->admin();
        $empresa = $this->empresaCompleta(['name' => 'Empresa Pronta Para Gerar']);
        $servico = $this->servicoComContrato();
        $this->vincularServico($empresa, $servico);

        // Caminho feliz — os 3 signatários fixos da ECF configurados.
        config(['services.clicksign.signatarios_ecf' => $this->signatariosEcfOk()]);

        $response = $this->actingAs($admin)->post(route('admin.contratos.gerar', $empresa));

        $response->assertRedirect();
        $response->assertSessionHas('success');
        $response->assertSessionMissing('error');

        // Reconsulta ao banco — nunca confia na mensagem de sucesso da tela.
        $contrato = ContratoAssinatura::where('company_id', $empresa->id)->first();
        $this->assertNotNull($contrato);
        $this->assertSame(ContratoAssinatura::STATUS_RASCUNHO, $contrato->status);
    }

    // ─── Caso 10 — prop de signatários NUNCA traz email/cpf/clicksign_signer_key ───

    public function test_prop_de_signatarios_nunca_traz_email_cpf_ou_clicksign_signer_key(): void
    {
        $admin   = $this->admin();
        $empresa = $this->empresaCompleta(['name' => 'Empresa Com Signatário']);
        $servico = $this->servicoComContrato();
        $this->vincularServico($empresa, $servico);

        $contrato = ContratoAssinatura::factory()->emAndamento()->create([
            'company_id' => $empresa->id,
            'servico_id' => $servico->id,
        ]);

        ContratoAssinaturaSignatario::create([
            'contrato_assinatura_id' => $contrato->id,
            'papel'                  => ContratoAssinaturaSignatario::PAPEL_CONTRATANTE,
            'nome'                   => 'Fulano Signatário',
            'email'                  => 'fulano@example.com',
            'cpf'                    => '123.456.789-00',
            'situacao'               => ContratoAssinaturaSignatario::SITUACAO_PENDENTE,
            'clicksign_signer_key'   => 'signer-key-teste',
        ]);

        $response = $this->actingAs($admin)->get(route('admin.contratos.show', $empresa));

        $response->assertOk();
        $props = $response->viewData('page')['props'];

        $contratoProp = collect($props['contratos'])->firstWhere('id', $contrato->id);
        $this->assertNotNull($contratoProp);
        $this->assertCount(1, $contratoProp['signatarios']);

        $signatarioProp = $contratoProp['signatarios'][0];
        $this->assertArrayHasKey('nome', $signatarioProp);
        $this->assertArrayHasKey('papel', $signatarioProp);
        $this->assertArrayHasKey('situacao', $signatarioProp);
        $this->assertArrayNotHasKey('email', $signatarioProp);
        $this->assertArrayNotHasKey('cpf', $signatarioProp);
        $this->assertArrayNotHasKey('clicksign_signer_key', $signatarioProp);
    }

    // ─── Quick 260819-guy — Tarefa 7 item 1: erro_mensagem exposta e "já tentou antes" derivado ───

    public function test_contrato_em_erro_expoe_erro_mensagem_e_ja_tentou_antes_falso_na_primeira_tentativa(): void
    {
        $admin   = $this->admin();
        $empresa = $this->empresaCompleta(['name' => 'Empresa Primeira Tentativa Com Erro']);
        $servico = $this->servicoComContrato();
        $this->vincularServico($empresa, $servico);

        $contrato = ContratoAssinatura::factory()->create([
            'company_id'    => $empresa->id,
            'servico_id'    => $servico->id,
            'status'        => ContratoAssinatura::STATUS_ERRO,
            'erro_mensagem' => '[Clicksign] name não está em um formato válido',
        ]);

        $response = $this->actingAs($admin)->get(route('admin.contratos.show', $empresa));
        $response->assertOk();

        $contratoProp = collect($response->viewData('page')['props']['contratos'])->firstWhere('id', $contrato->id);
        $this->assertNotNull($contratoProp);
        $this->assertSame('[Clicksign] name não está em um formato válido', $contratoProp['erro_mensagem']);
        $this->assertFalse($contratoProp['ja_tentou_antes'], 'é a única linha deste serviço — é a primeira tentativa.');
    }

    public function test_segunda_linha_de_erro_do_mesmo_servico_vem_com_ja_tentou_antes_verdadeiro(): void
    {
        $admin   = $this->admin();
        $empresa = $this->empresaCompleta(['name' => 'Empresa Segunda Tentativa Com Erro']);
        $servico = $this->servicoComContrato();
        $this->vincularServico($empresa, $servico);

        // Cada nova tentativa nasce como uma linha NOVA (a antiga, em erro,
        // já liberou o slot — GerarContratoAssinaturaJob::failed()).
        $primeiraTentativa = ContratoAssinatura::factory()->create([
            'company_id'    => $empresa->id,
            'servico_id'    => $servico->id,
            'status'        => ContratoAssinatura::STATUS_ERRO,
            'erro_mensagem' => 'Primeira falha',
        ]);

        $segundaTentativa = ContratoAssinatura::factory()->create([
            'company_id'    => $empresa->id,
            'servico_id'    => $servico->id,
            'status'        => ContratoAssinatura::STATUS_ERRO,
            'erro_mensagem' => 'Segunda falha',
        ]);

        $response = $this->actingAs($admin)->get(route('admin.contratos.show', $empresa));
        $response->assertOk();

        $props = collect($response->viewData('page')['props']['contratos']);

        $this->assertFalse($props->firstWhere('id', $primeiraTentativa->id)['ja_tentou_antes']);
        $this->assertTrue($props->firstWhere('id', $segundaTentativa->id)['ja_tentou_antes']);
    }

    // ─── Quick 260821-odj — nome de uma palavra só GRAVA os demais campos ───
    //
    // SUPERSEDE o teste antigo (Quick 260819-guy Tarefa 7 item 4). É a
    // regressão MEDIDA do incidente em produção: empresa 430 Mons Bike,
    // `nome_contato = "Vitor"` (veio do contato do HubSpot), duas tentativas
    // de "Salvar cadastro" com razão social + CNPJ + os 5 campos de
    // endereço preenchidos, ambas `303` sem gravar nada.
    // `NomeCompletoValido`/`NomeCompleto::valido()` seguem em uso — só
    // migraram para o gate da GERAÇÃO.
    public function test_atualizar_cadastro_com_nome_contato_de_uma_palavra_grava_os_demais_campos(): void
    {
        $admin   = $this->admin();
        $empresa = $this->empresaIncompleta(['name' => 'Empresa Nome Sem Sobrenome']);

        $response = $this->actingAs($admin)->patch(route('admin.contratos.cadastro', $empresa), [
            'nome_contato'  => 'Vitor',
            'cnpj'          => '26.754.383/0001-87',
            'razao_social'  => 'Empresa Nome Sem Sobrenome LTDA',
            'endereco'      => 'Rua de Teste, 123',
            'bairro'        => 'Bairro de Teste',
            'cidade'        => 'Cidade de Teste',
            'estado'        => 'TS',
            'cep'           => '00000-000',
        ]);

        $response->assertSessionDoesntHaveErrors('nome_contato');
        $response->assertSessionHas('success');

        // RECONSULTA ao banco — nunca por stdout.
        $fresca = $empresa->fresh();
        $this->assertSame('Vitor', $fresca->nome_contato);
        $this->assertSame('26.754.383/0001-87', $fresca->cnpj);
        $this->assertSame('Empresa Nome Sem Sobrenome LTDA', $fresca->razao_social);
        $this->assertSame('Rua de Teste, 123', $fresca->endereco);

        // O gate da GERAÇÃO não afrouxou: nome de uma palavra só continua
        // bloqueando a geração com motivo 'formato'.
        $response2 = $this->actingAs($admin)->get(route('admin.contratos.show', $empresa));
        $props = $response2->viewData('page')['props'];
        $item = collect($props['faltantes'])->firstWhere('campo', 'nome_contato');
        $this->assertNotNull($item, 'nome_contato de uma palavra só deveria seguir bloqueando a geração.');
        $this->assertSame('formato', $item['motivo']);
    }

    public function test_atualizar_cadastro_com_nome_completo_e_aceito(): void
    {
        $admin   = $this->admin();
        $empresa = $this->empresaIncompleta(['name' => 'Empresa Nome Completo']);

        $response = $this->actingAs($admin)->patch(route('admin.contratos.cadastro', $empresa), [
            'nome_contato' => 'Maria Silva',
        ]);

        $response->assertSessionDoesntHaveErrors('nome_contato');
        $this->assertSame('Maria Silva', $empresa->fresh()->nome_contato);
    }

    public function test_nome_contato_de_uma_palavra_so_entra_em_faltantes_como_motivo_formato(): void
    {
        $empresa = $this->empresaCompleta(['name' => 'Empresa Nome Formato Ruim', 'nome_contato' => 'teste']);

        $faltantes = app(ContratoDadosMinimosService::class)->faltantes($empresa);
        $item = collect($faltantes)->firstWhere('campo', 'nome_contato');

        $this->assertNotNull($item, 'nome_contato de uma palavra só deveria aparecer em faltantes().');
        $this->assertSame('formato', $item['motivo']);
    }
}
