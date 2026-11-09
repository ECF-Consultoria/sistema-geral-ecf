<?php

namespace App\Services\Renovacao;

use App\Models\Company;
use App\Models\ContratoRenovacao;
use App\Models\ContratoServico;
use App\Services\Contratos\ContratoGrupos;
use App\Services\Contratos\TerminoContratoService;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Fase 202 (REN-02) — detecção idempotente de ciclos de renovação.
 *
 * UM ciclo por (empresa, serviço dono, número). Corrigir o término atualiza o
 * mesmo ciclo. Contrato já vencido só ganha ciclo por pedido manual.
 */
class RenovacaoDetector
{
    public function __construct(
        private ContratoGrupos $grupos,
        private NegocioOriginalResolver $original,
        private TerminoContratoService $termino,
    ) {}

    public static function chaveCiclo(int $companyId, int $servicoId, int $numero): string
    {
        return "ren:{$companyId}:{$servicoId}:{$numero}";
    }

    public static function chaveCorrelacao(int $companyId, int $servicoId, int $numero): string
    {
        return config('services.hubspot.renovacao.prefixo_correlacao') . ':' . self::chaveCiclo($companyId, $servicoId, $numero);
    }

    /** Mesma regra da tela: maior data_vencimento não nula do grupo. */
    public function terminoDoGrupo(Collection $membros): ?Carbon
    {
        $max = $membros->pluck('data_vencimento')->filter()->max();

        return $max === null ? null : Carbon::parse($max)->startOfDay();
    }

    /**
     * @return Collection<int, ContratoRenovacao> ciclos a conciliar
     */
    public function detectar(?Carbon $hoje = null, ?int $companyId = null): Collection
    {
        $hoje  = ($hoje ?? Carbon::today())->copy()->startOfDay();
        $saida = collect();

        $query = Company::query()
            ->where('active', true)
            ->whereHas('contratosServico', function ($q) {
                $q->where('ativo', true)->whereHas('servico', fn ($s) => $s->where('exige_contrato', true));
            })
            ->orderBy('id');

        if ($companyId !== null) {
            $query->where('id', $companyId);
        }

        $query->chunkById(100, function ($empresas) use ($hoje, $saida) {
            foreach ($empresas as $empresa) {
                foreach ($this->gruposDaEmpresa($empresa) as $donoId => $membros) {
                    $ciclo = $this->processarGrupo($empresa, (int) $donoId, $membros, $hoje);

                    if ($ciclo !== null) {
                        $saida->push($ciclo);
                    }
                }
            }
        });

        return $saida;
    }

    /**
     * Cria/retorna o ciclo corrente (botão manual, D-07).
     *
     * @throws \DomainException
     */
    public function garantirCiclo(Company $company, int $servicoDonoId, bool $permitirVencido = false, string $origem = 'manual', ?int $userId = null): ContratoRenovacao
    {
        $membros = $this->gruposDaEmpresa($company)->get($servicoDonoId);

        if ($membros === null) {
            throw new \DomainException('Contrato não encontrado ou inativo para esta empresa.');
        }

        $hoje  = Carbon::today();
        $ciclo = $this->cicloCorrente($company->id, $servicoDonoId);

        if ($ciclo !== null && ! $this->ehRenovado($ciclo)) {
            $this->sincronizarTermino($ciclo, $origem);

            return $ciclo->fresh();
        }

        $termino  = $this->terminoDoGrupo($membros);
        $situacao = RenovacaoJanela::situacao($termino, $hoje);

        if ($situacao === RenovacaoJanela::SEM_TERMINO) {
            throw new \DomainException('Este contrato não tem data de término.');
        }
        if ($situacao === RenovacaoJanela::FORA_DA_JANELA) {
            throw new \DomainException('Este contrato ainda não entrou na janela de renovação.');
        }
        if ($situacao === RenovacaoJanela::VENCIDO && ! $permitirVencido) {
            throw new \DomainException('Este contrato já venceu; confirme para abrir a renovação manualmente.');
        }

        return $this->criarCiclo($company, $servicoDonoId, $membros, $termino, $ciclo, $origem, $userId);
    }

