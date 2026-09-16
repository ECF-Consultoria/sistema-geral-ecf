<?php

namespace App\Http\Controllers;

use App\Models\GoogleToken;
use App\Services\GoogleCalendarService;
use Illuminate\Http\Request;

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

        return redirect($this->calendar->getAuthUrl());
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

        $user = $request->user();

        GoogleToken::updateOrCreate(
            ['user_id' => $user->id],
            [
                'access_token'  => $tokens['access_token'],
                'refresh_token' => $tokens['refresh_token'] ?? null,
                'expires_at'    => now()->addSeconds(($tokens['expires_in'] ?? 3600) - 60),
            ]
        );

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
