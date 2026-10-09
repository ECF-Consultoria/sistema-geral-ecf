<?php

namespace App\Services\Publicador\Criativos;

use App\Models\MlAnuncioCriativo;
use App\Models\MlAnuncioCriativoKit;
use App\Models\PubImagemAtribuicao;
use App\Models\PubRascunho;
use App\Models\User;
use App\Services\Creative\ReferenciaEfemeraService;
use App\Services\Publicador\EditorRascunhoService;
use App\Services\Publicador\ImagemAssetService;
use App\Support\Publicador\Validacao\Problema;
use App\Support\Publicador\Variacao\ChaveCanonica;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Fase 165 — a aprovação de um criativo (ou do kit inteiro) gerado pelo
 * Creative Engine DENTRO do Publicador novo.
 *
 * **D-04.** A imagem aprovada entra no rascunho do Publicador pelo caminho de
 * foto que JÁ EXISTE (`ImagemAssetService::receber`, a mesma conferência de
 * tamanho/formato, disco privado e dedupe por sha256 de qualquer foto do
 * Publicador) e é atribuída ao grupo de onde o kit foi pedido
 * (`EditorRascunhoService::colocarFotoNoGrupo`, sob a trava — T-165-09). Em
 * conta liberada (D26) o arquivo sobe ao Mercado Livre pelo caminho de
 * SEMPRE do `ImagemAssetService`; a foto só entra de fato num anúncio na
 * publicação/conferência do Publicador. Esta classe NUNCA grava a lista de
 * fotos usada pelo assistente antigo, nem chama o serviço de envio direto ao
 * ML ou o publicador de kit dele (ambos exclusivos do caminho de
 * `MlbAnuncioController` — `criativoAprovar()`/`criativoKitAprovar()`), que
 * esta classe não toca.
 *
 * **D-11.** Aqui "aprovado" = a imagem entrou em `pub_imagens` (o id dela
 * preenchido no slot). Os dois campos que o assistente antigo usa para
 * registrar o upload direto ao ML nunca são tocados por esta classe — quem
 * decide se/quando a foto sobe ao ML é o `ImagemAssetService` (D26) e,
 * depois, a publicação do Publicador.
 *
 * **D-12.** Um slot `aprovado` cuja `PubImagem` foi removida do rascunho, ou
 * cuja atribuição saiu do grupo, pode ser posto de novo: `noAnuncio()`
 * passa a devolver `false` e o fluxo de `aprovarSlot()` repete os passos de
 * sempre — `receber()` deduplica pelo sha256 (nenhuma chamada nova ao
 * provedor de geração de imagem, que já rodou e gravou o arquivo em disco).
 *
 * **D-04 (não truncar).** Esta classe nunca corta a lista de fotos de um
 * grupo por limite — quem bloqueia o excesso é a validação do Publicador
 * (V-IMG-06/07) na hora de publicar.
 */
class PublicadorCriativoAprovacaoService
{
    /**
     * Estados em que o rascunho do Publicador não aceita mais mudança de
     * fotos por aqui — mesma lista de `IaParaRascunhoService::INTOCAVEIS`
     * (copiada, não importada: aquele arquivo não é tocado nesta fase).
     */
    public const INTOCAVEIS = [PubRascunho::PUBLISHING, PubRascunho::PUBLISHED, PubRascunho::PARTIALLY_PUBLISHED];

    public function __construct(
        private ImagemAssetService $imagens,
        private EditorRascunhoService $editor,
        private ReferenciaEfemeraService $efemera,
    ) {}

