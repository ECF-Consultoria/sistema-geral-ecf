<?php

namespace Tests\Feature\Publicador\Aprovacao;

use App\Models\Company;
use App\Models\MlbEmpresa;
use App\Models\MlToken;
use App\Models\PubAprovacaoConjunto;
use App\Models\PubAprovacaoEvento;
use App\Models\PubAprovacaoImagem;
use App\Models\PubAprovacaoImagemAlerta;
use App\Models\PubAprovacaoOferta;
use App\Models\PubImagem;
use App\Models\PubProduto;
use App\Models\PubRascunho;
use App\Models\PubRascunhoAlvo;
use App\Models\User;
use App\Services\Publicador\Aprovacao\AndamentoDaOfertaService;
use App\Services\Publicador\Aprovacao\AprovacaoDaOfertaService;
use App\Services\Publicador\Aprovacao\BloqueiosDaOfertaService;
use App\Services\Publicador\Aprovacao\Painel\FotosDoPainelService;
use App\Services\Publicador\Aprovacao\RevisaoDeImagemService;
use App\Support\Publicador\Variacao\ChaveCanonica;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 * Fase 188, plano 05 — AS TRÊS ESCRITAS DE IMAGEM DO PAINEL: aprovar foto · aprovar N sem alerta ·
 * reprovar imagem.
 *
 * ⚠️⚠️ DE CADA GATE DESTE ARQUIVO, A PERGUNTA RESPONDIDA ANTES DE ELE EXISTIR:
 * **"o que precisaria estar errado no mundo para ele falhar?"**
 *
 *   · **"Aprovar N sem alerta" aprovando MENOS do que o botão promete.** O rótulo vem de
 *     `FotosDoPainelService::para()` → `sem_alerta.quantidade`, que sai de
 *     `BloqueiosDaOfertaService::contagemDeFotos()` — e aquela contagem cobre **TODOS os grupos do
 *     rascunho**. Já `RevisaoDeImagemService::aprovarSemAlerta()` recebe **UM conjunto por
 *     chamada**. ⚠️ Chamar o serviço para um conjunto só faria o admin clicar em "Aprovar 3 sem
 *     alerta" e **uma ficar de pé**, sem nada na tela explicando. É o caso central deste arquivo,
 *     e a fixture tem DOIS grupos exatamente para ele poder falhar.
 *   · **Reprovar a principal deixando o conjunto com capa apontando para foto fora da
 *     publicação** — o anúncio subiria com a foto errada. São CINCO efeitos (sai da galeria, vai
 *     para "Fora da publicação", próxima promovida, motivo registrado, aprovação de TODAS as
 *     ofertas reaberta), e cada um tem isca própria.
 *   · **Foto aprovada no Clássico não contando no Premium** (DADO-04) — o compartilhamento é
 *     estrutural (`rascunho_id` nos dois lados) e a fixture tem **duas** ofertas: com uma só,
 *     "vale para as duas" não se prova.
 *   · **Motivo de reprovação vazio sendo aceito** — foto que sai sem ninguém saber por quê, e o
 *     pedido de regeneração depende do motivo.
 *   · **Escrever em oferta ou foto de OUTRA conta** (T-188-40). ⚠️ E a recusa tem de ser **404**,
 *     nunca 403: um 403 confirma que o id existe, que é metade do vazamento.
 *   · **Uma requisição ao Mercado Livre saindo daqui** — a fronteira do critério 18.
 *
 * ⚠️⚠️ **ZERO REQUISIÇÃO, E AQUI ISSO É LITERAL.** As três rotas de imagem **não** leem composição
 * financeira, então nenhuma delas tem motivo para tocar a rede. `Http::fake()` +
 * `preventStrayRequests()` em todo o arquivo, e o caso (3) exige
 * `Http::recorded()` **vazio** — com **isca** provando que o espião não está cego.
 * ⚠️ A asserção de "zero" é diferente da do `SalvarEAprovarDoPainelTest`, onde `aprovar()` LÊ a
 * tarifa real do ML por desenho (decisão 9 do usuário) e a régua honesta é **zero ESCRITA**.
 *
 * ⚠️⚠️ **NENHUM EXECUTOR DESTA FASE VIU ESTA TELA RENDERIZADA.** O banco local não tem empresa com
 * `ml_token` e `pub_aprovacao_ofertas` está vazia; o que estes casos exercitam é a **rota**, com
 * fixture. ⚠️ E a conferência visual da Fase 187 pelo usuário continua **ABERTA**.
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 */
class EscritaDeImagemDoPainelTest extends TestCase
{
    use RefreshDatabase;

    private const CLASSICO = 'gold_special';

    private const PREMIUM = 'gold_pro';

    private const COR_AZUL = 'COR:Azul';

    private int $sequencia = 0;

    protected function setUp(): void
    {
        parent::setUp();
        // ⚠️ Tudo que grava data é carimbado: `quem_id`/`quando` da imagem e `ocorrido_em` do
        // evento são o COL-05 (autor e data reais em todo registro, T-188-43).
        Carbon::setTestNow('2026-10-19 11:30:00');
        Http::fake();
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ─────────────────────────────────────────────────────────────────────────────────────────
    // Fixture — ⚠️ ELA DISCRIMINA, e cada peça existe por um caso que sem ela passaria
    // ─────────────────────────────────────────────────────────────────────────────────────────

    /** @return array{0: Company, 1: MlbEmpresa, 2: User} */
    private function conta(): array
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $company = Company::factory()->create();

        MlToken::create([
            'company_id' => $company->id,
            'ml_user_id' => (string) random_int(100000000, 999999999),
            'access_token' => 'fake-access-token',
            'refresh_token' => 'fake-refresh-token',
            'token_type' => 'bearer',
            'scope' => 'read write offline_access',
            'expires_at' => now()->addDays(6),
            'last_refreshed_at' => now(),
            'status' => 'active',
            'connected_at' => now(),
        ]);

        $empresa = MlbEmpresa::create([
            'nome' => 'Empresa Escrita '.$company->id,
            'tipo' => 'ASSESSORIA',
            'company_id' => $company->id,
            'responsavel_id' => $admin->id,
        ]);

        return [$company, $empresa, $admin];
    }

