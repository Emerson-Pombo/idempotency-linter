# Contribuindo

Obrigado por querer ajudar. Issues e pull requests são bem-vindos.

## Preparando o ambiente

Requer PHP 8.1 ou superior, as extensões `dom`, `mbstring`, `tokenizer` e `xml` e o Composer.

```bash
git clone https://github.com/Emerson-Pombo/idempotency-linter.git
cd idempotency-linter
composer install
composer check
```

`composer check` roda o estilo (Pint), a análise estática (PHPStan, nível 6) e os testes. O CI roda o mesmo, mais a matriz PHP 8.1 a 8.3 × Laravel 10 a 13.

| Comando | O que faz |
|---|---|
| `composer test` | PHPUnit |
| `composer lint` | confere o estilo sem alterar arquivos |
| `composer format` | aplica o estilo (Pint, preset `laravel`) |
| `composer analyse` | PHPStan |

## Como trabalhar

- **Teste primeiro.** Toda mudança de comportamento começa por um teste que falha. Os testes de análise usam fixtures de código PHP criadas na hora (veja `tests/TestCase.php`); classes de apoio ficam em `tests/Fixtures/`.
- **Sinks e guards** são dados, não código: ficam em `config/idempotency-linter.php`. Para suportar um novo gateway, uma nova facade ou um novo encadeamento, comece pelo catálogo.
- **Falso negativo silencioso é o pior defeito.** Se uma limitação nova aparecer, documente-a no README e fixe-a em `tests/Unit/Analysis/LimitationsTest.php`.
- **Não execute o código analisado.** A análise é estática. A única exceção é o autoload usado para resolver hierarquia de classes (`ClassMatcher`).
- Mensagens ao usuário, comentários e commits em português; nomes de classes e métodos em inglês.

## Pull requests

- Um assunto por PR, com commits pequenos e descritivos (modo imperativo, em português).
- Descreva o que muda, os pontos de atenção e como testar.
- Atualize o `CHANGELOG.md` na seção da próxima versão e o README quando o comportamento visível mudar.
- O CI precisa estar verde.

## Publicando uma versão (mantenedores)

1. Garanta que a `main` está verde e que o `CHANGELOG.md` está completo.
2. Troque "não lançado" pela data da versão em `CHANGELOG.md`.
3. Crie e publique a tag: `git tag -a v0.1.0 -m "v0.1.0" && git push origin v0.1.0`.
4. Crie a release no GitHub a partir da tag, com o texto da seção do changelog.
5. Na primeira versão, submeta o repositório em <https://packagist.org/packages/submit> e ative a atualização automática (webhook do GitHub).
