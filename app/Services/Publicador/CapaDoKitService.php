<?php

namespace App\Services\Publicador;

use App\Jobs\PlanejarKitCriativosJob;
use App\Models\MlAnuncioCriativoKit;
use App\Models\PubImagem;
use App\Models\PubProduto;
use App\Models\PubRascunho;
use App\Models\User;
use App\Services\Creative\CreativeEngineAtivo;
use App\Services\Creative\CreativePermissao;
use App\Services\Publicador\Criativos\PublicadorCriativoAprovacaoService;
use App\Services\Publicador\Criativos\PublicadorCriativoReferenciaService;
use App\Support\Publicador\Imagem\ResolvedorGruposImagem;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * A CAPA do kit (§5 da ETAPA-3, Fase 175): pede ao Creative Engine duas
 * imagens do kit a partir da foto 1 do próprio kit, mostrando as N unidades
 * idênticas lado a lado.
 *
 * ═══ Duas imagens, não uma (decisão do usuário em 2026-10-08) ═══════════════
 *
 * `175-DECISOES.md` item 1: *"gerar as duas e você escolhe"*. O kit da capa tem
 * DOIS slots — `lifestyle` (ambientada) e `hero` (fundo limpo) — e o operador
 * aprova UM dos dois como foto 1 do anúncio do kit. Custo aceito: ~R$ 1,10 por
 * capa, não ~R$ 0,55. Os dois valem em QUALQUER categoria, não só em móveis: a
 * escolha não faria sentido em metade dos casos, e o custo é o mesmo nos dois
 * ramos.
 *
 * ⚠️ Por isso os tipos são FIXADOS (`SLOTS_DA_CAPA`), e não deixados ao
 * planner: com dois slots livres e medida no cadastro, ele escolheria `hero` +
 * `dimensions` — imagem de medidas, que não é capa de kit.
 *
 * ═══ Por que `planejar` do núcleo não precisou mudar ════════════════════════
 *
 * `PublicadorCriativoReferenciaService::selecionarFotos()` só aceita foto do
 * PRÓPRIO rascunho. Como o clone do 175-02 já copiou a foto 1 do base para o
 * rascunho do kit — com bytes próprios —, a referência aqui é a **cópia do
 * kit**, e nada no núcleo precisou de exceção. Passar o id da imagem do base
 * seria recusado com "Uma das fotos escolhidas não é deste anúncio", e é bom
 * que seja.
 *
 * ═══ O que este serviço NUNCA faz ═══════════════════════════════════════════
 *
 * - **Não lança.** Devolve `['ok' => false, 'motivo' => '…']`. Ele roda DEPOIS
 *   da transação que criou a fase: um 403/500 aqui transformaria uma fase
 *   criada com sucesso em erro na tela, e a fase não volta atrás.
 * - **Não edita `CreativePermissao` nem `CreativeEngineAtivo`** — usa os dois
 *   como estão. Sem a chave do Creative Engine ou sem a permissão de gastar
 *   cota, nada é disparado e o motivo volta para a resposta.
 * - **Não aprova nada.** A imagem só vira foto 1 do kit DEPOIS de aprovada,
 *   pelo caminho normal (`PublicadorCriativoAprovacaoService`); as fotos 2+
 *   seguem herdadas do base.
 * - **Não devolve token** (D-13): só o `kit_id` numérico.
 */
class CapaDoKitService
{
    /**
     * Os dois slots da capa, nesta ordem: ambientada e fundo limpo.
     *
     * ⚠️ D5 continua valendo e é garantido pelo `CreativePromptBuilder`, não
     * aqui: o bloco AMBIENTE (ambiente brasileiro subentendido) entra só em
     * `lifestyle`, NUNCA em `hero` nem em `white_background`, que são regidos
     * pela moderação de capa do Mercado Livre.
     */
    public const SLOTS_DA_CAPA = ['lifestyle', 'hero'];

    public function __construct(
        private CreativeEngineAtivo $chave,
        private CreativePermissao $permissao,
        private PublicadorCriativoReferenciaService $referencias,
    ) {}

