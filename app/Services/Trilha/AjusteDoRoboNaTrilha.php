<?php

namespace App\Services\Trilha;

use App\Jobs\Trilha\AjustarPeloRoboNaTrilhaJob;
use App\Models\Company;
use App\Models\PubProduto;
use App\Models\PubRascunho;
use App\Models\TrilhaOnda;
use App\Models\User;
use App\Services\Portal\Estrutura\RegistroEstrutura;
use App\Services\Publicador\CategorySchemaRepository;
use App\Services\Publicador\DadosEfetivosService;
use App\Services\Publicador\DescricaoIaService;
use App\Services\Publicador\IaParaRascunhoService;
use App\Services\Publicador\PalavrasChaveService;
use App\Services\Publicador\PreparoIaDoRascunhoService as Preparo;
use App\Services\Publicador\RascunhoRepository;
use App\Support\Portal\AtorDoPortal;
use App\Support\Publicador\MemoriaDoPreparoIa as Memoria;
use App\Support\Publicador\NaFilaDePublicacao;
use App\Support\Publicador\RascunhoSnapshot;
use App\Support\Publicador\RegraViolada;
use App\Support\TituloDoProduto;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

/**
 * "Pedir ajuste ao agente" na revisão da trilha (4ª entrega, fatia 4b, desenho §19.14, D51/D60): a equipe escolhe o que o
 * agente refaz no anúncio que ele preparou — o TÍTULO (com o Modelo junto) e/ou a DESCRIÇÃO — sem texto livre e sem
 * imagens. O agente refaz com os MESMOS geradores do preparo do Publicador e grava pelo MESMO caminho, com as travas dele.
 *
 * ### Nenhum texto livre chega a prompt nenhum
 * Do corpo só se leem `revisao` e `refazer` (lista fechada: `titulo`, `descricao`); qualquer outra chave é ignorada e
 * NÃO vai para o estado, o Job nem o histórico. O que a IA recebe é o que o preparo de sempre manda (o rascunho, a ficha,
 * os termos) mais, no título, os títulos ATUAIS como "não repita" (`evitar`).
 *
 * ### A guarda do pedido (`POST …/ajuste`), nesta ordem
 * O controller conferiu as duas chaves (404) e a escrita (403). Aqui: 404 item (a régua única, antes do corpo) → 409 SEM
 * `travado`: na fila de publicação → publicando → publicado → fora da revisão → sendo conferido para publicar (4d) → o
 * texto não é do agente (kit da fase N, rascunho sem preparo) → ajuste já em andamento → a `revisao` (ausente/fora da
 * forma = 422 `errors.revisao`; velha = 409 `{revisao_atual, message}`) → prévia não pronta → 422 `errors.refazer`
 * (vazio, parte desconhecida, e o limite de {@see LIMITE_POR_DIA} por anúncio por dia).
 *
 * ### O limite de 3 por dia é contado no `activity_log`
 * Cada pedido aceito grava `trilha_ajuste_pedido` (canal `portal`, ator da equipe, subject = o rascunho). O limite conta
 * esses registros do rascunho desde a meia-noite de São Paulo ({@see pedidosHoje()}): é o registro durável de QUEM pediu
 * (sobrevive a reinício do cache) e a mesma linha serve de prova. A corrida "dois cliques ao mesmo tempo" fecha com a
 * trava curta do pedido (`Cache::lock`) e o 409 de "ajuste em andamento". Conta o PEDIDO (inclusive o que terminou em
 * erro ou "mantido"): "3 pedidos de ajuste por anúncio por dia" (§19.12). ⚠️ Com `ACTIVITY_LOGGER_ENABLED=false` o
 * histórico não grava e o limite deixa de valer (a cota diária da loja continua).
 *
 * ### O Job ({@see executar()}), na fila da IA
 * Para cada parte: é do agente? (cópia da régua do preparo — {@see titulosLivres()}, {@see descricaoLivre()}); não sendo,
 * `mantido` SEM chamar a IA. Sendo: a cota diária da LOJA (o MESMO contador do preparo, {@see consumirCota()}; sem cota =
 * `erro`, sem gastar) → gera (título: `PalavrasChaveService::gerarTitulos($r, $prazo, $evitar)` com `evitar` = os títulos
 * atuais dos dois tipos, e o Modelo depois; descrição: `DescricaoIaService::gerar`) → grava por
 * `PreparoIaDoRascunhoService::executarEtapa(rascunho, etapa, hash, $valorPronto)`, que é QUEM decide de verdade, sob a
 * trava do rascunho: só escreve campo que ainda é do agente (`step_state.ia_escrito`) — o resto vira `mantido` —, e adia
 * com o editor em uso, com o anúncio na fila de publicação ou com "Anunciar por IA" rodando (o valor gerado vai no Job
 * adiado do Publicador; nada se gera de novo). Adiado: a parte fica `fila` e um acompanhamento ({@see acompanhar()},
 * zero IA) lê o desfecho no `step_state` até a escrita acontecer (ou o agente desistir).
 *
 * ### As réguas COPIADAS do preparo — mudou lá, muda aqui
 * `PreparoIaDoRascunhoService::livres()`, `categoriaTemModelo()` e `consumirCota()` são PRIVADAS, e o arquivo é vigiado por
 * portões (Fases 188/193: os leitores do `EditorEmUso` por método, contagens de asserções — já reprovaram mudança legítima;
 * o `FotosDoPainelServiceTest`, que os roda, falha no worktree por ambiente): aqui moram cópias, sem tocar nele. As de
 * "é do agente?" só economizam a chamada — quem decide a escrita é o
 * `executarEtapa`, sob a trava; divergir faria gastar à toa, nunca sobrescrever o que a equipe escreveu. A da cota usa a
 * MESMA chave do preparo (o orçamento do dia é um só) — o teste prova que os dois contadores são o mesmo.
 *
 * ### O estado (D60)
 * Em cache por anúncio (`trilha:ajuste:{pub}`, {@see HORAS_DO_ESTADO} h): `fila` → `fazendo` → `feito` | `erro`. Enquanto
 * `fila`/`fazendo`, a linha da trilha diz "Agente refazendo · título, descrição" e fica na revisão
 * ({@see refazendoEmLote()}, lido pela {@see SituacaoDasOfertas}). `fila`/`fazendo` parado há mais de
 * {@see ANDAMENTO_VALE_MIN} min é Job que morreu: sai `erro` e libera um pedido novo.
 */
