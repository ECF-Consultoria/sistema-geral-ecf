import { Package } from 'lucide-react';
import { cn } from '@/lib/utils';
import { iniciais, tomDoProduto } from './identidade';

// ═══════════════════════════════════════════════════════════════════════════
// Fase 177, plano 02 — tile do produto (TOK-13).
//
// README §Design Tokens: "Tiles de produto (placeholder até haver foto real):
// background: oklch(0.27 0.035 H), color: oklch(0.86 0.07 H) com iniciais do
// produto, onde H é um hue estável por produto. Substituir pela foto principal
// quando existir."
//
// Tamanhos e raios medidos no protótipo (o raio e o corpo da letra acompanham o
// lado do tile; não é um valor só):
//   28px → raio 8px  ("PublicadorHeader.dc.html":35)
//   30px → raio 8px  ("Desempenho.dc.html":148)
//   32px → raio 8px, letra 10.5px/600 ("Visao Geral.dc.html":242)
//   34px → raio 8px  ("PublicadorHeader.dc.html":146)
//   38px → raio 9px  ("Publicacoes v2.dc.html":175)
//   40px → raio 9px, letra 11.5px/600 ("Visao Geral.dc.html":320)
//   84px → raio 12px, letra 20px/600 ("Publicacoes v2.dc.html":326)
//
// ⚠️ A URL pequena do CDN do Mercado Livre é decidida no SERVIDOR, por
// ProgramasPublicadorService::miniaturaDaFoto (sufixo -I.jpg, ~4 kB, contra
// -F.jpg, ~200 kB). Este componente NÃO mexe em sufixo de URL: se chegar a URL
// grande, o conserto é no servidor, não aqui.
// ═══════════════════════════════════════════════════════════════════════════

// ─── Fase 178, plano 02 — a prop `letra` (ADIÇÃO PURA) ───
//
// O protótipo `PublicadorHeader.dc.html`:146 diz `font-size:11px` para o tile de
// 34px do modal "Nova publicação", e a escada abaixo devolve `text-[10.5px]`
// para `lado <= 34`. Os dois estão certos: a escada foi medida nas telas onde
// foi medida (`Visao Geral.dc.html`:242 e vizinhas), e o modal mede outra coisa.
// O protótipo :54 confirma o mesmo 11px no tile de 30px do dropdown de empresa.
//
// Em vez de mexer na escada (que mudaria silenciosamente 4 telas já conferidas),
// `letra` deixa a TELA dizer o corpo quando precisa sair da escada — exatamente
// como `raio` já faz. Prop AUSENTE = escada intacta, byte a byte.

/** Raio e corpo da letra por lado do tile — a escada medida no protótipo. */
function escala(lado) {
    if (lado <= 34) return { raio: 8, letra: 'text-[10.5px]' };
    if (lado <= 40) return { raio: 9, letra: 'text-[11.5px]' };
    return { raio: 12, letra: 'text-[20px]' };
}

/**
 * @param {object} props
 * @param {string|number|null} props.id      identificador do produto (define o hue)
 * @param {string|null} props.nome           nome do produto (define as iniciais)
 * @param {string|null} [props.foto]         URL da miniatura; só string vale
 * @param {number} [props.tamanho]           lado em px
 * @param {number|null} [props.raio]         raio em px, quando a tela precisar sair da escada
 * @param {string|null} [props.letra]        classe de corpo de texto (`text-[11px]`),
 *   quando a tela precisar sair da escada. Ausente, nula ou vazia = a escada de
 *   sempre — nenhuma chamada existente muda de comportamento.
 * @param {string} [props.className]
 */
export default function TileDoProduto({ id, nome, foto = null, tamanho = 34, raio = null, letra = null, className }) {
    const lado = typeof tamanho === 'number' && Number.isFinite(tamanho) && tamanho > 0 ? tamanho : 34;
    const medida = escala(lado);
    const borderRadius = typeof raio === 'number' && Number.isFinite(raio) ? raio : medida.raio;

    // ⚠️ REND-01: foto que chega como objeto é tratada como AUSENTE. Nunca
    // interpolada: um src="[object Object]" dispararia request inútil e deixaria
    // o ícone de imagem quebrada na tela.
    const temFoto = typeof foto === 'string' && foto.trim() !== '';

    if (temFoto) {
        return (
            <img
                src={foto}
                alt=""
                loading="lazy"
                style={{ width: lado, height: lado, borderRadius }}
                className={cn('flex-none object-cover', className)}
            />
        );
    }

    const letras = iniciais(nome);
    const tom = tomDoProduto(id);
    // `letra` só vale como CLASSE: número, objeto e string vazia caem na escada.
    const corpo = typeof letra === 'string' && letra.trim() !== '' ? letra : medida.letra;

    return (
        <span
            aria-hidden="true"
            style={{ width: lado, height: lado, borderRadius, background: tom.fundo, color: tom.texto }}
            className={cn('grid flex-none place-items-center font-semibold', corpo, className)}
        >
            {letras !== '' ? letras : <Package strokeWidth={1.6} className="h-1/2 w-1/2 text-pub-txt-3" />}
        </span>
    );
}
