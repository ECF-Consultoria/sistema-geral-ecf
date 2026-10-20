<?php

namespace Tests\Feature\Publicador;

use App\Models\Company;
use App\Models\MlAcervoItem;
use App\Models\MlbEmpresa;
use App\Models\MlbImplementacao;
use App\Models\MlToken;
use App\Models\PubProduto;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Fase 177, plano 03 — o CONTRATO da prop `cabecalho` nas 9 páginas que o header
 * global atinge, mais o endpoint do dropdown de empresa.
 *
 * O que este arquivo guarda (e que uma asserção isolada não guardaria):
 *
 * 1. **As 9 páginas mandam `cabecalho`** — uma rota por caso, nenhuma sem caso.
 *    É o que impede uma página ficar para trás e renderizar header sem dado.
 * 2. **`abas` não regrediu** (REG-01). As páginas antigas e os gates de fonte
 *    leem `abas.company_id`; a prop nova é ADITIVA, não substituta.
 * 3. **`null` não é `0`** (HEAD-10). Acervo nunca coletado devolve `null`; acervo
 *    coletado sem nenhum motivo devolve `0`. **Os dois casos juntos** provam a
 *    disciplina — um só não prova nada.
 * 4. **O ERP é só DECLARADO.** A asserção NEGATIVA (`assertArrayNotHasKey`) é o
 *    que impede uma fase futura inventar "ERP conectado": não existe integração
 *    com ERP nenhum neste sistema.
 * 5. **O endpoint do seletor não vaza segredo e não aceita parâmetro.**
 *
 * ⚠️ Nenhum teste aqui chama o Mercado Livre: `Http::fake()` sem handler
 * intercepta tudo (D-05 — estas telas só leem o que já está gravado).
 *
 * ⚠️ Os testes rodam em SQLite; produção é MySQL e o dev local é MariaDB. Este
 * plano **não cria migration** (é tudo leitura), então as armadilhas de
 * enum/índice/FK não se aplicam — não há nada a validar no VPS.
 *
 * @group phase177
 */
class CabecalhoDoPublicadorTest extends TestCase
{
    use RefreshDatabase;

    /** As 4 chaves de topo do contrato — o header depende de todas. */
    private const CHAVES_TOPO = ['aba', 'empresa', 'company_id', 'contadores', 'integracoes'];

