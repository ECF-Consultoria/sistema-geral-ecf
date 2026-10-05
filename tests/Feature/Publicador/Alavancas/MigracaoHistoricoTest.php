<?php

namespace Tests\Feature\Publicador\Alavancas;

use App\Models\Company;
use App\Models\MlbEmpresa;
use App\Models\PubAlavancaEscrita;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * D-05 (Fase 166): a tabela do histórico de escritas existe com o desenho escrito, é
 * segura no MariaDB por construção (guarda por conteúdo) e sobrevive à exclusão da
 * empresa, da Company e do usuário.
 */
class MigracaoHistoricoTest extends TestCase
{
    use RefreshDatabase;

    private const ARQUIVO = '2026_10_05_100000_create_pub_alavanca_escritas_table.php';

    private function linha(array $extra = []): PubAlavancaEscrita
    {
        return PubAlavancaEscrita::create([
            'conta_chave' => 'empresa-1', 'ml_seller_id' => '1555596317', 'ator_nome' => 'Dev ECF',
            'alavanca' => 'promocao', 'acao' => 'convite.inscrever', ...$extra,
        ]);
    }

    public function test_as_colunas_do_desenho_existem(): void
    {
        $this->assertTrue(Schema::hasColumns('pub_alavanca_escritas', [
            'id', 'lote_uuid', 'mlb_empresa_id', 'company_id', 'conta_chave', 'ml_seller_id', 'user_id', 'ator_nome',
            'alavanca', 'acao', 'promotion_type', 'promotion_id', 'item_id', 'metodo', 'caminho', 'payload', 'resumo',
            'http_status', 'resposta', 'resultado', 'erro_codigo', 'mensagem', 'enviado_em', 'concluido_em',
            'created_at', 'updated_at',
        ]));
    }

    public function test_excluir_empresa_company_e_usuario_mantem_a_linha(): void
    {
        $company = Company::factory()->create();
        $empresa = MlbEmpresa::create(['nome' => 'Polo X', 'projeto' => 'POLOS']);
        $user = User::factory()->create(['role' => 'admin']);

        $linha = $this->linha(['mlb_empresa_id' => $empresa->id, 'company_id' => $company->id, 'user_id' => $user->id]);

        $empresa->delete();
        $company->delete();
        $user->forceDelete(); // User tem SoftDeletes: só a exclusão física aciona o SET NULL

        $linha = $linha->fresh();
        $this->assertNotNull($linha, 'a linha do histórico sobrevive');
        $this->assertNull($linha->mlb_empresa_id);
        $this->assertNull($linha->company_id);
        $this->assertNull($linha->user_id);
        $this->assertSame('empresa-1', $linha->conta_chave);
        $this->assertSame('Dev ECF', $linha->ator_nome);
    }

    public function test_resultado_nasce_pendente_e_os_casts_funcionam(): void
    {
        $linha = $this->linha([
            'payload' => ['dados' => ['a' => 1]], 'resumo' => ['preco' => 10], 'resposta' => ['status' => 'ok'],
            'enviado_em' => now(), 'concluido_em' => now(), 'lote_uuid' => (string) Str::uuid(),
        ])->fresh();

        $this->assertSame('PENDENTE', $linha->resultado);
        $this->assertSame(['dados' => ['a' => 1]], $linha->payload);
        $this->assertSame(['preco' => 10], $linha->resumo);
        $this->assertSame(['status' => 'ok'], $linha->resposta);
        $this->assertInstanceOf(\Carbon\Carbon::class, $linha->enviado_em);
        $this->assertInstanceOf(\Carbon\Carbon::class, $linha->concluido_em);
    }

    public function test_marcar_fecha_a_linha(): void
    {
        $linha = $this->linha()->marcar(PubAlavancaEscrita::OK, ['http_status' => 200]);

        $linha = $linha->fresh();
        $this->assertSame('OK', $linha->resultado);
        $this->assertSame(200, $linha->http_status);
        $this->assertNotNull($linha->concluido_em);
    }

    public function test_da_empresa_filtra_pelas_ancoras_e_nunca_devolve_tudo(): void
    {
        $company = Company::factory()->create();
        $outraCompany = Company::factory()->create();
        $empresa = MlbEmpresa::create(['nome' => 'Polo X', 'projeto' => 'POLOS']);
        $outraEmpresa = MlbEmpresa::create(['nome' => 'Polo Y', 'projeto' => 'POLOS']);

        $porEmpresa = $this->linha(['mlb_empresa_id' => $empresa->id]);
        $porCompany = $this->linha(['company_id' => $company->id]);
        $this->linha(['mlb_empresa_id' => $outraEmpresa->id]);
        $this->linha(['company_id' => $outraCompany->id]);

        $ids = PubAlavancaEscrita::daEmpresa($empresa, $company)->pluck('id')->all();
        $this->assertEqualsCanonicalizing([$porEmpresa->id, $porCompany->id], $ids);

        $this->assertSame([$porEmpresa->id], PubAlavancaEscrita::daEmpresa($empresa, null)->pluck('id')->all());
        $this->assertSame([$porCompany->id], PubAlavancaEscrita::daEmpresa(null, $company)->pluck('id')->all());
        $this->assertSame(0, PubAlavancaEscrita::daEmpresa(null, null)->count());
    }

    public function test_migration_e_segura_no_mariadb_por_construcao(): void
    {
        $codigo = file_get_contents(database_path('migrations/'.self::ARQUIVO));

        $this->assertStringNotContainsString('->enum(', $codigo);
        $this->assertStringNotContainsString('->change(', $codigo);

        // Todo nome de índice/FK tem até 64 caracteres.
        preg_match_all("/(?:index\(|foreign\()[^;]*?,\s*'([a-z0-9_]+)'\)/", $codigo, $nomes);
        $this->assertNotEmpty($nomes[1]);
        foreach ($nomes[1] as $nome) {
            $this->assertLessThanOrEqual(64, strlen($nome), "{$nome} passa de 64 caracteres");
        }

        // nullOnDelete só em coluna declarada anulável.
        preg_match_all("/foreign\('([a-z_]+)'/", $codigo, $fks);
        $this->assertCount(3, $fks[1]);
        foreach ($fks[1] as $coluna) {
            $this->assertMatchesRegularExpression("/\\\$t->unsignedBigInteger\('{$coluna}'\)->nullable\(\)/", $codigo, "{$coluna} precisa ser anulável");
        }

        // down() só dropIfExists.
        $down = substr($codigo, strpos($codigo, 'function down'));
        $this->assertStringContainsString('dropIfExists', $down);
        $this->assertStringNotContainsString('dropForeign', $down);
        $this->assertStringNotContainsString('dropColumn', $down);
    }
}
