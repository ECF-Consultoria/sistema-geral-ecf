<?php

namespace App\Services;

use App\Models\ContratoAssinatura;
use Carbon\Carbon;

/**
 * ContratoPdfService — Fase 126 (Plano 04, PDF-01/PDF-02).
 *
 * Parte pura da geração do PDF do contrato: transforma um `ContratoAssinatura`
 * no array de dados que a view vai renderizar. O plano 126-05 acrescenta
 * `gerar()`/`gerarESalvar()` (Dompdf) em cima deste arquivo — este service
 * NÃO chama `view()`, `Pdf::`, `loadView()` nem `render()` (PDF-02): a
 * montagem de dados é comprovadamente independente de qualquer view, e trocar
 * o texto jurídico do Blade não pode mudar um único dado daqui.
 *
 * D-04 (126-CONTEXT.md): serviços e valores saem EXCLUSIVAMENTE do
 * `servicos_snapshot` congelado em `contrato_assinaturas` — nunca da tabela
 * ao vivo `contratos_servico`. O PDF é função pura do contrato: mesmo
 * contrato, mesmo PDF, sempre. Motivo vivido: um `hs_mrr = 0` do HubSpot já
 * zerou 3 contratos de R$ 3.000 neste projeto quando um valor "ao vivo" foi
 * lido no lugar do valor congelado.
 *
 * Este service não acessa Storage, Log, Cache nem Http — é montagem pura,
 * como o precedente `RelatorioMensalPdfService`.
 */
class ContratoPdfService
{
    /**
     * Texto visível para campos que ainda não existem no banco (D-05 da
     * decisão registrada em 126-04-PLAN.md `<decisao_da_tensao_de_dados>`,
     * Opção C do RESEARCH: parâmetro opcional + placeholder visível). O que
     * não vale, num documento com validade jurídica, é campo em branco
     * silencioso.
     */
    public const PLACEHOLDER = 'A DEFINIR';

    /**
     * Monta o array de dados do contrato a partir do `servicos_snapshot`
     * congelado (D-04), formatado em pt-BR, com placeholder visível para os
     * campos que ainda não existem no banco (dia de vencimento, forma de
     * pagamento, endereço — D-05).
     *
     * @param  array{dia_vencimento?: string, forma_pagamento?: string, endereco?: string}  $complementos
     *         Campos opcionais que a Fase 131 (ADM-01) passa a preencher.
     *         Ausentes → caem no placeholder e entram em `campos_pendentes`.
     * @return array{
     *     empresa: array{razao_social: string, cnpj: string, endereco: string},
     *     contato: array{nome: string, email: string, telefone: string},
     *     servicos: array<int, array{servico: string, valor: float, valor_formatado: string, inicio: string, fim: string}>,
     *     totais: array{valor_mensal_formatado: string},
     *     vigencia: array{inicio: string, fim: string},
     *     pagamento: array{dia_vencimento: string, forma_pagamento: string},
     *     campos_pendentes: array<int, string>,
     *     gerado_em: string
     * }
     *
     * @throws \RuntimeException  Se o contrato não tiver `servicos_snapshot` — gerar PDF sem
     *                             snapshot é erro de sequência (quem grava o snapshot é a Fase
     *                             127), não caso de borda a silenciar.
     */
    public function montarDados(ContratoAssinatura $contrato, array $complementos = []): array
    {
        $snapshot = $contrato->servicos_snapshot;

        if (empty($snapshot)) {
            throw new \RuntimeException(
                "Contrato #{$contrato->id} não tem servicos_snapshot — o PDF não pode ser gerado sem o snapshot congelado (D-04, Fase 127 grava, esta fase só lê)."
            );
        }

        $company = $contrato->company;

        $camposPendentes = [];

        $servicos = $this->montarServicos($snapshot);

        $dados = [
            'empresa' => [
                'razao_social' => $this->resolverOuPendente($company->name ?? null, 'razao_social', $camposPendentes),
                'cnpj'         => $this->resolverOuPendente($company->cnpj ?? null, 'cnpj', $camposPendentes),
                'endereco'     => $this->resolverOuPendente($complementos['endereco'] ?? null, 'endereco', $camposPendentes),
            ],
            'contato' => [
                'nome'     => $this->resolverOuPendente($company->nome_contato ?? null, 'contato_nome', $camposPendentes),
                'email'    => $this->resolverOuPendente($company->email_cliente ?? null, 'contato_email', $camposPendentes),
                'telefone' => $this->resolverOuPendente($company->telefone ?? null, 'contato_telefone', $camposPendentes),
            ],
            'servicos' => $servicos,
            'totais'   => [
                'valor_mensal_formatado' => $this->formatarMoeda($this->somarValores($snapshot)),
            ],
            'vigencia' => $this->montarVigencia($snapshot),
            'pagamento' => [
                'dia_vencimento'  => $this->resolverOuPendente($complementos['dia_vencimento'] ?? null, 'dia_vencimento', $camposPendentes),
                'forma_pagamento' => $this->resolverOuPendente($complementos['forma_pagamento'] ?? null, 'forma_pagamento', $camposPendentes),
            ],
            'gerado_em' => now()->format('d/m/Y H:i'),
        ];

        sort($camposPendentes);
        $dados['campos_pendentes'] = array_values(array_unique($camposPendentes));

        return $dados;
    }