    /**
     * Recalcula o término atual do grupo e atualiza o ciclo não final.
     *
     * Fase 202-08 — corrigir o término NÃO cria ciclo novo (REN-02). Quando o
     * término muda:
     * - ciclo SEM negócio de renovação e sem tentativa remota:
     *   - novo término ainda na janela (ou vencido) → atualiza o término capturado;
     *   - novo término saiu da janela (ficou mais longe) → `dispensado` com
     *     justificativa automática; quando o contrato voltar a entrar na janela,
     *     `processarGrupo()` abre o ciclo seguinte pelo término novo;
     * - ciclo COM negócio criado ou tentativa remota → nunca mexe no HubSpot
     *   sozinho: atualiza o término capturado (a extensão parte dele) e manda
     *   para `revisao_manual`. Exceção: a chamada do webhook do ganho
     *   (`$marcarRevisao = false`), que só ressincroniza antes de estender.
     * Sempre registra o evento `termino_corrigido`.
     */
    public function sincronizarTermino(ContratoRenovacao $ciclo, string $origem = 'agendador', bool $marcarRevisao = true, ?Carbon $hoje = null): ?Carbon
    {
        $membros = $this->gruposDaEmpresa($ciclo->company)->get((int) $ciclo->servico_id);

        if ($membros === null) {
            return null;
        }

        $atual = $this->terminoDoGrupo($membros);

        if ($atual === null || in_array($ciclo->status_comercial, ContratoRenovacao::STATUS_COMERCIAIS_FINAIS, true)) {
            return $atual;
        }

        $antes = $ciclo->termino_capturado?->toDateString();

        if ($antes === $atual->toDateString()) {
            return $atual;
        }

        $hoje         = ($hoje ?? Carbon::today())->copy()->startOfDay();
        $temNegocio   = $ciclo->negocio_renovacao_id !== null || $ciclo->criacao_iniciada_em !== null;
        $saiuDaJanela = RenovacaoJanela::situacao($atual, $hoje) === RenovacaoJanela::FORA_DA_JANELA;
        $dados        = ['termino_antes' => $antes, 'termino_depois' => $atual->toDateString()];

        if ($temNegocio) {
            $atualizacao = ['termino_capturado' => $atual->toDateString()];

            if ($marcarRevisao) {
                $atualizacao['status_integracao'] = ContratoRenovacao::STATUS_INTEGRACAO_REVISAO_MANUAL;
                $atualizacao['ultimo_erro'] = "Término do contrato corrigido de {$this->br($antes)} para {$this->br($atual)} depois que o negócio de renovação já existia — conferir o negócio no HubSpot.";
                $dados['motivo'] = 'termino_corrigido';
                $dados['acao']   = 'revisao_manual';
            }

            $ciclo->update($atualizacao);
        } elseif ($saiuDaJanela) {
            $justificativa = "Término corrigido para {$this->br($atual)} (contrato assinado) — saiu da janela de renovação.";
            $ciclo->update([
                'termino_capturado'      => $atual->toDateString(),
                'status_comercial'       => ContratoRenovacao::STATUS_COMERCIAL_DISPENSADO,
                'dispensado_em'          => now(),
                'dispensa_justificativa' => $justificativa,
            ]);
            $dados['acao'] = 'dispensado';
        } else {
            $ciclo->update(['termino_capturado' => $atual->toDateString()]);
        }

        $ciclo->registrarEvento('termino_corrigido', $origem, null, $dados);

        return $atual;
    }

    private function br(Carbon|string|null $data): string
    {
        return $data === null ? '(sem data)' : Carbon::parse($data)->format('d/m/Y');
    }

    /** Linhas ativas agrupadas pelo dono, só grupos cujo dono exige contrato. */
    public function gruposDaEmpresa(Company $company): Collection
    {
        if (! $company->active) {
            return collect();
        }

        $ativos = ContratoServico::query()
            ->where('company_id', $company->id)
            ->where('ativo', true)
            ->with('servico')
            ->orderBy('id')
            ->get();

        if ($ativos->isEmpty()) {
            return collect();
        }

        return $this->grupos->agrupar($ativos)
            ->filter(fn ($grupo, $donoId) => $this->grupos->exigeContrato($grupo, (int) $donoId));
    }

    private function cicloCorrente(int $companyId, int $servicoId): ?ContratoRenovacao
    {
        return ContratoRenovacao::where('company_id', $companyId)
            ->where('servico_id', $servicoId)
            ->orderByDesc('numero_ciclo')
            ->first();
    }

    private function ehRenovado(ContratoRenovacao $c): bool
    {
        return $c->status_comercial === ContratoRenovacao::STATUS_COMERCIAL_RENOVADO;
    }

