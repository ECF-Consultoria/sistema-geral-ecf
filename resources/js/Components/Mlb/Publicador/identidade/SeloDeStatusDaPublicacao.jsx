import { AlertTriangle } from 'lucide-react';
import { cn } from '@/lib/utils';
import { textoSeguro } from '../BarraDaConta';

// ═══════════════════════════════════════════════════════════════════════════
// Fase 177, plano 02 — status da publicação no Mercado Livre (IDENT-04).
//
// README §Componentes de identidade: "ícone com forma + cor + texto" —
// Ativa = círculo cheio · Pausada = duas barras · Com erro = triângulo ·
// Em revisão = anel aberto. Motivo do status em 11.5px abaixo.
//
// ⚠️ As QUATRO coisas de uma vez: forma, cor e texto. Quem enxerga mal cor
// distingue pela forma; quem usa leitor de tela ouve o texto. Nenhuma das três
// camadas é dispensável.
//
// Medidas do protótipo ("Publicacoes v2.dc.html":246-253):
//   linha em 13px/500 na cor do status, gap de 7px até a forma;
//   círculo 8×8px · barras 2,5×9px com raio 1px e 2px entre elas ·
//   triângulo 11×11px · anel 9×9px com borda de 1,6px e a direita transparente;
//   motivo em 11.5px `pub-txt-3`, recuado 15px (alinha sob o texto, não sob a forma).
//
// ⚠️ A tradução de `MlAcervoItem.status` (`active|paused|under_review|closed`)
// para estas quatro chaves é do SERVIDOR — a Fase 180 liga. Aqui só se desenha
// o que chega.
// ═══════════════════════════════════════════════════════════════════════════

const STATUS = {
    ativa: { rotulo: 'Ativa', texto: 'text-pub-ok-txt' },
    pausada: { rotulo: 'Pausada', texto: 'text-pub-warn-txt' },
    com_erro: { rotulo: 'Com erro', texto: 'text-pub-danger-txt' },
    em_revisao: { rotulo: 'Em revisão', texto: 'text-pub-neutral-txt' },
};

/**
 * A forma de cada status. Status desconhecido cai no anel aberto — a forma
 * "ainda não decidido" — e NUNCA no círculo cheio: dizer "Ativa" para um status
 * que não se entendeu é pior que dizer "não sei".
 */
function FormaDoStatus({ chave }) {
    if (chave === 'ativa') {
        return <span aria-hidden="true" className="h-[8px] w-[8px] flex-none rounded-full bg-pub-ok" />;
    }

    if (chave === 'pausada') {
        return (
            <span aria-hidden="true" className="flex flex-none gap-[2px]">
                <span className="h-[9px] w-[2.5px] rounded-[1px] bg-pub-warn" />
                <span className="h-[9px] w-[2.5px] rounded-[1px] bg-pub-warn" />
            </span>
        );
    }

    if (chave === 'com_erro') {
        return <AlertTriangle aria-hidden="true" strokeWidth={1.6} className="h-[11px] w-[11px] flex-none text-pub-danger" />;
    }

    // `em_revisao` e qualquer status não reconhecido: anel ABERTO (a borda da
    // direita é transparente, como no protótipo).
    return (
        <span
            aria-hidden="true"
            className="h-[9px] w-[9px] flex-none rounded-full border-[1.6px] border-pub-neutral-dot border-r-transparent"
        />
    );
}

/**
 * @param {object} props
 * @param {'ativa'|'pausada'|'com_erro'|'em_revisao'|string|null} props.status
 * @param {string|null} [props.motivo]  por que não está ativa
 * @param {string|null} [props.corpo]   classe de corpo de texto da linha do
 *   status, quando a tela mede outro valor. ⚠️ ADIÇÃO PURA da Fase 179, plano
 *   08, no molde da prop `letra` do `TileDoProduto` (Fase 178): ausente, nula
 *   ou vazia = `text-[13px]`, o comportamento de hoje **byte a byte**.
 *
 *   Por que existe: a coluna de status das "Publicações recentes" da Visão
 *   Geral mede **12,5px** (`new_layout_publicador/Visao Geral.dc.html`:328,
 *   `font-size:12.5px;font-weight:500;gap:7px`), e a escada deste componente
 *   foi medida na tabela de Publicações (`Publicacoes v2.dc.html`:246-253),
 *   onde são 13px. Os dois estão certos — FID-03 proíbe arredondar meio pixel,
 *   e mexer no padrão mudaria em silêncio a tela já conferida.
 * @param {string} [props.className]
 */
export default function SeloDeStatusDaPublicacao({ status, motivo = null, corpo = null, className }) {
    const chave = typeof status === 'string' ? status : '';
    const conhecido = STATUS[chave] ?? null;

    // Status fora das quatro chaves aparece COMO CHEGOU (não se apaga a linha
    // nem se inventa um rótulo) — e em formato inesperado vira "—".
    const rotulo = conhecido ? conhecido.rotulo : textoSeguro(status);

    // Motivo só vira linha quando é texto de verdade. Nulo não renderiza nada —
    // uma linha vazia abaixo do status não informa, só empurra o layout.
    // Objeto também não: renderizaria um "—" solto, que o leitor confundiria
    // com "não tem motivo".
    const temMotivo = (typeof motivo === 'string' || typeof motivo === 'number') && String(motivo).trim() !== '';

    // `corpo` só vale como CLASSE: número, objeto e string vazia caem nos 13px
    // da escada — a mesma disciplina da prop `letra` do `TileDoProduto`.
    const tamanhoDoTexto = typeof corpo === 'string' && corpo.trim() !== '' ? corpo : 'text-[13px]';

    return (
        <span className={cn('inline-flex min-w-0 flex-col gap-[2px]', className)}>
            <span className={cn('flex items-center gap-[7px]', tamanhoDoTexto, 'font-medium', conhecido ? conhecido.texto : 'text-pub-neutral-txt')}>
                <FormaDoStatus chave={chave} />
                {rotulo}
            </span>
            {temMotivo && <span className="truncate pl-[15px] text-[11.5px] text-pub-txt-3">{textoSeguro(motivo)}</span>}
        </span>
    );
}
