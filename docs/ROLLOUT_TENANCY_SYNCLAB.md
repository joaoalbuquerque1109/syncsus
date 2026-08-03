# Rollout de tenancy e envio Synclab

## Antes do deploy

1. Gere um backup verificavel do MySQL e dos documentos privados.
2. Confirme que cada unidade existente possui um CNES real de sete digitos e
   que nao existem CNES duplicados. A migration interrompe o deploy se encontrar
   ambiguidade, evitando consolidar tenants incorretamente.
3. Mantenha `SYNC_SUS_SEED_DEMO=false` e configure as credenciais fortes do
   administrador global. O seed de producao cria ou atualiza somente esse acesso;
   nenhuma organizacao demonstrativa e criada.
4. Mantenha o envio Synclab desativado ate concluir a validacao funcional da
   unidade.

## Ordem de publicacao

1. Publique a imagem e deixe o pre-deploy executar `migrate --force` e
   `db:seed --force`.
2. Valide `/health/live` e `/health/ready`.
3. Entre com o codigo administrativo. Se ainda nao houver unidades, cadastre a
   primeira em **Unidades e tenants**, informando CNES e primeiro gestor.
4. Confira filas, salas, painel e vinculo do gestor criados automaticamente.
5. Configure o Synclab para a unidade, habilite a transmissao e envie um pedido
   ficticio autorizado. A aceitacao exige HTTP 200 e confirmacao visual no grid
   do Synclab.

## Monitoramento

- `pending` e `retrying`: aguardando worker ou nova tentativa.
- `accepted`: confirmado por HTTP 200.
- `rejected`: resposta definitiva diferente de HTTP 200.
- `manual_review`: envio interrompido com resultado externo desconhecido;
  conferir no Synclab antes de usar **Tentar novamente**.
- `awaiting_configuration`: pedido criado sem integracao ativa; nao e enviado em
  lote automaticamente.

O worker e o agendador rodam no mesmo container Railway nesta etapa. Monitore
reinicios, tempo de fila, quantidade de `retrying`/`manual_review` e erros de
conexao. Separe processos em servicos independentes somente quando a carga ou a
disponibilidade exigirem escala isolada.

## Reversao segura

Se houver anomalia, desative `transmission_enabled` na unidade antes de reverter
a aplicacao. Nao apague transmissões nem tentativas: o snapshot criptografado, os
hashes e a auditoria sao necessarios para reconciliar o que chegou ao Synclab.
Restaure banco e arquivos apenas como conjunto e conforme o procedimento de
backup documentado.
