<?php

namespace App\Console\Commands;

use App\Services\Publicador\Aprovacao\Fila\AquecimentoDeTarifasService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 * O acionador MANUAL do pré-aquecimento da tarifa da fila "Aguardando aprovação", na forma medida
 * do `adman:warm-diff` ({@see WarmAdmanDiffCache}).
 *
 * ⚠️⚠️ **ZERO LÓGICA PRÓPRIA.** Ele chama {@see AquecimentoDeTarifasService::paraAConta()} e
 * imprime o relatório. Não monta chave de cache, não lê o `Cache`, não conhece a URL do Mercado
 * Livre, não sabe o que é um trio. Há gate provando essas ausências sobre o código **sem
 * comentário** — e é por isso que o comando e o job não podem divergir: eles compartilham o
 * MESMO serviço.
 *
 * ═══ ⚠️⚠️ ELE **NÃO** ENTRA NO CRON, E O MOTIVO É MEDIDO ═══════════════════════════════════════
 *
 * O `adman:warm-diff` é agendado **2× por dia** (`routes/console.php:25` e `:29`) porque o cache
 * dele é **DIÁRIO** — *"auto-invalida à meia-noite"*. ⚠️ **O cache da tarifa é de 1 HORA**
 * (`config/publicador.php:97`, `'tarifa' => 3600`). Logo:
 *
 *   · um `dailyAt(...)` aqueceria às 6h e estaria **frio às 7h** — agendamento **sem efeito**, que
 *     é a definição de decorativo;
 *   · um `hourly()` faria **24 varreduras por dia** de uma fila que hoje está **vazia** e que,
 *     quando tiver ofertas, só é olhada em horário comercial — gastando chamadas ao Mercado Livre
 *     por conta própria, contra o limite de requisições que `adman:sync`, `ml:sync`,
 *     `adman:sync-margem`, `adman:warm-diff` e o warm do fechamento **já disputam** (a disputa
 *     está nomeada em `routes/console.php:42`).
 *
 * ➡️ **Quem resolve o caso real é {@see \App\Jobs\AquecerTarifasDaFilaJob}**, disparado por quem
 * está abrindo a tela: é o único acionador cuja janela **casa com o TTL de 1 h**.
 *
 * ➡️ **Este comando existe para os dois usos em que ele é a ferramenta certa, e os dois são
 * reais:**
 *   1. **depois de deploy ou de `cache:clear` no VPS** — exatamente o remédio registrado no
 *      incidente de **30/07/2026**, quando o `cache:clear` esvaziou o cache aquecido, o dashboard
 *      passou a esperar a API, as requisições lentas ocuparam os workers do php-fpm e **até o
 *      login parou** (o remédio foi `systemctl reload php8.2-fpm` + `adman:warm-diff`);
 *   2. **MEDIR** (`--dry-run`) quantos trios distintos uma conta tem, sem gastar chamada nenhuma.
 *
 * ⚠️ Há **gate de ausência** provando que não existe `Schedule::command('publicador:warm-tarifas')`
 * em `routes/console.php`, com **isca** (um `Schedule::command` sintético REPROVA a sonda). Se um
 * dia o TTL da tarifa virar diário, o agendamento passa a fazer sentido — e aí é mudança
 * consciente, não esquecimento.
 *
 * ═══ ⚠️ "0 TRIOS" É SUCESSO, NÃO ERRO ══════════════════════════════════════════════════════════
 *
 * Fila vazia imprime `trios=0` e devolve `SUCCESS`. Medido em 15/10/2026: **nada** cria
 * `PubAprovacaoOferta` hoje (quem passa a criar é a **Fase 194**), então `0` é o número honesto —
 * e tratá-lo como falha faria o comando gritar por um estado correto.
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 */
class WarmTarifasDaFila extends Command
{
    protected $signature = 'publicador:warm-tarifas
        {--empresa= : id da Company; default = todas as contas com oferta na fila}
        {--dry-run : só conta os trios distintos, sem consultar o Mercado Livre}';

    protected $description = 'Aquece o cache da tarifa do ML da fila de aprovação (a tela lê daqui). NÃO é agendado: o cache é de 1 h e quem aquece no uso real é o AquecerTarifasDaFilaJob — rodar à mão depois de deploy/cache:clear, ou com --dry-run para medir os trios.';

    public function __construct(private AquecimentoDeTarifasService $aquecimento)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $inicio = microtime(true);
        $simular = (bool) $this->option('dry-run');

        $daOpcao = $this->option('empresa');
        $contas = $daOpcao !== null && $daOpcao !== ''
            ? [(int) $daOpcao]
            : $this->aquecimento->contasComOferta();

        $ok = 0;
        $fail = 0;
        $trios = 0;
        $pulados = 0;
        $falhasDeOferta = 0;

        foreach ($contas as $companyId) {
            try {
                // ⚠️ `paraAConta()` faz o `Cache::remember` por dentro de `para()`: em cache-miss
                // popula, em cache-hit não custa nada (idempotente). O comando NÃO monta chave.
                $r = $this->aquecimento->paraAConta($companyId, null, $simular);

                $trios += $r['trios'];
                $pulados += $r['pulados'];
                $falhasDeOferta += $r['falhas'];
                $ok++;
            } catch (\Throwable $e) {
                // ⚠️ `Log then continue`, POR CONTA: uma conta que falha não interrompe as demais.
                $fail++;
                Log::warning(AquecimentoDeTarifasService::TAG.' conta falhou', [
                    'company_id' => $companyId,
                    'dry_run' => $simular,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $seg = round(microtime(true) - $inicio, 1);
        $msg = sprintf(
            '%s concluído em %ss — contas=%d trios=%d OK=%d FAIL=%d pulados=%d',
            AquecimentoDeTarifasService::TAG,
            $seg,
            count($contas),
            $trios,
            $ok,
            $fail,
            $pulados,
        );
        Log::info($msg);
        $this->info($msg);

        // ⚠️ As falhas POR OFERTA saem em linha PRÓPRIA, e só quando existem: embuti-las na linha
        // de forma fixa acima faria "0 trios numa fila vazia" imprimir vocabulário de erro.
        if ($falhasDeOferta > 0) {
            $this->warn(AquecimentoDeTarifasService::TAG.' ofertas com falha no aquecimento: '.$falhasDeOferta);
        }

        return self::SUCCESS;
    }
}