    private const URL_HEADER = '/mlb/anuncios/publicador/empresas-header';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Http::fake();
    }

    // ═══ Apoio ═══════════════════════════════════════════════════════════════

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    /**
     * Uma conta de Polos com token ativo. Com Company, o token fica na Company
     * (é o que faz `$company->mlToken` existir e as rotas `/meus|massa|historico`
     * passarem da trava); sem Company (D23) o token fica na MlbEmpresa.
     *
     * @return array{0: MlbEmpresa, 1: ?Company}
     */
    private function conta(bool $comCompany = true, string $nome = 'Polo do Header'): array
    {
        $company = $comCompany ? Company::factory()->create(['name' => $nome]) : null;
        $empresa = MlbEmpresa::create([
            'nome' => $nome,
            'projeto' => 'POLOS',
            'company_id' => $company?->id,
        ]);
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

    private function produto(MlbEmpresa $e, ?Company $c, string $sku = 'SKU-01'): PubProduto
    {
        return PubProduto::create([
            'mlb_empresa_id' => $e->id,
            'company_id' => $c?->id,
            'sku' => $sku,
            'nome' => 'Cadeira',
            'origem' => PubProduto::ORIGEM_PUBLICADOR,
        ]);
    }

    private function anuncio(Company $c, array $overrides = []): MlAcervoItem
    {
        return MlAcervoItem::create(array_merge([
            'company_id' => $c->id,
            'ml_item_id' => 'MLB'.random_int(1000000000, 9999999999),
            'title' => 'Anúncio de teste',
            'status' => 'active',
            'available_quantity' => 10,
            'sold_quantity' => 0,
            'motivos' => [],
            'severidade' => MlAcervoItem::SEVERIDADE_SAUDAVEL,
            'origem' => MlAcervoItem::ORIGEM_LEGADO,
            'coletado_em' => now(),
        ], $overrides));
    }

    /**
     * As props Inertia de uma rota. Mesmo padrão do resto da suíte do Publicador
     * (`viewData('page')['props']`) — prova a mesma coisa que `assertInertia` e
     * mantém a leitura uniforme com os arquivos vizinhos.
     */
    private function props(string $url): array
    {
        $r = $this->actingAs($this->admin())->get($url)->assertOk();

        return $r->viewData('page')['props'];
    }

    /**
     * As 9 páginas que o header atinge, com a aba esperada de cada uma.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    private function paginas(MlbEmpresa $e, ?Company $c): array
    {
        $chave = 'empresa-'.$e->id;
        $base = '/mlb/anuncios/publicador/empresas/'.$chave;
        $produto = $this->produto($e, $c);

        $paginas = [
            'produtos' => [$base, 'produtos'],
            'visao-geral' => [$base.'/visao-geral', 'visao-geral'],
            'configuracoes' => [$base.'/configuracoes', 'configuracoes'],
            'tela do produto' => [$base.'/produtos/'.$produto->id, 'produtos'],
            'publicação em lote' => [$base.'/lote', 'produtos'],
            'alavancas' => [$base.'/alavancas', 'alavancas'],
        ];

        // As 3 páginas do assistente antigo são por `{company}` e exigem token na
        // Company (a trava `abort_unless(mlToken)` de todas elas).
        if ($c !== null) {
            $paginas['em massa'] = ['/mlb/anuncios/massa/'.$c->id, 'produtos'];
            $paginas['histórico'] = ['/mlb/anuncios/historico/'.$c->id, 'publicacoes'];
            $paginas['meus anúncios'] = ['/mlb/anuncios/meus/'.$c->id, 'publicacoes'];
        }

        return $paginas;
    }

    // ═══ 1. As 9 páginas recebem a prop ══════════════════════════════════════

    public function test_as_nove_paginas_do_publicador_recebem_a_prop_cabecalho_com_a_aba_certa(): void
    {
        [$e, $c] = $this->conta();
        $paginas = $this->paginas($e, $c);
        $this->assertCount(9, $paginas, 'o header atinge 9 páginas — nenhuma pode ficar sem caso');

        foreach ($paginas as $rotulo => [$url, $aba]) {
            $cabecalho = $this->props($url)['cabecalho'] ?? null;

            $this->assertIsArray($cabecalho, "a página {$rotulo} não mandou `cabecalho`");
            foreach (self::CHAVES_TOPO as $chave) {
                $this->assertArrayHasKey($chave, $cabecalho, "`cabecalho.{$chave}` falta em {$rotulo}");
            }
            $this->assertSame($aba, $cabecalho['aba'], "a aba de {$rotulo} saiu errada");
            $this->assertSame($c->id, $cabecalho['company_id']);
            $this->assertSame('Polo do Header', $cabecalho['empresa']['nome']);
            $this->assertSame('empresa-'.$e->id, $cabecalho['empresa']['chave']);
        }
    }

    public function test_contadores_e_integracoes_tem_exatamente_as_chaves_do_contrato(): void
    {
        [$e, $c] = $this->conta();
        $this->produto($e, $c);
        $this->anuncio($c);

        $cabecalho = $this->props('/mlb/anuncios/publicador/empresas/empresa-'.$e->id.'/visao-geral')['cabecalho'];

        $this->assertSame(
            ['produtos', 'publicacoes', 'publicacoes_pedem_acao', 'alavancas'],
            array_keys($cabecalho['contadores'])
        );
        $this->assertSame(
            ['mercado_livre', 'erp', 'portal', 'coleta'],
            array_keys($cabecalho['integracoes'])
        );
        // Contadores REAIS (HEAD-10): 1 produto cadastrado, 1 anúncio no acervo.
        $this->assertSame(1, $cabecalho['contadores']['produtos']);
        $this->assertSame(1, $cabecalho['contadores']['publicacoes']);
        $this->assertSame(0, $cabecalho['contadores']['alavancas']);
        $this->assertSame('ativo', $cabecalho['integracoes']['mercado_livre']['estado']);
    }

    /** O escopo do contador é a conta: produto de OUTRA empresa nunca entra (T-177-11). */
    public function test_contador_de_produtos_nao_vaza_produto_de_outra_conta(): void
    {
        [$e, $c] = $this->conta();
        [$outraEmpresa, $outraCompany] = $this->conta(true, 'Outra Conta');
        $this->produto($e, $c, 'MEU-01');
        $this->produto($outraEmpresa, $outraCompany, 'ALHEIO-01');
        $this->produto($outraEmpresa, $outraCompany, 'ALHEIO-02');

        $cabecalho = $this->props('/mlb/anuncios/publicador/empresas/empresa-'.$e->id)['cabecalho'];

        $this->assertSame(1, $cabecalho['contadores']['produtos']);
    }

    // ═══ 2. `abas` não regrediu (REG-01) ═════════════════════════════════════

    public function test_a_prop_abas_continua_intacta_nas_paginas_que_ja_a_tinham(): void
    {
        [$e, $c] = $this->conta();
        $produto = $this->produto($e, $c);
        $base = '/mlb/anuncios/publicador/empresas/empresa-'.$e->id;

        foreach ([$base, $base.'/visao-geral', $base.'/lote'] as $url) {
            $abas = $this->props($url)['abas'] ?? null;

            $this->assertIsArray($abas, "`abas` desapareceu de {$url}");
            $this->assertArrayHasKey('company_id', $abas);
            $this->assertSame($c->id, $abas['company_id']);
            $this->assertArrayHasKey('alavancas_pendentes', $abas);
        }

        // A tela do Produto manda `abas` só com `company_id` — e continua mandando.
        $abas = $this->props($base.'/produtos/'.$produto->id)['abas'];
        $this->assertSame(['company_id' => $c->id], $abas);
    }

    // ═══ 2b. Abas ocultas (20/10/2026) ═══════════════════════════════════════

    /** "Vai ficar apenas Produtos": a lista vem da config, só as três podem sumir, e a página segue pela URL. */
    public function test_abas_ocultas_vem_da_config_e_produtos_nunca_some(): void
    {
        [$e, $c] = $this->conta();
        $this->produto($e, $c);
        $base = '/mlb/anuncios/publicador/empresas/empresa-'.$e->id;

        $this->assertSame(['visao-geral', 'publicacoes', 'alavancas'], $this->props($base)['cabecalho']['abas_ocultas']);
        // A Visão geral continua abrindo pela URL, com a mesma lista (o header mostra a aba da página aberta).
        $this->assertSame(['visao-geral', 'publicacoes', 'alavancas'], $this->props($base.'/visao-geral')['cabecalho']['abas_ocultas']);

        config(['publicador.abas_ocultas' => ['produtos', 'alavancas', 'inventada']]);
        $this->assertSame(['alavancas'], $this->props($base)['cabecalho']['abas_ocultas'], 'Produtos e chave desconhecida nunca somem');

        config(['publicador.abas_ocultas' => []]);
        $this->assertSame([], $this->props($base)['cabecalho']['abas_ocultas'], 'lista vazia devolve as quatro abas');
    }

    // ═══ 3. `null` não é `0` ═════════════════════════════════════════════════

    public function test_acervo_nunca_coletado_devolve_null_e_nunca_zero(): void
    {
        [$e, $c] = $this->conta();

        $cabecalho = $this->props('/mlb/anuncios/publicador/empresas/empresa-'.$e->id.'/visao-geral')['cabecalho'];

        $this->assertNull($cabecalho['contadores']['publicacoes'], 'nunca coletado tem de ser null, não 0');
        $this->assertNull($cabecalho['contadores']['publicacoes_pedem_acao']);
        $this->assertTrue($cabecalho['integracoes']['coleta']['nunca_coletado']);
        $this->assertNull($cabecalho['integracoes']['coleta']['coletado_em']);
    }

    public function test_acervo_coletado_sem_nenhum_motivo_devolve_zero_porque_foi_medido(): void
    {
        [$e, $c] = $this->conta();
        $this->anuncio($c);
        $this->anuncio($c);

        $cabecalho = $this->props('/mlb/anuncios/publicador/empresas/empresa-'.$e->id.'/visao-geral')['cabecalho'];

        $this->assertSame(2, $cabecalho['contadores']['publicacoes']);
        $this->assertSame(0, $cabecalho['contadores']['publicacoes_pedem_acao'], 'medido e é zero — aqui 0 é a verdade');
        $this->assertFalse($cabecalho['integracoes']['coleta']['nunca_coletado']);
        $this->assertNotNull($cabecalho['integracoes']['coleta']['coletado_em']);
    }

    /**
     * "Pedem ação" = `severidade > 0` = tem 1 ou mais motivos de triagem. ⚠️ Não é
     * "com erro": `MlAcervoItem.status` só assume `active|paused|under_review|closed`
     * — não existe status de erro no acervo (divergência declarada de rótulo, FID-02).
     */
    public function test_pedem_acao_conta_anuncio_com_motivo_de_triagem_nao_status_de_erro(): void
    {
        [$e, $c] = $this->conta();
        $this->anuncio($c); // saudável
        $this->anuncio($c, [
            'status' => 'paused',
            'motivos' => [MlAcervoItem::MOTIVO_PAUSADO],
            'severidade' => MlAcervoItem::SEVERIDADE_CRITICA,
        ]);
        $this->anuncio($c, [
            'motivos' => [MlAcervoItem::MOTIVO_FOTO_INSUFICIENTE],
            'severidade' => MlAcervoItem::SEVERIDADE_ATENCAO,
        ]);
        // Encerrado fica FORA do escopo `acionaveis`, mesmo com motivo.
        $this->anuncio($c, [
            'status' => 'closed',
            'motivos' => [MlAcervoItem::MOTIVO_PAUSADO],
            'severidade' => MlAcervoItem::SEVERIDADE_CRITICA,
        ]);

        $cabecalho = $this->props('/mlb/anuncios/publicador/empresas/empresa-'.$e->id.'/visao-geral')['cabecalho'];

        $this->assertSame(4, $cabecalho['contadores']['publicacoes'], 'o total é o acervo inteiro');
        $this->assertSame(2, $cabecalho['contadores']['publicacoes_pedem_acao']);
    }

    // ═══ 4. Conta sem Company (D23) ══════════════════════════════════════════

    public function test_conta_sem_company_nao_mede_publicacoes_e_nao_lanca(): void
    {
        [$e, $c] = $this->conta(comCompany: false, nome: 'Loja Sem Company');
        $this->assertNull($c);

        // `paginas()` já cadastra UM produto nesta conta (é o alvo da tela do Produto).
        foreach ($this->paginas($e, null) as $rotulo => [$url, $aba]) {
            $cabecalho = $this->props($url)['cabecalho'];

            $this->assertNull($cabecalho['company_id'], "company_id deveria ser null em {$rotulo}");
            $this->assertNull($cabecalho['contadores']['publicacoes'], "publicacoes deveria ser null em {$rotulo}");
            $this->assertNull($cabecalho['contadores']['publicacoes_pedem_acao']);
            $this->assertTrue($cabecalho['integracoes']['coleta']['nunca_coletado']);
            // Produto não depende de Company: a âncora é a MlbEmpresa.
            $this->assertSame(1, $cabecalho['contadores']['produtos']);
            $this->assertSame('sem_portal', $cabecalho['integracoes']['portal']['situacao']);
        }
    }

    // ═══ 5. O ERP é SÓ declarado ═════════════════════════════════════════════

    public function test_erp_aparece_so_como_declarado_e_nunca_como_conectado(): void
    {
        [$e, $c] = $this->conta();
        MlbImplementacao::create([
            'empresa_id' => $e->id,
            'token' => (string) Str::uuid(),
            'dados' => ['itens' => ['erp' => ['valor' => 'Bling', 'outro' => '', 'acesso' => '', 'feito' => true]]],
        ]);

        $erp = $this->props('/mlb/anuncios/publicador/empresas/empresa-'.$e->id.'/visao-geral')['cabecalho']['integracoes']['erp'];

        $this->assertSame(['declarado' => 'Bling'], $erp);
        // ⚠️ Asserção NEGATIVA proposital: não existe integração com ERP neste
        // sistema, logo "conectado" não pode nascer no contrato por descuido.
        $this->assertArrayNotHasKey('conectado', $erp);
        $this->assertArrayNotHasKey('sincronizado_em', $erp);
        $this->assertArrayNotHasKey('atualizado_em', $erp);
    }

    public function test_sem_ficha_de_onboarding_o_erp_declarado_e_null(): void
    {
        [$e, $c] = $this->conta();

        $erp = $this->props('/mlb/anuncios/publicador/empresas/empresa-'.$e->id.'/configuracoes')['cabecalho']['integracoes']['erp'];

        $this->assertSame(['declarado' => null], $erp);
    }

    /** O contrato não inventa prazo de renovação: só `ativo|expirado|sem_token`. */
    public function test_integracao_do_ml_expoe_estado_e_nunca_prazo_de_renovacao(): void
    {
        [$e, $c] = $this->conta();
        MlToken::where('company_id', $c->id)->update(['expires_at' => now()->subHour()]);

        $ml = $this->props('/mlb/anuncios/publicador/empresas/empresa-'.$e->id.'/visao-geral')['cabecalho']['integracoes']['mercado_livre'];

        $this->assertSame(['estado', 'conta_nome', 'conta_ml_id', 'link_reconexao'], array_keys($ml));
        $this->assertSame('expirado', $ml['estado']);
        $this->assertArrayNotHasKey('renova_em', $ml);
        $this->assertArrayNotHasKey('expires_at', $ml);
    }

    // ═══ 6. O endpoint do seletor de empresa ═════════════════════════════════

    public function test_endpoint_do_seletor_devolve_total_real_limite_e_as_contas(): void
    {
        $this->conta(true, 'Conta A');
        $this->conta(false, 'Conta B');
        $this->conta(false, 'Conta C');

        $r = $this->actingAs($this->admin())->getJson(self::URL_HEADER)->assertOk();

        $this->assertSame(['total', 'limite', 'empresas'], array_keys($r->json()));
        $this->assertSame(3, $r->json('total'));
        $this->assertSame(200, $r->json('limite'));
        $this->assertCount(3, $r->json('empresas'));
        $this->assertSame(['Conta A', 'Conta B', 'Conta C'], collect($r->json('empresas'))->pluck('nome')->all());
    }

    public function test_endpoint_do_seletor_nunca_devolve_token_de_acesso(): void
    {
        $this->conta(true, 'Conta A');

        $r = $this->actingAs($this->admin())->getJson(self::URL_HEADER)->assertOk();

        $this->assertStringNotContainsString('APP_USR', $r->getContent());
        $this->assertStringNotContainsString('TG-', $r->getContent());
        foreach ($r->json('empresas') as $item) {
            $this->assertSame(
                ['chave', 'nome', 'identificador', 'company_id', 'programa', 'token'],
                array_keys($item)
            );
            $this->assertSame('ativo', $item['token']);
        }
    }

    public function test_endpoint_do_seletor_recusa_quem_nao_e_admin(): void
    {
        $this->conta();

        $this->actingAs(User::factory()->create(['role' => 'consultor']))
            ->getJson(self::URL_HEADER)->assertForbidden();
    }

    // ═══ 7. Custo de consulta — o detector de N+1 ════════════════════════════

    /**
     * ⚠️ O objetivo NÃO é cravar um número bonito: é pegar N+1. Se os contadores ou
     * o `contasLeves()` virarem consulta por linha, o número estoura e este teste
     * acusa. O teto é folgado de propósito (a Visão geral já era a página mais
     * pesada do módulo ANTES desta fase).
     *
     * Medido em 12/10/2026, SQLite, 1 conta com 3 produtos e 3 anúncios: **50 consultas**
     * (teto de 80 para a página não ficar presa a um número exato).
     */
    public function test_a_visao_geral_nao_passou_a_consultar_por_linha(): void
    {
        [$e, $c] = $this->conta();
        foreach (['A', 'B', 'C'] as $i) {
            $this->produto($e, $c, 'SKU-'.$i);
            $this->anuncio($c);
        }
        $admin = $this->admin();

        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->actingAs($admin)->get('/mlb/anuncios/publicador/empresas/empresa-'.$e->id.'/visao-geral')->assertOk();
        $consultas = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThan(80, $consultas, "a Visão geral fez {$consultas} consultas — conferir N+1");
    }

    /**
     * O dropdown tem custo de DROPDOWN: `contasLeves()` não varre produto nem publicação.
     * Medido em 12/10/2026 com 8 contas: **16 consultas** (teto de 40). O número não cresce
     * com a quantidade de contas — é por isso que este teste pega consulta por linha.
     */
    public function test_endpoint_do_seletor_nao_consulta_por_conta(): void
    {
        for ($i = 1; $i <= 8; $i++) {
            [$e, $c] = $this->conta(true, sprintf('Conta %02d', $i));
            $this->produto($e, $c);
        }
        $admin = $this->admin();

        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->actingAs($admin)->getJson(self::URL_HEADER)->assertOk();
        $consultas = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThan(40, $consultas, "o seletor fez {$consultas} consultas para 8 contas — conferir N+1");
    }
}
