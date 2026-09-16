<?php

namespace Tests\Feature\Agenda;

use App\Models\Company;
use App\Models\ContratoServico;
use App\Models\GoogleToken;
use App\Models\Onboarding;
use App\Models\OnboardingContato;
use App\Models\OnboardingEventoGoogle;
use App\Models\Servico;
use App\Models\User;
use App\Services\Onboarding\OnboardingEngineService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * O cenário comum dos testes da Agenda (16/09/2026): um onboarding em
 * andamento com analista, estrategista e um contato do cliente com e-mail.
 *
 * `Http::fake()` fica em cada teste — ele ACUMULA stubs e o primeiro que casa
 * vence (ver AgendaGoogleTest).
 */
trait CenarioAgenda
{
    private function servicoDeGestao(): Servico
    {
        return Servico::query()
            ->where('ativo', true)
            ->where('setor', Servico::SETOR_PERFORMANCE)
            ->where('nome', 'like', '%Gestão%')
            ->firstOrFail();
    }

    /**
     * @return array{0: Onboarding, 1: User, 2: User, 3: Company}
     */
    private function cenario(array $opcoes = []): array
    {
        $company = Company::create([
            'name'         => $opcoes['empresa'] ?? 'Empresa Agenda '.uniqid(),
            'cnpj'         => substr(str_pad((string) random_int(1, 99999999999999), 14, '0', STR_PAD_LEFT), 0, 14),
            'active'       => true,
            'status'       => 'ativo',
            'empresa_nova' => false,
        ]);

        $contrato = ContratoServico::create([
            'company_id'       => $company->id,
            'servico_id'       => $this->servicoDeGestao()->id,
            'valor_contratado' => 1500,
            'data_contratacao' => now()->toDateString(),
            'ativo'            => true,
        ]);

        $onboarding = Onboarding::where('contrato_servico_id', $contrato->id)->firstOrFail();

        $analista = User::factory()->create(['name' => 'Analista da Conta', 'email' => 'analista.'.uniqid().'@ecf.test']);
        $estrategista = User::factory()->create(['name' => 'Estrategista da Conta', 'email' => 'estrategista.'.uniqid().'@ecf.test']);
        app(OnboardingEngineService::class)->definirResponsaveis($onboarding, $estrategista, $analista);

        // Quem conduz costuma ter a empresa na carteira — e é a carteira que
        // libera mexer no onboarding (EscopoOnboarding), não o papel.
        if ($opcoes['carteira'] ?? true) {
            $this->porNaCarteira($analista, $company);
            $this->porNaCarteira($estrategista, $company);
        }

        if ($opcoes['google_analista'] ?? true) {
            $this->conectarGoogle($analista, 'token-analista');
        }

        if ($opcoes['google_estrategista'] ?? false) {
            $this->conectarGoogle($estrategista, 'token-estrategista');
        }

        if ($opcoes['contato'] ?? true) {
            OnboardingContato::create([
                'onboarding_id' => $onboarding->id,
                'papel'         => OnboardingContato::PAPEL_PONTO_CONTATO,
                'nome'          => 'Fulano Cliente',
                'email'         => 'fulano@cliente.test',
            ]);
        }

        return [$onboarding->fresh(), $analista, $estrategista, $company];
    }

    private function conectarGoogle(User $user, string $token): GoogleToken
    {
        return GoogleToken::create([
            'user_id'       => $user->id,
            'access_token'  => $token,
            'refresh_token' => 'refresh-'.$token,
            // No futuro: sem isto o serviço renovaria o token e o teste mediria
            // a renovação em vez do que promete medir.
            'expires_at'    => now()->addHour(),
        ]);
    }

    /** O que o "Agendar" manda, com o que o teste quiser trocar. */
    private function dadosDoEvento(array $troca = []): array
    {
        return array_merge([
            'tipo'           => OnboardingEventoGoogle::TIPO_MAPEAMENTO,
            'titulo'         => 'ECF · Cliente — Mapeamento da conta',
            'inicio'         => CarbonImmutable::parse('2026-09-22 10:00', 'America/Sao_Paulo'),
            'duracao'        => 45,
            'plataforma'     => OnboardingEventoGoogle::PLATAFORMA_MEET,
            'link'           => null,
            'descricao'      => 'Levar o relatório da conta.',
            'participantes'  => [['email' => 'fulano@cliente.test', 'nome' => 'Fulano Cliente']],
            'organizador_id' => null,
            'somente_data'   => false,
        ], $troca);
    }

