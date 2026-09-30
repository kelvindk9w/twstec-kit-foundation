<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Twstec\Kit\Foundation\Money\Arithmetic;
use Twstec\Kit\Foundation\Money\AsMoney;
use Twstec\Kit\Foundation\Money\Money;
use Twstec\Kit\Foundation\Money\MoneyAsCents;

mutates(Money::class, Arithmetic::class, AsMoney::class, MoneyAsCents::class);

// Cast AsMoney: grava valor (bigint, menor unidade) e moeda juntos, lê de
// volta um Money, e recusa gravar o que não é Money.

beforeEach(function (): void {
    Schema::create('money_cast_orders', function (Blueprint $table): void {
        $table->id();
        $table->bigInteger('total')->nullable();
        $table->char('currency', 3)->nullable();
        $table->bigInteger('fee')->nullable();
        $table->char('fee_currency', 3)->nullable();
    });
});

function moneyCastOrder(): Model
{
    return new class extends Model
    {
        protected $table = 'money_cast_orders';

        public $timestamps = false;

        protected $guarded = [];

        protected function casts(): array
        {
            return [
                'total' => AsMoney::class,
                'fee' => AsMoney::class.':fee_currency',
            ];
        }
    };
}

it('grava valor e moeda e lê de volta um Money', function () {
    $order = moneyCastOrder();
    $order->total = Money::of(123456, 'BRL');
    $order->fee = Money::of(-5, 'USD');
    $order->save();

    $row = DB::table('money_cast_orders')->first();
    $fresh = moneyCastOrder()->newQuery()->findOrFail($order->getKey());

    expect((int) $row->total)->toBe(123456)
        ->and($row->currency)->toBe('BRL')
        ->and((int) $row->fee)->toBe(-5)
        ->and($row->fee_currency)->toBe('USD')
        ->and($fresh->total)->toBeInstanceOf(Money::class)
        ->and($fresh->total->equals(Money::of(123456, 'BRL')))->toBeTrue()
        ->and($fresh->fee->equals(Money::of(-5, 'USD')))->toBeTrue();
});

it('grava e lê os extremos de 64 bits', function () {
    $order = moneyCastOrder();
    $order->total = Money::of(PHP_INT_MAX, 'BRL');
    $order->fee = Money::of(PHP_INT_MIN, 'BRL');
    $order->save();

    $fresh = moneyCastOrder()->newQuery()->findOrFail($order->getKey());

    expect($fresh->total->amount())->toBe(PHP_INT_MAX)
        ->and($fresh->fee->amount())->toBe(PHP_INT_MIN);
});

it('null continua null', function () {
    $order = moneyCastOrder();
    $order->total = null;
    $order->save();

    expect(moneyCastOrder()->newQuery()->findOrFail($order->getKey())->total)->toBeNull();
});

it('lê o valor que o banco devolve como string inteira', function () {
    $model = moneyCastOrder();

    expect((new AsMoney)->get($model, 'total', '-42', ['currency' => 'BRL'])->amount())->toBe(-42);
});

it('recusa gravar o que não é Money', function (mixed $value) {
    $order = moneyCastOrder();
    $order->total = $value;
})->with([100, '100', 1.5, [[100, 'BRL']]])->throws(InvalidArgumentException::class, 'recebe um Money');

it('recusa ler valor sem moeda ou que não é inteiro', function (mixed $value, array $attributes, string $message) {
    expect(fn () => (new AsMoney)->get(moneyCastOrder(), 'total', $value, $attributes))
        ->toThrow(LogicException::class, $message);
})->with([
    'sem moeda' => [100, ['currency' => null], 'sem moeda'],
    'moeda vazia' => [100, ['currency' => ''], 'sem moeda'],
    'moeda não texto' => [100, ['currency' => 986], 'sem moeda'],
    'decimal' => ['1.50', ['currency' => 'BRL'], 'não é inteiro'],
    'float' => [1.5, ['currency' => 'BRL'], 'não é inteiro'],
]);

it('gravar null apaga o valor e mantém a moeda', function () {
    $order = moneyCastOrder();
    $order->total = Money::of(990, 'BRL');
    $order->save();

    $order->total = null;
    $order->save();

    $row = DB::table('money_cast_orders')->find($order->getKey());

    expect($row->total)->toBeNull()
        ->and($row->currency)->toBe('BRL');
});

// O cast de sempre (inteiro puro): o mesmo comportamento, agora com teste
// no pacote.
it('MoneyAsCents lê int e grava só inteiro em menor unidade', function () {
    $cast = new MoneyAsCents;
    $model = moneyCastOrder();

    expect($cast->get($model, 'total', '1990', []))->toBe(1990)
        ->and($cast->get($model, 'total', 1990, []))->toBe(1990)
        ->and($cast->set($model, 'total', 1990, []))->toBe(['total' => 1990])
        ->and($cast->set($model, 'total', '-1990', []))->toBe(['total' => -1990]);
});

it('MoneyAsCents recusa float e texto que não é inteiro', function (mixed $value, string $message) {
    expect(fn () => (new MoneyAsCents)->set(moneyCastOrder(), 'total', $value, []))
        ->toThrow(InvalidArgumentException::class, $message);
})->with([
    'float' => [19.9, 'float é proibido (total)'],
    'decimal em texto' => ['19.90', 'deve ser inteiro em menor unidade (total)'],
    'formatado' => ['R$ 19', 'deve ser inteiro em menor unidade (total)'],
]);
