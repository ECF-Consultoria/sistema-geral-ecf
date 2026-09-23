<?php

namespace App\Services\DevDemandas;

use App\Models\Chamado;
use App\Models\ChamadoAnexo;
use App\Models\ChamadoEvento;
use App\Models\ChamadoMensagem;
use App\Models\DevDemanda;
use App\Models\User;
use App\Notifications\ChamadoNotification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

/**
 * Chamados — regras de acesso, fluxo e histórico.
 *
 * Acesso (o servidor decide; a tela só reflete):
 *  - quem abriu vê o próprio chamado, só as mensagens públicas e só os eventos públicos;
 *  - admin atua em todos;
 *  - dev (cargo Dev, `users.is_dev`) atua nos que estão com ele e nos da fila (sem responsável);
 *  - nota interna, demanda ligada e motivo de transferência nunca saem para o solicitante.
 *
 * Tudo que muda o chamado grava um ChamadoEvento — nada muda em silêncio,
 * e nenhum evento/mensagem é editado ou apagado.
 */
class ChamadoService
{
    public function __construct(private DemandasDevService $demandas) {}

    public const MAX_ANEXOS = 5;

    // ═══ Acesso ═══

    public function ehSolicitante(User $u, Chamado $c): bool
    {
        return $c->solicitante_id !== null && $c->solicitante_id === $u->id;
    }

    /** Atuar como equipe neste chamado (responder interno, status, transferir, resolver). */
    public function podeAtuar(User $u, Chamado $c): bool
    {
        if ($u->isAdmin()) {
            return true;
        }

        return $u->isAdminDev() && ($c->responsavel_id === null || $c->responsavel_id === $u->id);
    }

    public function podeVer(User $u, Chamado $c): bool
    {
        return $this->ehSolicitante($u, $c) || $this->podeAtuar($u, $c);
    }

    /** Criar demanda a partir do chamado segue a regra de criar demanda (DemandasDevService). */
    public function podeConverter(User $u, Chamado $c): bool
    {
        return $this->podeAtuar($u, $c) && $this->demandas->podeGerenciar($u);
    }

    /** Chamados que a equipe enxerga na caixa de entrada: admin, todos; dev, os seus + a fila. */
    public function daEquipe(User $u): Builder
    {
        return Chamado::query()->when(! $u->isAdmin(), fn ($q) => $q->where(fn ($w) => $w
            ->whereNull('responsavel_id')
            ->orWhere('responsavel_id', $u->id)));
    }

    // ═══ Abertura ═══

    /**
     * @param  array{tipo:string, area:?string, responsavel_id:?int, titulo:string, descricao:string, impacto:string,
     *   contexto_tentando?:?string, contexto_aconteceu?:?string, contexto_esperado?:?string}  $dados
     * @param  UploadedFile[]  $arquivos
     */
    public function abrir(User $solicitante, array $dados, array $arquivos = []): Chamado
    {
        $responsavel = $this->devValido($dados['responsavel_id'] ?? null);

        $chamado = DB::transaction(function () use ($solicitante, $dados, $arquivos, $responsavel) {
            $c = Chamado::create([
                'solicitante_id'     => $solicitante->id,
                'solicitante_nome'   => $solicitante->name,
                'solicitante_email'  => $solicitante->email,
                'responsavel_id'     => $responsavel?->id,
                'area'               => $dados['area'] ?? null,
                'tipo'               => $dados['tipo'],
                'impacto'            => $dados['impacto'],
                'titulo'             => $dados['titulo'],
                'descricao'          => $dados['descricao'],
                'contexto_tentando'  => $dados['contexto_tentando'] ?? null,
                'contexto_aconteceu' => $dados['contexto_aconteceu'] ?? null,
                'contexto_esperado'  => $dados['contexto_esperado'] ?? null,
                'status'             => Chamado::STATUS_ABERTO,
                'ultima_interacao_em' => now(),
            ]);
            // Código derivado do id auto-incremento: dois pedidos simultâneos nunca repetem.
            $c->update(['codigo' => sprintf('TKT-%04d', $c->id)]);

            $this->evento($c, $solicitante, ChamadoEvento::CRIADO, publico: true);
            if ($responsavel) {
                $this->evento($c, $solicitante, ChamadoEvento::ATRIBUIDO, para: $responsavel->name, meta: ['para_id' => $responsavel->id], publico: true);
            }
            $this->salvarAnexos($c, null, $solicitante, $arquivos);

            return $c;
        });

        $destino = $responsavel ? collect([$responsavel]) : $this->equipe();
        $this->avisar($destino, $solicitante, $chamado,
            $responsavel ? "Novo chamado para você: {$chamado->codigo}" : "Novo chamado na fila: {$chamado->codigo}",
            "{$solicitante->name}: {$chamado->titulo}");

        return $chamado;
    }

