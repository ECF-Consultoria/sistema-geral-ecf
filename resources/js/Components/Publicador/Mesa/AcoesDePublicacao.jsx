import { Loader2, Lock, RefreshCw, Rocket } from 'lucide-react';
import { BotaoAcao } from './botoes';

// ─── Conferir e Publicar (UI-SPEC §8.1, movidos para a etapa de revisão) ────
//
// Até 03/10/2026 moravam na barra; agora são a ação da etapa "Revisar e
// publicar". Só UM deles é primário por vez: Conferir enquanto a conferência do
// Mercado Livre não aprovou; Publicar quando aprovou (com ou sem avisos).
// D26: em conta não liberada a conferência é só local ("Conferir dados") e o
// Publicar fica com cadeado, descrito pela nota `nota-conta-travada`.

export const TITLE_CONTA_TRAVADA = 'A publicação é liberada conta a conta. Peça ao time de desenvolvimento.';
export const TITLE_CONFERIR_LOCAL = 'Nesta conta a conferência é só local: a validação no Mercado Livre espera a liberação da conta.';

/** Rótulo do publicar conforme o estado: andamento, parcial anterior ou N anúncios. */
export function rotuloDoPublicar(pub) {
    if (pub.aguardando?.tipo === 'publicacao' || pub.publicacao?.status === 'RUNNING') return 'Publicando…';
    if (pub.m.estado?.rascunho?.status === 'PARTIALLY_PUBLISHED') return 'Publicar o que faltou';
    const n = pub.totalAnuncios;

    return n === 1 ? 'Publicar 1 anúncio' : `Publicar ${n} anúncios`;
}

/** O Publicar é o próximo passo quando a conferência do Mercado Livre aprovou (OK ou com avisos). */
export const publicarEhOProximoPasso = (pub) => ! pub.conferencia.local && ['ok', 'avisos'].includes(pub.conferencia.estado);

export function BotaoConferir({ pub, primario, className }) {
    const conferindo = pub.aguardando?.tipo === 'conferencia';

    return (
        <BotaoAcao
            primario={primario}
            onClick={pub.conferir}
            disabled={! pub.podeConferir}
            title={pub.liberada ? undefined : TITLE_CONFERIR_LOCAL}
            aria-describedby={pub.liberada ? undefined : 'nota-conta-travada'}
            data-acao="conferir"
            className={className}
        >
            {conferindo ? <Loader2 size={16} className="animate-spin" aria-hidden="true" /> : <RefreshCw size={16} aria-hidden="true" />}
            {conferindo ? 'Conferindo…' : (pub.liberada ? 'Conferir no Mercado Livre' : 'Conferir dados')}
        </BotaoAcao>
    );
}

export function BotaoPublicar({ pub, primario, className }) {
    const andamento = pub.aguardando?.tipo === 'publicacao' || pub.publicacao?.status === 'RUNNING';
    const Icone = andamento ? Loader2 : (pub.liberada ? Rocket : Lock);

    return (
        <BotaoAcao
            primario={primario}
            onClick={pub.publicar}
            disabled={! pub.podePublicar}
            title={pub.liberada ? undefined : TITLE_CONTA_TRAVADA}
            aria-describedby={pub.liberada ? undefined : 'nota-conta-travada'}
            data-acao="publicar"
            className={className}
        >
            <Icone size={16} className={andamento ? 'animate-spin' : undefined} aria-hidden="true" />
            {rotuloDoPublicar(pub)}
        </BotaoAcao>
    );
}
