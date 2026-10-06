import { ChevronRight, Package } from 'lucide-react';
import { ESTILO_LOGISTICA, iniciais, partesDaCategoria } from '@/lib/produtosEstrutura';
import { cn } from '@/lib/utils';

// ─── Peças pequenas da ficha do produto (167-19), reaproveitadas pela lista ──
//
// Só apresentação: o que mostram vem pronto do servidor (D-15/D-19/D-28).

const TAMANHOS_QUADRO = {
    grande: { caixa: 'h-[198px] w-full lg:w-[266px]', iniciais: 'text-[40px]', icone: 64 },
    cartao: { caixa: 'h-[108px] w-[106px]', iniciais: 'text-[28px]', icone: 44 },
    linha:  { caixa: 'h-[78px] w-[78px]', iniciais: 'text-[22px]', icone: 34 },
    mini:   { caixa: 'h-[62px] w-[62px]', iniciais: 'text-[16px]', icone: 28 },
};

/** Quadro da foto (D-29): existe, mas sem upload — ícone apagado e as iniciais do produto. */
export function QuadroFotoProduto({ nome, tamanho = 'grande', className }) {
    const t = TAMANHOS_QUADRO[tamanho] ?? TAMANHOS_QUADRO.grande;

    return (
        <div role="img" aria-label={`Sem foto de ${nome || 'produto novo'}`}
            className={cn('relative grid shrink-0 place-items-center overflow-hidden rounded-[10px] border border-white/[0.06] bg-white/[0.04]', t.caixa, className)}>
            <Package size={t.icone} strokeWidth={1.25} className="text-white/15" aria-hidden="true" />
            <span className={cn('absolute font-display font-semibold text-white/45', t.iniciais)}>{iniciais(nome)}</span>
        </div>
    );
}

/** Selo de logística (ME2 · Full, ME2, ME1, Pendente). O `ponto` é o da célula "Logística provável". */
export function PilulaLogistica({ chave, rotulos = {}, ponto = false, className }) {
    const estilo = ESTILO_LOGISTICA[chave] ?? ESTILO_LOGISTICA.pendente;

    return (
        <span className={cn('inline-flex h-7 items-center gap-1.5 whitespace-nowrap rounded-lg px-2.5 text-[13px] font-semibold', estilo, className)}
            title={chave === 'pendente' ? 'Pendente: completar cadastro' : undefined}>
            {ponto && <span className="h-2.5 w-2.5 rounded-full bg-current" aria-hidden="true" />}
            {rotulos[chave] ?? chave}
        </span>
    );
}

/** Asterisco dos campos obrigatórios (só Ref e nome do produto). Cor do sistema: vermelho é só erro. */
export function Obrigatorio() {
    return (
        <>
            <span aria-hidden="true" className="ml-0.5 text-white/50">*</span>
            <span className="sr-only"> (obrigatório)</span>
        </>
    );
}

/** Caminho da categoria "A › B › C"; `curto` mostra só a 1ª e a última parte. */
export function CaminhoCategoria({ linha, curto = false, className }) {
    const { partes, estado } = partesDaCategoria(linha);

    if (partes.length === 0) {
        return <span className={cn('text-white/45', className)}>Sem categoria</span>;
    }
    const mostradas = curto && partes.length > 2 ? [partes[0], partes[partes.length - 1]] : partes;
    const apoio = estado === 'a_confirmar' ? ' · a confirmar' : estado === 'nao_validada' ? ' · não validada' : '';

    return (
        <span className={cn('inline-flex min-w-0 flex-wrap items-center gap-x-1.5 text-white', className)} title={partes.join(' › ')}>
            {mostradas.map((parte, i) => (
                <span key={`${parte}-${i}`} className="inline-flex min-w-0 items-center gap-1.5">
                    {i > 0 && <ChevronRight size={13} className="shrink-0 text-white/40" aria-hidden="true" />}
                    <span className="truncate">{parte}</span>
                </span>
            ))}
            {apoio && <span className="text-white/45">{apoio}</span>}
        </span>
    );
}
