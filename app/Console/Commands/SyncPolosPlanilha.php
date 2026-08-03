<?php

namespace App\Console\Commands;

use App\Models\MlbEmpresa;
use App\Models\MlbImplementacao;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Sincroniza o cadastro de Polos (mlb_empresas + mlb_implementacoes) a partir do
 * CSV exportado da planilha "Dash Gerencial Polos". Casa por cust_id (numérico);
 * na ausência, por nome. ESCOPO RÍGIDO: só toca registros projeto=POLOS — nunca
 * mexe em MLBs de publicadores (projeto=Assessoria/outros).
 *
 * Dry-run por padrão. Use --apply para gravar (dentro de transação).
 */
class SyncPolosPlanilha extends Command
{
    protected $signature = 'polos:sync-planilha
        {--file= : Caminho do CSV/XLSX exportado da planilha}
        {--sheet=Dash Gerencial Polos V2 : Nome da aba (usado só p/ arquivos XLSX)}
        {--apply : Grava de fato (padrão é dry-run/preview)}
        {--limit=0 : Processa só as N primeiras linhas (0 = todas)}
        {--arquivar-ausentes : Arquiva empresas POLOS que não estão na planilha (some do painel; reversível)}';

    protected $description = 'Sincroniza mlb_empresas/mlb_implementacoes a partir da planilha de Polos — CSV ou XLSX (dry-run por padrão)';

    // ─── Mapeamentos confirmados com o usuário ───────────────────────────────
    private const FASE_MAP = [
        'M0' => 'M0', 'M1' => 'M1', 'M2' => 'M2', 'M3' => 'M3', 'M4' => 'M4',
        // "Aceite no Projeto" = empresa aceita, ainda em entrada. Preservada como fase PRÓPRIA
        // (pré-M0) para espelhar a planilha — antes era fundida em M0 e sumia o número de aceites.
        'ACEITE NO PROJETO' => 'Aceite no Projeto',
        'CHRUN' => 'Churn', 'CHURN' => 'Churn', 'PROTOCOLO CHURN' => 'Churn',
        'DESISTÊNCIA' => 'Churn', 'DESISTENCIA' => 'Churn', 'ENCERRADO' => 'Encerrado',
    ];

    // Polo: rename Bento Gonçalves → Serra Gaúcha (confirmado). Demais 1:1.
    private const POLO_MAP = [
        'BENTO GONÇALVES' => 'Serra Gaúcha', 'BENTO GONCALVES' => 'Serra Gaúcha',
        'SERRA GAÚCHA' => 'Serra Gaúcha', 'SERRA GAUCHA' => 'Serra Gaúcha',
        'ARAPONGAS' => 'Arapongas',
        'S. J. RIO PRETO' => 'S. J. Rio Preto', 'SÃO JOSÉ DO RIO PRETO' => 'S. J. Rio Preto',
        'SÃO BENTO DO SUL' => 'São Bento do Sul', 'SAO BENTO DO SUL' => 'São Bento do Sul',
    ];

    // "Publicação" da planilha → estagio (mlb_empresas). Confirmado.
    private const ESTAGIO_MAP = [
        'NÃO' => 'Não Listado', 'NAO' => 'Não Listado', 'NÃO LISTADO' => 'Não Listado',
        'CONCLUIDO' => 'Concluido', 'CONCLUÍDO' => 'Concluido',
        'ESTAGIO 1' => 'Estágio 1', 'ESTÁGIO 1' => 'Estágio 1', '1º ANÚNCIO' => 'Estágio 1', '1O ANUNCIO' => 'Estágio 1',
        'ESTAGIO 2' => 'Estágio 2', 'ESTÁGIO 2' => 'Estágio 2',
        'ESTAGIO 3' => 'Estágio 3', 'ESTÁGIO 3' => 'Estágio 3',
    ];

    // Colunas de texto copiadas FIEL para mlb_implementacoes (verbatim, trim).
    private const TEXT_IMPL = [
        'Acesso Colaborador'  => 'acesso_colaborador',
        'gmail colaborador'   => 'gmail_colaborador',
        'Planilha Produtos'   => 'planilha_produtos',
        'Listagem'            => 'listagem',
        'Publicação'          => 'publicacao',
        'Contextos logistica' => 'contextos_logistica',
        'ME1'                 => 'me1',
        'Integradora'         => 'integradora',
        'Places'              => 'places',
        'ERP'                 => 'erp',
        // ── Colunas novas da V2 (confirmadas com o usuário) ──
        'status de entrada'     => 'status_entrada',
        'chance de entrada'     => 'chance_entrada',
        'Reunião de onboarding' => 'reuniao_onboarding',
    ];

