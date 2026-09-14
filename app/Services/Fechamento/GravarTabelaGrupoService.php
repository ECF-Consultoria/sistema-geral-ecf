<?php

namespace App\Services\Fechamento;

use App\Models\Company;
use App\Models\CompanyGroup;
use App\Models\GrupoFaixaFaturamento;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * GravarTabelaGrupoService — a PORTA ÚNICA de escrita em `grupo_faixas_faturamento`
 * (Fase 143 Plano 03, T1). Gêmeo de `GravarTabelaEmpresaService` (Fase 142 Plano 01).
 *
 * ### Por que a trilha de auditoria é explícita aqui, e não delegada ao `LogsActivity` do model
 * É a MESMA razão do gêmeo de empresa, e vale a pena repetir porque é a razão de existir desta
 * classe: `GrupoFaixaFaturamento::where(...)->delete()` é delete de QUERY BUILDER — não dispara
 * eventos de model (`deleting`/`deleted`), então o `LogsActivity` do model NUNCA vê as linhas
 * apagadas. Até esta classe existir, quem substituía a tabela de um grupo deixava rastro só das
 * linhas NOVAS: **a tabela anterior evaporava sem registro nenhum** — e
 * `TabelaEmpresaContratoController` não tinha uma única chamada a `activity()` no arquivo
 * inteiro. Por isso este serviço registra, ele mesmo, UMA entrada de `activity_log` por
 * gravação, com a tabela INTEIRA `antes` e `depois`: para quem audita uma cobrança errada, uma
 * entrada com a tabela inteira vale mais que sete entradas soltas de linha.
 *
 * ### ⚠️ Por que aqui é mais grave que na empresa
 * Com a árvore de grupos da Fase 143 (`company_groups.parent_id`), a tabela de um grupo-pai
 * governa a cobrança de **todas** as empresas abaixo dele — no caso que abriu a fase
 * (143-CONTEXT, D-02), **10 empresas de uma vez**. Trocar essa tabela sem rastro é trocar a
 * mensalidade de dez clientes sem ninguém saber quem fez, quando, nem qual era o valor anterior.
 * Daí a propriedade `empresas_governadas` na trilha: quem lê o registro precisa enxergar o
 * TAMANHO da decisão, não só o seu conteúdo.
 *
 * ### Diferenças deliberadas em relação ao gêmeo de empresa
 * `grupo_faixas_faturamento` **não tem coluna `origem`** (e não ganha uma nesta fase) — tabela de
 * grupo é sempre cadastro humano. Logo, não há trava de precedência das três origens (D-05 da
 * Fase 141) a aplicar aqui, e nem `origem_anterior`/`origem_nova` na trilha. O resto — transação,
 * all-or-nothing, uma entrada por gravação — é idêntico de propósito.
 *
 * @see app/Services/Fechamento/GravarTabelaEmpresaService.php (o gêmeo, e o molde)
 * @see app/Models/CompanyGroup.php (a árvore de um nível, Fase 143)
 */
class GravarTabelaGrupoService
{
    /** `log_name` próprio desta trilha, irmão de `faixa_faturamento_tabela` (empresa). */
    public const LOG_NAME = 'faixa_faturamento_tabela_grupo';

    /**
     * Grava a tabela de faixas de um grupo (all-or-nothing) e registra a trilha de auditoria.
     *
     * @param  array<int, array{ordem:int, limite_superior?:float|null, valor:float, valor_e_piso?:bool}>  $faixas
     * @return array{substituiu: bool, quantidade_anterior: int, quantidade: int, empresas_governadas: int}
     */
    public function gravar(
        CompanyGroup $grupo,
        array $faixas,
        ?User $por = null,
        string $feitoDe = 'contrato_ficha',
    ): array {
        // All-or-nothing (D-13 da Fase 137): array vazio nunca "apaga por descuido" — quem quer
        // apagar usa remover(), que registra intenção e trilha próprias.
        if ($faixas === []) {
            throw new \RuntimeException(
                "Gravação recusada: array de faixas vazio para o grupo \"{$grupo->name}\" (id {$grupo->id}). "
                .'A tabela é all-or-nothing — para apagar, use remover().'
            );
        }

        return DB::transaction(function () use ($grupo, $faixas, $por, $feitoDe) {
            $linhasAtuais = GrupoFaixaFaturamento::where('company_group_id', $grupo->id)
                ->ordenadas()
                ->get();

            GrupoFaixaFaturamento::where('company_group_id', $grupo->id)->delete();

            $linhasNovas = new Collection();

            foreach ($faixas as $faixa) {
                $linhasNovas->push(GrupoFaixaFaturamento::create([
                    'company_group_id' => $grupo->id,
                    'ordem'            => $faixa['ordem'],
                    'limite_superior'  => $faixa['limite_superior'] ?? null,
                    'valor'            => $faixa['valor'],
                    'valor_e_piso'     => $faixa['valor_e_piso'] ?? false,
                ]));
            }

            $empresasGovernadas = $this->contarEmpresasGovernadas($grupo);

            $this->registrarAuditoria($grupo, $linhasAtuais, $linhasNovas, $empresasGovernadas, $por, $feitoDe);

            return [
                'substituiu'          => $linhasAtuais->isNotEmpty(),
                'quantidade_anterior' => $linhasAtuais->count(),
                'quantidade'          => $linhasNovas->count(),
                'empresas_governadas' => $empresasGovernadas,
            ];
        });
    }

