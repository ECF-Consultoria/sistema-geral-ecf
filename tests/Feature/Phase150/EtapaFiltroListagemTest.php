<?php

namespace Tests\Feature\Phase150;

use App\Models\Company;
use App\Models\ContratoServico;
use App\Models\Servico;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Fase 150 (plano 07, ETAPA-05) — prova ponta a ponta dos dois filtros
 * server-side independentes da aba "Empresas" de `/companies`: por etapa
 * (com `sem_etapa` de primeira classe, D-22) e por pendência (D-23),
 * combináveis sem que um apague o outro, sem alterar a visão padrão sem
 * filtro (Success Criteria nº 1) e ignorando valor inválido em silêncio
 * (T-150-18/T-150-19).
 *
 * Molde de fixture: tests/Feature/Phase37CompaniesPerformanceFilterTest.php
 * / tests/Feature/Phase150/EtapaBackfillTest.php — helpers idênticos,
 * adaptados para criar empresas VISÍVEIS na listagem (exige contrato
 * Performance ativo, o `whereHas('contratosServico', ...)` do
 * `CompanyController::index()`).
 */
class EtapaFiltroListagemTest extends TestCase
{
    use RefreshDatabase;

    // ─── Helpers (molde Phase37CompaniesPerformanceFilterTest) ───────────────

    private function actingAsAdmin(): User
    {
        $admin = User::create([
            'name'     => 'Admin Phase150-07 ' . uniqid(),
            'email'    => 'admin.p137-07.' . uniqid() . '@ecf.test',
            'password' => bcrypt('senha'),
            'role'     => 'admin',
            'active'   => true,
        ]);
        $this->actingAs($admin);

        return $admin;
    }

    private function criarServico(): Servico
    {
        return Servico::create([
            'nome'          => 'Gestao ' . uniqid(),
            'valor_padrao'  => 1500.0,
            'tipo_cobranca' => Servico::TIPO_MENSAL,
            'ativo'         => true,
            'setor'         => Servico::SETOR_PERFORMANCE,
        ]);
    }

    private function criarEmpresa(array $overrides = []): Company
    {
        return Company::create(array_merge([
            'name'               => 'Empresa P137-07 ' . uniqid(),
            'cnpj'               => substr(str_pad((string) random_int(1, 99999999999999), 14, '0', STR_PAD_LEFT), 0, 14),
            'active'             => true,
            'status'             => 'ativo',
            'email_colaborador'  => 'colab.' . uniqid() . '@ecf.test',
            'adman_account_id'   => (string) random_int(100000, 999999),
            'empresa_nova'       => false,
        ], $overrides));
    }

    private function criarContrato(Company $c, Servico $s): ContratoServico
    {
        return ContratoServico::create([
            'company_id'       => $c->id,
            'servico_id'       => $s->id,
            'valor_contratado' => 1500,
            'data_contratacao' => now()->toDateString(),
            'ativo'            => true,
        ]);
    }

    /** Empresa visível em /companies: exige contrato Performance ativo (whereHas do controller). */
    private function criarEmpresaVisivel(array $overrides = []): Company
    {
        $empresa = $this->criarEmpresa($overrides);
        $this->criarContrato($empresa, $this->criarServico());

        return $empresa;
    }

    private function payloadCompanies($response): \Illuminate\Support\Collection
    {
        return collect($response->viewData('page')['props']['companies']);
    }

    private function filtersProp($response): array
    {
        return $response->viewData('page')['props']['filters'];
    }

    // ═════════════════════════════════════════════════════════════════════════
    // 1. ?etapa=em_operacao — só empresas com essa etapa, exclui as demais
    // ═════════════════════════════════════════════════════════════════════════

    public function test_filtro_etapa_concreta_traz_so_empresas_daquela_etapa(): void
    {
        $this->actingAsAdmin();

        $emOperacao = $this->criarEmpresaVisivel(['etapa' => Company::ETAPA_EM_OPERACAO]);
        $outraEtapa = $this->criarEmpresaVisivel(['etapa' => Company::ETAPA_AGUARDANDO_ADMINISTRATIVO]);
        $semEtapa   = $this->criarEmpresaVisivel(['etapa' => null]);

        $response = $this->get('/companies?etapa=' . Company::ETAPA_EM_OPERACAO);
        $ids = $this->payloadCompanies($response)->pluck('id')->all();

        $this->assertContains($emOperacao->id, $ids);
        $this->assertNotContains($outraEtapa->id, $ids);
        $this->assertNotContains($semEtapa->id, $ids);
    }

