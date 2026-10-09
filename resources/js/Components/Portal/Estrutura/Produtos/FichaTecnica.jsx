import { Loader2 } from 'lucide-react';
import CampoFichaTecnica from '@/Components/Portal/Estrutura/Produtos/CampoFichaTecnica';

// ─── Bloco "Ficha técnica" da ficha do produto ──────────────────────────────
//
// Aparece quando o produto tem categoria. Mostra TODOS os campos dela de uma
// vez, agrupados, e quem preenche é o cliente; o que ele digita vai junto no
// "Salvar produto". Os grupos e os rótulos vêm do servidor, como estão, cada
// campo com a sua explicação (ícone ao lado do rótulo). O campo que é o eixo de
// alguma variação do produto já chega fora de `tecnica.grupos`, e as medidas do
// produto fora da caixa também (têm bloco próprio, `MedidasDoProduto`).

export default function FichaTecnica({ tecnica, salvando }) {
    if (! tecnica.temCategoria) return null;
    const grupos = tecnica.grupos ?? [];

    return (
        <section className="mt-2.5 rounded-[14px] border border-white/[0.08] bg-ecf-card p-4 lg:px-5 lg:pb-5 lg:pt-3" data-ficha-tecnica>
            <h2 className="text-[20px] font-bold text-white">Ficha técnica</h2>
            <p className="mt-1 text-[14px] text-white/70">Preencha as características do produto. Os campos com * são obrigatórios.</p>

            {tecnica.erroGeral && (
                <p role="alert" className="mt-3 rounded-xl border border-red-400/20 bg-red-500/10 px-3 py-2 text-[12px] text-red-200" data-erro-ficha-tecnica>{tecnica.erroGeral}</p>
            )}

            {tecnica.carregando && (
                <p role="status" className="mt-4 flex items-center gap-2 text-[14px] text-white/60" data-carregando-ficha-tecnica>
                    <Loader2 size={16} className="animate-spin" aria-hidden="true" /> Carregando os campos…
                </p>
            )}

            {! tecnica.carregando && tecnica.indisponivel && (
                <div role="status" className="mt-4 flex flex-wrap items-center justify-between gap-3 rounded-xl border border-white/[0.08] bg-white/[0.03] px-3 py-2 text-[14px] text-white/70" data-indisponivel-ficha-tecnica>
                    <span>Não foi possível carregar os campos agora; tente de novo.</span>
                    <button type="button" onClick={tecnica.tentarDeNovo} data-acao="recarregar-ficha-tecnica"
                        className="h-9 rounded-lg border border-white/[0.10] bg-white/[0.03] px-4 text-[14px] text-white hover:bg-white/[0.07]">
                        Tentar de novo
                    </button>
                </div>
            )}

            {/* Durante o "Salvando…" nada se edita (mesma regra dos outros blocos da ficha). */}
            <fieldset disabled={salvando} className="min-w-0 border-0 p-0" data-campos-ficha-tecnica>
                {grupos.map((grupo, i) => (
                    <div key={`${grupo.grupo}-${i}`} className="mt-4" data-grupo-tecnico>
                        <h3 className="mb-2 border-b border-white/[0.08] pb-1 text-[15px] font-semibold text-white/90">{grupo.grupo}</h3>
                        <div className="grid gap-x-6 gap-y-3 sm:grid-cols-2 xl:grid-cols-3">
                            {(grupo.campos ?? []).map((campo) => (
                                <CampoFichaTecnica key={campo.id} campo={campo} atual={tecnica.valores[campo.id]}
                                    erro={tecnica.erros[campo.id]} onMudar={tecnica.mudar} explicacao={campo.explicacao} />
                            ))}
                        </div>
                    </div>
                ))}
            </fieldset>
        </section>
    );
}
