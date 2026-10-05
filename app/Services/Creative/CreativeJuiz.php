<?php

namespace App\Services\Creative;

use App\Models\MlAnuncioCriativo;
use App\Services\Creative\Contracts\ImageJudgementProvider;
use App\Services\Creative\Dto\CreativeJudgementRequest;
use App\Services\Creative\Dto\CreativeJudgementResult;
use App\Services\Creative\Dto\CreativeValidacao;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Juiz de visão (Fase 162, D-06) — julga a imagem GERADA contra as fotos
 * ORIGINAIS e o Product Truth GRAVADO, e grava o veredito reconciliado na
 * tabela (nunca só em log — T-162-05).
 *
 * Lê SEMPRE as COLUNAS JÁ GRAVADAS em `ml_anuncio_criativos` (`truth`,
 * `slot_plano`, `imagem_path`) e as fotos originais do PORTADOR de
 * referência — nunca reconstrói nada por builder (trava de coordenação com
 * a Fase 165; é também mais CORRETO: valida contra o truth que de fato
 * gerou aquela imagem).
 *
 * RECONCILIAÇÃO — "nada é aceito do modelo" (mesma disciplina de
 * `CreativePlanner::reconciliar()` e `AnaliseAnuncioService`): o `veredito`
 * cru do modelo nunca é gravado como está; o status final é sempre
 * recalculado no servidor pela regra eliminatória de fidelidade (VAL-04).
 */
class CreativeJuiz
{
    /** Tipos de problema aceitos — valor fora disto vira `outro` (T-162-06). */
    private const TIPOS_PROBLEMA_VALIDOS = ['produto_alterado', 'contagem', 'texto', 'composicao', 'outro'];

    /** Gravidades aceitas — valor fora disto vira `media` (T-162-06). */
    private const GRAVIDADES_VALIDAS = ['alta', 'media', 'baixa'];

    /**
     * Tipos cuja gravidade `alta` é ELIMINATÓRIA (VAL-04) — fidelidade ao
     * produto, contagem inventada/errada e texto indevido reprovam mesmo
     * que o modelo tenha escrito `veredito: "aprovada"`.
     */
    private const TIPOS_ELIMINATORIOS = ['produto_alterado', 'contagem', 'texto'];

    public function __construct(
        private ImageJudgementProvider $provider,
        private ReferenciaEfemeraService $referencias,
        private CreativeSlotCatalog $catalogo,
        private CreativeJuizPromptBuilder $prompt,
    ) {}

    /**
     * Julga o criativo. NUNCA engole exceção do provedor — propaga para
     * quem chamou decidir (o job trata, o comando mostra).
     */
    public function julgar(MlAnuncioCriativo $criativo): CreativeValidacao
    {
        if (empty($criativo->imagem_path) || ! Storage::disk('local')->exists($criativo->imagem_path)) {
            return $this->indisponivel(
                'A imagem gerada não foi encontrada em disco — não foi possível validar automaticamente.'
            );
        }

        $originais = $this->referencias->bytesDe($criativo->portadorDeReferencia());

        if ($originais === []) {
            return $this->indisponivel(
                'As fotos originais já foram apagadas — não é possível validar automaticamente esta imagem.'
            );
        }

        $bytesGerada = (string) Storage::disk('local')->get($criativo->imagem_path);
        $mimeGerada  = (string) ($criativo->imagem_mime ?: 'image/jpeg');

        $prompt = $this->prompt->paraCriativo(
            (array) ($criativo->truth ?? []),
            $criativo->slot_plano,
            count($originais),
        );

        $imagens = [...$originais, ['mime' => $mimeGerada, 'bytes' => $bytesGerada]];

        // Exceção do provedor (RuntimeException/FalhaDeGeracaoTrocavel) NÃO
        // é capturada aqui — propaga para julgarEGravar()/comando/job.
        $resultado = $this->provider->julgar(new CreativeJudgementRequest($prompt, $imagens));

        $dados = $this->extrairJson($resultado->texto);

        if ($dados === null) {
            Log::warning('[Creative] Juiz: resposta não era JSON válido', [
                'inicio' => mb_substr($resultado->texto, 0, 300),
            ]);

            return $this->indisponivel(
                'O juiz não devolveu um veredito em formato válido — não foi possível validar automaticamente esta imagem.',
                $resultado->modelo,
                $resultado->latenciaMs,
            );
        }

        return $this->reconciliar($dados, $resultado);
    }

    /**
     * O que o job (Plano 02) e o comando usam: confere o teto, incrementa o
     * contador ANTES da chamada (a unidade faturada é a CHAMADA — contar
     * antes impede retentativa infinita de um juiz que falha sempre), julga
     * e grava o veredito na TABELA.
     */
    public function julgarEGravar(MlAnuncioCriativo $criativo): CreativeValidacao
    {
        $maxValidacoesAsset = (int) config('services.creative.validacao.max_validacoes_asset', 3);

        if ($criativo->validacoes >= $maxValidacoesAsset) {
            return $this->indisponivel('Esta imagem já passou pelo número máximo de validações automáticas.');
        }

        $criativo->increment('validacoes');

        $kit = $criativo->kit;
        if ($kit !== null) {
            $kit->increment('validacoes');
        }

        $validacao = $this->julgar($criativo);

        $criativo->update([
            'validacao_status' => $validacao->status,
            'validacao'         => $validacao->paraColuna(),
            'validacao_em'      => now(),
        ]);

        return $validacao;
    }

