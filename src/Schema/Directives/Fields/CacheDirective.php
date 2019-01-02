<?php

namespace Nuwave\Lighthouse\Schema\Directives\Fields;

use Carbon\Carbon;
use GraphQL\Language\AST\DirectiveNode;
use Nuwave\Lighthouse\Schema\Values\NodeValue;
use Nuwave\Lighthouse\Schema\Values\CacheValue;
use Nuwave\Lighthouse\Schema\Values\FieldValue;
use Nuwave\Lighthouse\Exceptions\DirectiveException;
use Nuwave\Lighthouse\Schema\Directives\BaseDirective;
use Nuwave\Lighthouse\Support\Contracts\FieldMiddleware;

class CacheDirective extends BaseDirective implements FieldMiddleware
{
    /**
     * Name of the directive.
     *
     * @return string
     */
    public function name(): string
    {
        return 'cache';
    }

    /**
     * Resolve the field directive.
     *
     * @param FieldValue $value
     * @param \Closure   $next
     *
     * @throws DirectiveException
     *
     * @return FieldValue
     */
    public function handleField(FieldValue $value, \Closure $next)
    {
        $this->setNodeKey(
            $value->getParent()
        );

        $value = $next($value);
        $resolver = $value->getResolver();
        $maxAge = $this->directiveArgValue('maxAge');
        $privateCache = $this->directiveArgValue('private', false);

        return $value->setResolver(function ($root, $args, $context, $info) use ($value, $resolver, $maxAge, $privateCache) {
            /** @var \Illuminate\Support\Facades\Cache $cache */
            $cache = app('cache');
            $cacheValue = new CacheValue([
                'field_value' => $value,
                'root' => $root,
                'args' => $args,
                'context' => $context,
                'resolve_info' => $info,
                'private_cache' => $privateCache,
            ]);

            $useTags = $this->useTags();
            $cacheExp = $maxAge
                ? Carbon::now()->addSeconds($maxAge)
                : null;
            $cacheKey = $cacheValue->getKey();
            $cacheTags = $cacheValue->getTags();
            $cacheHas = $useTags
                ? $cache->tags($cacheTags)->has($cacheKey)
                : $cache->has($cacheKey);

            if ($cacheHas) {
                return $useTags
                    ? $cache->tags($cacheTags)->get($cacheKey)
                    : $cache->get($cacheKey);
            }

            $resolvedValue = $resolver($root, $args, $context, $info);

            ($resolvedValue instanceof \GraphQL\Deferred)
                ? $resolvedValue->then(function ($result) use ($cache, $cacheKey, $cacheExp, $cacheTags) {
                    $this->store($cache, $cacheKey, $result, $cacheExp, $cacheTags);
                })
                : $this->store($cache, $cacheKey, $resolvedValue, $cacheExp, $cacheTags);

            return $resolvedValue;
        });
    }

    /**
     * Store value in cache.
     *
     * @param \Illuminate\Support\Facades\Cache $cache
     * @param string                            $key
     * @param mixed                             $value
     * @param \Carbon\Carbon|null               $expiration
     * @param array                             $tags
     */
    protected function store($cache, $key, $value, $expiration, $tags)
    {
        $supportsTags = $this->useTags();

        if ($expiration) {
            ($supportsTags)
                ? $cache->tags($tags)->put($key, $value, $expiration)
                : $cache->put($key, $value, $expiration);

            return;
        }

        ($supportsTags)
            ? $cache->tags($tags)->forever($key, $value)
            : $cache->forever($key, $value);
    }

    /**
     * Check if tags should be used.
     *
     * @return bool
     */
    protected function useTags(): bool
    {
        return config('lighthouse.cache.tags', false)
            && method_exists(app('cache')->store(), 'tags');
    }

    /**
     * Set node's cache key.
     *
     * @param NodeValue $nodeValue
     *
     * @throws DirectiveException
     */
    protected function setNodeKey(NodeValue $nodeValue)
    {
        if ($nodeValue->getCacheKey()) {
            return;
        }

        $fields = data_get($nodeValue->getTypeDefinition(), 'fields', []);
        $nodeKey = collect($fields)->reduce(function ($key, $field) {
            if ($key) {
                return $key;
            }

            $hasCacheKey = collect(data_get($field, 'directives', []))
                ->contains(function (DirectiveNode $directive) {
                    return $directive->name->value === 'cacheKey';
                });

            return $hasCacheKey ? data_get($field, 'name.value') : $key;
        });

        if (! $nodeKey) {
            $nodeKey = collect($fields)->reduce(function ($key, $field) {
                if ($key) {
                    return $key;
                }

                $type = $field->type;
                while (data_get($type, 'type') !== null) {
                    $type = data_get($type, 'type');
                }

                return data_get($type, 'name.value') === 'ID'
                    ? data_get($field, 'name.value')
                    : $key;
            });
        }

        if (! $nodeKey && $nodeValue->getTypeDefinitionName() !== 'Query') {
            $message = sprintf(
                'No @cacheKey or ID field defined on %s',
                $nodeValue->getTypeDefinitionName()
            );

            throw new DirectiveException($message);
        }

        $nodeValue->setCacheKey($nodeKey);
    }
}
