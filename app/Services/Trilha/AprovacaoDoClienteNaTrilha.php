<?php

namespace App\Services\Trilha;

use App\Models\Company;
use App\Models\PubFilaPublicacaoItem;
use App\Models\PubImagem;
use App\Models\PubRascunho;
use App\Models\TrilhaAprovacao;
use App\Models\TrilhaOnda;
use App\Models\User;
use App\Services\Portal\Estrutura\RegistroEstrutura;
use App\Support\Portal\AtorDoPortal;
use App\Support\Publicador\NaFilaDePublicacao;
use App\Support\Trilha\AprovacaoDoCliente;
use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * A aprovação do CLIENTE por loja (4ª entrega, fatia 4e, 04/11/2026 — desenho §19.16, D52–D57): a equipe manda a prévia
 * do anúncio ao cliente; ele APROVA (o anúncio segue para a fila de publicação da loja pelo MESMO caminho da 4d) ou PEDE
 * AJUSTE (um texto para a equipe — nunca para a IA). Vale só na loja da chave {@see AprovacaoDoCliente}; desligada, tudo
 * como na 4d.
 *
 * ### O estado mora em `trilha_aprovacoes` (D54) — uma linha viva por anúncio
 * `com_cliente` → `aprovado` | `ajuste`. Cada "Mandar ao cliente" reescreve a linha com `rodada` + 1 e a `revisao` do
 * rascunho naquele instante (`revisao_enviada`). O HISTÓRICO vai para o `activity_log` (canal `portal`, com o ator):
 * `trilha_aprovacao_enviada` (equipe), `trilha_aprovacao_aprovada` e `trilha_aprovacao_ajuste_pedido` (cliente, ou a
 * equipe pelo cliente no modo equipe do Portal) e `trilha_aprovacao_voltou` (D55: a equipe mudou depois de enviar).
 *
 * ### O cliente aprova a versão ENVIADA (D55)
 * Se a `revisao` do rascunho mudou depois do envio (o preço da 4c, o ajuste do agente da 4b, o editor do Publicador), a
 * aprovação é recusada (409) e a linha volta para a equipe (`ajuste`, `pedido` nulo — o motivo é
 * {@see MOTIVO_EQUIPE_MUDOU}). A equipe não precisa esperar o cliente: o "Mandar ao cliente de novo" vale também com a
 * linha `com_cliente` quando a revisão mudou (com a MESMA revisão, é 409 — já está com ele).
 *
 * ### Aprovado → o MESMO caminho da 4d, com quem mandou (D56)
 * {@see PublicacaoNaTrilha::pedir()} com o `User` `enviado_por` e `ciente: false` (o cliente não decide sobre avisos da
 * conferência: eles voltam para a equipe). A guarda da #459 (D48) vale igual: fora dela nada vai para a fila. Quem mandou
 * inativo (ou apagado), a loja fora da #459, a prévia que não monta: a aprovação FICA gravada e a linha da equipe diz
 * "Aprovado pelo cliente · falta publicar" — o [Aprovar e publicar] da equipe volta, na mesma revisão
 * ({@see exigirAprovacaoParaPublicar()}).
 *
 * ### O texto do ajuste nunca vai a prompt (D52)
 * Só `pedido` (coluna) e o `activity_log` o guardam; nenhum serviço da IA (o "Pedir ajuste ao agente" da 4b lê só
 * `revisao` e `refazer`) lê esta tabela.
 *
 * ### Os 409 do cliente trazem a `aprovacao` dele
 * Todo 409 da porta do cliente com a linha à mão (não está com ele, já seguiu, D55, a aba velha, a equipe mexendo) leva
 * `aprovacao` na forma de {@see doCliente()} — a caixa da tela mostra o estado de agora sem outro pedido. Só o da trava
 * ocupada (dois cliques no mesmo instante) vai sem.
 *
 * ### A porta do cliente vê menos (§19.16)
 * {@see paraOCliente()} monta a prévia por LISTA de campos: sem `preparo`, `bloqueios`, `ajuste`,
 * `ajustes_restantes_hoje`, `preco.*.da_precificacao` e `preco.*.minimo`; `publicacao` só com `situacao` e uma mensagem
 * neutra; `aprovacao` só com `estado`, `rodada`, `enviado_em` e `pedido`. E só para o item que a equipe mandou a ele
 * (qualquer estado da rodada de agora); o resto é 404.
 */
class AprovacaoDoClienteNaTrilha
{
    // ─── Status da linha da Lista (as duas portas leem; texto neutro) ───

    public const STATUS_COM_CLIENTE = 'Com o cliente para aprovar';

    public const STATUS_PEDIU_AJUSTE = 'O cliente pediu ajuste';

    /** D55: a linha voltou para a equipe porque ela mudou o anúncio depois de enviar (o cliente não pediu nada). */
    public const STATUS_EM_AJUSTE = 'Em ajuste pela equipe';

    /** Prefixo do aprovado; o sufixo diz onde a publicação está (`· conferindo`, `· na fila`, `· falta publicar`). */
    public const STATUS_APROVADO = 'Aprovado pelo cliente';

    public const SUFIXO_CONFERINDO = 'conferindo';

    public const SUFIXO_NA_FILA = 'na fila';

    public const SUFIXO_FALTA_PUBLICAR = 'falta publicar';

    // ─── Equipe ───

    public const MSG_DESLIGADA = 'Esta loja não aprova os anúncios com o cliente.';

    public const MSG_FORA_DA_REVISAO = 'Este anúncio não está na revisão: só dá para mandar ao cliente quando ele estiver pronto para revisar.';

