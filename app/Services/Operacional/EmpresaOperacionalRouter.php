<?php

namespace App\Services\Operacional;

use App\Http\Controllers\ComercialController;
use App\Models\Company;
use App\Models\Configuracao;
use App\Models\ContratoAssinatura;
use App\Models\ContratoLiberacao;
use App\Models\MlbEmpresa;
use App\Models\Servico;
use App\Services\MlbImplementacaoFactory;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Fase 124 — o lugar único que sabe transformar "serviço contratado" em
 * ficha de operação (`MlbEmpresa` + `MlbImplementacao`).
 *
 * Unifica as duas mecânicas de roteamento que hoje vivem duplicadas em
 * `ComercialController::store()` (cadastro manual) e
 * `HubspotWebhookController::rotearImplementacao()` (webhook) — SEM mudar
 * o comportamento de nenhuma delas. A Fase 124 é refatoração pura: as duas
 * mecânicas continuam preservadas separadamente (D-08), não fundidas numa
 * única regra.
 *
 * Também é o ponto único de leitura do interruptor de emergência
 * (`self::CHAVE_BLOQUEIO`, REDE-01, D-05 do CONTEXT da Fase 124): a chave
 * nasce e permanece DESLIGADA em produção durante toda a milestone v22.0,
 * até a Fase 133 decidir ligá-la. Ligar/desligar/testar num lugar só é o
 * motivo de o interruptor viver aqui dentro, e não espalhado pelos dois
 * controllers.
 *
 * Este service NÃO tem chamador ainda — os dois controllers continuam com
 * o código inline de hoje. Religar os dois caminhos para consumir este
 * router é escopo do plano 124-05, de propósito: separa o risco de
 * escrever o service novo do risco de trocar o caminho de produção.
 */
class EmpresaOperacionalRouter
{
    /**
     * Chave em `configuracoes` do interruptor de emergência (REDE-01).
     * Contrato entre as Fases 124 (mecanismo), 128 (isenção por serviço),
     * 131 (tela do admin que aciona) e 133 (ativação em produção) — não
     * renomear sem atualizar as quatro pontas.
     */
    public const CHAVE_BLOQUEIO = 'administrativo_bloqueio_ativo';

    /**
     * Lê o interruptor de emergência (REDE-01, D-04/D-05 do CONTEXT).
     *
     * A chave nasce e permanece DESLIGADA — não existe migration de seed
     * gravando valor nenhum, o "desligado" vem do default deste método. A
     * tela que a aciona é da Fase 131; ligá-la em produção é decisão da
     * Fase 133. Convenção do projeto: booleano persistido como string
     * '1'/'0', nunca `true`/`false` nativo.
     */
    public function bloqueioAtivo(): bool
    {
        return Configuracao::get(self::CHAVE_BLOQUEIO, '0') === '1';
    }

    /**
     * Mecânica do caminho webhook HubSpot — hoje
     * `HubspotWebhookController::rotearImplementacao()`. Chamada UMA VEZ
     * POR NOME de serviço, COM guard contra empresa que já tem ficha de
     * operação (reaproveitado como está — D-06/D-07).
     */
    public function rotearServico(Company $company, string $nomeServico, array $handoff = []): void
    {
        $this->rotear($company, [$nomeServico], $handoff, guardPorEmpresa: true);
    }

    /**
     * Mecânica do caminho Comercial — hoje bloco inline de
     * `ComercialController::store()`. Recebe a lista de nomes de serviço
     * de UMA submissão, deduplicada por TIPO, SEM guard entre iterações.
     *
     * A ausência do guard aqui é DELIBERADA (D-08 do CONTEXT da Fase 124 /
     * D10 do REQUIREMENTS-v22). Com dois serviços que geram ficha na mesma
     * submissão (ex.: Polos + Assessoria), este caminho cria DUAS
     * `MlbEmpresa` — o webhook, para o mesmo cenário, criaria só UMA.
     * Aplicar o guard também aqui unificaria as duas mecânicas e mudaria
     * comportamento observável, o que a Fase 124 proíbe. O caso já foi
     * medido como inalcançável em produção (2026-08-07: zero empresas com
     * 2+ fichas), mas a divergência fica preservada e documentada — não
     * "corrigida" por conta própria.
     */
    public function rotearCadastro(Company $company, iterable $nomesServicos, array $handoff = []): void
    {
        $this->rotear($company, $nomesServicos, $handoff, guardPorEmpresa: false);
    }

