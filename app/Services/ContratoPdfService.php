<?php

namespace App\Services;

use App\Models\ContratoAssinatura;
// Quick 260824-bte — só para a constante PLANO_PARCELAS_CASO_SIMPLES (leitura
// de constante, nunca instanciada aqui); não inverte a dependência real, que
// continua sendo ContratoVariaveisModeloService -> ContratoPdfService via
// injeção de construtor.
use App\Services\Clicksign\ContratoVariaveisModeloService;
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
     * @param  array{dia_vencimento?: string, forma_pagamento?: string, endereco?: string, bairro?: string, cidade?: string, estado?: string, cep?: string, data_primeira_parcela?: string}  $complementos
     *         `endereco`/`bairro`/`cidade`/`estado`/`cep` vêm de
     *         `Company::endereco`/`bairro`/`cidade`/`estado`/`cep` (lidos AO
     *         VIVO pelo chamador — dado de EMPRESA, mesma disciplina de
     *         cnpj/nome_contato/email_cliente abaixo, nunca do snapshot).
     *         Quick 260821-cq0 — `endereco` aqui é só o LOGRADOURO (rua e
     *         número); os outros 4 pedaços do endereço entram como campos
     *         próprios, mesma disciplina de placeholder/pendência.
     *         `dia_vencimento`/`data_primeira_parcela` vêm do
     *         `servicos_snapshot` CONGELADO (são dado de SERVIÇO — D-04, o
     *         chamador nunca deve lê-los da tabela `contratos_servico` ao
     *         vivo). Ausentes → caem no placeholder e entram em
     *         `campos_pendentes`.
     * @return array{
     *     empresa: array{razao_social: string, cnpj: string, endereco: string, bairro: string, cidade: string, estado: string, cep: string},
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
                // Quick 260821-cq0 — 4 pedaços do endereço que voltaram a
                // ser variáveis próprias do modelo `.docx`, mesma disciplina
                // de `resolverOuPendente()` que `endereco` já usa.
                'bairro'       => $this->resolverOuPendente($complementos['bairro'] ?? null, 'bairro', $camposPendentes),
                'cidade'       => $this->resolverOuPendente($complementos['cidade'] ?? null, 'cidade', $camposPendentes),
                'estado'       => $this->resolverOuPendente($complementos['estado'] ?? null, 'estado', $camposPendentes),
                'cep'          => $this->resolverOuPendente($complementos['cep'] ?? null, 'cep', $camposPendentes),
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
                // Quick 260824-bte — texto de {{plano_parcelas}}: override
                // literal (`contrato->plano_parcelas_texto`) ou composto a
                // partir das fases do snapshot (D-06/D-10 herdado, nunca
                // recalcula de ContratoServico ao vivo). Nunca pendente —
                // sempre tem um valor (o caso simples é a frase constante
                // de ContratoVariaveisModeloService).
                'plano_parcelas' => $this->planoParcelas($contrato),
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
     * Quick 260824-bte — somar serviços DIFERENTES continua certo (Gestão +
     * Mentoria = soma), mas somar FASES do mesmo serviço (pagamento
     * escalonado, quick 260824-bte) está errado: daria, por exemplo,
     * R$ 11.500,00 para um caso de 3 parcelas de R$ 5.500 + 9 de R$ 6.000,
     * valor que nunca é cobrado. Por serviço vale a PRIMEIRA fase (a
     * primeira ocorrência do nome no snapshot — a ordem é responsabilidade
     * de quem grava o snapshot, `ContratoClicksignService`). Snapshot
     * legado (uma fase só) soma normalmente, sem mudança de comportamento.
     *
     * @param  array<int, array{servico: string, valor_contratado: float|string}>  $snapshot
     */
    private function somarValores(array $snapshot): float
    {
        $valorPorServico = [];

        foreach ($snapshot as $item) {
            $nome = $item['servico'];

            if (array_key_exists($nome, $valorPorServico)) {
                continue; // fase seguinte do mesmo serviço — já contabilizada pela primeira.
            }

            $valorPorServico[$nome] = (float) $item['valor_contratado'];
        }

        return array_sum($valorPorServico);
    }

    /**
     * Texto de `{{plano_parcelas}}` (Quick 260824-bte, Tarefa 3): override
     * literal quando `$contrato->plano_parcelas_texto` está preenchido —
     * "guardar o override como fato, sem sobrescrever o composto" (D-06/
     * plano) —, senão o texto composto a partir das fases do snapshot
     * congelado.
     *
     * PÚBLICO de propósito: além de `montarDados()` usar internamente,
     * `ContratoAdminController::show()` chama direto para mostrar na tela o
     * texto EFETIVO atual (override ou composto) no campo editável — sem
     * precisar montar o array inteiro de `montarDados()` (que exige
     * `$complementos` de empresa que a tela de detalhe não tem à mão nesse
     * ponto).
     */
    public function planoParcelas(ContratoAssinatura $contrato): string
    {
        $override = $contrato->plano_parcelas_texto;

        if (is_string($override) && trim($override) !== '') {
            return $override;
        }

        return $this->comporPlanoParcelasDasFases((array) ($contrato->servicos_snapshot ?? []));
    }

    /**
     * Compõe a frase de `{{plano_parcelas}}` a partir das fases ORDENADAS
     * do `servicos_snapshot` (a ordem já vem certa de
     * `ContratoClicksignService::iniciarParaEmpresa()` — esta função nunca
     * reordena).
     *
     * Precedente real do jurídico (contrato de Mentoria já assinado, ver
     * `260824-bte-PLAN.md`): quantidade em dígito + por extenso entre
     * parênteses, valor no formato `R$ 0.000,00`, sem valor por extenso
     * (isso é do modelo de Mentoria, não do de Gestão onde a variável
     * vive).
     *
     * - 1 fase (ou snapshot legado de uma fase só): a constante
     *   `ContratoVariaveisModeloService::PLANO_PARCELAS_CASO_SIMPLES` —
     *   comportamento idêntico ao de antes deste quick.
     * - N fases, todas com quantidade de parcelas conhecida (`parcelas`
     *   não nulo — vem de `hs_recurring_billing_period`, 'P<N>M' -> N):
     *   "As 3 (três) primeiras parcelas corresponderão a R$ 5.500,00 e as 9
     *   (nove) demais a R$ 6.000,00.".
     * - Última fase SEM `parcelas` (período não definido no HubSpot —
     *   "as demais voltam à faixa"): termina em "...e as demais seguirão a
     *   faixa apurada na forma da Cláusula 2.1.2.".
     *
     * @param  array<int, array{servico?: string, valor_contratado: float|string, parcelas?: ?int}>  $snapshot
     */
    private function comporPlanoParcelasDasFases(array $snapshot): string
    {
        // Guarda: a composição só faz sentido para FASES DO MESMO SERVIÇO
        // (pagamento escalonado, quick 260824-bte). Um snapshot com nomes de
        // serviço DIFERENTES (o caso multi-serviço "um envelope por
        // empresa", D-19, ainda suportado por `montarDados()` de forma
        // genérica) não é "parcelamento" — cai no caso simples, IDÊNTICO ao
        // comportamento de antes deste quick.
        $nomesDistintos = count(array_unique(array_column($snapshot, 'servico')));

        if (count($snapshot) <= 1 || $nomesDistintos !== 1) {
            return ContratoVariaveisModeloService::PLANO_PARCELAS_CASO_SIMPLES;
        }

        $partes = [];
        $ultimoIndice = count($snapshot) - 1;
        $fases = array_values($snapshot);

        foreach ($fases as $indice => $fase) {
            $ehUltima = $indice === $ultimoIndice;
            $parcelas = $fase['parcelas'] ?? null;

            if ($ehUltima && $parcelas === null) {
                $partes[] = 'as demais seguirão a faixa apurada na forma da Cláusula 2.1.2';

                continue;
            }

            $qtd = (int) $parcelas;
            // Quick 260824-bte (correção pós-deploy) — "parcelas" é
            // substantivo FEMININO em pt-BR ("2 (duas) primeiras
            // parcelas", nunca "2 (dois)"). `numeroPorExtenso()` é
            // genérico (masculino por padrão); quem conta parcelas usa
            // este wrapper dedicado, nunca a forma crua.
            $extenso = $this->quantidadeDeParcelasPorExtenso($qtd);
            $valorFormatado = $this->formatarMoeda((float) $fase['valor_contratado']);

            if ($indice === 0) {
                $partes[] = "As {$qtd} ({$extenso}) primeiras parcelas corresponderão a {$valorFormatado}";
            } elseif ($ehUltima) {
                $partes[] = "as {$qtd} ({$extenso}) demais a {$valorFormatado}";
            } else {
                $partes[] = "as {$qtd} ({$extenso}) seguintes a {$valorFormatado}";
            }
        }

        if (count($partes) === 1) {
            return $partes[0] . '.';
        }

        $ultimaParte = array_pop($partes);

        return implode(', ', $partes) . ' e ' . $ultimaParte . '.';
    }

    /**
     * `numeroPorExtenso()` especializado para CONTAR PARCELAS — wrapper
     * dedicado, não um parâmetro solto na assinatura genérica.
     *
     * pt-BR: "parcela" é substantivo FEMININO. Só "um/uma" e "dois/duas"
     * flexionam em gênero nessa faixa (3 a 99 são invariáveis: "três
     * parcelas", nunca "treis"/"trêsa"), MAS os COMPOSTOS herdam a flexão
     * da unidade: "21 parcelas" -> "vinte e uma", "22 parcelas" -> "vinte e
     * duas", "31" -> "trinta e uma", etc. `numeroPorExtenso(feminino: true)`
     * já resolve isso — este método existe só para que quem monta a frase
     * de `{{plano_parcelas}}` nunca precise lembrar de passar a flag.
     *
     * Achado real (quick 260824-bte, correção pós-deploy): a primeira versão
     * usava a forma masculina fixa e produzia "2 (dois) primeiras parcelas"
     * — errado. Documentado aqui para que ninguém "corrija" de volta para o
     * masculino achando que é erro de digitação.
     */
    private function quantidadeDeParcelasPorExtenso(int $numero): string
    {
        return $this->numeroPorExtenso($numero, feminino: true);
    }

    /**
     * Número por extenso em pt-BR, minúsculo, para a quantidade de parcelas
     * entre parênteses (ex.: "3 (três)"). Cobre 0–99 — mais que suficiente
     * para quantidade de parcelas de um contrato; qualquer valor fora dessa
     * faixa cai no próprio dígito (nunca quebra, só perde o "por extenso").
     *
     * `$feminino` flexiona 1 e 2 (e os compostos que terminam neles: 21, 22,
     * 31, 32, ...) para "uma"/"duas" — de 3 a 99 o numeral é invariável em
     * pt-BR, então só essas duas unidades (e o resto do composto) mudam.
     * Default `false` (masculino) para não alterar nenhuma outra chamada
     * existente — quem precisa da forma feminina usa
     * `quantidadeDeParcelasPorExtenso()`, nunca esta flag direto.
     */
    private function numeroPorExtenso(int $numero, bool $feminino = false): string
    {
        $unidades = [
            0 => 'zero', 1 => 'um', 2 => 'dois', 3 => 'três', 4 => 'quatro',
            5 => 'cinco', 6 => 'seis', 7 => 'sete', 8 => 'oito', 9 => 'nove',
            10 => 'dez', 11 => 'onze', 12 => 'doze', 13 => 'treze', 14 => 'quatorze',
            15 => 'quinze', 16 => 'dezesseis', 17 => 'dezessete', 18 => 'dezoito', 19 => 'dezenove',
        ];
        $dezenas = [
            20 => 'vinte', 30 => 'trinta', 40 => 'quarenta', 50 => 'cinquenta',
            60 => 'sessenta', 70 => 'setenta', 80 => 'oitenta', 90 => 'noventa',
        ];

        // Únicas flexões de gênero da faixa 0-19 ("uma"/"duas") — 0 e de 3 a
        // 19 são invariáveis.
        if ($feminino) {
            $unidades[1] = 'uma';
            $unidades[2] = 'duas';
        }

        if ($numero < 20) {
            return $unidades[$numero] ?? (string) $numero;
        }

        if ($numero < 100) {
            $dezena = intdiv($numero, 10) * 10;
            $resto   = $numero % 10;

            if ($resto === 0) {
                return $dezenas[$dezena] ?? (string) $numero;
            }

            // O composto HERDA a flexão da unidade ($unidades já está
            // flexionado acima quando $feminino) — "vinte e uma", "trinta e
            // duas", nunca "vinte e um"/"trinta e dois" para contagem de
            // parcelas.
            return ($dezenas[$dezena] ?? (string) $dezena) . ' e ' . ($unidades[$resto] ?? (string) $resto);
        }

        return (string) $numero;
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
