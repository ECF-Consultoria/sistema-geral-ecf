import { useState } from 'react';
import { Check, Package, Pencil, Truck } from 'lucide-react';
import { cn } from '@/lib/utils';
import { fmtReais } from '@/Components/Portal/Estrutura/comum';
import { PilulaLogistica, QuadroFotoProduto } from '@/Components/Portal/Estrutura/Produtos/PecasDoProduto';
import { ROTULO_FASE } from '@/lib/sugestoesEstrutura';

// ─── Peças da linha compacta da sugestão (168-18, D-24..D-31) ───────────────
//
// Só apresentação. O que mostram vem pronto do servidor; as regras de marcação,
// edição e aceite moram em `sugestoesSelecao.js`.

const FOCO = 'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ecf-yellow/40';

const COR_FASE = {
    combo: 'border-violet-400/60 bg-violet-500/10 text-violet-300',
    kit: 'border-emerald-400/60 bg-emerald-500/10 text-emerald-300',
    combit: 'border-ecf-yellow/60 bg-ecf-yellow/10 text-ecf-yellow',
};

/** Selo da fase. A caixa alta é só de CSS: o texto do DOM continua "Combo"/"Kit"/"Combit". */
export function SeloFase({ fase, className }) {
    return (
        <span data-selo-fase={fase}
            className={cn('inline-flex h-6 items-center rounded-full border px-3 text-[12px] font-bold uppercase tracking-wide', COR_FASE[fase], className)}>
            {ROTULO_FASE[fase] ?? fase}
        </span>
    );
}

/** A caixa de seleção única da tela: marcada, fundo amarelo e check escuro. */
export function CaixaDeSelecao({ className, ...props }) {
    return (
        <span className="relative grid place-items-center">
            <input type="checkbox" {...props}
                className={cn('peer h-5 w-5 appearance-none rounded border border-white/35 bg-transparent checked:border-ecf-yellow checked:bg-none checked:bg-ecf-yellow checked:focus:border-ecf-yellow checked:focus:bg-ecf-yellow focus:ring-ecf-yellow/40 focus:ring-offset-0 disabled:opacity-40', 'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ecf-yellow/40', className)} />
            <Check strokeWidth={3} aria-hidden="true" className="pointer-events-none absolute hidden h-3.5 w-3.5 text-ecf-bg peer-checked:block" />
        </span>
    );
}

/** Imagem principal (iniciais da oferta) + "+" + uma miniatura por componente. */
export function QuadrosDaSugestao({ nome, itens = [] }) {
    return (
        <div data-quadros className="flex items-center gap-2">
            <QuadroFotoProduto nome={nome} tamanho="sugestao" />
            <span className="text-white/40" aria-hidden="true">+</span>
            <div className="flex flex-col gap-1">
                {itens.map((i) => <QuadroFotoProduto key={i.variacao_id} nome={i.produto_nome} tamanho="miniatura" />)}
            </div>
        </div>
    );
}

/**
 * Nome ou SKU como texto com lápis; ao clicar vira campo. Enter e sair do campo fecham
 * mantendo a edição; Esc devolve o valor de antes de abrir. `children` são os avisos.
 */
export function CampoEmLinha({ id, campo, rotulo, rotuloAcessivel, valor, editado = false, mono = false, caixa = false, onMudar, children }) {
    const [editando, setEditando] = useState(false);
    const [valorAoAbrir, setValorAoAbrir] = useState('');

    const abrir = () => {
        setValorAoAbrir(valor ?? '');
        setEditando(true);
    };

    return (
        <div className="min-w-0">
            <div className="flex items-center gap-2 text-[11px] leading-[13px] text-white/50 xl:text-[12px] xl:leading-[14px]">
                <span>{rotulo}</span>
                {editado && <span data-editado>· editado</span>}
            </div>
            {editando ? (
                <input id={id} data-campo={campo} aria-label={rotuloAcessivel} autoFocus value={valor ?? ''}
                    onChange={(e) => onMudar(e.target.value)}
                    onKeyDown={(e) => {
                        if (e.key === 'Enter') {
                            e.preventDefault();
                            setEditando(false);
                        } else if (e.key === 'Escape') {
                            e.preventDefault();
                            onMudar(valorAoAbrir);
                            setEditando(false);
                        }
                    }}
                    onBlur={() => setEditando(false)}
                    className={cn('h-11 w-full rounded-md border border-white/[0.12] bg-white/[0.04] px-2 text-[13px] text-white focus:border-ecf-yellow/40 focus:outline-none focus:ring-0 xl:h-7', mono && 'font-mono')} />
            ) : (
                <button type="button" data-editar={campo} onClick={abrir} aria-label={`Editar ${rotuloAcessivel}: ${valor ?? ''}`}
                    className={cn('flex min-h-[44px] max-w-full items-center gap-1.5 text-left text-[13px] text-white xl:min-h-0', FOCO,
                        caixa ? 'h-7 w-full justify-between rounded-md border border-white/[0.08] bg-white/[0.02] px-2 xl:h-6 xl:min-h-0' : 'xl:h-5',
                        mono && 'font-mono')}>
                    <span id={id} data-valor={campo} title={valor ?? ''} className="min-w-0 truncate">{valor}</span>
                    <Pencil size={13} aria-hidden="true" className="shrink-0 text-white/50" />
                </button>
            )}
            {children}
        </div>
    );
}

