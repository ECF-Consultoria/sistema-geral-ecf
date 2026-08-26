<?php

namespace App\Console\Commands;

use App\Models\MlbEmpresa;
use App\Models\MlbImplementacao;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Reconcilia a planilha de Polos com o cadastro ANTES de rodar `polos:sync-planilha --apply`.
 *
 * POR QUE ISTO EXISTE: o match do sync é cust_id-first e NÃO cai para nome quando a linha
 * TEM cust_id — se o cust não casa, ele CRIA. Mas o cadastro nasce em duas portas: a sync de
 * Entrantes cria registros SEM cust_id (fase "Aceite no Projeto"/M0), e quando a planilha
 * depois traz o mesmo seller já COM cust_id, o sync não acha o registro name-only e duplica.
 * Em 2026-07-31 isso seriam 38 duplicatas; reconciliar antes converteu as 38 em updates
 * in-place, preservando as fichas que os clientes já tinham preenchido (`dados`,
 * `ultimo_acesso`). E o `--arquivar-ausentes` NÃO pega essas duplicatas: o nome do registro
 * antigo casa com alguma linha da planilha.
 *
 * O QUE ELE FAZ: para cada linha cujo cust_id não casa com ninguém, procura o registro antigo
 * por (a) token do "Link da Planilha" — a chave mais confiável, aponta para o registro exato —
 * e, na falta, (b) nome normalizado + gmail colaborador idêntico. Achando, grava o cust_id no
 * registro ANTIGO. Depois disso o sync casa por cust_id e faz update em vez de criar.
 *
 * Dry-run por padrão. Escopo rígido projeto=POLOS — nunca toca MLB de publicador.
 */
class ReconciliarPolosPlanilha extends Command
{
    protected $signature = 'polos:reconciliar-planilha
        {--file= : Caminho do CSV/XLSX exportado da planilha}
        {--sheet=Dash Gerencial Polos V2 : Nome da aba (usado só p/ arquivos XLSX)}
        {--apply : Grava de fato (padrão é dry-run/preview)}
        {--trocar-cust : Também REPONTA cust_id divergente quando o token casa (troca de conta ML)}';

    protected $description = 'Casa a planilha de Polos com o cadastro por token/nome e faz backfill de cust_id — evita que o sync duplique (dry-run por padrão)';

    /** @var array<int,array{linha:int,nome:string,cust:string,via:string,id:int,nome_antigo:string,fase:string}> */
    private array $backfills = [];
    /** @var array<int,array{linha:int,nome:string,cust:string,id:int,cust_atual:string,nome_prod:string}> */
    private array $divergencias = [];
    /** @var array<int,string> */
    private array $ambiguos = [];
    /** @var array<int,string> */
    private array $criacoes = [];

    public function handle(): int
    {
        $file = (string) $this->option('file');
        if (! is_file($file)) {
            $this->error("Arquivo não encontrado: {$file}. Use --file=caminho.csv|.xlsx");

            return self::FAILURE;
        }
        $apply = (bool) $this->option('apply');

        $this->info('╔══════════════════════════════════════════════════════════╗');
        $this->info('║  Reconciliação Polos  ·  '.($apply ? 'APLICANDO (grava!)' : 'DRY-RUN (preview)').str_repeat(' ', $apply ? 12 : 15).'║');
        $this->info('╚══════════════════════════════════════════════════════════╝');
        $this->line("Arquivo: {$file}");

        [$header, $rows] = $this->ler($file);
        $idx = $this->indexar($header);
        foreach (['Loja', 'Cust ID'] as $req) {
            if (! isset($idx[$req])) {
                $this->error("Coluna obrigatória ausente: '{$req}'");

                return self::FAILURE;
            }
        }

        // Tokens repetidos em 2+ linhas da planilha são AMBÍGUOS: o time cola o mesmo
        // "Link da Planilha" em lojas diferentes. Backfill por token aí escolheria o
        // registro errado — então esses tokens são excluídos do match.
        $ocorrenciasToken = [];
        foreach ($rows as $r) {
            $tok = $this->token((string) ($r[$idx['Link da Planilha'] ?? -1] ?? ''));
            if ($tok !== null) {
                $ocorrenciasToken[$tok] = ($ocorrenciasToken[$tok] ?? 0) + 1;
            }
        }

        $run = function () use ($rows, $idx, $ocorrenciasToken, $apply) {
            foreach ($rows as $n => $r) {
                $this->processar($r, $idx, $ocorrenciasToken, $apply, $n + 2);
            }
        };

        $apply ? DB::transaction($run) : $run();

        $this->relatorio($apply);

        return self::SUCCESS;
    }