    private function processarGrupo(Company $empresa, int $donoId, Collection $membros, Carbon $hoje): ?ContratoRenovacao
    {
        $termino = $this->terminoDoGrupo($membros);
        $ciclo   = $this->cicloCorrente($empresa->id, $donoId);

        // Sem ciclo (ou o último já foi renovado e o seguinte ainda não existe).
        if ($ciclo === null || $this->ehRenovado($ciclo)) {
            if (RenovacaoJanela::situacao($termino, $hoje) !== RenovacaoJanela::NA_JANELA) {
                return null;
            }

            return $this->criarCiclo($empresa, $donoId, $membros, $termino, $ciclo, 'agendador', null);
        }

        if (in_array($ciclo->status_comercial, ContratoRenovacao::STATUS_COMERCIAIS_FINAIS, true)) {
            // Fase 202-08: ciclo dispensado SOZINHO porque o término foi corrigido para
            // fora da janela. Quando o término novo voltar à janela, abre o ciclo
            // seguinte (chave nova). Dispensa humana nunca reabre.
            if ($this->dispensadoPorTerminoCorrigido($ciclo)
                && $termino !== null
                && RenovacaoJanela::situacao($termino, $hoje) === RenovacaoJanela::NA_JANELA) {
                return $this->criarCiclo($empresa, $donoId, $membros, $termino, $ciclo, 'agendador', null);
            }

            return null;
        }

        $this->sincronizarTermino($ciclo, 'agendador', true, $hoje);
        $ciclo = $ciclo->fresh();

        if ($ciclo->status_comercial === ContratoRenovacao::STATUS_COMERCIAL_DISPENSADO) {
            return null;
        }

        if ($ciclo->status_integracao === ContratoRenovacao::STATUS_INTEGRACAO_REVISAO_MANUAL) {
            return null;
        }

        $naJanela = RenovacaoJanela::situacao($ciclo->termino_capturado, $hoje) === RenovacaoJanela::NA_JANELA;

        // Conciliação continua mesmo vencido quando já existe negócio de renovação.
        return ($naJanela || $ciclo->negocio_renovacao_id !== null) ? $ciclo : null;
    }

    private function dispensadoPorTerminoCorrigido(ContratoRenovacao $ciclo): bool
    {
        return $ciclo->status_comercial === ContratoRenovacao::STATUS_COMERCIAL_DISPENSADO
            && $ciclo->dispensado_por === null
            && $ciclo->negocio_renovacao_id === null
            && str_starts_with((string) $ciclo->dispensa_justificativa, 'Término corrigido para ');
    }

    private function criarCiclo(Company $empresa, int $donoId, Collection $membros, Carbon $termino, ?ContratoRenovacao $anterior, string $origem, ?int $userId): ContratoRenovacao
    {
        $numero = $anterior === null ? 1 : $anterior->numero_ciclo + 1;
        $meses  = $this->termino->mesesDeVigencia($empresa, $donoId, $membros);

        $atributos = [
            'ciclo_anterior_id'   => $anterior?->id,
            'chave_ciclo'         => self::chaveCiclo($empresa->id, $donoId, $numero),
            'chave_correlacao'    => self::chaveCorrelacao($empresa->id, $donoId, $numero),
            'termino_capturado'   => $termino->toDateString(),
            'inicio_vigencia'     => $anterior?->termino_novo?->toDateString(),
            'vigencia_meses'      => $meses,
            'negocio_original_id' => $anterior !== null ? $anterior->negocio_renovacao_id : $this->original->resolver($empresa),
            'hubspot_company_id'  => $empresa->hubspot_company_id,
            'detectado_em'        => now(),
        ];

        $chave = ['company_id' => $empresa->id, 'servico_id' => $donoId, 'numero_ciclo' => $numero];

        try {
            $ciclo = DB::transaction(fn () => ContratoRenovacao::firstOrCreate($chave, $atributos));
        } catch (QueryException $e) {
            // Corrida entre agendador e botão: o outro lado criou primeiro.
            $ciclo = ContratoRenovacao::where($chave)->first();

            if ($ciclo === null) {
                throw $e;
            }
        }

        if ($ciclo->wasRecentlyCreated) {
            $ciclo->registrarEvento('detectado', $origem, null, [
                'termino'      => $termino->toDateString(),
                'numero_ciclo' => $numero,
            ], false, $userId);
        }

        return $ciclo;
    }
}
