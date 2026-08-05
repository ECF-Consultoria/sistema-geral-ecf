<?php

namespace App\Console\Commands;

use App\Models\MlbEmpresa;
use App\Models\Publicacao;
use App\Models\User;
use App\Services\VendasSyncService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Devolve à competência original os anúncios que foram excluídos e cadastrados
 * de novo em outro mês.
 *
 * Contexto (auditoria de 2026-08-04): anúncio publicado num mês, excluído e
 * recadastrado no mês seguinte passa a contar na meta e na conversão do mês novo.
 * Como o sync casa a venda pelo mlb_code dentro da janela sincronizada, um anúncio
 * maduro entra no mês novo praticamente com venda garantida — e conversão é portão
 * de bônus. Foram 93 casos entre maio e agosto de 2026.
 *
 * A competência original vem da trilha de auditoria: cada cadastro grava um evento
 * `activity('mlb')` com a `data` declarada no formulário dentro de `properties`.
 * Comparando cadastros consecutivos do mesmo mlb_code, a mudança de mês fica
 * explícita. É a única fonte que sobreviveu — a linha antiga foi apagada.
 *
 * Dry-run por padrão. Todo --apply grava backup em `mlb_reversao_backup` antes de
 * qualquer UPDATE, e o lote inteiro pode ser desfeito com --desfazer.
 */
class ReverterRecadastrosCompetencia extends Command
{
    protected $signature = 'mlb:reverter-recadastros
                            {--apply : Grava as alterações (sem esta flag é só relatório)}
                            {--resync : Depois de aplicar, re-sincroniza as competências de origem}
                            {--desfazer= : Restaura um lote de reversão pelo uuid}
                            {--resync-lote= : Re-sincroniza as competências de um lote já revertido}
                            {--mes= : Limita ao mês de destino (YYYY-MM), ex: 2026-08}';

    protected $description = 'Devolve à competência original os anúncios recadastrados em outro mês';

    public function handle(): int
    {
        if ($lote = $this->option('desfazer')) {
            return $this->desfazer($lote);
        }

        // Reverter e re-sincronizar são operações separadas na prática: a reversão
        // é instantânea (banco), o re-sync leva minutos batendo na Adman. Quem
        // aplicou sem --resync precisa poder sincronizar depois sem reprocessar
        // nada — daí o lote como chave.
        if ($lote = $this->option('resync-lote')) {
            return $this->resyncLote($lote);
        }

        $casos = $this->detectarCasos();

        if ($mes = $this->option('mes')) {
            $casos = array_filter($casos, fn ($c) => substr($c['data_nova'], 0, 7) === $mes);
        }

        if (empty($casos)) {
            $this->info('Nenhum recadastro entre competências encontrado.');

            return self::SUCCESS;
        }

        [$aplicaveis, $ignorados] = $this->separarAplicaveis($casos);

        $this->relatorio($aplicaveis, $ignorados);

        if (empty($aplicaveis)) {
            $this->info('Nada a fazer — todos os casos já estão na competência original.');

            return self::SUCCESS;
        }

        if (! $this->option('apply')) {
            $this->line('');
            $this->warn('DRY-RUN — nada foi gravado. Rode com --apply para aplicar.');

            return self::SUCCESS;
        }

        $lote = (string) Str::uuid();
        $total = $this->aplicar($aplicaveis, $lote);

        $this->line('');
        $this->info("{$total} publicação(ões) devolvida(s) à competência original.");
        $this->line("Lote: {$lote}");
        $this->line("Desfazer com: php artisan mlb:reverter-recadastros --desfazer={$lote}");

        if ($this->option('resync')) {
            $this->resync($aplicaveis);
        } else {
            $this->line('');
            $this->warn('Venda e faturamento dessas linhas foram ZERADOS — rode com --resync');
            $this->warn('(ou sincronize pela tela) para as competências de origem serem reapuradas.');
        }

        return self::SUCCESS;
    }

    // =========================================================================
    // Detecção
    // =========================================================================