    /**
     * O cenário: **UM rascunho, DUAS ofertas** (Clássico e Premium) e **DOIS grupos de fotos**.
     *
     * ⚠️⚠️ **AS DUAS OFERTAS SÃO EXIGÊNCIA DO DADO-04.** Com uma só, "a aprovação vale para as
     * duas" é inobservável e o caso passaria sem medir nada.
     *
     * ⚠️⚠️ **OS DOIS GRUPOS SÃO EXIGÊNCIA DO CASO CENTRAL.** Com um grupo só, aprovar percorrendo
     * `doRascunho()` e aprovar um conjunto fixo dão **o mesmo número** — a mutação (iii) sobreviveria
     * por falha de FIXTURE, que é exatamente o que aconteceu duas vezes na wave 187-06.
     *
     * A galeria do grupo GERAL, na ordem de posição:
     *   #0 `pending` SEM alerta  ← a PRINCIPAL (`principal_imagem_id` aponta para ela)
     *   #1 `pending` COM alerta `high`
     *   #2 `pending` SEM alerta
     *   #3 `approved`
     *   #4 `regenerating` (fora da ordem: `posicao` nula)
     * E o grupo `COR:Azul`:
     *   #0 `pending` SEM alerta
     *
     * ➡️ `sem_alerta.quantidade` tem de dar **3** (2 do GERAL + 1 do azul) e `com_alerta` **1**.
     *
     * ⚠️ A principal é `pending`, e não `approved`, de propósito: reprovar a principal é o caso que
     * mais importa, e ele tem de valer para uma foto que ninguém decidiu ainda.
     *
     * @return array<string, mixed>
     */
    private function cenario(Company $company, ?MlbEmpresa $empresa): array
    {
        $this->sequencia++;
        $sku = 'IMGP-'.$this->sequencia;

        $produto = PubProduto::create([
            'company_id' => $company->id,
            'sku' => $sku,
            'nome' => 'Poltrona do painel '.$sku,
            'origem' => PubProduto::ORIGEM_PUBLICADOR,
            'quantidade_kit' => 1,
        ]);
        $rascunho = PubRascunho::create(['produto_id' => $produto->id]);

        $ofertas = [];
        foreach ([self::CLASSICO, self::PREMIUM] as $i => $tipo) {
            $alvo = PubRascunhoAlvo::create([
                'rascunho_id' => $rascunho->id,
                'listing_type_id' => $tipo,
                'titulo' => 'Poltrona Beny Decorativa Veludo Cinza',
                'posicao' => $i,
            ]);
            $ofertas[$tipo] = PubAprovacaoOferta::create([
                'alvo_id' => $alvo->id,
                'rascunho_id' => $rascunho->id,
                'produto_id' => $produto->id,
                'company_id' => $company->id,
                // ⚠️ A segunda âncora é gravada DE PROPÓSITO: o recorte de `paraAConta()` usa as
                // duas com `AND`, e oferta sem ela sairia do recorte — a rota daria 404 e o caso
                // passaria **medindo o caso errado**.
                'mlb_empresa_id' => $empresa?->id,
                'andamento' => PubAprovacaoOferta::AGUARDANDO_APROVACAO,
                'entrou_na_fila_em' => '2026-10-19 08:00:00',
            ]);
        }

        $geral = PubAprovacaoConjunto::create([
            'rascunho_id' => $rascunho->id,
            'grupo_chave' => PubAprovacaoConjunto::GERAL,
            'grupo_hash' => ChaveCanonica::hash(PubAprovacaoConjunto::GERAL.'-'.$rascunho->id),
        ]);

        $fotos = [
            'principal' => $this->imagem($geral, PubAprovacaoImagem::PENDENTE, 0, false),
            'com_alerta' => $this->imagem($geral, PubAprovacaoImagem::PENDENTE, 1, true),
            'limpa' => $this->imagem($geral, PubAprovacaoImagem::PENDENTE, 2, false),
            'aprovada' => $this->imagem($geral, PubAprovacaoImagem::APROVADA, 3, false),
            'regenerando' => $this->imagem($geral, PubAprovacaoImagem::REGENERANDO, null, false),
        ];
        $geral->update(['principal_imagem_id' => $fotos['principal']->imagem_id]);

        // ⚠️⚠️ O SEGUNDO GRUPO. Ver o docblock: é ele que faz o caso central poder falhar.
        $azul = PubAprovacaoConjunto::create([
            'rascunho_id' => $rascunho->id,
            'grupo_chave' => self::COR_AZUL,
            'grupo_hash' => ChaveCanonica::hash(self::COR_AZUL.'-'.$rascunho->id),
        ]);
        $fotos['azul'] = $this->imagem($azul, PubAprovacaoImagem::PENDENTE, 0, false);
        $azul->update(['principal_imagem_id' => $fotos['azul']->imagem_id]);

        return [
            'rascunho' => $rascunho,
            'produto' => $produto,
            'geral' => $geral->refresh(),
            'azul' => $azul->refresh(),
            'classico' => $ofertas[self::CLASSICO]->fresh(),
            'premium' => $ofertas[self::PREMIUM]->fresh(),
            'fotos' => $fotos,
        ];
    }

    private function imagem(PubAprovacaoConjunto $c, string $estado, ?int $posicao, bool $comAlerta): PubAprovacaoImagem
    {
        $crua = PubImagem::create([
            'rascunho_id' => $c->rascunho_id,
            'caminho' => 'fotos/foto-'.(PubImagem::count() + 1).'.jpg',
        ]);

        $img = PubAprovacaoImagem::create([
            'conjunto_id' => $c->id,
            'imagem_id' => $crua->id,
            'tipo' => PubAprovacaoImagem::TIPO_ORIGINAL,
            'estado' => $estado,
            'posicao' => $posicao,
        ]);

        PubAprovacaoImagemAlerta::create([
            'aprovacao_imagem_id' => $img->id,
            'severidade' => $comAlerta ? PubAprovacaoImagemAlerta::ALTO : PubAprovacaoImagemAlerta::OK,
            'texto' => $comAlerta ? 'Pés diferentes da original' : 'Formato e cor compatíveis',
        ]);

        return $img->fresh();
    }

    // ─────────────────────────────────────────────────────────────────────────────────────────
    // Apoio
    // ─────────────────────────────────────────────────────────────────────────────────────────

    private function urlAprovarFoto(Company $c, PubAprovacaoOferta $o, PubAprovacaoImagem $i): string
    {
        return route('mlb.anuncios.meus.aprovacao.painel.imagem.aprovar', [
            'company' => $c->id, 'oferta' => $o->id, 'imagem' => $i->id,
        ]);
    }

    private function urlReprovarFoto(Company $c, PubAprovacaoOferta $o, PubAprovacaoImagem $i): string
    {
        return route('mlb.anuncios.meus.aprovacao.painel.imagem.reprovar', [
            'company' => $c->id, 'oferta' => $o->id, 'imagem' => $i->id,
        ]);
    }

    private function urlSemAlerta(Company $c, PubAprovacaoOferta $o): string
    {
        return route('mlb.anuncios.meus.aprovacao.painel.fotos.sem-alerta', [
            'company' => $c->id, 'oferta' => $o->id,
        ]);
    }