    // ═════════════════════════════════════════════════════════════════════════
    // 2. ?etapa=sem_etapa — cobre o legado majoritário pós-backfill (D-22)
    // ═════════════════════════════════════════════════════════════════════════

    public function test_filtro_sem_etapa_traz_so_empresas_com_etapa_null(): void
    {
        $this->actingAsAdmin();

        $semEtapa = $this->criarEmpresaVisivel(['etapa' => null]);
        $comEtapa = $this->criarEmpresaVisivel(['etapa' => Company::ETAPA_EM_OPERACAO]);

        $response = $this->get('/companies?etapa=sem_etapa');
        $ids = $this->payloadCompanies($response)->pluck('id')->all();

        $this->assertContains($semEtapa->id, $ids);
        $this->assertNotContains($comEtapa->id, $ids);
    }

    // ═════════════════════════════════════════════════════════════════════════
    // 3. ?etapa=valor_invalido — fallback silencioso, mesmo padrão de
    //    cust_id_status inválido já testado em Phase37CompaniesPerformanceFilterTest
    // ═════════════════════════════════════════════════════════════════════════

    public function test_filtro_etapa_invalida_devolve_mesmo_payload_que_sem_filtro(): void
    {
        $this->actingAsAdmin();

        $a = $this->criarEmpresaVisivel(['etapa' => Company::ETAPA_EM_OPERACAO]);
        $b = $this->criarEmpresaVisivel(['etapa' => null]);

        $semFiltro   = $this->get('/companies');
        $comInvalido = $this->get('/companies?etapa=valor_que_nao_existe');

        $ordenar = fn($resp) => $this->payloadCompanies($resp)
            ->sortBy('id')
            ->values()
            ->all();

        $this->assertSame($ordenar($semFiltro), $ordenar($comInvalido),
            'Valor de etapa fora do domínio (Company::ETAPAS + sem_etapa) deveria ser ignorado em silêncio, '
                . 'devolvendo exatamente o mesmo payload de /companies sem filtro.');

        $idsInvalido = collect($ordenar($comInvalido))->pluck('id')->all();
        $this->assertContains($a->id, $idsInvalido);
        $this->assertContains($b->id, $idsInvalido);
    }

    // ═════════════════════════════════════════════════════════════════════════
    // 4. ?com_pendencia=1 — independente da etapa (D-23), incluindo etapa NULL
    // ═════════════════════════════════════════════════════════════════════════

    public function test_filtro_com_pendencia_traz_so_empresas_com_pendencia_aberta_independente_da_etapa(): void
    {
        $admin = $this->actingAsAdmin();

        $pendenteSemEtapa = $this->criarEmpresaVisivel(['etapa' => null]);
        $pendenteSemEtapa->declararPendencia('Contrato não assinado', $admin);

        $pendenteComEtapa = $this->criarEmpresaVisivel(['etapa' => Company::ETAPA_EM_OPERACAO]);
        $pendenteComEtapa->declararPendencia('Falta grant', $admin);

        $semPendencia = $this->criarEmpresaVisivel(['etapa' => Company::ETAPA_EM_OPERACAO]);

        $response = $this->get('/companies?com_pendencia=1');
        $ids = $this->payloadCompanies($response)->pluck('id')->all();

        $this->assertContains($pendenteSemEtapa->id, $ids,
            'Empresa com pendência e etapa NULL precisa aparecer no filtro com_pendencia=1.');
        $this->assertContains($pendenteComEtapa->id, $ids,
            'Empresa com pendência e etapa concreta precisa aparecer no filtro com_pendencia=1.');
        $this->assertNotContains($semPendencia->id, $ids);
    }

    // ═════════════════════════════════════════════════════════════════════════
    // 5. Combinado ?etapa=sem_etapa&com_pendencia=1 — intersecção (D-23)
    // ═════════════════════════════════════════════════════════════════════════

    public function test_filtros_combinados_intersectam_etapa_e_pendencia(): void
    {
        $admin = $this->actingAsAdmin();

        $alvo = $this->criarEmpresaVisivel(['etapa' => null]);
        $alvo->declararPendencia('Contrato não assinado', $admin);

        // Mesma pendência, mas COM etapa — fora da intersecção com sem_etapa.
        $pendenteComEtapa = $this->criarEmpresaVisivel(['etapa' => Company::ETAPA_EM_OPERACAO]);
        $pendenteComEtapa->declararPendencia('Falta grant', $admin);

        // Sem etapa, mas SEM pendência — fora da intersecção com com_pendencia.
        $semEtapaSemPendencia = $this->criarEmpresaVisivel(['etapa' => null]);

        $response = $this->get('/companies?etapa=sem_etapa&com_pendencia=1');
        $ids = $this->payloadCompanies($response)->pluck('id')->all();

        $this->assertContains($alvo->id, $ids);
        $this->assertNotContains($pendenteComEtapa->id, $ids,
            'Filtro combinado deveria excluir empresa com pendência mas COM etapa.');
        $this->assertNotContains($semEtapaSemPendencia->id, $ids,
            'Filtro combinado deveria excluir empresa sem etapa mas SEM pendência.');
    }

