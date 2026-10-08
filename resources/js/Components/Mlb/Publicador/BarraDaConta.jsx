import { Link } from '@inertiajs/react';
import { ChevronDown } from 'lucide-react';
import SeloConta from './SeloConta';
import SeloPortal from './SeloPortal';
import AvisoContaTravada from './AvisoContaTravada';

/**
 * Cabeçalho único da conta do Publicador — trilha, nome+selos, slot de ações
 * à direita e "Trocar empresa". Junta o que hoje está duplicado no cabeçalho
 * de `Pages/Mlb/Publicador/Produtos.jsx`; reusado por Produtos, Alavancas e
 * Publicações (Wave 2 — Fase 172). Este plano só cria o componente, sem
 * consumidor ainda.
 *
 * `empresa` é o shape de `ProgramasPublicadorService::empresaParaTela()` —
 * chega como prop de página Inertia e nunca deve ser assumido como forma
 * certa (T-172-BARRA-01): todo campo de texto do servidor passa por
 * `String(... ?? '—')` antes do JSX, pra nunca repetir "Objects are not
 * valid as a React child" (lição da tela preta de 07/10).
 *
 * `acoes` é só um slot — quem usa decide o conteúdo (Sincronizar + "+
 * Produto" em Produtos; "Atualizar agora" em Publicações; nada em
 * Alavancas). A ação em si NUNCA mora aqui dentro.
 */
export default function BarraDaConta({ empresa, liberada = true, acoes = null }) {
    const nome = String(empresa?.nome ?? '—');
    const chave = String(empresa?.chave ?? '—');
    const programaRotulo = String(empresa?.programa_rotulo ?? '');
    const programa = empresa?.programa;

    return (
        <div className="mb-6">
            <nav aria-label="Trilha" className="mb-2 text-[13px] font-normal text-white/55">
                <Link href={route('mlb.anuncios.index')} className="hover:text-ecf-yellow">Publicador</Link>
                <span aria-hidden="true"> › </span>
                <Link href={route('mlb.anuncios.index', { programa })} className="hover:text-ecf-yellow">{programaRotulo}</Link>
                <span aria-hidden="true"> › </span>
                <span className="text-white/70">{nome}</span>
            </nav>

            <div className="flex flex-wrap items-center justify-between gap-4">
                <div className="flex flex-wrap items-center gap-3">
                    <h1 className="font-display text-[24px] font-bold leading-tight text-white">{nome}</h1>
                    <span className="font-mono text-[11px] text-white/40">{chave}</span>
                    <SeloConta token={empresa?.token} />
                    <SeloPortal portal={empresa?.portal} />
                    {!liberada && <AvisoContaTravada variante="selo" />}
                    <Link
                        href={route('mlb.anuncios.index', { programa })}
                        className="inline-flex items-center gap-1 text-[13px] font-normal text-white/55 hover:text-ecf-yellow"
                    >
                        Trocar empresa
                        <ChevronDown size={14} aria-hidden="true" />
                    </Link>
                </div>

                {acoes !== null && (
                    <div className="flex items-center gap-2">{acoes}</div>
                )}
            </div>
        </div>
    );
}
