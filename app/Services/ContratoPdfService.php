<?php

namespace App\Services;

use App\Models\ContratoAssinatura;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * ContratoPdfService — Fase 126 (Planos 04 e 05, PDF-01/PDF-02/PDF-03).
 *
 * `montarDados()` é a parte pura: transforma um `ContratoAssinatura` no array
 * de dados que a view vai renderizar. Ela NÃO chama `view()`, `Pdf::`,
 * `loadView()` nem `render()` (PDF-02) — prova por asserção estática em
 * `ContratoPdfDadosTest` escopada ao corpo deste método: a montagem de dados
 * é comprovadamente independente de qualquer view, e trocar o texto jurídico
 * do Blade não pode mudar um único dado daqui.
 *
 * `gerar()`/`gerarESalvar()` (plano 126-05) SÃO a parte que renderiza: usam
 * `Pdf::loadView()` e, no caso de `gerarESalvar()`, `Storage::disk('local')`.
 * Isso é intencional e não contradiz a garantia acima — a garantia é sobre
 * `montarDados()` especificamente, não sobre a classe inteira.
 *
 * D-04 (126-CONTEXT.md): serviços e valores saem EXCLUSIVAMENTE do
 * `servicos_snapshot` congelado em `contrato_assinaturas` — nunca da tabela
 * ao vivo `contratos_servico`. O PDF é função pura do contrato: mesmo
 * contrato, mesmo PDF, sempre. Motivo vivido: um `hs_mrr = 0` do HubSpot já
 * zerou 3 contratos de R$ 3.000 neste projeto quando um valor "ao vivo" foi
 * lido no lugar do valor congelado.
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

    /**
     * Renderiza o PDF do contrato via Dompdf — mesmo molde do precedente
     * `RelatorioMensalPdfService`: `Pdf::loadView(...)->setPaper('A4')->output()`.
     * Devolve o binário; quem grava em disco é `gerarESalvar()`.
     */
    public function gerar(ContratoAssinatura $contrato, array $complementos = []): string
    {
        $dados = $this->montarDados($contrato, $complementos);

        $pdf = Pdf::loadView('contratos.pdf', ['dados' => $dados])->setPaper('A4');

        return $pdf->output();
    }

    /**
     * Gera o PDF e grava em `storage/app/contratos/contrato-{id}.pdf`, disco
     * `local` — privado, fora de `public/` (D-06, T-126-20). Anota o caminho
     * relativo em `pdf_path` do contrato e devolve esse caminho.
     *
     * ⚠️ D-02: é ESTE arquivo que vai para a Clicksign e é ele que vale
     * juridicamente — um contrato assinado NUNCA é re-renderizado a partir
     * das views. Trocar o texto de `clausulas.blade.php` depois de um
     * contrato já ter sido gerado não afeta nada do que já foi salvo.
     *
     * Fronteira desta fase: só `pdf_path` é atualizado aqui. Status do
     * contrato, `servicos_snapshot` e qualquer orquestração de envio
     * continuam sendo escopo da Fase 127 — não mexer neles neste método.
     */
    public function gerarESalvar(ContratoAssinatura $contrato, array $complementos = []): string
    {
        $binario = $this->gerar($contrato, $complementos);

        $caminho = "contratos/contrato-{$contrato->id}.pdf";

        Storage::disk('local')->put($caminho, $binario);

        $contrato->update(['pdf_path' => $caminho]);

        return $caminho;
    }
}
