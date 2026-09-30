<?php

namespace App\Console\Commands;

use App\Models\Company;
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
 * Custo por rodada: UMA chamada por empresa que pode usar a Adman (~84 em
 * produção), a 7 s de intervalo → ~10 min. O intervalo é o do `adman:sync`
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
        {--mes= : Competência YYYY-MM (default: mês corrente)}
        {--company= : Aquece apenas uma empresa (ID) — para conferência manual}';

    protected $description = 'Aquece o total do período da Adman que a tela do fechamento lê no mês em curso. Cache diário — rodar após o adman:sync.';

    /**
     * Pausa entre chamadas, em microssegundos. 7 s = o mesmo espaçamento do
     * fan-out do `adman:sync` (10 rpm → 6 s teóricos + 1 s de folga).
     */
    private const PAUSA_ENTRE_CHAMADAS = 7_000_000;

    public function __construct(
        private AdmanService $adman,
        private FechamentoRollupService $rollup,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $inicioEm = microtime(true);
        $mes      = $this->option('mes') ?: Carbon::now()->format('Y-m');

        if (! preg_match('/^\d{4}-\d{2}$/', $mes)) {
            $this->error("Competência inválida: \"{$mes}\". Formato esperado: YYYY-MM.");

            return self::FAILURE;
        }

        $janela = $this->rollup->janelaDaAdman($mes);

        if ($janela === null) {
            // Dia 1º do mês: ontem ainda é do mês passado, não existe nenhum dia
            // fechado na competência. Sair com SUCCESS de propósito — não é
            // erro, é o calendário.
            $this->info("[WarmFechamento] {$mes} ainda não tem nenhum dia fechado (a Adman é D-1) — nada a aquecer.");

            return self::SUCCESS;
        }

        $de  = $janela['inicio']->toDateString();
        $ate = $janela['fim']->toDateString();

        $empresas = $this->empresasParaAquecer();

        if ($empresas->isEmpty()) {
            $this->info('[WarmFechamento] Nenhuma empresa pode ler o faturamento da Adman — nada a aquecer.');

            return self::SUCCESS;
        }

        $this->info("[WarmFechamento] {$mes} — janela {$de}..{$ate}, {$empresas->count()} empresa(s), ~".
            (int) ceil(($empresas->count() * 7) / 60).'min.');

        $aquecidas    = [];
        $ficaramFora  = [];

        foreach ($empresas->values() as $i => $company) {
            if ($i > 0) {
                $this->pausarEntreChamadas();
            }

            // `fetchGrossBilling()` já engole a exceção, cacheia a sentinela de
            // erro e devolve `null`. O try/catch é a rede de segurança para
            // qualquer outra coisa (memória, DNS, timeout fora do cliente HTTP):
            // erro numa empresa NÃO derruba a rodada, mesmo espírito de
            // `AdmanService::syncAll()` — e aqui importa mais, porque a empresa
            // seguinte ainda tem cache para aquecer.
            // O que já estava no cache ANTES de forçar. Guardado porque
            // `fetchGrossBilling()` grava a sentinela de erro quando falha, e
            // uma rodada da tarde que pega um 429 passageiro trocaria um número
            // bom da manhã por fallback na tela. Se a releitura falhar, o valor
            // antigo volta — dado de algumas horas atrás é melhor que nenhum.
            $valorAnterior = $this->adman->getCachedGrossBilling((string) $company->cust_id, $de, $ate);

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

        // SUCCESS mesmo com empresas de fora: a rodada fez o que podia, e
        // devolver FAILURE faria o agendador tratar como falha de execução uma
        // conta Adman problemática — que é problema de cadastro, não do comando.
        return self::SUCCESS;
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
