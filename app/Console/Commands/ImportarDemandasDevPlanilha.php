<?php

namespace App\Console\Commands;

use App\Models\DevDemanda;
use App\Models\DevDemandaAtualizacao;
use App\Models\DevReuniao;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Importa a planilha "Gestão de Demandas de Desenvolvimento" para o módulo Demandas Dev.
 *
 * Lê só as colunas DIGITADAS (as calculadas nascem de novo no sistema):
 *  - Demandas:      A ID · B Demanda · C Área · D Escopo · E Responsável · F Prioridade · G Entrada · H Prazo · O Observações
 *  - Atualizações:  A Data · B Dev · C ID · D Status · E Feito · F Próxima ação · G Bloqueado? · H Motivo · I Previsão
 *  - Reuniões:      A Data · B Pauta · C Participantes · D Gravação · E Transcrição · F IDs · G Decisões · H Duração
 *
 * Nomes viram usuários por nome (único, ativo). Ambíguo ou inexistente ABORTA até
 * ser resolvido com --mapa=Nome=email|id, ou --mapa=Nome= (sem usuário: o nome fica
 * registrado na observação/autor). ID repetido na planilha: a 1ª linha fica com o
 * código e as demais ganham o próximo número livre do prefixo.
 *
 * Dry-run por padrão; grava só com --apply, e só em tabela vazia.
 */
class ImportarDemandasDevPlanilha extends Command
{
    protected $signature = 'demandas-dev:importar-planilha
        {arquivo : Caminho do .xlsx}
        {--mapa=* : Nome=email|id (vazio = sem usuário), ex.: --mapa=Maycon=dev.02@ecfconsultoria.com.br}
        {--ignorar=* : IDs da planilha a ignorar (ex.: TESTE-01)}
        {--apply : Grava de fato (sem isto, só mostra o que faria)}';

    protected $description = '[Demandas Dev] Importa demandas, atualizações e reuniões da planilha de gestão do time dev';

    /** @var array<string, ?int> nome normalizado → user id (null = sem usuário) */
    private array $usuarios = [];

    /** @var array<string, string[]> nomes sem resolução → candidatos */
    private array $pendentes = [];

    public function handle(): int
    {
        $arquivo = $this->argument('arquivo');
        if (! is_file($arquivo)) {
            $this->error("Arquivo não encontrado: {$arquivo}");

            return self::FAILURE;
        }

        if (! $this->carregarMapa()) {
            return self::FAILURE;
        }
        $planilha = IOFactory::createReaderForFile($arquivo)->setReadDataOnly(true)->load($arquivo);
        $ignorar = array_map('strtoupper', $this->option('ignorar'));

        // ── Leitura ──
        $demandas = $this->lerDemandas($planilha->getSheetByName('Demandas'), $ignorar);
        $atualizacoes = $this->lerAtualizacoes($planilha->getSheetByName('Atualizações'));
        $reunioes = ($aba = $planilha->getSheetByName('Reuniões')) ? $this->lerReunioes($aba) : [];

        foreach ($demandas as $d) {
            $this->resolverNome($d['responsavel']);
        }
        foreach ($atualizacoes as $a) {
            $this->resolverNome($a['autor']);
        }

        if ($this->pendentes) {
            $this->error('Nomes sem usuário único — resolva com --mapa=Nome=email (ou --mapa=Nome= para ficar sem usuário):');
            foreach ($this->pendentes as $nome => $candidatos) {
                $this->line("  · {$nome}: " . ($candidatos ? 'ambíguo → ' . implode(' | ', $candidatos) : 'nenhum usuário ativo com esse nome'));
            }

            return self::FAILURE;
        }

        // ── Códigos: 1ª ocorrência fica com o ID; repetidos continuam DEPOIS do maior
        // número do prefixo. Não preenche buracos: as observações da planilha citam
        // IDs antigos como dependência ("Dep.: DEV-16") e reusar o número confundiria.
        $maior = [];
        foreach ($demandas as $d) {
            if (preg_match('/^([A-Z]+)-(\d+)$/', $d['codigo_original'], $m)) {
                $maior[$m[1]] = max($maior[$m[1]] ?? 0, (int) $m[2]);
            }
        }
        $vistos = [];
        foreach ($demandas as &$d) {
            if (! isset($vistos[$d['codigo_original']])) {
                $vistos[$d['codigo_original']] = true;
                $d['codigo'] = $d['codigo_original'];
                continue;
            }
            $prefixo = explode('-', $d['codigo_original'])[0];
            $maior[$prefixo] = ($maior[$prefixo] ?? 0) + 1;
            $novo = sprintf('%s-%02d', $prefixo, $maior[$prefixo]);
            $d['codigo'] = $novo;
            $d['observacoes'] = trim("ID na planilha: {$d['codigo_original']} (repetido) · " . ($d['observacoes'] ?? ''), ' ·');
        }
        unset($d);

        $this->resumo($demandas, $atualizacoes, $reunioes, $ignorar);

        if (! $this->option('apply')) {
            $this->warn('Dry-run: nada gravado. Rode de novo com --apply.');

            return self::SUCCESS;
        }

        if (DevDemanda::query()->exists()) {
            $this->error('Já existem demandas cadastradas — a importação só roda em tabela vazia (evita duplicar).');

            return self::FAILURE;
        }

        DB::transaction(fn () => $this->gravar($demandas, $atualizacoes, $reunioes));

        $this->info(sprintf(
            'Importado: %d demandas, %d atualizações, %d reuniões.',
            DevDemanda::count(), DevDemandaAtualizacao::count(), DevReuniao::count(),
        ));

        return self::SUCCESS;
    }

