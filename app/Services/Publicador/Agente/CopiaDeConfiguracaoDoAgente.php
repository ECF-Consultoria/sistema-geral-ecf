<?php

namespace App\Services\Publicador\Agente;

use App\Models\PubAgenteConfiguracao;
use App\Services\Publicador\ProgramasPublicadorService;

/**
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 * COPIAR A CONFIGURAÇÃO DE OUTRA LOJA **COMO RASCUNHO** (E1, 23/10/2026).
 *
 * Linha *"Rascunho/impacto/cópia"* da §7.1 do plano do Agente: *"copiar configurações compatíveis
 * como rascunho"*.
 *
 * ═══ ⚠️⚠️ "COMO RASCUNHO" É LITERAL: ESTE SERVIÇO NÃO GRAVA NADA ══════════════════════════════
 *
 * Ele **lê** a configuração da loja de origem e **devolve** os valores compatíveis para o
 * formulário. Quem salva é a pessoa, pelo caminho normal — com a `versao_esperada` que ela viu, e
 * portanto com a mesma proteção de concorrência de sempre (`AG-01-VERSAO`).
 *
 * ⚠️ **Por que isso importa, e não é só elegância:** copiar que GRAVA mudaria o comportamento de
 * uma loja em produção com **um clique e sem conferência**. Numa tela em que a configuração
 * decide o que o agente prepara sozinho, isso é a diferença entre "me dá um ponto de partida" e
 * "mude a loja agora". O plano pede a primeira.
 *
 * ⚠️ E é **proteção dupla**: além de não gravar, o rascunho vem com `habilitado = false`
 * (*"regras copiadas começam inativas"*, §7.1). Mesmo que alguém salve sem ler, o agente não
 * liga sozinho na loja de destino.
 *
 * ═══ ⚠️ O QUE **NÃO** É COPIADO, E O MOTIVO DE CADA UM ═════════════════════════════════════════
 *
 * O plano é explícito: *"copiar configuração não copia credenciais, permissões de outra loja, IDs
 * de produtos/famílias inválidos, pareceres, execuções ou dados comerciais"*.
 *
 * | não copia          | por quê                                                                      |
 * |--------------------|------------------------------------------------------------------------------|
 * | `escopo`           | ⚠️ **são `pub_produto_id` DA LOJA DE ORIGEM.** Copiados, apontariam para produtos de outra loja — o caso literal de *"IDs de produtos inválidos"*. Pior: um `incluir` com ids de fora restringiria o agente a um conjunto **vazio** na loja de destino, e ele pareceria quebrado sem erro nenhum. |
 * | `habilitado`       | *"regras copiadas começam inativas"*. Vem sempre `false`.                     |
 * | `controle`         | pausado/suspenso é estado **desta** loja; herdar o da outra seria inventar um motivo. |
 * | `motivo_controle`  | é texto escrito sobre OUTRA loja — copiá-lo é atribuir a alguém uma frase que não escreveu. |
 * | `versao`           | a linha de versões do destino é dele; herdar número quebraria a trava de concorrência. |
 * | `conta_chave`, `company_id`, `mlb_empresa_id` | identidade da conta.                   |
 * | `atualizado_por`, `created_at`, `updated_at`  | autoria e datas são do ato de salvar.   |
 *
 * ═══ O QUE É COPIADO ══════════════════════════════════════════════════════════════════════════
 *
 * Só o que **significa a mesma coisa em qualquer loja**: a modalidade, os três limites numéricos
 * e a agenda (janela de hora e dias da semana). Nenhum deles referencia nada da loja de origem.
 */
class CopiaDeConfiguracaoDoAgente
{
    /**
     * ⚠️ Lista CONSTANTE do que viaja. Nunca `$cfg->toArray()` menos uma lista de exclusão: com a
     * exclusão, **coluna nova entra na cópia por omissão** — e a primeira coluna nova que não
     * puder ser copiada vazaria em silêncio. Aqui, coluna nova só viaja se alguém a escrever aqui.
     */
    public const COPIAVEIS = [
        'modalidade', 'estoque_minimo', 'limite_diario', 'teto_fila',
        'janela_inicio', 'janela_fim', 'dias_semana',
    ];

    /**
     * ⚠️ O que o rascunho FORÇA, independentemente da origem. Hoje é só o liga/desliga, e está
     * numa constante própria para o motivo (*"regras copiadas começam inativas"*) ficar visível
     * ao lado do valor — e para um gate poder cobrá-lo por nome.
     */
    public const FORCADOS = ['habilitado' => false];

