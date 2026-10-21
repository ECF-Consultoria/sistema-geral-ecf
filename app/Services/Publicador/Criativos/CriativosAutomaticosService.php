<?php

namespace App\Services\Publicador\Criativos;

use App\Jobs\PlanejarKitCriativosJob;
use App\Jobs\Publicador\AplicarCriativosAutomaticosJob;
use App\Jobs\Publicador\GerarCriativosAutomaticosJob;
use App\Models\AcervoImagem;
use App\Models\Configuracao;
use App\Models\EstruturaProdutoVariacao;
use App\Models\EstruturaProdutoVariacaoImagem;
use App\Models\MlAnuncioCriativo;
use App\Models\MlAnuncioCriativoKit;
use App\Models\PubImagem;
use App\Models\PubProduto;
use App\Models\PubRascunho;
use App\Models\User;
use App\Services\Creative\CreativeCategoriaMobiliarioService;
use App\Services\Creative\CreativeEngineAtivo;
use App\Services\Creative\CreativeKitDespachante;
use App\Services\Creative\CreativePermissao;
use App\Services\Publicador\CombosDoPlanejamentoService;
use App\Services\Publicador\EditorRascunhoService;
use App\Services\Publicador\PortalProdutoLeitor;
use App\Services\Publicador\PreparoIaDoRascunhoService;
use App\Support\Publicador\Imagem\ConversorParaJpg;
use App\Support\Publicador\Imagem\ResolvedorGruposImagem;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Support\Publicador\Imagem\ArquivosDeImagem;
use Illuminate\Support\Str;

/**
 * Imagens por IA AUTOMÁTICAS (10/10/2026) — gatilho PRONTO e DESLIGADO. Decisão do usuário: quando o produto chega
 * do Portal com a ficha completa e as fotos do cliente, o Creative Engine (do outro dev, Gemini, 2 imagens por kit
 * ≈ US$ 0,20) pode gerar as imagens sozinho — mas só depois que o dono do Creative Engine ajustar o lado dele
 * (`.planning/coordenacao/261010-criativos-automaticos.md`). Até lá, `publicador.criativos_auto.ativo = false`.
 *
 * Chamado no fim do preparo pela IA (`PreparoIaDoRascunhoService`): é o ÚLTIMO elo da cadeia de texto (título →
 * Modelo → descrição → isto), ou sozinho quando não houve texto a gerar. Só age quando TUDO vale, nesta ordem:
 *  1. `publicador.criativos_auto.ativo` (env `PUBLICADOR_CRIATIVOS_AUTO_ATIVO`, padrão false), a chave do Creative
 *     Engine (`CreativeEngineAtivo`) e a empresa na lista `configuracoes.publicador_criativos_auto_companies`;
 *  2. não é kit da Fase N (o kit tem a capa própria, `CapaDoKitService`), rascunho existe e não está publicado;
 *  3. a ficha do Portal está completa (a MESMA régua da IA de texto, `fichaCompleta`);
 *  4. o usuário de sistema `configuracoes.publicador_criativos_auto_usuario` existe, está ativo e tem a chave
 *     `mlb.criativos_ia` (`CreativePermissao` — a mesma regra de quem clica);
 *  5. o grupo de fotos (a galeria geral e cada cor; só a geral, ou a 1ª cor, com `todas_as_cores` = false) tem ≥ 1
 *     foto que o CLIENTE pôs no Portal, com arquivo no disco (20/10/2026, o usuário: "a imagem só deve ser gerada
 *     automaticamente, assim como os títulos, se o cliente puser uma imagem real do produto dele no Portal") —
 *     foto que a equipe subiu no Publicador e imagem por IA nunca são referência (o grupo que já recebeu o kit
 *     fica só com imagens por IA e, por isso, não gera de novo);
 *  6. nenhum kit em andamento ou aprovado para o rascunho + grupo (D-15: um kit ativo por grupo);
 *  7. o hash das fotos + ficha é diferente do último pedido (`step_state.criativos_auto`): o mesmo produto não
 *     gera de novo a cada save;
 *  8. cabe no teto diário da empresa (`limite_diario_por_empresa`).
 * Passando, planeja e gera na MESMA passada (as referências efêmeras somem em 48 h — `creative:limpar-referencias` —,
 * então planejar e esperar alguém clicar "Gerar" desperdiçaria o plano): `PlanejarKitCriativosJob` (o do Creative
 * Engine, intocado) e, encadeado, `GerarCriativosAutomaticosJob`, que despacha a geração pelo mesmo
 * `CreativeKitDespachante` do botão.
 *
 * Desde 20/10/2026 (decisões do usuário) as imagens do kit automático ENTRAM SOZINHAS no anúncio e passam a ser
 * AS fotos do grupo ({@see aplicar()}): "o preview é a única coisa que o colaborador confere; a IA faz o resto, e
 * se estiver errado a gente corrige" e "a imagem do cliente é usada apenas para referência de recriar igual". A
 * conferência é a Prévia (etapa 5). O kit tem 3 imagens de tipos fixos — Imagem principal, Ambientação e Textura
 * ("3 imagens, 2 capas": o Clássico e o Premium abrem cada um com uma capa, e a textura nunca é capa); em móvel a
 * Ambientação vem primeiro ({@see tiposDoKit()}). O kit pedido no botão segue com a aprovação de gente, e a imagem que
 * o juiz reprovou também.
 *
 * 21/10/2026 — A CAPA DA FASE N (decisão do usuário, "vamos fazer assim"): o kit da fase 2 em diante que o
 * Planejamento cria sozinho ({@see CombosDoPlanejamentoService}) pede a capa do conjunto — as N unidades juntas —
 * no mesmo passo em que nasce. A fase só nasce com a Fase 1 publicada, então a foto de base já foi conferida. Dois
 * tipos fixos, fundo limpo e ambientada ({@see self::TIPOS_DA_CAPA}), por cor que vai ao ar, com a foto REAL do
 * cliente daquela cor como referência ({@see gruposDaCapa()}); entram NA FRENTE das fotos que o kit herdou da Fase 1.
 * A fase criada à mão ("Criar Fase N", produto fora do Portal) segue com a capa do botão.
 *
 * Nunca lança para quem chama (o preparo): devolve o motivo, que vai para o log.
 */
