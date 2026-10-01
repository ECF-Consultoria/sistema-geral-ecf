<?php

namespace App\Http\Controllers;

use App\Models\GoogleToken;
use App\Services\GoogleCalendarService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class GoogleCalendarController extends Controller
{
    public function __construct(private GoogleCalendarService $calendar) {}

    // ── Redireciona para OAuth Google ─────────────────────────────────────────

    /**
     * `retorno` existe porque conectar deixou de ser assunto só do Perfil
     * (16/09/2026): quem marca reunião conecta de dentro da ficha do onboarding,
     * e voltar do Google no Perfil abandonaria a pessoa longe do que ela estava
     * fazendo. Guarda-se na SESSÃO, e não no `state` do OAuth, porque o callback
     * do Google não devolve query nossa.
     *
     * Só caminho interno entra: `/rota` sim, `//site.com` e `https://…` não —
     * senão a tela de consentimento do Google viraria trampolim para fora.
     */
    public function connect(Request $request)
    {
        $retorno = (string) $request->query('retorno', '');

        if ($retorno !== '' && str_starts_with($retorno, '/') && ! str_starts_with($retorno, '//')) {
            $request->session()->put('google_retorno', mb_substr($retorno, 0, 300));
        } else {
            $request->session()->forget('google_retorno');
        }

        // A volta do Google só vale para QUEM começou (23/09/2026). O `state`
        // vai e volta pelo Google; o dono dele fica na sessão.
        $state = Str::random(40);
        $request->session()->put('google_oauth', [
            'state'   => $state,
            'user_id' => $request->user()->id,
        ]);

        return redirect($this->calendar->getAuthUrl($state, $request->user()->email));
    }

    // ── Callback OAuth — salva tokens ─────────────────────────────────────────

    public function callback(Request $request)
    {
        // `pull` e não `get`: o destino vale para esta volta. Deixá-lo na sessão
        // faria a próxima conexão, vinda do Perfil, cair no onboarding de ontem.
        $destino = $request->session()->pull('google_retorno');

        $volta = fn (string $tipo, string $mensagem) => ($destino
            ? redirect($destino)
            : redirect()->route('profile.edit'))->with($tipo, $mensagem);

        // `pull`: um `state` vale para uma volta só.
        $pedido = $request->session()->pull('google_oauth');
        $user = $request->user();

        // Sem `state` casando com o que ESTA sessão pediu, a volta é de outra
        // pessoa — ou de alguém que trocou de usuário no meio do caminho, ou
        // de um link forjado com o código de outra conta. Antes o token ia
        // para quem estivesse logado na volta, e a agenda do sistema passava a
        // ler e escrever no Google de outra pessoa.
        $stateValido = is_array($pedido)
            && is_string($request->get('state'))
            && hash_equals((string) ($pedido['state'] ?? ''), $request->get('state'))
            && (int) ($pedido['user_id'] ?? 0) === $user->id;

        if (! $stateValido) {
            Log::warning('[GoogleCalendar] volta do OAuth recusada: state não confere', [
                'user_id'       => $user->id,
                'pedido_por'    => is_array($pedido) ? ($pedido['user_id'] ?? null) : null,
            ]);

            return $volta('error', 'A conexão com o Google foi iniciada em outra sessão. Clique em conectar de novo.');
        }

        if ($request->get('error')) {
            return $volta('error', 'Conexão com Google negada.');
        }

        $code = $request->get('code');
        if (!$code) {
            return $volta('error', 'Código OAuth ausente.');
        }

        try {
            $tokens = $this->calendar->exchangeCode($code);
        } catch (\Throwable $e) {
            return $volta('error', 'Erro ao conectar Google: ' . $e->getMessage());
        }

        $anterior = GoogleToken::where('user_id', $user->id)->first();

        GoogleToken::updateOrCreate(
            ['user_id' => $user->id],
            [
                'access_token'  => $tokens['access_token'],
                // O Google nem sempre devolve refresh token numa reconexão;
                // gravar `null` por cima do que existia matava a conexão na
                // primeira hora, quando o access token vencesse.
                'refresh_token' => $tokens['refresh_token'] ?? $anterior?->refresh_token,
                'expires_at'    => now()->addSeconds(($tokens['expires_in'] ?? 3600) - 60),
            ]
        );

        // Conta Google diferente do e-mail do sistema não é proibido — há quem
        // use outra conta para a agenda —, mas é o sintoma do navegador logado
        // no Google de outra pessoa. Aparece na hora, com o endereço à vista.
        $conta = $this->calendar->emailDaConta($tokens['access_token']);

        //
        // Vai pelo canal `error` de propósito: é o único, junto de `success`,
        // que o toast global do AppLayout desenha em qualquer tela de volta
        // (Perfil ou ficha do onboarding) — chave de flash nova morreria calada.
        if ($conta !== null && $conta !== mb_strtolower((string) $user->email)) {
            return $volta('error', "Atenção: o Google Agenda foi conectado com a conta {$conta}, que não é o seu e-mail do sistema. "
                .'Se não era essa a agenda, desconecte e conecte de novo escolhendo a conta certa.');
        }

        return $volta('success', 'Google Calendar conectado com sucesso!');
    }

    // ── Sincronização manual ──────────────────────────────────────────────────

    public function sync(Request $request)
    {
        $user  = $request->user();
        $token = GoogleToken::where('user_id', $user->id)->first();

        if (!$token) {
            return back()->with('error', 'Google Calendar não conectado. Conecte primeiro no perfil.');
        }

        try {
            $events = $this->calendar->fetchEvents($token, daysBack: 30);
            $stats  = $this->calendar->syncToMeetings($user, $events);
        } catch (\Throwable $e) {
            return back()->with('error', 'Erro ao sincronizar: ' . $e->getMessage());
        }

        $msg = "Sincronização concluída: {$stats['created']} criadas, {$stats['updated']} atualizadas, {$stats['skipped']} ignoradas.";

        return redirect()->route('meetings.index')->with('success', $msg);
    }

    // ── Desconectar ───────────────────────────────────────────────────────────

    public function disconnect(Request $request)
    {
        GoogleToken::where('user_id', $request->user()->id)->delete();
        return back()->with('success', 'Google Calendar desconectado.');
    }
}
