<?php

namespace App\Services\Trilha\Ondas;

use App\Models\Company;
use App\Models\PubProduto;
use App\Models\PubRascunho;
use App\Models\TrilhaOnda;
use App\Models\TrilhaOndaItem;
use App\Services\Trilha\TrilhaDaEmpresa;
use Illuminate\Support\Facades\Log;

/**
 * A trava do robô na data da onda (Trilha de produtos, 2ª entrega, 02/11/2026) — num lugar só.
 *
 * Na empresa com a trilha de trabalho ligada (`App\Support\Trilha\TrilhaDeTrabalho`), a oferta que ainda não andou
 * só é preparada pela IA quando chega o dia da onda dela. Quem pergunta:
 *
 * - `PreparoIaDoRascunhoService::avaliarIa()` — o ÚNICO lugar onde a IA de texto nasce (salvar no Portal, oferta
 *   criada, importador de fichas, a própria soltura da onda e o agente da Aprovação chegam todos lá). O teste vem
 *   antes da cota, da memória e de qualquer Job: o item segurado não gasta nada e nada é gravado. As imagens
 *   automáticas só saem depois de `avaliarIa` passar.
 * - `CombosDoPlanejamentoService::criarOsQueFaltam()` — o Kit N do combo ({@see seguraOCombo()}).
 * - A política do agente da Aprovação ({@see recusaParaOAgente()}).
 *
 * "Andou" é a régua da trilha (`SituacaoDasOfertas::andou`), lida item a item por {@see TrilhaDaEmpresa::itens()}:
 * a trava e a tela nunca discordam sobre quem já saiu do planejamento. O que andou antes de a chave ligar não é
 * segurado.
 *
 * Erro ao ler segura (e registra): é o lado barato — o item fica "na fila" e a passada seguinte tenta de novo; o
 * outro lado seria IA paga fora da data.
 */
class PortaoDaOnda
{
    /** O que `avaliarIa` devolve para o item segurado. */
    public const AGUARDANDO = 'aguardando_onda';

    /**
     * Os itens da trilha já lidos, por empresa, durante a vida DESTA instância. O container entrega uma instância nova
     * a cada `app(PortaoDaOnda::class)`: a memória vale só para quem reusa a mesma (o laço dos Kits N), nunca entre
     * Jobs do worker.
     *
     * @var array<int, array<string, array>>
     */
    private array $itens = [];

    /**
     * O preparo deste pub_produto espera a onda? Só é chamado com a trilha de trabalho ligada para a empresa dele.
     * O kit da fase N não passa por aqui (`avaliarIa` devolve `kit_da_fase` antes): a criação dele é que espera
     * ({@see seguraOCombo()}).
     */
    public function segura(PubProduto $pub, PubRascunho $r): bool
    {
        try {
            if ($pub->produto_base_id !== null || $pub->company_id === null) {
                return false;
            }
            $item = ItemDaTrilha::doPub($pub);
            if ($item === null || $item['sem_produto']) {
                return false;
            }

            return $this->seguraOItem((int) $pub->company_id, $item['chave']);
        } catch (\Throwable $e) {
            Log::warning("[Trilha] Trava da onda: não deu para ler o produto {$pub->id} do Publicador (empresa {$pub->company_id}); o preparo espera. ".$e->getMessage());

            return true;
        }
    }

    /**
     * O Kit N do Combo N do produto espera a onda? (`c-{produto}-{N}`.) O que já andou não espera — ninguém
     * conseguiria pô-lo numa onda.
     */
    public function seguraOCombo(int $estruturaProdutoId, int $n, int $empresaId): bool
    {
        try {
            return $this->seguraOItem($empresaId, "c-{$estruturaProdutoId}-{$n}");
        } catch (\Throwable $e) {
            Log::warning("[Trilha] Trava da onda: não deu para ler o Combo {$n} do produto {$estruturaProdutoId} (empresa {$empresaId}); o kit espera. ".$e->getMessage());

            return true;
        }
    }

    /**
     * A mensagem com que o agente da Aprovação recusa admitir o produto, ou null quando a onda deixa passar. Sem
     * rascunho não há o que segurar (`avaliarIa` devolveria `sem_rascunho`). Erro ao ler a onda recusa com um texto
     * próprio (a política roda dentro da admissão: uma exceção aqui viraria 500 no "Preparar agora").
     */
    public function recusaParaOAgente(PubProduto $pub): ?string
    {
        try {
            $r = PubRascunho::query()->where('produto_id', $pub->id)->first();
            if ($r === null || ! $this->segura($pub, $r)) {
                return null;
            }

            $item = ItemDaTrilha::doPub($pub);
            $onda = $item !== null ? $this->ondaDoItem((int) $pub->company_id, $item['chave']) : null;
            if ($onda === null) {
                return 'Esta oferta ainda não está numa onda da trilha.';
            }
            // Segurada com a onda já começada só acontece quando a leitura falhou (a trava segura no erro): mandar
            // "mudar a data" seria impossível — a data de uma onda que começou não muda mais.
            if ($onda->comecou()) {
                return 'A trilha não conseguiu conferir a onda desta oferta agora. Tente de novo em instantes.';
            }

            return 'Esta oferta espera a '.$onda->nome.', que começa em '.self::diaCurto($onda->dia()).'. Para adiantar, mude a data da onda na trilha.';
        } catch (\Throwable $e) {
            Log::warning("[Trilha] Trava da onda: não deu para conferir a onda do produto {$pub->id} do Publicador para o agente; ele espera. ".$e->getMessage());

            return 'A trilha não conseguiu conferir a onda desta oferta agora. Tente de novo em instantes.';
        }
    }

    private function seguraOItem(int $empresaId, string $chave): bool
    {
        $empresa = Company::query()->find($empresaId);
        if ($empresa === null) {
            return false;
        }

        // Lido uma vez por instância: o Kit N pergunta por N no mesmo laço (`CombosDoPlanejamentoService`).
        $this->itens[$empresaId] ??= TrilhaDaEmpresa::itens($empresa);
        $item = $this->itens[$empresaId][$chave] ?? null;
        if ($item === null) {
            // A chave saiu do pub_produto, mas o item não existe agora na trilha (a oferta acabou de mudar): não é
            // item da trilha, não há onda a esperar.
            Log::info("[Trilha] Trava da onda: o item {$chave} não está na trilha da empresa {$empresaId}; o preparo segue.");

            return false;
        }
        if ($item['andou'] || $item['sem_produto']) {
            return false;
        }

        $onda = $this->ondaDoItem($empresaId, $chave);

        return $onda === null || ! $onda->comecou();
    }

    private function ondaDoItem(int $empresaId, string $chave): ?TrilhaOnda
    {
        $colunas = TrilhaOndaItem::colunasDaChave($chave);
        if ($colunas === null) {
            return null;
        }

        $linha = TrilhaOndaItem::query()
            ->where('company_id', $empresaId)
            ->when($colunas['oferta_id'] !== null,
                fn ($q) => $q->where('oferta_id', $colunas['oferta_id']),
                fn ($q) => $q->where('produto_id', $colunas['produto_id'])->where('quantidade', $colunas['quantidade']))
            ->with('onda')
            ->first();

        return $linha?->onda !== null && (int) $linha->onda->company_id === $empresaId ? $linha->onda : null;
    }

    /** `2026-11-05` → `05/11`. */
    private static function diaCurto(string $data): string
    {
        return strlen($data) >= 10 ? substr($data, 8, 2).'/'.substr($data, 5, 2) : $data;
    }
}
