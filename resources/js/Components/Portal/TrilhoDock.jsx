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
// ### Sem a dependência `motion`, mas com mola de verdade
// O Dock original usa `motion`. No portal isso custou ~50 kB gzip no layout de
// TODA página (learnings `react-bits-no-portal.md` §6). Aqui a mola é a mesma
// (massa 0,1, rigidez 150, amortecimento 12 — o padrão do Dock), integrada
// quadro a quadro num `requestAnimationFrame`, e a tecla cresce por
// `transform: scale` — que a placa de vídeo anima sem recalcular a página.
//
// A primeira versão (02/10, manhã) animava `width/height` com transição CSS e o
// usuário achou "travada": cada quadro recalculava o layout do menu inteiro e
// cada movimento do mouse recomeçava a transição. E o "delay" era bug: o
// movimento do mouse reiniciava o cronômetro de abrir, então o menu só abria
// quando o mouse PARAVA. Agora abre no primeiro contato.
//
// ### Por que o painel SOBREPÕE em vez de empurrar
// O `<aside>` reserva 76 px no fluxo; o painel tem sempre 264 px e é RECORTADO
// (`clip-path`) no trilho quando fechado. Abrir é animar o recorte: nada muda
// de largura, nada recalcula, a página não pula. O recorte também corta o
// clique — fechado, só o trilho recebe o mouse.
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

const ESCALA_MAX = 1.3;      // 40 px → 52 px; cada linha tem 52 px, a tecla ampliada não encosta na vizinha
const DISTANCIA = 120;       // alcance do efeito, em px, acima e abaixo do cursor
const MOLA = { massa: 0.1, rigidez: 150, amortecimento: 12 };
const ESPERA_FECHAR = 160;
const CHAVE = 'portal.menu.aberto';
const RECORTE_FECHADO = 'inset(0 188px 0 0)';  // 264 − 76
const RECORTE_ABERTO = 'inset(0 -64px 0 0)';   // deixa a sombra aparecer à direita

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

/** A curva do Dock: escala pela distância do cursor ao centro da tecla. */
const escalaPelaDistancia = (d) => 1 + (ESCALA_MAX - 1) * Math.max(0, 1 - Math.abs(d) / DISTANCIA);

/**
 * Um passo da mola (Euler semi-implícito em sub-passos de 4 ms: com massa 0,1
 * e amortecimento 12 a mola é "dura", e passo de 16 ms ficaria instável).
 */
function passoDaMola(m, alvo, dt) {
    const passos = Math.max(1, Math.ceil(dt / 0.004));
    const h = dt / passos;
    for (let i = 0; i < passos; i++) {
        const a = (-MOLA.rigidez * (m.x - alvo) - MOLA.amortecimento * m.v) / MOLA.massa;
        m.v += a * h;
        m.x += m.v * h;
    }
}

