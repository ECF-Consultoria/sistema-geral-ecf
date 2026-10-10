<?php

namespace App\Services\Publicador\Tarefas;

use App\Models\Configuracao;
use App\Models\PubAlavancaEscrita;
use App\Models\PubProduto;
use App\Models\PubPromocaoAutomatica;
use App\Models\PubPublicacao;
use App\Models\PubPublicacaoItem;
use App\Models\PubRascunho;
use App\Models\PubTarefa;
use App\Models\SetorPermissao;
use App\Models\User;
use App\Notifications\TarefaAlavancasNotification;
use App\Support\Permissions;
use App\Support\Publicador\DiasUteis;
use App\Support\Publicador\RegraViolada;
use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * Tarefas pós-publicação (09/10/2026): "Vitória publicou → gatilho para o Caio ativar Central de
 * Promoções, cupom, afiliado e ADS". Três entradas:
 *
 *  - `abrir()` — no fim da publicação (`PublicacaoService`), uma tarefa por produto publicado com os
 *    MLBs CRIADOS naquela publicação. Idempotente (unique `tipo` + `publicacao_id`). Republicar o mesmo
 *    rascunho com a tarefa ainda aberta junta os MLBs novos nela (Clássico e Premium juntos).
 *  - `baixaPorEscrita()` — escrita OK das Alavancas num MLB de tarefa aberta marca o item do checklist.
 *  - `orientarPromocao()` — 10/10/2026: a promoção automática de um anúncio não saiu (ou não renovou);
 *    a Central de Promoções volta a pendente e a fila mostra o que fazer à mão (renovação reabre).
 *  - as ações da fila (pegar, marcar, concluir, observação), todas sob trava da linha da tarefa.
 *
 * Nada aqui fala com o Mercado Livre. Conta não liberada para as Alavancas também ganha tarefa: a equipe
 * faz no Seller Center e marca aqui.
 */
class TarefasPosPublicacao
{
    /** Em `configuracoes`: o id do usuário que recebe toda tarefa nova (vazio = fila comum). */
    public const CHAVE_RESPONSAVEL = 'publicador_alavancas_responsavel';

    /** Quem vê e opera a fila. Admin passa por qualquer chave (`User::hasPermission`). */
    public const PERMISSAO = Permissions::MLB_ALAVANCAS;

    public const MOTIVO_MAX = 300;

    public const OBSERVACAO_MAX = 2000;

    // ═══ Gatilho ═══════════════════════════════════════════════════════════

    /**
     * Abre (ou completa) a tarefa das alavancas de uma publicação concluída. Só os itens CREATED desta
     * publicação; publicação ainda rodando ou sem item criado não abre nada. `avisar = false` é o
     * retroativo: a tarefa nasce sem sino.
     */
    public function abrir(PubPublicacao $p, bool $avisar = true): ?PubTarefa
    {
        if ($p->status === PubPublicacao::RUNNING) {
            return null;
        }

        $criados = PubPublicacaoItem::query()->where('publicacao_id', $p->id)
            ->where('status', PubPublicacaoItem::CREATED)->whereNotNull('ml_item_id')
            ->orderBy('indice')->get();
        if ($criados->isEmpty()) {
            return null;
        }

        $existente = PubTarefa::query()->alavancas()->where('publicacao_id', $p->id)->first();
        if ($existente !== null) {
            return $existente;
        }

        $r = PubRascunho::query()->find($p->rascunho_id);
        $produto = $r?->produto_id !== null ? PubProduto::with(['company', 'mlbEmpresa', 'oferta', 'estruturaProduto'])->find($r->produto_id) : null;
        $itens = $criados->map(fn (PubPublicacaoItem $i) => self::itemDaTarefa($i, $p))->values()->all();

        try {
            return DB::transaction(fn () => $this->abrirOuJuntar($p, $r, $produto, $itens, $avisar));
        } catch (QueryException $e) {
            // Corrida no unique pubtar_tipo_pub_uq: outro processo abriu a mesma tarefa.
            $outra = PubTarefa::query()->alavancas()->where('publicacao_id', $p->id)->first();
            if ($outra !== null) {
                return $outra;
            }
            throw $e;
        }
    }

