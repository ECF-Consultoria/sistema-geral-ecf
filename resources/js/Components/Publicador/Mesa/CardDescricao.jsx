import { CLASSE_INPUT } from '@/Components/Portal/Estrutura/comum';
import { estadoDasSecoes } from '../apoio';
import { ChipSecao, PainelDaEtapa } from './comum';
import { cn } from '@/lib/utils';

// ─── Etapa 6 — Descrição (check "Descrição") ────────────────────────────────
//
// Texto simples (RN-72): sem formatação, abas ou pré-visualização. O limite
// é validado pelo servidor; aqui só se mostra o tamanho. A caixa de texto
// tem largura de leitura (~860px); ao lado, o que vale a pena escrever e o que
// o Mercado Livre recusa — para a pessoa não ter de lembrar.

const O_QUE_ESCREVER = [
    'O que o produto é e para quem serve.',
    'Material, medidas, capacidade e voltagem, quando houver.',
    'O que vem na caixa.',
    'Garantia e cuidados de uso.',
];

export default function CardDescricao({ m, rodape = null }) {
    const texto = m.rasc.descricao ?? '';
    const problemas = m.problemasDaSecao('descricao');
    const faltam = estadoDasSecoes(problemas, m.schema).descricao.faltam;
    const maximo = m.schema?.limites?.max_description_length ?? null;

    return (
        <PainelDaEtapa id="etapa-descricao" titulo="Descrição" chip={<ChipSecao faltam={faltam} />} problemas={problemas} rodape={rodape}
            apoio="Texto simples, sem telefone, e-mail ou link.">
            <div className="grid gap-6 lg:grid-cols-[minmax(0,860px)_minmax(240px,1fr)]">
                <div>
                    <textarea value={texto} onChange={(e) => m.mudarRasc({ descricao: e.target.value })} disabled={m.disabled} rows={14} aria-label="Descrição do anúncio"
                        placeholder="Conte o que o produto é, do que é feito, medidas e o que vem na caixa."
                        className={cn(CLASSE_INPUT, 'min-h-[280px] resize-y p-4 text-[13px] leading-relaxed disabled:opacity-50')} data-campo="descricao" />
                    <p className={cn('mt-1 text-right font-mono text-[11px] tabular-nums', maximo && texto.length > maximo ? 'text-red-300' : 'text-white/40')} data-contador-descricao>
                        {texto.length}{maximo ? `/${maximo}` : ''} caracteres
                    </p>
                </div>

                <aside className="self-start rounded-[10px] border border-white/[0.08] bg-white/[0.02] p-4 text-[13px] text-white/55" aria-label="O que escrever na descrição">
                    <p className="font-bold text-white/80">O que vale a pena escrever</p>
                    <ul className="mt-2 space-y-1.5">
                        {O_QUE_ESCREVER.map((item) => (
                            <li key={item} className="flex items-start gap-2">
                                <span className="mt-[7px] h-1 w-1 shrink-0 rounded-full bg-white/40" aria-hidden="true" />
                                <span>{item}</span>
                            </li>
                        ))}
                    </ul>
                    <p className="mt-4 font-bold text-white/80">O Mercado Livre recusa</p>
                    <p className="mt-1">Telefone, e-mail, link, redes sociais e preço no texto.</p>
                </aside>
            </div>
        </PainelDaEtapa>
    );
}
