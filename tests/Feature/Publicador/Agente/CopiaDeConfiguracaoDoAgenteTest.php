<?php

namespace Tests\Feature\Publicador\Agente;

use App\Models\PubAgenteConfiguracao;
use App\Services\Publicador\Agente\ConfiguracaoDoAgenteService;
use App\Services\Publicador\Agente\CopiaDeConfiguracaoDoAgente;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * COPIAR A CONFIGURAÇÃO DE OUTRA LOJA **COMO RASCUNHO** (E1, 23/10/2026).
 *
 * ⚠️⚠️ **A pergunta respondida antes de cada caso: "o que precisaria estar errado no mundo para ele
 * falhar?"** — *"nada"* é achado.
 *
 * 1. ⚠️ ESCOPO   → **o caso de maior consequência.** `escopo` guarda `pub_produto_id` **da loja de
 *                  origem**. Copiado, o `incluir` restringiria o agente do destino a um conjunto
 *                  **vazio** — e ele pareceria quebrado **sem erro nenhum**. É o caso literal de
 *                  *"não copia IDs de produtos/famílias inválidos"* (§7.1).
 * 2. DESLIGADA   → *"regras copiadas começam inativas"*. Mesmo copiando de uma loja LIGADA, o
 *                  rascunho vem `habilitado = false`.
 * 3. CONTROLE    → herdar "pausado" e o motivo escrito sobre OUTRA loja: seria atribuir a alguém
 *                  uma frase que não escreveu, e deixar o destino pausado sem razão própria.
 * 4. ⚠️ NÃO GRAVA → "copiar" que escreve mudaria uma loja em produção com UM clique e sem
 *                  conferência. O caso compara a configuração do destino **antes e depois**, e
 *                  conta as versões: nada muda.
 * 5. ORIGEM      → chave forjada na URL lendo a configuração de uma conta qualquer. A origem é
 *                  RESOLVIDA no servidor; forjada, inexistente, órfã ou a própria → `null`.
 * 6. FORMATO     → `HH:MM:SS` do banco num `<input type="time">` não casa o valor; e dia fora de
 *                  1..7 na origem não pode viajar.
 * 7. OMISSÃO     → **a armadilha estrutural.** A lista é de INCLUSÃO (`COPIAVEIS`), nunca de
 *                  exclusão: com exclusão, **coluna nova entraria na cópia por omissão**. O caso
 *                  cobra que nenhum nome proibido esteja em `COPIAVEIS`.
 */
class CopiaDeConfiguracaoDoAgenteTest extends TestCase
{
    use CenarioDoAgente;
    use RefreshDatabase;

    /**
     * ⚠️ Os nomes que NUNCA podem viajar. Lista CONSTANTE — e cada um tem o motivo no docblock do
     * serviço. Nome novo proibido entra aqui à mão, o que torna a decisão visível no diff.
     */
    private const PROIBIDOS = [
        'escopo', 'controle', 'motivo_controle', 'versao', 'conta_chave',
        'company_id', 'mlb_empresa_id', 'atualizado_por', 'created_at', 'updated_at', 'id',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->montarAgente();
    }

    private function copia(): CopiaDeConfiguracaoDoAgente
    {
        return app(CopiaDeConfiguracaoDoAgente::class);
    }

