import { useEffect, useRef, useState } from 'react';
import { Link } from '@inertiajs/react';
import { Calculator, ClipboardList, Home, LayoutGrid, Layers, ListChecks } from 'lucide-react';
import LogoEmpresa from '@/Components/Portal/LogoEmpresa';
import { cn } from '@/lib/utils';

// ─── O menu do portal: trilho minimizado que abre no hover, com efeito Dock ──
//
// Pedido do usuário (02/10/2026): menu lateral minimizado que expande quando o
// cursor passa, e a animação do Dock do React Bits (reactbits.dev/components/dock)
// nos botões — a tecla mais perto do cursor cresce e as vizinhas acompanham.
//
// ### Sem a dependência `motion`
// O Dock original usa `motion` (molas). No portal isso custou ~50 kB gzip no
// layout de TODA página (learnings `react-bits-no-portal.md` §6) — e o público é
// lojista, muito no celular. Aqui o mesmo efeito sai de CSS: a medida de cada
// tecla é calculada pela distância vertical do cursor (a mesma curva do Dock:
// base → ampliado → base em ±`DISTANCIA`) e escrita direto no estilo, uma vez
// por quadro; a transição com leve passada do ponto faz o papel da mola.
//
// ### Por que o painel SOBREPÕE em vez de empurrar
// O `<aside>` é só o trilho que reserva 76 px no fluxo; quem abre é um painel
// `absolute` por cima do conteúdo. Empurrar o layout a cada passada do mouse
// faria a página inteira pular (mesma decisão da barra interna, 25/08).
//
// ### Por que o "aberto" sobrevive à navegação
// O layout do portal não é persistente no Inertia: remonta a cada página.
// Sem guardar o estado, clicar num item fecharia o menu debaixo do cursor
// parado (lição do `AppLayout`, 25/08). Fica em `sessionStorage`.

export const ICONES = {
    'home':           Home,
    'list-checks':    ListChecks,
    'clipboard-list': ClipboardList,
    'calculator':     Calculator,
    'layers':         Layers,
};

const BASE = 40;
const AMPLIADO = 54;
const DISTANCIA = 130;
const ESPERA_ABRIR = 120;
const ESPERA_FECHAR = 220;
const CHAVE = 'portal.menu.aberto';

const lerAberto = () => {
    try {
        return window.sessionStorage.getItem(CHAVE) === '1';
    } catch {
        return false;
    }
};
const gravarAberto = (v) => {
    try {
        window.sessionStorage.setItem(CHAVE, v ? '1' : '0');
    } catch {
        // Navegação privada sem storage: o menu só não lembra.
    }
};

const semMovimento = () => typeof window !== 'undefined' && window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;

/** A curva do Dock: tamanho pela distância do cursor ao centro da tecla. */
const tamanhoPelaDistancia = (d) => BASE + (AMPLIADO - BASE) * Math.max(0, 1 - Math.abs(d) / DISTANCIA);

