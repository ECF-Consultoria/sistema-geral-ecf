import { useEffect, useState } from 'react';
import { paraNumero, paraTexto } from '../apoio';
import { textoDaPromocao } from '../promocaoAutomatica.js';
import { CAMPO, INVALIDO, LINK } from './comum';
import { cn } from '@/lib/utils';

// ─── Preço de uma variação num tipo de anúncio ──────────────────────────────
//
// Sem preço digitado, o campo MOSTRA o preço importado da Precificação do
// Portal (docx §4) — mas não o grava: o rascunho segue lendo a Precificação na
// hora de conferir e publicar, para o preço não congelar (`16` §1.6). Digitar
// outro valor sobrepõe; "usar o do Portal" volta a seguir a Precificação.
//
// Embaixo, a promoção que o anúncio ganha depois de publicar (10/10/2026): o
// mínimo do Portal, ou o mesmo desconto sobre o preço digitado, nunca abaixo do
// mínimo — a conta é a do servidor (`promocaoAutomatica.js` × `PrecoDaPromocao`).

export default function CampoPreco({ valor, efetivo, disabled, onMudar, chave, tipo, comPortal, rotulo, id, invalido = false, className, portal = null, promocaoAutomatica = null }) {
    const temValor = valor !== null && valor !== undefined;
    const temEfetivo = efetivo !== null && efetivo !== undefined;
    const [texto, setTexto] = useState(paraTexto(temValor ? valor : efetivo));
    useEffect(() => setTexto(paraTexto(temValor ? valor : efetivo)), [valor, efetivo]); // eslint-disable-line react-hooks/exhaustive-deps
    const falta = ! temValor && ! temEfetivo;
    const doPortal = ! temValor && temEfetivo;
    // O que está no campo agora (enquanto digita), senão o gravado, senão o do Portal.
    const precoAgora = paraNumero(texto) ?? (temValor ? Number(valor) : (temEfetivo ? Number(efetivo) : null));
    const promocao = disabled ? null : textoDaPromocao(precoAgora, portal, promocaoAutomatica);

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
                <span className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-[15px] text-white/45">R$</span>
                <input id={id} value={texto} onChange={(e) => setTexto(e.target.value)} onBlur={sair} disabled={disabled} inputMode="decimal" placeholder="0,00" aria-label={rotulo}
                    aria-invalid={invalido || undefined}
                    className={cn(CAMPO, 'pl-10 tabular-nums', doPortal && 'pr-24', invalido && INVALIDO)}
                    data-preco={`${chave}|${tipo}`} data-preco-origem={doPortal ? 'portal' : (temValor ? 'digitado' : 'vazio')} />
                {doPortal && (
                    <span className="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 rounded bg-emerald-500/10 px-1.5 py-px text-[11px] font-bold text-emerald-400">do Portal</span>
                )}
            </div>
            {temValor && temEfetivo && Number(valor) !== Number(efetivo) && ! disabled && (
                <button type="button" onClick={() => onMudar(null)} data-preco-voltar={`${chave}|${tipo}`} className={cn(LINK, 'mt-1.5 font-normal')}>
                    Usar o do Portal ({paraTexto(efetivo)})
                </button>
            )}
            {falta && comPortal && ! disabled && <p className="mt-1.5 text-[13px] text-white/50">A Precificação do Portal não tem preço para esta oferta. Preencha lá ou digite aqui.</p>}
            {promocao && <p className="mt-1.5 text-[13px] text-white/50" data-promocao-automatica={`${chave}|${tipo}`}>{promocao}</p>}
        </div>
    );
}
