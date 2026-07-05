<?php

declare(strict_types=1);

namespace Tsitsishvili\Documentator\Http\Controllers;

use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tsitsishvili\Documentator\OpenApi\OpenApiSections;

/**
 * Serves the docs UI: the built-in Aurora explorer by default, or the
 * Scalar embed when config('documentator.ui.driver') is "scalar".
 */
final class DocsController
{
    public function __construct(
        private readonly Factory $views,
        private readonly OpenApiSections $sections,
    ) {}

    public function index(?string $section = null): View|RedirectResponse
    {
        $currentSection = $section !== null ? $this->sections->find($section) : null;

        if ($section !== null && $currentSection === null) {
            throw new NotFoundHttpException;
        }

        if ($section === null && ($first = $this->sections->first()) !== null) {
            return new RedirectResponse(route('documentator.ui.section', $first['slug']));
        }

        $specUrl = $currentSection !== null
            ? route('documentator.openapi.section', $currentSection['slug'])
            : route('documentator.openapi');

        if (config('documentator.ui.driver') === 'scalar') {
            return $this->views->make('documentator::scalar', [
                'title' => config('documentator.title'),
                'specUrl' => $specUrl,
                'assets' => config('documentator.ui.assets'),
            ]);
        }

        return $this->views->make('documentator::docs', [
            'title' => config('documentator.title'),
            'specUrl' => $specUrl,
            'sections' => $this->sectionLinks(),
            'currentSection' => $currentSection,
            'authStorage' => config('documentator.ui.auth_storage', 'memory'),
            'cssUrl' => $this->assetUrl('app.css'),
            'jsUrl' => $this->assetUrl('app.js'),
        ]);
    }

    /**
     * @return array<int, array{slug: string, label: string, url: string, specUrl: string}>
     */
    private function sectionLinks(): array
    {
        return array_map(
            fn (array $section): array => [
                'slug' => $section['slug'],
                'label' => $section['label'],
                'url' => route('documentator.ui.section', $section['slug']),
                'specUrl' => route('documentator.openapi.section', $section['slug']),
            ],
            $this->sections->all(),
        );
    }

    private function assetUrl(string $asset): string
    {
        $path = __DIR__.'/../../../resources/ui/'.$asset;

        return route('documentator.asset', $asset).'?v='.(is_file($path) ? filemtime($path) : '1');
    }
}