class AjusteDoRoboNaTrilha
{
    public const LIMITE_POR_DIA = 3;

    public const EVENTO = 'trilha_ajuste_pedido';

    public const CHAVE_DO_ESTADO = 'trilha:ajuste:';

    public const HORAS_DO_ESTADO = 6;

    /**
     * `fila`/`fazendo` mais velho que isto (sem nenhuma atualização) é Job que morreu. Cobre a fila da IA cheia (~30 min
     * numa importação grande) e a espera do editor (o acompanhamento atualiza o estado a cada passada).
     */
    public const ANDAMENTO_VALE_MIN = 180;

    /** As partes do pedido, na ordem em que o agente as faz e a tela as lista. */
    public const PARTES = ['titulo', 'descricao'];

    public const ROTULO = ['titulo' => 'título', 'descricao' => 'descrição'];

    public const FILA = 'fila';
    public const FAZENDO = 'fazendo';
    public const FEITO = 'feito';
    public const ERRO = 'erro';
    public const REFEITO = 'refeito';
    public const MANTIDO = 'mantido';

    public const MSG_FILA = 'Pedido recebido: o agente começa em instantes.';
    public const MSG_FAZENDO = 'O agente está refazendo…';
    public const MSG_FEITO = 'Ajuste pronto.';
    public const MSG_ERRO = 'O agente não conseguiu fazer o ajuste.';

    public const MSG_PARTE_FILA = 'Na fila do agente.';
    public const MSG_PARTE_FAZENDO = 'O agente está refazendo.';
    public const MSG_TITULO_REFEITO = 'Título refeito.';
    public const MSG_DESCRICAO_REFEITA = 'Descrição refeita.';
    public const MSG_TITULO_MANTIDO = 'A equipe editou o título; o agente não mexe nele.';
    public const MSG_DESCRICAO_MANTIDA = 'A equipe editou a descrição; o agente não mexe nela.';
    public const MSG_ADIADO = 'O agente já refez e grava assim que o anúncio estiver livre.';
    public const MSG_COTA = 'O limite diário do agente para esta loja acabou. Amanhã libera de novo.';
    public const MSG_DESLIGADO = 'O agente está desligado agora.';
    public const MSG_IA_FALHOU = 'O agente não conseguiu refazer agora. Tente de novo mais tarde.';
    public const MSG_SEM_TITULO_NOVO = 'O agente não achou um título diferente do atual.';
    public const MSG_IGUAL = 'O agente chegou ao mesmo texto; nada mudou.';
    public const MSG_PUBLICADO_DEPOIS = 'O anúncio foi publicado antes de o agente gravar; nada foi trocado.';
    public const MSG_NA_FILA_DEPOIS = 'O anúncio entrou na fila de publicação; o agente não mexe mais nele.';
    public const MSG_MUDOU = 'O anúncio mudou enquanto o agente refazia; nada foi trocado. Peça de novo.';
    public const MSG_DESISTIU = 'O anúncio ficou ocupado por muito tempo; o agente desistiu de gravar.';
    public const MSG_SEM_TEMPO = 'O agente demorou demais. Tente de novo.';
    public const MSG_NAO_TERMINOU = 'O agente não terminou. Tente de novo.';

    public const MSG_NA_FILA_409 = 'Este anúncio já está na fila de publicação: o agente não mexe mais nele.';
    public const MSG_PUBLICANDO_409 = 'Este anúncio está sendo publicado agora: o agente não mexe mais nele.';
    public const MSG_PUBLICADO_409 = 'Este anúncio já foi publicado: o agente não mexe mais nele.';
    public const MSG_FORA_DA_REVISAO = 'Este anúncio não está na revisão: o agente só refaz quando ele estiver pronto para revisar.';
    public const MSG_CONFERINDO_409 = 'Este anúncio está sendo conferido para publicar. Espere a conferência terminar.';
    public const MSG_SEM_ROBO = 'O texto deste anúncio não foi escrito pelo agente: não há o que ele refazer.';
    public const MSG_EM_ANDAMENTO_409 = 'O agente já está refazendo este anúncio. Espere terminar.';
    public const MSG_REFAZER = 'Escolha o que refazer: o título, a descrição ou os dois.';
    public const MSG_LIMITE = 'Este anúncio já teve 3 ajustes hoje. Amanhã libera de novo.';

    /** O que a 4d (publicar) responde enquanto um ajuste anda. */
    public const MSG_PUBLICAR_COM_AJUSTE = 'O agente está refazendo este anúncio. Espere terminar para publicar.';

    /** Os dois tipos que recebem título (os do preparo). */
    private const TIPOS_TITULO = ['gold_special', 'gold_pro'];

    private const NOME_DO_TIPO = ['gold_special' => 'Clássico', 'gold_pro' => 'Premium'];

    /** O tempo total das chamadas de IA dentro do Job (abaixo do `timeout` dele, 540 s, e do worker da IA, 600 s). */
    private const ORCAMENTO_S = 480;

    /** O prazo de cada chamada — o `PRAZO_S` do preparo (privado lá). */
    private const PRAZO_S = 240;

    /** O acompanhamento do adiado: no máximo isto de passadas (`max_adiamentos` 24 × 5 min do preparo, com folga). */
    public const MAX_ACOMPANHAMENTOS = 30;

    public function __construct(
        private PreviaNaTrilha $previa,
        private Preparo $preparo,
        private PalavrasChaveService $palavras,
        private DescricaoIaService $descricao,
        private RascunhoRepository $repo,
        private DadosEfetivosService $efetivos,
        private CategorySchemaRepository $schemas,
    ) {}

    // ═══ O pedido ════════════════════════════════════════════════════════════

