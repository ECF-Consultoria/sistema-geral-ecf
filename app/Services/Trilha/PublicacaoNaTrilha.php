<?php

namespace App\Services\Trilha;

use App\Contracts\ContaMercadoLivre;
use App\Jobs\Trilha\AndarFilaDePublicacaoJob;
use App\Jobs\Trilha\PublicarPelaTrilhaJob;
use App\Models\Company;
use App\Models\MlbEmpresa;
use App\Models\PubFilaPublicacaoItem;
use App\Models\PubProduto;
use App\Models\PubPublicacao;
use App\Models\PubPublicacaoItem;
use App\Models\PubRascunho;
use App\Models\PubValidacao;
use App\Models\User;
use App\Services\Portal\Estrutura\RegistroEstrutura;
use App\Services\Publicador\ConferenciaService;
use App\Services\Publicador\Fila\FilaPublicacaoService;
use App\Services\Publicador\ProgramasPublicadorService;
use App\Support\Portal\AtorDoPortal;
use App\Support\Publicador\Erros\MapeadorErrosMl;
use App\Support\Publicador\NaFilaDePublicacao;
use App\Support\Publicador\Validacao\Problema;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Aprovar e publicar pela trilha (4ª entrega, fatia 4d, desenho §19.13, D47–D50 e D59): a equipe aprova a prévia do
 * anúncio e ele vai para a FILA de publicação da loja — a mesma fila do Publicador, que é quem fala com o Mercado Livre.
 * A trilha nunca publica: ela confere e agenda.
 *
 * ### A guarda da #459 é CÓDIGO, não configuração (D48)
 * {@see LOJA} é constante de propósito: nenhum `.env` liga esta fatia para outra loja (a `ContasLiberadas` NÃO protege a
 * #459 — `publicador.contas_liberadas.todas` está ligada em produção desde 26/10). {@see daLoja()} confere TRÊS coisas e
 * roda no pedido E dentro do Job (antes de conferir e de novo antes de agendar — o Job não confia no controller):
 * 1. a Company do ENDEREÇO;
 * 2. o `pub_produtos.company_id` do anúncio;
 * 3. as âncoras da conta que podem publicá-lo — a MlbEmpresa do anúncio (se houver) e a que tem token agora
 *    (`contaOuNula()`, a que a publicação usaria): Company 459, ou MlbEmpresa com `company_id` 459. Conferir as
 *    CANDIDATAS (e não só a de agora) fecha a janela em que o token muda entre o agendar e a publicação.
 * Fora disso: 403 com a mensagem neutra {@see MSG_LOJA}, nada na fila, nada no Mercado Livre.
 *
 * ### O pedido (`POST …/publicar`), nesta ordem (§19.13)
 * 404 chaves → 403 escrita (o controller) → 404 item → 403 fora da #459 → 409 (na fila → publicando → publicado → fora
 * da revisão → o agente refazendo o texto (4b); depois a `revisao` velha, SEM `travado`, com `revisao_atual`; e a prévia
 * não pronta) → 422 (Premium =
 * Clássico em TODAS as cores ativas — D46, a régua do `premium_igual` da prévia, a mesma do {@see PrecoNaTrilha}; a 4c
 * confere só as cores tocadas). Passou: grava o estado `conferindo` e enfileira o {@see PublicarPelaTrilhaJob}.
 *
 * 4e (04/11/2026, §19.16): na loja que aprova com o cliente ({@see \App\Support\Trilha\AprovacaoDoCliente}), a equipe NÃO
 * publica direto — 409 depois do estado, salvo o item que o cliente aprovou na MESMA revisão e cujo envio não publicou
 * (D56; {@see AprovacaoDoClienteNaTrilha::exigirAprovacaoParaPublicar()}). A aprovação do cliente entra por aqui mesmo
 * (`$peloCliente`), com o `User` que mandou ao cliente: a guarda da #459 e todo o resto valem igual.
 *
 * ### O Job ({@see executar()})
 * Refaz a guarda D48 → confere que a `revisao` é a que a pessoa aprovou → `ConferenciaService::conferir` (fotos, validate)
 * → erro = `recusado`; avisos sem `ciente` = `avisos`; senão refaz a D48 e `FilaPublicacaoService::agendar(resolver(
 * 'company-459'), [pub], quem aprovou, ['ciente' => …])` → `na_fila`. NUNCA o portão de aprovação, NUNCA o
 * `PublicacaoService::iniciar` direto, NUNCA `POST /items` (D49): quem publica é o `AgendadorDaFila`, que confere de novo
 * (o plano e a revisão gravados no item da fila).
 *
 * ### A situação ({@see situacao()})
 * O estado do pedido mora em cache (`trilha:publicar:{pub}`, 6 h). Depois de `na_fila`, ela sai da fila e da publicação
 * de VERDADE (`pub_fila_publicacao_itens` e `pub_publicacoes`): `na_fila` → `publicando` → `publicado` (com os MLBs) |
 * `erro`. Sem pedido da trilha, a situação só aparece quando o anúncio está VIVO na fila (agendado ou publicando) ou
 * publicando agora por outro caminho — o mesmo motivo do 409, para a tela não oferecer [Aprovar e publicar] à toa.
 *
 * Todo texto que sai daqui é neutro ({@see neutro()}): os da conferência e da fila citam o Mercado Livre e o Publicador.
 */