final class CriativosAutomaticosService
{
    public const CHAVE_EMPRESAS = 'publicador_criativos_auto_companies';

    public const CHAVE_USUARIO = 'publicador_criativos_auto_usuario';

    /** Onde a memória do último pedido mora no rascunho. */
    public const MEMORIA = 'criativos_auto';

    /**
     * A capa do conjunto da fase N, na ordem da galeria: o fundo limpo abre o anúncio (Clássico e Premium — o combo não
     * rotaciona a capa) e a ambientada vem em 2º. Os mesmos dois tipos da capa do botão ({@see \App\Services\Publicador\CapaDoKitService}).
     */
    public const TIPOS_DA_CAPA = ['hero', 'lifestyle'];

    /** O que a automação escreve ou não descreve o produto: fora do hash (como no preparo de texto). */
    private const FORA_DO_HASH = ['MODEL', 'GTIN', 'SELLER_SKU', 'EMPTY_GTIN_REASON'];

    public function __construct(
        private CreativeEngineAtivo $chave,
        private CreativePermissao $permissao,
        private PublicadorCriativoReferenciaService $referencias,
    ) {}

    /** A checagem barata (chaves e lista de empresas): sem ela, o preparo nem põe o Job na cadeia. */
    public function ligadoPara(?int $companyId): bool
    {
        return (bool) config('publicador.criativos_auto.ativo', false)
            && $companyId !== null
            && in_array($companyId, $this->empresas(), true)
            && $this->chave->ativa();
    }

    /**
     * Todas as condições e, passando, o kit planejado + a geração encadeada.
     *
     * @return string desligado, sem_rascunho, kit_da_fase, intocavel, incompleto, sem_usuario, sem_permissao, sem_foto,
     *                kit_existente, em_dia, limite, ocupado, erro, planejando (um ou mais grupos). No kit da fase N
     *                vindo do Planejamento, a capa do conjunto ({@see capaDaFase()}).
     */
    public function avaliar(int $rascunhoId): string
    {
        try {
            $r = PubRascunho::find($rascunhoId);
            $pub = $r?->produto;
            if ($r === null || $pub === null) {
                return 'sem_rascunho';
            }
            if (! $this->ligadoPara($pub->company_id !== null ? (int) $pub->company_id : null)) {
                return 'desligado';
            }
            if ($pub->produto_base_id !== null) {
                return $this->capaDaFase($r, $pub);
            }
            if (in_array($r->status, PublicadorCriativoAprovacaoService::INTOCAVEIS, true)) {
                return 'intocavel';
            }
            if (! app(PreparoIaDoRascunhoService::class)->fichaCompleta($pub)) {
                return 'incompleto';
            }
            $usuario = $this->usuario();
            if ($usuario === null) {
                return 'sem_usuario';
            }
            if (! $this->permissao->podePlanejar($usuario) || ! $this->permissao->podeGerar($usuario)) {
                return 'sem_permissao';
            }

            $resultados = [];
            foreach ($this->grupos($r, $pub) as $grupo => $fotos) {
                $resultados[] = $this->planejarGrupo($r, $pub, $grupo, $fotos, $usuario);
            }
            if ($resultados === []) {
                return 'sem_foto';
            }

            return in_array('planejando', $resultados, true) ? 'planejando' : $resultados[0];
        } catch (\Throwable $e) {
            Log::error("[Publicador] Imagens automáticas: o rascunho {$rascunhoId} não foi avaliado: {$e->getMessage()}");

            return 'erro';
        }
    }