    // ═══ Conversa ═══

    /**
     * Mensagem no chamado. Quem abriu só manda pública; a equipe escolhe pública ou interna.
     *
     * @param  UploadedFile[]  $arquivos
     */
    public function responder(Chamado $c, User $autor, string $texto, bool $interna, array $arquivos = []): ChamadoMensagem
    {
        $comoEquipe = $this->podeAtuar($autor, $c);
        if (! $comoEquipe) {
            $interna = false; // o solicitante nunca escreve nota interna, mande o que mandar
        }
        if ($c->estaEncerrado() && ! $interna) {
            throw new \RuntimeException('Este chamado está encerrado. Reabra para continuar a conversa.');
        }

        $mensagem = DB::transaction(function () use ($c, $autor, $texto, $interna, $arquivos, $comoEquipe) {
            $m = $c->mensagens()->create([
                'autor_id'     => $autor->id,
                'visibilidade' => $interna ? Chamado::VISIBILIDADE_INTERNA : Chamado::VISIBILIDADE_PUBLICA,
                'texto'        => $texto,
            ]);
            $this->evento($c, $autor, $interna ? ChamadoEvento::NOTA_INTERNA : ChamadoEvento::RESPOSTA_PUBLICA, meta: ['mensagem_id' => $m->id]);
            $this->salvarAnexos($c, $m, $autor, $arquivos);

            if (! $interna) {
                // Resposta muda o status de forma previsível — e registrada.
                if ($comoEquipe && in_array($c->status, [Chamado::STATUS_ABERTO, Chamado::STATUS_EM_TRIAGEM], true)) {
                    $this->trocarStatus($c, $autor, Chamado::STATUS_EM_ATENDIMENTO);
                } elseif (! $comoEquipe && $c->status === Chamado::STATUS_AGUARDANDO_SOLICITANTE) {
                    $this->trocarStatus($c, $autor, Chamado::STATUS_EM_ATENDIMENTO);
                }
                $c->update(['ultima_interacao_em' => now()]);
            }

            return $m;
        });

        if (! $interna) {
            $comoEquipe
                ? $this->avisar($this->solicitanteDe($c), $autor, $c, "Resposta no seu chamado {$c->codigo}", $c->titulo, paraSolicitante: true)
                : $this->avisar($this->quemAtende($c), $autor, $c, "{$c->solicitante_nome} respondeu o {$c->codigo}", $c->titulo);
        }

        return $mensagem;
    }

    // ═══ Fluxo da equipe ═══

    public const STATUS_MANUAIS = [
        Chamado::STATUS_ABERTO, Chamado::STATUS_EM_TRIAGEM, Chamado::STATUS_EM_ATENDIMENTO, Chamado::STATUS_AGUARDANDO_SOLICITANTE,
    ];

    /** Troca manual de status pela equipe. Resolver e cancelar têm fluxo próprio. */
    public function mudarStatus(Chamado $c, User $ator, string $novo): void
    {
        if (! in_array($novo, self::STATUS_MANUAIS, true)) {
            throw new \RuntimeException('Para resolver ou cancelar, use a ação própria.');
        }
        if ($c->estaEncerrado()) {
            throw new \RuntimeException('Chamado encerrado: reabra antes de mudar o status.');
        }
        if ($c->status === $novo) {
            return;
        }

        DB::transaction(function () use ($c, $ator, $novo) {
            $this->trocarStatus($c, $ator, $novo);
            $c->update(['ultima_interacao_em' => now()]);
        });

        $this->avisar($this->solicitanteDe($c), $ator, $c, "{$c->codigo}: " . Chamado::STATUS_LABELS[$novo], $c->titulo, paraSolicitante: true);
    }