    // Colunas booleanas (Sim=true / Não=false / vazio=não mexe).
    // "Decola" saiu daqui em 2026-08-03: virou texto (Sim/Não/Mensagem Enviada/valor criado)
    // e é normalizada por normDecola() para casar com ONB_DECOLA_OPCOES.
    private const BOOL_IMPL = [
        'Grupo'          => 'grupo_whatsapp',
        'Campanha Criada'=> 'campanha_criada',
    ];

    private array $rel = [
        'update_empresa' => 0, 'create_empresa' => 0, 'update_ficha' => 0, 'create_ficha' => 0,
        'skip_publicador' => 0, 'skip_erro' => 0, 'linhas' => 0, 'arquivadas' => 0,
    ];
    private array $skipPublicador = [];
    private array $fasesDesconhecidas = [];
    private array $polosDesconhecidos = [];
    private array $estagiosDesconhecidos = [];
    private array $criadas = [];
    private array $campoChanges = [];

    // Presença na planilha (p/ o arquivamento de ausentes): custs numéricos e nomes normalizados.
    private array $v2Custs = [];
    private array $v2Nomes = [];
    private array $aArquivar = []; // amostra p/ o relatório

    public function handle(): int
    {
        $file = $this->option('file') ?: base_path('../polos_planilha.csv');
        if (! is_file($file)) {
            $this->error("Arquivo não encontrado: {$file}. Use --file=caminho.csv|.xlsx");
            return self::FAILURE;
        }
        $apply    = (bool) $this->option('apply');
        $limit    = (int) $this->option('limit');
        $arquivar = (bool) $this->option('arquivar-ausentes');

        $this->info('╔══════════════════════════════════════════════════════════╗');
        $this->info('║  Sync Planilha Polos  ·  '.($apply ? 'APLICANDO (grava!)' : 'DRY-RUN (preview)').str_repeat(' ', $apply ? 12 : 15).'║');
        $this->info('╚══════════════════════════════════════════════════════════╝');
        $this->line("Arquivo: {$file}");

        // CSV ou XLSX (a planilha oficial é XLSX; lê valores em cache — sem recalcular fórmulas).
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        if (in_array($ext, ['xlsx', 'xls', 'xlsm'], true)) {
            $sheet = (string) $this->option('sheet');
            $this->line("Aba XLSX: {$sheet}");
            [$header, $rows] = $this->lerXlsx($file, $sheet);
        } else {
            [$header, $rows] = $this->lerCsv($file);
        }
        // Índice de colunas: chave EXATA primeiro (mantém a precedência de sempre — a v5 da
        // planilha tinha 'Fase' e 'fase' como colunas distintas) e, num segundo passe, um alias
        // NORMALIZADO como fallback. Sem o alias, uma variação de caixa no cabeçalho derruba a
        // coluna em silêncio: a planilha traz 'Reunião de Onboarding' e o TEXT_IMPL procurava
        // 'Reunião de onboarding', então reuniao_onboarding nunca era gravado.
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
                $idx[$this->chaveCol($col)] ??= $i;
            }
        }
        foreach (['Loja', 'Cust ID', 'Fase', 'Polo'] as $req) {
            if (! isset($idx[$req]) && ! isset($idx[$this->chaveCol($req)])) {
                $this->error("Coluna obrigatória ausente no CSV: '{$req}'");
                return self::FAILURE;
            }
        }

        $run = function () use ($rows, $idx, $limit, $apply) {
            $n = 0;
            foreach ($rows as $r) {
                if ($limit > 0 && $n >= $limit) {
                    break;
                }
                $n++;
                $this->rel['linhas']++;
                $this->processarLinha($r, $idx, $apply);
            }
            // Rename de polo em registros existentes fora da planilha (Bento Gonçalves → Serra Gaúcha)
            if ($apply) {
                $migrados = MlbEmpresa::where('polo', 'Bento Gonçalves')->update(['polo' => 'Serra Gaúcha']);
                if ($migrados) {
                    $this->line("  · Rename polo em {$migrados} empresa(s) existente(s): Bento Gonçalves → Serra Gaúcha");
                }
            }
        };

        if ($apply) {
            DB::transaction($run);
        } else {
            $run();
        }

        // Arquivamento de ausentes: preview sempre; grava só com --apply --arquivar-ausentes.
        // Precisa rodar DEPOIS do $run (v2Custs/v2Nomes são preenchidos ao processar as linhas).
        $this->arquivarAusentes($apply && $arquivar);

        $this->relatorio($apply, $arquivar);

        return self::SUCCESS;
    }

    private function processarLinha(array $r, array $idx, bool $apply): void
    {
        // Resolve pelo cabeçalho exato e, se não achar, pelo alias normalizado (ver handle()).
        $get = function (string $col) use ($r, $idx) {
            $i = $idx[$col] ?? $idx[$this->chaveCol($col)] ?? null;

            return $i === null ? '' : trim((string) ($r[$i] ?? ''));
        };

        $nome   = $get('Loja');
        $custRaw = $get('Cust ID');
        if ($nome === '') {
            $this->rel['skip_erro']++;
            return;
        }
        $custNum = preg_match('/^\d{5,}$/', $custRaw) ? $custRaw : null;

        // Marca presença na planilha (base do arquivamento de ausentes).
        if ($custNum !== null) {
            $this->v2Custs[$custNum] = true;
        }
        $this->v2Nomes[$this->normNome($nome)] = true;

        // ── Match escopado a POLOS ──────────────────────────────────────────
        [$empresa, $motivoSkip] = $this->encontrarEmpresaPolos($custNum, $nome);
        if ($motivoSkip === 'publicador') {
            $this->rel['skip_publicador']++;
            $this->skipPublicador[] = "{$nome} (cust {$custNum})";
            return;
        }

        $criando = $empresa === null;
        if ($criando) {
            $empresa = new MlbEmpresa();
            $empresa->projeto = 'POLOS';
            $empresa->estagio = 'Não Listado';
        }

        // ── mlb_empresas ────────────────────────────────────────────────────
        $this->setCampo($empresa, 'nome', $nome);
        if ($custNum !== null) {
            $this->setCampo($empresa, 'cust_id', $custNum);
        }
        $faseRaw = $get('Fase');
        if ($faseRaw !== '') {
            $fase = self::FASE_MAP[mb_strtoupper($faseRaw)] ?? null;
            if ($fase === null) {
                $this->fasesDesconhecidas[$faseRaw] = ($this->fasesDesconhecidas[$faseRaw] ?? 0) + 1;
            } else {
                $this->setCampo($empresa, 'fase', $fase);
            }
        }
        $poloRaw = $get('Polo');
        if ($poloRaw !== '') {
            $polo = self::POLO_MAP[mb_strtoupper($poloRaw)] ?? null;
            if ($polo === null) {
                $this->polosDesconhecidos[$poloRaw] = ($this->polosDesconhecidos[$poloRaw] ?? 0) + 1;
            } else {
                $this->setCampo($empresa, 'polo', $polo);
            }
        }
        $pubRaw = $get('Publicação');
        if ($pubRaw !== '') {
            $est = self::ESTAGIO_MAP[mb_strtoupper($pubRaw)] ?? null;
            if ($est === null) {
                $this->estagiosDesconhecidos[$pubRaw] = ($this->estagiosDesconhecidos[$pubRaw] ?? 0) + 1;
            } else {
                $this->setCampo($empresa, 'estagio', $est);
            }
        }

        // contexto (V2 coluna "contexto") → mlb_empresas.contexto (campo já existente).
        // Só grava se vier preenchido — não apaga um contexto já cadastrado.
        $ctxRaw = $get('contexto');
        if ($ctxRaw !== '') {
            $this->setCampo($empresa, 'contexto', $ctxRaw);
        }

        if ($apply) {
            $empresa->save();
        }
        if ($criando) {
            $this->rel['create_empresa']++;
            $this->criadas[] = "{$nome} (cust ".($custNum ?? 'sem id').", fase ".($empresa->fase ?? '?').")";
        } else {
            $this->rel['update_empresa']++;
        }

        // ── mlb_implementacoes (ficha) ──────────────────────────────────────
        // Só cria/atualiza ficha se houver ao menos 1 campo de onboarding na linha.
        $fichaData = [];
        foreach (self::TEXT_IMPL as $col => $campo) {
            $v = $get($col);
            if ($v !== '') {
                $fichaData[$campo] = $v;
            }
        }
        foreach (self::BOOL_IMPL as $col => $campo) {
            $b = $this->parseBool($get($col));
            if ($b !== null) {
                $fichaData[$campo] = $b;
            }
        }
        $decola = $this->normDecola($get('Decola'));
        if ($decola !== null) {
            $fichaData['decola'] = $decola;
        }
        $dataSol = $this->parseData($get('Data de Solicitação'));
        if ($dataSol !== null) {
            $fichaData['data_solicitacao'] = $dataSol;
        }

        if (empty($fichaData)) {
            return;
        }

        // Precisa do id da empresa para vincular a ficha; em dry-run sem save, pode não ter.
        $ficha = null;
        if ($empresa->exists) {
            $ficha = MlbImplementacao::where('empresa_id', $empresa->id)->first();
        }
        $criandoFicha = $ficha === null;

        if ($apply) {
            if ($criandoFicha) {
                $ficha = new MlbImplementacao();
                $ficha->empresa_id = $empresa->id;
                $ficha->token = $this->tokenUnico();
            }
            foreach ($fichaData as $campo => $valor) {
                $ficha->{$campo} = $valor;
                $this->campoChanges[$campo] = ($this->campoChanges[$campo] ?? 0) + 1;
            }
            $ficha->save();
        } else {
            foreach ($fichaData as $campo => $valor) {
                $this->campoChanges[$campo] = ($this->campoChanges[$campo] ?? 0) + 1;
            }
        }

        $this->rel[$criandoFicha ? 'create_ficha' : 'update_ficha']++;
    }

    /** @return array{0: ?MlbEmpresa, 1: ?string} [empresa, motivoSkip] */
    private function encontrarEmpresaPolos(?string $custNum, string $nome): array
    {
        $ehPolos = fn (MlbEmpresa $e) => (($e->getAttributes()['projeto'] ?? null)
            ?: (MlbEmpresa::FASE_PARA_PROJETO[$e->fase ?? ''] ?? null)) === 'POLOS';

        if ($custNum !== null) {
            $matches = MlbEmpresa::where('cust_id', $custNum)->get();
            if ($matches->isNotEmpty()) {
                $polos = $matches->first($ehPolos);
                if ($polos) {
                    return [$polos, null];
                }
                // Existe cust_id, mas nenhum é POLOS → é registro de publicador. NÃO tocar.
                return [null, 'publicador'];
            }
            return [null, null]; // criar
        }

        // Sem cust_id numérico: casar por nome normalizado, só entre POLOS.
        $norm = $this->normNome($nome);
        $cand = MlbEmpresa::whereRaw('UPPER(nome) LIKE ?', ['%'.mb_strtoupper($nome).'%'])
            ->orWhere('nome', $nome)->get()->first(fn ($e) => $ehPolos($e) && $this->normNome($e->nome) === $norm);

        return [$cand, null];
    }

    private function setCampo(MlbEmpresa $e, string $campo, $valor): void
    {
        $atual = $e->getAttribute($campo);
        if ((string) $atual !== (string) $valor) {
            $this->campoChanges["empresa.{$campo}"] = ($this->campoChanges["empresa.{$campo}"] ?? 0) + 1;
            $e->{$campo} = $valor;
        }
    }

    private function parseBool(string $v): ?bool
    {
        $v = mb_strtoupper(trim($v));
        if ($v === '') {
            return null;
        }
        if (in_array($v, ['SIM', '1', 'TRUE', 'X', 'VERDADEIRO'], true)) {
            return true;
        }
        // Não, Solicitado, Agendada, etc. → false (não concluído)
        return false;
    }

    /**
     * Normaliza a coluna "Decola" da planilha para o catálogo ONB_DECOLA_OPCOES.
     * Vazio → null (não mexe). Valor desconhecido passa VERBATIM (trim) — a planilha
     * continua sendo a verdade e o painel exibe o valor novo como opção criada.
     */
    private function normDecola(string $v): ?string
    {
        $v = trim($v);
        if ($v === '') {
            return null;
        }
        return match (mb_strtoupper($v)) {
            'SIM', '1', 'TRUE', 'X', 'VERDADEIRO'      => 'Sim',
            'NÃO', 'NAO', '0', 'FALSE', 'FALSO'        => 'Não',
            'MENSAGEM ENVIADA', 'MSG ENVIADA'          => 'Mensagem Enviada',
            default                                    => $v,
        };
    }

    private function parseData(string $v): ?string
    {
        $v = trim($v);
        if ($v === '') {
            return null;
        }
        // XLSX entrega datas como serial do Excel (ex.: 46211). Converte se na faixa ~1982..2064.
        if (is_numeric($v)) {
            $n = (float) $v;
            if ($n > 30000 && $n < 60000) {
                try {
                    return \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($n)->format('Y-m-d');
                } catch (\Throwable) {
                }
            }
        }
        foreach (['d/m/Y', 'd/m/y', 'Y-m-d', 'd-m-Y'] as $fmt) {
            try {
                $d = Carbon::createFromFormat($fmt, $v);
                if ($d && $d->year >= 2020 && $d->year <= 2030) {
                    return $d->format('Y-m-d');
                }
            } catch (\Throwable) {
            }
        }
        return null;
    }

    /** Normaliza um cabeçalho de coluna p/ casar sem depender de caixa ou espaço extra. */
    private function chaveCol(string $h): string
    {
        return mb_strtolower(preg_replace('/\s+/', ' ', trim($h)));
    }

    private function normNome(string $n): string
    {
        $n = mb_strtoupper(trim($n));
        $n = strtr($n, ['Á'=>'A','À'=>'A','Ã'=>'A','Â'=>'A','É'=>'E','Ê'=>'E','Í'=>'I','Ó'=>'O','Ô'=>'O','Õ'=>'O','Ú'=>'U','Ç'=>'C']);
        return preg_replace('/\s+/', ' ', $n);
    }

    private function tokenUnico(): string
    {
        do {
            $t = Str::random(48);
        } while (MlbImplementacao::where('token', $t)->exists());
        return $t;
    }

    private function lerCsv(string $file): array
    {
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

    /**
     * Lê uma aba de um XLSX devolvendo [header, rows] no MESMO formato do lerCsv().
     * Usa os VALORES EM CACHE das células (getOldCalculatedValue) — a planilha vem do
     * Google Sheets cheia de fórmulas e recalcular quebra (Formula Error). Ignora linhas
     * totalmente vazias.
     *
     * @return array{0: array<int,mixed>, 1: array<int,array<int,mixed>>}
     */
    private function lerXlsx(string $file, string $sheetName): array
    {
        $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($file);
        $reader->setReadDataOnly(true);
        $reader->setLoadSheetsOnly([$sheetName]);
        $spread = $reader->load($file);
        $sheet  = $spread->getSheetByName($sheetName);
        if (! $sheet) {
            throw new \RuntimeException("Aba '{$sheetName}' não encontrada no XLSX.");
        }

        $maxRow = $sheet->getHighestDataRow();
        $maxCol = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($sheet->getHighestDataColumn());

        $matrix = [];
        for ($r = 1; $r <= $maxRow; $r++) {
            $row = [];
            for ($c = 1; $c <= $maxCol; $c++) {
                $cell = $sheet->getCell(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($c) . $r);
                $row[] = $cell->getDataType() === \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_FORMULA
                    ? $cell->getOldCalculatedValue()
                    : $cell->getValue();
            }
            $matrix[] = $row;
        }

        $header = array_shift($matrix) ?? [];
        $rows   = array_values(array_filter(
            $matrix,
            fn ($r) => count(array_filter($r, fn ($x) => trim((string) $x) !== '')) > 0,
        ));

        return [$header, $rows];
    }

    /**
     * Arquiva (ou só lista, em dry-run/sem a flag) as empresas POLOS ATIVAS que NÃO estão
     * na planilha — casadas por cust_id numérico OU nome normalizado. Reversível: só marca
     * arquivado_em (nada é apagado). NUNCA toca empresas de outros projetos.
     *
     * ATENÇÃO (de-para de contas): se um seller trocou de conta ML, o cust_id do sistema
     * pode diferir do da planilha. O fallback por NOME cobre a maioria; o resto aparece na
     * lista do relatório para revisão humana ANTES do --apply.
     */
    private function arquivarAusentes(bool $gravar): void
    {
        $ehPolos = fn ($e) => (($e->getAttributes()['projeto'] ?? null)
            ?: (MlbEmpresa::FASE_PARA_PROJETO[$e->fase ?? ''] ?? null)) === 'POLOS';

        $candidatas = MlbEmpresa::whereNull('arquivado_em')->orderBy('nome')->get()->filter($ehPolos);
        $motivo = 'Ausente na planilha V2 (' . now()->format('Y-m-d') . ')';

        foreach ($candidatas as $e) {
            $cust    = trim((string) $e->cust_id);
            $custNum = preg_match('/^\d{5,}$/', $cust) ? $cust : null;
            $nomeN   = $this->normNome($e->nome);

            $presente = ($custNum !== null && isset($this->v2Custs[$custNum])) || isset($this->v2Nomes[$nomeN]);
            if ($presente) {
                continue;
            }

            $this->rel['arquivadas']++;
            $this->aArquivar[] = "{$e->nome} (cust " . ($cust ?: 'sem id') . ', fase ' . ($e->fase ?? '?') . ')';

            if ($gravar) {
                $e->update([
                    'arquivado_em'     => now(),
                    'arquivado_por'    => null, // sync automático (sem usuário logado)
                    'arquivado_motivo' => $motivo,
                ]);
            }
        }
    }

    private function relatorio(bool $apply, bool $arquivar = false): void
    {
        $this->newLine();
        $this->info('─── RESUMO '.($apply ? '(APLICADO)' : '(DRY-RUN)').' ───');
        $this->table(['Ação', 'Qtd'], [
            ['Linhas processadas',            $this->rel['linhas']],
            ['Empresas ATUALIZADAS',          $this->rel['update_empresa']],
            ['Empresas CRIADAS',              $this->rel['create_empresa']],
            ['Fichas ATUALIZADAS',            $this->rel['update_ficha']],
            ['Fichas CRIADAS',                $this->rel['create_ficha']],
            ['PULADAS (registro publicador)', $this->rel['skip_publicador']],
            ['Puladas (erro/sem nome)',       $this->rel['skip_erro']],
            [($apply && $arquivar ? 'Empresas ARQUIVADAS' : 'Ausentes (a arquivar)'), $this->rel['arquivadas']],
        ]);

        if ($this->campoChanges) {
            arsort($this->campoChanges);
            $this->line("\nCampos alterados (contagem):");
            foreach ($this->campoChanges as $c => $n) {
                $this->line("   {$c}: {$n}");
            }
        }
        if ($this->skipPublicador) {
            $this->warn("\n⚠ Registros de PUBLICADOR pulados (cust_id casou mas projeto != POLOS):");
            foreach (array_slice($this->skipPublicador, 0, 30) as $s) {
                $this->line("   - {$s}");
            }
        }
        if ($this->fasesDesconhecidas) {
            $this->warn("\n⚠ Fases NÃO mapeadas (deixadas como estavam):");
            foreach ($this->fasesDesconhecidas as $k => $n) {
                $this->line("   '{$k}' => {$n}");
            }
        }
        if ($this->polosDesconhecidos) {
            $this->warn("\n⚠ Polos NÃO mapeados:");
            foreach ($this->polosDesconhecidos as $k => $n) {
                $this->line("   '{$k}' => {$n}");
            }
        }
        if ($this->estagiosDesconhecidos) {
            $this->warn("\n⚠ Valores de 'Publicação'→estagio NÃO mapeados:");
            foreach ($this->estagiosDesconhecidos as $k => $n) {
                $this->line("   '{$k}' => {$n}");
            }
        }
        if ($this->criadas && ! $apply) {
            $this->line("\nAmostra de empresas que seriam CRIADAS (até 40):");
            foreach (array_slice($this->criadas, 0, 40) as $c) {
                $this->line("   + {$c}");
            }
            if (count($this->criadas) > 40) {
                $this->line('   ... +'.(count($this->criadas) - 40).' outras');
            }
        }

        if ($this->aArquivar) {
            $verbo = ($apply && $arquivar) ? 'ARQUIVADAS' : 'que seriam ARQUIVADAS (ausentes na planilha)';
            $this->warn("\n⚠ Empresas {$verbo} — REVISE antes de aplicar (até 60):");
            foreach (array_slice($this->aArquivar, 0, 60) as $a) {
                $this->line("   ⤵ {$a}");
            }
            if (count($this->aArquivar) > 60) {
                $this->line('   ... +'.(count($this->aArquivar) - 60).' outras');
            }
            if (! ($apply && $arquivar)) {
                $this->comment('   (nenhuma foi arquivada — passe --apply --arquivar-ausentes para arquivar de fato)');
            }
        }

        $this->newLine();
        if (! $apply) {
            $this->comment('Preview apenas. Para gravar: rode de novo com --apply' . ($this->rel['arquivadas'] ? ' --arquivar-ausentes' : ''));
        } else {
            $this->info('✔ Alterações gravadas.' . ($arquivar ? '' : ' (arquivamento de ausentes NÃO aplicado — faltou --arquivar-ausentes)'));
        }
    }
}