    /**
     * Único ponto de roteamento de fato — as duas APIs públicas acima só
     * delegam para cá, e é aqui, e só aqui, que o interruptor é lido
     * (D-05).
     *
     * Fase 129 Plano 04: o corpo posterior à checagem do interruptor foi
     * extraído para `aplicarRoteamento()` — é o método que `liberarEmpresa()`
     * chama DIRETO, sem passar pelo interruptor (ver docblock de
     * `liberarEmpresa()` para o porquê).
     */
    private function rotear(Company $company, iterable $nomesServicos, array $handoff, bool $guardPorEmpresa): void
    {
        if ($this->bloqueioAtivo()) {
            // Interruptor de emergência (REDE-01). Ligado, o roteamento automático PARA:
            // nenhuma MlbEmpresa e nenhuma implementação são criadas.
            // Em produção a chave está desligada e continua desligada até a Fase 133 —
            // nada muda hoje. O bloqueio existe e é exercitado agora para que, quando a
            // operação passar a depender dele, ele já tenha sido provado.
            //
            // PONTO DE EXTENSÃO da Fase 128 (FLUXO-08/D-09): aqui vai entrar a consulta
            // "este serviço exige contrato?", que isenta Polos do bloqueio.
            Log::warning('[Administrativo] Roteamento operacional bloqueado pelo interruptor de emergência (' . self::CHAVE_BLOQUEIO . ').', [
                'company_id' => $company->id,
            ]);

            return;
        }

        $this->aplicarRoteamento($company, $nomesServicos, $handoff, $guardPorEmpresa);
    }

    /**
     * Mecânica de roteamento de fato, SEM a checagem do interruptor —
     * extraída de `rotear()` na Fase 129 Plano 04 para que `liberarEmpresa()`
     * possa chamá-la sem respeitar o bloqueio (ver docblock de
     * `liberarEmpresa()`).
     *
     * Comportamento IDÊNTICO ao que estava inline em `rotear()` — nenhuma
     * mudança observável de `rotearServico()`/`rotearCadastro()`.
     *
     * **CR-01 (revisão de código da Fase 129):** quando `$guardPorEmpresa`
     * é verdadeiro, o guard check-then-act (`exists()` + `criarFicha()`)
     * agora roda dentro de `lockDaEmpresa()` (`Cache::lock()`). Sem a trava,
     * dois workers de fila processando envelopes DIFERENTES da MESMA
     * empresa quase ao mesmo tempo (ex.: Polos + Assessoria assinados em
     * sequência rápida) podiam os dois ler `exists()===false` antes de
     * qualquer um commitar e os dois criar `MlbEmpresa` — violando a D-02.
     * O caminho sem guard (Comercial/`rotearCadastro()`) continua SEM lock
     * de propósito: é uma única submissão, um único processo, sem chamador
     * concorrente a serializar (D-08 do CONTEXT da Fase 124).
     *
     * @return bool `true` se criou ao menos uma `MlbEmpresa`.
     */
    private function aplicarRoteamento(Company $company, iterable $nomesServicos, array $handoff, bool $guardPorEmpresa): bool
    {
        // Mesmo helper estático dos dois caminhos hoje — fonte única da
        // regra Polos/Assessoria/Incubadora.
        $tipos = collect($nomesServicos)
            ->map(fn(string $nome) => ComercialController::servicoDisparaImplementacao($nome))
            ->filter();

        // Dedup por TIPO só no caminho sem guard (Comercial) — é a mecânica
        // que já existe hoje em ComercialController::store().
        if (!$guardPorEmpresa) {
            $tipos = $tipos->unique();
        }

        $criarFichas = function () use ($company, $tipos, $handoff, $guardPorEmpresa): bool {
            $criouFicha = false;

            foreach ($tipos->values() as $tipo) {
                if ($guardPorEmpresa && MlbEmpresa::where('company_id', $company->id)->exists()) {
                    return $criouFicha;
                }

                $this->criarFicha($company, $tipo, $handoff);
                $criouFicha = true;
            }

            return $criouFicha;
        };

        if (!$guardPorEmpresa) {
            return $criarFichas();
        }

        // Trava atômica por empresa (CR-01). `block(5, ...)` espera até 5s
        // pela trava e lança `LockTimeoutException` se não conseguir — o
        // job (tries=3, backoff em 129-03) reprocessa naturalmente; 5s é
        // folgado porque a seção crítica é só um SELECT + um INSERT.
        return $this->lockDaEmpresa($company->id)->block(5, $criarFichas);
    }

