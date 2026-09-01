<?php

namespace Tests\Feature\Phase137;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Fase 137 (plano 06, ETAPA-03 / T-137-01) — prova permanente do Success
 * Criteria nº 2 da fase: só `App\Services\FluxoEntrada\EtapaTransicaoService`
 * escreve `companies.etapa`.
 *
 * `Company::etapa` está em `$fillable` desde o plano 137-02 (necessário para
 * o serviço gravar via Eloquent). Isso abre superfície de mass assignment:
 * nada no código impede um dev futuro de acrescentar `'etapa' =>
 * 'nullable|string'` à lista fechada de `CompanyController::update()` — a
 * partir daí toda edição manual de empresa poderia pular etapas do fluxo,
 * sem erro, sem log, sem teste quebrando. Este arquivo é a mitigação
 * registrada como T-137-01 (e o caso-espelho T-137-14, para `pendencia_*`).
 *
 * Grupo 1 — comportamento: `PUT /companies/{company}` com `etapa`/
 * `pendencia_*` no corpo não pode gravar, mesmo enviando todas as chaves que
 * a validação de hoje aceita.
 *
 * Grupo 2 — varredura estática: nenhum outro arquivo de `app/` pode escrever
 * `companies.etapa`, hoje ou em qualquer mudança futura.
 */
class EtapaPontoUnicoTest extends TestCase
{
    use RefreshDatabase;

    // ═════════════════════════════════════════════════════════════════════
    // Helpers
    // ═════════════════════════════════════════════════════════════════════

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    /**
     * Todas as chaves que `CompanyController::update()` aceita hoje, com
     * valores válidos — usado para provar que mesmo enviando o payload
     * "completo" mais `etapa`/`pendencia_*` extras, os campos extras não
     * passam. `name` é a única chave obrigatória.
     */
    private function payloadCompleto(array $extra = []): array
    {
        return array_merge([
            'name'    => 'Empresa Ponto Único ' . uniqid(),
            'cnpj'    => null,
            'segment' => 'Teste',
            'notes'   => 'Observação de teste',
            'active'  => true,
        ], $extra);
    }

    // ═════════════════════════════════════════════════════════════════════
    // Grupo 1 — o endpoint não pode mover a etapa nem a pendência
    // (T-137-01, T-137-14)
    // ═════════════════════════════════════════════════════════════════════

    public function test_put_companies_com_etapa_valida_no_corpo_nao_muda_a_etapa(): void
    {
        $admin   = $this->admin();
        $empresa = Company::factory()->create(['etapa' => Company::ETAPA_AGUARDANDO_ADMINISTRATIVO]);

        $response = $this->actingAs($admin)->put(
            route('companies.update', $empresa),
            $this->payloadCompleto(['etapa' => Company::ETAPA_EM_OPERACAO])
        );

        $response->assertSessionHasNoErrors();
        $this->assertLessThan(500, $response->getStatusCode(), 'PUT /companies/{company} não pode devolver erro de servidor.');

        $empresa->refresh();
        $this->assertSame(
            Company::ETAPA_AGUARDANDO_ADMINISTRATIVO,
            $empresa->etapa,
            'ETAPA-03 violado (plano 137-06, T-137-01): PUT /companies/{company} moveu companies.etapa '
                . "via mass assignment (esperado 'aguardando_administrativo', achou '{$empresa->etapa}'). "
                . 'Ver tests/Feature/Phase137/EtapaPontoUnicoTest.php — companies.etapa só pode ser '
                . 'gravado por App\\Services\\FluxoEntrada\\EtapaTransicaoService.'
        );
    }

    public function test_put_companies_com_etapa_invalida_no_corpo_nao_grava(): void
    {
        $admin   = $this->admin();
        $empresa = Company::factory()->create(['etapa' => Company::ETAPA_AGUARDANDO_ADMINISTRATIVO]);

        $response = $this->actingAs($admin)->put(
            route('companies.update', $empresa),
            $this->payloadCompleto(['etapa' => 'qualquer_coisa'])
        );

        $this->assertLessThan(500, $response->getStatusCode(), 'PUT /companies/{company} não pode devolver erro de servidor.');

        $empresa->refresh();
        $this->assertSame(
            Company::ETAPA_AGUARDANDO_ADMINISTRATIVO,
            $empresa->etapa,
            "ETAPA-03 violado (plano 137-06, T-137-01): PUT /companies/{company} aceitou etapa fora do "
                . 'vocabulário travado (Company::ETAPAS). Ver tests/Feature/Phase137/EtapaPontoUnicoTest.php.'
        );
    }

