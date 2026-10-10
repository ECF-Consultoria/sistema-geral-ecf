<?php

namespace App\Services\Publicador\Alavancas;

use App\Jobs\Publicador\CriarPromocaoAutomaticaJob;
use App\Models\Company;
use App\Models\Configuracao;
use App\Models\MlbEmpresa;
use App\Models\PubAlavancaEscrita;
use App\Models\PubPromocaoAutomatica;
use App\Models\PubPublicacao;
use App\Models\PubPublicacaoItem;
use App\Models\PubRascunho;
use App\Models\PubTarefa;
use App\Models\User;
use App\Services\Publicador\Alavancas\Acoes\CriarDescontoIndividual;
use App\Services\Publicador\DadosEfetivosService;
use App\Services\Publicador\RascunhoRepository;
use App\Services\Publicador\Tarefas\TarefasPosPublicacao;
use App\Support\Publicador\AlavancasLiberadas;
use App\Support\Publicador\PrecoDaPromocao;
use Illuminate\Database\QueryException;
use Illuminate\Queue\SyncQueue;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

/**
 * Promoção automática pós-publicação (10/10/2026, decisão do usuário): publicou pelo Publicador, cada
 * anúncio CRIADO ganha sozinho o desconto individual (PRICE_DISCOUNT) com o preço de promoção da
 * Precificação do Portal, por 14 dias (o máximo do ML, contando as duas pontas), e a promoção se
 * renova sozinha enquanto o anúncio estiver ativo e com o mesmo preço. É a EXCEÇÃO consciente à D-04
 * da Fase 166 (toda escrita das Alavancas com prévia assinada + confirmação humana): aqui não há tela,
 * quem decidiu foi o usuário. A escrita continua pelo ÚNICO caminho, o `EscritorAlavancas` (trava das
 * Alavancas, vendedor conferido no `/users/me`, linha do histórico antes do HTTP, nada se repete às
 * cegas) — e um teste de fonte proíbe `Http::`/escrita fora dele nesta pasta.
 *
 * Três entradas:
 *  - `agendar()` — no fim da publicação (`PublicacaoService`, logo depois de abrir a tarefa): um ciclo
 *    `agendada` por item criado com promoção calculável, e o Job com atraso. Conta fora da lista das
 *    Alavancas não recebe nada no ML: o ciclo nasce `recusada` e a tarefa orienta a fazer à mão;
 *  - `executar()` — o `CriarPromocaoAutomaticaJob`: lê o anúncio (ainda não ativo → volta depois, com
 *    espera crescente, até `tentativas_max`), confere conta e preço e escreve;
 *  - `renovar()` — o `publicador:promocoes-renovar` (00:05 em São Paulo): ciclo ativo cujo fim passou
 *    vira o ciclo seguinte; preço mudado ou anúncio fechado encerram e nada mais. Também reenvia o Job
 *    que se perdeu e fecha o envio interrompido.
 *
 * Segurança: unique (ml_item_id, ciclo), uma trava por anúncio, ALAV-DESC-08 (nunca dois descontos no
 * mesmo item: quem confere é a própria ação), e a escrita só sai na conta que publicou (`conta_chave`
 * e o vendedor fixados no clique em Publicar; o multiget só devolve anúncio do vendedor da conta).
 */
class PromocaoAutomaticaService
{
    /** Em `configuracoes`: o id do usuário que assina a escrita quando quem publicou não serve mais. */
    public const CHAVE_USUARIO_SISTEMA = 'publicador_usuario_sistema';

    private const SITUACOES = [
        'active' => 'ativo', 'paused' => 'pausado', 'under_review' => 'em revisão', 'closed' => 'encerrado',
        'inactive' => 'inativo', 'payment_required' => 'aguardando pagamento', 'not_yet_active' => 'ainda não ativo',
    ];

    public function __construct(
        private DadosEfetivosService $efetivos,
        private RascunhoRepository $repo,
        private ContextoAlavancas $contexto,
        private EscritorAlavancas $escritor,
        private TarefasPosPublicacao $tarefas,
    ) {}

    public static function chaveDaTrava(string $mlb): string
    {
        return "publicador:promocao-automatica:{$mlb}";
    }

    // ═══ Gatilho ═══════════════════════════════════════════════════════════

