import { Link, Head } from '@inertiajs/react';
import { ClipboardList, Home, LayoutGrid, ListChecks } from 'lucide-react';
import LogoEmpresa from '@/Components/Portal/LogoEmpresa';
import { cn } from '@/lib/utils';

// ─── Portal do Cliente — a moldura de todos os módulos ──────────────────────
//
// Nasceu como a `PortalSidebar` de `Onboarding/Publico.jsx`, quando o portal
// era só o onboarding. Virou layout em 21/08/2026 para que Início, Onboarding e
// PPA dividissem o mesmo menu sem que nenhum deles precisasse conhecer os
// outros — o menu vem pronto do backend (`App\Support\Portal\ModulosPortal`),
// e uma página nova só precisa embrulhar seu conteúdo aqui.
//
// O visual é o mesmo de antes, de propósito: mesma faixa `#0b1220`, mesmas
// bordas, mesmos raios e espaçamentos. O que mudou é que os itens deixaram de
// ser âncoras (`#inicio`, `#pendencias`) e viraram links de rota — cada módulo
// é uma página agora.
//
// ### "Documentos" continua fora
// A referência visual original trazia um item de Documentos com guias para
// baixar. Não existe biblioteca de documentos: o material de apoio é POR PASSO
// (`DefinicaoOnboarding::TUTORIAIS` e `PASSO_A_PASSO`) e já aparece dentro do
// card de cada item. Item de menu para prateleira vazia é pior do que item
// nenhum. Quando existir acervo, ele entra no catálogo de módulos.

// `icone` chega como string do backend. Mapa explícito: nome fora desta lista
// cai no genérico em vez de derrubar o render inteiro do menu.
const ICONES = {
    'home':           Home,
    'list-checks':    ListChecks,
    'clipboard-list': ClipboardList,
};

function ItemModulo({ modulo }) {
    const Icone = ICONES[modulo.icone] ?? LayoutGrid;

    return (
        <Link
            href={modulo.url}
            aria-current={modulo.ativo ? 'page' : undefined}
            className={cn(
                'flex items-center gap-2.5 rounded-xl px-3 py-2.5 text-[13px] transition-colors',
                modulo.ativo
                    ? 'bg-ecf-yellow/10 text-ecf-yellow font-semibold'
                    : 'text-white/55 hover:text-white hover:bg-white/[0.04]',
            )}
        >
            <Icone size={15} className="shrink-0" />
            <span className="truncate">{modulo.rotulo}</span>

            {/* O badge acompanha o cliente por todo o portal: ele precisa ver
                "3 pendências no Onboarding" enquanto está no PPA. Por isso a
                contagem é calculada no `PortalClienteService`, para toda
                página, e não em cada módulo. */}
            {modulo.badge > 0 && (
                <span className={cn(
                    'ml-auto inline-flex items-center justify-center min-w-[20px] h-5 px-1.5 rounded-full text-[11px] font-bold',
                    modulo.ativo
                        ? 'bg-ecf-yellow/15 text-ecf-yellow'
                        : 'bg-white/[0.07] text-white/60',
                )}>
                    {modulo.badge}
                </span>
            )}
        </Link>
    );
}

/**
 * @param {{nome: string, logo_url: ?string, iniciais: string}} empresa
 * @param {Array} modulos  vem pronto de `ModulosPortal::paraEmpresa()`
 */
export default function PortalClienteLayout({ empresa, modulos = [], titulo, children }) {
    return (
        <div className="min-h-screen bg-ecf-bg lg:flex">
            <Head title={titulo ? `${titulo} · ${empresa?.nome}` : `Portal · ${empresa?.nome}`} />

            <aside className="lg:w-[248px] lg:shrink-0 lg:min-h-screen bg-[#0b1220] border-b lg:border-b-0 lg:border-r border-white/[0.06]">
                <div className="lg:sticky lg:top-0 p-4 lg:p-5">
                    {/* A marca do cliente ocupa o lugar onde antes ficava fixo
                        "ECF Consultoria". A nossa identidade não some do
                        portal — ela continua no rodapé e nos responsáveis —,
                        mas o topo é da empresa que está entrando. */}
                    <div className="flex items-center justify-between gap-3">
                        <LogoEmpresa empresa={empresa} tamanho="menu" />
                        {/* No mobile a sidebar vira faixa: o nome da empresa vai
                            para o lado da logo em vez de sumir. */}
                        <p className="lg:hidden text-white/60 text-[12px] truncate">{empresa?.nome}</p>
                    </div>

                    <div className="hidden lg:block mt-6">
                        <p className="text-white text-[13px] font-semibold truncate">Olá, {empresa?.nome}!</p>
                        <p className="text-white/35 text-[12px] mt-0.5">Este é o portal da sua empresa.</p>
                    </div>

                    <nav className="hidden lg:block mt-6 space-y-1">
                        {modulos.map((modulo) => (
                            <ItemModulo key={modulo.chave} modulo={modulo} />
                        ))}
                    </nav>

                    {/* No mobile o menu vira uma linha rolável abaixo da faixa —
                        sem isso o cliente de celular ficaria preso no módulo em
                        que entrou, sem caminho para os outros. */}
                    <nav className="lg:hidden mt-3 flex gap-1.5 overflow-x-auto pb-0.5">
                        {modulos.map((modulo) => {
                            const Icone = ICONES[modulo.icone] ?? LayoutGrid;
                            return (
                                <Link
                                    key={modulo.chave}
                                    href={modulo.url}
                                    aria-current={modulo.ativo ? 'page' : undefined}
                                    className={cn(
                                        'flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-[12px] shrink-0 transition-colors',
                                        modulo.ativo
                                            ? 'bg-ecf-yellow/10 text-ecf-yellow font-semibold'
                                            : 'text-white/55 bg-white/[0.03]',
                                    )}
                                >
                                    <Icone size={13} /> {modulo.rotulo}
                                    {modulo.badge > 0 && (
                                        <span className="text-[11px] font-bold">({modulo.badge})</span>
                                    )}
                                </Link>
                            );
                        })}
                    </nav>

                    <div className="hidden lg:block mt-8 rounded-xl border border-white/[0.08] bg-white/[0.02] p-3.5">
                        <p className="text-white text-[12px] font-semibold">Dúvidas?</p>
                        <p className="text-white/40 text-[12px] mt-1 leading-relaxed">
                            Fale com o seu analista responsável — ele acompanha o seu processo com você.
                        </p>
                    </div>

                    <p className="hidden lg:block mt-6 text-white/20 text-[11px]">
                        Portal do Cliente · ECF Consultoria
                    </p>
                </div>
            </aside>

            <main className="flex-1 min-w-0">{children}</main>
        </div>
    );
}
