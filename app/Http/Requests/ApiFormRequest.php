<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Str;

/**
 * Base for the JSON API's FormRequests.
 *
 * Adds one thing to Laravel's default 422: a `reason_code`. Bulk upload
 * already reports failures that way (`rating_out_of_range`, `version_book_mismatch`),
 * and callers key on it rather than parsing prose. Without this, moving a
 * hand-rolled check into a validator would silently drop the code the SPA
 * and the tests read.
 *
 * A subclass declares the mapping in {@see reasonCodes()}, keyed by
 * `field.rule` — the same key Laravel uses for custom messages. The code for
 * the *first* failing rule wins, so declare them in the order you want them
 * reported.
 *
 * Authorization is `true` throughout this namespace: these endpoints are
 * gated by `auth:sanctum` at the route, and the catalog is deliberately
 * shared between accounts. Per-resource ownership lives in policies
 * (`BookListPolicy`), not here.
 */
abstract class ApiFormRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Map `field.rule` to the reason code a failure should report.
     *
     * @return array<string, string>
     */
    protected function reasonCodes(): array
    {
        return [];
    }

    protected function failedValidation(Validator $validator): void
    {
        $payload = [
            'message' => $validator->errors()->first(),
            'errors' => $validator->errors()->toArray(),
        ];

        $reasonCode = $this->reasonCodeFor($validator);

        if ($reasonCode !== null) {
            $payload['reason_code'] = $reasonCode;
        }

        throw new HttpResponseException(response()->json($payload, 422));
    }

    private function reasonCodeFor(Validator $validator): ?string
    {
        $codes = $this->reasonCodes();

        if ($codes === []) {
            return null;
        }

        // `failed()` is [field => [RuleName => params]]. Normalize to the
        // `field.rule` key that messages() and reasonCodes() both use, then
        // let the subclass's declaration order decide which wins.
        $failed = [];
        foreach ($validator->failed() as $field => $rules) {
            foreach (array_keys($rules) as $rule) {
                $failed[] = $field.'.'.$this->normalizeRuleName($rule);
            }
        }

        foreach ($codes as $pattern => $code) {
            foreach ($failed as $key) {
                // `failed()` reports concrete indices (`readInstances.0.rating`);
                // declarations use the wildcard the rules are written with.
                if (Str::is($pattern, $key)) {
                    return $code;
                }
            }
        }

        return null;
    }

    /**
     * `Between` -> `between`; `App\Rules\Rating` -> `rating`. Class-based
     * rules land in `failed()` under their FQCN.
     */
    private function normalizeRuleName(string $rule): string
    {
        $short = str_contains($rule, '\\') ? class_basename($rule) : $rule;

        return Str::snake($short);
    }
}