    /**
     * Um ciclo por item CRIADO desta publicação com promoção calculável (`PrecoDaPromocao`). Rodar duas
     * vezes não duplica (unique por anúncio e ciclo). Nada aqui fala com o ML.
     *
     * @return list<PubPromocaoAutomatica> os ciclos criados agora
     */
    public function agendar(PubPublicacao $p): array
    {
        if ($p->status === PubPublicacao::RUNNING) {
            return [];
        }
        $criados = PubPublicacaoItem::query()->where('publicacao_id', $p->id)
            ->where('status', PubPublicacaoItem::CREATED)->whereNotNull('ml_item_id')
            ->orderBy('indice')->get();
        if ($criados->isEmpty()) {
            return [];
        }

        $r = PubRascunho::query()->with('produto')->find($p->rascunho_id);
        $produto = $r?->produto;
        if ($r === null || $produto === null) {
            return [];
        }

        $contaChave = self::contaDaPublicacao($p, $produto->company_id, $produto->mlb_empresa_id);
        $liberada = AlavancasLiberadas::liberaChave($contaChave);
        $portal = $this->portalDoRascunho($r);
        $hoje = DatasDoMl::hoje();

        $novos = [];
        foreach ($criados as $item) {
            $preco = isset($item->payload['price']) ? (float) $item->payload['price'] : null;
            $calc = PrecoDaPromocao::calcular($preco, $portal[(string) $item->variante_chave][(string) $item->listing_type_id] ?? null);
            if (! $calc['calculavel']) {
                Log::info("[Publicador] {$item->ml_item_id} publicado sem promoção automática: ".PrecoDaPromocao::MOTIVOS[$calc['motivo']].'.');

                continue;
            }

            $ciclo = $this->criarCiclo([
                'publicacao_id' => $p->id,
                'publicacao_item_id' => $item->id,
                'rascunho_id' => $r->id,
                'produto_id' => $produto->id,
                'conta_chave' => mb_substr($contaChave, 0, 60),
                'company_id' => $produto->company_id,
                'mlb_empresa_id' => $produto->mlb_empresa_id,
                'ml_item_id' => (string) $item->ml_item_id,
                'listing_type' => (string) $item->listing_type_id,
                'variante_chave' => $item->variante_chave,
                'preco_publicado' => $preco,
                'preco_promocao' => $calc['preco'],
                'percentual' => $calc['percentual'],
                'ciclo' => 1,
                'inicio' => $hoje->format('Y-m-d'),
                'fim' => $hoje->addDays(PrecoDaPromocao::DIAS - 1)->format('Y-m-d'),
                'status' => $liberada ? PubPromocaoAutomatica::AGENDADA : PubPromocaoAutomatica::RECUSADA,
                'motivo' => $liberada
                    ? 'Aguardando o anúncio ficar ativo no Mercado Livre.'
                    : 'Conta não liberada para a promoção automática: as Alavancas não escrevem nesta conta do Mercado Livre.',
                'tentativas' => 0,
            ]);
            if ($ciclo !== null) {
                $novos[] = $ciclo;
            }
        }

        // Depois de gravar todos: o Job (conta liberada) ou a orientação na tarefa (conta não liberada).
        foreach ($novos as $ciclo) {
            if ($ciclo->status === PubPromocaoAutomatica::AGENDADA) {
                $this->despachar($ciclo, (int) config('publicador.promocao_automatica.atraso_min', 3));
            } else {
                Log::info("[Publicador] promoção automática {$ciclo->id} ({$ciclo->ml_item_id}) não escrita: conta {$ciclo->conta_chave} fora da lista das Alavancas.");
                $this->orientar($ciclo, renovacao: false);
            }
        }
        if ($novos !== []) {
            Log::info("[Publicador] publicação {$p->id}: ".count($novos).' promoção(ões) automática(s) '.($liberada ? 'agendada(s)' : 'só orientada(s) na tarefa').'.');
        }

        return $novos;
    }

    /**
     * variante_chave → listing_type_id → {anunciado, minimo, sem_frete}: a MESMA leitura do editor e da
     * conferência (`comEfetivosDe`), lida agora.
     *
     * @return array<string, array<string, array>>
     */
    private function portalDoRascunho(PubRascunho $r): array
    {
        $snapshot = $this->repo->snapshot($r)->comEfetivosDe($this->efetivos->daProduto($r->produto));
        $mapa = [];
        foreach ($snapshot->variantes as $v) {
            $mapa[$v->chave] = (array) ($v->dados['portal'] ?? []);
        }

        return $mapa;
    }

    /** A conta fixada no clique em Publicar (CR-B01); publicação antiga sem ela cai na âncora do produto. */
    private static function contaDaPublicacao(PubPublicacao $p, ?int $companyId, ?int $mlbEmpresaId): string
    {
        $fixada = (string) ($p->ator['conta']['chave'] ?? '');
        if ($fixada !== '') {
            return $fixada;
        }

        return $companyId !== null ? "company-{$companyId}" : ($mlbEmpresaId !== null ? "empresa-{$mlbEmpresaId}" : '');
    }