    private function processar(array $r, array $idx, array $ocorrenciasToken, bool $apply, int $linha): void
    {
        $get = fn (string $c) => isset($idx[$c]) ? trim((string) ($r[$idx[$c]] ?? '')) : '';

        $nome = $get('Loja');
        if ($nome === '') {
            return;
        }
        $cust = $get('Cust ID');
        if (! preg_match('/^\d{5,}$/', $cust)) {
            return; // linha sem cust: o sync já casa por nome, não há o que reconciliar
        }

        // Já casa por cust_id → o sync fará update. Nada a fazer.
        if ($this->empresaPorCust($cust) !== null) {
            return;
        }

        $tok = $this->token($get('Link da Planilha'));
        $alvo = null;
        $via  = '';

        if ($tok !== null && ($ocorrenciasToken[$tok] ?? 0) === 1) {
            $ficha = MlbImplementacao::where('token', $tok)->first();
            $cand  = $ficha?->empresa;
            if ($cand && $this->ehPolos($cand)) {
                $alvo = $cand;
                $via  = 'token';
            }
        } elseif ($tok !== null) {
            $this->ambiguos[] = "L{$linha} {$nome} (cust {$cust}) — token repetido em {$ocorrenciasToken[$tok]} linhas da planilha";
        }

        // Fallback: nome normalizado idêntico + gmail colaborador idêntico.
        if ($alvo === null) {
            $gmail = mb_strtolower($get('gmail colaborador'));
            $alvo = MlbEmpresa::with('implementacao')->get()
                ->first(fn ($e) => $this->ehPolos($e)
                    && $this->normNome($e->nome) === $this->normNome($nome)
                    && $gmail !== ''
                    && mb_strtolower((string) $e->implementacao?->gmail_colaborador) === $gmail);
            if ($alvo) {
                $via = 'nome+gmail';
            }
        }

        if ($alvo === null) {
            $this->criacoes[] = "L{$linha} {$nome} (cust {$cust})";

            return;
        }

        $custAtual = trim((string) $alvo->cust_id);

        // Registro antigo JÁ tem cust e é OUTRO: troca de conta ML. Repontar é mais arriscado
        // que preencher vazio (re-aponta faturamento/snapshots), então só sob --trocar-cust.
        if (preg_match('/^\d{5,}$/', $custAtual) && $custAtual !== $cust) {
            $this->divergencias[] = [
                'linha' => $linha, 'nome' => $nome, 'cust' => $cust,
                'id' => $alvo->id, 'cust_atual' => $custAtual, 'nome_prod' => $alvo->nome,
            ];
            if (! $this->option('trocar-cust')) {
                return;
            }
        }

        // Guarda: o cust da planilha não pode já pertencer a OUTRO registro.
        if ($this->empresaPorCust($cust) !== null) {
            $this->ambiguos[] = "L{$linha} {$nome} — cust {$cust} já pertence a outro registro";

            return;
        }

        $this->backfills[] = [
            'linha' => $linha, 'nome' => $nome, 'cust' => $cust, 'via' => $via,
            'id' => $alvo->id, 'nome_antigo' => $alvo->nome, 'fase' => (string) $alvo->fase,
        ];

        if ($apply) {
            $alvo->cust_id = $cust;
            // Alinha o nome ao da planilha (a planilha é a fonte de verdade do cadastro);
            // o sync faria isso em seguida de qualquer forma.
            $alvo->nome = $nome;
            $alvo->save();
        }
    }

    private function empresaPorCust(string $cust): ?MlbEmpresa
    {
        return MlbEmpresa::where('cust_id', $cust)->get()->first(fn ($e) => $this->ehPolos($e));
    }

    private function ehPolos(MlbEmpresa $e): bool
    {
        return (($e->getAttributes()['projeto'] ?? null)
            ?: (MlbEmpresa::FASE_PARA_PROJETO[$e->fase ?? ''] ?? null)) === 'POLOS';
    }

    /** Extrai o token de /implementacao/{token}; devolve null se a célula não for uma URL da ficha. */
    private function token(string $link): ?string
    {
        return preg_match('#/implementacao/([A-Za-z0-9]+)#', $link, $m) ? $m[1] : null;
    }

    private function normNome(string $n): string
    {
        $n = mb_strtoupper(trim($n));
        $n = strtr($n, ['Á'=>'A','À'=>'A','Ã'=>'A','Â'=>'A','É'=>'E','Ê'=>'E','Í'=>'I','Ó'=>'O','Ô'=>'O','Õ'=>'O','Ú'=>'U','Ç'=>'C']);

        return preg_replace('/\s+/', ' ', $n);
    }

    /** Índice de colunas: chave exata primeiro, alias normalizado como fallback. */
    private function indexar(array $header): array
    {
        $idx = [];
        foreach ($header as $i => $h) {
            $col = trim((string) $h);
            if ($col !== '') {
                $idx[$col] = $i;
            }
        }
        foreach ($header as $i => $h) {
            $col = trim((string) $h);
            if ($col !== '') {
                $idx[mb_strtolower(preg_replace('/\s+/', ' ', $col))] ??= $i;
            }
        }

        return $idx;
    }

