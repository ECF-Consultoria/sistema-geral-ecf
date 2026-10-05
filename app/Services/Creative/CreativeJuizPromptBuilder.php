<?php

namespace App\Services\Creative;

/**
 * Prompt do juiz de visão (Fase 162, D-06) — pt-BR, pedindo JSON. Molde de
 * ORDEM e disciplina de `CreativePromptBuilder` (NÃO editado nesta fase:
 * trava de coordenação com a Fase 165).
 *
 * Recebe ARRAYS — as colunas JÁ GRAVADAS em `ml_anuncio_criativos`
 * (`truth`, `slot_plano`), nunca DTOs reconstruídos por builder. É a mesma
 * disciplina da trava de coordenação: o juiz lê o que gerou a imagem, não
 * reconstrói o contexto de novo.
 */
class CreativeJuizPromptBuilder
{
    public function __construct(private CreativeSlotCatalog $catalogo) {}

    /**
     * @param  array<string, mixed>  $truth  forma de `ProductTruth::paraAuditoria()`
     * @param  array<string, mixed>|null  $slotPlano  forma de `CreativeSlotPlan::paraPrompt()`
     */
    public function paraCriativo(array $truth, ?array $slotPlano, int $qtdOriginais): string
    {
        $fatosVerificados = (array) ($truth['fatos_verificados'] ?? []);
        $contagens        = (array) ($truth['contagens'] ?? []);
        $marca            = $this->sanitizar((string) ($truth['marca'] ?? ''));
        $modelo           = $this->sanitizar((string) ($truth['modelo'] ?? ''));

        $blocoOrdem = $this->blocoOrdemDasImagens($qtdOriginais);
        $blocoTarefa = $this->blocoSuaTarefa();
        $blocoFatos = $this->blocoFatosEContagens($marca, $modelo, $fatosVerificados, $contagens);
        $blocoTruth0203 = $this->blocoTruth0203();
        $blocoSlot = $this->blocoObjetivoDoSlot($slotPlano);
        $blocoFidelidade = $this->blocoFidelidadeEliminatoria();
        $blocoJson = $this->blocoFormatoJson();

        return <<<TXT
        {$blocoOrdem}

        {$blocoTarefa}

        {$blocoFatos}

        {$blocoTruth0203}

        {$blocoSlot}

        {$blocoFidelidade}

        {$blocoJson}
        TXT;
    }

    // ═══ Blocos ════════════════════════════════════════════════════════════

    private function blocoOrdemDasImagens(int $qtdOriginais): string
    {
        return "ORDEM DAS IMAGENS: As {$qtdOriginais} primeiras imagens são as FOTOS ORIGINAIS "
            . "do produto. A ÚLTIMA imagem é a imagem GERADA que você deve julgar.";
    }

    private function blocoSuaTarefa(): string
    {
        return "SUA TAREFA: diga se a imagem GERADA representa o MESMO produto das fotos "
            . "ORIGINAIS e se ela cumpre o objetivo do slot descrito abaixo.";
    }

    /**
     * @param  array<string, string>  $fatosVerificados
     * @param  array<int, array{peca: string, quantidade: string, origem: string}>  $contagens
     */
    private function blocoFatosEContagens(string $marca, string $modelo, array $fatosVerificados, array $contagens): string
    {
        $linhasFatos = [];
        if ($marca !== '') {
            $linhasFatos[] = "- Marca: {$marca}";
        }
        if ($modelo !== '') {
            $linhasFatos[] = "- Modelo: {$modelo}";
        }
        foreach ($fatosVerificados as $rotulo => $valor) {
            $linhasFatos[] = '- ' . $this->sanitizar((string) $rotulo) . ': ' . $this->sanitizar((string) $valor);
        }

        $textoFatos = $linhasFatos === []
            ? 'Nenhum fato adicional foi confirmado no cadastro deste produto.'
            : implode("\n", $linhasFatos);

        $linhasContagens = [];
        foreach ($contagens as $contagem) {
            $peca = $this->sanitizar((string) ($contagem['peca'] ?? ''));
            $quantidade = $this->sanitizar((string) ($contagem['quantidade'] ?? ''));
            $linhasContagens[] = "- {$peca}: {$quantidade}";
        }

        $textoContagens = $linhasContagens === []
            ? 'Nenhuma contagem foi confirmada no cadastro deste produto.'
            : implode("\n", $linhasContagens);

        return "FATOS VERIFICADOS:\n{$textoFatos}\n\nCONTAGENS CONFIRMADAS NO CADASTRO:\n{$textoContagens}";
    }

