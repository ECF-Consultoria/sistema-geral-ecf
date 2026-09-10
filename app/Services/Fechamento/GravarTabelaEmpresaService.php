<?php

namespace App\Services\Fechamento;

use App\Models\Company;
use App\Models\EmpresaFaixaFaturamento;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * GravarTabelaEmpresaService — a PORTA ÚNICA de escrita em `empresa_faixas_faturamento`
 * (Fase 142 Plano 01).
 *
 * Antes deste serviço havia DOIS gravadores independentes —
 * `FechamentoController::salvarFaixasEmpresa()` (cadastro manual) e
 * `TabelasContratoController::confirmar()` (confirmação de leitura do contrato) — cada um
 * repetindo `delete()` + `foreach create()` com sua própria regra de `origem`. A ficha nova por
 * empresa dentro do módulo de contratos (planos 142-02/03) seria o TERCEIRO. Centralizar aqui é
 * o que impede a divergência entre as telas que cadastram tabela — ver `<decisao_de_projeto>` do
 * `142-01-PLAN.md`: "Uma única porta de escrita no código... precedência, all-or-nothing e
 * trilha moram lá, uma vez só."
 *
 * ### Por que a trilha de auditoria é explícita aqui, e não delegada ao `LogsActivity` do model
 * `EmpresaFaixaFaturamento::where(...)->delete()` é delete de QUERY BUILDER — não dispara
 * eventos de model (`deleting`/`deleted`), então o `LogsActivity` do model NUNCA vê as linhas
 * apagadas. Antes deste serviço, quem substituía a tabela de uma empresa deixava rastro só das
 * linhas novas: a tabela anterior evaporava sem registro nenhum. Numa tela que edita cobrança
 * viva de 169 empresas isso não pode continuar (achado do 142-01-PLAN.md). Por isso este
 * serviço registra, ele mesmo, UMA entrada de `activity_log` por gravação — com a tabela
 * INTEIRA `antes` e `depois` — em vez de confiar no log por linha do model: uma entrada com a
 * tabela inteira vale mais, para quem audita uma cobrança errada, que sete entradas soltas.
 */
class GravarTabelaEmpresaService
{
    /**
     * Grava a tabela de faixas de uma empresa (all-or-nothing), aplicando a trava de
     * precedência das três origens (D-05 da Fase 141) e registrando a trilha de auditoria.
     *
     * @param  array<int, array{ordem:int, limite_superior?:float|null, valor:float, valor_e_piso?:bool}>  $faixas
     * @return array{substituiu_confirmada: bool, origem_anterior: ?string, quantidade: int}
     */
    public function gravar(
        Company $company,
        array $faixas,
        string $origem,
        ?int $servicoOrigemId = null,
        ?User $por = null,
        string $feitoDe = 'contrato_ficha',
    ): array {
        // All-or-nothing (D-13 da Fase 137): array vazio nunca "apaga por descuido" — quem quer
        // apagar usa remover(), que registra intenção e trilha próprias.
        if ($faixas === []) {
            throw new \RuntimeException(
                "Gravação recusada: array de faixas vazio para a empresa \"{$company->name}\" (id {$company->id}). "
                .'A tabela é all-or-nothing — para apagar, use remover().'
            );
        }

        return DB::transaction(function () use ($company, $faixas, $origem, $servicoOrigemId, $por, $feitoDe) {
            $linhasAtuais = EmpresaFaixaFaturamento::where('company_id', $company->id)
                ->ordenadas()
                ->get();

            // Todas as linhas de uma empresa compartilham a mesma origem (mesma disciplina de
            // `FechamentoFaixaResolver::paraEmpresa()`, que lê `$excecaoPropria->first()->origem`).
            $origemAnterior = $linhasAtuais->first()->origem ?? null;

            $this->aplicarTravaDePrecedencia($company, $origem, $origemAnterior);

            // "Confirmada" = tabela que um humano cadastrou (manual) ou confirmou pela leitura
            // do contrato (contrato) — as duas únicas origens que não são presunção. A tela usa
            // esta flag para avisar antes de substituir; o serviço não recusa (ato humano
            // deliberado pode substituir qualquer coisa, inclusive outra tabela confirmada).
            $substituiuConfirmada = in_array($origemAnterior, [
                EmpresaFaixaFaturamento::ORIGEM_MANUAL,
                EmpresaFaixaFaturamento::ORIGEM_CONTRATO,
            ], true);

            EmpresaFaixaFaturamento::where('company_id', $company->id)->delete();

            $linhasNovas = new Collection();

            foreach ($faixas as $faixa) {
                $linhasNovas->push(EmpresaFaixaFaturamento::create([
                    'company_id'        => $company->id,
                    'ordem'             => $faixa['ordem'],
                    'limite_superior'   => $faixa['limite_superior'] ?? null,
                    'valor'             => $faixa['valor'],
                    'valor_e_piso'      => $faixa['valor_e_piso'] ?? false,
                    'origem'            => $origem,
                    'servico_origem_id' => $servicoOrigemId,
                ]));
            }

            $this->registrarAuditoria($company, $linhasAtuais, $linhasNovas, $origemAnterior, $origem, $por, $feitoDe);

            return [
                'substituiu_confirmada' => $substituiuConfirmada,
                'origem_anterior'       => $origemAnterior,
                'quantidade'            => $linhasNovas->count(),
            ];
        });
    }

