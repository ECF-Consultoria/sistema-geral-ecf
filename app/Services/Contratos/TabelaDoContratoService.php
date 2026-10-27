<?php

namespace App\Services\Contratos;

use App\Models\Company;
use App\Models\ContratoAssinatura;
use App\Models\EmpresaFaixaFaturamento;
use App\Models\Servico;
use App\Models\ServicoFaixaFaturamento;

/**
 * TabelaDoContratoService — quick 261027-m1y. A tabela progressiva que o
 * contrato Clicksign de Gestão IMPRIME deixou de ser texto fixo no `.docx`:
 * é a tabela ESPECÍFICA da empresa (origem `manual` ou `contrato`) quando
 * existir, senão a padrão do serviço — e fica CONGELADA no contrato no
 * momento da geração (`tabela_progressiva_snapshot`), mesma disciplina do
 * `servicos_snapshot`.
 *
 * `presumida_servico` NUNCA conta como específica (learning
 * fechamento-tabela-por-empresa §2): ninguém confirmou aquela tabela, ela é
 * só a cópia da tabela do serviço na virada da Fase 141. Tabela de GRUPO
 * também não entra aqui — "específica" é a da empresa.
 *
 * O `.docx` tem 7 linhas fixas; tabela com mais de 7 faixas é RECUSADA, nunca
 * truncada em silêncio. A regra só vale para serviço cujo modelo imprime a
 * tabela variável (`SERVICOS_COM_TABELA_NO_MODELO`) — Shopee tem 11 faixas e
 * seu modelo não imprime tabela.
 */
class TabelaDoContratoService
{
    /** Serviços (por nome exato) cujo modelo Clicksign imprime a tabela variável. */
    public const SERVICOS_COM_TABELA_NO_MODELO = ['Gestão'];

    /** Linhas fixas da tabela no `.docx`. */
    public const MAX_FAIXAS_NO_CONTRATO = 7;

    public function imprimeTabela(?Servico $servico): bool
    {
        return $servico !== null && in_array($servico->nome, self::SERVICOS_COM_TABELA_NO_MODELO, true);
    }

    /**
     * Tabela que o contrato desta empresa deve imprimir, ou null se não há nenhuma.
     *
     * @return array{origem: string, servico_id: int|null, congelada_em: string, faixas: array<int, array{ordem:int, limite_superior:float|null, valor:float, valor_e_piso:bool}>}|null
     */
    public function resolver(Company $company, Servico $servico): ?array
    {
        $daEmpresa = EmpresaFaixaFaturamento::where('company_id', $company->id)->ordenadas()->get();
        $origem    = $daEmpresa->first()->origem ?? null;

        if ($daEmpresa->isNotEmpty() && in_array($origem, [
            EmpresaFaixaFaturamento::ORIGEM_MANUAL,
            EmpresaFaixaFaturamento::ORIGEM_CONTRATO,
        ], true)) {
            return [
                'origem'       => $origem,
                'servico_id'   => $daEmpresa->first()->servico_origem_id !== null ? (int) $daEmpresa->first()->servico_origem_id : null,
                'congelada_em' => now()->format('Y-m-d H:i:s'),
                'faixas'       => $daEmpresa->map(fn (EmpresaFaixaFaturamento $f) => $this->achatar($f))->values()->all(),
            ];
        }

        $doServico = ServicoFaixaFaturamento::where('servico_id', $servico->id)->ordenadas()->get();

        if ($doServico->isEmpty()) {
            return null;
        }

        return [
            'origem'       => 'servico',
            'servico_id'   => (int) $servico->id,
            'congelada_em' => now()->format('Y-m-d H:i:s'),
            'faixas'       => $doServico->map(fn (ServicoFaixaFaturamento $f) => $this->achatar($f))->values()->all(),
        ];
    }

