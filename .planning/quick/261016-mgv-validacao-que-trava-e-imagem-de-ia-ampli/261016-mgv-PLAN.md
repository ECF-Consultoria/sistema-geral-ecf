---
quick_id: 261016-mgv
descricao: validação que travava em "Validando automaticamente…" e imagem de IA ampliada
data: 2026-10-16
---

# Validação que trava e imagem de IA ampliada

1. `CreativeJuiz::julgarEGravar()` — no teto `max_validacoes_asset` gravar `indisponivel` (devolvia sem gravar → `pendente` eterno; kit 17/slot 1 em produção, job em 4,9 ms). No Publicador (`semTeto()`), toda imagem nova é julgada.
2. Status do kit do Publicador encerra validação presa > `LIMITE_VALIDACAO_MINUTOS`.
3. `useCriativosDoPublicador` — polling também enquanto algum slot está com `validacao_status = pendente`.
4. Imagem gerada amplia em tela cheia (`Mesa/ImagemAmpliada.jsx`); o `ImagemAmpliada` interno do Acervo (v28.0, outra frente) não foi tocado.
5. Testes + prova inversa, regressão, build. Deploy só dos meus commits.