    /**
     * Fábrica da trava por empresa usada pelo guard de "ficha única" (D-02,
     * CR-01). Extraído em método próprio (protected, não inline) por dois
     * motivos: (1) permite o teste da corrida interceptar o momento exato
     * em que um chamador está prestes a disputar a trava, sem precisar de
     * paralelismo real de SO; (2) é o MESMO ponto que a Fase 130 (SC4) vai
     * precisar para a corrida entre liberação manual e webhook — como o
     * lock vive dentro de `aplicarRoteamento()`, `liberarEmpresa()` já o
     * herda automaticamente, sem duplicar a trava por chamador.
     *
     * `Cache::lock()` funciona com `CACHE_STORE=database` (padrão do
     * projeto, ver CLAUDE.md): a migration `0001_01_01_000001_create_
     * cache_table` cria a tabela `cache_locks`, e `DatabaseLock` a usa para
     * adquirir/liberar por INSERT/UPDATE condicional — é mutex real, não
     * uma leitura+escrita comum sobre a tabela `cache`. A chave é por
     * `company_id`: envelopes de EMPRESAS DIFERENTES continuam correndo em
     * paralelo, preservando o desenho da D-06 e o `WithoutOverlapping` por
     * envelope de `ProcessarEventoClicksignJob::middleware()`.
     */
    protected function lockDaEmpresa(int $companyId): Lock
    {
        return Cache::lock('operacional-guarda-empresa-' . $companyId, 10);
    }