    /** Nulo = o ciclo já existia (gatilho repetido): o unique (ml_item_id, ciclo) é a idempotência. */
    private function criarCiclo(array $campos): ?PubPromocaoAutomatica
    {
        $existe = fn () => PubPromocaoAutomatica::query()->where('ml_item_id', $campos['ml_item_id'])->where('ciclo', $campos['ciclo'])->exists();
        if ($existe()) {
            return null;
        }

        try {
            return PubPromocaoAutomatica::create($campos);
        } catch (QueryException $e) {
            // Corrida no `pubpromo_item_ciclo_uq`: outro processo criou o mesmo ciclo.
            if ($existe()) {
                return null;
            }
            throw $e;
        }
    }

    /** Agenda o Job. Fila `sync` não despacha (rodaria dentro da publicação): a varredura diária pega. */
    private function despachar(PubPromocaoAutomatica $ciclo, int $minutos): void
    {
        $quando = now()->addMinutes(max(0, $minutos));
        $ciclo->update(['proxima_tentativa_em' => $quando]);

        if (Queue::connection() instanceof SyncQueue) {
            Log::info("[Publicador] promoção automática {$ciclo->id} ({$ciclo->ml_item_id}) agendada sem Job: a fila é sync; a varredura diária a envia.");

            return;
        }

        CriarPromocaoAutomaticaJob::dispatch($ciclo->id)->delay($quando)->afterCommit();
    }

    // ═══ O Job ═════════════════════════════════════════════════════════════

    /** Uma tentativa de criar o ciclo `agendada` no ML. Um de cada vez por anúncio. */
    public function executar(int $id): void
    {
        $ciclo = PubPromocaoAutomatica::query()->find($id);
        if ($ciclo === null || $ciclo->status !== PubPromocaoAutomatica::AGENDADA) {
            return;
        }

        $trava = Cache::lock(self::chaveDaTrava($ciclo->ml_item_id), 300);
        if (! $trava->get()) {
            Log::info("[Publicador] promoção automática {$ciclo->id}: outro processo cuida de {$ciclo->ml_item_id} agora.");

            return;
        }

        try {
            $ciclo->refresh();
            if ($ciclo->status === PubPromocaoAutomatica::AGENDADA) {
                $this->tentar($ciclo);
            }
        } finally {
            $trava->release();
        }
    }

