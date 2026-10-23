<?php

namespace Tests\Feature\Publicador\Agente;

use App\Models\PubAgenteConfiguracao;
use App\Models\PubAgenteExecucao;
use App\Services\Publicador\Agente\ConfiguracaoDoAgenteService;
use App\Services\Publicador\Agente\LeituraDoAgente;
use App\Services\Publicador\Agente\PoliticaDoAgente;
use App\Support\Publicador\RegraViolada;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * O CALENDÁRIO da janela de preparo (E1, 23/10/2026) — `dias_semana`, em ISO (1 = segunda … 7 = domingo).
 *
 * ⚠️⚠️ **A pergunta respondida antes de cada caso: "o que precisaria estar errado no mundo para ele
 * falhar?"** — *"nada"* é achado, não aprovação.
 *
 *  1. NADA MUDA   → a coluna nasce `null` em TODA loja que já existe. Se `null` não valesse "todo dia",
 *                   o deploy **pararia o agente de todas elas em silêncio**. É o caso mais importante.
 *  2. DESMARCAR   → `[]` virar "nenhum dia" travaria o agente sem avisar, e seria lido como defeito.
 *                   Ele é normalizado para `null` e vale todo dia; a tela diz isso por extenso.
 *  3. RECORTE     → o dia marcado valer e o não-marcado não. O óbvio, e sem ele nada disso serve.
 *  4. ⚠️ MEIA-NOITE → **a decisão delicada.** Janela 22:00–02:00 com segunda marcada: a madrugada conta
 *                   como o dia em que a janela ABRIU. Avaliar pelo relógio faria a janela parar à
 *                   meia-noite sem ninguém pedir — duas horas que a loja escolheu virariam zero.
 *                   O caso cobra as DUAS pontas: terça 01:00 dentro, segunda 01:00 fora.
 *  5. ⚠️ DOMINGO  → ISO existe por isto: com 0-based, domingo seria `0`, que é *falsy*, e desapareceria
 *                   em `array_filter`/`.filter(Boolean)`. O caso prova que domingo marcado SOBREVIVE.
 *  6. LIXO        → valor fora de 1..7 no banco (dado antigo, escrita por fora) travando o agente.
 *                   Ele é descartado, e a lista vazia volta a significar "todo dia".
 *  7. FUSO        → o dia ser o do servidor, não o de São Paulo. Uma hora UTC que já é outro dia em SP
 *                   recusaria/aceitaria o dia errado.
 *  8. SNAPSHOT    → a versão não guardar o calendário: ninguém explicaria depois por que uma execução
 *                   foi recusada por `AG-01-JANELA` num domingo.
 *  9. MANUAL      → o calendário barrar o "Preparar agora". A janela vale para o que o agente faz
 *                   SOZINHO; quem clica decide a hora (regra que a AG-01 já tinha).
 * 10. VALIDAÇÃO   → `0` e `8` entrarem no banco.
 */
