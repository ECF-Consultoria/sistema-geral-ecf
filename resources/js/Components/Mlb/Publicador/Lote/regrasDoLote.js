// ─── Publicação em lote (10/10/2026): regras puras da tela ──────────────────
//
// ESM puro (sem JSX) para o `node --test` importar sem o Vite. As chaves espelham o PHP:
// `PubFilaPublicacao` (ativa/pausada/concluida/cancelada), `PubFilaPublicacaoItem` (agendado…pulado),
// `ConferenciaService` (OK/AVISOS/BLOQUEADO/ERRO/LOCAL) e as origens de `ResumoRapidoService`.
// A etapa do "Corrigir" é a MESMA do editor (`etapaDoProblema`, apoio.js) — nunca uma tabela nova.

import { etapaDoProblema } from '../../../Publicador/apoio.js';

export const comoLista = (valor) => (Array.isArray(valor) ? valor : []);
export const comoObjeto = (valor) => ((valor !== null && typeof valor === 'object' && ! Array.isArray(valor)) ? valor : {});
export const textoSeguro = (valor, reserva = '—') => ((typeof valor === 'string' || typeof valor === 'number') ? String(valor) : reserva);
export const numeroSeguro = (valor) => (typeof valor === 'number' && Number.isFinite(valor) ? valor : null);

export const TIPOS = [
    { chave: 'gold_special', rotulo: 'Clássico' },
    { chave: 'gold_pro', rotulo: 'Premium' },
];

export const ROTULO_ORIGEM_TITULO = { digitado: 'digitado', portal: 'do Portal', ia: 'pela IA' };
export const ROTULO_ORIGEM_PRECO = { digitado: 'digitado', portal: 'do Portal', misto: 'Portal e digitado' };
export const ROTULO_ORIGEM_FRETE = { digitado: 'digitado no Portal', outro_tipo: 'do outro tipo', misto: 'variado' };
export const ROTULO_ORIGEM_CUSTO = { produto: 'do produto', digitado: 'digitado', componentes: 'dos componentes', misto: 'variado' };

export const ROTULO_STATUS_FILA = {
    ativa: 'Andando',
    pausada: 'Pausada',
    concluida: 'Concluída',
    cancelada: 'Cancelada',
};

export const ROTULO_STATUS_ITEM = {
    agendado: 'Agendado',
    publicando: 'Publicando',
    publicado: 'Publicado',
    parcial: 'Parte publicada',
    falhou: 'Não publicado',
    precisa_revisar: 'Precisa revisar',
    cancelado: 'Tirado da fila',
    pulado: 'Pulado',
};

export const FILTROS = [
    { chave: 'todos', rotulo: 'Todos' },
    { chave: 'prontos', rotulo: 'Prontos' },
    { chave: 'pendencias', rotulo: 'Com pendência' },
    { chave: 'sem_conferencia', rotulo: 'Sem conferência' },
    { chave: 'na_fila', rotulo: 'Na fila' },
];

const BRL = new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' });

/** R$ 1.234,56 — ou "—" quando não é número. */
export function fmtBRL(valor) {
    const n = numeroSeguro(valor);

    return n === null ? '—' : BRL.format(n).replace(/ /g, ' ');
}

/** `{min, max}` → "R$ 10,00" (iguais) ou "R$ 10,00 – R$ 12,00"; sem valor, "—". */
export function faixaBRL(faixa) {
    const f = comoObjeto(faixa);
    const min = numeroSeguro(f.min);
    const max = numeroSeguro(f.max);
    if (min === null && max === null) return '—';
    if (min === null || max === null || Math.abs(min - max) < 0.005) return fmtBRL(min ?? max);

    return `${fmtBRL(min)} – ${fmtBRL(max)}`;
}

/** Percentual com uma casa e vírgula: 12,5%. */
export function fmtPct(valor) {
    const n = numeroSeguro(valor);

    return n === null ? '—' : `${n.toFixed(1).replace('.', ',')}%`;
}

