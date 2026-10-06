<?php

namespace Tests\Feature\Phase165;

use App\Models\PubImagem;
use App\Models\PubRascunho;
use App\Services\Publicador\EditorRascunhoService;
use App\Services\Publicador\RascunhoRepository;
use App\Support\Publicador\RascunhoSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Phase165\Concerns\CenarioCriativoDoPublicador;
use Tests\TestCase;

/**
 * Fase 165, Plano 03, Task 1 — `colocarFotoNoGrupo` sob a trava do rascunho
 * (T-165-09, WR-B02): upload manual (`foto()`) e aprovação de criativo (Task 2
 * deste plano) usam o MESMO método, que trava a linha ANTES de ler o snapshot.
 */
class ColocarFotoNoGrupoSobTravaTest extends TestCase
{
    use CenarioCriativoDoPublicador;
    use RefreshDatabase;

    /**
     * Uma foto com arquivo em disco, SEM atribuição em nenhum grupo ainda — com
     * conteúdo ÚNICO (`jpeg()` de mesmo lado devolve os mesmos bytes, e
     * `pub_imagens` tem unique por `(rascunho_id, sha256)`).
     */
    private function fotoSemGrupo(): PubImagem
    {
        $bytes = self::jpeg(1200 + random_int(1, 200));
        $sha = hash('sha256', $bytes);
        $caminho = "publicador/{$this->r->id}/{$sha}.jpg";
        Storage::disk('local')->put($caminho, $bytes);

        return $this->r->imagens()->create([
            'caminho' => $caminho, 'sha256' => $sha, 'mime' => 'image/jpeg', 'bytes' => strlen($bytes),
            'largura' => 1200, 'altura' => 1200, 'upload_status' => PubImagem::PENDENTE,
        ]);
    }

    /** `fotoSemGrupo()` já atribuída no FIM do grupo pedido (molde de `colocarFotoNoGrupo`). */
    private function fotoNoGrupo(string $grupo): PubImagem
    {
        $foto = $this->fotoSemGrupo();
        $atuais = app(RascunhoRepository::class)->snapshot($this->r->fresh())->imagens;
        $doGrupo = array_values(array_filter($atuais, fn ($a) => $a['grupo'] === $grupo));
        app(RascunhoRepository::class)->gravarAtribuicoes($this->r->fresh(), [
            ...$atuais, ['imagem' => $foto->id, 'grupo' => $grupo, 'posicao' => count($doGrupo)],
        ]);

        return $foto->fresh();
    }

    public function test_espiao_travar_acontece_antes_de_snapshot(): void
    {
        $this->montarCenarioCriativo('mlb_empresa');
        $grupo = $this->comVariacaoDeCor();
        $foto = $this->fotoSemGrupo();

        $espiao = new class extends RascunhoRepository
        {
            public array $ordem = [];

            public function travar(PubRascunho $r): void
            {
                $this->ordem[] = 'travar';
                parent::travar($r);
            }

            public function snapshot(PubRascunho $r): RascunhoSnapshot
            {
                $this->ordem[] = 'snapshot';

                return parent::snapshot($r);
            }
        };
        $this->app->instance(RascunhoRepository::class, $espiao);
        $editorEspiao = app(EditorRascunhoService::class);

        $editorEspiao->colocarFotoNoGrupo($this->r->fresh(), $foto, $grupo);

        $this->assertSame(['travar', 'snapshot'], $espiao->ordem, 'a trava precisa acontecer ANTES da leitura do snapshot');
    }

    public function test_foto_entra_no_fim_do_grupo_sem_mexer_nas_que_ja_estavam_la(): void
    {
        $this->montarCenarioCriativo('mlb_empresa');
        $grupo = $this->comVariacaoDeCor();
        $a = $this->fotoNoGrupo($grupo);
        $b = $this->fotoNoGrupo($grupo);
        $nova = $this->fotoSemGrupo();

        app(EditorRascunhoService::class)->colocarFotoNoGrupo($this->r->fresh(), $nova, $grupo);

        $doGrupo = collect(app(RascunhoRepository::class)->snapshot($this->r->fresh())->imagens)
            ->where('grupo', $grupo)
            ->sortBy('posicao')
            ->values();
        $this->assertSame([(string) $a->id, (string) $b->id, (string) $nova->id], $doGrupo->pluck('imagem')->all());
        $this->assertSame([0, 1, 2], $doGrupo->pluck('posicao')->all());

        // A foto da galeria geral que `montarCenario()` já tinha criado não se move.
        $geral = collect(app(RascunhoRepository::class)->snapshot($this->r->fresh())->imagens)->where('grupo', 'GENERAL');
        $this->assertSame([0], $geral->pluck('posicao')->all());
    }

    public function test_foto_ja_no_grupo_nao_grava_nem_sobe_a_revisao(): void
    {
        $this->montarCenarioCriativo('mlb_empresa');
        $grupo = $this->comVariacaoDeCor();
        $foto = $this->fotoNoGrupo($grupo);
        $revisaoAntes = $this->r->fresh()->revisao;
        $antesDasAtribuicoes = count(app(RascunhoRepository::class)->snapshot($this->r->fresh())->imagens);

        app(EditorRascunhoService::class)->colocarFotoNoGrupo($this->r->fresh(), $foto, $grupo);

        $this->assertSame($revisaoAntes, $this->r->fresh()->revisao, 'foto que já está no grupo não toca a revisão');
        $this->assertSame($antesDasAtribuicoes, count(app(RascunhoRepository::class)->snapshot($this->r->fresh())->imagens));
    }

    public function test_foto_nova_sobe_a_revisao_em_um(): void
    {
        $this->montarCenarioCriativo('mlb_empresa');
        $grupo = $this->comVariacaoDeCor();
        $nova = $this->fotoSemGrupo();
        $revisaoAntes = $this->r->fresh()->revisao;

        app(EditorRascunhoService::class)->colocarFotoNoGrupo($this->r->fresh(), $nova, $grupo);

        $this->assertSame($revisaoAntes + 1, $this->r->fresh()->revisao);
    }

    public function test_http_upload_manual_continua_igual_e_poe_a_foto_no_fim_do_grupo_pedido(): void
    {
        $this->montarCenarioCriativo('mlb_empresa');
        $admin = $this->admin();

        $resposta = $this->withoutVite()->actingAs($admin)->post(
            route('mlb.anuncios.publicador.fotos', ['produto' => $this->produto->id]),
            ['imagem' => UploadedFile::fake()->image('capa.jpg', 1200, 1200)],
            ['Accept' => 'application/json'],
        )->assertOk()->json();

        $this->assertTrue($resposta['foto']['nova']);
        $this->assertNotNull($resposta['foto']['id']);
        $this->assertSame([], $resposta['foto']['problemas']);

        $doGeral = collect($resposta['atribuicoes'])->where('grupo', 'GENERAL')->sortBy('posicao')->values();
        // A galeria geral já tinha 1 foto de `montarCenario()`; a nova entra na posição 1 (fim).
        $this->assertSame(2, $doGeral->count());
        $this->assertSame($resposta['foto']['id'], $doGeral->last()['imagem']);
        $this->assertSame(1, $doGeral->last()['posicao']);
    }
}
