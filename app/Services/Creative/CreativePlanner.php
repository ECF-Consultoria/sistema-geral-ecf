<?php

namespace App\Services\Creative;

use App\Services\Creative\Contracts\ImageGenerationProvider;
use App\Services\Creative\Dto\CreativeContext;
use App\Services\Creative\Dto\CreativePlan;
use App\Services\Creative\Dto\CreativeSlotPlan;
use App\Services\Creative\Dto\ProductTruth;
use Illuminate\Support\Facades\Log;

/**
 * Escolhe os N slots do kit (Fase 161, PLAN-01/02/03) em TRÊS camadas —
 * Decisão 2 do `161-01-PLAN.md`, nenhuma delas opcional:
 *
 *   1. Elegibilidade determinística (`CreativeSlotCatalog::elegiveis()`,
 *      PLAN-03) — decide ANTES de falar com o modelo.
 *   2. Escolha e redação pelo LLM (`ImageGenerationProvider::gerarTexto()`),
 *      chamada CURTA em JSON, molde `AnaliseAnuncioService` — inclusive o
 *      motivo de ser curta: lá o prompt grande devolvia 503 enquanto a
 *      parte pequena respondia em 30s com a MESMA conta.
 *   3. Reconciliação no servidor, sem confiar em nada do que o modelo
 *      devolveu — "Nada é aceito do modelo" (mesma disciplina de
 *      `AnaliseAnuncioService::normalizarTitulos()`).
 *
 * NUNCA grava no banco (quem persiste é `PlanejarKitCriativosJob`) e NUNCA
 * chama geração de imagem (isso é objetivo do 161-02).
 */
class CreativePlanner
{
    public function __construct(
        private ImageGenerationProvider $provider,
        private CreativeSlotCatalog $catalogo,
    ) {}

    public function planejar(CreativeContext $contexto, ProductTruth $truth, int $quantidade = 7): CreativePlan
    {
        $elegiveis = $this->catalogo->elegiveis($truth);

        $origem = 'deterministico';
        $modelo = null;
        $latenciaMs = null;
        $estrategiaProposta = null;
        $slotsPropostos = [];

        try {
            $prompt = $this->montarPrompt($contexto, $truth, $elegiveis, $quantidade);

            $t0 = microtime(true);
            $resposta = $this->provider->gerarTexto($prompt);
            $latenciaMs = (int) round((microtime(true) - $t0) * 1000);

            $dados = $this->extrairJson($resposta);

            if ($dados !== null) {
                $estrategiaProposta = is_array($dados['estrategia'] ?? null) ? $dados['estrategia'] : null;
                $slotsPropostos     = is_array($dados['slots'] ?? null) ? $dados['slots'] : [];
                // O contrato `gerarTexto()` não devolve QUAL modelo de fato
                // respondeu (ao contrário da chamada crua de
                // AnaliseAnuncioService) — o nome configurado é a melhor
                // aproximação disponível, documentada aqui como limitação.
                $modelo = (string) config('services.creative.gemini.text_model');
                $origem = 'llm';
            }
        } catch (\Throwable $e) {
            // Sem prompt, sem chave — só o que ajuda a depurar sem vazar nada (GEN-05).
            Log::warning('[Creative] Planner: geração de texto falhou, plano sai determinístico. ' . $e->getMessage());
        }

        return new CreativePlan(
            estrategia: $this->estrategiaFinal($estrategiaProposta, $contexto),
            slots: $this->reconciliar($slotsPropostos, $elegiveis, $truth, $quantidade),
            origem: $origem,
            modelo: $modelo,
            latenciaMs: $latenciaMs,
            // Quick 261007-rmv: calculado com o MESMO Truth que decidiu os
            // slots acima — a tela lê isto do kit já planejado, sem
            // reconstruir Truth a cada leitura (ver docblock do CreativePlan).
            podeTerTexto: $this->catalogo->algumAceitaTexto($truth),
            faltam: $this->catalogo->faltamParaTexto($truth),
        );
    }

    // ═══ Prompt ════════════════════════════════════════════════════════════