    /**
     * Depois do planejamento: despacha a geração das imagens pelo MESMO despachante do "Gerar agora". A chave é
     * conferida de novo aqui — é a hora em que o dinheiro sai.
     *
     * @return string sem_kit, desligado, nao_planejado, intocavel, teto, gerando:N
     */
    public function gerar(int $kitId): string
    {
        $kit = MlAnuncioCriativoKit::find($kitId);
        if ($kit === null || $kit->pub_rascunho_id === null) {
            return 'sem_kit';
        }
        if (! (bool) config('publicador.criativos_auto.ativo', false) || ! $this->chave->ativa()) {
            return 'desligado';
        }
        if ($kit->status !== MlAnuncioCriativoKit::STATUS_PLANEJADO) {
            return 'nao_planejado';
        }
        $r = PubRascunho::find($kit->pub_rascunho_id);
        if ($r === null || in_array($r->status, PublicadorCriativoAprovacaoService::INTOCAVEIS, true)) {
            return 'intocavel';
        }
        if ($kit->tetoDeImagensAtingido()) {
            return 'teto';
        }

        // O relógio do motor conta da TENTATIVA (o mesmo cuidado do `MlbPublicadorCriativoController::gerar`).
        $pendentes = $kit->slots()->whereIn('status', [MlAnuncioCriativo::STATUS_PENDENTE, MlAnuncioCriativo::STATUS_ERRO])->pluck('id')->all();
        MlAnuncioCriativoKit::whereKey($kit->id)->update(['created_at' => now()]);
        if ($pendentes !== []) {
            MlAnuncioCriativo::whereIn('id', $pendentes)->update(['created_at' => now()]);
        }

        $res = app(CreativeKitDespachante::class)->despachar($kit->fresh());
        Log::info("[Creative] Imagens automáticas: kit {$kit->id} do rascunho {$r->id} — {$res['enfileirados']} imagem(ns) na fila.");

        return 'gerando:'.$res['enfileirados'];
    }

