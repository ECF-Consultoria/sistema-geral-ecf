import { useEffect, useState } from 'react';
import { CLASSE_INPUT } from '@/Components/Portal/Estrutura/comum';
import { paraNumero, paraTexto } from '../apoio';
import { cn } from '@/lib/utils';

// ─── Preço de uma variação num tipo de anúncio ──────────────────────────────
//
// O mesmo campo (e o mesmo estado, `m.variantes[].precos`) aparece no item da
// variação e na tabela "Preços e taxas". Sem preço digitado, o campo MOSTRA o
// preço importado da Precificação do Portal (docx §4) — mas não o grava: o
// rascunho segue lendo a Precificação na hora de conferir e publicar, para o
// preço não congelar (`16` §1.6). Digitar outro valor sobrepõe; "usar o do
// Portal" volta a seguir a Precificação.

export default function CampoPreco({ valor, efetivo, disabled, onMudar, chave, tipo, comPortal, rotulo, className }) {
    const temValor = valor !== null && valor !== undefined;
    const temEfetivo = efetivo !== null && efetivo !== undefined;
    const [texto, setTexto] = useState(paraTexto(temValor ? valor : efetivo));
    useEffect(() => setTexto(paraTexto(temValor ? valor : efetivo)), [valor, efetivo]); // eslint-disable-line react-hooks/exhaustive-deps
    const falta = ! temValor && ! temEfetivo;
    const doPortal = ! temValor && temEfetivo;

    const sair = () => {
        const n = paraNumero(texto);
        // Igual ao do Portal (ou apagado) = continua seguindo a Precificação.
        if (! temValor && (n === null || (temEfetivo && n === Number(efetivo)))) {
            setTexto(paraTexto(efetivo));

            return;
        }
        onMudar(n);
    };

    return (
        <div className={className}>
            <div className="relative">
                <span className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 font-mono text-[11px] font-bold text-white/40">R$</span>
                <input value={texto} onChange={(e) => setTexto(e.target.value)} onBlur={sair} disabled={disabled} inputMode="decimal" placeholder="0,00" aria-label={rotulo}
                    className={cn(CLASSE_INPUT, 'py-1.5 pl-9 font-mono text-[13px] tabular-nums disabled:opacity-60', doPortal && 'pr-24', falta && ! disabled && 'border-amber-400/50')}
                    data-preco={`${chave}|${tipo}`} data-preco-origem={doPortal ? 'portal' : (temValor ? 'digitado' : 'vazio')} />
                {doPortal && (
                    <span className="pointer-events-none absolute right-2 top-1/2 -translate-y-1/2 rounded bg-emerald-500/10 px-1.5 py-px text-[11px] font-bold text-emerald-400">do Portal</span>
                )}
            </div>
            {temValor && temEfetivo && Number(valor) !== Number(efetivo) && ! disabled && (
                <button type="button" onClick={() => onMudar(null)} data-preco-voltar={`${chave}|${tipo}`}
                    className="mt-1 text-[11px] text-white/55 underline underline-offset-2 hover:text-ecf-yellow focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ecf-yellow">
                    usar o do Portal ({paraTexto(efetivo)})
                </button>
            )}
            {falta && comPortal && <p className="mt-1 text-[11px] text-amber-300">A Precificação do Portal não tem preço para esta oferta. Preencha lá ou digite aqui.</p>}
        </div>
    );
}
