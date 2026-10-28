<?php

namespace Tests\Feature\Publicador;

use App\Models\PubImagem;
use App\Services\Publicador\EditorRascunhoService;
use App\Services\Publicador\RascunhoRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Phase165\Concerns\CenarioCriativoDoPublicador;
use Tests\TestCase;

/**
 * "Trocar foto" no editor do Publicador (27/10/2026). Pedido do usuário: "poder mudar a foto, excluir, mover para os
 * lados". A foto nova fica NO LUGAR da antiga (a capa trocada continua capa), a antiga sai só daquele grupo e é
 * apagada quando não sobrou em lugar nenhum — a mesma regra do "remover" da tela.
 */
class TrocarFotoTest extends TestCase
{
    use CenarioCriativoDoPublicador;
    use RefreshDatabase;

    /** Faixa 1600 desta classe (as outras em uso estão listadas em `ColocarFotoNoGrupoSobTravaTest::fotoSemGrupo()`). */
    private int $seq = 0;

    private function fotoNoGrupo(string $grupo): PubImagem
    {
        $bytes = self::jpeg(1600 + (++$this->seq));
        $sha = hash('sha256', $bytes);
        $caminho = "publicador/{$this->r->id}/{$sha}.jpg";
        Storage::disk('local')->put($caminho, $bytes);
        $foto = $this->r->imagens()->create([
            'caminho' => $caminho, 'sha256' => $sha, 'mime' => 'image/jpeg', 'bytes' => strlen($bytes),
            'largura' => 1200, 'altura' => 1200, 'upload_status' => PubImagem::PENDENTE,
        ]);
        $atuais = app(RascunhoRepository::class)->snapshot($this->r->fresh())->imagens;
        $doGrupo = array_values(array_filter($atuais, fn ($a) => $a['grupo'] === $grupo));
        app(RascunhoRepository::class)->gravarAtribuicoes($this->r->fresh(), [
            ...$atuais, ['imagem' => $foto->id, 'grupo' => $grupo, 'posicao' => count($doGrupo)],
        ]);

        return $foto->fresh();
    }

    /** @return list<string> os ids do grupo, na ordem do anúncio */
    private function ordem(string $grupo): array
    {
        return collect(app(RascunhoRepository::class)->snapshot($this->r->fresh())->imagens)
            ->where('grupo', $grupo)->sortBy('posicao')->pluck('imagem')->map(fn ($id) => (string) $id)->values()->all();
    }

    private function trocar(PubImagem|int $antiga, ?string $grupo = null, ?UploadedFile $arquivo = null)
    {
        return $this->withoutVite()->actingAs($this->admin())->post(
            route('mlb.anuncios.publicador.fotos', ['produto' => $this->produto->id]),
            array_filter(['imagem' => $arquivo ?? UploadedFile::fake()->image('nova.jpg', 1300, 1300), 'grupo' => $grupo,
                'substituir' => $antiga instanceof PubImagem ? $antiga->id : $antiga]),
            ['Accept' => 'application/json'],
        );
    }

    public function test_a_capa_trocada_continua_capa_e_a_antiga_some(): void
    {
        $this->montarCenarioCriativo('mlb_empresa');
        $capa = $this->r->imagens()->firstOrFail();
        $segunda = $this->fotoNoGrupo('GENERAL');
        $this->assertSame([(string) $capa->id, (string) $segunda->id], $this->ordem('GENERAL'));

        $resposta = $this->trocar($capa)->assertOk()->json();

        $nova = $resposta['foto']['id'];
        $this->assertNotSame((string) $capa->id, $nova);
        $this->assertSame([$nova, (string) $segunda->id], $this->ordem('GENERAL'), 'a nova assume a posição 0 (capa)');
        $this->assertNull(PubImagem::find($capa->id), 'a antiga não estava em outro grupo: é apagada');
        $this->assertSame([0, 1], collect($resposta['atribuicoes'])->where('grupo', 'GENERAL')->sortBy('posicao')->pluck('posicao')->values()->all());
    }

    public function test_foto_do_meio_e_trocada_no_lugar_sem_mexer_nas_vizinhas(): void
    {
        $this->montarCenarioCriativo('mlb_empresa');
        $capa = $this->r->imagens()->firstOrFail();
        $meio = $this->fotoNoGrupo('GENERAL');
        $fim = $this->fotoNoGrupo('GENERAL');

        $nova = $this->trocar($meio)->assertOk()->json('foto.id');

        $this->assertSame([(string) $capa->id, $nova, (string) $fim->id], $this->ordem('GENERAL'));
    }

