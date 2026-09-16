<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\CompanyGroup;
use App\Support\Permissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Quick 260916-onn — marcar e desmarcar "não participa do fechamento", em
 * empresa e em grupo.
 *
 * O fechamento existe para descobrir em que faixa da tabela progressiva cada
 * cliente caiu no mês. Quem não tem contrato progressivo é tirado CASO A CASO
 * (decisão do usuário em 2026-09-16) — é isto que grava essa decisão. A regra
 * que a lê mora num lugar só: `FechamentoEmpresasDoMes::separar()`.
 *
 * - Marcar exige motivo (mínimo 10 caracteres); desmarcar não exige, mas fica
 *   na trilha.
 * - `_por` vem SEMPRE da sessão — qualquer id no corpo da requisição é
 *   ignorado (o `validate()` nem o aceita).
 * - Marcar NÃO apaga tabela de cobrança nenhuma: o valor gravado (ex.: os
 *   R$ 4.000 do grupo Wenus) continua lá.
 * - ⛔ `fora_do_fechamento` não é campo-gatilho de contrato
 *   (`CompanyGatilhoContratoObserver::CAMPOS_GATILHO`) — marcar não gera nada.
 *
 * Permissão: `admin.contratos`, a mesma das rotas vizinhas do módulo
 * administrativo de contratos (middleware do grupo). O `abort_unless` abaixo
 * repete a checagem de propósito: a ficha da empresa também é aberta por quem
 * só tem `comercial.entrada`, e essa pessoa não pode marcar.
 */
class ForaDoFechamentoController extends Controller
{
    /** `log_name` da trilha dos grupos (o model não usa `LogsActivity`). */
    public const LOG_NAME = 'fora_do_fechamento';

    public function marcarEmpresa(Request $request, Company $company): RedirectResponse
    {
        $this->autorizar($request);
        $motivo = $this->validarMotivo($request);

        $company->update($this->camposMarcados($request, $motivo));

        return back()->with('success', "\"{$company->name}\" não participa mais do fechamento.");
    }

    public function desmarcarEmpresa(Request $request, Company $company): RedirectResponse
    {
        $this->autorizar($request);

        // A trilha sai pelo `LogsActivity` do model (`fora_do_fechamento` e o
        // motivo estão no `logOnly`), com o antes e o depois.
        $company->update($this->camposDesmarcados());

        return back()->with('success', "\"{$company->name}\" volta a participar do fechamento.");
    }

    public function marcarGrupo(Request $request, CompanyGroup $grupo): RedirectResponse
    {
        $this->autorizar($request);
        $motivo = $this->validarMotivo($request);

        $grupo->update($this->camposMarcados($request, $motivo));

        activity(self::LOG_NAME)
            ->causedBy($request->user())
            ->performedOn($grupo)
            ->withProperties(['fora_do_fechamento' => true, 'motivo' => $motivo])
            ->log("Grupo \"{$grupo->name}\" marcado como não participante do fechamento.");

        return back()->with('success', "O grupo \"{$grupo->name}\" não participa mais do fechamento.");
    }

    public function desmarcarGrupo(Request $request, CompanyGroup $grupo): RedirectResponse
    {
        $this->autorizar($request);

        $motivoAnterior = $grupo->fora_do_fechamento_motivo;

        $grupo->update($this->camposDesmarcados());

        activity(self::LOG_NAME)
            ->causedBy($request->user())
            ->performedOn($grupo)
            ->withProperties(['fora_do_fechamento' => false, 'motivo_anterior' => $motivoAnterior])
            ->log("Grupo \"{$grupo->name}\" voltou a participar do fechamento.");

        return back()->with('success', "O grupo \"{$grupo->name}\" volta a participar do fechamento.");
    }

    private function autorizar(Request $request): void
    {
        abort_unless(
            $request->user()?->hasPermission(Permissions::ADMIN_CONTRATOS),
            403,
            'Você não tem permissão para mudar quem participa do fechamento.'
        );
    }

    /**
     * Só o motivo é aceito do corpo — nada de usuário nem data.
     */
    private function validarMotivo(Request $request): string
    {
        $dados = $request->validate([
            'motivo' => ['required', 'string', 'min:10', 'max:1000'],
        ], [
            'motivo.required' => 'Diga por que não participa do fechamento.',
            'motivo.min'      => 'Escreva um motivo com pelo menos 10 caracteres.',
            'motivo.max'      => 'O motivo pode ter no máximo 1000 caracteres.',
        ]);

        return trim($dados['motivo']);
    }

    private function camposMarcados(Request $request, string $motivo): array
    {
        return [
            'fora_do_fechamento'        => true,
            'fora_do_fechamento_motivo' => $motivo,
            'fora_do_fechamento_por'    => $request->user()->id,
            'fora_do_fechamento_em'     => Carbon::now(),
        ];
    }

    private function camposDesmarcados(): array
    {
        return [
            'fora_do_fechamento'        => false,
            'fora_do_fechamento_motivo' => null,
            'fora_do_fechamento_por'    => null,
            'fora_do_fechamento_em'     => null,
        ];
    }
}
