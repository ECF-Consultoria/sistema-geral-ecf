<?php

namespace App\Console\Commands;

use App\Services\Publicador\Jardinagem\JardinagemService;
use Illuminate\Console\Command;

/**
 * Jardinagem mensal dos anúncios do Publicador (23/10/2026), pela árvore do MAG — ver `JardinagemService`.
 *
 * Três modos, do mais seguro ao que grava:
 * - **sem opção (simulação):** só conta quem fechou o mês e quem ainda está em observação. Nada vai ao ML.
 * - **`--ler`:** lê no ML (só GET) os anúncios devidos e mostra o diagnóstico de cada um. Não grava nada —
 *   é o modo de conferir a régua numa conta de verdade (com `--dias=7`, por exemplo, sem esperar o mês).
 * - **`--aplicar`:** lê, grava o mês de cada anúncio e pede o título novo dos sem tração (fila da IA).
 *
 * A rodada diária (`routes/console.php`) roda com `--aplicar`: cada anúncio entra no dia em que o seu mês
 * fecha. `--dias` só vale à mão (a rodada usa `publicador.jardinagem.dias_do_ciclo`); com `--aplicar` ele
 * grava um mês mais curto de verdade — use `--ler` para testar.
 */
class PublicadorJardinagem extends Command
{
    protected $signature = 'publicador:jardinagem
        {--conta= : só esta conta, pela chave do Publicador (company-459, empresa-12)}
        {--dias= : tamanho do mês em dias (padrão: publicador.jardinagem.dias_do_ciclo)}
        {--teto= : no máximo N anúncios nesta rodada (padrão: publicador.jardinagem.teto)}
        {--ler : lê no Mercado Livre e mostra o diagnóstico, sem gravar}
        {--aplicar : lê, grava o mês de cada anúncio e pede o título novo dos sem tração}';

    protected $description = 'Jardinagem mensal dos publicados pela árvore do MAG (simulação por padrão)';

    public function handle(JardinagemService $jardinagem): int
    {
        $conta = trim((string) ($this->option('conta') ?? ''));
        $dias = $this->option('dias') !== null ? max(0, (int) $this->option('dias')) : JardinagemService::diasDoCiclo();
        $teto = max(0, (int) ($this->option('teto') ?? config('publicador.jardinagem.teto', 300)));
        $aplicar = (bool) $this->option('aplicar');
        $ler = $aplicar || (bool) $this->option('ler');

        $s = $jardinagem->situacao($conta === '' ? null : $conta, $dias);
        $escolhidos = array_slice($s['devidos'], 0, $teto);
        $acimaDoTeto = count($s['devidos']) - count($escolhidos);

        $this->info(($ler ? ($aplicar ? '' : 'LEITURA — nada é gravado. ') : 'SIMULAÇÃO — nada foi lido nem gravado. ')
            ."Mês de {$dias} dias".($conta !== '' ? ", conta {$conta}" : '').':');
        $this->line('  mês fechado (a analisar): '.count($escolhidos)
            .($acimaDoTeto > 0 ? " · {$acimaDoTeto} acima do teto de {$teto} (ficam para a próxima rodada)" : ''));
        $this->line('  em observação: '.count($s['em_observacao'])." · encerrados: {$s['encerrados']}"
            ." · pausados ou em revisão: {$s['fora_do_ar']} · fora (conta não liberada ou outro vendedor): {$s['fora']}");
        foreach ($s['por_conta'] as $chave => $n) {
            $this->line("    {$chave}: {$n}");
        }

        if (! $ler) {
            if ($escolhidos !== []) {
                $this->line('Rode com --ler para ver o diagnóstico (só GET no Mercado Livre) ou --aplicar para gravar.');
            }

            return self::SUCCESS;
        }
        if ($escolhidos === []) {
            return self::SUCCESS;
        }

        $resultados = $jardinagem->analisar($escolhidos, gravar: $aplicar);
        $rotulos = $jardinagem->regua()->descricoes();
        $contagem = [];
        foreach ($resultados as $r) {
            $chave = $r['resultado'] === 'analisado' ? $r['diagnostico'] : $r['resultado'];
            $contagem[$chave] = ($contagem[$chave] ?? 0) + 1;
            if ($r['resultado'] === 'analisado') {
                $conversao = $r['conversao'] === null ? '—' : number_format($r['conversao'], 2, ',', '.').'%';
                $this->line("  {$r['ml_item_id']} (mês {$r['ciclo']}): {$r['visitas']} visitas, {$r['vendas']} vendas, conversão {$conversao}"
                    ." → {$rotulos[$r['diagnostico']]['rotulo']} ({$rotulos[$r['diagnostico']]['acao']})");
            } else {
                $this->warn("  {$r['ml_item_id']}: ".($r['erro'] ?? $r['resultado']));
            }
        }
        $resumo = [];
        foreach ($contagem as $chave => $n) {
            $resumo[] = ($rotulos[$chave]['rotulo'] ?? match ($chave) {
                'erro' => 'não lidos',
                'fora_do_ar' => 'fora do ar',
                default => $chave,
            }).": {$n}";
        }
        $this->info(($aplicar ? 'Gravado. ' : '').implode(' · ', $resumo));

        return self::SUCCESS;
    }
}
