<?php

namespace Tests\Unit\Publicador\Alavancas;

use App\Models\Company;
use App\Models\MlbEmpresa;
use App\Support\Publicador\AlavancasLiberadas;
use App\Support\Publicador\ContasLiberadas;
use App\Support\Publicador\RegraViolada;
use Tests\TestCase;

/**
 * D-03 (Fase 166): a trava das Alavancas é PRÓPRIA — liberar a publicação não
 * libera as Alavancas, nem o contrário. Sem banco: modelos em memória.
 */
class AlavancasLiberadasTest extends TestCase
{
    /** Variáveis de ambiente mexidas por um teste, para restaurar no tearDown. */
    private array $envOriginal = [];

    protected function tearDown(): void
    {
        foreach ($this->envOriginal as $nome => $valor) {
            if ($valor === null) {
                putenv($nome);
                unset($_ENV[$nome], $_SERVER[$nome]);
            } else {
                putenv("{$nome}={$valor}");
                $_ENV[$nome] = $_SERVER[$nome] = $valor;
            }
        }
        parent::tearDown();
    }

    private function company(int $id): Company
    {
        $c = new Company();
        $c->id = $id;

        return $c;
    }

    private function empresa(int $id): MlbEmpresa
    {
        $e = new MlbEmpresa();
        $e->id = $id;

        return $e;
    }

    private function definirEnv(string $nome, string $valor): void
    {
        if (! array_key_exists($nome, $this->envOriginal)) {
            $atual = getenv($nome);
            $this->envOriginal[$nome] = $atual === false ? null : $atual;
        }
        putenv("{$nome}={$valor}");
        $_ENV[$nome] = $_SERVER[$nome] = $valor;
    }

    private function removerEnv(string $nome): void
    {
        if (! array_key_exists($nome, $this->envOriginal)) {
            $atual = getenv($nome);
            $this->envOriginal[$nome] = $atual === false ? null : $atual;
        }
        putenv($nome);
        unset($_ENV[$nome], $_SERVER[$nome]);
    }

    public function test_publicacao_liberada_nao_libera_as_alavancas(): void
    {
        config([
            'publicador.contas_liberadas.companies' => [459],
            'publicador.alavancas.contas_liberadas.companies' => [],
        ]);

        $this->assertTrue(ContasLiberadas::libera($this->company(459)));
        $this->assertFalse(AlavancasLiberadas::libera($this->company(459)));
    }

    public function test_alavancas_liberadas_nao_liberam_a_publicacao(): void
    {
        config([
            'publicador.contas_liberadas.companies' => [],
            'publicador.alavancas.contas_liberadas.companies' => [459],
        ]);

        $this->assertTrue(AlavancasLiberadas::libera($this->company(459)));
        $this->assertFalse(ContasLiberadas::libera($this->company(459)));
    }

    public function test_company_liberada_nao_libera_mlb_empresa_de_mesmo_id(): void
    {
        config([
            'publicador.alavancas.contas_liberadas.companies' => [459],
            'publicador.alavancas.contas_liberadas.mlb_empresas' => [],
        ]);

        $this->assertTrue(AlavancasLiberadas::libera($this->company(459)));
        $this->assertFalse(AlavancasLiberadas::libera($this->empresa(459)));

        config(['publicador.alavancas.contas_liberadas.mlb_empresas' => [459]]);
        $this->assertTrue(AlavancasLiberadas::libera($this->empresa(459)));
    }

    public function test_lista_vazia_nas_duas_ancoras_nao_libera_ninguem_e_null_e_false(): void
    {
        config([
            'publicador.alavancas.contas_liberadas.companies' => [],
            'publicador.alavancas.contas_liberadas.mlb_empresas' => [],
        ]);

        $this->assertFalse(AlavancasLiberadas::libera($this->company(459)));
        $this->assertFalse(AlavancasLiberadas::libera($this->empresa(459)));
        $this->assertFalse(AlavancasLiberadas::libera(null));
    }

    public function test_exigir_em_conta_fora_da_lista_lanca_regra_violada(): void
    {
        config(['publicador.alavancas.contas_liberadas.companies' => [459]]);

        try {
            AlavancasLiberadas::exigir($this->company(7));
            $this->fail('Devia lançar RegraViolada.');
        } catch (RegraViolada $e) {
            $this->assertSame('ALAV-LIB', $e->regra);
            $this->assertSame(AlavancasLiberadas::MOTIVO, $e->getMessage());
        }

        AlavancasLiberadas::exigir($this->company(459));
        $this->addToAssertionCount(1);
    }

    public function test_motivo_nao_fala_de_publicacao(): void
    {
        $this->assertStringNotContainsStringIgnoringCase('publica', AlavancasLiberadas::MOTIVO);
    }

    /** Avalia o arquivo de config com o ambiente atual. */
    private function configAvaliado(): array
    {
        return require config_path('publicador.php');
    }

    public function test_config_sem_variavel_das_alavancas_libera_so_a_459_sem_fallback_da_publicacao(): void
    {
        $this->removerEnv('PUBLICADOR_ALAVANCAS_LIBERADAS_COMPANIES');
        $this->definirEnv('PUBLICADOR_EMPRESAS_PILOTO', '7');
        $this->definirEnv('PUBLICADOR_CONTAS_LIBERADAS_COMPANIES', '8');

        $this->assertSame([459], $this->configAvaliado()['alavancas']['contas_liberadas']['companies']);
    }

    public function test_config_com_variavel_vazia_nao_libera_ninguem(): void
    {
        $this->definirEnv('PUBLICADOR_ALAVANCAS_LIBERADAS_COMPANIES', '');

        $this->assertSame([], $this->configAvaliado()['alavancas']['contas_liberadas']['companies']);
    }

    public function test_bloco_alavancas_do_config_nao_cita_as_variaveis_da_publicacao(): void
    {
        // Prova pela FONTE, além da prova pelo ambiente acima.
        $fonte = file_get_contents(config_path('publicador.php'));
        $inicio = strpos($fonte, "'alavancas' =>");
        $this->assertNotFalse($inicio);
        $bloco = substr($fonte, $inicio);

        $this->assertStringNotContainsString('PUBLICADOR_EMPRESAS_PILOTO', $bloco);
        $this->assertStringNotContainsString('PUBLICADOR_CONTAS_LIBERADAS', $bloco);
    }
}
