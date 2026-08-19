<?php

namespace Tests\Feature\OnboardingEmCompanies;

use App\Models\Company;
use App\Models\Onboarding;
use App\Models\OnboardingContato;
use App\Models\OnboardingPasso;
use App\Models\Servico;
use App\Services\Onboarding\Resolvers\ParticipantesResolver;
use App\Services\Onboarding\Resolvers\PontoContatoResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * As pessoas do lado do cliente: ponto de contato (§13.2) e participantes das
 * reuniões (§16), na mesma tabela, separados por `papel`.
 *
 * O que estes testes protegem, em ordem de custo:
 *  - a lista de participantes é feita de LINHAS com id próprio — editar uma
 *    não pode tocar nas outras. É o incidente da Precificação
 *    (`.planning/learnings/precificacao-onboarding-duas-telas.md` §3), onde a
 *    lista era um mapa chaveado por campo digitado e 11 produtos viraram um;
 *  - participante sem e-mail NÃO fecha o passo, e o motivo diz o nome de quem
 *    falta — o §16 existe para mandar convite, e convite sem e-mail não sai;
 *  - os dois papéis não se misturam: cadastrar participante não pode dar por
 *    resolvido o ponto de contato, e vice-versa.
 */
class ContatosResolverTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Onboarding + passo mínimos para exercitar um resolver isoladamente —
     * mesmo molde de `OnboardingResolversLocaisTest`, deliberadamente sem
     * passar por `DefinicaoOnboarding`: o que está sob teste é o resolver, não
     * a régua.
     *
     * @return array{0: Onboarding, 1: OnboardingPasso}
     */
    private function criarOnboardingComPasso(string $autoFonte, string $chave): array
    {
        $company = Company::factory()->create();

        $servico = Servico::create([
            'nome'          => 'Gestao Contatos '.uniqid(),
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
            'chave'         => $chave,
            'titulo'        => $chave,
            'dono'          => OnboardingPasso::DONO_SISTEMA,
            'auto_fonte'    => $autoFonte,
        ]);

        return [$onboarding, $passo];
    }

    private function contato(Onboarding $onboarding, string $papel, string $nome, ?string $email): OnboardingContato
    {
        return OnboardingContato::create([
            'onboarding_id' => $onboarding->id,
            'papel'         => $papel,
            'nome'          => $nome,
            'email'         => $email,
        ]);
    }

    // ─── §16: participantes das reuniões ────────────────────────────────────

    #[Test]
    public function varios_participantes_todos_com_email_fecham_o_passo(): void
    {
        [$onboarding, $passo] = $this->criarOnboardingComPasso(
            OnboardingPasso::AUTO_FONTE_PARTICIPANTES,
            'participantes_reuniao'
        );

        $this->contato($onboarding, OnboardingContato::PAPEL_PARTICIPANTE, 'Ana Ribeiro', 'ana@gmail.com');
        $this->contato($onboarding, OnboardingContato::PAPEL_PARTICIPANTE, 'Bruno Sales', 'bruno@gmail.com');
        $this->contato($onboarding, OnboardingContato::PAPEL_PARTICIPANTE, 'Carla Nunes', 'carla@gmail.com');

        $resultado = app(ParticipantesResolver::class)->resolver($onboarding, $passo);

        $this->assertTrue($resultado->ehConcluido());
        $this->assertFalse($resultado->ehIndeterminado());
        $this->assertSame(3, $resultado->valor['total'], 'Os três participantes têm de contar como três.');
        $this->assertCount(3, $resultado->valor['contato_ids']);
    }

    #[Test]
    public function sem_nenhum_participante_o_passo_fica_aberto(): void
    {
        [$onboarding, $passo] = $this->criarOnboardingComPasso(
            OnboardingPasso::AUTO_FONTE_PARTICIPANTES,
            'participantes_reuniao'
        );

        $resultado = app(ParticipantesResolver::class)->resolver($onboarding, $passo);

        $this->assertTrue($resultado->ehNaoColetado());
        $this->assertFalse($resultado->ehIndeterminado());
    }

    /**
     * A régua do §16: basta UM sem e-mail para o passo continuar aberto, e o
     * motivo tem de dizer o nome — a tela cobra nominalmente, senão sobra para
     * quem lê conferir a lista linha a linha.
     */
    #[Test]
    public function um_participante_sem_email_mantem_o_passo_aberto_e_o_motivo_cita_o_nome(): void
    {
        [$onboarding, $passo] = $this->criarOnboardingComPasso(
            OnboardingPasso::AUTO_FONTE_PARTICIPANTES,
            'participantes_reuniao'
        );

        $this->contato($onboarding, OnboardingContato::PAPEL_PARTICIPANTE, 'Ana Ribeiro', 'ana@gmail.com');
        $this->contato($onboarding, OnboardingContato::PAPEL_PARTICIPANTE, 'Bruno Sales', null);
        $this->contato($onboarding, OnboardingContato::PAPEL_PARTICIPANTE, 'Carla Nunes', 'carla@gmail.com');

        $resultado = app(ParticipantesResolver::class)->resolver($onboarding, $passo);

        $this->assertTrue($resultado->ehNaoColetado());
        $this->assertStringContainsString('Bruno Sales', $resultado->motivo, 'O motivo precisa cobrar quem falta pelo nome.');
        $this->assertStringContainsString('1 de 3', $resultado->motivo, 'O motivo precisa dizer quantos faltam.');
        $this->assertStringNotContainsString('Ana Ribeiro', $resultado->motivo, 'Quem já tem e-mail não é cobrado.');
    }

    #[Test]
    public function e_mail_em_branco_conta_como_ausente(): void
    {
        [$onboarding, $passo] = $this->criarOnboardingComPasso(
            OnboardingPasso::AUTO_FONTE_PARTICIPANTES,
            'participantes_reuniao'
        );

        // String vazia é o que um formulário manda quando o campo fica em
        // branco — se `filled()` não estivesse no meio, o passo fecharia com um
        // participante que nunca receberia convite.
        $this->contato($onboarding, OnboardingContato::PAPEL_PARTICIPANTE, 'Bruno Sales', '');

        $resultado = app(ParticipantesResolver::class)->resolver($onboarding, $passo);

        $this->assertTrue($resultado->ehNaoColetado());
        $this->assertStringContainsString('Bruno Sales', $resultado->motivo);
    }

    // ─── O incidente da Precificação: uma linha por pessoa ──────────────────

    /**
     * Editar um participante não pode tocar nos outros.
     *
     * Na Precificação, a lista era um mapa chaveado por SKU e o cliente
     * digitava o mesmo texto no SKU de todos: a cada save, as N linhas viravam
     * cópia de uma só e o custo sumia. Aqui o "campo repetido" natural é o
     * e-mail vazio — dois participantes ainda sem e-mail seriam a mesma chave
     * num mapa. Por isso a lista é feita de LINHAS com id próprio.
     */
    #[Test]
    public function editar_um_participante_nao_afeta_os_outros(): void
    {
        [$onboarding, $passo] = $this->criarOnboardingComPasso(
            OnboardingPasso::AUTO_FONTE_PARTICIPANTES,
            'participantes_reuniao'
        );

        // Os três nascem sem e-mail — o cenário exato em que um mapa chaveado
        // por campo digitado colapsaria tudo numa linha só.
        $ana = $this->contato($onboarding, OnboardingContato::PAPEL_PARTICIPANTE, 'Ana Ribeiro', null);
        $bruno = $this->contato($onboarding, OnboardingContato::PAPEL_PARTICIPANTE, 'Bruno Sales', null);
        $carla = $this->contato($onboarding, OnboardingContato::PAPEL_PARTICIPANTE, 'Carla Nunes', null);

        $this->assertCount(
            3,
            OnboardingContato::where('onboarding_id', $onboarding->id)->participantes()->get(),
            'Três pessoas sem e-mail são três linhas, nunca uma.'
        );

        // Edição POR ID, como a regra de escrita do model manda.
        $bruno->update(['email' => 'bruno@gmail.com', 'funcao' => 'Gerente de e-commerce']);

        $this->assertNull($ana->fresh()->email, 'Editar o Bruno não pode mexer na Ana.');
        $this->assertNull($carla->fresh()->email, 'Editar o Bruno não pode mexer na Carla.');
        $this->assertSame('bruno@gmail.com', $bruno->fresh()->email);
        $this->assertNull($ana->fresh()->funcao);

        $resultado = app(ParticipantesResolver::class)->resolver($onboarding, $passo);

        $this->assertTrue($resultado->ehNaoColetado(), 'Ana e Carla ainda estão sem e-mail.');
        $this->assertStringContainsString('2 de 3', $resultado->motivo);
        $this->assertStringContainsString('Ana Ribeiro', $resultado->motivo);
        $this->assertStringContainsString('Carla Nunes', $resultado->motivo);
        $this->assertStringNotContainsString('Bruno Sales', $resultado->motivo);
    }

    #[Test]
    public function remover_um_participante_nao_derruba_os_demais(): void
    {
        [$onboarding, $passo] = $this->criarOnboardingComPasso(
            OnboardingPasso::AUTO_FONTE_PARTICIPANTES,
            'participantes_reuniao'
        );

        $ana = $this->contato($onboarding, OnboardingContato::PAPEL_PARTICIPANTE, 'Ana Ribeiro', 'ana@gmail.com');
        $bruno = $this->contato($onboarding, OnboardingContato::PAPEL_PARTICIPANTE, 'Bruno Sales', 'bruno@gmail.com');

        $bruno->delete();

        $resultado = app(ParticipantesResolver::class)->resolver($onboarding, $passo);

        $this->assertTrue($resultado->ehConcluido());
        $this->assertSame([$ana->id], $resultado->valor['contato_ids']);
    }

    // ─── §13.2: ponto de contato ────────────────────────────────────────────

    #[Test]
    public function ponto_de_contato_com_nome_e_email_fecha_o_passo(): void
    {
        [$onboarding, $passo] = $this->criarOnboardingComPasso(
            OnboardingPasso::AUTO_FONTE_PONTO_CONTATO,
            'ponto_de_contato'
        );

        $contato = OnboardingContato::create([
            'onboarding_id' => $onboarding->id,
            'papel'         => OnboardingContato::PAPEL_PONTO_CONTATO,
            'nome'          => 'Débora Prado',
            'email'         => 'debora@empresa.com.br',
            'funcao'        => 'Sócia',
            'telefone'      => '(11) 99999-0000',
            'principal'     => true,
        ]);

        $resultado = app(PontoContatoResolver::class)->resolver($onboarding, $passo);

        $this->assertTrue($resultado->ehConcluido());
        $this->assertSame([$contato->id], $resultado->valor['contato_ids']);
        $this->assertTrue($contato->fresh()->principal, 'O cast de `principal` tem de devolver boolean.');
    }

    #[Test]
    public function ponto_de_contato_sem_email_mantem_o_passo_aberto_e_cita_o_nome(): void
    {
        [$onboarding, $passo] = $this->criarOnboardingComPasso(
            OnboardingPasso::AUTO_FONTE_PONTO_CONTATO,
            'ponto_de_contato'
        );

        $this->contato($onboarding, OnboardingContato::PAPEL_PONTO_CONTATO, 'Débora Prado', null);

        $resultado = app(PontoContatoResolver::class)->resolver($onboarding, $passo);

        $this->assertTrue($resultado->ehNaoColetado());
        $this->assertStringContainsString('Débora Prado', $resultado->motivo);
    }

    #[Test]
    public function sem_ponto_de_contato_nenhum_o_passo_fica_aberto(): void
    {
        [$onboarding, $passo] = $this->criarOnboardingComPasso(
            OnboardingPasso::AUTO_FONTE_PONTO_CONTATO,
            'ponto_de_contato'
        );

        $resultado = app(PontoContatoResolver::class)->resolver($onboarding, $passo);

        $this->assertTrue($resultado->ehNaoColetado());
    }

    // ─── Os dois papéis não se misturam ─────────────────────────────────────

    /**
     * Uma tabela só, dois usos — mas o `papel` tem de separar de verdade. Se
     * vazasse, cadastrar a lista de convidados daria por resolvido "quem
     * responde pela empresa", que é outra pergunta.
     */
    #[Test]
    public function participante_da_reuniao_nao_fecha_o_passo_de_ponto_de_contato(): void
    {
        [$onboarding, $passo] = $this->criarOnboardingComPasso(
            OnboardingPasso::AUTO_FONTE_PONTO_CONTATO,
            'ponto_de_contato'
        );

        $this->contato($onboarding, OnboardingContato::PAPEL_PARTICIPANTE, 'Ana Ribeiro', 'ana@gmail.com');

        $resultado = app(PontoContatoResolver::class)->resolver($onboarding, $passo);

        $this->assertTrue($resultado->ehNaoColetado(), 'Participante de reunião não é ponto de contato.');
    }

    #[Test]
    public function ponto_de_contato_nao_fecha_o_passo_de_participantes(): void
    {
        [$onboarding, $passo] = $this->criarOnboardingComPasso(
            OnboardingPasso::AUTO_FONTE_PARTICIPANTES,
            'participantes_reuniao'
        );

        $this->contato($onboarding, OnboardingContato::PAPEL_PONTO_CONTATO, 'Débora Prado', 'debora@empresa.com.br');

        $resultado = app(ParticipantesResolver::class)->resolver($onboarding, $passo);

        $this->assertTrue($resultado->ehNaoColetado(), 'Ponto de contato não entra na lista de convidados sozinho.');
    }

    /** Contato de OUTRO onboarding não pode contar aqui. */
    #[Test]
    public function contatos_de_outro_onboarding_nao_vazam(): void
    {
        [$onboarding, $passo] = $this->criarOnboardingComPasso(
            OnboardingPasso::AUTO_FONTE_PARTICIPANTES,
            'participantes_reuniao'
        );

        [$outro] = $this->criarOnboardingComPasso(
            OnboardingPasso::AUTO_FONTE_PARTICIPANTES,
            'participantes_reuniao'
        );

        $this->contato($outro, OnboardingContato::PAPEL_PARTICIPANTE, 'Ana Ribeiro', 'ana@gmail.com');

        $resultado = app(ParticipantesResolver::class)->resolver($onboarding, $passo);

        $this->assertTrue($resultado->ehNaoColetado());
    }

    // ─── Contrato do resolver ───────────────────────────────────────────────

    #[Test]
    public function as_chaves_batem_com_as_constantes_de_auto_fonte(): void
    {
        $this->assertSame(
            OnboardingPasso::AUTO_FONTE_PONTO_CONTATO,
            app(PontoContatoResolver::class)->chave()
        );

        $this->assertSame(
            OnboardingPasso::AUTO_FONTE_PARTICIPANTES,
            app(ParticipantesResolver::class)->chave()
        );

        $this->assertContains(OnboardingPasso::AUTO_FONTE_PONTO_CONTATO, OnboardingPasso::AUTO_FONTES);
        $this->assertContains(OnboardingPasso::AUTO_FONTE_PARTICIPANTES, OnboardingPasso::AUTO_FONTES);
    }

    /** Leem tabela local: nunca há rede, logo nunca há indeterminado. */
    #[Test]
    public function os_dois_resolvers_sao_sincronos(): void
    {
        $this->assertFalse(app(PontoContatoResolver::class)->assincrono());
        $this->assertFalse(app(ParticipantesResolver::class)->assincrono());

        $this->assertNotSame('', app(PontoContatoResolver::class)->label());
        $this->assertNotSame('', app(ParticipantesResolver::class)->ajuda());
    }

    /** Os dois papéis do catálogo cabem na coluna `papel` (varchar 30). */
    #[Test]
    public function todo_papel_do_catalogo_cabe_na_coluna(): void
    {
        foreach (OnboardingContato::PAPEIS as $papel) {
            $this->assertLessThanOrEqual(
                30,
                strlen($papel),
                "O papel '{$papel}' passa do varchar(30) e seria truncado em silêncio."
            );
        }
    }

    // ─── O motivo tem de caber em `onboarding_passos.ultimo_erro` ───────────

    /**
     * `aplicarResultado()` grava o `motivo` de todo `nao_coletado` em
     * `onboarding_passos.ultimo_erro`, que é `varchar(255)` — e as conexões
     * MySQL/MariaDB deste projeto rodam com `strict => true`. Motivo maior não
     * trunca em silêncio: estoura com SQLSTATE 22001 dentro do resolver, o job
     * cai no `failed()` e o passo termina em `indeterminado` com "Data too
     * long" — some exatamente a cobrança nominal que o §16 existe para
     * produzir. O SQLite destes testes ignora largura de `varchar` e jamais
     * acusaria isso (learnings §6.5), então a régua é medida aqui na mão.
     */
    #[Test]
    public function motivo_de_muitos_participantes_sem_email_cabe_na_coluna_ultimo_erro(): void
    {
        [$onboarding, $passo] = $this->criarOnboardingComPasso(
            OnboardingPasso::AUTO_FONTE_PARTICIPANTES,
            'participantes_reuniao'
        );

        // 12 pessoas com o nome no limite de `nome` (varchar 120): o pior caso
        // que a tela aceita sem reclamar.
        for ($i = 1; $i <= 12; $i++) {
            $this->contato(
                $onboarding,
                OnboardingContato::PAPEL_PARTICIPANTE,
                str_pad("Participante {$i} ", 120, 'x'),
                null
            );
        }

        $resultado = app(ParticipantesResolver::class)->resolver($onboarding, $passo);

        $this->assertTrue($resultado->ehNaoColetado());
        $this->assertLessThanOrEqual(
            OnboardingContato::LIMITE_MOTIVO,
            mb_strlen($resultado->motivo),
            'Motivo maior que onboarding_passos.ultimo_erro derruba aplicarResultado() no MariaDB.'
        );

        // A contagem é o que não pode encolher: só a lista de nomes vira "e
        // mais N". "12 de 12" continua dizendo o tamanho real do problema.
        $this->assertStringContainsString('12 de 12', $resultado->motivo);
        $this->assertStringContainsString('e mais', $resultado->motivo);
    }

    #[Test]
    public function motivo_de_muitos_pontos_de_contato_cabe_na_coluna_ultimo_erro(): void
    {
        [$onboarding, $passo] = $this->criarOnboardingComPasso(
            OnboardingPasso::AUTO_FONTE_PONTO_CONTATO,
            'ponto_de_contato'
        );

        for ($i = 1; $i <= 12; $i++) {
            $this->contato(
                $onboarding,
                OnboardingContato::PAPEL_PONTO_CONTATO,
                str_pad("Contato {$i} ", 120, 'x'),
                null
            );
        }

        $resultado = app(PontoContatoResolver::class)->resolver($onboarding, $passo);

        $this->assertTrue($resultado->ehNaoColetado());
        $this->assertLessThanOrEqual(
            OnboardingContato::LIMITE_MOTIVO,
            mb_strlen($resultado->motivo),
            'Motivo maior que onboarding_passos.ultimo_erro derruba aplicarResultado() no MariaDB.'
        );
    }

    /**
     * O teto do PHP tem de continuar igual ao da coluna. Se um dia
     * `ultimo_erro` mudar de largura, é esta linha que avisa que a constante
     * ficou para trás — o banco não avisa, ele estoura.
     */
    #[Test]
    public function o_limite_do_motivo_espelha_a_largura_de_ultimo_erro(): void
    {
        $this->assertSame(
            255,
            OnboardingContato::LIMITE_MOTIVO,
            'onboarding_passos.ultimo_erro é varchar(255) — ver 2026_08_11_120100_create_onboardings_tables.php.'
        );
    }

    /**
     * Cobrança nominal continua nominal enquanto a lista é pequena: cortar
     * cedo demais devolveria "3 participantes sem e-mail" e jogaria de volta
     * para quem lê o trabalho de abrir a lista e conferir linha a linha.
     */
    #[Test]
    public function lista_curta_continua_citando_todo_mundo_pelo_nome(): void
    {
        [$onboarding, $passo] = $this->criarOnboardingComPasso(
            OnboardingPasso::AUTO_FONTE_PARTICIPANTES,
            'participantes_reuniao'
        );

        foreach (['Ana Ribeiro', 'Bruno Sales', 'Carla Nunes'] as $nome) {
            $this->contato($onboarding, OnboardingContato::PAPEL_PARTICIPANTE, $nome, null);
        }

        $resultado = app(ParticipantesResolver::class)->resolver($onboarding, $passo);

        $this->assertStringContainsString('Ana Ribeiro', $resultado->motivo);
        $this->assertStringContainsString('Bruno Sales', $resultado->motivo);
        $this->assertStringContainsString('Carla Nunes', $resultado->motivo);
        $this->assertStringNotContainsString('e mais', $resultado->motivo);
    }

    /**
     * Ponto de contato com e-mail mas SEM nome não podia ficar invisível: a
     * régua exige nome E e-mail, e o motivo antigo só citava quem estava sem
     * e-mail — quem lesse cadastraria o e-mail que falta e o passo seguiria
     * aberto sem explicar por quê.
     */
    #[Test]
    public function ponto_de_contato_sem_nome_aparece_no_motivo(): void
    {
        [$onboarding, $passo] = $this->criarOnboardingComPasso(
            OnboardingPasso::AUTO_FONTE_PONTO_CONTATO,
            'ponto_de_contato'
        );

        $this->contato($onboarding, OnboardingContato::PAPEL_PONTO_CONTATO, '', 'sem.nome@empresa.com.br');
        $this->contato($onboarding, OnboardingContato::PAPEL_PONTO_CONTATO, 'Débora Prado', null);

        $resultado = app(PontoContatoResolver::class)->resolver($onboarding, $passo);

        $this->assertTrue($resultado->ehNaoColetado());
        $this->assertStringContainsString('Débora Prado', $resultado->motivo, 'Quem está sem e-mail continua sendo cobrado pelo nome.');
        $this->assertStringContainsString('sem nome', $resultado->motivo, 'A linha sem nome não pode ficar invisível.');
    }
}
