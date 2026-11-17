# Smart Assign

> [!WARNING]
> **Status: Em Desenvolvimento (Beta / Pré-homologação)**
> Este plugin teve a arquitetura, a segurança e os fluxos validados por revisão de código, auditoria de segurança e testes unitários automatizados em CI (`PHP 7.4–8.3` com mocks de GLPI 10/11/12), mas **ainda não foi testado em uma instância GLPI ao vivo**. Valide em ambiente de homologação antes de usar em produção e reporte eventuais ajustes via Issues.

Plugin para o GLPI que atribui chamados automaticamente a um técnico quando um grupo técnico é vinculado, usando distribuição por **Rodízio** ou **Menor fila**.

| Versão do GLPI | Compatibilidade |
|---|---|
| **12.0.x** | Suportado |
| **11.0.x** | Suportado |
| **10.0.x** | Suportado |

Requer **PHP 7.4** ou superior (com extensão `json`).

---

## Como funciona

Quando um chamado ativo (não excluído da lixeira, não modelo e com status anterior a Solucionado) recebe um grupo técnico (`type = 2`):

1. **Rodízio (`round_robin`)**: consulta o último técnico atendido para aquele grupo e escolhe o próximo técnico elegível na ordem de IDs, reiniciando no primeiro ao chegar ao fim da lista.
2. **Menor fila (`least_loaded`)**: conta os chamados em aberto (`is_deleted = 0` e status diferente de Solucionado/Fechado) de cada técnico em todas as entidades e escolhe quem tem menos chamados. Em caso de empate, o ponteiro de rodízio decide.

### Regras aplicadas na seleção

- Considera apenas membros do grupo ativos (`is_active = 1`), não excluídos (`is_deleted = 0`) e dentro da vigência (`begin_date` / `end_date`).
- Ignora usuários listados em **Usuários excluídos da fila**.
- Respeita o **limite máximo de chamados em aberto** quando maior que zero.
- Diferencia criação do chamado (`assign_on_create`, incluindo grupos atribuídos por regras de negócio do GLPI) de inclusão posterior de grupo (`assign_on_group_change`).
- **Não sobrescrever técnico já atribuído (`skip_if_assigned`)**:
  - Ligado (`1`): pula a atribuição caso o chamado já tenha qualquer técnico atribuído.
  - Desligado (`0`): pula apenas se um candidato do grupo alvo já estiver atribuído ao chamado; caso contrário, adiciona o técnico escolhido sem remover atribuições existentes.
- Mantém histórico das atribuições na tabela `glpi_plugin_smartassign_logs`, exibindo os 25 registros mais recentes na tela de configuração.

---

## Configuração por entidade

Acesse **Configurar > Plugins > Smart Assign**.

- Selecione a entidade desejada no topo da página.
- Marque **Aplicar às entidades filhas (recursivo)** para que subentidades sem configuração própria herdem automaticamente a regra do ancestral mais próximo.
- Salve a configuração de uma entidade específica para sobrescrever a herança, ou clique em **Restaurar herança** para remover a regra própria.

---

## Instalação

### A partir do pacote de release

1. Baixe o arquivo `smartassign-<versao>.zip` na página de Releases.
2. Extraia a pasta `smartassign` dentro do diretório `plugins/` da instalação do GLPI.
3. Acesse **Configurar > Plugins** no GLPI, instale e ative o **Smart Assign**.
4. Clique no ícone de configuração do plugin para habilitar e ajustar as regras por entidade.

---

## Desenvolvimento e testes

Os testes unitários simulam os ambientes GLPI 10, 11 e 12 sem dependências externas:

```bash
php tests/run.php
```

---

## Licença

Copyright (C) 2026 by G. Martins

Distribuído sob a licença **GPLv3+**. Veja o arquivo [LICENSE](LICENSE) para detalhes.
