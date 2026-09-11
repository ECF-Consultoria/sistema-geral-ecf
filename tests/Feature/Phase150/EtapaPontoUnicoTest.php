<?php

namespace Tests\Feature\Phase150;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Fase 150 (plano 06, ETAPA-03 / T-150-01) — prova permanente do Success
 * Criteria nº 2 da fase: só `App\Services\FluxoEntrada\EtapaTransicaoService`
 * escreve `companies.etapa`.
 *
 * `Company::etapa` está em `$fillable` desde o plano 150-02 (necessário para
 * o serviço gravar via Eloquent). Isso abre superfície de mass assignment:
 * nada no código impede um dev futuro de acrescentar `'etapa' =>
 * 'nullable|string'` à lista fechada de `CompanyController::update()` — a
 * partir daí toda edição manual de empresa poderia pular etapas do fluxo,
 * sem erro, sem log, sem teste quebrando. Este arquivo é a mitigação
 * registrada como T-150-01 (e o caso-espelho T-150-14, para `pendencia_*`).
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
    // (T-150-01, T-150-14)
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
            'ETAPA-03 violado (plano 150-06, T-150-01): PUT /companies/{company} moveu companies.etapa '
                . "via mass assignment (esperado 'aguardando_administrativo', achou '{$empresa->etapa}'). "
                . 'Ver tests/Feature/Phase150/EtapaPontoUnicoTest.php — companies.etapa só pode ser '
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
            "ETAPA-03 violado (plano 150-06, T-150-01): PUT /companies/{company} aceitou etapa fora do "
                . 'vocabulário travado (Company::ETAPAS). Ver tests/Feature/Phase150/EtapaPontoUnicoTest.php.'
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
            'T-150-14 violado (plano 150-06): PUT /companies/{company} marcou pendência via mass assignment. '
                . 'pendencia_aberta só pode ser gravado por Company::declararPendencia()/resolverPendencia().'
        );
        $this->assertNull($empresa->pendencia_motivo);
    }

    // ═════════════════════════════════════════════════════════════════════
    // Grupo 2 — varredura estática: nenhum outro escritor de companies.etapa
    // (ETAPA-03, T-150-03, T-150-17, T-150-21..T-150-24 — endurecido pelo
    // plano 150-08 depois de o gate anterior ser provado contornável por
    // injeção real de código, ver 150-VERIFICATION.md § "Achado: gate
    // estático da ETAPA-03 é contornável")
    //
    // Fecha as 3 formas de bypass comprovadas:
    //
    //   Regra A — atribuição direta `->etapa = ...` (exclui `==`/`===`),
    //   avaliada sobre o ARQUIVO INTEIRO já sem comentário (não por corpo
    //   de função) — pega também atribuição de propriedade de classe ou
    //   fora de método. Deixou de exigir o nome literal `$company`: fecha
    //   o Bypass 1 (`$empresa->etapa = ...`). Sem nenhuma ocorrência
    //   legítima hoje em app/ nem database/migrations/ (150-08-PLAN.md,
    //   <measured_facts>), o custo de um falso positivo eventual é aceito
    //   e tratado por EXCECOES_REGRA_A — lista que nasce VAZIA;
    //   acrescentar ali é ato deliberado e revisado, nunca conveniência
    //   para destravar a suíte.
    //
    //   Regra B — escrita em OFFSET de array (`$var['etapa'] = ...`),
    //   avaliada por CORPO DE FUNÇÃO, só conta como violação quando o
    //   mesmo corpo também grava no model Company (detector abaixo).
    //   Fecha o Bypass 2 (`$dados['etapa'] = ...; $company->update($dados);`).
    //
    //   Regra C — chave literal de array (`'etapa' => `/`"etapa" => `),
    //   mantida por CORPO DE FUNÇÃO + mesmo detector — herdada do plano
    //   150-06, protege contra o falso positivo de `OnboardingPasso::etapa`
    //   (coluna homônima, sem relação com `companies.etapa`, usada em
    //   ~10 lugares de app/Console/Commands/Onboarding*.php,
    //   app/Services/Onboarding/*.php,
    //   app/Http/Controllers/OnboardingController.php e
    //   app/Support/Onboarding/DefinicaoOnboarding.php). Um grep cru de
    //   `'etapa' =>` sozinho nasceria vermelho contra o repositório atual
    //   sem violação nenhuma — por isso NUNCA vira `str_contains` puro,
    //   ao contrário do gate irmão de `pendencia_aberta`
    //   (EtapaPendenciaParaleloTest.php), que pode usar substring porque
    //   aquele nome é único no repositório.
    //
    // Detector de escrita no model Company ($escreveNaCompany — método
    // escreveNaCompany() abaixo), ampliado para as regras B e C:
    //   (a) `Company::(create|updateOrCreate|firstOrCreate|forceCreate)(`;
    //   (b) `DB::table('companies')` — fecha o Bypass 3
    //       (`DB::table('companies')->where(...)->update([...])`, idioma
    //       real de app/Console/Commands/DiagnoseCustId.php:206 e
    //       app/Console/Commands/ImportMarketplaceFromCsv.php:145, hoje
    //       sem a chave 'etapa' em nenhum dos dois);
    //   (c) o ARQUIVO (não o corpo) cita `\bCompany\b` E o CORPO casa
    //       `->(update|fill|forceFill|save)(` — remove o hardcode do nome
    //       `$company`. Avaliado no ARQUIVO de propósito: corposDeFuncao()
    //       descarta a assinatura da função ao extrair o corpo, então um
    //       parâmetro `Company $empresa` some do texto varrido — um guard
    //       escopado ao corpo deixaria escapar exatamente
    //       `$dados['etapa'] = ...; $empresa->update($dados);`;
    //   (d) `Company::\w+(` + `->update(` no mesmo corpo — regra original
    //       do plano 150-06, mantida (cobre
    //       `Company::whereIn(...)->update([...])` de
    //       `EtapaTransicaoService::carimbarBackfill()`).
    //
    // Comentários (`T_COMMENT`/`T_DOC_COMMENT`) são neutralizados ANTES de
    // qualquer regra rodar (removerComentarios()): o próprio verificador
    // teve um falso positivo causado por um comentário contendo o texto
    // `$company->etapa = ` — e o comentário-guarda de 16 linhas em
    // CompanyController::update() é exatamente esse tipo de texto vivendo
    // em app/. O texto do comentário é trocado só pelas quebras de linha
    // que ele continha, para não deslocar a numeração usada em
    // linhaDoPrimeiroMatch().
    //
    // Escopo: app/ E database/migrations/ (migration corretiva é o idioma
    // natural de `DB::table('companies')->update(...)` e não passa pelo
    // serviço). tests/ continua FORA de propósito — este próprio arquivo e
    // o 150-10 precisam escrever companies.etapa fora do serviço dentro de
    // testes, para simular violação e estado obsoleto.
    //
    // Quase-colisões conhecidas que PASSAM hoje, e por quê
    // (150-08-PLAN.md, <measured_facts>) — se algum destes corpos ganhar
    // um `->update()`/`->save()` no futuro, o gate fica vermelho por falso
    // positivo; a saída correta é EXCECOES_REGRA_A ou um ajuste do
    // detector, NUNCA afrouxar a regra:
    //   - app/Services/Onboarding/OnboardingEngineService.php:368 e
    //     app/Services/Onboarding/OnboardingLinkService.php:96 têm
    //     'etapa' => de OnboardingPasso — passam porque o corpo não
    //     contém escrita reconhecida em Company;
    //   - app/Http/Controllers/OnboardingController.php:948 (Regra B,
    //     `$payload['etapa'] = $trava->etapa;`) e :1135 (Regra C) — passam
    //     pelo mesmo motivo, mesmo o ARQUIVO citando Company em outros
    //     métodos (ex.: `gerarLink(Request $request, Company $company)`);
    //   - database/migrations/2026_08_17_120000_add_etapa_to_onboarding_passos_table.php:69
    //     tem `->update(['etapa' => $etapa])` — passa porque o arquivo NÃO
    //     cita Company (0 ocorrências) e a tabela é onboarding_passos, não
    //     companies.
    //
    // Prova por injeção real (150-08-PLAN.md, Task 2), datada 2026-09-02 —
    // cada sonda criada isoladamente em app/Http/Controllers/, gate rodado,
    // sonda removida antes da próxima; árvore confirmada limpa ao final
    // (`git status --short` sem `??` em app/):
    //   Sonda 1 (nome de variável diferente — $empresa->etapa = ...):
    //     `phpunit tests/Feature/Phase150/EtapaPontoUnicoTest.php --filter
    //     test_gate_nenhum_arquivo_de_app_escreve_companies_etapa_fora_do_servico`
    //     → FALHA, nomeando SondaGate137_08Temp.php:15, [Regra A].
    //   Sonda 2 (array indireto, sem token Company no corpo — $dados['etapa']
    //     = 'em_operacao'; $company->update($dados);): mesmo comando → FALHA,
    //     nomeando SondaGate137_08Temp.php:19, [Regra B].
    //   Sonda 3 (DB::table('companies')->where(...)->update(['etapa' =>
    //     ...])): mesmo comando → FALHA, nomeando SondaGate137_08Temp.php:18,
    //     [Regra C] (disparada pelo detector DB::table('companies')).
    //   Sonda 4 (regressão — $company->etapa = ..., nome original já coberto
    //     pelo 150-06): mesmo comando → FALHA, nomeando
    //     SondaGate137_08Temp.php:16, [Regra A]. Confirma que a ampliação não
    //     perdeu o que já funcionava.
    //   Sonda 5 (falso positivo de comentário — único conteúdo relevante é um
    //     comentário contendo `$company->etapa = Company::ETAPA_EM_OPERACAO;`
    //     como texto, sem código de escrita real): mesmo comando → `OK (1
    //     test, 2 assertions)`, provando a neutralização de comentários.
    // Evidência completa (saída real do phpunit) em 150-08-SUMMARY.md.
    // ═════════════════════════════════════════════════════════════════════

    /**
     * Exceções nomeadas à Regra A — nasce VAZIA de propósito. Hoje não
     * existe nenhuma ocorrência legítima de `->etapa = ` fora do serviço em
     * app/ nem em database/migrations/ (150-08-PLAN.md, <measured_facts>).
     * Acrescentar um caminho aqui é ato deliberado e revisado — nunca
     * conveniência para destravar a suíte depois de um vermelho. Caminho
     * relativo a `base_path()`, com barra `/`.
     *
     * @var list<string>
     */
    private const EXCECOES_REGRA_A = [];

    private const REGEX_ATRIBUICAO_DIRETA = '/->etapa\s*=(?!=)/';
    private const REGEX_ARRAY_OFFSET      = '/\[\s*([\'"])etapa\1\s*\]\s*=(?!=)/';
    private const REGEX_CHAVE_ARRAY       = '/([\'"])etapa\1\s*=>/';

    public function test_gate_nenhum_arquivo_de_app_escreve_companies_etapa_fora_do_servico(): void
    {
        $arquivoPermitido = realpath(base_path('app/Services/FluxoEntrada/EtapaTransicaoService.php'));
        $this->assertNotFalse($arquivoPermitido, 'EtapaTransicaoService.php precisa existir (plano 150-03).');

        $ofensores = [];

        $arquivos = array_merge(
            File::allFiles(base_path('app')),
            File::allFiles(base_path('database/migrations'))
        );

        foreach ($arquivos as $arquivo) {
            $caminho = $arquivo->getPathname();

            if (! str_ends_with($caminho, '.php')) {
                continue;
            }

            if (realpath($caminho) === $arquivoPermitido) {
                continue;
            }

            $conteudo = $this->removerComentarios(File::get($caminho));

            // Regra A — arquivo inteiro, sem exigir nome de variável
            // (150-08-PLAN.md, item 3).
            if (preg_match(self::REGEX_ATRIBUICAO_DIRETA, $conteudo) === 1
                && ! in_array($this->caminhoRelativo($caminho), self::EXCECOES_REGRA_A, true)
            ) {
                $linha       = $this->linhaDoPrimeiroMatch($conteudo, self::REGEX_ATRIBUICAO_DIRETA);
                $ofensores[] = "{$caminho}" . ($linha !== null ? ":{$linha}" : '')
                    . ' — [Regra A] atribuição direta ->etapa = ... fora de EtapaTransicaoService';
            }

            $arquivoCitaCompany = preg_match('/\bCompany\b/', $conteudo) === 1;

            foreach ($this->corposDeFuncao($conteudo) as $corpo) {
                $violacao = $this->descreveViolacaoNoCorpo($corpo, $arquivoCitaCompany);

                if ($violacao !== null) {
                    $linha       = $this->linhaDoPrimeiroMatch($conteudo, $violacao['regex']);
                    $ofensores[] = "{$caminho}" . ($linha !== null ? ":{$linha}" : '') . " — {$violacao['motivo']}";
                }
            }
        }

        $this->assertEmpty(
            $ofensores,
            "Success Criteria nº 2 da Fase 150 violado (ETAPA-03): companies.etapa é gravado fora de "
                . 'App\\Services\\FluxoEntrada\\EtapaTransicaoService em: '
                . implode(' | ', $ofensores)
                . '. Ver tests/Feature/Phase150/EtapaPontoUnicoTest.php.'
        );
    }

    /**
     * Neutraliza comentários (`T_COMMENT`/`T_DOC_COMMENT`) ANTES de
     * qualquer regra rodar (150-08-PLAN.md, item 1): o texto do comentário
     * some, trocado só pelas quebras de linha que ele continha, para que a
     * numeração de linha usada em linhaDoPrimeiroMatch() não se desloque.
     * Fecha o falso positivo que o próprio 150-VERIFICATION.md relatou
     * (comentário contendo `$company->etapa = ` como texto explicativo).
     */
    private function removerComentarios(string $codigo): string
    {
        $tokens = token_get_all($codigo);
        $limpo  = '';

        foreach ($tokens as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                $limpo .= str_repeat("\n", substr_count($token[1], "\n"));

                continue;
            }

            $limpo .= is_array($token) ? $token[1] : $token;
        }

        return $limpo;
    }

    /**
     * Extrai o texto de todo corpo de função/método (inclui closures) de um
     * arquivo PHP via `token_get_all()` + balanceamento de chaves — imune a
     * `{`/`}` dentro de strings ou comentários, o que uma extração por
     * regex não garantiria. Recebe o código já limpo de comentários
     * (removerComentarios()) — corpo NUNCA contém texto de comentário.
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
     * Regras B e C (150-08-PLAN.md, itens 4-5) — avaliadas por CORPO DE
     * FUNÇÃO, cada uma só conta como violação quando o mesmo corpo também
     * satisfaz escreveNaCompany().
     *
     * @return array{regex: string, motivo: string}|null
     */
    private function descreveViolacaoNoCorpo(string $corpo, bool $arquivoCitaCompany): ?array
    {
        // Regra B — escrita em offset de array: $dados['etapa'] = ...
        if (preg_match(self::REGEX_ARRAY_OFFSET, $corpo) === 1
            && $this->escreveNaCompany($corpo, $arquivoCitaCompany)
        ) {
            return [
                'regex'  => self::REGEX_ARRAY_OFFSET,
                'motivo' => "[Regra B] escrita por offset \$var['etapa'] = ... dentro de um corpo que também grava no model Company",
            ];
        }

        // Regra C — chave literal de array: 'etapa' => / "etapa" => ...
        if (preg_match(self::REGEX_CHAVE_ARRAY, $corpo) === 1
            && $this->escreveNaCompany($corpo, $arquivoCitaCompany)
        ) {
            return [
                'regex'  => self::REGEX_CHAVE_ARRAY,
                'motivo' => "[Regra C] chave 'etapa' => dentro de uma escrita no model Company",
            ];
        }

        return null;
    }

    /**
     * Detector ampliado (150-08-PLAN.md, item 6) — verdadeiro quando
     * QUALQUER uma das 4 formas de escrita no model Company aparece.
     * O guard (c) é avaliado no ARQUIVO, não no corpo — ver docblock do
     * Grupo 2 para o motivo (corposDeFuncao() descarta a assinatura da
     * função, então o nome/tipo do parâmetro `Company $x` some do corpo).
     */
    private function escreveNaCompany(string $corpo, bool $arquivoCitaCompany): bool
    {
        // (a) Escrita estática de criação.
        if (preg_match('/Company::(create|updateOrCreate|firstOrCreate|forceCreate)\s*\(/', $corpo) === 1) {
            return true;
        }

        // (b) DB::table('companies')->...->update([...]) — Bypass 3, sem
        // passar pelo Eloquent e sem nome de variável de instância nenhum.
        if (preg_match('/DB::table\(\s*([\'"])companies\1\s*\)/', $corpo) === 1) {
            return true;
        }

        // (c) Corpo grava por ->update/->fill/->forceFill/->save E o
        // ARQUIVO (não o corpo) cita Company — sem nome de variável
        // hardcoded, fecha o Bypass 1 combinado com Regra B/C.
        if ($arquivoCitaCompany && preg_match('/->(update|fill|forceFill|save)\s*\(/', $corpo) === 1) {
            return true;
        }

        // (d) Company::algumMetodo(...)->update(...) — regra original do
        // plano 150-06, mantida (cobre
        // EtapaTransicaoService::carimbarBackfill()).
        if (preg_match('/Company::\w+\s*\(/', $corpo) === 1 && preg_match('/->update\s*\(/', $corpo) === 1) {
            return true;
        }

        return false;
    }

    /**
     * Caminho relativo a base_path(), sempre com barra `/` — usado para
     * checar EXCECOES_REGRA_A independente do separador do SO (este
     * worktree roda em Windows).
     */
    private function caminhoRelativo(string $caminhoAbsoluto): string
    {
        $base = rtrim(str_replace('\\', '/', base_path()), '/') . '/';
        $abs  = str_replace('\\', '/', $caminhoAbsoluto);

        return str_starts_with($abs, $base) ? substr($abs, strlen($base)) : $abs;
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
