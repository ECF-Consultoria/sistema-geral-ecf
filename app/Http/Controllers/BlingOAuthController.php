<?php

namespace App\Http\Controllers;

use App\Models\BlingConexao;
use App\Models\Company;
use App\Services\Bling\BlingClient;
use App\Services\Bling\BlingOAuthService;
use App\Support\Cnpj;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Fase 184 (BLING-17, BLING-18, BLING-19, BLING-29) — o caminho que o
 * cliente percorre para conectar o Bling: a equipe gera um link assinado nas
 * Configurações da loja (plano 11), o cliente abre a landing pública
 * (`landing`), clica, autoriza no Bling dele, e volta para o `callback`, que
 * confere o CNPJ antes de gravar qualquer token (CONTEXT §4.2 — lição do
 * OAuth do ML nos Polos).
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
        // verdade de "ativa" só no callback, que é quem de fato grava.
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
     * Rota pública — o Bling redireciona para cá depois que o cliente
     * autoriza. Confere o CNPJ antes de gravar qualquer token (CONTEXT §4.2,
     * lição do OAuth do ML nos Polos): só `Cnpj::COMPARACAO_IGUAL` grava a
     * conexão como `ativa`. Os outros quatro estados ficam `divergente`,
     * **sem nenhum token na linha crua** — decidido assim de propósito,
     * porque o seed §9 deixou "uma conta Bling pode servir a duas empresas
     * da ECF?" como pergunta aberta.
     */
    public function callback(Request $request)
    {
        $state = $request->string('state')->value();
        $code = $request->string('code')->value();

        if (! $state || ! $code) {
            return $this->erro('Parâmetros inválidos no retorno da autorização.');
        }

        // Para ANTES de qualquer chamada ao Bling: um callback reentregue
        // (duplo clique, retry do navegador) encontra o `state` já consumido
        // e reusar um `code` ainda válido revogaria o cliente no Bling.
        $dados = $this->oauth->consumirState($state);

        if (! $dados) {
            return $this->erro('Este link de autorização já foi usado ou expirou. Peça um link novo para a equipe.');
        }

        $company = Company::find($dados['company_id'] ?? null);

        if (! $company) {
            return $this->erro('Empresa não encontrada. Entre em contato com a equipe.');
        }

        try {
            $token = $this->oauth->trocarCode($code);
        } catch (\Throwable $e) {
            Log::warning("[Bling] Falha ao trocar o código de autorização para a empresa {$company->id}: ".$e->getMessage());

            return $this->erro('Não foi possível concluir a autorização. Peça um link novo para a equipe.');
        }

        // Conexão em memória (ainda não persistida): o mínimo necessário
        // para o BlingClient conseguir ler os dados básicos e conferir o
        // CNPJ. No ramo divergente, os dois tokens são regravados como nulos
        // na mesma escrita — ao fim do callback divergente a linha crua não
        // fica com nenhum token.
        $conexaoEmMemoria = BlingConexao::where('company_id', $company->id)->first()
            ?? new BlingConexao(['company_id' => $company->id]);
        $conexaoEmMemoria->access_token = $token['access_token'] ?? null;
        $conexaoEmMemoria->refresh_token = $token['refresh_token'] ?? null;

        try {
            $basicos = $this->cliente->dadosBasicos($conexaoEmMemoria);
        } catch (\Throwable $e) {
            Log::warning("[Bling] Falha ao ler dados básicos da conta Bling para a empresa {$company->id}: ".$e->getMessage());

            return $this->erro('Não foi possível confirmar os dados da conta autorizada. Peça um link novo para a equipe.');
        }

        $comparacao = Cnpj::comparar($basicos['cnpj'] ?? null, $company->cnpj);

        if ($comparacao !== Cnpj::COMPARACAO_IGUAL) {
            $motivo = match ($comparacao) {
                Cnpj::COMPARACAO_MESMA_EMPRESA_OUTRA_UNIDADE => 'A conta do Bling é da mesma empresa, mas de outra unidade (matriz/filial). Confirme com a equipe qual unidade deve ser conectada.',
                Cnpj::COMPARACAO_DIFERENTE => 'A conta autorizada no Bling é de outra empresa.',
                Cnpj::COMPARACAO_EMPRESA_SEM_CNPJ => 'Esta empresa não tem CNPJ cadastrado, então não é possível conferir a conta.',
                Cnpj::COMPARACAO_CONTRATO_SEM_CNPJ => 'O Bling não informou o CNPJ da conta autorizada.',
                default => 'Não foi possível confirmar a conta autorizada.',
            };

            BlingConexao::updateOrCreate(['company_id' => $company->id], [
                'status' => BlingConexao::STATUS_DIVERGENTE,
                'access_token' => null,
                'refresh_token' => null,
                'connected_at' => null,
                'last_error' => $motivo,
                'last_error_at' => now(),
            ]);

            Log::warning("[Bling] Conexão divergente ({$comparacao}) para a empresa {$company->id} ({$company->name}).");

            return $this->erro($motivo);
        }

        BlingConexao::updateOrCreate(['company_id' => $company->id], [
            'bling_empresa_id' => (string) ($basicos['id'] ?? ''),
            'bling_cnpj' => $basicos['cnpj'] ?? null,
            'bling_nome' => $basicos['nome'] ?? null,
            'access_token' => $token['access_token'] ?? null,
            'refresh_token' => $token['refresh_token'] ?? null,
            'expires_at' => now()->addSeconds((int) ($token['expires_in'] ?? 0)),
            'last_refreshed_at' => now(),
            'connected_at' => now(),
            'status' => BlingConexao::STATUS_ATIVA,
            'last_error' => null,
            'last_error_at' => null,
        ]);

        Log::info("[Bling] Conexão concluída para a empresa {$company->id} ({$company->name}).");

        return $this->renderizar('concluido', $company);
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
     * técnico (nome de classe, caminho, `code`, `state`, token) chega até
     * aqui — só a mensagem em pt-BR já pronta para o cliente ler.
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
