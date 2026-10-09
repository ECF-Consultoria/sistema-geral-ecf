import { useState } from 'react';
import { Link } from '@inertiajs/react';
import * as Popover from '@radix-ui/react-popover';
import { ChevronDown } from 'lucide-react';
import SeloConta from './SeloConta';
import SeloPortal from './SeloPortal';
import AvisoContaTravada from './AvisoContaTravada';
import SeletorEmpresaBusca from './SeletorEmpresaBusca';

/**
 * Cabeçalho único da conta do Publicador — trilha, nome+selos, slot de ações
 * à direita e "Trocar empresa". Junta o que hoje está duplicado no cabeçalho
 * de `Pages/Mlb/Publicador/Produtos.jsx`; reusado por Produtos, Alavancas e
 * Publicações (Wave 2 — Fase 172).
 *
 * "Trocar empresa" abre um popover (`@radix-ui/react-popover`) com
 * `SeletorEmpresaBusca` dentro — busca por nome/chave e navega preservando a
 * aba atual (Fase 173, plano 03). O botão é só o Trigger; toda a lógica de
 * busca e de mapeamento de rota fica em `SeletorEmpresaBusca.jsx`.
 *
 * `empresa` é o shape de `ProgramasPublicadorService::empresaParaTela()` —
 * chega como prop de página Inertia e nunca deve ser assumido como forma
 * certa (T-172-BARRA-01): todo campo de texto do servidor passa por
 * `textoSeguro()` antes do JSX, pra nunca repetir "Objects are not valid as
 * a React child" (lição da tela preta de 07/10). `String(valor ?? '—')` NÃO
 * basta — só cobre `null`/`undefined`; um objeto vindo no lugar do texto
 * ainda vira `"[object Object]"` na tela.
 *
 * `acoes` é só um slot — quem usa decide o conteúdo (Sincronizar + "+
 * Produto" em Produtos; "Atualizar agora" em Publicações; nada em
 * Alavancas). A ação em si NUNCA mora aqui dentro.
 */

/**
 * Só aceita string/number do servidor; qualquer outra forma (objeto, array,
 * etc.) cai no fallback. Export NOMEADO: `SeletorEmpresaBusca.jsx` importa
 * esta função de aqui, nunca recria.
 */
export function textoSeguro(valor, fallback = '—') {
    return (typeof valor === 'string' || typeof valor === 'number') ? String(valor) : fallback;
}

export default function BarraDaConta({ empresa, liberada = true, acoes = null }) {
    const [seletorAberto, setSeletorAberto] = useState(false);
    const nome = textoSeguro(empresa?.nome);
    const chave = textoSeguro(empresa?.chave);
    const programaRotulo = textoSeguro(empresa?.programa_rotulo, '');
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
                    <Popover.Root open={seletorAberto} onOpenChange={setSeletorAberto}>
                        <Popover.Trigger asChild>
                            <button
                                type="button"
                                className="inline-flex items-center gap-1 text-[13px] font-normal text-white/55 hover:text-ecf-yellow focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ecf-yellow"
                            >
                                Trocar empresa
                                <ChevronDown size={14} aria-hidden="true" />
                            </button>
                        </Popover.Trigger>
                        <Popover.Portal>
                            <Popover.Content
                                align="start"
                                sideOffset={8}
                                className="z-50 w-[320px] rounded-xl border border-white/[0.08] bg-ecf-card p-3"
                            >
                                <SeletorEmpresaBusca aberto={seletorAberto} onFechar={() => setSeletorAberto(false)} />
                            </Popover.Content>
                        </Popover.Portal>
                    </Popover.Root>
                </div>

                {acoes !== null && (
                    <div className="flex items-center gap-2">{acoes}</div>
                )}
            </div>
        </div>
    );
}
