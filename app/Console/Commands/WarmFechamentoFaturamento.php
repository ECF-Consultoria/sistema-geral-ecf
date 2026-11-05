<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\FechamentoSnapshot;
use App\Services\AdmanService;
use App\Services\Fechamento\FechamentoRollupService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Quick 260930-njd (T2) — aquece o total do período da Adman que a tela do
 * fechamento lê no mês EM CURSO.
 *
 * PROBLEMA que resolve: desde este quick, `FechamentoRollupService::porEmpresa()`
 * prefere o total do período devolvido pelo `/performance` da Adman à nossa
 * soma de `adman_metrics` — porque a Adman revisa dias já passados depois da
 * nossa coleta e a soma envelhece. Medido em produção em 30/09/2026, janela
 * 01/09–29/09: amostra de 12 empresas, R$ 12.468.923,94 na nossa soma contra
 * R$ 12.910.546,59 na Adman (+3,5%). Dia a dia, mesma empresa: 28/09 guardado
 * R$ 15.933,13 contra R$ 17.904,08 na Adman (+12,4%).
 *
 * Mas dentro de um request a leitura é SÓ DO CACHE: 84 chamadas HTTP num
 * carregamento de tela é exatamente como o `cache:clear` de 2026-07-30 derrubou
 * a produção (o dashboard passou a esperar a Adman, as requisições lentas
 * ocuparam os workers do php-fpm e até o login parou). Sem este comando o cache
 * nasce frio todo dia — a chave de `fetchGrossBilling()` inclui a data BRT — e a
 * tela fica permanentemente no fallback da soma diária.
 *
 * ⚠️ A JANELA VEM DE `FechamentoRollupService::janelaDaAdman()`, nunca calculada
 * aqui. A chave de cache é `custId:dateFrom:dateTo:dia`: um dia de diferença
 * entre o que este comando aquece e o que a tela pede e o cache nunca casa — o
 * aquecimento rodaria bonito, com resumo verde, e a tela seguiria no fallback
 * sem ninguém entender por quê.
 *
 * ⚠️ O MARKETPLACE fica no default 'meli' de `fetchGrossBilling()`, igual ao do
 * rollup, pela mesma razão: o marketplace entra na chave de cache.
 *
 * Debug fechamento-outubro-fallback (2026-11-04): sem `--mes`, aquece também
 * o mês ANTERIOR enquanto ele não tiver `FechamentoSnapshot` — a tela lê o
 * anterior só do cache também, e na virada ele ficava 100% frio até a
 * consolidação manual (ver `mesesDoAgendamento()`).
 *
 * Custo por rodada: UMA chamada por empresa que pode usar a Adman (~84 em
 * produção) POR COMPETÊNCIA, a 7 s de intervalo → ~10 min; ~20 min nos dias
 * entre a virada do mês e a consolidação do anterior. O intervalo é o do `adman:sync`
 * (`AdmanService::ADMAN_RATE_LIMIT_RPM` = 10 rpm → 6 s teóricos + 1 s de folga).
 *
 * ⚠️ NÃO enfileira nada: é trabalho em lote e roda no próprio processo do
 * agendador. A fila de produção é Redis e a `default` já viveu congestionada
 * (179 jobs de acervo ML seguraram o webhook do Clicksign por horas em 16/09);
 * a `high` é dos jobs interativos e de webhook e não é lugar de lote.
 */
class WarmFechamentoFaturamento extends Command
{
    protected $signature = 'adman:warm-fechamento
        {--mes= : Competência YYYY-MM (default: mês corrente + mês anterior enquanto não consolidado)}
        {--company= : Aquece apenas uma empresa (ID) — para conferência manual}';

    protected $description = 'Aquece o total do período da Adman que a tela do fechamento lê (mês em curso e o anterior ainda não consolidado). Cache diário — rodar após o adman:sync.';

    /**
     * Pausa entre chamadas, em microssegundos. 7 s = o mesmo espaçamento do
     * fan-out do `adman:sync` (10 rpm → 6 s teóricos + 1 s de folga).
     */
    private const PAUSA_ENTRE_CHAMADAS = 7_000_000;

    /**
     * Proporção de empresas sem o número da Adman a partir da qual a rodada
     * grita no log (`Log::error`) — ver `alertarSeMuitasDeFora()`.
     */
    private const LIMITE_PROPORCAO_DE_FORA = 0.10;

    public function __construct(
        private AdmanService $adman,
        private FechamentoRollupService $rollup,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $mesExplicito = $this->option('mes');

        if ($mesExplicito !== null && $mesExplicito !== '' && ! preg_match('/^\d{4}-\d{2}$/', $mesExplicito)) {
            $this->error("Competência inválida: \"{$mesExplicito}\". Formato esperado: YYYY-MM.");

            return self::FAILURE;
        }

        $meses = $mesExplicito ? [$mesExplicito] : $this->mesesDoAgendamento();

        $empresas = $this->empresasParaAquecer();

        if ($empresas->isEmpty()) {
            $this->info('[WarmFechamento] Nenhuma empresa pode ler o faturamento da Adman — nada a aquecer.');

            return self::SUCCESS;
        }

        $houveChamada = false;

        foreach ($meses as $mes) {
            $houveChamada = $this->aquecerMes($mes, $empresas, $houveChamada) || $houveChamada;
        }

        // SUCCESS mesmo com empresas de fora: a rodada fez o que podia, e
        // devolver FAILURE faria o agendador tratar como falha de execução uma
        // conta Adman problemática — que é problema de cadastro, não do comando.
        return self::SUCCESS;
    }