class PublicacaoNaTrilha
{
    /** A ÚNICA loja em que a trilha publica (D48). Constante de propósito: não vem do `.env`. */
    public const LOJA = 459;

    /** O estado do pedido, por pub_produto (§19.13). */
    public const CHAVE_DO_ESTADO = 'trilha:publicar:';

    public const HORAS_DO_ESTADO = 6;

    /** `conferindo` mais velho que isto é Job que morreu (o `timeout` dele é 5 min; a `high` pode ter fila). */
    public const CONFERINDO_VALE_MIN = 30;

    public const CONFERINDO = 'conferindo';
    public const AVISOS = 'avisos';
    public const NA_FILA = 'na_fila';
    public const PUBLICANDO = 'publicando';
    public const PUBLICADO = 'publicado';
    public const RECUSADO = 'recusado';
    public const ERRO = 'erro';

    public const MSG_LOJA = 'Publicar pela trilha ainda não está liberado para esta loja.';

    public const MSG_NA_FILA_409 = 'Este anúncio já está na fila de publicação.';

    public const MSG_PUBLICANDO_409 = 'Este anúncio está sendo publicado agora.';

    public const MSG_PUBLICADO_409 = 'Este anúncio já foi publicado.';

    public const MSG_FORA_DA_REVISAO = 'Este anúncio não está na revisão: só dá para publicar quando ele estiver pronto para revisar.';

    public const MSG_NAO_PRONTA = 'A prévia deste anúncio ainda não está pronta.';

    public const MSG_CIENTE = 'Diga se está ciente dos avisos (sim ou não).';

    public const MSG_CONFERINDO = 'Conferindo o anúncio antes de publicar…';

    public const MSG_AVISOS = 'A conferência deu avisos. Se estiver de acordo, publique assim mesmo.';

    public const MSG_NA_FILA = 'Na fila de publicação.';

    public const MSG_PUBLICANDO = 'Publicando agora…';

    public const MSG_PUBLICADO = 'Publicado. Nas primeiras horas o anúncio fica em análise antes de ir à venda.';

    public const MSG_PUBLICADO_EM_PARTE = 'Publicado em parte: nem todos os anúncios entraram. Confira o anúncio.';

    public const MSG_RECUSADO = 'A conferência encontrou problemas que impedem publicar. Corrija e tente de novo.';

    public const MSG_CONTA = 'A conta da loja não está liberada para publicar agora.';

    public const MSG_RECONECTAR = 'A conta da loja precisa ser reconectada antes de publicar.';

    public const MSG_MUDOU = 'O anúncio mudou depois que você aprovou. Confira a prévia de novo e aprove.';

    public const MSG_SEM_PESSOA = 'Quem aprovou não está mais ativo no sistema. Aprove de novo.';

    public const MSG_FILA_RECUSOU = 'A fila de publicação não aceitou este anúncio.';

    public const MSG_NAO_TERMINOU = 'A conferência não terminou. Tente de novo em instantes.';

    public const MSG_FALHOU = 'A publicação não deu certo. Confira o anúncio e tente de novo.';

    public const MSG_PRECISA_REVISAR = 'O anúncio mudou depois de conferido e não foi publicado. Confira e aprove de novo.';

    public const MSG_CANCELADO = 'A publicação foi cancelada antes de começar.';

    public const MSG_PULADO = 'A publicação foi pulada. Confira o anúncio e aprove de novo.';

    public const MSG_SAIU_DA_FILA = 'O anúncio saiu da fila de publicação sem ser publicado.';

    /** A mensagem de reserva de cada situação (toda `publicacao` sai com `mensagem` — a tela conta com ela). */
    private const MENSAGEM = [
        self::CONFERINDO => self::MSG_CONFERINDO, self::AVISOS => self::MSG_AVISOS, self::NA_FILA => self::MSG_NA_FILA,
        self::PUBLICANDO => self::MSG_PUBLICANDO, self::PUBLICADO => self::MSG_PUBLICADO, self::RECUSADO => self::MSG_RECUSADO,
        self::ERRO => self::MSG_NAO_TERMINOU,
    ];

