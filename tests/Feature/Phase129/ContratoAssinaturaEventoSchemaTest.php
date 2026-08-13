<?php

namespace Tests\Feature\Phase129;

use App\Models\ContratoAssinatura;
use App\Models\ContratoAssinaturaEvento;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Prova de SCHEMA da tabela `contrato_assinatura_eventos` (DADOS-03) — as
 * 14 colunas, a constraint única de `payload_hash` (idempotência da
 * CLICK-04, provada por captura de SQLSTATE 23000 — nunca `errorInfo[1]`,
 * precedente 127-06 / `NpsController.php:1835`), `contrato_assinatura_id`
 * nullable (armadilha 1830 do MariaDB) e o comportamento `nullOnDelete`.
 */
class ContratoAssinaturaEventoSchemaTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function tabela_existe_com_todas_as_colunas_esperadas(): void
    {
        $this->assertTrue(Schema::hasTable('contrato_assinatura_eventos'));

        $this->assertTrue(Schema::hasColumns('contrato_assinatura_eventos', [
            'id',
            'contrato_assinatura_id',
            'clicksign_envelope_id',
            'name',
            'signature_valid',
            'payload',
            'raw_body',
            'raw_truncado',
            'payload_hash',
            'origem',
            'status',
            'ip_address',
            'erro_msg',
            'processado_em',
            'created_at',
            'updated_at',
        ]));
    }

    #[Test]
    public function indices_tem_nome_explicito_e_cabem_em_64_caracteres(): void
    {
        $indexes     = Schema::getIndexes('contrato_assinatura_eventos');
        $indexeNomes = array_column($indexes, 'name');

        $this->assertContains('cae_payload_hash_uniq', $indexeNomes);
        $this->assertContains('cae_envelope_idx', $indexeNomes);
        $this->assertContains('cae_status_created_idx', $indexeNomes);

        foreach ($indexeNomes as $nome) {
            $this->assertLessThanOrEqual(64, strlen($nome), "Índice '{$nome}' estoura o limite de 64 chars do MariaDB (erro 1059).");
        }
    }

    #[Test]
    public function payload_hash_duplicado_captura_sqlstate_23000(): void
    {
        ContratoAssinaturaEvento::create([
            'payload'      => ['raw' => 'primeiro'],
            'payload_hash' => str_repeat('a', 64),
            'status'       => ContratoAssinaturaEvento::STATUS_RECEBIDO,
        ]);

        $capturou = false;

        try {
            ContratoAssinaturaEvento::create([
                'payload'      => ['raw' => 'segundo'],
                'payload_hash' => str_repeat('a', 64),
                'status'       => ContratoAssinaturaEvento::STATUS_RECEBIDO,
            ]);
        } catch (QueryException $e) {
            // Usar getCode() (SQLSTATE), NUNCA $e->errorInfo[1] — que é
            // código numérico do MySQL e não existe no SQLite (precedente
            // 127-06 / NpsController.php:1835).
            $this->assertSame('23000', (string) $e->getCode());
            $capturou = true;
        }

        $this->assertTrue($capturou, 'Esperava QueryException com SQLSTATE 23000 ao duplicar payload_hash.');
        $this->assertSame(1, DB::table('contrato_assinatura_eventos')->count());
    }

    #[Test]
    public function evento_com_contrato_assinatura_id_nulo_e_gravavel(): void
    {
        $evento = ContratoAssinaturaEvento::create([
            'contrato_assinatura_id' => null,
            'payload'                => ['raw' => 'sem contrato resolvido'],
            'payload_hash'           => str_repeat('b', 64),
            'status'                 => ContratoAssinaturaEvento::STATUS_RECEBIDO,
        ]);

        $this->assertNull($evento->fresh()->contrato_assinatura_id);
    }

    #[Test]
    public function apagar_o_contrato_deixa_o_evento_vivo_com_fk_nula(): void
    {
        $contrato = ContratoAssinatura::factory()->create();

        $evento = ContratoAssinaturaEvento::create([
            'contrato_assinatura_id' => $contrato->id,
            'payload'                => ['raw' => 'vinculado'],
            'payload_hash'           => str_repeat('c', 64),
            'status'                 => ContratoAssinaturaEvento::STATUS_RECEBIDO,
        ]);

        DB::table('contrato_assinaturas')->where('id', $contrato->id)->delete();

        $this->assertNull($evento->fresh()->contrato_assinatura_id);
    }
}