    /**
     * Debug fechamento-outubro-fallback (2026-11-04) — as competências que a
     * rodada AGENDADA (sem `--mes`) aquece.
     *
     * A tela do fechamento lê SÓ DO CACHE o mês selecionado E o anterior
     * (quick 260930-njd). Até aqui o aquecimento cobria só o mês corrente: na
     * virada do mês, o mês que acabou de fechar ficava 100% frio até alguém
     * consolidar à mão, e a tela caía inteira na soma diária envelhecida
     * (outubro/2026: 139 de 143 empresas no fallback, soma 1,6%–3,9% abaixo da
     * Adman).
     *
     * Por isso o mês ANTERIOR também é aquecido enquanto não tiver NENHUMA
     * linha em `fechamento_snapshots` — depois de consolidado a tela lê o
     * congelado e o aquecimento dele vira chamada à toa. O anterior vem
     * PRIMEIRO: é o mês que está em conferência para cobrança.
     *
     * @return list<string>
     */
    private function mesesDoAgendamento(): array
    {
        $corrente = Carbon::now()->startOfMonth();
        $anterior = $corrente->copy()->subMonthNoOverflow();

        $anteriorConsolidado = FechamentoSnapshot::query()
            ->whereDate('mes_referencia', $anterior->toDateString())
            ->exists();

        return $anteriorConsolidado
            ? [$corrente->format('Y-m')]
            : [$anterior->format('Y-m'), $corrente->format('Y-m')];
    }

    /**
     * Aquece uma competência. Devolve `true` se fez ao menos uma chamada — para
     * a pausa de 7 s valer também entre a última empresa de uma competência e a
     * primeira da seguinte (o limite de 10 rpm não sabe de competência).
     *
     * @param  \Illuminate\Support\Collection<int, Company>  $empresas
     */
    private function aquecerMes(string $mes, \Illuminate\Support\Collection $empresas, bool $houveChamadaAntes): bool
    {
        $inicioEm = microtime(true);

        $janela = $this->rollup->janelaDaAdman($mes);

        if ($janela === null) {
            // Dia 1º do mês: ontem ainda é do mês passado, não existe nenhum dia
            // fechado na competência. Não é erro, é o calendário.
            $this->info("[WarmFechamento] {$mes} ainda não tem nenhum dia fechado (a Adman é D-1) — nada a aquecer.");

            return false;
        }

        $de  = $janela['inicio']->toDateString();
        $ate = $janela['fim']->toDateString();

        $this->info("[WarmFechamento] {$mes} — janela {$de}..{$ate}, {$empresas->count()} empresa(s), ~".
            (int) ceil(($empresas->count() * 7) / 60).'min.');

        $aquecidas    = [];
        $ficaramFora  = [];
        $idsDeFora    = [];

        foreach ($empresas->values() as $i => $company) {
            if ($i > 0 || $houveChamadaAntes) {
                $this->pausarEntreChamadas();
            }

            // O que já estava no cache ANTES de forçar. Guardado porque
            // `fetchGrossBilling()` grava a sentinela de erro quando falha, e
            // uma rodada da tarde que pega um 429 passageiro trocaria um número
            // bom da manhã por fallback na tela. Se a releitura falhar, o valor
            // antigo volta — dado de algumas horas atrás é melhor que nenhum.
            $valorAnterior = $this->adman->getCachedGrossBilling((string) $company->cust_id, $de, $ate);

            // `fetchGrossBilling()` já engole a exceção, cacheia a sentinela de
            // erro e devolve `null`. O try/catch é a rede de segurança para
            // qualquer outra coisa (memória, DNS, timeout fora do cliente HTTP):
            // erro numa empresa NÃO derruba a rodada, mesmo espírito de
            // `AdmanService::syncAll()` — e aqui importa mais, porque a empresa
            // seguinte ainda tem cache para aquecer.
            try {
                $valor = $this->adman->fetchGrossBilling(
                    (string) $company->cust_id,
                    $de,
                    $ate,
                    1440,       // TTL 24h — a chave já inclui o dia BRT
                    true,       // forceRefresh: sem isto a rodada da tarde seria
                                // um no-op (cache-hit da manhã) e a Adman revisa
                                // dias passados DURANTE o dia — reler é o ponto
                );
            } catch (\Throwable $e) {
                $valor = null;
                Log::warning(
                    "[WarmFechamento] Falha ao aquecer a empresa {$company->id} ({$company->name}) "
                    ."na competência {$mes}: ".$e->getMessage(),
                    ['company_id' => (int) $company->id, 'cust_id' => (string) $company->cust_id, 'janela' => "{$de}..{$ate}"]
                );
            }

            if ($valor === null) {
                if ($valorAnterior !== null) {
                    // Repõe o valor bom sobre a sentinela de erro que a tentativa
                    // acabou de gravar. Não conta como aquecida: a rodada não
                    // conseguiu o número de agora, e o resumo tem de dizer isso.
                    $this->adman->guardarGrossBillingNoCache((string) $company->cust_id, $de, $ate, $valorAnterior);
                }

                $ficaramFora[] = "{$company->id} ({$company->name})";
                $idsDeFora[]   = (int) $company->id;

                continue;
            }

            $aquecidas[] = (int) $company->id;
        }

        $segundos = round(microtime(true) - $inicioEm, 1);

        $resumo = sprintf(
            '[WarmFechamento] %s concluído em %ss — janela %s..%s, aquecidas=%d, de fora=%d',
            $mes, $segundos, $de, $ate, count($aquecidas), count($ficaramFora)
        );

        $this->info($resumo);
        Log::info($resumo, ['aquecidas' => count($aquecidas), 'de_fora' => $ficaramFora]);

        // COM NOMES: "3 ficaram de fora" não é acionável. O nome é o que permite
        // abrir a empresa e ver se a conta Adman dela está errada.
        if ($ficaramFora !== []) {
            $this->warn('[WarmFechamento] Sem número da Adman (a tela vai mostrar a nossa soma diária nestas):');

            foreach ($ficaramFora as $nome) {
                $this->line("  - {$nome}");
            }
        }

        $this->alertarSeMuitasDeFora($mes, $empresas->count(), $idsDeFora);

        return true;
    }