    private function abrirOuJuntar(PubPublicacao $p, ?PubRascunho $r, ?PubProduto $produto, array $itens, bool $avisar): ?PubTarefa
    {
        $doRascunho = $r !== null
            ? PubTarefa::query()->alavancas()->where('rascunho_id', $r->id)->orderBy('id')->get()
            : collect();
        $jaNaFila = $doRascunho->flatMap(fn (PubTarefa $t) => $t->idsDosItens())->all();
        $novos = array_values(array_filter($itens, fn (array $i) => ! in_array($i['ml_item_id'], $jaNaFila, true)));

        if ($novos === []) {
            // Todos os MLBs já estão numa tarefa (retroativo rodado de novo, por exemplo).
            $ids = array_column($itens, 'ml_item_id');

            return $doRascunho->first(fn (PubTarefa $t) => array_intersect($t->idsDosItens(), $ids) !== []);
        }

        // Uma tarefa por produto: republicar com a tarefa ainda aberta junta os MLBs novos nela.
        $aberta = $doRascunho->first(fn (PubTarefa $t) => $t->aberta());
        if ($aberta !== null) {
            $travada = PubTarefa::query()->whereKey($aberta->id)->lockForUpdate()->first();
            $travada->update(['itens' => [...(array) $travada->itens, ...$novos]]);
            Log::info("[Publicador] tarefa pós-publicação {$travada->id} ganhou ".count($novos)." MLB(s) da publicação {$p->id}.");

            return $travada;
        }

        $ator = (array) ($p->ator ?? []);
        $publicadoPor = ! empty($ator['equipe']) && isset($ator['id']) ? (int) $ator['id'] : null;
        $publicadoEm = $p->concluida_em ?? now();
        $responsavel = $this->responsavelPadrao();

        $tarefa = PubTarefa::create([
            'tipo' => PubTarefa::TIPO_ALAVANCAS,
            'status' => PubTarefa::PENDENTE,
            'publicacao_id' => $p->id,
            'produto_id' => $produto?->id,
            'rascunho_id' => $r?->id,
            'company_id' => $produto?->company_id,
            'mlb_empresa_id' => $produto?->mlb_empresa_id,
            'conta_chave' => mb_substr($this->chaveDaConta($ator, $produto), 0, 60),
            'itens' => $novos,
            'publicado_por' => $publicadoPor,
            'publicado_em' => $publicadoEm,
            'responsavel_id' => $responsavel?->id,
            'prazo' => $this->prazoPara($publicadoEm),
            'checklist' => PubTarefa::checklistInicial(),
        ]);
        Log::info("[Publicador] tarefa pós-publicação {$tarefa->id} aberta: publicação {$p->id}, ".count($novos).' MLB(s), '
            .($responsavel ? "responsável {$responsavel->id}" : 'fila comum').", prazo {$tarefa->prazo}.");

        if ($avisar) {
            $quem = (string) ($ator['nome'] ?? '');
            // Sai só depois do commit: a tarefa que o sino abre já existe.
            DB::afterCommit(fn () => $this->avisar($tarefa, $responsavel, $produto, $quem));
        }

        return $tarefa;
    }

    /** O que a tarefa guarda de cada MLB criado. */
    private static function itemDaTarefa(PubPublicacaoItem $i, PubPublicacao $p): array
    {
        $payload = (array) ($i->payload ?? []);

        return [
            'ml_item_id' => (string) $i->ml_item_id,
            'listing_type' => (string) $i->listing_type_id,
            'permalink' => $i->avisos['estado']['permalink'] ?? null,
            'titulo' => $payload['family_name'] ?? $payload['title'] ?? null,
            'publicacao_id' => $p->id,
        ];
    }

    /** A conta fixada no clique em Publicar (CR-B01); publicação antiga sem ela cai na âncora do produto. */
    private function chaveDaConta(array $ator, ?PubProduto $produto): string
    {
        $fixada = (string) ($ator['conta']['chave'] ?? '');
        if ($fixada !== '') {
            return $fixada;
        }
        if ($produto?->company_id !== null) {
            return 'company-'.$produto->company_id;
        }

        return $produto?->mlb_empresa_id !== null ? 'empresa-'.$produto->mlb_empresa_id : '';
    }

    /** D+N útil a partir do dia da publicação (`publicador.tarefas.prazo_dias_uteis`). */
    public function prazoPara(CarbonInterface $publicadoEm): string
    {
        return DiasUteis::somar($publicadoEm, (int) config('publicador.tarefas.prazo_dias_uteis', 1))->format('Y-m-d');
    }

