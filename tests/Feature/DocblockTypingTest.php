<?php

declare(strict_types=1);

use Carbon\Carbon;
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
        ->and($props['reviewed_at'])->toBe(['type' => 'string', 'format' => 'date-time', 'nullable' => true]);
});
