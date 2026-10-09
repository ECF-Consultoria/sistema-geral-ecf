<?php

namespace Tests\Feature\Publicador;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * WR-B06: as migrations da fase 164 tratam o driver `mariadb` (Laravel 11+) como o
 * `mysql`. Com `DB_CONNECTION=mariadb`, a detecção por `=== 'mysql'` mandava o MariaDB
 * para o `PRAGMA` do SQLite no meio do `up()` — depois do NOT NULL, com a migration
 * presa em `Pending`. O SQLite dos testes não roda o ramo do MariaDB; por isso a prova
 * é dupla: a decisão de driver da migration (sem conectar: o PDO do Laravel é
 * preguiçoso) e uma varredura que barra a comparação estrita de volta.
 */
class MigracoesDaFaseDetectamMariaDbTest extends TestCase
{
    /** As migrations da fase que conversam com o driver. */
    private const MIGRACOES = [
        '2026_10_01_200000_create_publicador_tables.php',
        '2026_10_02_100000_create_pub_produtos_table.php',
        '2026_10_02_100100_add_produto_id_to_pub_rascunhos.php',
        '2026_10_02_200000_pub_produtos_ancoras_sem_cascata.php',
        // Fase 166: não consulta o driver; a varredura garante que continue assim.
        '2026_10_05_100000_create_pub_alavanca_escritas_table.php',
        // Fase 167
        '2026_10_06_100000_create_estrutura_produtos_tables.php',
        '2026_10_06_100100_add_variacao_id_to_estrutura_ofertas.php',
        // Fase 168
        '2026_10_07_100000_create_estrutura_geracao_tables.php',
        '2026_10_07_100100_semear_estrutura_tipos_e_pares.php',
        // Ficha técnica do produto
        '2026_10_07_100200_create_estrutura_produto_atributos_table.php',
        // Imagens por variação do produto
        '2026_10_07_100300_create_estrutura_produto_variacao_imagens_table.php',
        // Fase 175: consulta o driver nos helpers `emMysql`/`hasIndex`/`hasForeignKey`
        '2026_10_08_120000_add_fases_to_pub_produtos.php',
    ];

    /** A conexão padrão vira uma `$driver` FALSA só para a decisão — nenhuma query é feita. */
    private function comDriver(string $driver, \Closure $fn): mixed
    {
        $padrao = config('database.default');
        config(['database.connections.wr_b06' => ['driver' => $driver, 'host' => '127.0.0.1', 'port' => 1, 'database' => 'nao_conecta',
            'username' => 'x', 'password' => '', 'prefix' => '']]);
        config(['database.default' => 'wr_b06']);
        try {
            return $fn();
        } finally {
            config(['database.default' => $padrao]);
            DB::purge('wr_b06');
        }
    }

    public function test_a_migration_do_backfill_trata_mariadb_como_mysql(): void
    {
        $migration = require database_path('migrations/2026_10_02_100100_add_produto_id_to_pub_rascunhos.php');
        $emMysql = (new \ReflectionMethod($migration, 'emMysql'))->getClosure($migration);

        $this->assertTrue($this->comDriver('mariadb', $emMysql), 'DB_CONNECTION=mariadb usa information_schema');
        $this->assertTrue($this->comDriver('mysql', $emMysql));
        $this->assertFalse($emMysql(), 'o SQLite dos testes segue no PRAGMA');
    }

    public function test_a_migration_da_variacao_trata_mariadb_como_mysql(): void
    {
        $migration = require database_path('migrations/2026_10_06_100100_add_variacao_id_to_estrutura_ofertas.php');
        $emMysql = (new \ReflectionMethod($migration, 'emMysql'))->getClosure($migration);

        $this->assertTrue($this->comDriver('mariadb', $emMysql), 'DB_CONNECTION=mariadb usa information_schema');
        $this->assertTrue($this->comDriver('mysql', $emMysql));
        $this->assertFalse($emMysql(), 'o SQLite dos testes segue no PRAGMA');
    }