    private function tentar(PubPromocaoAutomatica $ciclo): void
    {
        if (! AlavancasLiberadas::liberaChave($ciclo->conta_chave)) {
            $this->recusar($ciclo, 'Conta não liberada para a promoção automática: as Alavancas não escrevem nesta conta do Mercado Livre.');

            return;
        }
        $ator = $this->ator($ciclo);
        if ($ator === null) {
            $this->recusar($ciclo, 'Sem usuário para registrar a escrita: quem publicou não está mais ativo e o usuário de sistema do Publicador não foi configurado.');

            return;
        }
        $conta = $this->conta($ciclo);
        if ($conta === null) {
            $this->recusar($ciclo, 'A conta do Mercado Livre desta empresa está sem conexão ativa: nada foi enviado.');

            return;
        }
        // Nunca escreve em anúncio de outra conta: a âncora com token e o vendedor têm de ser os do clique.
        $vendedor = (string) ($ciclo->publicacao?->ator['conta']['seller'] ?? '');
        if ($conta->chaveConta() !== $ciclo->conta_chave || ($vendedor !== '' && $vendedor !== $conta->sellerId)) {
            $this->recusar($ciclo, 'A conexão desta empresa agora é de outra conta do Mercado Livre: nada foi enviado.');

            return;
        }

        $ciclo->update(['tentativas' => min(255, $ciclo->tentativas + 1)]);
        $leituras = LeiturasDaAcao::para($conta);
        try {
            $anuncio = $leituras->produto($ciclo->ml_item_id);
        } catch (\Throwable $e) {
            Log::warning("[Publicador] promoção automática {$ciclo->id}: leitura de {$ciclo->ml_item_id} falhou: {$e->getMessage()}");
            $this->reagendar($ciclo, 'sem resposta do Mercado Livre');

            return;
        }

        if ($anuncio === null) {
            $this->recusar($ciclo, 'O anúncio não foi encontrado nesta conta do Mercado Livre: nada foi enviado.');

            return;
        }
        $status = (string) ($anuncio['status'] ?? '');
        if (in_array($status, ['closed', 'inactive'], true)) {
            $this->fechar($ciclo, PubPromocaoAutomatica::CANCELADA, 'O anúncio foi encerrado no Mercado Livre: não há o que promover.');

            return;
        }
        if ($status !== 'active') {
            $this->reagendar($ciclo, self::SITUACOES[$status] ?? ($status !== '' ? $status : 'sem situação'));

            return;
        }
        if (! self::mesmoPreco($anuncio, (float) $ciclo->preco_publicado)) {
            $this->recusar($ciclo, 'O preço do anúncio mudou desde a publicação (era '.PrecoDaPromocao::reais((float) $ciclo->preco_publicado)
                .', agora '.PrecoDaPromocao::reais((float) ($anuncio['preco'] ?? 0)).'): a promoção calculada não vale mais.');

            return;
        }

        // O Job que só rodou noutro dia começa hoje: o ML recusa início no passado (ALAV-DESC-04).
        $hoje = DatasDoMl::hoje();
        if ((string) $ciclo->inicio < $hoje->format('Y-m-d')) {
            $ciclo->update(['inicio' => $hoje->format('Y-m-d'), 'fim' => $hoje->addDays(PrecoDaPromocao::DIAS - 1)->format('Y-m-d')]);
        }

        $ciclo->update(['status' => PubPromocaoAutomatica::ENVIANDO, 'motivo' => null, 'proxima_tentativa_em' => null]);
        $acao = (new CriarDescontoIndividual($conta, [
            'item_id' => $ciclo->ml_item_id,
            'deal_price' => round((float) $ciclo->preco_promocao, 2),
            'start_date' => (string) $ciclo->inicio,
            'finish_date' => (string) $ciclo->fim,
        ]))->usarLeituras($leituras);
        $escrita = $this->escritor->executar($acao, $ator);
        $ciclo->update(['escrita_id' => $escrita->id]);

        match (true) {
            $escrita->resultado === PubAlavancaEscrita::OK => $this->ativar($ciclo),
            $escrita->erro_codigo === 'ALAV-DESC-08' => $this->fechar($ciclo, PubPromocaoAutomatica::CANCELADA,
                'O anúncio já tinha desconto individual no Mercado Livre: nada foi criado por cima.'),
            $escrita->resultado === PubAlavancaEscrita::INCERTO => $this->recusar($ciclo,
                'O Mercado Livre não confirmou a criação. Confira no Seller Center se a promoção existe antes de criar outra.'),
            default => $this->recusar($ciclo, self::motivoDoMl($escrita)),
        };
    }

    /** Quem assina a escrita: quem publicou (da equipe e ativo) ou o usuário de sistema configurado. */
    private function ator(PubPromocaoAutomatica $ciclo): ?User
    {
        $a = (array) ($ciclo->publicacao?->ator ?? []);
        if (! empty($a['equipe']) && isset($a['id'])) {
            $quem = User::query()->where('active', true)->find((int) $a['id']);
            if ($quem !== null) {
                return $quem;
            }
        }
        $sistema = (int) Configuracao::get(self::CHAVE_USUARIO_SISTEMA, 0);

        return $sistema > 0 ? User::query()->where('active', true)->find($sistema) : null;
    }

    /** A conta das Alavancas pelas âncoras do produto (a que tem token). Sem token = nula. */
    private function conta(PubPromocaoAutomatica $ciclo): ?ContaAlavanca
    {
        $empresa = $ciclo->mlb_empresa_id !== null ? MlbEmpresa::query()->find($ciclo->mlb_empresa_id) : null;
        $company = $ciclo->company_id !== null ? Company::query()->find($ciclo->company_id) : null;
        if ($empresa === null && $company === null) {
            return null;
        }

        return $this->contexto->daTela(['mlb_empresa' => $empresa, 'company' => $company, 'chave' => $ciclo->conta_chave]);
    }

    /**
     * O anúncio continua com o preço publicado? Com outra promoção no ar o ML mostra o preço com
     * desconto e o cheio em `original_price`: qualquer um dos dois igual ao publicado vale.
     */
    private static function mesmoPreco(array $anuncio, float $preco): bool
    {
        foreach ([$anuncio['preco'] ?? null, $anuncio['preco_original'] ?? null] as $valor) {
            if ($valor !== null && abs((float) $valor - $preco) < 0.005) {
                return true;
            }
        }

        return false;
    }

