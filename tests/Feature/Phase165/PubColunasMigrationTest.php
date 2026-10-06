<?php

namespace Tests\Feature\Phase165;

use App\Models\MlAnuncioCriativo;
use App\Models\MlAnuncioCriativoKit;
use App\Models\PubImagem;
use App\Models\PubProduto;
use App\Models\PubRascunho;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Fase 165, Plano 01 — a migration aditiva `pub_rascunho_id`/`pub_grupo`/
 * `pub_imagem_id` em `ml_anuncio_criativos` e `ml_anuncio_criativo_kits`
 * (D-02/D-10) e os helpers novos dos dois models (`pubRascunhoIdEfetivo`,
 * `pubGrupoEfetivo`, `retomavelDoPublicador`, `ultimoAprovadoDoPublicador`).
 */
class PubColunasMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'database/migrations/2026_10_04_120000_add_pub_columns_to_ml_anuncio_criativos_and_kits.php';

    public function test_as_cinco_colunas_existem(): void
    {
        foreach (['pub_rascunho_id', 'pub_grupo', 'pub_imagem_id'] as $coluna) {
            $this->assertTrue(
                \Illuminate\Support\Facades\Schema::hasColumn('ml_anuncio_criativos', $coluna),
                "ml_anuncio_criativos deveria ter a coluna {$coluna}."
            );
        }

        foreach (['pub_rascunho_id', 'pub_grupo'] as $coluna) {
            $this->assertTrue(
                \Illuminate\Support\Facades\Schema::hasColumn('ml_anuncio_criativo_kits', $coluna),
                "ml_anuncio_criativo_kits deveria ter a coluna {$coluna}."
            );
        }
    }

    public function test_rodar_a_migration_de_novo_nao_lanca_e_as_colunas_continuam(): void
    {
        $migration = require base_path(self::MIGRATION);

        // Idempotente: todas as colunas já existem (RefreshDatabase já
        // rodou todas as migrations), então o up() não deveria fazer nada
        // nem lançar.
        $migration->up();

        foreach (['pub_rascunho_id', 'pub_grupo', 'pub_imagem_id'] as $coluna) {
            $this->assertTrue(\Illuminate\Support\Facades\Schema::hasColumn('ml_anuncio_criativos', $coluna));
        }
        foreach (['pub_rascunho_id', 'pub_grupo'] as $coluna) {
            $this->assertTrue(\Illuminate\Support\Facades\Schema::hasColumn('ml_anuncio_criativo_kits', $coluna));
        }
    }

    public function test_criativo_e_kit_no_formato_antigo_gravam_e_leem_as_colunas_novas_nulas(): void
    {
        $criativo = MlAnuncioCriativo::create([
            'token'       => Str::random(32),
            'rascunho_id' => null,
            'slot'        => 'hero',
            'status'      => MlAnuncioCriativo::STATUS_PENDENTE,
        ]);

        $kit = MlAnuncioCriativoKit::create([
            'token'  => Str::random(32),
            'status' => MlAnuncioCriativoKit::STATUS_PLANEJANDO,
        ]);

        $criativo->refresh();
        $kit->refresh();

        $this->assertNull($criativo->pub_rascunho_id);
        $this->assertNull($criativo->pub_grupo);
        $this->assertNull($criativo->pub_imagem_id);
        $this->assertNull($kit->pub_rascunho_id);
        $this->assertNull($kit->pub_grupo);
    }

    private function produtoComRascunho(): array
    {
        $produto  = PubProduto::create(['sku' => 'SKU-' . Str::random(6), 'nome' => 'Produto de teste']);
        $rascunho = PubRascunho::create(['produto_id' => $produto->id, 'status' => PubRascunho::DRAFT]);

        return [$produto, $rascunho];
    }

    public function test_pub_rascunho_id_efetivo_de_um_slot_sem_colunas_proprias_devolve_o_do_kit(): void
    {
        [, $rascunho] = $this->produtoComRascunho();

        $kit = MlAnuncioCriativoKit::create([
            'token'          => Str::random(32),
            'status'         => MlAnuncioCriativoKit::STATUS_PLANEJADO,
            'pub_rascunho_id' => $rascunho->id,
            'pub_grupo'      => 'GENERAL',
        ]);

        // O slot NÃO carrega pub_rascunho_id/pub_grupo próprios — exatamente
        // como PlanejarKitCriativosJob cria hoje (copia só rascunho_id/
        // company_id/mlb_empresa_id/user_id do portador, nunca as colunas
        // novas).
        $slot = MlAnuncioCriativo::create([
            'token'      => Str::random(32),
            'kit_id'     => $kit->id,
            'slot'       => 'hero',
            'slot_indice' => 1,
            'status'     => MlAnuncioCriativo::STATUS_PENDENTE,
        ]);

        $this->assertSame($rascunho->id, $slot->pubRascunhoIdEfetivo());
        $this->assertSame('GENERAL', $slot->pubGrupoEfetivo());
    }

    public function test_pub_rascunho_id_efetivo_do_proprio_criativo_tem_prioridade_sobre_o_kit(): void
    {
        [, $rascunhoDoCriativo] = $this->produtoComRascunho();
        [, $rascunhoDoKit] = $this->produtoComRascunho();

        $kit = MlAnuncioCriativoKit::create([
            'token'          => Str::random(32),
            'status'         => MlAnuncioCriativoKit::STATUS_PLANEJADO,
            'pub_rascunho_id' => $rascunhoDoKit->id,
        ]);

        $portador = MlAnuncioCriativo::create([
            'token'          => Str::random(32),
            'kit_id'         => $kit->id,
            'slot'           => 'referencia',
            'status'         => MlAnuncioCriativo::STATUS_PRONTO,
            'pub_rascunho_id' => $rascunhoDoCriativo->id,
        ]);

        $this->assertSame($rascunhoDoCriativo->id, $portador->pubRascunhoIdEfetivo());
    }

    public function test_criativo_sem_kit_e_sem_pub_rascunho_id_proprio_devolve_nulo(): void
    {
        $criativo = MlAnuncioCriativo::create([
            'token'  => Str::random(32),
            'slot'   => 'hero',
            'status' => MlAnuncioCriativo::STATUS_PENDENTE,
        ]);

        $this->assertNull($criativo->pubRascunhoIdEfetivo());
        $this->assertNull($criativo->pubGrupoEfetivo());
    }

    public function test_retomavel_do_publicador_ignora_kit_de_outro_rascunho(): void
    {
        [, $rascunhoA] = $this->produtoComRascunho();
        [, $rascunhoB] = $this->produtoComRascunho();

        MlAnuncioCriativoKit::create([
            'token'           => Str::random(32),
            'status'          => MlAnuncioCriativoKit::STATUS_PLANEJADO,
            'pub_rascunho_id' => $rascunhoB->id,
            'pub_grupo'       => 'GENERAL',
        ]);

        $retomado = MlAnuncioCriativoKit::retomavelDoPublicador($rascunhoA->id, 'GENERAL');

        $this->assertNull($retomado);
    }

    public function test_retomavel_do_publicador_ignora_kit_aprovado_ou_erro(): void
    {
        [, $rascunho] = $this->produtoComRascunho();

        // APROVADO e ERRO são terminais: não entram em STATUS_RETOMAVEIS —
        // `retomavelDoPublicador` não deve achar nenhum dos dois.
        MlAnuncioCriativoKit::create([
            'token'           => Str::random(32),
            'status'          => MlAnuncioCriativoKit::STATUS_APROVADO,
            'pub_rascunho_id' => $rascunho->id,
            'pub_grupo'       => 'GENERAL',
        ]);

        MlAnuncioCriativoKit::create([
            'token'           => Str::random(32),
            'status'          => MlAnuncioCriativoKit::STATUS_ERRO,
            'pub_rascunho_id' => $rascunho->id,
            'pub_grupo'       => 'GENERAL',
        ]);

        $this->assertNull(MlAnuncioCriativoKit::retomavelDoPublicador($rascunho->id, 'GENERAL'));
    }

    public function test_retomavel_do_publicador_ignora_kit_de_outro_grupo_mesmo_rascunho(): void
    {
        [, $rascunho] = $this->produtoComRascunho();

        MlAnuncioCriativoKit::create([
            'token'           => Str::random(32),
            'status'          => MlAnuncioCriativoKit::STATUS_PLANEJADO,
            'pub_rascunho_id' => $rascunho->id,
            'pub_grupo'       => 'COLOR:Verde',
        ]);

        $this->assertNull(MlAnuncioCriativoKit::retomavelDoPublicador($rascunho->id, 'GENERAL'));
    }

    public function test_retomavel_do_publicador_acha_o_kit_retomavel_do_mesmo_grupo(): void
    {
        [, $rascunho] = $this->produtoComRascunho();

        $planejando = MlAnuncioCriativoKit::create([
            'token'           => Str::random(32),
            'status'          => MlAnuncioCriativoKit::STATUS_GERANDO,
            'pub_rascunho_id' => $rascunho->id,
            'pub_grupo'       => 'GENERAL',
        ]);

        $retomado = MlAnuncioCriativoKit::retomavelDoPublicador($rascunho->id, 'GENERAL');

        $this->assertNotNull($retomado);
        $this->assertSame($planejando->id, $retomado->id);
    }

    public function test_retomavel_do_publicador_nao_confunde_grupos_que_so_diferem_em_maiuscula(): void
    {
        [, $rascunho] = $this->produtoComRascunho();

        // Collation _ci do MariaDB casaria "txt:M" com "txt:m" — a
        // comparação precisa ser EXATA, feita em PHP (=== ), não no SQL.
        MlAnuncioCriativoKit::create([
            'token'           => Str::random(32),
            'status'          => MlAnuncioCriativoKit::STATUS_PLANEJADO,
            'pub_rascunho_id' => $rascunho->id,
            'pub_grupo'       => 'txt:M',
        ]);

        $this->assertNull(MlAnuncioCriativoKit::retomavelDoPublicador($rascunho->id, 'txt:m'));

        $encontrado = MlAnuncioCriativoKit::retomavelDoPublicador($rascunho->id, 'txt:M');
        $this->assertNotNull($encontrado);
    }

    public function test_ultimo_aprovado_do_publicador_acha_o_kit_aprovado_do_grupo(): void
    {
        [, $rascunho] = $this->produtoComRascunho();

        $aprovado = MlAnuncioCriativoKit::create([
            'token'           => Str::random(32),
            'status'          => MlAnuncioCriativoKit::STATUS_APROVADO,
            'pub_rascunho_id' => $rascunho->id,
            'pub_grupo'       => 'COLOR:Azul',
        ]);

        $encontrado = MlAnuncioCriativoKit::ultimoAprovadoDoPublicador($rascunho->id, 'COLOR:Azul');

        $this->assertNotNull($encontrado);
        $this->assertSame($aprovado->id, $encontrado->id);
    }

    public function test_relacoes_pub_rascunho_e_pub_imagem_resolvem(): void
    {
        [, $rascunho] = $this->produtoComRascunho();

        $imagem = PubImagem::create([
            'rascunho_id' => $rascunho->id,
            'sha256'      => str_repeat('a', 64),
            'mime'        => 'image/jpeg',
        ]);

        $criativo = MlAnuncioCriativo::create([
            'token'           => Str::random(32),
            'slot'            => 'hero',
            'status'          => MlAnuncioCriativo::STATUS_APROVADO,
            'pub_rascunho_id' => $rascunho->id,
            'pub_imagem_id'   => $imagem->id,
        ]);

        $this->assertSame($rascunho->id, $criativo->pubRascunho->id);
        $this->assertSame($imagem->id, $criativo->pubImagem->id);

        $kit = MlAnuncioCriativoKit::create([
            'token'           => Str::random(32),
            'status'          => MlAnuncioCriativoKit::STATUS_PLANEJANDO,
            'pub_rascunho_id' => $rascunho->id,
        ]);

        $this->assertSame($rascunho->id, $kit->pubRascunho->id);
    }
}