    /**
     * Transfere (ou assume, se estava na fila). O histórico guarda de quem saiu,
     * para quem foi, quem fez e o motivo — cada transferência é uma linha nova.
     */
    public function transferir(Chamado $c, User $ator, int $novoId, ?string $motivo): void
    {
        $novo = $this->devValido($novoId) ?? throw new \RuntimeException('Escolha um dev válido.');
        if ($c->responsavel_id === $novo->id) {
            throw new \RuntimeException("O chamado já está com {$novo->name}.");
        }
        $anterior = $c->responsavel;
        // Tirar de alguém exige motivo; assumir um chamado da fila, não.
        if ($anterior && trim((string) $motivo) === '') {
            throw new \RuntimeException('Diga o motivo da transferência.');
        }

        DB::transaction(function () use ($c, $ator, $novo, $anterior, $motivo) {
            $c->update(['responsavel_id' => $novo->id, 'ultima_interacao_em' => now()]);
            $this->evento($c, $ator, $anterior ? ChamadoEvento::TRANSFERIDO : ChamadoEvento::ATRIBUIDO,
                de: $anterior?->name, para: $novo->name,
                meta: array_filter(['de_id' => $anterior?->id, 'para_id' => $novo->id, 'motivo' => $motivo ? trim($motivo) : null]),
                publico: true);
        });

        $this->avisar(collect([$novo]), $ator, $c,
            $anterior ? "Chamado transferido para você: {$c->codigo}" : "Chamado atribuído a você: {$c->codigo}",
            trim($c->titulo . ($motivo ? " — {$motivo}" : '')));
    }

    /**
     * Cria a demanda a partir do chamado. Idempotente: dois cliques (ou duas abas)
     * devolvem a MESMA demanda. Trava a linha do chamado e, por garantia, a coluna
     * `chamados.dev_demanda_id` é única no banco.
     *
     * @return array{0: DevDemanda, 1: bool} [demanda, criada agora?]
     */
    public function converterEmDemanda(Chamado $c, User $ator, array $dados): array
    {
        return DB::transaction(function () use ($c, $ator, $dados) {
            $travado = Chamado::query()->lockForUpdate()->findOrFail($c->id);
            if ($travado->dev_demanda_id) {
                return [DevDemanda::findOrFail($travado->dev_demanda_id), false];
            }

            $prefixo = strtoupper($dados['prefixo']);
            unset($dados['prefixo']);
            $origem = "Origem: chamado {$travado->codigo}";
            $demanda = DevDemanda::create($dados + [
                'codigo'     => DevDemanda::proximoCodigo($prefixo),
                'criado_por' => $ator->id,
            ]);
            if (! str_contains((string) $demanda->observacoes, $origem)) {
                $demanda->update(['observacoes' => trim($origem . "\n" . ($demanda->observacoes ?? ''))]);
            }

            $travado->update(['dev_demanda_id' => $demanda->id, 'ultima_interacao_em' => now()]);
            $this->evento($travado, $ator, ChamadoEvento::CONVERTIDO, para: $demanda->codigo, meta: ['dev_demanda_id' => $demanda->id]);
            if (in_array($travado->status, [Chamado::STATUS_ABERTO, Chamado::STATUS_EM_TRIAGEM], true)) {
                $this->trocarStatus($travado, $ator, Chamado::STATUS_EM_ATENDIMENTO);
            }

            return [$demanda, true];
        });
    }

    /** Resolve: a mensagem de resolução vai para o solicitante e fica gravada no chamado. */
    public function resolver(Chamado $c, User $ator, string $resolucao): void
    {
        if ($c->estaEncerrado()) {
            throw new \RuntimeException('Este chamado já está encerrado.');
        }

        DB::transaction(function () use ($c, $ator, $resolucao) {
            $m = $c->mensagens()->create(['autor_id' => $ator->id, 'visibilidade' => Chamado::VISIBILIDADE_PUBLICA, 'texto' => $resolucao]);
            $de = $c->status;
            $c->update(['status' => Chamado::STATUS_RESOLVIDO, 'resolucao' => $resolucao, 'resolvido_em' => now(), 'ultima_interacao_em' => now()]);
            $this->evento($c, $ator, ChamadoEvento::RESOLVIDO, de: $de, para: Chamado::STATUS_RESOLVIDO, meta: ['mensagem_id' => $m->id], publico: true);
        });

        $this->avisar($this->solicitanteDe($c), $ator, $c, "Chamado {$c->codigo} resolvido", $c->titulo, paraSolicitante: true);
    }

