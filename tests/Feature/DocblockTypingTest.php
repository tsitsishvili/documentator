<?php

declare(strict_types=1);

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Attributes\Appends;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Route;
use Tsitsishvili\Documentator\Attributes\UsesModel;
use Tsitsishvili\Documentator\Documentator;

/**
 * @property string $title
 * @property int $views
 * @property ?Carbon $reviewed_at
 */
class Article extends Model
{
    //
}

#[UsesModel(Article::class)]
class ArticleResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'title' => $this->title,
            'views' => $this->views,
            'reviewed_at' => $this->reviewed_at,
        ];
    }
}

class ArticleShowController
{
    public function show(): ArticleResource
    {
        return new ArticleResource(null);
    }
}

it('types fields from the model @property docblock when not cast', function () {
    Route::get('api/articles/{article}', [ArticleShowController::class, 'show']);

    $props = app(Documentator::class)->toOpenApi()['paths']['/api/articles/{article}']['get']['responses']['200']['content']['application/json']['schema']['properties'];

    expect($props['title']['type'])->toBe('string')
        ->and($props['views']['type'])->toBe('integer')
        ->and($props['reviewed_at'])->toBe(['type' => ['string', 'null'], 'format' => 'date-time']);
});

/**
 * @property int $id
 * @property string $name
 * @property string $password
 */
class Member extends Model
{
    protected $hidden = ['password'];

    protected $appends = ['display_name', 'initials'];

    public function getDisplayNameAttribute(): string
    {
        return (string) $this->name;
    }

    protected function initials(): Attribute
    {
        return Attribute::make(get: fn (): string => substr((string) $this->name, 0, 1));
    }
}

/**
 * @property int $id
 * @property string $name
 * @property string $email
 */
class Contact extends Model
{
    protected $visible = ['id', 'name'];
}

/**
 * @property int $id
 * @property string $api_token
 */
#[Hidden(['api_token'])]
#[Appends(['is_active'])]
class AttributedMember extends Model
{
    public function getIsActiveAttribute(): bool
    {
        return true;
    }
}

class MemberController
{
    public function member(): Member
    {
        return new Member;
    }

    public function contact(): Contact
    {
        return new Contact;
    }

    public function attributed(): AttributedMember
    {
        return new AttributedMember;
    }
}

function modelProps(string $uri): array
{
    return app(Documentator::class)->toOpenApi()['paths'][$uri]['get']['responses']['200']['content']['application/json']['schema']['properties'];
}

it('documents a returned model as serialized: hidden dropped, visible kept, appends added', function () {
    Route::get('api/member', [MemberController::class, 'member']);
    Route::get('api/contact', [MemberController::class, 'contact']);

    $member = modelProps('/api/member');

    expect($member)->not->toHaveKey('password')
        ->and($member['name']['type'])->toBe('string')
        ->and($member['display_name']['type'])->toBe('string')
        ->and($member['initials']['type'])->toBe('string')
        ->and(array_keys(modelProps('/api/contact')))->toBe(['id', 'name']);
});

it('honours Laravel 13 #[Hidden] and #[Appends] model attributes', function () {
    Route::get('api/attributed-member', [MemberController::class, 'attributed']);

    $props = modelProps('/api/attributed-member');

    expect($props)->not->toHaveKey('api_token')
        ->and($props['is_active']['type'])->toBe('boolean');
})->skip(! class_exists(Hidden::class), 'Model visibility attributes require Laravel 13.');
