<?php

namespace App\Http\Controllers;

use App\Jobs\LerProdutosDoBlingJob;
use App\Models\BlingConexao;
use App\Models\Company;
use App\Services\Bling\BlingOAuthService;
use App\Services\Bling\EstoqueDoBlingService;
use App\Services\Bling\ImportadorDoBling;
use App\Services\Publicador\PainelVisaoGeralService;
use App\Services\Publicador\ProgramasPublicadorService;
use App\Support\Portal\AtorDoPortal;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Fase 184, Plano 11 (BLING-20, BLING-27, BLING-31) — as sete ações
 * autenticadas que o bloco do Bling nas Configurações da loja chama
 * (plano 12 é a tela; este controller é só o lado servidor).
 *
 * ⚠️ **Nenhum método daqui recalcula o ESTADO da conexão.** `estado()` e
 * `desconectar()` devolvem o bloco `bling` que `PainelVisaoGeralService`
 * monta — a mesma disciplina de fonte única que já existe para o ERP
 * declarado (ver o docblock de `PainelVisaoGeralService::integracoes()`).
 * Duas implementações do mesmo estado já divergiram neste projeto uma vez
 * (o ERP, tela A × Visão geral) — não repetir.
 *
 * Todas as sete rotas vivem no grupo `role:admin` de `routes/mlb_anuncios.php`
 * (T-184-74), com `{conta}` travado por `where('conta', '(empresa|company)-
 * [0-9]+')` (T-184-75) e throttle de prefixo PRÓPRIO por ação (T-184-77): um
 * prefixo compartilhado faria uma conta consumir a cota do Bling de outra.
 */
class MlbPublicadorBlingController extends Controller
{
    public function __construct(
        private ProgramasPublicadorService $programas,
        private PainelVisaoGeralService $painel,
        private BlingOAuthService $oauth,
        private ImportadorDoBling $importador,
        private EstoqueDoBlingService $estoque,
    ) {}

    /**
     * O bloco `bling` de `PainelVisaoGeralService::integracoes()` — nenhum
     * cálculo de estado aqui, só o repasse.
     */
    public function estado(string $conta): JsonResponse
    {
        $alvo = $this->alvo($conta);
        $empresaParaTela = $this->programas->empresaParaTela($alvo);

        return response()->json($this->painel->integracoes($alvo, $empresaParaTela)['bling']);
    }