    public const MSG_AJUSTE_DO_ROBO = 'O agente está refazendo este anúncio. Espere terminar para mandar ao cliente.';

    public const MSG_CONFERINDO = 'Este anúncio está sendo conferido para publicar. Espere a conferência terminar.';

    public const MSG_JA_COM_CLIENTE = 'Este anúncio já está com o cliente para aprovar.';

    public const MSG_ENVIANDO = 'Este anúncio está sendo mandado ao cliente agora. Recarregue em instantes.';

    /** O 409 do "Aprovar e publicar" da equipe na loja que aprova com o cliente (§19.16). */
    public const MSG_APROVA_COM_CLIENTE = 'Esta loja aprova com o cliente: mande a prévia para ele.';

    public const MSG_MUDOU_DEPOIS_DA_APROVACAO = 'O anúncio mudou depois que o cliente aprovou: mande a prévia para ele de novo.';

    /** O `motivo` da `aprovacao` da equipe no D55 (o `pedido` fica nulo: o cliente não pediu nada). */
    public const MOTIVO_EQUIPE_MUDOU = 'A equipe mudou o anúncio depois de enviar.';

    // ─── Cliente ───

    public const MSG_NAO_ESTA_COM_VOCE = 'Este anúncio não está esperando a sua aprovação.';

    public const MSG_MUDOU_DEPOIS_DE_ENVIAR = 'Este anúncio mudou depois que foi enviado para você. A equipe vai conferir e mandar de novo.';

    public const MSG_JA_SEGUIU = 'Este anúncio já seguiu para a publicação.';

    public const MSG_EQUIPE_MEXENDO = 'A equipe está ajustando este anúncio agora. Tente de novo em instantes.';

    public const MSG_TEXTO = 'Escreva o que precisa mudar no anúncio.';

    public const MSG_TEXTO_LONGO = 'O pedido passa de 1000 caracteres. Resuma um pouco.';

    // ─── `publicacao` na porta do cliente (texto neutro; a situação é a da 4d) ───

    public const MSG_CLIENTE_APROVADO = 'Aprovado. A equipe publica em seguida.';

    public const MSG_CLIENTE_NA_FILA = 'Aprovado. O anúncio está na fila para ir ao ar.';

    public const MSG_CLIENTE_COM_A_EQUIPE = 'Aprovado. A equipe confere os últimos detalhes antes de publicar.';

    /** A trava curta do "mandar"/"aprovar"/"pedir ajuste" de um anúncio (dois cliques, duas abas). */
    private const CHAVE_DA_TRAVA = 'trilha:aprovacao:';

    /** O que a publicação já está fazendo — o aprovado com uma destas NÃO "falta publicar". */
    private const ANDANDO = [PublicacaoNaTrilha::CONFERINDO, PublicacaoNaTrilha::NA_FILA, PublicacaoNaTrilha::PUBLICANDO, PublicacaoNaTrilha::PUBLICADO];

    /** O rascunho que a régua de sempre já descreve (publicando, publicado, falhou): o status da aprovação não o cobre. */
    private const RASCUNHO_FORA = [PubRascunho::PUBLISHING, PubRascunho::PUBLISHED, PubRascunho::PARTIALLY_PUBLISHED, PubRascunho::FAILED];

    public function __construct(
        private PreviaNaTrilha $previa,
        private PublicacaoNaTrilha $publicacao,
    ) {}

    // ═══ Equipe: mandar ao cliente ═══════════════════════════════════════════

