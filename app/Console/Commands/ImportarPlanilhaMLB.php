<?php

namespace App\Console\Commands;

use App\Models\MlbEmpresa;
use App\Models\Publicacao;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ImportarPlanilhaMLB extends Command
{
    protected $signature   = 'mlb:importar-planilha';
    protected $description = 'Importa publicações da planilha de controle (JSON pré-gerado)';

    // user_id por nome de aba
    private const USER_IDS = [
        'Kaio'  => 6,
        'Ruben' => 4,
        'Bruno' => 5,
        'Maycon'=> 7,
    ];

    public function handle(): int
    {
        $jsonPath = storage_path('app/import_mlb.json');
        if (!file_exists($jsonPath)) {
            $this->error("Arquivo não encontrado: {$jsonPath}");
            return 1;
        }

        $dados = json_decode(file_get_contents($jsonPath), true);

        // ── 1. Apaga publicações de teste (Maycon do dia 05/04 e 04/05) ──────
        $this->info('Removendo publicações de teste do Maycon…');
        $deletados = Publicacao::where('user_id', 7)
            ->where('data', '2026-05-04')
            ->delete();
        $this->line("  → {$deletados} publicações removidas.");

        // ── 2. Importação ──────────────────────────────────────────────────────
        $totalInseridos  = 0;
        $totalIgnorados  = 0;
        $totalEmpresas   = 0;
        $mlbsExistentes  = Publicacao::pluck('mlb_code')->flip()->toArray();

        foreach ($dados as $nomeUser => $blocos) {
            $userId = self::USER_IDS[$nomeUser] ?? null;
            if (!$userId) {
                $this->warn("Usuário '{$nomeUser}' não mapeado — ignorado.");
                continue;
            }

            $this->info("\n=== {$nomeUser} (user_id={$userId}) ===");

            foreach ($blocos as $bloco) {
                $mes = $bloco['mes'];
                $ano = $bloco['ano'];
                $label = "{$ano}-" . str_pad($mes, 2, '0', STR_PAD_LEFT);

                $diasUteis = $this->diasUteis($ano, $mes);
                if (empty($diasUteis)) {
                    $this->warn("  Sem dias úteis para {$label}");
                    continue;
                }

                $allMlbs = [];
                foreach ($bloco['empresas'] as $emp) {
                    $allMlbs[] = ['empresa' => $emp, 'mlbs' => $emp['mlbs']];
                }

                // Coleta todos os MLBs do mês (planos) para distribuir
                $planosDoMes = [];
                foreach ($allMlbs as $item) {
                    $emp    = $item['empresa'];
                    $empObj = $this->getOuCriaEmpresa($emp['nome'], $emp['cust'], $userId);
                    $totalEmpresas++;
                    foreach ($item['mlbs'] as $mlb) {
                        $planosDoMes[] = [
                            'mlb_code'       => $mlb,
                            'empresa'        => $emp['nome'],
                            'cust_id'        => $emp['cust'] ?? '',
                            'mlb_empresa_id' => $empObj?->id,
                        ];
                    }
                }

                // Distribui pelos dias úteis
                $totalDias    = count($diasUteis);
                $totalMlbsMes = count($planosDoMes);
                $rows         = [];
                $now          = now();

                foreach ($planosDoMes as $i => $plano) {
                    $mlb = $plano['mlb_code'];

                    if (isset($mlbsExistentes[$mlb])) {
                        $totalIgnorados++;
                        continue;
                    }

                    // Distribui ciclicamente pelos dias úteis
                    $dia = $diasUteis[$i % $totalDias];

                    $rows[] = [
                        'data'           => $dia,
                        'user_id'        => $userId,
                        'empresa'        => $plano['empresa'],
                        'cust_id'        => $plano['cust_id'],
                        'mlb_code'       => $mlb,
                        'mlb_empresa_id' => $plano['mlb_empresa_id'],
                        'vendido'        => false,
                        'vendas_qty'     => 0,
                        'revisado'       => false,
                        'created_at'     => $now,
                        'updated_at'     => $now,
                    ];

                    $mlbsExistentes[$mlb] = true;
                }

                // Insere em lotes
                foreach (array_chunk($rows, 200) as $chunk) {
                    Publicacao::insert($chunk);
                    $totalInseridos += count($chunk);
                }

                $this->line("  {$label}: {$totalMlbsMes} MLBs → {$totalDias} dias úteis → " . count($rows) . " inseridos");
            }
        }

        $this->newLine();
        $this->info("✓ Importação concluída!");
        $this->table(['Métrica', 'Total'], [
            ['MLBs inseridos',  $totalInseridos],
            ['MLBs ignorados (duplicata)', $totalIgnorados],
            ['Empresas acessadas/criadas', $totalEmpresas],
        ]);

        return 0;
    }

    /** Retorna ou cria uma MlbEmpresa com nome + cust_id + responsável. */
    private function getOuCriaEmpresa(string $nome, ?string $custId, int $responsavelId): ?MlbEmpresa
    {
        if ($custId) {
            $empresa = MlbEmpresa::where('cust_id', $custId)->first();
            if ($empresa) {
                if (!$empresa->responsavel_id) {
                    $empresa->update(['responsavel_id' => $responsavelId]);
                }
                return $empresa;
            }
        }

        // Busca pelo nome (normalizado) se não achou pelo cust_id
        $empresa = MlbEmpresa::whereRaw('LOWER(nome) = ?', [strtolower($nome)])->first();
        if ($empresa) {
            if ($custId && !$empresa->cust_id) {
                $empresa->update(['cust_id' => $custId]);
            }
            if (!$empresa->responsavel_id) {
                $empresa->update(['responsavel_id' => $responsavelId]);
            }
            return $empresa;
        }

        // Cria nova
        return MlbEmpresa::create([
            'nome'           => $nome,
            'cust_id'        => $custId,
            'responsavel_id' => $responsavelId,
            'tipo'           => 'POLO',
            'estagio'        => 'Não Listado',
            'criado_por'     => 1, // admin
        ]);
    }

    /** Retorna todos os dias úteis (seg–sex) de um mês. */
    private function diasUteis(int $ano, int $mes): array
    {
        $inicio  = Carbon::create($ano, $mes, 1)->startOfDay();
        $fim     = $inicio->copy()->endOfMonth();
        $dias    = [];
        $current = $inicio->copy();

        while ($current->lte($fim)) {
            if ($current->isWeekday()) {
                $dias[] = $current->toDateString();
            }
            $current->addDay();
        }

        return $dias;
    }
}
