import { useEffect } from 'react';
import { AlertCircle, CheckCircle2, X } from 'lucide-react';
import { cn } from '@/lib/utils';

// ─── Aviso fixo da tela de sugestões ────────────────────────────────────────
//
// Irmão do `AvisoFlash` (mesmo desenho: fixo embaixo, some em 6 s, X para fechar), com
// uma ação opcional: `{ rotulo, onClick }` (Desfazer) ou `{ rotulo, href }` (Ver na Lista
// SKUs). O erro (falha de rede) usa `role="alert"` e não some sozinho.

export default function AvisoSugestoes({ aviso, onFechar }) {
    useEffect(() => {
        if (! aviso || aviso.erro) return undefined;
        const t = setTimeout(onFechar, 6000);

        return () => clearTimeout(t);
    }, [aviso]); // eslint-disable-line react-hooks/exhaustive-deps

    if (! aviso) return null;
    const { erro = false, texto, acao = null } = aviso;
    const Icone = erro ? AlertCircle : CheckCircle2;
    const classeAcao = 'ml-1 font-semibold underline underline-offset-2 hover:text-white';

    const classe = cn('fixed bottom-24 left-1/2 z-[60] flex max-w-[92vw] -translate-x-1/2 items-start gap-2 rounded-xl border px-4 py-3 text-[13px] shadow-2xl',
        erro ? 'border-red-500/30 bg-[#1a0f0f] text-red-200' : 'border-emerald-500/30 bg-[#0f1a14] text-emerald-200');
    const conteudo = (
        <>
            <Icone size={16} className="mt-0.5 shrink-0" aria-hidden="true" />
            <span>
                {texto}
                {acao?.href && <a href={acao.href} className={classeAcao}>{acao.rotulo}</a>}
                {acao?.onClick && <button type="button" onClick={acao.onClick} className={classeAcao}>{acao.rotulo}</button>}
            </span>
            <button type="button" onClick={onFechar} className="ml-2 opacity-60 hover:opacity-100" aria-label="Fechar aviso"><X size={14} /></button>
        </>
    );

    // Erro (falha de rede) é alerta; o resto é status, como o AvisoFlash.
    return erro
        ? <div role="alert" data-aviso-sugestoes className={classe}>{conteudo}</div>
        : <div role="status" data-aviso-sugestoes className={classe}>{conteudo}</div>;
}
