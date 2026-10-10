<?php

namespace App\Services\Publicador\Criativos;

use App\Jobs\PlanejarKitCriativosJob;
use App\Jobs\Publicador\GerarCriativosAutomaticosJob;
use App\Models\Configuracao;
use App\Models\MlAnuncioCriativo;
use App\Models\MlAnuncioCriativoKit;
use App\Models\PubImagem;
use App\Models\PubProduto;
use App\Models\PubRascunho;
use App\Models\User;
use App\Services\Creative\CreativeEngineAtivo;
use App\Services\Creative\CreativeKitDespachante;
use App\Services\Creative\CreativePermissao;
use App\Services\Publicador\PreparoIaDoRascunhoService;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
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
 *  5. o grupo de fotos (galeria geral, senão a 1ª cor; todas as cores só com `todas_as_cores`) tem ≥ 1 foto com
 *     arquivo no disco;
 *  6. nenhum kit em andamento ou aprovado para o rascunho + grupo (D-15: um kit ativo por grupo);
 *  7. o hash das fotos + ficha é diferente do último pedido (`step_state.criativos_auto`): o mesmo produto não
 *     gera de novo a cada save;
 *  8. cabe no teto diário da empresa (`limite_diario_por_empresa`).
 * Passando, planeja e gera na MESMA passada (as referências efêmeras somem em 48 h — `creative:limpar-referencias` —,
 * então planejar e esperar alguém clicar "Gerar" desperdiçaria o plano): `PlanejarKitCriativosJob` (o do Creative
 * Engine, intocado) e, encadeado, `GerarCriativosAutomaticosJob`, que despacha a geração pelo mesmo
 * `CreativeKitDespachante` do botão. A APROVAÇÃO continua de gente: o kit aparece no editor como qualquer kit do
 * Publicador, e a visão rápida do lote avisa "Imagens de IA prontas para revisar".
 *
 * Nunca lança para quem chama (o preparo): devolve o motivo, que vai para o log.
 */
final class CriativosAutomaticosService
{
    public const CHAVE_EMPRESAS = 'publicador_criativos_auto_companies';

    public const CHAVE_USUARIO = 'publicador_criativos_auto_usuario';

    /** Onde a memória do último pedido mora no rascunho. */
    public const MEMORIA = 'criativos_auto';

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
     *                kit_existente, em_dia, limite, ocupado, erro, planejando (um ou mais grupos)
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
                return 'kit_da_fase';
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
            foreach ($this->grupos($r) as $grupo => $fotos) {
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

    // ═══ Um grupo ════════════════════════════════════════════════════════════

    /** @param list<PubImagem> $fotos */
    private function planejarGrupo(PubRascunho $r, PubProduto $pub, string $grupo, array $fotos, User $usuario): string
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

        $slots = array_values(array_filter(array_map('strval', (array) config('publicador.criativos_auto.slots', ['lifestyle', 'hero']))));
        Bus::chain([
            new PlanejarKitCriativosJob($portador->id, $kit->id, $slots === [] ? null : $slots),
            new GerarCriativosAutomaticosJob($kit->id),
        ])->dispatch();

        $this->comEstado($r->id, function (array $estado) use ($grupo, $hash, $kit) {
            $estado[self::MEMORIA]['grupos'][$grupo] = ['hash' => $hash, 'kit_id' => (int) $kit->id, 'em' => now()->toIso8601String()];
            // O kit mais recente: é ele que a visão rápida do lote olha para o aviso "prontas para revisar".
            $estado[self::MEMORIA]['kit_id'] = (int) $kit->id;

            return $estado;
        });
        // GEN-05: ids e nada mais — sem prompt, sem chave, sem bytes de foto.
        Log::info("[Creative] Imagens automáticas: kit {$kit->id} planejando (rascunho {$r->id}, produto {$pub->id})", ['slots' => $slots, 'user_id' => $usuario->id]);

        return 'planejando';
    }

    // ═══ Apoio ═══════════════════════════════════════════════════════════════

    /**
     * Os grupos de fotos que recebem imagens: a galeria geral, senão a 1ª cor (todas as cores com `todas_as_cores`),
     * cada um com as fotos dele que têm arquivo no disco, na ordem do grupo.
     *
     * @return array<string, list<PubImagem>>
     */
    private function grupos(PubRascunho $r): array
    {
        $ordem = $this->referencias->gruposValidos($r); // GENERAL primeiro, depois as cores na ordem do anúncio
        $atribuicoes = DB::table('pub_imagem_atribuicoes as a')
            ->join('pub_imagens as i', 'i.id', '=', 'a.imagem_id')
            ->where('i.rascunho_id', $r->id)
            ->whereNotNull('i.caminho')
            ->orderBy('a.posicao')->orderBy('i.id')
            ->get(['a.grupo_chave', 'i.id']);
        $imagens = PubImagem::query()->where('rascunho_id', $r->id)->whereNotNull('caminho')->get()->keyBy('id');

        $porGrupo = [];
        foreach ($atribuicoes as $a) {
            $img = $imagens->get($a->id);
            if ($img !== null && Storage::disk('local')->exists($img->caminho)) {
                $porGrupo[(string) $a->grupo_chave][] = $img;
            }
        }

        $saida = [];
        $todas = (bool) config('publicador.criativos_auto.todas_as_cores', false);
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

    /** Lê-muda-grava o `step_state` sob a trava da linha, sem subir a revisão (não é edição do anúncio). */
    private function comEstado(int $rascunhoId, callable $mudar): void
    {
        DB::transaction(function () use ($rascunhoId, $mudar) {
            PubRascunho::whereKey($rascunhoId)->lockForUpdate()->value('id');
            DB::table('pub_rascunhos')->where('id', $rascunhoId)
                ->update(['step_state' => json_encode($mudar($this->lerEstado($rascunhoId)), JSON_UNESCAPED_UNICODE)]);
        });
    }
}
