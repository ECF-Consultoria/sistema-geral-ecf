import { useState } from 'react';
import axios from 'axios';
import { router } from '@inertiajs/react';
import { Loader2 } from 'lucide-react';
import { Dialog, DialogContent, DialogDescription, DialogTitle } from '@/Components/ui/dialog';

const CAMPO = 'h-10 w-full rounded-lg border border-white/[0.08] bg-white/[0.04] px-3 text-[13px] font-normal text-white placeholder:text-white/40 focus:outline-none focus-visible:ring-2 focus-visible:ring-ecf-yellow';

/**
 * Cadastro manual de produto (D15). SKU repetido só avisa (não bloqueia);
 * no 201 vai direto para o editor (`json.url`).
 */
export default function ModalNovoProduto({ aberto, onFechar, conta, skusExistentes = [] }) {
    const [sku, setSku] = useState('');
    const [nome, setNome] = useState('');
    const [enviando, setEnviando] = useState(false);
    const [erros, setErros] = useState({});
    const [erroGeral, setErroGeral] = useState(null);

    const skuLimpo = sku.trim();
    const repetido = skuLimpo !== '' && skusExistentes.some((s) => String(s).trim().toLowerCase() === skuLimpo.toLowerCase());
    const pode = skuLimpo !== '' && nome.trim() !== '' && !enviando;

    function fechar() {
        if (enviando) return;
        setErros({});
        setErroGeral(null);
        onFechar?.();
    }

    async function criar(ev) {
        ev.preventDefault();
        if (!pode) return;
        setEnviando(true);
        setErros({});
        setErroGeral(null);
        try {
            const { data } = await axios.post(
                route('mlb.anuncios.publicador.produtos.criar', { conta }),
                { sku: skuLimpo, nome: nome.trim() },
            );
            router.get(data.url);
        } catch (e) {
            if (e.response?.status === 422) setErros(e.response.data?.errors ?? {});
            else setErroGeral('Não foi possível criar o produto. Tente de novo.');
            setEnviando(false);
        }
    }

    return (
        <Dialog open={aberto} onOpenChange={(v) => { if (!v) fechar(); }}>
            <DialogContent className="max-w-md rounded-2xl border-white/[0.08] bg-ecf-card p-6 shadow-none">
                <DialogTitle className="text-[15px] font-bold text-white">Novo produto</DialogTitle>
                <DialogDescription className="text-[13px] font-normal text-white/55">
                    Cadastre o produto aqui e siga direto para o editor.
                </DialogDescription>
                <form onSubmit={criar} className="space-y-4">
                    <label className="block">
                        <span className="mb-1 block text-[13px] font-normal text-white/70">SKU</span>
                        <input
                            type="text"
                            value={sku}
                            maxLength={120}
                            onChange={(e) => setSku(e.target.value)}
                            className={`${CAMPO} font-mono`}
                            autoFocus
                        />
                        {erros.sku && <span className="mt-1 block text-[13px] font-normal text-red-300">{erros.sku[0]}</span>}
                        {repetido && (
                            <span className="mt-1 block text-[13px] font-normal text-amber-300">
                                Já existe um produto com este SKU nesta empresa. Você pode criar mesmo assim.
                            </span>
                        )}
                    </label>
                    <label className="block">
                        <span className="mb-1 block text-[13px] font-normal text-white/70">Nome do produto</span>
                        <input
                            type="text"
                            value={nome}
                            maxLength={255}
                            onChange={(e) => setNome(e.target.value)}
                            className={CAMPO}
                        />
                        {erros.nome && <span className="mt-1 block text-[13px] font-normal text-red-300">{erros.nome[0]}</span>}
                    </label>
                    {erroGeral && <p className="text-[13px] font-normal text-red-300">{erroGeral}</p>}
                    <div className="flex justify-end gap-2">
                        <button
                            type="button"
                            onClick={fechar}
                            className="inline-flex h-10 items-center rounded-lg px-4 text-[13px] font-normal text-white/70 hover:bg-white/[0.06] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ecf-yellow"
                        >
                            Voltar
                        </button>
                        <button
                            type="submit"
                            disabled={!pode}
                            className="inline-flex h-10 items-center gap-2 rounded-lg border border-white/[0.10] bg-white/[0.03] px-4 text-[13px] font-normal text-white/80 hover:bg-white/[0.06] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ecf-yellow disabled:cursor-not-allowed disabled:opacity-40"
                        >
                            {enviando && <Loader2 className="h-4 w-4 animate-spin" aria-hidden="true" />}
                            Criar e abrir
                        </button>
                    </div>
                </form>
            </DialogContent>
        </Dialog>
    );
}
