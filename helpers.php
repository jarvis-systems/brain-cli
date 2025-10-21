<?php

declare(strict_types=1);

if (!function_exists("config")) {
    /**
     * Get the specified configuration value.
     *
     * @param  string|null  $key
     * @param  mixed  $default
     * @return \Illuminate\Contracts\Config\Repository|mixed
     * @return ($key is null ? \Illuminate\Contracts\Config\Repository : mixed)
     */
    function config(string|null $key = null, mixed $default = null): mixed
    {
        try {
            $container = Illuminate\Container\Container::getInstance();
            if ($container && $container->bound('config')) {
                /** @var \Illuminate\Contracts\Config\Repository $config */
                $config = $container->make('config');
                if ($key) {
                    return $config->get($key, $default);
                }
                return $config;
            }
        } catch (\Throwable) {}

        return $default;
    }
}

if (!function_exists('app')) {
    /**
     * Get the available container instance.
     *
     * @template TAbstract as class-string
     * @param  TAbstract|null  $abstract
     * @param  array<int, mixed>  $parameters
     * @return ($abstract is null ? \Illuminate\Contracts\Container\Container : TAbstract)
     * @throws \Illuminate\Contracts\Container\BindingResolutionException
     */
    function app(string|null $abstract = null, array $parameters = []): mixed
    {
        $container = Illuminate\Container\Container::getInstance();
        if ($abstract) {
            return $container->make($abstract, $parameters);
        }
        return $container;
    }
}

if (!function_exists('tag_replace')) {
    /**
     * Invested tag replacement on the object values or array.
     *
     * @template T as list<string>|string
     * @param  T  $text
     * @param  array<string, mixed>|object  $materials
     * @param  string  $pattern
     * @return T|null
     */
    function tag_replace(array|string $text, array|object $materials, string $pattern = "{*}"): array|string|null
    {
        $pattern = preg_quote($pattern);
        $pattern = str_replace('\*', '([a-zA-Z0-9\_\-\.]+)', $pattern);

        /** @var T|null */
        return preg_replace_callback("/{$pattern}/", fn (array $m) => to_string(data_get($materials, $m[1])), $text);
    }
}

if (! function_exists('to_string')) {
    /**
     * Convert a value to a string representation.
     *
     * @param  mixed  $value
     * @param  string  $default
     * @return string
     */
    function to_string(mixed $value, string $default = ''): string
    {
        if ($value instanceof BackedEnum) {
            $value = $value->value;
        }
        $value = is_callable($value) ? call_user_func($value) : $value;
        return is_scalar($value)
            ? (string) $value
            : (is_array($value) || is_object($value)
                ? (json_encode($value, JSON_FORCE_OBJECT|JSON_UNESCAPED_UNICODE) ?: $default)
                : $default);
    }
}

if (! function_exists('to_int')) {
    /**
     * Convert a value to an integer representation.
     *
     * @param  mixed  $value
     * @param  int  $default
     * @return int
     */
    function to_int(mixed $value, int $default = 0): int
    {
        if ($value instanceof BackedEnum) {
            $value = $value->value;
        }
        $value = is_callable($value) ? call_user_func($value) : $value;
        return is_scalar($value) ? (int) $value : $default;
    }
}

if (! function_exists('to_float')) {
    /**
     * Convert a value to a float representation.
     *
     * @param  mixed  $value
     * @param  float  $default
     * @return float
     */
    function to_float(mixed $value, float $default = 0.0): float
    {
        if ($value instanceof BackedEnum) {
            $value = $value->value;
        }
        $value = is_callable($value) ? call_user_func($value) : $value;
        return is_scalar($value) ? (float) $value : $default;
    }
}

if (! function_exists('to_bool')) {
    /**
     * Convert a value to a boolean representation.
     *
     * @param  mixed  $value
     * @param  bool  $default
     * @return bool
     */
    function to_bool(mixed $value, bool $default = false): bool
    {
        if ($value instanceof BackedEnum) {
            $value = $value->value;
        }
        $value = is_callable($value) ? call_user_func($value) : $value;
        return is_scalar($value)
            ? (filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? $default)
            : $default;
    }
}

if (! function_exists('to_array')) {
    /**
     * Convert a value to an array representation.
     *
     * @param  mixed  $value
     * @param  array<int|string, mixed>  $default
     * @return array<int|string, mixed>
     */
    function to_array(mixed $value, array $default = []): array
    {
        if ($value instanceof BackedEnum) {
            $value = $value->value;
        }
        $value = is_callable($value) ? call_user_func($value) : $value;
        return is_array($value)
            ? $value
            : ($value instanceof Arrayable
                ? $value->toArray()
                : ($value ? (array) $value : $default));
    }
}

if (! function_exists('to_array_values')) {
    /**
     * Convert a value to an array of values representation.
     *
     * @param  mixed  $value
     * @param  array<int|string, mixed>  $default
     * @return array<int, mixed>
     */
    function to_array_values(mixed $value, array $default = []): array
    {
        return array_values(
            to_array($value, $default)
        );
    }
}
