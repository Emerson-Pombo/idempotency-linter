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
🔴 ALTO RISCO — app/Jobs/ProcessarPagamentoJob.php:16
   Chamada a gateway de pagamento sem verificação de idempotência.

❌ 2 jobs analisados, 1 com risco, 1 protegidos corretamente.
```

## Escopo do MVP

- Pacote Composer instalável em qualquer projeto Laravel.
- Comando Artisan `idempotency:scan`.
- Análise estática de classes `ShouldQueue`, restrita ao método `handle()`.
- Catálogos de sinks e guardas customizáveis via config publicável.
- Relatório de risco (alto/médio/baixo) com arquivo e linha exatos.

Fora do escopo por enquanto: análise dinâmica/runtime, idempotência distribuída entre microsserviços, correção automática, outros ecossistemas de fila (BullMQ, Sidekiq, Celery) e outras interfaces (VSCode, CI, SaaS) — tudo isso é evolução futura planejada.

## Como a análise funciona

Para cada job `ShouldQueue`, o linter lê o corpo do `handle()` e:

1. **Procura sinks:** a partir do `handle()`, e dos métodos da própria classe que ele chama, procura chamadas do catálogo `sinks` (pagamento, e-mail, notificação, HTTP, inserção no banco). Cada chamada encontrada gera um achado, com a linha exata.
2. **Procura guards:** chamadas do catálogo `guards` (`Cache::lock`/`Cache::add`, `firstOrCreate`/`updateOrCreate`/`upsert`, chave de idempotência em arrays como `['idempotency_key' => ...]`). Um guard só protege o que vem **depois dele na ordem de execução** (o corpo de um método seguido conta no ponto onde ele é chamado); guard posterior ao sink não conta. Uma chave de idempotência também protege a chamada que a recebe como argumento.
3. **Considera proteção parcial:** se o job implementa `ShouldBeUnique`, o risco de cada achado cai um nível (alto → médio → baixo) e achados de risco baixo deixam de ser reportados. O `ShouldBeUnique` evita jobs simultâneos, mas não cobre retry nem reentrega.

Métodos são reconhecidos quando o tipo do objeto é conhecido: chamadas estáticas (`Mail::send()`), funções, e métodos em propriedades (inclusive promovidas no construtor) ou parâmetros do `handle()` com **tipo declarado** (`$this->stripe->create()` com `PaymentIntentService $stripe`). Subclasses, interfaces e traits do catálogo também casam (`Invoice::create()` com `Invoice extends Model`).

Encadeamentos são seguidos quando estão declarados no catálogo `chains`, que diz qual tipo cada método devolve. Por padrão cobrem `Mail::to($u)->cc($c)->send($m)`, `Http::withToken($t)->acceptJson()->post($url)` e `Notification::route('mail', $to)->notify($n)`. A linha reportada é a do início do encadeamento.

### Limitações da v1

- A análise parte do `handle()` e segue só métodos da própria classe (`$this->metodo()`, `self::metodo()`, `static::metodo()`, até 5 níveis): efeitos colaterais em serviços injetados, em métodos herdados de outro arquivo ou em traits não são vistos.
- Sem inferência de tipos: variáveis locais, propriedades sem tipo e encadeamentos que não estão no catálogo `chains` (por exemplo `app(Foo::class)->send()`) são ignorados, sem erro.
- Um guard conta apenas pela posição no código; um guard dentro de um `if` sem relação com o sink protege o sink mesmo assim.
- Jobs que herdam a interface de uma classe base (`extends BaseJob`) ou usam uma interface que estende `ShouldQueue` são detectados, desde que a classe base seja resolvível: no mesmo arquivo, ou carregável pelo autoload. Pai que não carrega é ignorado.

### Atenção: autoload

Para resolver subclasses e traits, o linter carrega as classes do seu projeto pelo autoload do Composer (`class_exists`, `is_a`, `class_uses`). Ele **não instancia** nem executa classes, mas o autoload de uma classe com código no nível do arquivo executa esse código. Classes que falham ao carregar são ignoradas. Rode o comando no ambiente do projeto, como faria com qualquer comando Artisan.

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

Gera `config/idempotency-linter.php` com os caminhos padrão e os catálogos de **sinks** (efeitos colaterais: pagamento, e-mail, notificação, HTTP, inserção no banco), **guards** (`Cache::lock`/`Cache::add`, `firstOrCreate`/`upsert`, chave de idempotência, `ShouldBeUnique`) e **chains** (encadeamentos conhecidos). O formato desses catálogos ainda vai mudar. Em regras `function`, os nomes das funções vão na chave `functions` (ou `methods`).

## Roadmap

- [x] Estrutura base: ServiceProvider, config publicável, comando `idempotency:scan`, localização de jobs `ShouldQueue`
- [x] Motor de análise: detectar sinks dentro de `handle()`
- [x] Detectar guards e decidir se protegem cada sink
- [x] Resolver chamadas encadeadas declaradas no catálogo (`Mail::to()->send()`, `Http::withToken()->post()`)
- [x] Seguir métodos da própria classe a partir do `handle()`
- [ ] Seguir serviços injetados e métodos herdados a partir do `handle()`
- [x] Reconhecer jobs que herdam `ShouldQueue` de uma classe base
- [ ] Saída JSON para CI

## Desenvolvimento

```bash
composer install
composer check     # estilo (Pint) + análise estática (PHPStan nível 6) + testes
composer format    # aplica o estilo automaticamente
```

O CI roda os testes em PHP 8.1 a 8.3 com Laravel 10, 11 e 12 (combinações compatíveis), além de Pint e PHPStan.

## Contribuindo

Projeto em estágio inicial — issues e discussões são bem-vindas. Veja o [CONTRIBUTING.md](CONTRIBUTING.md).

## Licença

[MIT](LICENSE)