    /**
     * Debug fechamento-outubro-fallback — alerta quando a rodada deixa MUITAS
     * empresas sem o número da Adman. Uma ou outra conta problemática é rotina
     * de cadastro; acima de `LIMITE_PROPORCAO_DE_FORA` é sinal de que a tela do
     * fechamento vai cair em massa na soma diária (rate limit, Adman fora,
     * chave errada) e alguém precisa olhar antes de a cobrança sair.
     *
     * `Log::error` e não `warning` DE PROPÓSITO: produção roda com
     * `LOG_LEVEL=error`, e um warning simplesmente não aparece no log.
     *
     * @param  list<int>  $idsDeFora
     */
    private function alertarSeMuitasDeFora(string $mes, int $total, array $idsDeFora): void
    {
        if ($total === 0 || count($idsDeFora) / $total <= self::LIMITE_PROPORCAO_DE_FORA) {
            return;
        }

        $primeiros = array_slice($idsDeFora, 0, 10);

        Log::error(sprintf(
            '[WarmFechamento] Competência %s: %d de %d empresa(s) ficaram SEM o número da Adman (%.0f%%, limite %.0f%%) — '
            .'a tela do fechamento vai mostrar a soma diária nelas. Primeiros ids: %s',
            $mes,
            count($idsDeFora),
            $total,
            count($idsDeFora) / $total * 100,
            self::LIMITE_PROPORCAO_DE_FORA * 100,
            implode(', ', $primeiros),
        ), ['competencia' => $mes, 'de_fora' => count($idsDeFora), 'total' => $total, 'ids' => $primeiros]);
    }

    /**
     * Espera entre duas chamadas consecutivas — no-op durante os testes.
     *
     * Mesmo padrão de `AdmanService::dormirEntreTentativas()`, e pela mesma
     * razão: o espaçamento de 7 s é o que faz o comando respeitar o limite de
     * 10 rpm em produção, mas uma suíte que dorme de verdade somaria minutos de
     * sono real. A lógica de TEMPO (o valor da pausa) fica travada por
     * `PAUSA_ENTRE_CHAMADAS` e pelo teste que confere a constante — pular o sono
     * não esconde nada.
     */
    protected function pausarEntreChamadas(): void
    {
        if (app()->runningUnitTests()) {
            return;
        }

        usleep(self::PAUSA_ENTRE_CHAMADAS);
    }

    /**
     * As empresas cujo total de período a tela vai consultar: ativas, e que
     * passam no MESMO critério do rollup (`podeLerFaturamentoDaAdman()`, que
     * delega no `podeUsarApiDaAdman()` privado). Duplicar o critério aqui seria
     * criar uma segunda régua — e aquecer o cache de quem a tela não consulta,
     * ou deixar de aquecer quem ela consulta, dá no mesmo resultado: fallback.
     *
     * `with('mlToken')` porque o critério lê `is_ml_driven`, que é acessor por
     * cima da relação — sem isto seria um N+1 no laço de ~187 empresas.
     *
     * @return \Illuminate\Support\Collection<int, Company>
     */
    private function empresasParaAquecer(): \Illuminate\Support\Collection
    {
        $query = Company::query()->with('mlToken');

        if ($companyId = $this->option('company')) {
            $query->whereKey($companyId);
        } else {
            $query->where('active', true);
        }

        return $query->get()->filter(
            fn (Company $company) => $this->rollup->podeLerFaturamentoDaAdman($company)
        );
    }
}
