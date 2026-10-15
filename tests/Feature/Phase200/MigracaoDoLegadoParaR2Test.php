<?php

namespace Tests\Feature\Phase200;

use App\Models\AcervoImagem;
use App\Models\Company;
use App\Models\PubImagem;
use App\Models\PubProduto;
use App\Models\PubRascunho;
use App\Support\Publicador\Imagem\ArquivosDeImagem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Fase 200 (v28.0) — migração local → R2 (MIG-02/MIG-03) e o relatório só de leitura (MIG-01, RET-01/02).
 */
class MigracaoDoLegadoParaR2Test extends TestCase
{
    use RefreshDatabase;

    private Company $empresa;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Storage::fake('r2');
        // Antes da virada: tudo grava e lê no local.
        config(['filesystems.imagens' => 'local']);
        $this->empresa = Company::factory()->create();
    }

    private function rascunho(string $sku): PubRascunho
    {
        $p = PubProduto::create(['company_id' => $this->empresa->id, 'sku' => $sku, 'nome' => "Produto {$sku}", 'origem' => PubProduto::ORIGEM_PUBLICADOR]);

        return PubRascunho::create(['produto_id' => $p->id]);
    }

    /** Foto antiga (caminho por rascunho) já registrada no acervo pelo backfill, com uma cópia redundante. */
    private function legadoComCopia(string $conteudo = 'jpg-original'): array
    {
        $sha = hash('sha256', $conteudo);
        $r1 = $this->rascunho('CAD-01');
        $r2 = $this->rascunho('CAD-02');
        $c1 = "publicador/{$r1->id}/{$sha}.jpg";
        $c2 = "publicador/{$r2->id}/{$sha}.jpg";
        Storage::disk('local')->put($c1, $conteudo);
        Storage::disk('local')->put($c2, $conteudo);

        $ativo = AcervoImagem::create(['company_id' => $this->empresa->id, 'dono_chave' => 'c'.$this->empresa->id, 'disco' => 'local',
            'caminho' => $c1, 'sha256' => $sha, 'mime' => 'image/jpeg', 'bytes' => strlen($conteudo), 'origem' => 'upload', 'status' => 'disponivel']);
        $usos = [];
        foreach ([[$r1, $c1], [$r2, $c2]] as [$r, $c]) {
            $usos[] = $r->imagens()->create(['caminho' => $c, 'acervo_imagem_id' => $ativo->id, 'sha256' => $sha, 'mime' => 'image/jpeg',
                'bytes' => strlen($conteudo), 'upload_status' => PubImagem::ENVIADA, 'ml_picture_id' => 'X']);
        }

        return [$ativo, $usos, [$c1, $c2]];
    }

    /** MIG-02: simulação não mexe; aplicar copia, confere sha, repõe ativo e usos na chave canônica; locais ficam. */
    public function test_migra_conferindo_sha_e_preserva_os_locais(): void
    {
        [$ativo, $usos, $locais] = $this->legadoComCopia();
        $chave = AcervoImagem::caminhoPara($ativo->dono_chave, $ativo->sha256, 'image/jpeg');

        $this->artisan('acervo:migrar-para-r2')->assertSuccessful();
        $this->assertSame([], Storage::disk('r2')->allFiles());
        $this->assertSame('local', $ativo->fresh()->disco);

        $this->artisan('acervo:migrar-para-r2', ['--aplicar' => true])->assertSuccessful();

        $ativo->refresh();
        $this->assertSame(['r2', $chave], [$ativo->disco, $ativo->caminho]);
        $this->assertNotNull($ativo->migrado_em);
        $this->assertEqualsCanonicalizing($locais, $ativo->caminhos_legados);
        $this->assertSame('jpg-original', Storage::disk('r2')->get($chave));
        foreach ($usos as $uso) {
            $this->assertSame($chave, $uso->fresh()->caminho);
        }
        foreach ($locais as $c) {
            Storage::disk('local')->assertExists($c);
        }
    }

    /** MIG-02: rodar de novo não duplica nem regrava. */
    public function test_reexecucao_e_idempotente(): void
    {
        [$ativo] = $this->legadoComCopia();
        $this->artisan('acervo:migrar-para-r2', ['--aplicar' => true])->assertSuccessful();
        $migradoEm = $ativo->fresh()->migrado_em;

        $this->artisan('acervo:migrar-para-r2', ['--aplicar' => true])->assertSuccessful();

        $this->assertCount(1, Storage::disk('r2')->allFiles());
        $this->assertEquals($migradoEm, $ativo->fresh()->migrado_em);
    }

    /** Sha que não confere não migra (arquivo local corrompido ou trocado). */
    public function test_sha_divergente_nao_migra(): void
    {
        [$ativo, , $locais] = $this->legadoComCopia();
        Storage::disk('local')->put($locais[0], 'conteudo-trocado');

        $this->artisan('acervo:migrar-para-r2', ['--aplicar' => true])->assertSuccessful();

        $this->assertSame('local', $ativo->fresh()->disco);
        $this->assertSame([], Storage::disk('r2')->allFiles());
    }

    /** MIG-03: rollback — com a chave de volta em `local` e o bucket configurado, a foto migrada segue legível. */
    public function test_rollback_continua_lendo_do_r2(): void
    {
        [$ativo, $usos] = $this->legadoComCopia();
        $this->artisan('acervo:migrar-para-r2', ['--aplicar' => true])->assertSuccessful();
        config(['filesystems.imagens' => 'local', 'filesystems.disks.r2.bucket' => 'ecf-imagens']);

        $this->assertSame('jpg-original', ArquivosDeImagem::ler($usos[0]->fresh()->caminho));
    }

    /** MIG-03: a limpeza local respeita a janela e nunca apaga caminho que algo ainda aponta. */
    public function test_limpeza_local_respeita_janela_e_referencias(): void
    {
        [$ativo, , $locais] = $this->legadoComCopia();
        $this->artisan('acervo:migrar-para-r2', ['--aplicar' => true])->assertSuccessful();
        // Uma foto SEM ativo (produto sem conta) ainda aponta para um dos caminhos antigos.
        $this->rascunho('SEM-01')->imagens()->create(['caminho' => $locais[1], 'sha256' => str_repeat('d', 64), 'mime' => 'image/jpeg',
            'bytes' => 1, 'upload_status' => PubImagem::PENDENTE]);

        $this->artisan('acervo:limpar-local-migrado', ['--aplicar' => true])->assertSuccessful();
        Storage::disk('local')->assertExists($locais[0]);

        $this->travel(31)->days();
        $this->artisan('acervo:limpar-local-migrado')->assertSuccessful();
        Storage::disk('local')->assertExists($locais[0]);

        $this->artisan('acervo:limpar-local-migrado', ['--aplicar' => true])->assertSuccessful();
        Storage::disk('local')->assertMissing($locais[0]);
        Storage::disk('local')->assertExists($locais[1]);
        $this->assertSame([$locais[1]], $ativo->fresh()->caminhos_legados);
    }

    /** MIG-01/RET-01/RET-02: o relatório roda, mostra os números e não altera nada. */
    public function test_relatorio_e_so_de_leitura(): void
    {
        [$ativo] = $this->legadoComCopia();
        Storage::disk('local')->put('publicador/999/orfao.jpg', 'x');
        $arquivosAntes = Storage::disk('local')->allFiles();
        $antes = AcervoImagem::query()->get()->toArray();

        $this->artisan('acervo:relatorio', ['--orfaos' => true])
            ->expectsOutputToContain('Disco local da VPS')
            ->expectsOutputToContain('c'.$this->empresa->id)
            ->expectsOutputToContain('Candidatos a descarte')
            ->expectsOutputToContain('nada foi alterado')
            ->assertSuccessful();

        $this->assertSame($arquivosAntes, Storage::disk('local')->allFiles());
        $this->assertSame($antes, AcervoImagem::query()->get()->toArray());
    }
}