    /**
     * Gera (ou regera) o link assinado de 7 dias. A geração em si mora em
     * `BlingOAuthService::gerarLinkDeAutorizacao()` (plano 07) — o mesmo
     * método que `BlingOAuthController::gerarLink()` chama. Este controller
     * NÃO chama aquele (controller não chama controller) e não o modifica.
     */
    public function gerarLink(string $conta, Request $request): JsonResponse
    {
        $this->garantirConfigurado();

        $alvo = $this->alvo($conta);
        $company = $this->company($alvo);

        try {
            $dados = $this->oauth->gerarLinkDeAutorizacao($company, $request->user()?->id);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($dados);
    }

    /**
     * Revoga a autorização e apaga o token local (`BlingOAuthService::revogar`,
     * plano 04) — NUNCA apaga `bling_vinculos` nem nada do Portal: o histórico
     * do que estava vinculado é o que permite reconectar sem reimportar
     * (T-184-81).
     */
    public function desconectar(string $conta): JsonResponse
    {
        $this->garantirConfigurado();

        $alvo = $this->alvo($conta);
        $company = $this->company($alvo);

        $conexao = BlingConexao::where('company_id', $company->id)->first();

        if ($conexao === null) {
            return response()->json(['message' => 'Esta empresa ainda não tem conexão com o Bling para desconectar.'], 422);
        }

        $this->oauth->revogar($conexao);

        $empresaParaTela = $this->programas->empresaParaTela($alvo);

        return response()->json($this->painel->integracoes($alvo, $empresaParaTela)['bling']);
    }

    /**
     * Despacha uma rodada de `LerProdutosDoBlingJob` (plano 08), na fila
     * `publicador-ia`. Travado por `Cache::lock` E por rodada já em
     * andamento (T-184-77/T-184-78): duas leituras simultâneas da mesma
     * empresa dobrariam o consumo da cota do Bling DO CLIENTE — a chamada
     * repetida devolve a MESMA rodada em vez de despachar um segundo Job.
     */
    public function iniciarLeitura(string $conta): JsonResponse
    {
        $this->garantirConfigurado();

        $alvo = $this->alvo($conta);
        $company = $this->company($alvo);

        $chaveRodadaAtiva = self::chaveRodadaAtiva($company->id);

        return Cache::lock("bling-leitura-lock-{$company->id}", 10)->block(5, function () use ($company, $chaveRodadaAtiva) {
            $rodadaAtiva = Cache::get($chaveRodadaAtiva);

            if (is_string($rodadaAtiva) && $this->rodadaEmAndamento($company->id, $rodadaAtiva)) {
                return response()->json(['rodada' => $rodadaAtiva]);
            }

            $rodada = (string) Str::uuid();
            Cache::put($chaveRodadaAtiva, $rodada, LerProdutosDoBlingJob::RODADA_TTL);

            // Semeia o estado da rodada ANTES de despachar — no mesmo formato que o
            // próprio Job grava (`ler()`, quando o cache ainda está vazio). Sem isto, a
            // trava de "rodada em andamento" só funcionaria depois do Job ter rodado ao
            // menos uma fatia: numa fila `sync` sem worker, ou num teste com `Queue::fake()`,
            // o Job nunca escreve nada, e a 2ª chamada veria o cache vazio e despacharia
            // um segundo Job — exatamente o dobro de cota do Bling DO CLIENTE que a trava
            // existe para evitar.
            Cache::put(LerProdutosDoBlingJob::chaveEstado($company->id, $rodada), [
                'pagina' => 1, 'produtos' => [], 'avisos' => [], 'erro' => null, 'concluida' => false, 'lidos' => 0,
            ], LerProdutosDoBlingJob::RODADA_TTL);

            LerProdutosDoBlingJob::dispatch($company->id, $rodada);

            return response()->json(['rodada' => $rodada]);
        });
    }

    /** `ImportadorDoBling::previa()` (plano 08) — nunca grava nada. */
    public function progresso(string $conta, string $rodada): JsonResponse
    {
        $this->garantirConfigurado();

        $alvo = $this->alvo($conta);
        $company = $this->company($alvo);

        return response()->json($this->importador->previa($company, $rodada));
    }

    /**
     * `ImportadorDoBling::aplicar()` (plano 08), com o ator da EQUIPE —
     * quem clicou foi uma pessoa; `AtorDoPortal::sistema()` é só para a
     * varredura agendada.
     */
    public function importar(string $conta, Request $request): JsonResponse
    {
        $this->garantirConfigurado();

        $alvo = $this->alvo($conta);
        $company = $this->company($alvo);

        $rodada = $request->string('rodada')->value();
        if ($rodada === '') {
            return response()->json(['message' => 'Rodada não informada.'], 422);
        }

        $user = $request->user();
        abort_if($user === null, 403);

        $resultado = $this->importador->aplicar($company, $rodada, AtorDoPortal::daEquipe($user));

        return response()->json($resultado);
    }

    /**
     * `EstoqueDoBlingService::varrer()` (plano 09) — 422 quando a conexão
     * não está `ativa` (varrer sem conexão viva não tem o que ler).
     */
    public function atualizarEstoque(string $conta): JsonResponse
    {
        $this->garantirConfigurado();

        $alvo = $this->alvo($conta);
        $company = $this->company($alvo);

        $conexao = BlingConexao::where('company_id', $company->id)->first();

        if ($conexao === null || ! $conexao->estaAtiva()) {
            return response()->json(['message' => 'A conexão com o Bling precisa estar ativa para atualizar o estoque agora.'], 422);
        }

        return response()->json($this->estoque->varrer($conexao));
    }

    // ═══ Internos ═════════════════════════════════════════════════════════

    /** A chave do cache onde a rodada ATIVA desta empresa fica — para a trava de `iniciarLeitura()`. */
    private static function chaveRodadaAtiva(int $companyId): string
    {
        return "bling-leitura-atual-{$companyId}";
    }

    /** `true` quando a rodada existe em cache e ainda não terminou (nem com sucesso, nem com erro). */
    private function rodadaEmAndamento(int $companyId, string $rodada): bool
    {
        $estado = Cache::get(LerProdutosDoBlingJob::chaveEstado($companyId, $rodada));

        if (! is_array($estado)) {
            return false;
        }

        return ! ($estado['concluida'] ?? false) && ($estado['erro'] ?? null) === null;
    }

    /**
     * Resolve `{conta}` para `$alvo` — mesmo padrão de
     * `MlbPublicadorEntradaController::configuracoes()`: `{conta}` fora do
     * padrão (`(empresa|company)-[0-9]+`) já morreu na ROTA; aqui só falta a
     * conta não existir de verdade.
     *
     * @return array{mlb_empresa: ?\App\Models\MlbEmpresa, company: ?Company, programa: string, chave: string}
     */
    private function alvo(string $conta): array
    {
        $alvo = $this->programas->resolver($conta);
        abort_if($alvo === null, 404);

        return $alvo;
    }

    /**
     * A `Company` do `$alvo`, ou um 422 em pt-BR — NUNCA cria uma Company
     * (CONTEXT §4.3, T-184-76). `HttpResponseException` em vez de retorno
     * nulo: o chamador não precisa checar null em toda ação.
     */
    private function company(array $alvo): Company
    {
        $company = $alvo['company'] ?? null;

        if ($company === null) {
            throw new HttpResponseException(response()->json([
                'message' => 'Esta conta ainda não tem uma empresa cadastrada no sistema — a equipe precisa ligar uma antes de conectar o Bling.',
            ], 422));
        }

        return $company;
    }

    /**
     * T-184-82: `.env` vazio nunca pode virar 500 — as seis ações que de
     * fato FALAM com o Bling (ou preparam para falar) checam a configuração
     * ANTES de qualquer outra coisa. `estado()` fica de fora de propósito:
     * ela já reporta `nao_configurado` como um dos sete estados normais
     * (fonte única em `PainelVisaoGeralService::bling()`), não como erro.
     */
    private function garantirConfigurado(): void
    {
        try {
            $this->oauth->assertConfigurado();
        } catch (\RuntimeException $e) {
            throw new HttpResponseException(response()->json(['message' => $e->getMessage()], 422));
        }
    }
}