    /**
     * O `POST …/ofertas/{item}/ajuste`. Corpo `{revisao, refazer: ["titulo", "descricao"]}` — outra chave é ignorada.
     * Devolve `{ajuste: {situacao: 'fila', …}}` (o controller responde 202).
     *
     * @param  array<string, mixed>  $corpo
     * @return array{ajuste: array<string, mixed>}
     *
     * @throws HttpResponseException 409
     * @throws ValidationException 422
     */
    public function pedir(Company $empresa, string $chave, array $corpo, User $quem): array
    {
        $item = TrilhaDaEmpresa::itens($empresa, comoNaTela: true)[$chave] ?? null;
        abort_if($item === null, 404);

        $r = PreviaNaTrilha::rascunho($item);
        self::exigirQueDaParaAjustar($item, $r);
        /** @var PubRascunho $r */
        $pub = $r->produto;
        if (! self::doRobo($pub, $r)) {
            self::conflito(self::MSG_SEM_ROBO);
        }
        if (self::emAndamento(self::ler((int) $r->produto_id))) {
            self::conflito(self::MSG_EM_ANDAMENTO_409);
        }
        $revisao = self::exigirRevisao($r, $corpo);

        $previa = $this->previa->montar($empresa, $chave);
        if (! $previa['pronta']) {
            self::conflito((string) ($previa['motivo'] ?? PreviaNaTrilha::MOTIVO_PREPARANDO));
        }
        $partes = self::partes($corpo);

        $pubId = (int) $r->produto_id;
        $trava = Cache::lock(self::chave($pubId).':pedido', 10);
        if (! $trava->get()) {
            self::conflito(self::MSG_EM_ANDAMENTO_409);
        }
        try {
            if (self::emAndamento(self::ler($pubId))) {
                self::conflito(self::MSG_EM_ANDAMENTO_409);
            }
            $feitos = self::pedidosHoje($r);
            if ($feitos >= self::LIMITE_POR_DIA) {
                throw ValidationException::withMessages(['refazer' => self::MSG_LIMITE]);
            }

            $pedido = (string) Str::uuid();
            $estado = self::gravar($pubId, [
                'pedido'    => $pedido,
                'situacao'  => self::FILA,
                'mensagem'  => self::MSG_FILA,
                'partes'    => array_fill_keys($partes, ['situacao' => self::FILA, 'mensagem' => self::MSG_PARTE_FILA]),
                'revisao'   => $revisao,
                'por'       => (int) $quem->id,
                'item'      => $chave,
                'pedido_em' => now()->toIso8601String(),
            ]);
            RegistroEstrutura::registrar(AtorDoPortal::daEquipe($quem), $empresa, $r, self::EVENTO,
                "Ajuste pedido ao agente em \"{$item['nome']}\": ".implode(' e ', array_map(fn ($p) => self::ROTULO[$p], $partes)), [
                    'item'           => $chave,
                    'pub_produto_id' => $pubId,
                    'rascunho_id'    => (int) $r->id,
                    'revisao'        => $revisao,
                    'partes'         => $partes,
                    'pedido'         => $pedido,
                ]);
            AjustarPeloRoboNaTrilhaJob::dispatch((int) $empresa->id, $chave, $pubId, $pedido, $partes);
        } finally {
            $trava->release();
        }

        return ['ajuste' => self::publico($estado, max(0, self::LIMITE_POR_DIA - $feitos - 1))];
    }

    /**
     * O `GET …/ofertas/{item}/ajuste`: o item do endereço (404) e o ajuste ({@see situacao()}), ou `null` quando nunca
     * pediu (ou o pedido venceu as {@see HORAS_DO_ESTADO} h).
     *
     * @return array{ajuste: ?array<string, mixed>}
     */
    public function acompanharPedido(Company $empresa, string $chave): array
    {
        $item = TrilhaDaEmpresa::itens($empresa)[$chave] ?? null;
        abort_if($item === null, 404);

        return ['ajuste' => self::situacao(PreviaNaTrilha::rascunho($item))];
    }

    // ═══ O Job ═══════════════════════════════════════════════════════════════

    /**
     * O que o {@see AjustarPeloRoboNaTrilhaJob} faz. Devolve o desfecho (log e testes): `superado`, `sumiu`, `bloqueado`,
     * ou a situação final (`feito`, `erro`, `fazendo` quando alguma parte ficou adiada).
     *
     * @param  list<string>  $partes
     */
    public function executar(int $empresaId, string $chave, int $pubId, string $pedido, array $partes): string
    {
        $estado = self::ler($pubId);
        if (! self::doPedido($estado, $pedido)) {
            return 'superado';
        }
        // O mesmo pedido entregue duas vezes pela fila (o `retry_after` da conexão): só o 1º gera — a IA não é paga de novo.
        if ($estado['situacao'] !== self::FILA) {
            return 'repetido';
        }
        $pub = PubProduto::query()->find($pubId);
        $r = $pub !== null ? PubRascunho::query()->where('produto_id', $pubId)->first() : null;
        if ($pub === null || $r === null) {
            self::concluir($pubId, $pedido, array_fill_keys($partes, self::parte(self::ERRO, self::MSG_NAO_TERMINOU)));

            return 'sumiu';
        }
        self::atualizar($pubId, $pedido, fn (array $e) => [...$e, 'situacao' => self::FAZENDO, 'mensagem' => self::MSG_FAZENDO,
            'partes' => array_map(fn () => self::parte(self::FAZENDO, self::MSG_PARTE_FAZENDO), $e['partes'])]);

        // Antes de gastar: o anúncio ainda é do agente e ainda dá para mexer nele?
        $bloqueio = match (true) {
            IaParaRascunhoService::intocavel($r)                 => self::MSG_PUBLICADO_DEPOIS,
            NaFilaDePublicacao::emUso($pubId)                    => self::MSG_NA_FILA_DEPOIS,
            ! self::doRobo($pub, $r)                             => self::MSG_SEM_ROBO,
            ! (bool) config('publicador.preparo_ia.ativo', true) => self::MSG_DESLIGADO,
            default                                              => null,
        };
        if ($bloqueio !== null) {
            self::concluir($pubId, $pedido, array_fill_keys($partes, self::parte(self::ERRO, $bloqueio)));

            return 'bloqueado';
        }

        $hash = (string) $r->step_state[Memoria::PREPARO]['hash'];
        $escrito = (array) ($r->step_state[Memoria::ESCRITO] ?? []);
        $snap = $this->repo->snapshot($r);
        $saida = [];
        // Quem é do agente AGORA (economia: a decisão que vale é a do `executarEtapa`, sob a trava).
        $livres = in_array('titulo', $partes, true) ? $this->titulosLivres($r, $snap, $escrito) : [];
        if (in_array('titulo', $partes, true) && $livres === []) {
            $saida['titulo'] = self::parte(self::MANTIDO, self::MSG_TITULO_MANTIDO);
        }
        if (in_array('descricao', $partes, true) && ! self::descricaoLivre($r, $escrito)) {
            $saida['descricao'] = self::parte(self::MANTIDO, self::MSG_DESCRICAO_MANTIDA);
        }
        $aGerar = array_values(array_filter($partes, fn ($p) => ! isset($saida[$p])));
        // A cota diária da LOJA, uma por pedido que vai gastar (o preparo também conta uma por produto preparado).
        if ($aGerar !== [] && ! self::consumirCota((int) $pub->company_id)) {
            Log::warning("[Trilha] Ajuste do agente: limite diário da empresa {$pub->company_id} atingido; o anúncio {$pubId} (item {$chave}) não foi refeito.");
            foreach ($aGerar as $p) {
                $saida[$p] = self::parte(self::ERRO, self::MSG_COTA);
            }
            $aGerar = [];
        }

        $fim = microtime(true) + self::ORCAMENTO_S;
        if (in_array('titulo', $aGerar, true)) {
            $saida['titulo'] = $this->refazerTitulo($r, $snap, $hash, $escrito, $livres, $fim);
        }
        if (in_array('descricao', $aGerar, true)) {
            $saida['descricao'] = $this->refazerDescricao($r->fresh() ?? $r, $hash, $escrito, $fim);
        }

        $final = self::concluir($pubId, $pedido, $saida);
        if ($final === self::FAZENDO) {
            AjustarPeloRoboNaTrilhaJob::dispatch($empresaId, $chave, $pubId, $pedido, $partes, 'acompanhar', 1)
                ->delay(now()->addMinutes(self::esperaDoAcompanhamento()));
        }
        Log::info("[Trilha] Ajuste do agente: item {$chave} (anúncio {$pubId}) da empresa {$empresaId}: {$final} ("
            .implode(', ', array_map(fn ($p, $s) => "{$p} {$s['situacao']}", array_keys($saida), $saida)).').');

        return $final;
    }