    public function test_foto_que_tambem_esta_em_outro_grupo_sai_so_deste_e_nao_e_apagada(): void
    {
        $this->montarCenarioCriativo('mlb_empresa');
        $azul = $this->comVariacaoDeCor();
        $comum = $this->fotoNoGrupo($azul);
        // A mesma foto também nas gerais.
        $atuais = app(RascunhoRepository::class)->snapshot($this->r->fresh())->imagens;
        app(RascunhoRepository::class)->gravarAtribuicoes($this->r->fresh(), [...$atuais, ['imagem' => $comum->id, 'grupo' => 'GENERAL', 'posicao' => 1]]);
        $geralAntes = $this->ordem('GENERAL');

        $nova = $this->trocar($comum, $azul)->assertOk()->json('foto.id');

        $this->assertSame([$nova], $this->ordem($azul));
        $this->assertSame($geralAntes, $this->ordem('GENERAL'), 'o outro grupo fica como estava');
        $this->assertNotNull(PubImagem::find($comum->id), 'segue nas gerais: não é apagada');
    }

    public function test_foto_recusada_nao_troca_nada(): void
    {
        $this->montarCenarioCriativo('mlb_empresa');
        $capa = $this->r->imagens()->firstOrFail();
        $revisao = $this->r->fresh()->revisao;

        // 100 px é menos que o mínimo do Mercado Livre (V-IMG-03): a foto é recusada antes de ser guardada.
        $resposta = $this->trocar($capa, null, UploadedFile::fake()->image('pequena.jpg', 100, 100))->assertOk()->json();

        $this->assertNull($resposta['foto']['id']);
        $this->assertNotEmpty(array_filter($resposta['foto']['problemas'], fn ($p) => $p['severidade'] === 'BLOCKER'));
        $this->assertSame([(string) $capa->id], $this->ordem('GENERAL'));
        $this->assertNotNull(PubImagem::find($capa->id));
        $this->assertSame($revisao, $this->r->fresh()->revisao);
    }

    public function test_foto_que_nao_e_deste_rascunho_da_404_e_nao_toca_em_nada(): void
    {
        $this->montarCenarioCriativo('mlb_empresa');
        $antes = $this->ordem('GENERAL');

        // A antiga é achada pelo rascunho do produto autorizado: id de fora dele (de outro rascunho, ou inexistente) é 404.
        $this->trocar((int) PubImagem::query()->max('id') + 999)->assertNotFound();

        $this->assertSame($antes, $this->ordem('GENERAL'));
        $this->assertSame(1, $this->r->imagens()->count(), 'nenhuma foto nova foi guardada');
    }

    public function test_mesmo_arquivo_de_outra_posicao_vai_para_o_lugar_da_antiga_sem_repetir(): void
    {
        $this->montarCenarioCriativo('mlb_empresa');
        $capa = $this->r->imagens()->firstOrFail();
        $segunda = $this->fotoNoGrupo('GENERAL');

        // A "nova" é a 2ª, já no grupo (o receber deduplica pelo sha256): ela vira a capa e a antiga sai.
        $orfa = app(EditorRascunhoService::class)->trocarFotoNoGrupo($this->r->fresh(), $segunda, 'GENERAL', $capa->id);

        $this->assertTrue($orfa);
        $this->assertSame([(string) $segunda->id], $this->ordem('GENERAL'));
    }

    public function test_antiga_fora_do_grupo_nova_entra_no_fim_e_a_antiga_fica(): void
    {
        $this->montarCenarioCriativo('mlb_empresa');
        $capa = $this->r->imagens()->firstOrFail();
        // Foto guardada sem grupo nenhum (a do cliente que a IA usa de referência): não é desta troca.
        $referencia = $this->r->imagens()->create([
            'caminho' => 'publicador/ref.jpg', 'sha256' => str_repeat('c', 64), 'mime' => 'image/jpeg', 'bytes' => 1000,
            'largura' => 1200, 'altura' => 1200, 'upload_status' => PubImagem::PENDENTE,
        ]);
        $nova = $this->fotoNoGrupo('OUTRO');

        $orfa = app(EditorRascunhoService::class)->trocarFotoNoGrupo($this->r->fresh(), $nova, 'GENERAL', $referencia->id);

        $this->assertFalse($orfa, 'a referência não estava no grupo: quem chama não a apaga');
        $this->assertSame([(string) $capa->id, (string) $nova->id], $this->ordem('GENERAL'));
    }

    public function test_trocar_pela_mesma_foto_nao_grava_nada(): void
    {
        $this->montarCenarioCriativo('mlb_empresa');
        $capa = $this->r->imagens()->firstOrFail();
        $revisao = $this->r->fresh()->revisao;

        $this->assertFalse(app(EditorRascunhoService::class)->trocarFotoNoGrupo($this->r->fresh(), $capa, 'GENERAL', $capa->id));
        $this->assertSame($revisao, $this->r->fresh()->revisao);
    }
}
