// Tempo relativo curto em pt-BR para os selos do Publicador.
// "agora" < 1 min; "há N min" < 60 min; "há N h" < 48 h; senão "há N d".
// `agora` é injetável para o teste usar data fixa.
export const haQuanto = (iso, agora = Date.now()) => {
    if (!iso) return null;
    const t = new Date(iso).getTime();
    if (Number.isNaN(t)) return null;

    const minutos = Math.floor(Math.max(0, agora - t) / 60000);
    if (minutos < 1) return 'agora';
    if (minutos < 60) return `há ${minutos} min`;

    const horas = Math.floor(minutos / 60);
    if (horas < 48) return `há ${horas} h`;

    return `há ${Math.floor(horas / 24)} d`;
};