    public function __construct(private ProgramasPublicadorService $programas) {}

    /**
     * As lojas de onde dá para copiar: as que **têm configuração salva** e não são a própria.
     *
     * ⚠️ Devolve só **identidade** (`chave` + `nome`), nunca os valores. A tela não precisa deles
     * para montar o seletor, e mandá-los faria a página carregar a configuração de todas as lojas
     * — mais dado do que a pessoa pediu, sem necessidade.
     *
     * @return list<array{chave: string, nome: string}>
     */
    public function origensPossiveis(array $contaDestino): array
    {
        $chaves = PubAgenteConfiguracao::query()
            ->where('conta_chave', '!=', $contaDestino['chave'])
            ->orderBy('conta_chave')
            ->pluck('conta_chave');

        $lojas = [];
        foreach ($chaves as $chave) {
            // ⚠️ Flag DENTRO do laço. E a conta é RESOLVIDA: configuração órfã (empresa desativada,
            // Company apagada) não pode aparecer no seletor como se fosse uma loja viva.
            $alvo = $this->programas->resolver((string) $chave);
            if ($alvo === null) {
                continue;
            }

            $empresa = $this->programas->empresaParaTela($alvo);
            $nome = is_string($empresa['nome'] ?? null) && trim($empresa['nome']) !== ''
                ? $empresa['nome']
                : (string) $chave;

            $lojas[] = ['chave' => (string) $chave, 'nome' => $nome];
        }

        return $lojas;
    }

    /**
     * O rascunho a partir de outra loja — ou `null` quando não há de onde copiar.
     *
     * ⚠️ **Nada é gravado.** O retorno é para o formulário.
     *
     * @return array{valores: array<string,mixed>, origem: array{chave: string, nome: string}, nao_copiados: list<string>}|null
     */
    public function rascunho(array $contaDestino, ?string $chaveOrigem): ?array
    {
        if (! is_string($chaveOrigem) || trim($chaveOrigem) === '') {
            return null;
        }

        // ⚠️ Copiar de si mesma não é erro — é pedido sem efeito. Devolver `null` evita um rascunho
        // que "muda" a tela para exatamente o que ela já mostra, o que a pessoa leria como falha.
        if ($chaveOrigem === $contaDestino['chave']) {
            return null;
        }

        // ⚠️⚠️ A ORIGEM É RESOLVIDA NO SERVIDOR, nunca confiada da URL. Sem isto, `?copiar_de=` com
        // uma chave qualquer leria a configuração de uma conta que quem está na tela talvez não
        // devesse ver — e a chave vem da querystring, que é do cliente.
        $origem = $this->programas->resolver($chaveOrigem);
        if ($origem === null) {
            return null;
        }

        $cfg = PubAgenteConfiguracao::query()->where('conta_chave', $origem['chave'])->first();
        if ($cfg === null) {
            return null;
        }

        $valores = [];
        foreach (self::COPIAVEIS as $chave) {
            $valores[$chave] = $cfg->{$chave};
        }

        // ⚠️ A janela sai no formato da TELA (`HH:MM`), igual ao `LeituraDoAgente` — o banco guarda
        // `HH:MM:SS` e um `<input type="time">` com segundos não casa o valor.
        foreach (['janela_inicio', 'janela_fim'] as $hora) {
            $valores[$hora] = $valores[$hora] === null ? null : substr((string) $valores[$hora], 0, 5);
        }

        // ⚠️ O calendário sai saneado para 1..7, pela mesma razão da política: valor fora da faixa
        // no banco de origem não pode viajar para o destino.
        $valores['dias_semana'] = array_values(array_filter(
            array_map('intval', (array) ($valores['dias_semana'] ?? [])),
            fn (int $d) => $d >= 1 && $d <= 7,
        ));

        $valores = [...$valores, ...self::FORCADOS];

        $empresa = $this->programas->empresaParaTela($origem);

        return [
            'valores' => $valores,
            'origem' => [
                'chave' => $origem['chave'],
                'nome' => is_string($empresa['nome'] ?? null) && trim($empresa['nome']) !== '' ? $empresa['nome'] : $origem['chave'],
            ],
            // ⚠️ A tela DIZ o que não veio. Sem isto a pessoa acha que a loja está configurada
            // igual à outra, quando o escopo de produtos e o liga/desliga ficaram de fora.
            'nao_copiados' => [
                'o escopo de produtos (os ids são da outra loja)',
                'o liga/desliga (a cópia nasce desligada)',
                'a pausa ou suspensão e o motivo dela',
            ],
        ];
    }
}