    /**
     * Uma loja de ORIGEM, com configuração rica salva à mão.
     *
     * ⚠️ Gravada por `DB::table`, de propósito: eu quero uma origem com `escopo` povoado e
     * `controle` pausado **sem** depender do caminho de gravação do serviço, que é o que está
     * sendo medido do outro lado.
     */
    private function origemRica(string $chave = 'company-99991'): string
    {
        DB::table('pub_agente_configuracoes')->insert([
            'conta_chave' => $chave,
            'company_id' => $this->empresa->id,
            'habilitado' => true,
            'controle' => PubAgenteConfiguracao::PAUSADO,
            'motivo_controle' => 'Conferindo as fotos da outra loja.',
            'versao' => 7,
            'modalidade' => 'gold_pro',
            'estoque_minimo' => 4,
            'limite_diario' => 9,
            'teto_fila' => 21,
            'janela_inicio' => '08:30:00',
            'janela_fim' => '19:45:00',
            'dias_semana' => json_encode([2, 4, 6, 0, 9]),
            'escopo' => json_encode(['incluir' => [101, 102], 'excluir' => [103]]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $chave;
    }

    /** O rascunho a partir da origem rica, resolvendo a conta de origem de verdade. */
    private function rascunhoDaOrigemRica(): array
    {
        // ⚠️ A origem tem de ser uma conta RESOLVÍVEL — o serviço recusa chave que o
        // `ProgramasPublicadorService` não resolve, e é isso que impede chave forjada na URL.
        // Então uso a PRÓPRIA empresa do cenário como origem, e o destino é outra chave.
        $chave = "company-{$this->empresa->id}";
        DB::table('pub_agente_configuracoes')->where('conta_chave', $chave)->delete();
        $this->origemRica($chave);

        $destino = [...$this->conta(), 'chave' => 'company-99999'];
        $r = $this->copia()->rascunho($destino, $chave);
        $this->assertNotNull($r, 'o rascunho veio nulo — o cenário não montou a origem resolvível');

        return $r;
    }

    public function test_o_escopo_de_produtos_NAO_viaja_na_copia_e1(): void
    {
        // ⚠️⚠️ O CASO DE MAIOR CONSEQUÊNCIA. Com o `escopo` copiado, o `incluir` [101, 102]
        // apontaria para produtos da OUTRA loja: o agente do destino ficaria restrito a um
        // conjunto VAZIO e pareceria quebrado sem erro nenhum.
        $r = $this->rascunhoDaOrigemRica();

        $this->assertArrayNotHasKey('escopo', $r['valores'], '⚠️⚠️ o `escopo` viajou na cópia — os ids são da outra loja e o agente do destino ficaria restrito a um conjunto VAZIO');

        // ⚠️ E a ISCA: a origem REALMENTE tinha escopo povoado. Sem isto, a ausência acima passaria
        // porque a origem não tinha nada — que é o modo clássico de este caso não medir nada.
        $daOrigem = PubAgenteConfiguracao::query()->where('conta_chave', $r['origem']['chave'])->firstOrFail();
        $this->assertSame(['incluir' => [101, 102], 'excluir' => [103]], $daOrigem->escopo, 'a origem não tinha escopo povoado — a ausência acima não provaria nada');

        // E a tela é AVISADA de que o escopo ficou de fora.
        $this->assertNotEmpty($r['nao_copiados'], 'a tela não é avisada do que ficou de fora');
        $this->assertStringContainsString('escopo', implode(' | ', $r['nao_copiados']), 'o aviso não menciona o escopo de produtos');
    }

    public function test_a_copia_nasce_desligada_mesmo_vindo_de_loja_ligada_e1(): void
    {
        $r = $this->rascunhoDaOrigemRica();

        // ⚠️ *"Regras copiadas começam inativas"* (§7.1). Mesmo que alguém salve sem ler, o agente
        // não liga sozinho na loja de destino.
        $this->assertFalse($r['valores']['habilitado'], 'a cópia nasceu LIGADA — ela tem de começar inativa');

        // ⚠️ ISCA: a origem estava LIGADA. Sem isto, `false` poderia ser só o eco da origem.
        $daOrigem = PubAgenteConfiguracao::query()->where('conta_chave', $r['origem']['chave'])->firstOrFail();
        $this->assertTrue((bool) $daOrigem->habilitado, 'a origem não estava ligada — o `false` acima não prova forçamento');

        // E o forçamento está declarado por nome, para um gate poder cobrá-lo.
        $this->assertSame(['habilitado' => false], CopiaDeConfiguracaoDoAgente::FORCADOS, 'a lista de forçados mudou de forma');
    }

    public function test_pausa_e_motivo_da_outra_loja_nao_viajam_e1(): void
    {
        $r = $this->rascunhoDaOrigemRica();

        // ⚠️ Herdar "pausado" deixaria o destino parado sem razão própria; herdar o MOTIVO seria
        // atribuir a alguém uma frase escrita sobre outra loja.
        foreach (['controle', 'motivo_controle', 'versao'] as $chave) {
            $this->assertArrayNotHasKey($chave, $r['valores'], "`{$chave}` viajou na cópia");
        }

        // ⚠️ ISCA: a origem tinha os três povoados.
        $daOrigem = PubAgenteConfiguracao::query()->where('conta_chave', $r['origem']['chave'])->firstOrFail();
        $this->assertSame(PubAgenteConfiguracao::PAUSADO, $daOrigem->controle, 'a origem não estava pausada');
        $this->assertNotNull($daOrigem->motivo_controle, 'a origem não tinha motivo de pausa');
        $this->assertSame(7, (int) $daOrigem->versao, 'a origem não tinha versão 7');

        $this->assertStringContainsString('pausa', implode(' | ', $r['nao_copiados']), 'o aviso não menciona a pausa');
    }

    public function test_copiar_NAO_grava_nada_no_destino_e1(): void
    {
        // ⚠️⚠️ "Copiar" que escreve mudaria uma loja em produção com UM clique e sem conferência.
        $this->ligar(['estoque_minimo' => 2]);
        $destino = $this->conta();
        $antes = PubAgenteConfiguracao::query()->where('conta_chave', $destino['chave'])->firstOrFail()->toArray();
        $versoesAntes = DB::table('pub_agente_configuracao_versoes')->count();

        $origem = $this->origemRica();
        $this->copia()->rascunho($destino, $origem);
        // E também o caminho em que a origem não resolve — ele não pode gravar nem por engano.
        $this->copia()->rascunho($destino, 'company-77777');

        $depois = PubAgenteConfiguracao::query()->where('conta_chave', $destino['chave'])->firstOrFail()->toArray();
        $this->assertSame($antes, $depois, '⚠️⚠️ copiar GRAVOU no destino — ele é RASCUNHO e não escreve nada');
        $this->assertSame($versoesAntes, DB::table('pub_agente_configuracao_versoes')->count(), 'copiar criou versão nova — ele não grava');

        // ⚠️ ISCA: a comparação não é entre dois vazios — o destino tem configuração de verdade.
        $this->assertSame(2, (int) $antes['estoque_minimo'], 'o destino não tinha configuração — a comparação acima seria entre dois nadas');
    }

    public function test_origem_forjada_inexistente_ou_a_propria_devolve_nulo_e1(): void
    {
        $destino = $this->conta();

        // ⚠️ A chave vem da QUERYSTRING, que é do cliente. Chave que o resolvedor não reconhece não
        // pode fazer o servidor ler a configuração de uma conta qualquer.
        foreach ([null, '', '   ', 'company-abc', 'empresa-', '../../etc/passwd', 'company-99999999'] as $forjada) {
            $this->assertNull($this->copia()->rascunho($destino, $forjada), 'uma origem forjada devolveu rascunho');
        }

        // Conta resolvível mas SEM configuração salva: não há o que copiar.
        $this->assertNull($this->copia()->rascunho($destino, $destino['chave']), 'copiar da PRÓPRIA conta devolveu rascunho — é pedido sem efeito');

        // ⚠️ ISCA: o caminho feliz funciona. Sem ela, um `rascunho()` que devolvesse `null` sempre
        // faria todas as asserções acima passarem por engano.
        $this->assertNotNull($this->rascunhoDaOrigemRica(), 'o caminho feliz também devolve nulo — as asserções acima passariam por engano');
    }

    public function test_so_os_compativeis_viajam_e_no_formato_da_tela_e1(): void
    {
        $r = $this->rascunhoDaOrigemRica();
        $chaves = array_keys($r['valores']);
        sort($chaves);

        $esperadas = [...CopiaDeConfiguracaoDoAgente::COPIAVEIS, ...array_keys(CopiaDeConfiguracaoDoAgente::FORCADOS)];
        sort($esperadas);
        $this->assertSame($esperadas, $chaves, 'o rascunho deixou de ser EXATAMENTE os copiáveis mais os forçados');

        // Os valores da origem chegaram.
        $this->assertSame('gold_pro', $r['valores']['modalidade'], 'a modalidade não veio');
        $this->assertSame(4, (int) $r['valores']['estoque_minimo'], 'o estoque mínimo não veio');
        $this->assertSame(9, (int) $r['valores']['limite_diario'], 'o limite diário não veio');
        $this->assertSame(21, (int) $r['valores']['teto_fila'], 'o teto da fila não veio');

        // ⚠️ `HH:MM`, não `HH:MM:SS`: um `<input type="time">` com segundos não casa o valor e o
        // campo aparece VAZIO — a pessoa acharia que a janela não veio.
        $this->assertSame('08:30', $r['valores']['janela_inicio'], 'a janela não saiu no formato da tela');
        $this->assertSame('19:45', $r['valores']['janela_fim'], 'a janela não saiu no formato da tela');

        // ⚠️ O calendário saneado: a origem tinha `[2, 4, 6, 0, 9]` e o `0` e o `9` não viajam.
        $this->assertSame([2, 4, 6], $r['valores']['dias_semana'], 'dia fora de 1..7 viajou da origem para o rascunho');
    }

    public function test_nenhum_nome_proibido_esta_na_lista_de_copiaveis_e1(): void
    {
        // ⚠️⚠️ A ARMADILHA ESTRUTURAL. A lista é de INCLUSÃO: com uma lista de EXCLUSÃO, **coluna
        // nova entraria na cópia por omissão**, e a primeira coluna que não pudesse ser copiada
        // vazaria em silêncio. Este caso guarda a inclusão.
        $vazados = array_values(array_intersect(self::PROIBIDOS, CopiaDeConfiguracaoDoAgente::COPIAVEIS));
        $this->assertSame([], $vazados, 'nome proibido entrou em `COPIAVEIS`: '.implode(', ', $vazados));

        // ⚠️ ISCA: a sonda acha quando o nome ESTÁ lá. Sem isto, um `array_intersect` sobre lista
        // errada daria `[]` para sempre.
        $this->assertSame(['escopo'], array_values(array_intersect(self::PROIBIDOS, ['modalidade', 'escopo'])), 'a sonda de nome proibido está CEGA');

        // ⚠️ E todo copiável existe de verdade como coluna: nome inventado em `COPIAVEIS` leria
        // `null` em silêncio e a cópia perderia o campo sem avisar.
        $cfg = new PubAgenteConfiguracao();
        foreach (CopiaDeConfiguracaoDoAgente::COPIAVEIS as $chave) {
            $this->assertTrue(
                \Illuminate\Support\Facades\Schema::hasColumn($cfg->getTable(), $chave),
                "`{$chave}` está em COPIAVEIS e não existe como coluna — a cópia perderia o campo em silêncio",
            );
        }
    }

    public function test_o_seletor_de_origens_nao_lista_a_propria_nem_configuracao_orfa_e1(): void
    {
        $this->ligar();
        $destino = $this->conta();

        // Uma configuração ÓRFÃ: a chave não resolve para loja nenhuma.
        DB::table('pub_agente_configuracoes')->insert([
            'conta_chave' => 'company-88888', 'habilitado' => false, 'controle' => PubAgenteConfiguracao::ATIVO,
            'versao' => 1, 'estoque_minimo' => 1, 'limite_diario' => 5, 'teto_fila' => 10,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $chaves = array_column($this->copia()->origensPossiveis($destino), 'chave');

        $this->assertNotContains($destino['chave'], $chaves, 'o seletor lista a PRÓPRIA loja — copiar de si mesma é pedido sem efeito');
        $this->assertNotContains('company-88888', $chaves, 'o seletor lista configuração ÓRFÃ como se fosse uma loja viva');

        // ⚠️ E o seletor devolve só IDENTIDADE: mandar os valores de todas as lojas seria carregar
        // mais dado do que a pessoa pediu.
        foreach ($this->copia()->origensPossiveis($destino) as $o) {
            $this->assertSame(['chave', 'nome'], array_keys($o), 'o seletor passou a mandar mais que identidade');
        }
    }
}
