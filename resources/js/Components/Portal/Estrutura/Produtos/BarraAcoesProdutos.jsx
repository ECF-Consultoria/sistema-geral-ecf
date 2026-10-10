import { Link } from '@inertiajs/react';
import { Download, FileSpreadsheet, Folder, Images, Lightbulb, Loader2, Plus, Search, Sparkles, Upload, X } from 'lucide-react';
import { cn } from '@/lib/utils';

// ─── Produtos: linha de ações + busca (167-20, D-25) ────────────────────────
//
// A linha da REF-1/REF-3: as ações com o nome do que fazem (D-24: a planilha
// só existe como arquivo — baixar o modelo e importar; desde 09/10/2026, com
// produtos, também baixar os produtos já preenchidos) e a busca à direita.
// "Adicionar produto" só é amarelo quando já há produtos: sem produtos o
// amarelo é o "Cadastrar o primeiro produto" do estado vazio (um amarelo por
// vista). Só apresentação: nada é calculado aqui.

const ACAO = 'inline-flex h-12 items-center gap-3 rounded-[10px] px-5 text-[15px] transition-colors disabled:pointer-events-none disabled:opacity-40';
const SECUNDARIA = 'border border-white/[0.10] bg-white/[0.03] font-medium text-white/85 hover:bg-white/[0.07] hover:text-white';

export default function BarraAcoesProdutos({ temProdutos, busca, onBusca, onAdicionar, onListas, onImportar, onFotosEmLote, onSugerir, sugerindo = false, podeSugerir = false }) {
    return (
        <div className="flex flex-col gap-3 lg:flex-row lg:items-center" data-barra-acoes>
            <div className="flex flex-wrap items-center gap-4">
                <button type="button" onClick={onAdicionar} data-acao="adicionar-produto"
                    className={cn(ACAO, temProdutos ? 'bg-ecf-yellow font-semibold text-black hover:bg-ecf-yellow/90' : SECUNDARIA)}>
                    <Plus size={18} /> Adicionar produto
                </button>
                <button type="button" onClick={onListas} data-acao="familias-ambientes" className={cn(ACAO, SECUNDARIA)}>
                    <Folder size={18} /> Famílias e ambientes
                </button>
                <button type="button" onClick={onImportar} data-acao="importar-planilha" className={cn(ACAO, SECUNDARIA)}
                    title="Para cadastrar muitos produtos de uma vez, preencha o modelo e importe aqui.">
                    <Upload size={18} /> Importar planilha
                </button>
                <a href={route('portal.auth.estrutura.produtos.modelo')} download data-acao="baixar-modelo" className={cn(ACAO, SECUNDARIA)}
                    title="Planilha-modelo em .xlsx">
                    <Download size={18} /> Baixar modelo
                </a>
                {temProdutos && (
                    <a href={route('portal.auth.estrutura.produtos.exportar')} download data-acao="baixar-meus-produtos" className={cn(ACAO, SECUNDARIA)}
                        title="Seus produtos no modelo .xlsx, para editar e enviar de novo pelo Importar planilha.">
                        <FileSpreadsheet size={18} /> Baixar meus produtos na planilha
                    </a>
                )}
                {temProdutos && onFotosEmLote && (
                    <button type="button" onClick={onFotosEmLote} data-acao="fotos-em-lote" className={cn(ACAO, SECUNDARIA)}
                        title="Envie as fotos de muitas variações de uma vez, com o nome Ref_número (MESA-01_1.jpg).">
                        <Images size={18} /> Enviar fotos em lote
                    </button>
                )}
                <button type="button" onClick={onSugerir} disabled={! podeSugerir || sugerindo} data-acao="sugerir-categorias"
                    className={cn(ACAO, SECUNDARIA)} title={! podeSugerir && ! sugerindo ? 'Todos os produtos já têm categoria.' : undefined}>
                    {sugerindo ? <Loader2 size={18} className="animate-spin" /> : <Lightbulb size={18} />}
                    {sugerindo ? 'Buscando sugestões…' : 'Sugerir categorias'}
                </button>
                {temProdutos && (
                    <Link href={route('portal.auth.estrutura.sugestoes')} data-acao="sugestoes-de-ofertas" className={cn(ACAO, SECUNDARIA)}
                        title="Veja combos, kits e combits sugeridos a partir dos seus produtos.">
                        <Sparkles size={18} /> Planejamento
                    </Link>
                )}
            </div>

            <div className="relative w-full lg:ml-auto lg:w-[336px]">
                <Search size={18} className="absolute left-5 top-1/2 -translate-y-1/2 text-white/45" aria-hidden="true" />
                <input value={busca} onChange={(e) => onBusca(e.target.value)} placeholder="Buscar código ou nome…" aria-label="Buscar código ou nome"
                    className="h-12 w-full rounded-[10px] border border-white/[0.10] bg-white/[0.03] pl-12 pr-10 text-[15px] text-white placeholder:text-white/35 focus:border-ecf-yellow/40 focus:outline-none focus:ring-0"
                    data-busca />
                {busca && (
                    <button type="button" onClick={() => onBusca('')} className="absolute right-3 top-1/2 -translate-y-1/2 text-white/40 hover:text-white" aria-label="Limpar busca">
                        <X size={16} />
                    </button>
                )}
            </div>
        </div>
    );
}