    /** Quantos problemas da conferência vão para `avisos` (a tela lista; o resto é "e mais N"). */
    private const AVISOS_NA_LISTA = 12;

    private const TIPO = ['gold_special' => 0, 'gold_pro' => 1];

    public function __construct(
        private PreviaNaTrilha $previa,
        private ConferenciaService $conferencia,
        private FilaPublicacaoService $fila,
        private ProgramasPublicadorService $programas,
    ) {}

    // ═══ O pedido ════════════════════════════════════════════════════════════

    /**
     * O `POST …/ofertas/{item}/publicar`. Corpo `{revisao, ciente}`. Devolve `{publicacao: {situacao: 'conferindo', …}}`
     * (o controller responde 202). Dois cliques seguidos não enfileiram duas conferências: com uma `conferindo` recente,
     * devolve a mesma, sem Job novo.
     *
     * @param  array<string, mixed>  $corpo
     * @return array{publicacao: array{situacao: string, mensagem: string, avisos: list<string>, mlbs: list<string>}}
     *
     * @throws HttpResponseException 403 (fora da #459) e 409
     * @throws ValidationException 422
     */
    public function pedir(Company $empresa, string $chave, array $corpo, User $quem, bool $peloCliente = false): array
    {
        $item = TrilhaDaEmpresa::itens($empresa, comoNaTela: true)[$chave] ?? null;
        abort_if($item === null, 404);

        $r = PreviaNaTrilha::rascunho($item);
        $pub = $r?->produto;
        self::exigirLoja((int) $empresa->id, $pub);
        self::exigirQueDaParaPublicar($item, $r);
        /** @var PubRascunho $r */
        /** @var PubProduto $pub */
        // 4e: a loja que aprova com o cliente não publica direto pela equipe (a aprovação dele não valeria nada) — só o
        // item que ele aprovou nesta revisão e não publicou (D56). A aprovação do cliente já conferiu isso e entra aqui
        // com `$peloCliente`.
        if (! $peloCliente) {
            AprovacaoDoClienteNaTrilha::exigirAprovacaoParaPublicar($empresa, $r);
        }
        // 4b: com o agente refazendo o texto, a conferência validaria o texto velho e a escrita dele viria depois — 409 até
        // o ajuste terminar (a tela já desliga o botão; esta é a regra do servidor).
        if (AjusteDoRoboNaTrilha::andandoNoPub((int) $pub->id)) {
            throw new HttpResponseException(response()->json(['message' => AjusteDoRoboNaTrilha::MSG_PUBLICAR_COM_AJUSTE], 409));
        }
        $revisao = self::exigirRevisao($r, $corpo);
        $ciente = self::ciente($corpo);

        $previa = $this->previa->montar($empresa, $chave);
        if (! $previa['pronta']) {
            throw new HttpResponseException(response()->json(['message' => (string) ($previa['motivo'] ?? self::MSG_NAO_PRONTA)], 409));
        }
        // D46 no publicar: TODAS as cores ativas (o `premium_igual` da prévia olha o preço do plano de cada cor — o que
        // vai ao anúncio), não só as que um pedido de preço tocou.
        if ($previa['preco']['premium_igual'] ?? false) {
            throw ValidationException::withMessages(['precos.premium' => PrecoNaTrilha::MSG_PREMIUM_IGUAL]);
        }

        $trava = Cache::lock(self::CHAVE_DO_ESTADO.$pub->id.':pedido', 10);
        if (! $trava->get()) {
            return ['publicacao' => self::situacao($r) ?? self::estado(self::CONFERINDO, self::MSG_CONFERINDO)];
        }
        try {
            $atual = Cache::get(self::chave((int) $pub->id));
            if (is_array($atual) && ($atual['situacao'] ?? null) === self::CONFERINDO && ! self::velho($atual)) {
                return ['publicacao' => self::publico($atual)];
            }

            self::gravar((int) $pub->id, self::estado(self::CONFERINDO, self::MSG_CONFERINDO) + [
                'revisao' => $revisao, 'ciente' => $ciente, 'por' => (int) $quem->id, 'item' => $chave,
            ]);
            // O ator é quem publica (na fila, `criada_por` é o mesmo usuário). Pela aprovação do cliente (4e), é quem mandou
            // a prévia a ele — quem aprovou de fato fica no `trilha_aprovacao_aprovada`, com o ator dele.
            RegistroEstrutura::registrar(AtorDoPortal::daEquipe($quem), $empresa, $r, 'trilha_publicacao_pedida',
                $peloCliente ? "Publicação de \"{$item['nome']}\" pedida pela aprovação do cliente"
                    : "Publicação de \"{$item['nome']}\" aprovada na revisão".($ciente ? ' (ciente dos avisos)' : ''), [
                    'item' => $chave, 'pub_produto_id' => (int) $pub->id, 'rascunho_id' => (int) $r->id, 'revisao' => $revisao, 'ciente' => $ciente,
                    'via' => $peloCliente ? 'aprovacao_do_cliente' : 'revisao',
                ]);
            PublicarPelaTrilhaJob::dispatch((int) $empresa->id, $chave, (int) $pub->id, (int) $quem->id, $revisao, $ciente);
        } finally {
            $trava->release();
        }

        return ['publicacao' => self::estado(self::CONFERINDO, self::MSG_CONFERINDO)];
    }

