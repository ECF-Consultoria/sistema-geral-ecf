<?php

namespace App\Http\Controllers;

use App\Models\GoogleToken;
use App\Services\GoogleCalendarService;
use Illuminate\Http\Request;

class GoogleCalendarController extends Controller
{
    public function __construct(private GoogleCalendarService $calendar) {}

    // ── Redireciona para OAuth Google ─────────────────────────────────────────

    public function connect()
    {
        return redirect($this->calendar->getAuthUrl());
    }

    // ── Callback OAuth — salva tokens ─────────────────────────────────────────

    public function callback(Request $request)
    {
        if ($request->get('error')) {
            return redirect()->route('profile.edit')->with('error', 'Conexão com Google negada.');
        }

        $code = $request->get('code');
        if (!$code) {
            return redirect()->route('profile.edit')->with('error', 'Código OAuth ausente.');
        }

        try {
            $tokens = $this->calendar->exchangeCode($code);
        } catch (\Throwable $e) {
            return redirect()->route('profile.edit')->with('error', 'Erro ao conectar Google: ' . $e->getMessage());
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

        return redirect()->route('profile.edit')->with('success', 'Google Calendar conectado com sucesso!');
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