class CalendarioDoAgenteTest extends TestCase
{
    use CenarioDoAgente;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->montarAgente();
    }

    /** Uma configuração NÃO salva, só para a política ler. */
    private function cfg(array $dados = []): PubAgenteConfiguracao
    {
        return new PubAgenteConfiguracao([
            'conta_chave' => 'company-1', 'habilitado' => true, 'controle' => PubAgenteConfiguracao::ATIVO,
            ...$dados,
        ]);
    }

    /** Um instante em São Paulo, pelo dia da semana e pela hora. */
    private function emSp(string $data, string $hora): Carbon
    {
        return Carbon::parse("{$data} {$hora}", 'America/Sao_Paulo');
    }

    public function test_sem_dias_marcados_vale_todo_dia_calendario_e1(): void
    {
        // ⚠️⚠️ O CASO MAIS IMPORTANTE DESTA FATIA. A migration acrescenta a coluna `null` em toda loja
        // que já existe. Se `null` não valesse "todo dia", o deploy pararia o agente de TODAS elas em
        // silêncio — e ninguém veria, porque a recusa é uma linha de log.
        $semNada = $this->cfg(['dias_semana' => null]);

        // Uma semana inteira, dia a dia: 2026-10-19 é segunda.
        foreach (range(0, 6) as $i) {
            $momento = $this->emSp('2026-10-19', '10:00')->addDays($i);
            $this->assertTrue(
                PoliticaDoAgente::dentroDaJanela($semNada, $momento),
                "`dias_semana` nulo recusou {$momento->isoFormat('dddd')} — isso pararia toda loja existente",
            );
        }

        // E com hora configurada, o calendário nulo não interfere na hora.
        $comHora = $this->cfg(['dias_semana' => null, 'janela_inicio' => '08:00:00', 'janela_fim' => '18:00:00']);
        $this->assertTrue(PoliticaDoAgente::dentroDaJanela($comHora, $this->emSp('2026-10-25', '10:00')), 'domingo 10:00 na janela 08–18 foi recusado');
        $this->assertFalse(PoliticaDoAgente::dentroDaJanela($comHora, $this->emSp('2026-10-25', '20:00')), 'domingo 20:00 fora da janela 08–18 foi aceito');
    }

    public function test_desmarcar_todos_os_dias_nao_trava_o_agente_calendario_e1(): void
    {
        // ⚠️ `[]` significa "não restringi", pela mesma convenção que a hora já usa ("sem janela =
        // sempre"). Se valesse "nenhum dia", desmarcar tudo travaria o agente SEM AVISAR.
        $this->ligar(['dias_semana' => [3, 5]]);
        $cfg = app(ConfiguracaoDoAgenteService::class)->daConta($this->conta());
        $this->assertSame([3, 5], $cfg->dias_semana, 'os dias não foram gravados');

        app(ConfiguracaoDoAgenteService::class)->salvar($this->conta(), ['habilitado' => true, 'dias_semana' => []], (int) $cfg->versao, $this->admin);
        $depois = app(ConfiguracaoDoAgenteService::class)->daConta($this->conta());

        $this->assertNull($depois->dias_semana, 'lista vazia não foi normalizada para `null` — duas representações do mesmo estado');
        $this->assertTrue(PoliticaDoAgente::dentroDaJanela($depois, $this->emSp('2026-10-25', '10:00')), 'desmarcar todos os dias TRAVOU o agente');
    }

    public function test_o_dia_marcado_vale_e_o_nao_marcado_nao_calendario_e1(): void
    {
        // Só quarta (3) e sexta (5). 2026-10-19 é segunda.
        $cfg = $this->cfg(['dias_semana' => [3, 5]]);

        $esperado = [
            '2026-10-19' => false, // segunda
            '2026-10-20' => false, // terça
            '2026-10-21' => true,  // quarta
            '2026-10-22' => false, // quinta
            '2026-10-23' => true,  // sexta
            '2026-10-24' => false, // sábado
            '2026-10-25' => false, // domingo
        ];

        foreach ($esperado as $dia => $dentro) {
            $this->assertSame(
                $dentro,
                PoliticaDoAgente::dentroDaJanela($cfg, $this->emSp($dia, '10:00')),
                "o dia {$dia} deu o contrário do esperado para `dias_semana = [3, 5]`",
            );
        }

        // ⚠️ ISCA: a sonda não está presa em `true` nem em `false` — o laço acima tem casos dos dois lados.
        $this->assertContains(true, $esperado, 'o cenário não tem nenhum dia DENTRO — o laço não prova recorte');
        $this->assertContains(false, $esperado, 'o cenário não tem nenhum dia FORA — o laço não prova recorte');
    }

    public function test_madrugada_de_janela_que_cruza_a_meia_noite_conta_o_dia_que_ABRIU_calendario_e1(): void
    {
        // ⚠️⚠️ A DECISÃO DELICADA, cobrada nas DUAS pontas.
        //
        // Só SEGUNDA (1), janela 22:00–02:00. A loja escolheu "segunda, das 22h às 2h".
        $cfg = $this->cfg(['dias_semana' => [1], 'janela_inicio' => '22:00:00', 'janela_fim' => '02:00:00']);

        // 2026-10-19 é segunda, 2026-10-20 é terça.
        $this->assertTrue(PoliticaDoAgente::dentroDaJanela($cfg, $this->emSp('2026-10-19', '23:00')), 'segunda 23:00 — o começo da própria janela — foi recusado');

        // ⚠️ TERÇA 01:00 está DENTRO: a janela ABRIU na segunda. Avaliar pelo dia do relógio faria a
        // janela parar à meia-noite sem ninguém pedir, e as duas horas escolhidas virariam zero.
        $this->assertTrue(PoliticaDoAgente::dentroDaJanela($cfg, $this->emSp('2026-10-20', '01:00')), 'terça 01:00 foi recusado — a janela da segunda está parando na meia-noite');

        // ⚠️ E a outra ponta, que é a consequência DITA no docblock da política: SEGUNDA 01:00 está
        // FORA, porque essa madrugada pertence ao DOMINGO, que não está marcado.
        $this->assertFalse(PoliticaDoAgente::dentroDaJanela($cfg, $this->emSp('2026-10-19', '01:00')), 'segunda 01:00 foi aceito — essa madrugada é do domingo, que não está marcado');

        // Fora da hora, o dia certo também não basta.
        $this->assertFalse(PoliticaDoAgente::dentroDaJanela($cfg, $this->emSp('2026-10-19', '10:00')), 'segunda 10:00 entrou numa janela de 22:00 a 02:00');
        // E terça 23:00 está fora: terça não é dia de abrir.
        $this->assertFalse(PoliticaDoAgente::dentroDaJanela($cfg, $this->emSp('2026-10-20', '23:00')), 'terça 23:00 abriu uma janela que é só de segunda');
    }

    public function test_domingo_marcado_sobrevive_porque_o_iso_nao_tem_dia_zero_calendario_e1(): void
    {
        // ⚠️⚠️ É PARA ISTO QUE O ISO EXISTE AQUI. Com o `dayOfWeek` do Carbon, domingo seria `0` — e `0`
        // é *falsy* em PHP e em JS, então domingo marcado desapareceria em qualquer `array_filter`,
        // `if ($dia)` ou `.filter(Boolean)` do caminho. Com ISO, domingo é `7`.
        $this->ligar(['dias_semana' => [7]]);
        $cfg = app(ConfiguracaoDoAgenteService::class)->daConta($this->conta());

        $this->assertSame([7], $cfg->dias_semana, 'domingo desapareceu na gravação — é o sintoma do 0-based falsy');
        $this->assertTrue(PoliticaDoAgente::dentroDaJanela($cfg, $this->emSp('2026-10-25', '10:00')), 'domingo marcado não valeu no domingo');
        $this->assertFalse(PoliticaDoAgente::dentroDaJanela($cfg, $this->emSp('2026-10-26', '10:00')), 'domingo marcado valeu na segunda');

        // E o rótulo de domingo existe, em pt-BR — a tela não mostra número.
        $this->assertSame('Dom', PubAgenteConfiguracao::ROTULO_DO_DIA[7], 'o rótulo de domingo mudou');
        $this->assertSame([1, 2, 3, 4, 5, 6, 7], PubAgenteConfiguracao::DIAS_ISO, 'os dias deixaram de ser ISO 1..7');
        $this->assertArrayNotHasKey(0, PubAgenteConfiguracao::ROTULO_DO_DIA, 'apareceu um dia `0` — o 0-based voltou');
    }

    public function test_valor_fora_da_faixa_no_banco_nao_trava_o_agente_calendario_e1(): void
    {
        // ⚠️ Dado antigo, escrita por fora, migração de outro sistema: a política não pode travar o
        // agente por causa de um `0` ou `9` no json. Eles são DESCARTADOS, e a lista vazia volta a
        // significar "todo dia".
        $soLixo = $this->cfg(['dias_semana' => [0, 9, -1, 'x', null]]);
        $this->assertTrue(PoliticaDoAgente::dentroDaJanela($soLixo, $this->emSp('2026-10-25', '10:00')), 'lixo no `dias_semana` travou o agente');

        // ⚠️ E com lixo MAIS um dia válido, o dia válido continua recortando — descartar o lixo não pode
        // virar "ignorar a restrição toda".
        $comUmValido = $this->cfg(['dias_semana' => [0, 9, 3]]);
        $this->assertTrue(PoliticaDoAgente::dentroDaJanela($comUmValido, $this->emSp('2026-10-21', '10:00')), 'quarta foi recusada apesar de estar marcada');
        $this->assertFalse(PoliticaDoAgente::dentroDaJanela($comUmValido, $this->emSp('2026-10-22', '10:00')), 'o lixo fez a restrição inteira ser ignorada');

        // Nem string, nem objeto, nem valor escalar derrubam.
        foreach ([null, [], 'segunda', 42] as $torto) {
            $this->assertTrue(PoliticaDoAgente::dentroDaJanela($this->cfg(['dias_semana' => $torto]), $this->emSp('2026-10-25', '10:00')), 'um formato torto de `dias_semana` derrubou ou travou');
        }
    }

    public function test_o_dia_e_o_de_sao_paulo_nao_o_do_servidor_calendario_e1(): void
    {
        // ⚠️ 2026-10-26 03:00 UTC é ainda DOMINGO 25 em São Paulo (UTC−3). Se a política lesse o dia do
        // servidor, ela veria segunda e decidiria pelo dia errado.
        $cfg = $this->cfg(['dias_semana' => [7]]); // domingo
        $utc = Carbon::parse('2026-10-26 02:00', 'UTC');

        $this->assertSame('2026-10-25', $utc->copy()->setTimezone('America/Sao_Paulo')->format('Y-m-d'), 'a fixture não representa a virada de dia entre os fusos');
        $this->assertTrue(PoliticaDoAgente::dentroDaJanela($cfg, $utc), 'a política leu o dia do servidor, não o de São Paulo');

        // E a outra direção: 2026-10-26 12:00 UTC já é segunda nos dois fusos.
        $this->assertFalse(PoliticaDoAgente::dentroDaJanela($cfg, Carbon::parse('2026-10-26 12:00', 'UTC')), 'segunda entrou num calendário só de domingo');
    }

    public function test_o_calendario_entra_no_snapshot_da_versao_calendario_e1(): void
    {
        // ⚠️ Sem isto, ninguém explicaria depois por que uma execução foi recusada por `AG-01-JANELA`
        // num domingo: a configuração de hoje pode já ter mudado.
        $this->assertContains('dias_semana', PubAgenteConfiguracao::CHAVES_DA_VERSAO, '`dias_semana` ficou fora das chaves da versão');

        $this->ligar(['dias_semana' => [2, 4]]);
        $cfg = app(ConfiguracaoDoAgenteService::class)->daConta($this->conta());
        $versao = $cfg->versoes()->orderByDesc('versao')->first();

        $this->assertNotNull($versao, 'a gravação não deixou versão');
        $dados = (array) $versao->dados;
        $this->assertArrayHasKey('dias_semana', $dados, 'o snapshot da versão não guardou o calendário');
        $this->assertSame([2, 4], $dados['dias_semana'], 'o snapshot guardou outro calendário');
    }

    public function test_o_calendario_barra_o_automatico_mas_nunca_o_preparar_agora_calendario_e1(): void
    {
        // ⚠️ A janela vale para o que o agente faz SOZINHO; quem clica "Preparar agora" decide a hora.
        // É a regra que a AG-01 já tinha para a hora, e o calendário tem de seguir a mesma — senão a
        // pessoa clicaria num domingo e nada aconteceria, sem explicação.
        $this->ligar(['dias_semana' => [3]]); // só quarta
        $pub = $this->noPublicador();
        $cfg = app(ConfiguracaoDoAgenteService::class)->daConta($this->conta());
        $politica = app(PoliticaDoAgente::class);

        // Congela um domingo: fora do calendário.
        Carbon::setTestNow($this->emSp('2026-10-25', '10:00'));
        try {
            $recusaAuto = $politica->recusaParaAdmitir($this->conta(), $cfg, $pub, PubAgenteExecucao::ORIGEM_AUTOMATICA);
            $this->assertNotNull($recusaAuto, 'o automático foi admitido fora do calendário');
            $this->assertSame(PoliticaDoAgente::REGRA_JANELA, $recusaAuto['regra'], 'a recusa não foi por janela');

            $this->assertNull(
                $politica->recusaParaAdmitir($this->conta(), $cfg, $pub, PubAgenteExecucao::ORIGEM_MANUAL),
                'o "Preparar agora" foi barrado pelo calendário — quem clica decide a hora',
            );
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_dia_fora_de_um_a_sete_e_recusado_na_gravacao_calendario_e1(): void
    {
        foreach ([[0], [8], [-1], [1, 8]] as $invalido) {
            try {
                app(ConfiguracaoDoAgenteService::class)
                    ->salvar($this->conta(), ['habilitado' => true, 'dias_semana' => $invalido], 0, $this->admin);
                $this->fail('o calendário com dia fora de 1..7 foi aceito: ['.implode(', ', array_map('strval', $invalido)).']');
            } catch (ValidationException|RegraViolada $e) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_a_tela_recebe_o_calendario_e_os_rotulos_do_servidor_calendario_e1(): void
    {
        $this->ligar(['dias_semana' => [1, 7]]);
        $lido = app(LeituraDoAgente::class)->paraConta($this->conta());

        $this->assertSame([1, 7], $lido['configuracao']['dias_semana'], 'a tela não recebeu o calendário');

        // ⚠️ Nulo vai como `[]` para a tela: lá "nenhum marcado" e "todo dia" são o MESMO estado.
        app(ConfiguracaoDoAgenteService::class)->salvar($this->conta(), ['habilitado' => true, 'dias_semana' => []], (int) app(ConfiguracaoDoAgenteService::class)->daConta($this->conta())->versao, $this->admin);
        $this->assertSame([], app(LeituraDoAgente::class)->paraConta($this->conta())['configuracao']['dias_semana'], 'o calendário nulo não virou `[]` para a tela');

        // ⚠️ Os rótulos vêm do SERVIDOR — um vocabulário só. Lista em JS divergiria.
        $opcoes = $lido['dias_opcoes'];
        $this->assertCount(7, $opcoes, 'as opções de dia não são sete');
        $this->assertSame([1, 2, 3, 4, 5, 6, 7], array_column($opcoes, 'valor'), 'as opções de dia não estão em ISO 1..7');
        $this->assertSame(['Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb', 'Dom'], array_column($opcoes, 'rotulo'), 'os rótulos dos dias mudaram');

        // E nenhum rótulo é o número cru — a tela não mostra "1" para a pessoa.
        foreach ($opcoes as $o) {
            $this->assertNotSame((string) $o['valor'], $o['rotulo'], "o dia {$o['valor']} aparece cru na tela");
        }
    }
}