    /**
     * As imagens do kit AUTOMÁTICO pronto entram SOZINHAS no anúncio e passam a ser AS fotos do grupo, na ordem dos
     * slots; as do cliente saem da galeria e ficam no rascunho só como referência (decisões do usuário de
     * 20/10/2026 — como no assistente antigo, onde o kit é o dono das fotos). Quem confere é a Prévia (etapa 5).
     *
     * - Só o kit que ESTA automação pediu (`step_state.criativos_auto.grupos[grupo].kit_id`): o do botão segue com a
     *   aprovação de gente. Uma vez por kit (`aplicado_em`).
     * - Espera o juiz (validação da Fase 162): com algum slot pronto ainda `pendente`, sai — volta quando ele
     *   terminar. Imagem REPROVADA que o motor ainda vai refazer sozinho (VAL-05: uma vez, dentro do orçamento do kit)
     *   também faz esperar; a que não vai ser refeita não entra e fica no painel para alguém decidir.
     * - Entra pelo MESMO `aprovarSlot` do botão (foto do Publicador, dedupe por sha256, disco) e depois o grupo
     *   fica só com elas (`EditorRascunhoService::trocarFotosDoGrupo`). Nenhuma imagem aprovável: as fotos do
     *   cliente ficam — o anúncio nunca fica sem foto por aqui. Na regra de hoje, o Clássico abre com a 1ª e o
     *   Premium com a 2ª (rotação D6).
     *
     * Nunca lança para quem chama (o Job): devolve o motivo, que vai para o log.
     *
     * @return string sem_kit, nao_pronto, desligado, nao_automatico, ja_aplicado, intocavel, sem_usuario,
     *                esperando_juiz, esperando_regeneracao, nada_aprovavel, erro, aplicado:N
     */
    public function aplicar(int $kitId): string
    {
        try {
            $kit = MlAnuncioCriativoKit::find($kitId);
            if ($kit === null || $kit->pub_rascunho_id === null) {
                return 'sem_kit';
            }
            if (! in_array($kit->status, [MlAnuncioCriativoKit::STATUS_PRONTO, MlAnuncioCriativoKit::STATUS_PARCIAL], true)) {
                return 'nao_pronto';
            }
            if (! (bool) config('publicador.criativos_auto.ativo', false) || ! $this->chave->ativa()) {
                return 'desligado';
            }
            $grupo = (string) $kit->pub_grupo;
            $memoria = (array) ($this->lerEstado((int) $kit->pub_rascunho_id)[self::MEMORIA]['grupos'][$grupo] ?? []);
            if ((int) ($memoria['kit_id'] ?? 0) !== (int) $kit->id) {
                return 'nao_automatico';
            }
            if (isset($memoria['aplicado_em'])) {
                return 'ja_aplicado';
            }
            $r = PubRascunho::find($kit->pub_rascunho_id);
            if ($r === null || in_array($r->status, PublicadorCriativoAprovacaoService::INTOCAVEIS, true)) {
                return 'intocavel';
            }
            $usuario = $this->usuario();
            if ($usuario === null) {
                return 'sem_usuario';
            }

            $prontos = $kit->slots()->where('status', MlAnuncioCriativo::STATUS_PRONTO)->orderBy('slot_indice')->get();
            if ((bool) config('services.creative.validacao.ativa', true)
                && $prontos->contains(fn (MlAnuncioCriativo $s) => $s->validacao_status === MlAnuncioCriativo::VALIDACAO_PENDENTE)) {
                return 'esperando_juiz';
            }
            $reprovadas = $prontos->filter(fn (MlAnuncioCriativo $s) => $s->validacao_status === MlAnuncioCriativo::VALIDACAO_REPROVADA);
            if ((bool) config('services.creative.validacao.regenerar_automatico', true)
                && $reprovadas->contains(fn (MlAnuncioCriativo $s) => $s->regeneracao_automatica !== true && $kit->podeRegenerarAsset($s))) {
                return 'esperando_regeneracao';
            }
            $boas = $prontos->reject(fn (MlAnuncioCriativo $s) => $s->validacao_status === MlAnuncioCriativo::VALIDACAO_REPROVADA)->values();
            if ($boas->isEmpty()) {
                $this->marcarAplicado($r->id, $grupo, 0);
                // Fecha o kit (20/10/2026, TESTE-SKU: a foto do Portal era de OUTRO produto e o juiz reprovou as 3). Parado em
                // "pronto", ele seguraria o grupo (D-15) e a foto certa que o cliente subir depois não geraria nada. Com os
                // mesmos fatos, o hash da memória segura a repetição (`em_dia`). Sem os ganchos: query, não modelo.
                MlAnuncioCriativoKit::whereKey($kit->id)->update(['status' => MlAnuncioCriativoKit::STATUS_ERRO, 'finished_at' => now(),
                    'erro_mensagem' => ! empty($memoria['capa'])
                        ? 'Nenhuma capa do conjunto passou no juiz; o kit seguiu com as fotos da Fase 1.'
                        : 'Nenhuma imagem passou no juiz e a foto do cliente ficou. Uma foto ou ficha nova no Portal gera de novo.']);
                Log::info("[Creative] Imagens automáticas: kit {$kit->id} sem imagem aprovável (o juiz reprovou); nada entrou no rascunho {$r->id}.");

                return 'nada_aprovavel';
            }

            $aprovacao = app(PublicadorCriativoAprovacaoService::class);
            $ids = [];
            foreach ($boas as $slot) {
                $res = $aprovacao->aprovarSlot($r->fresh(), $slot, $grupo, $usuario);
                if ($res['ok'] && $res['imagem_id'] !== null) {
                    $ids[] = (int) $res['imagem_id'];
                } else {
                    Log::warning("[Creative] Imagens automáticas: o slot {$slot->id} do kit {$kit->id} não entrou no rascunho {$r->id}: {$res['mensagem']}");
                }
            }
            $editor = app(EditorRascunhoService::class);
            if ($ids !== [] && ! empty($memoria['capa'])) {
                // A capa do conjunto da fase N vai NA FRENTE, na ordem dos slots (fundo limpo, depois ambientada); as
                // fotos que o kit herdou da Fase 1 seguem depois.
                $editor->trocarFotosDoGrupo($r->fresh(), $grupo, [...$ids, ...array_values(array_diff($this->galeriaDoGrupo($r->id, $grupo), $ids))]);
            } elseif ($ids !== []) {
                // A foto do cliente é só referência: o grupo fica com as imagens do kit.
                $editor->trocarFotosDoGrupo($r->fresh(), $grupo, $ids);
            }
            if ($ids !== [] && count($ids) === $prontos->count()) {
                // Como o "Aprovar kit": fecha o kit só quando TODA imagem pronta entrou.
                $kit->update(['status' => MlAnuncioCriativoKit::STATUS_APROVADO, 'aprovado_por' => $usuario->id, 'aprovado_em' => now()]);
            }
            $this->marcarAplicado($r->id, $grupo, count($ids));
            Log::info("[Creative] Imagens automáticas: kit {$kit->id} — ".count($ids)." imagem(ns) no lugar das fotos do grupo {$grupo} do rascunho {$r->id}.");

            // O resumo do que falta (lista, vitrine): sem ele o rascunho mostraria o estado de antes das fotos.
            try {
                $editor->estado($r->fresh());
            } catch (\Throwable $e) {
                Log::warning("[Creative] Imagens automáticas: o resumo do rascunho {$r->id} não foi recalculado: {$e->getMessage()}");
            }

            return 'aplicado:'.count($ids);
        } catch (\Throwable $e) {
            Log::error("[Creative] Imagens automáticas: o kit {$kitId} não entrou no anúncio: {$e->getMessage()}");

            return 'erro';
        }
    }

    /**
     * Gatilho do KIT (o `updated` do Eloquent, registrado no `AppServiceProvider`): o status virou pronto/parcial.
     * Só o barato aqui — a chave e o kit ser do Publicador; quem confere o resto é o {@see aplicar()}, no Job. Roda
     * dentro dos Jobs do Creative Engine: NUNCA lança.
     */
    public static function aoMudarKit(MlAnuncioCriativoKit $kit): void
    {
        try {
            if ($kit->pub_rascunho_id !== null && $kit->wasChanged('status')
                && in_array($kit->status, [MlAnuncioCriativoKit::STATUS_PRONTO, MlAnuncioCriativoKit::STATUS_PARCIAL], true)
                && (bool) config('publicador.criativos_auto.ativo', false)) {
                AplicarCriativosAutomaticosJob::dispatch((int) $kit->id)->afterCommit();
            }
        } catch (\Throwable $e) {
            Log::warning("[Creative] Imagens automáticas: o gatilho do kit {$kit->id} falhou: {$e->getMessage()}");
        }
    }

