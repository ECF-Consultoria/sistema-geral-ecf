import AppLayout from '@/Layouts/AppLayout';
import { router, useForm, usePage } from '@inertiajs/react';
import { useState, useEffect, useMemo, useRef } from 'react';
import {
    Search, Plus, QrCode, Link2, X, Loader2, Camera, ChevronDown, ChevronRight, ShieldCheck,
} from 'lucide-react';
import jsQR from 'jsqr';
import { cn } from '@/lib/utils';
import { StatusBadge, ServicoIcone, Avatar, STATUS_LABELS, antesDoArroba } from '@/Components/Autenticadores/common';

// ─── Página: busca + lista ──────────────────────────────────────────────────
// O código de cada conta fica na própria página dela (Show). Aqui o pessoal
// pesquisa e abre a que precisa.

export default function Index({ autenticadores = [], filtros = {}, servicos = [] }) {
    const { csrf_token } = usePage().props;

    const [q, setQ] = useState(filtros.q || '');
    const [fServico, setFServico] = useState(filtros.servico || '');
    const [fStatus, setFStatus] = useState(filtros.status || '');

    // Busca client-side: cliente, parte antes do @, número no domínio e serviço.
    const lista = useMemo(() => {
        const termo = q.trim().toLowerCase();
        return autenticadores.filter((a) => {
            if (fServico && a.servico !== fServico) return false;
            if (fStatus && a.status !== fStatus) return false;
            if (!termo) return true;
            return [a.cliente, a.conta, a.servico, a.issuer, antesDoArroba(a.conta)]
                .filter(Boolean)
                .some((v) => String(v).toLowerCase().includes(termo));
        });
    }, [autenticadores, q, fServico, fStatus]);

    const [addAberto, setAddAberto] = useState(false);

    const abrir = (id) => router.visit(route('autenticadores.show', id));

    return (
        <AppLayout title="Autenticadores 2FA">
            <div className="max-w-5xl mx-auto space-y-6">
                {/* Cabeçalho */}
                <div className="flex items-start justify-between gap-4 flex-wrap">
                    <div>
                        <h1 className="text-2xl font-bold text-white flex items-center gap-2">
                            <ShieldCheck size={24} className="text-ecf-yellow" />
                            Autenticadores 2FA
                        </h1>
                        <p className="text-white/40 text-sm mt-1">Pesquise a conta e abra para ver o código.</p>
                    </div>
                    <button
                        onClick={() => setAddAberto((v) => !v)}
                        className="inline-flex items-center gap-2 px-4 py-2.5 rounded-lg bg-ecf-yellow text-black font-semibold text-sm hover:brightness-105 transition"
                    >
                        <Plus size={18} /> Adicionar autenticador
                    </button>
                </div>

                {/* Busca + filtros */}
                <div className="flex gap-3 flex-wrap">
                    <div className="relative flex-1 min-w-[240px]">
                        <Search size={18} className="absolute left-3 top-1/2 -translate-y-1/2 text-white/30" />
                        <input
                            value={q}
                            onChange={(e) => setQ(e.target.value)}
                            autoFocus
                            placeholder="Pesquisar cliente, e-mail, domínio ou serviço…"
                            className="w-full pl-10 pr-3 py-2.5 rounded-lg bg-ecf-card border border-white/[0.08] text-white text-sm placeholder:text-white/30 focus:outline-none focus:border-ecf-yellow/40"
                        />
                    </div>
                    <FiltroSelect value={fServico} onChange={setFServico} placeholder="Serviço" options={servicos.map((s) => ({ value: s, label: s }))} />
                    <FiltroSelect value={fStatus} onChange={setFStatus} placeholder="Status" options={Object.entries(STATUS_LABELS).map(([value, label]) => ({ value, label }))} />
                </div>

                {addAberto && (
                    <NovoAutenticador csrf={csrf_token} onClose={() => setAddAberto(false)} />
                )}

                {/* Lista */}
                <div className="rounded-xl border border-white/[0.08] bg-ecf-card overflow-hidden">
                    <div className="flex items-center gap-2 px-5 py-4 border-b border-white/[0.06]">
                        <h2 className="text-white font-semibold">Contas</h2>
                        <span className="text-[11px] text-white/40 bg-white/5 rounded-full px-2 py-0.5">{lista.length}</span>
                    </div>

                    {lista.length === 0 ? (
                        <div className="px-5 py-12 text-center text-white/40 text-sm">
                            {autenticadores.length === 0 ? 'Nenhuma conta cadastrada ainda.' : 'Nenhuma conta encontrada para a busca.'}
                        </div>
                    ) : (
                        <ul>
                            {lista.map((a) => (
                                <li key={a.id}>
                                    <button
                                        type="button"
                                        onClick={() => abrir(a.id)}
                                        className="w-full flex items-center gap-4 px-5 py-3 text-left border-t border-white/[0.05] hover:bg-white/[0.03] transition group"
                                    >
                                        <Avatar texto={a.cliente} />
                                        <div className="min-w-0 flex-1">
                                            <p className="text-white font-medium truncate">{a.cliente}</p>
                                            <p className="text-white/50 text-[13px] truncate">{a.conta}</p>
                                        </div>
                                        <span className="hidden sm:inline-flex items-center gap-2 text-white/70 text-sm w-40 shrink-0">
                                            <ServicoIcone servico={a.servico} /> <span className="truncate">{a.servico}</span>
                                        </span>
                                        <span className="hidden md:block shrink-0"><StatusBadge status={a.status} /></span>
                                        <ChevronRight size={18} className="text-white/25 group-hover:text-ecf-yellow shrink-0 transition" />
                                    </button>
                                </li>
                            ))}
                        </ul>
                    )}
                </div>
            </div>
        </AppLayout>
    );
}

