<?php

namespace App\Services\Publicador\Fila;

use App\Models\PubFilaPublicacao;
use App\Models\PubFilaPublicacaoItem;
use App\Models\PubProduto;
use App\Models\PubPublicacao;
use App\Models\PubPublicacaoItem;
use App\Models\PubRascunho;
use App\Models\PubTarefa;
use App\Services\Publicador\ConferenciaService;
use App\Services\Publicador\ProgramasPublicadorService;
use App\Services\Publicador\PublicacaoService;
use App\Support\Portal\AtorDoPortal;
use App\Support\Publicador\ContasLiberadas;
use App\Support\Publicador\EditorEmUso;
use App\Support\Publicador\RegraViolada;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Anda as filas de publicação — o `publicador:fila-publicacao`, todo minuto (10/10/2026, learnings §20).
 *
 * A cada passada:
 *  1. FECHA o item `publicando` de toda fila (viva ou não): a publicação terminou → `publicado`/`parcial`/
 *     `falhou`, com os MLBs criados e a tarefa das alavancas; passou de `publicando_max_min` (40) ainda
 *     rodando → PAUSA a fila com aviso (o item fica `publicando` até a publicação terminar de fato).
 *  2. Em cada fila ATIVA, sem item publicando, dentro da janela e com `proximo_em` vencido, pega o próximo
 *     agendado e faz no BANCO as checagens do `iniciar()` antes de chamá-lo:
 *     - erro de CONTA (sem token, fora da lista de liberadas, outro vendedor) → pausa a fila, nada sai;
 *     - erro do ITEM (conferência vencida, revisão ou plano diferentes do agendado, preço/título do Portal
 *       que mudou, avisos sem "Estou ciente") → `precisa_revisar` e SEGUE para o próximo sem esperar;
 *     - editor do produto aberto → este espera a próxima passada e a fila tenta o seguinte;
 *     marca `publicando` ANTES e chama `PublicacaoService::iniciar($r, AtorDoPortal::daEquipe(quem agendou), ciente)`.
 *  3. Teto GLOBAL de `teto_inicios_por_minuto` (2) inícios por minuto, somando todas as filas.
 *
 * Um produto por vez em cada fila, e o próximo só começa `intervalo_minutos` depois do INÍCIO do anterior (e
 * nunca antes de ele terminar). Nada aqui fala com o Mercado Livre: quem fala é o `PublicarRascunhoJob`.
 */
final class AgendadorDaFila
{
    private const REGRAS_DA_CONTA = ['V-ACC-01', 'V-ACC-03', 'CONTA-LIB'];

    public function __construct(
        private FilaPublicacaoService $filas,
        private ResumoRapidoService $resumo,
        private ProgramasPublicadorService $programas,
        private PublicacaoService $publicacoes,
    ) {}

    /** @return array{fechados: int, iniciados: int, revisar: int, pausadas: int, concluidas: int} */
    public function rodar(): array
    {
        $conta = ['fechados' => 0, 'iniciados' => 0, 'revisar' => 0, 'pausadas' => 0, 'concluidas' => 0];

        foreach (PubFilaPublicacaoItem::query()->where('status', PubFilaPublicacaoItem::PUBLICANDO)->orderBy('id')->get() as $item) {
            try {
                if ($this->fechar($item, $conta)) {
                    $conta['fechados']++;
                }
            } catch (\Throwable $e) {
                Log::error("[Publicador] Fila: não foi possível fechar o item {$item->id}: {$e->getMessage()}");
            }
        }

        $filas = PubFilaPublicacao::query()->where('status', PubFilaPublicacao::ATIVA)->orderBy('proximo_em')->orderBy('id')->get();
        foreach ($filas as $fila) {
            try {
                $this->andar($fila, $conta);
            } catch (\Throwable $e) {
                Log::error("[Publicador] Fila {$fila->id}: a passada quebrou: {$e->getMessage()}");
            }
        }

        return $conta;
    }

    // ═══ 1. Fechar o item publicando ═════════════════════════════════════════