    /**
     * A passada do acompanhamento de uma parte ADIADA (zero IA): o valor gerado está no Job adiado do Publicador, que grava
     * quando o anúncio fica livre e marca a etapa no `step_state`. Aqui só se lê o desfecho: `ok` → refeito (a memória
     * `ia_escrito` mudou e o campo está com ela) ou mantido (a equipe editou nesse meio-tempo); `desistiu`/`intocavel`/
     * fatos novos → erro; ainda `adiado` → outra passada, até {@see MAX_ACOMPANHAMENTOS}.
     *
     * @param  list<string>  $partes
     */
    public function acompanhar(int $empresaId, string $chave, int $pubId, string $pedido, array $partes, int $vez): string
    {
        $estado = self::ler($pubId);
        if (! self::doPedido($estado, $pedido)) {
            return 'superado';
        }
        if ($estado['situacao'] !== self::FAZENDO) {
            return (string) $estado['situacao'];
        }
        $r = PubRascunho::query()->where('produto_id', $pubId)->first();
        $saida = [];
        $esperando = [];
        foreach ((array) $estado['partes'] as $p => $s) {
            if (($s['situacao'] ?? null) !== self::FILA || ! isset($s['espera'])) {
                continue;
            }
            $desfecho = $r !== null ? $this->desfechoDoAdiado($r, (string) $p, (array) $s['espera']) : self::parte(self::ERRO, self::MSG_NAO_TERMINOU);
            if ($desfecho === null) {
                $esperando[] = $p;
            } else {
                $saida[$p] = $desfecho;
            }
        }
        if ($esperando !== [] && $vez >= self::MAX_ACOMPANHAMENTOS) {
            foreach ($esperando as $p) {
                $saida[$p] = self::parte(self::ERRO, self::MSG_DESISTIU);
            }
            $esperando = [];
        }

        $final = self::concluir($pubId, $pedido, $saida);
        if ($final === self::FAZENDO && $esperando !== []) {
            AjustarPeloRoboNaTrilhaJob::dispatch($empresaId, $chave, $pubId, $pedido, $partes, 'acompanhar', $vez + 1)
                ->delay(now()->addMinutes(self::esperaDoAcompanhamento()));
        }

        return $final;
    }

    /** O Job quebrou (ou estourou o tempo): a parte que ainda andava vira `erro` — a tela não fica em "refazendo". */
    public static function quebrou(int $pubId, string $pedido): void
    {
        $estado = self::ler($pubId);
        if (! self::doPedido($estado, $pedido)) {
            return;
        }
        $saida = [];
        foreach ((array) $estado['partes'] as $p => $s) {
            if (in_array($s['situacao'] ?? null, [self::FILA, self::FAZENDO], true)) {
                $saida[$p] = self::parte(self::ERRO, self::MSG_NAO_TERMINOU);
            }
        }
        self::concluir($pubId, $pedido, $saida);
    }

    // ═══ Refazer cada parte ══════════════════════════════════════════════════

    /**
     * O título dos dois tipos: `gerarTitulos` com `evitar` = os títulos ATUAIS (o servidor garante um diferente) e a
     * escrita pelo `executarEtapa` com o valor pronto. Escrito ou adiado, o Modelo vem junto ({@see refazerModelo()}).
     *
     * @param  list<string>  $livres  os tipos cujo título ainda é do agente
     */
    private function refazerTitulo(PubRascunho $r, RascunhoSnapshot $snap, string $hash, array $escrito, array $livres, float $fim): array
    {
        $evitar = [];
        foreach ($snap->alvos as $alvo) {
            if ($alvo->ativo && in_array($alvo->listingTypeId, self::TIPOS_TITULO, true) && trim((string) $alvo->titulo) !== '') {
                $evitar[$alvo->listingTypeId] = trim((string) $alvo->titulo);
            }
        }
        try {
            $titulos = $this->palavras->gerarTitulos($r, self::prazo($fim), $evitar);
        } catch (\Throwable $e) {
            Log::warning("[Trilha] Ajuste do agente (título) falhou no rascunho {$r->id}: ".$e->getMessage());

            return self::parte(self::ERRO, self::MSG_IA_FALHOU);
        }
        if (array_filter(array_map(fn ($t) => trim((string) $t), $titulos)) === []) {
            return self::parte(self::ERRO, self::MSG_SEM_TITULO_NOVO);
        }

        $antes = self::memoria($escrito, array_map(fn ($lt) => Memoria::chaveDoTitulo($lt), $livres));
        $resultado = $this->preparo->executarEtapa((int) $r->id, Preparo::TITULO, $hash, (string) json_encode($titulos, JSON_UNESCAPED_UNICODE));
        if (in_array($resultado, ['escrito', Preparo::ADIADO], true)) {
            $this->refazerModelo((int) $r->id, $hash, $titulos, $fim);
        }

        return self::desfecho('titulo', $resultado, $hash, $antes, self::mensagemDoTitulo($snap, $livres));
    }