    /**
     * O `POST /trilha/{company}/ofertas/{item}/cliente` `{revisao}`. Devolve a prévia nova (§19.5) com `aprovacao`.
     *
     * Ordem (§19.16): 404 item → 409 chave desligada → 409 do estado (na fila → publicando → publicado → fora da revisão)
     * → 409 o agente refazendo o texto (4b) → 409 a publicação conferindo (4d) → 409 já com o cliente nesta revisão →
     * 422/409 da `revisao` (a regra da 4c: ausente = 422, velha = 409 SEM `travado`, com `revisao_atual`) → 409 prévia não
     * pronta → 422 Premium = Clássico em TODAS as cores (D46).
     *
     * @param  array<string, mixed>  $corpo
     * @return array<string, mixed>
     *
     * @throws HttpResponseException 409
     * @throws ValidationException 422
     */
    public function mandar(Company $empresa, string $chave, array $corpo, User $quem): array
    {
        $item = TrilhaDaEmpresa::itens($empresa, comoNaTela: true)[$chave] ?? null;
        abort_if($item === null, 404);
        if (! AprovacaoDoCliente::ligada($empresa)) {
            self::conflito(self::MSG_DESLIGADA);
        }

        $r = PreviaNaTrilha::rascunho($item);
        self::exigirNaRevisao($item, $r, self::MSG_FORA_DA_REVISAO, PublicacaoNaTrilha::MSG_NA_FILA_409,
            PublicacaoNaTrilha::MSG_PUBLICANDO_409, PublicacaoNaTrilha::MSG_PUBLICADO_409);
        /** @var PubRascunho $r */
        $pubId = (int) $r->produto_id;
        if (AjusteDoRoboNaTrilha::andandoNoPub($pubId)) {
            self::conflito(self::MSG_AJUSTE_DO_ROBO);
        }
        if ((PublicacaoNaTrilha::situacao($r)['situacao'] ?? null) === PublicacaoNaTrilha::CONFERINDO) {
            self::conflito(self::MSG_CONFERINDO);
        }
        $linha = self::linha($empresa, $pubId);
        if ($linha !== null && $linha->estado === TrilhaAprovacao::COM_CLIENTE && $linha->revisao_enviada === (int) $r->revisao) {
            self::conflito(self::MSG_JA_COM_CLIENTE);
        }
        $revisao = self::revisaoDoCorpo($corpo, (int) $r->revisao);

        $previa = $this->previa->montar($empresa, $chave, null, $item);
        if (! $previa['pronta']) {
            self::conflito((string) ($previa['motivo'] ?? PublicacaoNaTrilha::MSG_NAO_PRONTA));
        }
        // D46: o cliente não aprova um Premium igual ao Clássico — TODAS as cores ativas (a régua do publicar).
        if ($previa['preco']['premium_igual'] ?? false) {
            throw ValidationException::withMessages(['precos.premium' => PrecoNaTrilha::MSG_PREMIUM_IGUAL]);
        }

        self::comTrava($pubId, function () use ($empresa, $chave, $item, $r, $pubId, $revisao, $quem) {
            $linha = self::linha($empresa, $pubId);
            // Relido sob a trava: o 2º clique (ou a outra aba) já mandou esta revisão.
            if ($linha !== null && $linha->estado === TrilhaAprovacao::COM_CLIENTE && $linha->revisao_enviada === $revisao) {
                self::conflito(self::MSG_JA_COM_CLIENTE);
            }
            $dados = [
                'company_id'           => (int) $empresa->id,
                'item_chave'           => $chave,
                'estado'               => TrilhaAprovacao::COM_CLIENTE,
                'rodada'               => $linha !== null ? min(65535, $linha->rodada + 1) : 1,
                'revisao_enviada'      => $revisao,
                'enviado_em'           => now(),
                'enviado_por'          => (int) $quem->id,
                'decidido_em'          => null,
                'decidido_por_cliente' => null,
                'decidido_por_equipe'  => null,
                'pedido'               => null,
            ];
            $linha = $linha !== null ? tap($linha)->update($dados) : TrilhaAprovacao::create([...$dados, 'pub_produto_id' => $pubId]);

            RegistroEstrutura::registrar(AtorDoPortal::daEquipe($quem), $empresa, $r, 'trilha_aprovacao_enviada',
                "Prévia de \"{$item['nome']}\" mandada ao cliente para aprovar (rodada {$linha->rodada})", [
                    'item' => $chave, 'pub_produto_id' => $pubId, 'rascunho_id' => (int) $r->id, 'aprovacao_id' => (int) $linha->id,
                    'rodada' => (int) $linha->rodada, 'revisao' => $revisao,
                ]);
        });

        return $this->previa->montar($empresa, $chave);
    }

    // ═══ Equipe: o "Aprovar e publicar" da 4d com a chave ligada ══════════════

    /**
     * Na loja que aprova com o cliente, a equipe só publica direto o item que o cliente APROVOU nesta mesma revisão
     * (D56: o envio não publicou — quem mandou inativo, avisos da conferência, a fila recusou). Fora disso, 409. A loja sem
     * a chave passa direto (tudo como na 4d). Chamado por {@see PublicacaoNaTrilha::pedir()} depois do 409 do estado.
     *
     * @throws HttpResponseException 409
     */
    public static function exigirAprovacaoParaPublicar(Company $empresa, PubRascunho $r): void
    {
        if (! AprovacaoDoCliente::ligada($empresa)) {
            return;
        }
        $linha = self::linha($empresa, (int) $r->produto_id);
        if ($linha?->estado !== TrilhaAprovacao::APROVADO) {
            self::conflito(self::MSG_APROVA_COM_CLIENTE);
        }
        if ($linha->revisao_enviada !== (int) $r->revisao) {
            self::conflito(self::MSG_MUDOU_DEPOIS_DA_APROVACAO);
        }
    }

    // ═══ Cliente: a prévia e a foto ══════════════════════════════════════════

    /**
     * O `GET portal/{pe}/trilha/ofertas/{item}/previa` — a prévia na versão do cliente ({@see paraOCliente()}). Só do item
     * que a equipe mandou a ele (a linha existe, em qualquer estado da rodada de agora); senão 404, como o item de outra
     * loja.
     *
     * @param  Closure(int): string  $urlDaFoto  id da `pub_imagens` → a foto na porta do cliente
     * @return array<string, mixed>
     */
    public function previaDoCliente(Company $empresa, string $chave, Closure $urlDaFoto): array
    {
        $item = TrilhaDaEmpresa::itens($empresa, comoNaTela: true)[$chave] ?? null;
        abort_if($item === null, 404);
        $linha = self::linhaDoItem($empresa, $item);
        abort_if($linha === null, 404);

        return self::paraOCliente($this->previa->montar($empresa, $chave, $urlDaFoto, $item), $linha);
    }

    /**
     * A foto do ENDEREÇO na porta do cliente: só uma `pub_imagens` do rascunho do item que a equipe mandou a ele (a régua
     * da 4a, sem as ondas — o `pub_produto_id` não depende delas). A de outro rascunho, de outra loja ou de item que não
     * foi mandado: 404.
     */
    public function imagemDoCliente(Company $empresa, string $chave, int $imagem): PubImagem
    {
        $item = TrilhaDaEmpresa::itens($empresa)[$chave] ?? null;
        $r = $item !== null && self::linhaDoItem($empresa, $item) !== null ? PreviaNaTrilha::rascunho($item) : null;
        abort_if($r === null, 404);

        return PubImagem::query()->where('rascunho_id', $r->id)->whereKey($imagem)->firstOrFail();
    }