    /** Reabre um chamado resolvido (quem abriu ou a equipe). */
    public function reabrir(Chamado $c, User $ator, ?string $motivo): void
    {
        if ($c->status !== Chamado::STATUS_RESOLVIDO) {
            throw new \RuntimeException('Só um chamado resolvido pode ser reaberto.');
        }
        $novo = $c->responsavel_id ? Chamado::STATUS_EM_ATENDIMENTO : Chamado::STATUS_ABERTO;

        DB::transaction(function () use ($c, $ator, $motivo, $novo) {
            $c->update(['status' => $novo, 'resolvido_em' => null, 'ultima_interacao_em' => now()]);
            $this->evento($c, $ator, ChamadoEvento::REABERTO, de: Chamado::STATUS_RESOLVIDO, para: $novo, publico: true);
            if ($motivo && trim($motivo) !== '') {
                $c->mensagens()->create(['autor_id' => $ator->id, 'visibilidade' => Chamado::VISIBILIDADE_PUBLICA, 'texto' => trim($motivo)]);
            }
        });

        $this->ehSolicitante($ator, $c)
            ? $this->avisar($this->quemAtende($c), $ator, $c, "{$c->codigo} foi reaberto", $c->titulo)
            : $this->avisar($this->solicitanteDe($c), $ator, $c, "{$c->codigo} foi reaberto", $c->titulo, paraSolicitante: true);
    }

    public function cancelar(Chamado $c, User $ator, ?string $motivo): void
    {
        if ($c->estaEncerrado()) {
            throw new \RuntimeException('Este chamado já está encerrado.');
        }

        DB::transaction(function () use ($c, $ator, $motivo) {
            $de = $c->status;
            $c->update(['status' => Chamado::STATUS_CANCELADO, 'ultima_interacao_em' => now()]);
            $this->evento($c, $ator, ChamadoEvento::CANCELADO, de: $de, para: Chamado::STATUS_CANCELADO, publico: true);
            if ($motivo && trim($motivo) !== '') {
                $c->mensagens()->create(['autor_id' => $ator->id, 'visibilidade' => Chamado::VISIBILIDADE_PUBLICA, 'texto' => trim($motivo)]);
            }
        });

        $this->ehSolicitante($ator, $c)
            ? $this->avisar($this->quemAtende($c), $ator, $c, "{$c->codigo} foi cancelado por quem abriu", $c->titulo)
            : $this->avisar($this->solicitanteDe($c), $ator, $c, "Chamado {$c->codigo} cancelado", $c->titulo, paraSolicitante: true);
    }

    // ═══ Leitura ═══

    /**
     * Linha da lista. `$comoEquipe` inclui o que só a equipe vê (demanda, prioridade sugerida).
     */
    public function resumo(Chamado $c, bool $comoEquipe): array
    {
        $ultimaPublica = $c->ultimaMensagemPublica;

        $linha = [
            'id'                  => $c->id,
            'codigo'              => $c->codigo,
            'titulo'              => $c->titulo,
            'status'              => $c->status,
            'tipo'                => $c->tipo,
            'impacto'             => $c->impacto,
            'area'                => $c->area,
            'solicitante'         => $c->solicitante_nome,
            'solicitante_id'      => $c->solicitante_id,
            'responsavel'         => $c->responsavel ? ['id' => $c->responsavel->id, 'name' => $c->responsavel->name] : null,
            'criado_em'           => $c->created_at?->toIso8601String(),
            'ultima_interacao_em' => ($c->ultima_interacao_em ?? $c->created_at)?->toIso8601String(),
            'ultima_publica'      => $ultimaPublica ? mb_substr($ultimaPublica->texto, 0, 140) : null,
        ];

        if ($comoEquipe) {
            $linha += [
                // Precisa da equipe: novo, ou o solicitante foi o último a falar.
                'precisa_atencao'     => ! $c->estaEncerrado() && (
                    $c->status === Chamado::STATUS_ABERTO
                    || ($ultimaPublica && $ultimaPublica->autor_id === $c->solicitante_id && $c->status !== Chamado::STATUS_AGUARDANDO_SOLICITANTE)
                ),
                'prioridade_sugerida' => Chamado::PRIORIDADE_SUGERIDA[$c->impacto] ?? null,
                'demanda'             => $c->demanda ? ['id' => $c->demanda->id, 'codigo' => $c->demanda->codigo] : null,
            ];
        }

        return $linha;
    }

