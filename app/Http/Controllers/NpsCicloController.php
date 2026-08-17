<?php

namespace App\Http\Controllers;

use App\Models\NpsCiclo;
use App\Services\Nps\NpsJanelaResolver;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Fechamento MANUAL do ciclo de NPS — spec 2026-08-14, item 2.
 *
 * Até aqui o encerramento era só uma conta de datas: o ciclo virava "fechado"
 * sozinho no último dia do mês de coleta, e não havia como encerrar antes nem
 * registro de quem encerrou. Agora um usuário autorizado fecha quando quiser, e
 * o fechamento manual PREVALECE sobre a data (ver `NpsJanelaResolver::fechada()`).
 *
 * Efeito do fechamento, tudo aplicado NO SERVIDOR:
 *  - `NpsController::generate` recusa novo link do ciclo;
 *  - `NpsController::submitResponse` recusa resposta, mesmo de link ainda
 *    dentro da validade — o link já está na mão do cliente, então a tela
 *    pública não é caminho confiável para barrar nada;
 *  - a nota 1 do não respondido passa a valer imediatamente nos cards do
 *    `/nps` (a régua é a mesma: `fechada()`).
 *
 * Admin-only pelo middleware `role:admin` na rota.
 *
 * @see database/migrations/2026_08_14_170000_create_nps_ciclos_table.php (decisão de schema)
 * @see app/Services/Nps/NpsJanelaResolver.php
 */
class NpsCicloController extends Controller
{
    public function __construct(private NpsJanelaResolver $janela)
    {
    }

    /**
     * Encerra o ciclo de um mês de COLETA (`?mes=YYYY-MM`).
     *
     * Idempotente: fechar duas vezes não duplica nem sobrescreve a trilha —
     * quem fechou primeiro continua sendo o responsável registrado.
     */
    public function fechar(Request $request)
    {
        $data = $request->validate([
            'mes' => ['required', 'string', 'regex:/^\d{4}-\d{2}$/'],
        ], [
            'mes.regex' => 'Informe o mês no formato AAAA-MM.',
        ]);

        $mesColeta = Carbon::parse($data['mes'] . '-01')->startOfMonth();

        // Fechar mês FUTURO não faz sentido: encerraria uma coleta que ainda
        // nem começou, e o efeito prático seria bloquear disparos de um mês que
        // ainda vai acontecer. Passado e mês corrente são livres.
        if ($mesColeta->greaterThan(now()->startOfMonth())) {
            return back()->with('error', 'Não é possível encerrar um mês que ainda não começou.');
        }

        $ciclo = NpsCiclo::firstOrCreate(
            ['mes_coleta' => NpsCiclo::chaveDoMes($mesColeta)],
            ['fechado_em' => now(), 'fechado_por' => $request->user()->id],
        );

        // O cache do resolver é por request — sem isto, uma leitura feita logo
        // após o fechamento (no MESMO request) ainda veria o ciclo aberto.
        $this->janela->esquecerCache();

        $competencia = $mesColeta->copy()->subMonthNoOverflow()->startOfMonth();

        if (! $ciclo->wasRecentlyCreated) {
            return back()->with('success', 'Este ciclo já estava encerrado.');
        }

        Log::info('[NPS Ciclo] ciclo encerrado manualmente', [
            'mes_coleta'  => $mesColeta->format('Y-m'),
            'competencia' => $competencia->format('Y-m'),
            'user_id'     => $request->user()->id,
        ]);

        return back()->with('success', sprintf(
            'Ciclo de %s encerrado (referente a %s). Novos links e novas respostas estão bloqueados.',
            $mesColeta->locale('pt_BR')->isoFormat('MMMM/YYYY'),
            $competencia->locale('pt_BR')->isoFormat('MMMM/YYYY'),
        ));
    }

    /**
     * Reabre um ciclo encerrado por engano.
     *
     * Existe porque o fechamento é irreversível pela via normal e um clique
     * errado deixaria o mês travado sem saída pela tela. A remoção é da LINHA
     * inteira: se a data automática já tiver passado, o mês volta a ser
     * considerado fechado pela régua de data — reabrir não ressuscita um mês
     * vencido, só desfaz a antecipação.
     */
    public function reabrir(Request $request)
    {
        $data = $request->validate([
            'mes' => ['required', 'string', 'regex:/^\d{4}-\d{2}$/'],
        ]);

        $mesColeta = Carbon::parse($data['mes'] . '-01')->startOfMonth();

        // `whereDate` e não igualdade crua: com o cast `date`, o SQLite dos
        // testes persiste "YYYY-MM-01 00:00:00" e um WHERE bare-date nunca
        // casa (cicatriz conhecida do projeto — mesmo motivo de
        // `NpsPendingService::isPendente()` usar `whereDate`). Em MariaDB a
        // coluna DATE trunca a hora e os dois casariam; o teste é que pega.
        $apagados = NpsCiclo::whereDate('mes_coleta', $mesColeta->toDateString())->delete();
        $this->janela->esquecerCache();

        if ($apagados === 0) {
            return back()->with('error', 'Este ciclo não estava encerrado manualmente.');
        }

        Log::info('[NPS Ciclo] fechamento manual revertido', [
            'mes_coleta' => $mesColeta->format('Y-m'),
            'user_id'    => $request->user()->id,
        ]);

        $aviso = $this->janela->fechada($mesColeta)
            ? ' O mês segue fechado pela data de encerramento automático.'
            : '';

        return back()->with('success', 'Fechamento manual revertido.' . $aviso);
    }
}
