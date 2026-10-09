import { useEffect, useRef, useState } from 'react';
import { MoreHorizontal } from 'lucide-react';
import { cn } from '@/lib/utils';
import { linkAnuncioMl } from '@/Pages/Mlb/anuncioHistoricoUtils';

// ═══════════════════════════════════════════════════════════════════════════
// O menu "⋯" de UMA linha da lista de Produtos (layout v2, quick 261009-prd).
//
// A spec trocou as duas ações idênticas de toda linha ("Abrir produto" +
// "Continuar") por UM botão contextual mais este menu. Cada item só aparece
// quando faz sentido para aquele produto.
//
// O menu NÃO navega e NÃO fala com o servidor: devolve a chave escolhida para
// a página, que é quem conhece as rotas. A única exceção é "Ver no Mercado
// Livre", que é um link externo de verdade (`<a target="_blank">`).
// ═══════════════════════════════════════════════════════════════════════════

/** Objeto do servidor em forma segura; qualquer outra coisa vira `{}`. */
const objetoSeguro = (valor) => (valor && typeof valor === 'object' && !Array.isArray(valor) ? valor : {});

/**
 * Os itens do menu deste produto, na ordem do handoff.
 *
 * ⚠️ Função PURA e exportada de propósito: variável de escopo do componente
 * lida dentro de `.map()` já foi eliminada pelo Rollup no bundle de produção
 * deste projeto (feedback_rollup_map_scope_bug.md). Tudo o que o `.map()` do
 * JSX precisa já vem calculado aqui, dentro de cada item.
 *
 * @param {unknown} produto  a linha de `produtosParaTela`
 * @param {unknown} sugestao a `sugestao_kit` já validada pela página (ou null)
 * @returns {Array<{chave: string, rotulo: string, destaque: boolean, href?: string}>}
 */
export function itensDoMenu(produto, sugestao) {
    const p = objetoSeguro(produto);

    const itens = [
        { chave: 'produto', rotulo: 'Abrir produto', destaque: false },
        { chave: 'editor', rotulo: 'Abrir no editor', destaque: false },
    ];

    // "Ver no Mercado Livre" só com anúncio no ar — leva ao primeiro MLB.
    const anuncios = Array.isArray(p.anuncios) ? p.anuncios : [];
    const mlb = anuncios
        .map((bruto) => objetoSeguro(bruto).ml_item_id)
        .find((id) => typeof id === 'string' && id !== '');
    const href = mlb === undefined ? null : linkAnuncioMl(mlb);
    if (href !== null) {
        itens.push({ chave: 'ml', rotulo: 'Ver no Mercado Livre', destaque: false, href });
    }

    // "Criar Fase 2" só faz sentido num BASE que já está no ar: a Fase 2 nasce
    // clonando a Fase 1 publicada. Num kit, não existe fase a criar a partir dele.
    const chave = objetoSeguro(p.status).chave;
    if ((chave === 'publicado' || chave === 'parcial') && p.eh_kit !== true) {
        itens.push({ chave: 'fase2', rotulo: 'Criar Fase 2', destaque: true });
    }

    // "Vincular como kit…" só com sugestão do servidor.
    const s = objetoSeguro(sugestao);
    if (typeof s.base_id === 'number' && Number.isFinite(s.base_id)) {
        itens.push({ chave: 'vincular', rotulo: 'Vincular como kit…', destaque: true });
    }

    return itens;
}

const ITEM = 'flex w-full items-center whitespace-nowrap px-3 py-2 text-left text-[13px] font-normal hover:bg-white/[0.06] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-ecf-yellow';

/**
 * @param {Object}   props
 * @param {Object}   props.produto
 * @param {?Object}  props.sugestao
 * @param {boolean}  props.defaultAberto já aberto na primeira renderização
 *                   (padrão não controlado, como o `defaultOpen` do Radix) —
 *                   é o que deixa o render estático dos testes ver os itens.
 * @param {Function} props.aoEscolher recebe a chave do item
 */
export default function MenuDeAcoesDoProduto({
    produto = null,
    sugestao = null,
    defaultAberto = false,
    aoEscolher,
    className = '',
}) {
    const [aberto, setAberto] = useState(defaultAberto === true);
    const caixa = useRef(null);

    // Fecha com Esc e com clique fora. Guarda o `document` porque este
    // componente também roda em render estático (testes).
    useEffect(() => {
        if (!aberto || typeof document === 'undefined') return undefined;

        const aoTeclar = (ev) => {
            if (ev.key === 'Escape') setAberto(false);
        };
        const aoClicarFora = (ev) => {
            if (caixa.current && !caixa.current.contains(ev.target)) setAberto(false);
        };

        document.addEventListener('keydown', aoTeclar);
        document.addEventListener('mousedown', aoClicarFora);

        return () => {
            document.removeEventListener('keydown', aoTeclar);
            document.removeEventListener('mousedown', aoClicarFora);
        };
    }, [aberto]);

    const itens = itensDoMenu(produto, sugestao);

    return (
        <div ref={caixa} className={cn('relative', className)}>
            <button
                type="button"
                aria-haspopup="menu"
                aria-expanded={aberto}
                aria-label="Mais ações deste produto"
                onClick={(ev) => { ev.stopPropagation(); setAberto((a) => !a); }}
                className="inline-flex h-8 w-8 items-center justify-center rounded-lg border border-white/[0.10] bg-white/[0.03] text-white/70 hover:bg-white/[0.06] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ecf-yellow"
            >
                <MoreHorizontal className="h-4 w-4" aria-hidden="true" />
            </button>

            {aberto && (
                <div
                    role="menu"
                    aria-label="Ações do produto"
                    onClick={(ev) => ev.stopPropagation()}
                    className="absolute right-0 top-9 z-20 w-56 overflow-hidden rounded-lg border border-white/[0.10] bg-ecf-card-2 py-1 shadow-lg"
                >
                    {itens.map((item) => {
                        // ⚠️ Rollup: tudo o que a linha do menu usa é lido do
                        // PRÓPRIO item, nunca de variável do escopo do componente.
                        const ehLink = typeof item.href === 'string' && item.href !== '';
                        const cor = item.destaque === true ? 'text-ecf-yellow' : 'text-white/85';

                        if (ehLink) {
                            return (
                                <a
                                    key={item.chave}
                                    role="menuitem"
                                    href={item.href}
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    onClick={(ev) => { ev.stopPropagation(); setAberto(false); }}
                                    className={cn(ITEM, cor)}
                                >
                                    {item.rotulo}
                                </a>
                            );
                        }

                        return (
                            <button
                                key={item.chave}
                                type="button"
                                role="menuitem"
                                onClick={(ev) => {
                                    ev.stopPropagation();
                                    setAberto(false);
                                    aoEscolher?.(item.chave);
                                }}
                                className={cn(ITEM, cor)}
                            >
                                {item.rotulo}
                            </button>
                        );
                    })}
                </div>
            )}
        </div>
    );
}
