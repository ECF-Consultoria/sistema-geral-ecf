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

    /**
     * `$categoriaMoveis` (quick 261007-amb): quem chama já decidiu, fora
     * daqui, se a categoria do anúncio é de móvel (`CreativeCategoriaMobiliarioService`,
     * que lê `path_from_root` da categoria) — o Planner só repassa a
     * decisão para `CreativeSlotCatalog::elegiveis()`, nunca consulta a
     * API ele mesmo (nunca chama rede nesta classe, mantém os testes de
     * unidade sem HTTP).
     */
    public function planejar(CreativeContext $contexto, ProductTruth $truth, int $quantidade = 7, bool $categoriaMoveis = false): CreativePlan
    {
        $elegiveis = $this->catalogo->elegiveis($truth, $categoriaMoveis);
        $primeiro  = $categoriaMoveis ? 'lifestyle' : 'hero';

        $origem = 'deterministico';
        $modelo = null;
        $latenciaMs = null;
        $estrategiaProposta = null;
        $slotsPropostos = [];

        try {
            $prompt = $this->montarPrompt($contexto, $truth, $elegiveis, $quantidade, $primeiro);

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
            slots: $this->reconciliar($slotsPropostos, $elegiveis, $truth, $quantidade, $primeiro),
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
    private function montarPrompt(CreativeContext $contexto, ProductTruth $truth, array $elegiveis, int $quantidade, string $primeiro = 'hero'): string
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
        1. O slot de índice 1 é sempre do tipo "{$primeiro}".
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
    private function reconciliar(array $propostos, array $elegiveis, ProductTruth $truth, int $quantidade, string $primeiro = 'hero'): array
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

        $aceitos = $this->garantirPrimeiroSlot($aceitos, $truth, $quantidade, $primeiro);

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
     * Força o slot 1 = `$tipoPrimeiro` (PLAN-02; quick 261007-amb
     * generalizou de "sempre hero" para "hero OU lifestyle, conforme
     * categoria"), mesmo que o LLM não o tenha proposto ou o tenha
     * proposto fora da primeira posição. Se precisar inserir um slot
     * padrão e isso ultrapassar `$quantidade`, corta o último — nunca
     * devolve mais slots do que o pedido.
     *
     * @param  array<int, CreativeSlotPlan>  $aceitos
     * @return array<int, CreativeSlotPlan>
     */
    private function garantirPrimeiroSlot(array $aceitos, ProductTruth $truth, int $quantidade, string $tipoPrimeiro = 'hero'): array
    {
        $aceitos = array_values($aceitos);

        foreach ($aceitos as $i => $slot) {
            if ($slot->tipo !== $tipoPrimeiro) {
                continue;
            }

            if ($i === 0) {
                return $aceitos;
            }

            unset($aceitos[$i]);
            array_unshift($aceitos, $slot);

            return array_values($aceitos);
        }

        array_unshift($aceitos, $this->montarSlotPadrao($tipoPrimeiro, $truth));

        return array_slice($aceitos, 0, max($quantidade, 1));
    }

    /**
     * Um slot aceito da proposta do LLM — objetivo sanitizado, texto
     * validado contra o Truth.
     *
     * Quick 261007-amb: para os tipos que ACEITAM TEXTO (`dimensions` e
     * os demais COM_FATO com texto), a `cena` é SEMPRE a do catálogo
     * (`LAYOUT_MEDIDAS`/`LAYOUT_TOPICOS`), mesmo que o LLM proponha outra
     * — o layout de medidas/tópicos é pedido explícito do usuário
     * (baseado em prints reais de referência), não espaço de
     * criatividade do modelo. Para os demais tipos (visuais, sem texto),
     * a cena continua vindo do LLM quando proposta, com fallback ao
     * padrão — nenhuma mudança de comportamento aí.
     */
    private function montarSlotAceito(string $tipo, array $proposta, ProductTruth $truth): CreativeSlotPlan
    {
        $padrao      = $this->catalogo->padraoDe($tipo) ?? [];
        $aceitaTexto = $this->catalogo->aceitaTexto($tipo);

        $objetivo = $this->sanitizar((string) ($proposta['objetivo'] ?? ''));
        $cena     = $this->sanitizar((string) ($proposta['cena'] ?? ''));

        [$headline, $badges, $fatosUsados] = $aceitaTexto
            ? $this->validarTexto($proposta, $truth)
            : [null, [], []];

        // Correção 1 (quick 261008-txt): "tem texto confirmado" É DIFERENTE
        // de "o tipo aceita texto" — `dimensions`/`specifications`/etc.
        // aceitam texto por TIPO, mas só têm texto DE FATO quando
        // `validarTexto()` confirmou pelo menos um headline/badge. Usar
        // `$aceitaTexto` puro aqui (como antes desta quick) gerava o bloco
        // TEXTO vazio contradizendo a CENA do leiaute — ver PLAN.md.
        $temTexto = $headline !== null || $badges !== [];

        return new CreativeSlotPlan(
            indice: 0, // renumerado em reconciliar()
            tipo: $tipo,
            objetivo: $objetivo !== '' ? $objetivo : (string) ($padrao['objetivo_padrao'] ?? ''),
            cena: $aceitaTexto
                ? (string) ($padrao['cena_padrao'] ?? '')
                : ($cena !== '' ? $cena : (string) ($padrao['cena_padrao'] ?? '')),
            headline: $headline,
            badges: $badges,
            fatosUsados: $fatosUsados,
            proibicoes: $this->proibicoesDoSlot($temTexto),
        );
    }

    /** Um slot 100% padrão do catálogo — usado para completar o plano e para o primeiro slot forçado (hero/lifestyle). */
    private function montarSlotPadrao(string $tipo, ProductTruth $truth): CreativeSlotPlan
    {
        $padrao = $this->catalogo->padraoDe($tipo) ?? [];

        return new CreativeSlotPlan(
            indice: 0,
            tipo: $tipo,
            objetivo: (string) ($padrao['objetivo_padrao'] ?? ''),
            cena: (string) ($padrao['cena_padrao'] ?? ''),
            headline: null,
            badges: [],
            fatosUsados: [],
            // Nunca há proposta do LLM aqui, logo nunca há texto confirmado
            // (headline/badges saem sempre vazios acima) — mesmo quando o
            // TIPO aceita texto por catálogo.
            proibicoes: $this->proibicoesDoSlot(temTextoConfirmado: false),
        );
    }

    /**
     * headline/badges só sobrevivem quando o VALOR que carregam casa
     * EXATAMENTE (trim literal) com um valor de `fatosVerificados` ou com a
     * forma "peça: quantidade" das contagens — a prova de T-161-03/PLAN-03
     * para texto na imagem.
     *
     * Correção 2 (quick 261008-txt, decisão do usuário): o texto ACEITO
     * nunca é o literal que o modelo escreveu — para um fato de
     * `fatosVerificados`, é sempre REMONTADO pelo SISTEMA como "{rótulo}:
     * {valor}" (`rotularSeConfirmado()`), com o rótulo oficial do Truth, já
     * em pt-BR (`ProductTruthBuilder::rotulo()`). Antes desta correção, o
     * modelo propondo "Largura: 120 cm" para o fato `WIDTH => '120 cm'` era
     * descartado por não casar byte a byte com o valor NU "120 cm" — achado
     * em produção no criativo 40 (ver PLAN.md). O NÚMERO continua vindo só
     * do cadastro: só decidimos SE o texto do modelo se refere a um valor
     * confirmado, nunca aceitamos a frase dele como está. Contagens não
     * mudam — já chegam rotuladas de `ProductTruthBuilder` e continuam
     * exigindo igualdade EXATA da string inteira (nenhuma flexibilização
     * aqui, por não ser o defeito desta quick — ver §18 do spike).
     *
     * `fatosUsados` guarda os RÓTULOS (chaves de `fatosVerificados`), não os
     * valores.
     *
     * @return array{0: ?string, 1: array<int, string>, 2: array<int, string>}
     */
    private function validarTexto(array $proposta, ProductTruth $truth): array
    {
        $headlineProposto = trim((string) ($proposta['headline'] ?? ''));
        $headline = $headlineProposto !== '' ? $this->rotularSeConfirmado($headlineProposto, $truth) : null;

        $badges = [];
        foreach ((array) ($proposta['badges'] ?? []) as $badge) {
            $badge = trim((string) $badge);
            $rotulado = $badge !== '' ? $this->rotularSeConfirmado($badge, $truth) : null;

            if ($rotulado !== null) {
                $badges[] = $rotulado;
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

    /**
     * `$texto` (proposto pelo modelo) casa com um fato ou contagem
     * confirmados — e, quando casa, devolve o texto REMONTADO pelo sistema
     * (nunca o literal proposto, ver docblock de `validarTexto()`).
     *
     * Contagem exige igualdade EXATA da string "peça: quantidade" inteira
     * (comportamento inalterado). Fato verificado tolera o modelo já ter
     * colado um rótulo próprio antes do valor (`semRotulo()` descarta tudo
     * até o último ":") — o VALOR depois do ":" (ou o texto inteiro, se não
     * houver ":") precisa casar EXATAMENTE com o valor do cadastro.
     */
    private function rotularSeConfirmado(string $texto, ProductTruth $truth): ?string
    {
        foreach ($truth->contagens as $contagem) {
            $textoContagem = "{$contagem['peca']}: {$contagem['quantidade']}";
            if (trim($texto) === trim($textoContagem)) {
                return $textoContagem;
            }
        }

        $valorProposto = $this->semRotulo($texto);
        foreach ($truth->fatosVerificados as $rotulo => $valor) {
            if ($valorProposto === trim((string) $valor)) {
                return "{$rotulo}: {$valor}";
            }
        }

        return null;
    }

    /** Parte depois do último ":" de `$texto`, ou o texto inteiro quando não há ":". */
    private function semRotulo(string $texto): string
    {
        $pos = strrpos($texto, ':');

        return trim($pos === false ? $texto : substr($texto, $pos + 1));
    }

    /**
     * A proibição ESPECÍFICA deste slot — hoje, só a de "não escrever
     * texto" quando ele não tem texto confirmado (nem porque o tipo não
     * aceita texto, nem porque aceita mas nada foi validado).
     *
     * Correção 3 (quick 261008-txt): este método NÃO repete
     * `$truth->claimsProibidas` — isso já é mesclado de novo em
     * `CreativePromptBuilder::claimsDoSlot()` a partir do Truth gravado.
     * Gravar aqui TAMBÉM a lista fixa do Truth é a causa raiz da duplicação
     * encontrada em produção (os mesmos 7 itens de CLAIMS PROIBIDAS duas
     * vezes no prompt do criativo 40) — ver PLAN.md.
     */
    private function proibicoesDoSlot(bool $temTextoConfirmado): array
    {
        if ($temTextoConfirmado) {
            return [];
        }

        return ['Não escreva texto, logo, selo ou marca d\'água nesta imagem.'];
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
