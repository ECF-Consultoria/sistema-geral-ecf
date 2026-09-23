<?php

namespace App\Services\Portal;

use App\Models\Company;
use App\Models\Ppa;
use App\Models\PpaTask;
use Illuminate\Support\Collection;

/**
 * PortalPpaService — a régua ÚNICA de "qual PPA este cliente pode ver".
 *
 * O módulo PPA do Portal do Cliente não é um segundo PPA: é uma segunda
 * INTERFACE sobre `ppas`/`ppa_tasks`, as mesmas linhas que a equipe gerencia
 * em `/ppa` (carteira) e `/polos-ppa` (Polos). Não há sincronização, cópia nem
 * espelho — criar um PPA para a empresa internamente é o que o faz aparecer no
 * portal dela.
 *
 * Toda pergunta de visibilidade e de posse passa por aqui. Espalhar essas
 * regras entre o controller e a contagem do badge seria o caminho curto para
 * um cliente ver o PPA de outra empresa por causa de uma query esquecida.
 *
 * ### O que o cliente NÃO recebe
 * `trello_board_url` (quadro interno), `mentor_id`, `workspace_token`,
 * `escopo` e `completed_at` — nada disso viaja no payload de
 * {@see self::visao()}.
 *
 * `atualizado_em` É uma exceção deliberada (23/09/2026): a tela do cliente
 * passou a poder ordenar por "atualizados recentemente", e ordenar por um
 * critério invisível confunde. É a data de mexida no plano DELE, não um dado
 * de controle interno. O nome do estrategista fica de fora aqui porque o
 * portal já apresenta quem atende o cliente em bloco próprio, com foto e papel
 * (`OnboardingLinkService::responsaveisDaEmpresa()`); repetir por PPA só
 * criaria uma segunda fonte para o mesmo fato.
 */
class PortalPpaService
{
    /**
     * Rascunho é trabalho interno em construção. O PPA entra no portal no
     * momento em que a equipe o marca como enviado — que é exatamente o que
     * `sent_at` já significa no módulo interno desde sempre.
     */
    public const STATUS_VISIVEIS = ['sent', 'completed'];

    /**
     * Os PPAs que ESTA empresa vê, dos dois escopos.
     *
     * `geral` amarra em `ppas.company_id`; `polos` amarra em `mlb_empresa_id`
     * e chega à Company pelo `mlb_empresas.company_id`
     * (`PolosPpaController::store()` deixa `company_id` nulo de propósito). Do
     * lado do cliente a distinção não existe — é tudo "o meu plano de ação" —,
     * por isso a lista é uma só.
     *
     * ATENÇÃO à cobertura do vínculo de Polos: `mlb_empresas.company_id` é
     * nulo na maioria das linhas (3 de 308 no banco local em 21/08/2026). PPA
     * de Polos de empresa sem esse vínculo NÃO aparece no portal — e o sintoma
     * é silencioso, uma lista vazia. Se um cliente de Polos reclamar que não vê
     * o plano, o primeiro lugar a olhar é essa coluna, não esta query.
     *
     * @return Collection<int, Ppa>
     */
    public function ppasDaEmpresa(Company $company): Collection
    {
        return Ppa::query()
            ->with(['tasks'])
            ->whereIn('status', self::STATUS_VISIVEIS)
            ->where(function ($q) use ($company) {
                $q->where('company_id', $company->id)
                  ->orWhereHas('mlbEmpresa', fn ($m) => $m->where('company_id', $company->id));
            })
            // Plano em andamento antes do concluído: é nele que o cliente tem o
            // que fazer. Dentro de cada grupo, o mais recente primeiro.
            ->orderByRaw("CASE WHEN status = 'completed' THEN 1 ELSE 0 END")
            ->orderByDesc('created_at')
            ->get();
    }