    /**
     * Monta os casos a partir da trilha de cadastros.
     *
     * @return array<int, array{mlb_code:string, data_original:string, data_nova:string,
     *                          quem:string, quando_original:string, quando_novo:string}>
     */
    private function detectarCasos(): array
    {
        $eventos = DB::table('activity_log')
            ->where('log_name', 'mlb')
            ->where('description', 'like', 'Publicações MLB registradas%')
            ->orderBy('id')
            ->get(['causer_id', 'properties', 'created_at']);

        $historico = [];
        foreach ($eventos as $ev) {
            $props = json_decode($ev->properties, true);
            $data = $props['data'] ?? null;
            if (! $data) {
                continue;
            }
            foreach (($props['mlbs'] ?? []) as $code) {
                $historico[$code][] = [
                    'data'      => $data,
                    'quando'    => $ev->created_at,
                    'causer_id' => $ev->causer_id,
                ];
            }
        }

        $casos = [];
        foreach ($historico as $code => $cadastros) {
            if (count($cadastros) < 2) {
                continue;
            }

            $primeiro = $cadastros[0];
            $ultimo   = end($cadastros);

            // Só interessa quando a COMPETÊNCIA mudou. Recadastro no mesmo mês é
            // correção de digitação e não altera meta nem bônus.
            if (substr($primeiro['data'], 0, 7) === substr($ultimo['data'], 0, 7)) {
                continue;
            }

            $casos[] = [
                'mlb_code'        => $code,
                'data_original'   => $primeiro['data'],
                'data_nova'       => $ultimo['data'],
                'quem'            => $ultimo['causer_id'],
                'quando_original' => $primeiro['quando'],
                'quando_novo'     => $ultimo['quando'],
            ];
        }

        return $casos;
    }

    /**
     * Separa o que dá para aplicar com segurança.
     *
     * Só toca a linha se ela ainda estiver exatamente na competência do último
     * recadastro. Se a `data` for outra, alguém mexeu depois da trilha e a
     * correção automática passaria por cima — nesse caso a linha vai para o
     * relatório de ignorados, para conferência humana.
     */
    private function separarAplicaveis(array $casos): array
    {
        $codes = array_column($casos, 'mlb_code');
        $linhas = Publicacao::whereIn('mlb_code', $codes)->get()->keyBy('mlb_code');
        $custPorEmpresa = MlbEmpresa::whereNotNull('cust_id')->where('cust_id', '!=', '')
            ->pluck('cust_id', 'id');

        $aplicaveis = [];
        $ignorados  = [];

        foreach ($casos as $caso) {
            $linha = $linhas[$caso['mlb_code']] ?? null;

            if (! $linha) {
                $ignorados[] = $caso + ['motivo' => 'publicação não existe mais'];
                continue;
            }

            $dataAtual = substr((string) $linha->data, 0, 10);

            if ($dataAtual === $caso['data_original']) {
                $ignorados[] = $caso + ['motivo' => 'já está na competência original'];
                continue;
            }

            if ($dataAtual !== $caso['data_nova']) {
                $ignorados[] = $caso + ['motivo' => "data atual ({$dataAtual}) não bate com a do recadastro"];
                continue;
            }

            $caso['linha']   = $linha;
            $caso['cust_id'] = trim((string) $linha->cust_id) !== ''
                ? trim((string) $linha->cust_id)
                : (string) ($custPorEmpresa[$linha->mlb_empresa_id] ?? '');
            $aplicaveis[] = $caso;
        }

        return [$aplicaveis, $ignorados];
    }

    // =========================================================================
    // Relatório
    // =========================================================================

    private function relatorio(array $aplicaveis, array $ignorados): void
    {
        $nomes = User::pluck('name', 'id');

        $this->line('');
        $this->info(sprintf('%d publicação(ões) para devolver à competência original:', count($aplicaveis)));
        $this->line('');

        $porGrupo = [];
        foreach ($aplicaveis as $c) {
            $chave = ($nomes[$c['quem']] ?? 'Sistema')
                . '|' . substr($c['data_nova'], 0, 7)
                . '|' . substr($c['data_original'], 0, 7);
            $porGrupo[$chave]['n'] = ($porGrupo[$chave]['n'] ?? 0) + 1;
            $porGrupo[$chave]['venda'] = ($porGrupo[$chave]['venda'] ?? 0) + ($c['linha']->vendido ? 1 : 0);
            $porGrupo[$chave]['net'] = ($porGrupo[$chave]['net'] ?? 0) + (float) $c['linha']->net_billing;
            $porGrupo[$chave]['sem_cust'] = ($porGrupo[$chave]['sem_cust'] ?? 0) + ($c['cust_id'] === '' ? 1 : 0);
        }
        ksort($porGrupo);

        $linhas = [];
        foreach ($porGrupo as $chave => $v) {
            [$quem, $de, $para] = explode('|', $chave);
            $linhas[] = [
                $quem,
                "{$de} → {$para}",
                $v['n'],
                $v['venda'],
                'R$ ' . number_format($v['net'], 2, ',', '.'),
                $v['sem_cust'] ?: '—',
            ];
        }

        $this->table(
            ['Publicador', 'Sai de → volta para', 'Anúncios', 'Com venda', 'Faturamento movido', 'Sem Cust ID'],
            $linhas
        );

        if ($ignorados) {
            $this->line('');
            $this->warn(sprintf('%d caso(s) ignorado(s):', count($ignorados)));
            $motivos = [];
            foreach ($ignorados as $i) {
                $motivos[$i['motivo']] = ($motivos[$i['motivo']] ?? 0) + 1;
            }
            foreach ($motivos as $motivo => $n) {
                $this->line("  {$n}× {$motivo}");
            }
        }

        $this->line('');
        $this->line('Cada linha volta para a data do cadastro original, recebe o Cust ID da');
        $this->line('empresa se estiver vazio, e tem venda/faturamento ZERADOS — os valores');
        $this->line('gravados hoje vieram da janela do mês errado.');
    }

