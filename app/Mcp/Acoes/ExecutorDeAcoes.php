<?php

namespace App\Mcp\Acoes;

use App\Mcp\ErroDaFerramenta;
use App\Mcp\Telas\NavegacaoInterna;
use App\Mcp\Telas\NavegadorDeTelas;
use App\Models\User;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use Symfony\Component\HttpFoundation\Response;

/**
 * Envia um formulário do Admin "como o usuário" — o mesmo POST/PUT/PATCH/DELETE
 * que o botão da tela manda — e traduz a resposta para o MCP.
 *
 * Passa pelo kernel HTTP ({@see NavegacaoInterna}): middlewares, CSRF,
 * validação, controller, policy, log de atividade e avisos são os da tela, por
 * construção. Nenhuma regra de negócio é repetida aqui.
 *
 * O pedido vai como JSON (`Accept: application/json`) para a validação
 * responder 422 com os campos, em vez de "voltar para a página anterior". O
 * resultado de um formulário Inertia comum é um redirect com mensagem em
 * flash (`back()->with('success', ...)`); a sessão em memória da navegação é
 * lida depois para saber o que a tela teria mostrado.
 */
final class ExecutorDeAcoes
{
    /** Chaves de flash que são "a mensagem da tela"; `error` é falha. */
    private const FLASH_MENSAGEM = ['success', 'warning', 'info', 'status', 'message'];

    public function __construct(
        private CatalogoDeAcoes $catalogo,
        private NavegacaoInterna $navegacao,
        private NavegadorDeTelas $telas,
    ) {}

    /**
     * @param  array<string, mixed>  $parametros  parâmetros do endereço ({chamado}, {company}...)
     * @param  array<string, mixed>  $dados  corpo do formulário
     * @return array{acao:string, metodo:string, endereco:string, status:int, mensagem:?string, avisos:array<string,mixed>, destino:?string, dados:mixed}
     */
    public function enviar(User $usuario, string $acao, array $parametros = [], array $dados = []): array
    {
        $entrada = $this->catalogo->achar($usuario, $acao)
            ?? throw new ErroDaFerramenta("Ação \"{$acao}\" não existe, está fora do MCP ou não está no seu perfil. Use listar_acoes para ver as disponíveis.");

        $faltando = array_values(array_diff($entrada['parametros'], array_keys($parametros)));
        if ($faltando !== []) {
            throw new ErroDaFerramenta('Esta ação precisa de: '.implode(', ', $faltando).' em "parametros". Ex.: {"parametros": {"'.$faltando[0].'": 123}}.');
        }

        $caminho = route($acao, array_intersect_key($parametros, array_flip($entrada['parametros'])), false);

        [$resposta, $sessao] = $this->navegacao->despachar($usuario, $entrada['metodo'], $caminho, $dados, [
            'Accept'           => 'application/json',
            'X-Requested-With' => 'XMLHttpRequest',
        ], comCsrf: true);

        // Gravou (ou pode ter gravado parte): a próxima leitura de tela deste
        // usuário não pode vir do cache de antes.
        $this->telas->esquecerCacheDe($usuario);

        return $this->interpretar($acao, $entrada['metodo'], $caminho, $resposta, $sessao, $usuario);
    }