    /**
     * Gatilho do SLOT: o juiz terminou (a validação saiu de "pendente"). Com o kit já pronto, é a vez de aplicar.
     * Mesmo cuidado do {@see aoMudarKit()}: barato e nunca lança.
     */
    public static function aoMudarSlot(MlAnuncioCriativo $slot): void
    {
        try {
            if ($slot->kit_id === null || ! $slot->wasChanged('validacao_status') || $slot->validacao_status === MlAnuncioCriativo::VALIDACAO_PENDENTE
                || ! (bool) config('publicador.criativos_auto.ativo', false)) {
                return;
            }
            $kit = MlAnuncioCriativoKit::query()->whereKey($slot->kit_id)->first(['id', 'pub_rascunho_id', 'status']);
            if ($kit !== null && $kit->pub_rascunho_id !== null
                && in_array($kit->status, [MlAnuncioCriativoKit::STATUS_PRONTO, MlAnuncioCriativoKit::STATUS_PARCIAL], true)) {
                AplicarCriativosAutomaticosJob::dispatch((int) $kit->id)->afterCommit();
            }
        } catch (\Throwable $e) {
            Log::warning("[Creative] Imagens automáticas: o gatilho do slot {$slot->id} falhou: {$e->getMessage()}");
        }
    }

    // ═══ Um grupo ════════════════════════════════════════════════════════════

    /**
     * @param  list<PubImagem>  $fotos
     * @param  bool  $capa  a capa do conjunto da fase N ({@see capaDaFase()}): tipos fixos e as imagens entram na frente
     */
    private function planejarGrupo(PubRascunho $r, PubProduto $pub, string $grupo, array $fotos, User $usuario, bool $capa = false): string
    {
        if (MlAnuncioCriativoKit::retomavelDoPublicador($r->id, $grupo) !== null || MlAnuncioCriativoKit::ultimoAprovadoDoPublicador($r->id, $grupo) !== null) {
            return 'kit_existente';
        }
        $hash = $this->hash($r, $grupo, $fotos);
        if ((($this->lerEstado($r->id)[self::MEMORIA]['grupos'][$grupo]['hash']) ?? null) === $hash) {
            return 'em_dia';
        }
        if (! $this->consumirCota((int) $pub->company_id)) {
            Log::warning("[Publicador] Imagens automáticas: limite diário da empresa {$pub->company_id} atingido; o rascunho {$r->id} ficou sem.");

            return 'limite';
        }

        // Mesmo lock e mesma ordem do `CapaDoKitService`/`MlbPublicadorCriativoController::planejarSobLock()` (D-15).
        $lock = Cache::lock('criativo-kit-planejar-pub:'.$r->id.':'.md5($grupo), 5);
        try {
            $resultado = $lock->block(3, function () use ($r, $grupo, $usuario) {
                if (MlAnuncioCriativoKit::retomavelDoPublicador($r->id, $grupo) !== null) {
                    return null;
                }
                $portador = $this->referencias->criarPortador($r, $grupo, $usuario);
                $kit = MlAnuncioCriativoKit::create([
                    'token' => Str::random(32),
                    'company_id' => $portador->company_id,
                    'mlb_empresa_id' => $portador->mlb_empresa_id,
                    'rascunho_id' => null,
                    'pub_rascunho_id' => $r->id,
                    'pub_grupo' => $grupo,
                    'user_id' => $usuario->id,
                    'criativo_referencia_id' => $portador->id,
                    'status' => MlAnuncioCriativoKit::STATUS_PLANEJANDO,
                ]);

                return ['kit' => $kit, 'portador' => $portador];
            });
        } catch (LockTimeoutException) {
            return 'ocupado';
        }
        if ($resultado === null) {
            return 'kit_existente';
        }

        ['kit' => $kit, 'portador' => $portador] = $resultado;
        try {
            $this->referencias->guardar($portador, $fotos, []);
        } catch (\Throwable $e) {
            $kit->update(['status' => MlAnuncioCriativoKit::STATUS_ERRO, 'erro_mensagem' => 'Não foi possível guardar as fotos de referência.', 'finished_at' => now()]);
            Log::error("[Creative] Imagens automáticas: as referências do kit {$kit->id} (rascunho {$r->id}) não foram guardadas: {$e->getMessage()}");

            return 'erro';
        }

        $slots = $capa ? self::TIPOS_DA_CAPA : $this->tiposDoKit($r);
        Bus::chain([
            new PlanejarKitCriativosJob($portador->id, $kit->id, $slots === [] ? null : $slots),
            new GerarCriativosAutomaticosJob($kit->id),
        ])->dispatch();

        $this->comEstado($r->id, function (array $estado) use ($grupo, $hash, $kit, $capa) {
            $estado[self::MEMORIA]['grupos'][$grupo] = ['hash' => $hash, 'kit_id' => (int) $kit->id, 'em' => now()->toIso8601String()]
                + ($capa ? ['capa' => true] : []);
            // O kit mais recente: é ele que a visão rápida do lote olha para o aviso "prontas para revisar".
            $estado[self::MEMORIA]['kit_id'] = (int) $kit->id;

            return $estado;
        });
        // GEN-05: ids e nada mais — sem prompt, sem chave, sem bytes de foto.
        Log::info("[Creative] Imagens automáticas: kit {$kit->id} planejando (rascunho {$r->id}, produto {$pub->id})", ['slots' => $slots, 'user_id' => $usuario->id]);

        return 'planejando';
    }

