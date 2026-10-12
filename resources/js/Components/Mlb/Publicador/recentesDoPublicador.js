// ═══════════════════════════════════════════════════════════════════════════
// Fase 177, plano 06 — a fonte ÚNICA das "contas recentes" do Publicador.
//
// POR QUE ESTE MÓDULO EXISTE: até agora estas quatro funções eram LOCAIS a
// `Pages/Mlb/AnunciosEmpresas.jsx` (Fase 173, plano 03). O dropdown de empresa
// do header global (HEAD-05) precisa do MESMO grupo "RECENTES", e duas leituras
// da mesma chave de `localStorage` com duas implementações divergem com o tempo
// — a disciplina de `try/catch`, o descarte de item sem `chave` e o limite de 4
// são exatamente o que não pode divergir entre as duas telas.
//
// Então o código SAIU do arquivo e virou módulo, sem mudar UMA linha de
// comportamento. O gate que provava essas regras não foi apagado: as asserções
// foram REAPONTADAS para este arquivo (a tabela do reaponte está na SUMMARY do
// plano 177-06).
//
// ─── Onde o dado mora ───
//   `localStorage`, chave `publicador.recentes.{user_id}` — dado do próprio
//   usuário, no próprio navegador. Sem tabela nova (T-173-07).
//
// ─── Quem lê ───
//   · `Pages/Mlb/AnunciosEmpresas.jsx`                  (a tela de entrada)
//   · `Components/Mlb/Publicador/header/SeletorDeEmpresaDoHeader.jsx`
//     (o grupo "RECENTES" do dropdown de 360px)
//
// ⚠️ O ITEM DE RECENTE NUNCA GUARDA TOKEN (T-173-07). Quem monta o item é a
// tela, e ela grava só `{ chave, nome, identificador, programa }` — nada de
// `token`, nada de credencial, nada que o navegador não deva ver. Há asserção
// literal sobre esse objeto em `tests/js/publicador-entrada.test.js`.
//
// ⚠️ TODA leitura e TODA escrita passam por `try/catch`: `localStorage` pode
// LANÇAR (janela privada, quota cheia) ou vir com JSON corrompido, e a tela
// precisa funcionar normalmente sem ele. `lerRecentes` devolve `[]` e
// `gravarRecente` devolve `null` — nunca propagam o erro.
// ═══════════════════════════════════════════════════════════════════════════

/** Até 4 contas na lista — o 5º acesso empurra o mais antigo para fora. */
export const MAX_RECENTES = 4;

/** A chave do `localStorage`, por usuário. */
export const chaveRecentes = (userId) => `publicador.recentes.${userId}`;

/**
 * As contas recentes do usuário, da mais recente para a mais antiga.
 *
 * @param {number|string|null} userId
 * @returns {Array<{chave: string, nome?: unknown, identificador?: unknown, programa?: unknown}>}
 *   sempre um array — vazio quando não há nada, quando o conteúdo não é lista
 *   ou quando o `localStorage` falha
 */
export function lerRecentes(userId) {
    try {
        const bruto = window.localStorage.getItem(chaveRecentes(userId));
        if (!bruto) return [];
        const lista = JSON.parse(bruto);
        if (!Array.isArray(lista)) return [];
        // Descarta item sem `chave` string válida — forma inesperada (lixo
        // gravado por versão antiga, edição manual do localStorage etc.)
        // nunca derruba a tela nem quebra a navegação do clique.
        return lista.filter((item) => item && typeof item.chave === 'string' && item.chave !== '').slice(0, MAX_RECENTES);
    } catch {
        return [];
    }
}

/**
 * Põe `item` no topo da lista, sem duplicar a mesma `chave`, e grava.
 *
 * @param {number|string|null} userId
 * @param {{chave: string}} item  montado por quem chama — NUNCA com token
 * @returns {Array|null} a lista nova, ou `null` quando o `localStorage` falhou
 *   (quem chama só mexe no estado da tela se vier lista)
 */
export function gravarRecente(userId, item) {
    try {
        const semDuplicata = lerRecentes(userId).filter((r) => r?.chave !== item.chave);
        const novaLista = [item, ...semDuplicata].slice(0, MAX_RECENTES);
        window.localStorage.setItem(chaveRecentes(userId), JSON.stringify(novaLista));
        return novaLista;
    } catch {
        return null;
    }
}