    /**
     * O `GET …/ofertas/{item}/publicacao`: a mesma guarda até a #459 (404 item, 403 fora dela) e a situação
     * ({@see situacao()}), ou `null` quando a trilha nunca pediu e nada está vivo na fila.
     *
     * @return array{publicacao: ?array{situacao: string, mensagem: string, avisos: list<string>, mlbs: list<string>}}
     */
    public function acompanhar(Company $empresa, string $chave): array
    {
        $item = TrilhaDaEmpresa::itens($empresa)[$chave] ?? null;
        abort_if($item === null, 404);

        $r = PreviaNaTrilha::rascunho($item);
        self::exigirLoja((int) $empresa->id, $r?->produto);

        return ['publicacao' => self::situacao($r)];
    }

    // ═══ O Job ═══════════════════════════════════════════════════════════════

    /**
     * O que o {@see PublicarPelaTrilhaJob} faz. Devolve o que aconteceu (log e testes): `loja`, `sumiu`, `sem_pessoa`,
     * `ocupado`, `mudou`, `recusado:<RESULTADO>`, `avisos`, `na_fila`, `fila_recusou`.
     */
    public function executar(int $empresaId, string $chave, int $pubId, int $userId, int $revisao, bool $ciente): string
    {
        $pub = PubProduto::query()->find($pubId);
        $r = $pub !== null ? PubRascunho::query()->where('produto_id', $pub->id)->first() : null;
        if ($pub === null || $r === null) {
            self::gravar($pubId, self::estado(self::ERRO, self::MSG_NAO_TERMINOU));

            return 'sumiu';
        }
        // D48 de novo: o Job não confia no controller (o anúncio pode ter trocado de âncora enquanto esperava na fila).
        if (! self::daLoja($empresaId, $pub)) {
            Log::warning("[Trilha] Publicar: o anúncio {$pubId} (item {$chave}, empresa {$empresaId}) não é da loja ".self::LOJA.'; nada conferido nem agendado.');
            self::gravar($pubId, self::estado(self::RECUSADO, self::MSG_LOJA));

            return 'loja';
        }
        $quem = User::query()->find($userId);
        if ($quem === null || $quem->active === false) {
            self::gravar($pubId, self::estado(self::RECUSADO, self::MSG_SEM_PESSOA));

            return 'sem_pessoa';
        }
        // Na fila ou publicando por outro caminho enquanto o Job esperava: nada é conferido; a situação passa a sair da
        // fila e da publicação de verdade (como depois de `na_fila`).
        $vivo = PubFilaPublicacaoItem::query()->where('produto_ativo', $pubId)->first();
        if ($vivo !== null || in_array($r->status, [PubRascunho::PUBLISHING, PubRascunho::PUBLISHED, PubRascunho::PARTIALLY_PUBLISHED], true)) {
            self::gravar($pubId, self::estado(self::NA_FILA, self::MSG_NA_FILA) + ['fila_item_id' => $vivo?->id]);

            return 'ocupado';
        }
        // A pessoa aprovou a revisão N: a N+1 (o preço de outra aba, o agente) ela não viu.
        if ((int) $r->revisao !== $revisao) {
            self::gravar($pubId, self::estado(self::RECUSADO, self::MSG_MUDOU));

            return 'mudou';
        }

        $v = $this->conferencia->conferir($r);

        if (! in_array($v->resultado, [ConferenciaService::OK, ConferenciaService::AVISOS], true)) {
            self::gravar($pubId, self::recusadoPelaConferencia($v));

            return 'recusado:'.$v->resultado;
        }
        if ($v->resultado === ConferenciaService::AVISOS && ! $ciente) {
            self::gravar($pubId, self::estado(self::AVISOS, self::MSG_AVISOS, self::problemas($v, Problema::AVISO)) + ['revisao' => $revisao]);

            return 'avisos';
        }

        // D48 antes de agendar (a conferência levou segundos e chamou o ML): ainda é da loja, e a conta da fila também.
        $pub = $pub->fresh() ?? $pub;
        $alvo = $this->programas->resolver('company-'.self::LOJA);
        if (! self::daLoja($empresaId, $pub) || $alvo === null || ! self::alvoDaLoja($alvo)) {
            Log::warning("[Trilha] Publicar: o anúncio {$pubId} deixou de ser da loja ".self::LOJA.' durante a conferência; nada agendado.');
            self::gravar($pubId, self::estado(self::RECUSADO, self::MSG_LOJA));

            return 'loja';
        }

        $resultado = $this->fila->agendar($alvo, [$pubId], $quem, ['ciente' => $ciente]);
        $vivo = PubFilaPublicacaoItem::query()->where('produto_ativo', $pubId)->first();
        if (in_array($pubId, $resultado['agendados'], true) || $vivo !== null) {
            self::gravar($pubId, self::estado(self::NA_FILA, self::MSG_NA_FILA) + ['fila_item_id' => $vivo?->id, 'revisao' => $revisao]);
            Log::info("[Trilha] Publicar: o anúncio {$pubId} (item {$chave}) entrou na fila de publicação da loja ".self::LOJA." por {$quem->name}.");
            // R5 (retorno do 1º teste, 04/11/2026): a passada da fila NA HORA, em vez de esperar a do minuto — a mesma
            // `AgendadorDaFila::rodar()`, com todas as checagens dela e a mesma trava de sobreposição do agendamento.
            AndarFilaDePublicacaoJob::dispatch();

            return 'na_fila';
        }
        $motivo = (string) ($resultado['recusados'][$pubId] ?? '');
        self::gravar($pubId, self::estado(self::RECUSADO, self::MSG_FILA_RECUSOU, $motivo !== '' ? [self::neutro($motivo)] : []));

        return 'fila_recusou';
    }