    private function interpretar(string $acao, string $metodo, string $caminho, Response $resposta, Store $sessao, User $usuario): array
    {
        $status = $resposta->getStatusCode();
        $json   = json_decode((string) $resposta->getContent(), true);

        if ($status === 422) {
            throw new ErroDaFerramenta('A tela recusou os dados.'.$this->camposComProblema(is_array($json) ? ($json['errors'] ?? []) : [], $json['message'] ?? null));
        }
        if ($status === 401 || $status === 403) {
            throw new ErroDaFerramenta('Seu perfil não pode fazer esta ação ('.$acao.').'.$this->mensagemDe($json));
        }
        if ($status === 404) {
            throw new ErroDaFerramenta('Não encontrado ('.$caminho.'). Confira os ids em "parametros" — ou o registro não está no seu perfil.');
        }
        if ($status === 419) {
            Log::error('[MCP] formulário recusado por CSRF na navegação interna', ['acao' => $acao, 'user_id' => $usuario->id]);
            throw new ErroDaFerramenta('Erro interno ao enviar o formulário (sessão recusada). Avise o time de desenvolvimento.');
        }
        if ($status === 429) {
            throw new ErroDaFerramenta('Limite de uso desta ação atingido. Tente de novo em um minuto.');
        }
        if ($status >= 500) {
            throw new ErroDaFerramenta('A ação deu erro no servidor ('.$status.'). Antes de tentar de novo, confira na tela se algo foi gravado — para não duplicar.');
        }
        if ($status >= 400) {
            throw new ErroDaFerramenta('A ação foi recusada (erro '.$status.').'.$this->mensagemDe($json));
        }

        // Formulário Inertia: redirect + flash. `error` em flash e erros em
        // sessão (`withErrors`) são a tela dizendo "não deu".
        $flash = $this->flash($sessao);
        if (filled($flash['error'] ?? null)) {
            throw new ErroDaFerramenta('A tela recusou: '.$this->texto($flash['error']));
        }
        $erros = $sessao->get('errors');
        if ($erros instanceof ViewErrorBag && $erros->any()) {
            $campos = collect($erros->getBags())->flatMap(fn (MessageBag $b) => $b->toArray())->all();
            throw new ErroDaFerramenta('A tela recusou os dados.'.$this->camposComProblema($campos, null));
        }

        $mensagem = collect(self::FLASH_MENSAGEM)->map(fn ($k) => $flash[$k] ?? null)->first(fn ($v) => is_string($v) && $v !== '');
        $avisos   = collect($flash)->except([...self::FLASH_MENSAGEM, 'error'])->filter(fn ($v) => $v !== null)->all();

        $destino = null;
        if ($resposta->isRedirection()) {
            $local   = (string) $resposta->headers->get('Location');
            $destino = parse_url($local, PHP_URL_PATH) ?: null;
            if ($destino === '/') {
                $destino = null; // `back()` sem página anterior — não diz nada
            }
        }

        return [
            'acao'     => $acao,
            'metodo'   => $metodo,
            'endereco' => $caminho,
            'status'   => $status,
            'mensagem' => $mensagem,
            'avisos'   => $avisos,
            'destino'  => $destino,
            'dados'    => is_array($json) ? $json : null,
        ];
    }

    /** Tudo o que foi posto em flash nesta navegação. @return array<string, mixed> */
    private function flash(Store $sessao): array
    {
        $chaves = array_unique([...(array) $sessao->get('_flash.old', []), ...(array) $sessao->get('_flash.new', []), 'success', 'error']);

        return collect($chaves)
            ->reject(fn ($k) => in_array($k, ['errors', '_old_input'], true))
            ->mapWithKeys(fn ($k) => [$k => $sessao->get($k)])
            ->all();
    }

    /** @param  array<string, mixed>  $erros */
    private function camposComProblema(array $erros, mixed $mensagem): string
    {
        if ($erros === []) {
            return is_string($mensagem) && $mensagem !== '' ? ' '.$mensagem : '';
        }

        return ' Campos com problema: '.collect($erros)
            ->map(fn ($msgs, $campo) => $campo.' ('.implode('; ', (array) $msgs).')')
            ->take(10)
            ->implode(', ').'.';
    }

    private function mensagemDe(mixed $json): string
    {
        $msg = is_array($json) ? ($json['message'] ?? null) : null;

        return is_string($msg) && $msg !== '' ? ' '.$msg : '';
    }

    private function texto(mixed $valor): string
    {
        return is_string($valor) ? $valor : (string) json_encode($valor, JSON_UNESCAPED_UNICODE);
    }
}
