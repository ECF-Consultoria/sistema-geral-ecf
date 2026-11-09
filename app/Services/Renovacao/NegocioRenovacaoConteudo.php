<?php

namespace App\Services\Renovacao;

use App\Models\ContratoClicksignAssinado;
use App\Models\ContratoRenovacao;
use App\Models\ContratoServico;
use Carbon\Carbon;

/**
 * Renovação (09/11/2026) — o que o negócio de renovação mostra no HubSpot.
 *
 * Pedido do usuário depois do primeiro negócio real (VS Hospitalar): o nome
 * antigo ("Renovação | X | contrato 255-6 | ciclo 1 | término 2026-11-22") era
 * código interno, e o negócio chegava sem explicar a situação. Agora:
 *   - nome legível ("Renovação · Empresa · Serviço · vence dd/mm/aaaa");
 *   - data de fechamento = término do contrato (o prazo do Comercial);
 *   - razão social, CNPJ e e-mail de envio do contrato nas propriedades do negócio;
 *   - uma OBSERVAÇÃO (Note do HubSpot) com a situação do contrato e o que fazer.
 *
 * O identificador interno continua só na propriedade `ecf_renovacao_chave`
 * (é ela que casa o negócio com o ciclo — o nome não é usado para nada).
 * Valor (`amount`) continua fora de propósito: não presumir proposta (§4.4);
 * o valor ATUAL vai na observação como informação.
 */
class NegocioRenovacaoConteudo
{
    private const ORIGEM_LEGIVEL = [
        ContratoServico::ORIGEM_TERMINO_CONTRATO  => 'pelo contrato assinado',
        ContratoServico::ORIGEM_TERMINO_MANUAL    => 'informado pelo Administrativo',
        ContratoServico::ORIGEM_TERMINO_HUBSPOT   => 'pela data de fim do HubSpot',
        ContratoServico::ORIGEM_TERMINO_RENOVACAO => 'pela última renovação',
        ContratoServico::ORIGEM_TERMINO_CALCULADA => 'estimado (contrato assinado não encontrado — conferir)',
    ];

    /** Propriedades do negócio que o Comercial lê (sem pipeline/etapa/chave). */
    public function propriedades(ContratoRenovacao $ciclo): array
    {
        $company = $ciclo->company;
        $termino = $ciclo->termino_capturado;
        $props   = config('services.hubspot.props.deal');

        $out = [
            'dealname'    => mb_substr(sprintf(
                'Renovação · %s · %s · vence %s',
                trim((string) $company->name),
                $this->nomeServico($ciclo),
                $termino->format('d/m/Y')
            ), 0, 250),
            // Meio-dia UTC: evita que o fuso mostre o dia anterior no HubSpot.
            'closedate'   => $termino->format('Y-m-d') . 'T12:00:00Z',
            'description' => $this->descricaoCurta($ciclo),
        ];

        foreach ([
            'razao_social'         => $company->razao_social,
            'cnpj_da_empresa'      => $company->cnpj,
            'email_envio_contrato' => $company->email_cliente,
        ] as $chave => $valor) {
            if (filled($valor) && filled($props[$chave] ?? null)) {
                $out[$props[$chave]] = trim((string) $valor);
            }
        }

        return $out;
    }

    public function descricaoCurta(ContratoRenovacao $ciclo): string
    {
        $termino = $ciclo->termino_capturado;
        $dias    = $this->diasRestantes($termino);

        return sprintf(
            'O contrato de %s de %s termina em %s (%s). Negócio aberto automaticamente pelo ECF Admin para o Comercial conversar sobre a renovação. Detalhes na observação. Contrato: %s',
            $this->nomeServico($ciclo),
            trim((string) $ciclo->company->name),
            $termino->format('d/m/Y'),
            $dias >= 0 ? "faltam {$dias} dias" : 'já venceu há ' . abs($dias) . ' dias',
            route('admin.contratos.show', $ciclo->company)
        );
    }