    /** O sino: o responsável; sem responsável, todo mundo que vê a fila. Falhar aqui não desfaz a tarefa. */
    private function avisar(PubTarefa $t, ?User $responsavel, ?PubProduto $produto, string $quemPublicou): void
    {
        try {
            $destino = $responsavel !== null ? collect([$responsavel]) : $this->usuariosDaFila();
            if ($destino->isEmpty()) {
                return;
            }

            $nome = $produto?->nomeExibido() ?? ($t->itens[0]['titulo'] ?? 'Produto');
            $empresa = $produto?->mlbEmpresa?->nome ?? $produto?->company?->name ?? $t->conta_chave;
            $prazo = $t->prazoData()?->format('d/m') ?? '—';
            $partes = [$nome.' — '.$empresa.'.'];
            if ($quemPublicou !== '') {
                $partes[] = "Publicado por {$quemPublicou};";
            }
            $partes[] = "prazo {$prazo}.";

            Notification::send($destino, new TarefaAlavancasNotification(
                'Publicado, aguardando alavancas',
                mb_substr(implode(' ', $partes), 0, 200),
                route('mlb.anuncios.publicador.tarefas.index', ['tarefa' => $t->id], false),
                $t->publicado_por,
                ['tarefa_id' => $t->id],
            ));
        } catch (\Throwable $e) {
            Log::warning("[Publicador] tarefa pós-publicação {$t->id} aberta, mas o aviso no sino falhou: {$e->getMessage()}");
        }
    }

    // ═══ Quem recebe ═══════════════════════════════════════════════════════

    /** O responsável padrão configurado, se ainda for usuário ativo e ainda puder ver a fila. */
    public function responsavelPadrao(): ?User
    {
        $id = (int) Configuracao::get(self::CHAVE_RESPONSAVEL, 0);
        if ($id <= 0) {
            return null;
        }
        $u = User::query()->where('active', true)->find($id);

        return $u !== null && $u->hasPermission(self::PERMISSAO) ? $u : null;
    }

    /** O id gravado, mesmo que o usuário não sirva mais (a tela mostra que caiu na fila comum). */
    public function responsavelPadraoGravado(): ?int
    {
        $id = (int) Configuracao::get(self::CHAVE_RESPONSAVEL, 0);

        return $id > 0 ? $id : null;
    }

    public function definirResponsavelPadrao(?User $u): void
    {
        if ($u !== null && (! $u->active || ! $u->hasPermission(self::PERMISSAO))) {
            throw new RegraViolada('TAREFA-RESP', 'Escolha alguém ativo que veja a fila de publicados aguardando alavancas.');
        }
        Configuracao::set(self::CHAVE_RESPONSAVEL, $u?->id);
    }

    /** Ativos que veem a fila: os admins e os membros de setor com a chave `mlb.alavancas`. */
    public function usuariosDaFila(): Collection
    {
        $setores = SetorPermissao::query()->where('permission_key', self::PERMISSAO)->pluck('setor_id')->all();

        return User::query()->where('active', true)
            ->where(function ($q) use ($setores) {
                $q->where('role', 'admin');
                if ($setores !== []) {
                    $q->orWhereHas('setores', fn ($s) => $s->whereIn('setores.id', $setores));
                }
            })
            ->orderBy('name')->get();
    }

    /** O contador do menu: as abertas que são minhas ou de ninguém. */
    public function pendentesPara(User $u): int
    {
        return PubTarefa::query()->alavancas()->abertas()
            ->where(fn ($q) => $q->where('responsavel_id', $u->id)->orWhereNull('responsavel_id'))
            ->count();
    }

    // ═══ Baixa automática pela escrita das Alavancas ══════════════════════

    /**
     * A escrita que APLICA a alavanca dá baixa no item do checklist; tirar, remover e excluir não
     * contam, nem gravar o atacado com a lista vazia (é apagar as faixas). Cupom e campanha do
     * vendedor sem `item_id` (são da conta) não têm anúncio para casar.
     */
    public static function chaveDaEscrita(PubAlavancaEscrita $linha): ?string
    {
        return match ((string) $linha->acao) {
            'convite.inscrever', 'convite.alterar' => $linha->alavanca === 'cupom' ? 'cupom' : 'central_promocao',
            'desconto.criar' => 'central_promocao',
            'atacado.gravar' => count((array) ($linha->payload['dados']['faixas'] ?? [])) > 0 ? 'atacado' : null,
            default => null,
        };
    }

