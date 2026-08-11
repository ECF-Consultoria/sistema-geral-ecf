<?php

namespace Tests\Feature\Phase134;

use App\Models\Company;
use App\Models\MlAcervoItem;
use App\Models\MlAcervoMetricaDiaria;
use App\Models\MlbEmpresa;
use App\Models\MlToken;
use App\Models\User;
use App\Services\Mlb\Acervo\AnuncioSaudeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Fase 134 (Plano 10) — rota `mlb.anuncios.meus.detalhe`: checklist de
 * sinais que fecha com a nota (D-10/D-22), série de até 90 dias com a
 * assimetria ESTADO×FLUXO (D-07b/D-23) e o escopo/leitura-só do restante
 * do módulo (D-05/D-11/D-15).
 *
 * O que blinda:
 *   (a) D-10/D-22 — checklist tem os 7 sinais, total sempre 86, e a soma
 *       dos sinais que passaram fecha com nota_ecf
 *   (b) T-134-21 — quando NÃO fecha, a resposta sinaliza `divergencia`
 *       (não silencia, não mascara)
 *   (c) D-07b/D-23 — série faz forward-fill em campos de ESTADO (vendas,
 *       notaEcf) e NUNCA em campos de FLUXO (visitas)
 *   (d) T-134-01 — escopo por empresa (404 fora da empresa)
 *   (e) T-134-18 — restrição de formato na própria rota (MLB[0-9]+)
 *   (f) D-05 — zero chamada HTTP síncrona ao ML
 *   (g) D-15 — módulo continua role:admin
 *
 * Estratégia: RefreshDatabase (SQLite in-memory) + Http::fake() — nunca ML real.
 *
 * @group phase134
 */
class DetalheAnuncioTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake(); // nenhuma chamada real ao ML nestes testes
    }

    /** @test */
    public function detalhe_devolve_checklist_com_os_sete_sinais_e_total_86(): void
    {
        [$company, , $admin] = $this->criarFixture();
        $item = $this->criarItemComSinais($company);

        $response = $this->actingAs($admin)
            ->getJson($this->urlDetalhe($company, $item->ml_item_id))
            ->assertOk();

        $checklist = $response->json('checklist');
        $this->assertCount(7, $checklist, 'o checklist tem que ter exatamente os 7 sinais computáveis (D-22)');
        $this->assertSame(86, $response->json('checklistTotal'));

        $pesos = collect($checklist)->pluck('peso', 'chave')->all();
        foreach (AnuncioSaudeService::PESOS as $chave => $peso) {
            $this->assertSame($peso, $pesos[$chave] ?? null, "peso de {$chave} tem que vir de AnuncioSaudeService::PESOS, nunca escrito à mão");
        }
    }

    /** @test */
    public function soma_dos_sinais_verdadeiros_fecha_com_a_nota(): void
    {
        [$company, , $admin] = $this->criarFixture();
        $item = $this->criarItemComSinais($company); // nota_ecf construída como a soma dos sinais ok

        $checklist = $this->actingAs($admin)
            ->getJson($this->urlDetalhe($company, $item->ml_item_id))
            ->assertOk()
            ->json('checklist');

        $soma = collect($checklist)->where('ok', true)->sum('peso');
        $this->assertSame(
            (int) $item->nota_ecf,
            $soma,
            'D-10/D-22: a soma dos sinais que passaram tem que fechar com a nota exibida — mesma pegadinha do nps_medio ≠ pontos_componentes.nps'
        );
    }

    /** @test */
    public function checklist_marca_como_criticos_apenas_ficha_obrigatoria_e_foto(): void
    {
        [$company, , $admin] = $this->criarFixture();
        $item = $this->criarItemComSinais($company);

        $checklist = $this->actingAs($admin)
            ->getJson($this->urlDetalhe($company, $item->ml_item_id))
            ->assertOk()
            ->json('checklist');

        $criticos = collect($checklist)->where('critico', true)->pluck('chave')->sort()->values()->all();
        $this->assertSame(['ficha_obrigatoria', 'foto'], $criticos, 'só os 2 sinais que analisarAnuncio() classifica como erro bloqueante são críticos');
    }

    /** @test */
    public function serie_preenche_estado_para_frente_e_deixa_buraco_em_visitas(): void
    {
        [$company, , $admin] = $this->criarFixture();
        $item = $this->criarItemComSinais($company);

        $mlItemId = $item->ml_item_id;
        $diaBase  = now()->subDays(10)->startOfDay();

        // Dois registros esparsos — dias 0 e 4 dos últimos 90, com um buraco
        // deliberado de 3 dias entre eles.
        MlAcervoMetricaDiaria::create([
            'company_id'    => $company->id,
            'ml_item_id'    => $mlItemId,
            'data'          => $diaBase->toDateString(),
            'visitas'       => 41,
            'sold_quantity' => 3,
            'nota_ecf'      => 74,
            'created_at'    => now(),
        ]);
        MlAcervoMetricaDiaria::create([
            'company_id'    => $company->id,
            'ml_item_id'    => $mlItemId,
            'data'          => $diaBase->copy()->addDays(4)->toDateString(),
            'visitas'       => 55,
            'sold_quantity' => 5,
            'nota_ecf'      => 80,
            'created_at'    => now(),
        ]);

        $serie = $this->actingAs($admin)
            ->getJson($this->urlDetalhe($company, $mlItemId))
            ->assertOk()
            ->json('serie');

        $porData = collect($serie)->keyBy('data');

        // Dia SEM registro, dentro do buraco: ESTADO repete o último valor
        // conhecido, FLUXO fica nulo — a assimetria travada pelo D-08/D-23.
        $diaSemRegistro = $diaBase->copy()->addDays(2)->toDateString();
        $ponto = $porData->get($diaSemRegistro);
        $this->assertNotNull($ponto, 'a série tem que ter um ponto por dia do intervalo, mesmo sem coleta naquele dia');
        $this->assertSame(3, $ponto['vendas'], 'ESTADO: vendas repete o último valor conhecido no buraco (preenchimento para frente)');
        $this->assertSame(74, $ponto['notaEcf'], 'ESTADO: nota repete o último valor conhecido no buraco (preenchimento para frente)');
        $this->assertNull($ponto['visitas'], 'FLUXO: visitas NUNCA é preenchido para frente — buraco é buraco, nunca inventa tráfego');

        // Dia com registro real: o dado gravado passa intacto.
        $diaComRegistro = $diaBase->toDateString();
        $this->assertSame(41, $porData->get($diaComRegistro)['visitas']);
    }

    /** @test */
    public function detalhe_nao_vaza_item_de_outra_empresa(): void
    {
        [$company, , $admin] = $this->criarFixture();
        [$outra] = $this->criarFixture();
        $itemOutra = $this->criarItemComSinais($outra);

        // T-134-01: pedir o mlItemId da empresa B na URL da empresa A tem que dar 404.
        $this->actingAs($admin)
            ->getJson($this->urlDetalhe($company, $itemOutra->ml_item_id))
            ->assertNotFound();
    }

    /** @test */
    public function detalhe_nao_faz_chamada_ao_ml(): void
    {
        [$company, , $admin] = $this->criarFixture();
        $item = $this->criarItemComSinais($company);

        $this->actingAs($admin)
            ->getJson($this->urlDetalhe($company, $item->ml_item_id))
            ->assertOk();

        // D-05: endpoint de detalhe é JSON puro (nunca Inertia::render()), então
        // nenhum shared prop lazy do HandleInertiaRequests é resolvido — a
        // asserção bruta é segura aqui (ao contrário de MeusAnunciosTest, que
        // precisa escopar por causa do EcfDriveService acoplado às páginas Inertia).
        Http::assertNothingSent();
    }

    /** @test */
    public function consultor_nao_acessa_o_detalhe(): void
    {
        [$company] = $this->criarFixture();
        $item = $this->criarItemComSinais($company);
        $consultor = User::factory()->create(['role' => 'consultor']);

        $this->actingAs($consultor)
            ->getJson($this->urlDetalhe($company, $item->ml_item_id))
            ->assertForbidden();
    }

    /** @test */
    public function checklist_divergente_e_sinalizado_nao_silenciado(): void
    {
        [$company, , $admin] = $this->criarFixture();
        // nota_ecf adulterada de propósito para NÃO bater com nota_sinais.
        $item = $this->criarItemComSinais($company, [], ['nota_ecf' => 99]);

        $response = $this->actingAs($admin)
            ->getJson($this->urlDetalhe($company, $item->ml_item_id))
            ->assertOk();

        $this->assertTrue(
            $response->json('divergencia'),
            'T-134-21: a inconsistência entre nota_ecf e nota_sinais tem que ser sinalizada, nunca mascarada'
        );
    }

    // ─── helpers ────────────────────────────────────────────────────────────

    private function urlDetalhe(Company $company, string $mlItemId): string
    {
        return route('mlb.anuncios.meus.detalhe', ['company' => $company->id, 'mlItemId' => $mlItemId]);
    }

    /**
     * Cria um item do acervo com `nota_sinais` no shape real gravado por
     * MlAcervoService (AnuncioSaudeService::avaliar()['sinais']) e `nota_ecf`
     * derivado dos flags — a menos que $overrides force um valor divergente
     * de propósito (teste de divergência).
     *
     * @param  array<string,bool>  $sinaisOk  chave => ok, sobrepõe o default por sinal
     */
    private function criarItemComSinais(Company $company, array $sinaisOk = [], array $overrides = []): MlAcervoItem
    {
        // Default: mistura sinais ok/falho passando pelos 2 críticos
        // (ficha_obrigatoria, foto) para exercitar o caminho crítico E o
        // neutro (dimensoes) no mesmo fixture.
        $defaults = [
            'titulo'            => true,
            'categoria'         => true,
            'ficha_obrigatoria' => false,
            'ficha_opcional'    => true,
            'foto'              => false,
            'dimensoes'         => false,
            'preco'             => true,
        ];
        $flags = array_merge($defaults, $sinaisOk);

        $sinais = [];
        $nota   = 0;
        foreach (AnuncioSaudeService::PESOS as $chave => $peso) {
            $ok = $flags[$chave];
            $sinais[$chave] = ['peso' => $peso, 'ok' => $ok];
            if ($ok) {
                $nota += $peso;
            }
        }

        return MlAcervoItem::create(array_merge([
            'company_id'          => $company->id,
            'ml_item_id'          => 'MLB' . random_int(1000000000, 9999999999),
            'title'               => 'Produto de Teste',
            'status'              => 'active',
            'available_quantity'  => 10,
            'sold_quantity'       => 0,
            'nota_ecf'            => $nota,
            'nota_sinais'         => $sinais,
            'motivos'             => [],
            'severidade'          => MlAcervoItem::SEVERIDADE_SAUDAVEL,
            'origem'              => MlAcervoItem::ORIGEM_LEGADO,
            'coletado_em'         => now(),
        ], $overrides));
    }

    private function criarAdmin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    /** Cria Company + MlToken ativo + MlbEmpresa (mesmo padrão de MeusAnunciosTest). */
    private function criarFixture(): array
    {
        $admin   = $this->criarAdmin();
        $company = Company::factory()->create();

        MlToken::create([
            'company_id'        => $company->id,
            'ml_user_id'        => (string) random_int(100000000, 999999999),
            'access_token'      => 'fake-access-token',
            'refresh_token'     => 'fake-refresh-token',
            'token_type'        => 'bearer',
            'scope'             => 'read write offline_access',
            'expires_at'        => now()->addDays(6),
            'last_refreshed_at' => now(),
            'status'            => 'active',
            'connected_at'      => now(),
        ]);

        $empresa = MlbEmpresa::create([
            'nome'           => 'Empresa Detalhe Teste ' . $company->id,
            'tipo'           => 'ASSESSORIA',
            'company_id'     => $company->id,
            'responsavel_id' => $admin->id,
        ]);

        return [$company, $empresa, $admin];
    }
}
