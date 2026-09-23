import { useEffect, useRef, useState } from 'react';
import axios from 'axios';
import { Check, Copy, ExternalLink, Globe, KeyRound, Loader2, Send } from 'lucide-react';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/Components/ui/dialog';

// ─── Compartilhar UM plano com o cliente ────────────────────────────────────
//
// A lista interna não tinha como mandar um PPA: o painel de compartilhamento
// vivia só no "quadro completo", que foi desligado. Este diálogo põe o link de
// cada plano a um clique da lista, nos dois escopos (carteira e Polos).
//
// O link vem pronto do servidor (`PpaListaService::compartilhamento()`), e a
// tela só decide o que MOSTRAR:
//  - `via: 'portal'` — PPA de empresa. Abre o Portal do Cliente neste plano e
//    pede login: o token de Company foi aposentado em 15/09/2026.
//  - `via: 'link'`   — PPA de Polos. Link do quadro, sem login. Se o token
//    ainda não existe, é gerado quando o diálogo abre.
//
// Rascunho não tem link — o portal o esconde. Em vez de um botão desabilitado
// sem explicação, o diálogo oferece o passo que falta: marcar como enviado.

export default function CompartilharPpa({ plano, aberto, onFechar, rotaGerar, onMarcarEnviado }) {
    const info = plano?.compartilhar ?? { disponivel: false, via: 'link', url: null };

    // O link gerado agora vive aqui até a lista recarregar — os props da
    // página ainda são os de antes do clique.
    const [gerado, setGerado] = useState(null);
    const [gerando, setGerando] = useState(false);
    const [erro, setErro] = useState(false);
    const [copiado, setCopiado] = useState(false);
    const campo = useRef(null);

    const url = info.url ?? gerado;

    useEffect(() => {
        if (!aberto) {
            setGerado(null);
            setErro(false);
            setCopiado(false);
            return;
        }
        if (!info.disponivel || info.url || !rotaGerar) return;

        setGerando(true);
        axios.post(route(rotaGerar, plano.id), {}, { headers: { Accept: 'application/json' } })
            .then(({ data }) => setGerado(data.url))
            .catch((e) => {
                console.error('[PPA] falha ao gerar link', e);
                setErro(true);
            })
            .finally(() => setGerando(false));
    }, [aberto, plano?.id, info.disponivel, info.url]);

    const copiar = async () => {
        if (!url) return;

        try {
            await navigator.clipboard.writeText(url);
        } catch {
            // Sem a API do clipboard (contexto sem HTTPS, navegador antigo):
            // seleciona o campo e usa o caminho antigo.
            campo.current?.select();
            document.execCommand?.('copy');
        }
        setCopiado(true);
        setTimeout(() => setCopiado(false), 2000);
    };

    const ehPortal = info.via === 'portal';

    return (
        <Dialog open={aberto} onOpenChange={(v) => !v && onFechar()}>
            <DialogContent className="max-w-lg">
                <DialogHeader>
                    <DialogTitle>Compartilhar PPA</DialogTitle>
                    <DialogDescription className="truncate">
                        {plano?.titulo}{plano?.empresa ? ` · ${plano.empresa}` : ''}
                    </DialogDescription>
                </DialogHeader>

                {!info.disponivel ? (
                    <div className="space-y-4">
                        <p className="text-white/60 text-[13px] leading-relaxed">
                            Este plano está em <span className="text-white font-medium">Rascunho</span> — o
                            cliente não o vê. Para gerar o link, marque-o como enviado.
                        </p>
                        <div className="flex justify-end gap-2">
                            <Button variant="outline" onClick={onFechar}>Cancelar</Button>
                            <Button onClick={() => onMarcarEnviado(plano)}>
                                <Send className="h-4 w-4 mr-1.5" /> Marcar como enviado
                            </Button>
                        </div>
                    </div>
                ) : (
                    <div className="space-y-4">
                        <div className="flex items-start gap-2.5 rounded-xl bg-white/[0.03] ring-1 ring-inset ring-white/[0.06] px-3.5 py-3">
                            {ehPortal
                                ? <KeyRound className="h-4 w-4 text-ecf-yellow mt-0.5 shrink-0" />
                                : <Globe className="h-4 w-4 text-emerald-300 mt-0.5 shrink-0" />}
                            <p className="text-white/55 text-[12.5px] leading-relaxed">
                                {ehPortal
                                    ? 'Abre este plano no Portal do Cliente. O cliente entra com o acesso dele ao portal e cai direto aqui.'
                                    : 'Abre o quadro deste plano sem login — quem tiver o link vê e move as tarefas.'}
                            </p>
                        </div>

                        <div className="flex items-center gap-2">
                            <Input
                                ref={campo}
                                readOnly
                                value={url ?? (gerando ? 'Gerando link…' : '')}
                                onFocus={(e) => e.target.select()}
                                className="h-9 text-[12.5px] font-mono"
                            />
                            <Button onClick={copiar} disabled={!url} className="shrink-0">
                                {gerando
                                    ? <Loader2 className="h-4 w-4 animate-spin" />
                                    : copiado
                                        ? <><Check className="h-4 w-4 mr-1.5" /> Copiado</>
                                        : <><Copy className="h-4 w-4 mr-1.5" /> Copiar</>}
                            </Button>
                        </div>

                        {erro && (
                            <p className="text-rose-300 text-[12px]">
                                Não foi possível gerar o link. Tente de novo em instantes.
                            </p>
                        )}

                        {url && (
                            <a
                                href={url}
                                target="_blank"
                                rel="noreferrer"
                                className="inline-flex items-center gap-1.5 text-[12px] text-white/40 hover:text-white transition-colors"
                            >
                                <ExternalLink className="h-3.5 w-3.5" /> Abrir o link
                            </a>
                        )}
                    </div>
                )}
            </DialogContent>
        </Dialog>
    );
}