// ─── Novo autenticador ──────────────────────────────────────────────────────

// Lê um QR Code de um arquivo de imagem (jsQR). Devolve o texto ou null.
async function decodeQrDaImagem(file) {
    const url = URL.createObjectURL(file);
    try {
        const img = await new Promise((resolve, reject) => {
            const el = new Image();
            el.onload = () => resolve(el);
            el.onerror = reject;
            el.src = url;
        });
        const canvas = document.createElement('canvas');
        canvas.width = img.naturalWidth;
        canvas.height = img.naturalHeight;
        const ctx = canvas.getContext('2d', { willReadFrequently: true });
        ctx.drawImage(img, 0, 0);
        const data = ctx.getImageData(0, 0, canvas.width, canvas.height);
        return jsQR(data.data, canvas.width, canvas.height)?.data ?? null;
    } finally {
        URL.revokeObjectURL(url);
    }
}

// Câmera ao vivo lendo QR Code (jsQR sobre os frames do vídeo). Exige HTTPS —
// produção é https, então funciona no celular e no desktop.
function ScannerQr({ onDetectar, onFechar }) {
    const videoRef = useRef(null);
    const streamRef = useRef(null);
    const rafRef = useRef(null);
    const cbRef = useRef(onDetectar);
    const [erro, setErro] = useState('');

    useEffect(() => { cbRef.current = onDetectar; });

    useEffect(() => {
        let cancelado = false;
        const canvas = document.createElement('canvas');

        const tick = () => {
            const video = videoRef.current;
            if (!video) return;
            if (video.readyState >= 2 && video.videoWidth) {
                canvas.width = video.videoWidth;
                canvas.height = video.videoHeight;
                const ctx = canvas.getContext('2d', { willReadFrequently: true });
                ctx.drawImage(video, 0, 0, canvas.width, canvas.height);
                const img = ctx.getImageData(0, 0, canvas.width, canvas.height);
                const code = jsQR(img.data, canvas.width, canvas.height, { inversionAttempts: 'dontInvert' });
                if (code?.data && /^otpauth(-migration)?:/i.test(code.data)) {
                    cbRef.current(code.data);
                    return; // achou — para o loop
                }
            }
            rafRef.current = requestAnimationFrame(tick);
        };

        (async () => {
            try {
                const stream = await navigator.mediaDevices.getUserMedia({
                    video: { facingMode: { ideal: 'environment' } },
                    audio: false,
                });
                if (cancelado) { stream.getTracks().forEach((t) => t.stop()); return; }
                streamRef.current = stream;
                const video = videoRef.current;
                video.srcObject = stream;
                video.muted = true;
                video.playsInline = true;
                await video.play();
                rafRef.current = requestAnimationFrame(tick);
            } catch (e) {
                if (cancelado) return;
                setErro(e?.name === 'NotAllowedError'
                    ? 'Permissão de câmera negada — libere a câmera no navegador e tente de novo.'
                    : 'Não foi possível abrir a câmera. Use "Escolher imagem" ou cole a URI.');
            }
        })();

        return () => {
            cancelado = true;
            cancelAnimationFrame(rafRef.current);
            if (streamRef.current) streamRef.current.getTracks().forEach((t) => t.stop());
        };
    }, []);

    return (
        <div className="space-y-2">
            <div className="relative rounded-lg overflow-hidden border border-white/[0.12] bg-black" style={{ aspectRatio: '4 / 3' }}>
                <video ref={videoRef} className="w-full h-full object-cover" muted playsInline />
                {!erro && <div className="absolute inset-6 border-2 border-ecf-yellow/50 rounded-lg pointer-events-none" />}
            </div>
            {erro
                ? <p className="text-red-300 text-[12px]">{erro}</p>
                : <p className="text-white/40 text-[12px]">Aponte a câmera para o QR Code do autenticador.</p>}
            <button type="button" onClick={onFechar} className="text-white/60 text-[13px] hover:text-white/90">Fechar câmera</button>
        </div>
    );
}

