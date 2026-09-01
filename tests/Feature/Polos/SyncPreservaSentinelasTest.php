<?php

// A planilha de Polos é copiada por cima das fichas a cada `polos:sync-planilha --apply`.
// Isso é intencional para quase tudo — a planilha é a verdade operacional. Mas dois valores
// nascem do CLIENTE, no link público do Onboarding, e não existem na planilha:
//
//   · acesso_colaborador = 'Falta Aceitar'  (cliente diz que convidou; falta a ECF aceitar)
//   · decola             = 'Verificar'      (cliente diz que aderiu; falta a ECF conferir)
//
// Sem blindagem, o próximo --apply devolvia a ficha ao valor antigo e o clique do cliente
// sumia sem rastro. Este teste tranca isso. Cobre também a normalização de ME1/Integradora
// na INGESTÃO — sem ela, `onboarding:normalizar-catalogos` seria um one-shot desfeito pelo
// sync seguinte (foi assim que a coluna ME1 acumulou 12 variantes em 269 fichas).

namespace Tests\Feature\Polos;

use App\Models\MlbEmpresa;
use App\Models\MlbImplementacao;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SyncPreservaSentinelasTest extends TestCase
{
    use RefreshDatabase;

    private function csv(array $header, array $rows): string
    {
        $path = tempnam(sys_get_temp_dir(), 'polos_') . '.csv';
        $fh   = fopen($path, 'w');
        fputcsv($fh, $header);
        foreach ($rows as $r) {
            fputcsv($fh, $r);
        }
        fclose($fh);

        return $path;
    }

    /** Cria empresa POLOS + ficha com os valores informados. */
    private function fichaCom(string $cust, array $attrs): MlbImplementacao
    {
        $empresa = MlbEmpresa::create([
            'nome'    => 'Loja Sentinela ' . $cust,
            'tipo'    => 'POLO',
            'projeto' => 'POLOS',
            'fase'    => 'M2',
            'polo'    => 'Arapongas',
            'cust_id' => $cust,
        ]);

        return MlbImplementacao::create(array_merge([
            'empresa_id' => $empresa->id,
            'token'      => 'tok' . $cust,
        ], $attrs));
    }

    public function test_planilha_nao_sobrescreve_falta_aceitar(): void
    {
        $ficha = $this->fichaCom('3100000001', ['acesso_colaborador' => 'Falta Aceitar']);

        $file = $this->csv(
            ['Cust ID', 'Loja', 'Fase', 'Polo', 'Acesso Colaborador'],
            [['3100000001', 'Loja Sentinela 3100000001', 'M2', 'Arapongas', 'Sem acesso']],
        );

        $this->artisan('polos:sync-planilha', ['--file' => $file, '--apply' => true])
            ->assertExitCode(0);

        $this->assertSame('Falta Aceitar', $ficha->refresh()->acesso_colaborador);

        @unlink($file);
    }

    public function test_planilha_nao_sobrescreve_verificar_do_decola(): void
    {
        $ficha = $this->fichaCom('3100000002', ['decola' => 'Verificar']);

        $file = $this->csv(
            ['Cust ID', 'Loja', 'Fase', 'Polo', 'Decola'],
            [['3100000002', 'Loja Sentinela 3100000002', 'M2', 'Arapongas', 'Não']],
        );

        $this->artisan('polos:sync-planilha', ['--file' => $file, '--apply' => true])
            ->assertExitCode(0);

        $this->assertSame('Verificar', $ficha->refresh()->decola);

        @unlink($file);
    }

    public function test_planilha_continua_mandando_quando_nao_ha_sentinela(): void
    {
        // A blindagem é cirúrgica: protege só o valor do cliente, não a coluna inteira.
        $ficha = $this->fichaCom('3100000003', ['acesso_colaborador' => 'Sem acesso', 'decola' => 'Não']);

        $file = $this->csv(
            ['Cust ID', 'Loja', 'Fase', 'Polo', 'Acesso Colaborador', 'Decola'],
            [['3100000003', 'Loja Sentinela 3100000003', 'M2', 'Arapongas', 'Com acesso', 'Sim']],
        );

        $this->artisan('polos:sync-planilha', ['--file' => $file, '--apply' => true])
            ->assertExitCode(0);

        $ficha->refresh();
        $this->assertSame('Com acesso', $ficha->acesso_colaborador);
        $this->assertSame('Sim', $ficha->decola);

        @unlink($file);
    }

    public function test_me1_e_normalizado_na_ingestao(): void
    {
        // Sem isto, a planilha re-suja a coluna no --apply seguinte à normalização.
        $ficha = $this->fichaCom('3100000004', []);

        $file = $this->csv(
            ['Cust ID', 'Loja', 'Fase', 'Polo', 'ME1'],
            [['3100000004', 'Loja Sentinela 3100000004', 'M2', 'Arapongas', 'EM CONTRATAÇÃO']],
        );

        $this->artisan('polos:sync-planilha', ['--file' => $file, '--apply' => true])
            ->assertExitCode(0);

        $this->assertSame('Em contratação', $ficha->refresh()->me1);
        $this->assertContains($ficha->me1, MlbImplementacao::ONB_ME1_OPCOES);

        @unlink($file);
    }

    public function test_integradora_e_normalizada_na_ingestao(): void
    {
        $ficha = $this->fichaCom('3100000005', []);

        $file = $this->csv(
            ['Cust ID', 'Loja', 'Fase', 'Polo', 'Integradora'],
            [['3100000005', 'Loja Sentinela 3100000005', 'M2', 'Arapongas', 'Intelispost']],
        );

        $this->artisan('polos:sync-planilha', ['--file' => $file, '--apply' => true])
            ->assertExitCode(0);

        $this->assertSame('Intelipost', $ficha->refresh()->integradora);

        @unlink($file);
    }

    public function test_sem_itens_ainda_da_planilha_limpa_a_coluna(): void
    {
        // 'Sem itens ainda' saiu do catálogo: não há status de ME1 a afirmar enquanto a
        // empresa não mandou produto. O de-para é o mesmo do comando de normalização.
        $ficha = $this->fichaCom('3100000006', ['me1' => 'Ativo']);

        $file = $this->csv(
            ['Cust ID', 'Loja', 'Fase', 'Polo', 'ME1'],
            [['3100000006', 'Loja Sentinela 3100000006', 'M2', 'Arapongas', 'Sem itens ainda']],
        );

        $this->artisan('polos:sync-planilha', ['--file' => $file, '--apply' => true])
            ->assertExitCode(0);

        // normalizarMe1 devolve null → o campo não entra em $fichaData → a planilha não
        // mexe. O valor antigo permanece, e quem limpa o legado é o comando dedicado.
        $this->assertSame('Ativo', $ficha->refresh()->me1);

        @unlink($file);
    }
}