    /**
     * Apaga a tabela de faixas da empresa e registra a mesma trilha de auditoria, com
     * `depois = []`.
     */
    public function remover(Company $company, ?User $por = null, string $feitoDe = 'contrato_ficha'): void
    {
        DB::transaction(function () use ($company, $por, $feitoDe) {
            $linhasAtuais = EmpresaFaixaFaturamento::where('company_id', $company->id)
                ->ordenadas()
                ->get();

            $origemAnterior = $linhasAtuais->first()->origem ?? null;

            EmpresaFaixaFaturamento::where('company_id', $company->id)->delete();

            $this->registrarAuditoria($company, $linhasAtuais, new Collection(), $origemAnterior, null, $por, $feitoDe);
        });
    }

    /**
     * Trava de precedência (D-05 da Fase 141, restrição permanente do 142-CONTEXT): presunção
     * do serviço nunca sobrescreve tabela que um humano confirmou. `manual` e `contrato` são
     * ato humano deliberado e podem substituir qualquer coisa — o aviso de "você está trocando
     * uma tabela conferida pelo contrato" é responsabilidade da tela, alimentada por
     * `substituiu_confirmada`.
     */
    private function aplicarTravaDePrecedencia(Company $company, string $origemNova, ?string $origemAnterior): void
    {
        $anteriorEraConfirmada = in_array($origemAnterior, [
            EmpresaFaixaFaturamento::ORIGEM_MANUAL,
            EmpresaFaixaFaturamento::ORIGEM_CONTRATO,
        ], true);

        if ($origemNova === EmpresaFaixaFaturamento::ORIGEM_PRESUMIDA_SERVICO && $anteriorEraConfirmada) {
            throw new \RuntimeException(
                "Gravação recusada: a tabela da empresa \"{$company->name}\" (id {$company->id}) já foi confirmada "
                ."(origem atual '{$origemAnterior}') — uma presunção do serviço nunca pode sobrescrever tabela confirmada."
            );
        }
    }

    /**
     * Registra UMA entrada de `activity_log` por gravação/remoção, com a tabela inteira antes e
     * depois — fecha o buraco de auditoria descoberto na leitura do código (ver docblock da
     * classe). `log_name = 'faixa_faturamento_tabela'`, sujeito (`performedOn`) é a `Company`.
     */
    private function registrarAuditoria(
        Company $company,
        Collection $linhasAntes,
        Collection $linhasDepois,
        ?string $origemAnterior,
        ?string $origemNova,
        ?User $por,
        string $feitoDe,
    ): void {
        $mapear = fn (Collection $linhas): array => $linhas
            ->map(fn (EmpresaFaixaFaturamento $f): array => [
                'ordem'           => $f->ordem,
                'limite_superior' => $f->limite_superior !== null ? (float) $f->limite_superior : null,
                'valor'           => (float) $f->valor,
                'valor_e_piso'    => (bool) $f->valor_e_piso,
            ])
            ->values()
            ->all();

        $descricao = $origemNova === null
            ? "Tabela progressiva da empresa \"{$company->name}\" (id {$company->id}) removida — {$linhasAntes->count()} faixa(s) apagada(s)."
            : "Tabela progressiva da empresa \"{$company->name}\" (id {$company->id}) gravada — {$linhasDepois->count()} faixa(s), origem \"{$origemNova}\".";

        activity('faixa_faturamento_tabela')
            ->performedOn($company)
            ->causedBy($por)
            ->withProperties([
                'antes'           => $mapear($linhasAntes),
                'depois'          => $mapear($linhasDepois),
                'origem_anterior' => $origemAnterior,
                'origem_nova'     => $origemNova,
                'feito_de'        => $feitoDe,
            ])
            ->log($descricao);
    }
}
