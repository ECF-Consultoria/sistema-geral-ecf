<?php

namespace App\Services\Operacional;

use App\Http\Controllers\ComercialController;
use App\Models\Company;
use App\Models\Configuracao;
use App\Models\MlbEmpresa;
use App\Services\MlbImplementacaoFactory;
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

        foreach ($tipos->values() as $tipo) {
            if ($guardPorEmpresa && MlbEmpresa::where('company_id', $company->id)->exists()) {
                return;
            }

            $this->criarFicha($company, $tipo, $handoff);
        }
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
