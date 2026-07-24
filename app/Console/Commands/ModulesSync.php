<?php

namespace App\Console\Commands;

use App\Models\Module;
use App\Support\Modules;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Hidrata a tabela `modules` a partir do catálogo estático `App\Support\Modules`
 * (Module Registry — Fase 97, Plano 04).
 *
 * Upsert idempotente por `key`. Dois grupos de campos com regras distintas:
 *   - ESTRUTURAIS (name, grupo, route_prefix, permission_key): sempre
 *     sincronizam do catálogo — são definidos em código, o banco nunca diverge
 *     de propósito.
 *   - CICLO DE VIDA (stage, notas, ordem): mutáveis em runtime pelo board
 *     (Fase 100). Só são gravados na CRIAÇÃO (stage nasce com o default do
 *     catálogo via `Modules::defaultStageFor()`). Em módulo já existente cujo
 *     `stage` diverge do default, o valor é PRESERVADO — o board é a fonte de
 *     verdade do estado; o catálogo só semeia. Com `--force`, `stage` é
 *     ressincronizado para o default do catálogo (T-97-08 no threat model).
 *
 * `--dry-run` não grava nada, só reporta o que faria.
 */
class ModulesSync extends Command
{
    protected $signature = 'modules:sync
        {--force : Sobrescreve stage já divergente do catálogo (por padrão, estado de runtime é preservado)}
        {--dry-run : Mostra o que seria feito sem gravar no banco}';

    protected $description = 'Sincroniza o catálogo App\\Support\\Modules com a tabela modules (upsert idempotente por key)';

    public function handle(): int
    {
        $force  = (bool) $this->option('force');
        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->warn('[dry-run] Nenhuma alteração será gravada.');
        }

        $criados = $atualizados = $inalterados = 0;

        foreach ($this->itensDoCatalogo() as $item) {
            $key = $item['key'];

            try {
                $estruturais = [
                    'name'           => $item['name'],
                    'grupo'          => $item['grupo'],
                    'route_prefix'   => $item['route_prefix'],
                    'permission_key' => $item['permission_key'],
                ];

                $existente = Module::where('key', $key)->first();

                if (! $existente) {
                    if (! $dryRun) {
                        Module::create(array_merge($estruturais, [
                            'key'   => $key,
                            'stage' => Modules::defaultStageFor($key),
                        ]));
                    }
                    $criados++;
                    continue;
                }

                $mudouEstrutural = false;
                foreach ($estruturais as $campo => $valor) {
                    if ($existente->{$campo} !== $valor) {
                        $mudouEstrutural = true;
                        break;
                    }
                }

                // Regra de ciclo de vida (T-97-08): stage só ressincroniza com --force,
                // e só se realmente divergir do default — o board é a fonte de verdade.
                $novoStage  = $existente->stage;
                $mudouStage = false;
                if ($force) {
                    $defaultStage = Modules::defaultStageFor($key);
                    if ($existente->stage !== $defaultStage) {
                        $novoStage  = $defaultStage;
                        $mudouStage = true;
                    }
                }

                if (! $mudouEstrutural && ! $mudouStage) {
                    $inalterados++;
                    continue;
                }

                if (! $dryRun) {
                    $atributos = $estruturais;
                    if ($mudouStage) {
                        $atributos['stage'] = $novoStage;
                    }
                    $existente->update($atributos);
                }
                $atualizados++;
            } catch (\Throwable $e) {
                Log::error('[Modules] falha ao sincronizar módulo', [
                    'key'   => $key,
                    'erro'  => $e->getMessage(),
                ]);
            }
        }

        $this->table(
            ['Criados', 'Atualizados', 'Inalterados'],
            [[$criados, $atualizados, $inalterados]]
        );

        Log::info('[Modules] sync concluído', [
            'criados'     => $criados,
            'atualizados' => $atualizados,
            'inalterados' => $inalterados,
            'force'       => $force,
            'dry_run'     => $dryRun,
        ]);

        return self::SUCCESS;
    }

    /**
     * Achata `App\Support\Modules::catalog()` (agrupado por grupo) numa lista
     * plana de itens `key/name/grupo/route_prefix/permission_key/stage`.
     *
     * @return array<int, array{key: string, name: string, grupo: string, route_prefix: ?string, permission_key: ?string, stage: string}>
     */
    private function itensDoCatalogo(): array
    {
        $itens = [];
        foreach (Modules::catalog() as $grupoItens) {
            foreach ($grupoItens as $item) {
                $itens[] = $item;
            }
        }
        return $itens;
    }
}
