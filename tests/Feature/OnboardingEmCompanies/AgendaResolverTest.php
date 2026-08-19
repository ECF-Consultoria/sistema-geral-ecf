<?php

namespace Tests\Feature\OnboardingEmCompanies;

use App\Models\Company;
use App\Models\Onboarding;
use App\Models\OnboardingAgenda;
use App\Models\OnboardingPasso;
use App\Models\Servico;
use App\Models\User;
use App\Services\Onboarding\Resolvers\AgendaQuinzenalResolver;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Agenda das reuniões quinzenais (§14) — a REGRA combinada, não a reunião de
 * kickoff.
 *
 * O teste que mais importa aqui é o último: as quatro colunas `reuniao_*` de
 * `onboardings` são a reunião ÚNICA de kickoff e têm que sair intactas de
 * definir a agenda. Fundir as duas coisas é o erro previsível — os nomes se
 * parecem — e ele só apareceria como data de kickoff sobrescrita, semanas
 * depois, sem ninguém saber de onde veio.
 */
class AgendaResolverTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Onboarding mínimo + o passo com a `auto_fonte` da agenda, para
     * exercitar o resolver isoladamente.
     *
     * @return array{0: Onboarding, 1: OnboardingPasso}
     */
    private function criarOnboardingComPassoDeAgenda(): array
    {
        $company = Company::factory()->create();

        $servico = Servico::create([
            'nome'          => 'Gestao Agenda '.uniqid(),
            'valor_padrao'  => 3000,
            'tipo_cobranca' => Servico::TIPO_MENSAL,
            'ativo'         => true,
            'setor'         => Servico::SETOR_PERFORMANCE,
        ]);

        $onboarding = Onboarding::create([
            'company_id' => $company->id,
            'servico_id' => $servico->id,
        ]);

        $passo = OnboardingPasso::create([
            'onboarding_id' => $onboarding->id,
            'ordem'         => 1,
            'chave'         => 'agenda_reunioes_quinzenais',
            'titulo'        => 'Agenda das reuniões quinzenais',
            'dono'          => OnboardingPasso::DONO_INTERNO,
            'auto_fonte'    => OnboardingPasso::AUTO_FONTE_AGENDA_QUINZENAL,
        ]);

        return [$onboarding, $passo];
    }

    /** @param array<string, mixed> $atributos */
    private function criarAgenda(Onboarding $onboarding, array $atributos = []): OnboardingAgenda
    {
        return OnboardingAgenda::create(array_merge([
            'onboarding_id' => $onboarding->id,
            'dia_semana'    => 2,
            'horario'       => '14:00',
            'periodicidade' => OnboardingAgenda::PERIODICIDADE_QUINZENAL,
            'definida_em'   => now(),
        ], $atributos));
    }

    // ─── A chave do resolver bate com a constante ───────────────────────────

    /** @test */
    public function a_chave_do_resolver_e_a_constante_do_catalogo(): void
    {
        $this->assertSame(
            OnboardingPasso::AUTO_FONTE_AGENDA_QUINZENAL,
            app(AgendaQuinzenalResolver::class)->chave()
        );

        // Síncrono e sem rede: o dado está na tabela local ou não está.
        $this->assertFalse(app(AgendaQuinzenalResolver::class)->assincrono());
    }

    // ─── Os três campos fecham o passo ──────────────────────────────────────

    /** @test */
    public function agenda_com_dia_horario_e_periodicidade_resolve_concluido(): void
    {
        [$onboarding, $passo] = $this->criarOnboardingComPassoDeAgenda();
        $this->criarAgenda($onboarding);

        $resultado = app(AgendaQuinzenalResolver::class)->resolver($onboarding, $passo);

        $this->assertTrue($resultado->ehConcluido());
        $this->assertFalse($resultado->ehIndeterminado());
        $this->assertSame(2, $resultado->valor['dia_semana']);
        $this->assertSame('14:00', $resultado->valor['horario']);
        $this->assertSame(
            OnboardingAgenda::PERIODICIDADE_QUINZENAL,
            $resultado->valor['periodicidade']
        );
        $this->assertSame('Quinzenal, terça-feira às 14:00', $resultado->valor['agenda_legivel']);
    }

    /** @test */
    public function onboarding_sem_agenda_nenhuma_resolve_nao_coletado(): void
    {
        [$onboarding, $passo] = $this->criarOnboardingComPassoDeAgenda();

        $resultado = app(AgendaQuinzenalResolver::class)->resolver($onboarding, $passo);

        $this->assertTrue($resultado->ehNaoColetado());
        $this->assertFalse($resultado->ehIndeterminado());
        $this->assertStringContainsString('ainda não combinada', $resultado->motivo);
    }

    // ─── Faltando cada campo, o motivo cita o campo certo ───────────────────

    /**
     * Sem esta cobertura o motivo pode citar o campo errado e ninguém nota:
     * a mensagem parece certa em qualquer um dos três casos.
     *
     * @test
     *
     * @dataProvider camposFaltantes
     */
    public function agenda_incompleta_diz_qual_campo_falta(
        string $campoAusente,
        string $rotuloEsperado,
        array $rotulosQueNaoDevemAparecer
    ): void {
        [$onboarding, $passo] = $this->criarOnboardingComPassoDeAgenda();
        $this->criarAgenda($onboarding, [$campoAusente => null]);

        $resultado = app(AgendaQuinzenalResolver::class)->resolver($onboarding, $passo);

        $this->assertTrue($resultado->ehNaoColetado());
        $this->assertStringContainsString($rotuloEsperado, $resultado->motivo);

        foreach ($rotulosQueNaoDevemAparecer as $rotulo) {
            $this->assertStringNotContainsString($rotulo, $resultado->motivo);
        }
    }

    /** @return array<string, array{0: string, 1: string, 2: array<int, string>}> */
    public static function camposFaltantes(): array
    {
        return [
            'sem dia da semana'  => ['dia_semana', 'dia da semana', ['horário', 'periodicidade']],
            'sem horário'        => ['horario', 'horário', ['dia da semana', 'periodicidade']],
            'sem periodicidade'  => ['periodicidade', 'periodicidade', ['dia da semana', 'horário']],
        ];
    }

    /** @test */
    public function agenda_incompleta_nao_produz_frase_legivel_pela_metade(): void
    {
        [$onboarding] = $this->criarOnboardingComPassoDeAgenda();
        $agenda = $this->criarAgenda($onboarding, ['dia_semana' => null]);

        // Meia frase ("Quinzenal, às 14:00") parece agenda combinada e não é.
        $this->assertNull($agenda->agenda_legivel);
        $this->assertFalse($agenda->completa());
        $this->assertSame(['dia da semana'], $agenda->camposPendentes());
    }

    // ─── O accessor legível ─────────────────────────────────────────────────

    /** @test */
    public function o_accessor_monta_a_agenda_legivel_em_pt_br(): void
    {
        [$onboarding] = $this->criarOnboardingComPassoDeAgenda();
        $agenda = $this->criarAgenda($onboarding, ['dia_semana' => 4, 'horario' => '09:30']);

        $this->assertSame('Quinzenal, quinta-feira às 09:30', $agenda->agenda_legivel);
    }

    /**
     * O SQLite guarda a string crua e o MariaDB normaliza sozinho para
     * `H:i:s`. Sem o mutator, "14:00" e "14:00:00" produziriam frases
     * diferentes conforme o banco.
     *
     * @test
     */
    public function o_horario_e_normalizado_e_lido_sem_os_segundos(): void
    {
        [$onboarding] = $this->criarOnboardingComPassoDeAgenda();
        $agenda = $this->criarAgenda($onboarding, ['horario' => '9:05'])->fresh();

        $this->assertSame('09:05:00', $agenda->horario);
        $this->assertSame('09:05', $agenda->horario_curto);
        $this->assertSame('Quinzenal, terça-feira às 09:05', $agenda->agenda_legivel);
    }

    /**
     * `dia_semana` é ISO-8601 (1 = segunda … 7 = domingo), igual ao
     * `dayOfWeekIso` do Carbon — e NÃO o `dayOfWeek` (0 = domingo). Trocar um
     * pelo outro desloca a semana inteira sem erro nenhum.
     *
     * @test
     */
    public function os_dias_da_semana_seguem_a_numeracao_iso_do_carbon(): void
    {
        // `startOfWeek()` sem argumento obedece à configuração de locale, que
        // neste app começa a semana no domingo — passar `Carbon::MONDAY`
        // explicitamente é o que torna a asserção sobre ISO-8601 honesta.
        $segunda = Carbon::now()->startOfWeek(Carbon::MONDAY);

        foreach (OnboardingAgenda::DIAS_SEMANA as $iso => $rotulo) {
            $dia = $segunda->copy()->addDays($iso - 1);

            // Comparar o ÍNDICE com o `dayOfWeekIso` do mesmo índice é
            // tautologia: passa com a tabela inteira deslocada em um dia. Quem
            // pega o deslocamento é o RÓTULO — o nome pt-BR do Carbon para
            // aquele dia tem que ser o nome que este catálogo promete.
            $this->assertSame(
                $rotulo,
                $dia->locale('pt_BR')->dayName,
                "DIAS_SEMANA[{$iso}] diz '{$rotulo}', mas o ISO-8601 {$iso} é outro dia"
            );
            $this->assertSame($iso, $dia->dayOfWeekIso);
        }
    }

    // ─── Valor fora do catálogo não fecha o passo ───────────────────────────

    /**
     * O passo da agenda é `auto_fonte` — existe para RECUSAR conclusão manual
     * (D-19) e só fechar com dado real. Valor fora do catálogo devolvia essa
     * porta pelos fundos: fechava o passo e ainda exibia "Quinzenal, dia 9 às
     * 25:00" como se fosse agenda combinada.
     *
     * Nenhum destes valores é hipotético: o banco aceita todos. `dia_semana` é
     * tinyint, `periodicidade` é varchar (nunca enum, learnings §6), e o TIME
     * do MariaDB é tipo de DURAÇÃO — engole `25:00:00` sem reclamar. E a
     * validação de entrada mora no controller, que ainda não existe.
     *
     * @test
     *
     * @dataProvider valoresForaDoCatalogo
     */
    public function valor_fora_do_catalogo_nao_fecha_o_passo(string $campo, mixed $valor): void
    {
        [$onboarding, $passo] = $this->criarOnboardingComPassoDeAgenda();
        $agenda = $this->criarAgenda($onboarding, [$campo => $valor]);

        $resultado = app(AgendaQuinzenalResolver::class)->resolver($onboarding, $passo);

        $this->assertTrue(
            $resultado->ehNaoColetado(),
            "{$campo} = ".var_export($valor, true).' fechou o passo da agenda'
        );
        $this->assertFalse($agenda->completa());
        $this->assertNull($agenda->agenda_legivel);
    }

    /** @return array<string, array{0: string, 1: mixed}> */
    public static function valoresForaDoCatalogo(): array
    {
        return [
            'dia da semana zero'        => ['dia_semana', 0],
            'dia da semana oito'        => ['dia_semana', 8],
            'periodicidade não ofertada' => ['periodicidade', 'trimestral'],
            'hora que não existe'       => ['horario', '25:00'],
            'minuto que não existe'     => ['horario', '14:99'],
            'horário por extenso'       => ['horario', 'de manhã'],
        ];
    }

    // ─── A frase pronta chega ao front ──────────────────────────────────────

    /**
     * O acordo é a tela consumir `agenda_legivel` pronto e nunca remontar a
     * string em JavaScript. Acessor que não está em `$appends` não sai no
     * `toArray()`, então esse acordo seria impossível de cumprir — e a tela
     * remontaria a frase, criando a terceira cópia divergente.
     *
     * @test
     */
    public function o_payload_leva_a_frase_pronta_e_o_horario_curto(): void
    {
        [$onboarding] = $this->criarOnboardingComPassoDeAgenda();
        $agenda = $this->criarAgenda($onboarding, ['dia_semana' => 5, 'horario' => '08:00']);

        $payload = $agenda->toArray();

        $this->assertSame('Quinzenal, sexta-feira às 08:00', $payload['agenda_legivel']);
        $this->assertSame('08:00', $payload['horario_curto']);
    }

    // ─── A reunião de kickoff continua intocada ─────────────────────────────

    /**
     * As colunas `reuniao_*` de `onboardings` são a reunião ÚNICA de kickoff
     * (o cliente pede, o responsável marca data e hora). Definir a agenda
     * recorrente não pode encostar nelas — nem para "aproveitar" a data.
     *
     * @test
     */
    public function definir_a_agenda_nao_toca_nas_colunas_de_reuniao_do_onboarding(): void
    {
        [$onboarding, $passo] = $this->criarOnboardingComPassoDeAgenda();
        $responsavel = User::factory()->create();

        $onboarding->forceFill([
            'reuniao_status'        => Onboarding::REUNIAO_AGENDADA,
            'reuniao_solicitada_em' => now()->subDays(3),
            'reuniao_agendada_para' => now()->addDays(2)->setTime(10, 0),
            'reuniao_agendada_por'  => $responsavel->id,
        ])->save();

        $antes = $onboarding->fresh()->only([
            'reuniao_status',
            'reuniao_solicitada_em',
            'reuniao_agendada_para',
            'reuniao_agendada_por',
        ]);

        $this->criarAgenda($onboarding, ['dia_semana' => 3, 'horario' => '16:45']);
        $resultado = app(AgendaQuinzenalResolver::class)->resolver($onboarding, $passo);

        $this->assertTrue($resultado->ehConcluido());
        $this->assertSame('Quinzenal, quarta-feira às 16:45', $resultado->valor['agenda_legivel']);
        $this->assertEquals($antes, $onboarding->fresh()->only(array_keys($antes)));
    }

    /**
     * O caminho inverso do mesmo erro: kickoff marcado NÃO é agenda
     * recorrente combinada. Se o resolver aceitasse `reuniao_agendada_para`,
     * este passo fecharia sozinho em todo onboarding que já teve a primeira
     * reunião marcada.
     *
     * @test
     */
    public function kickoff_marcado_nao_fecha_o_passo_da_agenda_recorrente(): void
    {
        [$onboarding, $passo] = $this->criarOnboardingComPassoDeAgenda();

        $onboarding->forceFill([
            'reuniao_status'        => Onboarding::REUNIAO_AGENDADA,
            'reuniao_agendada_para' => now()->addDays(2)->setTime(10, 0),
        ])->save();

        $resultado = app(AgendaQuinzenalResolver::class)->resolver($onboarding->fresh(), $passo);

        $this->assertTrue($resultado->ehNaoColetado());
    }
}