    // ═══ A capa da fase N ════════════════════════════════════════════════════

    /**
     * A capa do conjunto do kit da fase N que o Planejamento criou (21/10/2026). Só o kit cujo produto base é do Portal
     * (a fase criada à mão segue com o botão) e de 2 unidades para cima; rascunho não publicado; o usuário de sistema
     * com a permissão; a foto REAL do cliente de cada cor que vai ao ar. Mesmos fatos não geram de novo e o teto do dia
     * vale ({@see planejarGrupo()}).
     */
    private function capaDaFase(PubRascunho $r, PubProduto $kit): string
    {
        $base = PubProduto::find($kit->produto_base_id);
        if ($base === null || ! CombosDoPlanejamentoService::doPortal($base) || (int) $kit->quantidade_kit < 2) {
            return 'kit_da_fase';
        }
        if (in_array($r->status, PublicadorCriativoAprovacaoService::INTOCAVEIS, true)) {
            return 'intocavel';
        }
        $usuario = $this->usuario();
        if ($usuario === null) {
            return 'sem_usuario';
        }
        if (! $this->permissao->podePlanejar($usuario) || ! $this->permissao->podeGerar($usuario)) {
            return 'sem_permissao';
        }

        $resultados = [];
        foreach ($this->gruposDaCapa($r, $base) as $grupo => $fotos) {
            $resultados[] = $this->planejarGrupo($r, $kit, $grupo, $fotos, $usuario, capa: true);
        }
        if ($resultados === []) {
            return 'sem_foto';
        }

        return in_array('planejando', $resultados, true) ? 'planejando' : $resultados[0];
    }

    /**
     * Os grupos da capa e a foto REAL do cliente de cada um. O kit nasceu com cópia de TODAS as fotos do rascunho do
     * base ({@see \App\Services\Publicador\CriarFaseService::copiarImagens()}), inclusive a do cliente que a Fase 1
     * tirou da galeria ao trocar pelas imagens por IA — ela segue no rascunho, sem grupo. Por isso: a foto do cliente
     * que está no grupo; sem ela, a do cliente da MESMA cor (o valor da variação no Portal = o rótulo do grupo); na
     * galeria geral de produto sem cor, qualquer foto do cliente. Imagem por IA nunca é referência (cópia de cópia perde
     * fidelidade). Só os grupos de variante ATIVA: a cor sem o Combo N no Portal não vai ao ar.
     *
     * @return array<string, list<PubImagem>>
     */
    private function gruposDaCapa(PubRascunho $r, PubProduto $base): array
    {
        $doCliente = $this->fotosDoCliente($base);
        if ($doCliente === []) {
            return [];
        }
        $imagens = PubImagem::query()->where('rascunho_id', $r->id)->whereNotNull('caminho')->orderBy('id')->get()
            ->filter(fn (PubImagem $i) => array_key_exists((string) $i->sha256, $doCliente) && ArquivosDeImagem::existe($i->caminho))
            ->keyBy('id');
        if ($imagens->isEmpty()) {
            return [];
        }
        $atribuicoes = DB::table('pub_imagem_atribuicoes')->whereIn('imagem_id', $imagens->keys()->all())
            ->orderBy('posicao')->orderBy('imagem_id')->get(['imagem_id', 'grupo_chave']);

        $grupos = $this->referencias->gruposComRotulo($r);
        $soGeral = count($grupos) === 1;
        $todas = (bool) config('publicador.criativos_auto.todas_as_cores', true);
        $saida = [];
        foreach ($grupos as $chave => $g) {
            if (! $g['ativo'] || ($chave === ResolvedorGruposImagem::GERAL && ! $soGeral)) {
                continue;
            }
            $fotos = $atribuicoes->where('grupo_chave', $chave)->map(fn ($a) => $imagens->get($a->imagem_id))->filter()->values()->all();
            if ($fotos === []) {
                $cor = self::normalizarCor($g['rotulo']);
                $fotos = $imagens->filter(fn (PubImagem $i) => $soGeral || ($cor !== '' && $doCliente[(string) $i->sha256] === $cor))->values()->all();
            }
            $fotos = array_slice($fotos, 0, PublicadorCriativoReferenciaService::MAX);
            if ($fotos === []) {
                continue;
            }
            $saida[$chave] = $fotos;
            if (! $todas) {
                break;
            }
        }

        return $saida;
    }

    /** @return list<int> as fotos do grupo, na ordem da galeria */
    private function galeriaDoGrupo(int $rascunhoId, string $grupo): array
    {
        return DB::table('pub_imagem_atribuicoes as a')->join('pub_imagens as i', 'i.id', '=', 'a.imagem_id')
            ->where('i.rascunho_id', $rascunhoId)->where('a.grupo_chave', $grupo)
            ->orderBy('a.posicao')->orderBy('i.id')->pluck('i.id')->map(fn ($id) => (int) $id)->all();
    }