    /** Corpo HTML da observação (Note) — o HubSpot aceita HTML simples. */
    public function observacao(ContratoRenovacao $ciclo): string
    {
        $company = $ciclo->company;
        $termino = $ciclo->termino_capturado;
        $dias    = $this->diasRestantes($termino);
        $e       = static fn ($v) => e(trim((string) $v));

        $situacao = $dias >= 0
            ? "termina em <b>{$termino->format('d/m/Y')}</b> — faltam <b>{$dias} dias</b>"
            : "terminou em <b>{$termino->format('d/m/Y')}</b> — <b>já venceu há " . abs($dias) . ' dias</b>';

        $linhas   = [];
        $linhas[] = '<p><b>Renovação de contrato — ' . $e($company->name) . '</b></p>';
        $linhas[] = '<p>O contrato de ' . $e($this->nomeServico($ciclo)) . " {$situacao}. "
            . 'Este negócio foi aberto automaticamente pelo ECF Admin para o Comercial falar com o cliente sobre a renovação.</p>';

        // Contrato atual
        $itens = [];
        foreach ($this->contratosDoGrupo($ciclo) as $cs) {
            $valor   = (float) ($cs->valor_contratado ?? 0);
            $itens[] = $e($cs->servico?->nome ?? 'Serviço')
                . ($valor > 0 ? ' — R$ ' . number_format($valor, 2, ',', '.') . '/mês' : '');
        }
        $dono   = $this->contratosDoGrupo($ciclo)->firstWhere('servico_id', $ciclo->servico_id);
        $origem = self::ORIGEM_LEGIVEL[$dono?->data_vencimento_origem] ?? null;

        $contrato = [];
        if ($itens !== []) {
            $contrato[] = 'Serviços: ' . implode('; ', $itens);
        }
        $assinado = $this->contratoAssinado($ciclo);
        if ($assinado?->assinado_em) {
            $contrato[] = 'Assinado na Clicksign em ' . $assinado->assinado_em->format('d/m/Y')
                . ($assinado->vigencia_meses ? ", prazo de {$assinado->vigencia_meses} meses" : '');
        } elseif ($dono?->data_contratacao) {
            $contrato[] = 'Início: ' . $dono->data_contratacao->format('d/m/Y');
        }
        $contrato[] = 'Término: ' . $termino->format('d/m/Y') . ($origem ? " ({$origem})" : '');
        if ((int) $ciclo->numero_ciclo > 1) {
            $contrato[] = 'Esta é a ' . (int) $ciclo->numero_ciclo . 'ª renovação deste contrato';
        }
        $linhas[] = '<p><b>Contrato atual</b></p>' . $this->lista($contrato);

        // Cliente
        $cliente = array_filter([
            filled($company->razao_social) ? 'Razão social: ' . $e($company->razao_social) : null,
            filled($company->cnpj) ? 'CNPJ: ' . $e($company->cnpj) : null,
            filled($company->nome_contato) ? 'Contato: ' . $e($company->nome_contato) : null,
            filled($company->telefone) ? 'Telefone: ' . $e($company->telefone) : null,
            filled($company->email_cliente) ? 'E-mail: ' . $e($company->email_cliente) : null,
        ]);
        if ($cliente !== []) {
            $linhas[] = '<p><b>Cliente</b></p>' . $this->lista($cliente);
        }

        // Quem atende hoje
        $equipe = array_filter([
            $this->responsavel($ciclo, 'estrategista', 'Estrategista'),
            $this->responsavel($ciclo, 'consultor', 'Analista'),
        ]);
        if ($equipe !== []) {
            $linhas[] = '<p><b>Quem atende o cliente hoje</b></p>' . $this->lista($equipe);
        }

        $linhas[] = '<p><b>O que fazer</b></p>' . $this->lista([
            'Conversar com o cliente sobre a renovação antes do término.',
            'Renovou: mover este negócio para <b>Ganho</b> — o ECF Admin estende o término do contrato sozinho.',
            'Não vai renovar: mover para <b>Perdido</b>.',
        ]);
        $linhas[] = '<p>Contrato no ECF Admin: <a href="' . e(route('admin.contratos.show', $company)) . '">'
            . e(route('admin.contratos.show', $company)) . '</a></p>';

        return implode('', $linhas);
    }

    // ─── apoio ───

    private function nomeServico(ContratoRenovacao $ciclo): string
    {
        return trim((string) ($ciclo->servico?->nome ?? 'serviço'));
    }

    private function diasRestantes(Carbon $termino): int
    {
        return (int) Carbon::today()->diffInDays($termino->copy()->startOfDay(), false);
    }

    /** Contratos ativos do grupo do ciclo: o serviço dono + os que assinam junto com ele. */
    private function contratosDoGrupo(ContratoRenovacao $ciclo)
    {
        return ContratoServico::query()
            ->with('servico')
            ->where('company_id', $ciclo->company_id)
            ->where('ativo', true)
            ->where(fn ($q) => $q->where('servico_id', $ciclo->servico_id)
                ->orWhereHas('servico', fn ($s) => $s->where('contrato_junto_com_servico_id', $ciclo->servico_id)))
            ->orderBy('id')
            ->get();
    }

    private function contratoAssinado(ContratoRenovacao $ciclo): ?ContratoClicksignAssinado
    {
        $tipo = str_contains(mb_strtolower($this->nomeServico($ciclo)), 'mentoria')
            ? ContratoClicksignAssinado::TIPO_MENTORIA
            : ContratoClicksignAssinado::TIPO_GESTAO;

        return ContratoClicksignAssinado::query()
            ->where('company_id', $ciclo->company_id)
            ->where('situacao', ContratoClicksignAssinado::SITUACAO_CASADO)
            ->where('tipo', $tipo)
            ->whereNotNull('assinado_em')
            ->orderByDesc('assinado_em')
            ->first();
    }

    private function responsavel(ContratoRenovacao $ciclo, string $role, string $rotulo): ?string
    {
        $nomes = $ciclo->company->responsavelDoServicoOuConsolidado($role, (int) $ciclo->servico_id)
            ->pluck('name')->filter()->unique()->values()->all();

        return $nomes === [] ? null : $rotulo . ': ' . e(implode(', ', $nomes));
    }

    private function lista(array $itens): string
    {
        return '<ul>' . implode('', array_map(static fn ($i) => "<li>{$i}</li>", $itens)) . '</ul>';
    }
}