    /** BE-WR-08: a criação das tabelas de Produtos ficou idempotente e repõe FK só no MySQL/MariaDB. */
    public function test_a_migration_da_criacao_dos_produtos_trata_mariadb_como_mysql(): void
    {
        $migration = require database_path('migrations/2026_10_06_100000_create_estrutura_produtos_tables.php');
        $emMysql = (new \ReflectionMethod($migration, 'emMysql'))->getClosure($migration);

        $this->assertTrue($this->comDriver('mariadb', $emMysql), 'DB_CONNECTION=mariadb repõe as FKs pelo information_schema');
        $this->assertTrue($this->comDriver('mysql', $emMysql));
        $this->assertFalse($emMysql(), 'o SQLite dos testes segue no PRAGMA');
    }

    /** Fase 168: a criação das tabelas da geração repõe FK só no MySQL/MariaDB. */
    public function test_a_migration_da_geracao_trata_mariadb_como_mysql(): void
    {
        $migration = require database_path('migrations/2026_10_07_100000_create_estrutura_geracao_tables.php');
        $emMysql = (new \ReflectionMethod($migration, 'emMysql'))->getClosure($migration);

        $this->assertTrue($this->comDriver('mariadb', $emMysql), 'DB_CONNECTION=mariadb repõe as FKs pelo information_schema');
        $this->assertTrue($this->comDriver('mysql', $emMysql));
        $this->assertFalse($emMysql(), 'o SQLite dos testes segue no PRAGMA');
    }

    /** Ficha técnica do produto: a criação da tabela repõe FK só no MySQL/MariaDB. */
    public function test_a_migration_da_ficha_tecnica_trata_mariadb_como_mysql(): void
    {
        $migration = require database_path('migrations/2026_10_07_100200_create_estrutura_produto_atributos_table.php');
        $emMysql = (new \ReflectionMethod($migration, 'emMysql'))->getClosure($migration);

        $this->assertTrue($this->comDriver('mariadb', $emMysql), 'DB_CONNECTION=mariadb repõe as FKs pelo information_schema');
        $this->assertTrue($this->comDriver('mysql', $emMysql));
        $this->assertFalse($emMysql(), 'o SQLite dos testes segue no PRAGMA');
    }

    /** Imagens por variação: a criação da tabela repõe FK só no MySQL/MariaDB. */
    public function test_a_migration_das_imagens_da_variacao_trata_mariadb_como_mysql(): void
    {
        $migration = require database_path('migrations/2026_10_07_100300_create_estrutura_produto_variacao_imagens_table.php');
        $emMysql = (new \ReflectionMethod($migration, 'emMysql'))->getClosure($migration);

        $this->assertTrue($this->comDriver('mariadb', $emMysql), 'DB_CONNECTION=mariadb repõe as FKs pelo information_schema');
        $this->assertTrue($this->comDriver('mysql', $emMysql));
        $this->assertFalse($emMysql(), 'o SQLite dos testes segue no PRAGMA');
    }

    /** Fase 175: o ALTER das fases de `pub_produtos` guarda índice e FK pelo information_schema no MySQL/MariaDB. */
    public function test_a_migration_das_fases_do_produto_trata_mariadb_como_mysql(): void
    {
        $migration = require database_path('migrations/2026_10_08_120000_add_fases_to_pub_produtos.php');
        $emMysql = (new \ReflectionMethod($migration, 'emMysql'))->getClosure($migration);

        $this->assertTrue($this->comDriver('mariadb', $emMysql), 'DB_CONNECTION=mariadb usa information_schema');
        $this->assertTrue($this->comDriver('mysql', $emMysql));
        $this->assertFalse($emMysql(), 'o SQLite dos testes segue no PRAGMA');
    }

    public function test_nenhuma_migration_da_fase_compara_o_driver_so_com_mysql(): void
    {
        foreach (self::MIGRACOES as $arquivo) {
            $codigo = file_get_contents(database_path("migrations/{$arquivo}"));

            $this->assertDoesNotMatchRegularExpression("/getDriverName\(\)\s*[!=]==?\s*'mysql'/", $codigo,
                "{$arquivo}: compare com ['mysql', 'mariadb'] (o driver mariadb do Laravel 11+ cairia no ramo do SQLite)");
        }
    }
}