    public function test_put_companies_com_pendencia_no_corpo_nao_marca_pendencia(): void
    {
        $admin   = $this->admin();
        $empresa = Company::factory()->create(['etapa' => Company::ETAPA_AGUARDANDO_ADMINISTRATIVO]);

        $this->assertFalse($empresa->pendenciaAberta(), 'empresa recém-criada não deveria ter pendência.');

        $response = $this->actingAs($admin)->put(
            route('companies.update', $empresa),
            $this->payloadCompleto([
                'pendencia_aberta' => true,
                'pendencia_motivo' => 'Furou o ponto único por mass assignment',
            ])
        );

        $this->assertLessThan(500, $response->getStatusCode(), 'PUT /companies/{company} não pode devolver erro de servidor.');

        $empresa->refresh();
        $this->assertFalse(
            $empresa->pendenciaAberta(),
            'T-137-14 violado (plano 137-06): PUT /companies/{company} marcou pendência via mass assignment. '
                . 'pendencia_aberta só pode ser gravado por Company::declararPendencia()/resolverPendencia().'
        );
        $this->assertNull($empresa->pendencia_motivo);
    }

    // ═════════════════════════════════════════════════════════════════════
    // Grupo 2 — varredura estática: nenhum outro escritor de companies.etapa
    // (ETAPA-03, T-137-03, T-137-17)
    //
    // Padrões procurados, por CORPO DE FUNÇÃO/MÉTODO (não por arquivo
    // inteiro nem por linha solta):
    //   1. `$company->etapa = ` (atribuição direta, exclui `==`/`===`) —
    //      sinaliza violação sozinha, não precisa de mais nada perto.
    //   2. `'etapa' => ` / `"etapa" => ` (chave de array) — só conta como
    //      violação quando o MESMO corpo de função também contém uma
    //      chamada de escrita no model Company (`Company::create(`,
    //      `Company::updateOrCreate(`, `Company::firstOrCreate(`,
    //      `Company::forceCreate(`, `$company->update(`, `$company->fill(`,
    //      `$company->forceFill(`, ou `Company::` combinado com `->update(`
    //      no mesmo corpo — cobre o `Company::whereIn(...)->update([...])`
    //      que `EtapaTransicaoService::carimbarBackfill()` já usa).
    //
    // Por que a chave crua `'etapa' =>` sozinha NÃO é o padrão (deviation
    // documentada em 137-06-SUMMARY.md, Rule 1): `OnboardingPasso` também
    // tem uma coluna `etapa`, sem nenhuma relação com `companies.etapa`, e
    // é gravada/lida por `'etapa' => ...` em ~10 lugares de
    // app/Console/Commands/Onboarding*.php,
    // app/Services/Onboarding/OnboardingEngineService.php,
    // app/Services/Onboarding/OnboardingLinkService.php,
    // app/Http/Controllers/OnboardingController.php e
    // app/Support/Onboarding/DefinicaoOnboarding.php. Um grep cru de
    // `'etapa' =>` OU falha hoje, no repositório atual e sem violação
    // nenhuma (falso positivo em massa), ou exigiria uma lista de exceções
    // por arquivo que cresce a cada novo uso legítimo de
    // `OnboardingPasso::etapa` — o oposto de um gate que "fica verdadeiro
    // para sempre" sem manutenção. Escopar por corpo de função, exigindo
    // coocorrência com uma chamada de escrita em `Company`, resolve os dois
    // falsos positivos confirmados (OnboardingPasso::etapa e arrays de
    // resposta que só LEEM `$passo->etapa`) sem abrir exceção nenhuma.
    // ═════════════════════════════════════════════════════════════════════

