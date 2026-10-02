import { FileText } from 'lucide-react';
import { CLASSE_INPUT } from '@/Components/Portal/Estrutura/comum';
import { estadoDasSecoes } from '../apoio';
import { CardMesa, ChipSecao } from './comum';
import { cn } from '@/lib/utils';

// ─── Card 7 — Descrição (check "Descrição") ─────────────────────────────────
//
// Texto simples (RN-72): sem formatação, abas ou pré-visualização. O limite
// é validado pelo servidor; aqui só se mostra o tamanho.

export default function CardDescricao({ m, aberto = true, onAlternar }) {
    const texto = m.rasc.descricao ?? '';
    const faltam = estadoDasSecoes(m.problemasDaSecao('descricao'), m.schema).descricao.faltam;
    const maximo = m.schema?.limites?.max_description_length ?? null;

    return (
        <CardMesa id="card-descricao" icone={FileText} titulo="Descrição" chip={<ChipSecao faltam={faltam} />} aberto={aberto} onAlternar={onAlternar}
            apoio="Texto simples, sem telefone, e-mail ou link.">
            <textarea value={texto} onChange={(e) => m.mudarRasc({ descricao: e.target.value })} disabled={m.disabled} rows={8}
                placeholder="Conte o que o produto é, do que é feito, medidas e o que vem na caixa."
                className={cn(CLASSE_INPUT, 'min-h-[160px] resize-y p-4 font-mono text-[13px] leading-relaxed disabled:opacity-50')} data-campo="descricao" />
            <p className={cn('mt-1 text-right font-mono text-[11px] tabular-nums', maximo && texto.length > maximo ? 'text-red-300' : 'text-white/40')} data-contador-descricao>
                {texto.length}{maximo ? `/${maximo}` : ''} caracteres
            </p>
        </CardMesa>
    );
}
