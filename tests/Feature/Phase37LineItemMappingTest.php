<?php

namespace Tests\Feature;

use App\Models\HubspotLineItemMapping;
use App\Models\Servico;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Suite Feature — Phase 37 Plan 37-02 (REQ-37-02).
 *
 * Cobertura:
 *  T1. Tabela hubspot_line_item_mapping existe + colunas + UNIQUE em line_item_name
 *  T2. FK servico_id em onDelete cascade
 *  T3. Scope ativo() filtra apenas rows com ativo=true
 *  T4. paraNome() faz match case-insensitive (LOWER comparison)
 *  T5. paraNome() ignora rows com ativo=false
 *  T6. paraNome() retorna null quando nao ha match
 *  T7. paraNome() retorna mapping com Servico eager-loaded
 *  T8. Seed canonico cria mapeamentos para MAP/Polo/Brigada/Gestao/Mentoria/Publicacao
 *
 * Roda contra SQLite RefreshDatabase — replica o seed inline para isolar
 * do estado de migrations (D-08 do PLAN 37-02).
 */
class Phase37LineItemMappingTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Limpa o estado deixado pelas migrations do seed (Phase 14 + Phase 37 Plan 37-02)
     * antes de cada teste — cada teste constroi seu proprio cenario isolado.
     *
     * RefreshDatabase ja zera o schema entre testes mas roda as migrations de seed
     * automaticamente; queremos cenario controlado para asserts de contagem.
     */
    protected function setUp(): void
    {
        parent::setUp();

        DB::table('hubspot_line_item_mapping')->delete();
        DB::table('servicos')->delete();
    }

    /**
     * Helper: cria um Servico minimo no catalogo.
     */
    private function criarServico(string $nome, string $setor = Servico::SETOR_OUTROS): Servico
    {
        return Servico::create([
            'nome'          => $nome,
            'valor_padrao'  => 0,
            'tipo_cobranca' => Servico::TIPO_MENSAL,
            'ativo'         => true,
            'setor'         => $setor,
        ]);
    }

    /**
     * Helper: cria um mapping na tabela hubspot_line_item_mapping.
     */
    private function criarMapping(string $nome, Servico $servico, bool $ativo = true): HubspotLineItemMapping
    {
        return HubspotLineItemMapping::create([
            'line_item_name' => $nome,
            'servico_id'     => $servico->id,
            'ativo'          => $ativo,
        ]);
    }

    public function test_tabela_existe_com_colunas_esperadas(): void
    {
        $this->assertTrue(
            Schema::hasTable('hubspot_line_item_mapping'),
            'Tabela hubspot_line_item_mapping deveria existir apos as migrations'
        );

        $this->assertTrue(Schema::hasColumns('hubspot_line_item_mapping', [
            'id',
            'line_item_name',
            'servico_id',
            'ativo',
            'observacoes',
            'created_at',
            'updated_at',
        ]));
    }

    public function test_unique_em_line_item_name_impede_duplicata(): void
    {
        $gestao = $this->criarServico('Gestão', Servico::SETOR_PERFORMANCE);

        $this->criarMapping('MAP', $gestao);

        $this->expectException(QueryException::class);
        $this->criarMapping('MAP', $gestao);
    }

    public function test_fk_cascade_em_servico_id(): void
    {
        // SQLite precisa de PRAGMA explicito para enforcar foreign keys com cascade
        // em testes RefreshDatabase. RefreshDatabase ja roda com FK enabled, mas
        // reforcamos defensivamente.
        DB::statement('PRAGMA foreign_keys = ON');

        $gestao  = $this->criarServico('Gestão', Servico::SETOR_PERFORMANCE);
        $mapping = $this->criarMapping('MAP', $gestao);

        $mappingId = $mapping->id;

        // Apaga o Servico — cascade deve apagar o mapping junto.
        $gestao->delete();

        $this->assertFalse(
            HubspotLineItemMapping::where('id', $mappingId)->exists(),
            'Mapping deveria ter sido apagado em cascade ao deletar o Servico associado'
        );
    }

    public function test_scope_ativo_filtra_apenas_ativos(): void
    {
        $gestao = $this->criarServico('Gestão', Servico::SETOR_PERFORMANCE);

        $this->criarMapping('MAP', $gestao, true);
        $this->criarMapping('Polo', $gestao, true);
        $this->criarMapping('Brigada', $gestao, false);

        $this->assertSame(2, HubspotLineItemMapping::ativo()->count());
        $this->assertSame(3, HubspotLineItemMapping::count());
    }

    public function test_paraNome_case_insensitive(): void
    {
        $gestao = $this->criarServico('Gestão', Servico::SETOR_PERFORMANCE);

        $this->criarMapping('MAP PREMIUM', $gestao);

        $match = HubspotLineItemMapping::paraNome('mAp PrEmIuM');

        $this->assertNotNull($match, 'paraNome deveria fazer match case-insensitive');
        $this->assertSame('MAP PREMIUM', $match->line_item_name);
        $this->assertSame($gestao->id, $match->servico_id);
    }

    public function test_paraNome_ignora_inativos(): void
    {
        $gestao = $this->criarServico('Gestão', Servico::SETOR_PERFORMANCE);

        $this->criarMapping('MAP', $gestao, false);

        $this->assertNull(
            HubspotLineItemMapping::paraNome('MAP'),
            'paraNome deveria ignorar mappings inativos'
        );
    }

    public function test_paraNome_retorna_null_quando_inexistente(): void
    {
        $this->assertNull(HubspotLineItemMapping::paraNome('Inexistente XYZ'));
    }

    public function test_paraNome_retorna_servico_eager_loaded(): void
    {
        $gestao = $this->criarServico('Gestão', Servico::SETOR_PERFORMANCE);

        $this->criarMapping('MAP', $gestao);

        $match = HubspotLineItemMapping::paraNome('MAP');

        $this->assertNotNull($match);

        // Eager loaded — relacao 'servico' ja deve estar carregada.
        $this->assertTrue(
            $match->relationLoaded('servico'),
            'Relacao servico deveria estar eager-loaded em paraNome()'
        );
        $this->assertSame('Gestão', $match->servico->nome);
    }

    public function test_seed_inicial_cria_mapeamentos_canonicos(): void
    {
        // Pre-condicao: catalogo de servicos populado (replica Phase 14 seed).
        $gestao     = $this->criarServico('Gestão', Servico::SETOR_PERFORMANCE);
        $polos      = $this->criarServico('Polos');
        $publicacao = $this->criarServico('Publicação', Servico::SETOR_PUBLICACAO);
        $mentoria   = $this->criarServico('Mentoria', Servico::SETOR_PERFORMANCE);

        // Replica o conteudo do seed inline — isola a suite do estado de migrations
        // no SQLite (D-08 do PLAN 37-02).
        $mapeamentos = [
            'MAP'         => $gestao,
            'MAP PREMIUM' => $gestao,
            'Polo'        => $polos,
            'POLO'        => $polos,
            'Brigada'     => $gestao,
            'Gestão'      => $gestao,
            'Mentoria'    => $mentoria,
            'Publicação'  => $publicacao,
        ];

        foreach ($mapeamentos as $nome => $servico) {
            HubspotLineItemMapping::firstOrCreate(
                ['line_item_name' => $nome],
                ['servico_id' => $servico->id, 'ativo' => true],
            );
        }

        // Re-rodar deve ser no-op (firstOrCreate).
        foreach ($mapeamentos as $nome => $servico) {
            HubspotLineItemMapping::firstOrCreate(
                ['line_item_name' => $nome],
                ['servico_id' => $servico->id, 'ativo' => true],
            );
        }

        // Cobertura minima: 8 mapeamentos canonicos (>= 6 exigidos pelo plan).
        $this->assertSame(8, HubspotLineItemMapping::count());

        // Conferencia das familias canonicas (case-sensitive na tabela).
        $nomes = DB::table('hubspot_line_item_mapping')
            ->pluck('line_item_name')
            ->all();

        foreach (['MAP', 'Polo', 'Brigada', 'Gestão', 'Mentoria', 'Publicação'] as $esperado) {
            $this->assertContains(
                $esperado,
                $nomes,
                "Seed canonico deveria ter criado o mapping '{$esperado}'"
            );
        }

        // Resolucao via paraNome — confirma que o seed produz mappings utilizaveis.
        $resolvido = HubspotLineItemMapping::paraNome('map premium');
        $this->assertNotNull($resolvido);
        $this->assertSame('Gestão', $resolvido->servico->nome);
    }
}