    /**
     * A recusa do ML, legível. O `EscritorAlavancas` já traduz o que conhece (`MapeadorErroAlavanca`);
     * aqui só se explica, em pt-BR, o que o ML costuma recusar no desconto individual. [ASSUMED] as
     * palavras-chave: a #459 não tinha anúncio ativo para provar os textos reais (learnings §12).
     */
    private static function motivoDoMl(PubAlavancaEscrita $escrita): string
    {
        $texto = trim((string) $escrita->mensagem);
        $original = mb_strtolower($texto.' '.json_encode($escrita->resposta ?? [], JSON_UNESCAPED_UNICODE));
        $explicacao = match (true) {
            str_contains($original, 'reputation') || str_contains($original, 'reputação') => 'A reputação da conta não permite desconto agora.',
            str_contains($original, 'sold') || str_contains($original, 'sales') || str_contains($original, 'vendas') => 'O Mercado Livre exige vendas no anúncio antes do desconto.',
            str_contains($original, 'deal') || str_contains($original, 'campaign') || str_contains($original, 'campanha') => 'O anúncio está numa campanha do Mercado Livre.',
            default => null,
        };
        $base = $texto !== '' ? $texto : 'O Mercado Livre recusou a promoção.';

        return $explicacao !== null ? "{$explicacao} {$base}" : $base;
    }

    /** O anúncio ainda não está ativo: volta depois, com espera crescente; esgotadas as tentativas, recusa. */
    private function reagendar(PubPromocaoAutomatica $ciclo, string $situacao): void
    {
        $esperas = array_values(array_map('intval', (array) config('publicador.promocao_automatica.esperas_min', [5, 10, 20, 40, 60, 120, 240])));
        $maximo = (int) config('publicador.promocao_automatica.tentativas_max', 8);
        if ($ciclo->tentativas >= $maximo || $esperas === []) {
            $this->recusar($ciclo, "O anúncio não ficou ativo a tempo para a promoção automática (situação no Mercado Livre: {$situacao}).");

            return;
        }

        $minutos = $esperas[min(max(0, $ciclo->tentativas - 1), count($esperas) - 1)];
        $ciclo->update(['status' => PubPromocaoAutomatica::AGENDADA, 'motivo' => "Aguardando o anúncio ficar ativo (agora: {$situacao})."]);
        Log::info("[Publicador] promoção automática {$ciclo->id} ({$ciclo->ml_item_id}): anúncio {$situacao}, nova tentativa em {$minutos} min ({$ciclo->tentativas}/{$maximo}).");
        $this->despachar($ciclo, $minutos);
    }

    private function ativar(PubPromocaoAutomatica $ciclo): void
    {
        $ciclo->update(['status' => PubPromocaoAutomatica::ATIVA, 'motivo' => null, 'proxima_tentativa_em' => null]);
        Log::info("[Publicador] promoção automática {$ciclo->id}: {$ciclo->ml_item_id} ciclo {$ciclo->ciclo} ativa de {$ciclo->inicio} a {$ciclo->fim} ("
            .PrecoDaPromocao::reais((float) $ciclo->preco_publicado).' → '.PrecoDaPromocao::reais((float) $ciclo->preco_promocao).').');
    }

    /** O sistema não criou: a tarefa pós-publicação orienta a fazer à mão. */
    private function recusar(PubPromocaoAutomatica $ciclo, string $motivo): void
    {
        $ciclo->update(['status' => PubPromocaoAutomatica::RECUSADA, 'motivo' => mb_substr($motivo, 0, PubPromocaoAutomatica::MOTIVO_MAX), 'proxima_tentativa_em' => null]);
        Log::warning("[Publicador] promoção automática {$ciclo->id} ({$ciclo->ml_item_id}, ciclo {$ciclo->ciclo}) recusada: {$motivo}");
        $this->orientar($ciclo, renovacao: $ciclo->ciclo > 1);
    }

    private function fechar(PubPromocaoAutomatica $ciclo, string $status, string $motivo): void
    {
        $ciclo->update(['status' => $status, 'motivo' => mb_substr($motivo, 0, PubPromocaoAutomatica::MOTIVO_MAX), 'proxima_tentativa_em' => null]);
        Log::info("[Publicador] promoção automática {$ciclo->id} ({$ciclo->ml_item_id}, ciclo {$ciclo->ciclo}) {$status}: {$motivo}");
    }

    /** A tarefa nunca derruba a promoção (nem o contrário): falhar aqui só fica no log. */
    private function orientar(PubPromocaoAutomatica $ciclo, bool $renovacao): void
    {
        try {
            $this->tarefas->orientarPromocao($ciclo->fresh(), $renovacao);
        } catch (\Throwable $e) {
            Log::warning("[Publicador] promoção automática {$ciclo->id}: a orientação na tarefa falhou: {$e->getMessage()}");
        }
    }

