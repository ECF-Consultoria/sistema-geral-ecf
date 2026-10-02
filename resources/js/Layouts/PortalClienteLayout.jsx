import { Link, Head, router, usePage } from '@inertiajs/react';
import { Eye, LayoutGrid, LogOut } from 'lucide-react';
import LogoEmpresa from '@/Components/Portal/LogoEmpresa';
import TrilhoDock, { ICONES } from '@/Components/Portal/TrilhoDock';
import { cn } from '@/lib/utils';

// ─── Portal do Cliente — a moldura de todos os módulos ──────────────────────
//
// Nasceu como a `PortalSidebar` de `Onboarding/Publico.jsx`, quando o portal
// era só o onboarding. Virou layout em 21/08/2026 para que Início, Onboarding e
// PPA dividissem o mesmo menu sem que nenhum deles precisasse conhecer os
// outros — o menu vem pronto do backend (`App\Support\Portal\ModulosPortal`),
// e uma página nova só precisa embrulhar seu conteúdo aqui.
//
// No computador (02/10/2026) o menu é um trilho minimizado que abre no hover,
// com o efeito Dock nas teclas — ver `TrilhoDock`. O conteúdo ganha a largura
// que a coluna fixa de 248 px ocupava. No celular nada muda: faixa no topo com
// o menu em linha rolável.
//
// ### "Documentos" continua fora
// A referência visual original trazia um item de Documentos com guias para
// baixar. Não existe biblioteca de documentos: o material de apoio é POR PASSO
// (`DefinicaoOnboarding::TUTORIAIS` e `PASSO_A_PASSO`) e já aparece dentro do
// card de cada item. Item de menu para prateleira vazia é pior do que item
// nenhum. Quando existir acervo, ele entra no catálogo de módulos.

/**
 * Os submódulos do módulo ativo no celular: uma segunda linha rolável, na
 * ordem do caminho. "Em breve" aparece apagado e sem link.
 */
function SubmodulosCompactos({ submodulos }) {
    return (
        <div className="flex gap-1.5 overflow-x-auto pb-0.5" data-submodulos>
            {submodulos.map((s, i) => {
                const numero = <span className={cn('w-3.5 shrink-0 text-[11px] tabular-nums', s.ativo ? 'text-ecf-yellow' : 'text-white/30')}>{i + 1}</span>;
                const classe = cn(
                    'flex shrink-0 items-center gap-2 rounded-lg px-2.5 py-1.5 text-[12px] transition-colors',
                    s.ativo ? 'bg-ecf-yellow/10 font-semibold text-ecf-yellow' : 'text-white/55 hover:bg-white/[0.04] hover:text-white',
                );

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
 * Faixa de sessão de equipe.
 *
 * Fica FIXA no topo, cobre a largura toda e usa a cor de alerta — porque o
 * risco que ela cobre é o analista esquecer onde está e marcar um passo "como
 * cliente". A faixa não impede nada; ela lembra. O que protege o dado é o
 * registro no nome de quem agiu, do lado do servidor.
 */
function FaixaDeEquipe({ empresa }) {
    return (
        <div className="sticky top-0 z-50 bg-amber-400 text-ecf-bg">
            <div className="flex items-center justify-between gap-3 px-4 py-2">
                <p className="flex items-center gap-2 text-[12.5px] font-semibold min-w-0">
                    <Eye size={14} className="shrink-0" />
                    <span className="truncate">
                        Você está no portal de {empresa?.nome} como equipe ECF — o que fizer aqui fica no seu nome.
                    </span>
                </p>

                <button
                    type="button"
                    onClick={() => router.post(route('portal.equipe.sair'))}
                    className="inline-flex items-center gap-1.5 h-7 px-2.5 rounded-lg bg-ecf-bg/15 hover:bg-ecf-bg/25 text-[12px] font-semibold shrink-0 transition-colors"
                >
                    <LogOut size={12} /> Sair do portal
                </button>
            </div>
        </div>
    );
}

/**
 * @param {{nome: string, logo_url: ?string, iniciais: string}} empresa
 * @param {Array} modulos  vem pronto de `ModulosPortal::paraEmpresa()`
 */
export default function PortalClienteLayout({ empresa, modulos = [], titulo, children }) {
    // O ator vem das props da PÁGINA, não de uma prop deste componente. É
    // deliberado: assim uma tela nova do portal não pode esquecer de repassar
    // `usuario` e nascer sem a faixa de aviso. No modo por token não há ator, e
    // `equipe` é falso — que é o certo, já que ali ninguém está autenticado.
    const equipe = !! usePage().props.usuario?.equipe;

    return (
        <div className="min-h-screen bg-ecf-bg">
            <Head title={titulo ? `${titulo} · ${empresa?.nome}` : `Portal · ${empresa?.nome}`} />

            {equipe && <FaixaDeEquipe empresa={empresa} />}

            {/* O `lg:flex` mora SEMPRE aqui, no wrapper que de fato envolve o
                menu + `main`. Antes ele era condicional: com `equipe` ficava
                aqui, e sem `equipe` ia para o container de fora — que tem este
                div como único filho. O resultado era que, no acesso POR TOKEN
                (todo cliente que entra pelo link, onde `equipe` é sempre falso),
                menu e `main` empilhavam como blocos e o conteúdo ia parar uma
                tela inteira abaixo, sem erro nenhum no console.

                A faixa de equipe continua acima das duas colunas por ficar
                FORA deste div, que era o motivo de o wrapper existir. */}
            <div className="lg:flex">

            <TrilhoDock empresa={empresa} modulos={modulos} comFaixa={equipe} />

            {/* No celular a moldura vira faixa no topo: marca, nome e o menu em
                linha rolável — sem isso o cliente de celular ficaria preso no
                módulo em que entrou, sem caminho para os outros. */}
            <header className="lg:hidden bg-[#0b1220] border-b border-white/[0.06] p-4">
                <div className="flex items-center justify-between gap-3">
                    <LogoEmpresa empresa={empresa} tamanho="menu" />
                    <p className="text-white/60 text-[12px] truncate">{empresa?.nome}</p>
                </div>

                <nav className="mt-3 flex gap-1.5 overflow-x-auto pb-0.5">
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
                {/* Os submódulos do módulo ativo ganham uma segunda linha. */}
                {modulos.filter((m) => m.ativo && m.submodulos?.length > 0).map((m) => (
                    <div key={m.chave} className="mt-2">
                        <SubmodulosCompactos submodulos={m.submodulos} />
                    </div>
                ))}
            </header>

            <main className="flex-1 min-w-0">{children}</main>
            </div>
        </div>
    );
}
