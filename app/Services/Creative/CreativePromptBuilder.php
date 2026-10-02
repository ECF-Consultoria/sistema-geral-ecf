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
 */
class CreativePromptBuilder
{
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

        $linhas = [
            'MASTER — REGRAS QUE NÃO PODEM SER QUEBRADAS',
            'As fotos fornecidas são a fonte visual absoluta da verdade sobre este produto.',
            'Nunca redesenhe o produto, nunca altere sua geometria, marca, logotipo ou cores.',
            'Nunca adicione acessório que não existe no produto, nunca remova componente ou',
            'peça visível, nunca invente especificação e nunca mude a quantidade ou o conteúdo',
            'da embalagem.',
            '',
            'SLOT: IMAGEM PRINCIPAL (HERO) DO MERCADO LIVRE',
            'Produto centralizado, fundo branco limpo, luz de estúdio, enquadramento 1:1.',
            'NENHUM texto, logo aplicado, selo ou marca d\'água na imagem — é regra de moderação',
            'do Mercado Livre, não só escolha estética.',
            '',
            'FATOS PERMITIDOS (use só o que está listado abaixo — nada além disso):',
        ];

        if ($truthPrompt['marca']) {
            $linhas[] = '- Marca: '.$this->sanitizar((string) $truthPrompt['marca']);
        }

        if ($truthPrompt['modelo']) {
            $linhas[] = '- Modelo: '.$this->sanitizar((string) $truthPrompt['modelo']);
        }

        foreach ($truthPrompt['fatos_verificados'] as $rotulo => $valor) {
            $linhas[] = '- '.$this->sanitizar((string) $rotulo).': '.$this->sanitizar((string) $valor);
        }

        $linhas[] = '';

        // TRUTH-02/03: só emite número quando o cadastro confirmou. Vazio
        // nunca é silêncio — vira instrução explícita de preservar o que a
        // foto já mostra, sem declarar quantidade nenhuma.
        if ($truthPrompt['contagens'] !== []) {
            $linhas[] = 'CONTAGENS CONFIRMADAS NO CADASTRO (respeite exatamente):';
            foreach ($truthPrompt['contagens'] as $contagem) {
                $linhas[] = '- '.$this->sanitizar((string) $contagem['peca']).': '.$this->sanitizar((string) $contagem['quantidade']);
            }
        } else {
            $linhas[] = 'CONTAGENS: mantenha exatamente a quantidade de peças visível nas fotos de '
                .'referência e não declare número algum.';
        }

        $linhas[] = '';
        $linhas[] = 'CLAIMS PROIBIDAS:';
        foreach ($truthPrompt['claims_proibidas'] as $claim) {
            $linhas[] = '- '.$this->sanitizar((string) $claim);
        }

        return implode("\n", $linhas);
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