    /**
     * Lê CSV ou XLSX devolvendo [header, rows].
     *
     * Duplica de propósito o leitor do SyncPolosPlanilha em vez de extrair um serviço
     * compartilhado: aquele comando é o que roda contra produção e já está provado em
     * quatro syncs — refatorá-lo para acomodar este aqui trocaria risco por elegância.
     */
    private function ler(string $file): array
    {
        if (! in_array(strtolower(pathinfo($file, PATHINFO_EXTENSION)), ['xlsx', 'xls', 'xlsm'], true)) {
            $fh = fopen($file, 'r');
            $header = fgetcsv($fh);
            $rows = [];
            while (($r = fgetcsv($fh)) !== false) {
                if (count(array_filter($r, fn ($x) => trim((string) $x) !== ''))) {
                    $rows[] = $r;
                }
            }
            fclose($fh);

            return [$header, $rows];
        }

        $sheetName = (string) $this->option('sheet');
        $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($file);
        $reader->setReadDataOnly(true);
        $reader->setLoadSheetsOnly([$sheetName]);
        $sheet = $reader->load($file)->getSheetByName($sheetName);
        if (! $sheet) {
            throw new \RuntimeException("Aba '{$sheetName}' não encontrada no XLSX.");
        }

        $maxRow = $sheet->getHighestDataRow();
        $maxCol = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($sheet->getHighestDataColumn());
        $matrix = [];
        for ($r = 1; $r <= $maxRow; $r++) {
            $row = [];
            for ($c = 1; $c <= $maxCol; $c++) {
                // Valores em CACHE: a planilha vem do Google Sheets cheia de fórmulas e
                // recalcular quebra (Formula Error).
                $cell = $sheet->getCell(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($c).$r);
                $row[] = $cell->getDataType() === \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_FORMULA
                    ? $cell->getOldCalculatedValue()
                    : $cell->getValue();
            }
            $matrix[] = $row;
        }
        $header = array_shift($matrix) ?? [];
        $rows = array_values(array_filter(
            $matrix,
            fn ($r) => count(array_filter($r, fn ($x) => trim((string) $x) !== '')) > 0,
        ));

        return [$header, $rows];
    }

    private function relatorio(bool $apply): void
    {
        $this->newLine();
        $this->info('─── RESUMO '.($apply ? '(APLICADO)' : '(DRY-RUN)').' ───');
        $this->table(['Situação', 'Qtd'], [
            [$apply ? 'cust_id GRAVADO no registro antigo' : 'cust_id a gravar (evita duplicata)', count($this->backfills)],
            ['Divergências de cust (troca de conta ML)', count($this->divergencias)],
            ['Ambíguos (pulados)', count($this->ambiguos)],
            ['Criações genuínas (o sync vai criar)', count($this->criacoes)],
        ]);

        if ($this->backfills) {
            $this->line("\n".($apply ? 'Backfill aplicado:' : 'Backfill a aplicar:'));
            foreach ($this->backfills as $b) {
                $this->line(sprintf('   L%-5d %-36s cust %-12s via %-11s → id=%-5d %s (fase %s)',
                    $b['linha'], mb_substr($b['nome'], 0, 36), $b['cust'], $b['via'], $b['id'],
                    mb_substr($b['nome_antigo'], 0, 30), $b['fase'] ?: '?'));
            }
        }

        if ($this->divergencias) {
            $this->warn("\n⚠ Token casa, mas o cust_id DIVERGE (seller trocou de conta ML) — decisão humana:");
            foreach ($this->divergencias as $d) {
                $this->line(sprintf('   L%-5d %-32s planilha=%-12s → id=%-5d %-28s atual=%s',
                    $d['linha'], mb_substr($d['nome'], 0, 32), $d['cust'], $d['id'],
                    mb_substr($d['nome_prod'], 0, 28), $d['cust_atual']));
            }
            if (! $this->option('trocar-cust')) {
                $this->comment('   (não repontados — passe --trocar-cust para repontar; sem isso o sync CRIA um registro novo)');
            }
        }

        if ($this->ambiguos) {
            $this->warn("\n⚠ Ambíguos — PULADOS, exigem correção na planilha:");
            foreach ($this->ambiguos as $a) {
                $this->line("   · {$a}");
            }
        }

        if ($this->criacoes) {
            $this->line("\nCriações genuínas (nenhum registro antigo casou — o sync vai criar):");
            foreach (array_slice($this->criacoes, 0, 40) as $c) {
                $this->line("   + {$c}");
            }
            if (count($this->criacoes) > 40) {
                $this->line('   ... +'.(count($this->criacoes) - 40).' outras');
            }
        }

        $this->newLine();
        $this->comment($apply
            ? '✔ Reconciliado. Agora rode: polos:sync-planilha --file=... (dry-run deve mostrar ~0 criações inesperadas)'
            : 'Preview apenas. Para gravar: rode de novo com --apply');
    }
}