    // ═══ Leitura das abas ═══

    private function lerDemandas(Worksheet $aba, array $ignorar): array
    {
        $prioridades = array_flip(DevDemanda::PRIORIDADE_LABELS);
        $linhas = [];

        for ($r = 3; $r <= $aba->getHighestDataRow(); $r++) {
            $codigo = strtoupper($this->texto($aba, "A{$r}") ?? '');
            $titulo = $this->texto($aba, "B{$r}");
            if ($codigo === '' || ! $titulo || in_array($codigo, $ignorar, true)) {
                continue;
            }

            $responsavel = $this->texto($aba, "E{$r}");
            $prioridadeTxt = $this->texto($aba, "F{$r}");

            $linhas[] = [
                'linha'           => $r,
                'codigo_original' => $codigo,
                'titulo'          => $titulo,
                'area'            => $this->texto($aba, "C{$r}"),
                'escopo'          => $this->texto($aba, "D{$r}"),
                'responsavel'     => $responsavel,
                'prioridade'      => $prioridades[$prioridadeTxt] ?? 2,
                'data_entrada'    => $this->data($aba, "G{$r}") ?? now()->toDateString(),
                // "sem prazo definido" (texto) vira prazo nulo.
                'prazo'           => $this->data($aba, "H{$r}"),
                'observacoes'     => $this->texto($aba, "O{$r}"),
            ];
        }

        return $linhas;
    }

    private function lerAtualizacoes(Worksheet $aba): array
    {
        $status = array_change_key_case(array_flip(DevDemanda::STATUS_LABELS), CASE_LOWER);
        $linhas = [];

        for ($r = 3; $r <= $aba->getHighestDataRow(); $r++) {
            $codigo = strtoupper($this->texto($aba, "C{$r}") ?? '');
            $statusTxt = mb_strtolower($this->texto($aba, "D{$r}") ?? '');
            if ($codigo === '' || $statusTxt === '') {
                continue;
            }
            if (! isset($status[$statusTxt])) {
                $this->warn("Atualizações linha {$r}: status desconhecido \"{$statusTxt}\" — ignorada.");
                continue;
            }

            $linhas[] = [
                'linha'             => $r,
                'codigo'            => $codigo,
                'data'              => $this->data($aba, "A{$r}") ?? now()->toDateString(),
                'autor'             => $this->texto($aba, "B{$r}"),
                'status'            => $status[$statusTxt],
                'feito'             => $this->texto($aba, "E{$r}"),
                'proxima_acao'      => $this->texto($aba, "F{$r}"),
                'bloqueado'         => mb_strtolower($this->texto($aba, "G{$r}") ?? '') === 'sim',
                'motivo_bloqueio'   => $this->texto($aba, "H{$r}"),
                'previsao_revisada' => $this->data($aba, "I{$r}"),
            ];
        }

        return $linhas;
    }

