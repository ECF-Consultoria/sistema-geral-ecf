import { useRef } from 'react';
import { Eye } from 'lucide-react';
import { cn } from '@/lib/utils';
import { Sobrelinha } from '@/Components/Trilha/Pecas';

// ─── Trilha de produtos — o topo da tela ────────────────────────────────────
//
// Título e explicação do protótipo; o "Ver como" (só a equipe vê); a faixa roxa
// da visão do cliente; e as abas "Trilha" | "Mapa da loja".
//
// O "Ver como: Cliente" troca a visão NO NAVEGADOR (nada vai ao servidor): é a
// equipe conferindo o que o cliente vê. Na porta do cliente de verdade o
// seletor não existe.

const VER_COMO = [
    { valor: 'equipe', rotulo: 'Time ECF' },
    { valor: 'cliente', rotulo: 'Cliente' },
];

/**
 * @param {{
 *   empresa: {nome: string} | null,
 *   portaDaEquipe: boolean,
 *   verComo: 'equipe'|'cliente',
 *   onVerComo: (valor: 'equipe'|'cliente') => void,
 *   sessaoEquipe: boolean,
 * }} props
 */
export default function CabecalhoDaTrilha({ empresa, portaDaEquipe, verComo, onVerComo, sessaoEquipe }) {
    const visaoDoCliente = !portaDaEquipe || verComo === 'cliente';

    return (
        <>
            <div className="mb-[18px] flex flex-wrap items-end justify-between gap-x-5 gap-y-3">
                <div className="min-w-0">
                    <Sobrelinha>{empresa?.nome ? `${empresa.nome} · ` : ''}Gestão de Performance</Sobrelinha>
                    {/* `font-sans` e a entrelinha explícitos: o app.css põe h1–h5 em Manrope com
                        line-height 1.08, e a trilha é toda em Inter, como o protótipo. */}
                    <h1 className="mt-0.5 font-sans text-[26px] font-extrabold leading-tight tracking-[-0.02em] text-trilha-fg [text-wrap:balance]">Trilha de produtos</h1>
                    <p className="mt-1.5 max-w-[68ch] text-trilha-fg2">
                        Da lista de produtos ao anúncio no ar, numa trilha só. Cada etapa tem um dono e uma regra de “pronto”; o item só anda quando a etapa fecha.
                    </p>
                </div>

                {portaDaEquipe && (
                    <div className="flex flex-wrap items-center gap-2">
                        <span className="text-[12.5px] text-trilha-muted">Ver como</span>
                        <div role="group" aria-label="Ver como" className="inline-flex overflow-hidden rounded-[10px] border border-trilha-line bg-trilha-surface">
                            {VER_COMO.map((opcao) => {
                                const ligado = verComo === opcao.valor;

                                return (
                                    <button
                                        key={opcao.valor}
                                        type="button"
                                        aria-pressed={ligado}
                                        data-ver-como={opcao.valor}
                                        onClick={() => onVerComo(opcao.valor)}
                                        className={cn(
                                            'px-3 py-[7px] text-[13px] font-semibold text-trilha-fg2 focus-visible:[outline-style:solid] focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-trilha-run',
                                            ligado && 'bg-trilha-navy text-trilha-on-navy',
                                        )}
                                    >
                                        {opcao.rotulo}
                                    </button>
                                );
                            })}
                        </div>
                    </div>
                )}
            </div>

            {/* Alguém da equipe abriu a porta do cliente pelo modo equipe do Portal. */}
            {!portaDaEquipe && sessaoEquipe && (
                <div className="mb-2.5 flex items-center gap-2 rounded-[10px] bg-trilha-run-bg px-[14px] py-2 text-[13px] font-semibold text-trilha-run" data-faixa="sessao-equipe">
                    <Eye size={15} aria-hidden="true" />
                    Você está vendo como o cliente vê.
                </div>
            )}

            {/* Texto do protótipo sem a aprovação do cliente (sem decisão ainda). Na porta do
                cliente de verdade, falando com ele; na simulação da equipe, falando dele. */}
            {visaoDoCliente && (
                <div className="mb-[14px] flex flex-wrap items-center gap-x-2.5 gap-y-1 rounded-[10px] bg-trilha-roxo-bg px-[14px] py-2.5 text-[13px] text-trilha-fg" data-faixa="visao-cliente">
                    <b>Visão do cliente.</b>
                    <span>
                        {portaDaEquipe
                            ? 'O cliente vê a trilha inteira. Ele mexe na lista de produtos; o resto ele acompanha.'
                            : 'Você vê a trilha inteira: mexe na lista de produtos e acompanha o resto.'}
                    </span>
                </div>
            )}
        </>
    );
}

const ABAS = [
    { valor: 'trilha', rotulo: 'Trilha' },
    { valor: 'mapa', rotulo: 'Mapa da loja' },
];

/** As abas "Trilha" | "Mapa da loja" (setas ← → trocam, como numa lista de abas de verdade). */
export function AbasDaVista({ vista, onVista, idDoPainel }) {
    const abas = useRef([]);

    const aoTeclar = (evento) => {
        if (evento.key !== 'ArrowRight' && evento.key !== 'ArrowLeft') return;
        evento.preventDefault();
        const atual = ABAS.findIndex((a) => a.valor === vista);
        const proxima = (atual + (evento.key === 'ArrowRight' ? 1 : -1) + ABAS.length) % ABAS.length;
        onVista(ABAS[proxima].valor);
        abas.current[proxima]?.focus();
    };

    return (
        <div role="tablist" aria-label="Visão" onKeyDown={aoTeclar} className="mb-[14px] inline-flex gap-0.5 rounded-xl bg-trilha-surface2 p-[3px]">
            {ABAS.map((aba, i) => {
                const ligada = vista === aba.valor;

                return (
                    <button
                        key={aba.valor}
                        ref={(el) => {
                            abas.current[i] = el;
                        }}
                        type="button"
                        role="tab"
                        id={`trilha-aba-${aba.valor}`}
                        aria-selected={ligada}
                        aria-controls={idDoPainel}
                        tabIndex={ligada ? 0 : -1}
                        onClick={() => onVista(aba.valor)}
                        className={cn(
                            'rounded-[9px] px-[14px] py-2 text-[13.5px] font-bold text-trilha-fg2 focus-visible:[outline-style:solid] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-trilha-run',
                            ligada && 'bg-trilha-surface text-trilha-fg ring-1 ring-trilha-line',
                        )}
                    >
                        {aba.rotulo}
                    </button>
                );
            })}
        </div>
    );
}
