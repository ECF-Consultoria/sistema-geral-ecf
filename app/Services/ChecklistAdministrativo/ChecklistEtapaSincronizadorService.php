<?php

namespace App\Services\ChecklistAdministrativo;

use App\Models\ChecklistAdministrativoItem;
use App\Models\Company;
use App\Models\User;
use App\Services\FluxoEntrada\EtapaTransicaoService;
use Illuminate\Support\Facades\Log;

/**
 * ChecklistEtapaSincronizadorService — Fase 139 Plano 07. A régua da D-15:
 * traduz o PROGRESSO do checklist administrativo em transições de etapa
 * 1→2, 2→3 e 2/3→4, sempre por {@see EtapaTransicaoService::transicionar()}.
 *
 * Razão de existir: até este plano, nenhum chamador de produção escrevia as
 * etapas 2, 3 ou 4 de `companies.etapa` — só a etapa 1
 * (`HubspotWebhookController` e `ComercialController`, Fase 138). Como
 * `EtapaTransicaoService::TRANSICOES_PERMITIDAS` só permite chegar em
 * `aguardando_distribuicao` (5) vindo de `administrativo_concluido` (4), o
 * FINALIZAR do plano 139-06 nasceria morto sem esta classe: não haveria
 * como a empresa alcançar a etapa 4. Esta classe é o TERCEIRO chamador de
 * produção de `EtapaTransicaoService`, e a única a escrever as etapas
 * intermediárias.
 *
 * ⚠️ **Direção da dependência é one-way e NÃO pode ser invertida.** Este
 * service depende de {@see ChecklistAdministrativoService} e de
 * {@see FinalizarEntradaAdministrativaService} — nenhum dos dois pode
 * depender dele. Um construtor recíproco (ex.: `ChecklistAdministrativoService`
 * recebendo este service no construtor) fecharia um ciclo no autowiring do
 * container do Laravel — `Illuminate\Contracts\Container\CircularDependencyException`
 * — e derrubaria com 500 toda rota que type-hinte qualquer um dos três
 * serviços, incluindo `ContratoAdminController::show()`. Se um dia parecer
 * necessário que `ChecklistAdministrativoService` "avise" a etapa, a
 * resposta certa é o CHAMADOR orquestrar os dois (é o que o plano 139-08
 * faz), nunca a injeção recíproca.
 *
 * Quem DISPARA `sincronizar()` é a camada HTTP do plano 139-08 — esta
 * classe nunca é chamada de dentro de `ChecklistAdministrativoService` nem
 * de `FinalizarEntradaAdministrativaService`.
 */
class ChecklistEtapaSincronizadorService
{
    /**
     * As quatro etapas do vocabulário de `Company::ETAPAS` que este service
     * conhece — espelhadas aqui (não só referenciadas por `Company::`)
     * porque o contrato de saída do plano 139-07 as nomeia explicitamente.
     */
    public const ETAPA_AGUARDANDO_ADMINISTRATIVO = Company::ETAPA_AGUARDANDO_ADMINISTRATIVO;
    public const ETAPA_ADMINISTRATIVO_ANDAMENTO = Company::ETAPA_ADMINISTRATIVO_ANDAMENTO;
    public const ETAPA_AGUARDANDO_ASSINATURA = Company::ETAPA_AGUARDANDO_ASSINATURA;
    public const ETAPA_ADMINISTRATIVO_CONCLUIDO = Company::ETAPA_ADMINISTRATIVO_CONCLUIDO;

    /**
     * As etapas sob responsabilidade deste sincronizador — 1, 2 e 3. Etapa 4
     * em diante (inclusive) é fora de escopo: a 4→5 é do
     * {@see FinalizarEntradaAdministrativaService}, e as etapas posteriores
     * são das Fases 141/142.
     */
    private const ETAPAS_SOB_RESPONSABILIDADE = [
        self::ETAPA_AGUARDANDO_ADMINISTRATIVO,
        self::ETAPA_ADMINISTRATIVO_ANDAMENTO,
        self::ETAPA_AGUARDANDO_ASSINATURA,
    ];

    public function __construct(
        private ChecklistAdministrativoService $checklist,
        private FinalizarEntradaAdministrativaService $finalizar,
        private EtapaTransicaoService $etapas,
    ) {
    }

