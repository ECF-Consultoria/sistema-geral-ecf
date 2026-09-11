<?php

namespace Tests\Feature\Phase153;

use App\Models\BoasVindasTemplate;
use App\Models\Company;
use App\Models\ContratoServico;
use App\Models\OnboardingLink;
use App\Models\Servico;
use App\Models\User;
use App\Services\BoasVindas\MensagemBoasVindasService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Fase 153 (COMUNIC-01/02/03) — o motor da mensagem de boas-vindas.
 *
 * Cobre o que o caminho do Polos NUNCA teve coberto: a substituição dos
 * placeholders. Lá ela vive no JSX (`ImplModal.jsx`), sem teste — e é
 * exatamente onde um link errado tem consequência fora do sistema.
 */
class MensagemBoasVindasTest extends TestCase
{
    use RefreshDatabase;

    private static int $seqCnpj = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        Queue::fake();
        config(['services.adman.register_url' => 'https://app.ad-man.io/register?ref=TESTE']);
    }

    private function servico(string $nome, bool $exigeContrato = true): Servico
    {
        return Servico::create([
            'nome'           => $nome,
            'valor_padrao'   => 100,
            'tipo_cobranca'  => Servico::TIPO_MENSAL,
            'ativo'          => true,
            'setor'          => Servico::SETOR_PERFORMANCE,
            'exige_contrato' => $exigeContrato,
        ]);
    }

    private function empresa(array $overrides = []): Company
    {
        $n = str_pad((string) (++self::$seqCnpj), 4, '0', STR_PAD_LEFT);

        return Company::factory()->create(array_merge([
            'active'            => true,
            'name'              => 'Empresa Boas-Vindas '.$n,
            'cnpj'              => "15.315.315/{$n}-31",
            'email_colaborador' => 'colaborador@example.com',
        ], $overrides));
    }

    private function vincular(Company $c, Servico $s): ContratoServico
    {
        return ContratoServico::withoutEvents(fn () => ContratoServico::create([
            'company_id'            => $c->id,
            'servico_id'            => $s->id,
            'valor_contratado'      => 100,
            'data_contratacao'      => now()->toDateString(),
            'data_primeira_parcela' => now()->addMonth()->toDateString(),
            'dia_vencimento'        => 10,
            'ativo'                 => true,
        ]));
    }

    private function comLink(Company $c): OnboardingLink
    {
        return OnboardingLink::create([
            'company_id' => $c->id,
            'token'      => 'tok-153-'.$c->id,
        ]);
    }

    private function servico153(): MensagemBoasVindasService
    {
        return app(MensagemBoasVindasService::class);
    }

    // ─── COMUNIC-02 — os 6 blocos preenchidos ───────────────────────────────

    public function test_mensagem_traz_os_seis_blocos_preenchidos_e_sem_placeholder_sobrando(): void
    {
        $empresa = $this->empresa(['name' => 'Acme Comercio']);
        $this->vincular($empresa, $this->servico('Publicação 153'));
        $link = $this->comLink($empresa);

        $r = $this->servico153()->paraEmpresa($empresa->fresh());

        $this->assertTrue($r['pronta'], 'Com todos os dados presentes a mensagem tem de sair pronta. Pendências: '.implode(' | ', $r['pendencias']));
        $this->assertSame([], $r['pendencias']);

        // Bloco 1 — boas-vindas nomeando a empresa.
        $this->assertStringContainsString('Acme Comercio', $r['texto']);
        // Bloco 2 — e-mail colaborador.
        $this->assertStringContainsString('colaborador@example.com', $r['texto']);
        // Bloco 3 — link do Adman, vindo do config.
        $this->assertStringContainsString('https://app.ad-man.io/register?ref=TESTE', $r['texto']);
        // Blocos 4 e 5 — os dois links do cliente.
        $this->assertStringContainsString(route('onboarding.publico.conectar-ml', $link->token), $r['texto']);
        $this->assertStringContainsString(route('portal.inicio', $link->token), $r['texto']);
        // Bloco 6 — orientações.
        $this->assertStringContainsString('O que precisamos de você agora', $r['texto']);

        // Nenhum placeholder pendurado no texto entregue.
        $this->assertDoesNotMatchRegularExpression('/\{[a-z_]+\}/', $r['texto']);
    }

    // ─── O link do OAuth é o PÚBLICO, jamais o interno ──────────────────────

    /**
     * A asserção mais importante desta suíte. `ml.oauth.initiate` é autenticada:
     * clicada por um usuário ECF logado, autoriza a conta do Mercado Livre DELE
     * como se fosse a do cliente. Esta mensagem vai para o cliente.
     */
    public function test_link_de_oauth_e_a_rota_publica_por_token_nunca_a_interna(): void
    {
        $empresa = $this->empresa();
        $this->vincular($empresa, $this->servico('Publicação 153'));
        $link = $this->comLink($empresa);

        $texto = $this->servico153()->paraEmpresa($empresa->fresh())['texto'];

        $this->assertStringContainsString('portal-cliente/'.$link->token.'/onboarding/conectar/ml', $texto);
        $this->assertStringNotContainsString('/ml/initiate', $texto);
        $this->assertStringNotContainsString('companies/'.$empresa->id.'/ml', $texto);
    }

    // ─── COMUNIC-03 — funciona para qualquer serviço, não só Polos ──────────

    public function test_empresa_de_servico_sem_template_proprio_recebe_o_generico(): void
    {
        $empresa = $this->empresa();
        $this->vincular($empresa, $this->servico('Assessoria 153'));
        $this->comLink($empresa);

        BoasVindasTemplate::salvarGenerico('Genérico: olá {empresa}, seu link é {link_sistema}.');

        $r = $this->servico153()->paraEmpresa($empresa->fresh());

        $this->assertNull($r['template_servico_id'], 'Serviço sem template próprio tem de cair no genérico.');
        $this->assertStringContainsString('Genérico: olá '.$empresa->name, $r['texto']);
    }

    public function test_template_do_servico_vence_o_generico(): void
    {
        $empresa = $this->empresa();
        $servico = $this->servico('Incubadora 153');
        $this->vincular($empresa, $servico);
        $this->comLink($empresa);

        BoasVindasTemplate::salvarGenerico('TEXTO GENERICO');
        BoasVindasTemplate::salvarParaServico($servico->id, 'TEXTO DO SERVICO para {empresa}');

        $r = $this->servico153()->paraEmpresa($empresa->fresh());

        $this->assertSame($servico->id, $r['template_servico_id']);
        $this->assertStringContainsString('TEXTO DO SERVICO', $r['texto']);
        $this->assertStringNotContainsString('TEXTO GENERICO', $r['texto']);
    }

    // ─── D-D — desempate determinístico com mais de um serviço ──────────────

    public function test_com_dois_servicos_com_template_vence_o_de_menor_id(): void
    {
        $empresa = $this->empresa();
        $primeiro = $this->servico('AAA 153');   // id menor
        $segundo  = $this->servico('BBB 153');   // id maior
        $this->vincular($empresa, $primeiro);
        $this->vincular($empresa, $segundo);
        $this->comLink($empresa);

        BoasVindasTemplate::salvarParaServico($primeiro->id, 'DO PRIMEIRO');
        BoasVindasTemplate::salvarParaServico($segundo->id, 'DO SEGUNDO');

        // Duas chamadas seguidas: o desempate é estável, não depende da ordem de carga.
        $a = $this->servico153()->paraEmpresa($empresa->fresh());
        $b = $this->servico153()->paraEmpresa($empresa->fresh());

        $this->assertSame($primeiro->id, $a['template_servico_id']);
        $this->assertSame($a['template_servico_id'], $b['template_servico_id']);
        $this->assertStringContainsString('DO PRIMEIRO', $a['texto']);
    }

    public function test_servico_inativo_nao_escolhe_o_template(): void
    {
        $empresa = $this->empresa();
        $ativo   = $this->servico('Ativo 153');
        $inativo = $this->servico('Inativo 153');
        $this->vincular($empresa, $ativo);
        $vinculoInativo = $this->vincular($empresa, $inativo);
        ContratoServico::withoutEvents(fn () => $vinculoInativo->update(['ativo' => false]));
        $this->comLink($empresa);

        BoasVindasTemplate::salvarParaServico($ativo->id, 'DO ATIVO');
        BoasVindasTemplate::salvarParaServico($inativo->id, 'DO INATIVO');

        $r = $this->servico153()->paraEmpresa($empresa->fresh());

        $this->assertSame($ativo->id, $r['template_servico_id']);
        $this->assertStringNotContainsString('DO INATIVO', $r['texto']);
    }

    // ─── Pendências: bloco sem dado avisa em vez de entregar texto quebrado ──

    public function test_sem_conexao_ecf_a_mensagem_nao_sai_pronta_e_diz_o_que_falta(): void
    {
        $empresa = $this->empresa();
        $this->vincular($empresa, $this->servico('Publicação 153'));
        // De propósito: nenhum OnboardingLink.

        $r = $this->servico153()->paraEmpresa($empresa->fresh());

        $this->assertFalse($r['pronta']);
        $this->assertCount(1, $r['pendencias'], 'Os dois links saem do MESMO OnboardingLink — a causa é uma só, a pendência também.');
        $this->assertStringContainsString('Conexão com o sistema ECF', $r['pendencias'][0]);
    }

    public function test_sem_email_colaborador_a_mensagem_avisa(): void
    {
        $empresa = $this->empresa(['email_colaborador' => null]);
        $this->vincular($empresa, $this->servico('Publicação 153'));
        $this->comLink($empresa);

        $r = $this->servico153()->paraEmpresa($empresa->fresh());

        $this->assertFalse($r['pronta']);
        $this->assertStringContainsString('E-mail colaborador', implode(' ', $r['pendencias']));
    }

    // ─── D-C — a aplicação garante o genérico único, não o índice ───────────

    /**
     * MariaDB permite N linhas com `servico_id` NULL num índice único. Quem
     * garante a linha única é `salvarGenerico()` — este teste falha se alguém
     * trocar a chamada por um `create()` direto.
     */
    public function test_salvar_generico_duas_vezes_atualiza_a_mesma_linha(): void
    {
        BoasVindasTemplate::salvarGenerico('PRIMEIRO');
        BoasVindasTemplate::salvarGenerico('SEGUNDO');

        $this->assertSame(1, BoasVindasTemplate::whereNull('servico_id')->count());
        $this->assertSame('SEGUNDO', BoasVindasTemplate::generico()->texto);
    }

    public function test_autoria_da_edicao_e_gravada(): void
    {
        $user = User::factory()->create(['role' => 'admin']);

        $t = BoasVindasTemplate::salvarGenerico('COM AUTOR', $user->id);

        $this->assertSame($user->id, $t->atualizado_por);
        $this->assertSame($user->name, $t->fresh()->atualizadoPor->name);
    }

    // ─── Empresa sem serviço nenhum ainda assim tem mensagem ────────────────

    public function test_empresa_sem_servico_ativo_cai_no_generico_sem_quebrar(): void
    {
        $empresa = $this->empresa();
        $this->comLink($empresa);

        $r = $this->servico153()->paraEmpresa($empresa->fresh());

        $this->assertNull($r['template_servico_id']);
        $this->assertNotEmpty($r['texto']);
        $this->assertStringContainsString($empresa->name, $r['texto']);
    }
}