    /**
     * Escrita OK num MLB de tarefa ABERTA da MESMA conta (por âncora): marca o item feito com o
     * `escrita_id` e quem escreveu, e a tarefa pendente passa a em andamento. Item já feito fica como está.
     */
    public function baixaPorEscrita(PubAlavancaEscrita $linha): ?PubTarefa
    {
        if ($linha->resultado !== PubAlavancaEscrita::OK || (string) $linha->item_id === '') {
            return null;
        }
        $chave = self::chaveDaEscrita($linha);
        if ($chave === null || ($linha->company_id === null && $linha->mlb_empresa_id === null)) {
            return null;
        }

        $candidatas = PubTarefa::query()->alavancas()->abertas()
            ->where(function ($q) use ($linha) {
                if ($linha->mlb_empresa_id !== null) {
                    $q->orWhere('mlb_empresa_id', $linha->mlb_empresa_id);
                }
                if ($linha->company_id !== null) {
                    $q->orWhere('company_id', $linha->company_id);
                }
            })
            ->orderBy('id')->get()
            ->filter(fn (PubTarefa $t) => in_array((string) $linha->item_id, $t->idsDosItens(), true));

        $ultima = null;
        foreach ($candidatas as $t) {
            $ultima = DB::transaction(function () use ($t, $linha, $chave) {
                $travada = PubTarefa::query()->whereKey($t->id)->lockForUpdate()->first();
                if ($travada === null || ! $travada->aberta()) {
                    return $travada;
                }
                $checklist = $travada->checklistCompleto();
                if ($checklist[$chave]['estado'] === PubTarefa::ITEM_FEITO) {
                    return $travada;
                }
                // 10/10/2026: outro anúncio do produto ficou sem a promoção automática (o sistema não
                // conseguiu e a fila orienta fazer à mão): o item continua pendente até alguém resolver.
                if ($chave === 'central_promocao' && self::outroAnuncioSemPromocao($travada, (string) $linha->item_id)) {
                    Log::info("[Publicador] tarefa pós-publicação {$travada->id}: escrita {$linha->id} em {$linha->item_id} OK, mas outro anúncio ainda espera a promoção à mão; Central de Promoções segue pendente.");

                    return $travada;
                }
                $checklist[$chave] = [
                    'estado' => PubTarefa::ITEM_FEITO,
                    'motivo' => null,
                    'por' => ['id' => $linha->user_id, 'nome' => (string) $linha->ator_nome],
                    'em' => now()->toIso8601String(),
                    'escrita_id' => $linha->id,
                ];
                $travada->update([
                    'checklist' => $checklist,
                    ...$this->comecar($travada),
                ]);
                Log::info("[Publicador] tarefa pós-publicação {$travada->id}: {$chave} feito pela escrita {$linha->id} ({$linha->acao} {$linha->item_id}).");

                return $travada;
            });
        }

        return $ultima;
    }

    // ═══ Promoção automática (10/10/2026) ═════════════════════════════════

    /**
     * Algum OUTRO anúncio da tarefa tem a promoção automática (o último ciclo) recusada — o colaborador
     * ainda precisa criá-la à mão. Sem a tabela (entre o deploy e o `migrate`), não.
     */
    private static function outroAnuncioSemPromocao(PubTarefa $t, string $exceto): bool
    {
        $outros = array_values(array_diff($t->idsDosItens(), [$exceto]));
        if ($outros === []) {
            return false;
        }

        try {
            return PubPromocaoAutomatica::query()->ultimoCicloDe($outros)->where('status', PubPromocaoAutomatica::RECUSADA)->exists();
        } catch (\Throwable $e) {
            Log::warning('[Publicador] promoções automáticas indisponíveis na baixa da tarefa: '.$e->getMessage());

            return false;
        }
    }

