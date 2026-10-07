# idempotency-linter

Pacote Composer para Laravel que analisa estaticamente classes `Job` de fila (`ShouldQueue`) e identifica jobs que executam efeitos colaterais sensíveis a duplicação — cobranças, envio de e-mail, inserções no banco — sem qualquer proteção contra reexecução.

> ⚠️ **Status: em desenvolvimento inicial (pré-alfa).** A API, o comando Artisan e os catálogos de detecção ainda vão mudar bastante. Não use em produção ainda.

## O problema

Sistemas de filas (Laravel Queues, BullMQ, Sidekiq, Celery) operam sob garantia **at-least-once**: falhas de rede, timeouts e reinicializações de deploy podem fazer o mesmo job rodar mais de uma vez. Cabe ao desenvolvedor tornar cada job idempotente manualmente — e esse processo é propenso a falhas humanas. Em bases de código com dezenas de jobs, é comum que alguns fiquem sem proteção, e o problema só aparece em produção como incidente visível (cobrança duplicada, e-mail repetido, registro duplicado).

Hoje, as ferramentas existentes (bibliotecas de idempotência, middlewares de deduplicação) atuam apenas na camada de **prevenção manual**: o desenvolvedor decora explicitamente o código com uma chave de idempotência. Nenhuma ferramenta pública audita automaticamente código já existente para sinalizar jobs desprotegidos antes que virem incidente.

## A proposta

Um linter estático (via [nikic/php-parser](https://github.com/nikic/PHP-Parser)) que percorre o método `handle()` de cada Job Laravel, identifica chamadas de efeitos colaterais perigosos ("sinks") e verifica se existe uma guarda de idempotência reconhecida ("guards") protegendo essa chamada. Jobs sem proteção são reportados com nível de risco e localização exata no código.

```bash
composer require --dev emerson-pombo/idempotency-linter

php artisan idempotency:scan app/Jobs
```

```
🔴 ALTO RISCO — app/Jobs/ProcessarPagamentoJob.php:7
   Chamada a gateway de pagamento sem verificação de idempotência.

❌ 8 jobs analisados, 1 com risco, 7 protegidos corretamente.
```

## Escopo do MVP

- Pacote Composer instalável em qualquer projeto Laravel.
- Comando Artisan `idempotency:scan`.
- Análise estática de classes `ShouldQueue`, restrita ao método `handle()`.
- Catálogos de sinks e guardas customizáveis via config publicável.
- Relatório de risco (alto/médio/baixo) com arquivo e linha exatos.

Fora do escopo por enquanto: análise dinâmica/runtime, idempotência distribuída entre microsserviços, correção automática, outros ecossistemas de fila (BullMQ, Sidekiq, Celery) e outras interfaces (VSCode, CI, SaaS) — tudo isso é evolução futura planejada.

## Instalação

Ainda não publicado no Packagist. Em breve.

## Uso

```bash
# analisa os caminhos definidos em config (padrão: app/Jobs)
php artisan idempotency:scan

# um ou mais arquivos/diretórios
php artisan idempotency:scan app/Jobs app/Domain/Billing/Jobs

# controla o código de saída (útil em CI): high | medium | low (padrão) | none
php artisan idempotency:scan --fail-on=high
```

O comando sai com código `1` se houver algum achado no nível de `--fail-on` ou acima.

### Configuração

```bash
php artisan vendor:publish --tag=idempotency-linter-config
```

Gera `config/idempotency-linter.php` com os caminhos padrão e os catálogos de **sinks** (efeitos colaterais: pagamento, e-mail, notificação, HTTP, inserção no banco) e **guards** (`Cache::lock`/`Cache::add`, `firstOrCreate`/`upsert`, chave de idempotência, `ShouldBeUnique`). O formato desses catálogos ainda vai mudar.

## Roadmap

- [x] Estrutura base: ServiceProvider, config publicável, comando `idempotency:scan`, localização de jobs `ShouldQueue`
- [ ] Motor de análise: detectar sinks dentro de `handle()`
- [ ] Detectar guards e decidir se protegem cada sink
- [ ] Saída JSON para CI

## Contribuindo

Projeto em estágio inicial — issues e discussões são bem-vindas. Veja o [CONTRIBUTING.md](CONTRIBUTING.md).

## Licença

[MIT](LICENSE)