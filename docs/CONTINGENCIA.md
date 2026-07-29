# Plano de contingência

## Objetivo

Preservar atendimento seguro quando o SYNC SUS, a rede local ou o servidor estiver indisponível.
Este plano deve ser adaptado e aprovado pela direção clínica, enfermagem, recepção e infraestrutura.

## Acionamento

1. Confirme a indisponibilidade em dois terminais e consulte `/health/live` e `/health/ready`.
2. Registre horário, unidade, sintomas e responsável pelo incidente.
3. Acione infraestrutura e o coordenador assistencial.
4. Se a recuperação não ocorrer no limite institucional, declare contingência.
5. Não reinicie banco, remova volumes ou restaure backup sem autorização técnica.

## Operação manual

- usar formulários numerados de recepção, triagem, evolução, prescrição, exames e destinação;
- manter senha manual única e quadro local de chamadas sem nome completo;
- registrar autor, conselho profissional, data e hora em cada folha;
- guardar documentos em pasta controlada por turno;
- nunca fotografar prontuários com dispositivos pessoais;
- em risco imediato, o cuidado clínico tem precedência sobre a digitação posterior.

## Retorno do sistema

1. Confirme banco, storage privado, worker, scheduler, espaço e horário do servidor.
2. Execute health checks e um login de homologação.
3. Defina um único responsável pela reconciliação de cada setor.
4. Cadastre os episódios na ordem dos formulários, identificando `origem: contingência`.
5. Digitalize somente quando a instituição possuir processo homologado de anexos; o MVP não possui
   upload genérico.
6. Registre adendos para fatos posteriores sem sobrescrever conteúdo finalizado.
7. Faça conferência cruzada de pacientes, senhas, riscos, prescrições e destinações.
8. Encerrar a contingência somente após assinatura dos responsáveis.

## Restauração e retorno

- restaure apenas em ambiente ou volume validado;
- preserve o banco e os arquivos afetados antes de qualquer tentativa;
- valide `SHA256SUMS` e execute `sync-sus:backup-verify`;
- use o procedimento de [backup e restauração](BACKUP_RESTORE.md);
- se a restauração falhar, retorne ao conjunto anterior e mantenha a contingência manual;
- documente RPO, RTO, perdas, reconciliação e ações preventivas.

## Comunicação

O comunicado deve conter impacto operacional, setores afetados, início, previsão e canal oficial.
Não inclua nome, CPF, CNS, diagnóstico ou imagem de paciente.