    /** Um vínculo já gravado, como se o "Agendar" o tivesse criado. */
    private function vinculo(Onboarding $onboarding, User $dono, array $troca = []): OnboardingEventoGoogle
    {
        return OnboardingEventoGoogle::create(array_merge([
            'onboarding_id'          => $onboarding->id,
            'tipo'                   => OnboardingEventoGoogle::TIPO_MAPEAMENTO,
            'chave'                  => null,
            'google_event_id'        => 'evt_'.uniqid(),
            'calendar_owner_user_id' => $dono->id,
            'calendar_owner_email'   => $dono->email,
            'enviado_em'             => now(),
            'enviado_por'            => $dono->id,
            'convidados'             => 1,
            'titulo'                 => 'ECF · Cliente — Mapeamento da conta',
            'inicio'                 => CarbonImmutable::parse('2026-09-22 10:00', 'America/Sao_Paulo'),
            'fim'                    => CarbonImmutable::parse('2026-09-22 11:00', 'America/Sao_Paulo'),
            'plataforma'             => OnboardingEventoGoogle::PLATAFORMA_MEET,
            'link_reuniao'           => 'https://meet.google.com/aaa-bbbb-ccc',
            'participantes'          => [['email' => 'fulano@cliente.test', 'nome' => 'Fulano Cliente', 'lado' => 'cliente']],
            'status'                 => OnboardingEventoGoogle::STATUS_ATIVO,
            'sincronizado_em'        => now(),
        ], $troca));
    }

    /** Um item como a API do Google devolve. */
    private function itemGoogle(array $troca = []): array
    {
        return array_replace_recursive([
            'id'        => 'evt_'.uniqid(),
            'status'    => 'confirmed',
            'summary'   => 'Consulta médica',
            'htmlLink'  => 'https://calendar.google.com/event?eid=x',
            'start'     => ['dateTime' => '2026-09-22T14:00:00-03:00'],
            'end'       => ['dateTime' => '2026-09-22T15:00:00-03:00'],
            'organizer' => ['email' => 'dono@ecf.test', 'self' => true],
        ], $troca);
    }

    /** Tem `core.onboarding` (passa no middleware), e só a carteira que o teste der. */
    private function userComPermissaoDeOnboarding(?Company $naCarteira = null): User
    {
        $user = $this->darPermissaoDeOnboarding(User::factory()->create(['role' => 'consultor']));

        if ($naCarteira) {
            $this->porNaCarteira($user, $naCarteira);
        }

        return $user->fresh();
    }

    /** As rotas `/onboarding/*` exigem `core.onboarding`, que vem do setor. */
    private function darPermissaoDeOnboarding(User $user): User
    {
        $slug = 'coordenacao-agenda-'.uniqid();

        $setorId = DB::table('setores')->insertGetId([
            'nome'       => 'Coordenação '.$slug,
            'slug'       => $slug,
            'active'     => true,
            'is_system'  => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('setor_permissoes')->insert([
            'setor_id'       => $setorId,
            'permission_key' => 'core.onboarding',
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);

        DB::table('user_setores')->insert([
            'user_id'      => $user->id,
            'setor_id'     => $setorId,
            'cargo_id'     => null,
            'is_principal' => true,
            'assigned_at'  => now(),
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);

        return $user->fresh();
    }

    private function porNaCarteira(User $user, Company $company): void
    {
        $jaEsta = DB::table('company_users')
            ->where('company_id', $company->id)
            ->where('user_id', $user->id)
            ->exists();

        if ($jaEsta) {
            return;
        }

        DB::table('company_users')->insert([
            'company_id'  => $company->id,
            'user_id'     => $user->id,
            'role'        => 'consultor',
            'servico_id'  => $this->servicoDeGestao()->id,
            'assigned_at' => now(),
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);
    }
}