    // ═════════════════════════════════════════════════════════════════════════
    // 6. Sem filtro nenhum — visão padrão idêntica à de antes da fase (SC nº 1)
    // ═════════════════════════════════════════════════════════════════════════

    public function test_sem_filtro_a_visao_padrao_continua_sem_filtro_nenhum(): void
    {
        $admin = $this->actingAsAdmin();

        $a = $this->criarEmpresaVisivel(['etapa' => Company::ETAPA_EM_OPERACAO]);
        $b = $this->criarEmpresaVisivel(['etapa' => null]);
        $c = $this->criarEmpresaVisivel(['etapa' => Company::ETAPA_AGUARDANDO_ADMINISTRATIVO]);
        $d = $this->criarEmpresaVisivel(['etapa' => null]);
        $d->declararPendencia('Contrato não assinado', $admin);

        $response = $this->get('/companies');
        $ids = $this->payloadCompanies($response)->pluck('id')->all();

        // D-22: sem tocar em nenhum dos dois filtros, nenhuma empresa some —
        // nem por etapa, nem por pendência.
        foreach ([$a, $b, $c, $d] as $empresa) {
            $this->assertContains($empresa->id, $ids);
        }
    }

    // ═════════════════════════════════════════════════════════════════════════
    // 7. Prop `filters` reflete etapa/com_pendencia aplicados (e o default)
    // ═════════════════════════════════════════════════════════════════════════

    public function test_prop_filters_reflete_etapa_e_com_pendencia_aplicados(): void
    {
        $this->actingAsAdmin();

        $response = $this->get('/companies?etapa=sem_etapa&com_pendencia=1');
        $filters = $this->filtersProp($response);

        $this->assertSame('sem_etapa', $filters['etapa']);
        $this->assertTrue($filters['com_pendencia']);
    }

    public function test_prop_filters_sem_query_param_devolve_etapa_null_e_com_pendencia_false(): void
    {
        $this->actingAsAdmin();

        $response = $this->get('/companies');
        $filters = $this->filtersProp($response);

        $this->assertNull($filters['etapa']);
        $this->assertFalse($filters['com_pendencia']);
    }

    public function test_prop_filters_com_etapa_invalida_devolve_null(): void
    {
        $this->actingAsAdmin();

        $response = $this->get('/companies?etapa=valor_que_nao_existe');
        $filters = $this->filtersProp($response);

        $this->assertNull($filters['etapa'],
            'Etapa fora do domínio deve virar null também na prop filters (fallback silencioso).');
    }

    // ═════════════════════════════════════════════════════════════════════════
    // 8. Combinação TRIPLA — cust_id_status + etapa + com_pendencia (gap
    //    closure WR-03, plano 150-11). O backend já suportava os três
    //    combinados desde o 150-07; esta é a prova que faltava com
    //    cust_id_status na mistura, o filtro que os quatro handlers do
    //    cliente apagavam em silêncio antes desta correção.
    // ═════════════════════════════════════════════════════════════════════════

    public function test_filtros_triplos_combinados_intersectam_cust_id_etapa_e_pendencia(): void
    {
        $admin = $this->actingAsAdmin();

        // Satisfaz os três critérios — único que deve sobrar.
        $alvo = $this->criarEmpresaVisivel(['etapa' => null, 'cust_id_status' => 'invalido']);
        $alvo->declararPendencia('Contrato não assinado', $admin);

        // Cust ID e pendência batem, mas a etapa NÃO é sem_etapa.
        $forceadaEtapaErrada = $this->criarEmpresaVisivel(['etapa' => Company::ETAPA_EM_OPERACAO, 'cust_id_status' => 'invalido']);
        $forceadaEtapaErrada->declararPendencia('Falta grant', $admin);

        // Etapa e pendência batem, mas cust_id_status é outro valor do domínio.
        $custIdDivergente = $this->criarEmpresaVisivel(['etapa' => null, 'cust_id_status' => 'ok']);
        $custIdDivergente->declararPendencia('Falta documento', $admin);

        // Cust ID e etapa batem, mas SEM pendência.
        $semPendencia = $this->criarEmpresaVisivel(['etapa' => null, 'cust_id_status' => 'invalido']);

        $response = $this->get('/companies?cust_id_status=invalido&etapa=sem_etapa&com_pendencia=1');
        $ids = $this->payloadCompanies($response)->pluck('id')->all();

        $this->assertContains($alvo->id, $ids);
        $this->assertNotContains($forceadaEtapaErrada->id, $ids,
            'Filtro triplo deveria excluir empresa com etapa fora de sem_etapa mesmo com cust_id_status/pendência batendo.');
        $this->assertNotContains($custIdDivergente->id, $ids,
            'Filtro triplo deveria excluir empresa com cust_id_status divergente mesmo com etapa/pendência batendo.');
        $this->assertNotContains($semPendencia->id, $ids,
            'Filtro triplo deveria excluir empresa sem pendência mesmo com cust_id_status/etapa batendo.');
    }