    /**
     * ⚠️ Bloco TRUTH-02/03 do JUIZ — obrigatório, com teste próprio. O que
     * não está no Product Truth gravado fica FORA do julgamento, exatamente
     * como fica fora do prompt de geração (TRUTH-03): o juiz não pode
     * inventar fato nem para reprovar nem para aprovar.
     */
    private function blocoTruth0203(): string
    {
        return "REGRA OBRIGATÓRIA SOBRE CONTAGEM E FATOS: Se não houver contagem listada acima, "
            . "NÃO exija nem afirme contagem nenhuma — compare apenas com o que as fotos ORIGINAIS "
            . "mostram. Você NÃO pode inventar fato para reprovar NEM para aprovar. Fato que não "
            . "esteja listado acima e não seja visível nas fotos originais simplesmente NÃO entra "
            . "no seu julgamento.";
    }

    /** @param  array<string, mixed>|null  $slotPlano */
    private function blocoObjetivoDoSlot(?array $slotPlano): string
    {
        $tipo = (string) ($slotPlano['tipo'] ?? '');
        $objetivo = $this->sanitizar((string) ($slotPlano['objetivo'] ?? ''));
        $cena = $this->sanitizar((string) ($slotPlano['cena'] ?? ''));

        if ($tipo === '' && $objetivo === '' && $cena === '') {
            return 'OBJETIVO DESTE SLOT: não informado — avalie apenas a fidelidade ao produto '
                . 'das fotos originais.';
        }

        $linhas = ['OBJETIVO DESTE SLOT:'];
        if ($objetivo !== '') {
            $linhas[] = "- Objetivo: {$objetivo}";
        }
        if ($cena !== '') {
            $linhas[] = "- Cena esperada: {$cena}";
        }

        $aceitaTexto = $tipo !== '' && $this->catalogo->aceitaTexto($tipo);

        if (! $aceitaTexto) {
            $linhas[] = 'REGRA DE TEXTO: este slot NÃO aceita texto, logo, selo ou marca d\'água na '
                . 'imagem (regra de moderação do Mercado Livre). Qualquer texto/logo/selo visível na '
                . 'imagem gerada é DEFEITO.';
        } else {
            $headline = $this->sanitizar((string) ($slotPlano['headline'] ?? ''));
            $badges = array_map(fn ($b) => $this->sanitizar((string) $b), (array) ($slotPlano['badges'] ?? []));

            $textosEsperados = array_values(array_filter([$headline, ...$badges], fn ($t) => $t !== ''));

            $linhas[] = $textosEsperados === []
                ? 'REGRA DE TEXTO: este slot aceita texto, mas nenhum texto exato foi definido para '
                    . 'ele — texto diferente do objetivo acima ou com erro de grafia é DEFEITO.'
                : 'REGRA DE TEXTO: este slot aceita SOMENTE estes textos exatos: '
                    . implode(' | ', $textosEsperados) . '. Texto diferente ou com erro de grafia é DEFEITO.';
        }

        return implode("\n", $linhas);
    }

    private function blocoFidelidadeEliminatoria(): string
    {
        return "FIDELIDADE É ELIMINATÓRIA: produto alterado, peça acrescentada ou removida, "
            . "geometria/marca/cor mudada, produto duplicado (\"fantasma\") ou produto DIFERENTE "
            . "do das fotos originais — qualquer um destes casos é fidelidade=\"falha\", "
            . "independentemente da beleza da imagem.";
    }

    private function blocoFormatoJson(): string
    {
        return <<<'TXT'
        RESPONDA SOMENTE O JSON, sem crases, sem texto antes ou depois, no formato:
        {"fidelidade":"ok|falha","veredito":"aprovada|reprovada","motivo_curto":"<até 200 caracteres, pt-BR, o que fazer diferente>","problemas":[{"tipo":"produto_alterado|contagem|texto|composicao|outro","gravidade":"alta|media|baixa","explicacao":"<pt-BR>"}]}
        `problemas` deve ser `[]` quando não houver defeito.
        TXT;
    }

    /**
     * Colapsa quebras de linha/espaços repetidos e remove caracteres de
     * controle — cópia privada do molde de `CreativePromptBuilder::sanitizar()`.
     * NÃO importar nem editar aquele arquivo (trava de coordenação com a
     * Fase 165): defesa contra injeção de prompt via título/descrição/
     * atributo do cadastro.
     */
    private function sanitizar(string $texto): string
    {
        $semControle = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $texto) ?? $texto;
        $colapsado   = preg_replace('/\s+/u', ' ', $semControle) ?? $semControle;

        return trim($colapsado);
    }
}
