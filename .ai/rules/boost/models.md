# Regras para Modelos Eloquent

## Atributos e Decorators (PHP 8 Attributes)

- **Prefira decorators/atributos PHP ao invés de propriedades protegidas**: Em modelos Eloquent, use os atributos nativos do Laravel (ex: `#[Fillable([...])]`, `#[Hidden([...])]`, `#[UsePolicy(...)]`) para definir atributos fillable, ocultos, políticas, etc., em vez de declarar propriedades tradicionais como `protected $fillable = [...]` ou `protected $hidden = [...]`.
- Mantenha a consistência com os modelos existentes no projeto (`app/Models/**`).