    /**
     * Aprova UM slot — o `$grupo` vem SEMPRE do kit (`pub_grupo`), passado
     * pelo controller (165-04), nunca da requisição (T-165-10).
     *
     * @return array{ok: bool, mensagem: ?string, imagem_id: ?int, repetida: bool}
     */
    public function aprovarSlot(PubRascunho $r, MlAnuncioCriativo $slot, string $grupo, User $u): array
    {
        if (in_array($r->status, self::INTOCAVEIS, true)) {
            return $this->recusa('Este anúncio já está publicado (ou sendo publicado) — as fotos não mudam mais por aqui.');
        }

        if ($slot->status === MlAnuncioCriativo::STATUS_APROVADO && $this->noAnuncio($slot, $grupo)) {
            // Já está lá — nem reenvia, nem toca na revisão do rascunho.
            return ['ok' => true, 'mensagem' => null, 'imagem_id' => $slot->pub_imagem_id, 'repetida' => true];
        }

        if (! in_array($slot->status, [MlAnuncioCriativo::STATUS_PRONTO, MlAnuncioCriativo::STATUS_APROVADO], true)) {
            return $this->recusa('Esta imagem ainda não está pronta para ser usada.');
        }

        $disco = Storage::disk('local');
        if ($slot->imagem_path === null || ! $disco->exists($slot->imagem_path)) {
            return $this->recusa('A imagem gerada deste criativo não foi encontrada.');
        }

        // FORA de qualquer transação: pode fazer HTTP ao Mercado Livre (D26).
        $res = $this->imagens->receber($r, $disco->get($slot->imagem_path), "criativo-{$slot->token}.jpg");
        if ($res['imagem'] === null) {
            $bloqueio = collect($res['problemas'])->first(fn (Problema $p) => $p->bloqueia());
            $mensagem = $bloqueio !== null ? EditorRascunhoService::problemaParaTela($bloqueio)['mensagem'] : 'A imagem gerada não pôde ser usada como foto.';

            return $this->recusa($mensagem);
        }

        // Capa de combo (Fase 2): vai para a POSIÇÃO 0, não para o fim do grupo.
        // O rascunho do kit nasce com as fotos clonadas do produto base, então no
        // fim da fila a capa gerada — que mostra as N unidades — nunca viraria a
        // capa no Mercado Livre, e o anúncio de N unidades abriria com a foto de
        // uma. O gatilho é `unidades_da_composicao`, que o `PlanejarKitCriativosJob`
        // grava no `slot_plano` SÓ na capa de kit: dado estruturado, nunca a frase
        // da `cena` (texto de prompt não é regra de negócio). Ausente ou < 2 — todo
        // criativo da Fase 1 — segue no fim do grupo, como sempre.
        $unidades = (int) (((array) $slot->slot_plano)['unidades_da_composicao'] ?? 0);
        $this->editor->colocarFotoNoGrupo($r, $res['imagem'], $grupo, naFrente: $unidades >= 2);

        $slot->update([
            'status' => MlAnuncioCriativo::STATUS_APROVADO,
            'aprovado_por' => $u->id,
            'aprovado_em' => now(),
            'pub_imagem_id' => $res['imagem']->id,
        ]);

        // GEN-05: nunca bytes, nunca prompt — só os ids para rastrear quem aprovou o quê.
        Log::info("[Creative] Publicador: slot {$slot->id} do kit {$slot->kit_id} entrou nas fotos do rascunho {$r->id}");

        // Não recalcula o status do kit a partir dos slots aqui — com um slot aprovado ele viraria "gerando" (achado do 165-01).
        return ['ok' => true, 'mensagem' => null, 'imagem_id' => $res['imagem']->id, 'repetida' => false];
    }

