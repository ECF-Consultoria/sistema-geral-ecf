<?php

namespace App\Support\Agenda;

/**
 * Leituras de um item de evento da API do Google Agenda que mais de um serviço
 * precisa fazer do mesmo jeito (16/09/2026).
 *
 * Sem estado e sem chamada de rede: recebe o array que a API devolveu e diz o
 * que ele significa para a tela.
 */
final class EventoGoogle
{
    /**
     * Por onde se entra na reunião.
     *
     * Ordem de confiança: a conferência que o próprio Google registrou (Meet,
     * ou complemento de Teams/Zoom) vem antes do que alguém escreveu no local
     * ou na descrição.
     *
     * @param  array<string, mixed>  $item
     * @return array{plataforma: ?string, link: ?string, local: ?string}
     */
    public static function plataforma(array $item): array
    {
        $local = trim((string) ($item['location'] ?? '')) ?: null;
        $solucao = mb_strtolower((string) ($item['conferenceData']['conferenceSolution']['name'] ?? ''));
        $tipoSolucao = (string) ($item['conferenceData']['conferenceSolution']['key']['type'] ?? '');

        $video = null;
        foreach ($item['conferenceData']['entryPoints'] ?? [] as $entrada) {
            if (($entrada['entryPointType'] ?? '') === 'video' && ! empty($entrada['uri'])) {
                $video = (string) $entrada['uri'];
                break;
            }
        }

        if (! empty($item['hangoutLink']) || $tipoSolucao === 'hangoutsMeet') {
            return ['plataforma' => 'google_meet', 'link' => $item['hangoutLink'] ?? $video, 'local' => $local];
        }

        if ($video) {
            return ['plataforma' => self::marcaDoLink($video, $solucao), 'link' => $video, 'local' => $local];
        }

        $textos = $local.' '.self::texto($item['description'] ?? null);

        foreach (self::links($textos) as $link) {
            $marca = self::marcaDoLink($link, '');

            if ($marca !== 'link') {
                return ['plataforma' => $marca, 'link' => $link, 'local' => $local];
            }
        }

        if ($local !== null && preg_match('~^https?://~i', $local)) {
            return ['plataforma' => 'link', 'link' => $local, 'local' => null];
        }

        if ($local !== null) {
            return ['plataforma' => 'presencial', 'link' => null, 'local' => $local];
        }

        return ['plataforma' => null, 'link' => null, 'local' => null];
    }

    /**
     * A descrição do Google em texto: ela chega em HTML quando foi escrita no
     * próprio Google Agenda.
     */
    public static function texto(?string $descricao): ?string
    {
        if ($descricao === null || trim($descricao) === '') {
            return null;
        }

        $texto = preg_replace('~<\s*br\s*/?\s*>~i', "\n", $descricao);
        $texto = preg_replace('~</\s*(p|div|li)\s*>~i', "\n", (string) $texto);
        $texto = html_entity_decode(strip_tags((string) $texto), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $texto = preg_replace("~\n{3,}~", "\n\n", $texto);

        return trim((string) $texto) ?: null;
    }

    /**
     * Mantém o objeto do Google de quem continua convidado (com a resposta que
     * já deu) e de quem organiza; acrescenta os novos; tira quem saiu.
     *
     * Mandar um convidado antigo sem `responseStatus` apagaria o "aceito" dele.
     *
     * @param  array<int, array<string, mixed>>  $atuais
     * @param  array<int, array{email: string, nome?: ?string}>  $participantes
     * @return array<int, array<string, mixed>>
     */
    public static function mesclarConvidados(array $atuais, array $participantes): array
    {
        $porEmail = [];
        $lista = [];

        foreach ($atuais as $atual) {
            $email = mb_strtolower((string) ($atual['email'] ?? ''));
            $porEmail[$email] = $atual;

            if (($atual['organizer'] ?? false) || ($atual['self'] ?? false)) {
                $lista[$email] = $atual;
            }
        }

        foreach ($participantes as $pessoa) {
            $email = mb_strtolower((string) $pessoa['email']);
            $lista[$email] = $porEmail[$email]
                ?? array_filter(['email' => $email, 'displayName' => $pessoa['nome'] ?? null]);
        }

        return array_values($lista);
    }

    /** @return array<int, string> */
    private static function links(string $texto): array
    {
        preg_match_all('~https?://[^\s<>"\')]+~i', $texto, $achados);

        return $achados[0] ?? [];
    }

    private static function marcaDoLink(string $link, string $solucao): string
    {
        $alvo = mb_strtolower($link.' '.$solucao);

        return match (true) {
            str_contains($alvo, 'teams.microsoft') || str_contains($alvo, 'teams') => 'teams',
            str_contains($alvo, 'zoom.us') || str_contains($alvo, 'zoom')          => 'zoom',
            str_contains($alvo, 'meet.google')                                     => 'google_meet',
            default                                                                => 'link',
        };
    }
}
