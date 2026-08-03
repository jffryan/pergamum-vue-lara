<?php

namespace App\Http\Requests;

use App\Statistics\MetricRegistry;
use App\Statistics\Scope;
use App\Statistics\ScopeResolver;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StatisticsRequest extends FormRequest
{
    private ?Scope $scope = null;

    /**
     * Resolving the scope is also where it gets authorized — an unknown scope
     * type 404s and a list the user doesn't own 403s, both from here.
     */
    public function scope(): Scope
    {
        return $this->scope ??= app(ScopeResolver::class)->resolve(
            $this->route('scope'),
            $this->route('scopeId'),
        );
    }

    /**
     * The requested metric keys, or null for "everything this scope has".
     *
     * @return string[]|null
     */
    public function metricKeys(): ?array
    {
        $keys = $this->validated()['metrics'] ?? [];

        return $keys ?: null;
    }

    public function authorize(): bool
    {
        return true;
    }

    /**
     * `?metrics=` is a comma-separated list; unpack it before validating so
     * each key can be checked individually.
     */
    protected function prepareForValidation(): void
    {
        $raw = $this->input('metrics');

        if (! is_string($raw)) {
            return;
        }

        $this->merge([
            'metrics' => collect(explode(',', $raw))
                ->map(fn ($key) => trim($key))
                ->filter()
                ->values()
                ->all(),
        ]);
    }

    public function rules(): array
    {
        $supported = app(MetricRegistry::class)->keysFor($this->scope());

        return [
            // An unknown key is a 422, not a silent omission: a typo in a
            // surface config should fail loudly in dev rather than render a
            // quietly missing card.
            'metrics' => ['sometimes', 'array'],
            'metrics.*' => ['string', Rule::in($supported)],
        ];
    }

    public function messages(): array
    {
        return [
            'metrics.*.in' => 'Unknown statistics metric :input for this scope.',
        ];
    }
}