function Tecla({ modulo, aberto }) {
    const Icone = ICONES[modulo.icone] ?? LayoutGrid;

    return (
        <Link href={modulo.url} aria-current={modulo.ativo ? 'page' : undefined} aria-label={modulo.rotulo}
            className={cn('group flex items-center gap-3 rounded-2xl pr-2 outline-none transition-colors focus-visible:ring-2 focus-visible:ring-ecf-yellow/60',
                aberto && ! modulo.ativo && 'hover:bg-white/[0.03]')}
            data-modulo={modulo.chave}>
            {/* A coluna das teclas tem a largura da tecla AMPLIADA: crescer não empurra o rótulo para o lado. */}
            <span className="flex w-[56px] shrink-0 items-center justify-center">
                <span data-dock-tecla style={{ width: BASE, height: BASE }}
                    className={cn('relative flex items-center justify-center rounded-xl border transition-[width,height,background-color,border-color] duration-200 ease-[cubic-bezier(.34,1.56,.64,1)] motion-reduce:transition-none',
                        modulo.ativo
                            ? 'border-ecf-yellow/40 bg-ecf-yellow/[0.12] text-ecf-yellow'
                            : 'border-white/[0.08] bg-[#11182b] text-white/60 group-hover:border-white/20 group-hover:text-white')}>
                    <Icone className="h-[42%] w-[42%] min-h-[16px] min-w-[16px]" />
                    {/* No trilho fechado o número some com o rótulo: o badge vira ponto na quina da tecla. */}
                    {modulo.badge > 0 && ! aberto && (
                        <span className="absolute -right-1 -top-1 flex h-[18px] min-w-[18px] items-center justify-center rounded-full bg-ecf-yellow px-1 text-[10px] font-bold text-black" data-badge-trilho>
                            {modulo.badge}
                        </span>
                    )}
                </span>
            </span>
            <span className={cn('min-w-0 flex-1 truncate text-[13px] transition-opacity duration-150',
                aberto ? 'opacity-100' : 'pointer-events-none opacity-0',
                modulo.ativo ? 'font-semibold text-ecf-yellow' : 'text-white/65 group-hover:text-white')}>
                {modulo.rotulo}
            </span>
            {modulo.badge > 0 && aberto && (
                <span className={cn('inline-flex h-5 min-w-[20px] items-center justify-center rounded-full px-1.5 text-[11px] font-bold',
                    modulo.ativo ? 'bg-ecf-yellow/15 text-ecf-yellow' : 'bg-white/[0.07] text-white/60')}>
                    {modulo.badge}
                </span>
            )}
        </Link>
    );
}

function Submodulos({ submodulos }) {
    return (
        <div className="ml-[37px] mt-1 space-y-0.5 border-l border-white/[0.08] pl-3" data-submodulos>
            {submodulos.map((s, i) => {
                const numero = <span className={cn('w-3.5 shrink-0 text-[11px] tabular-nums', s.ativo ? 'text-ecf-yellow' : 'text-white/30')}>{i + 1}</span>;
                const classe = cn('flex items-center gap-2 rounded-lg px-2.5 py-1.5 text-[12.5px] transition-colors',
                    s.ativo ? 'bg-ecf-yellow/10 font-semibold text-ecf-yellow' : 'text-white/55 hover:bg-white/[0.04] hover:text-white');

                return s.em_breve ? (
                    <span key={s.chave} className={cn(classe, 'cursor-default text-white/25 hover:bg-transparent hover:text-white/25')} data-submodulo={s.chave} data-em-breve>
                        {numero}{s.rotulo}
                        <span className="ml-auto whitespace-nowrap rounded bg-white/[0.05] px-1.5 py-px text-[9.5px] font-semibold uppercase tracking-wide text-white/35">Em breve</span>
                    </span>
                ) : (
                    <Link key={s.chave} href={s.url} aria-current={s.ativo ? 'page' : undefined} className={classe} data-submodulo={s.chave}>
                        {numero}{s.rotulo}
                    </Link>
                );
            })}
        </div>
    );
}

/**
 * @param {{nome: string, logo_url: ?string, iniciais: string}} empresa
 * @param {Array} modulos  pronto de `ModulosPortal::paraEmpresa()`
 * @param {boolean} comFaixa  há a faixa de equipe no topo (o painel começa abaixo dela)
 */
