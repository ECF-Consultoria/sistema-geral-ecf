import { cn } from '@/lib/utils';
import { textoSeguro, numeroSeguro } from './CartaoKpi';

/**
 * O cartão da "Metodologia de evolução de Fases" da tela do Produto (quick
 * 261009-t04, tela 04 do pacote do Stitch), nas três variantes do mockup:
 *
 * - `concluida`   — a fase que já existe na família (borda discreta);
 * - `oportunidade`— a próxima fase, a que tem a ação (amarelo TRANSLÚCIDO);
 * - `roadmap`     — a fase que ainda não dá para criar (apagada, com o motivo).
 *
 * ⚠️ Este cartão é só a MOLDURA. Ele não deriva estado de fase nenhum: quem
 * deriva é o servidor (`fases[].estado` / `estado_fase` do
 * `FamiliaDeFasesService`). A Etapa 3 registrou que `pub_produtos` não tem — e
 * não vai ter — coluna de status, então reimplementar a derivação aqui seria
 * inventar uma segunda verdade.
 *
 * ⚠️ **"Não sabemos" ≠ "é zero".** Uma linha de `linhas` só vira texto quando
 * tem `valor`. Sem valor, ela mostra o `motivo` ("ainda não coletado"); sem
 * valor E sem motivo, ela simplesmente NÃO aparece. Em nenhum caminho o cartão
 * escreve 0 no lugar de um dado que não temos.
 *
 * ⚠️ A tela preta de 07/10 nasceu de um campo que chegou como OBJETO e foi
 * renderizado cru ("Objects are not valid as a React child"). Todo campo passa
 * por `textoSeguro`/`numeroSeguro` — `String(x ?? '—')` NÃO cobre esse caso,
 * vira "[object Object]".
 *
 * ⚠️ Armadilha do Rollup deste projeto (feedback_rollup_map_scope_bug.md):
 * variável de escopo do componente lida DENTRO de um `.map()` já foi eliminada
 * no bundle de produção. Tudo que o `.map()` das linhas usa é calculado no
 * próprio callback.
 *
 * `textoSeguro`/`numeroSeguro` vêm de `CartaoKpi.jsx` porque é o módulo mais
 * leve que já os exporta (só depende de `cn`) — `BarraDaConta.jsx` arrastaria o
 * Radix popover do "Trocar empresa" e o axios do seletor de empresas.
 */

/** Moldura de cada variante. Amarelo só TRANSLÚCIDO — sólido é proibido aqui. */
const MOLDURA = {
    concluida: 'border-white/[0.08] bg-white/[0.03]',
    oportunidade: 'border-ecf-yellow/40 bg-ecf-yellow/[0.06]',
    roadmap: 'border-dashed border-white/[0.10] bg-white/[0.02]',
};

/** Cor do selo de estado, por variante. */
const SELO = {
    concluida: 'border-emerald-400/30 bg-emerald-400/10 text-emerald-300',
    oportunidade: 'border-ecf-yellow/40 bg-ecf-yellow/10 text-ecf-yellow',
    roadmap: 'border-white/[0.10] bg-white/[0.04] text-white/55',
};

/** Só as três do mockup; qualquer outra coisa cai na discreta. */
function varianteSegura(valor) {
    return typeof valor === 'string' && Object.prototype.hasOwnProperty.call(MOLDURA, valor)
        ? valor
        : 'concluida';
}

export default function CartaoDaFase({
    numero = null,
    titulo = '',
    subtitulo = '',
    descricao = '',
    variante = 'concluida',
    selo = null,
    destaque = false,
    linhas = [],
    acao = null,
    notaDaAcao = null,
    children = null,
}) {
    const qual = varianteSegura(variante);
    const numeroFase = numeroSeguro(numero);
    const tituloTexto = textoSeguro(titulo, '');
    const subtituloTexto = textoSeguro(subtitulo, '');
    const descricaoTexto = textoSeguro(descricao, '');
    const seloTexto = textoSeguro(selo, '');
    const notaTexto = textoSeguro(notaDaAcao, '');
    const listaDeLinhas = Array.isArray(linhas) ? linhas : [];

    return (
        <div
            className={cn(
                'flex flex-col rounded-xl border p-4',
                MOLDURA[qual],
                qual === 'roadmap' && 'opacity-80',
                destaque === true && 'ring-2 ring-ecf-yellow',
            )}
        >
            <div className="flex flex-wrap items-start justify-between gap-2">
                <p className="text-[11px] font-bold uppercase tracking-[0.05em] text-white/40">
                    {numeroFase !== null ? `Fase ${numeroFase}` : 'Fase'}
                </p>
                {seloTexto !== '' && (
                    <span className={cn('inline-flex items-center rounded-md border px-2 py-0.5 text-[11px] font-bold', SELO[qual])}>
                        {seloTexto}
                    </span>
                )}
            </div>

            {tituloTexto !== '' && (
                <p className="mt-2 font-display text-[15px] font-bold leading-tight text-white">{tituloTexto}</p>
            )}
            {subtituloTexto !== '' && (
                <p className="mt-0.5 text-[11px] font-normal text-white/55">{subtituloTexto}</p>
            )}
            {descricaoTexto !== '' && (
                <p className="mt-2 text-[13px] font-normal text-white/55">{descricaoTexto}</p>
            )}

            {listaDeLinhas.length > 0 && (
                <dl className="mt-3 flex flex-col gap-1.5 rounded-lg border border-white/[0.06] bg-black/20 p-3">
                    {listaDeLinhas.map((bruto, indice) => {
                        // ⚠️ TUDO calculado DENTRO do callback (armadilha do Rollup).
                        const item = bruto && typeof bruto === 'object' && !Array.isArray(bruto) ? bruto : {};
                        const rotulo = textoSeguro(item.rotulo, '');
                        const numeroDoValor = numeroSeguro(item.valor);
                        const valor = numeroDoValor !== null ? String(numeroDoValor) : textoSeguro(item.valor, '');
                        const motivo = textoSeguro(item.motivo, '');

                        if (rotulo === '') return null;
                        // Sem valor E sem motivo a linha não existe — ela NÃO vira zero.
                        if (valor === '' && motivo === '') return null;

                        return (
                            <div key={indice} className="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-0.5">
                                <dt className="text-[11px] font-normal text-white/40">{rotulo}</dt>
                                {valor !== '' ? (
                                    <dd className="text-[13px] font-normal tabular-nums text-white/80">{valor}</dd>
                                ) : (
                                    <dd className="text-[11px] font-normal text-white/40">— {motivo}</dd>
                                )}
                            </div>
                        );
                    })}
                </dl>
            )}

            {children}

            {(acao !== null && acao !== undefined) && <div className="mt-3">{acao}</div>}
            {notaTexto !== '' && (
                <p className="mt-2 text-[11px] font-normal text-white/40">{notaTexto}</p>
            )}
        </div>
    );
}