    private function lerReunioes(Worksheet $aba): array
    {
        $linhas = [];

        for ($r = 3; $r <= $aba->getHighestDataRow(); $r++) {
            $titulo = $this->texto($aba, "B{$r}");
            if (! $titulo) {
                continue;
            }

            $linhas[] = [
                'data'             => $this->data($aba, "A{$r}") ?? now()->toDateString(),
                'titulo'           => $titulo,
                'participantes'    => $this->texto($aba, "C{$r}"),
                'link_gravacao'    => $this->texto($aba, "D{$r}"),
                'link_transcricao' => $this->texto($aba, "E{$r}"),
                'ids'              => $this->texto($aba, "F{$r}"),
                'decisoes'         => $this->texto($aba, "G{$r}"),
                'duracao'          => $this->texto($aba, "H{$r}"),
            ];
        }

        return $linhas;
    }

    // ═══ Gravação ═══

    private function gravar(array $demandas, array $atualizacoes, array $reunioes): void
    {
        // código original → ids (repetidos apontam todos para o mesmo código original)
        $porOriginal = [];
        // código original → id da 1ª ocorrência (quem recebe as atualizações)
        $donoDoCodigo = [];

        foreach ($demandas as $d) {
            $userId = $this->idDe($d['responsavel']);
            $obs = $d['observacoes'];
            if ($d['responsavel'] && $userId === null) {
                $obs = trim("Responsável na planilha: {$d['responsavel']} · " . ($obs ?? ''), ' ·');
            }

            $nova = DevDemanda::create([
                'codigo'         => $d['codigo'],
                'titulo'         => $d['titulo'],
                'area'           => $d['area'],
                'escopo'         => $d['escopo'],
                'responsavel_id' => $userId,
                'prioridade'     => $d['prioridade'],
                'data_entrada'   => $d['data_entrada'],
                'prazo'          => $d['prazo'],
                'observacoes'    => $obs,
            ]);

            $porOriginal[$d['codigo_original']][] = $nova->id;
            $donoDoCodigo[$d['codigo_original']] ??= $nova->id;
        }

        // Ordem das linhas preservada: o id crescente é o que define a "última atualização".
        foreach ($atualizacoes as $a) {
            $demandaId = $donoDoCodigo[$a['codigo']] ?? null;
            if (! $demandaId) {
                continue;
            }
            $userId = $this->idDe($a['autor']);

            DevDemandaAtualizacao::create([
                'dev_demanda_id'    => $demandaId,
                'user_id'           => $userId,
                'autor_nome'        => $userId === null ? $a['autor'] : null,
                'data'              => $a['data'],
                'status'            => $a['status'],
                'feito'             => $a['feito'],
                'proxima_acao'      => $a['proxima_acao'],
                'bloqueado'         => $a['bloqueado'],
                'motivo_bloqueio'   => $a['motivo_bloqueio'],
                'previsao_revisada' => $a['previsao_revisada'],
            ]);
        }

        foreach ($reunioes as $r) {
            $reuniao = DevReuniao::create(collect($r)->except('ids')->all());
            $reuniao->demandas()->sync($this->idsCitados($r['ids'] ?? '', $porOriginal));
        }
    }

    /**
     * IDs citados em texto livre: "DEV-01 a DEV-24, ADM-01 a ADM-03, MGT-01".
     * Intervalo "X-NN a X-MM" (ou "X-NN a MM") expande; sub-IDs (DEV-15.1) não existem como demanda.
     */
    private function idsCitados(string $texto, array $porOriginal): array
    {
        $codigos = [];

        preg_match_all('/\b([A-Z]{2,6})-(\d+)\s+a\s+(?:\1-)?(\d+)\b(?!\.)/u', $texto, $intervalos, PREG_SET_ORDER);
        foreach ($intervalos as [$trecho, $prefixo, $de, $ate]) {
            for ($n = (int) $de; $n <= (int) $ate; $n++) {
                $codigos[] = sprintf('%s-%02d', $prefixo, $n);
            }
            $texto = str_replace($trecho, '', $texto);
        }

        preg_match_all('/\b([A-Z]{2,6}-\d+)\b(?!\.)/u', $texto, $soltos);
        $codigos = array_merge($codigos, $soltos[1]);

        return collect($codigos)
            ->flatMap(fn (string $c) => $porOriginal[$c] ?? [])
            ->unique()
            ->values()
            ->all();
    }

