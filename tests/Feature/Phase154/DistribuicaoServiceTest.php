<?php

namespace Tests\Feature\Phase154;

use App\Models\Cargo;
use App\Models\Company;
use App\Models\CompanyEtapaTransicao;
use App\Models\ContratoServico;
use App\Models\Servico;
use App\Models\Setor;
use App\Models\User;
use App\Services\FluxoEntrada\DistribuicaoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Fase 154 (DISTRIB-01..04) — a fila da Coordenação e o ato de distribuir.
 *
 * Toda asserção de etapa e de vínculo por RECONSULTA ao banco.
 */
class DistribuicaoServiceTest extends TestCase
{
    use RefreshDatabase;

    private static int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        Queue::fake();
    }

    // ─── Fixtures ───────────────────────────────────────────────────────────

    /**
     * `firstOrCreate` e não `create`: a migration
     * `2026_07_27_140001_seed_setor_desenvolvimento_cargo_dev` já cria o setor
     * `desenvolvimento`, e RefreshDatabase roda as migrations. Fixture que
     * assume banco vazio quebra com "UNIQUE constraint failed: setores.slug"
     * numa falha que não tem nada a ver com o que o teste mede.
     */
    private function setor(string $slug, string $nome): Setor
    {
        return Setor::firstOrCreate(['slug' => $slug], ['nome' => $nome, 'active' => true]);
    }

    private function cargo(Setor $setor, string $slug): Cargo
    {
        return Cargo::firstOrCreate(
            ['setor_id' => $setor->id, 'slug' => $slug],
            ['nome' => ucfirst($slug)]
        );
    }

    /** User ativo com o cargo pedido dentro do setor. */
    private function colaborador(Cargo $cargo, string $nome): User
    {
        $u = User::factory()->create(['role' => 'consultor', 'name' => $nome, 'active' => true]);

        DB::table('user_setores')->insert([
            'user_id'      => $u->id,
            'setor_id'     => $cargo->setor_id,
            'cargo_id'     => $cargo->id,
            'is_principal' => true,
            'assigned_at'  => now()->toDateString(),
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);

        return $u;
    }

    private function servico(string $nome, string $setorSlug): Servico
    {
        return Servico::create([
            'nome'           => $nome,
            'valor_padrao'   => 100,
            'tipo_cobranca'  => Servico::TIPO_MENSAL,
            'ativo'          => true,
            'setor'          => $setorSlug,
            'exige_contrato' => true,
        ]);
    }

    private function empresa(array $overrides = []): Company
    {
        $n = str_pad((string) (++self::$seq), 4, '0', STR_PAD_LEFT);

        return Company::factory()->create(array_merge([
            'active' => true,
            'name'   => 'Empresa Distrib '.$n,
            'cnpj'   => "17.417.417/{$n}-41",
            'etapa'  => Company::ETAPA_AGUARDANDO_DISTRIBUICAO,
        ], $overrides));
    }

    private function vincularServico(Company $c, Servico $s): ContratoServico
    {
        return ContratoServico::withoutEvents(fn () => ContratoServico::create([
            'company_id'            => $c->id,
            'servico_id'            => $s->id,
            'valor_contratado'      => 100,
            'data_contratacao'      => now()->toDateString(),
            'data_primeira_parcela' => now()->addMonth()->toDateString(),
            'dia_vencimento'        => 10,
            'ativo'                 => true,
        ]));
    }

    private function svc(): DistribuicaoService
    {
        return app(DistribuicaoService::class);
    }

    private function coordenador(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    // ─── DISTRIB-01 — a fila ────────────────────────────────────────────────

    public function test_fila_lista_so_empresa_na_etapa_5_sem_responsaveis(): void
    {
        $setor   = $this->setor('publicacao', 'Publicação');
        $servico = $this->servico('Publicação 154', 'publicacao');

        $naFila = $this->empresa(['name' => 'AAA Na fila']);
        $this->vincularServico($naFila, $servico);

        // Etapa anterior — não entra.
        $cedo = $this->empresa(['name' => 'BBB Cedo demais', 'etapa' => Company::ETAPA_ADMINISTRATIVO_CONCLUIDO]);
        $this->vincularServico($cedo, $servico);

        // Etapa posterior — já saiu.
        $tarde = $this->empresa(['name' => 'CCC Ja distribuida', 'etapa' => Company::ETAPA_AGUARDANDO_ONBOARDING]);
        $this->vincularServico($tarde, $servico);

        // Na etapa 5 mas JÁ com analista — não entra.
        $comResp = $this->empresa(['name' => 'DDD Ja tem analista']);
        $this->vincularServico($comResp, $servico);
        $analista = $this->colaborador($this->cargo($setor, 'analista'), 'Analista Um');
        DB::table('company_users')->insert([
            'company_id' => $comResp->id, 'user_id' => $analista->id,
            'role' => 'analista', 'servico_id' => $servico->id,
            'assigned_at' => now()->toDateString(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $fila = $this->svc()->fila()->pluck('name')->all();

        $this->assertSame(['AAA Na fila'], $fila);
    }

    public function test_empresa_inativa_nao_entra_na_fila(): void
    {
        $servico = $this->servico('Publicação 154', 'publicacao');
        $inativa = $this->empresa(['active' => false]);
        $this->vincularServico($inativa, $servico);

        $this->assertCount(0, $this->svc()->fila());
    }

    // ─── DISTRIB-02 — elegibilidade ─────────────────────────────────────────

    public function test_elegiveis_filtra_pelo_setor_do_servico_quando_ha_gente_la(): void
    {
        $setorPub = $this->setor('publicacao', 'Publicação');
        $setorOut = $this->setor('shopee', 'Shopee');

        $doSetor  = $this->colaborador($this->cargo($setorPub, 'analista'), 'Analista Do Setor');
        $deFora   = $this->colaborador($this->cargo($setorOut, 'analista'), 'Analista De Fora');
        $this->colaborador($this->cargo($setorPub, 'estrategista'), 'Estrategista Do Setor');

        $empresa = $this->empresa();
        $this->vincularServico($empresa, $this->servico('Publicação 154', 'publicacao'));

        $r = $this->svc()->elegiveis($empresa->fresh());

        $ids = array_column($r['analistas'], 'id');
        $this->assertContains($doSetor->id, $ids);
        $this->assertNotContains($deFora->id, $ids, 'analista de outro setor não pode aparecer quando o setor do serviço tem gente.');
        $this->assertNull($r['motivo_abertura'], 'com gente no setor, a lista não foi aberta.');
    }

    /**
     * O caso REAL medido no banco: `servicos.setor = 'performance'` não tem
     * linha correspondente em `setores`. Filtro estrito devolveria lista vazia.
     */
    public function test_setor_do_servico_inexistente_abre_a_lista_e_diz_por_que(): void
    {
        $setorDev = $this->setor('desenvolvimento', 'Desenvolvimento');
        $analista = $this->colaborador($this->cargo($setorDev, 'analista'), 'Analista Dev');
        $estrat   = $this->colaborador($this->cargo($setorDev, 'estrategista'), 'Estrategista Dev');

        $empresa = $this->empresa();
        // 'performance' não tem setor correspondente — é o caso real.
        $this->vincularServico($empresa, $this->servico('Gestão 154', 'performance'));

        $r = $this->svc()->elegiveis($empresa->fresh());

        $this->assertNotNull($r['motivo_abertura'], 'a tela precisa dizer por que está mostrando todos.');
        $this->assertStringContainsString('setor', $r['motivo_abertura']);
        $this->assertContains($analista->id, array_column($r['analistas'], 'id'));
        $this->assertContains($estrat->id, array_column($r['estrategistas'], 'id'));
    }

    public function test_setor_existe_mas_sem_ninguem_com_o_cargo_tambem_abre(): void
    {
        $setorPub = $this->setor('publicacao', 'Publicação');
        $this->cargo($setorPub, 'analista'); // cargo existe, ninguém atribuído

        $setorDev = $this->setor('desenvolvimento', 'Desenvolvimento');
        $analista = $this->colaborador($this->cargo($setorDev, 'analista'), 'Analista Dev');
        $this->colaborador($this->cargo($setorDev, 'estrategista'), 'Estrategista Dev');

        $empresa = $this->empresa();
        $this->vincularServico($empresa, $this->servico('Publicação 154', 'publicacao'));

        $r = $this->svc()->elegiveis($empresa->fresh());

        $this->assertNotNull($r['motivo_abertura']);
        $this->assertContains($analista->id, array_column($r['analistas'], 'id'));
    }

    public function test_colaborador_inativo_nunca_aparece(): void
    {
        $setor = $this->setor('publicacao', 'Publicação');
        $cargo = $this->cargo($setor, 'analista');
        $ativo   = $this->colaborador($cargo, 'Analista Ativo');
        $inativo = $this->colaborador($cargo, 'Analista Inativo');
        $inativo->update(['active' => false]);
        $this->colaborador($this->cargo($setor, 'estrategista'), 'Estrategista');

        $empresa = $this->empresa();
        $this->vincularServico($empresa, $this->servico('Publicação 154', 'publicacao'));

        $ids = array_column($this->svc()->elegiveis($empresa->fresh())['analistas'], 'id');

        $this->assertContains($ativo->id, $ids);
        $this->assertNotContains($inativo->id, $ids);
    }

    public function test_quem_nao_tem_o_cargo_nunca_aparece(): void
    {
        $setor = $this->setor('publicacao', 'Publicação');
        $this->colaborador($this->cargo($setor, 'analista'), 'Analista');
        $this->colaborador($this->cargo($setor, 'estrategista'), 'Estrategista');
        $semCargo = User::factory()->create(['role' => 'consultor', 'active' => true]);

        $empresa = $this->empresa();
        $this->vincularServico($empresa, $this->servico('Publicação 154', 'publicacao'));

        $r = $this->svc()->elegiveis($empresa->fresh());

        $this->assertNotContains($semCargo->id, array_column($r['analistas'], 'id'));
        $this->assertNotContains($semCargo->id, array_column($r['estrategistas'], 'id'));
    }

    // ─── DISTRIB-03/04 — o ato de distribuir ────────────────────────────────

    public function test_distribuir_grava_os_dois_vinculos_por_servico_e_move_para_etapa_6(): void
    {
        $setor = $this->setor('publicacao', 'Publicação');
        $analista = $this->colaborador($this->cargo($setor, 'analista'), 'Analista');
        $estrat   = $this->colaborador($this->cargo($setor, 'estrategista'), 'Estrategista');
        $coord    = $this->coordenador();

        $empresa = $this->empresa();
        // DOIS serviços ativos — o par tem de ser replicado nos dois (D-A).
        $s1 = $this->servico('Publicação 154', 'publicacao');
        $s2 = $this->servico('Gestão 154', 'performance');
        $this->vincularServico($empresa, $s1);
        $this->vincularServico($empresa, $s2);

        $r = $this->svc()->distribuir($empresa->fresh(), $analista->id, $estrat->id, $coord);

        $this->assertSame('distribuido', $r['status']);
        $this->assertSame(2, $r['servicos_vinculados']);

        // Reconsulta ao banco: 4 linhas (2 serviços × 2 funções).
        $this->assertSame(4, DB::table('company_users')->where('company_id', $empresa->id)->count());
        foreach ([$s1->id, $s2->id] as $sid) {
            $this->assertDatabaseHas('company_users', [
                'company_id' => $empresa->id, 'servico_id' => $sid,
                'role' => 'analista', 'user_id' => $analista->id,
            ]);
            $this->assertDatabaseHas('company_users', [
                'company_id' => $empresa->id, 'servico_id' => $sid,
                'role' => 'estrategista', 'user_id' => $estrat->id,
            ]);
        }

        // DISTRIB-04 — etapa 6, por reconsulta.
        $this->assertSame(Company::ETAPA_AGUARDANDO_ONBOARDING, Company::findOrFail($empresa->id)->etapa);
    }

    /**
     * DISTRIB-03 — "o coordenador que distribuiu, data e horário" vem da linha
     * de transição, sem coluna nova em `companies` (D-C).
     */
    public function test_quem_distribuiu_e_quando_ficam_na_linha_de_transicao(): void
    {
        $setor = $this->setor('publicacao', 'Publicação');
        $analista = $this->colaborador($this->cargo($setor, 'analista'), 'Analista');
        $estrat   = $this->colaborador($this->cargo($setor, 'estrategista'), 'Estrategista');
        $coord    = $this->coordenador();

        $empresa = $this->empresa();
        $this->vincularServico($empresa, $this->servico('Publicação 154', 'publicacao'));

        $this->svc()->distribuir($empresa->fresh(), $analista->id, $estrat->id, $coord);

        $linha = CompanyEtapaTransicao::where('company_id', $empresa->id)
            ->where('etapa_nova', Company::ETAPA_AGUARDANDO_ONBOARDING)
            ->first();

        $this->assertNotNull($linha, 'a distribuição precisa deixar linha de histórico.');
        $this->assertSame($coord->id, $linha->user_id, 'o ator é o coordenador da sessão.');
        $this->assertSame(Company::ETAPA_AGUARDANDO_DISTRIBUICAO, $linha->etapa_anterior);
        $this->assertNotNull($linha->created_at);
    }

    public function test_empresa_fora_da_etapa_5_e_recusada_sem_gravar_nada(): void
    {
        $setor = $this->setor('publicacao', 'Publicação');
        $analista = $this->colaborador($this->cargo($setor, 'analista'), 'Analista');
        $estrat   = $this->colaborador($this->cargo($setor, 'estrategista'), 'Estrategista');

        $empresa = $this->empresa(['etapa' => Company::ETAPA_ADMINISTRATIVO_ANDAMENTO]);
        $this->vincularServico($empresa, $this->servico('Publicação 154', 'publicacao'));

        $r = $this->svc()->distribuir($empresa->fresh(), $analista->id, $estrat->id, $this->coordenador());

        $this->assertSame('recusado', $r['status']);
        $this->assertSame(0, DB::table('company_users')->where('company_id', $empresa->id)->count());
        $this->assertSame(Company::ETAPA_ADMINISTRATIVO_ANDAMENTO, Company::findOrFail($empresa->id)->etapa);
    }

    public function test_empresa_sem_servico_ativo_e_recusada(): void
    {
        $setor = $this->setor('publicacao', 'Publicação');
        $analista = $this->colaborador($this->cargo($setor, 'analista'), 'Analista');
        $estrat   = $this->colaborador($this->cargo($setor, 'estrategista'), 'Estrategista');

        $empresa = $this->empresa(); // sem ContratoServico

        $r = $this->svc()->distribuir($empresa->fresh(), $analista->id, $estrat->id, $this->coordenador());

        $this->assertSame('recusado', $r['status']);
        $this->assertStringContainsString('serviço ativo', $r['requisito_faltante']);
        $this->assertSame(Company::ETAPA_AGUARDANDO_DISTRIBUICAO, Company::findOrFail($empresa->id)->etapa);
    }

    /** Distribuir de novo troca o responsável em vez de acumular linha. */
    public function test_distribuir_e_idempotente_por_empresa_servico_e_funcao(): void
    {
        $setor = $this->setor('publicacao', 'Publicação');
        $cargoA = $this->cargo($setor, 'analista');
        $a1 = $this->colaborador($cargoA, 'Analista Um');
        $a2 = $this->colaborador($cargoA, 'Analista Dois');
        $estrat = $this->colaborador($this->cargo($setor, 'estrategista'), 'Estrategista');
        $coord  = $this->coordenador();

        $empresa = $this->empresa();
        $this->vincularServico($empresa, $this->servico('Publicação 154', 'publicacao'));

        $this->svc()->distribuir($empresa->fresh(), $a1->id, $estrat->id, $coord);
        // A empresa saiu da etapa 5; devolvê-la para simular redistribuição.
        Company::whereKey($empresa->id)->update(['etapa' => Company::ETAPA_AGUARDANDO_DISTRIBUICAO]);
        $this->svc()->distribuir($empresa->fresh(), $a2->id, $estrat->id, $coord);

        $this->assertSame(2, DB::table('company_users')->where('company_id', $empresa->id)->count(), 'nada de acumular linha por redistribuição.');
        $this->assertDatabaseHas('company_users', [
            'company_id' => $empresa->id, 'role' => 'analista', 'user_id' => $a2->id,
        ]);
        $this->assertDatabaseMissing('company_users', [
            'company_id' => $empresa->id, 'role' => 'analista', 'user_id' => $a1->id,
        ]);
    }

    // ─── RESP-02 — os marcadores derivam, nada de coluna ───────────────────

    public function test_marcadores_acendem_apos_a_distribuicao(): void
    {
        $setor = $this->setor('publicacao', 'Publicação');
        $analista = $this->colaborador($this->cargo($setor, 'analista'), 'Analista');
        $estrat   = $this->colaborador($this->cargo($setor, 'estrategista'), 'Estrategista');

        $empresa = $this->empresa();
        $this->vincularServico($empresa, $this->servico('Publicação 154', 'publicacao'));

        // Antes: nem novo, nem onboarding pendente.
        $antes = $this->svc()->marcadores([$empresa->id]);
        $this->assertFalse($antes[$empresa->id]['novo_cliente']);
        $this->assertFalse($antes[$empresa->id]['onboarding_pendente']);

        $this->svc()->distribuir($empresa->fresh(), $analista->id, $estrat->id, $this->coordenador());

        $depois = $this->svc()->marcadores([$empresa->id]);
        $this->assertTrue($depois[$empresa->id]['novo_cliente'], 'recém-distribuída conta como novo cliente.');
        $this->assertTrue($depois[$empresa->id]['onboarding_pendente'], 'etapa 6 é onboarding pendente.');
    }

    public function test_novo_cliente_apaga_depois_da_janela(): void
    {
        $setor = $this->setor('publicacao', 'Publicação');
        $analista = $this->colaborador($this->cargo($setor, 'analista'), 'Analista');
        $estrat   = $this->colaborador($this->cargo($setor, 'estrategista'), 'Estrategista');

        $empresa = $this->empresa();
        $this->vincularServico($empresa, $this->servico('Publicação 154', 'publicacao'));
        $this->svc()->distribuir($empresa->fresh(), $analista->id, $estrat->id, $this->coordenador());

        // Envelhece a linha de transição para além da janela.
        CompanyEtapaTransicao::where('company_id', $empresa->id)
            ->where('etapa_nova', Company::ETAPA_AGUARDANDO_ONBOARDING)
            ->update(['created_at' => now()->subDays(DistribuicaoService::DIAS_NOVO_CLIENTE + 1)]);

        $m = $this->svc()->marcadores([$empresa->id]);

        $this->assertFalse(
            $m[$empresa->id]['novo_cliente'],
            'passada a janela, o selo de novo cliente apaga sozinho — é o motivo de derivar em vez de guardar coluna.'
        );
        // Mas "onboarding pendente" continua: depende da etapa, não do tempo.
        $this->assertTrue($m[$empresa->id]['onboarding_pendente']);
    }

    public function test_marcadores_com_lista_vazia_nao_quebra(): void
    {
        $this->assertSame([], $this->svc()->marcadores([]));
    }

    /** RESP-01 — o vínculo é o que faz a empresa aparecer na carteira dos dois. */
    public function test_apos_distribuir_a_empresa_esta_na_carteira_dos_dois(): void
    {
        $setor = $this->setor('publicacao', 'Publicação');
        $analista = $this->colaborador($this->cargo($setor, 'analista'), 'Analista');
        $estrat   = $this->colaborador($this->cargo($setor, 'estrategista'), 'Estrategista');

        $empresa = $this->empresa();
        $this->vincularServico($empresa, $this->servico('Publicação 154', 'publicacao'));

        $this->svc()->distribuir($empresa->fresh(), $analista->id, $estrat->id, $this->coordenador());

        $this->assertTrue($analista->companies()->where('companies.id', $empresa->id)->exists());
        $this->assertTrue($estrat->companies()->where('companies.id', $empresa->id)->exists());
    }
}
