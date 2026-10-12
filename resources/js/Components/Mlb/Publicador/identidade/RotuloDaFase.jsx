import { cn } from '@/lib/utils';
import GlyphDaFase, { chaveDaFase } from './GlyphDaFase';

// ═══════════════════════════════════════════════════════════════════════════
// Fase 177, plano 02 — rótulo de metodologia (IDENT-02): glyph + nome + o
// complemento que explica a metodologia em uma linha.
//
// Os literais são os da `phaseInfo()` do protótipo
// ("Publicacoes v2.dc.html":509-512) e os do README §Componentes de
// identidade — copiados byte a byte, com o "·" (ponto medial) e o "un." com
// ponto. ⚠️ NÃO parafrasear: "Simples (1 unidade)" não é o mesmo texto.
//
// Medidas do protótipo ("Publicacoes v2.dc.html":223-235): glyph e texto em
// linha com gap de 10px na tabela, 9px na marca do header
// ("PublicadorHeader.dc.html":25); nome 13px/500, complemento 11.5px
// `pub-txt-3`, um sob o outro com gap de 1px.
// ═══════════════════════════════════════════════════════════════════════════

const NOMES = { f1: 'Fase 1', f2: 'Fase 2', legado: 'Legado' };

/** Complemento fixo das metodologias que não têm número de unidades. */
const COMPLEMENTOS = { f1: 'Simples · 1 un.', legado: 'Fora do Publicador' };

/** Gap entre o glyph e o texto, por tamanho — medido no protótipo. */
const GAPS = { lista: 'gap-[10px]', marca: 'gap-[9px]', rodape: 'gap-[9px]' };

/**
 * Quantidade de unidades da Fase 2. Aceita número e texto numérico (o servidor
 * manda `int`; uma prop vinda de query string chega string).
 *
 * ⚠️ REND-02: sem quantidade válida o complemento é só "Combo" — NUNCA
 * "Combo · undefined un." nem "Combo · NaN un.". Zero também não vale: uma
 * oferta de 0 unidades não existe.
 */
function unidadesValidas(quantidade) {
    if (typeof quantidade !== 'number' && typeof quantidade !== 'string') return null;

    const numero = Number(quantidade);
    return Number.isFinite(numero) && numero > 0 ? numero : null;
}

/**
 * @param {object} props
 * @param {1|2|'legado'|string|number|null} props.fase
 * @param {number|string|null} [props.quantidade]  unidades por oferta (Fase 2)
 * @param {'lista'|'marca'|'rodape'} [props.tamanho]
 * @param {string} [props.className]
 */
export default function RotuloDaFase({ fase, quantidade = null, tamanho = 'lista', className }) {
    const chave = chaveDaFase(fase);
    const unidades = unidadesValidas(quantidade);

    // Fase que não chegou (nula, objeto, ausente): "—" e nenhum complemento.
    // Escrever "Legado" aqui seria inventar informação — o mesmo erro que o
    // selo de status não comete ao recusar inventar "Ativa".
    const nome = chave ? NOMES[chave] : '—';

    let complemento = null;
    if (chave === 'f2') {
        complemento = unidades ? `Combo · ${unidades} un.` : 'Combo';
    } else if (chave) {
        complemento = COMPLEMENTOS[chave];
    }

    return (
        <span className={cn('inline-flex items-center', GAPS[tamanho] ?? GAPS.lista, className)}>
            <GlyphDaFase fase={fase} tamanho={tamanho} />
            <span className="flex min-w-0 flex-col gap-[1px]">
                <span
                    className={cn(
                        'text-[13px] font-medium',
                        chave === 'legado' || !chave ? 'text-pub-txt-2' : 'text-pub-txt',
                    )}
                >
                    {nome}
                </span>
                {complemento && <span className="text-[11.5px] text-pub-txt-3">{complemento}</span>}
            </span>
        </span>
    );
}