    private function fechar(PubFilaPublicacaoItem $item, array &$conta): bool
    {
        $p = $item->publicacao_id !== null ? PubPublicacao::find($item->publicacao_id) : null;
        if ($p === null) {
            // O processo morreu entre marcar `publicando` e o `iniciar()`: adota a publicação se ela nasceu.
            $p = $item->rascunho_id === null || $item->iniciado_em === null ? null : PubPublicacao::query()
                ->where('rascunho_id', $item->rascunho_id)->where('iniciada_em', '>=', $item->iniciado_em->copy()->subSeconds(5))
                ->latest('id')->first();
            if ($p === null) {
                if ($item->iniciado_em === null || $item->iniciado_em->lt(now()->subMinutes(5))) {
                    $this->finalizar($item, PubFilaPublicacaoItem::FALHOU, 'A publicação não chegou a começar e nada foi enviado ao Mercado Livre. Agende de novo.');
                    $this->filas->concluirSeVazia($item->fila()->first());

                    return true;
                }

                return false;
            }
            $item->update(['publicacao_id' => $p->id]);
        }

        if ($p->status === PubPublicacao::RUNNING) {
            $limite = max(5, (int) config('publicador.fila_publicacao.publicando_max_min', 40));
            $fila = $item->fila()->first();
            if ($fila?->status === PubFilaPublicacao::ATIVA && $item->iniciado_em !== null && $item->iniciado_em->lt(now()->subMinutes($limite))) {
                $nome = (string) ($item->resumo['nome'] ?? "#{$item->produto_id}");
                $this->pausar($fila, "A publicação de {$nome} passou de {$limite} minutos sem terminar. Confira o produto no editor antes de retomar a fila.");
                $conta['pausadas']++;
            }

            return false;
        }

        $criados = $p->itens()->where('status', PubPublicacaoItem::CREATED)->get(['ml_item_id', 'listing_type_id', 'variante_chave', 'avisos']);
        $status = match ($p->status) {
            PubPublicacao::PUBLISHED => PubFilaPublicacaoItem::PUBLICADO,
            PubPublicacao::PARTIALLY_PUBLISHED => PubFilaPublicacaoItem::PARCIAL,
            default => $criados->isNotEmpty() ? PubFilaPublicacaoItem::PARCIAL : PubFilaPublicacaoItem::FALHOU,
        };
        $motivo = null;
        if ($status !== PubFilaPublicacaoItem::PUBLICADO) {
            $falhou = $p->itens()->where('status', PubPublicacaoItem::FAILED)->get(['avisos'])->first(fn ($i) => ! empty($i->avisos['mensagem']));
            $motivo = $p->conta_snapshot['motivo'] ?? $falhou?->avisos['mensagem']
                ?? 'O Mercado Livre recusou '.($status === PubFilaPublicacaoItem::PARCIAL ? 'parte dos anúncios' : 'a publicação').': veja o motivo no editor.';
        }

        $resumo = [...(array) $item->resumo, 'mlbs' => $criados->map(fn ($i) => [
            'ml_item_id' => $i->ml_item_id,
            'listing_type_id' => $i->listing_type_id,
            'variante_chave' => $i->variante_chave,
            'permalink' => $i->avisos['estado']['permalink'] ?? null,
        ])->values()->all()];
        $tarefa = $this->tarefaDoRascunho($item->rascunho_id);
        if ($tarefa !== null) {
            $resumo['tarefa_id'] = $tarefa;
        }

        $item->update(['status' => $status, 'produto_ativo' => null, 'concluido_em' => now(), 'motivo' => $motivo === null ? null : mb_substr((string) $motivo, 0, 500), 'resumo' => $resumo]);
        Log::info("[Publicador] Fila {$item->fila_id}: produto {$item->produto_id} {$status} ({$criados->count()} anúncio(s) criado(s)).");
        $this->filas->concluirSeVazia($item->fila()->first());

        return true;
    }

    // ═══ 2. Andar a fila ═════════════════════════════════════════════════════

