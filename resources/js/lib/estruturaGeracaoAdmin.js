// Formatação da tela admin "Tipos e pares do Planejamento" (sem React).

/** Opções do grupo "No Combit"; os rótulos acompanham os tipos escolhidos. */
export function opcoesDoCombit(primeiroNome, segundoNome) {
    const primeiro = (primeiroNome ?? '').trim() || 'o primeiro tipo';
    const segundo = (segundoNome ?? '').trim() || 'o segundo tipo';
    return [
        { valor: 'nao', rotulo: 'Não gerar Combit (só Kit)' },
        { valor: 'primeiro', rotulo: `Repetir ${primeiro}` },
        { valor: 'segundo', rotulo: `Repetir ${segundo}` },
        { valor: 'ambos', rotulo: 'Repetir os dois' },
    ];
}

// Artigo pela última letra do nome: a -> 'a', o -> 'o', outra -> sem artigo.
function comArtigo(nome) {
    const n = (nome ?? '').trim();
    const ultima = n.slice(-1).toLowerCase();
    if (ultima === 'a') return `a ${n}`;
    if (ultima === 'o') return `o ${n}`;
    return n;
}

/** Texto de apoio da linha do par. */
export function resumoDoPar(par) {
    switch (par?.combit) {
        case 'primeiro': return `Kit · Combit: ${comArtigo(par.primeiro?.nome)} se repete`;
        case 'segundo': return `Kit · Combit: ${comArtigo(par.segundo?.nome)} se repete`;
        case 'ambos': return 'Kit · Combit: os dois se repetem';
        default: return 'Só Kit';
    }
}

/** Palavras-chave visíveis (até `max`) e quantas ficaram de fora. */
export function chipsDePalavras(palavras, max = 6) {
    const lista = Array.isArray(palavras) ? palavras : [];
    return { visiveis: lista.slice(0, max), resto: Math.max(0, lista.length - max) };
}

/** 'nenhuma' para vazio ou 0; senão o próprio texto. */
export function textoQuantidades(t) {
    const s = (t ?? '').toString().trim();
    return s === '' || s === '0' ? 'nenhuma' : s;
}

export function linhaDeQuantidades(tipo) {
    return `Combo: ${textoQuantidades(tipo?.qtd_combo)} · Combit: ${textoQuantidades(tipo?.qtd_combit)}`;
}
