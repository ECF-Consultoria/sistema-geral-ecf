<?php

namespace Tests\Feature\Publicador\Agente;

use App\Models\PubAgenteConfiguracao;
use App\Models\PubAgenteExecucao;
use App\Services\Publicador\Agente\ConfiguracaoDoAgenteService;
use App\Services\Publicador\Agente\LeituraDoAgente;
use App\Support\Publicador\RegraViolada;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * O RITMO do agente (E1, 26/10/2026) — `cooldown_minutos` e `tentativas_max`, por loja.
 *
 * ⚠️⚠️ **A LACUNA QUE ISTO FECHOU, MEDIDA ANTES DE ESCREVER:** `VarreduraDoAgente::candidatos()`
 * já tinha um cooldown **de fato** de 24 h — mas **só para `BLOQUEADA`**. **`FALHOU` não era
 * excluído por nada**, então um produto cuja preparação falhou podia ser repescado na **passada
 * seguinte** do agendador, e cada passada gasta chamada de IA **paga**. Era um laço de gasto.
 *
 * ⚠️ De cada caso, a pergunta respondida antes dele existir:
 *
 * 1. NADA MUDA  → as duas colunas nascem `null`. Se `null` não resolvesse para o que já valia
 *                 (1440 min e 2 tentativas), o deploy mudaria o ritmo de **todas** as lojas.
 * 2. ⚠️ ZERO    → `0` cair no padrão por causa de `?:`/`??` sobre *falsy*. Zero é **válido** e
 *                 significa "sem espera" / "não repita" — o OPOSTO do padrão de 24 h.
 * 3. ⚠️ FALHOU  → o laço de gasto: produto que falhou sendo repescado na passada seguinte.
 * 4. CANCELADA  → cooldown no cancelamento faria o sistema recusar o próprio gesto da pessoa.
 * 5. UMA FONTE  → o padrão existir em dois lugares e as cópias divergirem. **É o gate que eu
 *                 prometi no comentário de `PreparadorDaAprovacao`** — comentário que promete
 *                 gate e não tem é pior que comentário nenhum.
 * 6. VALIDAÇÃO  → valor absurdo (um ano de espera, 50 tentativas pagas) entrando no banco.
 * 7. TELA       → o campo vazio virar o padrão escrito, fixando na loja um número que ela nunca
 *                 escolheu e fazendo-a parar de acompanhar o padrão.
 */
