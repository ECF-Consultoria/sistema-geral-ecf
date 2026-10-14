<?php

namespace App\Http\Controllers;

use App\Models\BlingConexao;
use App\Models\Company;
use App\Services\Bling\BlingClient;
use App\Services\Bling\BlingOAuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Fase 184 (BLING-17, BLING-18, BLING-19, BLING-29) — o caminho que o
 * cliente percorre para conectar o Bling: a equipe gera um link assinado nas
 * Configurações da loja (plano 11), o cliente abre a landing pública
 * (`landing`), clica, autoriza no Bling dele, e volta para o `callback`
 * (Task 2), que confere o CNPJ antes de gravar qualquer token (CONTEXT §4.2
 * — lição do OAuth do ML nos Polos).
 *
 * Molde copiado de `ShopeeOAuthController` (link assinado de 7 dias + landing
 * guiada em blade). A geração do link em si mora em
 * `BlingOAuthService::gerarLinkDeAutorizacao()` — este controller é uma
 * casca fina sobre ela, porque o plano 11 cria `MlbPublicadorBlingController`,
 * que também precisa gerar o link e compartilha o mesmo método de serviço.
 *
 * Nenhum texto que o cliente lê (landing, mensagens de erro) cita a
 * plataforma de venda (CONTEXT §4.6, `assertSemOrigem`).
 */
class BlingOAuthController extends Controller
{
    public function __construct(
        private readonly BlingOAuthService $oauth,
        private readonly BlingClient $cliente,
    ) {}

    /**
     * Gera (ou regera) o link assinado de 7 dias. A rota autenticada que
     * chama este método fica no plano 11 (`routes/mlb_anuncios.php`, grupo
     * `role:admin`) — aqui só o método existe, pronto para ser chamado.
     */
    public function gerarLink(Company $company, Request $request): JsonResponse
    {
        try {
            $dados = $this->oauth->gerarLinkDeAutorizacao($company, $request->user()?->id);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($dados);
    }

    /**
     * Rota pública — protegida só pela assinatura (middleware `signed`). É a
     * tela que o cliente abre a partir do link que a equipe mandou.
     */
    public function landing(Company $company, Request $request)
    {
        if (! BlingConexao::estaConfigurado()) {
            return $this->renderizar('nao_configurado', $company);
        }

        $conexao = BlingConexao::where('company_id', $company->id)->first();

        // Lê pelo model (decifra o cast `encrypted`) — nunca compara a
        // coluna crua por query builder. Presença de token já basta para a
        // landing mostrar "concluído"; o status fica como a fonte única de
        // verdade de "ativa" só no callback (Task 2), que é quem de fato grava.
        if ($conexao && $conexao->access_token) {
            return $this->renderizar('concluido', $company);
        }

        // "Regerar" grava uma `link_url` NOVA na empresa. A assinatura do
        // link antigo continua criptograficamente válida até o próprio
        // prazo dela (isso é inerente ao HMAC do Laravel — não há revogação
        // de assinatura), então quem abrir o link velho não leva 403: cai
        // nesta tela amigável, porque o link gravado na empresa já é outro.
        if ($conexao && $conexao->link_url && $conexao->link_url !== $request->fullUrl()) {
            return $this->erro('Este link não é mais válido — foi substituído por um mais recente. Peça o link atualizado para a equipe.');
        }

        $url = $this->oauth->urlDeAutorizacao($company, $conexao?->conectado_por);

        return $this->renderizar('convite', $company, [
            'button_url' => $url,
            'button_label' => 'Autorizar acesso',
        ]);
    }

    /**
     * Renderiza a blade da landing. Nenhum dado da empresa além do NOME vai
     * para a view (CONTEXT §4.6).
     */
    private function renderizar(string $estado, Company $company, array $extra = [])
    {
        return view('oauth.bling-conectar', array_merge([
            'estado' => $estado,
            'empresa_nome' => $company->name,
            'button_url' => null,
            'button_label' => 'Autorizar acesso',
            'mensagem' => null,
        ], $extra));
    }

    /**
     * Renderiza o estado de erro — SEMPRE status 200: a landing é a tela que
     * o cliente vê, e um 4xx/5xx cru é o oposto da "tela amigável no retorno
     * do OAuth" que a revisão humana da homologação exige. Nenhum detalhe
     * técnico chega até aqui — só a mensagem em pt-BR já pronta para o
     * cliente ler.
     */
    private function erro(string $mensagem)
    {
        return view('oauth.bling-conectar', [
            'estado' => 'erro',
            'empresa_nome' => null,
            'button_url' => null,
            'button_label' => 'Autorizar acesso',
            'mensagem' => $mensagem,
        ]);
    }
}