    /**
     * Prompt CURTO em pt-BR, saída só JSON — molde
     * `AnaliseAnuncioService::promptAnalise()`. Deixa explícito que tipo
     * fora da lista e número/medida/capacidade fora dos fatos são proibidos
     * — a reconciliação (camada 3) não confia nisso, mas reduz o quanto ela
     * precisa descartar.
     */
    private function montarPrompt(CreativeContext $contexto, ProductTruth $truth, array $elegiveis, int $quantidade): string
    {
        $truthPrompt = $truth->paraPrompt();

        $tiposTexto = collect($elegiveis)
            ->map(function (string $tipo) {
                $padrao = $this->catalogo->padraoDe($tipo) ?? [];

                return "- {$tipo}: " . ($padrao['objetivo_padrao'] ?? '');
            })
            ->implode("\n");

        $fatos = collect($truthPrompt['fatos_verificados'])
            ->map(fn ($valor, $rotulo) => "- {$rotulo}: {$valor}")
            ->implode("\n");

        $contagens = collect($truthPrompt['contagens'])
            ->map(fn ($c) => "- {$c['peca']}: {$c['quantidade']}")
            ->implode("\n");

        $claims = collect($truthPrompt['claims_proibidas'])
            ->map(fn ($c) => "- {$c}")
            ->implode("\n");

        return <<<TXT
        Planeje um kit de {$quantidade} imagens de anúncio para o produto **{$contexto->produto}** no Mercado Livre.

        Categoria: {$contexto->categoriaId}
        Marca: {$truthPrompt['marca']}
        Modelo: {$truthPrompt['modelo']}

        FATOS VERIFICADOS (use só o que está listado — nada além disso):
        {$fatos}

        CONTAGENS CONFIRMADAS NO CADASTRO:
        {$contagens}

        CLAIMS PROIBIDAS:
        {$claims}

        TIPOS DE SLOT PERMITIDOS — proibido propor qualquer tipo fora desta lista:
        {$tiposTexto}

        REGRAS OBRIGATÓRIAS:
        1. O slot de índice 1 é sempre do tipo "hero".
        2. Proibido propor tipo fora da lista acima.
        3. Proibido repetir o mesmo tipo duas vezes.
        4. Proibido inventar número, medida ou capacidade que não esteja nos FATOS
           VERIFICADOS ou nas CONTAGENS acima — "headline"/"badges" só podem repetir,
           literalmente, um valor já listado.

        Responda APENAS com JSON válido, sem crases, sem texto antes ou depois:
        {"estrategia":{"publico":"...","proposta_de_valor":"...","direcao_visual":"..."},"slots":[{"tipo":"...","objetivo":"...","cena":"...","headline":null,"badges":[],"fatos_usados":[]}]}
        TXT;
    }

    // ═══ Reconciliação (camada 3 — "Nada é aceito do modelo") ═══════════════

    /**
     * @param  array<int, mixed>  $propostos
     * @param  array<int, string>  $elegiveis
     * @return array<int, CreativeSlotPlan>
     */
    private function reconciliar(array $propostos, array $elegiveis, ProductTruth $truth, int $quantidade): array
    {
        $aceitos = [];

        foreach ($propostos as $proposta) {
            if (count($aceitos) >= $quantidade) {
                break;
            }

            $tipo = is_array($proposta) ? trim((string) ($proposta['tipo'] ?? '')) : '';

            if ($tipo === '' || ! in_array($tipo, $elegiveis, true) || $this->tipoJaUsado($aceitos, $tipo)) {
                continue;
            }

            $aceitos[] = $this->montarSlotAceito($tipo, (array) $proposta, $truth);
        }

        // Completa até $quantidade pela prioridade do catálogo (PLAN-01).
        foreach ($elegiveis as $tipo) {
            if (count($aceitos) >= $quantidade) {
                break;
            }

            if ($this->tipoJaUsado($aceitos, $tipo)) {
                continue;
            }

            $aceitos[] = $this->montarSlotPadrao($tipo, $truth);
        }

        $aceitos = $this->garantirHeroPrimeiro($aceitos, $truth, $quantidade);

        return array_values(array_map(
            fn (CreativeSlotPlan $slot, int $i) => new CreativeSlotPlan(
                indice: $i + 1,
                tipo: $slot->tipo,
                objetivo: $slot->objetivo,
                cena: $slot->cena,
                headline: $slot->headline,
                badges: $slot->badges,
                fatosUsados: $slot->fatosUsados,
                proibicoes: $slot->proibicoes,
            ),
            $aceitos,
            array_keys($aceitos),
        ));
    }