    private function andar(PubFilaPublicacao $fila, array &$conta): void
    {
        $fila->refresh();
        if ($fila->status !== PubFilaPublicacao::ATIVA) {
            return;
        }
        if ($fila->itens()->where('status', PubFilaPublicacaoItem::PUBLICANDO)->exists()) {
            return; // um produto por vez: espera este terminar
        }
        if (! $fila->itens()->where('status', PubFilaPublicacaoItem::AGENDADO)->exists()) {
            $this->filas->concluirSeVazia($fila);
            $conta['concluidas'] += $fila->fresh()->status === PubFilaPublicacao::CONCLUIDA ? 1 : 0;

            return;
        }
        if ($fila->proximo_em !== null && $fila->proximo_em->isFuture()) {
            return;
        }
        if (! self::naJanela($fila, CarbonImmutable::now())) {
            return;
        }
        $quem = FilaPublicacaoService::autorValido($fila);
        if ($quem === null) {
            $this->pausar($fila, 'Quem agendou esta fila não está mais ativo no sistema. Retome a fila para ela seguir em seu nome.');
            $conta['pausadas']++;

            return;
        }
        $alvo = $this->programas->resolver($fila->conta_chave);
        if ($alvo === null) {
            $this->pausar($fila, 'Esta empresa saiu do Publicador (arquivada ou sem programa). Nada foi enviado.');
            $conta['pausadas']++;

            return;
        }

        $esperando = [];
        while (true) {
            $item = $fila->itens()->where('status', PubFilaPublicacaoItem::AGENDADO)
                ->when($esperando !== [], fn ($q) => $q->whereNotIn('id', $esperando))
                ->reorder()->orderBy('posicao')->orderBy('id')->first();
            if ($item === null) {
                if ($esperando === []) {
                    $this->filas->concluirSeVazia($fila);
                }

                return;
            }

            $checagem = $this->checar($fila, $alvo, $item);
            if ($checagem['tipo'] === 'conta') {
                $this->pausar($fila, $checagem['motivo']);
                $conta['pausadas']++;

                return;
            }
            if ($checagem['tipo'] === 'item') {
                $this->finalizar($item, $checagem['status'], $checagem['motivo']);
                $conta['revisar']++;

                continue; // nada foi publicado: segue sem esperar o intervalo
            }
            if ($checagem['tipo'] === 'esperar') {
                $esperando[] = $item->id;

                continue;
            }

            if (! $this->reservarInicio()) {
                return; // teto global do minuto: a próxima passada continua
            }
            if ($this->iniciar($fila, $item, $checagem['rascunho'], $quem, $conta)) {
                $conta['iniciados']++;

                return; // um produto por passada em cada fila
            }
            if ($fila->fresh()->status !== PubFilaPublicacao::ATIVA) {
                return;
            }
        }
    }

    /**
     * As checagens do `iniciar()`, no banco, ANTES de marcar o item.
     *
     * @return array{tipo: 'ok', rascunho: PubRascunho}|array{tipo: 'conta', motivo: string}|array{tipo: 'item', status: string, motivo: string}|array{tipo: 'esperar'}
     */
    private function checar(PubFilaPublicacao $fila, array $alvo, PubFilaPublicacaoItem $itemFila): array
    {
        $item = fn (string $status, string $motivo) => ['tipo' => 'item', 'status' => $status, 'motivo' => $motivo];

        $r = $itemFila->rascunho_id !== null ? PubRascunho::find($itemFila->rascunho_id) : null;
        $p = $itemFila->produto_id !== null ? PubProduto::with(['mlbEmpresa.mlToken', 'company.mlToken'])->find($itemFila->produto_id) : null;
        if ($r === null || $p === null || (int) $r->produto_id !== (int) $p->id) {
            return $item(PubFilaPublicacaoItem::PULADO, 'O produto foi apagado do Publicador.');
        }
        // Isolamento: o produto continua sendo desta conta (as âncoras da fila).
        $daConta = ($fila->mlb_empresa_id !== null && (int) $p->mlb_empresa_id === (int) $fila->mlb_empresa_id)
            || ($fila->company_id !== null && (int) $p->company_id === (int) $fila->company_id);
        if (! $daConta) {
            return $item(PubFilaPublicacaoItem::PULADO, 'O produto não é mais desta conta.');
        }
        if ($r->status === PubRascunho::PUBLISHED) {
            return $item(PubFilaPublicacaoItem::PULADO, 'Já estava publicado.');
        }
        if ($r->status === PubRascunho::PUBLISHING) {
            return $item(PubFilaPublicacaoItem::PULADO, 'Começou a ser publicado fora da fila: acompanhe no editor.');
        }

        // Conta: erro que vale para a fila inteira.
        $ancora = $p->contaOuNula();
        if ($ancora === null) {
            return ['tipo' => 'conta', 'motivo' => 'A conta do Mercado Livre desta empresa precisa ser reconectada. Reconecte pelo Onboarding e retome a fila. Nada foi enviado.'];
        }
        if (! ContasLiberadas::libera($ancora)) {
            return ['tipo' => 'conta', 'motivo' => 'A publicação não está liberada para esta conta do Mercado Livre. A fila parou sem enviar nada.'];
        }

        // Conferência: erro deste produto.
        $v = $r->validacoes()->where('camada', 'L3')->latest('id')->first();
        if ($v === null || ! in_array($v->resultado, [ConferenciaService::OK, ConferenciaService::AVISOS], true) || (int) $v->revisao !== (int) $r->revisao || ! $v->plano_hash) {
            return $item(PubFilaPublicacaoItem::PRECISA_REVISAR, 'A conferência deste produto não vale mais para a versão de agora: confira de novo e agende outra vez.');
        }
        if ((int) $r->revisao !== (int) $itemFila->revisao || $v->plano_hash !== $itemFila->plano_hash) {
            return $item(PubFilaPublicacaoItem::PRECISA_REVISAR, 'O produto mudou depois de agendado: confira de novo e agende outra vez.');
        }
        if ($v->resultado === ConferenciaService::AVISOS && ! $itemFila->ciente) {
            return $item(PubFilaPublicacaoItem::PRECISA_REVISAR, 'O Mercado Livre deu avisos na conferência e ninguém marcou "Estou ciente".');
        }
        $seller = (string) ($v->respostas_ml['conta']['sellerId'] ?? '');
        if ($seller === '') {
            return $item(PubFilaPublicacaoItem::PRECISA_REVISAR, 'A conferência não registrou a conta do Mercado Livre: confira de novo e agende outra vez.');
        }
        if ($seller !== (string) ($ancora->mlToken?->ml_user_id ?? '')) {
            return ['tipo' => 'conta', 'motivo' => 'A conexão desta empresa agora é de outro vendedor do Mercado Livre. A fila parou sem enviar nada: confira os produtos de novo.'];
        }

        // O que vem do Portal (preço da Precificação, título planejado) não pode ter mudado desde o agendamento.
        $guardado = $itemFila->resumo['digital'] ?? null;
        if (is_string($guardado) && $guardado !== '' && $this->resumo->digitalDe($alvo, (int) $p->id) !== $guardado) {
            return $item(PubFilaPublicacaoItem::PRECISA_REVISAR, 'O preço ou o título que vem do Portal mudou depois da conferência: confira de novo e agende outra vez.');
        }

        // Gente com o editor aberto: não publica por baixo dela; este espera a próxima passada.
        if (EditorEmUso::emUso((int) $p->id)) {
            return ['tipo' => 'esperar'];
        }

        return ['tipo' => 'ok', 'rascunho' => $r];
    }