export function faixaPct(min, max) {
    const a = numeroSeguro(min);
    const b = numeroSeguro(max);
    if (a === null && b === null) return '—';
    if (a === null || b === null || Math.abs(a - b) < 0.05) return fmtPct(a ?? b);

    return `${fmtPct(a)} – ${fmtPct(b)}`;
}

/** Tom da margem: negativa = vermelho, abaixo de 10% = âmbar, senão verde. Sem margem, neutro. */
export function tomDaMargem(margem) {
    const m = comoObjeto(margem);
    const pct = numeroSeguro(m.pct_min);
    const valor = numeroSeguro(m.min);
    if (pct === null && valor === null) return 'neutro';
    if ((valor ?? 0) < 0 || (pct ?? 0) < 0) return 'negativa';
    if ((pct ?? 0) < 10) return 'baixa';

    return 'boa';
}

/** Instante ISO → "hh:mm" em São Paulo (com a data quando não é hoje). */
export function fmtHora(valor, agora = new Date()) {
    if (! valor) return '—';
    const d = new Date(String(valor));
    if (Number.isNaN(d.getTime())) return '—';
    const opcoes = { timeZone: 'America/Sao_Paulo', hour: '2-digit', minute: '2-digit' };
    const hora = d.toLocaleTimeString('pt-BR', opcoes);
    const dia = (x) => x.toLocaleDateString('pt-BR', { timeZone: 'America/Sao_Paulo' });

    return dia(d) === dia(agora) ? hora : `${d.toLocaleDateString('pt-BR', { timeZone: 'America/Sao_Paulo', day: '2-digit', month: '2-digit' })} ${hora}`;
}

/**
 * A situação da conferência da linha, para o selo: conferindo, sem, vencida, local, erro, bloqueado, avisos, ok.
 * `conferindo` vence tudo (o job ainda não terminou).
 */
export function situacaoDaConferencia(linha) {
    const l = comoObjeto(linha);
    const c = l.conferencia && typeof l.conferencia === 'object' ? l.conferencia : null;
    if (l.conferindo === true) return { chave: 'conferindo', rotulo: 'Conferindo…' };
    if (c === null) return { chave: 'sem', rotulo: 'Não conferido' };
    if (c.vale !== true) return { chave: 'vencida', rotulo: 'Conferência vencida' };
    if (c.resultado === 'BLOQUEADO') return { chave: 'bloqueado', rotulo: 'Com pendências' };
    if (c.resultado === 'ERRO') return { chave: 'erro', rotulo: 'Conferência não terminou' };
    // Conta fora da lista de liberadas: a conferência foi só local (nada foi ao Mercado Livre).
    if (c.local === true) return { chave: 'local', rotulo: 'Conferido só aqui' };
    if (c.resultado === 'AVISOS') return { chave: 'avisos', rotulo: 'Conferido com avisos' };
    if (c.resultado === 'OK') return { chave: 'ok', rotulo: 'Conferido' };

    return { chave: 'sem', rotulo: 'Não conferido' };
}

/** A linha tem algo a corrigir antes de publicar? (bloqueio visto antes de conferir, conferência barrou/não terminou/venceu, ou bloqueios locais) */
export function temPendencia(linha) {
    const l = comoObjeto(linha);
    const s = situacaoDaConferencia(l).chave;

    return bloqueiosDaLinha(l).length > 0 || ['bloqueado', 'erro', 'vencida'].includes(s) || (numeroSeguro(l.faltam) ?? 0) > 0;
}

// ─── Bloqueios vistos ANTES de conferir e a promoção automática (10/10/2026) ───
// Os bloqueios são os do `ValidadorRascunho::bloqueiosSemSchema` (V-TIT-04 títulos iguais, V-SAL-08 preço do
// Portal sem frete): o servidor manda prontos, a tela só nomeia. A promoção é a conta do `PrecoDaPromocao`.

