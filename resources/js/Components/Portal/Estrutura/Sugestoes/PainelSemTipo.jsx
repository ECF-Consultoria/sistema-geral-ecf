import { useState } from 'react';
import { Link } from '@inertiajs/react';
import { Loader2, Tag } from 'lucide-react';
import { Paginacao } from '@/Components/Portal/Estrutura/comum';
import { cn } from '@/lib/utils';
import { opcoesDeTipo, textoPodeSer } from '@/lib/sugestoesEstrutura';

// ─── Aba "Sem tipo" (UI-SPEC "Aba Sem tipo", D-12) ──────────────────────────
//
// Para Kit e Combit o produto precisa de um tipo. A escolha é feita AQUI, nunca na ficha
// da 167. Uma linha por produto, num container (168-19, D-24); gravar é por botão (`Definir tipo`), não ao mudar o select.
// Nada é calculado: o servidor manda os candidatos da inferência e a página só apresenta.
// O cartão some quando a página recarrega as sugestões depois de gravar.

const FOCO = 'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ecf-yellow/40';
const SELECT = 'h-11 w-full rounded-[10px] border border-white/[0.10] bg-white/[0.04] px-3 text-[14px] text-white focus:border-ecf-yellow/40 focus:outline-none focus:ring-0 [&>option]:bg-ecf-card';

/** Família, ambiente e categoria chegam como texto; tolera objeto `{ nome }`. */
const texto = (x) => (x && typeof x === 'object' ? (x.nome ?? '') : (x ?? ''));

function CartaoSemTipo({ produto, tipos, gravando, onDefinir, onAjustar }) {
    const [escolha, setEscolha] = useState('');
    const opcoes = opcoesDeTipo(tipos, produto.candidatos);
    const podeSer = textoPodeSer(produto.candidatos);
    const familia = texto(produto.familia);
    const ambientes = (produto.ambientes ?? []).map(texto).filter(Boolean);
    const categoria = texto(produto.categoria);
    const idSelect = `tipo-produto-${produto.id}`;

    return (
        <article data-produto-sem-tipo={produto.id}
            className="min-w-0 rounded-[10px] border border-white/[0.07] bg-ecf-card p-3 xl:grid xl:grid-cols-[minmax(0,1.4fr)_minmax(0,1fr)_220px_auto] xl:items-center xl:gap-4 xl:py-2.5">
            <div className="min-w-0">
                <h3 className="truncate text-[15px] font-semibold text-white" title={produto.nome}>{produto.nome}</h3>
                {familia ? (
                    <p className="mt-0.5 truncate text-[13px] text-white/60">{[familia, ambientes.join(', ')].filter(Boolean).join(' · ')}</p>
                ) : (
                    <p className="mt-0.5 text-[13px] text-white/60">
                        Sem família. Kit e Combit precisam de família, escolha{' '}
                        <Link href={route('portal.auth.estrutura.produtos.ficha', produto.id)} className="text-white/85 underline underline-offset-2 hover:text-white">na ficha</Link>.
                    </p>
                )}
                {categoria && (
                    <p className="mt-0.5 flex items-center gap-1.5 text-[12px] text-white/50">
                        <Tag size={12} aria-hidden="true" className="shrink-0" /> <span className="truncate">{categoria}</span>
                    </p>
                )}
            </div>

            <div className="mt-2 xl:mt-0">
                {podeSer && <p className="text-[13px] text-white/70">{podeSer}</p>}
            </div>

            <div className="mt-3 xl:mt-0">
                <label htmlFor={idSelect} className="mb-1 block text-[12px] font-semibold text-white/70 xl:sr-only">Tipo do produto</label>
                <select id={idSelect} value={escolha} onChange={(e) => setEscolha(e.target.value)} className={cn(SELECT, 'xl:h-10')}>
                    <option value="">Escolha o tipo…</option>
                    {opcoes.map((o) => <option key={o.valor} value={o.valor}>{o.rotulo}</option>)}
                </select>
            </div>

            <div className="mt-3 flex flex-wrap items-center gap-3 xl:mt-0">
                <button type="button" onClick={() => onDefinir(produto, escolha)} disabled={! escolha || gravando} data-acao="definir-tipo"
                    aria-label={`Definir tipo de ${produto.nome}`}
                    className={cn('inline-flex h-11 items-center justify-center gap-1.5 rounded-xl border border-white/[0.10] bg-white/[0.03] px-4 text-[13px] font-medium text-white/85 transition-colors hover:bg-white/[0.07] hover:text-white disabled:pointer-events-none disabled:opacity-40 xl:h-9', FOCO)}>
                    {gravando && <Loader2 size={14} className="animate-spin" aria-hidden="true" />}
                    Definir tipo
                </button>
                <button type="button" onClick={() => onAjustar(produto)} data-acao="ajustar-quantidades"
                    className={cn('min-h-[44px] text-[13px] text-white/60 underline-offset-2 hover:text-white hover:underline xl:min-h-0', FOCO)}>
                    Ajustar quantidades
                </button>
            </div>
        </article>
    );
}

export default function PainelSemTipo({ produtos = [], tipos = [], paginacao, gravando = new Set(), visitando = false, onDefinir, onAjustar, onIr }) {
    return (
        <section className="mt-4" data-painel-sem-tipo>
            <p className="max-w-[900px] text-[14px] leading-relaxed text-white/70">
                Para sugerir Kit e Combit, precisamos saber o que cada produto é. Escolha o tipo dos produtos abaixo. Quem fica sem tipo continua podendo ter Combo.
            </p>

            {produtos.length === 0 ? (
                <div className="mt-6 rounded-2xl border border-dashed border-white/[0.12] p-6 text-center" data-estado-vazio>
                    <h2 className="text-[17px] font-semibold text-white">Todos os produtos têm tipo</h2>
                </div>
            ) : (
                <div className={cn('mt-4 space-y-1.5 rounded-[12px] border border-white/[0.08] bg-ecf-card/60 p-1.5', visitando && 'opacity-60')} aria-busy={visitando}>
                    {produtos.map((p) => (
                        <CartaoSemTipo key={p.id} produto={p} tipos={tipos} gravando={gravando.has(p.id)} onDefinir={onDefinir} onAjustar={onAjustar} />
                    ))}
                </div>
            )}

            {paginacao?.paginas > 1 && (
                <div className="mt-6">
                    <Paginacao rotulo="produtos" paginacao={paginacao} onIr={onIr} />
                </div>
            )}
        </section>
    );
}
