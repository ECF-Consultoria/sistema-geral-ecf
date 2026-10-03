<?php

namespace App\Services\Creative;

use App\Services\Creative\Dto\CreativeContext;
use App\Services\Creative\Dto\ProductTruth;

/**
 * Monta o prompt de texto enviado ao provedor de imagem — formato
 * MASTER + SLOT do §8.5 das notas do spike (261001-nkx), em português.
 *
 * Só recebe `ProductTruth` (nunca `CreativeContext` cru nem o corpo salvo do
 * rascunho) — é essa limitação de assinatura que garante TRUTH-03 por
 * construção: o que não está no `ProductTruth` não tem como entrar aqui.
 *
 * Sanitização (§17.3 das notas): quebras de linha repetidas e caracteres de
 * controle vindos do cadastro são colapsados ANTES de entrar no prompt —
 * texto malicioso no título/descrição vira texto inofensivo, nunca comando.
 *
 * `paraSlot()` (Fase 161, Plano 02) monta o prompt de cada um dos N slots do
 * kit a partir do `slot_plano` já RECONCILIADO pelo `CreativePlanner`
 * (161-01) — nunca o `CreativeContext` nem o corpo salvo do rascunho, mesma
 * limitação de assinatura de `paraSlotHero()`.
 */
class CreativePromptBuilder
{
    /**
     * Claim literal que proíbe texto na imagem — uma das `CLAIMS_FIXAS` de
     * `ProductTruthBuilder`. Correto para o HERO (regra de moderação do
     * Mercado Livre) e CONTRADITÓRIO para um slot que deve escrever badge
     * ou headline (Decisão 7 do 161-02-PLAN.md). Comparado por igualdade
     * EXATA do literal — nunca por busca aproximada.
     */
    private const CLAIM_SEM_TEXTO = 'Não escreva texto na imagem.';

    public function __construct(private CreativeSlotCatalog $catalogo) {}

    /**
     * SLOT 1 — imagem principal (HERO) do Mercado Livre.
     *
     * Regra de produto para este slot (não desconfiança do modelo, ver
     * medição §17 das notas): NENHUM texto/logo/selo/marca d'água — é regra
     * de MODERAÇÃO do Mercado Livre, a mesma que o wizard já avisa ao
     * publicador ("sem logos, marca d'água, texto promocional").
     */
    public function paraSlotHero(CreativeContext $contexto, ProductTruth $truth): string
    {
        $truthPrompt = $truth->paraPrompt();

        $linhas = $this->linhasMaster();

        $linhas[] = 'SLOT: IMAGEM PRINCIPAL (HERO) DO MERCADO LIVRE';
        $linhas[] = 'Produto centralizado, fundo branco limpo, luz de estúdio, enquadramento 1:1.';
        $linhas[] = 'NENHUM texto, logo aplicado, selo ou marca d\'água na imagem — é regra de moderação';
        $linhas[] = 'do Mercado Livre, não só escolha estética.';
        $linhas[] = '';

        array_push($linhas, ...$this->linhasFatosPermitidos($truth));
        $linhas[] = '';

        array_push($linhas, ...$this->linhasContagens($truth));
        $linhas[] = '';

        array_push($linhas, ...$this->linhasClaimsProibidas($truthPrompt['claims_proibidas']));

        return implode("\n", $linhas);
    }

    /**
     * Um dos N slots do kit (Fase 161, Plano 02) — `$slotPlano` é a forma
     * gravada em `ml_anuncio_criativos.slot_plano`
     * (`CreativeSlotPlan::paraPrompt()`): `tipo`, `objetivo`, `cena`,
     * `headline`, `badges`, `fatosUsados`, `proibicoes`.
     *
     * Estrutura, em pt-BR e nesta ordem: (1) MASTER, idêntico ao de
     * `paraSlotHero()`; (2) SLOT, com o rótulo/objetivo/cena do plano; (3)
     * TEXTO, que se bifurca por `CreativeSlotCatalog::aceitaTexto()`; (4)
     * FATOS PERMITIDOS; (5) CONTAGENS; (6) CLAIMS PROIBIDAS — as do Truth
     * mais as `proibicoes` do slot, menos o claim de "não escrever texto"
     * quando o slot aceita texto (Decisão 7).
     */
    public function paraSlot(ProductTruth $truth, array $slotPlano): string
    {
        $tipo        = (string) ($slotPlano['tipo'] ?? '');
        $aceitaTexto = $this->catalogo->aceitaTexto($tipo);
        $padrao      = $this->catalogo->padraoDe($tipo) ?? [];

        $linhas = $this->linhasMaster();

        $linhas[] = 'SLOT: '.$this->sanitizar((string) ($padrao['rotulo'] ?? $tipo))
            .' (posição '.($slotPlano['indice'] ?? '?').' do kit)';
        $linhas[] = $this->sanitizar((string) ($slotPlano['objetivo'] ?? ($padrao['objetivo_padrao'] ?? '')));
        $linhas[] = 'CENA: '.$this->sanitizar((string) ($slotPlano['cena'] ?? ($padrao['cena_padrao'] ?? '')));
        $linhas[] = '';

        array_push($linhas, ...$this->linhasTexto($aceitaTexto, $slotPlano));
        $linhas[] = '';

        array_push($linhas, ...$this->linhasFatosPermitidos($truth));
        $linhas[] = '';

        array_push($linhas, ...$this->linhasContagens($truth));
        $linhas[] = '';

        array_push($linhas, ...$this->linhasClaimsProibidas($this->claimsDoSlot($truth, $slotPlano, $aceitaTexto)));

        return implode("\n", $linhas);
    }