    /**
     * Congela no contrato a tabela que ele vai imprimir. Idempotente: contrato com snapshot
     * já preenchido não muda (editar a tabela depois da geração não altera o contrato).
     *
     * @throws \RuntimeException  tabela com mais de 7 faixas — antes de gravar qualquer coisa.
     */
    public function congelar(ContratoAssinatura $contrato): void
    {
        if (! empty($contrato->tabela_progressiva_snapshot)) {
            return;
        }

        $servico = $contrato->servico;

        if (! $this->imprimeTabela($servico)) {
            return;
        }

        $snapshot = $this->resolver($contrato->company, $servico);

        if ($snapshot === null) {
            // Sem tabela: não grava; montarDados() marca a pendência.
            return;
        }

        $this->recusarSeAcimaDoLimite($snapshot, $contrato->company?->name ?? "#{$contrato->company_id}");

        $contrato->tabela_progressiva_snapshot = $snapshot;
        $contrato->save();
    }

    /**
     * 7 linhas {faturamento, valor} prontas para as variáveis do `.docx`. PURO: sem DB/Log/Cache.
     *
     * @return array<int, array{faturamento: string, valor: string}>
     *
     * @throws \RuntimeException  mais de 7 faixas.
     */
    public static function linhasParaContrato(?array $snapshot): array
    {
        $faixas = $snapshot['faixas'] ?? [];

        if (count($faixas) > self::MAX_FAIXAS_NO_CONTRATO) {
            throw new \RuntimeException(sprintf(
                'A tabela progressiva tem %d faixas; o contrato comporta no máximo %d.',
                count($faixas),
                self::MAX_FAIXAS_NO_CONTRATO
            ));
        }

        usort($faixas, fn (array $a, array $b) => $a['ordem'] <=> $b['ordem']);

        $linhas = [];
        $limiteAnterior = null;

        foreach ($faixas as $i => $f) {
            $limite = $f['limite_superior'] ?? null;

            if ($limite === null) {
                $faturamento = $i === 0
                    ? 'Qualquer faturamento'
                    : 'Acima de ' . self::moeda(self::inicioDaFaixa($limiteAnterior));
            } elseif ($i === 0) {
                $faturamento = 'Até ' . self::moeda((float) $limite);
            } else {
                $faturamento = 'A partir de ' . self::moeda(self::inicioDaFaixa($limiteAnterior));
            }

            $valor = self::moeda((float) $f['valor']);
            if (! empty($f['valor_e_piso'])) {
                $valor = 'a partir de ' . $valor;
            }

            $linhas[] = ['faturamento' => $faturamento, 'valor' => $valor];
            $limiteAnterior = $limite;
        }

        while (count($linhas) < self::MAX_FAIXAS_NO_CONTRATO) {
            $linhas[] = ['faturamento' => '', 'valor' => ''];
        }

        return $linhas;
    }

    /** Início de uma faixa = limite da anterior; na convenção ",99" soma 0,01. */
    private static function inicioDaFaixa(?float $limiteAnterior): float
    {
        $limite = (float) $limiteAnterior;

        return ((int) round($limite * 100)) % 100 === 99 ? round($limite + 0.01, 2) : $limite;
    }

    private static function moeda(float $valor): string
    {
        return 'R$ ' . number_format($valor, 2, ',', '.');
    }

    private function recusarSeAcimaDoLimite(array $snapshot, string $empresa): void
    {
        $n = count($snapshot['faixas']);

        if ($n > self::MAX_FAIXAS_NO_CONTRATO) {
            throw new \RuntimeException(sprintf(
                'A tabela progressiva da empresa %s tem %d faixas; o contrato de Gestão comporta no máximo %d. Ajuste a tabela em Administrativo › Contratos › Tabela antes de gerar.',
                $empresa,
                $n,
                self::MAX_FAIXAS_NO_CONTRATO
            ));
        }
    }

    /** @return array{ordem:int, limite_superior:float|null, valor:float, valor_e_piso:bool} */
    private function achatar(EmpresaFaixaFaturamento|ServicoFaixaFaturamento $f): array
    {
        return [
            'ordem'           => (int) $f->ordem,
            'limite_superior' => $f->limite_superior !== null ? (float) $f->limite_superior : null,
            'valor'           => (float) $f->valor,
            'valor_e_piso'    => (bool) $f->valor_e_piso,
        ];
    }
}
