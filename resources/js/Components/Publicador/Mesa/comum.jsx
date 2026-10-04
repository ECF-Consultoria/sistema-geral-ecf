import { createContext, useContext } from 'react';
import { AlertCircle } from 'lucide-react';
import { cn } from '@/lib/utils';

// ─── Base do formulário do anúncio (3 etapas, 04/10/2026) ───────────────────
//
// Pedido do cliente: "os campos nem parecem que são para preencher". Então
// aqui não há tile nem cartão em volta de campo: rótulo normal (sem caixa-alta)
// em cima, a caixa do campo com borda bem visível e espaço entre os campos.
// As seções (`Secao`) são o único bloco, como no Mercado Livre.
//
// Erro só aparece DEPOIS do "Continuar" (`ErrosDaEtapa`): antes disso o campo
// vazio fica neutro. Aí o campo fica com borda vermelha e a mensagem embaixo —
// a do servidor quando há, senão "Preencha este campo.".

/** A caixa de um campo de texto/número: 44px, borda clara, foco amarelo. */
export const CAMPO = 'h-11 w-full rounded-lg border border-white/20 bg-black/40 px-3 text-[15px] font-normal text-white placeholder:text-white/30 hover:border-white/35 focus:border-ecf-yellow focus:outline-none focus:ring-2 focus:ring-ecf-yellow/25 disabled:cursor-not-allowed disabled:opacity-50';
/** O mesmo, num select nativo (o Radix com `value=""` derruba a tela). */
export const SELECT = cn(CAMPO, 'appearance-auto [&>option]:bg-ecf-card');
/** A caixa de texto longo (descrição). */
export const AREA = 'w-full rounded-lg border border-white/20 bg-black/40 p-3 text-[15px] font-normal leading-relaxed text-white placeholder:text-white/30 hover:border-white/35 focus:border-ecf-yellow focus:outline-none focus:ring-2 focus:ring-ecf-yellow/25 disabled:cursor-not-allowed disabled:opacity-50';
/** Borda vermelha do campo com erro (somar a CAMPO/SELECT/AREA). */
export const INVALIDO = 'border-red-400 hover:border-red-400 focus:border-red-400 focus:ring-red-400/25';
/** Botão de texto (Sugerir com IA, Copiar do…, Alterar): discreto, sublinha no hover. */
export const LINK = 'inline-flex items-center gap-1.5 rounded text-[13px] font-bold text-white/70 underline-offset-4 hover:text-ecf-yellow hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ecf-yellow disabled:pointer-events-none disabled:opacity-50';

const ContextoDeErros = createContext({ mostrar: false, problemas: [] });

/** A etapa diz se já houve "Continuar" (`mostrar`) e quais são os problemas do rascunho. */
export const ErrosDaEtapa = ContextoDeErros.Provider;

// Mensagem do servidor que só pede o valor ("Preencha…", "Informe…"): não vale para campo já preenchido
// (o servidor ainda não viu o que acabou de ser digitado).
const PEDE_VALOR = /^(Preencha|Informe|Escreva|Escolha|Adicione)\b/;

/**
 * O erro de um campo depois do "Continuar". `filtro(alvo)` escolhe os bloqueios do campo;
 * `vazio` = o campo é exigido e está vazio na tela (sem mensagem do servidor, vale "Preencha
 * este campo."); `preenchido` = há valor na tela (aí "Preencha…" do servidor está velho).
 * Antes do "Continuar", sempre nulo.
 */
export function useErroDoCampo(filtro, { vazio = false, preenchido = ! vazio } = {}) {
    const { mostrar, problemas } = useContext(ContextoDeErros);
    if (! mostrar) return null;
    const meus = (problemas ?? []).filter((p) => p.severidade === 'BLOCKER' && filtro(p.alvo ?? {}) && (! preenchido || ! PEDE_VALOR.test(p.mensagem ?? '')));
    if (meus.length > 0) return meus[0].mensagem;

    return vazio ? 'Preencha este campo.' : null;
}

/** Mensagem de erro embaixo do campo. */
export function ErroDoCampo({ id, children }) {
    if (! children) return null;

    return (
        <p id={id} className="mt-1.5 flex items-start gap-1.5 text-[13px] font-normal text-red-300" data-erro-campo>
            <AlertCircle size={14} className="mt-0.5 shrink-0" aria-hidden="true" /> <span>{children}</span>
        </p>
    );
}

/**
 * Um campo: rótulo em cima (13px, negrito, sem caixa-alta), o controle, e embaixo o erro ou a
 * dica. `extra` fica à direita do rótulo (contador, "Sugerir com IA"). `htmlFor` liga o rótulo.
 */
export function Campo({ rotulo, htmlFor, extra = null, dica = null, erro = null, className, children }) {
    return (
        <div className={className} data-campo-rotulo={typeof rotulo === 'string' ? rotulo : undefined}>
            {(rotulo || extra) && (
                <div className="mb-1.5 flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1">
                    {rotulo && <label htmlFor={htmlFor} className="text-[13px] font-bold text-white/90">{rotulo}</label>}
                    {extra}
                </div>
            )}
            {children}
            {erro ? <ErroDoCampo>{erro}</ErroDoCampo> : (dica && <p className="mt-1.5 text-[13px] font-normal text-white/50">{dica}</p>)}
        </div>
    );
}

/** Uma seção da etapa (o bloco do Mercado Livre): título 24px, descrição e o formulário. */
export function Secao({ id, titulo, descricao = null, acao = null, children, className }) {
    return (
        <section id={id} aria-labelledby={id ? `${id}-titulo` : undefined} className={cn('scroll-mt-24 rounded-xl border border-white/[0.08] bg-ecf-card p-6 max-sm:p-4', className)} data-secao={id}>
            <header className="mb-6 flex flex-wrap items-start justify-between gap-3">
                <div className="min-w-0">
                    <h2 id={id ? `${id}-titulo` : undefined} className="font-display text-[24px] font-bold leading-tight text-white">{titulo}</h2>
                    {descricao && <p className="mt-1 max-w-[72ch] text-[13px] font-normal text-white/55">{descricao}</p>}
                </div>
                {acao}
            </header>
            {children}
        </section>
    );
}

/** Subtítulo dentro de uma seção (ex.: "Características principais"). */
export function Subtitulo({ children, descricao = null }) {
    return (
        <div className="mb-4">
            <h3 className="text-[15px] font-bold text-white">{children}</h3>
            {descricao && <p className="mt-0.5 text-[13px] font-normal text-white/50">{descricao}</p>}
        </div>
    );
}
