<?php

namespace App\Console\Commands;

use App\Models\ContratoClicksignAssinado;
use App\Services\Clicksign\ClicksignClient;
use App\Services\Contratos\AcervoVigenciaClicksignService;
use App\Services\Contratos\EmpresaPalpiteService;
use App\Services\Contratos\ExtratorTextoContratoService;
use App\Services\Contratos\TabelaProgressivaContratoParser;
use Illuminate\Console\Command;

/**
 * Fase 202-08 — `contratos:ler-clicksign`: lê os contratos ASSINADOS da
 * Clicksign (prazo + data da assinatura + empresa) para o término do contrato.
 *
 * Escreve SÓ em `contratos_clicksign_assinados`. Não mexe em término: quem lê
 * esta tabela é `contratos:calcular-termino` / `TerminoContratoService`.
 * Incremental — envelope já lido não é baixado de novo (`--releer` força).
 */
class LerContratosClicksign extends Command
{
    protected $signature = 'contratos:ler-clicksign
        {--limite= : Lê no máximo N envelopes novos nesta rodada}
        {--releer : Lê de novo os envelopes já lidos}
        {--pausa-ms=3500 : Pausa entre chamadas à Clicksign (janela de 20/min)}';

    protected $description = 'Lê o prazo e a data de assinatura dos contratos assinados na Clicksign (só grava na tabela do acervo)';

    public function handle(
        ClicksignClient $client,
        ExtratorTextoContratoService $extrator,
        TabelaProgressivaContratoParser $parser,
        EmpresaPalpiteService $palpite,
    ): int {
        $limite = $this->option('limite') !== null && $this->option('limite') !== '' ? (int) $this->option('limite') : null;

        $servico = new AcervoVigenciaClicksignService($client, $extrator, $parser, $palpite, (int) $this->option('pausa-ms'));

        $resumo = $servico->executar($limite, (bool) $this->option('releer'));

        $this->info("Contratos de cliente assinados na Clicksign: {$resumo['candidatos']}");
        $this->line("  já lidos antes (pulados): {$resumo['pulados']}");
        $this->line("  lidos nesta rodada: {$resumo['lidos']}");

        foreach ($resumo['por_situacao'] as $situacao => $qtd) {
            $this->line("    {$situacao}: {$qtd}");
        }

        if ($resumo['linhas'] !== []) {
            $this->table(
                ['Envelope', 'Tipo', 'Empresa', 'Assinado em', 'Meses (fonte)', 'Situação', 'Motivo'],
                collect($resumo['linhas'])->map(fn (array $l) => [
                    mb_substr((string) $l['envelope_nome'], 0, 60),
                    $l['tipo'],
                    $l['company_id'] ?? '-',
                    $l['assinado_em'] ?? '-',
                    $l['vigencia_meses'] !== null ? "{$l['vigencia_meses']} ({$l['vigencia_fonte']})" : '-',
                    $l['situacao'],
                    mb_substr((string) ($l['motivo'] ?? ''), 0, 80),
                ])->all()
            );
        }

        $this->line('Total na tabela por situação (reconsulta):');
        ContratoClicksignAssinado::query()
            ->selectRaw('situacao, COUNT(*) as qtd')
            ->groupBy('situacao')
            ->orderBy('situacao')
            ->get()
            ->each(fn ($r) => $this->line("  {$r->situacao}: {$r->qtd}"));

        return self::SUCCESS;
    }
}