    /**
     * Fase 129 Plano 04 (D-01/D-02/D-03/D-05) — o ponto único que executa a
     * liberação de uma empresa para o operacional, compartilhado pelo
     * fluxo automático do webhook Clicksign (esta fase) e pela liberação
     * manual da Fase 130.
     *
     * **Por que nasce AQUI, na Fase 129, e não é deixado para a 130:**
     * (a) o SC3 da Fase 130 exige que a liberação manual use *o mesmo*
     * método do fluxo automático; se a 130 o criasse, o fluxo automático
     * da 129 teria nascido por outro caminho e a duplicação que a FLUXO-04
     * eliminou voltaria pela porta dos fundos;
     * (b) o SC4 da Fase 130 (liberação manual e webhook correndo ao mesmo
     * tempo sem criar `MlbEmpresa` duplicada) só é garantível num ponto
     * único guardado;
     * (c) a coluna `via` da D-05 já nasceu prevendo os dois chamadores.
     *
     * **Por que NÃO respeita o interruptor `bloqueioAtivo()`:** o
     * interruptor da REDE-01 existe para parar o roteamento automático
     * PRÉ-contrato; quando a Fase 133 o ligar, a liberação por contrato
     * assinado passa a ser o ÚNICO caminho para o operacional — se ela
     * também respeitasse o interruptor, nenhuma empresa jamais chegaria à
     * operação e o bloqueio viraria uma parede sem porta. Contorno
     * DELIBERADO (T-129-25): se a Fase 133 precisar parar TUDO, o
     * desligamento tem que ser feito na origem (não gerar contrato), não
     * aqui.
     *
     * **D-02 (uma ficha só por empresa, mesmo liberando serviços em
     * semanas diferentes):** o guard `MlbEmpresa::where('company_id',
     * ...)->exists()` dentro de `aplicarRoteamento()` JÁ COBRE essa
     * garantia sem precisar mudar — ele reconsulta o banco a CADA chamada,
     * então sobrevive a liberações separadas no tempo. **A garantia é por
     * RECONSULTA, não por estado em memória.** Não "consertar" isto — não
     * está quebrado.
     *
     * **Fora de escopo deste método, de propósito (D-04):** contrato
     * recusado ou expirado NÃO mexe no cadastro. Nada aqui desativa
     * `ContratoServico`, nada apaga `MlbEmpresa`. A decisão de tirar um
     * serviço da empresa é humana.
     */
    public function liberarEmpresa(
        Company $company,
        Servico $servico,
        string $via,
        ?ContratoAssinatura $contrato = null,
        ?int $liberadoPorUserId = null,
        ?string $motivo = null,
        array $handoff = []
    ): ContratoLiberacao {
        // 1. Guard de leitura (idempotência do EFEITO, CLICK-04): dois
        // eventos diferentes do mesmo envelope (`sign` e `document_closed`)
        // podem chegar e os dois tentar liberar. Se já existe, devolve a
        // existente sem criar nada e sem rotear de novo.
        $existente = ContratoLiberacao::existeParaServico($company->id, $servico->id);
        if ($existente !== null) {
            return $existente;
        }

        // 2. Cria a linha de liberação ANTES de qualquer efeito — a
        // liberação é gravada mesmo que nada mais aconteça (D-03): "Gestão"
        // sozinho não gera ficha (`ComercialController::servicoDisparaImplementacao()`
        // só reconhece Polos, Assessoria e Incubadora), e uma empresa
        // só-Gestão precisa constar como liberada — senão o alerta da
        // REDE-03 acusa falso positivo eterno.
        try {
            $liberacao = ContratoLiberacao::create([
                'company_id'            => $company->id,
                'servico_id'            => $servico->id,
                'contrato_assinatura_id' => $contrato?->id,
                'via'                   => $via,
                'liberado_por_user_id'  => $liberadoPorUserId,
                'motivo'                => $motivo,
                'gerou_ficha'           => false,
                'liberado_em'           => now(),
            ]);
        } catch (QueryException $e) {
            // A corrida real (webhook + liberação manual da Fase 130 no
            // mesmo instante) passa pelo guard de leitura do passo 1 nos
            // dois lados; só a constraint `cl_empresa_servico_uniq` a
            // resolve de verdade.
            if ((string) $e->getCode() === '23000') {
                return ContratoLiberacao::existeParaServico($company->id, $servico->id)
                    ?? throw $e;
            }

            throw $e;
        }

        // 3. Roteia SEM passar pelo interruptor (ver docblock acima).
        $gerouFicha = $this->aplicarRoteamento($company, [$servico->nome], $handoff, guardPorEmpresa: true);

        // 4. Atualiza a prova material da D-03.
        if ($gerouFicha) {
            $liberacao->gerou_ficha = true;
            $liberacao->save();
        }

        // 5. `save()`, nunca `update()` de query builder — o hook `saving`
        // de `ContratoAssinatura` é o que alimenta a trava
        // `ca_empresa_servico_andamento_uniq`; pular o hook desliga a
        // trava em silêncio.
        if ($contrato !== null) {
            $contrato->liberado_em = now();
            $contrato->save();
        }

        Log::info('[Administrativo] Empresa liberada para o operacional', [
            'company_id'  => $company->id,
            'servico_id'  => $servico->id,
            'via'         => $via,
            'gerou_ficha' => $gerouFicha,
            'contrato_id' => $contrato?->id,
        ]);

        return $liberacao;
    }

    /**
     * Cria a ficha de operação para um tipo já resolvido. Campos idênticos
     * aos dois caminhos hoje: nenhum ramo seta `fase` ou `estagio`, só o
     * ramo `polos` seta `projeto` e dispara `MlbImplementacao`.
     */
    private function criarFicha(Company $company, string $tipo, array $handoff): void
    {
        if ($tipo === 'polos') {
            $mlbEmp = MlbEmpresa::create([
                'nome'       => $company->name,
                'tipo'       => 'POLO',
                'projeto'    => 'POLOS',
                'company_id' => $company->id,
            ]);

            // Chamada direta à factory (D-03): o wrapper que hoje delega
            // uma linha em ComercialController deixa de existir no plano
            // 124-05. Com $handoff vazio o efeito é idêntico ao do webhook
            // de hoje, que não passa nada.
            MlbImplementacaoFactory::criarParaPolo($mlbEmp, $handoff);
        } elseif ($tipo === 'assessoria') {
            MlbEmpresa::create([
                'nome'       => $company->name,
                'tipo'       => 'ASSESSORIA',
                'company_id' => $company->id,
            ]);
        } elseif ($tipo === 'incubadora') {
            MlbEmpresa::create([
                'nome'       => $company->name,
                'tipo'       => 'INCUBADORA',
                'company_id' => $company->id,
            ]);
        }
    }
}