    /**
     * Traduz o estado atual do checklist administrativo da empresa em
     * transições de etapa, avançando EM CADEIA até no máximo 3 degraus (um
     * por etapa possível: 1→2, 2→3 ou 2/3→4) numa única chamada — uma
     * empresa que fecha tudo de uma vez pode subir 1→2→4 sem precisar de
     * uma segunda chamada.
     *
     * **Só AVANÇA — nunca retrocede.** Desmarcar um item depois de a
     * empresa ter chegado à etapa 4 NÃO a devolve para a 3: a
     * reversibilidade está registrada como Deferred no `139-CONTEXT.md` (não
     * discutida com o usuário), e `EtapaTransicaoService::podeTransicionar()`
     * já recusa retrocesso sem motivo explícito por desenho — inventar aqui
     * um motivo automático seria escrever no histórico uma decisão que
     * ninguém tomou.
     *
     * Guardas, nesta ordem:
     * 1. **Legado** (D-14 da Fase 138 / D-05 da Fase 137): `etapa === null`
     *    nunca é carimbada — devolve sem tocar em nada. Derivar etapa de
     *    estado externo é exatamente o que a máquina de estados existe para
     *    impedir.
     * 2. **Fora de escopo**: etapa atual fora de {1, 2, 3} — devolve sem
     *    transicionar. Empresa já em `administrativo_concluido` (4) ou
     *    adiante não é responsabilidade deste service.
     *
     * Toda transição passa por `$this->etapas->transicionar()`. Quando o
     * retorno vem com `status !== 'transicionado'`, loga e INTERROMPE a
     * cadeia — nunca tenta o degrau seguinte por cima de uma recusa.
     *
     * @return array{transicoes: array<int, string>, etapa_final: ?string}
     */
    public function sincronizar(Company $company, User $por): array
    {
        // Guarda de legado — empresa sem etapa nunca é carimbada.
        if ($company->etapa === null) {
            return ['transicoes' => [], 'etapa_final' => null];
        }

        // Guarda de escopo — a 4→5 é do FinalizarEntradaAdministrativaService,
        // as posteriores são de fases futuras.
        if (! in_array($company->etapa, self::ETAPAS_SOB_RESPONSABILIDADE, true)) {
            return ['transicoes' => [], 'etapa_final' => $company->etapa];
        }

        $transicoes = [];

        for ($degrau = 0; $degrau < 3; $degrau++) {
            $destino = $this->proximoDestino($company);

            if ($destino === null) {
                break;
            }

            $resultado = $this->etapas->transicionar($company, $destino, $por);

            if ($resultado['status'] !== 'transicionado') {
                Log::warning('[Checklist] transição de etapa recusada', [
                    'company_id' => $company->id,
                    'destino' => $destino,
                    'resultado' => $resultado,
                ]);

                break;
            }

            $transicoes[] = $destino;

            // Relê a empresa do banco antes de reavaliar o próximo degrau —
            // o mesmo `sincronizar()` pode aplicar mais de uma transição
            // numa chamada só (1→2→4, por exemplo).
            $company->refresh();
        }

        return ['transicoes' => $transicoes, 'etapa_final' => $company->etapa];
    }

    /**
     * Decide o PRÓXIMO degrau único a partir da etapa atual — nunca mais de
     * um por chamada; é o laço de `sincronizar()` que encadeia vários.
     *
     * Nas etapas 2 e 3, o salto para a 4 (via `podeFinalizar()`) é checado
     * ANTES do avanço normal 2→3: uma empresa que já fechou tudo (isenta,
     * D-07, ou com contrato assinado) não precisa passar pela etapa 3 — é o
     * salto 2→4 já previsto em `EtapaTransicaoService::TRANSICOES_PERMITIDAS`
     * e não exige tratamento especial aqui, só a ordem certa de checagem.
     */
    private function proximoDestino(Company $company): ?string
    {
        // 1 → 2: o primeiro item concluído tira a empresa de "aguardando
        // administrativo".
        if ($company->etapa === self::ETAPA_AGUARDANDO_ADMINISTRATIVO) {
            $progresso = $this->checklist->progresso($company);

            return $progresso['feitos'] >= 1 ? self::ETAPA_ADMINISTRATIVO_ANDAMENTO : null;
        }

        // 2 ou 3 → 4: tudo obrigatório concluído + contrato assinado (ou
        // isenção por ausência do grupo, D-07) — a mesma régua pura do
        // FINALIZAR decide o degrau.
        if (in_array($company->etapa, [self::ETAPA_ADMINISTRATIVO_ANDAMENTO, self::ETAPA_AGUARDANDO_ASSINATURA], true)
            && $this->finalizar->podeFinalizar($company)['permitido']) {
            return self::ETAPA_ADMINISTRATIVO_CONCLUIDO;
        }

        // 2 → 3: só quando a empresa EXIGE contrato e o envelope foi
        // enviado. Empresa isenta nunca tem `exige_contrato = true`, então
        // este degrau nunca dispara para ela (D-07) — sem envelope não
        // existe etapa 3.
        if ($company->etapa === self::ETAPA_ADMINISTRATIVO_ANDAMENTO) {
            $checklist = $this->checklist->paraEmpresa($company);

            if ($checklist['exige_contrato'] && $this->itemConcluido($checklist, ChecklistAdministrativoDefinicao::AUTO_FONTE_CONTRATO_ENVIADO)) {
                return self::ETAPA_AGUARDANDO_ASSINATURA;
            }
        }

        return null;
    }

    /**
     * Procura a chave nos grupos já montados por `paraEmpresa()` e devolve
     * se o item está concluído. `false` para chave ausente (ex.: grupo
     * Contrato não montado para empresa isenta) — nunca lança.
     *
     * @param array{exige_contrato: bool, grupos: array<string, array{chave:string, titulo:string, itens:array<int, array<string, mixed>>}>, progresso: array{feitos:int, total:int, percentual:int}} $checklist
     */
    private function itemConcluido(array $checklist, string $chave): bool
    {
        foreach ($checklist['grupos'] as $grupo) {
            foreach ($grupo['itens'] as $item) {
                if ($item['chave'] === $chave) {
                    return $item['status'] === ChecklistAdministrativoItem::STATUS_CONCLUIDO;
                }
            }
        }

        return false;
    }
}