function NovoAutenticador({ csrf, onClose }) {
    const [modo, setModo] = useState('uri'); // 'uri' | 'qr'
    const [qrMsg, setQrMsg] = useState(null);
    const [cameraAberta, setCameraAberta] = useState(false);

    const form = useForm({ uri: '', cliente: '', conta: '', servico: '', secret: '' });

    const temUri = form.data.uri.trim() !== '';

    const submeter = (e) => {
        e.preventDefault();
        form.post(route('autenticadores.store'), {
            preserveScroll: true,
            onSuccess: () => { form.reset(); onClose(); },
        });
    };

    // Aplica o conteúdo de um QR lido (câmera ou imagem). Só aceita otpauth://.
    const aplicarQr = (data) => {
        if (!/^otpauth(-migration)?:/i.test(data || '')) {
            setQrMsg('QR lido, mas não é um autenticador (otpauth://).');
            return;
        }
        setCameraAberta(false);
        form.setData('uri', data);
        setModo('uri');
        setQrMsg('QR lido. Confira e clique em Adicionar.');
    };

    const lerImagem = async (file) => {
        setQrMsg('Lendo imagem…');
        try {
            const data = await decodeQrDaImagem(file);
            if (!data) { setQrMsg('Nenhum QR Code encontrado na imagem.'); return; }
            aplicarQr(data);
        } catch {
            setQrMsg('Não foi possível ler a imagem.');
        }
    };

    const onDrop = (e) => {
        e.preventDefault();
        const file = e.dataTransfer.files?.[0];
        if (file) lerImagem(file);
    };

    return (
        <div className="rounded-xl border border-white/[0.08] bg-ecf-card p-5">
            <div className="flex items-center justify-between mb-1">
                <h2 className="text-white font-semibold">Novo autenticador</h2>
                <button onClick={onClose} className="text-white/40 hover:text-white/70"><X size={18} /></button>
            </div>
            <p className="text-white/40 text-[13px] mb-4">Importe por QR Code, cole a URI (uma ou várias contas) ou informe os dados manualmente.</p>

            {form.errors.uri && <p className="text-red-300 text-[13px] mb-3">{form.errors.uri}</p>}

            <form onSubmit={submeter} className="grid grid-cols-1 md:grid-cols-2 gap-5">
                {/* Coluna esquerda: QR / URI */}
                <div>
                    <div className="inline-flex rounded-lg border border-white/[0.1] p-1 mb-3">
                        <TabBtn ativo={modo === 'qr'} onClick={() => setModo('qr')} icon={QrCode}>Ler QR Code</TabBtn>
                        <TabBtn ativo={modo === 'uri'} onClick={() => setModo('uri')} icon={Link2}>Colar URI</TabBtn>
                    </div>

                    {modo === 'qr' ? (
                        <div className="space-y-3">
                            {cameraAberta ? (
                                <ScannerQr onDetectar={aplicarQr} onFechar={() => setCameraAberta(false)} />
                            ) : (
                                <button
                                    type="button"
                                    onClick={() => { setQrMsg(null); setCameraAberta(true); }}
                                    className="w-full inline-flex items-center justify-center gap-2 px-4 py-3 rounded-lg bg-ecf-yellow/[0.12] border border-ecf-yellow/25 text-ecf-yellow font-medium text-sm hover:bg-ecf-yellow/[0.18] transition"
                                >
                                    <Camera size={18} /> Escanear com a câmera
                                </button>
                            )}
                            <div
                                onDragOver={(e) => e.preventDefault()}
                                onDrop={onDrop}
                                className="rounded-lg border-2 border-dashed border-white/[0.12] p-5 text-center"
                            >
                                <QrCode size={28} className="mx-auto text-white/25 mb-1.5" />
                                <label className="text-ecf-yellow text-sm font-medium cursor-pointer hover:underline">
                                    Escolher imagem do QR Code
                                    <input type="file" accept="image/*" className="hidden" onChange={(e) => e.target.files?.[0] && lerImagem(e.target.files[0])} />
                                </label>
                                <p className="text-white/30 text-[12px] mt-1">ou arraste uma imagem aqui (JPG, PNG, WEBP)</p>
                            </div>
                            {qrMsg && <p className="text-white/60 text-[12px]">{qrMsg}</p>}
                        </div>
                    ) : (
                        <>
                            <textarea
                                value={form.data.uri}
                                onChange={(e) => form.setData('uri', e.target.value)}
                                rows={5}
                                spellCheck={false}
                                placeholder="otpauth://totp/…  ou  otpauth-migration://offline?data=…"
                                className="w-full px-3 py-2.5 rounded-lg bg-white/[0.03] border border-white/[0.1] text-white text-[13px] font-mono placeholder:text-white/25 focus:outline-none focus:border-ecf-yellow/40 resize-none"
                                style={{ WebkitTextSecurity: temUri ? 'disc' : 'none' }}
                            />
                            <p className="text-white/30 text-[12px] mt-1">A URI de "Transferir contas" traz várias contas de uma vez. O secret vai cifrado e não reaparece.</p>
                        </>
                    )}
                </div>

                {/* Coluna direita: manual */}
                <div className={cn('space-y-3', temUri && 'opacity-40 pointer-events-none')}>
                    <div className="text-white/30 text-[12px] uppercase tracking-wide">ou preencha manualmente</div>
                    <Campo rotulo="Cliente / loja" valor={form.data.cliente} onChange={(v) => form.setData('cliente', v)} placeholder="Ex.: Loja Prime" />
                    <Campo rotulo="E-mail ou identificação" valor={form.data.conta} onChange={(v) => form.setData('conta', v)} placeholder="financeiro@loja.com" />
                    <Campo rotulo="Serviço" valor={form.data.servico} onChange={(v) => form.setData('servico', v)} placeholder="Google, Amazon, Mercado Livre…" />
                    <div>
                        <label className="text-white/50 text-[12px] font-medium">Secret TOTP</label>
                        <input
                            value={form.data.secret}
                            onChange={(e) => form.setData('secret', e.target.value)}
                            spellCheck={false}
                            placeholder="abcd efgh ijkl mnop …"
                            className="w-full mt-1 px-3 py-2 rounded-lg bg-white/[0.03] border border-white/[0.1] text-white text-sm font-mono placeholder:text-white/25 focus:outline-none focus:border-ecf-yellow/40"
                            style={{ WebkitTextSecurity: form.data.secret ? 'disc' : 'none' }}
                        />
                    </div>
                </div>

                {/* Submit */}
                <div className="md:col-span-2 flex justify-end pt-1 border-t border-white/[0.06]">
                    <button
                        type="submit"
                        disabled={form.processing}
                        className="inline-flex items-center gap-2 px-5 py-2.5 rounded-lg bg-ecf-yellow text-black font-semibold text-sm hover:brightness-105 transition disabled:opacity-50"
                    >
                        {form.processing ? <Loader2 size={16} className="animate-spin" /> : <Plus size={16} />} Adicionar autenticador
                    </button>
                </div>
            </form>
        </div>
    );
}