    /**
     * Chamado completo para quem está vendo. O solicitante recebe só o que é dele:
     * nada de nota interna, anexo de nota interna, demanda ou motivo de transferência.
     * `$comoSolicitante` força essa visão mesmo para quem também é da equipe (a tela
     * "Meus chamados" é sempre a de quem pediu).
     */
    public function detalhe(Chamado $c, User $viewer, bool $comoSolicitante = false): array
    {
        $comoEquipe = ! $comoSolicitante && $this->podeAtuar($viewer, $c);
        $c->loadMissing(['responsavel:id,name', 'demanda.ultimaAtualizacao', 'mensagens.autor:id,name', 'mensagens.anexos', 'eventos.ator:id,name', 'anexos']);

        $anexo = fn (ChamadoAnexo $a) => [
            'id'      => $a->id,
            'nome'    => $a->nome_original,
            'mime'    => $a->mime,
            'tamanho' => $a->tamanho,
            'url'     => route('chamados.anexos.show', [$c->id, $a->id]),
            'imagem'  => str_starts_with($a->mime, 'image/'),
        ];

        $mensagens = $c->mensagens
            ->filter(fn (ChamadoMensagem $m) => $comoEquipe || ! $m->ehInterna())
            ->map(fn (ChamadoMensagem $m) => [
                'item'       => 'mensagem',
                'id'         => $m->id,
                'em'         => $m->created_at?->toIso8601String(),
                'autor'      => $m->autor?->name ?? '—',
                'da_equipe'  => $m->autor_id !== $c->solicitante_id,
                'interna'    => $m->ehInterna(),
                'texto'      => $m->texto,
                'anexos'     => $m->anexos->map($anexo)->values()->all(),
            ]);

        $eventos = $c->eventos
            ->filter(fn (ChamadoEvento $e) => $comoEquipe || $e->publico)
            // Resposta/nota já aparecem como mensagem; o evento delas é auditoria, não linha do tempo.
            ->reject(fn (ChamadoEvento $e) => in_array($e->tipo, [ChamadoEvento::RESPOSTA_PUBLICA, ChamadoEvento::NOTA_INTERNA, ChamadoEvento::ANEXO], true))
            ->map(fn (ChamadoEvento $e) => [
                'item'   => 'evento',
                'id'     => $e->id,
                'em'     => $e->created_at?->toIso8601String(),
                'ator'   => $e->ator?->name ?? '—',
                'tipo'   => $e->tipo,
                'de'     => $e->de,
                'para'   => $e->para,
                // Motivo de transferência e ids internos ficam com a equipe.
                'motivo' => $comoEquipe ? ($e->meta['motivo'] ?? null) : null,
            ]);

        $linhaDoTempo = $mensagens->concat($eventos)
            ->sortBy(fn ($i) => [$i['em'], $i['item'] === 'evento' ? 0 : 1, $i['id']])
            ->values()
            ->all();

        $dados = $this->resumo($c, $comoEquipe) + [
            'descricao'          => $c->descricao,
            'contexto_tentando'  => $c->contexto_tentando,
            'contexto_aconteceu' => $c->contexto_aconteceu,
            'contexto_esperado'  => $c->contexto_esperado,
            'resolucao'          => $c->resolucao,
            'resolvido_em'       => $c->resolvido_em?->toIso8601String(),
            'anexos'             => $c->anexos->whereNull('mensagem_id')->map($anexo)->values()->all(),
            'linha_do_tempo'     => $linhaDoTempo,
            'sou_solicitante'    => $this->ehSolicitante($viewer, $c),
        ];

        if ($comoEquipe) {
            $dados['demanda'] = $c->demanda ? [
                'id'        => $c->demanda->id,
                'codigo'    => $c->demanda->codigo,
                'titulo'    => $c->demanda->titulo,
                'status'    => $c->demanda->statusAtual(),
                'concluida' => $c->demanda->statusAtual() === DevDemanda::STATUS_CONCLUIDO,
            ] : null;
            $dados['pode'] = ['atuar' => true, 'converter' => $this->podeConverter($viewer, $c)];
        }

        return $dados;
    }

    // ═══ Anexos ═══

