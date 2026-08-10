<?php

namespace Tests\Feature\Phase125;

use App\Models\ContratoAssinatura;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Prova de SCHEMA PURO da tabela `contrato_assinatura_signatarios`
 * (DADOS-02) — colunas, índices nomeados à mão (armadilha 1059),
 * `user_id` nullable (armadilha 1830) e ausência de tipo restrito
 * (`enum`/CHECK) em `papel`/`situacao` (D-04).
 *
 * Este arquivo NÃO referencia o model do signatário — ele nasce na
 * Task 2. Usa Schema/DB::table() puro.
 */
class ContratoAssinaturaSignatarioSchemaTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function tabela_existe_com_todas_as_colunas_esperadas(): void
    {
        $this->assertTrue(Schema::hasTable('contrato_assinatura_signatarios'));

        $this->assertTrue(Schema::hasColumns('contrato_assinatura_signatarios', [
            'id',
            'contrato_assinatura_id',
            'user_id',
            'papel',
            'nome',
            'email',
            'cpf',
            'situacao',
            'assinado_em',
            'ip_address',
            'auths',
            'evidencia_signer',
            'clicksign_signer_key',
            'created_at',
            'updated_at',
        ]));
    }

    #[Test]
    public function indices_tem_nome_explicito_e_cabem_em_64_caracteres(): void
    {
        $indexes     = Schema::getIndexes('contrato_assinatura_signatarios');
        $indexeNomes = array_column($indexes, 'name');

        $this->assertContains('cas_contrato_situacao_idx', $indexeNomes);
        $this->assertContains('cas_clicksign_signer_idx', $indexeNomes);

        foreach ($indexeNomes as $nome) {
            $this->assertLessThanOrEqual(64, strlen($nome), "Índice '{$nome}' estoura o limite de 64 chars do MariaDB (erro 1059).");
        }
    }

    #[Test]
    public function user_id_aceita_nulo(): void
    {
        $contrato = ContratoAssinatura::factory()->create();

        $id = DB::table('contrato_assinatura_signatarios')->insertGetId([
            'contrato_assinatura_id' => $contrato->id,
            'user_id'                => null,
            'papel'                  => 'contratante',
            'nome'                   => 'Fulano de Tal',
            'email'                  => 'fulano@example.com',
            'situacao'               => 'pendente',
            'created_at'             => now(),
            'updated_at'             => now(),
        ]);

        $row = DB::table('contrato_assinatura_signatarios')->find($id);

        $this->assertNotNull($row);
        $this->assertNull($row->user_id);
    }

    #[Test]
    public function papel_e_situacao_aceitam_string_arbitraria_sem_tipo_restrito(): void
    {
        $contrato = ContratoAssinatura::factory()->create();

        $id = DB::table('contrato_assinatura_signatarios')->insertGetId([
            'contrato_assinatura_id' => $contrato->id,
            'papel'                  => 'valor_fora_da_lista',
            'nome'                   => 'Ciclana',
            'email'                  => 'ciclana@example.com',
            'situacao'               => 'outro_valor_qualquer',
            'created_at'             => now(),
            'updated_at'             => now(),
        ]);

        $row = DB::table('contrato_assinatura_signatarios')->find($id);

        $this->assertSame('valor_fora_da_lista', $row->papel);
        $this->assertSame('outro_valor_qualquer', $row->situacao);
    }

    #[Test]
    public function apagar_o_contrato_leva_os_signatarios_junto(): void
    {
        $contrato = ContratoAssinatura::factory()->create();

        DB::table('contrato_assinatura_signatarios')->insert([
            [
                'contrato_assinatura_id' => $contrato->id,
                'papel'                  => 'contratante',
                'nome'                   => 'Um',
                'email'                  => 'um@example.com',
                'situacao'               => 'pendente',
                'created_at'             => now(),
                'updated_at'             => now(),
            ],
            [
                'contrato_assinatura_id' => $contrato->id,
                'papel'                  => 'testemunha',
                'nome'                   => 'Dois',
                'email'                  => 'dois@example.com',
                'situacao'               => 'pendente',
                'created_at'             => now(),
                'updated_at'             => now(),
            ],
        ]);

        $this->assertSame(2, DB::table('contrato_assinatura_signatarios')
            ->where('contrato_assinatura_id', $contrato->id)->count());

        DB::table('contrato_assinaturas')->where('id', $contrato->id)->delete();

        // cascadeOnDelete deliberado (T-125-16) — não é acidente.
        $this->assertSame(0, DB::table('contrato_assinatura_signatarios')
            ->where('contrato_assinatura_id', $contrato->id)->count());
    }
}
