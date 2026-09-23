<?php

namespace Tests\Feature\Phase158;

use App\Models\Company;
use App\Models\Onboarding;
use App\Models\OnboardingConfirmacao;
use App\Models\OnboardingPasso;
use App\Models\PortalUsuario;
use App\Models\Servico;
use App\Models\User;
use App\Services\Onboarding\OnboardingLinkService;
use App\Support\Portal\AtorDoPortal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * 14/09 — os itens CONDUZIDOS na reunião, registrados pelo portal.
 *
 * O que este teste protege, acima de tudo, é a guarda de quem registra. Os
 * cinco "explicados" são `dono=interno` e passaram a aparecer no portal. Existe
 * um incidente REAL documentado em `marcarFeitoPorChave()`: em 21/08, sem essa
 * guarda, um PATCH cru com o token — sem sessão e sem CSRF — fechou
 * `adman_preenchimento_interno` sem deixar marca de origem. Levar passo interno
 * para o portal sem repetir a guarda reabriria exatamente aquele buraco.
 */
class ConfirmacaoNoPortalTest extends TestCase
{
    use RefreshDatabase;

    private static int $seq = 0;

    private const CHAVE = 'publicidade_processo_explicado';

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        Queue::fake();
    }

    /** @return array{empresa: Company, onboarding: Onboarding} */
    private function cenario(): array
    {
        $n = str_pad((string) (++self::$seq), 4, '0', STR_PAD_LEFT);

        $empresa = Company::factory()->create([
            'active' => true,
            'name'   => 'Empresa 158 '.$n,
            'cnpj'   => "15.815.815/{$n}-81",
        ]);

        $servico = Servico::create([
            'nome'          => 'Serviço 158 '.$n,
            'valor_padrao'  => 100,
            'tipo_cobranca' => Servico::TIPO_MENSAL,
            'ativo'         => true,
            'setor'         => Servico::SETOR_OUTROS,
        ]);

        $onboarding = Onboarding::create([
            'company_id'  => $empresa->id,
            'servico_id'  => $servico->id,
            'status'      => Onboarding::STATUS_ANDAMENTO,
            'iniciado_em' => now(),
        ]);

        OnboardingPasso::create([
            'onboarding_id' => $onboarding->id,
            'ordem'         => 30,
            'etapa'         => 'publicidade',
            'natureza'      => OnboardingPasso::NATUREZA_ACAO,
            'chave'         => self::CHAVE,
            'titulo'        => 'Processo de publicidade explicado',
            'dono'          => OnboardingPasso::DONO_INTERNO,
            'auto_fonte'    => OnboardingPasso::AUTO_FONTE_CONFIRMACAO,
            'status'        => OnboardingPasso::STATUS_ABERTO,
        ]);

        // Um segundo passo, ABERTO e alheio à confirmação: sem ele o
        // onboarding inteiro conclui quando o único passo fecha, e
        // `passosDoPortal()` — que só olha onboarding EM ANDAMENTO — devolve
        // vazio. O item sumiria da tela por conclusão, não por defeito.
        OnboardingPasso::create([
            'onboarding_id' => $onboarding->id,
            'ordem'         => 3,
            'etapa'         => 'acessos',
            'natureza'      => OnboardingPasso::NATUREZA_ACAO,
            'chave'         => 'grant_sistema_ecf',
            'titulo'        => 'Grant com o Sistema ECF (OAuth)',
            'dono'          => OnboardingPasso::DONO_CLIENTE,
            'auto_fonte'    => OnboardingPasso::AUTO_FONTE_ML_TOKEN,
            'status'        => OnboardingPasso::STATUS_ABERTO,
        ]);

        return ['empresa' => $empresa, 'onboarding' => $onboarding];
    }

    private function service(): OnboardingLinkService
    {
        return app(OnboardingLinkService::class);
    }

    private function daEquipe(): AtorDoPortal
    {
        return AtorDoPortal::daEquipe(User::factory()->create(['role' => 'consultor', 'active' => true]));
    }

    // ─── A guarda ───────────────────────────────────────────────────────────

    /** Ator ausente é o modo por TOKEN: anônimo, e foi por aí que o buraco de 21/08 passou. */
    public function test_sem_ator_a_resposta_e_recusada(): void
    {
        $c = $this->cenario();

        $this->expectException(\DomainException::class);

        $this->service()->responderConfirmacaoPorChave(
            $c['empresa'], self::CHAVE, OnboardingConfirmacao::RESPOSTA_SIM, 'tentativa anônima', null
        );
    }

    /**
     * O caso mais perigoso: o cliente AUTENTICADO. Ele tem sessão de verdade,
     * então uma guarda escrita como "tem ator?" o deixaria passar — e ele
     * fecharia item interno. A régua é `equipe`, não a presença de ator.
     */
    public function test_cliente_autenticado_tambem_e_recusado(): void
    {
        $c = $this->cenario();

        $usuario = PortalUsuario::create([
            'company_id' => $c['empresa']->id,
            'nome'       => 'Cliente Autenticado',
            'email'      => 'cliente158@exemplo.com.br',
            'ativo'      => true,
        ]);

        try {
            $this->service()->responderConfirmacaoPorChave(
                $c['empresa'],
                self::CHAVE,
                OnboardingConfirmacao::RESPOSTA_SIM,
                'tentativa do cliente',
                AtorDoPortal::cliente($usuario)
            );

            $this->fail('cliente autenticado não pode registrar item interno');
        } catch (\DomainException) {
            // esperado
        }

        $this->assertSame(0, OnboardingConfirmacao::count(), 'nada pode ter sido gravado');
    }

    public function test_chave_fora_do_portal_e_recusada_mesmo_para_a_equipe(): void
    {
        $c = $this->cenario();

        $this->expectException(\DomainException::class);

        $this->service()->responderConfirmacaoPorChave(
            $c['empresa'], 'adman_preenchimento_interno', OnboardingConfirmacao::RESPOSTA_SIM, null, $this->daEquipe()
        );
    }

    // ─── O caminho feliz ────────────────────────────────────────────────────

    public function test_equipe_registra_resposta_observacao_e_autoria(): void
    {
        $c = $this->cenario();
        $ator = $this->daEquipe();

        $n = $this->service()->responderConfirmacaoPorChave(
            $c['empresa'], self::CHAVE, OnboardingConfirmacao::RESPOSTA_SIM, 'explicado na call de hoje', $ator
        );

        $this->assertSame(1, $n);

        $linha = OnboardingConfirmacao::where('onboarding_id', $c['onboarding']->id)
            ->where('chave', self::CHAVE)
            ->firstOrFail();

        $this->assertSame(OnboardingConfirmacao::RESPOSTA_SIM, $linha->resposta);
        $this->assertSame('explicado na call de hoje', $linha->observacoes);
        $this->assertSame($ator->modelo->id, $linha->respondido_por);
        $this->assertNotNull($linha->respondido_em);
    }

    /** O passo fecha pelo RESOLVER, não por escrita direta de status. */
    public function test_resposta_sim_fecha_o_passo(): void
    {
        $c = $this->cenario();

        $this->service()->responderConfirmacaoPorChave(
            $c['empresa'], self::CHAVE, OnboardingConfirmacao::RESPOSTA_SIM, null, $this->daEquipe()
        );

        $passo = OnboardingPasso::where('onboarding_id', $c['onboarding']->id)
            ->where('chave', self::CHAVE)
            ->firstOrFail();

        $this->assertSame(OnboardingPasso::STATUS_CONCLUIDO, $passo->status);
    }

    public function test_resposta_pendente_guarda_a_observacao_sem_fechar(): void
    {
        $c = $this->cenario();

        $this->service()->responderConfirmacaoPorChave(
            $c['empresa'], self::CHAVE, OnboardingConfirmacao::RESPOSTA_PENDENTE, 'cliente vai confirmar depois', $this->daEquipe()
        );

        $passo = OnboardingPasso::where('onboarding_id', $c['onboarding']->id)
            ->where('chave', self::CHAVE)
            ->firstOrFail();

        $this->assertNotSame(OnboardingPasso::STATUS_CONCLUIDO, $passo->status);
        $this->assertSame(
            'cliente vai confirmar depois',
            OnboardingConfirmacao::where('onboarding_id', $c['onboarding']->id)->value('observacoes'),
            'a observação de quem ainda vai confirmar precisa de onde morar sem forçar um Sim.'
        );
    }

    // ─── O payload do portal ────────────────────────────────────────────────

    public function test_a_resposta_registrada_chega_ao_portal_junto_do_item(): void
    {
        $c = $this->cenario();

        $this->service()->responderConfirmacaoPorChave(
            $c['empresa'], self::CHAVE, OnboardingConfirmacao::RESPOSTA_SIM, 'nota da reunião', $this->daEquipe()
        );

        $item = collect($this->service()->passosDoPortal($c['empresa']))
            ->firstWhere('chave', self::CHAVE);

        $this->assertNotNull($item, 'o item de confirmação precisa aparecer no portal');
        $this->assertSame(OnboardingLinkService::ACAO_CONFIRMAR, $item['acao']);
        $this->assertSame(OnboardingConfirmacao::RESPOSTA_SIM, $item['confirmacao']['resposta']);
        $this->assertSame('nota da reunião', $item['confirmacao']['observacoes']);
        $this->assertNotNull($item['confirmacao']['respondido_por']);
    }

    public function test_item_sem_resposta_chega_com_confirmacao_nula(): void
    {
        $c = $this->cenario();

        $item = collect($this->service()->passosDoPortal($c['empresa']))
            ->firstWhere('chave', self::CHAVE);

        $this->assertNotNull($item);
        $this->assertNull($item['confirmacao']);
    }

    // ─── O check do portal (23/09/2026) ─────────────────────────────────────

    /**
     * O portal virou só um check nestes itens e deixou de mandar observação.
     * A observação escrita pela ficha interna precisa sobreviver ao check e ao
     * desmarcar — mandar `null` a apagaria calada.
     */
    public function test_check_e_desmarcar_do_portal_preservam_a_observacao_da_ficha(): void
    {
        $c = $this->cenario();

        $this->service()->responderConfirmacaoPorChave(
            $c['empresa'], self::CHAVE, OnboardingConfirmacao::RESPOSTA_NAO, 'escrita na ficha interna', $this->daEquipe()
        );

        $this->service()->responderConfirmacaoPorChave(
            $c['empresa'], self::CHAVE, OnboardingConfirmacao::RESPOSTA_SIM, null, $this->daEquipe(), manterObservacoes: true
        );

        $passo = OnboardingPasso::where('onboarding_id', $c['onboarding']->id)->where('chave', self::CHAVE)->first();
        $this->assertSame(OnboardingPasso::STATUS_CONCLUIDO, $passo->status, 'o check grava "Sim", que fecha o item');

        $this->service()->responderConfirmacaoPorChave(
            $c['empresa'], self::CHAVE, OnboardingConfirmacao::RESPOSTA_PENDENTE, null, $this->daEquipe(), manterObservacoes: true
        );

        $confirmacao = OnboardingConfirmacao::where('onboarding_id', $c['onboarding']->id)->first();
        $this->assertSame(OnboardingConfirmacao::RESPOSTA_PENDENTE, $confirmacao->resposta);
        $this->assertSame('escrita na ficha interna', $confirmacao->observacoes);
        $this->assertNotSame(OnboardingPasso::STATUS_CONCLUIDO, $passo->fresh()->status, 'desmarcar reabre o item');
    }

    /** Sem a flag, a observação continua sendo o que veio — a ficha interna depende disso para limpar. */
    public function test_sem_a_flag_observacao_nula_limpa_como_antes(): void
    {
        $c = $this->cenario();

        $this->service()->responderConfirmacaoPorChave(
            $c['empresa'], self::CHAVE, OnboardingConfirmacao::RESPOSTA_SIM, 'vai sumir', $this->daEquipe()
        );
        $this->service()->responderConfirmacaoPorChave(
            $c['empresa'], self::CHAVE, OnboardingConfirmacao::RESPOSTA_SIM, null, $this->daEquipe()
        );

        $this->assertNull(OnboardingConfirmacao::where('onboarding_id', $c['onboarding']->id)->value('observacoes'));
    }
}