    /**
     * O Modelo depois do título novo ("título — com o Modelo —", §19.1): só se o campo ainda é do agente e a categoria tem
     * Modelo; gerado com os títulos NOVOS e gravado pelo mesmo `executarEtapa`. Melhor esforço: falhar aqui não muda o
     * desfecho do título (o Modelo não é parte do contrato).
     *
     * @param  array<string, string>  $titulos
     */
    private function refazerModelo(int $rascunhoId, string $hash, array $titulos, float $fim): void
    {
        try {
            $r = PubRascunho::query()->find($rascunhoId);
            if ($r === null || microtime(true) >= $fim - 10 || ! $this->modeloLivre($r) || ! $this->categoriaTemModelo($r)) {
                return;
            }
            $gerados = array_values(array_unique(array_filter(array_map(fn ($t) => trim((string) $t), $titulos))));
            $valor = trim((string) ($this->palavras->gerarModelo($r, self::prazo($fim), $gerados)['valor'] ?? ''));
            if ($valor !== '') {
                $this->preparo->executarEtapa($rascunhoId, Preparo::MODELO, $hash, $valor);
            }
        } catch (\Throwable $e) {
            Log::warning("[Trilha] Ajuste do agente (Modelo) falhou no rascunho {$rascunhoId}: ".$e->getMessage());
        }
    }

    private function refazerDescricao(PubRascunho $r, string $hash, array $escrito, float $fim): array
    {
        if (microtime(true) >= $fim - 10) {
            return self::parte(self::ERRO, self::MSG_SEM_TEMPO);
        }
        try {
            $texto = $this->descricao->gerar($r, self::prazo($fim));
        } catch (\Throwable $e) {
            Log::warning("[Trilha] Ajuste do agente (descrição) falhou no rascunho {$r->id}: ".$e->getMessage());

            return self::parte(self::ERRO, self::MSG_IA_FALHOU);
        }
        $antes = self::memoria($escrito, [Memoria::DESCRICAO]);
        $resultado = $this->preparo->executarEtapa((int) $r->id, Preparo::DESCRICAO, $hash, $texto);

        return self::desfecho('descricao', $resultado, $hash, $antes, self::MSG_DESCRICAO_REFEITA);
    }

    /**
     * O que o `executarEtapa` devolveu → a parte do contrato. Adiado guarda o que a passada do acompanhamento precisa para
     * reconhecer a escrita: o hash dos fatos e a memória `ia_escrito` de ANTES (em sha1).
     *
     * @param  array<string, ?string>  $antes
     */
    private static function desfecho(string $parte, string $resultado, string $hash, array $antes, string $refeito): array
    {
        return match ($resultado) {
            'escrito'        => self::parte(self::REFEITO, $refeito),
            'mantido'        => self::parte(self::MANTIDO, $parte === 'titulo' ? self::MSG_TITULO_MANTIDO : self::MSG_DESCRICAO_MANTIDA),
            'igual'          => self::parte(self::ERRO, self::MSG_IGUAL),
            Preparo::ADIADO  => [...self::parte(self::FILA, self::MSG_ADIADO), 'espera' => ['hash' => $hash, 'antes' => $antes, 'refeito' => $refeito]],
            Preparo::INTOCAVEL => self::parte(self::ERRO, self::MSG_PUBLICADO_DEPOIS),
            'superado'       => self::parte(self::ERRO, self::MSG_MUDOU),
            Preparo::DESISTIU => self::parte(self::ERRO, self::MSG_DESISTIU),
            'desligado'      => self::parte(self::ERRO, self::MSG_DESLIGADO),
            default          => self::parte(self::ERRO, self::MSG_NAO_TERMINOU),
        };
    }

    /**
     * O desfecho de uma parte adiada, lido do `step_state` (nulo = ainda esperando). A escrita do Publicador grava a etapa
     * `ok` também quando o campo virou da equipe ("mantido") — o que separa os dois é a memória `ia_escrito`: mudou E o
     * campo está com ela = o agente gravou.
     */
    private function desfechoDoAdiado(PubRascunho $r, string $parte, array $espera): ?array
    {
        $estado = (array) $r->step_state;
        if (($estado[Memoria::PREPARO]['hash'] ?? null) !== ($espera['hash'] ?? null)) {
            return self::parte(self::ERRO, self::MSG_MUDOU);
        }
        $etapa = (array) ($estado[Memoria::PREPARO]['etapas'][$parte === 'titulo' ? Preparo::TITULO : Preparo::DESCRICAO] ?? []);
        $status = $etapa['status'] ?? null;
        if ($status === Preparo::ADIADO && self::recente($etapa['em'] ?? null)) {
            return null;
        }
        if ($status === Preparo::INTOCAVEL) {
            return self::parte(self::ERRO, self::MSG_PUBLICADO_DEPOIS);
        }
        if ($status !== Preparo::OK) {
            return self::parte(self::ERRO, $status === Preparo::DESISTIU ? self::MSG_DESISTIU : self::MSG_NAO_TERMINOU);
        }

        $escrito = (array) ($estado[Memoria::ESCRITO] ?? []);
        $antes = (array) ($espera['antes'] ?? []);
        $gravou = false;
        if ($parte === 'titulo') {
            $atuais = [];
            foreach ($this->repo->snapshot($r)->alvos as $alvo) {
                $atuais[Memoria::chaveDoTitulo($alvo->listingTypeId)] = (string) ($alvo->titulo ?? '');
            }
            foreach ($antes as $chave => $sha) {
                $agora = is_string($escrito[$chave] ?? null) ? $escrito[$chave] : null;
                $gravou = $gravou || ($agora !== null && sha1($agora) !== $sha
                    && ($atuais[$chave] ?? null) === TituloDoProduto::capitalizar($agora));
            }
        } else {
            $agora = is_string($escrito[Memoria::DESCRICAO] ?? null) ? $escrito[Memoria::DESCRICAO] : null;
            $gravou = $agora !== null && sha1($agora) !== ($antes[Memoria::DESCRICAO] ?? null) && (string) $r->descricao === $agora;
        }

        return $gravou
            ? self::parte(self::REFEITO, (string) ($espera['refeito'] ?? ($parte === 'titulo' ? self::MSG_TITULO_REFEITO : self::MSG_DESCRICAO_REFEITA)))
            : self::parte(self::MANTIDO, $parte === 'titulo' ? self::MSG_TITULO_MANTIDO : self::MSG_DESCRICAO_MANTIDA);
    }