    // ═══ Cliente: aprovar ════════════════════════════════════════════════════

    /**
     * O `POST portal/{pe}/trilha/ofertas/{item}/aprovar` `{revisao}`. Devolve `{aprovacao, publicacao}` (na versão do
     * cliente).
     *
     * Ordem: 404 item / linha → 409 não está com ele → 409 já seguiu para a publicação (na fila, publicando, publicado) →
     * 409 D55 (a equipe mudou depois de enviar: a linha VOLTA para a equipe) → 422/409 da `revisao` dele (a aba velha de
     * uma rodada anterior) → 409 o agente refazendo agora (nada muda) → grava `aprovado` e pede a publicação pelo caminho da
     * 4d com quem mandou ({@see publicarPeloCliente()}).
     *
     * @param  array<string, mixed>  $corpo
     * @return array{aprovacao: array, publicacao: ?array}
     *
     * @throws HttpResponseException 409
     * @throws ValidationException 422
     */
    public function aprovar(Company $empresa, string $chave, array $corpo, AtorDoPortal $ator): array
    {
        // A régua sem as ondas basta (o anúncio do item não depende delas); o pedido da 4d relê a da tela.
        $item = TrilhaDaEmpresa::itens($empresa)[$chave] ?? null;
        abort_if($item === null, 404);
        $linha = self::linhaDoItem($empresa, $item);
        $r = PreviaNaTrilha::rascunho($item);
        abort_if($linha === null || $r === null, 404);

        if ($linha->estado !== TrilhaAprovacao::COM_CLIENTE) {
            self::conflito(self::MSG_NAO_ESTA_COM_VOCE, ['aprovacao' => self::doCliente($linha)]);
        }
        if (NaFilaDePublicacao::emUso((int) $r->produto_id) || in_array($r->status, [PubRascunho::PUBLISHING, PubRascunho::PUBLISHED, PubRascunho::PARTIALLY_PUBLISHED], true)) {
            self::conflito(self::MSG_JA_SEGUIU, ['aprovacao' => self::doCliente($linha)]);
        }
        // D55: a equipe mudou o anúncio depois de enviar — o cliente não aprova o que não viu. A linha volta para a equipe.
        if ((int) $r->revisao !== $linha->revisao_enviada) {
            $voltou = $this->voltarParaAEquipe($empresa, $item, $r, $linha, $ator);
            self::conflito(self::MSG_MUDOU_DEPOIS_DE_ENVIAR, ['aprovacao' => self::doCliente($voltou)]);
        }
        $revisao = self::revisaoDoCorpo($corpo, $linha->revisao_enviada, ['aprovacao' => self::doCliente($linha)]);
        if (AjusteDoRoboNaTrilha::andandoNoPub((int) $r->produto_id)) {
            self::conflito(self::MSG_EQUIPE_MEXENDO, ['aprovacao' => self::doCliente($linha)]);
        }

        $linha = self::comTrava((int) $r->produto_id, fn () => DB::transaction(function () use ($empresa, $item, $r, $revisao, $ator) {
            $linha = self::linha($empresa, (int) $r->produto_id, travar: true);
            // Relido sob a trava: o 2º clique, a outra aba, ou a equipe que mandou de novo enquanto ele lia.
            if ($linha === null || $linha->estado !== TrilhaAprovacao::COM_CLIENTE || $linha->revisao_enviada !== $revisao) {
                self::conflito(self::MSG_NAO_ESTA_COM_VOCE, $linha !== null ? ['aprovacao' => self::doCliente($linha)] : []);
            }
            $linha->update(['estado' => TrilhaAprovacao::APROVADO, 'decidido_em' => now(), ...self::quemDecidiu($ator)]);
            RegistroEstrutura::registrar($ator, $empresa, $r, 'trilha_aprovacao_aprovada',
                "Anúncio de \"{$item['nome']}\" aprovado ".($ator->equipe ? 'pela equipe em nome do cliente' : 'pelo cliente'), [
                    'item' => $item['chave'], 'pub_produto_id' => (int) $r->produto_id, 'rascunho_id' => (int) $r->id,
                    'aprovacao_id' => (int) $linha->id, 'rodada' => (int) $linha->rodada, 'revisao' => $revisao,
                ]);

            return $linha;
        }));

        $this->publicarPeloCliente($empresa, $item, $linha);

        return ['aprovacao' => self::doCliente($linha->fresh()), 'publicacao' => self::publicacaoDoCliente(PublicacaoNaTrilha::situacao($r->fresh()), $linha)];
    }

    /**
     * Aprovado: o MESMO pedido da 4d ({@see PublicacaoNaTrilha::pedir()}) com o `User` que mandou ao cliente (D56) e
     * `ciente: false`. O que a 4d recusa (403 fora da #459, 409, 422) e quem mandou inativo deixam a aprovação gravada e
     * NADA na fila: a linha da equipe diz "falta publicar" e o [Aprovar e publicar] volta para ela.
     */
    private function publicarPeloCliente(Company $empresa, array $item, TrilhaAprovacao $linha): void
    {
        $quem = $linha->enviado_por !== null ? User::query()->find($linha->enviado_por) : null;
        if ($quem === null || $quem->active === false) {
            Log::info("[Trilha] Aprovação do cliente: o anúncio {$linha->pub_produto_id} (item {$item['chave']}, empresa {$empresa->id}) foi aprovado, mas quem mandou não está ativo — falta publicar.");

            return;
        }
        try {
            $this->publicacao->pedir($empresa, (string) $item['chave'], ['revisao' => $linha->revisao_enviada, 'ciente' => false], $quem, peloCliente: true);
        } catch (HttpResponseException|ValidationException $e) {
            $status = $e instanceof HttpResponseException ? $e->getResponse()->getStatusCode() : 422;
            Log::info("[Trilha] Aprovação do cliente: o anúncio {$linha->pub_produto_id} (item {$item['chave']}, empresa {$empresa->id}) foi aprovado, mas a publicação recusou ({$status}) — falta publicar.");
        } catch (\Throwable $e) {
            Log::error("[Trilha] Aprovação do cliente: a publicação do anúncio {$linha->pub_produto_id} (item {$item['chave']}, empresa {$empresa->id}) falhou: ".class_basename($e).' — '.$e->getMessage());
        }
    }