export const ROTULO_BLOQUEIO = { 'V-TIT-04': 'Títulos iguais', 'V-SAL-08': 'Preço sem frete' };

/** Os bloqueios da linha (lista segura). */
export function bloqueiosDaLinha(linha) {
    return comoLista(comoObjeto(linha).bloqueios).filter((b) => b && typeof b === 'object');
}

/** Os selos dos bloqueios, um por regra, na ordem em que apareceram ("Títulos iguais", "Preço sem frete"). */
export function selosDosBloqueios(linha) {
    const vistos = [];
    for (const b of bloqueiosDaLinha(linha)) {
        const rotulo = ROTULO_BLOQUEIO[b.regra];
        if (rotulo && ! vistos.includes(rotulo)) vistos.push(rotulo);
    }

    return vistos;
}

/** "R$ 172,66 (−16,67%)" / faixa entre as cores; sem promoção, o motivo; sem Portal, "—". */
export function textoDaPromocaoDoTipo(promocao) {
    const p = comoObjeto(promocao);
    if (p.calculavel !== true) {
        const motivo = textoSeguro(p.motivo, '');

        return motivo === '' ? '—' : `sem promoção: ${motivo}`;
    }
    // Duas casas, como o editor (`promocaoAutomatica.pct`): "−16,67%".
    const pct2 = (n) => `${Number(n).toFixed(2).replace('.', ',')}%`;
    const a = numeroSeguro(p.pct_min);
    const b = numeroSeguro(p.pct_max);
    if (a === null) return faixaBRL(p);
    const faixa = b === null || Math.abs(a - b) < 0.005 ? `−${pct2(a)}` : `−${pct2(a)} a −${pct2(b)}`;

    return `${faixaBRL(p)} (${faixa})`;
}

/** A frase da linha sobre a promoção pós-publicação: automática por N dias, ou "não será criada" (conta fora das Alavancas). */
export function fraseDaPromocao(linha) {
    const l = comoObjeto(linha);
    const algumaPromocao = Object.values(comoObjeto(l.promocao)).some((p) => p && p.calculavel === true);
    if (! algumaPromocao) return null;
    const auto = comoObjeto(l.promocao_automatica);
    const dias = numeroSeguro(auto.dias) ?? 14;

    return auto.automatica === true
        ? `Promoção automática por ${dias} dias depois de publicar.`
        : 'Promoção não será criada: conta não liberada para as Alavancas (a tarefa pós-publicação orienta a criar no Seller Center).';
}

/** Para onde o "Corrigir" de uma pendência leva: o editor do produto, na etapa onde ela se resolve. */
export function urlCorrigir(linha, pendencia) {
    const base = textoSeguro(comoObjeto(linha).url_editor, '');
    if (base === '') return null;
    const etapa = etapaDoProblema(comoObjeto(pendencia));

    return `${base}${base.includes('?') ? '&' : '?'}etapa=${encodeURIComponent(etapa)}`;
}

/** As linhas do filtro escolhido e da busca (SKU ou nome). */
export function filtrarLinhas(linhas, filtro = 'todos', busca = '') {
    const termo = String(busca ?? '').trim().toLowerCase();

    return comoLista(linhas).filter((l) => {
        if (! l || typeof l !== 'object') return false;
        const s = situacaoDaConferencia(l).chave;
        if (filtro === 'prontos' && l.pronto !== true) return false;
        if (filtro === 'pendencias' && ! temPendencia(l)) return false;
        if (filtro === 'sem_conferencia' && s !== 'sem') return false;
        if (filtro === 'na_fila' && ! l.fila) return false;
        if (termo === '') return true;

        return `${textoSeguro(l.sku, '')} ${textoSeguro(l.nome, '')}`.toLowerCase().includes(termo);
    });
}

/** Quantas linhas cabem em cada filtro (o número ao lado do chip). */
export function contagensDoLote(linhas) {
    const saida = {};
    for (const f of FILTROS) {
        saida[f.chave] = filtrarLinhas(linhas, f.chave).length;
    }

    return saida;
}