    /**
     * Aprova o KIT INTEIRO: cada slot `pronto`, na ordem de `slot_indice`,
     * pelo MESMO `aprovarSlot()` — cada um com a sua transação (o ML não
     * fica preso atrás da trava de um upload lento). Só fecha o kit
     * (`status = aprovado`) quando NENHUM falhou e pelo menos uma imagem
     * ficou aprovada; falha parcial nunca muda o status do kit.
     *
     * Quick 261007-kit2 (decisão de reunião, 2026-10-07): `minimo_aprovadas`
     * DEIXOU DE SER CONDIÇÃO aqui — as imagens por IA são complemento às
     * fotos reais, nunca trava ("serão duas imagens geradas por IA e o
     * restante serão imagens reais", palavras do usuário). Isto também
     * corrige um bug garantido: com kit de 2 slots e `minimo_aprovadas`
     * congelado em 3 (valor antigo), o mínimo NUNCA seria atingido e
     * travaria toda aprovação de kit — vale também para kits antigos com o
     * valor congelado, que nunca é lido aqui para bloquear.
     *
     * @return array{ok: bool, mensagem: string, aprovadas: int, falharam: list<int>, kit_aprovado: bool}
     */
    public function aprovarKit(PubRascunho $r, MlAnuncioCriativoKit $kit, User $u): array
    {
        if ($kit->status === MlAnuncioCriativoKit::STATUS_APROVADO) {
            return ['ok' => false, 'mensagem' => 'Este kit já foi aprovado.', 'aprovadas' => 0, 'falharam' => [], 'kit_aprovado' => false];
        }

        if (in_array($r->status, self::INTOCAVEIS, true)) {
            return ['ok' => false, 'mensagem' => 'Este anúncio já está publicado (ou sendo publicado) — as fotos não mudam mais por aqui.', 'aprovadas' => 0, 'falharam' => [], 'kit_aprovado' => false];
        }

        $disponiveis = $kit->prontas() + $kit->aprovadas();
        if ($disponiveis < 1) {
            return ['ok' => false, 'mensagem' => 'Não há nenhuma imagem pronta para aprovar.', 'aprovadas' => 0, 'falharam' => [], 'kit_aprovado' => false];
        }

        $aprovadas = 0;
        $falharam = [];
        foreach ($kit->slots()->where('status', MlAnuncioCriativo::STATUS_PRONTO)->get() as $slot) {
            $resultado = $this->aprovarSlot($r, $slot, $kit->pub_grupo, $u);
            if ($resultado['ok']) {
                $aprovadas++;
            } else {
                $falharam[] = $slot->slot_indice;
            }
        }

        $kitAprovado = false;
        if ($falharam === [] && $kit->aprovadas() >= 1) {
            $kit->update(['status' => MlAnuncioCriativoKit::STATUS_APROVADO, 'aprovado_por' => $u->id, 'aprovado_em' => now()]);
            $kitAprovado = true;

            // Falhar ao apagar a referência efêmera NUNCA desfaz a aprovação já confirmada — a
            // varredura diária (`creative:limpar-referencias`, FOTO-03/CE165-09) recolhe o resto.
            try {
                $this->efemera->apagar($kit->criativoReferencia);
            } catch (\Throwable $e) {
                Log::warning("[Creative] Publicador: falha ao apagar a referência efêmera do kit {$kit->id}: {$e->getMessage()}");
            }
        }

        $mensagem = $falharam === []
            ? "{$aprovadas} imagem(ns) entrou(aram) nas fotos do anúncio."
            : "{$aprovadas} imagem(ns) entrou(aram); não deu certo com o(s) slot(s) ".implode(', ', $falharam).'.';

        return ['ok' => true, 'mensagem' => $mensagem, 'aprovadas' => $aprovadas, 'falharam' => $falharam, 'kit_aprovado' => $kitAprovado];
    }

    /** O slot já está no grupo pedido (`pub_imagem_id` atribuído ali) — D-11/D-12. */
    public function noAnuncio(MlAnuncioCriativo $slot, string $grupo): bool
    {
        return $slot->pub_imagem_id !== null
            && PubImagemAtribuicao::where('imagem_id', $slot->pub_imagem_id)->where('grupo_hash', ChaveCanonica::hash($grupo))->exists();
    }

    /**
     * `noAnuncio()` para VÁRIOS slots de uma vez, numa única consulta — o
     * presenter do 165-04 não bate uma query no banco por slot a cada
     * polling.
     *
     * @param  Collection<int, MlAnuncioCriativo>  $slots
     * @return array<int, bool> id do slot → está no grupo
     */
    public function noAnuncioEmLote(Collection $slots, string $grupo): array
    {
        $ids = $slots->filter(fn (MlAnuncioCriativo $s) => $s->pub_imagem_id !== null)->pluck('pub_imagem_id', 'id');
        if ($ids->isEmpty()) {
            return $slots->mapWithKeys(fn (MlAnuncioCriativo $s) => [$s->id => false])->all();
        }

        $noGrupo = PubImagemAtribuicao::whereIn('imagem_id', $ids->values()->unique())
            ->where('grupo_hash', ChaveCanonica::hash($grupo))
            ->pluck('imagem_id')
            ->flip();

        return $ids->mapWithKeys(fn ($imagemId, $slotId) => [$slotId => $noGrupo->has($imagemId)])->all();
    }

    /** @return array{ok: bool, mensagem: ?string, imagem_id: ?int, repetida: bool} */
    private function recusa(string $mensagem): array
    {
        return ['ok' => false, 'mensagem' => $mensagem, 'imagem_id' => null, 'repetida' => false];
    }
}