    /**
     * O sistema não criou (ou não renovou) a promoção de um anúncio: a Central de Promoções da tarefa volta
     * a pendente, e a fila mostra o que fazer à mão (a frase vem do próprio ciclo, `orientacao()`).
     *
     * - 1º ciclo: só mexe em tarefa ABERTA, e nunca desfaz o que uma PESSOA marcou (ela resolveu ou
     *   decidiu que não se aplica);
     * - renovação: é trabalho novo — reabre a tarefa já concluída, com prazo novo (D+1 útil de hoje), e
     *   avisa no sino.
     */
    public function orientarPromocao(PubPromocaoAutomatica $promocao, bool $renovacao): ?PubTarefa
    {
        $tarefa = PubTarefa::query()->alavancas()
            ->where(function ($q) use ($promocao) {
                $q->where('rascunho_id', $promocao->rascunho_id);
                if ($promocao->publicacao_id !== null) {
                    $q->orWhere('publicacao_id', $promocao->publicacao_id);
                }
            })
            ->orderByDesc('id')->get()
            ->first(fn (PubTarefa $t) => in_array($promocao->ml_item_id, $t->idsDosItens(), true));
        if ($tarefa === null) {
            Log::warning("[Publicador] promoção automática {$promocao->id} ({$promocao->ml_item_id}) sem tarefa pós-publicação para orientar.");

            return null;
        }

        $reaberta = false;
        $travada = $this->sobTrava($tarefa, function (PubTarefa $t) use ($promocao, $renovacao, &$reaberta) {
            if (! $t->aberta() && ! $renovacao) {
                return $t;
            }
            $checklist = $t->checklistCompleto();
            $item = $checklist['central_promocao'];
            $porPessoa = $item['estado'] !== PubTarefa::ITEM_PENDENTE && $item['escrita_id'] === null && $item['por'] !== null;
            if (! $renovacao && $porPessoa) {
                return $t;
            }

            $checklist['central_promocao'] = ['estado' => PubTarefa::ITEM_PENDENTE, 'motivo' => null, 'por' => null, 'em' => now()->toIso8601String(), 'escrita_id' => null];
            $campos = ['checklist' => $checklist];
            if (! $t->aberta()) {
                $reaberta = true;
                $campos += ['status' => PubTarefa::PENDENTE, 'concluida_em' => null, 'prazo' => $this->prazoPara(now())];
            }
            $t->update($campos);
            Log::info("[Publicador] tarefa pós-publicação {$t->id}: Central de Promoções pendente — promoção automática {$promocao->id} ({$promocao->ml_item_id}, ciclo {$promocao->ciclo}) {$promocao->status}"
                .($reaberta ? '; tarefa reaberta.' : '.'));

            return $t;
        });

        if ($reaberta) {
            DB::afterCommit(fn () => $this->avisarRenovacao($travada, $promocao));
        }

        return $travada;
    }

    /** O sino da tarefa reaberta: o responsável dela; sem responsável, quem vê a fila. */
    private function avisarRenovacao(PubTarefa $t, PubPromocaoAutomatica $promocao): void
    {
        try {
            $destino = $t->responsavel_id !== null
                ? User::query()->where('active', true)->whereKey($t->responsavel_id)->get()
                : $this->usuariosDaFila();
            if ($destino->isEmpty()) {
                return;
            }
            $produto = $t->produto()->with(['mlbEmpresa', 'company'])->first();
            $nome = $produto?->nomeExibido() ?? ($t->itens[0]['titulo'] ?? 'Produto');
            $empresa = $produto?->mlbEmpresa?->nome ?? $produto?->company?->name ?? $t->conta_chave;

            Notification::send($destino, new TarefaAlavancasNotification(
                'Promoção não renovada',
                mb_substr("{$nome} — {$empresa}. {$promocao->ml_item_id}: crie a promoção à mão; prazo ".($t->prazoData()?->format('d/m') ?? '—').'.', 0, 200),
                route('mlb.anuncios.publicador.tarefas.index', ['tarefa' => $t->id], false),
                null,
                ['tarefa_id' => $t->id, 'promocao_id' => $promocao->id],
            ));
        } catch (\Throwable $e) {
            Log::warning("[Publicador] tarefa pós-publicação {$t->id} reaberta, mas o aviso no sino falhou: {$e->getMessage()}");
        }
    }

    // ═══ Ações da fila ═════════════════════════════════════════════════════

    /** "Pegar": vira o responsável e a tarefa entra em andamento. */
    public function pegar(PubTarefa $t, User $u): PubTarefa
    {
        return $this->sobTrava($t, function (PubTarefa $travada) use ($u) {
            $this->exigirAberta($travada);
            $travada->update(['responsavel_id' => $u->id, 'status' => PubTarefa::EM_ANDAMENTO,
                'iniciada_em' => $travada->iniciada_em ?? now()]);

            return $travada;
        });
    }

