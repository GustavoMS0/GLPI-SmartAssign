# Changelog

Todas as alterações relevantes do Smart Assign são documentadas neste arquivo.

## [1.0.0] - 2026-11-16

### Adicionado
- Estrutura compatível com GLPI 10.0, 11.0 e 12.0 em pacote único (`PluginSmartassignCompat`, declaração condicional de `$rightname`, prefixo de ativos e sanitização adaptativa).
- Tabelas `glpi_plugin_smartassign_configs`, `glpi_plugin_smartassign_state` e `glpi_plugin_smartassign_logs` criadas na instalação e removidas na desinstalação.
- Configuração por entidade com herança recursiva do ancestral mais próximo (`is_recursive = 1`) e sobrescrita local.
- Motor de atribuição automática (`PluginSmartassignAssigner`) com suporte aos modos **Rodízio** e **Menor fila**, contagem global de chamados abertos, limite por técnico, exclusão de usuários e proteção contra reentrância.
- Diferenciação entre criação de chamado (incluindo grupos definidos por regras de negócio) e alteração posterior de grupo técnico.
- Tela de configuração em **Configurar > Plugins > Smart Assign** com seletor de entidade, controles de regra, lista de usuários excluídos e tabela com as 25 atribuições mais recentes.
- Suíte de testes unitários standalone em `tests/run.php`.
