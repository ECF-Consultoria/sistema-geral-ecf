/**
 * "há 2 minutos", "ontem", "há 3 dias" — o tempo como a pessoa fala.
 *
 * Escrito à mão em vez de `date-fns/formatDistanceToNow`: aquele precisa do
 * locale pt-BR importado junto, e devolve "cerca de 1 hora" / "menos de um
 * minuto", que é mais comprido do que cabe numa linha de feed.
 *
 * Devolve `null` para data ausente — quem chama decide o que mostrar no lugar,
 * porque "—" e "nunca" não querem dizer a mesma coisa em toda tela.
 */
export function tempoRelativo(iso) {
    if (!iso) return null;

    const ms = Date.now() - new Date(iso).getTime();

    // Data no futuro é legítima aqui: reunião agendada para amanhã aparece no
    // mesmo feed. Dizer "há -3 dias" seria pior do que dizer "em breve".
    if (ms < 0) return 'em breve';

    const min = Math.floor(ms / 60000);
    if (min < 1) return 'agora';
    if (min < 60) return `há ${min} min`;

    const horas = Math.floor(min / 60);
    if (horas < 24) return `há ${horas}h`;

    const dias = Math.floor(horas / 24);
    if (dias === 1) return 'ontem';
    if (dias < 30) return `há ${dias} dias`;

    const meses = Math.floor(dias / 30);
    if (meses < 12) return `há ${meses} ${meses === 1 ? 'mês' : 'meses'}`;

    const anos = Math.floor(meses / 12);
    return `há ${anos} ano${anos === 1 ? '' : 's'}`;
}