    /** O Job quebrou (ou estourou o tempo): a tela não pode ficar em "conferindo" para sempre. */
    public static function quebrou(int $pubId): void
    {
        self::gravar($pubId, self::estado(self::ERRO, self::MSG_NAO_TERMINOU));
    }

    // ═══ A guarda da #459 (D48) ══════════════════════════════════════════════

    /**
     * O anúncio é da #459 pelas três conferências (a classe explica). `$pub` nulo (o item ainda sem anúncio): só a
     * empresa — o resto da guarda o recusa como "fora da revisão".
     */
    public static function daLoja(int $empresaId, ?PubProduto $pub): bool
    {
        // 1. A Company do endereço.
        if ($empresaId !== self::LOJA) {
            return false;
        }
        if ($pub === null) {
            return true;
        }
        // 2. O dono do anúncio no Publicador.
        if ((int) $pub->company_id !== self::LOJA) {
            return false;
        }
        // 3. As âncoras que podem publicá-lo: a MlbEmpresa dele (se houver) e a que tem token agora.
        foreach ([$pub->mlbEmpresa, $pub->contaOuNula()] as $ancora) {
            if ($ancora !== null && self::donoDaAncora($ancora) !== self::LOJA) {
                return false;
            }
        }

        return true;
    }

    /** @throws HttpResponseException 403 com a mensagem neutra */
    private static function exigirLoja(int $empresaId, ?PubProduto $pub): void
    {
        if (! self::daLoja($empresaId, $pub)) {
            throw new HttpResponseException(response()->json(['message' => self::MSG_LOJA], 403));
        }
    }

    /** A Company dona da âncora: ela mesma, ou a da MlbEmpresa (nula = de ninguém). */
    private static function donoDaAncora(ContaMercadoLivre $ancora): ?int
    {
        return match (true) {
            $ancora instanceof Company    => (int) $ancora->id,
            $ancora instanceof MlbEmpresa => $ancora->company_id !== null ? (int) $ancora->company_id : null,
            default                       => null,
        };
    }

    /** A conta da fila (`resolver('company-459')`) é da #459. */
    private static function alvoDaLoja(array $alvo): bool
    {
        $company = $alvo['company'] ?? null;
        $mlb = $alvo['mlb_empresa'] ?? null;

        return $company instanceof Company && (int) $company->id === self::LOJA
            && ($mlb === null || ($mlb instanceof MlbEmpresa && (int) $mlb->company_id === self::LOJA));
    }

    // ═══ 409 e 422 ═══════════════════════════════════════════════════════════