    /** @param UploadedFile[] $arquivos */
    private function salvarAnexos(Chamado $c, ?ChamadoMensagem $m, User $quem, array $arquivos): void
    {
        foreach (array_slice($arquivos, 0, self::MAX_ANEXOS) as $arquivo) {
            $caminho = $arquivo->store("chamados/{$c->id}", 'local');
            $c->anexos()->create([
                'mensagem_id'   => $m?->id,
                'enviado_por'   => $quem->id,
                // Nome só para exibir; no disco o arquivo tem nome gerado.
                'nome_original' => mb_substr(basename(str_replace('\\', '/', $arquivo->getClientOriginalName())), 0, 255),
                'caminho'       => $caminho,
                // Tipo detectado pelo servidor (conteúdo), não o que o navegador declarou.
                'mime'          => $arquivo->getMimeType() ?: 'application/octet-stream',
                'tamanho'       => (int) $arquivo->getSize(),
            ]);
            $this->evento($c, $quem, ChamadoEvento::ANEXO, para: $arquivo->getClientOriginalName(), meta: array_filter(['mensagem_id' => $m?->id]));
        }
    }

    /** O anexo pode ser baixado por quem vê o chamado — e anexo de nota interna, só pela equipe. */
    public function podeBaixar(User $u, Chamado $c, ChamadoAnexo $a): bool
    {
        if ($a->chamado_id !== $c->id || ! $this->podeVer($u, $c)) {
            return false;
        }
        if ($a->mensagem && $a->mensagem->ehInterna()) {
            return $this->podeAtuar($u, $c);
        }

        return true;
    }

    public function arquivo(ChamadoAnexo $a)
    {
        $disco = Storage::disk('local');
        abort_unless($disco->exists($a->caminho), 404);

        $headers = ['X-Content-Type-Options' => 'nosniff', 'Content-Type' => $a->mime];

        return in_array($a->mime, ChamadoAnexo::MIMES_EM_LINHA, true)
            ? $disco->response($a->caminho, $a->nome_original, $headers)
            : $disco->download($a->caminho, $a->nome_original, $headers);
    }

    // ═══ Internos ═══

    /** Troca de status sempre com evento (nunca em silêncio). */
    private function trocarStatus(Chamado $c, User $ator, string $novo): void
    {
        $de = $c->status;
        $c->update(['status' => $novo]);
        $this->evento($c, $ator, ChamadoEvento::STATUS, de: $de, para: $novo, publico: true);
    }

    private function evento(Chamado $c, ?User $ator, string $tipo, ?string $de = null, ?string $para = null, array $meta = [], bool $publico = false): void
    {
        $c->eventos()->create([
            'ator_id' => $ator?->id,
            'tipo'    => $tipo,
            'de'      => $de !== null ? mb_substr($de, 0, 255) : null,
            'para'    => $para !== null ? mb_substr($para, 0, 255) : null,
            'meta'    => $meta ?: null,
            'publico' => $publico,
        ]);
    }

    /** Responsável tem de ser dev ativo — id vindo da tela não é confiável. */
    private function devValido(?int $id): ?User
    {
        if (! $id) {
            return null;
        }
        $dev = User::query()->where('id', $id)->where('active', true)->where('is_dev', true)->first();
        if (! $dev) {
            throw new \RuntimeException('Escolha um dev válido.');
        }

        return $dev;
    }

    private function equipe(): Collection
    {
        return User::query()->where('active', true)->where('is_dev', true)->get();
    }

    private function quemAtende(Chamado $c): Collection
    {
        return $c->responsavel ? collect([$c->responsavel]) : $this->equipe();
    }

    private function solicitanteDe(Chamado $c): Collection
    {
        return collect([$c->solicitante])->filter();
    }

    /** Avisa pelo sino — nunca quem fez a ação. Sai só depois do commit. */
    private function avisar(Collection $para, User $ator, Chamado $c, string $titulo, string $mensagem, bool $paraSolicitante = false): void
    {
        $destino = $para->filter(fn (?User $u) => $u && $u->id !== $ator->id)->unique('id')->values();
        if ($destino->isEmpty()) {
            return;
        }
        $url = $paraSolicitante ? "/chamados/{$c->id}" : "/dev/demandas?aba=chamados&chamado={$c->id}";

        DB::afterCommit(fn () => Notification::send($destino, new ChamadoNotification($titulo, mb_substr($mensagem, 0, 200), $url, $ator->id, ['chamado_id' => $c->id])));
    }
}