    // =========================================================================
    // Aplicação
    // =========================================================================

    private function aplicar(array $aplicaveis, string $lote): int
    {
        return DB::transaction(function () use ($aplicaveis, $lote) {
            $agora = now();
            $backup = [];

            foreach ($aplicaveis as $c) {
                $l = $c['linha'];
                $backup[] = [
                    'lote'                    => $lote,
                    'publicacao_id'           => $l->id,
                    'mlb_code'                => $l->mlb_code,
                    'data_anterior'           => substr((string) $l->data, 0, 10),
                    'cust_id_anterior'        => $l->cust_id,
                    'vendido_anterior'        => (bool) $l->vendido,
                    'vendas_qty_anterior'     => (int) $l->vendas_qty,
                    'net_billing_anterior'    => $l->net_billing,
                    'preco_unitario_anterior' => $l->preco_unitario,
                    'data_nova'               => $c['data_original'],
                    'created_at'              => $agora,
                ];
            }

            // Backup ANTES do primeiro UPDATE — se algo falhar depois, a transação
            // desfaz tudo junto, mas a ordem deixa a intenção explícita.
            foreach (array_chunk($backup, 200) as $pedaco) {
                DB::table('mlb_reversao_backup')->insert($pedaco);
            }

            $n = 0;
            foreach ($aplicaveis as $c) {
                $dados = [
                    'data'           => $c['data_original'],
                    'vendido'        => false,
                    'vendas_qty'     => 0,
                    'net_billing'    => null,
                    'preco_unitario' => null,
                ];

                if ($c['cust_id'] !== '' && trim((string) $c['linha']->cust_id) === '') {
                    $dados['cust_id'] = $c['cust_id'];
                }

                $n += Publicacao::where('id', $c['linha']->id)->update($dados);
            }

            Log::info("[MLB Reversão] Lote {$lote}: {$n} publicação(ões) devolvidas à competência original");

            activity('mlb')
                ->withProperties([
                    'lote'        => $lote,
                    'publicacoes' => $n,
                    'mlbs'        => array_column($aplicaveis, 'mlb_code'),
                ])
                ->log("Reversão de competência aplicada em {$n} publicação(ões) — lote {$lote}");

            return $n;
        });
    }

    // =========================================================================
    // Re-sync
    // =========================================================================

    /**
     * Reapura as vendas nas competências de origem.
     *
     * ATENÇÃO: `VendasSyncService::syncEmpresa` RESETA todas as publicações da loja
     * naquele mês (vendido=false, qty=0, net=null) antes de reaplicar o que vier da
     * Adman. Ou seja, isto não mexe só nas linhas revertidas — recalcula a loja
     * inteira naquela competência, e números de outros publicadores na mesma
     * loja/mês podem mudar. É o preço de ter o dado correto.
     */
    private function resync(array $aplicaveis): void
    {
        $pares = [];
        foreach ($aplicaveis as $c) {
            if ($c['cust_id'] === '') {
                continue;
            }
            $mes = substr($c['data_original'], 0, 7);
            $pares[$c['cust_id'] . '|' . $mes] = ['cust_id' => $c['cust_id'], 'mes' => $mes];
        }

        $this->sincronizarPares($pares);
    }

    /**
     * Re-sincroniza as competências de um lote já revertido.
     *
     * Os pares saem da posição ATUAL das publicações do lote — depois da reversão
     * elas já estão na competência de origem, que é justamente a que precisa ser
     * reapurada.
     */
    private function resyncLote(string $lote): int
    {
        $ids = DB::table('mlb_reversao_backup')->where('lote', $lote)->pluck('publicacao_id');

        if ($ids->isEmpty()) {
            $this->error("Lote {$lote} não encontrado.");

            return self::FAILURE;
        }

        $pares = [];
        $linhas = Publicacao::whereIn('id', $ids)
            ->whereNotNull('cust_id')->where('cust_id', '!=', '')
            ->get(['cust_id', 'data']);

        foreach ($linhas as $l) {
            $mes = substr((string) $l->data, 0, 7);
            $pares[$l->cust_id . '|' . $mes] = ['cust_id' => $l->cust_id, 'mes' => $mes];
        }

        $this->info(sprintf('Lote %s: %d publicação(ões), %d combinação(ões) loja × competência.', $lote, $ids->count(), count($pares)));

        $this->sincronizarPares($pares);

        return self::SUCCESS;
    }