    /**
     * Apaga a tabela de faixas do grupo e registra a mesma trilha de auditoria, com
     * `depois = []`.
     *
     * @return array{quantidade_anterior: int, empresas_governadas: int}
     */
    public function remover(CompanyGroup $grupo, ?User $por = null, string $feitoDe = 'contrato_ficha'): array
    {
        return DB::transaction(function () use ($grupo, $por, $feitoDe) {
            $linhasAtuais = GrupoFaixaFaturamento::where('company_group_id', $grupo->id)
                ->ordenadas()
                ->get();

            GrupoFaixaFaturamento::where('company_group_id', $grupo->id)->delete();

            $empresasGovernadas = $this->contarEmpresasGovernadas($grupo);

            $this->registrarAuditoria($grupo, $linhasAtuais, new Collection(), $empresasGovernadas, $por, $feitoDe);

            return [
                'quantidade_anterior' => $linhasAtuais->count(),
                'empresas_governadas' => $empresasGovernadas,
            ];
        });
    }

    /**
     * Quantas empresas esta tabela alcança: as do próprio grupo MAIS as dos grupos pendurados
     * nele (Fase 143 — um nível só, então esta conta não caminha em profundidade).
     *
     * ⚠️ Vai para a trilha de propósito: é o número que diz se a pessoa mexeu na mensalidade de
     * duas empresas ou de dez. Uma query só, e fora do laço de consolidação (este serviço roda
     * uma vez por clique de tela, nunca dentro de laço).
     */
    private function contarEmpresasGovernadas(CompanyGroup $grupo): int
    {
        $ids = CompanyGroup::query()
            ->where('id', $grupo->id)
            ->orWhere('parent_id', $grupo->id)
            ->pluck('id');

        return Company::whereIn('company_group_id', $ids)->count();
    }

    /**
     * Registra UMA entrada de `activity_log` por gravação/remoção, com a tabela inteira antes e
     * depois — fecha o buraco de auditoria descrito no docblock da classe.
     * `log_name = 'faixa_faturamento_tabela_grupo'`, sujeito (`performedOn`) é o `CompanyGroup`.
     */
    private function registrarAuditoria(
        CompanyGroup $grupo,
        Collection $linhasAntes,
        Collection $linhasDepois,
        int $empresasGovernadas,
        ?User $por,
        string $feitoDe,
    ): void {
        $mapear = fn (Collection $linhas): array => $linhas
            ->map(fn (GrupoFaixaFaturamento $f): array => [
                'ordem'           => $f->ordem,
                'limite_superior' => $f->limite_superior !== null ? (float) $f->limite_superior : null,
                'valor'           => (float) $f->valor,
                'valor_e_piso'    => (bool) $f->valor_e_piso,
            ])
            ->values()
            ->all();

        $descricao = $linhasDepois->isEmpty()
            ? "Tabela progressiva do grupo \"{$grupo->name}\" (id {$grupo->id}) removida — {$linhasAntes->count()} faixa(s) apagada(s), {$empresasGovernadas} empresa(s) alcançada(s)."
            : "Tabela progressiva do grupo \"{$grupo->name}\" (id {$grupo->id}) gravada — {$linhasDepois->count()} faixa(s), {$empresasGovernadas} empresa(s) alcançada(s).";

        activity(self::LOG_NAME)
            ->performedOn($grupo)
            ->causedBy($por)
            ->withProperties([
                'antes'               => $mapear($linhasAntes),
                'depois'              => $mapear($linhasDepois),
                'empresas_governadas' => $empresasGovernadas,
                'feito_de'            => $feitoDe,
            ])
            ->log($descricao);
    }
}