    private function iniciar(PubFilaPublicacao $fila, PubFilaPublicacaoItem $item, PubRascunho $r, $quem, array &$conta): bool
    {
        $intervalo = max(1, (int) $fila->intervalo_minutos);
        // Marca `publicando` ANTES do `iniciar()`, e só se a fila continua ativa e o item continua agendado: o
        // "Cancelar"/"Tirar da fila" da tela pode ter chegado entre a checagem e aqui (UPDATE condicional).
        $marcou = DB::transaction(function () use ($fila, $item, $intervalo) {
            if (! PubFilaPublicacao::query()->whereKey($fila->id)->where('status', PubFilaPublicacao::ATIVA)->lockForUpdate()->exists()) {
                return false;
            }
            $n = PubFilaPublicacaoItem::query()->whereKey($item->id)->where('status', PubFilaPublicacaoItem::AGENDADO)
                ->update(['status' => PubFilaPublicacaoItem::PUBLICANDO, 'iniciado_em' => now(), 'motivo' => null, 'updated_at' => now()]);
            if ($n !== 1) {
                return false;
            }
            $fila->update(['proximo_em' => now()->addMinutes($intervalo), 'iniciada_em' => $fila->iniciada_em ?? now()]);

            return true;
        });
        if (! $marcou) {
            return false;
        }
        $item->refresh();

        try {
            $p = $this->publicacoes->iniciar($r, AtorDoPortal::daEquipe($quem), cienteDosAvisos: (bool) $item->ciente);
            $item->update(['publicacao_id' => $p->id]);
            Log::info("[Publicador] Fila {$fila->id}: publicação {$p->id} do produto {$item->produto_id} começou (item {$item->id}).");

            return true;
        } catch (RegraViolada $e) {
            // Nada foi publicado: a fila não precisa esperar o intervalo por este.
            $fila->update(['proximo_em' => now()]);
            if (in_array($e->regra, self::REGRAS_DA_CONTA, true)) {
                $item->update(['status' => PubFilaPublicacaoItem::AGENDADO, 'iniciado_em' => null]);
                $this->pausar($fila, $e->getMessage());
                $conta['pausadas']++;

                return false;
            }
            if ($e->regra === 'RN-93') {
                $this->finalizar($item, PubFilaPublicacaoItem::PULADO, 'Já estava sendo publicado fora da fila: acompanhe no editor.');

                return false;
            }
            $this->finalizar($item, PubFilaPublicacaoItem::PRECISA_REVISAR, $e->getMessage());
            $conta['revisar']++;

            return false;
        } catch (\Throwable $e) {
            Log::error("[Publicador] Fila {$fila->id}: o início da publicação do produto {$item->produto_id} quebrou: {$e->getMessage()}");
            // A publicação pode ter nascido antes da quebra (ex.: o despacho do Job falhou): o item a acompanha, e o
            // fechamento (ou a pausa dos 40 min) resolve. Sem publicação, nada saiu: falhou e a fila segue.
            $criada = $item->iniciado_em === null ? null : PubPublicacao::query()->where('rascunho_id', $r->id)
                ->where('iniciada_em', '>=', $item->iniciado_em->copy()->subSeconds(5))->latest('id')->first();
            if ($criada !== null) {
                $item->update(['publicacao_id' => $criada->id]);

                return true;
            }
            $fila->update(['proximo_em' => now()]);
            $this->finalizar($item, PubFilaPublicacaoItem::FALHOU, 'Não foi possível começar a publicação: tente agendar de novo.');

            return false;
        }
    }