    private function sincronizarPares(array $pares): void
    {
        if (empty($pares)) {
            $this->warn('Nenhuma loja com Cust ID entre as revertidas — nada para sincronizar.');

            return;
        }

        $this->line('');
        $this->info(sprintf('Re-sincronizando %d combinação(ões) loja × competência...', count($pares)));
        $this->warn('Isto reseta e reapura TODAS as publicações de cada loja no mês — não só as revertidas.');
        $this->line('');

        $servico = new VendasSyncService();

        foreach ($pares as $par) {
            $inicio = Carbon::parse($par['mes'] . '-01')->startOfMonth()->toDateString();
            $fim    = Carbon::parse($par['mes'] . '-01')->endOfMonth()->toDateString();

            // Antes/depois por reconsulta ao banco: a saída do serviço diz quantas
            // linhas ele tocou, não o efeito na competência. Sem isto a conferência
            // dependeria do stdout, que já enganou nesta casa.
            $antes = $this->totaisDaLoja($par['cust_id'], $inicio, $fim);

            try {
                $r = $servico->syncEmpresa($par['cust_id'], $inicio, $fim);
                $depois = $this->totaisDaLoja($par['cust_id'], $inicio, $fim);
                $this->line(sprintf(
                    '  %-12s %s → %3d item(ns), %2d com venda | vendas %d→%d | R$ %s → R$ %s',
                    $par['cust_id'], $par['mes'], $r['itens'], $r['com_venda'],
                    $antes['vendas'], $depois['vendas'],
                    number_format($antes['net'], 2, ',', '.'),
                    number_format($depois['net'], 2, ',', '.')
                ));
            } catch (\Throwable $e) {
                $this->error("  {$par['cust_id']} {$par['mes']} → falhou: " . $e->getMessage());
                Log::error("[MLB Reversão] Re-sync {$par['cust_id']} {$par['mes']}: " . $e->getMessage());
            }

            // Throttle da Adman (ADMAN_RATE_LIMIT_RPM = 10) — mesmo intervalo dos
            // demais call-sites.
            usleep(7_000_000);
        }
    }

    /** @return array{vendas:int, net:float} */
    private function totaisDaLoja(string $custId, string $inicio, string $fim): array
    {
        $r = Publicacao::where('cust_id', $custId)
            ->whereBetween('data', [$inicio, $fim])
            ->selectRaw('SUM(vendido) v, SUM(COALESCE(net_billing, 0)) net')
            ->first();

        return ['vendas' => (int) ($r->v ?? 0), 'net' => (float) ($r->net ?? 0)];
    }

    // =========================================================================
    // Desfazer
    // =========================================================================

    private function desfazer(string $lote): int
    {
        $registros = DB::table('mlb_reversao_backup')->where('lote', $lote)->get();

        if ($registros->isEmpty()) {
            $this->error("Lote {$lote} não encontrado.");

            return self::FAILURE;
        }

        if (! $this->option('apply')) {
            $this->info(sprintf('Lote %s: %d publicação(ões) seriam restauradas ao estado anterior.', $lote, $registros->count()));
            $this->warn('DRY-RUN — rode com --apply para restaurar.');

            return self::SUCCESS;
        }

        $n = DB::transaction(function () use ($registros) {
            $n = 0;
            foreach ($registros as $r) {
                $n += Publicacao::where('id', $r->publicacao_id)->update([
                    'data'           => $r->data_anterior,
                    'cust_id'        => $r->cust_id_anterior,
                    'vendido'        => (bool) $r->vendido_anterior,
                    'vendas_qty'     => (int) $r->vendas_qty_anterior,
                    'net_billing'    => $r->net_billing_anterior,
                    'preco_unitario' => $r->preco_unitario_anterior,
                ]);
            }

            return $n;
        });

        $this->info("{$n} publicação(ões) restaurada(s) ao estado anterior à reversão.");

        activity('mlb')
            ->withProperties(['lote' => $lote, 'publicacoes' => $n])
            ->log("Reversão de competência DESFEITA — lote {$lote}");

        return self::SUCCESS;
    }
}