    /** "Cinza" e " cinza " são a mesma cor; "Bordô" acha "bordo". */
    private static function normalizarCor(?string $cor): string
    {
        return Str::lower(Str::ascii(trim((string) $cor)));
    }

    // ═══ Apoio ═══════════════════════════════════════════════════════════════

    /**
     * Os grupos de fotos que recebem imagens: a galeria geral e cada cor (só a geral, ou a 1ª cor, com
     * `todas_as_cores` = false), cada um com as fotos que o CLIENTE pôs no Portal ({@see shasDoCliente()}) e que
     * têm arquivo no disco, na ordem do grupo. Foto que a equipe subiu no Publicador não conta, e imagem por IA
     * (ativo do acervo com origem `ia`, ou a foto de um criativo aprovado) também não: referência é a foto real do
     * produto, e o grupo que já ficou só com as imagens do kit sai da lista — o kit seguinte nunca é IA recriando IA.
     *
     * @return array<string, list<PubImagem>>
     */
    private function grupos(PubRascunho $r, PubProduto $pub): array
    {
        $doCliente = $this->shasDoCliente($pub);
        if ($doCliente === []) {
            return [];
        }
        $ordem = $this->referencias->gruposValidos($r); // GENERAL primeiro, depois as cores na ordem do anúncio
        $atribuicoes = DB::table('pub_imagem_atribuicoes as a')
            ->join('pub_imagens as i', 'i.id', '=', 'a.imagem_id')
            ->leftJoin('acervo_imagens as acv', 'acv.id', '=', 'i.acervo_imagem_id')
            ->where('i.rascunho_id', $r->id)
            ->whereNotNull('i.caminho')
            ->where(fn ($q) => $q->whereNull('acv.origem')->orWhere('acv.origem', '!=', AcervoImagem::ORIGEM_IA))
            // A mesma regra do `acervo:registrar-existentes`: foto que veio de criativo aprovado é imagem por IA.
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('ml_anuncio_criativos as c')->whereColumn('c.pub_imagem_id', 'i.id'))
            ->orderBy('a.posicao')->orderBy('i.id')
            ->get(['a.grupo_chave', 'i.id']);
        $imagens = PubImagem::query()->where('rascunho_id', $r->id)->whereNotNull('caminho')->get()->keyBy('id');

        $porGrupo = [];
        foreach ($atribuicoes as $a) {
            $img = $imagens->get($a->id);
            if ($img !== null && isset($doCliente[(string) $img->sha256]) && ArquivosDeImagem::existe($img->caminho)) {
                $porGrupo[(string) $a->grupo_chave][] = $img;
            }
        }

        $saida = [];
        $todas = (bool) config('publicador.criativos_auto.todas_as_cores', true);
        foreach ($ordem as $grupo) {
            $fotos = array_slice($porGrupo[$grupo] ?? [], 0, PublicadorCriativoReferenciaService::MAX);
            if ($fotos === []) {
                continue;
            }
            $saida[$grupo] = $fotos;
            if (! $todas) {
                break;
            }
        }