    /** @param  array<int, CreativeSlotPlan>  $aceitos */
    private function tipoJaUsado(array $aceitos, string $tipo): bool
    {
        foreach ($aceitos as $slot) {
            if ($slot->tipo === $tipo) {
                return true;
            }
        }

        return false;
    }

    /**
     * Força o slot 1 = `hero` (PLAN-02), mesmo que o LLM não o tenha
     * proposto ou o tenha proposto fora da primeira posição. Se precisar
     * inserir um hero padrão e isso ultrapassar `$quantidade`, corta o
     * último — nunca devolve mais slots do que o pedido.
     *
     * @param  array<int, CreativeSlotPlan>  $aceitos
     * @return array<int, CreativeSlotPlan>
     */
    private function garantirHeroPrimeiro(array $aceitos, ProductTruth $truth, int $quantidade): array
    {
        $aceitos = array_values($aceitos);

        foreach ($aceitos as $i => $slot) {
            if ($slot->tipo !== 'hero') {
                continue;
            }

            if ($i === 0) {
                return $aceitos;
            }

            unset($aceitos[$i]);
            array_unshift($aceitos, $slot);

            return array_values($aceitos);
        }

        array_unshift($aceitos, $this->montarSlotPadrao('hero', $truth));

        return array_slice($aceitos, 0, max($quantidade, 1));
    }

    /** Um slot aceito da proposta do LLM — objetivo/cena sanitizados, texto validado contra o Truth. */
    private function montarSlotAceito(string $tipo, array $proposta, ProductTruth $truth): CreativeSlotPlan
    {
        $padrao      = $this->catalogo->padraoDe($tipo) ?? [];
        $aceitaTexto = $this->catalogo->aceitaTexto($tipo);

        $objetivo = $this->sanitizar((string) ($proposta['objetivo'] ?? ''));
        $cena     = $this->sanitizar((string) ($proposta['cena'] ?? ''));

        [$headline, $badges, $fatosUsados] = $aceitaTexto
            ? $this->validarTexto($proposta, $truth)
            : [null, [], []];

        return new CreativeSlotPlan(
            indice: 0, // renumerado em reconciliar()
            tipo: $tipo,
            objetivo: $objetivo !== '' ? $objetivo : (string) ($padrao['objetivo_padrao'] ?? ''),
            cena: $cena !== '' ? $cena : (string) ($padrao['cena_padrao'] ?? ''),
            headline: $headline,
            badges: $badges,
            fatosUsados: $fatosUsados,
            proibicoes: $this->proibicoesDoSlot($truth, $aceitaTexto),
        );
    }

    /** Um slot 100% padrão do catálogo — usado para completar o plano e para o hero forçado. */
    private function montarSlotPadrao(string $tipo, ProductTruth $truth): CreativeSlotPlan
    {
        $padrao      = $this->catalogo->padraoDe($tipo) ?? [];
        $aceitaTexto = $this->catalogo->aceitaTexto($tipo);

        return new CreativeSlotPlan(
            indice: 0,
            tipo: $tipo,
            objetivo: (string) ($padrao['objetivo_padrao'] ?? ''),
            cena: (string) ($padrao['cena_padrao'] ?? ''),
            headline: null,
            badges: [],
            fatosUsados: [],
            proibicoes: $this->proibicoesDoSlot($truth, $aceitaTexto),
        );
    }