    /**
     * O PPA como o cliente o vê. Ver o docblock da classe para o que fica de
     * fora e por quê.
     *
     * ### O prazo viaja em três formas, e nenhuma é redundante
     * `prazo` é o que a tela escreve; `prazo_iso` é o que ela ordena; e
     * `prazo_dias` é a distância até hoje CALCULADA AQUI. O terceiro existe
     * porque o mesmo cálculo no JS usaria o fuso do navegador — um cliente no
     * Acre veria "atrasado" um dia antes do cliente em São Paulo, sobre a
     * mesma linha do banco. Mesma razão pela qual `PpaQuadroService::tarefas()`
     * já mandava `prazo_dias` para o quadro interno.
     *
     * @return array{id: int, titulo: string, descricao: ?string, status: string, concluido: bool, prazo: ?string, prazo_iso: ?string, prazo_dias: ?int, enviado_em: ?string, tarefas: array<int, array{id: int, titulo: string, descricao: ?string, status: string, prazo: ?string, prazo_dias: ?int, responsavel_lado: ?string}>, total: int, feitas: int, fazendo: int, a_fazer: int, pct: int}
     */
    public function visao(Ppa $ppa): array
    {
        $tarefas = $ppa->tasks
            ->sortBy('order')
            ->map(fn (PpaTask $t) => [
                'id'        => $t->id,
                'titulo'    => $t->title,
                'descricao' => $t->description,
                'status'    => $t->status,
                // Prazo e lado responsável passaram a viajar em 21/09/2026.
                // Não são dado interno: o prazo é o que combinamos COM o
                // cliente, e o lado responde a pergunta que ele mais faz ao
                // abrir o plano — "isto é comigo ou com vocês?". Sem o lado,
                // toda tarefa parece cobrança dele. O que continua de fora é o
                // que o docblock da classe lista (quadro interno, mentor,
                // token, escopo e datas de controle).
                'prazo'            => $t->prazo?->format('d/m/Y'),
                'prazo_dias'       => $this->diasAte($t->prazo, $t->status === 'done'),
                'responsavel_lado' => $t->responsavel_lado,
            ])
            ->values()
            ->all();

        $total   = count($tarefas);
        $feitas  = count(array_filter($tarefas, fn ($t) => $t['status'] === 'done'));
        $fazendo = count(array_filter($tarefas, fn ($t) => $t['status'] === 'doing'));

        return [
            'id'         => $ppa->id,
            'titulo'     => $ppa->title,
            'descricao'  => $ppa->description,
            'status'     => $ppa->status,
            'concluido'  => $ppa->status === 'completed',
            'prazo'      => $ppa->due_date?->format('d/m/Y'),
            'prazo_iso'  => $ppa->due_date?->format('Y-m-d'),
            'prazo_dias' => $this->diasAte($ppa->due_date, $ppa->status === 'completed'),
            'enviado_em' => $ppa->sent_at?->format('d/m/Y'),
            // Quando mexeram neste plano pela última vez, contando as TAREFAS
            // (ver `Ppa::atualizadoEm()`). O `_iso` é o que a tela ORDENA; o
            // outro é o que ela escreve — mesma divisão do prazo, logo acima.
            'atualizado_em'  => $ppa->atualizadoEm()?->format('d/m/Y'),
            'atualizado_iso' => $ppa->atualizadoEm()?->format('Y-m-d H:i:s'),
            'tarefas'    => $tarefas,
            'total'      => $total,
            'feitas'     => $feitas,
            // As contagens que decidem em que seção o plano cai na tela
            // (`resources/js/lib/ppaAgrupamento.js`). Elas saem das MESMAS
            // tarefas acima — a tela as recalcula ao vivo enquanto o cliente
            // arrasta, e estas aqui são o ponto de partida.
            'fazendo'    => $fazendo,
            'a_fazer'    => $total - $feitas - $fazendo,
            'pct'        => $total > 0 ? (int) round(($feitas / $total) * 100) : 0,
        ];
    }

    /**
     * Dias entre hoje e uma data (negativo = passou). `null` quando não há
     * data ou quando o trabalho já foi encerrado — atraso de coisa pronta não
     * é atraso, e o `null` é o que apaga o selo vermelho do outro lado.
     */
    private function diasAte(?\Illuminate\Support\Carbon $data, bool $encerrado): ?int
    {
        if (! $data || $encerrado) {
            return null;
        }

        return (int) now()->startOfDay()->diffInDays($data->startOfDay(), false);
    }

    /**
     * Quantas tarefas ainda esperam o cliente — o badge do menu.
     *
     * PPA já concluído não conta mesmo que tenha tarefa aberta: a equipe
     * encerrou o plano, e um número teimando no menu mandaria o cliente
     * perseguir algo que ninguém mais espera dele.
     */
    public function pendentes(Company $company): int
    {
        return $this->ppasDaEmpresa($company)
            ->reject(fn (Ppa $p) => $p->status === 'completed')
            ->sum(fn (Ppa $p) => $p->tasks->where('status', '!=', 'done')->count());
    }

    /**
     * A trava de posse: `true` só se a tarefa pertence a um PPA que ESTA
     * empresa pode ver.
     *
     * Checa a lista inteira em vez de subir por `task->ppa->company_id` de
     * propósito — assim a resposta usa a MESMA régua da listagem, incluindo o
     * filtro de status e o caminho de Polos. Se um dia a listagem mudar, a
     * trava muda junto, e não fica um caminho de escrita mais permissivo que o
     * de leitura.
     */
    public function podeMexer(Company $company, PpaTask $task): bool
    {
        return $this->ppasDaEmpresa($company)
            ->contains(fn (Ppa $p) => $p->id === $task->ppa_id && $p->status !== 'completed');
    }
}