    /** O Job morreu (`failed`): o ciclo não fica preso em agendada/enviando. */
    public function interrompida(int $id, string $erro): void
    {
        $ciclo = PubPromocaoAutomatica::query()->find($id);
        if ($ciclo === null || ! in_array($ciclo->status, [PubPromocaoAutomatica::AGENDADA, PubPromocaoAutomatica::ENVIANDO], true)) {
            return;
        }
        Log::error("[Publicador] promoção automática {$id} ({$ciclo->ml_item_id}) quebrou: {$erro}");
        $this->recusar($ciclo, $ciclo->status === PubPromocaoAutomatica::ENVIANDO
            ? 'A criação foi interrompida depois de sair para o Mercado Livre. Confira no Seller Center se a promoção existe antes de criar outra.'
            : 'A criação foi interrompida antes de enviar (erro interno): nada foi enviado.');
    }

    // ═══ Renovação diária ══════════════════════════════════════════════════

    /**
     * Ciclo `ativa` cujo fim já passou: anúncio ativo (ou só fora do ar por um tempo) e com o MESMO
     * preço → o ciclo seguinte, de hoje a hoje+13, pelo mesmo Job; preço mudado, anúncio encerrado ou
     * sumido da conta → `encerrada` e nada mais. O preço da promoção nunca cai abaixo do mínimo do Portal
     * de agora; se com ele o desconto sair das regras, não renova e a tarefa avisa. Depois, a varredura:
     * Job agendado que não voltou é reenviado; envio preso há mais de uma hora vira recusa.
     *
     * @return array{renovadas: int, encerradas: int, nao_renovadas: int, reenviadas: int, interrompidas: int}
     */
    public function renovar(): array
    {
        $resumo = ['renovadas' => 0, 'encerradas' => 0, 'nao_renovadas' => 0, 'reenviadas' => 0, 'interrompidas' => 0];
        $hoje = DatasDoMl::hoje();

        $vencidas = PubPromocaoAutomatica::query()->where('status', PubPromocaoAutomatica::ATIVA)
            ->where('fim', '<', $hoje->format('Y-m-d'))->orderBy('id')->get();
        foreach ($vencidas->groupBy('conta_chave') as $linhas) {
            $lidos = $this->lerAnuncios($linhas);
            foreach ($linhas as $ciclo) {
                $resultado = $this->renovarUm($ciclo, $lidos);
                if ($resultado !== null) {
                    $resumo[$resultado]++;
                }
            }
        }

        // Job que se perdeu (worker reiniciado, fila sync): volta para a fila.
        $perdidas = PubPromocaoAutomatica::query()->where('status', PubPromocaoAutomatica::AGENDADA)
            ->where('proxima_tentativa_em', '<=', now()->subMinutes(15))->orderBy('id')->get();
        foreach ($perdidas as $ciclo) {
            $this->despacharAgora($ciclo);
            $resumo['reenviadas']++;
        }
        // `enviando` há mais de uma hora: o processo caiu no meio. Nunca reenvia — vira recusa com orientação.
        $presas = PubPromocaoAutomatica::query()->where('status', PubPromocaoAutomatica::ENVIANDO)
            ->where('updated_at', '<=', now()->subHour())->orderBy('id')->get();
        foreach ($presas as $ciclo) {
            $this->interrompida($ciclo->id, 'envio preso em "enviando"');
            $resumo['interrompidas']++;
        }

        Log::info('[Publicador] promoções automáticas: '.json_encode($resumo));

        return $resumo;
    }

    /**
     * Os anúncios das linhas, numa leitura por conta (multiget de 20 em 20). Nulo = não deu para ler
     * (sem conta, sem token, ML fora): a renovação segue e o Job decide.
     *
     * @param  Collection<int, PubPromocaoAutomatica>  $linhas
     * @return ?array<string, ?array>
     */
    private function lerAnuncios(Collection $linhas): ?array
    {
        $primeira = $linhas->first();
        $conta = $primeira !== null ? $this->conta($primeira) : null;
        if ($conta === null || $conta->chaveConta() !== $primeira->conta_chave || ! $conta->liberada()) {
            return null;
        }
        $ids = $linhas->pluck('ml_item_id')->unique()->values()->all();

        try {
            $mapa = app(ProdutosDaContaService::class)->porIds($conta, $ids);
        } catch (\Throwable $e) {
            Log::warning("[Publicador] renovação das promoções: leitura da conta {$primeira->conta_chave} falhou: {$e->getMessage()}");

            return null;
        }

        $saida = [];
        foreach ($ids as $id) {
            $saida[$id] = $mapa[$id] ?? null;
        }

        return $saida;
    }