    /** Marca um item: feito, não se aplica (motivo obrigatório) ou de volta a pendente. */
    public function marcar(PubTarefa $t, string $chave, string $estado, ?string $motivo, User $u): PubTarefa
    {
        if (! array_key_exists($chave, PubTarefa::CHECKLIST_ALAVANCAS)) {
            throw new RegraViolada('TAREFA-ITEM', 'Item do checklist desconhecido.');
        }
        if (! in_array($estado, PubTarefa::ESTADOS_DO_ITEM, true)) {
            throw new RegraViolada('TAREFA-ESTADO', 'Escolha feito, não se aplica ou pendente.');
        }
        $motivo = trim((string) $motivo);
        if ($estado === PubTarefa::ITEM_NAO_SE_APLICA && $motivo === '') {
            throw new RegraViolada('TAREFA-MOTIVO', 'Diga por que não se aplica.');
        }

        return $this->sobTrava($t, function (PubTarefa $travada) use ($chave, $estado, $motivo, $u) {
            $this->exigirAberta($travada);
            $checklist = $travada->checklistCompleto();
            $checklist[$chave] = $estado === PubTarefa::ITEM_PENDENTE
                ? ['estado' => PubTarefa::ITEM_PENDENTE, 'motivo' => null, 'por' => null, 'em' => null, 'escrita_id' => null]
                : [
                    'estado' => $estado,
                    'motivo' => $estado === PubTarefa::ITEM_NAO_SE_APLICA ? mb_substr($motivo, 0, self::MOTIVO_MAX) : null,
                    'por' => ['id' => $u->id, 'nome' => (string) $u->name],
                    'em' => now()->toIso8601String(),
                    'escrita_id' => null,
                ];
            $travada->update(['checklist' => $checklist, ...($estado === PubTarefa::ITEM_PENDENTE ? [] : $this->comecar($travada))]);

            return $travada;
        });
    }

    /** "Concluir": só com todos os itens feitos ou "não se aplica". */
    public function concluir(PubTarefa $t, User $u): PubTarefa
    {
        return $this->sobTrava($t, function (PubTarefa $travada) use ($u) {
            $this->exigirAberta($travada);
            if (! $travada->checklistResolvido()) {
                throw new RegraViolada('TAREFA-INCOMPLETA', 'Para concluir, marque cada item como feito ou "não se aplica".');
            }
            $travada->update([
                'status' => PubTarefa::FEITA,
                'concluida_em' => now(),
                'iniciada_em' => $travada->iniciada_em ?? now(),
                'responsavel_id' => $travada->responsavel_id ?? $u->id,
            ]);

            return $travada;
        });
    }

    /** A observação vale em qualquer status (até na feita: é o registro do que foi combinado). */
    public function observar(PubTarefa $t, ?string $texto): PubTarefa
    {
        $texto = trim((string) $texto);

        return $this->sobTrava($t, function (PubTarefa $travada) use ($texto) {
            $travada->update(['observacao' => $texto === '' ? null : mb_substr($texto, 0, self::OBSERVACAO_MAX)]);

            return $travada;
        });
    }

    /** @return array<string, mixed> o que muda quando a tarefa pendente ganha a primeira ação */
    private function comecar(PubTarefa $t): array
    {
        $campos = [];
        if ($t->status === PubTarefa::PENDENTE) {
            $campos['status'] = PubTarefa::EM_ANDAMENTO;
        }
        if ($t->iniciada_em === null) {
            $campos['iniciada_em'] = now();
        }

        return $campos;
    }

    private function exigirAberta(PubTarefa $t): void
    {
        if (! $t->aberta()) {
            throw new RegraViolada('TAREFA-FECHADA', 'Esta tarefa já foi concluída.');
        }
    }

    /** Lê e grava a linha da tarefa na mesma transação, sob `FOR UPDATE` (o checklist é um JSON só). */
    private function sobTrava(PubTarefa $t, \Closure $fn): PubTarefa
    {
        return DB::transaction(function () use ($t, $fn) {
            $travada = PubTarefa::query()->whereKey($t->id)->lockForUpdate()->firstOrFail();

            return $fn($travada);
        });
    }
}