    /**
     * D55: a linha volta para a equipe (`ajuste`, `pedido` nulo, nenhuma decisão do cliente) — a equipe mudou o anúncio
     * depois de enviar. O histórico (`trilha_aprovacao_voltou`) guarda quem tentou aprovar e as duas revisões.
     */
    private function voltarParaAEquipe(Company $empresa, array $item, PubRascunho $r, TrilhaAprovacao $linha, AtorDoPortal $ator): TrilhaAprovacao
    {
        return self::comTrava((int) $r->produto_id, function () use ($empresa, $item, $r, $linha, $ator) {
            $linha = $linha->fresh() ?? $linha;
            if ($linha->estado !== TrilhaAprovacao::COM_CLIENTE) {
                return $linha;
            }
            $linha->update(['estado' => TrilhaAprovacao::AJUSTE, 'pedido' => null, 'decidido_em' => null, 'decidido_por_cliente' => null, 'decidido_por_equipe' => null]);
            RegistroEstrutura::registrar($ator, $empresa, $r, 'trilha_aprovacao_voltou',
                "Anúncio de \"{$item['nome']}\" voltou para a equipe: ele mudou depois de enviado ao cliente", [
                    'item' => $item['chave'], 'pub_produto_id' => (int) $r->produto_id, 'rascunho_id' => (int) $r->id,
                    'aprovacao_id' => (int) $linha->id, 'rodada' => (int) $linha->rodada,
                    'revisao_enviada' => (int) $linha->revisao_enviada, 'revisao_atual' => (int) $r->revisao,
                ]);

            return $linha;
        });
    }

    // ═══ Cliente: pedir ajuste ═══════════════════════════════════════════════

    /**
     * O `POST portal/{pe}/trilha/ofertas/{item}/ajuste` `{revisao, texto}`. Devolve `{aprovacao}` (versão do cliente).
     * O texto (1 a 1000 caracteres) vai para `pedido` e para o histórico — e para mais lugar nenhum (D52).
     *
     * Ordem: 404 item / linha → 409 não está com ele → 422/409 da `revisao` (a aba velha de uma rodada anterior) → 422
     * `errors.texto`. Com a equipe tendo mudado o anúncio depois de enviar, o pedido VALE (a linha volta para a equipe de
     * qualquer jeito, e o texto diz o que ele quer).
     *
     * @param  array<string, mixed>  $corpo
     * @return array{aprovacao: array}
     */
    public function pedirAjuste(Company $empresa, string $chave, array $corpo, AtorDoPortal $ator): array
    {
        $item = TrilhaDaEmpresa::itens($empresa)[$chave] ?? null;
        abort_if($item === null, 404);
        $linha = self::linhaDoItem($empresa, $item);
        $r = PreviaNaTrilha::rascunho($item);
        abort_if($linha === null || $r === null, 404);

        if ($linha->estado !== TrilhaAprovacao::COM_CLIENTE) {
            self::conflito(self::MSG_NAO_ESTA_COM_VOCE, ['aprovacao' => self::doCliente($linha)]);
        }
        $revisao = self::revisaoDoCorpo($corpo, $linha->revisao_enviada, ['aprovacao' => self::doCliente($linha)]);
        $texto = self::textoDoCorpo($corpo);

        $linha = self::comTrava((int) $r->produto_id, fn () => DB::transaction(function () use ($empresa, $item, $r, $revisao, $texto, $ator) {
            $linha = self::linha($empresa, (int) $r->produto_id, travar: true);
            if ($linha === null || $linha->estado !== TrilhaAprovacao::COM_CLIENTE || $linha->revisao_enviada !== $revisao) {
                self::conflito(self::MSG_NAO_ESTA_COM_VOCE, $linha !== null ? ['aprovacao' => self::doCliente($linha)] : []);
            }
            $linha->update(['estado' => TrilhaAprovacao::AJUSTE, 'pedido' => $texto, 'decidido_em' => now(), ...self::quemDecidiu($ator)]);
            RegistroEstrutura::registrar($ator, $empresa, $r, 'trilha_aprovacao_ajuste_pedido',
                "Ajuste pedido no anúncio de \"{$item['nome']}\"".($ator->equipe ? ' (pela equipe, em nome do cliente)' : ''), [
                    'item' => $item['chave'], 'pub_produto_id' => (int) $r->produto_id, 'rascunho_id' => (int) $r->id,
                    'aprovacao_id' => (int) $linha->id, 'rodada' => (int) $linha->rodada, 'revisao' => $revisao, 'pedido' => $texto,
                ]);

            return $linha;
        }));

        return ['aprovacao' => self::doCliente($linha->fresh())];
    }

    // ═══ O que cada porta lê ═════════════════════════════════════════════════