    // ═══ Reconciliação — "nada é aceito do modelo" ═══════════════════════════

    private function reconciliar(array $dados, CreativeJudgementResult $resultado): CreativeValidacao
    {
        $maxProblemas = (int) config('services.creative.validacao.max_problemas', 5);

        $problemasCru = is_array($dados['problemas'] ?? null) ? $dados['problemas'] : [];

        $problemas = [];
        foreach (array_slice($problemasCru, 0, max($maxProblemas, 0)) as $problema) {
            if (! is_array($problema)) {
                continue;
            }

            $tipo = in_array($problema['tipo'] ?? null, self::TIPOS_PROBLEMA_VALIDOS, true)
                ? (string) $problema['tipo']
                : 'outro';

            $gravidade = in_array($problema['gravidade'] ?? null, self::GRAVIDADES_VALIDAS, true)
                ? (string) $problema['gravidade']
                : 'media';

            $explicacao = mb_substr($this->sanitizar((string) ($problema['explicacao'] ?? '')), 0, 300);

            $problemas[] = ['tipo' => $tipo, 'gravidade' => $gravidade, 'explicacao' => $explicacao];
        }

        $temProblemaGravidadeAlta = count(array_filter($problemas, fn ($p) => $p['gravidade'] === 'alta')) > 0;

        $fidelidadeCru = $dados['fidelidade'] ?? null;
        $fidelidade = in_array($fidelidadeCru, ['ok', 'falha'], true)
            ? (string) $fidelidadeCru
            : ($temProblemaGravidadeAlta ? 'falha' : 'ok');

        $temProblemaEliminatorio = count(array_filter(
            $problemas,
            fn ($p) => $p['gravidade'] === 'alta' && in_array($p['tipo'], self::TIPOS_ELIMINATORIOS, true)
        )) > 0;

        // VAL-04: fidelidade=falha OU problema eliminatório → reprovada,
        // SEMPRE — mesmo que o modelo tenha escrito veredito "aprovada". O
        // caminho inverso não existe: nada transforma falha em aprovação.
        $status = ($fidelidade === 'falha' || $temProblemaEliminatorio)
            ? MlAnuncioCriativo::VALIDACAO_REPROVADA
            : MlAnuncioCriativo::VALIDACAO_APROVADA;

        $motivoCurto = mb_substr($this->sanitizar((string) ($dados['motivo_curto'] ?? '')), 0, 200);
        $motivoCurto = $motivoCurto !== '' ? $motivoCurto : null;

        return new CreativeValidacao(
            status: $status,
            fidelidade: $fidelidade,
            motivoCurto: $motivoCurto,
            problemas: $problemas,
            modelo: $resultado->modelo,
            latenciaMs: $resultado->latenciaMs,
            mensagem: $this->mensagemParaStatus($status, $motivoCurto),
        );
    }

    /** Frase pt-BR montada no SERVIDOR (APROV-04) — nunca string crua do modelo. */
    private function mensagemParaStatus(string $status, ?string $motivoCurto): string
    {
        return match ($status) {
            MlAnuncioCriativo::VALIDACAO_REPROVADA => $motivoCurto !== null
                ? "Risco: {$motivoCurto}. Confira antes de aprovar."
                : 'Risco identificado pela validação automática. Confira antes de aprovar.',
            MlAnuncioCriativo::VALIDACAO_APROVADA => $motivoCurto !== null
                ? "Validação automática aprovou esta imagem. {$motivoCurto}"
                : 'Validação automática aprovou esta imagem.',
            default => 'Não foi possível validar automaticamente esta imagem.',
        };
    }

    private function indisponivel(string $mensagem, ?string $modelo = null, ?int $latenciaMs = null): CreativeValidacao
    {
        return new CreativeValidacao(
            status: MlAnuncioCriativo::VALIDACAO_INDISPONIVEL,
            fidelidade: null,
            motivoCurto: null,
            problemas: [],
            modelo: $modelo,
            latenciaMs: $latenciaMs,
            mensagem: $mensagem,
        );
    }

    /**
     * Extrai o JSON mesmo cercado por crases com a marca `json` ou com texto
     * antes/depois — cópia privada do molde de
     * `AnaliseAnuncioService::extrairJson()` / `CreativePlanner::extrairJson()`.
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
     * controle — molde de `CreativePromptBuilder::sanitizar()` (NÃO
     * importado nem editado — trava de coordenação com a Fase 165).
     */
    private function sanitizar(string $texto): string
    {
        $semControle = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $texto) ?? $texto;
        $colapsado   = preg_replace('/\s+/u', ' ', $semControle) ?? $semControle;

        return trim($colapsado);
    }
}