        return $saida;
    }

    /** As fotos do grupo + a ficha do rascunho (fora o que a automação escreve) + nome e categoria. */
    private function hash(PubRascunho $r, string $grupo, array $fotos): string
    {
        $atributos = $r->atributos()->orderBy('attribute_id')->get()
            ->reject(fn ($a) => in_array($a->attribute_id, self::FORA_DO_HASH, true))
            ->map(fn ($a) => [$a->attribute_id, $a->value_id, $a->value_name, $a->value_number === null ? null : (float) $a->value_number, $a->value_unit])
            ->values()->all();

        return sha1((string) json_encode([
            'grupo' => $grupo,
            'fotos' => array_map(fn (PubImagem $i) => $i->sha256 ?? $i->id, $fotos),
            'nome' => $r->produto?->nomeExibido(),
            'categoria' => $r->categoria_id,
            'atributos' => $atributos,
        ], JSON_UNESCAPED_UNICODE));
    }

    /** @return list<int> */
    private function empresas(): array
    {
        $csv = (string) Configuracao::get(self::CHAVE_EMPRESAS, '');

        return array_values(array_map('intval', array_filter(array_map('trim', explode(',', $csv)), fn ($id) => $id !== '' && ctype_digit($id))));
    }

    /** O usuário de sistema que planeja e gera (e paga a cota) — existe e está ativo. */
    private function usuario(): ?User
    {
        $id = trim((string) Configuracao::get(self::CHAVE_USUARIO, ''));
        if ($id === '' || ! ctype_digit($id)) {
            return null;
        }
        $u = User::query()->find((int) $id);

        return $u !== null && $u->active !== false ? $u : null;
    }

    private function consumirCota(int $companyId): bool
    {
        $limite = (int) config('publicador.criativos_auto.limite_diario_por_empresa', 10);
        if ($limite <= 0) {
            return false;
        }
        $chave = "publicador:criativos-auto:cota:{$companyId}:".now()->format('Y-m-d');
        Cache::add($chave, 0, now()->addDays(2));

        return (int) Cache::increment($chave) <= $limite;
    }

    private function lerEstado(int $rascunhoId): array
    {
        return json_decode((string) DB::table('pub_rascunhos')->where('id', $rascunhoId)->value('step_state'), true) ?: [];
    }

    /** O kit automático deste grupo já passou pelo {@see aplicar()} (com N imagens, até zero): não volta. */
    private function marcarAplicado(int $rascunhoId, string $grupo, int $n): void
    {
        $this->comEstado($rascunhoId, function (array $estado) use ($grupo, $n) {
            $estado[self::MEMORIA]['grupos'][$grupo]['aplicado_em'] = now()->toIso8601String();
            $estado[self::MEMORIA]['grupos'][$grupo]['aplicadas'] = $n;

            return $estado;
        });
    }

    /** Lê-muda-grava o `step_state` sob a trava da linha, sem subir a revisão (não é edição do anúncio). */
    private function comEstado(int $rascunhoId, callable $mudar): void
    {
        DB::transaction(function () use ($rascunhoId, $mudar) {
            PubRascunho::whereKey($rascunhoId)->lockForUpdate()->value('id');
            DB::table('pub_rascunhos')->where('id', $rascunhoId)
                ->update(['step_state' => json_encode($mudar($this->lerEstado($rascunhoId)), JSON_UNESCAPED_UNICODE)]);
        });
    }

    /**
     * O sha256 das fotos que o CLIENTE pôs no Portal para este produto (20/10/2026, o usuário: "a imagem só deve ser
     * gerada automaticamente, assim como os títulos, se o cliente puser uma imagem real do produto dele no Portal").
     * A foto do Portal entra no rascunho pelo `PortalParaRascunhoService::copiarFoto`, com o sha256 do que o
     * `ConversorParaJpg` devolve (JPEG e PNG inteiros; o resto vira JPEG) — o mesmo cálculo aqui reconhece a foto do
     * cliente no rascunho, inclusive a trazida antes desta regra. Foto que a equipe subiu no Publicador e imagem por IA
     * não estão aqui. Cada arquivo é lido uma vez (cache pela foto + caminho + tamanho).
     *
     * @return array<string, true>
     */
    private function shasDoCliente(PubProduto $pub): array
    {
        return array_map(fn () => true, $this->fotosDoCliente($pub));
    }

    /**
     * As fotos do cliente no Portal ({@see shasDoCliente()}), cada uma com a cor da variação dela, normalizada (null
     * sem valor) — a capa da fase N acha por ela a foto da cor ({@see gruposDaCapa()}).
     *
     * @return array<string, ?string> sha256 => cor
     */
    private function fotosDoCliente(PubProduto $pub): array
    {
        $ids = app(PreparoIaDoRascunhoService::class)->produtosDoPortal($pub);
        if ($ids === []) {
            return [];
        }

        $shas = [];
        $fotos = EstruturaProdutoVariacaoImagem::query()->where('company_id', $pub->company_id)->whereIn('produto_id', $ids)
            ->get(['id', 'caminho', 'tamanho', 'variacao_id']);
        $cores = EstruturaProdutoVariacao::query()->whereIn('id', $fotos->pluck('variacao_id')->filter()->unique()->all())->pluck('valor', 'id');
        foreach ($fotos as $foto) {
            $chave = 'publicador:criativos-auto:sha-do-portal:'.$foto->id.':'.md5($foto->caminho.'|'.$foto->tamanho);
            $sha = Cache::get($chave);
            if (! is_string($sha)) {
                $conteudo = app(PortalProdutoLeitor::class)->lerImagem((int) $pub->company_id, (string) $foto->caminho);
                $convertida = $conteudo === null ? null : ConversorParaJpg::converter($conteudo)['conteudo'];
                if ($convertida === null) {
                    continue; // arquivo sumido ou formato que nem entra no rascunho: não é referência
                }
                $sha = hash('sha256', $convertida);
                Cache::put($chave, $sha, now()->addDays(30));
            }
            $cor = self::normalizarCor($cores[$foto->variacao_id] ?? null);
            $shas[$sha] ??= $cor !== '' ? $cor : null;
        }

        return $shas;
    }

    /**
     * Os tipos do kit automático, na ordem da galeria (20/10/2026, decisão do usuário: "3 imagens, 2 capas"). A rotação
     * D6/CAPA-01 manda a 1ª foto para o fim no Premium, então o 1º tipo é a capa do Clássico e o 2º a do Premium — os
     * dois precisam servir de capa, e a Textura (detalhe) vai por último. Em MÓVEL a capa é a Ambientação (Quick
     * 261007-amb, pedido do usuário de 07/10): com tipos fixos o planejador não faz essa troca sozinho, por isso a
     * ordem já sai pronta daqui. Lista vazia = o kit do botão (o planejador escolhe tudo).
     *
     * @return list<string>
     */
    private function tiposDoKit(PubRascunho $r): array
    {
        $slots = array_values(array_filter(array_map('strval', (array) config('publicador.criativos_auto.slots', []))));
        if (in_array('lifestyle', $slots, true) && $slots[0] !== 'lifestyle'
            && app(CreativeCategoriaMobiliarioService::class)->ehMoveis($r->categoria_id)) {
            $slots = ['lifestyle', ...array_values(array_diff($slots, ['lifestyle']))];
        }

        return $slots;
    }
}