    public function test_prop_filters_reflete_os_tres_valores_aplicados_simultaneamente(): void
    {
        $this->actingAsAdmin();

        $response = $this->get('/companies?cust_id_status=invalido&etapa=sem_etapa&com_pendencia=1');
        $filters = $this->filtersProp($response);

        $this->assertSame('invalido', $filters['cust_id_status']);
        $this->assertSame('sem_etapa', $filters['etapa']);
        $this->assertTrue($filters['com_pendencia']);
    }

    // ═════════════════════════════════════════════════════════════════════════
    // 9. Gate estático (gap closure WR-03, plano 150-11) — trava a
    //    centralização do cliente contra reintrodução. Este gate prova a
    //    FORMA do arquivo (que existe um único montador de query e que os
    //    quatro handlers delegam a ele), não o comportamento no navegador —
    //    esse é o checkpoint humano da Task 3. Ele existe porque o
    //    comentário do próprio código-fonte já tinha afirmado a preservação
    //    entre filtros (D-23) e estava errado para o par etapa/cust_id_status
    //    e para aplicarSort — este gate é a versão executável dessa
    //    afirmação, para que ela nunca mais divirja do código sem que a
    //    suíte perceba.
    // ═════════════════════════════════════════════════════════════════════════

    public function test_gate_index_jsx_tem_um_unico_montador_de_query_e_os_quatro_handlers_delegam_a_ele(): void
    {
        $caminho = base_path('resources/js/Pages/Companies/Index.jsx');
        $conteudo = File::get($caminho);

        // (a) No máximo um único ponto no arquivo chama router.get contra a
        // rota companies.index — hoje eram 4 (um por handler), WR-03.
        $chamadasRouterGet = preg_match_all("/router\.get\(route\('companies\.index'\)/", $conteudo);
        $this->assertLessThanOrEqual(1, $chamadasRouterGet,
            "WR-03 reintroduzido: {$chamadasRouterGet} handler(s) de Index.jsx voltaram a chamar router.get(route('companies.index')) "
                . 'por conta própria — escolher um filtro vai apagar os outros em silêncio outra vez. '
                . "Centralize num único montador (ex: 'aplicarFiltros') e faça os handlers delegarem a ele.");

        // (b) Cada um dos quatro handlers de filtro precisa delegar ao
        // montador único — nenhum pode voltar a montar `params` por conta
        // própria dentro do próprio corpo.
        $handlers = ['aplicarCustIdFilter', 'aplicarSort', 'aplicarEtapaFilter', 'aplicarComPendenciaFilter'];
        foreach ($handlers as $handler) {
            $inicio = strpos($conteudo, "const {$handler} = ");
            $this->assertNotFalse($inicio, "WR-03: handler '{$handler}' não foi encontrado em Index.jsx.");

            // Corpo do handler: da declaração até o próximo `const ` no mesmo
            // nível de indentação (molde simplificado, suficiente para um
            // arrow function de poucas linhas como estes quatro).
            $fimDoCorpo = strpos($conteudo, "\n    const ", $inicio + 10);
            $corpo = $fimDoCorpo !== false
                ? substr($conteudo, $inicio, $fimDoCorpo - $inicio)
                : substr($conteudo, $inicio, 400);

            $this->assertStringContainsString('aplicarFiltros(', $corpo,
                "WR-03 reintroduzido: '{$handler}' deixou de delegar a 'aplicarFiltros()' e voltou a montar "
                    . 'params por conta própria — escolher esse filtro vai apagar os outros em silêncio.');
            $this->assertStringNotContainsString("router.get(", $corpo,
                "WR-03 reintroduzido: '{$handler}' chama router.get() diretamente em vez de delegar ao montador único.");
        }
    }
}