    /**
     * O Job do ciclo seguinte sai DEPOIS de soltar a trava do anúncio: na fila `sync` ele roda na hora e
     * precisa da mesma trava.
     *
     * @param  ?array<string, ?array>  $lidos
     */
    private function renovarUm(PubPromocaoAutomatica $ciclo, ?array $lidos): ?string
    {
        $seguinte = null;
        $resultado = $this->decidirRenovacao($ciclo, $lidos, $seguinte);
        if ($seguinte !== null) {
            $this->despacharAgora($seguinte);
        }

        return $resultado;
    }

    /** @param  ?array<string, ?array>  $lidos */
    private function decidirRenovacao(PubPromocaoAutomatica $ciclo, ?array $lidos, ?PubPromocaoAutomatica &$seguinte): ?string
    {
        $trava = Cache::lock(self::chaveDaTrava($ciclo->ml_item_id), 300);
        if (! $trava->get()) {
            return null;
        }

        try {
            $ciclo->refresh();
            if ($ciclo->status !== PubPromocaoAutomatica::ATIVA) {
                return null;
            }
            $hoje = DatasDoMl::hoje();
            if (PubPromocaoAutomatica::query()->where('ml_item_id', $ciclo->ml_item_id)->where('ciclo', '>', $ciclo->ciclo)->exists()) {
                $this->fechar($ciclo, PubPromocaoAutomatica::ENCERRADA, 'O ciclo terminou; o seguinte já existia.');

                return 'encerradas';
            }

            if ($lidos !== null) {
                $anuncio = $lidos[$ciclo->ml_item_id] ?? null;
                if ($anuncio === null) {
                    $this->fechar($ciclo, PubPromocaoAutomatica::ENCERRADA, 'O anúncio não foi encontrado nesta conta do Mercado Livre: a promoção não foi renovada.');

                    return 'encerradas';
                }
                if (in_array((string) ($anuncio['status'] ?? ''), ['closed', 'inactive'], true)) {
                    $this->fechar($ciclo, PubPromocaoAutomatica::ENCERRADA, 'O anúncio foi encerrado no Mercado Livre: a promoção não foi renovada.');

                    return 'encerradas';
                }
                if (! self::mesmoPreco($anuncio, (float) $ciclo->preco_publicado)) {
                    $this->fechar($ciclo, PubPromocaoAutomatica::ENCERRADA, 'O preço do anúncio mudou (era '.PrecoDaPromocao::reais((float) $ciclo->preco_publicado)
                        .', agora '.PrecoDaPromocao::reais((float) ($anuncio['preco'] ?? 0)).'): a promoção não foi renovada.');

                    return 'encerradas';
                }
            }

            $preco = $this->precoDaRenovacao($ciclo);
            if ($preco['motivo'] !== null) {
                $this->fechar($ciclo, PubPromocaoAutomatica::ENCERRADA, "Não renovada: {$preco['motivo']}. Revise a Precificação do Portal antes de criar outra promoção.");
                $this->orientar($ciclo, renovacao: true);

                return 'nao_renovadas';
            }

            $seguinte = $this->criarCiclo([
                ...$ciclo->only(['publicacao_id', 'publicacao_item_id', 'rascunho_id', 'produto_id', 'conta_chave', 'company_id', 'mlb_empresa_id',
                    'ml_item_id', 'listing_type', 'variante_chave', 'preco_publicado']),
                'preco_promocao' => $preco['preco'],
                'percentual' => $preco['percentual'],
                'ciclo' => $ciclo->ciclo + 1,
                'inicio' => $hoje->format('Y-m-d'),
                'fim' => $hoje->addDays(PrecoDaPromocao::DIAS - 1)->format('Y-m-d'),
                'status' => PubPromocaoAutomatica::AGENDADA,
                'motivo' => 'Renovação: aguardando o envio ao Mercado Livre.',
                'tentativas' => 0,
            ]);
            $this->fechar($ciclo, PubPromocaoAutomatica::ENCERRADA, "Ciclo de {$ciclo->inicio} a {$ciclo->fim} terminou; renovada no ciclo ".($ciclo->ciclo + 1).'.');

            return 'renovadas';
        } finally {
            $trava->release();
        }
    }