    /**
     * headline/badges só sobrevivem quando casam EXATAMENTE (trim literal)
     * com um valor de `fatosVerificados` ou com a forma "peça: quantidade"
     * das contagens — a prova de T-161-03/PLAN-03 para texto na imagem.
     * `fatosUsados` guarda os RÓTULOS (chaves de `fatosVerificados`), não os
     * valores.
     *
     * @return array{0: ?string, 1: array<int, string>, 2: array<int, string>}
     */
    private function validarTexto(array $proposta, ProductTruth $truth): array
    {
        $valoresValidos = array_map(
            fn ($v) => trim((string) $v),
            array_merge(
                array_values($truth->fatosVerificados),
                array_map(fn ($c) => "{$c['peca']}: {$c['quantidade']}", $truth->contagens),
            ),
        );

        $headlineProposto = trim((string) ($proposta['headline'] ?? ''));
        $headline = ($headlineProposto !== '' && in_array($headlineProposto, $valoresValidos, true))
            ? $headlineProposto
            : null;

        $badges = [];
        foreach ((array) ($proposta['badges'] ?? []) as $badge) {
            $badge = trim((string) $badge);
            if ($badge !== '' && in_array($badge, $valoresValidos, true)) {
                $badges[] = $badge;
            }
        }

        $rotulosValidos = array_keys($truth->fatosVerificados);
        $fatosUsados = [];
        foreach ((array) ($proposta['fatos_usados'] ?? []) as $fato) {
            $fato = trim((string) $fato);
            if ($fato !== '' && in_array($fato, $rotulosValidos, true)) {
                $fatosUsados[] = $fato;
            }
        }

        return [$headline, $badges, $fatosUsados];
    }

    /** Claims do Truth + (quando o tipo não aceita texto) a proibição total de texto na imagem. */
    private function proibicoesDoSlot(ProductTruth $truth, bool $aceitaTexto): array
    {
        $proibicoes = $truth->claimsProibidas;

        if (! $aceitaTexto) {
            $proibicoes[] = 'Não escreva texto, logo, selo ou marca d\'água nesta imagem.';
        }

        return $proibicoes;
    }

    /** Estratégia final — sanitizada campo a campo, com fallback padrão quando o LLM não propôs nada usável. */
    private function estrategiaFinal(?array $proposta, CreativeContext $contexto): array
    {
        $padrao = $this->estrategiaPadrao($contexto);

        if ($proposta === null) {
            return $padrao;
        }

        $publico = $this->sanitizar((string) ($proposta['publico'] ?? ''));
        $pdv     = $this->sanitizar((string) ($proposta['proposta_de_valor'] ?? ''));
        $direcao = $this->sanitizar((string) ($proposta['direcao_visual'] ?? ''));

        return [
            'publico'           => $publico !== '' ? $publico : $padrao['publico'],
            'proposta_de_valor' => $pdv !== '' ? $pdv : $padrao['proposta_de_valor'],
            'direcao_visual'    => $direcao !== '' ? $direcao : $padrao['direcao_visual'],
        ];
    }

    private function estrategiaPadrao(CreativeContext $contexto): array
    {
        return [
            'publico'           => 'Compradores do Mercado Livre interessados em ' . $contexto->produto . '.',
            'proposta_de_valor' => 'Imagens fiéis ao que o cadastro confirma, sem texto promocional na capa.',
            'direcao_visual'    => 'Fundo neutro, luz de estúdio, foco no produto.',
        ];
    }

    // ═══ Saída do modelo ═════════════════════════════════════════════════

    /**
     * Extrai o JSON mesmo cercado por crases com a marca `json` ou com texto
     * antes/depois — molde `AnaliseAnuncioService::extrairJson()`.
     */
    private function extrairJson(string $conteudo): ?array
    {
        $limpo = trim((string) preg_replace('/^```(?:json)?\s*|\s*```$/m', '', trim($conteudo)));

        $dados = json_decode($limpo, true);
        if (is_array($dados)) {
            return $dados;
        }

        $ini = strpos($limpo, '{');
        $fim = strrpos($limpo, '}');

        if ($ini === false || $fim === false || $fim <= $ini) {
            return null;
        }

        $dados = json_decode(substr($limpo, $ini, $fim - $ini + 1), true);

        return is_array($dados) ? $dados : null;
    }

    /**
     * Colapsa quebras de linha/espaços repetidos e remove caracteres de
     * controle — molde `CreativePromptBuilder::sanitizar()` (defesa contra
     * injeção de prompt via título/descrição/atributo do anúncio).
     */
    private function sanitizar(string $texto): string
    {
        $semControle = (string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $texto);
        $colapsado   = (string) preg_replace('/\s+/u', ' ', $semControle);

        return trim($colapsado);
    }
}
