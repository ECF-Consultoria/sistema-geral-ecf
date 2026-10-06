<?php

namespace App\Console\Commands;

use App\Models\ContratoAssinatura;
use App\Services\Fechamento\TabelaDeContratoAssinadoService;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Quick 261006-gf5 — o RETROATIVO de `TabelaDeContratoAssinadoService`.
 *
 * O serviço carimba a tabela da empresa na hora em que o contrato é assinado, mas os contratos
 * já assinados antes dele não passaram por lá. Medido em produção em 2026-10-06: **5** contratos
 * assinados gerados pelo sistema, em 5 empresas — 3 com tabela `presumida_servico` (entre elas a
 * MADERATTO MÓVEIS #446, contrato assinado em 2026-08-27), 1 `manual` e 1 sem tabela nenhuma.
 * Este comando é o que fecha essa lacuna.
 *
 * ⛔ **Nenhuma regra nova nasce aqui.** A decisão inteira (quais serviços o contrato cobre, se a
 * tabela já é confirmada, se há divergência entre serviços combinados) é de
 * `TabelaDeContratoAssinadoService::aplicar()`, chamado com `aplicar: false` no dry-run — o modo
 * simulação percorre EXATAMENTE o mesmo caminho e só não grava. Uma segunda cópia da regra aqui
 * garantiria divergência entre "o que a varredura diz que faria" e "o que a assinatura faz".
 *
 * ⚠️ **Dry-run é o PADRÃO** — gravar exige `--aplicar` explícito, mesma disciplina de
 * `fechamento:materializar-tabelas` (Fase 141 Plano 03).
 *
 * ⚠️ O texto impresso é conveniência operacional. A conferência oficial é a RECONSULTA ao banco
 * (`empresa_faixas_faturamento` por `origem`), nunca o stdout desta rodada — disciplina registrada
 * em `.planning/learnings/desempenho-bonificacao.md`.
 *
 * ⚠️ Este comando NUNCA lê nem grava `fechamento_snapshots`/`fechamento_grupo_snapshots`: carimbar
 * a procedência da tabela agora não reescreve nenhuma competência já congelada (D-11 da Fase 137).
 */
class TabelasDeContratosAssinadosFechamento extends Command
{
    protected $signature = 'fechamento:tabelas-de-contratos-assinados
        {--aplicar : grava de fato (sem esta opção, só mostra o que faria)}
        {--dry-run : explícito o que já é o padrão — não grava nada}
        {--company= : limita a varredura a UMA empresa (id)}
        {--json : saída em JSON para conferência}';

    protected $description = 'Varre os contratos já assinados e carimba a tabela progressiva da empresa como confirmada por contrato (quick 261006-gf5). Dry-run é o padrão.';

    public function __construct(private TabelaDeContratoAssinadoService $tabelaDoContrato)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        // `--dry-run` e a AUSÊNCIA de `--aplicar` significam a mesma coisa; a opção existe para
        // quem escreve a intenção por extenso. `--dry-run` junto com `--aplicar` vence o dry-run:
        // entre "gravou sem querer" e "não gravou achando que gravou", o segundo é o erro barato.
        $aplicar = (bool) $this->option('aplicar') && ! (bool) $this->option('dry-run');

        $companyId = $this->option('company') !== null ? (int) $this->option('company') : null;

        $contratos = ContratoAssinatura::query()
            ->where('status', ContratoAssinatura::STATUS_ASSINADO)
            ->when($companyId !== null, fn ($q) => $q->where('company_id', $companyId))
            ->with(['company', 'servico'])
            // Mais antigo primeiro: quando a MESMA empresa tem 2+ contratos assinados, quem
            // grava é o primeiro, e os seguintes caem em `ja_confirmada` — o resultado da
            // varredura não depende da ordem de leitura do banco.
            ->orderBy('assinado_em')
            ->orderBy('id')
            ->get();

        $linhas = [];

        foreach ($contratos as $contrato) {
            $resultado = $this->tabelaDoContrato->aplicarComSeguranca($contrato, $aplicar);

            $linhas[] = [
                'contrato_assinatura_id' => $contrato->id,
                'company_id'             => $resultado['company_id'],
                'company_name'           => $resultado['company_name'],
                'servico_nome'           => $resultado['servico_nome'] ?? optional($contrato->servico)->nome,
                'motivo'                 => $resultado['motivo'],
                'gravou'                 => $resultado['gravou'],
                'origem_anterior'        => $resultado['origem_anterior'],
                'faixas_antes'           => $resultado['faixas_antes'],
                'faixas_depois'          => $resultado['faixas_depois'],
            ];
        }

        $this->relatorio(new Collection($linhas), $aplicar);

        $falhas = (new Collection($linhas))
            ->where('motivo', TabelaDeContratoAssinadoService::MOTIVO_FALHA)
            ->count();

        return $falhas > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Relatório por EMPRESA: o que tinha, o que passa a ter, e o motivo de quem foi pulada —
     * em pt-BR, sem jargão (regra sistêmica do projeto).
     *
     * @param  Collection<int, array<string, mixed>>  $linhas
     */
    private function relatorio(Collection $linhas, bool $aplicar): void
    {
        $contagens = $linhas->countBy('motivo')->all();

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'aplicou'    => $aplicar,
                'contratos'  => $linhas->count(),
                'contagens'  => $contagens,
                'linhas'     => $linhas->all(),
            ], JSON_UNESCAPED_UNICODE));

            return;
        }

        $this->info($aplicar
            ? '[Fechamento] Retroativo EXECUTADO — tabelas carimbadas como confirmadas por contrato (origem contrato).'
            : '[Fechamento] Simulação (dry-run) — nada foi gravado. Rode de novo com --aplicar para gravar.');

        $this->line("Contratos assinados examinados: {$linhas->count()}");

        if ($linhas->isEmpty()) {
            return;
        }

        foreach ($linhas as $linha) {
            $this->line('');
            $this->line("  {$linha['company_name']} (empresa {$linha['company_id']}) — contrato #{$linha['contrato_assinatura_id']}, serviço \"{$linha['servico_nome']}\"");
            $this->line('    tinha: '.$this->descreverTabelaAtual($linha));
            $this->line('    '.$this->descreverDesfecho($linha, $aplicar));
        }
    }

    /**
     * "O que tinha" — a tabela própria da empresa ANTES desta rodada, em português.
     *
     * @param  array<string, mixed>  $linha
     */
    private function descreverTabelaAtual(array $linha): string
    {
        if ($linha['faixas_antes'] === 0) {
            return 'nenhuma tabela própria cadastrada';
        }

        $procedencia = match ($linha['origem_anterior']) {
            'manual'            => 'cadastrada à mão',
            'contrato'          => 'confirmada por contrato',
            'presumida_servico' => 'presumida (copiada da tabela do serviço)',
            default             => 'procedência desconhecida',
        };

        return "{$linha['faixas_antes']} faixa(s), {$procedencia}";
    }

    /**
     * "O que passa a ter" ou "por que foi pulada" — um motivo por desfecho, nunca um genérico.
     *
     * @param  array<string, mixed>  $linha
     */
    private function descreverDesfecho(array $linha, bool $aplicar): string
    {
        return match ($linha['motivo']) {
            TabelaDeContratoAssinadoService::MOTIVO_GRAVOU =>
                "passa a ter: {$linha['faixas_depois']} faixa(s) confirmadas por contrato — GRAVADO",

            TabelaDeContratoAssinadoService::MOTIVO_SIMULADO =>
                "passaria a ter: {$linha['faixas_depois']} faixa(s) confirmadas por contrato (nada gravado nesta rodada)",

            TabelaDeContratoAssinadoService::MOTIVO_JA_CONFIRMADA =>
                'pulada: a tabela já foi confirmada por um humano (ou por um contrato anterior) — ato humano vence',

            TabelaDeContratoAssinadoService::MOTIVO_TABELAS_DIVERGENTES =>
                'pulada: o contrato cobre serviços com tabelas progressivas diferentes — adivinhar qual vale é pior que deixar presumida',

            TabelaDeContratoAssinadoService::MOTIVO_SERVICO_SEM_FAIXAS =>
                'pulada: o serviço deste contrato não tem tabela progressiva (cobrança fixa)',

            TabelaDeContratoAssinadoService::MOTIVO_SEM_EMPRESA =>
                'pulada: contrato sem empresa associada (dado inconsistente)',

            TabelaDeContratoAssinadoService::MOTIVO_NAO_ASSINADO =>
                'pulada: o contrato não está assinado',

            TabelaDeContratoAssinadoService::MOTIVO_FALHA =>
                'FALHA ao gravar — detalhe no log (procure por "[Fechamento] Falha ao gravar a tabela da empresa")',

            default => "desfecho não mapeado: {$linha['motivo']}",
        };
    }
}