    /**
     * A `aprovacao` da prévia da EQUIPE (§19.16; nula sem a chave ou sem envio): o que o cliente vê (`estado`, `rodada`,
     * `enviado_em`, `pedido`) e o que só a equipe precisa —
     * `motivo` (o D55: a equipe mudou depois de enviar; nulo fora dele), `enviado_por` (nome), `decidido_em`,
     * `decidido_por` (o nome do cliente, ou "Nome (equipe ECF)" no modo equipe), `mudou_depois` (a revisão de agora não é
     * a enviada — o "Mandar ao cliente de novo" vale mesmo `com_cliente`) e `falta_publicar` (aprovado, e nada andando nem
     * publicado: o [Aprovar e publicar] volta — D56).
     *
     * @param  ?array  $publicacao  a `publicacao` da prévia ({@see PublicacaoNaTrilha::situacao()}), já calculada
     */
    public static function daEquipe(Company $empresa, ?PubRascunho $r, ?array $publicacao): ?array
    {
        if ($r === null || ! AprovacaoDoCliente::ligada($empresa)) {
            return null;
        }
        $linha = self::linha($empresa, (int) $r->produto_id);
        if ($linha === null) {
            return null;
        }
        $linha->loadMissing(['remetente:id,name', 'clienteQueDecidiu:id,nome', 'equipeQueDecidiu:id,name']);
        $decidiu = $linha->clienteQueDecidiu?->nome ?? ($linha->equipeQueDecidiu !== null ? $linha->equipeQueDecidiu->name.' (equipe ECF)' : null);

        return [
            ...self::doCliente($linha),
            'motivo'         => $linha->estado === TrilhaAprovacao::AJUSTE && $linha->pedido === null ? self::MOTIVO_EQUIPE_MUDOU : null,
            'enviado_por'    => $linha->remetente?->name,
            'decidido_em'    => self::quando($linha->decidido_em),
            'decidido_por'   => $decidiu,
            'mudou_depois'   => $linha->estado !== TrilhaAprovacao::AJUSTE && $linha->revisao_enviada !== (int) $r->revisao,
            'falta_publicar' => $linha->estado === TrilhaAprovacao::APROVADO && ! in_array($r->status, self::RASCUNHO_FORA, true)
                && ! in_array($publicacao['situacao'] ?? null, self::ANDANDO, true),
        ];
    }

    /**
     * A `aprovacao` na porta do CLIENTE: só `estado`, `rodada`, `enviado_em` (ISO, São Paulo) e `pedido` (§19.16).
     *
     * @return array{estado: string, rodada: int, enviado_em: ?string, pedido: ?string}
     */
    public static function doCliente(TrilhaAprovacao $linha): array
    {
        return [
            'estado'     => (string) $linha->estado,
            'rodada'     => (int) $linha->rodada,
            'enviado_em' => self::quando($linha->enviado_em),
            'pedido'     => $linha->pedido,
        ];
    }

    /**
     * A prévia na versão do CLIENTE, por LISTA de campos (o campo novo que a prévia da equipe ganhar não chega a ele sem
     * decisão — a lógica da `ApresentacaoDaTrilha`): a forma da §19.5 SEM `preparo`, `bloqueios`, `ajuste`,
     * `ajustes_restantes_hoje`, `preco.*.da_precificacao` e `preco.*.minimo`; `publicacao` só com `situacao` e a mensagem
     * neutra ({@see publicacaoDoCliente()}); `aprovacao` na versão dele ({@see doCliente()}).
     *
     * @param  array<string, mixed>  $previa  a de {@see PreviaNaTrilha::montar()} (com as fotos já na porta do cliente)
     * @return array<string, mixed>
     */
    public static function paraOCliente(array $previa, TrilhaAprovacao $linha): array
    {
        $preco = null;
        if (is_array($previa['preco'] ?? null)) {
            $preco = [];
            foreach (array_keys(PreviaNaTrilha::NOME_DO_TIPO) as $tipo) {
                $t = $previa['preco'][$tipo] ?? null;
                $preco[$tipo] = is_array($t) ? ['valor' => $t['valor'] ?? null, 'ajustado' => (bool) ($t['ajustado'] ?? false)] : null;
            }
            $preco['por_cor'] = (bool) ($previa['preco']['por_cor'] ?? false);
            $preco['premium_igual'] = (bool) ($previa['preco']['premium_igual'] ?? false);
        }

        return [
            'item'       => [
                'id'     => (string) $previa['item']['id'],
                'nome'   => (string) $previa['item']['nome'],
                'tipo'   => (string) $previa['item']['tipo'],
                'etapa'  => (string) $previa['item']['etapa'],
                'status' => (string) $previa['item']['status'],
            ],
            'pronta'     => (bool) $previa['pronta'],
            'motivo'     => $previa['motivo'],
            'revisao'    => $previa['revisao'],
            'categoria'  => is_array($previa['categoria'] ?? null) ? ['caminho' => array_values((array) $previa['categoria']['caminho'])] : null,
            'tipos'      => array_map(fn (array $t) => ['chave' => $t['chave'], 'nome' => $t['nome']], (array) $previa['tipos']),
            'variantes'  => array_map(fn (array $v) => ['chave' => $v['chave'], 'rotulo' => $v['rotulo']], (array) $previa['variantes']),
            'anuncios'   => array_map(fn (array $a) => [
                'tipo'            => $a['tipo'],
                'variante'        => $a['variante'],
                'titulo'          => $a['titulo'],
                'preco'           => $a['preco'],
                'promocao'        => $a['promocao'],
                'parcelas'        => $a['parcelas'],
                'fotos'           => array_map(fn (array $f) => ['id' => $f['id'], 'url' => $f['url'], 'gerada' => (bool) $f['gerada']], (array) $a['fotos']),
                'caracteristicas' => $a['caracteristicas'],
                'envio'           => [
                    'modo'         => $a['envio']['modo'] ?? null,
                    'frete_gratis' => (bool) ($a['envio']['frete_gratis'] ?? false),
                    'retirada'     => (bool) ($a['envio']['retirada'] ?? false),
                    'aviso'        => null,
                ],
            ], (array) $previa['anuncios']),
            'descricao'  => $previa['descricao'],
            'preco'      => $preco,
            'publicacao' => self::publicacaoDoCliente($previa['publicacao'] ?? null, $linha),
            'aprovacao'  => self::doCliente($linha),
        ];
    }

