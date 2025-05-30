<?php

declare(strict_types=1);

/**
 * This file is part of Scout Extended.
 *
 * (c) Algolia Team <contact@algolia.com>
 *
 *  For the full copyright and license information, please view the LICENSE
 *  file that was distributed with this source code.
 */

namespace Algolia\ScoutExtended\Engines;

use Algolia\AlgoliaSearch\SearchClient;
use Algolia\ScoutExtended\Jobs\DeleteJob;
use Algolia\ScoutExtended\Jobs\UpdateJob;
use Algolia\ScoutExtended\Searchable\ModelsResolver;
use Algolia\ScoutExtended\Searchable\ObjectIdEncrypter;
use Illuminate\Support\LazyCollection;
use Illuminate\Support\Str;
use Laravel\Scout\Engines\Algolia3Engine;
use Laravel\Scout\Scout;
use function is_array;
use Laravel\Scout\Builder;

if (version_compare(Scout::VERSION, '10.11.6', '>=')) {
    // New Laravel Scout base class for Algolia
    class_alias(Algolia3Engine::class, BaseAlgoliaEngine::class);
} else {
    // Legacy Laravel Scout class
    class_alias(\Laravel\Scout\Engines\AlgoliaEngine::class, BaseAlgoliaEngine::class);
}

class AlgoliaEngine extends BaseAlgoliaEngine
{
    /**
     * The Algolia client.
     *
     * @var \Algolia\AlgoliaSearch\SearchClient
     */
    protected $algolia;

    /**
     * Create a new engine instance.
     *
     * @param  \Algolia\AlgoliaSearch\SearchClient $algolia
     * @return void
     */
    public function __construct(SearchClient $algolia)
    {
        $this->algolia = $algolia;
    }

    /**
     * @param \Algolia\AlgoliaSearch\SearchClient $algolia
     *
     * @return void
     */
    public function setClient($algolia): void
    {
        $this->algolia = $algolia;
    }

    /**
     * Get the client.
     *
     * @return \Algolia\AlgoliaSearch\SearchClient $algolia
     */
    public function getClient(): SearchClient
    {
        return $this->algolia;
    }

    /**
     * {@inheritdoc}
     */
    public function update($searchables)
    {
        dispatch_sync(new UpdateJob($searchables));
    }

    /**
     * {@inheritdoc}
     */
    public function delete($searchables)
    {
        dispatch_sync(new DeleteJob($searchables));
    }

    /**
     * {@inheritdoc}
     */
    public function map(Builder $builder, $results, $searchable)
    {
        if (count($results['hits']) === 0) {
            return $searchable->newCollection();
        }

        return app(ModelsResolver::class)->from($builder, $searchable, $results);
    }

    /**
     * {@inheritdoc}
     */
    public function lazyMap(Builder $builder, $results, $searchable)
    {
        return LazyCollection::make($this->map($builder, $results, $searchable));
    }

    /**
     * {@inheritdoc}
     */
    public function flush($model)
    {
        $index = $this->algolia->initIndex($model->searchableAs());

        $index->clearObjects();
    }

    /**
     * {@inheritdoc}
     */
    protected function filters(Builder $builder): array
    {
        $operators = ['<', '<=', '=', '!=', '>=', '>', ':'];

        return collect($builder->wheres)->map(function ($value, $key) use ($operators) {
            if (! is_array($value)) {
                if (Str::endsWith($key, $operators) || Str::startsWith($value, $operators)) {
                    return $key.' '.$value;
                }

                return $key.'='.$value;
            }

            return $value;
        })->values()->all();
    }

    /**
     * Pluck and return the primary keys of the given results.
     *
     * @param  mixed  $results
     * @return \Illuminate\Support\Collection
     */
    public function mapIds($results)
    {
        return collect($results['hits'])->pluck('objectID')->values()
            ->map([ObjectIdEncrypter::class, 'decryptSearchableKey']);
    }
}