function Tecla({ modulo, aberto }) {
    const Icone = ICONES[modulo.icone] ?? LayoutGrid;

    return (
        <Link href={modulo.url} aria-current={modulo.ativo ? 'page' : undefined} aria-label={modulo.rotulo}
            className={cn('group flex h-[52px] items-center gap-3 rounded-2xl pr-2 outline-none transition-colors focus-visible:ring-2 focus-visible:ring-ecf-yellow/60',
                aberto && ! modulo.ativo && 'hover:bg-white/[0.03]')}
            data-modulo={modulo.chave}>
            <span className="flex w-[56px] shrink-0 items-center justify-center">
                <span data-dock-tecla style={{ willChange: 'transform' }}
                    className={cn('relative flex h-10 w-10 items-center justify-center rounded-xl border transition-colors',
                        modulo.ativo
                            ? 'border-ecf-yellow/40 bg-ecf-yellow/[0.12] text-ecf-yellow'
                            : 'border-white/[0.08] bg-[#11182b] text-white/60 group-hover:border-white/20 group-hover:text-white')}>
                    <Icone size={17} />
                    {/* No trilho fechado o número some com o rótulo: o badge vai para a quina da tecla. */}
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
        <div className="mb-1 ml-[37px] space-y-0.5 border-l border-white/[0.08] pl-3" data-submodulos>
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
    const fechar = useRef(null);
    const cursor = useRef(null);       // Y do cursor sobre o menu; nulo = fora
    const molas = useRef(new Map());   // tecla → { x: escala, v: velocidade }
    const quadro = useRef(null);
    const ultimo = useRef(0);

    const marcar = (v) => {
        setAberto(v);
        gravarAberto(v);
    };
    const abrir = () => {
        clearTimeout(fechar.current);
        if (! aberto) marcar(true);
    };
    const fecharDepois = () => {
        clearTimeout(fechar.current);
        fechar.current = setTimeout(() => marcar(false), ESPERA_FECHAR);
    };

    // O efeito Dock: lê a posição das teclas (o `scale` não mexe no layout, então
    // ler é barato), anda cada mola um passo e escreve só `transform`.
    const animar = (agora) => {
        const dt = Math.min(0.05, ultimo.current ? (agora - ultimo.current) / 1000 : 1 / 60);
        ultimo.current = agora;
        let emRepouso = cursor.current === null;

        painel.current?.querySelectorAll('[data-dock-tecla]').forEach((el) => {
            const r = el.getBoundingClientRect();
            const alvo = cursor.current === null ? 1 : escalaPelaDistancia(cursor.current - (r.top + r.height / 2));
            const m = molas.current.get(el) ?? { x: 1, v: 0 };
            passoDaMola(m, alvo, dt);
            if (Math.abs(m.x - alvo) > 0.001 || Math.abs(m.v) > 0.001) {
                emRepouso = false;
            } else {
                m.x = alvo; // encaixa: sem `scale(1.0001)` sobrando
                m.v = 0;
            }
            molas.current.set(el, m);
            el.style.transform = m.x === 1 ? '' : `scale(${m.x.toFixed(4)})`;
        });

        if (emRepouso) {
            quadro.current = null;
            ultimo.current = 0;

            return;
        }
        quadro.current = requestAnimationFrame(animar);
    };
    const acordar = () => {
        if (quadro.current === null && ! semMovimento()) quadro.current = requestAnimationFrame(animar);
    };

    useEffect(() => () => { clearTimeout(fechar.current); if (quadro.current) cancelAnimationFrame(quadro.current); }, []);

    return (
        <aside className="relative hidden w-[76px] shrink-0 border-r border-white/[0.06] bg-[#0b1220] lg:block" data-trilho-portal data-aberto={aberto ? '1' : '0'}>
            <div ref={painel}
                onPointerEnter={(e) => { abrir(); cursor.current = e.clientY; acordar(); }}
                onPointerMove={(e) => { abrir(); cursor.current = e.clientY; acordar(); }}
                onPointerLeave={() => { fecharDepois(); cursor.current = null; acordar(); }}
                onFocusCapture={abrir}
                onBlurCapture={(e) => { if (! painel.current?.contains(e.relatedTarget)) fecharDepois(); }}
                style={{ clipPath: aberto ? RECORTE_ABERTO : RECORTE_FECHADO }}
                className={cn('sticky z-40 flex w-[264px] flex-col border-r bg-[#0b1220] transition-[clip-path,box-shadow,border-color] duration-200 ease-[cubic-bezier(.2,.8,.2,1)] motion-reduce:transition-none',
                    // A faixa de equipe (44 px, também fixa no topo) fica por cima: o painel começa abaixo dela.
                    comFaixa ? 'top-[44px] h-[calc(100vh-44px)]' : 'top-0 h-screen',
                    aberto ? 'border-white/[0.08] shadow-[16px_0_40px_rgba(0,0,0,0.5)]' : 'border-transparent')}
                data-painel-menu>
                {/* Topo: as iniciais no trilho; o "Olá" quando aberto. */}
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

                <nav className="flex-1 space-y-0.5 overflow-y-auto overflow-x-hidden px-[10px] pt-1" aria-label="Módulos do portal">
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