    /**
     * A `publicacao` na porta do cliente: só depois que ELE aprovou (antes, nula), com a situação da 4d e uma mensagem
     * neutra — avisos, recusa e erro da conferência são da equipe ("a equipe confere os últimos detalhes").
     *
     * @return ?array{situacao: string, mensagem: string}
     */
    public static function publicacaoDoCliente(?array $publicacao, TrilhaAprovacao $linha): ?array
    {
        if ($linha->estado !== TrilhaAprovacao::APROVADO || ! isset($publicacao['situacao'])) {
            return null;
        }

        return [
            'situacao' => (string) $publicacao['situacao'],
            'mensagem' => match ($publicacao['situacao']) {
                PublicacaoNaTrilha::CONFERINDO => self::MSG_CLIENTE_APROVADO,
                PublicacaoNaTrilha::NA_FILA    => self::MSG_CLIENTE_NA_FILA,
                PublicacaoNaTrilha::PUBLICANDO => PublicacaoNaTrilha::MSG_PUBLICANDO,
                PublicacaoNaTrilha::PUBLICADO  => PublicacaoNaTrilha::MSG_PUBLICADO,
                default                        => self::MSG_CLIENTE_COM_A_EQUIPE,
            },
        ];
    }

    /**
     * O status da linha da Lista de cada anúncio com aprovação, EM LOTE (para {@see SituacaoDasOfertas}, que só o aplica
     * ao item na revisão com o rascunho ainda não publicado — publicando, publicado e falhou seguem a régua de sempre):
     *
     * | estado        | status                                         | alerta |
     * |---------------|------------------------------------------------|--------|
     * | `com_cliente` | Com o cliente para aprovar                     | não    |
     * | `ajuste`      | O cliente pediu ajuste (com `pedido`)          | sim    |
     * | `ajuste`      | Em ajuste pela equipe (D55, `pedido` nulo)      | sim    |
     * | `aprovado`    | Aprovado pelo cliente · conferindo / · na fila | não    |
     * | `aprovado`    | Aprovado pelo cliente · falta publicar         | sim    |
     *
     * Sem a chave: vazio, sem consulta. Com ela: 1 consulta (as linhas) e, havendo aprovado, mais 1 (a fila) e um
     * `Cache::many` (o estado do pedido da 4d) — fixas, qualquer que seja o catálogo.
     *
     * @param  array<int, PubRascunho>  $rascunhos  pub_produto → rascunho
     * @return array<int, array{status: string, alerta: bool}>
     */
    public static function statusEmLote(Company $empresa, array $rascunhos): array
    {
        if ($rascunhos === [] || ! AprovacaoDoCliente::ligada($empresa)) {
            return [];
        }
        $linhas = TrilhaAprovacao::query()
            ->where('company_id', $empresa->id)
            ->whereIn('pub_produto_id', array_keys($rascunhos))
            ->get(['pub_produto_id', 'estado', 'pedido']);
        if ($linhas->isEmpty()) {
            return [];
        }

        $aprovados = $linhas->where('estado', TrilhaAprovacao::APROVADO)->pluck('pub_produto_id')->map(fn ($id) => (int) $id)->all();
        $naFila = $aprovados !== []
            ? PubFilaPublicacaoItem::query()->whereIn('produto_ativo', $aprovados)->pluck('status', 'produto_ativo')->all()
            : [];
        $pedidos = $aprovados !== [] ? Cache::many(array_map(fn (int $id) => PublicacaoNaTrilha::chave($id), $aprovados)) : [];

        $saida = [];
        foreach ($linhas as $l) {
            $pub = (int) $l->pub_produto_id;
            $saida[$pub] = match ($l->estado) {
                TrilhaAprovacao::COM_CLIENTE => ['status' => self::STATUS_COM_CLIENTE, 'alerta' => false],
                TrilhaAprovacao::AJUSTE      => ['status' => $l->pedido !== null ? self::STATUS_PEDIU_AJUSTE : self::STATUS_EM_AJUSTE, 'alerta' => true],
                default                      => self::statusDoAprovado($naFila[$pub] ?? null, $pedidos[PublicacaoNaTrilha::chave($pub)] ?? null),
            };
        }

        return $saida;
    }

    /** O aprovado pela fila viva e pelo pedido da 4d (o `conferindo` recente); o resto "falta publicar". */
    private static function statusDoAprovado(?string $naFila, mixed $pedido): array
    {
        $sufixo = match (true) {
            $naFila === PubFilaPublicacaoItem::AGENDADO => self::SUFIXO_NA_FILA,
            $naFila === PubFilaPublicacaoItem::PUBLICANDO => 'publicando',
            is_array($pedido) && ($pedido['situacao'] ?? null) === PublicacaoNaTrilha::CONFERINDO && ! PublicacaoNaTrilha::velho($pedido) => self::SUFIXO_CONFERINDO,
            default => null,
        };

        return $sufixo !== null
            ? ['status' => self::STATUS_APROVADO.' · '.$sufixo, 'alerta' => false]
            : ['status' => self::STATUS_APROVADO.' · '.self::SUFIXO_FALTA_PUBLICAR, 'alerta' => true];
    }

