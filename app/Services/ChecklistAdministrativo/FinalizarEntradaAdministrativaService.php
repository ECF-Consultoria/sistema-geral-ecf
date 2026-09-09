<?php

namespace App\Services\ChecklistAdministrativo;

use App\Models\ChecklistAdministrativoItem;
use App\Models\Company;
use App\Models\User;
use App\Services\FluxoEntrada\EtapaTransicaoService;
use Illuminate\Support\Facades\Log;

/**
 * FinalizarEntradaAdministrativaService — Fase 139 Plano 06. A trava do
 * botão FINALIZAR ENTRADA ADMINISTRATIVA (ADMIN-05) e o efeito que move a
 * empresa da etapa 4 para a 5 pela única porta permitida (ADMIN-06).
 *
 * Mesmo par régua-pura/efeito de
 * `EtapaTransicaoService::podeTransicionar()`/`transicionar()`
 * ({@see EtapaTransicaoService}, linhas 16-17 do docblock de classe):
 * `podeFinalizar()` é a ÚNICA fonte da condição de habilitação — o
 * `disabled` do botão no frontend recebe o resultado desta função pelo
 * payload da ficha, e a recusa no servidor a reavalia no momento exato do
 * clique. Nunca duas implementações da mesma régua.
 *
 * ⚠️ Este service depende só de {@see ChecklistAdministrativoService} e de
 * {@see EtapaTransicaoService}. Ele NÃO conhece a camada de sincronização
 * de etapa do plano 139-07, que por sua vez depende DELE — a direção é
 * one-way e mantém o grafo de injeção acíclico (mesma disciplina já
 * documentada em `ChecklistAdministrativoService.php:25-32`).
 */
class FinalizarEntradaAdministrativaService
{
    public function __construct(
        private ChecklistAdministrativoService $checklist,
        private EtapaTransicaoService $etapas,
    ) {
    }

    /**
     * Régua PURA da trava do FINALIZAR (ADMIN-05) — zero efeito colateral.
     * Não consulta `companies.etapa`, não escreve nada. É régua de negócio
     * sobre o CHECKLIST, não sobre a máquina de estados.
     *
     * Duas condições, avaliadas NESTA ordem para que o `requisito_faltante`
     * seja o mais útil:
     *
     * 1. **Todos os itens obrigatórios concluídos.** Lida de `progresso()`;
     *    se `feitos < total`, recusa nomeando quantos faltam e citando o
     *    título do primeiro item pendente na ordem do catálogo.
     * 2. **Contrato assinado.** Quando a empresa exige contrato, o item de
     *    chave `contrato_assinado` precisa estar concluído; senão recusa
     *    com "O contrato ainda não está assinado". Quando a empresa é
     *    ISENTA, esta condição é satisfeita por AUSÊNCIA do grupo Contrato
     *    (D-07) — a empresa isenta (hoje só Polos) não tem esse grupo no
     *    checklist, e exigir dela um item que não existe travaria o
     *    FINALIZAR para sempre.
     *
     * Nota de desenho: na prática a condição 2 já é IMPLICADA pela condição
     * 1 quando o grupo Contrato existe, porque o item 3 entra no
     * denominador do progresso. Ela é escrita assim mesmo, em separado,
     * porque o ADMIN-05 nomeia as duas condições e porque
     * "o contrato ainda não está assinado" é a informação que quem olha a
     * tela precisa — não "falta 1 de 9".
     *
     * @return array{permitido: bool, requisito_faltante: ?string}
     */
    public function podeFinalizar(Company $company): array
    {
        $checklist = $this->checklist->paraEmpresa($company);
        $progresso = $checklist['progresso'];

        // 1. Todos os itens obrigatórios concluídos.
        if ($progresso['feitos'] < $progresso['total']) {
            $pendentes = $this->itensPendentes($checklist);

            // Caso especial: o ÚNICO item pendente é justamente o contrato
            // assinado — a mensagem certa é sobre CONTRATO, não sobre
            // contagem. O ADMIN-05 nomeia as duas condições em separado, e
            // "o contrato ainda não está assinado" é a informação que quem
            // olha a tela precisa — não "falta 1 de 9" (nota de desenho no
            // docblock da classe).
            if (count($pendentes) === 1 && $pendentes[0]['chave'] === ChecklistAdministrativoDefinicao::AUTO_FONTE_CONTRATO_ASSINADO) {
                return [
                    'permitido' => false,
                    'requisito_faltante' => 'O contrato ainda não está assinado.',
                ];
            }

            $faltam = $progresso['total'] - $progresso['feitos'];

            return [
                'permitido' => false,
                'requisito_faltante' => sprintf(
                    'Faltam %d de %d itens — o primeiro pendente é "%s".',
                    $faltam,
                    $progresso['total'],
                    $pendentes[0]['titulo']
                ),
            ];
        }

        // 2. Contrato assinado — por ausência do grupo para empresa isenta
        // (D-07), ou pelo item de chave 'contrato_assinado' concluído.
        if ($checklist['exige_contrato']) {
            $itensContrato = $checklist['grupos'][ChecklistAdministrativoDefinicao::GRUPO_CONTRATO]['itens'] ?? [];
            $contratoAssinado = collect($itensContrato)
                ->firstWhere('chave', ChecklistAdministrativoDefinicao::AUTO_FONTE_CONTRATO_ASSINADO);

            $assinado = $contratoAssinado !== null
                && $contratoAssinado['status'] === ChecklistAdministrativoItem::STATUS_CONCLUIDO;

            if (! $assinado) {
                return [
                    'permitido' => false,
                    'requisito_faltante' => 'O contrato ainda não está assinado.',
                ];
            }
        }

        return [
            'permitido' => true,
            'requisito_faltante' => null,
        ];
    }