    /** "Título refeito." — ou, com um dos tipos da equipe, qual foi refeito e qual ficou. */
    private static function mensagemDoTitulo(RascunhoSnapshot $snap, array $livres): string
    {
        $ativos = [];
        foreach ($snap->alvos as $alvo) {
            if ($alvo->ativo && in_array($alvo->listingTypeId, self::TIPOS_TITULO, true)) {
                $ativos[] = $alvo->listingTypeId;
            }
        }
        $ficaram = array_values(array_diff($ativos, $livres));
        if ($ficaram === [] || count($livres) !== 1) {
            return self::MSG_TITULO_REFEITO;
        }

        return 'Título do '.self::NOME_DO_TIPO[$livres[0]].' refeito; o do '.self::NOME_DO_TIPO[$ficaram[0]].' é da equipe e ficou.';
    }

    // ═══ As réguas copiadas do preparo (mudou lá, muda aqui) ══════════════════

    /**
     * Os tipos cujo título ainda é do agente — cópia do ramo do título de `PreparoIaDoRascunhoService::livres()` (privado):
     * vazio ou ainda com o último título que a automação escreveu; o título PLANEJADO (aba Anúncios, ou o título de venda
     * do cadastro) conta como preenchido — também quando o campo ainda tem o que o agente escreveu antes dele.
     *
     * @param  array<string, mixed>  $escrito  `step_state.ia_escrito`
     * @return list<string>
     */
    private function titulosLivres(PubRascunho $r, RascunhoSnapshot $snap, array $escrito): array
    {
        $planejados = $r->produto !== null ? (array) ($this->efetivos->daProduto($r->produto)['titulos'] ?? []) : [];
        $tipos = [];
        foreach ($snap->alvos as $alvo) {
            if (! in_array($alvo->listingTypeId, self::TIPOS_TITULO, true) || ! $alvo->ativo) {
                continue;
            }
            $atual = (string) ($alvo->titulo ?? '');
            $ultimo = $escrito[Memoria::chaveDoTitulo($alvo->listingTypeId)] ?? null;
            $daIa = TituloDoProduto::capitalizar(is_string($ultimo) ? $ultimo : null);
            if (trim((string) ($planejados[$alvo->listingTypeId] ?? '')) !== '' && (trim($atual) === '' || Memoria::aindaDaIa($atual, $daIa))) {
                continue;
            }
            if (Memoria::podeEscrever($atual, $daIa)) {
                $tipos[] = $alvo->listingTypeId;
            }
        }

        return $tipos;
    }

    /** A descrição ainda é do agente — o ramo da descrição de `PreparoIaDoRascunhoService::livres()`. */
    private static function descricaoLivre(PubRascunho $r, array $escrito): bool
    {
        $ultimo = $escrito[Memoria::DESCRICAO] ?? null;

        return Memoria::podeEscrever((string) ($r->descricao ?? ''), is_string($ultimo) ? $ultimo : null);
    }

    /** O Modelo ainda é do agente — o ramo do Modelo de `PreparoIaDoRascunhoService::livres()` (lido agora, depois do título). */
    private function modeloLivre(PubRascunho $r): bool
    {
        $atual = $this->repo->snapshot($r)->atributos['MODEL'] ?? null;
        if ($atual !== null && trim((string) ($atual['value_id'] ?? '')) !== '') {
            return false;
        }
        $ultimo = ((array) ($r->step_state[Memoria::ESCRITO] ?? []))[Memoria::MODELO] ?? null;

        return Memoria::podeEscrever((string) ($atual['value_name'] ?? ''), is_string($ultimo) ? $ultimo : null);
    }

    /** `PreparoIaDoRascunhoService::categoriaTemModelo()` (privado): o schema da categoria tem o atributo MODEL. */
    private function categoriaTemModelo(PubRascunho $r): bool
    {
        if (! $r->categoria_id) {
            return false;
        }
        try {
            $schema = $this->schemas->obter((string) $r->categoria_id);
        } catch (RegraViolada) {
            return false;
        }

        return collect($schema->atributos)->contains(fn ($a) => is_array($a) && ($a['id'] ?? null) === 'MODEL');
    }

    /**
     * Uma geração a mais no dia da LOJA — `PreparoIaDoRascunhoService::consumirCota()` (privado), com a MESMA chave e o
     * MESMO teto (`publicador.preparo_ia.limite_diario_por_empresa`): o ajuste gasta do mesmo orçamento do preparo.
     */
    private static function consumirCota(int $companyId): bool
    {
        $limite = (int) config('publicador.preparo_ia.limite_diario_por_empresa', 60);
        if ($limite <= 0) {
            return false;
        }
        $chave = "publicador:preparo:cota:{$companyId}:".now()->format('Y-m-d');
        Cache::add($chave, 0, now()->addDays(2));

        return (int) Cache::increment($chave) <= $limite;
    }

    // ═══ Leituras para a prévia, o GET, a linha (D60) e a 4d ══════════════════

    /**
     * O ajuste do anúncio do rascunho no contrato `{situacao, partes, restantes_hoje, mensagem}` — o do `GET …/ajuste` e o
     * do campo `ajuste` da prévia (o MESMO cálculo). Nulo: nunca pediu (ou o estado venceu).
     *
     * @param  ?int  $restantes  já contado (a prévia conta uma vez para os dois campos)
     * @return ?array{situacao: string, partes: array<string, array{situacao: string, mensagem: string}>, restantes_hoje: int, mensagem: string}
     */
    public static function situacao(?PubRascunho $r, ?int $restantes = null): ?array
    {
        if ($r === null) {
            return null;
        }
        $estado = self::ler((int) $r->produto_id);
        if ($estado === null) {
            return null;
        }

        return self::publico($estado, $restantes ?? self::restantesHoje($r));
    }