    /**
     * Converte o `servicos_snapshot` (array congelado, D-04) nas entradas
     * de serviço que a view vai listar — nome, valor formatado e vigência
     * individual de cada serviço.
     *
     * @param  array<int, array{servico: string, valor_contratado: float|string, data_contratacao: string, data_vencimento: string}>  $snapshot
     * @return array<int, array{servico: string, valor: float, valor_formatado: string, inicio: string, fim: string}>
     */
    private function montarServicos(array $snapshot): array
    {
        return array_map(function (array $item) {
            $valor = (float) $item['valor_contratado'];

            return [
                'servico'         => $item['servico'],
                'valor'           => $valor,
                'valor_formatado' => $this->formatarMoeda($valor),
                'inicio'          => $this->formatarData($item['data_contratacao']),
                'fim'             => $this->formatarData($item['data_vencimento']),
            ];
        }, $snapshot);
    }

    /**
     * Vigência do contrato inteiro (D-05): a menor `data_contratacao` e a
     * maior `data_vencimento` entre os serviços do snapshot.
     *
     * @param  array<int, array{data_contratacao: string, data_vencimento: string}>  $snapshot
     * @return array{inicio: string, fim: string}
     */
    private function montarVigencia(array $snapshot): array
    {
        $inicios = array_map(fn (array $item) => $item['data_contratacao'], $snapshot);
        $fins    = array_map(fn (array $item) => $item['data_vencimento'], $snapshot);

        sort($inicios);
        sort($fins);

        return [
            'inicio' => $this->formatarData($inicios[0]),
            'fim'    => $this->formatarData($fins[count($fins) - 1]),
        ];
    }

    /**
     * Soma os valores contratados dos serviços do snapshot — o total mensal
     * exibido no contrato.
     *
     * @param  array<int, array{valor_contratado: float|string}>  $snapshot
     */
    private function somarValores(array $snapshot): float
    {
        return array_sum(array_map(fn (array $item) => (float) $item['valor_contratado'], $snapshot));
    }

    /**
     * Devolve `$valor` quando é string não vazia; caso contrário devolve o
     * placeholder `A DEFINIR` e registra `$chave` em `$camposPendentes` (por
     * referência) — é a materialização da decisão da Opção C do RESEARCH:
     * parâmetro opcional + placeholder visível, nunca campo em branco.
     */
    private function resolverOuPendente(?string $valor, string $chave, array &$camposPendentes): string
    {
        if (is_string($valor) && $valor !== '') {
            return $valor;
        }

        $camposPendentes[] = $chave;

        return self::PLACEHOLDER;
    }

    /**
     * Formata valor monetário no padrão pt-BR: `R$ 1.234,56`.
     */
    private function formatarMoeda(float $valor): string
    {
        return 'R$ ' . number_format($valor, 2, ',', '.');
    }

    /**
     * Formata data (string `Y-m-d` ou compatível) no padrão pt-BR: `d/m/Y`.
     */
    private function formatarData(string $data): string
    {
        return Carbon::parse($data)->format('d/m/Y');
    }
}
