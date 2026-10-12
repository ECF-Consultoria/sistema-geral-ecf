<?php

namespace Tests\Feature\Publicador;

use App\Models\Company;
use App\Models\EstruturaOferta;
use App\Models\MlbEmpresa;
use App\Models\MlToken;
use App\Models\PubProduto;
use App\Models\PubPublicacao;
use App\Models\PubPublicacaoItem;
use App\Models\PubRascunho;
use App\Models\User;
use App\Services\Publicador\NovaPublicacaoService;
use App\Services\Publicador\PainelVisaoGeralService;
use App\Services\Publicador\ProgramasPublicadorService;
use App\Support\Publicador\Variacao\ChaveCanonica;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Fase 178, plano 01 — o CONTRATO do servidor para o modal "Nova publicação":
 * os três grupos do "próximo passo" de cada produto da conta, e o endpoint lazy
 * que o modal consome na abertura.
 *
 * O que este arquivo guarda (e que uma asserção isolada não guardaria):
 *
 * 1. **Os três grupos vêm SEMPRE, na ordem fixa** `prontos_fase2 →
 *    sem_publicacao → rascunhos`, inclusive vazios (NOVA-08). "Grupo sem item
 *    não aparece" (NOVA-03) é regra de RENDER, da wave 2 — nunca do payload.
 * 2. **A regra de "pronto para a Fase 2" é a MESMA da Visão geral.** O gate
 *    `test_regra_de_prontos_fase2_bate_com_a_da_visao_geral()` compara o número
 *    de itens contra `PainelVisaoGeralService::prontosParaFase2()` por reflexão:
 *    se as duas definições divergirem, as duas telas discordariam sobre o mesmo
 *    produto e este teste reprova.
 * 3. **Todo item tem destino REAL** montado no servidor (NOVA-07). Item sem
 *    destino usável não entra no grupo — e isso tem teste próprio.
 * 4. **Nenhum campo do item é array ou objeto.** Foi um campo-objeto renderizado
 *    como texto que derrubou a página inteira em 07/10 ("Objects are not valid
 *    as a React child"). As chaves são comparadas contra um ARRAY LITERAL, em
 *    ordem, e todo valor é varrido por `is_array`/`is_object`.
 * 5. **As exclusões são cobradas por teste**, uma a uma: `publicando`, `erro`,
 *    composto do Planejamento, base publicada que já tem kit e lista de shape
 *    antigo (sem `eh_kit`).
 * 6. **A consulta pesada roda UMA vez por chamada** — cobrado por expectativa
 *    (`->once()`), não só por contagem de consultas.
 * 7. **A rota rejeita `{conta}` fora do padrão ANTES do controller** — provado
 *    por `resolver()` nunca ser chamado, não por "deu 404" (um 404 do resolver
 *    passaria pelo motivo errado).
 *
 * ═══ Este arquivo foi PROVADO POR MUTAÇÃO ════════════════════════════════════
 *
 * Em 12/10/2026 rodaram **14 mutações** no código real (o serviço, a action e a
 * rota). **Todas as 14 reprovaram**, e cada uma caiu no caso que devia cair —
 * não num caso qualquer. O relato completo está em `178-01-SUMMARY.md`. Os três
 * arquivos foram restaurados de cópia pristina com conferência por `sha256`.
 *
 * Três gates deste arquivo existem porque o gate óbvio passaria pelo motivo
 * errado — **não afrouxe nenhum dos três**:
 *
 * - `test_rota_recusa_conta_fora_do_padrao_antes_do_controller` cobra que
 *   `resolver()` **nunca seja chamado**, não "deu 404": o resolver também
 *   devolve 404 para chave desconhecida, então um teste de status passaria
 *   mesmo SEM o `->where('conta', …)` na rota.
 * - `test_a_consulta_pesada_roda_uma_vez_por_chamada` cobra `->once()`: duas
 *   chamadas a `produtosParaTela()` ainda caberiam no teto do detector de N+1.
 * - `test_base_publicada_que_ja_tem_kit_nao_entra_em_nenhum_grupo` dá ao kit um
 *   rascunho PUBLICADO para poder cobrar `total === 0`; sem isso o kit cairia em
 *   `sem_publicacao` e o total deixaria de ser uma asserção forte.
 *
 * ⚠️ Nenhum teste aqui chama o Mercado Livre: `Http::fake()` sem handler
 * intercepta tudo (D-05 — este contrato só lê o que já está gravado).
 *
 * ⚠️ Os testes rodam em SQLite; produção é MySQL 8 e o dev local é MariaDB.
 * Este plano **não cria migration** (é tudo leitura), então as armadilhas de
 * enum/índice/FK não se aplicam — não há nada a validar no VPS.
 *
 * @group phase178
 */
class NovaPublicacaoDoHeaderTest extends TestCase
{
    use RefreshDatabase;

    /** As chaves do item, em ORDEM. Campo a mais aqui é convite a inventar tela na wave 2. */
    private const CAMPOS_DO_ITEM = ['produto_id', 'nome', 'sku', 'nota', 'capa', 'url'];

    /** A ordem dos grupos é contrato: a wave 2 renderiza nesta sequência. */
    private const ORDEM_DOS_GRUPOS = ['prontos_fase2', 'sem_publicacao', 'rascunhos'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        // O HandleInertiaRequests busca os sinais do ECF Drive em toda página autenticada.
        Http::fake();
    }

    // ═══ Apoio ═══════════════════════════════════════════════════════════════

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    /**
     * Uma conta de Polos com token ativo. Com Company o token fica na Company;
     * sem Company (D23 — 535 de 539 `MlbEmpresa` não têm Company) ele fica na
     * própria `MlbEmpresa`.
     *
     * @return array{0: MlbEmpresa, 1: ?Company}
     */
    private function conta(bool $comCompany = true, string $nome = 'Polo da Nova Publicação'): array
    {
        $company = $comCompany ? Company::factory()->create(['name' => $nome]) : null;
        $empresa = MlbEmpresa::create(['nome' => $nome, 'projeto' => 'POLOS', 'company_id' => $company?->id]);
        MlToken::create([
            'company_id' => $company?->id,
            'mlb_empresa_id' => $company === null ? $empresa->id : null,
            'ml_user_id' => '1555596317',
            'access_token' => 'APP_USR-segredo',
            'refresh_token' => 'TG-segredo',
            'expires_at' => now()->addHours(5),
            'status' => 'active',
        ]);

        return [$empresa->fresh(), $company];
    }

    private function produto(MlbEmpresa $e, ?Company $c, string $sku = 'SKU-01', array $extra = []): PubProduto
    {
        return PubProduto::create($extra + [
            'mlb_empresa_id' => $e->id,
            'company_id' => $c?->id,
            'sku' => $sku,
            'nome' => 'Cadeira '.$sku,
            'origem' => PubProduto::ORIGEM_PUBLICADOR,
        ]);
    }

    private function rascunho(PubProduto $p, string $status, array $extra = []): PubRascunho
    {
        return PubRascunho::create($extra + ['produto_id' => $p->id, 'status' => $status, 'revisao' => 1]);
    }

    /** Um kit da Fase N do produto base: mesmas âncoras, `quantidade_kit >= 2`. */
    private function kit(PubProduto $base, int $quantidade = 2): PubProduto
    {
        return $this->produto(
            $base->mlbEmpresa,
            $base->company,
            $base->sku.'-K'.$quantidade,
            ['produto_base_id' => $base->id, 'quantidade_kit' => $quantidade, 'fase' => $quantidade],
        );
    }

    /** Um produto ligado a uma oferta Combo/Kit/Combit do Portal — composto, nunca base de Fase 1 (KIT-06). */
    private function composto(MlbEmpresa $e, Company $c, string $tipo, string $sku): PubProduto
    {
        $oferta = EstruturaOferta::create(['company_id' => $c->id, 'sku' => $sku, 'fase' => $tipo, 'nome' => $sku]);

        return $this->produto($e, $c, $sku, ['oferta_id' => $oferta->id, 'origem' => PubProduto::ORIGEM_PORTAL]);
    }

    /** N anúncios CREATED no rascunho — é o que `anuncios` conta na nota de prontos_fase2. */
    private function anuncios(PubRascunho $r, int $quantos): void
    {
        $publicacao = PubPublicacao::create([
            'rascunho_id' => $r->id, 'revisao' => 1, 'modelo_publicacao' => 'items',
            'status' => PubPublicacao::PUBLISHED, 'chave_idempotencia' => (string) Str::uuid(),
            'concluida_em' => now()->subDay(), 'ator' => ['equipe' => true, 'id' => 1, 'nome' => 'Dev'],
        ]);
        for ($i = 0; $i < $quantos; $i++) {
            PubPublicacaoItem::create([
                'publicacao_id' => $publicacao->id, 'indice' => $i,
                'listing_type_id' => $i === 0 ? 'gold_special' : 'gold_pro',
                'variante_chave' => ChaveCanonica::UNICA, 'status' => PubPublicacaoItem::CREATED,
                'ml_item_id' => 'MLB'.(7770000 + $i), 'payload' => [],
            ]);
        }
    }

    private function programas(): ProgramasPublicadorService
    {
        return app(ProgramasPublicadorService::class);
    }

    /** O `$alvo` canônico da conta, do MESMO `resolver()` que o controller usa. */
    private function alvo(MlbEmpresa $e): array
    {
        return $this->programas()->resolver('empresa-'.$e->id);
    }

    private function grupos(MlbEmpresa $e): array
    {
        return app(NovaPublicacaoService::class)->grupos($this->alvo($e));
    }

    /** Os itens de um grupo, pela chave. */
    private function itens(array $saida, string $chave): array
    {
        foreach ($saida['grupos'] as $g) {
            if ($g['chave'] === $chave) {
                return $g['itens'];
            }
        }
        $this->fail("o grupo '{$chave}' não veio na saída");
    }

    /** Os SKUs de um grupo, na ordem em que vieram. */
    private function skus(array $saida, string $chave): array
    {
        return array_column($this->itens($saida, $chave), 'sku');
    }

    private function url(MlbEmpresa $e): string
    {
        return '/mlb/anuncios/publicador/empresas/empresa-'.$e->id.'/nova-publicacao';
    }

    // ═══ 1. O shape: três grupos, sempre, na ordem fixa ══════════════════════

    public function test_conta_sem_produto_devolve_os_tres_grupos_vazios_e_total_zero(): void
    {
        [$e] = $this->conta();

        $saida = $this->grupos($e);

        $this->assertSame(['total', 'grupos'], array_keys($saida));
        $this->assertSame(0, $saida['total']);
        $this->assertSame(self::ORDEM_DOS_GRUPOS, array_column($saida['grupos'], 'chave'));
        foreach ($saida['grupos'] as $g) {
            $this->assertSame(['chave', 'itens'], array_keys($g));
            $this->assertSame([], $g['itens']);
        }
    }

    /**
     * ⚠️ Este é o teste que impede o "conserto" errado de NOVA-03: filtrar grupo
     * vazio é regra de RENDER (wave 2), não de payload. Aqui só um grupo tem
     * item e os outros DOIS têm de vir, vazios, no lugar certo.
     */
    public function test_grupo_vazio_continua_no_payload_porque_o_filtro_e_do_cliente(): void
    {
        [$e, $c] = $this->conta();
        $this->produto($e, $c, 'SO-RASCUNHO');

        $saida = $this->grupos($e);

        $this->assertSame(self::ORDEM_DOS_GRUPOS, array_column($saida['grupos'], 'chave'));
        $this->assertSame([], $this->itens($saida, 'prontos_fase2'));
        $this->assertCount(1, $this->itens($saida, 'sem_publicacao'));
        $this->assertSame([], $this->itens($saida, 'rascunhos'));
        $this->assertSame(1, $saida['total']);
    }

    public function test_total_e_a_soma_dos_itens_dos_tres_grupos(): void
    {
        [$e, $c] = $this->conta();

        $pronto = $this->produto($e, $c, 'PRONTO');
        $this->rascunho($pronto, PubRascunho::PUBLISHED);
        $this->produto($e, $c, 'SEM-1');
        $this->produto($e, $c, 'SEM-2');
        $this->rascunho($this->produto($e, $c, 'CONFERIR'), PubRascunho::DRAFT);

        $saida = $this->grupos($e);

        $this->assertCount(1, $this->itens($saida, 'prontos_fase2'));
        $this->assertCount(2, $this->itens($saida, 'sem_publicacao'));
        $this->assertCount(1, $this->itens($saida, 'rascunhos'));
        $this->assertSame(4, $saida['total']);
    }

    // ═══ 2. A regra de prontos_fase2 ═════════════════════════════════════════

    public function test_base_publicada_sem_kit_entra_em_prontos_fase2(): void
    {
        [$e, $c] = $this->conta();
        $base = $this->produto($e, $c, 'CAD-PUB');
        $this->rascunho($base, PubRascunho::PUBLISHED);

        $saida = $this->grupos($e);

        $this->assertSame(['CAD-PUB'], $this->skus($saida, 'prontos_fase2'));
        $this->assertSame([], $this->itens($saida, 'sem_publicacao'));
        $this->assertSame([], $this->itens($saida, 'rascunhos'));
    }

    /** `parcial` está em `STATUS_NO_AR`: é a MESMA regra de `prontosParaFase2()`. */
    public function test_base_parcial_sem_kit_tambem_entra_em_prontos_fase2(): void
    {
        [$e, $c] = $this->conta();
        $base = $this->produto($e, $c, 'CAD-PARC');
        $this->rascunho($base, PubRascunho::PARTIALLY_PUBLISHED);

        $saida = $this->grupos($e);

        $this->assertSame(['CAD-PARC'], $this->skus($saida, 'prontos_fase2'));
    }

    /**
     * A base que JÁ TEM kit não espera mais nada: sai de prontos_fase2 e não cai
     * em grupo nenhum. O kit dela entra publicado aqui de propósito, para o
     * total poder ser cobrado como ZERO — se a exclusão da base cair, o total
     * deixa de ser 0 e este teste reprova.
     */
    public function test_base_publicada_que_ja_tem_kit_nao_entra_em_nenhum_grupo(): void
    {
        [$e, $c] = $this->conta();
        $base = $this->produto($e, $c, 'CAD-COM-KIT');
        $this->rascunho($base, PubRascunho::PUBLISHED);
        $kit = $this->kit($base, 2);
        $this->rascunho($kit, PubRascunho::PUBLISHED);

        $saida = $this->grupos($e);

        $this->assertSame([], $this->itens($saida, 'prontos_fase2'));
        $this->assertSame([], $this->itens($saida, 'sem_publicacao'));
        $this->assertSame([], $this->itens($saida, 'rascunhos'));
        $this->assertSame(0, $saida['total']);
    }

    /**
     * ⚠️ GATE DE CONSISTÊNCIA. A regra de "pronto para a Fase 2" é copiada de
     * `PainelVisaoGeralService::prontosParaFase2()` (app/Services/Publicador/
     * PainelVisaoGeralService.php:592) com citação no docblock, porque lá ela é
     * `private` e aquele arquivo não pode ser tocado nesta fase. Se as duas
     * divergirem, a Visão geral e o modal discordariam sobre o MESMO produto —
     * então aqui o número de itens é comparado contra a regra original, por
     * reflexão, numa lista com os cinco casos de fronteira juntos.
     */
    public function test_regra_de_prontos_fase2_bate_com_a_da_visao_geral(): void
    {
        [$e, $c] = $this->conta();

        $this->rascunho($this->produto($e, $c, 'PUB'), PubRascunho::PUBLISHED);            // entra
        $this->rascunho($this->produto($e, $c, 'PARC'), PubRascunho::PARTIALLY_PUBLISHED); // entra
        $comKit = $this->produto($e, $c, 'COM-KIT');
        $this->rascunho($comKit, PubRascunho::PUBLISHED);
        $this->rascunho($this->kit($comKit, 2), PubRascunho::PUBLISHED);                   // base sai (tem kit)
        $this->rascunho($this->composto($e, $c, 'combo', 'CB-PUB'), PubRascunho::PUBLISHED); // sai (composto)
        $this->rascunho($this->produto($e, $c, 'DRAFT'), PubRascunho::DRAFT);              // sai (não está no ar)

        $produtos = $this->programas()->produtosParaTela($e, $c, 'empresa-'.$e->id);
        $contagem = $this->programas()->contagemProdutos($produtos);

        $metodo = new \ReflectionMethod(PainelVisaoGeralService::class, 'prontosParaFase2');
        $metodo->setAccessible(true);
        $daVisaoGeral = $metodo->invoke(app(PainelVisaoGeralService::class), $contagem, $produtos);

        $saida = $this->grupos($e);

        $this->assertSame(2, $daVisaoGeral, 'a regra original mudou — reler PainelVisaoGeralService:592');
        $this->assertCount(
            $daVisaoGeral,
            $this->itens($saida, 'prontos_fase2'),
            'o modal e a Visão geral discordam sobre quantos produtos esperam Fase 2',
        );
        $this->assertEqualsCanonicalizing(['PUB', 'PARC'], $this->skus($saida, 'prontos_fase2'));
    }

    // ═══ 3. Os outros dois grupos, por status ════════════════════════════════

    public function test_produto_sem_rascunho_vai_para_sem_publicacao(): void
    {
        [$e, $c] = $this->conta();
        $this->produto($e, $c, 'NUNCA-TOCADO');

        $saida = $this->grupos($e);

        $this->assertSame(['NUNCA-TOCADO'], $this->skus($saida, 'sem_publicacao'));
        $this->assertSame([], $this->itens($saida, 'rascunhos'));
    }

    public function test_rascunho_em_preenchimento_e_conferido_vao_para_rascunhos(): void
    {
        [$e, $c] = $this->conta();
        $this->rascunho($this->produto($e, $c, 'EM-PREENCHIMENTO'), PubRascunho::DRAFT);
        $this->rascunho($this->produto($e, $c, 'CONFERIDO'), PubRascunho::VALIDATED);

        $saida = $this->grupos($e);

        $this->assertSame([], $this->itens($saida, 'prontos_fase2'));
        $this->assertSame([], $this->itens($saida, 'sem_publicacao'));
        $this->assertEqualsCanonicalizing(['EM-PREENCHIMENTO', 'CONFERIDO'], $this->skus($saida, 'rascunhos'));
    }

    // ═══ 4. As exclusões declaradas ══════════════════════════════════════════

    /** Em voo: o próximo passo não é do usuário. Nunca em `rascunhos`. */
    public function test_publicando_fica_fora_dos_tres_grupos(): void
    {
        [$e, $c] = $this->conta();
        $this->rascunho($this->produto($e, $c, 'EM-VOO'), PubRascunho::PUBLISHING);

        $saida = $this->grupos($e);

        $this->assertSame(0, $saida['total']);
        foreach (self::ORDEM_DOS_GRUPOS as $chave) {
            $this->assertSame([], $this->itens($saida, $chave), "'publicando' não pode cair em {$chave}");
        }
    }

    /** Erro de publicação é TRIAGEM, e a casa dela é "O que fazer agora" (Fase 179). */
    public function test_erro_de_publicacao_fica_fora_dos_tres_grupos(): void
    {
        [$e, $c] = $this->conta();
        $this->rascunho($this->produto($e, $c, 'FALHOU'), PubRascunho::FAILED);

        $saida = $this->grupos($e);

        $this->assertSame(0, $saida['total']);
        foreach (self::ORDEM_DOS_GRUPOS as $chave) {
            $this->assertSame([], $this->itens($saida, $chave), "'erro' não pode cair em {$chave}");
        }
    }

    /**
     * KIT-06: composto do Planejamento não é base de Fase 1 nem espera Fase 2.
     * Os DOIS estados entram no caso — publicado (tentaria prontos_fase2) e em
     * preenchimento (tentaria rascunhos) —, senão a guarda poderia cair pela
     * metade sem o teste acusar.
     */
    public function test_composto_do_planejamento_fica_fora_dos_tres_grupos(): void
    {
        [$e, $c] = $this->conta();
        $this->rascunho($this->composto($e, $c, 'combo', 'CB-PUB'), PubRascunho::PUBLISHED);
        $this->rascunho($this->composto($e, $c, 'kit', 'KT-DRAFT'), PubRascunho::DRAFT);
        $this->composto($e, $c, 'combit', 'CT-SEM-RASCUNHO');

        $saida = $this->grupos($e);

        $this->assertSame(0, $saida['total']);
        foreach (self::ORDEM_DOS_GRUPOS as $chave) {
            $this->assertSame([], $this->itens($saida, $chave), "composto não pode cair em {$chave}");
        }
    }

    /**
     * Lista de shape ANTIGO (sem a chave `eh_kit`): não classificável, fica fora
     * dos três grupos e **nada lança**. ⚠️ De propósito SEM o fallback por
     * `contagemProdutos` que `PainelVisaoGeralService` tem: ali o fallback
     * devolve um NÚMERO; aqui o modal precisa de ITENS, e um número sem item
     * correspondente não tem o que mostrar.
     */
    public function test_produto_de_shape_antigo_nao_e_classificavel_e_nada_lanca(): void
    {
        $this->mock(ProgramasPublicadorService::class, function ($m) {
            $m->shouldReceive('produtosParaTela')->once()->andReturn([
                // Shape da Etapa 1/2: sem `eh_kit`, sem `kits`, sem `url_produto`.
                ['id' => 1, 'sku' => 'ANTIGO', 'nome' => 'Cadeira antiga', 'origem' => 'portal',
                    'status' => ['chave' => 'publicado', 'rotulo' => 'publicado', 'faltam' => 0]],
                ['id' => 2, 'sku' => 'ANTIGO-2', 'nome' => 'Mesa antiga', 'origem' => 'portal',
                    'status' => ['chave' => 'rascunho', 'rotulo' => 'a preencher', 'faltam' => 0]],
            ]);
        });

        $saida = app(NovaPublicacaoService::class)->grupos(['mlb_empresa' => null, 'company' => null, 'chave' => 'empresa-1']);

        $this->assertSame(0, $saida['total']);
        $this->assertSame(self::ORDEM_DOS_GRUPOS, array_column($saida['grupos'], 'chave'));
        foreach ($saida['grupos'] as $g) {
            $this->assertSame([], $g['itens']);
        }
    }

    // ═══ 5. O item: seis campos, todos escalares, destino real ═══════════════

    public function test_item_tem_exatamente_seis_campos_e_nenhum_e_array_ou_objeto(): void
    {
        [$e, $c] = $this->conta();
        $pronto = $this->produto($e, $c, 'PRONTO');
        $this->rascunho($pronto, PubRascunho::PUBLISHED);
        $this->produto($e, $c, 'SEM');
        $this->rascunho($this->produto($e, $c, 'RASC'), PubRascunho::DRAFT);

        $saida = $this->grupos($e);

        $vistos = 0;
        foreach ($saida['grupos'] as $g) {
            foreach ($g['itens'] as $item) {
                $vistos++;
                $this->assertSame(self::CAMPOS_DO_ITEM, array_keys($item), 'o shape do item é contrato da wave 2');
                $this->assertIsInt($item['produto_id']);
                $this->assertIsString($item['nome']);
                $this->assertIsString($item['sku']);
                $this->assertIsString($item['nota']);
                $this->assertIsString($item['url']);
                $this->assertTrue($item['capa'] === null || is_string($item['capa']));
                foreach ($item as $chave => $valor) {
                    $this->assertFalse(is_array($valor), "o campo '{$chave}' é array — foi assim que a tela ficou preta em 07/10");
                    $this->assertFalse(is_object($valor), "o campo '{$chave}' é objeto — a wave 2 renderiza isso como texto");
                }
            }
        }
        $this->assertSame(3, $vistos);
    }

    /** ⚠️ Nenhum rótulo de UI no payload: "PRONTOS PARA FASE 2"/hint/cta são literais da wave 2. */
    public function test_payload_nao_carrega_rotulo_de_tela(): void
    {
        [$e, $c] = $this->conta();
        $this->rascunho($this->produto($e, $c, 'PRONTO'), PubRascunho::PUBLISHED);

        $saida = $this->grupos($e);

        foreach ($saida['grupos'] as $g) {
            $this->assertArrayNotHasKey('rotulo', $g);
            $this->assertArrayNotHasKey('hint', $g);
            $this->assertArrayNotHasKey('cta', $g);
        }
        $this->assertStringNotContainsStringIgnoringCase('PRONTOS PARA FASE 2', json_encode($saida, JSON_UNESCAPED_UNICODE));
        $this->assertStringNotContainsStringIgnoringCase('Criar combo', json_encode($saida, JSON_UNESCAPED_UNICODE));
    }

    public function test_pronto_aponta_para_a_tela_do_produto_e_os_outros_para_o_editor(): void
    {
        [$e, $c] = $this->conta();
        $pronto = $this->produto($e, $c, 'PRONTO');
        $this->rascunho($pronto, PubRascunho::PUBLISHED);
        $sem = $this->produto($e, $c, 'SEM');
        $rasc = $this->produto($e, $c, 'RASC');
        $this->rascunho($rasc, PubRascunho::DRAFT);

        $saida = $this->grupos($e);

        $this->assertSame(
            route('mlb.anuncios.publicador.produto', ['conta' => 'empresa-'.$e->id, 'produto' => $pronto->id]),
            $this->itens($saida, 'prontos_fase2')[0]['url'],
        );
        $this->assertSame(
            route('mlb.anuncios.publicador.editor', ['produto' => $sem->id]),
            $this->itens($saida, 'sem_publicacao')[0]['url'],
        );
        $this->assertSame(
            route('mlb.anuncios.publicador.editor', ['produto' => $rasc->id]),
            $this->itens($saida, 'rascunhos')[0]['url'],
        );
    }

    /**
     * NOVA-07 é literal: nenhum CTA sem destino. Sem `chaveConta` o
     * `url_produto` de `produtosParaTela()` vem `null` — e aí o item de
     * prontos_fase2 **fica fora do grupo** em vez de ir para a tela sem destino.
     */
    public function test_item_de_prontos_fase2_sem_destino_fica_fora_do_grupo(): void
    {
        [$e, $c] = $this->conta();
        $this->rascunho($this->produto($e, $c, 'PRONTO-SEM-URL'), PubRascunho::PUBLISHED);
        $this->produto($e, $c, 'SEM');

        // `$alvo` sem a chave da conta: é a única condição em que `url_produto` é null.
        $saida = app(NovaPublicacaoService::class)->grupos(['mlb_empresa' => $e, 'company' => $c]);

        $this->assertSame([], $this->itens($saida, 'prontos_fase2'), 'item sem destino não pode entrar (NOVA-07)');
        $this->assertSame(['SEM'], $this->skus($saida, 'sem_publicacao'), 'o editor não depende da chave da conta');
        $this->assertSame(1, $saida['total']);
    }

    // ═══ 6. As notas, construídas no servidor e só de campo medido ═══════════

    public function test_nota_de_prontos_fase2_conta_os_anuncios_no_ar(): void
    {
        [$e, $c] = $this->conta();
        $comDois = $this->produto($e, $c, 'COM-DOIS');
        $this->anuncios($this->rascunho($comDois, PubRascunho::PUBLISHED), 2);
        $semNenhum = $this->produto($e, $c, 'SEM-ANUNCIO');
        $this->rascunho($semNenhum, PubRascunho::PUBLISHED);

        $notas = collect($this->itens($this->grupos($e), 'prontos_fase2'))->pluck('nota', 'sku')->all();

        $this->assertSame('Fase 1 publicada · 2 no ar', $notas['COM-DOIS']);
        $this->assertSame('Fase 1 publicada', $notas['SEM-ANUNCIO']);
    }

    public function test_nota_de_sem_publicacao_vem_da_origem_do_produto(): void
    {
        [$e, $c] = $this->conta();
        $this->produto($e, $c, 'DO-PORTAL', ['origem' => PubProduto::ORIGEM_PORTAL]);
        $this->produto($e, $c, 'DO-PUBLICADOR', ['origem' => PubProduto::ORIGEM_PUBLICADOR]);
        // `pub_produtos.origem` é `string(12)` NOT NULL sem enum nem CHECK: uma origem
        // nova (de uma fase futura) chega aqui sem aviso. O ramo `default` existe para
        // ela nunca virar nota vazia.
        $this->produto($e, $c, 'SEM-ORIGEM', ['origem' => 'legado']);

        $notas = collect($this->itens($this->grupos($e), 'sem_publicacao'))->pluck('nota', 'sku')->all();

        $this->assertSame('Recebido do Portal do Cliente', $notas['DO-PORTAL']);
        $this->assertSame('Cadastro manual no Publicador', $notas['DO-PUBLICADOR']);
        $this->assertSame('Origem não informada', $notas['SEM-ORIGEM']);
    }

    public function test_nota_de_rascunhos_diz_o_estado_e_quantos_bloqueios_faltam(): void
    {
        [$e, $c] = $this->conta();
        $this->rascunho($this->produto($e, $c, 'CONFERIDO'), PubRascunho::VALIDATED);
        $this->rascunho($this->produto($e, $c, 'FALTAM-TRES'), PubRascunho::DRAFT, [
            'step_state' => ['resumo' => ['bloqueios' => 3, 'revisao' => 1]],
        ]);
        $this->rascunho($this->produto($e, $c, 'SEM-BLOQUEIO'), PubRascunho::DRAFT);

        $notas = collect($this->itens($this->grupos($e), 'rascunhos'))->pluck('nota', 'sku')->all();

        $this->assertSame('Conferido, pronto para publicar', $notas['CONFERIDO']);
        $this->assertSame('Rascunho em preenchimento · faltam 3', $notas['FALTAM-TRES']);
        $this->assertSame('Rascunho em preenchimento', $notas['SEM-BLOQUEIO']);
    }

    /** `rotuloFase()` é a fonte ÚNICA do texto da fase: "Kit 3", nunca um literal novo. */
    public function test_nota_do_kit_acrescenta_o_rotulo_da_fase(): void
    {
        [$e, $c] = $this->conta();
        $base = $this->produto($e, $c, 'CAD');
        $this->rascunho($base, PubRascunho::PUBLISHED);
        $kit = $this->kit($base, 3);

        $notas = collect($this->itens($this->grupos($e), 'sem_publicacao'))->pluck('nota', 'sku')->all();

        $this->assertSame('Cadastro manual no Publicador · Kit 3', $notas[$kit->sku]);
    }

    /** ⚠️ A nota NUNCA afirma o que não foi medido: nada de "parado em Imagens" nem de "—". */
    public function test_nota_nunca_inventa_etapa_nem_usa_travessao_como_informacao(): void
    {
        [$e, $c] = $this->conta();
        $this->rascunho($this->produto($e, $c, 'RASC'), PubRascunho::DRAFT);
        $this->produto($e, $c, 'SEM');
        $this->rascunho($this->produto($e, $c, 'PUB'), PubRascunho::PUBLISHED);

        foreach ($this->grupos($e)['grupos'] as $g) {
            foreach ($g['itens'] as $item) {
                $this->assertNotSame('', $item['nota'], 'nota vazia não é informação');
                $this->assertNotSame('—', $item['nota']);
                $this->assertStringNotContainsStringIgnoringCase('Imagens', $item['nota']);
                $this->assertStringNotContainsStringIgnoringCase('hoje', $item['nota']);
            }
        }
    }

    // ═══ 7. A ordem dentro do grupo é a de produtosParaTela() ════════════════

    /** `produtosParaTela()` já devolve por `atualizado_em` desc; o serviço não reordena. */
    public function test_ordem_dentro_do_grupo_e_a_de_atualizado_em_desc(): void
    {
        [$e, $c] = $this->conta();
        foreach (['ANTIGO' => 10, 'MEIO' => 5, 'NOVO' => 1] as $sku => $dias) {
            $p = $this->produto($e, $c, $sku);
            DB::table('pub_produtos')->where('id', $p->id)->update(['updated_at' => now()->subDays($dias)]);
        }

        $this->assertSame(['NOVO', 'MEIO', 'ANTIGO'], $this->skus($this->grupos($e), 'sem_publicacao'));
    }

    // ═══ 8. O endpoint ═══════════════════════════════════════════════════════

    public function test_endpoint_devolve_os_tres_grupos_com_o_shape_do_contrato(): void
    {
        [$e, $c] = $this->conta();
        $this->rascunho($this->produto($e, $c, 'PRONTO'), PubRascunho::PUBLISHED);

        $r = $this->actingAs($this->admin())->getJson($this->url($e))->assertOk();

        $this->assertSame(['total', 'grupos'], array_keys($r->json()));
        $this->assertSame(self::ORDEM_DOS_GRUPOS, array_column($r->json('grupos'), 'chave'));
        $this->assertSame(1, $r->json('total'));
        $this->assertSame(self::CAMPOS_DO_ITEM, array_keys($r->json('grupos.0.itens.0')));
    }

    public function test_endpoint_devolve_404_para_conta_inexistente(): void
    {
        $this->actingAs($this->admin())
            ->getJson('/mlb/anuncios/publicador/empresas/empresa-999999/nova-publicacao')
            ->assertNotFound();
    }

    public function test_endpoint_recusa_quem_nao_e_admin(): void
    {
        [$e] = $this->conta();

        $this->actingAs(User::factory()->create(['role' => 'consultor']))
            ->getJson($this->url($e))->assertForbidden();
    }

    public function test_endpoint_nunca_devolve_token_de_acesso(): void
    {
        [$e, $c] = $this->conta();
        $this->rascunho($this->produto($e, $c, 'PRONTO'), PubRascunho::PUBLISHED);

        $r = $this->actingAs($this->admin())->getJson($this->url($e))->assertOk();

        $this->assertStringNotContainsString('APP_USR', $r->getContent());
        $this->assertStringNotContainsString('TG-', $r->getContent());
        $corpo = $r->json();
        $this->assertArrayNotHasKey('access_token', $corpo);
        $this->assertArrayNotHasKey('refresh_token', $corpo);
        foreach ($r->json('grupos.0.itens') as $item) {
            $this->assertArrayNotHasKey('access_token', $item);
            $this->assertArrayNotHasKey('refresh_token', $item);
        }
    }

    /**
     * ⚠️ Chave não canônica **não redireciona** aqui: isto é JSON consumido por
     * `axios`, e um 302 viraria resposta estranha no cliente. O endpoint segue
     * com `$alvo['chave']`, que é a canônica — e é ela que aparece na `url` do
     * item de prontos_fase2.
     */
    public function test_endpoint_aceita_chave_nao_canonica_sem_redirecionar(): void
    {
        [$e, $c] = $this->conta();
        $this->rascunho($this->produto($e, $c, 'PRONTO'), PubRascunho::PUBLISHED);

        $r = $this->actingAs($this->admin())
            ->getJson('/mlb/anuncios/publicador/empresas/company-'.$c->id.'/nova-publicacao');

        $r->assertOk();
        $this->assertStringContainsString('/empresas/empresa-'.$e->id.'/produtos/', $r->json('grupos.0.itens.0.url'));
    }

    /**
     * ⚠️ A rota recusa `{conta}` fora do padrão ANTES do controller. O gate NÃO
     * pode ser "deu 404": o `resolver()` também devolve 404 para chave
     * desconhecida, então um teste de status passaria pelo motivo errado mesmo
     * sem o `->where('conta', …)`. O que se cobra aqui é que `resolver()`
     * **nunca seja chamado**.
     */
    public function test_rota_recusa_conta_fora_do_padrao_antes_do_controller(): void
    {
        $this->mock(ProgramasPublicadorService::class, function ($m) {
            $m->shouldReceive('resolver')->never();
        });

        $this->actingAs($this->admin())
            ->getJson('/mlb/anuncios/publicador/empresas/empresa-abc/nova-publicacao')
            ->assertNotFound();
    }

    /** D23: 535 de 539 `MlbEmpresa` não têm Company. O endpoint responde e nada lança. */
    public function test_endpoint_responde_para_conta_sem_company(): void
    {
        [$e] = $this->conta(false, 'Polo sem Company');
        $this->rascunho($this->produto($e, null, 'PRONTO-SEM-COMPANY'), PubRascunho::PUBLISHED);

        $r = $this->actingAs($this->admin())->getJson($this->url($e))->assertOk();

        $this->assertSame(1, $r->json('total'));
        $this->assertSame('PRONTO-SEM-COMPANY', $r->json('grupos.0.itens.0.sku'));
    }

    // ═══ 9. Custo: uma chamada à consulta pesada, e nenhum N+1 ═══════════════

    /**
     * ⚠️ `produtosParaTela()` é a consulta mais pesada do módulo. O serviço a
     * chama **UMA vez** por montagem do payload — cobrado por expectativa, não
     * por contagem de consultas: duas chamadas dobrariam o custo do endpoint
     * mais usado do modal e a contagem sozinha poderia caber no teto.
     */
    public function test_a_consulta_pesada_roda_uma_vez_por_chamada(): void
    {
        [$e, $c] = $this->conta();
        $this->rascunho($this->produto($e, $c, 'PRONTO'), PubRascunho::PUBLISHED);
        $alvo = $this->alvo($e);

        $this->partialMock(ProgramasPublicadorService::class, function ($m) {
            $m->shouldReceive('produtosParaTela')->once()->passthru();
        });

        $saida = app(NovaPublicacaoService::class)->grupos($alvo);

        $this->assertSame(1, $saida['total']);
    }

    /**
     * Detector de N+1 e custo. Medido em 12/10/2026, SQLite: o endpoint faz
     * **18 consultas** com 1 conta, 6 produtos (2 por grupo) e 3 anúncios, e as
     * MESMAS **18** com 12 produtos.
     *
     * ⚠️ Cada conta é medida na SEGUNDA requisição, depois de uma de
     * aquecimento: o cache do `ProgramasPublicadorService` persiste dentro do
     * teste, e sem o aquecimento a primeira conta medida sai com 2 consultas a
     * mais que a segunda — o que faria "12 produtos custam menos que 6" e
     * esconderia a comparação.
     *
     * A prova de que não há consulta por linha é a SEGUNDA asserção: 6 produtos
     * a mais, se houvesse N+1, somariam no MÍNIMO 6 consultas. O teto da
     * primeira asserção é folgado de propósito — o objetivo é pegar N+1, não
     * cravar número bonito.
     */
    public function test_endpoint_nao_consulta_por_produto(): void
    {
        $admin = $this->admin();

        $medir = function (MlbEmpresa $e) use ($admin): int {
            // Aquecimento: tira o cache do serviço da conta da medição.
            $this->actingAs($admin)->getJson($this->url($e))->assertOk();
            DB::enableQueryLog();
            DB::flushQueryLog();
            $this->actingAs($admin)->getJson($this->url($e))->assertOk();
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };

        [$e6, $c6] = $this->conta(true, 'Conta de 6');
        $this->povoar($e6, $c6, 2);
        $seis = $medir($e6);

        [$e12, $c12] = $this->conta(true, 'Conta de 12');
        $this->povoar($e12, $c12, 4);
        $doze = $medir($e12);

        $this->assertLessThan(30, $seis, "o endpoint fez {$seis} consultas com 6 produtos — conferir N+1");
        $this->assertLessThan(
            $seis + 6,
            $doze,
            "6 produtos deram {$seis} consultas e 12 deram {$doze} — os 6 produtos a mais viraram consulta por linha",
        );
    }

    /** `$porGrupo` produtos em cada um dos três grupos, com anúncios nos publicados. */
    private function povoar(MlbEmpresa $e, ?Company $c, int $porGrupo): void
    {
        for ($i = 1; $i <= $porGrupo; $i++) {
            $pub = $this->produto($e, $c, "PUB-{$i}");
            $this->anuncios($this->rascunho($pub, PubRascunho::PUBLISHED), 1);
            $this->produto($e, $c, "SEM-{$i}");
            $this->rascunho($this->produto($e, $c, "RASC-{$i}"), PubRascunho::DRAFT);
        }
    }
}