/** Os ids selecionados que a fila aceitaria agora (os prontos) e quantos deles têm avisos do ML. */
export function prontosDaSelecao(linhas, selecao) {
    const ids = selecao instanceof Set ? selecao : new Set(comoLista(selecao));
    const prontos = comoLista(linhas).filter((l) => l && l.pronto === true && ids.has(l.produto_id));

    return {
        ids: prontos.map((l) => l.produto_id),
        anuncios: prontos.reduce((t, l) => t + (numeroSeguro(l.anuncios) ?? 0), 0),
        comAvisos: prontos.filter((l) => l.avisos_ml === true).length,
    };
}

/** Os ids selecionados que podem ir para "Conferir selecionados". */
export function conferiveisDaSelecao(linhas, selecao) {
    const ids = selecao instanceof Set ? selecao : new Set(comoLista(selecao));

    return comoLista(linhas).filter((l) => l && l.pode_conferir === true && ids.has(l.produto_id)).map((l) => l.produto_id);
}

/** Um inteiro ≥ 1 a partir do valor (ou a reserva). */
const inteiroPositivo = (valor, reserva) => Math.max(1, Math.trunc(numeroSeguro(valor) ?? reserva));

/** Quantas rodadas `n` produtos levam, `porRodada` de cada vez. */
export function rodadasDoLote(n, porRodada = 1) {
    const qtd = Math.max(0, Math.trunc(numeroSeguro(n) ?? 0));

    return qtd === 0 ? 0 : Math.ceil(qtd / inteiroPositivo(porRodada, 1));
}

/**
 * "Termina por volta de" (o mesmo passo do agendador, 10/10/2026): `n` produtos em rodadas de `porRodada`, uma
 * rodada a cada `intervalo` minutos; dentro da rodada começam no máximo `teto` por minuto; o último leva ~2 min.
 * Sem janela (a do servidor é a que vale; esta é só a estimativa do diálogo).
 */
export function previsaoDoLote(n, intervaloMinutos, inicio = new Date(), porRodada = 1, teto = 2) {
    const qtd = Math.max(0, Math.trunc(numeroSeguro(n) ?? 0));
    if (qtd === 0) return null;
    const intervalo = inteiroPositivo(intervaloMinutos, 10);
    const tamanho = inteiroPositivo(porRodada, 1);
    const rodadas = rodadasDoLote(qtd, tamanho);
    const naUltima = qtd - (rodadas - 1) * tamanho;
    const minutos = (rodadas - 1) * intervalo + Math.floor((naUltima - 1) / inteiroPositivo(teto, 2)) + 2;

    return new Date(inicio.getTime() + minutos * 60_000).toISOString();
}

/** O ritmo da fila em uma frase: "Um produto (…) a cada 10 minutos" ou "5 produtos por rodada (…), uma rodada a cada 20 minutos". */
export function fraseDoRitmo(porRodada, intervaloMinutos) {
    const tamanho = inteiroPositivo(porRodada, 1);
    const intervalo = inteiroPositivo(intervaloMinutos, 10);

    return tamanho === 1
        ? `Um produto (Clássico e Premium, todas as cores) a cada ${intervalo} minutos`
        : `${tamanho} produtos por rodada (Clássico e Premium, todas as cores), uma rodada a cada ${intervalo} minutos`;
}

/** Há algo andando que justifique o polling de 10 s? */
export function precisaAcompanhar(linhas, fila) {
    const f = comoObjeto(fila);

    return comoLista(linhas).some((l) => l && l.conferindo === true) || f.viva === true;
}

/** Os ids que vieram de `?produtos=` e existem na lista (o resto é ignorado). */
export function selecaoInicial(linhas, pedidos) {
    const existentes = new Set(comoLista(linhas).map((l) => l?.produto_id));

    return new Set(comoLista(pedidos).filter((id) => existentes.has(id)));
}
