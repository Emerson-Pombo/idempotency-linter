# Changelog

Todas as mudanças relevantes deste projeto são documentadas aqui.

O formato segue o [Keep a Changelog](https://keepachangelog.com/pt-BR/1.1.0/) e o projeto usa [versionamento semântico](https://semver.org/lang/pt-BR/). Enquanto a versão for `0.x`, os catálogos de detecção e a saída podem mudar entre versões menores.

## [0.1.0] - 2026-10-07

Primeira versão pública.

### Adicionado

- Comando Artisan `idempotency:scan`, com `--fail-on=high|medium|low|none` para uso em CI e `--format=text|json`.
- Localização de jobs `ShouldQueue`, inclusive os que herdam a interface de uma classe base (no mesmo arquivo ou carregável pelo autoload) e os que usam uma interface que estende `ShouldQueue`.
- Motor de análise estática (via `nikic/php-parser`) a partir do `handle()`, seguindo métodos da própria classe (`$this->`, `self::` e `static::`, até 5 níveis).
- Catálogo de sinks: pagamento, e-mail, notificação, HTTP com efeito colateral e inserção no banco.
- Catálogo de guards: `Cache::lock`/`Cache::add`, `firstOrCreate`/`updateOrCreate`/`upsert`, chave de idempotência em arrays literais e `ShouldBeUnique` (proteção parcial, que reduz o risco em um nível).
- Resolução de tipos declarados (propriedades, propriedades promovidas e parâmetros), de subclasses, interfaces e traits do catálogo (via autoload) e de encadeamentos declarados em `chains` (`Mail::to()->send()`, `Http::withToken()->post()`, `Notification::route()->notify()`).
- Relatório de risco (alto, médio, baixo) com arquivo e linha exatos, em texto ou em JSON versionado.
- Configuração publicável (`php artisan vendor:publish --tag=idempotency-linter-config`).
- Compatível com Laravel 10, 11, 12 e 13 (PHP 8.1 ou superior; o Laravel 13 exige PHP 8.3). CI com testes em PHP 8.1 a 8.3, Pint e PHPStan (nível 6).

### Limitações conhecidas

- Não segue serviços injetados, métodos herdados de uma classe pai em outro arquivo nem traits.
- Sem inferência de tipos: variáveis locais, propriedades sem tipo e encadeamentos fora do catálogo `chains` são ignorados.
- Um guard conta pela ordem de execução, não pelo fluxo de controle (um guard dentro de um `if` sem relação com o sink protege o sink mesmo assim).
- O autoload do projeto analisado é usado para resolver hierarquia de classes.

[0.1.0]: https://github.com/Emerson-Pombo/idempotency-linter/releases/tag/v0.1.0