    public function test_gate_nenhum_arquivo_de_app_escreve_companies_etapa_fora_do_servico(): void
    {
        $arquivoPermitido = realpath(base_path('app/Services/FluxoEntrada/EtapaTransicaoService.php'));
        $this->assertNotFalse($arquivoPermitido, 'EtapaTransicaoService.php precisa existir (plano 137-03).');

        $ofensores = [];

        foreach (File::allFiles(base_path('app')) as $arquivo) {
            $caminho = $arquivo->getPathname();

            if (! str_ends_with($caminho, '.php')) {
                continue;
            }

            if (realpath($caminho) === $arquivoPermitido) {
                continue;
            }

            $conteudo = File::get($caminho);

            foreach ($this->corposDeFuncao($conteudo) as $corpo) {
                $violacao = $this->descreveViolacao($corpo);

                if ($violacao !== null) {
                    $linha        = $this->linhaDoPrimeiroMatch($conteudo, $violacao['regex']);
                    $ofensores[] = "{$caminho}" . ($linha !== null ? ":{$linha}" : '') . " — {$violacao['motivo']}";
                }
            }
        }

        $this->assertEmpty(
            $ofensores,
            "Success Criteria nº 2 da Fase 137 violado (ETAPA-03): companies.etapa é gravado fora de "
                . 'App\\Services\\FluxoEntrada\\EtapaTransicaoService em: '
                . implode(' | ', $ofensores)
                . '. Ver tests/Feature/Phase137/EtapaPontoUnicoTest.php.'
        );
    }

    /**
     * Extrai o texto de todo corpo de função/método (inclui closures) de um
     * arquivo PHP via `token_get_all()` + balanceamento de chaves — imune a
     * `{`/`}` dentro de strings ou comentários, o que uma extração por
     * regex não garantiria.
     *
     * @return list<string>
     */
    private function corposDeFuncao(string $codigo): array
    {
        $tokens = token_get_all($codigo);
        $n      = count($tokens);
        $corpos = [];

        for ($i = 0; $i < $n; $i++) {
            $token = $tokens[$i];

            if (! is_array($token) || $token[0] !== T_FUNCTION) {
                continue;
            }

            // Avança até a chave de abertura do corpo (pula assinatura,
            // tipo de retorno, `use (...)` de closure). Funções abstratas
            // ou de interface terminam em `;` antes de achar `{` — pula.
            $j = $i + 1;
            while ($j < $n && $tokens[$j] !== '{' && $tokens[$j] !== ';') {
                $j++;
            }
            if ($j >= $n || $tokens[$j] === ';') {
                continue;
            }

            $inicio = $j;
            $profundidade = 0;

            for ($k = $j; $k < $n; $k++) {
                if ($tokens[$k] === '{') {
                    $profundidade++;
                } elseif ($tokens[$k] === '}') {
                    $profundidade--;
                    if ($profundidade === 0) {
                        $corpo = '';
                        for ($m = $inicio; $m <= $k; $m++) {
                            $corpo .= is_array($tokens[$m]) ? $tokens[$m][1] : $tokens[$m];
                        }
                        $corpos[] = $corpo;
                        break;
                    }
                }
            }
        }

        return $corpos;
    }

    /**
     * @return array{regex: string, motivo: string}|null
     */
    private function descreveViolacao(string $corpo): ?array
    {
        // 1. Atribuição direta a $company->etapa (exclui == / ===).
        if (preg_match('/\$company->etapa\s*=(?!=)/', $corpo)) {
            return [
                'regex'  => '/\$company->etapa\s*=(?!=)/',
                'motivo' => 'atribuição direta $company->etapa = ...',
            ];
        }

        // 2. Chave 'etapa' => / "etapa" => dentro de um corpo que também
        // grava no model Company.
        $temChaveEtapa = preg_match('/([\'"])etapa\1\s*=>/', $corpo) === 1;
        if (! $temChaveEtapa) {
            return null;
        }

        $escreveNaCompany =
            preg_match('/Company::(create|updateOrCreate|firstOrCreate|forceCreate)\s*\(/', $corpo) === 1
            || preg_match('/\$company->(update|fill|forceFill)\s*\(/', $corpo) === 1
            || (preg_match('/Company::\w+\s*\(/', $corpo) === 1 && preg_match('/->update\s*\(/', $corpo) === 1);

        if ($escreveNaCompany) {
            return [
                'regex'  => '/([\'"])etapa\1\s*=>/',
                'motivo' => "chave 'etapa' => dentro de uma escrita no model Company",
            ];
        }

        return null;
    }

    private function linhaDoPrimeiroMatch(string $conteudo, string $regex): ?int
    {
        if (preg_match($regex, $conteudo, $m, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }

        $offset = $m[0][1];

        return substr_count(substr($conteudo, 0, $offset), "\n") + 1;
    }
}
