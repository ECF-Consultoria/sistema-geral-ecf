<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\MlbEmpresa;
use App\Models\MlToken;
use App\Services\MercadoLivreService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `ml_tokens` com as duas âncoras: `Company` e `MlbEmpresa`.
 *
 * O que estes testes protegem: empresa de Polos autorizava o OAuth e o token
 * era jogado fora, porque `company_id` era NOT NULL e ela não tem `Company`.
 * Resultado: 246 empresas autorizadas, nenhuma aparecendo em `/mlb/anuncios`.
 */
class MlTokenAncoraPolosTest extends TestCase
{
    use RefreshDatabase;

    private function dadosToken(string $userId = '123456'): array
    {
        return [
            'user_id'       => $userId,
            'access_token'  => 'APP_USR-token-de-teste',
            'refresh_token' => 'TG-refresh-de-teste',
            'token_type'    => 'bearer',
            'scope'         => 'read write offline_access',
            'expires_in'    => 21600,
        ];
    }

    private function svc(): MercadoLivreService
    {
        return app(MercadoLivreService::class);
    }

    public function test_empresa_de_polos_guarda_token_sem_precisar_de_company(): void
    {
        $empresa = MlbEmpresa::create(['nome' => 'Unity Móveis']);

        $token = $this->svc()->saveToken($empresa, $this->dadosToken('465723451'));

        $this->assertSame($empresa->id, $token->mlb_empresa_id);
        $this->assertNull($token->company_id, 'Token de Polos não pode ocupar company_id.');
        $this->assertSame('465723451', $token->ml_user_id);
    }

    public function test_company_continua_guardando_token_na_ancora_dela(): void
    {
        $company = Company::factory()->create();

        $token = $this->svc()->saveToken($company, $this->dadosToken('111222333'));

        $this->assertSame($company->id, $token->company_id);
        $this->assertNull($token->mlb_empresa_id, 'Token de Company não pode ocupar a âncora de Polos.');
    }

    public function test_varias_empresas_de_polos_conectadas_ao_mesmo_tempo(): void
    {
        // O UNIQUE de `company_id` continua de pé, mas admite VÁRIOS NULL —
        // é exatamente isso que permite N empresas de Polos conectadas sem
        // precisar dropar o índice (e sem esbarrar no erro 1553 do MariaDB).
        foreach (['Unity Móveis', 'Masitto Home Decor', 'Fuxicando'] as $i => $nome) {
            $this->svc()->saveToken(
                MlbEmpresa::create(['nome' => $nome]),
                $this->dadosToken('90000' . $i)
            );
        }

        $this->assertSame(3, MlToken::whereNull('company_id')->count());
    }

    public function test_company_e_empresa_de_polos_de_mesmo_id_nao_trocam_de_token(): void
    {
        // Numa base limpa os dois ids nascem em 1 — a colisão é o caso normal,
        // não o excepcional. Se `ensureValidToken` procurasse sempre por
        // `company_id`, a empresa de Polos receberia o token da Company.
        $company = Company::factory()->create();
        $empresa = MlbEmpresa::create(['nome' => 'Unity Móveis']);

        $this->svc()->saveToken($company, $this->dadosToken('AAA'));
        $this->svc()->saveToken($empresa, $this->dadosToken('BBB'));

        $this->assertSame('AAA', $this->svc()->ensureValidToken($company->fresh())->ml_user_id);
        $this->assertSame('BBB', $this->svc()->ensureValidToken($empresa->fresh())->ml_user_id);
    }

    public function test_chave_de_lock_separa_as_duas_ancoras(): void
    {
        // O refresh token do ML é de uso único. Se os dois tokens caíssem na
        // mesma chave de lock, o refresh de um derrubaria a conexão do outro.
        $company = Company::factory()->create();
        $empresa = MlbEmpresa::create(['nome' => 'Unity Móveis']);

        $tokenCompany = $this->svc()->saveToken($company, $this->dadosToken('AAA'));
        $tokenPolos   = $this->svc()->saveToken($empresa, $this->dadosToken('BBB'));

        $this->assertNotSame($tokenCompany->chaveLock(), $tokenPolos->chaveLock());
        $this->assertSame("company-{$company->id}", $tokenCompany->chaveLock());
        $this->assertSame("empresa-{$empresa->id}", $tokenPolos->chaveLock());
    }

    public function test_reautorizar_atualiza_o_token_em_vez_de_duplicar(): void
    {
        $empresa = MlbEmpresa::create(['nome' => 'Unity Móveis']);

        $this->svc()->saveToken($empresa, $this->dadosToken('465723451'));
        $this->svc()->saveToken($empresa, $this->dadosToken('465723451'));

        $this->assertSame(1, MlToken::where('mlb_empresa_id', $empresa->id)->count());
    }

    public function test_rascunho_aceita_company_id_nulo(): void
    {
        // Sem isto a empresa de Polos conecta mas não consegue nem começar um
        // anúncio: `ml_anuncio_rascunhos.company_id` era NOT NULL.
        $empresa = MlbEmpresa::create(['nome' => 'Unity Móveis']);

        $rascunho = \App\Models\MlAnuncioRascunho::create([
            'company_id'     => null,
            'mlb_empresa_id' => $empresa->id,
            'user_id'        => \App\Models\User::factory()->create()->id,
            'status'         => \App\Models\MlAnuncioRascunho::STATUS_RASCUNHO,
            'payload'        => ['title' => 'Mesa de Jantar 6 Lugares'],
        ]);

        $this->assertNull($rascunho->fresh()->company_id);
        $this->assertSame($empresa->id, $rascunho->fresh()->mlb_empresa_id);
    }
}
