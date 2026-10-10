<?php

namespace Tests\Feature\Publicador;

use App\Jobs\GerarCriativoIaJob;
use App\Jobs\PlanejarKitCriativosJob;
use App\Jobs\Publicador\AvaliarCriativosAutomaticosJob;
use App\Jobs\Publicador\GerarCriativosAutomaticosJob;
use App\Jobs\Publicador\GerarPreparoIaJob;
use App\Models\Configuracao;
use App\Models\EstruturaProduto;
use App\Models\EstruturaProdutoAtributo;
use App\Models\MlAnuncioCriativo;
use App\Models\MlAnuncioCriativoKit;
use App\Models\PubImagem;
use App\Models\PubProduto;
use App\Models\PubRascunho;
use App\Models\User;
use App\Services\Creative\CreativeEngineAtivo;
use App\Services\Publicador\Criativos\CriativosAutomaticosService;
use App\Services\Publicador\RascunhoRepository;
use App\Support\Publicador\Imagem\ResolvedorGruposImagem as R;
use App\Support\Publicador\Payload\Alvo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Publicador\Lote\CenarioDoPreparo;
use Tests\TestCase;

/**
 * Imagens por IA automáticas (10/10/2026): o gatilho está PRONTO e DESLIGADO. Desligado por padrão, nada entra na
 * cadeia do preparo nem é planejado. Ligado, só age com TODAS as condições (chave + Creative Engine + empresa na
 * lista, não é kit da Fase N, rascunho não publicado, ficha completa, usuário de sistema com `mlb.criativos_ia`,
 * foto com arquivo no grupo, nenhum kit ativo/aprovado no grupo, fatos novos, teto diário) — e então planeja o kit
 * com as fotos do cliente e encadeia a geração. O Creative Engine fica em `Queue::fake()`: nenhuma IA de verdade.
 */
class CriativosAutomaticosTest extends TestCase
{
    use CenarioDoPreparo;
    use RefreshDatabase;