    // ═══ Apoio ═══════════════════════════════════════════════════════════════

    /** A linha do anúncio NESTA empresa (a de outra loja não existe para esta); `$travar` = presa até o fim da transação. */
    private static function linha(Company $empresa, int $pubId, bool $travar = false): ?TrilhaAprovacao
    {
        $q = TrilhaAprovacao::query()->where('company_id', $empresa->id)->where('pub_produto_id', $pubId);

        return ($travar ? $q->lockForUpdate() : $q)->first();
    }

    /** A linha do item (pelo anúncio que o representa — a régua única). Sem anúncio, nenhuma. */
    private static function linhaDoItem(Company $empresa, array $item): ?TrilhaAprovacao
    {
        $pub = $item['pub_produto_id'] ?? null;

        return $pub !== null ? self::linha($empresa, (int) $pub) : null;
    }

    /**
     * 409 do estado, nesta ordem (a da 4d — o estado diz mais que a revisão): na fila → publicando → publicado → fora da
     * revisão (inclusive sem rascunho).
     *
     * @throws HttpResponseException
     */
    private static function exigirNaRevisao(array $item, ?PubRascunho $r, string $fora, string $naFila, string $publicando, string $publicado): void
    {
        $motivo = match (true) {
            $r !== null && NaFilaDePublicacao::emUso((int) $r->produto_id)                                        => $naFila,
            $r !== null && $r->status === PubRascunho::PUBLISHING                                                 => $publicando,
            $r !== null && in_array($r->status, [PubRascunho::PUBLISHED, PubRascunho::PARTIALLY_PUBLISHED], true) => $publicado,
            $r === null || $item['etapa'] !== 'revisao'                                                           => $fora,
            default                                                                                               => null,
        };
        if ($motivo !== null) {
            self::conflito($motivo);
        }
    }

    /**
     * A `revisao` do corpo contra a esperada (a regra da 4c): ausente ou fora da forma = 422 `errors.revisao`; diferente =
     * 409 `{revisao_atual, message}` SEM `travado` — na porta do cliente com a `aprovacao` dele junto (`$extra`: a caixa da
     * tela mostra o estado de agora sem outro pedido).
     *
     * @throws HttpResponseException|ValidationException
     */
    private static function revisaoDoCorpo(array $corpo, int $esperada, array $extra = []): int
    {
        $revisao = $corpo['revisao'] ?? null;
        if (! is_int($revisao) && ! (is_string($revisao) && preg_match('/^[0-9]{1,9}$/', $revisao))) {
            throw ValidationException::withMessages(['revisao' => PrecoNaTrilha::MSG_RECARREGUE]);
        }
        if ((int) $revisao !== $esperada) {
            throw new HttpResponseException(response()->json(['revisao_atual' => $esperada, 'message' => PrecoNaTrilha::MSG_REVISAO, ...$extra], 409));
        }

        return (int) $revisao;
    }

    /**
     * O texto do "Pedir ajuste": texto, 1 a 1000 caracteres depois de tirar os espaços das pontas. Fica como veio (sem
     * corte nem tradução); nunca vai a prompt.
     *
     * @throws ValidationException
     */
    private static function textoDoCorpo(array $corpo): string
    {
        $texto = $corpo['texto'] ?? null;
        $texto = is_string($texto) ? trim($texto) : '';
        if ($texto === '') {
            throw ValidationException::withMessages(['texto' => self::MSG_TEXTO]);
        }
        if (mb_strlen($texto) > TrilhaAprovacao::PEDIDO_MAX) {
            throw ValidationException::withMessages(['texto' => self::MSG_TEXTO_LONGO]);
        }

        return $texto;
    }

    /** Quem decidiu: o cliente (`PortalUsuario`) ou, no modo equipe do Portal, o membro da ECF em nome dele. */
    private static function quemDecidiu(AtorDoPortal $ator): array
    {
        $id = $ator->modelo !== null ? (int) $ator->modelo->getKey() : null;

        return $ator->equipe
            ? ['decidido_por_cliente' => null, 'decidido_por_equipe' => $id]
            : ['decidido_por_cliente' => $id, 'decidido_por_equipe' => null];
    }

    /**
     * Roda `$fazer` sob a trava curta do anúncio (dois cliques, duas abas, o cliente e a equipe ao mesmo tempo). Esperou
     * demais: 409.
     *
     * @template T
     *
     * @param  Closure(): T  $fazer
     * @return T
     */
    private static function comTrava(int $pubId, Closure $fazer): mixed
    {
        try {
            return Cache::lock(self::CHAVE_DA_TRAVA.$pubId, 10)->block(5, $fazer);
        } catch (LockTimeoutException) {
            self::conflito(self::MSG_ENVIANDO);
        }
    }

    /** @throws HttpResponseException 409 `{message}` (+ o que vier), nunca `travado` */
    private static function conflito(string $mensagem, array $extra = []): never
    {
        throw new HttpResponseException(response()->json(['message' => $mensagem, ...$extra], 409));
    }

    private static function quando(mixed $em): ?string
    {
        return $em?->copy()->setTimezone(TrilhaOnda::FUSO)->toIso8601String();
    }
}