    /**
     * O preço do ciclo seguinte: o mesmo, a não ser que o mínimo do Portal de AGORA tenha subido acima
     * dele (o custo mudou) — aí vale a conta de novo. Produto sem preço no Portal mantém o de antes
     * (o publicado não mudou e não há nada melhor). Motivo preenchido = não renova.
     *
     * @return array{preco: ?float, percentual: ?float, motivo: ?string}
     */
    private function precoDaRenovacao(PubPromocaoAutomatica $ciclo): array
    {
        $mesmo = ['preco' => (float) $ciclo->preco_promocao, 'percentual' => (float) $ciclo->percentual, 'motivo' => null];
        $r = PubRascunho::query()->with('produto')->find($ciclo->rascunho_id);
        if ($r === null || $r->produto === null) {
            return $mesmo;
        }

        try {
            $portal = $this->portalDoRascunho($r)[(string) $ciclo->variante_chave][$ciclo->listing_type] ?? null;
        } catch (\Throwable $e) {
            Log::warning("[Publicador] renovação da promoção {$ciclo->id}: Precificação ilegível ({$e->getMessage()}); mantém o preço de antes.");

            return $mesmo;
        }
        $minimo = isset($portal['minimo']) && empty($portal['sem_frete']) ? (float) $portal['minimo'] : null;
        if ($minimo === null || $minimo <= (float) $ciclo->preco_promocao) {
            return $mesmo;
        }

        $calc = PrecoDaPromocao::calcular((float) $ciclo->preco_publicado, $portal);

        return $calc['calculavel']
            ? ['preco' => $calc['preco'], 'percentual' => $calc['percentual'], 'motivo' => null]
            : ['preco' => null, 'percentual' => null, 'motivo' => 'o mínimo do Portal subiu e '.PrecoDaPromocao::MOTIVOS[$calc['motivo']]];
    }

    /** Despacho sem atraso (renovação e varredura). Na fila `sync` o Job roda aqui mesmo, no comando. */
    private function despacharAgora(PubPromocaoAutomatica $ciclo): void
    {
        $ciclo->update(['proxima_tentativa_em' => now()]);
        CriarPromocaoAutomaticaJob::dispatch($ciclo->id);
    }

    // ═══ Leitura para a fila de tarefas ═══════════════════════════════════

    /**
     * O último ciclo de cada anúncio de cada tarefa, pronto para a tela (a frase de orientação só quando
     * o sistema não criou), numa consulta só para a página inteira. Só anúncio da MESMA conta da tarefa
     * (por âncora). Sem a tabela (entre o deploy e o `migrate`), tudo vazio.
     *
     * @param  iterable<PubTarefa>  $tarefas
     * @return array<int, list<array<string, mixed>>> tarefa id → promoções
     */
    public static function dasTarefas(iterable $tarefas): array
    {
        $mlbs = [];
        foreach ($tarefas as $t) {
            array_push($mlbs, ...$t->idsDosItens());
        }
        $mlbs = array_values(array_unique($mlbs));
        if ($mlbs === []) {
            return [];
        }

        try {
            $linhas = PubPromocaoAutomatica::query()->ultimoCicloDe($mlbs)->get()->groupBy('ml_item_id');
        } catch (\Throwable $e) {
            Log::warning('[Publicador] promoções automáticas indisponíveis na fila: '.$e->getMessage());

            return [];
        }

        $saida = [];
        foreach ($tarefas as $t) {
            $saida[$t->id] = [];
            foreach ($t->idsDosItens() as $mlb) {
                $l = collect($linhas[$mlb] ?? [])->first(fn (PubPromocaoAutomatica $p) => ($t->company_id !== null && (int) $p->company_id === (int) $t->company_id)
                    || ($t->mlb_empresa_id !== null && (int) $p->mlb_empresa_id === (int) $t->mlb_empresa_id));
                if ($l !== null) {
                    $saida[$t->id][] = self::paraTela($l);
                }
            }
        }

        return $saida;
    }

    /** @return array<string, mixed> */
    private static function paraTela(PubPromocaoAutomatica $l): array
    {
        return [
            'ml_item_id' => (string) $l->ml_item_id,
            'tipo' => $l->tipo(),
            'status' => $l->status,
            'ciclo' => $l->ciclo,
            'inicio' => (string) $l->inicio,
            'fim' => (string) $l->fim,
            'preco_publicado' => (float) $l->preco_publicado,
            'preco_promocao' => (float) $l->preco_promocao,
            'percentual' => (float) $l->percentual,
            'motivo' => $l->motivo,
            'orientacao' => $l->status === PubPromocaoAutomatica::RECUSADA ? $l->orientacao() : null,
            'atualizada_em' => $l->updated_at?->toIso8601String(),
        ];
    }
}