    /**
     * Pede a capa do kit. Nunca lança: o motivo da recusa volta no retorno.
     *
     * @return array{ok: bool, motivo: ?string, kit_id: ?int}
     */
    public function planejar(PubProduto $kit, User $user): array
    {
        if (! $this->chave->ativa()) {
            return self::recusa('O gerador de imagens por IA está desligado. A fase foi criada; a capa pode ser gerada depois.');
        }

        // `podePlanejar()` em vez de `exigir()`: a MESMA regra (OPS-04), sem o
        // `abort(403)` — ver "O que este serviço NUNCA faz" no docblock.
        if (! $this->permissao->podePlanejar($user)) {
            return self::recusa('Você não tem permissão para gerar imagens por IA. A fase foi criada sem a capa.');
        }

        $r = $kit->rascunho;
        if ($r === null) {
            return self::recusa('O anúncio deste kit ainda não existe — não há foto de onde partir.');
        }

        if (in_array($r->status, PublicadorCriativoAprovacaoService::INTOCAVEIS, true)) {
            return self::recusa('Este anúncio já está publicado (ou sendo publicado) — as fotos não mudam mais por aqui.');
        }

        $grupo = ResolvedorGruposImagem::GERAL;

        $foto = $this->fotoDaCapa($r);
        if ($foto === null) {
            return self::recusa('Este kit não tem foto na galeria principal — envie uma foto antes de gerar a capa.');
        }

        try {
            $fotos = $this->referencias->selecionarFotos($r, [$foto->id]);
        } catch (\Throwable $e) {
            Log::warning("[Creative] Capa do kit {$kit->id}: foto {$foto->id} recusada como referência: {$e->getMessage()}");

            return self::recusa('Não foi possível usar a foto principal deste kit como referência.');
        }

        if ($fotos === []) {
            return self::recusa('O arquivo da foto principal deste kit não está disponível no servidor.');
        }

        // Mesmo lock e mesma ordem do `MlbPublicadorCriativoController::planejarSobLock()`:
        // no máximo um kit ativo por (rascunho, grupo) — D-15.
        $lock = Cache::lock('criativo-kit-planejar-pub:'.$r->id.':'.md5($grupo), 5);

        try {
            $resultado = $lock->block(3, fn () => $this->sobLock($r, $grupo, $user));
        } catch (LockTimeoutException) {
            $existente = MlAnuncioCriativoKit::retomavelDoPublicador($r->id, $grupo);

            return $existente !== null
                ? ['ok' => true, 'motivo' => null, 'kit_id' => $existente->id]
                : self::recusa('Outra pessoa está planejando as imagens deste anúncio agora. Tente de novo em alguns segundos.');
        }

        $kitCriativo = $resultado['kit'];

        if ($resultado['criado'] !== true) {
            // Já havia um kit retomável deste (rascunho, grupo): não gasta cota de novo.
            return ['ok' => true, 'motivo' => null, 'kit_id' => $kitCriativo->id];
        }

        try {
            $this->referencias->guardar($resultado['portador'], $fotos, []);
        } catch (\Throwable $e) {
            $kitCriativo->update([
                'status' => MlAnuncioCriativoKit::STATUS_ERRO,
                'erro_mensagem' => 'Não foi possível guardar a foto de referência da capa.',
                'finished_at' => now(),
            ]);
            Log::error("[Creative] Capa do kit {$kit->id}: falha ao guardar a referência do kit {$kitCriativo->id}: {$e->getMessage()}");

            return self::recusa('Não foi possível guardar a foto de referência da capa.');
        }

        PlanejarKitCriativosJob::dispatch($resultado['portador']->id, $kitCriativo->id, self::SLOTS_DA_CAPA);

        // GEN-05: ids e nada mais — sem prompt, sem chave, sem bytes de foto.
        Log::info("[Creative] Capa do kit planejamento enfileirado (produto {$kit->id}, rascunho {$r->id}, kit {$kitCriativo->id})", [
            'unidades' => (int) $kit->quantidade_kit,
            'slots' => self::SLOTS_DA_CAPA,
            'user_id' => $user->id,
        ]);

        return ['ok' => true, 'motivo' => null, 'kit_id' => $kitCriativo->id];
    }

    // ═══ Apoio ═══════════════════════════════════════════════════════════════

    /**
     * Os passos sob o lock — molde literal do `planejarSobLock()` do
     * `MlbPublicadorCriativoController`, na mesma ordem: procura o retomável
     * ANTES de criar portador e kit.
     *
     * @return array{kit: MlAnuncioCriativoKit, portador?: \App\Models\MlAnuncioCriativo, criado: bool}
     */
    private function sobLock(PubRascunho $r, string $grupo, User $user): array
    {
        $existente = MlAnuncioCriativoKit::retomavelDoPublicador($r->id, $grupo);
        if ($existente !== null) {
            return ['kit' => $existente, 'criado' => false];
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
     * A foto de posição 1 do grupo GENERAL do rascunho DO KIT — a CÓPIA que o
     * clone do 175-02 fez, nunca a foto do base (`selecionarFotos()` recusaria,
     * e com razão).
     *
     * Mesma consulta (e mesma ordem de desempate) do
     * `FamiliaDeFasesService::fotoDaCapa()`.
     */
    private function fotoDaCapa(PubRascunho $r): ?PubImagem
    {
        return PubImagem::query()
            ->join('pub_imagem_atribuicoes', 'pub_imagem_atribuicoes.imagem_id', '=', 'pub_imagens.id')
            ->where('pub_imagens.rascunho_id', $r->id)
            ->where('pub_imagem_atribuicoes.grupo_hash', hash('sha256', ResolvedorGruposImagem::GERAL))
            ->orderBy('pub_imagem_atribuicoes.posicao')
            ->orderBy('pub_imagens.id')
            ->first(['pub_imagens.*']);
    }

    /** @return array{ok: false, motivo: string, kit_id: null} */
    private static function recusa(string $motivo): array
    {
        return ['ok' => false, 'motivo' => $motivo, 'kit_id' => null];
    }
}
