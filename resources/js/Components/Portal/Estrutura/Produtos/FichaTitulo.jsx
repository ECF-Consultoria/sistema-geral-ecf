import Explicacao from '@/Components/Explicacao';
import { CampoDoTitulo, ContadorDoTitulo } from '@/Components/Titulo/CampoDoTitulo';
import TermosDaCategoria from '@/Components/Titulo/TermosDaCategoria';
import { alternarTermo, limiteDoTitulo } from '@/lib/tituloDoProduto';
import { cn } from '@/lib/utils';

// ─── Bloco "Título de venda" da ficha (19/10/2026) ──────────────────────────
//
// O nome é como a empresa chama o produto; o título é como ele aparece para quem
// procura. A pessoa monta o título clicando nos termos mais buscados da categoria
// (clicar de novo tira) ou digitando. O limite de caracteres vem da categoria e o
// campo não passa dele. Sem categoria, ou se os termos não carregarem, o título
// continua sendo digitado normalmente. Vai junto no "Salvar produto".
//
// 29/10/2026: com o título vazio, "Começar pelo nome" põe o nome do produto no campo (sem
// IA: é o nome digitado). Os termos mostram os 8 primeiros e "Ver todos" — eram 50 chips
// (752 px na Poltrona Opala), a maioria de outros produtos.

const CAMPO = 'h-11 lg:h-10 w-full min-w-0 rounded-lg border border-white/20 bg-black/40 px-3 text-[14px] text-white placeholder:text-white/30 focus:border-ecf-yellow/40 focus:outline-none focus:ring-0';
const TERMOS_A_VISTA = 8;

export default function FichaTitulo({ ficha }) {
    const primeira = ficha.primeira;
    const termos = ficha.termosDoTitulo;
    const titulo = primeira.titulo ?? '';
    const limite = limiteDoTitulo(termos.limite);
    const acima = titulo.length > limite;
    const nome = String(primeira.nome ?? '').trim();

    return (
        <section id="secao-titulo" className="scroll-mt-4 rounded-[14px] border border-white/[0.08] bg-ecf-card p-4 lg:p-5" data-ficha-titulo>
            <div className="flex items-center gap-1">
                <h2 className="text-[19px] font-bold text-white">Título de venda</h2>
                <Explicacao texto={ficha.explicacoes.titulo} nome="Título de venda" />
            </div>
            <p className="mt-0.5 text-[13.5px] text-white/65">É como o produto aparece para quem procura. Clique nos termos mais buscados para montar o título, ou digite.</p>

            <div className="mt-4">
                <div className="mb-1.5 flex items-baseline justify-between gap-3">
                    <label htmlFor="ficha-titulo" className="text-[12.5px] font-medium text-white/70">Título</label>
                    <ContadorDoTitulo tamanho={titulo.length} limite={limite} data-contador-titulo />
                </div>
                <div className="flex gap-2">
                    <CampoDoTitulo id="ficha-titulo" valor={titulo} limite={limite} onChange={ficha.alterarTitulo}
                        placeholder="Ex.: Poltrona Decorativa Opala Linho Pés de Madeira"
                        aria-invalid={acima || undefined} className={cn(CAMPO, acima && 'border-red-400')} data-titulo />
                    {titulo.trim() === '' && nome !== '' && (
                        <button type="button" onClick={() => ficha.alterarTitulo(nome)} data-acao="titulo-pelo-nome"
                            className="h-11 shrink-0 rounded-lg border border-white/[0.14] bg-white/[0.03] px-4 text-[13.5px] text-white hover:bg-white/[0.07] lg:h-10">
                            Começar pelo nome
                        </button>
                    )}
                </div>
                {acima && (
                    <p role="alert" className="mt-1.5 text-[13px] text-red-300" data-titulo-acima>
                        O título passa de {limite} caracteres, o máximo desta categoria. Encurte para salvar.
                    </p>
                )}
            </div>

            {termos.temCategoria ? (
                <TermosDaCategoria className="mt-4" dados={termos.dados} carregando={termos.carregando} erro={termos.erro} onTentarDeNovo={termos.tentarDeNovo}
                    titulo={titulo} limite={limite} onAlternar={(termo) => ficha.alterarTitulo(alternarTermo(titulo, termo))}
                    limiteInicial={TERMOS_A_VISTA} />
            ) : (
                <p className="mt-3 text-[13px] text-white/55" data-titulo-sem-categoria>Escolha a categoria acima para ver os termos mais buscados.</p>
            )}
        </section>
    );
}