    /**
     * Resolve o módulo de destino do FINALIZAR (D-12).
     *
     * `is_primary` é guard de Model, não constraint de banco — nada impede
     * duas linhas `is_primary = true` na pivot `company_marketplaces`, e
     * `Company::primaryMarketplace()` usa `->value('marketplace')` SEM
     * `orderBy`, o que devolveria a primeira linha que o motor entregar,
     * sem ordem garantida. A escolha precisa ser DETERMINÍSTICA, ainda que
     * arbitrária: com 2+ linhas `is_primary = true`, a de menor `id` vence
     * e um warning é logado. Com 0 ou 1, delega a
     * `Company::primaryMarketplace()`, que já cai para a coluna flat
     * `companies.marketplace` (`NOT NULL`, `default('meli')`) quando a
     * pivot está vazia.
     *
     * `Company::primaryMarketplace()` NÃO é alterado por esta fase: é
     * método compartilhado por outros módulos, e mudar a ordenação lá teria
     * alcance muito além do FINALIZAR — o desempate mora aqui, onde a
     * decisão foi tomada.
     */
    public function marketplaceDestino(Company $company): ?string
    {
        $quantidade = $company->marketplaces()->where('is_primary', true)->count();

        if ($quantidade > 1) {
            Log::warning('[Checklist] is_primary duplicado na pivot company_marketplaces', [
                'company_id' => $company->id,
                'quantidade' => $quantidade,
            ]);

            return $company->marketplaces()
                ->where('is_primary', true)
                ->orderBy('id')
                ->value('marketplace');
        }

        return $company->primaryMarketplace();
    }

    /**
     * O EFEITO do FINALIZAR (ADMIN-06). Na ordem:
     *
     * 1. Reavalia `podeFinalizar()` — se `permitido` for `false`, devolve
     *    `recusado` SEM ESCREVER NADA.
     * 2. Transiciona a empresa para `Company::ETAPA_AGUARDANDO_DISTRIBUICAO`
     *    exclusivamente por `EtapaTransicaoService::transicionar()`.
     * 3. Se a transição não vier `transicionado`, propaga o `status` e o
     *    `requisito_faltante` do próprio `EtapaTransicaoService` SEM
     *    inventar mensagem nova — mesma disciplina do chamador de produção
     *    já existente (`ComercialController`). O caso mais provável é a
     *    empresa ainda não estar na etapa 4: a tabela de transições
     *    permitidas só deixa chegar na etapa 5 vindo da 4, e quem leva a
     *    empresa até lá é a camada de sincronização de etapa do plano
     *    139-07, chamada PELO CONTROLLER antes deste método (plano 139-08).
     *    Este service não chama aquela camada: ela já é dependente DELE, e
     *    a injeção recíproca fecharia ciclo no container. A orquestração é
     *    do chamador — aqui só se propaga a recusa.
     * 4. Em caso de sucesso, devolve `finalizado` com o destino resolvido
     *    por `marketplaceDestino()`.
     *
     * Proibido neste método (e em todo este arquivo): qualquer
     * `Company::whereKey(...)->update(['etapa' => ...])`, qualquer
     * `$company->etapa = ...` seguido de `save()`, e qualquer
     * `DB::transaction()` envolvendo a chamada a `transicionar()` — o
     * service chamado já é transacional com `lockForUpdate()` por dentro; a
     * mesma empresa é a única a ser afetada (ADMIN-06: mesmo `company_id`,
     * nenhum cadastro novo), e aninhar outra transação que também escreve
     * em `Company` mexeria com locks sem necessidade.
     *
     * @return array{status: string, requisito_faltante: ?string, marketplace_destino: ?string, etapa: string}
     */
    public function finalizar(Company $company, User $por): array
    {
        $avaliacao = $this->podeFinalizar($company);

        if (! $avaliacao['permitido']) {
            return [
                'status' => 'recusado',
                'requisito_faltante' => $avaliacao['requisito_faltante'],
                'marketplace_destino' => null,
                'etapa' => $company->etapa,
            ];
        }

        $resultado = $this->etapas->transicionar($company, Company::ETAPA_AGUARDANDO_DISTRIBUICAO, $por);

        if ($resultado['status'] !== 'transicionado') {
            Log::warning('[Checklist] transição de finalização recusada', [
                'company_id' => $company->id,
                'resultado' => $resultado,
            ]);

            return [
                'status' => $resultado['status'],
                'requisito_faltante' => $resultado['requisito_faltante'],
                'marketplace_destino' => null,
                'etapa' => $company->etapa,
            ];
        }

        return [
            'status' => 'finalizado',
            'requisito_faltante' => null,
            'marketplace_destino' => $this->marketplaceDestino($company),
            'etapa' => Company::ETAPA_AGUARDANDO_DISTRIBUICAO,
        ];
    }

    /**
     * Itens pendentes (status diferente de concluído), na ORDEM DO
     * CATÁLOGO — `grupos` já preserva a ordem 1..9 (Contrato antes de
     * Entrada, cada grupo com seus itens na ordem em que
     * `ChecklistAdministrativoDefinicao::todos()` os declara), então
     * percorrer grupo a grupo, item a item, já é percorrer na ordem certa
     * sem precisar reordenar nada.
     *
     * @return array<int, array{chave: string, titulo: string}>
     */
    private function itensPendentes(array $checklist): array
    {
        $pendentes = [];

        foreach ($checklist['grupos'] as $grupo) {
            foreach ($grupo['itens'] as $item) {
                if ($item['status'] !== ChecklistAdministrativoItem::STATUS_CONCLUIDO) {
                    $pendentes[] = ['chave' => $item['chave'], 'titulo' => $item['titulo']];
                }
            }
        }

        return $pendentes;
    }
}