    /**
     * Quantos pedidos de ajuste o anúncio ainda tem hoje. 0 também quando o agente não tem o que refazer nele (o kit da fase
     * N e o rascunho sem preparo do agente): a tela só oferece o botão com saldo.
     */
    public static function restantesHoje(?PubRascunho $r): int
    {
        if ($r === null || ! self::doRobo($r->produto, $r)) {
            return 0;
        }

        return max(0, self::LIMITE_POR_DIA - self::pedidosHoje($r));
    }

    /** Os pedidos do rascunho desde a meia-noite de São Paulo (o `activity_log` do pedido). */
    public static function pedidosHoje(PubRascunho $r): int
    {
        $desde = Carbon::now(TrilhaOnda::FUSO)->startOfDay()->setTimezone((string) config('app.timezone'));

        return Activity::query()
            ->where('subject_type', $r->getMorphClass())
            ->where('subject_id', $r->id)
            ->where('properties->evento', self::EVENTO)
            ->where('created_at', '>=', $desde)
            ->count();
    }

    /**
     * D60, em lote para a linha da trilha: anúncio → as partes que o agente ainda está refazendo (rótulos, na ordem), só
     * dos ajustes em `fila`/`fazendo`. Uma leitura de cache para todos.
     *
     * @param  list<int>  $pubIds
     * @return array<int, list<string>>
     */
    public static function refazendoEmLote(array $pubIds): array
    {
        if ($pubIds === []) {
            return [];
        }
        $chaves = [];
        foreach ($pubIds as $id) {
            $chaves[self::chave((int) $id)] = (int) $id;
        }
        $saida = [];
        foreach (Cache::many(array_keys($chaves)) as $chave => $estado) {
            if (! isset($chaves[$chave]) || ! self::emAndamento(is_array($estado) ? $estado : null)) {
                continue;
            }
            $rotulos = [];
            foreach (self::PARTES as $p) {
                if (in_array($estado['partes'][$p]['situacao'] ?? null, [self::FILA, self::FAZENDO], true)) {
                    $rotulos[] = self::ROTULO[$p];
                }
            }
            $saida[$chaves[$chave]] = $rotulos !== [] ? $rotulos : array_map(fn ($p) => self::ROTULO[$p], array_keys((array) $estado['partes']));
        }

        return $saida;
    }

    /** Há um ajuste em `fila`/`fazendo` (e não vencido) para o anúncio? A 4d pergunta antes de publicar. */
    public static function andandoNoPub(int $pubId): bool
    {
        return self::emAndamento(self::ler($pubId));
    }

    // ═══ 409 e 422 ═══════════════════════════════════════════════════════════

    /**
     * 409 SEM `travado` pelo estado do anúncio (D47): na fila, publicando, publicado; fora da revisão (inclusive sem
     * rascunho); e o "Aprovar e publicar" (4d) conferindo agora — o agente gravaria depois da conferência.
     */
    private static function exigirQueDaParaAjustar(array $item, ?PubRascunho $r): void
    {
        $motivo = match (true) {
            $r !== null && NaFilaDePublicacao::emUso((int) $r->produto_id)                                        => self::MSG_NA_FILA_409,
            $r !== null && $r->status === PubRascunho::PUBLISHING                                                 => self::MSG_PUBLICANDO_409,
            $r !== null && in_array($r->status, [PubRascunho::PUBLISHED, PubRascunho::PARTIALLY_PUBLISHED], true) => self::MSG_PUBLICADO_409,
            $r === null || $item['etapa'] !== 'revisao'                                                           => self::MSG_FORA_DA_REVISAO,
            (PublicacaoNaTrilha::situacao($r)['situacao'] ?? null) === PublicacaoNaTrilha::CONFERINDO             => self::MSG_CONFERINDO_409,
            default                                                                                               => null,
        };
        if ($motivo !== null) {
            self::conflito($motivo);
        }
    }

    /** A regra da 4c/4d: ausente ou fora da forma = 422 `errors.revisao`; velha = 409 `{revisao_atual, message}`. */
    private static function exigirRevisao(PubRascunho $r, array $corpo): int
    {
        $revisao = $corpo['revisao'] ?? null;
        if (! is_int($revisao) && ! (is_string($revisao) && preg_match('/^[0-9]{1,9}$/', $revisao))) {
            throw ValidationException::withMessages(['revisao' => PrecoNaTrilha::MSG_RECARREGUE]);
        }
        if ((int) $revisao !== (int) $r->revisao) {
            throw new HttpResponseException(response()->json(['revisao_atual' => (int) $r->revisao, 'message' => PrecoNaTrilha::MSG_REVISAO], 409));
        }

        return (int) $revisao;
    }

    /**
     * `refazer`: lista com 1 ou 2 das partes ({@see PARTES}), sem repetir (a repetida conta uma vez); vazia, fora da forma
     * ou com qualquer outra coisa = 422 `errors.refazer`. Sai na ordem de {@see PARTES}.
     *
     * @return list<string>
     */
    private static function partes(array $corpo): array
    {
        $refazer = $corpo['refazer'] ?? null;
        if (! is_array($refazer) || ! array_is_list($refazer) || $refazer === []) {
            throw ValidationException::withMessages(['refazer' => self::MSG_REFAZER]);
        }
        foreach ($refazer as $p) {
            if (! is_string($p) || ! in_array($p, self::PARTES, true)) {
                throw ValidationException::withMessages(['refazer' => self::MSG_REFAZER]);
            }
        }

        return array_values(array_filter(self::PARTES, fn ($p) => in_array($p, $refazer, true)));
    }

    /**
     * O texto do anúncio é do agente: não é o kit da fase N (o texto dele tem fluxo próprio — `avaliarIa` devolve
     * `kit_da_fase`) e o rascunho tem o preparo do agente (`ia_preparo.hash`: sem ele o `executarEtapa` não grava nada).
     */
    private static function doRobo(?PubProduto $pub, PubRascunho $r): bool
    {
        return $pub !== null && $pub->produto_base_id === null
            && is_string($r->step_state[Memoria::PREPARO]['hash'] ?? null) && $r->step_state[Memoria::PREPARO]['hash'] !== '';
    }