    // ═══ Apoio ═══════════════════════════════════════════════════════════════

    private function finalizar(PubFilaPublicacaoItem $item, string $status, ?string $motivo): void
    {
        $item->update(['status' => $status, 'produto_ativo' => null, 'concluido_em' => now(), 'motivo' => $motivo === null ? null : mb_substr($motivo, 0, 500)]);
        Log::info("[Publicador] Fila {$item->fila_id}: produto {$item->produto_id} {$status}".($motivo ? " — {$motivo}" : '').'.');
    }

    private function pausar(PubFilaPublicacao $fila, string $motivo): void
    {
        $fila->update(['status' => PubFilaPublicacao::PAUSADA, 'motivo_pausa' => mb_substr($motivo, 0, 500)]);
        Log::warning("[Publicador] Fila {$fila->id} da conta {$fila->conta_chave} pausada: {$motivo}");
    }

    /** Uma vaga no teto global do minuto (todas as filas juntas). */
    private function reservarInicio(): bool
    {
        $teto = max(1, (int) config('publicador.fila_publicacao.teto_inicios_por_minuto', 2));
        $chave = 'publicador:fila:inicios:'.now()->format('YmdHi');
        Cache::add($chave, 0, 180);
        if ((int) Cache::increment($chave) > $teto) {
            Cache::decrement($chave);

            return false;
        }

        return true;
    }

    /** A tarefa das alavancas do rascunho (a mais recente), se a tabela existir. */
    private function tarefaDoRascunho(?int $rascunhoId): ?int
    {
        if ($rascunhoId === null) {
            return null;
        }
        try {
            $id = PubTarefa::query()->where('rascunho_id', $rascunhoId)->latest('id')->value('id');

            return $id !== null ? (int) $id : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** Dentro da janela da fila (fuso de São Paulo)? Sem janela, sempre. Início > fim = passa da meia-noite. */
    public static function naJanela(PubFilaPublicacao $fila, CarbonImmutable $quando): bool
    {
        $janela = $fila->janela();
        if ($janela === null) {
            return true;
        }
        $agora = $quando->setTimezone(config('app.timezone'))->format('H:i');

        return $janela['inicio'] < $janela['fim']
            ? $agora >= $janela['inicio'] && $agora < $janela['fim']
            : $agora >= $janela['inicio'] || $agora < $janela['fim'];
    }

    /** O primeiro instante a partir de `$t` em que a fila pode começar um produto. */
    public static function proximoNaJanela(PubFilaPublicacao $fila, CarbonImmutable $t): CarbonImmutable
    {
        $janela = $fila->janela();
        if ($janela === null || self::naJanela($fila, $t)) {
            return $t;
        }
        [$h, $m] = array_map('intval', explode(':', $janela['inicio']));
        $local = $t->setTimezone(config('app.timezone'));
        $inicio = $local->setTime($h, $m);
        if ($inicio->lte($local)) {
            $inicio = $inicio->addDay();
        }

        return $inicio;
    }
}
