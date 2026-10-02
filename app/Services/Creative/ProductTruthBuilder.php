<?php

namespace App\Services\Creative;

use App\Services\Creative\Dto\CreativeContext;
use App\Services\Creative\Dto\ProductTruth;

/**
 * Constrói o `ProductTruth` a partir do `CreativeContext` — nunca do corpo
 * bruto salvo do rascunho (essa leitura é monopólio do `CreativeContextBuilder`,
 * CTX-02). É aqui que TRUTH-01 a TRUTH-04 ganham forma de código.
 *
 * ORDEM DE IMPORTÂNCIA DAS REGRAS (não inverter):
 *   1. fatosVerificados só de atributos com valor — título/descrição nunca
 *      são minerados.
 *   2. contagens só de atributos com id explícito de quantidade — "Proibido
 *      regex sobre título/descrição para extrair número de portas, gavetas,
 *      pés ou itens de kit" (§18 das notas do spike): o erro que já custou
 *      caro foi do PROMPT, não do modelo — um número errado no prompt é pior
 *      que número nenhum, porque o modelo obedece com confiança e a imagem
 *      fica coerente consigo mesma, passando pela revisão humana sem
 *      levantar suspeita.
 *   3. claimsProibidas nunca vazio — lista fixa + proibição derivada de
 *      ausência de fato.
 *   4. TRUTH-03 é garantido por CONSTRUÇÃO: o `CreativePromptBuilder` só
 *      recebe este DTO, nunca o `CreativeContext` nem o corpo salvo do
 *      rascunho — o que não está aqui simplesmente não tem por onde entrar
 *      no prompt.
 */
class ProductTruthBuilder
{
    /**
     * Lista FECHADA de ids/padrões de atributo que indicam quantidade de
     * peça. Qualquer id fora desta lista — mesmo que o título ou a
     * descrição mencionem um número — NÃO entra em `contagens` (TRUTH-02).
     * Regex ancorada, não substring livre: evita falso-positivo tipo
     * `QUANTITY_DISCOUNT` (atributo comercial, não de peça física).
     */
    private const PADRAO_ID_CONTAGEM = '/^(PIECES_NUMBER|DRAWERS_NUMBER|DOORS_NUMBER)$|_QUANTITY$|^QUANTITY_|^NUMBER_OF_/';

    /** Lista fixa de claims proibidas — base de TODO Product Truth (§8.5/§16). */
    private const CLAIMS_FIXAS = [
        'Não altere a cor do produto.',
        'Não altere a geometria do produto.',
        'Não adicione nem altere logotipo ou marca.',
        'Não adicione acessório que não existe no produto.',
        'Não remova componente ou peça do produto.',
        'Não mude a quantidade ou o conteúdo da embalagem.',
        'Não invente especificação que não está no cadastro.',
        'Não escreva texto na imagem.',
    ];

    /** Rótulos legíveis em pt-BR para ids de atributo conhecidos. Fallback: humaniza o id. */
    private const ROTULOS_CONHECIDOS = [
        'BRAND'          => 'Marca',
        'MODEL'          => 'Modelo',
        'COLOR'          => 'Cor',
        'MATERIAL'       => 'Material',
        'DOOR_QUANTITY'  => 'Quantidade de portas',
        'DRAWERS_NUMBER' => 'Quantidade de gavetas',
        'PIECES_NUMBER'  => 'Quantidade de peças do kit',
    ];

    /** Rótulo da "peça" contada, para a entrada de `contagens`. Fallback: humaniza o id. */
    private const PECA_POR_ID = [
        'DOORS_NUMBER'   => 'portas',
        'DOOR_QUANTITY'  => 'portas',
        'DRAWERS_NUMBER' => 'gavetas',
        'PIECES_NUMBER'  => 'peças do kit',
    ];

    public function paraContexto(CreativeContext $contexto): ProductTruth
    {
        $fatosVerificados = $this->fatosVerificados($contexto);
        $contagens        = $this->contagens($contexto);

        return new ProductTruth(
            marca: $contexto->marca,
            modelo: $contexto->modelo,
            fatosVerificados: $fatosVerificados,
            contagens: $contagens,
            beneficiosVerificados: [],
            claimsProibidas: $this->claimsProibidas($contexto, $contagens),
            referenciasMeta: $contexto->referenciasMeta,
        );
    }

    /**
     * TRUTH-01: só atributos com `value_name` preenchido (já filtrado pelo
     * `CreativeContextBuilder`) entram, com rótulo legível. Título e
     * descrição NUNCA são minerados aqui — eles só existem como texto livre
     * no `CreativeContext`, fora do alcance deste método.
     *
     * @return array<string, string>
     */
    private function fatosVerificados(CreativeContext $contexto): array
    {
        $fatos = [];

        foreach ($contexto->atributos as $id => $valor) {
            $fatos[$this->rotulo((string) $id)] = $valor;
        }

        return $fatos;
    }

    /**
     * TRUTH-02: contagem só entra quando o PRÓPRIO ID do atributo do
     * cadastro é um id de quantidade conhecido (`PADRAO_ID_CONTAGEM`).
     * Nenhuma leitura de título/descrição acontece aqui — eles não são nem
     * parâmetro deste método.
     *
     * @return array<int, array{peca: string, quantidade: string, origem: string}>
     */
    private function contagens(CreativeContext $contexto): array
    {
        $contagens = [];

        foreach ($contexto->atributos as $id => $valor) {
            if (preg_match(self::PADRAO_ID_CONTAGEM, (string) $id) !== 1) {
                continue;
            }

            $contagens[] = [
                'peca'       => self::PECA_POR_ID[$id] ?? $this->rotulo((string) $id),
                'quantidade' => $valor,
                'origem'     => 'cadastro',
            ];
        }

        return $contagens;
    }

    /**
     * TRUTH-04: nunca vazio. Lista fixa + uma proibição por fato AUSENTE que
     * o slot poderia querer inventar — cor e contagem são os dois casos que
     * já custaram caro neste projeto (§18 das notas do spike).
     *
     * @param  array<int, array{peca: string, quantidade: string, origem: string}>  $contagens
     * @return array<int, string>
     */
    private function claimsProibidas(CreativeContext $contexto, array $contagens): array
    {
        $claims = self::CLAIMS_FIXAS;

        if (! array_key_exists('COLOR', $contexto->atributos)) {
            $claims[] = 'Não afirme nem altere a cor do produto — não há cor confirmada no cadastro.';
        }

        if ($contagens === []) {
            $claims[] = 'Não desenhe quantidade de portas/gavetas/peças diferente do que as fotos de '
                . 'referência mostram, e não declare número algum — não há contagem confirmada no cadastro.';
        }

        return $claims;
    }

    /** Rótulo legível pt-BR do id do atributo. Fallback: humaniza o id (snake → título). */
    private function rotulo(string $id): string
    {
        if (isset(self::ROTULOS_CONHECIDOS[$id])) {
            return self::ROTULOS_CONHECIDOS[$id];
        }

        return ucfirst(mb_strtolower(str_replace('_', ' ', $id)));
    }
}