function TabBtn({ ativo, onClick, icon: Icon, children }) {
    return (
        <button
            type="button"
            onClick={onClick}
            className={cn('inline-flex items-center gap-1.5 px-3 py-1.5 rounded-md text-[13px] font-medium transition', ativo ? 'bg-white/10 text-white' : 'text-white/50 hover:text-white/80')}
        >
            <Icon size={15} /> {children}
        </button>
    );
}

function Campo({ rotulo, valor, onChange, placeholder }) {
    return (
        <div>
            <label className="text-white/50 text-[12px] font-medium">{rotulo}</label>
            <input
                value={valor}
                onChange={(e) => onChange(e.target.value)}
                placeholder={placeholder}
                className="w-full mt-1 px-3 py-2 rounded-lg bg-white/[0.03] border border-white/[0.1] text-white text-sm placeholder:text-white/25 focus:outline-none focus:border-ecf-yellow/40"
            />
        </div>
    );
}

function FiltroSelect({ value, onChange, placeholder, options }) {
    return (
        <div className="relative">
            <select
                value={value}
                onChange={(e) => onChange(e.target.value)}
                className="appearance-none pl-3 pr-9 py-2.5 rounded-lg bg-ecf-card border border-white/[0.08] text-white text-sm focus:outline-none focus:border-ecf-yellow/40 min-w-[150px]"
            >
                <option value="" className="bg-ecf-card">{placeholder}: todos</option>
                {options.map((o) => <option key={o.value} value={o.value} className="bg-ecf-card">{o.label}</option>)}
            </select>
            <ChevronDown size={16} className="absolute right-2.5 top-1/2 -translate-y-1/2 text-white/30 pointer-events-none" />
        </div>
    );
}