/** Logística (texto colorido, sem pílula) e frete, lado a lado, sem virar mini-cartões. */
export function LogisticaEFrete({ sugestao, freteCotado = null, vocabulario }) {
    const logistica = sugestao.logistica;
    if (! logistica) return null;

    const semMedida = logistica.sem_medida ?? [];
    const valorFrete = freteCotado?.valor ?? sugestao.frete?.valor ?? null;
    const cotado = freteCotado?.origem === 'api' && ! freteCotado.falhou;
    const temFrete = (logistica.chave === 'me2' || logistica.chave === 'me2_full') && valorFrete !== null;

    return (
        <div className="flex flex-wrap items-center gap-x-3 gap-y-1 text-[12px] text-white/60">
            <span data-logistica className="inline-flex items-center gap-1.5">
                <Package size={14} className="text-white/60" aria-hidden="true" />
                <PilulaLogistica chave={logistica.chave} rotulos={vocabulario?.logisticas}
                    className="h-auto bg-transparent px-0" />
            </span>
            {logistica.chave === 'pendente' && semMedida.length > 0 && (
                <span>
                    Faltam medidas em{' '}
                    {semMedida.map((p, k) => (
                        <span key={p.id}>
                            {k > 0 && ', '}
                            <a href={route('portal.auth.estrutura.produtos.ficha', p.id)} className="text-white/85 underline underline-offset-2 hover:text-white">{p.nome}</a>
                        </span>
                    ))}
                    . Complete na ficha.
                </span>
            )}
            {logistica.chave === 'me1' && (
                <span data-frete title="Fora do tamanho do envio ME2. O frete usa a tabela da sua transportadora.">Frete pela sua transportadora</span>
            )}
            {temFrete && (
                <>
                    <span className="hidden h-8 w-px bg-white/[0.08] sm:block" aria-hidden="true" />
                    <span data-frete className="inline-flex items-center gap-1.5">
                        <Truck size={14} className="text-white/60" aria-hidden="true" />
                        <span className="flex flex-col leading-tight">
                            <span className="text-[12px] text-white/50">{cotado ? 'Frete do Mercado Livre' : 'Frete estimado'}</span>
                            <span className="text-[14px] tabular-nums text-white">{fmtReais(valorFrete)}</span>
                        </span>
                    </span>
                </>
            )}
        </div>
    );
}

/** Tipos distintos dos componentes: botão de texto que abre a escolha do tipo do produto. */
export function TiposDaSugestao({ itens = [], onTipo }) {
    const tipos = [];
    for (const i of itens) {
        if (i.tipo_nome && ! tipos.some((t) => t.tipo === i.tipo)) tipos.push({ tipo: i.tipo, nome: i.tipo_nome, produtoId: i.produto_id, produtoNome: i.produto_nome });
    }

    return (
        <div className="flex flex-wrap gap-x-2">
            {tipos.map((t) => (onTipo ? (
                <button key={t.tipo} type="button" onClick={() => onTipo(t.produtoId)} title="Mudar o tipo deste produto"
                    aria-label={`Mudar o tipo de ${t.produtoNome} (${t.nome})`}
                    className={cn('inline-flex min-h-[44px] items-center gap-1 text-[12px] text-white/55 hover:text-white xl:min-h-0', FOCO)}>
                    {t.nome} <Pencil size={10} aria-hidden="true" />
                </button>
            ) : (
                <span key={t.tipo} className="text-[12px] text-white/55">{t.nome}</span>
            )))}
        </div>
    );
}