class RitmoDoAgenteTest extends TestCase
{
    use CenarioDoAgente;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->montarAgente();
    }

    private function cfgSalva(): PubAgenteConfiguracao
    {
        return app(ConfiguracaoDoAgenteService::class)->daConta($this->conta());
    }

    /** Uma execução terminada no estado e no instante pedidos. */
    private function terminada(string $estado, int $produtoId, Carbon $quando): PubAgenteExecucao
    {
        $n = PubAgenteExecucao::query()->count() + 1;

        return PubAgenteExecucao::create([
            'uuid' => sprintf('%08d-0000-4000-8000-%012d', $n, $n),
            'conta_chave' => $this->conta()['chave'],
            'produto_id' => $produtoId,
            'origem' => PubAgenteExecucao::ORIGEM_AUTOMATICA,
            'configuracao_versao' => 1,
            'chave_logica' => str_pad((string) $n, 64, 'r'),
            'estado' => $estado,
            'iniciada_em' => $quando,
            'concluida_em' => $quando,
        ]);
    }

    /** Os produtos que a varredura aceitaria agora. */
    private function candidatos(): array
    {
        $resumo = app(\App\Services\Publicador\Agente\VarreduraDoAgente::class)->rodar();

        return $resumo;
    }

    public function test_coluna_nula_resolve_para_o_que_ja_valia_ritmo_e1(): void
    {
        // ⚠️⚠️ O CASO DE MAIOR CONSEQUÊNCIA. As duas colunas nascem `null` em toda loja existente.
        // Se `null` não resolvesse para o que já valia, o deploy mudaria o ritmo de TODAS elas.
        $this->ligar();
        $cfg = $this->cfgSalva();

        $this->assertNull($cfg->cooldown_minutos, 'a coluna de cooldown não nasce nula');
        $this->assertNull($cfg->tentativas_max, 'a coluna de tentativas não nasce nula');

        // ⚠️ 1440 min = as 24 h que `VarreduraDoAgente` já aplicava a `BLOQUEADA`.
        $this->assertSame(1440, $cfg->cooldownEfetivo(), 'o cooldown padrão deixou de ser as 24 h de antes');
        $this->assertSame(1440, PubAgenteConfiguracao::COOLDOWN_PADRAO_MINUTOS, 'a constante do padrão mudou de valor');
        // ⚠️ 2 = a antiga `MAX_TENTATIVAS`.
        $this->assertSame(2, $cfg->tentativasEfetivas(), 'o padrão de tentativas deixou de ser 2');
        $this->assertSame(2, PubAgenteConfiguracao::TENTATIVAS_PADRAO, 'a constante do padrão mudou de valor');
    }

    public function test_zero_e_valido_e_NAO_cai_no_padrao_ritmo_e1(): void
    {
        // ⚠️⚠️ A ARMADILHA DO FALSY. Com `?:` ou `??` sobre *falsy*, um `0` cairia no padrão de
        // 24 h — e a loja receberia o OPOSTO do que pediu.
        $this->ligar(['cooldown_minutos' => 0, 'tentativas_max' => 0]);
        $cfg = $this->cfgSalva();

        $this->assertSame(0, $cfg->cooldown_minutos, 'o zero não foi gravado');
        $this->assertSame(0, $cfg->cooldownEfetivo(), '⚠️ o cooldown ZERO caiu no padrão de 24 h — é o oposto do pedido');
        $this->assertSame(0, $cfg->tentativasEfetivas(), '⚠️ tentativas ZERO caiu no padrão');

        // ⚠️ ISCA: a resolução não devolve 0 para tudo — com `null` ela devolve o padrão.
        app(ConfiguracaoDoAgenteService::class)->salvar($this->conta(), [
            'habilitado' => true, 'cooldown_minutos' => null, 'tentativas_max' => null,
        ], (int) $cfg->versao, $this->admin);
        $this->assertSame(1440, $this->cfgSalva()->cooldownEfetivo(), 'a resolução devolve 0 sempre — as asserções acima não provam nada');
    }

    public function test_produto_que_FALHOU_respeita_o_cooldown_ritmo_e1(): void
    {
        // ⚠️⚠️ O LAÇO DE GASTO que esta fatia fechou: antes `FALHOU` não era excluído por nada.
        $this->ligar(['cooldown_minutos' => 120]);
        $pub = $this->noPublicador();

        // Falhou há 10 minutos: dentro do cooldown de 120 → NÃO deve ser repescado.
        $this->terminada(PubAgenteExecucao::FALHOU, (int) $pub->id, now()->subMinutes(10));

        $resumo = $this->candidatos();
        $this->assertSame(0, $resumo['pedidos'], '⚠️⚠️ o produto que FALHOU há 10 min foi repescado com cooldown de 120 min — é o laço de gasto de volta');

        // ⚠️ ISCA, e ela é o que prova que o cooldown é um RECORTE e não um "nunca": movendo o
        // relógio para além da espera, o MESMO produto volta a ser candidato.
        Carbon::setTestNow(now()->addMinutes(121));
        try {
            $this->assertGreaterThanOrEqual(1, $this->candidatos()['pedidos'], 'passado o cooldown, o produto NÃO voltou — o cooldown virou exclusão permanente');
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_cancelada_NAO_entra_no_cooldown_ritmo_e1(): void
    {
        // ⚠️ Cancelar não é falhar. Quem cancela pode querer pedir de novo em seguida — pôr
        // cooldown ali faria o sistema recusar o próprio gesto da pessoa, sem explicação.
        $this->ligar(['cooldown_minutos' => 10080]); // 7 dias, o máximo
        $pub = $this->noPublicador();
        $this->terminada(PubAgenteExecucao::CANCELADA, (int) $pub->id, now()->subMinute());

        $this->assertGreaterThanOrEqual(1, $this->candidatos()['pedidos'], 'o cancelamento entrou no cooldown — ele não é uma falha');
    }

    public function test_o_padrao_de_tentativas_tem_UMA_fonte_so_ritmo_e1(): void
    {
        // ⚠️⚠️ **ESTE É O GATE QUE EU PROMETI NO COMENTÁRIO** de `PreparadorDaAprovacao`.
        // Comentário que promete gate e não tem é pior que comentário nenhum.
        //
        // O padrão era a constante `MAX_TENTATIVAS` daquela classe; ela FOI REMOVIDA para o número
        // não existir em dois lugares. Se alguém declarar um máximo próprio de novo, as duas
        // cópias divergem em silêncio: o padrão muda num lugar e o outro segue valendo.
        $DIR = base_path('app/Services/Publicador/Agente');
        $arquivos = glob($DIR.'/*.php') ?: [];
        $this->assertGreaterThanOrEqual(9, count($arquivos), 'o recorte do agente veio curto — a ausência abaixo passaria por lista vazia');

        $infratores = [];
        foreach ($arquivos as $abs) {
            // ⚠️ Flag DENTRO do laço. E leitura SEM COMENTÁRIO: os docblocks EXPLICAM que a
            // constante saiu, e citam o nome dela — ler com comentário faria o gate reprovar pelo
            // próprio conserto, que é a armadilha mais frequente deste projeto.
            $codigo = $this->semComentarios(file_get_contents($abs));
            if (preg_match('/const\s+(MAX_TENTATIVAS|TENTATIVAS_MAX|LIMITE_TENTATIVAS)\b/', $codigo) === 1) {
                $infratores[] = basename($abs);
            }
        }

        $this->assertSame([], $infratores, 'uma classe do agente voltou a declarar um máximo de tentativas próprio — o padrão tem UMA fonte, `PubAgenteConfiguracao::TENTATIVAS_PADRAO`: '.implode(', ', $infratores));

        // ⚠️ ISCA: a regex acha a declaração quando ela existe.
        $this->assertSame(1, preg_match('/const\s+(MAX_TENTATIVAS|TENTATIVAS_MAX|LIMITE_TENTATIVAS)\b/', 'public const MAX_TENTATIVAS = 2;'), 'a regex da constante está CEGA');
        // ⚠️ E a leitura sem comentário realmente tira comentário: a frase abaixo cita o nome e
        // NÃO pode ser achada.
        $this->assertSame(0, preg_match('/const\s+MAX_TENTATIVAS\b/', $this->semComentarios("<?php\n// const MAX_TENTATIVAS = 9;\nclass X {}\n")), 'o leitor sem comentário não tirou o comentário');
    }

    public function test_valor_absurdo_de_ritmo_e_recusado_ritmo_e1(): void
    {
        $this->ligar();
        $versao = (int) $this->cfgSalva()->versao;

        foreach ([
            ['cooldown_minutos' => 10081],   // mais de 7 dias não é ritmo, é desligar
            ['cooldown_minutos' => -1],
            ['tentativas_max' => 6],         // cada tentativa gasta IA paga
            ['tentativas_max' => -1],
            ['cooldown_minutos' => 'muito'],
        ] as $invalido) {
            try {
                app(ConfiguracaoDoAgenteService::class)
                    ->salvar($this->conta(), ['habilitado' => true, ...$invalido], $versao, $this->admin);
                $this->fail('valor absurdo de ritmo foi aceito: '.json_encode($invalido));
            } catch (ValidationException|RegraViolada $e) {
                $this->assertTrue(true);
            }
        }

        // ⚠️ ISCA: os extremos VÁLIDOS passam. Sem isto, uma validação que recusasse tudo faria
        // todas as asserções acima passarem por engano.
        app(ConfiguracaoDoAgenteService::class)->salvar($this->conta(), [
            'habilitado' => true, 'cooldown_minutos' => 10080, 'tentativas_max' => 5,
        ], $versao, $this->admin);
        $this->assertSame(10080, $this->cfgSalva()->cooldown_minutos, 'o máximo válido foi recusado');
    }

    public function test_a_tela_recebe_o_ritmo_CRU_e_o_EFETIVO_ritmo_e1(): void
    {
        // ⚠️⚠️ A tela precisa dos DOIS: o campo mostra o cru (vazio = padrão) e a frase de apoio
        // mostra o efetivo. Mandar só o resolvido faria o campo vazio virar "1440" ao salvar,
        // FIXANDO na loja um número que ela nunca escolheu — e ela deixaria de acompanhar o padrão.
        $this->ligar();
        $c = app(LeituraDoAgente::class)->paraConta($this->conta())['configuracao'];

        $this->assertNull($c['cooldown_minutos'], 'o cru deveria vir nulo (= use o padrão)');
        $this->assertSame(1440, $c['cooldown_efetivo'], 'o efetivo não veio para a frase de apoio');
        $this->assertNull($c['tentativas_max'], 'o cru de tentativas deveria vir nulo');
        $this->assertSame(2, $c['tentativas_efetivas'], 'o efetivo de tentativas não veio');

        // Com valor escolhido, o cru é o escolhido e o efetivo o acompanha.
        app(ConfiguracaoDoAgenteService::class)->salvar($this->conta(), [
            'habilitado' => true, 'cooldown_minutos' => 30, 'tentativas_max' => 1,
        ], (int) $this->cfgSalva()->versao, $this->admin);
        $d = app(LeituraDoAgente::class)->paraConta($this->conta())['configuracao'];

        $this->assertSame(30, $d['cooldown_minutos'], 'o cru não acompanhou a escolha');
        $this->assertSame(30, $d['cooldown_efetivo'], 'o efetivo não acompanhou a escolha');
        $this->assertSame(1, $d['tentativas_efetivas'], 'o efetivo de tentativas não acompanhou');
    }

    public function test_o_ritmo_entra_no_snapshot_da_versao_ritmo_e1(): void
    {
        // ⚠️ Sem isto, ninguém explicaria depois por que um produto não foi repescado, nem quantas
        // tentativas valiam quando aquela execução rodou.
        foreach (['cooldown_minutos', 'tentativas_max'] as $campo) {
            $this->assertContains($campo, PubAgenteConfiguracao::CHAVES_DA_VERSAO, "`{$campo}` ficou fora das chaves da versão");
        }

        $this->ligar(['cooldown_minutos' => 45, 'tentativas_max' => 3]);
        $dados = (array) $this->cfgSalva()->versoes()->orderByDesc('versao')->first()->dados;

        $this->assertSame(45, $dados['cooldown_minutos'], 'o snapshot não guardou o cooldown');
        $this->assertSame(3, $dados['tentativas_max'], 'o snapshot não guardou as tentativas');
    }

    public function test_no_diff_o_nulo_do_ritmo_le_PADRAO_e_o_zero_le_SEM_ESPERA_ritmo_e1(): void
    {
        // ⚠️⚠️ **A DISTINÇÃO QUE NÃO PODE SE PERDER NO TEXTO.** Nestes dois campos `null` significa
        // *"use o padrão"* e `0` significa *"sem espera" / "não repita"*. Se `null` caísse no `'sem'`
        // genérico, o diff leria *"Espera: sem → 30 min"* onde a verdade era *"padrão (1440 min) →
        // 30 min"* — e a pessoa concluiria que o agente **não esperava nada** antes. O contrário.
        $this->ligar(); // v1 com as duas colunas NULAS
        $cfg = $this->cfgSalva();

        app(ConfiguracaoDoAgenteService::class)->salvar($this->conta(), [
            'habilitado' => true, 'cooldown_minutos' => 30, 'tentativas_max' => 0,
        ], (int) $cfg->versao, $this->admin);

        $atividade = app(\App\Services\Publicador\Agente\AtividadeDoAgente::class)
            ->paraConta($this->conta(), ['fonte' => 'configuracao']);
        $linhas = [];
        foreach ($atividade['dias'] as $d) {
            foreach ($d['linhas'] as $l) {
                $linhas[] = $l;
            }
        }
        $porCampo = collect($linhas[0]['mudancas'])->keyBy('campo');

        $this->assertArrayHasKey('cooldown_minutos', $porCampo, 'a mudança de cooldown não apareceu no diff');
        $this->assertSame('padrão (1440 min)', $porCampo['cooldown_minutos']['de'], '`null` no cooldown NÃO leu "padrão" — a pessoa concluiria que não havia espera');
        $this->assertSame('30 min', $porCampo['cooldown_minutos']['para'], 'o novo cooldown não saiu com a unidade');

        $this->assertSame('padrão (2)', $porCampo['tentativas_max']['de'], '`null` nas tentativas NÃO leu "padrão"');
        // ⚠️ E o ZERO lê "nenhuma", nunca "padrão" — são estados diferentes.
        $this->assertSame('nenhuma', $porCampo['tentativas_max']['para'], 'o ZERO leu como padrão — é o oposto do que a loja pediu');

        // ⚠️ E nenhum dos dois cai no `'sem'` genérico.
        foreach (['cooldown_minutos', 'tentativas_max'] as $campo) {
            $this->assertNotSame('sem', $porCampo[$campo]['de'], "o campo `{$campo}` caiu no 'sem' genérico");
        }
    }

    /** O CÓDIGO sem os comentários, pelo tokenizador do PHP. */
    private function semComentarios(string $texto): string
    {
        $saida = '';
        foreach (token_get_all($texto) as $token) {
            if (! is_array($token)) {
                $saida .= $token;

                continue;
            }
            if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                $saida .= str_repeat("\n", substr_count($token[1], "\n"));

                continue;
            }
            $saida .= $token[1];
        }

        return $saida;
    }
}