    /**
     * 409 SEM `travado` pelo ESTADO (D47): na fila, publicando, publicado; e, fora disso, fora da revisão (inclusive sem
     * rascunho). O estado vem antes da revisão: recarregar não tira da fila.
     *
     * @throws HttpResponseException
     */
    private static function exigirQueDaParaPublicar(array $item, ?PubRascunho $r): void
    {
        $motivo = match (true) {
            $r !== null && NaFilaDePublicacao::emUso((int) $r->produto_id)                                        => self::MSG_NA_FILA_409,
            $r !== null && $r->status === PubRascunho::PUBLISHING                                                 => self::MSG_PUBLICANDO_409,
            $r !== null && in_array($r->status, [PubRascunho::PUBLISHED, PubRascunho::PARTIALLY_PUBLISHED], true) => self::MSG_PUBLICADO_409,
            $r === null || $item['etapa'] !== 'revisao'                                                           => self::MSG_FORA_DA_REVISAO,
            default                                                                                               => null,
        };
        if ($motivo !== null) {
            throw new HttpResponseException(response()->json(['message' => $motivo], 409));
        }
    }

    /**
     * A `revisao` que a tela tinha — a regra da 4c ({@see PrecoNaTrilha}): ausente ou fora da forma = 422
     * `errors.revisao`; diferente da atual = 409 `{revisao_atual, message}` SEM `travado`.
     *
     * @throws HttpResponseException|ValidationException
     */
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
     * `ciente`: ausente = não; `true`/`false` (ou 1/0, "1"/"0", "true"/"false", vindos de formulário). Outra coisa: 422.
     *
     * @throws ValidationException
     */
    private static function ciente(array $corpo): bool
    {
        if (! array_key_exists('ciente', $corpo) || $corpo['ciente'] === null) {
            return false;
        }
        $valor = is_bool($corpo['ciente']) || is_int($corpo['ciente']) || is_string($corpo['ciente'])
            ? filter_var($corpo['ciente'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) : null;
        if ($valor === null || (is_int($corpo['ciente']) && ! in_array($corpo['ciente'], [0, 1], true))) {
            throw ValidationException::withMessages(['ciente' => self::MSG_CIENTE]);
        }

        return $valor;
    }

    // ═══ A situação ══════════════════════════════════════════════════════════

    /**
     * A situação da publicação do anúncio do rascunho, no contrato `{situacao, mensagem, avisos, mlbs}` — a do `GET
     * …/publicacao` e a do campo `publicacao` da prévia (o mesmo cálculo). Nula: a trilha não pediu (ou o pedido venceu
     * as 6 h) e nada está vivo na fila nem publicando.
     *
     * Ordem: o item VIVO na fila vence tudo (é o que vai acontecer); depois o pedido da trilha (do cache) — e, depois de
     * `na_fila`, o item da fila que ele entrou, de verdade; sem pedido, o rascunho publicando agora por outro caminho.
     *
     * @return array{situacao: string, mensagem: string, avisos: list<string>, mlbs: list<string>}|null
     */
    public static function situacao(?PubRascunho $r): ?array
    {
        if ($r === null) {
            return null;
        }
        $pubId = (int) $r->produto_id;
        $vivo = PubFilaPublicacaoItem::query()->where('produto_ativo', $pubId)->first();
        if ($vivo !== null) {
            return self::daFila($vivo, $r);
        }

        $estado = Cache::get(self::chave($pubId));
        if (! is_array($estado) || ! isset($estado['situacao'])) {
            return $r->status === PubRascunho::PUBLISHING ? self::estado(self::PUBLICANDO, self::MSG_PUBLICANDO) : null;
        }

        return match ($estado['situacao']) {
            self::CONFERINDO => self::velho($estado) ? self::estado(self::ERRO, self::MSG_NAO_TERMINOU) : self::publico($estado),
            self::NA_FILA, self::PUBLICANDO, self::PUBLICADO => self::depoisDaFila($estado, $r),
            default => self::publico($estado),
        };
    }

    /** Depois de `na_fila`: o item da fila em que ele entrou; sem ele (apagado), o rascunho e a última publicação. */
    private static function depoisDaFila(array $estado, PubRascunho $r): array
    {
        $item = isset($estado['fila_item_id']) ? PubFilaPublicacaoItem::query()->find((int) $estado['fila_item_id']) : null;
        if ($item !== null) {
            return self::daFila($item, $r);
        }
        if (in_array($r->status, [PubRascunho::PUBLISHED, PubRascunho::PARTIALLY_PUBLISHED], true)) {
            $p = PubPublicacao::query()->where('rascunho_id', $r->id)->latest('id')->first();

            return self::estado(self::PUBLICADO, $r->status === PubRascunho::PUBLISHED ? self::MSG_PUBLICADO : self::MSG_PUBLICADO_EM_PARTE, [], self::mlbs($p, null));
        }
        if ($r->status === PubRascunho::PUBLISHING) {
            return self::estado(self::PUBLICANDO, self::MSG_PUBLICANDO);
        }

        return self::estado(self::ERRO, self::MSG_SAIU_DA_FILA);
    }

    /**
     * Do item da fila: agendado → `na_fila`; publicando → `publicando` até a publicação terminar (o agendador só fecha o
     * item na passada seguinte — a publicação terminada já vale); publicado/parcial → `publicado` com os MLBs; o resto →
     * `erro` com o motivo da fila (neutro).
     */
    private static function daFila(PubFilaPublicacaoItem $item, PubRascunho $r): array
    {
        $p = $item->publicacao_id !== null ? PubPublicacao::query()->find($item->publicacao_id) : null;

        if ($item->status === PubFilaPublicacaoItem::AGENDADO) {
            return self::estado(self::NA_FILA, self::MSG_NA_FILA);
        }
        if ($item->status === PubFilaPublicacaoItem::PUBLICANDO) {
            if ($p === null || $p->status === PubPublicacao::RUNNING) {
                return self::estado(self::PUBLICANDO, self::MSG_PUBLICANDO);
            }
            $mlbs = self::mlbs($p, null);

            return match (true) {
                $p->status === PubPublicacao::PUBLISHED => self::estado(self::PUBLICADO, self::MSG_PUBLICADO, [], $mlbs),
                $mlbs !== []                            => self::estado(self::PUBLICADO, self::MSG_PUBLICADO_EM_PARTE, [], $mlbs),
                default                                 => self::estado(self::ERRO, self::MSG_FALHOU),
            };
        }
        if (in_array($item->status, PubFilaPublicacaoItem::NO_AR, true)) {
            return self::estado(self::PUBLICADO, $item->status === PubFilaPublicacaoItem::PUBLICADO ? self::MSG_PUBLICADO : self::MSG_PUBLICADO_EM_PARTE,
                [], self::mlbs($p, $item));
        }

        $mensagem = match ($item->status) {
            PubFilaPublicacaoItem::PRECISA_REVISAR => self::MSG_PRECISA_REVISAR,
            PubFilaPublicacaoItem::CANCELADO       => self::MSG_CANCELADO,
            PubFilaPublicacaoItem::PULADO          => self::MSG_PULADO,
            default                                => self::MSG_FALHOU,
        };

        return self::estado(self::ERRO, $mensagem, trim((string) $item->motivo) !== '' ? [self::neutro((string) $item->motivo)] : []);
    }

    /**
     * Os MLBs criados, Clássico antes do Premium: os itens `CREATED` da publicação; sem ela, os que a fila guardou ao
     * fechar o item (`resumo.mlbs`).
     *
     * @return list<string>
     */
    private static function mlbs(?PubPublicacao $p, ?PubFilaPublicacaoItem $item): array
    {
        $linhas = $p !== null
            ? PubPublicacaoItem::query()->where('publicacao_id', $p->id)->where('status', PubPublicacaoItem::CREATED)->whereNotNull('ml_item_id')
                ->orderBy('indice')->orderBy('id')->get(['ml_item_id', 'listing_type_id'])
                ->map(fn ($i) => ['ml_item_id' => $i->ml_item_id, 'listing_type_id' => $i->listing_type_id])->all()
            : [];
        if ($linhas === [] && $item !== null) {
            $linhas = array_values((array) ($item->resumo['mlbs'] ?? []));
        }

        $saida = [];
        foreach ($linhas as $l) {
            $mlb = strtoupper(trim((string) ($l['ml_item_id'] ?? '')));
            if ($mlb !== '') {
                $saida[$mlb] = self::TIPO[(string) ($l['listing_type_id'] ?? '')] ?? 2;
            }
        }
        uksort($saida, fn ($a, $b) => [$saida[$a], $a] <=> [$saida[$b], $b]);

        return array_keys($saida);
    }

    /** A conferência que não liberou: a conta (só local), a que não terminou, e os bloqueios (erros) em texto neutro. */
    private static function recusadoPelaConferencia(PubValidacao $v): array
    {
        if ($v->resultado === ConferenciaService::LOCAL) {
            $motivo = (string) ($v->respostas_ml['motivo'] ?? '');

            return self::estado(self::RECUSADO, $motivo === 'V-ACC-01' ? self::MSG_RECONECTAR : self::MSG_CONTA);
        }
        $erros = self::problemas($v, Problema::BLOQUEIO);

        return self::estado(self::RECUSADO, $v->resultado === ConferenciaService::ERRO && $erros === [] ? self::MSG_NAO_TERMINOU : self::MSG_RECUSADO, $erros);
    }

    /**
     * As mensagens dos problemas da conferência de uma severidade, como a visão rápida do lote as lê (sem o ruído do ML e
     * com a tradução de hoje), em texto neutro, sem repetir.
     *
     * @return list<string>
     */
    private static function problemas(PubValidacao $v, string $severidade): array
    {
        $dicionario = (array) config('publicador_erros', []);
        $saida = [];
        foreach ((array) $v->issues as $i) {
            $i = (array) $i;
            if (($i['severidade'] ?? null) !== $severidade || MapeadorErrosMl::ehRuido((array) ($i['ml_causa'] ?? []))) {
                continue;
            }
            $texto = self::neutro((string) (MapeadorErrosMl::comTraducaoDeHoje($i, $dicionario)['mensagem'] ?? ''));
            if ($texto !== '') {
                $saida[$texto] = true;
            }
        }
        $lista = array_keys($saida);
        if (count($lista) > self::AVISOS_NA_LISTA) {
            $mais = count($lista) - self::AVISOS_NA_LISTA;
            $lista = [...array_slice($lista, 0, self::AVISOS_NA_LISTA), "E mais {$mais}."];
        }

        return $lista;
    }

    /**
     * O texto da conferência e da fila sem o nome da plataforma, do Publicador nem do Portal (D59 — a trilha vira a porta
     * do cliente). "Mercado Livre" → "canal de venda" (o artigo masculino de antes continua certo: "o", "do", "no",
     * "pelo"). R9 (04/11/2026): a IA da trilha é "o agente" para a equipe E o cliente — o "agente" que vem do Publicador
     * passa como está, e um "robô" vira "agente" (também masculino: os artigos seguem certos).
     */
    public static function neutro(string $texto): string
    {
        $texto = trim((string) preg_replace(
            ['/\bMercado\s+Livre\b/iu', '/\bPublicador\b/iu', '/\bPortal\b/iu', '/\bRob\x{F4}\b/u', '/\brob\x{F4}\b/iu'],
            ['canal de venda', 'sistema', 'cadastro', 'Agente', 'agente'],
            $texto,
        ));

        return $texto === '' ? '' : mb_strtoupper(mb_substr($texto, 0, 1)).mb_substr($texto, 1);
    }

    // ═══ Apoio ═══════════════════════════════════════════════════════════════

    public static function chave(int $pubId): string
    {
        return self::CHAVE_DO_ESTADO.$pubId;
    }

    /**
     * @param  list<string>  $avisos
     * @param  list<string>  $mlbs
     * @return array{situacao: string, mensagem: string, avisos: list<string>, mlbs: list<string>}
     */
    private static function estado(string $situacao, string $mensagem, array $avisos = [], array $mlbs = []): array
    {
        return ['situacao' => $situacao, 'mensagem' => $mensagem, 'avisos' => array_values($avisos), 'mlbs' => array_values($mlbs)];
    }

    /**
     * Só as chaves do contrato (o cache guarda também revisão, quem pediu, quando e o item da fila). A `mensagem` nunca
     * sai vazia (a tela a mostra em toda situação): sem ela no estado, a da situação.
     */
    private static function publico(array $estado): array
    {
        $situacao = (string) $estado['situacao'];
        $mensagem = trim((string) ($estado['mensagem'] ?? ''));

        return self::estado($situacao, $mensagem !== '' ? $mensagem : (self::MENSAGEM[$situacao] ?? self::MSG_NAO_TERMINOU),
            array_values(array_map('strval', (array) ($estado['avisos'] ?? []))), array_values(array_map('strval', (array) ($estado['mlbs'] ?? []))));
    }

    private static function gravar(int $pubId, array $estado): void
    {
        Cache::put(self::chave($pubId), [...$estado, 'em' => now()->toIso8601String()], now()->addHours(self::HORAS_DO_ESTADO));
    }

    /**
     * O `conferindo` gravado há mais de {@see CONFERINDO_VALE_MIN} minutos. Público (4e): o status em lote da Lista
     * ({@see AprovacaoDoClienteNaTrilha::statusEmLote()}) lê o mesmo estado do cache.
     */
    public static function velho(array $estado): bool
    {
        $em = isset($estado['em']) ? Carbon::parse((string) $estado['em']) : null;

        return $em === null || $em->lt(now()->subMinutes(self::CONFERINDO_VALE_MIN));
    }
}
