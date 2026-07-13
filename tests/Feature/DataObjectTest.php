<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Lazy;
use Spatie\LaravelData\Optional;
use Tsitsishvili\Documentator\Documentator;

enum DataStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';
}

class AddressData extends Data
{
    public function __construct(
        public string $city,
        public string $zip,
    ) {}
}

class CreateUserData extends Data
{
    public function __construct(
        public string $name,
        public DataStatus $status,
        public ?int $age = null,
        public ?AddressData $address = null,
    ) {}
}

class UserData extends Data
{
    public function __construct(
        public int $id,
        public string $name,
    ) {}
}

class MappedUserData extends Data
{
    public function __construct(
        #[MapInputName('first_name')]
        #[MapOutputName('display_name')]
        public string $name,
        public string|Optional $nickname,
        public string|Lazy $biography,
    ) {}
}

class DataObjectController
{
    public function store(CreateUserData $data): UserData
    {
        return new UserData(1, 'Ada');
    }

    public function search(CreateUserData $data): void
    {
        //
    }

    public function mapped(MappedUserData $data): MappedUserData
    {
        return $data;
    }
}

it('infers body params and a response schema from Data objects', function () {
    Route::post('api/data-users', [DataObjectController::class, 'store']);

    $op = app(Documentator::class)->toOpenApi()['paths']['/api/data-users']['post'];
    $body = $op['requestBody']['content']['application/json']['schema'];
    $props = $body['properties'];

    expect($props['name']['type'])->toBe('string')
        ->and($props['status']['enum'])->toBe(['active', 'inactive'])
        ->and($props['age'])->toMatchArray(['type' => ['integer', 'null']])
        ->and($props['address']['type'])->toBe(['object', 'null'])
        ->and($props['address']['properties']['city']['type'])->toBe('string')
        ->and($body['required'])->toContain('name', 'status')
        ->and($body['required'])->not->toContain('age');

    // POST returning a Data object -> 201 with the Data's shape.
    $response = $op['responses']['201']['content']['application/json']['schema'];
    expect($response['properties']['id']['type'])->toBe('integer')
        ->and($response['properties']['name']['type'])->toBe('string');
});

it('routes Data params to the query string on GET', function () {
    Route::get('api/data-users', [DataObjectController::class, 'search']);

    $op = app(Documentator::class)->toOpenApi()['paths']['/api/data-users']['get'];
    $byName = collect($op['parameters'])->keyBy('name');

    expect($op)->not->toHaveKey('requestBody')
        ->and($byName)->toHaveKeys(['name', 'status'])
        ->and($byName['status']['in'])->toBe('query');
});

it('honors Data input/output names and optional or lazy properties', function () {
    Route::post('api/mapped-data-users', [DataObjectController::class, 'mapped']);

    $operation = app(Documentator::class)->toOpenApi()['paths']['/api/mapped-data-users']['post'];
    $request = $operation['requestBody']['content']['application/json']['schema'];
    $response = $operation['responses']['201']['content']['application/json']['schema'];

    expect($request['properties'])->toHaveKeys(['first_name', 'nickname', 'biography'])
        ->and($request['properties'])->not->toHaveKey('name')
        ->and($request['required'])->toBe(['first_name'])
        ->and($response['properties'])->toHaveKeys(['display_name', 'nickname', 'biography'])
        ->and($response['properties'])->not->toHaveKey('name')
        ->and($response['required'])->toBe(['display_name']);
});
