import { useEffect, useRef, useState } from 'react';
import useIdentidadeDaConta from '../useIdentidadeDaConta';
import { AREA, LINK } from './comum';

// ─── Identidade visual da conta (Fase 170, D2, IDENT-01/04) ─────────────────
//
// Montado UMA VEZ, no topo da etapa Imagens (EtapaImagens.jsx), ACIMA de
// "Fotos e variações" — nunca dentro de PainelCriativos.jsx, que remonta uma
// instância por grupo de fotos (o operador veria o campo duplicado). Pedido
// do usuário: "pode ser apenas um texto mesmo" — um `<textarea>` só, sem
// seletor de cor nem dropdown de fonte. Sem logo (D3 fora desta fase).

/**
 * `textoSeguro` (mesma defesa de `PainelCriativos.jsx`, REND-02): o campo pode
 * chegar do servidor em formato inesperado (ex.: objeto) — nunca derruba a
 * tela, vira vazio em vez do valor cru.
 */
const textoSeguro = (v) => (typeof v === 'string' ? v : '');

/**
 * Parte pura de apresentação — separada do hook para permitir teste de
 * render com o JSON real do controller (`tests/js/publicador-identidade-render.test.js`).
 */
export function CampoIdentidade({ texto, carregando, salvando, erro, onSalvar }) {
    const [rascunho, setRascunho] = useState(() => textoSeguro(texto));
    // Guarda se já aplicamos o texto carregado do servidor — sem isto, um
    // re-render depois que o operador já começou a digitar (ex.: erro mudou)
    // reaplicaria `texto` e apagaria o que ele estava escrevendo.
    const jaCarregouRef = useRef(! carregando);

    useEffect(() => {
        if (! carregando && ! jaCarregouRef.current) {
            jaCarregouRef.current = true;
            setRascunho(textoSeguro(texto));
        }
    }, [carregando, texto]);

    return (
        <div className="rounded-xl border border-white/[0.08] bg-ecf-card p-4">
            <h3 className="text-[13px] font-bold text-white/90">Identidade visual da conta</h3>
            <p className="mt-1 text-[11px] font-normal text-white/50">
                Cadastrada uma vez por conta — vale para todos os produtos dela, não só este. Opcional: sem isto, a
                geração de imagens continua normal. Pode ser só um texto, por exemplo as cores de preferência em
                hexadecimal e a fonte usada pela empresa.
            </p>
            {carregando ? (
                <div className="mt-3 h-20 w-full animate-pulse rounded-lg bg-white/[0.04]" />
            ) : (
                <>
                    <textarea
                        className={`${AREA} mt-3`}
                        value={rascunho}
                        onChange={(e) => setRascunho(e.target.value)}
                        maxLength={4000}
                        placeholder="Ex.: cor principal #0A2342, cor secundária #FFC107, fonte Montserrat, acabamento fosco."
                    />
                    <div className="mt-2 flex items-center justify-between gap-3">
                        {erro ? <p className="text-[11px] font-normal text-red-300">{erro}</p> : <span />}
                        <button type="button" className={LINK} onClick={() => onSalvar(rascunho)} disabled={salvando}>
                            {salvando ? 'Salvando…' : 'Salvar'}
                        </button>
                    </div>
                </>
            )}
        </div>
    );
}

export default function IdentidadeDaConta({ produtoId }) {
    const { texto, carregando, salvando, erro, salvar } = useIdentidadeDaConta({ produtoId });

    return <CampoIdentidade texto={texto} carregando={carregando} salvando={salvando} erro={erro} onSalvar={salvar} />;
}
