import { cn } from '@/lib/utils';

/**
 * Cartão de KPI da faixa do topo do Dashboard do Publicador (quick 261009-t02,
 * tela 02 do pacote do Stitch).
 *
 * Rótulo em maiúsculas pequenas, número grande, um par de sub-números abaixo,
 * ícone à direita e variante de destaque (o "Prioritário" âmbar e o "Pendentes"
 * vermelho do mockup).
 *
 * ⚠️ **Estado vazio honesto é requisito, não detalhe.** Com `numero` nulo o
 * cartão escreve "—" e DIZ O MOTIVO ("Ainda não medimos", "Sem dado do acervo
 * ainda"), nunca 0. É a distinção entre "não sabemos" e "é zero", que neste
 * projeto já custou caro (o "dia sem linha ≠ venda zero" dos learnings).
 *
 * ⚠️ A tela preta de 07/10 nasceu de um campo que chegou como OBJETO e foi
 * renderizado cru ("Objects are not valid as a React child"). `String(x ?? '—')`
 * NÃO cobre esse caso — vira "[object Object]". Daí `textoSeguro`/`numeroSeguro`
 * em TODO campo que vem do servidor.
 *
 * ⚠️ A ordem do JSX é contrato: o `<p>` do rótulo é seguido IMEDIATAMENTE pelo
 * `<p>` do número (o ícone e o selo de destaque são `<span>`, nunca `<p>`).
 * Há gate de render que recorta do rótulo até o próximo `</p>` para provar que
 * o cartão mostra "—" e não "0" quando não há dado.
 */

/** Só string/number do servidor; qualquer outra forma (objeto, array…) cai no fallback. */
export function textoSeguro(valor, fallback = '—') {
    return (typeof valor === 'string' || typeof valor === 'number') ? String(valor) : fallback;
}

/** Número de verdade ou `null` — `NaN`, string e objeto não viram contagem. */
export function numeroSeguro(valor) {
    return (typeof valor === 'number' && Number.isFinite(valor)) ? valor : null;
}

/** As duas variantes de destaque do mockup. Fora dessas, o cartão é neutro. */
const DESTAQUES = {
    atencao: 'border-amber-400/40 bg-amber-400/10 text-amber-300',
    critico: 'border-red-400/40 bg-red-400/10 text-red-300',
};

export default function CartaoKpi({
    rotulo,
    numero = null,
    motivoVazio = 'Ainda não medimos',
    subs = [],
    icone = null,
    destaque = null,
    destaqueTexto = null,
    nota = null,
    barraPct = null,
    onClick = null,
    botaoTexto = null,
    onBotao = null,
}) {
    const rotuloSeguro = textoSeguro(rotulo, 'Indicador');
    const valor = numeroSeguro(numero);
    const temValor = valor !== null;
    const classeDestaque = typeof destaque === 'string' ? (DESTAQUES[destaque] ?? null) : null;
    const seloTexto = textoSeguro(destaqueTexto, '');
    const mostraSelo = classeDestaque !== null && seloTexto !== '' && temValor;
    const notaTexto = textoSeguro(nota, '');
    const pct = numeroSeguro(barraPct);
    const linhasSub = Array.isArray(subs) ? subs : [];

    const corpo = (
        <>
            <div className="flex items-start justify-between gap-2">
                <p className="text-[11px] font-bold uppercase tracking-[0.05em] text-white/40">{rotuloSeguro}</p>
                {icone ? <span aria-hidden="true" className="shrink-0 text-white/30">{icone}</span> : null}
            </div>

            <p className="mt-1 font-display text-[24px] font-bold tabular-nums text-white">
                {temValor ? valor : '—'}
                {mostraSelo && (
                    <span className={cn('ml-2 inline-flex items-center rounded-md border px-2 py-0.5 align-middle text-[11px] font-bold', classeDestaque)}>
                        {seloTexto}
                    </span>
                )}
            </p>

            {/* Sem número, o motivo ocupa o lugar da nota — nunca um zero disfarçado. */}
            {!temValor && <p className="text-[13px] font-normal text-white/55">{textoSeguro(motivoVazio, 'Ainda não medimos')}</p>}
            {temValor && notaTexto !== '' && <p className="text-[13px] font-normal text-white/55">{notaTexto}</p>}

            {pct !== null && (
                <div className="mt-2 h-1 w-full rounded-full bg-white/10">
                    <div
                        className="h-1 rounded-full bg-ecf-yellow/50"
                        style={{ width: `${Math.max(0, Math.min(100, pct))}%` }}
                    />
                </div>
            )}

            {linhasSub.length > 0 && (
                <div className="mt-2 flex flex-wrap items-baseline gap-x-3 gap-y-1">
                    {linhasSub.map((bruto, indice) => {
                        // ⚠️ Flags calculadas DENTRO do callback: variável de escopo do
                        // componente lida só dentro de um `.map()` já foi eliminada pelo
                        // Rollup no bundle de produção deste projeto
                        // (feedback_rollup_map_scope_bug.md) — ReferenceError em produção.
                        const sub = bruto && typeof bruto === 'object' && !Array.isArray(bruto) ? bruto : {};
                        const subRotulo = textoSeguro(sub.rotulo, '');
                        const subValorNumero = numeroSeguro(sub.valor);
                        const subValor = subValorNumero !== null ? String(subValorNumero) : textoSeguro(sub.valor, '—');

                        if (subRotulo === '') return null;

                        return (
                            <span key={indice} className="text-[11px] font-normal text-white/55">
                                <span className="font-mono tabular-nums text-white/80">{subValor}</span>
                                {' '}
                                {subRotulo}
                            </span>
                        );
                    })}
                </div>
            )}
        </>
    );

    // Cartão com botão de ação (ex.: "Atualizar agora") NUNCA é ele mesmo um
    // <button> — evita <button> dentro de <button>. Mesma regra do
    // `CartaoIndicador` que este cartão substitui.
    if (botaoTexto) {
        return (
            <div className="rounded-xl border border-white/[0.08] bg-ecf-card p-4 text-left">
                {corpo}
                <button
                    type="button"
                    onClick={() => onBotao?.()}
                    className="mt-2 inline-flex h-8 items-center rounded-lg border border-white/[0.10] bg-white/[0.03] px-3 text-[11px] font-bold text-white/80 hover:bg-white/[0.06] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ecf-yellow"
                >
                    {textoSeguro(botaoTexto, 'Atualizar')}
                </button>
            </div>
        );
    }

    if (typeof onClick === 'function') {
        return (
            <button
                type="button"
                onClick={onClick}
                className="rounded-xl border border-white/[0.08] bg-ecf-card p-4 text-left hover:bg-white/[0.06] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ecf-yellow"
            >
                {corpo}
            </button>
        );
    }

    return <div className="rounded-xl border border-white/[0.08] bg-ecf-card p-4 text-left">{corpo}</div>;
}