    /** Os textos dos eventos de uma oferta, na ordem do fato. */
    private function eventos(PubAprovacaoOferta $oferta): array
    {
        return PubAprovacaoEvento::query()
            ->where('oferta_id', $oferta->id)
            ->orderBy('ocorrido_em')
            ->orderBy('id')
            ->pluck('texto')
            ->all();
    }

    /** Quantos eventos existem no banco inteiro — a régua de "nada foi gravado". */
    private function totalDeEventos(): int
    {
        return PubAprovacaoEvento::query()->count();
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // (1) APROVAR UMA FOTO — e ela vale para as DUAS ofertas do conjunto (DADO-04 / IMG-04)
    // ═════════════════════════════════════════════════════════════════════════════════════════

    public function test_aprovar_a_foto_vale_para_as_duas_ofertas_do_conjunto_188_05(): void
    {
        [$company, $empresa, $admin] = $this->conta();
        $c = $this->cenario($company, $empresa);

        /** @var PubAprovacaoImagem $foto */
        $foto = $c['fotos']['limpa'];

        // ─── A escrita chega pela rota do CLÁSSICO ────────────────────────────────────────────
        $this->actingAs($admin)
            ->post($this->urlAprovarFoto($company, $c['classico'], $foto))
            ->assertRedirect();

        $depois = $foto->fresh();

        // (a) O estado, o autor e a data — COL-05.
        $this->assertSame(PubAprovacaoImagem::APROVADA, $depois->estado);
        $this->assertSame($admin->id, (int) $depois->quem_id, 'IMG-04: a foto foi aprovada sem autor');
        $this->assertSame('2026-10-19 11:30:00', $depois->quando->format('Y-m-d H:i:s'));

        // (b) ⚠️⚠️ DADO-04: a MESMA foto conta como aprovada no PREMIUM, que é a outra oferta do
        //     MESMO rascunho — e a ligação é estrutural, não combinada.
        $doPremium = app(BloqueiosDaOfertaService::class)->contagemDeFotos($c['premium']->fresh());
        $this->assertSame(2, $doPremium['aprovadas'], 'DADO-04: a aprovação feita no Clássico não contou no Premium');

        // ⚠️ ISCA DO (b): a contagem do Premium ANTES não podia já ser 2, senão a asserção acima
        // passaria sem a rota ter feito nada. O cenário nasce com UMA aprovada.
        $outro = $this->cenario($company, $empresa);
        $this->assertSame(
            1,
            app(BloqueiosDaOfertaService::class)->contagemDeFotos($outro['premium'])['aprovadas'],
            'ISCA: o cenário já nasce com 2 aprovadas — o caso (b) é verdadeiro por construção'
        );

        // (c) O `tocado` nas DUAS ofertas (IMG-04) — é ele que alimenta o "PRÓXIMO A REVISAR".
        $this->assertTrue((bool) $c['classico']->fresh()->tocado);
        $this->assertTrue((bool) $c['premium']->fresh()->tocado, 'IMG-04: o Premium ficaria "ainda não analisado"');

        // (d) O registro na linha do tempo das DUAS, com o rótulo derivado da foto.
        $noClassico = $this->eventos($c['classico']);
        $noPremium = $this->eventos($c['premium']);
        $this->assertCount(1, $noClassico);
        $this->assertCount(1, $noPremium);
        $this->assertStringStartsWith('Aprovou a foto ', $noClassico[0]);
        $this->assertSame($noClassico[0], $noPremium[0], 'o Premium não explicaria por que a foto dele ficou aprovada');

        // (e) ⚠️ T-188-40/T-188-44 — oferta de OUTRA conta: **404**, nunca 403.
        [$outraEmpresa, $outraMlb] = $this->conta();
        $alheio = $this->cenario($outraEmpresa, $outraMlb);

        $this->actingAs($admin)
            ->post($this->urlAprovarFoto($company, $alheio['classico'], $alheio['fotos']['limpa']))
            ->assertNotFound();

        // E a foto de OUTRO rascunho na oferta desta conta também é 404 — o escopo da escrita é a
        // oferta aberta no painel, não "a mesma empresa".
        $this->actingAs($admin)
            ->post($this->urlAprovarFoto($company, $c['classico'], $outro['fotos']['limpa']))
            ->assertNotFound();

        $this->assertSame(
            PubAprovacaoImagem::PENDENTE,
            $alheio['fotos']['limpa']->fresh()->estado,
            'T-188-40: a foto de outra conta foi aprovada'
        );

        // (f) ⚠️ IMG-09 — `regenerating` não aceita aprovar: 422 com a regra, e sem gravar.
        $antes = $this->totalDeEventos();
        $this->actingAs($admin)
            ->postJson($this->urlAprovarFoto($company, $c['classico'], $c['fotos']['regenerando']))
            ->assertStatus(422)
            ->assertJsonPath('regra', RevisaoDeImagemService::REGRA_REGENERANDO);

        $this->assertSame(PubAprovacaoImagem::REGENERANDO, $c['fotos']['regenerando']->fresh()->estado);
        $this->assertSame($antes, $this->totalDeEventos(), 'IMG-09: a recusa gravou evento');
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // (2) ⚠️⚠️ O CASO CENTRAL — "Aprovar N sem alerta" PERCORRE TODOS OS GRUPOS DO RASCUNHO
    // ═════════════════════════════════════════════════════════════════════════════════════════

    /**
     * ⚠️⚠️ **O QUE PRECISARIA ESTAR ERRADO:** a action chamar
     * `RevisaoDeImagemService::aprovarSemAlerta()` para **um** conjunto em vez de percorrer
     * `PubAprovacaoConjunto::doRascunho()`. O botão diria "Aprovar 3 sem alerta", o servidor
     * aprovaria **2**, e a foto do grupo `COR:Azul` ficaria de pé — com a tela sem nada que
     * explicasse. ⚠️ É o mesmo invariante da **decisão 5 do usuário** (o bloqueio de fotos cobre
     * TODOS os grupos; aprovação cor por cor foi RECUSADA).
     *
     * ⚠️ A asserção ancora no **número que o botão promete**, lido de `FotosDoPainelService`, e não
     * num literal `3` escrito à mão: é o que liga as duas pontas. Um `3` cravado passaria mesmo se
     * o serviço do painel passasse a prometer outro número.
     */
    public function test_aprovar_sem_alerta_percorre_todos_os_grupos_do_rascunho_188_05(): void
    {
        [$company, $empresa, $admin] = $this->conta();
        $c = $this->cenario($company, $empresa);

        // ─── O QUE O BOTÃO PROMETE, lido do serviço da wave 03 ───────────────────────────────
        $fotos = app(FotosDoPainelService::class)->para($c['classico']);
        $prometido = (int) $fotos['sem_alerta']['quantidade'];

        // ⚠️ ISCA DO RECORTE: a promessa tem de ser MAIOR que o que um conjunto só entregaria.
        // Sem esta linha, "percorrer" e "não percorrer" dariam o mesmo número e o caso seria
        // inobservável por construção — a falha de fixture que matou duas mutações da 187-06.
        $soDoGeral = $c['geral']->pendentes() - $c['geral']->comAlerta();
        $this->assertSame(2, $soDoGeral);
        $this->assertSame(3, $prometido, 'a fixture deixou de cobrir dois grupos — o caso vira vazio');
        $this->assertGreaterThan($soDoGeral, $prometido, 'ISCA: um grupo só entregaria o mesmo número — nada discrimina');

        // ─── A ESCRITA ───────────────────────────────────────────────────────────────────────
        $resposta = $this->actingAs($admin)
            ->post($this->urlSemAlerta($company, $c['classico']))
            ->assertRedirect();

        $flash = $resposta->getSession()->get('painel_fotos_sem_alerta');

        // (a) ⚠️⚠️ APROVOU O MESMO NÚMERO QUE O BOTÃO PROMETIA.
        $this->assertSame($prometido, (int) $flash['aprovadas'], 'o botão prometeu mais do que o servidor aprovou');
        $this->assertSame(2, (int) $flash['grupos_percorridos'], 'a action não percorreu os dois grupos');

        // (b) As três que estavam `pending` SEM alerta ficaram aprovadas — nos DOIS grupos.
        $this->assertSame(PubAprovacaoImagem::APROVADA, $c['fotos']['principal']->fresh()->estado);
        $this->assertSame(PubAprovacaoImagem::APROVADA, $c['fotos']['limpa']->fresh()->estado);
        $this->assertSame(
            PubAprovacaoImagem::APROVADA,
            $c['fotos']['azul']->fresh()->estado,
            'a foto do grupo COR:Azul ficou de pé — a action aprovou só um conjunto'
        );

        // (c) ⚠️ A `pending` COM alerta **continua** para conferência individual — é o ponto
        //     inteiro do botão: não passar as suspeitas de bonde.
        $this->assertSame(PubAprovacaoImagem::PENDENTE, $c['fotos']['com_alerta']->fresh()->estado);
        // E a `regenerating` nem é `pending`, então também fica fora.
        $this->assertSame(PubAprovacaoImagem::REGENERANDO, $c['fotos']['regenerando']->fresh()->estado);

        // (d) ⚠️⚠️ DECISÃO 8 DO USUÁRIO — *"a tela diz quem ficou de fora e por quê"*. O servidor
        //     entrega o dado que permite a frase; o TEXTO não é desta fase (PERGUNTA-188-D).
        $this->assertSame(1, (int) $flash['com_alerta_restantes'], 'sem este dado a tela não pode dizer quem ficou de fora');
        $this->assertSame(1, (int) $flash['pendentes']);

        // (e) O registro é POR CONJUNTO e com a contagem DAQUELE conjunto — e chega nas duas
        //     ofertas, porque `registrarNoConjunto()` escreve na linha do tempo de todas.
        $textos = $this->eventos($c['classico']);
        $this->assertContains('Aprovou 2 fotos sem alerta do conjunto compartilhado', $textos);
        $this->assertContains('Aprovou 1 fotos sem alerta do conjunto compartilhado', $textos);
        $this->assertSame($textos, $this->eventos($c['premium']), 'o Premium não recebeu os mesmos registros');

        // (f) Rodar de novo, com nada mais a aprovar, devolve **0** — e não um sucesso falso.
        $deNovo = $this->actingAs($admin)
            ->post($this->urlSemAlerta($company, $c['classico']))
            ->assertRedirect();
        $this->assertSame(0, (int) $deNovo->getSession()->get('painel_fotos_sem_alerta')['aprovadas']);

        // (g) Oferta de outra conta: 404, e nada aprovado nela.
        [$outraEmpresa, $outraMlb] = $this->conta();
        $alheio = $this->cenario($outraEmpresa, $outraMlb);
        $this->actingAs($admin)
            ->post($this->urlSemAlerta($company, $alheio['classico']))
            ->assertNotFound();
        $this->assertSame(PubAprovacaoImagem::PENDENTE, $alheio['fotos']['azul']->fresh()->estado);
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // (3) ⚠️⚠️ ZERO REQUISIÇÃO AO MERCADO LIVRE — provado por AUSÊNCIA, com ISCA
    // ═════════════════════════════════════════════════════════════════════════════════════════

    /**
     * ⚠️⚠️ **A FRONTEIRA DO CRITÉRIO 18, nas três rotas de imagem.** Aqui o "zero" é **literal**:
     * nenhuma das três lê composição financeira, então nenhuma tem motivo para tocar a rede. A
     * escrita no Mercado Livre é da **Fase 193**.
     *
     * ⚠️ **A ISCA É O QUE FAZ ESTE GATE MEDIR ALGO.** Sem ela, `Http::recorded()` vazio é
     * indistinguível de "o fake não está ligado" ou "o espião não registra nada" — e o gate ficaria
     * verde para sempre, inclusive depois de alguém publicar daqui.
     */
    public function test_as_tres_rotas_de_imagem_nao_fazem_nenhuma_requisicao_ao_ml_com_isca_188_05(): void
    {
        [$company, $empresa, $admin] = $this->conta();
        $c = $this->cenario($company, $empresa);

        // ⚠️ ISCA 1 — o espião NÃO está cego: uma requisição feita de propósito é registrada.
        Http::get('https://api.mercadolibre.com/items/MLB123');
        $this->assertCount(1, Http::recorded(), 'ISCA 1: o `Http::fake()` não está registrando nada — o gate é vazio');

        // ⚠️⚠️ ISCA 2 — `preventStrayRequests()` está de fato travando, e ela roda numa `Factory`
        // NOVA de propósito. **MEDIDO em 19/10/2026:** `Http::fake(['url' => …])` depois de um
        // `Http::fake()` sem argumento **NÃO estreita o conjunto de stubs — ele MERGEIA**, e o
        // curinga `*` do `setUp()` continua casando tudo. ➡️ A primeira redação desta isca
        // registrava um stub estreito, chamava uma URL "não combinada" e esperava a exceção: ela
        // **nunca vinha**, e a isca reprovou (certa) acusando que não estava provando nada.
        // ⚠️ É a família "asserção vazia quando escrita": sem a fábrica limpa, o `assertTrue` só
        // mediria a semântica de merge do Laravel, não a trava.
        $fabricaLimpa = new \Illuminate\Http\Client\Factory;
        $fabricaLimpa->preventStrayRequests();
        $fabricaLimpa->fake(['combinado.example/*' => Http::response([], 200)]);
        $explodiu = false;
        try {
            $fabricaLimpa->post('https://api.mercadolibre.com/items', ['title' => 'x']);
        } catch (\Throwable $e) {
            $explodiu = true;
        }
        $this->assertTrue($explodiu, 'ISCA 2: `preventStrayRequests()` não está travando nada');
        // ⚠️ E o lado positivo da mesma isca: a URL COMBINADA passa — senão o `assertTrue` acima
        // poderia estar verde por a fábrica estar quebrada de outro jeito.
        $this->assertSame(200, $fabricaLimpa->get('https://combinado.example/ok')->status());

        // ─── Agora o espião limpo, e as TRÊS rotas ───────────────────────────────────────────
        Http::fake();
        Http::preventStrayRequests();

        $this->actingAs($admin)->post($this->urlAprovarFoto($company, $c['classico'], $c['fotos']['limpa']))->assertRedirect();
        $this->actingAs($admin)->post($this->urlSemAlerta($company, $c['classico']))->assertRedirect();
        $this->actingAs($admin)
            ->post($this->urlReprovarFoto($company, $c['classico'], $c['fotos']['com_alerta']), ['motivo' => 'Pés diferentes da foto original.'])
            ->assertRedirect();

        // ⚠️⚠️ ZERO — e a lista das URLs vai na mensagem, para quem for depurar não precisar
        // adivinhar qual rota vazou.
        // ⚠️ `Http::recorded()` devolve **Collection** nesta versão do Laravel, não array:
        // `array_map` sobre ela lançaria. `->all()` é explícito.
        $saiu = Http::recorded()
            ->map(static fn ($par) => $par[0]->method().' '.$par[0]->url())
            ->values()
            ->all();
        $this->assertSame([], $saiu, 'critério 18: saiu requisição das rotas de imagem — a escrita no ML é da Fase 193');

        // E a prova de que as três de fato EXECUTARAM (gate sobre zero casos não prova nada).
        $this->assertSame(PubAprovacaoImagem::APROVADA, $c['fotos']['limpa']->fresh()->estado);
        $this->assertSame(PubAprovacaoImagem::APROVADA, $c['fotos']['azul']->fresh()->estado);
        $this->assertSame(PubAprovacaoImagem::REPROVADA, $c['fotos']['com_alerta']->fresh()->estado);
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // (4) ⚠️⚠️ REPROVAR A PRINCIPAL — OS CINCO EFEITOS, CADA UM COM ISCA (critério 13)
    // ═════════════════════════════════════════════════════════════════════════════════════════

    public function test_reprovar_a_principal_tem_os_cinco_efeitos_188_05(): void
    {
        [$company, $empresa, $admin] = $this->conta();
        $c = $this->cenario($company, $empresa);

        /** @var PubAprovacaoImagem $principal */
        $principal = $c['fotos']['principal'];
        /** @var PubAprovacaoImagem $proxima */
        $proxima = $c['fotos']['com_alerta'];

        // ⚠️⚠️ AS DUAS OFERTAS SÃO LEVADAS A `aprovado` **PELA ÚNICA PORTA**, e isso é exigência do
        // EFEITO 5, não decoração. **MEDIDO:** `registrarMudanca()` só emite o evento
        // *"Aprovação reaberta: …"* para oferta que estava **`aprovado`** — numa oferta em
        // `aguardando_aprovacao` há incremento de `versao` mas **nada a reabrir**, e a primeira
        // redação deste caso media o efeito 5 sobre um cenário em que ele era **inobservável por
        // construção**. ⚠️ `mover()` valida a TRANSIÇÃO, não os bloqueios: é por isso que dá para
        // chegar a `aprovado` aqui sem montar a conta financeira inteira.
        $porta = app(AndamentoDaOfertaService::class);
        foreach (['classico', 'premium'] as $qual) {
            $porta->mover($c[$qual], PubAprovacaoOferta::APROVADO, $admin);
        }

        // ⚠️ ISCA DO PONTO DE PARTIDA, nas cinco pontas: sem ela, cada efeito abaixo poderia já
        // ser verdade antes da rota e a asserção seria vazia.
        $this->assertSame($principal->imagem_id, $c['geral']->principal_imagem_id, 'ISCA 1: a foto reprovada não era a principal');
        $this->assertSame(0, (int) $principal->posicao, 'ISCA 2: ela já estava fora da ordem');
        $this->assertFalse((bool) $principal->fora, 'ISCA 3: ela já estava fora da publicação');
        $this->assertSame(1, (int) $c['classico']->fresh()->versao, 'ISCA 4: a versão não começa em 1 — a reabertura seria indistinguível');
        foreach (['classico', 'premium'] as $qual) {
            $this->assertSame(
                PubAprovacaoOferta::APROVADO,
                $c[$qual]->fresh()->andamento,
                "ISCA 5: a oferta `{$qual}` não está `aprovado` — o efeito 5 seria inobservável"
            );
        }

        // ─── A ESCRITA ───────────────────────────────────────────────────────────────────────
        $motivo = 'Pés diferentes da foto original do fabricante.';
        $this->actingAs($admin)
            ->post($this->urlReprovarFoto($company, $c['classico'], $principal), ['motivo' => $motivo])
            ->assertRedirect();

        $depois = $principal->fresh();

        // ─── EFEITO 1: sai do conjunto final NA HORA ─────────────────────────────────────────
        $this->assertSame(PubAprovacaoImagem::REPROVADA, $depois->estado);
        $this->assertFalse($depois->noConjuntoFinal(), 'efeito 1: a foto reprovada continua na galeria final');

        // ─── EFEITO 2: vai para "Fora da publicação" (as TRÊS marcas juntas) ─────────────────
        $this->assertNull($depois->posicao, 'efeito 2: a foto reprovada continua ocupando lugar na ordem');
        $this->assertTrue((bool) $depois->fora);
        $this->assertSame($motivo, $depois->motivo, 'T-188-47: o motivo não foi gravado');
        $this->assertSame($admin->id, (int) $depois->quem_id);

        // ─── EFEITO 3: ⚠️⚠️ A PRÓXIMA VIROU PRINCIPAL ────────────────────────────────────────
        $this->assertSame(
            $proxima->imagem_id,
            $c['geral']->fresh()->principal_imagem_id,
            'efeito 3: a capa aponta para foto que saiu da publicação — o anúncio subiria com a foto errada'
        );
        // E a ordem foi RECOMPACTADA, sem buraco na posição 0.
        $this->assertSame(0, (int) $proxima->fresh()->posicao);
        $this->assertSame(1, (int) $c['fotos']['limpa']->fresh()->posicao);

        // ─── EFEITO 4: o motivo registrado na linha do tempo ────────────────────────────────
        $textos = $this->eventos($c['classico']);
        $comMotivo = array_values(array_filter($textos, static fn (string $t) => str_contains($t, $motivo)));
        $this->assertCount(1, $comMotivo, 'efeito 4: o motivo não aparece na linha do tempo');
        $this->assertStringStartsWith('Reprovou a foto ', $comMotivo[0]);

        // ─── EFEITO 5: ⚠️⚠️ A APROVAÇÃO DE **TODAS** AS OFERTAS DO CONJUNTO FOI REABERTA ─────
        $prefixo = AprovacaoDaOfertaService::REABERTA_PREFIXO;
        foreach (['classico', 'premium'] as $qual) {
            $oferta = $c[$qual]->fresh();
            $this->assertSame(2, (int) $oferta->versao, "efeito 5: a versão da oferta `{$qual}` não foi incrementada");
            $reabertura = array_values(array_filter(
                $this->eventos($oferta),
                static fn (string $t) => str_starts_with($t, $prefixo)
            ));
            $this->assertCount(1, $reabertura, "efeito 5: a oferta `{$qual}` não registrou a reabertura");
        }

        // ⚠️ ISCA DO EFEITO 5 — e ela é a que pega a mutação "reabrir só a oferta, não o conjunto".
        // Se a reabertura alcançasse só a oferta da rota, o Premium ficaria na versão 1.
        $this->assertSame(
            (int) $c['classico']->fresh()->versao,
            (int) $c['premium']->fresh()->versao,
            'ISCA: as duas ofertas do conjunto divergiram de versão — a reabertura não propagou'
        );

        // ⚠️ E a oferta aprovada volta para `aguardando_aprovacao` — o 13º par das transições. O
        // andamento é movido pela ÚNICA PORTA, por dentro de `registrarMudanca()`: este teste
        // nunca escreve a coluna.
        $this->assertSame(PubAprovacaoOferta::AGUARDANDO_APROVACAO, $c['classico']->fresh()->andamento);
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // (5) O MOTIVO É OBRIGATÓRIO — e a trava real é do SERVIÇO (IMG-10 / T-188-47)
    // ═════════════════════════════════════════════════════════════════════════════════════════

    /**
     * ⚠️⚠️ **DIVERGÊNCIA DECLARADA, MEDIDA:** o protótipo exige `> 2` caracteres
     * (`Aprovacao.dc.html:1402` — ponteiro RECONFERIDO por `sed`; a prosa do plano dizia
     * `:1404`, que é o ramo `regen`, outro assunto) e `RevisaoDeImagemService::reprovar()` — **em produção desde a
     * Fase 186** — aceita **qualquer motivo não-vazio** depois do `trim()`.
     *
     * ➡️ O piso do **servidor** é não-vazio, e é isso que este caso mede. O `> 2` do protótipo é o
     * `disabled=` do **modal MOD-04** (planos 188-11/12): conforto de tela, como o `disabled=` do
     * botão de aprovar. Cravar `min:3` na action criaria **duas** regras sobre o mesmo campo, a da
     * action mais estrita que a do serviço que outros chamadores já usam — e a divergente só
     * apareceria quando alguém reprovasse por um motivo curto pela API e não pela tela.
     */
    public function test_reprovar_sem_motivo_e_recusado_sem_gravar_nada_188_05(): void
    {
        [$company, $empresa, $admin] = $this->conta();
        $c = $this->cenario($company, $empresa);

        /** @var PubAprovacaoImagem $foto */
        $foto = $c['fotos']['limpa'];
        $eventosAntes = $this->totalDeEventos();
        $versaoAntes = (int) $c['classico']->versao;

        // ─── (a) SEM A CHAVE e (b) STRING VAZIA: a porta da frente, 422 de validação ─────────
        foreach ([[], ['motivo' => '']] as $corpo) {
            $this->actingAs($admin)
                ->postJson($this->urlReprovarFoto($company, $c['classico'], $foto), $corpo)
                ->assertStatus(422)
                ->assertJsonValidationErrors('motivo');
        }

        // ─── (c) ⚠️⚠️ SÓ ESPAÇOS: recusado — mas quem chega primeiro é o FRAMEWORK ──────────
        //
        // **MEDIDO em 19/10/2026, e é correção de uma premissa minha:** o middleware
        // `TrimStrings` do Laravel apara a entrada **antes** da validação, então `'     '` chega
        // como `''` e morre no `required` — com `errors.motivo`, **não** com
        // `REGRA_SEM_MOTIVO`. ➡️ A primeira redação deste caso exigia a regra do serviço aqui e
        // reprovou: o gate estava medindo um caminho que, **pela rota**, não é alcançável.
        // ⚠️ Isso **não** torna a guarda do serviço decorativa — ela é o backstop de quem chama o
        // serviço **sem** passar por HTTP (jobs, commands, outras waves), e o bloco (c2) abaixo
        // prova que ela está viva. Afirmar "o serviço recusa" sem exercitá-lo direto seria a
        // asserção vazia que vira falso positivo no dia em que alguém a apagar.
        $this->actingAs($admin)
            ->postJson($this->urlReprovarFoto($company, $c['classico'], $foto), ['motivo' => '     '])
            ->assertStatus(422)
            ->assertJsonValidationErrors('motivo');

        // ─── (c2) ⚠️ A GUARDA DO SERVIÇO, EXERCITADA DIRETO — ela existe e está viva ────────
        $doServico = null;
        try {
            app(RevisaoDeImagemService::class)->reprovar($foto->fresh(), $admin, "  \t ");
        } catch (\App\Support\Publicador\RegraViolada $e) {
            $doServico = $e->regra;
        }
        $this->assertSame(
            RevisaoDeImagemService::REGRA_SEM_MOTIVO,
            $doServico,
            'IMG-10: a guarda de motivo do serviço sumiu — pela rota o `TrimStrings` a esconde, e aí nada mais protege os chamadores não-HTTP'
        );

        // ⚠️⚠️ NADA FOI GRAVADO em nenhum dos quatro — a recusa é no-op completo.
        $intacta = $foto->fresh();
        $this->assertSame(PubAprovacaoImagem::PENDENTE, $intacta->estado, 'T-188-47: a foto saiu sem motivo registrado');
        $this->assertSame(2, (int) $intacta->posicao);
        $this->assertFalse((bool) $intacta->fora);
        $this->assertNull($intacta->motivo);
        $this->assertSame($eventosAntes, $this->totalDeEventos(), 'a recusa gravou evento');
        $this->assertSame($versaoAntes, (int) $c['classico']->fresh()->versao, 'a recusa reabriu a aprovação');

        // ─── (d) ⚠️ UM CARACTERE: o SERVIDOR ACEITA — ver o docblock deste caso ─────────────
        $this->actingAs($admin)
            ->post($this->urlReprovarFoto($company, $c['classico'], $foto), ['motivo' => 'x'])
            ->assertRedirect();

        $this->assertSame(
            PubAprovacaoImagem::REPROVADA,
            $foto->fresh()->estado,
            'o piso do servidor deixou de ser "não-vazio" — se isso é desejado, é mudança no serviço da 186, não aqui'
        );
        $this->assertSame('x', $foto->fresh()->motivo);

        // ⚠️ ISCA DA DIVERGÊNCIA: o `> 2` do protótipo NÃO está no servidor, e o caso (d) é a
        // prova. Se um dia ele entrar, esta asserção vira vermelha e a pergunta volta à mesa.
        $this->assertGreaterThan($eventosAntes, $this->totalDeEventos());
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // (6) REPROVAR: conta alheia (404) e `regenerating` (422), sem gravar
    // ═════════════════════════════════════════════════════════════════════════════════════════

    public function test_reprovar_imagem_de_outra_conta_ou_regenerando_e_recusado_188_05(): void
    {
        [$company, $empresa, $admin] = $this->conta();
        $c = $this->cenario($company, $empresa);

        [$outraEmpresa, $outraMlb] = $this->conta();
        $alheio = $this->cenario($outraEmpresa, $outraMlb);

        $eventosAntes = $this->totalDeEventos();
        $motivo = 'Fundo com sombra forte demais.';

        // ─── (a) Oferta de outra conta: **404**, nunca 403 (T-188-44) ───────────────────────
        $resposta = $this->actingAs($admin)
            ->post($this->urlReprovarFoto($company, $alheio['classico'], $alheio['fotos']['limpa']), ['motivo' => $motivo]);
        $resposta->assertNotFound();
        $this->assertNotSame(403, $resposta->getStatusCode(), 'T-188-44: um 403 confirma que o id existe');

        // ─── (b) Foto de OUTRO rascunho, na oferta desta conta: 404 ─────────────────────────
        $meuOutro = $this->cenario($company, $empresa);
        $this->actingAs($admin)
            ->post($this->urlReprovarFoto($company, $c['classico'], $meuOutro['fotos']['limpa']), ['motivo' => $motivo])
            ->assertNotFound();

        // ─── (c) Empresa SEM conta ML conectada: 404 com a trava de conta (T-187-11) ────────
        $semToken = Company::factory()->create();
        $this->actingAs($admin)
            ->post($this->urlReprovarFoto($semToken, $c['classico'], $c['fotos']['limpa']), ['motivo' => $motivo])
            ->assertNotFound();

        // ─── (d) ⚠️ IMG-09 — `regenerating` não aceita reprovar ─────────────────────────────
        $this->actingAs($admin)
            ->postJson($this->urlReprovarFoto($company, $c['classico'], $c['fotos']['regenerando']), ['motivo' => $motivo])
            ->assertStatus(422)
            ->assertJsonPath('regra', RevisaoDeImagemService::REGRA_REGENERANDO);

        // ⚠️⚠️ NADA GRAVADO em nenhum dos quatro.
        $this->assertSame($eventosAntes, $this->totalDeEventos(), 'alguma das quatro recusas gravou evento');
        $this->assertSame(PubAprovacaoImagem::PENDENTE, $alheio['fotos']['limpa']->fresh()->estado);
        $this->assertSame(PubAprovacaoImagem::PENDENTE, $meuOutro['fotos']['limpa']->fresh()->estado);
        $this->assertSame(PubAprovacaoImagem::PENDENTE, $c['fotos']['limpa']->fresh()->estado);
        $this->assertSame(PubAprovacaoImagem::REGENERANDO, $c['fotos']['regenerando']->fresh()->estado);
        $this->assertNull($c['fotos']['regenerando']->fresh()->motivo);

        // ⚠️ ISCA: a MESMA chamada, na conta certa e com a foto certa, PASSA — sem isto as quatro
        // asserções acima poderiam estar vermelhas por um motivo que não é o recorte de conta.
        $this->actingAs($admin)
            ->post($this->urlReprovarFoto($company, $c['classico'], $c['fotos']['limpa']), ['motivo' => $motivo])
            ->assertRedirect();
        $this->assertSame(PubAprovacaoImagem::REPROVADA, $c['fotos']['limpa']->fresh()->estado);
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // (7) ⚠️ IMG-02 / IMG-03 — NÃO-REGRESSÃO, COM A FRONTEIRA DECLARADA
    // ═════════════════════════════════════════════════════════════════════════════════════════

    /**
     * ⚠️⚠️ **A FRONTEIRA, DECLARADA:** `IMG-02` e `IMG-03` são REQ-IDs **desta** fase, mas o
     * **caminho de TELA** deles (MOD-06 "substituir pela original", MOD-07 "regenerar com IA") é
     * das Fases **189/192**. ➡️ Este plano **não** desenha aquela UI e **não** abre rota para ela —
     * botão sem destino é **D4** violado, e antecipar a 189/192 é o que a régua proíbe.
     *
     * ➡️ O que este caso prova é que as regras de
     * {@see RevisaoDeImagemService::substituirPorOriginal()},
     * {@see RevisaoDeImagemService::recolocarOriginal()} e
     * {@see RevisaoDeImagemService::concluirRegeneracao()} **continuam valendo e não regrediram**
     * com as seis rotas novas no mesmo controller:
     *   · a entrante (nova / regenerada / recolocada) entra **`pending`**, precisando de aprovação;
     *   · a original entra **na MESMA posição** da que saiu;
     *   · e **mantém a aprovação** se já estava aprovada (IMG-02) — zerá-la obrigaria a pessoa a
     *     reconferir a MESMA foto que ela aprovou cinco minutos antes.
     *
     * ⚠️⚠️ **E ELE TEM ID PRÓPRIO NO MANIFESTO (`img-02-img-03-sem-regressao`), RODADO POR UM
     * `<verify>`.** Na primeira redação do plano o id existia no manifesto e **nenhum `<verify>` o
     * rodava — um id órfão é gate sobre ZERO casos, indistinguível de verde**, e é o gêmeo exato da
     * armadilha 1 da Fase 186 (teste sem id nunca roda). Os dois lados do mesmo buraco.
     */
    public function test_substituir_recolocar_e_concluir_regeneracao_nao_regrediram_188_05(): void
    {
        [$company, $empresa, $admin] = $this->conta();
        $c = $this->cenario($company, $empresa);
        $servico = app(RevisaoDeImagemService::class);

        // ─── IMG-03 · SUBSTITUIR POR ORIGINAL: a entrante assume a POSIÇÃO da que saiu ───────
        /** @var PubAprovacaoImagem $saindo */
        $saindo = $c['fotos']['limpa'];
        $posicaoDaQueSai = (int) $saindo->posicao;
        $originalCrua = PubImagem::create([
            'rascunho_id' => $c['rascunho']->id,
            'caminho' => 'fotos/original-de-referencia.jpg',
        ]);

        $entrante = $servico->substituirPorOriginal($saindo, $originalCrua, $admin);

        $this->assertSame(PubAprovacaoImagem::SUBSTITUIDA, $saindo->fresh()->estado);
        $this->assertSame($posicaoDaQueSai, (int) $entrante->posicao, 'IMG-03: a original não entrou na MESMA posição');
        $this->assertSame(PubAprovacaoImagem::PENDENTE, $entrante->estado, 'IMG-02: a entrante nasceu já aprovada');
        $this->assertSame((int) $originalCrua->id, (int) $entrante->imagem_id);

        // ⚠️ ISCA DA POSIÇÃO: ela não podia ser 0 por acaso — a fixture a põe em 2, e um serviço
        // que empilhasse no fim daria outra coisa.
        $this->assertSame(2, $posicaoDaQueSai, 'ISCA: a posição de partida virou 0 — a asserção perde o sujeito');

        // ─── IMG-02 · a aprovação da original é MANTIDA quando ela já estava aprovada ───────
        //
        // ⚠️⚠️ **A FIXTURE AQUI TEM DE SER DUAS LINHAS DIFERENTES, e isso está MEDIDO.** O unique
        // de `pub_aprovacao_imagens` é (`conjunto_id`, `imagem_id`), então `substituirPorOriginal`
        // resolve a entrante por aquele par. ➡️ Substituir uma foto **pela própria `PubImagem`
        // dela** faz entrante e substituída serem **a MESMA linha**: o serviço grava `approved` e
        // logo depois `replaced` em cima, e o resultado é `replaced`. A primeira redação deste
        // caso fazia exatamente isso e reprovou — o cenário era **degenerado**, não o invariante.
        // ➡️ O cenário REAL do IMG-02 é: uma original que **já foi aprovada e saiu** da publicação
        // (`fora`, `posicao` nula) voltando como substituta de **outra** foto.
        $originalJaAprovada = PubImagem::create([
            'rascunho_id' => $c['rascunho']->id,
            'caminho' => 'fotos/original-ja-conferida.jpg',
        ]);
        $linhaJaAprovada = PubAprovacaoImagem::create([
            'conjunto_id' => $c['geral']->id,
            'imagem_id' => $originalJaAprovada->id,
            'tipo' => PubAprovacaoImagem::TIPO_ORIGINAL,
            'estado' => PubAprovacaoImagem::APROVADA,
            'posicao' => null,
            'fora' => true,
        ]);
        // ⚠️ ISCA DA FIXTURE: as duas linhas são DISTINTAS, senão o caso volta a ser degenerado.
        $this->assertNotSame((int) $linhaJaAprovada->id, (int) $c['fotos']['com_alerta']->id);

        $outroEntrante = $servico->substituirPorOriginal($c['fotos']['com_alerta']->fresh(), $originalJaAprovada, $admin);
        $this->assertSame(
            (int) $linhaJaAprovada->id,
            (int) $outroEntrante->id,
            'a entrante não é a linha de revisão que já existia para esta original'
        );
        $this->assertSame(
            PubAprovacaoImagem::APROVADA,
            $outroEntrante->estado,
            'IMG-02: a original já aprovada voltou como `pending` — a pessoa reconferiria a foto que ela mesma aprovou'
        );
        $this->assertFalse((bool) $outroEntrante->fora, 'IMG-02: a entrante continuou fora da publicação');

        // ─── IMG-08 · RECOLOCAR A ORIGINAL: volta `pending`, na posição da que saiu ─────────
        $recolocada = $servico->recolocarOriginal($entrante->fresh(), $admin);
        $this->assertSame(PubAprovacaoImagem::PENDENTE, $recolocada->estado);
        $this->assertNotNull($recolocada->posicao);

        // ─── IMG-09 · CONCLUIR REGENERAÇÃO: a nova entra `pending` ──────────────────────────
        $novaCrua = PubImagem::create([
            'rascunho_id' => $c['rascunho']->id,
            'caminho' => 'fotos/regenerada-pela-ia.jpg',
        ]);
        $regenerada = $servico->concluirRegeneracao($c['fotos']['regenerando']->fresh(), $novaCrua, $admin);
        $this->assertSame(
            PubAprovacaoImagem::PENDENTE,
            $regenerada->estado,
            'IMG-09: a foto regenerada pela IA nasceu aprovada — ninguém a conferiu'
        );
        $this->assertSame((int) $novaCrua->id, (int) $regenerada->imagem_id);

        // ─── ⚠️ A FRONTEIRA 189/192, PROVADA POR AUSÊNCIA DE ROTA ──────────────────────────
        // ⚠️ A sonda é a LISTA CONSTANTE das três rotas que a 189/192 vai criar — não um `grep`
        // largo por "substituir", que casaria qualquer comentário. E o recorte é o nosso prefixo:
        // cobrar o projeto inteiro é a família "gate mais largo que o invariante".
        $doPainel = [];
        foreach (app('router')->getRoutes() as $rota) {
            $nome = (string) $rota->getName();
            if (str_starts_with($nome, 'mlb.anuncios.meus.aprovacao.painel.')) {
                $doPainel[] = $nome;
            }
        }
        sort($doPainel);

        // ⚠️ IGUALDADE DE CONJUNTO, nunca `>= 6`: piso não prova PRESENÇA, e perder uma rota
        // deixaria cinco passando por um `assertGreaterThan`.
        $this->assertSame([
            'mlb.anuncios.meus.aprovacao.painel.aprovar',
            'mlb.anuncios.meus.aprovacao.painel.fotos.sem-alerta',
            'mlb.anuncios.meus.aprovacao.painel.imagem.aprovar',
            'mlb.anuncios.meus.aprovacao.painel.imagem.reprovar',
            'mlb.anuncios.meus.aprovacao.painel.salvar-revisao',
            'mlb.anuncios.meus.aprovacao.painel.tentar-de-novo',
        ], $doPainel, 'as rotas do painel mudaram — se é a 189/192 entrando, atualize a fronteira declarada aqui');

        // ⚠️ ISCA DA SONDA DE ROTA: ela DEVE casar algo que existe (senão o `assertSame` acima
        // seria `[] === []` e o gate estaria vazio por construção).
        $this->assertNotEmpty($doPainel, 'ISCA: a sonda não achou NENHUMA rota do painel — ela não está medindo nada');
    }
}
