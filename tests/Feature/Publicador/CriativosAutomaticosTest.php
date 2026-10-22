<?php

namespace Tests\Feature\Publicador;

use App\Jobs\GerarCriativoIaJob;
use App\Jobs\PlanejarKitCriativosJob;
use App\Jobs\Publicador\AplicarCriativosAutomaticosJob;
use App\Jobs\Publicador\AvaliarCriativosAutomaticosJob;
use App\Jobs\Publicador\GerarCriativosAutomaticosJob;
use App\Jobs\Publicador\GerarPreparoIaJob;
use App\Services\Publicador\PreparoIaDoRascunhoService;
use App\Models\Configuracao;
use App\Models\EstruturaOferta;
use App\Models\EstruturaOfertaComponente;
use App\Models\EstruturaProduto;
use App\Models\EstruturaProdutoAtributo;
use App\Models\EstruturaProdutoVariacao;
use App\Models\EstruturaProdutoVariacaoImagem;
use App\Models\MlAnuncioCriativo;
use App\Models\MlAnuncioCriativoKit;
use App\Models\PubImagem;
use App\Models\PubProduto;
use App\Models\PubRascunho;
use App\Models\User;
use App\Services\Creative\CreativeCategoriaMobiliarioService;
use App\Services\Creative\CreativeEngineAtivo;
use App\Services\Publicador\Criativos\CriativosAutomaticosService;
use App\Services\Publicador\CriarFaseService;
use App\Services\Publicador\EditorRascunhoService;
use App\Services\Publicador\RascunhoRepository;
use App\Support\Publicador\Imagem\ResolvedorGruposImagem as R;
use App\Support\Publicador\ImagensReprovadas;
use App\Support\Publicador\Payload\Alvo;
use App\Support\Publicador\Payload\OrdemCapaPorAlvo;
use App\Support\Publicador\Variacao\ChaveCanonica;
use App\Support\Publicador\Variacao\Eixo;
use App\Support\Publicador\Variacao\GeradorCombinacoes;
use App\Support\Publicador\Variacao\ValorEixo;
use App\Support\Publicador\Variacao\Variante;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
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

    /** O produto do Portal preparado uma vez (rascunho existe) e com `$n` fotos que o cliente pôs no Portal, já na galeria geral. */
    private function produtoComFotos(int $n = 2, string $codigo = 'CAD'): array
    {
        $p = $this->produtoDoPortal($codigo);
        $this->salvarERodar($p);
        $r = $this->rascunhoDo($p);
        for ($i = 0; $i < $n; $i++) {
            $this->fotoNoRascunho($r, "{$codigo}-{$i}", [20 + $i * 40, crc32($codigo) % 200, 90], $p);
        }

        return [$p, $r->fresh()];
    }

    /**
     * Uma foto no fim da galeria geral do rascunho. Com `$doPortal`, é a foto que o CLIENTE pôs no Portal: o mesmo
     * arquivo fica em `estrutura_produto_variacao_imagens` (como o Sincronizar a copia, mesmo sha256); sem ele, é foto
     * que a equipe subiu no Publicador.
     */
    private function fotoNoRascunho(PubRascunho $r, string $nome, array $rgb, ?EstruturaProduto $doPortal = null): PubImagem
    {
        $conteudo = $this->jpeg($rgb);
        if ($doPortal !== null) {
            $caminhoPortal = "estrutura/{$this->empresa->id}/produtos/{$nome}.jpg";
            Storage::disk('local')->put($caminhoPortal, $conteudo);
            EstruturaProdutoVariacaoImagem::create(['company_id' => $this->empresa->id, 'produto_id' => $doPortal->id,
                'variacao_id' => EstruturaProdutoVariacao::where('produto_id', $doPortal->id)->value('id'), 'caminho' => $caminhoPortal,
                'nome_original' => "{$nome}.jpg", 'mime' => 'image/jpeg', 'tamanho' => strlen($conteudo), 'largura' => 1200, 'altura' => 1200,
                'ordem' => EstruturaProdutoVariacaoImagem::where('produto_id', $doPortal->id)->count()]);
        }
        $caminho = "publicador/{$r->id}/{$nome}.jpg";
        Storage::disk('local')->put($caminho, $conteudo);
        $foto = $r->imagens()->create(['caminho' => $caminho, 'sha256' => hash('sha256', $conteudo), 'mime' => 'image/jpeg', 'bytes' => strlen($conteudo),
            'largura' => 1200, 'altura' => 1200, 'upload_status' => PubImagem::PENDENTE]);
        $repo = new RascunhoRepository();
        $atuais = $repo->snapshot($r->fresh())->imagens;
        $naGeral = count(array_filter($atuais, fn ($a) => $a['grupo'] === R::GERAL));
        $repo->gravarAtribuicoes($r, [...$atuais, ['imagem' => $foto->id, 'grupo' => R::GERAL, 'posicao' => $naGeral]]);

        return $foto;
    }

    public function test_desligado_por_padrao_nada_entra_na_cadeia_nem_e_planejado(): void
    {
        // Tudo o mais ligado — só a chave nova no padrão (false).
        Configuracao::set(CreativeEngineAtivo::CHAVE, '1');
        Configuracao::set(CriativosAutomaticosService::CHAVE_EMPRESAS, (string) $this->empresa->id);
        Configuracao::set(CriativosAutomaticosService::CHAVE_USUARIO, (string) $this->sistema->id);
        $this->assertFalse(config('publicador.criativos_auto.ativo'));

        [$p, $r] = $this->produtoComFotos();

        // 21/10/2026: [título → Modelo] e [descrição], sem o elo das imagens.
        Queue::assertPushedWithChain(GerarPreparoIaJob::class, [GerarPreparoIaJob::class], fn ($j) => $j->etapa === PreparoIaDoRascunhoService::TITULO);
        Queue::assertPushedWithoutChain(GerarPreparoIaJob::class, fn ($j) => $j->etapa === PreparoIaDoRascunhoService::DESCRICAO);
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
        // 21/10/2026: as imagens são o último elo da cadeia da DESCRIÇÃO, que roda em paralelo com [título → Modelo].
        Queue::assertPushedWithChain(GerarPreparoIaJob::class, [GerarPreparoIaJob::class], fn ($j) => $j->etapa === PreparoIaDoRascunhoService::TITULO);
        Queue::assertPushedWithChain(GerarPreparoIaJob::class, [AvaliarCriativosAutomaticosJob::class], fn ($j) => $j->etapa === PreparoIaDoRascunhoService::DESCRICAO);
        $daDescricao = collect(Queue::pushed(GerarPreparoIaJob::class))->firstWhere('etapa', PreparoIaDoRascunhoService::DESCRICAO);
        $this->assertSame('creative', unserialize($daDescricao->chained[0])->queue, 'o elo das imagens fica na fila `creative`');

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
        // "3 imagens, 2 capas" (20/10/2026): Imagem principal, Ambientação e Textura, nessa ordem fora de móvel — e, desde
        // 22/10/2026, a 2ª ambientada (`composicao`) no fim, a capa do Premium.
        Queue::assertPushed(PlanejarKitCriativosJob::class, fn ($j) => $j->kitId === $kit->id && $j->tiposFixos === ['hero', 'lifestyle', 'detail_textura', 'composicao']);
        $this->assertSame($kit->id, (int) $r2->fresh()->step_state['criativos_auto']['kit_id']);

        // Um kit ativo por grupo (D-15) e a aprovação é de gente: nada planejado de novo.
        $this->assertSame('kit_existente', $this->servico()->avaliar($r2->id));
        $this->assertSame(1, MlAnuncioCriativoKit::query()->count());
    }

    public function test_so_a_foto_que_o_cliente_pos_no_portal_dispara_a_geracao(): void
    {
        // 20/10/2026, o usuário: "a imagem só deve ser gerada automaticamente, assim como os títulos, se o cliente puser
        // uma imagem real do produto dele no Portal".
        $this->ligar();
        $p = $this->produtoDoPortal('EQP');
        $this->salvarERodar($p);
        $r = $this->rascunhoDo($p);
        $daEquipe = $this->fotoNoRascunho($r, 'da-equipe', [10, 10, 200]);

        $this->assertSame('sem_foto', $this->servico()->avaliar($r->id), 'foto que a equipe subiu no Publicador não é a do cliente');
        $this->assertSame(0, MlAnuncioCriativoKit::query()->count());

        $doCliente = $this->fotoNoRascunho($r, 'do-cliente', [10, 200, 10], $p);
        $this->assertSame('planejando', $this->servico()->avaliar($r->id));
        $portador = MlAnuncioCriativo::query()->findOrFail(MlAnuncioCriativoKit::query()->sole()->criativo_referencia_id);
        $this->assertCount(1, (array) $portador->referencias, 'só a foto do cliente vai de referência');
        $this->assertNotSame($daEquipe->id, $doCliente->id);
    }

    public function test_tres_imagens_duas_capas_e_em_movel_a_ambientacao_abre_o_classico(): void
    {
        $this->ligar();
        [, $r] = $this->produtoComFotos(1, 'MOV');
        $this->mock(CreativeCategoriaMobiliarioService::class, fn ($m) => $m->shouldReceive('ehMoveis')->andReturn(true));

        $this->assertSame('planejando', $this->servico()->avaliar($r->id));

        // 22/10/2026: a 4ª imagem é a 2ª ambientada (`composicao`), a capa do Premium, no fim da galeria.
        $tipos = ['lifestyle', 'hero', 'detail_textura', 'composicao'];
        Queue::assertPushed(PlanejarKitCriativosJob::class, fn ($j) => $j->tiposFixos === $tipos);
        // A ordem da galeria é a dos tipos; o Premium só troca a capa — nunca abre com fundo branco nem com a Textura.
        $porTipo = array_combine($tipos, $tipos);
        $this->assertSame($tipos, OrdemCapaPorAlvo::aplicar($tipos, 0, $porTipo), 'o Clássico abre com a Ambientação');
        $this->assertSame(['composicao', 'hero', 'detail_textura', 'lifestyle'], OrdemCapaPorAlvo::aplicar($tipos, 1, $porTipo),
            'o Premium abre com a 2ª ambientada, e o meio é o mesmo do Clássico');
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

        // Arquivo a arquivo (e não `deleteDirectory`): o disco falso é a mesma pasta para todo phpunit da máquina.
        Storage::disk('local')->delete($r->imagens()->pluck('caminho')->all());
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
        // 21/10/2026: o kit da fase do produto do Portal pede a capa do conjunto — este, sem a cópia da foto do cliente, não tem de onde.
        $this->assertSame('sem_foto', $this->servico()->avaliar($rk->id));
        $avulso = PubProduto::create(['company_id' => $this->empresa->id, 'sku' => 'AVULSO', 'nome' => 'Avulso', 'origem' => PubProduto::ORIGEM_PUBLICADOR]);
        $kitAvulso = PubProduto::create(['company_id' => $this->empresa->id, 'sku' => 'AVULSO-KIT2', 'nome' => 'Kit 2 Avulso', 'origem' => PubProduto::ORIGEM_PUBLICADOR,
            'produto_base_id' => $avulso->id, 'fase' => 2, 'quantidade_kit' => 2]);
        $rka = (new RascunhoRepository())->criar($kitAvulso, [new Alvo('gold_special', null)]);
        $this->assertSame('kit_da_fase', $this->servico()->avaliar($rka->id), 'a fase de produto fora do Portal segue com a capa do botão');

        $this->assertSame('planejando', $this->servico()->avaliar($r->id));
        // O kit deu erro (o operador o descartaria): com os MESMOS fatos, não gera de novo.
        MlAnuncioCriativoKit::query()->update(['status' => MlAnuncioCriativoKit::STATUS_ERRO]);
        $this->assertSame('em_dia', $this->servico()->avaliar($r->id));
        // Foto nova do cliente no Portal = fatos novos = gera.
        $this->fotoNoRascunho($r, 'nova', [200, 30, 30], EstruturaProduto::query()->findOrFail($p->id));
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

    // ═══ 20/10/2026: as imagens do kit automático entram SOZINHAS e viram as fotos do grupo ═══

    /** Um JPEG de verdade (a foto passa pela mesma conferência de tamanho/formato de qualquer foto do Publicador). */
    private function jpeg(array $rgb): string
    {
        $im = imagecreatetruecolor(1200, 1200);
        imagefill($im, 0, 0, imagecolorallocate($im, ...$rgb));
        ob_start();
        imagejpeg($im, null, 90);
        imagedestroy($im);

        return (string) ob_get_clean();
    }

    /**
     * O kit automático como o Creative Engine o deixa: planejado pela automação (a memória no rascunho), os dois
     * slots prontos com a imagem no disco e o veredito do juiz — e o status `pronto` gravado pelo modelo, como o
     * `recalcularStatus()` faz (é o que dispara o gatilho).
     *
     * @param  list<string>  $vereditos  `validacao_status` de cada slot, na ordem
     */
    private function kitPronto(PubRascunho $r, array $vereditos = ['aprovada', 'aprovada']): MlAnuncioCriativoKit
    {
        $this->assertSame('planejando', $this->servico()->avaliar($r->id));

        return $this->slotsProntos(MlAnuncioCriativoKit::query()->where('pub_rascunho_id', $r->id)->sole(), ['lifestyle', 'hero'], $vereditos);
    }

    /** Os slots do kit prontos, com a imagem no disco e o veredito do juiz, e o kit `pronto` (o gatilho). */
    private function slotsProntos(MlAnuncioCriativoKit $kit, array $tipos, array $vereditos, array $plano = []): MlAnuncioCriativoKit
    {
        $kit->update(['status' => MlAnuncioCriativoKit::STATUS_GERANDO, 'total_slots' => count($tipos)]);
        foreach ($tipos as $i => $tipo) {
            $caminho = "creative/kit-{$kit->id}-{$tipo}.jpg";
            Storage::disk('local')->put($caminho, $this->jpeg($i === 0 ? [180, 120, 60] : [60, 120, 180]));
            MlAnuncioCriativo::create(['token' => str_repeat((string) $i, 32), 'company_id' => $kit->company_id, 'pub_rascunho_id' => null,
                'user_id' => $this->sistema->id, 'kit_id' => $kit->id, 'slot' => $tipo, 'slot_indice' => $i, 'slot_plano' => ['tipo' => $tipo] + $plano,
                'status' => MlAnuncioCriativo::STATUS_PRONTO, 'imagem_path' => $caminho, 'imagem_mime' => 'image/jpeg', 'modelo' => 'gemini-teste',
                'validacao_status' => $vereditos[$i]]);
        }
        $kit->update(['status' => MlAnuncioCriativoKit::STATUS_PRONTO]);

        return $kit->fresh();
    }

    /** @return list<int> as fotos do grupo, na ordem da galeria */
    private function galeria(PubRascunho $r, string $grupo = R::GERAL): array
    {
        $doGrupo = array_values(array_filter((new RascunhoRepository())->snapshot($r->fresh())->imagens, fn ($a) => $a['grupo'] === $grupo));
        usort($doGrupo, fn ($a, $b) => (int) $a['posicao'] <=> (int) $b['posicao']);

        return array_map(fn ($a) => (int) $a['imagem'], $doGrupo);
    }

    private function imagemDoSlot(MlAnuncioCriativoKit $kit, string $tipo): ?int
    {
        $id = MlAnuncioCriativo::query()->where('kit_id', $kit->id)->where('slot', $tipo)->value('pub_imagem_id');

        return $id === null ? null : (int) $id;
    }

    public function test_kit_automatico_pronto_vira_as_fotos_do_grupo_e_a_do_cliente_fica_so_de_referencia(): void
    {
        $this->ligar();
        [, $r] = $this->produtoComFotos(2);
        $doCliente = $this->galeria($r);
        $kit = $this->kitPronto($r);

        Queue::assertPushedOn('creative', AplicarCriativosAutomaticosJob::class, fn (AplicarCriativosAutomaticosJob $j) => $j->kitId === $kit->id);
        $this->assertSame('aplicado:2', $this->servico()->aplicar($kit->id));

        $lifestyle = $this->imagemDoSlot($kit, 'lifestyle');
        $hero = $this->imagemDoSlot($kit, 'hero');
        $this->assertNotNull($lifestyle);
        $this->assertSame([$lifestyle, $hero], $this->galeria($r), 'o grupo fica SÓ com as imagens do kit, na ordem dos slots (a 1ª é a capa)');
        $this->assertSame(2, PubImagem::query()->whereIn('id', $doCliente)->count(), 'a foto do cliente sai da galeria, mas fica no rascunho como referência');
        $this->assertSame(MlAnuncioCriativoKit::STATUS_APROVADO, $kit->fresh()->status);
        $this->assertSame($this->sistema->id, (int) $kit->fresh()->aprovado_por);
        $memoria = $r->fresh()->step_state['criativos_auto']['grupos'][R::GERAL];
        $this->assertSame(2, $memoria['aplicadas']);
        $this->assertSame('nao_pronto', $this->servico()->aplicar($kit->id), 'o kit fechou (aprovado): não volta');
        $this->assertSame([$lifestyle, $hero], $this->galeria($r));

        // O próximo preparo não gera de novo: o grupo agora só tem imagem por IA, e imagem por IA nunca é referência
        // de outro kit — nem com o kit fora do caminho (erro, descartado).
        $this->assertSame('sem_foto', $this->servico()->avaliar($r->id));
        MlAnuncioCriativoKit::whereKey($kit->id)->update(['status' => MlAnuncioCriativoKit::STATUS_ERRO]);
        $this->assertSame('sem_foto', $this->servico()->avaliar($r->id));
        $this->assertSame(1, MlAnuncioCriativoKit::query()->count());
    }

    /**
     * 21/10/2026 — a capa da fase N: o kit que nasce do base do Portal pede a capa do conjunto POR COR, com a foto REAL
     * do cliente daquela cor — mesmo depois de a Fase 1 ter trocado as fotos pelas da IA (a do cliente fica no rascunho
     * sem grupo, e o clone a copia) —, e as duas imagens entram NA FRENTE das que o kit herdou da Fase 1.
     */
    public function test_capa_da_fase_n_por_cor_com_a_foto_real_do_cliente_e_na_frente_das_herdadas(): void
    {
        $this->ligar();
        [$p, $r] = $this->produtoComFotos(1);
        $doCliente = PubImagem::query()->findOrFail($this->galeria($r)[0]);
        // A Fase 1 tem Azul e Preto (a cor define a foto); o Portal só tem foto do Azul. As imagens por IA já tomaram o
        // lugar da foto do cliente: ela não está em grupo nenhum.
        $eixos = [new Eixo('COLOR', 'Cor', 0, definesPicture: true, valores: [new ValorEixo('52028', 'Azul'), new ValorEixo('52049', 'Preto')])];
        (new RascunhoRepository())->gravarVariacao($r->fresh(), $eixos, array_map(fn ($c) => Variante::daCombinacao($c), GeradorCombinacoes::gerar($eixos)));
        $azul = ChaveCanonica::combinacao(['COLOR' => 'id:52028']);
        $ia = $this->fotoNoRascunho($r, 'ia-da-fase-1', [10, 200, 10]);
        $editor = app(EditorRascunhoService::class);
        $editor->trocarFotosDoGrupo($r->fresh(), $azul, [$ia->id]);
        $editor->trocarFotosDoGrupo($r->fresh(), R::GERAL, [$ia->id]);

        $kit = app(CriarFaseService::class)->criar($this->pubDo($p)->fresh(), ['quantidade' => 2, 'sku' => 'CAD-KIT2']);
        $rk = $kit->rascunho;
        $this->assertSame('planejando', $this->servico()->avaliar($rk->id));

        $kitIa = MlAnuncioCriativoKit::query()->where('pub_rascunho_id', $rk->id)->sole();
        $this->assertSame($azul, $kitIa->pub_grupo, 'uma capa por cor com foto do cliente: o Preto não tem, e a galeria geral não entra em produto com cor');
        Queue::assertPushed(PlanejarKitCriativosJob::class, fn ($j) => $j->kitId === $kitIa->id && $j->tiposFixos === ['hero', 'lifestyle']);
        $portador = MlAnuncioCriativo::query()->findOrFail($kitIa->criativo_referencia_id);
        $this->assertCount(1, (array) $portador->referencias, 'a referência é a foto do cliente, nunca a imagem por IA da Fase 1');
        $this->assertSame(sha1_file(Storage::disk('local')->path($doCliente->caminho)), $portador->referencias[0]['hash']);
        $this->assertTrue($rk->fresh()->step_state['criativos_auto']['grupos'][$azul]['capa']);

        $herdadas = $this->galeria($rk, $azul);
        $this->assertCount(1, $herdadas, 'o kit herdou a imagem por IA da Fase 1 no Azul');
        $this->slotsProntos($kitIa, ['hero', 'lifestyle'], ['aprovada', 'aprovada'], ['unidades_da_composicao' => 2]);
        $this->assertSame('aplicado:2', $this->servico()->aplicar($kitIa->id));
        $this->assertSame([$this->imagemDoSlot($kitIa, 'hero'), $this->imagemDoSlot($kitIa, 'lifestyle'), ...$herdadas], $this->galeria($rk, $azul),
            'a capa do conjunto na frente (fundo limpo, depois ambientada) e a foto herdada da Fase 1 depois');
        $this->assertSame(MlAnuncioCriativoKit::STATUS_APROVADO, $kitIa->fresh()->status);
        $this->assertSame('kit_existente', $this->servico()->avaliar($rk->id), 'a fase não pede outra capa');
    }

    /** 21/10/2026 (teste do usuário no Kit 2): em MÓVEL a capa do Kit N abre com a AMBIENTADA. */
    public function test_capa_da_fase_em_movel_abre_com_a_ambientada(): void
    {
        $this->ligar();
        $this->mock(CreativeCategoriaMobiliarioService::class, fn ($m) => $m->shouldReceive('ehMoveis')->andReturn(true));
        [$p] = $this->produtoComFotos(1);
        $kit = app(CriarFaseService::class)->criar($this->pubDo($p)->fresh(), ['quantidade' => 2, 'sku' => 'CAD-KIT2']);

        $this->assertSame('planejando', $this->servico()->avaliar($kit->rascunho->id));
        Queue::assertPushed(PlanejarKitCriativosJob::class, fn ($j) => $j->tiposFixos === ['lifestyle', 'hero']);
    }

    /**
     * 21/10/2026 — o COMPOSTO do Planejamento (o Kit/Combo do Portal que é produto próprio no Publicador) gera as duas
     * capas com o CONJUNTO na mesma imagem: a frase da composição entra na cena de cada capa, depois do enquadramento.
     */
    public function test_composto_gera_as_duas_capas_com_o_conjunto_na_mesma_imagem(): void
    {
        $this->ligar();
        [, $r] = $this->produtoComFotos(1);
        $simples = EstruturaOferta::query()->where('company_id', $this->empresa->id)->where('fase', 'simples')->firstOrFail();
        $doPortal = EstruturaOferta::create(['company_id' => $this->empresa->id, 'sku' => 'CAD-CB2', 'fase' => 'combo', 'nome' => 'Combo 2 Cadeira']);
        EstruturaOfertaComponente::create(['oferta_id' => $doPortal->id, 'componente_id' => $simples->id, 'quantidade' => 2]);
        $composto = PubProduto::create(['company_id' => $this->empresa->id, 'oferta_id' => $doPortal->id, 'sku' => 'CAD-CB2', 'nome' => 'Combo 2 Cadeira',
            'origem' => PubProduto::ORIGEM_PORTAL]);
        $rc = (new RascunhoRepository())->criar($composto, [new Alvo('gold_special', null), new Alvo('gold_pro', null)]);
        DB::table('pub_rascunhos')->where('id', $rc->id)->update(['categoria_id' => $r->categoria_id]);
        // A cópia da foto do cliente (o Sincronizar a traz com o mesmo sha256).
        $this->fotoNoRascunho($rc->fresh(), 'CAD-0-copia', [20, crc32('CAD') % 200, 90]);

        $this->assertSame('planejando', $this->servico()->avaliar($rc->id));
        $kit = MlAnuncioCriativoKit::query()->where('pub_rascunho_id', $rc->id)->sole();
        Queue::assertPushed(PlanejarKitCriativosJob::class, fn ($j) => $j->kitId === $kit->id && $j->tiposFixos === ['hero', 'lifestyle']);

        // O plano como o Creative Engine o grava; a textura (se houvesse) não entraria na composição.
        $kit->update(['status' => MlAnuncioCriativoKit::STATUS_PLANEJADO, 'total_slots' => 2]);
        foreach (['hero' => 'Cadeira isolada em fundo branco.', 'lifestyle' => 'Cadeira numa sala clara'] as $tipo => $cena) {
            MlAnuncioCriativo::create(['token' => Str::random(32), 'company_id' => $kit->company_id, 'user_id' => $this->sistema->id, 'kit_id' => $kit->id,
                'slot' => $tipo, 'slot_indice' => $tipo === 'hero' ? 1 : 2, 'slot_plano' => ['tipo' => $tipo, 'cena' => $cena], 'status' => MlAnuncioCriativo::STATUS_PENDENTE]);
        }
        $this->assertStringStartsWith('gerando:', $this->servico()->gerar($kit->id));

        $planos = MlAnuncioCriativo::query()->where('kit_id', $kit->id)->whereNotNull('slot_indice')->orderBy('slot_indice')->get()->map(fn ($s) => (array) $s->slot_plano)->all();
        foreach ($planos as $plano) {
            $this->assertStringContainsString('exatamente 2 peças: 2 Cadeira Executiva Azul', $plano['cena']);
            $this->assertSame(2, $plano['unidades_da_composicao']);
        }
        $this->assertStringStartsWith('Cadeira isolada em fundo branco. Mostre JUNTAS', $planos[0]['cena'], 'o enquadramento do slot fica; a composição vem depois');
        $this->assertStringStartsWith('Cadeira numa sala clara. Mostre JUNTAS', $planos[1]['cena']);

        // 21/10/2026 — prontas e aprovadas, as do conjunto abrem a galeria e as fotos da Fase 1 da peça vêm depois (do
        // acervo da conta, sem cópia de arquivo): "capa ambientada e mais imagens do produto, como detalhes".
        $daFase1 = PubImagem::query()->where('rascunho_id', $r->id)->orderBy('id')->firstOrFail();
        $ativo = \App\Models\AcervoImagem::create(['company_id' => $this->empresa->id, 'dono_chave' => 'company-'.$this->empresa->id, 'disco' => 'local',
            'caminho' => $daFase1->caminho, 'sha256' => $daFase1->sha256, 'mime' => 'image/jpeg', 'bytes' => (int) $daFase1->bytes, 'origem' => 'upload']);
        $daFase1->update(['acervo_imagem_id' => $ativo->id]);
        // 22/10/2026: a AMBIENTADA da peça sozinha não entra no conjunto — a da mesa Aurora tinha 6 cadeiras de outro
        // modelo e foi parar no combit Mesa + 4 Cadeiras. Do componente vêm só o fundo branco e os detalhes.
        $cenaDaFase1 = $this->fotoNoRascunho($r->fresh(), 'CAD-cena-da-fase-1', [200, 30, 30]);
        $cenaDaFase1->update(['acervo_imagem_id' => \App\Models\AcervoImagem::create(['company_id' => $this->empresa->id,
            'dono_chave' => 'company-'.$this->empresa->id, 'disco' => 'local', 'caminho' => $cenaDaFase1->caminho, 'sha256' => $cenaDaFase1->sha256,
            'mime' => 'image/jpeg', 'bytes' => (int) $cenaDaFase1->bytes, 'origem' => 'ia'])->id]);
        MlAnuncioCriativo::create(['token' => Str::random(32), 'company_id' => $this->empresa->id, 'user_id' => $this->sistema->id, 'slot' => 'lifestyle',
            'slot_plano' => ['tipo' => 'lifestyle'], 'status' => MlAnuncioCriativo::STATUS_APROVADO, 'pub_imagem_id' => $cenaDaFase1->id]);
        MlAnuncioCriativo::query()->where('kit_id', $kit->id)->delete();
        $this->slotsProntos($kit->fresh(), ['hero', 'lifestyle'], ['aprovada', 'aprovada'], ['unidades_da_composicao' => 2]);
        $this->assertSame('aplicado:2', $this->servico()->aplicar($kit->id));
        $galeria = $this->galeria($rc);
        $this->assertSame([$this->imagemDoSlot($kit, 'hero'), $this->imagemDoSlot($kit, 'lifestyle')], array_slice($galeria, 0, 2));
        $this->assertCount(3, $galeria, 'a foto da Fase 1 da peça entra depois das do conjunto — a ambientada dela, não');
        $this->assertNotContains($cenaDaFase1->sha256, PubImagem::query()->whereIn('id', $galeria)->pluck('sha256')->all());
        // O composto já tinha a cópia da foto (mesmo sha256): ela é reaproveitada, sem linha nova.
        $this->assertSame($daFase1->sha256, PubImagem::query()->findOrFail($galeria[2])->sha256, 'a foto da Fase 1 da peça');
    }

    /** Capa reprovada pelo juiz: o kit da fase segue com as fotos da Fase 1, e o kit fecha com o motivo. */
    public function test_capa_da_fase_reprovada_o_kit_segue_com_as_fotos_da_fase_1(): void
    {
        $this->ligar();
        [$p, $r] = $this->produtoComFotos(1);
        $kit = app(CriarFaseService::class)->criar($this->pubDo($p)->fresh(), ['quantidade' => 3, 'sku' => 'CAD-KIT3']);
        $rk = $kit->rascunho;
        $antes = $this->galeria($rk);
        $this->assertSame('planejando', $this->servico()->avaliar($rk->id), 'produto sem cor: a galeria geral, com a foto do cliente que está nela');
        $kitIa = MlAnuncioCriativoKit::query()->where('pub_rascunho_id', $rk->id)->sole();
        $this->assertSame(R::GERAL, $kitIa->pub_grupo);
        $this->slotsProntos($kitIa, ['hero', 'lifestyle'], ['reprovada', 'reprovada'], ['unidades_da_composicao' => 3]);
        MlAnuncioCriativo::query()->where('kit_id', $kitIa->id)->whereNotNull('slot_indice')->update(['regeneracao_automatica' => true]);

        $this->assertSame('nada_aprovavel', $this->servico()->aplicar($kitIa->id));
        $this->assertSame($antes, $this->galeria($rk), 'o anúncio nunca fica sem foto por aqui');
        $this->assertSame(MlAnuncioCriativoKit::STATUS_ERRO, $kitIa->fresh()->status);
        $this->assertStringContainsString('fotos da Fase 1', (string) $kitIa->fresh()->erro_mensagem);
    }

    public function test_trocar_fotos_do_grupo_so_mexe_no_grupo_pedido(): void
    {
        [, $r] = $this->produtoComFotos(2);
        $geral = $this->galeria($r);
        $cor = 'COLOR=id:52049';
        $repo = new RascunhoRepository();
        $daCor = $r->imagens()->create(['caminho' => 'publicador/cor.jpg', 'sha256' => hash('sha256', 'cor'), 'mime' => 'image/jpeg', 'bytes' => 1, 'largura' => 1200, 'altura' => 1200, 'upload_status' => PubImagem::PENDENTE]);
        $daIa = $r->imagens()->create(['caminho' => 'publicador/ia.jpg', 'sha256' => hash('sha256', 'ia'), 'mime' => 'image/jpeg', 'bytes' => 1, 'largura' => 1200, 'altura' => 1200, 'upload_status' => PubImagem::PENDENTE]);
        $repo->gravarAtribuicoes($r, [...$repo->snapshot($r->fresh())->imagens, ['imagem' => $daCor->id, 'grupo' => $cor, 'posicao' => 0]]);
        $editor = app(EditorRascunhoService::class);

        $editor->trocarFotosDoGrupo($r->fresh(), $cor, [$daIa->id]);
        $this->assertSame([$daIa->id], $this->galeria($r, $cor));
        $this->assertSame($geral, $this->galeria($r), 'a galeria geral fica como estava');
        $this->assertNotNull(PubImagem::query()->find($daCor->id), 'a foto que sai do grupo não é apagada');

        $revisao = (int) $r->fresh()->revisao;
        $editor->trocarFotosDoGrupo($r->fresh(), $cor, [$daIa->id]);
        $editor->trocarFotosDoGrupo($r->fresh(), $cor, []);
        $this->assertSame($revisao, (int) $r->fresh()->revisao, 'a mesma lista e a lista vazia não gravam nada');
        $this->assertSame([$daIa->id], $this->galeria($r, $cor));
    }

    public function test_nenhuma_imagem_aprovavel_as_fotos_do_cliente_ficam(): void
    {
        $this->ligar();
        [, $r] = $this->produtoComFotos(2);
        $doCliente = $this->galeria($r);
        $kit = $this->kitPronto($r, ['reprovada', 'reprovada']);
        // As duas já foram refeitas uma vez (VAL-05) e reprovaram de novo: não há outra tentativa.
        MlAnuncioCriativo::query()->where('kit_id', $kit->id)->update(['regeneracao_automatica' => true, 'validacao' => json_encode([
            'status' => 'reprovada', 'mensagem' => 'Risco: A foto original mostra uma banqueta, e o cadastro é de uma poltrona.. Confira antes de aprovar.',
        ])]);

        $this->assertSame('nada_aprovavel', $this->servico()->aplicar($kit->id));
        $this->assertSame($doCliente, $this->galeria($r), 'o anúncio nunca fica sem foto por aqui');
        $this->assertNull($this->imagemDoSlot($kit, 'hero'));
        $this->assertSame(MlAnuncioCriativoKit::STATUS_ERRO, $kit->fresh()->status, 'o kit reprovado não segura o grupo');
        $this->assertSame('nao_pronto', $this->servico()->aplicar($kit->id));
        // 21/10/2026: "tem que ter o aviso quando for reprovado, e o motivo".
        $this->assertSame(['todas' => true, 'reprovadas' => 2, 'geradas' => 2,
            'motivos' => ['A foto original mostra uma banqueta, e o cadastro é de uma poltrona.']], ImagensReprovadas::do($r->fresh()));

        // Os mesmos fatos não geram de novo; a foto certa que o cliente sobe no Portal, sim.
        $this->assertSame('em_dia', $this->servico()->avaliar($r->id));
        $this->fotoNoRascunho($r->fresh(), 'a-certa', [90, 60, 30], EstruturaProduto::query()->findOrFail($r->produto->estrutura_produto_id));
        $this->assertSame('planejando', $this->servico()->avaliar($r->id));
        $this->assertNull(ImagensReprovadas::do($r->fresh()), 'o kit novo do grupo apaga o aviso do velho');
    }

    public function test_o_motivo_do_aviso_e_a_mensagem_do_juiz_sem_o_que_fala_com_quem_aprova(): void
    {
        $this->assertSame('A imagem gerada mostra uma banqueta.', ImagensReprovadas::motivo('Risco: A imagem gerada mostra uma banqueta.. Confira antes de aprovar.'));
        $this->assertNull(ImagensReprovadas::motivo('   '));
        $this->assertNull(ImagensReprovadas::motivo(null));
        $this->assertSame('Kit … falhou.', ImagensReprovadas::motivo('Kit '.str_repeat('a', 32).' falhou.'), 'nada com cara de token vai para a tela');
    }

    public function test_kit_pedido_no_botao_nao_entra_sozinho(): void
    {
        $this->ligar();
        [, $r] = $this->produtoComFotos(2);
        $kit = $this->kitPronto($r);
        // O kit que alguém pediu no botão não está na memória da automação.
        DB::table('pub_rascunhos')->where('id', $r->id)->update(['step_state' => json_encode(['criativos_auto' => ['grupos' => [R::GERAL => ['kit_id' => 999999]]]])]);

        $this->assertSame('nao_automatico', $this->servico()->aplicar($kit->id));
        $this->assertNull($this->imagemDoSlot($kit, 'lifestyle'));
    }

    public function test_espera_o_juiz_e_a_refacao_e_a_reprovada_que_nao_vai_ser_refeita_nao_entra(): void
    {
        $this->ligar();
        [, $r] = $this->produtoComFotos(2);
        $doCliente = $this->galeria($r);
        $kit = $this->kitPronto($r, ['pendente', 'aprovada']);

        $this->assertSame('esperando_juiz', $this->servico()->aplicar($kit->id));

        $lifestyle = MlAnuncioCriativo::query()->where('kit_id', $kit->id)->where('slot', 'lifestyle')->sole();
        $lifestyle->update(['validacao_status' => MlAnuncioCriativo::VALIDACAO_REPROVADA]);
        $this->assertSame('esperando_regeneracao', $this->servico()->aplicar($kit->id), 'o motor ainda refaz a reprovada uma vez (VAL-05)');

        // Já foi refeita uma vez e reprovou de novo: não há outra tentativa — entra só a boa.
        $lifestyle->update(['regeneracao_automatica' => true]);
        $this->assertSame('aplicado:1', $this->servico()->aplicar($kit->id));
        $this->assertSame([$this->imagemDoSlot($kit, 'hero')], $this->galeria($r), 'entra só a boa, no lugar das do cliente');
        $aviso = ImagensReprovadas::do($r->fresh());
        $this->assertFalse($aviso['todas'], 'uma entrou: o aviso fala só da que ficou de fora');
        $this->assertSame([1, 2], [$aviso['reprovadas'], $aviso['geradas']]);
        $this->assertNotSame([], $doCliente);
        $this->assertNull($this->imagemDoSlot($kit, 'lifestyle'), 'a reprovada fica no painel para alguém decidir');
        $this->assertSame(MlAnuncioCriativoKit::STATUS_PRONTO, $kit->fresh()->status, 'o kit não fecha com imagem de fora');
        $this->assertSame('ja_aplicado', $this->servico()->aplicar($kit->id), 'uma vez por kit, mesmo aberto');
    }

    public function test_desligado_ou_rascunho_publicado_nada_entra(): void
    {
        $this->ligar();
        [, $r] = $this->produtoComFotos(2);
        $kit = $this->kitPronto($r);

        config(['publicador.criativos_auto.ativo' => false]);
        $this->assertSame('desligado', $this->servico()->aplicar($kit->id));
        config(['publicador.criativos_auto.ativo' => true]);

        PubRascunho::whereKey($r->id)->update(['status' => PubRascunho::PUBLISHED]);
        $this->assertSame('intocavel', $this->servico()->aplicar($kit->id));
        $this->assertNull($this->imagemDoSlot($kit, 'hero'));
    }

    public function test_os_gatilhos_so_disparam_com_o_kit_pronto_e_o_juiz_terminado(): void
    {
        $this->ligar();
        [, $r] = $this->produtoComFotos(2);
        $this->servico()->avaliar($r->id);
        $kit = MlAnuncioCriativoKit::query()->where('pub_rascunho_id', $r->id)->sole();

        $kit->update(['status' => MlAnuncioCriativoKit::STATUS_GERANDO]);
        Queue::assertNotPushed(AplicarCriativosAutomaticosJob::class);

        // Kit já pronto (sem passar pelo gatilho do kit) e o juiz terminando um slot: é a vez do gatilho do slot.
        MlAnuncioCriativoKit::whereKey($kit->id)->update(['status' => MlAnuncioCriativoKit::STATUS_PRONTO]);
        $slot = MlAnuncioCriativo::create(['token' => str_repeat('9', 32), 'company_id' => $kit->company_id, 'pub_rascunho_id' => null,
            'user_id' => $this->sistema->id, 'kit_id' => $kit->id, 'slot' => 'hero', 'slot_indice' => 0, 'slot_plano' => ['tipo' => 'hero'],
            'status' => MlAnuncioCriativo::STATUS_PRONTO, 'validacao_status' => MlAnuncioCriativo::VALIDACAO_PENDENTE]);
        $slot->update(['modelo' => 'outro']);
        Queue::assertNotPushed(AplicarCriativosAutomaticosJob::class);
        $slot->update(['validacao_status' => MlAnuncioCriativo::VALIDACAO_APROVADA]);
        Queue::assertPushedOn('creative', AplicarCriativosAutomaticosJob::class, fn (AplicarCriativosAutomaticosJob $j) => $j->kitId === $kit->id);

        // Desligado, nenhum gatilho põe nada na fila.
        config(['publicador.criativos_auto.ativo' => false]);
        $outro = MlAnuncioCriativoKit::query()->create(['token' => str_repeat('k', 32), 'company_id' => $kit->company_id, 'pub_rascunho_id' => $r->id,
            'pub_grupo' => 'COLOR=id:1', 'user_id' => $this->sistema->id, 'status' => MlAnuncioCriativoKit::STATUS_GERANDO]);
        $outro->update(['status' => MlAnuncioCriativoKit::STATUS_PRONTO]);
        Queue::assertNotPushed(AplicarCriativosAutomaticosJob::class, fn (AplicarCriativosAutomaticosJob $j) => $j->kitId === $outro->id);
    }
}
