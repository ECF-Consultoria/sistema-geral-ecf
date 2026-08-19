<?php

namespace App\Services;

use App\Models\ContratoAssinatura;
use Carbon\Carbon;

/**
 * ContratoPdfService — Fase 126 (Plano 04, PDF-01/PDF-02/PDF-03; revisado no
 * plano 12 após a reversão D-16/D-17).
 *
 * `montarDados()` é a única responsabilidade que resta nesta classe: transforma
 * um `ContratoAssinatura` no array de dados que preenche o documento. Ela NÃO
 * depende de nenhum motor de renderização local (PDF-02) — prova por asserção
 * estática em `ContratoPdfDadosTest` — porque ela não é mais consumida por uma
 * view Blade local: quem lê este array hoje é `ContratoVariaveisModeloService`,
 * que mapeia os campos para as variáveis do modelo `.docx` cadastrado na
 * Clicksign. Quem RENDERIZA o documento passou a ser a Clicksign, não este
 * serviço.
 *
 * Histórico: o plano 126-05 chegou a adicionar dois métodos de renderização
 * local (removidos no plano 12), que produziam um PDF via Dompdf a partir de
 * duas views Blade (layout + texto jurídico, também removidas). O plano 126-11
 * mediu e aprovou o caminho de modelo da Clicksign ponta a ponta contra a API
 * real, e o plano 126-12 removeu o caminho local — decisão consciente do
 * usuário de manter só um caminho de geração de contrato, nunca dois
 * concorrendo pelo mesmo documento jurídico. A D-02 original (guardar o
 * arquivo renderizado) foi revertida por essa troca, não esquecida.
 *
 * D-04 (126-CONTEXT.md): serviços e valores saem EXCLUSIVAMENTE do
 * `servicos_snapshot` congelado em `contrato_assinaturas` — nunca da tabela
 * ao vivo `contratos_servico`. Os dados são função pura do contrato: mesmo
 * contrato, mesmos dados, sempre. Motivo vivido: um `hs_mrr = 0` do HubSpot já
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
     * campos que ainda não existem no banco (D-05).
     *
     * ⚠️ SUPERADO em 2026-08-19 (Quick 260819-guy): `dia_vencimento`,
     * `data_primeira_parcela` e `razao_social` (com fallback) DEIXARAM de
     * ser "campos que ainda não existem no banco" — as colunas nasceram nas
     * migrations 2026_08_19_100000/100001 e `ContratoDadosMinimosService`
     * já trava a geração até elas serem preenchidas (Tarefa 3 do mesmo
     * quick). O placeholder aqui continua existindo como REDE DE SEGURANÇA
     * (defesa em profundidade: um contrato gerado por um caminho que pule o
     * gate de dados mínimos ainda cai em "A DEFINIR" visível, nunca em
     * branco silencioso), não porque o dado seja opcional.
     *
     * @param  array{dia_vencimento?: string, forma_pagamento?: string, endereco?: string, data_primeira_parcela?: string}  $complementos
     *         `endereco` vem de `Company::endereco` (lido AO VIVO pelo
     *         chamador — é dado de EMPRESA, mesma disciplina de
     *         cnpj/nome_contato/email_cliente abaixo, nunca do snapshot).
     *         `dia_vencimento`/`data_primeira_parcela` vêm do
     *         `servicos_snapshot` CONGELADO (são dado de SERVIÇO — D-04, o
     *         chamador nunca deve lê-los da tabela `contratos_servico` ao
     *         vivo). Ausentes → caem no placeholder e entram em
     *         `campos_pendentes`.
     * @return array{
     *     empresa: array{razao_social: string, cnpj: string, endereco: string},
     *     contato: array{nome: string, email: string, telefone: string},
     *     servicos: array<int, array{servico: string, valor: float, valor_formatado: string, inicio: string, fim: string}>,
     *     totais: array{valor_mensal_formatado: string},
     *     vigencia: array{inicio: string, fim: string},
     *     pagamento: array{dia_vencimento: string, forma_pagamento: string, data_primeira_parcela: string},
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
                // Quick 260819-guy — razão social de verdade
                // (`companies.razao_social`), com fallback para o nome
                // fantasia (`Company::name`) só quando a coluna nova está
                // vazia. Fallback, não pendência: `ContratoDadosMinimosService`
                // já trava a geração sem `razao_social` preenchido (Tarefa
                // 3), então este `??` é rede de segurança, não o caminho
                // esperado.
                'razao_social' => $this->resolverOuPendente($company->razao_social ?? $company->name ?? null, 'razao_social', $camposPendentes),
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
                // Quick 260819-guy — data única (não é "dia do mês" como
                // dia_vencimento), formatada em pt-BR igual às demais datas
                // do documento (mesma disciplina de `formatarData()`).
                'data_primeira_parcela' => $this->resolverDataOuPendente($complementos['data_primeira_parcela'] ?? null, 'data_primeira_parcela', $camposPendentes),
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
     * @param  array<int, array{servico: string, valor_contratado: float|string, data_contratacao: string, data_vencimento: ?string}>  $snapshot
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
     * @param  array<int, array{data_contratacao: string, data_vencimento: ?string}>  $snapshot
     * @return array{inicio: string, fim: string}
     */
    private function montarVigencia(array $snapshot): array
    {
        $inicios = array_map(fn (array $item) => $item['data_contratacao'], $snapshot);
        $fins    = array_map(fn (array $item) => $item['data_vencimento'], $snapshot);

        sort($inicios);

        // Achado do gate do plano 128-06 (medição real, não Http::fake()):
        // `data_vencimento` nulo é caso LEGÍTIMO ("prazo indeterminado" —
        // ContratoDadosMinimosService::faltantes(), item 5) e
        // ContratoClicksignService grava exatamente `null` no snapshot
        // congelado quando o ContratoServico não tem vencimento. Um único
        // serviço em aberto torna a vigência do CONJUNTO indeterminada — não
        // dá para apurar "a maior data" quando uma delas não existe.
        $fimIndeterminado = in_array(null, $fins, true);

        if (!$fimIndeterminado) {
            sort($fins);
        }

        return [
            'inicio' => $this->formatarData($inicios[0]),
            'fim'    => $fimIndeterminado ? $this->formatarData(null) : $this->formatarData($fins[count($fins) - 1]),
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
     * Quick 260819-guy — mesma lógica de `resolverOuPendente()`, mas para um
     * complemento que é DATA (não texto livre): quando presente, formata em
     * pt-BR via `formatarData()` (mesma função usada pelos demais campos de
     * data do documento); ausente/vazio cai no placeholder e entra em
     * `campos_pendentes`, igual a qualquer outro campo pendente.
     */
    private function resolverDataOuPendente(?string $data, string $chave, array &$camposPendentes): string
    {
        if (is_string($data) && $data !== '') {
            return $this->formatarData($data);
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
     *
     * Achado do gate do plano 128-06 (medição real contra o sandbox
     * Clicksign, não `Http::fake()`): esta função assumia `string`
     * obrigatório e quebrava com `TypeError` quando `data_vencimento` do
     * snapshot vinha `null` — o caso legítimo de "prazo indeterminado"
     * (`ContratoDadosMinimosService::faltantes()`, item 5, NÃO reprova esse
     * campo vazio). `null`/`''` viram o texto visível "Indeterminado", nunca
     * um `TypeError` nem um branco silencioso no documento.
     */
    private function formatarData(?string $data): string
    {
        if ($data === null || $data === '') {
            return 'Indeterminado';
        }

        return Carbon::parse($data)->format('d/m/Y');
    }
}
