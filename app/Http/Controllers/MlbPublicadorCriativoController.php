<?php

namespace App\Http\Controllers;

use App\Jobs\GerarCriativoIaJob;
use App\Jobs\PlanejarKitCriativosJob;
use App\Models\MlAnuncioCriativo;
use App\Models\MlAnuncioCriativoKit;
use App\Models\PubProduto;
use App\Models\PubProdutoFatoCriativo;
use App\Models\PubRascunho;
use App\Models\User;
use App\Services\Creative\CreativeEngineAtivo;
use App\Services\Creative\CreativeKitDespachante;
use App\Services\Creative\CreativePermissao;
use App\Services\Creative\CreativeSlotCatalog;
use App\Services\Creative\Dto\CreativeContext;
use App\Services\Creative\ProductTruthBuilder;
use App\Services\Publicador\Criativos\ContextoCriativoDoPublicador;
use App\Services\Publicador\Criativos\PublicadorCriativoAprovacaoService;
use App\Services\Publicador\Criativos\PublicadorCriativoKitPresenter;
use App\Services\Publicador\Criativos\PublicadorCriativoReferenciaService;
use App\Services\Publicador\ProgramasPublicadorService;
use App\Support\Publicador\Imagem\ResolvedorGruposImagem;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Fase 165 (D-09) — o Creative Engine por PRODUTO do Publicador, em rotas
 * próprias (`mlb.anuncios.publicador.criativos.*`), NUNCA pelas rotas
 * antigas `criativo.*` do `MlbAnuncioController` (que não é tocado nesta
 * fase): o escopo antigo some com `rascunho_id = NULL` e
 * `planejarKitSobLock()` devolveria o kit de OUTRO produto para um token do
 * Publicador.
 *
 * **D-13 (decisão de desenho mais importante deste controller).** O kit é
 * endereçado pelo `id` numérico, escopado por `pub_rascunho_id` — nenhum
 * token de 32 caracteres (do kit, do slot ou do portador) sai daqui, nem na
 * URL (`whereNumber('kit')`/`whereNumber('indice')`) nem no JSON
 * (`kit_id`). O id numérico é enumerável, mas TODA rota compara
 * `pub_rascunho_id` com o rascunho do produto autorizado e responde 404
 * quando não bate — nunca 403 (T-165-16/T-165-17: não distinguir "existe mas
 * não é seu" de "não existe").
 *
 * Ordem obrigatória em TODO endpoint, igual à disciplina do Creative Engine
 * antigo: (1) chave `creative_engine_ativo` ligada, (2) produto autorizado
 * (`ProgramasPublicadorService::empresaDoProduto`), (3) rascunho do produto,
 * (4) kit/slot escopados por `pub_rascunho_id`, (5) permissão explícita
 * (`CreativePermissao::exigir`) SÓ DEPOIS do escopo — para não revelar a
 * existência de um kit pela diferença entre 403 e 404 (OPS-04/T-165-17). As
 * recusas de negócio usam o formato do Creative Engine:
 * `{ok:false, erros:[{mensagem}]}`.
 *
 * Fase 162 (validador Gemini-juiz): o gate de validação automática
 * (`validacao_status`/VAL-01/04/06) também vale no caminho do Publicador —
 * `aprovar()` nunca sobe uma imagem reprovada em silêncio (ver docblock do
 * método). Este plano (165-04) foi escrito ANTES da Fase 162 mergear; o
 * gate foi acrescentado aqui porque o código manda sobre o plano quando os
 * dois divergem.
 */
class MlbPublicadorCriativoController extends Controller
{
    public function __construct(
        private CreativeEngineAtivo $chave,
        private CreativePermissao $permissao,
        private ProgramasPublicadorService $programas,
        private PublicadorCriativoReferenciaService $referencias,
        private PublicadorCriativoAprovacaoService $aprovacao,
        private PublicadorCriativoKitPresenter $presenter,
        private CreativeKitDespachante $despachante,
        private CreativeSlotCatalog $catalogo,
    ) {}

    /**
     * O kit ATIVO (ou o último aprovado) do grupo pedido — D-15: no máximo
     * um kit ativo por (rascunho, grupo); reabrir a tela retoma. Sem kit
     * retomável nem aprovado (ou kit em `erro`, que não entra em nenhuma das
     * duas listas), devolve `{kit: null}` — nunca 404: a ausência de kit é
     * estado normal, não erro.
     */
    public function atual(Request $request, int $produto): JsonResponse
    {
        $r = $this->rascunhoAutorizado($produto);

        $dados = $request->validate(['grupo' => ['required', 'string', 'max:600']]);

        $kit = MlAnuncioCriativoKit::retomavelDoPublicador($r->id, $dados['grupo'])
            ?? MlAnuncioCriativoKit::ultimoAprovadoDoPublicador($r->id, $dados['grupo']);

        if ($kit === null) {
            return response()->json(['kit' => null]);
        }

        $this->atualizarEstado($kit);

        return response()->json(['kit' => $this->presenter->paraTela($kit->fresh(), $r)]);
    }

    /**
     * Polling — mesma disciplina do `criativoKitStatus()` antigo:
     * `encerrarSeTravado()`/`recalcularStatus()` ANTES de montar a resposta,
     * mas só quando faz sentido (decisão 3 do objective — ver
     * `atualizarEstado()`).
     */
    public function status(int $produto, int $kit): JsonResponse
    {
        $r = $this->rascunhoAutorizado($produto);
        $k = $this->kitDoRascunho($r, $kit);

        $this->atualizarEstado($k);

        return response()->json($this->presenter->paraTela($k->fresh(), $r));
    }

    /**
     * Dispara o planejamento do kit (referências + o kit em si), sob o MESMO
     * lock por (rascunho, grupo) que o `criativoKitPlanejar()` antigo usa
     * por rascunho — aqui por `pub_rascunho_id` + `md5(grupo)` (T-165-22: o
     * grupo vem da requisição só até aqui, validado contra
     * `gruposValidos()`; os demais endpoints nunca aceitam grupo do corpo).
     *
     * SEMPRE 202 antes de qualquer chamada ao provedor — o planejamento roda
     * fora da request, em `PlanejarKitCriativosJob` (fila `creative`).
     */
    public function planejar(Request $request, int $produto): JsonResponse
    {
        $r = $this->rascunhoAutorizado($produto);

        $this->permissao->exigir($request->user(), 'planejar');

        // 261005-si3: quando o POST passa de `post_max_size`, o PHP descarta o corpo inteiro
        // ANTES do Laravel — a validação normal "acharia" que faltou `grupo` (campo que o
        // operador nunca viu e nunca preencheu à mão), confundindo quem só tentou enviar uma
        // foto grande. Avisar isto primeiro, com a mensagem certa.
        if ($this->corpoDescartadoPeloPhp($request)) {
            return $this->recusa('Esta foto é grande demais para o servidor aceitar agora. Envie uma imagem de até 10 MB.');
        }

        $dados = $request->validate([
            'grupo' => ['required', 'string', 'max:600'],
            'imagens' => ['nullable', 'array', 'max:' . PublicadorCriativoReferenciaService::MAX],
            'imagens.*' => ['integer', 'distinct'],
            'referencias' => ['nullable', 'array', 'max:' . PublicadorCriativoReferenciaService::MAX],
            'referencias.*' => ['required', 'file', 'image', 'max:10240'],
        ], [
            'referencias.*.image' => 'Envie uma imagem (JPG ou PNG) de até 10 MB.',
            'referencias.*.max' => 'Envie uma imagem (JPG ou PNG) de até 10 MB.',
        ]);

        $grupo = $dados['grupo'];

        if (in_array($r->status, PublicadorCriativoAprovacaoService::INTOCAVEIS, true)) {
            return $this->recusa('Este anúncio já está publicado (ou sendo publicado) — as fotos não mudam mais por aqui.');
        }

        if (! in_array($grupo, $this->referencias->gruposValidos($r), true)) {
            return $this->recusa('Este grupo de fotos não existe mais neste anúncio. Recarregue a página.');
        }

        try {
            $fotos = $this->referencias->selecionarFotos($r, $dados['imagens'] ?? []);
        } catch (ValidationException $e) {
            return $this->recusa(collect($e->errors())->collapse()->first() ?? 'Uma das fotos escolhidas não é deste anúncio.');
        }

        $uploads = $request->file('referencias', []);
        $total = count($fotos) + count($uploads);

        if ($total === 0) {
            return $this->recusa('Escolha ao menos uma foto do anúncio ou envie uma foto do produto.');
        }

        if ($total > PublicadorCriativoReferenciaService::MAX) {
            return $this->recusa('Use no máximo ' . PublicadorCriativoReferenciaService::MAX . ' fotos de referência.');
        }

        $user = $request->user();
        $lock = Cache::lock('criativo-kit-planejar-pub:' . $r->id . ':' . md5($grupo), 5);

        try {
            $resultado = $lock->block(3, fn () => $this->planejarSobLock($r, $grupo, $user));
        } catch (LockTimeoutException) {
            $kitExistente = MlAnuncioCriativoKit::retomavelDoPublicador($r->id, $grupo);

            if ($kitExistente !== null) {
                return response()->json(['kit_id' => $kitExistente->id, 'status' => $kitExistente->status, 'criado' => false], 202);
            }

            return $this->recusa('Outra pessoa está planejando o kit destas fotos agora. Tente de novo em alguns segundos.', 409);
        }

        $kit = $resultado['kit'];

        if ($resultado['criado'] === true) {
            try {
                $this->referencias->guardar($resultado['portador'], $fotos, $uploads);
            } catch (\Throwable $e) {
                $kit->update(['status' => MlAnuncioCriativoKit::STATUS_ERRO, 'erro_mensagem' => 'Não foi possível guardar as fotos de referência. Tente de novo.']);
                Log::error("[Creative] Publicador: falha ao guardar referências do kit {$kit->id} (rascunho {$r->id}): {$e->getMessage()}");

                return $this->recusa('Não foi possível guardar as fotos de referência. Tente de novo.');
            }

            PlanejarKitCriativosJob::dispatch($resultado['portador']->id, $kit->id);

            Log::info("[Creative] Kit do Publicador {$kit->id} planejamento enfileirado (rascunho {$r->id}) por " . $user->name);
        }

        return response()->json(['kit_id' => $kit->id, 'status' => $kit->fresh()->status, 'criado' => $resultado['criado']], 202);
    }

    /**
     * Passos sob o lock por (rascunho, grupo) — devolve um array simples
     * (não HTTP), chamado de dentro de `Cache::lock()->block()`.
     *
     * @return array{kit: MlAnuncioCriativoKit, portador?: MlAnuncioCriativo, criado: bool}
     */
    private function planejarSobLock(PubRascunho $r, string $grupo, User $user): array
    {
        $kitExistente = MlAnuncioCriativoKit::retomavelDoPublicador($r->id, $grupo);

        if ($kitExistente !== null) {
            return ['kit' => $kitExistente, 'criado' => false];
        }

        $portador = $this->referencias->criarPortador($r, $grupo, $user);

        $kit = MlAnuncioCriativoKit::create([
            'token' => Str::random(32),
            'company_id' => $portador->company_id,
            'mlb_empresa_id' => $portador->mlb_empresa_id,
            'rascunho_id' => null,
            'pub_rascunho_id' => $r->id,
            'pub_grupo' => $grupo,
            'user_id' => $user->id,
            'criativo_referencia_id' => $portador->id,
            'status' => MlAnuncioCriativoKit::STATUS_PLANEJANDO,
        ]);

        return ['kit' => $kit, 'portador' => $portador, 'criado' => true];
    }

    /**
     * Dispara a geração das imagens — SEMPRE 202 antes de qualquer chamada
     * ao provedor (o despacho só enfileira jobs em `CreativeKitDespachante`).
     */
    public function gerar(Request $request, int $produto, int $kit): JsonResponse
    {
        $r = $this->rascunhoAutorizado($produto);
        $k = $this->kitDoRascunho($r, $kit);

        $this->permissao->exigir($request->user(), 'gerar');

        $this->atualizarEstado($k);

        if ($k->status === MlAnuncioCriativoKit::STATUS_APROVADO) {
            return $this->recusa('Este kit já foi aprovado.');
        }

        if ($k->status === MlAnuncioCriativoKit::STATUS_PLANEJANDO
            || ($k->status === MlAnuncioCriativoKit::STATUS_ERRO && $k->totalSlots() === 0)) {
            return $this->recusa('O planejamento deste kit ainda não terminou — aguarde antes de gerar as imagens.');
        }

        if ($k->tetoDeImagensAtingido()) {
            return $this->recusa((string) $k->motivoDoTeto());
        }

        if ($this->presenter->emAndamento($k)) {
            return response()->json(['kit_id' => $k->id, 'status' => $this->presenter->statusEfetivo($k, $k->slots()->get()), 'enfileirados' => 0], 202);
        }

        $this->reiniciarRelogioDaTentativa($k, $k->slots()->whereIn('status', [MlAnuncioCriativo::STATUS_PENDENTE, MlAnuncioCriativo::STATUS_ERRO])->pluck('id')->all());

        $res = $this->despachante->despachar($k);

        $k->refresh();

        return response()->json([
            'kit_id' => $k->id,
            'status' => $this->presenter->statusEfetivo($k, $k->slots()->get()),
            'enfileirados' => $res['enfileirados'],
        ], 202);
    }

    /**
     * Fase 165 (achado (b) do 165-01, combinado no checkpoint): o motor mede
     * o tempo-limite de travamento pelo `created_at` (`travada()`/
     * `travado()`). Aqui, `created_at` do kit e dos slots que vão RODAR
     * nesta tentativa passa a marcar o início da TENTATIVA — o início real
     * do kit continua em `started_at`. Sem isso, gerar muito tempo depois do
     * planejamento encerraria o kit como travado antes mesmo do despacho.
     *
     * Só kits/slots do Publicador passam por aqui; qualquer relatório que
     * ler `created_at` de `ml_anuncio_criativos`/`ml_anuncio_criativo_kits`
     * como "quando o kit nasceu" precisa usar `started_at` (registrado no
     * 165-01-SUMMARY.md para não se perder). Via query builder — sem mass
     * assignment: `created_at` não está no `$fillable`.
     */
    private function reiniciarRelogioDaTentativa(MlAnuncioCriativoKit $kit, array $slotIds): void
    {
        MlAnuncioCriativoKit::whereKey($kit->id)->update(['created_at' => now()]);

        if ($slotIds !== []) {
            MlAnuncioCriativo::whereIn('id', $slotIds)->update(['created_at' => now()]);
        }

        $kit->refresh();
    }

    /**
     * Aprova UMA imagem do kit — vira foto do rascunho do Publicador
     * (D-04/D-11), nunca envio direto ao ML.
     *
     * Fase 162 (VAL-01/04/06) — a validação automática é GATE no SERVIDOR,
     * molde literal do `criativoAprovar()` antigo: pendente há mais de
     * `LIMITE_VALIDACAO_MINUTOS` libera como `indisponivel` antes de
     * recusar por "em andamento"; reprovada só sobe com
     * `confirmar_risco=true` explícito, com o override auditado na própria
     * linha do slot (quem assumiu o risco e quando). Só entra quando o slot
     * ainda está `pronto` — um slot já `aprovado` que está sendo
     * READICIONADO (D-12, a foto saiu do rascunho ou do grupo) não passa de
     * novo pela validação: ela já rodou (ou foi confirmada) na primeira
     * aprovação.
     */
    public function aprovar(Request $request, int $produto, int $kit, int $indice): JsonResponse
    {
        $r = $this->rascunhoAutorizado($produto);
        $k = $this->kitDoRascunho($r, $kit);
        $slot = $this->slotDoKit($k, $indice);

        $this->permissao->exigir($request->user(), 'aprovar');

        if ($slot->status === MlAnuncioCriativo::STATUS_PRONTO) {
            $slot->encerrarValidacaoSeTravada();
            $slot->refresh();

            if ($slot->validacao_status === MlAnuncioCriativo::VALIDACAO_PENDENTE) {
                return $this->recusa('A validação automática desta imagem ainda está em andamento. Aguarde alguns segundos e tente de novo.');
            }

            $request->validate(['confirmar_risco' => ['sometimes', 'boolean']]);

            if ($slot->validacao_status === MlAnuncioCriativo::VALIDACAO_REPROVADA) {
                if (! $request->boolean('confirmar_risco')) {
                    return $this->recusa($slot->validacaoMensagem() ?? 'A validação automática identificou um risco nesta imagem. Confira antes de aprovar.');
                }

                $validacaoComOverride = $slot->validacao ?? [];
                $validacaoComOverride['override'] = [
                    'user_id' => $request->user()->id,
                    'em' => now()->toDateTimeString(),
                ];
                $slot->update(['validacao' => $validacaoComOverride]);

                Log::warning("[Creative] Publicador: aprovação com risco confirmado — slot {$slot->id} do kit {$k->id} por " . $request->user()->name);
            }
        }

        $res = $this->aprovacao->aprovarSlot($r, $slot, (string) $k->pub_grupo, $request->user());

        if (! $res['ok']) {
            return $this->recusa((string) $res['mensagem']);
        }

        return response()->json([
            'ok' => true,
            'imagem_id' => (string) $res['imagem_id'],
            'repetida' => $res['repetida'],
            'kit' => $this->presenter->paraTela($k->fresh(), $r),
        ]);
    }

    /**
     * Regenera UMA imagem do kit (Fase 165-05, CE165-06) — mesma
     * orquestração do `criativoRegenerar()` antigo (`MlbAnuncioController`,
     * linhas 1770-1849): "a mesma intenção, outra tentativa" (Decisão 10 do
     * 161-03-PLAN.md) — reusa o `slot_plano` já planejado, nunca replaneja, e
     * enfileira UM job em `creative`; os outros 6 slots ficam intocados.
     *
     * `motivo` (opcional, até 300 caracteres): o que o operador escreveu em
     * "o que não ficou bom?". Sanitizado aqui (tamanho, sem quebra de linha
     * nem caractere de controle já cuidado pela validação `string`/`max`) e
     * NUNCA tratado como fato novo sobre o produto (TRUTH-02/03) —
     * `GerarCriativoIaJob` lê `regeneracoes` e o ÚLTIMO item de
     * `regenerar_motivos` direto do slot (não há parâmetro para passar
     * daqui) e passa os dois para `CreativePromptBuilder::paraSlot()`, que
     * só aceita o ajuste como VARIAÇÃO visual, nunca como novo atributo do
     * produto. O texto nunca é gravado no log (T-L8O-02).
     *
     * Ordem: (1) rascunho → kit → slot, escopados; (2) permissão explícita;
     * (3) validação do `motivo`; (4) recusas em pt-BR — kit fechado ou sem
     * referência viva (ANTES de subir qualquer contador: sem referência o
     * job falharia depois de já ter gasto o clique — info 2 da revisão),
     * slot já aprovado (D-11: já está em `pub_imagens`), slot em andamento,
     * teto do asset/kit; (5) transação que acrescenta entrada em
     * `regenerar_motivos` e incrementa as duas contagens; (6) reinicia o
     * relógio da tentativa (achado (b) do 165-01 — sem isto, um kit com mais
     * de 12 min encerraria o slot regenerado como travado antes mesmo de
     * rodar); (7) despacha UM job — NUNCA `recalcularStatus()` aqui (achado
     * (a): com um slot aprovado misturado, o kit voltaria a `gerando`; o
     * status de tela já vem do presenter); (8) 202.
     */
    public function regenerar(Request $request, int $produto, int $kit, int $indice): JsonResponse
    {
        $r = $this->rascunhoAutorizado($produto);
        $kit = $this->kitDoRascunho($r, $kit);
        $slot = $this->slotDoKit($kit, $indice);

        $this->permissao->exigir($request->user(), 'regenerar');

        $request->validate(
            ['motivo' => ['nullable', 'string', 'max:300']],
            ['motivo.max' => 'O texto do que não ficou bom deve ter no máximo 300 caracteres.'],
        );

        if ($kit->status === MlAnuncioCriativoKit::STATUS_APROVADO
            || $kit->criativoReferencia?->referenciasVivas() === []) {
            return $this->recusa('Este kit já foi fechado e as fotos de referência foram apagadas — gere outro kit para tentar de novo.');
        }

        if ($slot->status === MlAnuncioCriativo::STATUS_APROVADO) {
            return $this->recusa('Esta imagem já está nas fotos do anúncio — não é possível gerar de novo.');
        }

        if (in_array($slot->status, MlAnuncioCriativo::STATUS_EM_ANDAMENTO, true)) {
            return $this->recusa('Esta imagem já está sendo gerada.');
        }

        if (! $kit->podeRegenerarAsset($slot)) {
            return $this->recusa($kit->motivoDoTetoAsset($slot) ?? 'Esta imagem não pode ser gerada de novo agora.');
        }

        DB::transaction(function () use ($slot, $kit, $request) {
            // Quick 261003-l8o (T-L8O-05): cada clique ACRESCENTA uma
            // entrada (nunca sobrescreve) — o texto de uma regeneração
            // anterior nunca vaza para a próxima.
            $texto = trim((string) $request->input('motivo', ''));
            $motivos = $slot->regenerar_motivos ?? [];
            $motivos[] = [
                'em' => now()->toDateTimeString(),
                'user_id' => $request->user()->id,
                'texto' => $texto !== '' ? $texto : null,
            ];

            $slot->update([
                'status' => MlAnuncioCriativo::STATUS_PENDENTE,
                'etapa' => null,
                'erro_mensagem' => null,
                'regenerar_motivos' => $motivos,
            ]);
            $slot->increment('regeneracoes');
            $kit->increment('regeneracoes');
        });

        $this->reiniciarRelogioDaTentativa($kit, [$slot->id]);

        GerarCriativoIaJob::dispatch($slot->id);

        // GEN-05/T-L8O-02: nunca o texto do motivo — só o que ajuda a rastrear quem pediu o quê.
        Log::info("[Creative] Publicador: regeneração enfileirada — slot {$indice} do kit {$kit->id} por " . $request->user()->name);

        return response()->json([
            'indice' => $indice,
            'status' => $slot->fresh()->status,
            'regeneracoes_restantes' => $kit->fresh()->regeneracoesRestantesAsset($slot->fresh()),
        ], 202);
    }

    /**
     * Aprova o KIT INTEIRO de uma vez (Fase 165-05, CE165-09/D-04) — delega a
     * `PublicadorCriativoAprovacaoService::aprovarKit()` (165-03): cada slot
     * `pronto`, na ordem de `slot_indice`, pelo mesmo `aprovarSlot()`; o kit
     * só fecha (`status = aprovado`) quando nenhum falhou e o mínimo
     * congelado foi atingido. A referência efêmera do portador só é apagada
     * AQUI — nunca na aprovação de um slot isolado (`aprovar()`), porque os
     * slots ainda não aprovados podem precisar regenerar e leem a MESMA foto
     * do portador (retenção, CE165-09).
     */
    public function aprovarKit(Request $request, int $produto, int $kit): JsonResponse
    {
        $r = $this->rascunhoAutorizado($produto);
        $kit = $this->kitDoRascunho($r, $kit);

        $this->permissao->exigir($request->user(), 'aprovar');

        $res = $this->aprovacao->aprovarKit($r, $kit, $request->user());

        if ($res['aprovadas'] === 0 && $res['ok'] === false) {
            return $this->recusa($res['mensagem']);
        }

        return response()->json([
            'ok' => $res['ok'],
            'aprovadas' => $res['aprovadas'],
            'falharam' => $res['falharam'],
            'kit_aprovado' => $res['kit_aprovado'],
            'mensagem' => $res['mensagem'],
            'kit' => $this->presenter->paraTela($kit->fresh(), $r),
        ]);
    }

    /**
     * Fase 169 (TXT-01/04) — o fato confirmado pelo operador para ESTE
     * produto: lista de confirmados + se algum slot de texto já é elegível
     * (`pode_ter_texto`) + o que falta quando não é (`faltam`). Leitura —
     * mesma disciplina de `atual()`/`status()`: SEM `permissao->exigir()`.
     */
    public function fatos(int $produto): JsonResponse
    {
        $r = $this->rascunhoAutorizado($produto);

        return response()->json($this->statusDosFatos($r));
    }

    /**
     * Grava UM fato confirmado (ponto forte ou medida) para o produto —
     * escrita, mesma disciplina de permissão de `planejar()`.
     */
    public function salvarFato(Request $request, int $produto): JsonResponse
    {
        $r = $this->rascunhoAutorizado($produto);

        $this->permissao->exigir($request->user(), 'planejar');

        $dados = $request->validate([
            'tipo' => ['required', 'string', 'in:' . PubProdutoFatoCriativo::TIPO_BENEFICIO . ',' . PubProdutoFatoCriativo::TIPO_MEDIDA],
            'texto' => ['required', 'string', 'max:300'],
        ]);

        PubProdutoFatoCriativo::create([
            'pub_produto_id' => $r->produto_id,
            'tipo' => $dados['tipo'],
            'texto' => trim($dados['texto']),
            'confirmado_por_id' => $request->user()->id,
        ]);

        return response()->json($this->statusDosFatos($r));
    }

    /**
     * Remove UM fato confirmado — escopado por `pub_produto_id` do rascunho
     * autorizado. Fato de outro produto dá 404 (T-169-05), nunca 403 — nunca
     * distinguir "existe mas não é seu" de "não existe" (T-165-16/17).
     */
    public function removerFato(Request $request, int $produto, int $fato): JsonResponse
    {
        $r = $this->rascunhoAutorizado($produto);

        $this->permissao->exigir($request->user(), 'planejar');

        $registro = PubProdutoFatoCriativo::where('id', $fato)->where('pub_produto_id', $r->produto_id)->first();
        abort_if($registro === null, 404, 'Fato não encontrado.');

        $registro->delete();

        return response()->json($this->statusDosFatos($r));
    }

    /**
     * Fase 169 — a resposta dos três endpoints de fatos: monta um
     * `CreativeContext` MANUAL (sem nenhum `MlAnuncioCriativo` persistido,
     * `rascunhoId: 0`) a partir de `ContextoCriativoDoPublicador::montar()`
     * (mesma leitura que `CreativeContextBuilder::paraPublicador()` faz para
     * gerar de verdade), monta o `ProductTruth` e devolve
     * confirmados/pode_ter_texto/faltam. Nenhum id de kit/slot/portador nem
     * token de 32 caracteres sai daqui (T-169-08, D-13).
     *
     * @return array{confirmados: array<int, array{id: int, tipo: string, texto: string}>, pode_ter_texto: bool, faltam: array<int, string>}
     */
    private function statusDosFatos(PubRascunho $r): array
    {
        $dados = app(ContextoCriativoDoPublicador::class)->montar($r, ResolvedorGruposImagem::GERAL);

        $contexto = new CreativeContext(
            rascunhoId: 0,
            produto: $dados['produto'],
            marca: $dados['atributos']['BRAND'] ?? null,
            modelo: $dados['atributos']['MODEL'] ?? null,
            categoriaId: $dados['categoria_id'],
            descricao: $dados['descricao'],
            atributos: $dados['atributos'],
            variacoes: $dados['variacoes'],
            loja: $dados['loja'],
            imagensReferencia: [],
            referenciasMeta: [],
            fatosHumanosBeneficios: $dados['fatos_humanos']['beneficios'],
            fatosHumanosMedidas: $dados['fatos_humanos']['medidas'],
        );

        $truth = app(ProductTruthBuilder::class)->paraContexto($contexto);

        $confirmados = PubProdutoFatoCriativo::where('pub_produto_id', $r->produto_id)
            ->orderBy('id')
            ->get()
            ->map(fn (PubProdutoFatoCriativo $f) => ['id' => $f->id, 'tipo' => $f->tipo, 'texto' => $f->texto])
            ->values()
            ->all();

        return [
            'confirmados' => $confirmados,
            'pode_ter_texto' => $this->catalogo->algumAceitaTexto($truth),
            'faltam' => $this->catalogo->faltamParaTexto($truth),
        ];
    }

    /** A referência viva do portador — mesma disciplina de `criativoReferenciaVer()` antigo. */
    public function referencia(int $produto, int $kit, int $indice): Response
    {
        $r = $this->rascunhoAutorizado($produto);
        $k = $this->kitDoRascunho($r, $kit);

        $portador = $k->criativoReferencia;
        $referencia = $portador !== null ? collect($portador->referenciasVivas())->firstWhere('indice', $indice) : null;
        abort_if($referencia === null, 404, 'Referência já foi removida.');

        $disco = Storage::disk('local');
        abort_unless($disco->exists($referencia['path']), 404, 'Referência já foi removida.');

        return response($disco->get($referencia['path']), 200, [
            'Content-Type' => $referencia['mime'] ?? 'image/jpeg',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /** O binário da imagem gerada de um slot — mesma disciplina de `criativoImagem()` antigo. */
    public function imagem(int $produto, int $kit, int $indice): Response
    {
        $r = $this->rascunhoAutorizado($produto);
        $k = $this->kitDoRascunho($r, $kit);
        $slot = $this->slotDoKit($k, $indice);

        abort_if($slot->imagem_path === null, 404, 'Imagem ainda não foi gerada.');

        $disco = Storage::disk('local');
        abort_unless($disco->exists($slot->imagem_path), 404, 'Imagem não encontrada.');

        return response($disco->get($slot->imagem_path), 200, [
            'Content-Type' => $slot->imagem_mime ?? 'image/jpeg',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /** O produto autorizado + o rascunho do Publicador — chave desligada ou fora do escopo dão 404. */
    private function rascunhoAutorizado(int $produto): PubRascunho
    {
        abort_unless($this->chave->ativa(), 404);

        $p = PubProduto::findOrFail($produto);
        abort_if($this->programas->empresaDoProduto($p) === null, 404);

        return PubRascunho::where('produto_id', $p->id)->firstOrFail();
    }

    /** O kit, escopado por `pub_rascunho_id` — nunca por `rascunho_id` (esse é o espaço do assistente antigo). */
    private function kitDoRascunho(PubRascunho $r, int $kit): MlAnuncioCriativoKit
    {
        $k = MlAnuncioCriativoKit::whereKey($kit)->where('pub_rascunho_id', $r->id)->first();
        abort_if($k === null, 404, 'Kit não encontrado.');

        return $k;
    }

    /** O slot do kit, pelo índice — 404 quando não existe (índice inventado ou fora do total). */
    private function slotDoKit(MlAnuncioCriativoKit $kit, int $indice): MlAnuncioCriativo
    {
        $slot = $kit->slots()->where('slot_indice', $indice)->first();
        abort_if($slot === null, 404, 'Imagem não encontrada.');

        return $slot;
    }

    /** Decisão 3 do objective: só chama o que precisa, na ordem que importa. */
    private function atualizarEstado(MlAnuncioCriativoKit $kit): void
    {
        if ($this->presenter->emAndamento($kit)) {
            $kit->encerrarSeTravado();
        }

        if ($kit->aprovadas() === 0) {
            $kit->recalcularStatus();
        }
    }

    private function recusa(string $mensagem, int $status = 422): JsonResponse
    {
        return response()->json(['ok' => false, 'erros' => [['mensagem' => $mensagem]]], $status);
    }

    /**
     * 261005-si3: o `Content-Length` sobrevive no cabeçalho (vai ANTES do corpo) mesmo quando o
     * PHP descarta o corpo por passar de `post_max_size` — comparar os dois distingue "o PHP
     * jogou tudo fora" de "o operador mandou um POST de verdade vazio".
     */
    private function corpoDescartadoPeloPhp(Request $request): bool
    {
        if (! $request->isMethod('post') || $request->post() !== [] || $request->allFiles() !== []) {
            return false;
        }

        $limite = self::bytesDoIni((string) ini_get('post_max_size'));
        $tamanho = (int) $request->server('CONTENT_LENGTH', 0);

        return $limite > 0 && $tamanho > $limite;
    }

    private static function bytesDoIni(string $valor): int
    {
        $valor = trim($valor);
        $numero = (int) $valor;
        $unidade = strtolower(substr($valor, -1));

        return match ($unidade) {
            'g' => $numero * 1024 ** 3,
            'm' => $numero * 1024 ** 2,
            'k' => $numero * 1024,
            default => $numero,
        };
    }
}