    // ═══ Usuários ═══

    private function carregarMapa(): bool
    {
        foreach ($this->option('mapa') as $par) {
            [$nome, $alvo] = array_pad(explode('=', $par, 2), 2, '');
            $chave = mb_strtolower(trim($nome));
            $alvo = trim($alvo);

            if ($alvo === '') {
                $this->usuarios[$chave] = null;
                continue;
            }

            $user = ctype_digit($alvo) ? User::find((int) $alvo) : User::where('email', $alvo)->first();
            if (! $user) {
                $this->error("--mapa: usuário \"{$alvo}\" não encontrado (para {$nome}).");

                return false;
            }
            $this->usuarios[$chave] = $user->id;
        }

        return true;
    }

    private function resolverNome(?string $nome): void
    {
        if (! $nome) {
            return;
        }
        $chave = mb_strtolower(trim($nome));
        if (array_key_exists($chave, $this->usuarios) || isset($this->pendentes[$nome])) {
            return;
        }

        $candidatos = User::query()
            ->where('active', true)
            ->where(fn ($q) => $q->whereRaw('LOWER(name) = ?', [$chave])->orWhereRaw('LOWER(name) LIKE ?', [$chave . ' %']))
            ->get(['id', 'name', 'email']);

        if ($candidatos->count() === 1) {
            $this->usuarios[$chave] = $candidatos->first()->id;

            return;
        }

        $this->pendentes[$nome] = $candidatos->map(fn ($u) => "{$u->name} <{$u->email}> #{$u->id}")->all();
    }

    private function idDe(?string $nome): ?int
    {
        return $nome ? ($this->usuarios[mb_strtolower(trim($nome))] ?? null) : null;
    }

    // ═══ Células ═══

    private function texto(Worksheet $aba, string $celula): ?string
    {
        $v = $aba->getCell($celula)->getValue();
        if ($v === null) {
            return null;
        }
        $v = trim((string) $v);

        return $v === '' ? null : $v;
    }

    /** Data serial do Excel → Y-m-d. Texto ("sem prazo definido") ou vazio → null. */
    private function data(Worksheet $aba, string $celula): ?string
    {
        $v = $aba->getCell($celula)->getValue();
        if (! is_numeric($v)) {
            return null;
        }

        return Carbon::instance(ExcelDate::excelToDateTimeObject((float) $v))->toDateString();
    }

    private function resumo(array $demandas, array $atualizacoes, array $reunioes, array $ignorar): void
    {
        $codigos = array_column($demandas, 'codigo_original');
        $semDono = array_filter($atualizacoes, fn ($a) => ! in_array($a['codigo'], $codigos, true));
        $repetidos = array_filter($demandas, fn ($d) => $d['codigo'] !== $d['codigo_original']);

        $this->info(sprintf('Planilha: %d demandas, %d atualizações, %d reuniões.', count($demandas), count($atualizacoes), count($reunioes)));
        if ($ignorar) {
            $this->line('  Ignorados: ' . implode(', ', $ignorar));
        }
        foreach ($repetidos as $d) {
            $this->line("  ID repetido {$d['codigo_original']} (linha {$d['linha']}, \"{$d['titulo']}\") → {$d['codigo']}");
        }
        if ($repetidos) {
            $this->warn('  Atualizações de um ID repetido vão para a 1ª linha que usa o ID.');
        }
        if ($semDono) {
            $this->line('  Atualizações sem demanda (descartadas): ' . count($semDono) . ' — ' . implode(', ', array_unique(array_column($semDono, 'codigo'))));
        }
        foreach ($this->usuarios as $nome => $id) {
            $this->line("  {$nome} → " . ($id ? "usuário #{$id}" : 'sem usuário (nome guardado)'));
        }
    }
}