export default function TrilhoDock({ empresa, modulos, comFaixa = false }) {
    const [aberto, setAberto] = useState(lerAberto);
    const painel = useRef(null);
    const relogio = useRef(null);
    const quadro = useRef(null);

    const marcar = (v) => {
        setAberto(v);
        gravarAberto(v);
    };
    const abrirDepois = () => {
        clearTimeout(relogio.current);
        relogio.current = setTimeout(() => marcar(true), ESPERA_ABRIR);
    };
    const fecharDepois = () => {
        clearTimeout(relogio.current);
        relogio.current = setTimeout(() => marcar(false), ESPERA_FECHAR);
    };

    // O efeito Dock: a cada movimento, no máximo uma medida por quadro.
    const ampliar = (y) => {
        if (semMovimento()) return;
        cancelAnimationFrame(quadro.current);
        quadro.current = requestAnimationFrame(() => {
            painel.current?.querySelectorAll('[data-dock-tecla]').forEach((el) => {
                const r = el.getBoundingClientRect();
                const t = y === null ? BASE : tamanhoPelaDistancia(y - (r.top + r.height / 2));
                el.style.width = `${t}px`;
                el.style.height = `${t}px`;
            });
        });
    };

    useEffect(() => () => { clearTimeout(relogio.current); cancelAnimationFrame(quadro.current); }, []);

    return (
        <aside className="relative hidden w-[76px] shrink-0 lg:block" data-trilho-portal data-aberto={aberto ? '1' : '0'}>
            <div ref={painel}
                onPointerEnter={abrirDepois}
                // Segundo cinto: o cursor parado em cima depois de navegar não dispara "enter" de novo.
                onPointerMove={(e) => { if (! aberto) abrirDepois(); ampliar(e.clientY); }}
                onPointerLeave={() => { fecharDepois(); ampliar(null); }}
                onFocusCapture={() => { clearTimeout(relogio.current); marcar(true); }}
                onBlurCapture={(e) => { if (! painel.current?.contains(e.relatedTarget)) fecharDepois(); }}
                // A faixa de equipe (44 px, também fixa no topo) fica por cima: o painel começa abaixo dela.
                className={cn('sticky z-40 flex flex-col overflow-hidden border-r border-white/[0.06] bg-[#0b1220] transition-[width,box-shadow] duration-200 ease-out motion-reduce:transition-none',
                    comFaixa ? 'top-[44px] h-[calc(100vh-44px)]' : 'top-0 h-screen',
                    aberto ? 'w-[264px] shadow-[12px_0_40px_rgba(0,0,0,0.45)]' : 'w-[76px]')}
                data-painel-menu>
                {/* Topo: as iniciais no trilho; a marca e o "Olá" quando aberto. */}
                <div className="flex h-[76px] shrink-0 items-center gap-3 px-[10px]">
                    <span className="flex w-[56px] shrink-0 items-center justify-center">
                        <span className="flex h-11 w-11 items-center justify-center rounded-xl border border-white/[0.12] bg-[#11182b] text-[13px] font-bold text-ecf-yellow" aria-hidden>
                            {empresa?.iniciais ?? '·'}
                        </span>
                    </span>
                    <div className={cn('min-w-0 transition-opacity duration-150', aberto ? 'opacity-100' : 'opacity-0')}>
                        <p className="truncate text-[13px] font-semibold text-white">Olá, {empresa?.nome}!</p>
                        <p className="truncate text-[11.5px] text-white/35">Portal da sua empresa</p>
                    </div>
                </div>

                <nav className="flex-1 space-y-1.5 overflow-y-auto overflow-x-hidden px-[10px] pt-2" aria-label="Módulos do portal">
                    {modulos.map((modulo) => (
                        <div key={modulo.chave}>
                            <Tecla modulo={modulo} aberto={aberto} />
                            {aberto && modulo.ativo && modulo.submodulos?.length > 0 && <Submodulos submodulos={modulo.submodulos} />}
                        </div>
                    ))}
                </nav>

                <div className={cn('shrink-0 space-y-4 p-4 transition-opacity duration-150', aberto ? 'opacity-100' : 'pointer-events-none opacity-0')}>
                    {empresa?.logo_url && <LogoEmpresa empresa={empresa} tamanho="menu" className="w-[168px]" />}
                    <div className="rounded-xl border border-white/[0.08] bg-white/[0.02] p-3">
                        <p className="text-[12px] font-semibold text-white">Dúvidas?</p>
                        <p className="mt-1 text-[12px] leading-relaxed text-white/40">Fale com o seu analista responsável — ele acompanha o seu processo com você.</p>
                    </div>
                    <p className="text-[11px] text-white/20">Portal do Cliente · ECF Consultoria</p>
                </div>
            </div>
        </aside>
    );
}
