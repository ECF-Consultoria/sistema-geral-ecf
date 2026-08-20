import AvatarUsuario from '@/Components/AvatarUsuario';

/**
 * ResponsaveisCliente — quem atende esta empresa, com rosto.
 *
 * ### Por que isto existe apesar do T-135-11-02
 * A regra do portal é "nenhum dado de operação interna sai daqui — sem
 * responsável, sem SLA, sem dias parado, sem nome de usuário interno". Ela
 * continua valendo para OPERAÇÃO: carga de trabalho, fila, prazo estourado.
 *
 * Dizer ao cliente quem é o analista dele não é operação — é relacionamento, e
 * ele já sabe pelo primeiro e-mail e pela reunião. O negócio pediu em 20/08, e
 * o recorte foi deliberado: só nome, foto e papel. E-mail interno, telefone e
 * qualquer métrica seguem fora.
 *
 * ### Foto pode faltar, e falta mesmo
 * `users.avatar_url` é opcional e nem todo mundo subiu foto. `AvatarUsuario`
 * cai nas iniciais — e é por isso que ele existe como componente único, com o
 * `onError`: uma foto apagada do disco viraria ícone quebrado justamente na
 * tela que gente de fora enxerga.
 */
export default function ResponsaveisCliente({ responsaveis = [] }) {
    if (responsaveis.length === 0) return null;

    return (
        <section className="rounded-2xl border border-white/[0.08] bg-white/[0.02] p-5">
            <h2 className="text-white font-display font-bold text-[15px]">
                Responsáveis pelo onboarding
            </h2>
            <p className="text-white/40 text-[12px] mt-0.5">
                É com estas pessoas que você fala durante o processo.
            </p>

            <ul className="mt-4 space-y-3.5">
                {responsaveis.map((r) => (
                    <li key={`${r.papel}-${r.nome}`} className="flex items-center gap-3 min-w-0">
                        <AvatarUsuario nome={r.nome} foto={r.foto} size={40} />
                        <div className="min-w-0">
                            <p className="text-white text-[14px] font-semibold truncate">{r.nome}</p>
                            <p className="text-white/40 text-[12px] truncate">{r.papel}</p>
                        </div>
                    </li>
                ))}
            </ul>
        </section>
    );
}
