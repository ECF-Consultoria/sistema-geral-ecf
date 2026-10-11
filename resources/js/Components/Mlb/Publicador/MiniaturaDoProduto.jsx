import { useState } from 'react';
import { cn } from '@/lib/utils';
import { iniciaisDoNome } from '@/Components/Mlb/Publicador/layoutDaListaDeProdutos.js';

// ═══════════════════════════════════════════════════════════════════════════
// A miniatura do produto: a foto de capa, ou as iniciais do nome (10/10/2026).
//
// O usuário, olhando a lista: "aqui deveria trazer as imagens dos produtos
// também", no lugar dos "ícones que só têm as duas primeiras letras".
//
// `capa` vem na linha de `produtosParaTela`: a 1ª foto do rascunho que já está
// no Mercado Livre, na variação pequena da mesma foto. Sem foto (produto novo,
// conta não liberada), ou se a imagem não carregar, ficam as iniciais — nunca
// o ícone de imagem quebrada.
//
// Um componente só para a linha e para o painel lateral: as duas mostram o
// mesmo produto e não podem discordar.
// ═══════════════════════════════════════════════════════════════════════════

/** A capa em forma segura: só endereço https em texto; qualquer outra coisa vira nulo. */
export const capaSegura = (valor) => (typeof valor === 'string' && valor.startsWith('https://') ? valor : null);

/**
 * @param {Object}  props
 * @param {*}       props.nome  o nome do produto (as iniciais saem dele)
 * @param {*}       props.capa  `produto.capa` do servidor, como veio
 * @param {?number} props.lado  lado em px; sem ele, o tamanho vem de `className`
 */
export default function MiniaturaDoProduto({ nome, capa = null, lado = null, className }) {
    // Guarda QUAL endereço falhou: trocada a foto, a capa nova tem a sua chance.
    const [falhou, setFalhou] = useState(null);
    const url = capaSegura(capa);
    const iniciais = iniciaisDoNome(nome);
    const comFoto = url !== null && falhou !== url;

    return (
        <span
            aria-hidden="true"
            data-miniatura={iniciais}
            data-miniatura-foto={comFoto ? 'sim' : 'nao'}
            style={lado ? { width: `${lado}px`, height: `${lado}px` } : undefined}
            className={cn(
                'flex shrink-0 items-center justify-center overflow-hidden rounded-lg border border-white/[0.08] text-[11px] font-bold text-white/55',
                // Foto de produto tem fundo branco: o quadrado acompanha, para não sobrar moldura escura.
                comFoto ? 'bg-white' : 'bg-white/[0.04]',
                className,
            )}
        >
            {comFoto
                ? <img src={url} alt="" loading="lazy" decoding="async" onError={() => setFalhou(url)} className="h-full w-full object-cover" />
                : iniciais}
        </span>
    );
}