    /** @throws HttpResponseException */
    private static function conflito(string $mensagem): never
    {
        throw new HttpResponseException(response()->json(['message' => $mensagem], 409));
    }

    // ═══ O estado em cache ═══════════════════════════════════════════════════

    public static function chave(int $pubId): string
    {
        return self::CHAVE_DO_ESTADO.$pubId;
    }

    private static function ler(int $pubId): ?array
    {
        $e = Cache::get(self::chave($pubId));

        return is_array($e) && isset($e['pedido'], $e['situacao'], $e['partes']) && is_array($e['partes']) ? $e : null;
    }

    /** Grava com o carimbo da última atualização (`em`, a régua do vencido) e devolve o que gravou. */
    private static function gravar(int $pubId, array $estado): array
    {
        $estado = [...$estado, 'em' => now()->toIso8601String()];
        Cache::put(self::chave($pubId), $estado, now()->addHours(self::HORAS_DO_ESTADO));

        return $estado;
    }

    private static function doPedido(?array $estado, string $pedido): bool
    {
        return $estado !== null && $estado['pedido'] === $pedido;
    }

    /** Lê-muda-grava o estado do MESMO pedido (o de outro pedido não é tocado). */
    private static function atualizar(int $pubId, string $pedido, callable $mudar): ?array
    {
        $estado = self::ler($pubId);
        if (! self::doPedido($estado, $pedido)) {
            return null;
        }
        return self::gravar($pubId, $mudar($estado));
    }

    /**
     * Grava o desfecho das partes e recalcula a situação: alguma parte ainda `fila`/`fazendo` → `fazendo`; senão `feito`
     * se alguma foi refeita ou mantida, `erro` se todas deram erro (a mensagem do erro, quando é uma só).
     *
     * @param  array<string, array>  $partes  só as que mudaram
     */
    private static function concluir(int $pubId, string $pedido, array $partes): string
    {
        $novo = self::atualizar($pubId, $pedido, function (array $e) use ($partes) {
            $todas = [...$e['partes'], ...$partes];
            $situacoes = array_map(fn ($s) => $s['situacao'] ?? self::ERRO, $todas);
            $erros = array_values(array_unique(array_map(fn ($s) => $s['mensagem'] ?? '', array_filter($todas, fn ($s) => ($s['situacao'] ?? null) === self::ERRO))));
            [$situacao, $mensagem] = match (true) {
                array_intersect($situacoes, [self::FILA, self::FAZENDO]) !== [] => [self::FAZENDO, self::MSG_FAZENDO],
                array_intersect($situacoes, [self::REFEITO, self::MANTIDO]) !== [] => [self::FEITO, self::MSG_FEITO],
                default => [self::ERRO, count($erros) === 1 && $erros[0] !== '' ? $erros[0] : self::MSG_ERRO],
            };

            return [...$e, 'partes' => $todas, 'situacao' => $situacao, 'mensagem' => $mensagem];
        });

        return $novo['situacao'] ?? 'superado';
    }

    /** `fila`/`fazendo` com a última atualização há menos de {@see ANDAMENTO_VALE_MIN} min. */
    private static function emAndamento(?array $estado): bool
    {
        return $estado !== null && in_array($estado['situacao'], [self::FILA, self::FAZENDO], true) && self::recente($estado['em'] ?? null);
    }

    private static function recente(mixed $em): bool
    {
        if (! is_string($em) || $em === '') {
            return false;
        }
        try {
            return Carbon::parse($em)->gte(now()->subMinutes(self::ANDAMENTO_VALE_MIN));
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Só as chaves do contrato (o cache guarda também o pedido, quem pediu, a revisão e o que o acompanhamento precisa). O
     * `fila`/`fazendo` vencido sai como `erro` (o Job morreu) — e o pedido novo passa.
     *
     * @return array{situacao: string, partes: array<string, array{situacao: string, mensagem: string}>, restantes_hoje: int, mensagem: string}
     */
    private static function publico(array $estado, int $restantes): array
    {
        $vencido = in_array($estado['situacao'], [self::FILA, self::FAZENDO], true) && ! self::emAndamento($estado);
        $partes = [];
        foreach (self::PARTES as $p) {
            if (! isset($estado['partes'][$p])) {
                continue;
            }
            $s = (array) $estado['partes'][$p];
            $situacao = (string) ($s['situacao'] ?? self::ERRO);
            $partes[$p] = $vencido && in_array($situacao, [self::FILA, self::FAZENDO], true)
                ? self::parte(self::ERRO, self::MSG_NAO_TERMINOU)
                : self::parte($situacao, (string) ($s['mensagem'] ?? ''));
        }

        return [
            'situacao'       => $vencido ? self::ERRO : (string) $estado['situacao'],
            'partes'         => $partes,
            'restantes_hoje' => $restantes,
            'mensagem'       => $vencido ? self::MSG_NAO_TERMINOU : (string) ($estado['mensagem'] ?? ''),
        ];
    }

    /** @return array{situacao: string, mensagem: string} */
    private static function parte(string $situacao, string $mensagem): array
    {
        return ['situacao' => $situacao, 'mensagem' => $mensagem];
    }

    /**
     * A memória `ia_escrito` de algumas chaves, em sha1 (nulo = vazia) — o "antes" do acompanhamento.
     *
     * @param  list<string>  $chaves
     * @return array<string, ?string>
     */
    private static function memoria(array $escrito, array $chaves): array
    {
        $saida = [];
        foreach ($chaves as $c) {
            $saida[$c] = is_string($escrito[$c] ?? null) ? sha1($escrito[$c]) : null;
        }

        return $saida;
    }

    /** O prazo de UMA chamada: o do preparo, sem passar do orçamento do Job. */
    private static function prazo(float $fim): float
    {
        return min(microtime(true) + self::PRAZO_S, $fim);
    }

    /** A passada do acompanhamento: um minuto depois de cada nova tentativa do Publicador (`adiar_min`). */
    private static function esperaDoAcompanhamento(): int
    {
        return max(1, (int) config('publicador.preparo_ia.adiar_min', 5)) + 1;
    }
}
