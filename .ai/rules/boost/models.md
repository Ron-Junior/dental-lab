# Regras para Modelos Eloquent

## Atributos e Decorators (PHP 8 Attributes)

- **Prefira decorators/atributos PHP ao invés de propriedades protegidas**: Em modelos Eloquent, use os atributos nativos do Laravel (ex: `#[Fillable([...])]`, `#[Hidden([...])]`, `#[UsePolicy(...)]`) para definir atributos fillable, ocultos, políticas, etc., em vez de declarar propriedades tradicionais como `protected $fillable = [...]` ou `protected $hidden = [...]`.
- **Prefira Attribute Casts nos Models**: Sempre dê preferência por utilizar Attribute Casts do Laravel (`Illuminate\Database\Eloquent\Casts\Attribute` via `Attribute::make(...)`) para valores calculados, formatação e mutações em modelos Eloquent (ex: `$model->is_expired`, `$model->is_applied`), garantindo consistência no acesso a propriedades do modelo.
- Mantenha a consistência com os modelos existentes no projeto (`app/Models/**`).
