<?php

namespace Tests\Feature\OnboardingEmCompanies;

use App\Models\Company;
use App\Models\ContratoServico;
use App\Models\Onboarding;
use App\Models\OnboardingLink;
use App\Models\Servico;
use App\Models\User;
use App\Services\Onboarding\OnboardingEngineService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "Responsáveis pelo onboarding" no portal do cliente (pedido do negócio,
 * 20/08).
 *
 * ### O que este teste protege
 * Uma EMENDA consciente ao T-135-11-02 ("nenhum dado de operação interna sai
 * daqui"). A emenda libera nome, foto e papel — e mais nada. O teste existe
 * para que a linha continue exatamente onde foi posta: se alguém acrescentar
 * e-mail interno, carga de trabalho, SLA ou dias parado ao payload do portal,
 * ele quebra. Sem isso, "só o nome" vira "o nome e mais uma coisinha" a cada
 * pedido, e um dia o cliente vê a fila interna.
 */
class PortalResponsaveisTest extends TestCase
{
    use RefreshDatabase;

    /**
     * `avatar_url` NÃO está no `$fillable` do User — por isso o
     * `UserController` também usa `forceFill()` ao salvar a foto. Passá-lo no
     * `create()` é descartado em silêncio, e o teste passaria a afirmar que a
     * foto é null sem que isso significasse nada.
     */
    private function usuario(string $nome, ?string $avatar = null): User
    {
        $user = User::create([
            'name'     => $nome,
            'email'    => strtolower(str_replace(' ', '.', $nome)).'.'.uniqid().'@ecf.test',
            'password' => bcrypt('senha'),
            'role'     => 'consultor',
            'active'   => true,
        ]);

        if ($avatar !== null) {
            $user->forceFill(['avatar_url' => $avatar])->save();
        }

        return $user->fresh();
    }

    /** @return array{0:Company,1:Onboarding} */
    private function empresaComOnboarding(): array
    {
        $servico = Servico::query()
            ->where('ativo', true)
            ->where('setor', Servico::SETOR_PERFORMANCE)
            ->where('nome', 'like', '%Gestão%')
            ->firstOrFail();

        $company = Company::create([
            'name'              => 'Empresa Portal '.uniqid(),
            'cnpj'              => substr(str_pad((string) random_int(1, 99999999999999), 14, '0', STR_PAD_LEFT), 0, 14),
            'active'            => true,
            'status'            => 'ativo',
            'email_colaborador' => 'colab.'.uniqid().'@ecf.test',
            'adman_account_id'  => (string) random_int(100000, 999999),
            'empresa_nova'      => false,
        ]);

        $contrato = ContratoServico::create([
            'company_id'       => $company->id,
            'servico_id'       => $servico->id,
            'valor_contratado' => 1500,
            'data_contratacao' => now()->toDateString(),
            'ativo'            => true,
        ]);

        return [$company, Onboarding::where('contrato_servico_id', $contrato->id)->firstOrFail()];
    }

    /**
     * Define os dois papéis e tira do rascunho de uma vez.
     *
     * `confirmarResponsavel()` NÃO serve aqui: ele preenche UM slot e passa
     * `null` para o outro, o que apaga o papel que acabou de ser gravado à
     * mão. Quem preenche os dois é `definirResponsaveis()` — está dito no
     * próprio docblock dele.
     */
    private function definir(Onboarding $onboarding, ?User $estrategista, ?User $analista): void
    {
        app(OnboardingEngineService::class)->definirResponsaveis($onboarding, $estrategista, $analista);
    }

    private function token(Company $company): string
    {
        return OnboardingLink::firstOrCreate(
            ['company_id' => $company->id],
            ['token' => \Illuminate\Support\Str::random(48)]
        )->token;
    }

    /** @test */
    public function portal_mostra_analista_e_estrategista_com_nome_papel_e_foto(): void
    {
        [$company, $onboarding] = $this->empresaComOnboarding();

        $analista = $this->usuario('Gustavo Analista', '/storage/avatars/gustavo.webp');
        $estrategista = $this->usuario('Maycon Estrategista');

        $this->definir($onboarding, $estrategista, $analista);

        $props = $this->get(route('portal.onboarding', $this->token($company)))
            ->assertOk()
            ->viewData('page')['props'];

        $responsaveis = collect($props['responsaveis']);

        $this->assertCount(2, $responsaveis);

        // Analista PRIMEIRO: é o contato do dia a dia do cliente.
        $this->assertSame('Analista responsável', $responsaveis[0]['papel']);
        $this->assertSame('Gustavo Analista', $responsaveis[0]['nome']);
        $this->assertSame('/storage/avatars/gustavo.webp', $responsaveis[0]['foto']);

        // Sem foto é caso normal, não erro — a tela cai nas iniciais.
        $this->assertSame('Estrategista', $responsaveis[1]['papel']);
        $this->assertNull($responsaveis[1]['foto']);
    }

    /**
     * A emenda ao T-135-11-02 libera nome, foto e papel. Mais nada.
     *
     * @test
     */
    public function responsavel_no_portal_nao_carrega_nada_alem_de_nome_foto_e_papel(): void
    {
        [$company, $onboarding] = $this->empresaComOnboarding();

        $analista = $this->usuario('Fulano Analista');
        $this->definir($onboarding, null, $analista);

        $props = $this->get(route('portal.onboarding', $this->token($company)))
            ->assertOk()
            ->viewData('page')['props'];

        foreach ($props['responsaveis'] as $r) {
            $this->assertSame(
                ['papel', 'nome', 'foto'],
                array_keys($r),
                'O portal passou a expor campo interno além de nome/foto/papel.'
            );
        }

        // O e-mail interno é o vazamento mais fácil de acontecer sem querer:
        // ele está no mesmo objeto User de onde saem nome e foto.
        $this->assertStringNotContainsString(
            $analista->email,
            json_encode($props, JSON_UNESCAPED_UNICODE),
            'O e-mail interno do responsável vazou para o portal do cliente.'
        );
    }

    /**
     * Onboarding em RASCUNHO não expõe portal (SC-04) — logo, o responsável
     * dele também não é assunto do cliente.
     *
     * @test
     */
    public function rascunho_nao_traz_responsavel_para_o_portal(): void
    {
        [$company, $onboarding] = $this->empresaComOnboarding();

        $analista = $this->usuario('Ninguem Ainda');
        $onboarding->forceFill(['responsavel_analista_id' => $analista->id])->save();

        $this->assertSame(Onboarding::STATUS_RASCUNHO, $onboarding->fresh()->status);

        $props = $this->get(route('portal.onboarding', $this->token($company)))
            ->assertOk()
            ->viewData('page')['props'];

        $this->assertSame([], $props['responsaveis']);
    }

    /**
     * Uma empresa com N onboardings tem o mesmo analista em todos. O cliente
     * não pode ver o mesmo rosto repetido porque contratou dois serviços.
     *
     * @test
     */
    public function responsavel_repetido_em_varios_onboardings_aparece_uma_vez(): void
    {
        [$company, $primeiro] = $this->empresaComOnboarding();

        $servicoExtra = Servico::create([
            'nome'          => 'Gestão Extra '.uniqid(),
            'valor_padrao'  => 900,
            'tipo_cobranca' => Servico::TIPO_MENSAL,
            'ativo'         => true,
            'setor'         => Servico::SETOR_PERFORMANCE,
        ]);

        ContratoServico::create([
            'company_id'       => $company->id,
            'servico_id'       => $servicoExtra->id,
            'valor_contratado' => 900,
            'data_contratacao' => now()->toDateString(),
            'ativo'            => true,
        ]);

        $analista = $this->usuario('Unico Analista');

        foreach (Onboarding::where('company_id', $company->id)->get() as $o) {
            $this->definir($o, null, $analista);
        }

        $props = $this->get(route('portal.onboarding', $this->token($company)))
            ->assertOk()
            ->viewData('page')['props'];

        $analistas = collect($props['responsaveis'])->where('papel', 'Analista responsável');

        $this->assertCount(1, $analistas, 'O mesmo analista apareceu mais de uma vez.');
    }
}
