import { cn } from '@/lib/utils';
import { textoSeguro } from '../BarraDaConta';
import { normalizar } from './identidade';

// ═══════════════════════════════════════════════════════════════════════════
// Fase 177, plano 02 — tipo da oferta (IDENT-03).
//
// README §Componentes de identidade: "Clássico" (400) / "Premium" (600, com
// losango 7px rotacionado 45° antes). "Catálogo" = tag outline 10.5px.
//
// Medidas do protótipo ("Publicacoes v2.dc.html":238-243):
//   coluna com gap de 3px; linha do tipo em 13px, gap de 5px até o losango;
//   losango 7×7px, `rotate(45deg)`, raio 1px, amarelo;
//   tag "Catálogo" em 10.5px/500, `pub-txt-2`, padding 1px 6px, borda
//   `pub-line-strong`, raio 4px (= `rounded-pub-badge`).
//
// ⚠️ O peso É a informação aqui (400 contra 600) — e vem acompanhado do texto,
// nunca sozinho.
//
// ⚠️ A tradução `gold_special → Clássico` e `gold_pro → Premium` NÃO mora aqui:
// é regra de dado e vive no servidor (hoje em `LABEL_TIER`, no lado PHP /
// `gradeMassaUtils`). Este componente recebe o rótulo já decidido e só desenha.
// ═══════════════════════════════════════════════════════════════════════════

/**
 * @param {object} props
 * @param {string|null} props.tipo      rótulo já traduzido ("Clássico" / "Premium")
 * @param {boolean} [props.catalogo]    oferta em catálogo do Mercado Livre
 * @param {string} [props.className]
 */
export default function SeloDoTipo({ tipo, catalogo = false, className }) {
    // Só "Premium" ganha losango e peso 600. Tipo desconhecido ou em formato
    // inesperado cai no "—" do `textoSeguro` e NÃO ganha losango (o losango
    // significa Premium; num tipo que não chegou, seria informação inventada).
    const premium = normalizar(tipo) === 'premium';
    const rotulo = textoSeguro(tipo);

    return (
        <span className={cn('inline-flex min-w-0 flex-col items-start gap-[3px]', className)}>
            <span
                className={cn(
                    'flex items-center gap-[5px] text-[13px] text-pub-txt',
                    premium ? 'font-semibold' : 'font-normal',
                )}
            >
                {premium && <span aria-hidden="true" className="h-[7px] w-[7px] flex-none rotate-45 rounded-[1px] bg-pub-accent" />}
                {rotulo}
            </span>
            {catalogo === true && (
                <span className="rounded-pub-badge border border-pub-line-strong px-[6px] py-[1px] text-[10.5px] font-medium text-pub-txt-2">
                    Catálogo
                </span>
            )}
        </span>
    );
}
