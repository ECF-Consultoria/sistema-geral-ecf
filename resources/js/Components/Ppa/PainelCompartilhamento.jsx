import { useState } from 'react';
import { Check, Copy, ExternalLink, Eye, Link as LinkIcon, Lock, MoreVertical } from 'lucide-react';
import { cn } from '@/lib/utils';

// ─── Compartilhamento como ESTADO do plano ──────────────────────────────────
//
// Antes isto era um botão solto — "Gerar Link do Cliente" — e nada na tela
// dizia se o cliente estava vendo o plano ou não. Agora o painel comunica o
// ESTADO primeiro (compartilhado ou interno, com a régua do próprio Portal) e
// guarda as ações num menu.
//
// ### Por que ele perdeu a moldura
// Nasceu como um cartão com borda ao lado do título, e o resultado foi um bloco
// flutuando longe de tudo. Agora é uma FAIXA dentro do mesmo bloco de
// cabeçalho, separada do título só por um fio vertical: o compartilhamento
// passa a ler como uma propriedade do PPA, que é o que ele é, e não como um
// widget avulso.
//
// ### Dois caminhos para o cliente, e por que os dois continuam
//  - **Portal do Cliente** (`portal.ppa`), por EMPRESA: o cliente entra uma vez
//    e vê todos os planos dele. É o caminho preferido e o que o botão principal
//    abre quando existe.
//  - **Link do quadro** (`ppa.workspace`), por PPA: o link avulso que sempre
//    existiu. Continua sendo gerado e enviado do mesmo jeito, e é o único
//    caminho quando não há portal — PPA de Polos amarra em `MlbEmpresa`, e nem
//    toda uma tem `company_id` para chegar a uma empresa do portal.
//
// Nenhuma das duas funcionalidades foi removida.

export default function PainelCompartilhamento({ visibilidade, workspaceUrl, onGerarLink }) {
    const [menu, setMenu] = useState(false);
    const [copiado, setCopiado] = useState(false);

    const { compartilhado, rotulo, detalhe, portal_url: portalUrl } = visibilidade;

    // O que o botão principal abre. Portal por empresa quando existe; senão, o
    // link avulso do quadro.
    const urlPrincipal = portalUrl || workspaceUrl;

    const copiar = (url) => {
        navigator.clipboard?.writeText(url);
        setCopiado(true);
        setMenu(false);
        setTimeout(() => setCopiado(false), 2000);
    };

    return (
        <div className="flex items-start gap-3 lg:pl-6 lg:border-l lg:border-white/[0.06] min-w-0 lg:max-w-[400px]">
            <span className={cn(
                'grid place-items-center h-9 w-9 rounded-xl shrink-0',
                compartilhado
                    ? 'bg-emerald-400/10 text-emerald-300 ring-1 ring-inset ring-emerald-400/20'
                    : 'bg-white/[0.04] text-white/40 ring-1 ring-inset ring-white/[0.06]',
            )}>
                {compartilhado ? <Eye size={16} /> : <Lock size={16} />}
            </span>

            <div className="min-w-0 flex-1">
                <div className="flex items-start gap-2">
                    <div className="min-w-0 flex-1">
                        <p className={cn(
                            'font-semibold text-[13.5px] leading-tight',
                            compartilhado ? 'text-emerald-300' : 'text-white/70',
                        )}>
                            {compartilhado ? 'Compartilhado com o cliente' : rotulo}
                        </p>
                        <p className="text-white/35 text-[11.5px] mt-1 leading-relaxed">{detalhe}</p>
                    </div>

                    {/* Ações num menu: o estado é a informação principal, e três
                        botões lado a lado competiriam com ela. */}
                    {compartilhado && (
                        <div className="relative shrink-0">
                            <button
                                type="button"
                                onClick={() => setMenu((v) => !v)}
                                className="p-1 rounded-lg text-white/30 hover:text-white/80 hover:bg-white/[0.06] transition-colors"
                                title="Opções de compartilhamento"
                            >
                                <MoreVertical size={15} />
                            </button>

                            {menu && (
                                <>
                                    <div className="fixed inset-0 z-10" onClick={() => setMenu(false)} />
                                    <div className="absolute right-0 top-7 z-20 w-56 rounded-xl bg-ecf-card ring-1 ring-white/[0.10] shadow-xl shadow-black/50 py-1">
                                        {portalUrl && (
                                            <button
                                                type="button"
                                                onClick={() => copiar(portalUrl)}
                                                className="w-full flex items-center gap-2 px-3 py-2 text-[12.5px] text-white/70 hover:bg-white/[0.05] hover:text-white transition-colors"
                                            >
                                                <Copy size={12} /> Copiar link do Portal
                                            </button>
                                        )}

                                        {workspaceUrl ? (
                                            <button
                                                type="button"
                                                onClick={() => copiar(workspaceUrl)}
                                                className="w-full flex items-center gap-2 px-3 py-2 text-[12.5px] text-white/70 hover:bg-white/[0.05] hover:text-white transition-colors"
                                            >
                                                <LinkIcon size={12} /> Copiar link deste quadro
                                            </button>
                                        ) : (
                                            <button
                                                type="button"
                                                onClick={() => { setMenu(false); onGerarLink(); }}
                                                className="w-full flex items-center gap-2 px-3 py-2 text-[12.5px] text-white/70 hover:bg-white/[0.05] hover:text-white transition-colors"
                                            >
                                                <LinkIcon size={12} /> Gerar link deste quadro
                                            </button>
                                        )}
                                    </div>
                                </>
                            )}
                        </div>
                    )}
                </div>

                {/* Ação principal */}
                {compartilhado ? (
                    urlPrincipal && (
                        <a
                            href={urlPrincipal}
                            target="_blank"
                            rel="noreferrer"
                            className="mt-2.5 inline-flex items-center gap-1.5 rounded-lg bg-white/[0.05] hover:bg-white/[0.09] px-3 py-1.5 text-[12.5px] text-white/75 hover:text-white transition-colors"
                        >
                            <ExternalLink size={13} />
                            {portalUrl ? 'Abrir portal do cliente' : 'Abrir quadro do cliente'}
                        </a>
                    )
                ) : (
                    // Rascunho: nada de link. O caminho para compartilhar é mudar
                    // o status na listagem — dizer isso é mais útil do que um
                    // botão que geraria link para uma tela que o portal esconde.
                    <p className="mt-2 text-white/25 text-[11.5px] leading-relaxed">
                        Para compartilhar, mude o status para <span className="text-white/55 font-medium">Enviado</span> na listagem.
                    </p>
                )}

                {copiado && (
                    <p className="mt-2 flex items-center gap-1.5 text-emerald-400 text-[11.5px]">
                        <Check size={12} /> Link copiado
                    </p>
                )}
            </div>
        </div>
    );
}
