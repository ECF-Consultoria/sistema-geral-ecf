<?php

namespace Tests\Feature\Bling;

use App\Models\BlingConexao;
use App\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Fase 184 (BLING-03, BLING-04) — prova de que o token do Bling é ilegível
 * na linha crua do banco e que `estaConfigurado()` degrada sem 500 quando o
 * `.env` está vazio.
 */
class BlingEsquemaTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN_EM_CLARO = 'texto-conhecido-do-token-bling-xyz-123';

    private const REFRESH_EM_CLARO = 'texto-conhecido-do-refresh-bling-abc-456';

    public function test_token_em_claro_nao_aparece_na_linha_crua(): void
    {
        $company = Company::factory()->create();

        BlingConexao::create([
            'company_id'     => $company->id,
            'access_token'   => self::TOKEN_EM_CLARO,
            'refresh_token'  => self::REFRESH_EM_CLARO,
            'status'         => BlingConexao::STATUS_ATIVA,
        ]);

        $linhaCrua = DB::table('bling_conexoes')->first();

        $this->assertStringNotContainsString(self::TOKEN_EM_CLARO, (string) $linhaCrua->access_token);
        $this->assertStringNotContainsString(self::REFRESH_EM_CLARO, (string) $linhaCrua->refresh_token);
    }

    public function test_cast_encrypted_devolve_os_tokens_em_claro_pelo_model(): void
    {
        $company = Company::factory()->create();

        $conexao = BlingConexao::create([
            'company_id'    => $company->id,
            'access_token'  => self::TOKEN_EM_CLARO,
            'refresh_token' => self::REFRESH_EM_CLARO,
            'status'        => BlingConexao::STATUS_ATIVA,
        ]);

        $lida = BlingConexao::find($conexao->id);

        $this->assertSame(self::TOKEN_EM_CLARO, $lida->access_token);
        $this->assertSame(self::REFRESH_EM_CLARO, $lida->refresh_token);
    }

    public function test_to_array_nao_expoe_os_tokens(): void
    {
        $company = Company::factory()->create();

        $conexao = BlingConexao::create([
            'company_id'    => $company->id,
            'access_token'  => self::TOKEN_EM_CLARO,
            'refresh_token' => self::REFRESH_EM_CLARO,
            'status'        => BlingConexao::STATUS_ATIVA,
        ]);

        $array = $conexao->toArray();

        $this->assertArrayNotHasKey('access_token', $array);
        $this->assertArrayNotHasKey('refresh_token', $array);
    }

    public function test_esta_configurado_devolve_false_sem_lancar_excecao_quando_env_vazio(): void
    {
        config([
            'services.bling.client_id'     => null,
            'services.bling.client_secret' => null,
            'services.bling.redirect'      => null,
        ]);

        $this->assertFalse(BlingConexao::estaConfigurado());
    }

    public function test_esta_configurado_devolve_true_com_as_tres_chaves_preenchidas(): void
    {
        config([
            'services.bling.client_id'     => 'client-id-de-teste',
            'services.bling.client_secret' => 'client-secret-de-teste',
            'services.bling.redirect'      => 'https://exemplo.com/callback',
        ]);

        $this->assertTrue(BlingConexao::estaConfigurado());
    }
}