    private User $sistema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->montarPreparo();
        $this->sistema = User::factory()->create(['role' => 'admin', 'name' => 'Sistema Imagens']);
    }

    private function ligar(): void
    {
        config(['publicador.criativos_auto.ativo' => true]);
        Configuracao::set(CreativeEngineAtivo::CHAVE, '1');
        Configuracao::set(CriativosAutomaticosService::CHAVE_EMPRESAS, (string) $this->empresa->id);
        Configuracao::set(CriativosAutomaticosService::CHAVE_USUARIO, (string) $this->sistema->id);
    }

    private function servico(): CriativosAutomaticosService
    {
        return app(CriativosAutomaticosService::class);
    }

    /** O produto do Portal preparado uma vez (rascunho existe) e com `$n` fotos do cliente na galeria geral. */
    private function produtoComFotos(int $n = 2, string $codigo = 'CAD'): array
    {
        $p = $this->produtoDoPortal($codigo);
        $this->salvarERodar($p);
        $r = $this->rascunhoDo($p);
        $atribuicoes = [];
        for ($i = 0; $i < $n; $i++) {
            $caminho = "publicador/{$codigo}-{$i}.jpg";
            Storage::disk('local')->put($caminho, 'jpeg-'.$i);
            $foto = $r->imagens()->create(['caminho' => $caminho, 'sha256' => hash('sha256', $codigo.$i), 'mime' => 'image/jpeg', 'bytes' => 1000,
                'largura' => 1200, 'altura' => 1200, 'upload_status' => PubImagem::PENDENTE]);
            $atribuicoes[] = ['imagem' => $foto->id, 'grupo' => R::GERAL, 'posicao' => $i];
        }
        (new RascunhoRepository())->gravarAtribuicoes($r, $atribuicoes);

        return [$p, $r->fresh()];
    }

    public function test_desligado_por_padrao_nada_entra_na_cadeia_nem_e_planejado(): void
    {
        // Tudo o mais ligado — só a chave nova no padrão (false).
        Configuracao::set(CreativeEngineAtivo::CHAVE, '1');
        Configuracao::set(CriativosAutomaticosService::CHAVE_EMPRESAS, (string) $this->empresa->id);
        Configuracao::set(CriativosAutomaticosService::CHAVE_USUARIO, (string) $this->sistema->id);
        $this->assertFalse(config('publicador.criativos_auto.ativo'));

        [$p, $r] = $this->produtoComFotos();

        Queue::assertPushedWithChain(GerarPreparoIaJob::class, [GerarPreparoIaJob::class, GerarPreparoIaJob::class]);
        Queue::assertNotPushed(AvaliarCriativosAutomaticosJob::class);
        $this->assertSame('desligado', $this->servico()->avaliar($r->id));
        $this->assertSame(0, MlAnuncioCriativoKit::query()->count());
        Queue::assertNotPushed(PlanejarKitCriativosJob::class);
    }

    public function test_ligado_o_ultimo_elo_do_preparo_planeja_o_kit_com_as_fotos_e_encadeia_a_geracao(): void
    {
        $this->ligar();
        $p = $this->produtoDoPortal();
        $this->salvarERodar($p);
        Queue::assertPushedWithChain(GerarPreparoIaJob::class, [GerarPreparoIaJob::class, GerarPreparoIaJob::class, AvaliarCriativosAutomaticosJob::class]);
        $this->assertSame('creative', collect(Queue::pushed(GerarPreparoIaJob::class))->first()->chained === [] ? null
            : unserialize(collect(Queue::pushed(GerarPreparoIaJob::class))->first()->chained[2])->queue, 'o elo das imagens fica na fila `creative`');

        [, $r] = [$p, $this->rascunhoDo($p)];
        $this->assertSame('sem_foto', $this->servico()->avaliar($r->id), 'sem foto do cliente, nada');
        [$p2, $r2] = $this->produtoComFotos(2, 'MES');

        $this->assertSame('planejando', $this->servico()->avaliar($r2->id));

        $kit = MlAnuncioCriativoKit::query()->where('pub_rascunho_id', $r2->id)->sole();
        $this->assertSame(R::GERAL, $kit->pub_grupo);
        $this->assertSame(MlAnuncioCriativoKit::STATUS_PLANEJANDO, $kit->status);
        $this->assertSame($this->sistema->id, (int) $kit->user_id, 'quem paga a cota é o usuário de sistema');
        $portador = MlAnuncioCriativo::query()->findOrFail($kit->criativo_referencia_id);
        $this->assertCount(2, (array) $portador->referencias, 'as duas fotos do cliente vão de referência');
        Queue::assertPushedWithChain(PlanejarKitCriativosJob::class, [GerarCriativosAutomaticosJob::class]);
        Queue::assertPushed(PlanejarKitCriativosJob::class, fn ($j) => $j->kitId === $kit->id && $j->tiposFixos === ['lifestyle', 'hero']);
        $this->assertSame($kit->id, (int) $r2->fresh()->step_state['criativos_auto']['kit_id']);

        // Um kit ativo por grupo (D-15) e a aprovação é de gente: nada planejado de novo.
        $this->assertSame('kit_existente', $this->servico()->avaliar($r2->id));
        $this->assertSame(1, MlAnuncioCriativoKit::query()->count());
    }

    public function test_cada_condicao_barra_o_gatilho(): void
    {
        $this->ligar();
        [$p, $r] = $this->produtoComFotos();
        $s = fn () => $this->servico()->avaliar($r->id);

        Configuracao::set(CriativosAutomaticosService::CHAVE_EMPRESAS, '999');
        $this->assertSame('desligado', $s(), 'empresa fora da lista');
        Configuracao::set(CriativosAutomaticosService::CHAVE_EMPRESAS, (string) $this->empresa->id);

        Configuracao::set(CreativeEngineAtivo::CHAVE, '0');
        $this->assertSame('desligado', $s(), 'Creative Engine desligado');
        Configuracao::set(CreativeEngineAtivo::CHAVE, '1');

        $r->update(['status' => PubRascunho::PUBLISHED]);
        $this->assertSame('intocavel', $s());
        $r->update(['status' => PubRascunho::DRAFT]);

        Configuracao::set(CriativosAutomaticosService::CHAVE_USUARIO, '');
        $this->assertSame('sem_usuario', $s());
        $this->sistema->update(['active' => false]);
        Configuracao::set(CriativosAutomaticosService::CHAVE_USUARIO, (string) $this->sistema->id);
        $this->assertSame('sem_usuario', $s(), 'usuário inativo não paga cota');
        $this->sistema->update(['active' => true]);
        $semChave = User::factory()->create(['role' => 'consultor']);
        Configuracao::set(CriativosAutomaticosService::CHAVE_USUARIO, (string) $semChave->id);
        $this->assertSame('sem_permissao', $s(), 'sem mlb.criativos_ia');
        Configuracao::set(CriativosAutomaticosService::CHAVE_USUARIO, (string) $this->sistema->id);

        $estrutura = EstruturaProduto::query()->findOrFail($p->id);
        $marca = EstruturaProdutoAtributo::query()->where('produto_id', $estrutura->id)->where('atributo_id', 'BRAND')->first();
        $marca?->update(['valor' => '', 'valor_id' => null]);
        $this->assertSame('incompleto', $s(), 'ficha do Portal incompleta');
        $marca?->update(['valor' => 'ECF']);

        Storage::disk('local')->deleteDirectory('publicador');
        $this->assertSame('sem_foto', $s(), 'foto sem arquivo no disco não serve de referência');

        $this->assertSame(0, MlAnuncioCriativoKit::query()->count(), 'nenhuma barreira deixou kit para trás');
    }

    public function test_kit_da_fase_mesmos_fatos_e_teto_diario(): void
    {
        $this->ligar();
        [$p, $r] = $this->produtoComFotos();

        $base = $this->pubDo($p);
        $kit = PubProduto::create(['company_id' => $this->empresa->id, 'sku' => 'CAD-KIT2', 'nome' => 'Kit 2 Cadeira', 'origem' => PubProduto::ORIGEM_PUBLICADOR,
            'produto_base_id' => $base->id, 'fase' => 2, 'quantidade_kit' => 2]);
        $rk = (new RascunhoRepository())->criar($kit, [new Alvo('gold_special', null)]);
        $this->assertSame('kit_da_fase', $this->servico()->avaliar($rk->id), 'o kit da Fase N tem a capa própria');

        $this->assertSame('planejando', $this->servico()->avaliar($r->id));
        // O kit deu erro (o operador o descartaria): com os MESMOS fatos, não gera de novo.
        MlAnuncioCriativoKit::query()->update(['status' => MlAnuncioCriativoKit::STATUS_ERRO]);
        $this->assertSame('em_dia', $this->servico()->avaliar($r->id));
        // Foto nova = fatos novos = gera.
        $foto = $r->imagens()->create(['caminho' => 'publicador/nova.jpg', 'sha256' => hash('sha256', 'nova'), 'mime' => 'image/jpeg', 'bytes' => 1, 'largura' => 1200, 'altura' => 1200, 'upload_status' => PubImagem::PENDENTE]);
        Storage::disk('local')->put('publicador/nova.jpg', 'x');
        $atuais = (new RascunhoRepository())->snapshot($r->fresh())->imagens;
        (new RascunhoRepository())->gravarAtribuicoes($r, [...$atuais, ['imagem' => $foto->id, 'grupo' => R::GERAL, 'posicao' => 9]]);
        config(['publicador.criativos_auto.limite_diario_por_empresa' => 1]);
        $this->assertSame('limite', $this->servico()->avaliar($r->id), 'o teto do dia já foi gasto pelo primeiro');
        config(['publicador.criativos_auto.limite_diario_por_empresa' => 5]);
        $this->assertSame('planejando', $this->servico()->avaliar($r->id));
    }

    public function test_gerar_despacha_as_imagens_so_com_o_kit_planejado_e_a_chave_ligada(): void
    {
        $this->ligar();
        [, $r] = $this->produtoComFotos();
        $this->servico()->avaliar($r->id);
        $kit = MlAnuncioCriativoKit::query()->sole();

        $this->assertSame('nao_planejado', $this->servico()->gerar($kit->id), 'o planejamento ainda não terminou');

        // O que o `PlanejarKitCriativosJob` deixaria: o kit planejado com os dois slots pendentes.
        $kit->update(['status' => MlAnuncioCriativoKit::STATUS_PLANEJADO, 'total_slots' => 2]);
        foreach (['lifestyle', 'hero'] as $i => $tipo) {
            MlAnuncioCriativo::create(['token' => str_repeat((string) $i, 32), 'company_id' => $kit->company_id, 'pub_rascunho_id' => null, 'user_id' => $this->sistema->id,
                'kit_id' => $kit->id, 'slot' => $tipo, 'slot_indice' => $i, 'slot_plano' => ['tipo' => $tipo], 'status' => MlAnuncioCriativo::STATUS_PENDENTE]);
        }

        config(['publicador.criativos_auto.ativo' => false]);
        $this->assertSame('desligado', $this->servico()->gerar($kit->id), 'desligar no meio para a geração (é aqui que o dinheiro sai)');
        Queue::assertNotPushed(GerarCriativoIaJob::class);

        config(['publicador.criativos_auto.ativo' => true]);
        $this->assertSame('gerando:2', $this->servico()->gerar($kit->id));
        Queue::assertPushed(GerarCriativoIaJob::class, 2);
        $this->assertSame(MlAnuncioCriativoKit::STATUS_GERANDO, $kit->fresh()->status);
    }

    public function test_sem_cadeia_de_texto_o_elo_das_imagens_entra_sozinho(): void
    {
        $this->ligar();
        [$p] = $this->produtoComFotos();
        // Rodar a cadeia inteira (texto escrito, fatos iguais): o próximo save não gera texto.
        $this->rodar(GerarPreparoIaJob::class);
        $antes = count(Queue::pushed(AvaliarCriativosAutomaticosJob::class));

        $this->salvarERodar($p);

        $this->assertSame($antes + 1, count(Queue::pushed(AvaliarCriativosAutomaticosJob::class)), 'o texto estava em dia, as fotos podem ter chegado agora');
    }
}
