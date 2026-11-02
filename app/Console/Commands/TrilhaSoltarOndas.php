<?php

namespace App\Console\Commands;

use App\Services\Trilha\Ondas\SoltarOndas;
use Illuminate\Console\Command;

/**
 * Trilha de produtos (2ª entrega, 02/11/2026): a data da onda solta o robô. Agendado em `routes/console.php` aos
 * minutos 7, 22, 37 e 52 (a onda do dia D começa às 00h07 de São Paulo), só quando há empresa na trilha de
 * trabalho. Sem `--aplicar`, só lista o que faria — nada é gravado e nenhum Job sai.
 *
 * Resolve na mão o agendador parado: `php artisan trilha:soltar-ondas --aplicar`.
 */
class TrilhaSoltarOndas extends Command
{
    protected $signature = 'trilha:soltar-ondas
        {--aplicar : grava e pede o robô (sem a opção, só mostra o que faria)}
        {--empresa= : só esta empresa (companies.id)}';

    protected $description = 'Solta as ondas da Trilha de produtos cujo dia chegou e pede o robô para as ofertas delas';

    public function handle(SoltarOndas $soltura): int
    {
        $empresa = $this->option('empresa');
        if ($empresa !== null && (! ctype_digit((string) $empresa) || (int) $empresa <= 0)) {
            $this->error('--empresa precisa ser um id de empresa.');

            return self::INVALID;
        }
        $aplicar = (bool) $this->option('aplicar');

        $resumos = $soltura->rodar($empresa !== null ? (int) $empresa : null, $aplicar);
        if ($resumos === []) {
            $this->line('Nenhuma empresa na trilha de trabalho (TRILHA_TRABALHO_EMPRESAS).');

            return self::SUCCESS;
        }

        foreach ($resumos as $r) {
            if (isset($r['erro'])) {
                $this->error("Empresa {$r['empresa']}: {$r['erro']}");

                continue;
            }
            $nada = $r['soltas'] === [] && $r['pedidos'] === [] && $r['repescados'] === [] && $r['combos'] === [] && $r['orfaos'] === [];
            if ($nada && $aplicar) {
                continue;
            }
            $this->line(($aplicar ? '' : '[só leitura] ').now('America/Sao_Paulo')->format('d/m H:i')." · empresa {$r['empresa']}: "
                .count($r['soltas']).' onda(s) solta(s)'.($r['soltas'] !== [] ? ' ('.implode(', ', $r['soltas']).')' : '')
                .' · '.count($r['pedidos']).' pedido(s) · '.count($r['repescados']).' repescado(s)'
                .' · '.$r['produtos'].' produto(s) · '.count($r['combos']).' combo(s) conferido(s) · '.count($r['orfaos']).' órfã(s).');
        }

        return self::SUCCESS;
    }
}