    /** Bloco MASTER — idêntico nos dois métodos, nunca muda por tipo de slot. */
    private function linhasMaster(): array
    {
        return [
            'MASTER — REGRAS QUE NÃO PODEM SER QUEBRADAS',
            'As fotos fornecidas são a fonte visual absoluta da verdade sobre este produto.',
            'Nunca redesenhe o produto, nunca altere sua geometria, marca, logotipo ou cores.',
            'Nunca adicione acessório que não existe no produto, nunca remova componente ou',
            'peça visível, nunca invente especificação e nunca mude a quantidade ou o conteúdo',
            'da embalagem.',
            '',
        ];
    }

    /**
     * Bloco de TEXTO do slot (Decisão 7) — bifurcado por
     * `aceita_texto`. Quando falso, proibição total (mesma redação do bloco
     * SLOT do hero); quando verdadeiro, a headline e as badges do plano,
     * uma por linha, com a instrução de escrever EXATAMENTE esses textos —
     * nenhuma badge do slot entra quando `aceitaTexto` é falso, mesmo que o
     * plano as traga.
     */
    private function linhasTexto(bool $aceitaTexto, array $slotPlano): array
    {
        if (! $aceitaTexto) {
            return [
                'TEXTO: PROIBIDO.',
                'NENHUM texto, logo aplicado, selo ou marca d\'água na imagem — é regra de moderação',
                'do Mercado Livre, não só escolha estética.',
            ];
        }

        $linhas = ['TEXTO: escreva EXATAMENTE os textos abaixo, e não acrescente nenhum outro texto.'];

        $headline = $slotPlano['headline'] ?? null;
        if ($headline !== null && trim((string) $headline) !== '') {
            $linhas[] = '- Headline: '.$this->sanitizar((string) $headline);
        }

        foreach ((array) ($slotPlano['badges'] ?? []) as $badge) {
            $linhas[] = '- Badge: '.$this->sanitizar((string) $badge);
        }

        return $linhas;
    }

    /** Bloco FATOS PERMITIDOS — igual nos dois métodos, só varia o Truth. */
    private function linhasFatosPermitidos(ProductTruth $truth): array
    {
        $truthPrompt = $truth->paraPrompt();

        $linhas = ['FATOS PERMITIDOS (use só o que está listado abaixo — nada além disso):'];

        if ($truthPrompt['marca']) {
            $linhas[] = '- Marca: '.$this->sanitizar((string) $truthPrompt['marca']);
        }

        if ($truthPrompt['modelo']) {
            $linhas[] = '- Modelo: '.$this->sanitizar((string) $truthPrompt['modelo']);
        }

        foreach ($truthPrompt['fatos_verificados'] as $rotulo => $valor) {
            $linhas[] = '- '.$this->sanitizar((string) $rotulo).': '.$this->sanitizar((string) $valor);
        }

        return $linhas;
    }

    /**
     * Bloco CONTAGENS — TRUTH-02/03: só emite número quando o cadastro
     * confirmou. Vazio nunca é silêncio — vira instrução explícita de
     * preservar o que a foto já mostra, sem declarar quantidade nenhuma.
     * Vale por slot, não só no hero.
     */
    private function linhasContagens(ProductTruth $truth): array
    {
        $contagens = $truth->paraPrompt()['contagens'];

        if ($contagens !== []) {
            $linhas = ['CONTAGENS CONFIRMADAS NO CADASTRO (respeite exatamente):'];
            foreach ($contagens as $contagem) {
                $linhas[] = '- '.$this->sanitizar((string) $contagem['peca']).': '.$this->sanitizar((string) $contagem['quantidade']);
            }

            return $linhas;
        }

        return ['CONTAGENS: mantenha exatamente a quantidade de peças visível nas fotos de '
            .'referência e não declare número algum.'];
    }

    /** Bloco CLAIMS PROIBIDAS — lista literal recebida, uma claim por linha. */
    private function linhasClaimsProibidas(array $claims): array
    {
        $linhas = ['CLAIMS PROIBIDAS:'];
        foreach ($claims as $claim) {
            $linhas[] = '- '.$this->sanitizar((string) $claim);
        }

        return $linhas;
    }

    /**
     * Claims do Truth + as `proibicoes` do slot, menos o claim literal de
     * "não escrever texto" quando o slot aceita texto (Decisão 7) —
     * comparação por igualdade exata do literal, nunca por substring.
     */
    private function claimsDoSlot(ProductTruth $truth, array $slotPlano, bool $aceitaTexto): array
    {
        $claims = array_merge(
            $truth->paraPrompt()['claims_proibidas'],
            (array) ($slotPlano['proibicoes'] ?? []),
        );

        if (! $aceitaTexto) {
            return $claims;
        }

        return array_values(array_filter(
            $claims,
            fn ($claim) => trim((string) $claim) !== self::CLAIM_SEM_TEXTO
        ));
    }

    /**
     * Colapsa quebras de linha/espaços repetidos e remove caracteres de
     * controle do texto vindo do cadastro (§17.3) — defesa contra injeção
     * de prompt via título/descrição/atributo do anúncio (T-160-13).
     */
    private function sanitizar(string $texto): string
    {
        $semControle = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $texto) ?? $texto;
        $colapsado   = preg_replace('/\s+/u', ' ', $semControle) ?? $semControle;

        return trim($colapsado);
    }
}
